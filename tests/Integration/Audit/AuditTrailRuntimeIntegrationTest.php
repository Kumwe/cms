<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Audit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Kernel\Container;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Application\Automation\GlobalJobPrincipals;
use Kumwe\App\Application\Automation\JobExecutionScope;
use Kumwe\App\Audit\Application\AuditAnchorWriter;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Application\AuditTrailExporter;
use Kumwe\App\Audit\Application\AuditTrailVerifier;
use Kumwe\App\Audit\Domain\AuditEnforcementState;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Audit\Domain\AuditEventDigest;
use Kumwe\App\Audit\Infrastructure\Persistence\AuditAppendOnlyGuard;
use Kumwe\App\Audit\Infrastructure\Persistence\DoctrineAuditAnchorWriter;
use Kumwe\App\Audit\Infrastructure\Persistence\DoctrineAuditRecorder;
use Kumwe\App\Audit\Infrastructure\Persistence\DoctrineAuditTrailExporter;
use Kumwe\App\Audit\Infrastructure\Persistence\DoctrineAuditTrailVerifier;
use Kumwe\App\Infrastructure\Persistence\Migration\AuditTamperEvidenceMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\IndexNameIsolationMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\AuditTamperHarness;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

#[CoversClass(AuditTamperEvidenceMigration::class)]
#[CoversClass(AuditAppendOnlyGuard::class)]
#[CoversClass(DoctrineAuditRecorder::class)]
#[CoversClass(DoctrineAuditAnchorWriter::class)]
#[CoversClass(DoctrineAuditTrailVerifier::class)]
#[CoversClass(DoctrineAuditTrailExporter::class)]
final class AuditTrailRuntimeIntegrationTest extends TestCase
{
    private Container $container;

    private Connection $database;

    private TableNames $tables;

    protected function setUp(): void
    {
        $this->container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $this->container->get(Connection::class);
        $tables = $this->container->get(TableNames::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $this->database = $database;
        $this->tables = $tables;
    }

    public function testTheMigrationLeavesAMonotonicChainedAppendOnlyTrail(): void
    {
        $schema = $this->database->createSchemaManager();
        $events = $schema->introspectTableByUnquotedName($this->tables->raw('audit_events'));
        foreach (['position', 'digest', 'previous_digest'] as $column) {
            self::assertTrue($events->hasColumn($column), sprintf('Column %s is missing.', $column));
        }
        // The installed schema carries the position index under its installation-unique name: the
        // index-name isolation at the end of the plan renames every non-digest-suffixed name, the
        // prefixed spelling included, because a prefix alone is no proof against a collision.
        self::assertTrue($events->hasIndex(IndexNameIsolationMigration::isolatedName(
            $this->tables->raw('audit_events'),
            $this->tables->raw('uniq_audit_event_position'),
        )));
        self::assertTrue($schema->tablesExist([$this->tables->raw('audit_anchors')]));
        // Append-only enforcement is a property of the server's privileges, not of the migration, so
        // what must hold everywhere is that the trail reports the posture it is actually in. Whether
        // the triggers really refuse a write is proven under the active branch of the refusal test.
        self::assertSame(
            AuditAppendOnlyGuard::state($this->database, $this->tables),
            $this->verifier()->verify($this->context())->enforcement,
            'The verification report must agree with the server about append-only enforcement.',
        );
        self::assertTrue($this->positionIsDatabaseAllocated(), 'The driver must allocate audit positions.');
        self::assertSame('0', (string) $this->database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE position IS NULL OR digest IS NULL',
            $this->tables->quoted('audit_events'),
        )));
        foreach (
            [
                AuditTamperEvidenceMigration::ANCHOR_JOB_TYPE,
                AuditTamperEvidenceMigration::VERIFY_JOB_TYPE,
                AuditTamperEvidenceMigration::RETENTION_JOB_TYPE,
            ] as $type
        ) {
            self::assertSame('installation', $this->database->fetchOne(sprintf(
                'SELECT execution_scope FROM %s WHERE job_type = ?',
                $this->tables->quoted('schedules'),
            ), [$type]));
        }
        foreach (['audit.export', 'audit.manage'] as $capability) {
            self::assertSame($capability, $this->database->fetchOne(sprintf(
                'SELECT code FROM %s WHERE code = ?',
                $this->tables->quoted('capabilities'),
            ), [$capability]));
        }
    }

    public function testRecordingChainsEachEventAndTheTrailVerifies(): void
    {
        $identifiers = $this->record(3);
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT id, position, digest, previous_digest FROM %s WHERE id IN (?, ?, ?) ORDER BY position ASC',
            $this->tables->quoted('audit_events'),
        ), $identifiers);

        self::assertCount(3, $rows);
        self::assertSame($identifiers, array_column($rows, 'id'));
        self::assertSame($rows[0]['digest'], $rows[1]['previous_digest']);
        self::assertSame($rows[1]['digest'], $rows[2]['previous_digest']);
        self::assertGreaterThan((int) $rows[0]['position'], (int) $rows[1]['position']);
        $report = $this->verifier()->verify($this->context());
        self::assertTrue($report->intact(), $report->firstDivergence?->detail ?? '');
        self::assertGreaterThanOrEqual(3, $report->eventsVerified);
    }

    public function testTheDigestSurvivesWhateverThisEngineDoesToStoredJsonAndDatetimes(): void
    {
        // Engines do not store back what they were handed. MySQL's native `json` column reorders object
        // keys and restyles whitespace, MariaDB keeps JSON as text, PostgreSQL preserves the input, and
        // `occurred_at` is a datetime(6) on some of them and second-resolution on others. If the digest
        // were taken over the bytes a driver hands back, `audit:verify` would report tampering on an
        // untouched trail — a permanent false positive, and worse than any failure it could report
        // truthfully. It is taken over a canonical form instead, and this pins that on every engine.
        $cases = [
            'reordered keys' => ['zulu' => 'z', 'alpha' => 'a', 'mike' => 'm'],
            'nested and listed' => ['outer' => ['z' => 1, 'a' => ['c', 'b']], 'n' => 42, 'ok' => true],
            'unicode and slashes' => ['path' => 'a/b/c', 'text' => 'héllo — ünïcode ✓', 'quote' => 'say "hi"'],
            'numbers that reformat' => ['whole' => 1.0, 'tenth' => 0.1, 'huge' => 9007199254740993],
        ];
        $recorded = [];
        foreach ($cases as $metadata) {
            $id = Uuid::uuid7()->toString();
            $recorded[$id] = $metadata;
            $this->recorder()->record(new AuditEvent(
                $id,
                $this->clock()->now(),
                'roundtrip-actor',
                'identity.user.activated',
                'identity.user',
                'roundtrip-subject',
                'success',
                $metadata,
            ));
        }

        foreach (array_keys($recorded) as $id) {
            $row = $this->row((string) $id);
            $stored = is_string($row['metadata']) ? $row['metadata'] : '';
            $decoded = $stored === '' ? [] : json_decode($stored, true, 64, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            /** @var array<string, mixed> $decoded */
            self::assertSame(
                AuditEventDigest::compute(
                    (string) $id,
                    substr((string) $row['occurred_at'], 0, 19),
                    'roundtrip-actor',
                    'identity.user.activated',
                    'identity.user',
                    'roundtrip-subject',
                    'success',
                    $decoded,
                ),
                $row['digest'],
                'The digest must survive this engine\'s storage round-trip unchanged.',
            );
        }
        self::assertTrue($this->verifier()->verify($this->context())->intact());
    }

    public function testRewritesAreRefusedWhereEnforcementIsInstalledAndDetectedWhereItIsNot(): void
    {
        $identifiers = $this->record(1);

        if (AuditAppendOnlyGuard::installed($this->database, $this->tables)) {
            self::assertTrue(AuditTamperHarness::updateIsRefused($this->database, $this->tables, $identifiers[0]));
            self::assertTrue(AuditTamperHarness::deleteIsRefused($this->database, $this->tables, $identifiers[0]));
            self::assertSame('success', $this->database->fetchOne(sprintf(
                'SELECT outcome FROM %s WHERE id = ?',
                $this->tables->quoted('audit_events'),
            ), [$identifiers[0]]));
            self::assertTrue($this->verifier()->verify($this->context())->intact());

            return;
        }

        // This server refused the guards, so the rewrite the guarded branch proves impossible is
        // simply allowed here. What must still hold is the claim the subsystem actually makes: the
        // evidence catches it. Prevention is absent; detection is not.
        $this->database->executeStatement(sprintf(
            'UPDATE %s SET outcome = ? WHERE id = ?',
            $this->tables->quoted('audit_events'),
        ), ['denied', $identifiers[0]]);

        $report = $this->verifier()->verify($this->context());
        self::assertFalse($report->intact(), 'An unprevented rewrite must still be detected.');
        self::assertSame('event.digest.mismatch', $report->firstDivergence?->code);
        self::assertSame($identifiers[0], $report->firstDivergence?->eventId);
        self::assertSame(AuditEnforcementState::NotInstalled, $report->enforcement);
        self::assertFalse($report->guarded());

        $this->database->executeStatement(sprintf(
            'UPDATE %s SET outcome = ? WHERE id = ?',
            $this->tables->quoted('audit_events'),
        ), ['success', $identifiers[0]]);
        self::assertTrue($this->verifier()->verify($this->context())->intact());
    }

    public function testMutatingAStoredRowIsDetectedAndTheTrailRecoversWhenItIsPutBack(): void
    {
        $identifiers = $this->record(2);
        $this->withoutGuards(function () use ($identifiers): void {
            $this->database->executeStatement(sprintf(
                'UPDATE %s SET outcome = ? WHERE id = ?',
                $this->tables->quoted('audit_events'),
            ), ['denied', $identifiers[1]]);
        });

        $report = $this->verifier()->verify($this->context());
        self::assertFalse($report->intact());
        self::assertSame('event.digest.mismatch', $report->firstDivergence?->code);
        self::assertSame($identifiers[1], $report->firstDivergence?->eventId);

        $this->withoutGuards(function () use ($identifiers): void {
            $this->database->executeStatement(sprintf(
                'UPDATE %s SET outcome = ? WHERE id = ?',
                $this->tables->quoted('audit_events'),
            ), ['success', $identifiers[1]]);
        });
        self::assertTrue($this->verifier()->verify($this->context())->intact());
    }

    public function testDeletingAStoredRowIsDetectedAndTheTrailRecoversWhenItIsRestored(): void
    {
        $identifiers = $this->record(3);
        $row = $this->row($identifiers[1]);
        $this->withoutGuards(function () use ($identifiers): void {
            $this->database->executeStatement(sprintf(
                'DELETE FROM %s WHERE id = ?',
                $this->tables->quoted('audit_events'),
            ), [$identifiers[1]]);
        });

        $report = $this->verifier()->verify($this->context());
        self::assertFalse($report->intact());
        self::assertSame('event.link.unresolved', $report->firstDivergence?->code);
        self::assertSame($identifiers[2], $report->firstDivergence?->eventId);

        $this->database->insert($this->tables->raw('audit_events'), $row);
        self::assertTrue($this->verifier()->verify($this->context())->intact());
    }

    public function testReorderingTwoRowsIsDetectedOnceTheRangeIsAnchored(): void
    {
        $identifiers = $this->record(3, '-2 hours');
        $sequence = $this->anchors()->anchor($this->context());
        self::assertIsInt($sequence);
        self::assertTrue($this->verifier()->verify($this->context())->intact());

        $this->withoutGuards(function () use ($identifiers): void {
            AuditTamperHarness::swapPositions($this->database, $this->tables, $identifiers[0], $identifiers[1]);
        });
        $report = $this->verifier()->verify($this->context());
        self::assertFalse($report->intact());
        self::assertSame('anchor.rolling.mismatch', $report->firstDivergence?->code);

        $this->withoutGuards(function () use ($identifiers): void {
            AuditTamperHarness::swapPositions($this->database, $this->tables, $identifiers[0], $identifiers[1]);
        });
        self::assertTrue($this->verifier()->verify($this->context())->intact());
    }

    public function testTheGuardedRetentionWindowIsTheOnlyPathThatMayDelete(): void
    {
        $identifiers = $this->record(2);
        $row = $this->row($identifiers[0]);

        $deleted = $this->database->transactional(fn (): int => AuditAppendOnlyGuard::withPruneAllowed(
            $this->database,
            $this->tables,
            fn (): int => (int) $this->database->executeStatement(sprintf(
                'DELETE FROM %s WHERE id = ?',
                $this->tables->quoted('audit_events'),
            ), [$identifiers[0]]),
        ));

        self::assertSame(1, $deleted);
        if (AuditAppendOnlyGuard::installed($this->database, $this->tables)) {
            self::assertTrue(
                AuditTamperHarness::deleteIsRefused($this->database, $this->tables, $identifiers[1]),
                'The window must close behind the guarded delete.',
            );
        } else {
            // Nothing was opened, so there is nothing to close; what matters is that the pass did not
            // leave a guard behind it on a server that never had one.
            self::assertSame(
                AuditEnforcementState::NotInstalled,
                AuditAppendOnlyGuard::state($this->database, $this->tables),
                'A prune on an unguarded server must not install a guard on its way out.',
            );
        }
        $this->database->insert($this->tables->raw('audit_events'), $row);
        self::assertTrue($this->verifier()->verify($this->context())->intact());
    }

    public function testVerificationReportsTheEnforcementThisServerIsActuallyApplying(): void
    {
        $this->record(2);
        $observed = AuditAppendOnlyGuard::state($this->database, $this->tables);

        $report = $this->verifier()->verify($this->context());
        self::assertTrue($report->intact(), $report->firstDivergence?->detail ?? '');
        self::assertSame($observed, $report->enforcement, 'The report must follow the server.');
        self::assertSame($observed->installed(), $report->guarded());

        if (!$observed->installed()) {
            // The interesting property on a server that cannot install the guards is that nothing
            // talks itself into claiming otherwise: a repeated install attempt keeps reporting the
            // refusal rather than quietly returning success.
            self::assertSame(
                AuditEnforcementState::NotInstalled,
                AuditAppendOnlyGuard::install($this->database, $this->tables),
                'A server that refuses the guards must never be reported as having accepted them.',
            );

            return;
        }

        $this->withoutGuards(function (): void {
            $report = $this->verifier()->verify($this->context());
            self::assertSame(
                AuditEnforcementState::NotInstalled,
                $report->enforcement,
                'The report must follow the server, not whatever the migration once managed to do.',
            );
            self::assertTrue($report->intact(), 'Removing prevention does not damage the evidence.');
            self::assertFalse($report->guarded(), 'An unguarded server must never read as a guarded one.');
        });

        self::assertSame(AuditEnforcementState::Active, $this->verifier()->verify($this->context())->enforcement);
    }

    public function testTheRetentionWindowDeletesCorrectlyOnAServerWithNoGuardsToOpen(): void
    {
        $identifiers = $this->record(2);
        $row = $this->row($identifiers[0]);

        $this->withoutGuards(function () use ($identifiers): void {
            $deleted = $this->database->transactional(fn (): int => AuditAppendOnlyGuard::withPruneAllowed(
                $this->database,
                $this->tables,
                fn (): int => (int) $this->database->executeStatement(sprintf(
                    'DELETE FROM %s WHERE id = ?',
                    $this->tables->quoted('audit_events'),
                ), [$identifiers[0]]),
            ));

            self::assertSame(1, $deleted, 'The sanctioned prune must still remove exactly its range.');
            self::assertSame(
                AuditEnforcementState::NotInstalled,
                AuditAppendOnlyGuard::state($this->database, $this->tables),
                'Closing a window that was never open must not install a guard as a side effect.',
            );
        });

        $this->database->insert($this->tables->raw('audit_events'), $row);
        self::assertTrue($this->verifier()->verify($this->context())->intact());
    }

    public function testTheExportWritesAChecksummedArchiveAndRecordsItself(): void
    {
        $this->record(2);
        $head = (int) $this->database->fetchOne(sprintf(
            'SELECT MAX(position) FROM %s',
            $this->tables->quoted('audit_events'),
        ));

        $export = $this->exporter()->export($this->context(), max(1, $head - 1), $head);

        self::assertSame(2, $export->eventCount);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $export->archive->checksum);
        self::assertSame('1', (string) $this->database->fetchOne(sprintf(
            "SELECT COUNT(*) FROM %s WHERE action = 'audit.trail.exported' AND subject_id = ?",
            $this->tables->quoted('audit_events'),
        ), [explode('.', $export->archive->key)[0]]));
        self::assertTrue($this->verifier()->verify($this->context())->intact());
    }

    /**
     * @return list<string>
     */
    private function record(int $count, string $shift = '-30 seconds'): array
    {
        $recorder = $this->container->get(AuditRecorder::class);
        $clock = $this->container->get(ClockInterface::class);
        self::assertInstanceOf(AuditRecorder::class, $recorder);
        self::assertInstanceOf(ClockInterface::class, $clock);
        $identifiers = [];
        $now = $clock->now()->modify($shift);
        for ($index = 0; $index < $count; $index++) {
            $id = Uuid::uuid7()->toString();
            $identifiers[] = $id;
            $recorder->record(new AuditEvent(
                $id,
                $now->modify(sprintf('+%d seconds', $index)),
                'integration-actor',
                'identity.user.activated',
                'identity.user',
                'integration-subject-' . $index,
                'success',
                ['request_id' => 'audit-integration-' . $index],
            ));
        }

        return $identifiers;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $id): array
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT id, occurred_at, actor_id, action, subject_type, subject_id, outcome, metadata, '
            . 'position, digest, previous_digest FROM %s WHERE id = ?',
            $this->tables->quoted('audit_events'),
        ), [$id]);
        self::assertIsArray($row);
        if (is_resource($row['metadata'])) {
            $row['metadata'] = stream_get_contents($row['metadata']);
        }

        return $row;
    }

    private function withoutGuards(callable $operation): void
    {
        AuditTamperHarness::disableGuards($this->database, $this->tables);
        try {
            $operation();
        } finally {
            AuditTamperHarness::enableGuards($this->database, $this->tables);
        }
    }

    private function positionIsDatabaseAllocated(): bool
    {
        $platform = $this->database->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $extra = $this->database->fetchOne(
                'SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() '
                . 'AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$this->tables->raw('audit_events'), 'position'],
            );

            return is_string($extra) && str_contains(strtolower($extra), 'auto_increment');
        }
        if ($platform instanceof PostgreSQLPlatform) {
            return in_array($this->database->fetchOne(
                'SELECT is_identity FROM information_schema.columns WHERE table_schema = current_schema() '
                . 'AND table_name = ? AND column_name = ?',
                [$this->tables->raw('audit_events'), 'position'],
            ), ['YES', 'yes', true], true);
        }

        return true;
    }

    private function recorder(): AuditRecorder
    {
        $recorder = $this->container->get(AuditRecorder::class);
        self::assertInstanceOf(AuditRecorder::class, $recorder);

        return $recorder;
    }

    private function clock(): ClockInterface
    {
        $clock = $this->container->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);

        return $clock;
    }

    private function verifier(): AuditTrailVerifier
    {
        $verifier = $this->container->get(AuditTrailVerifier::class);
        self::assertInstanceOf(AuditTrailVerifier::class, $verifier);

        return $verifier;
    }

    private function anchors(): AuditAnchorWriter
    {
        $anchors = $this->container->get(AuditAnchorWriter::class);
        self::assertInstanceOf(AuditAnchorWriter::class, $anchors);

        return $anchors;
    }

    private function exporter(): AuditTrailExporter
    {
        $exporter = $this->container->get(AuditTrailExporter::class);
        self::assertInstanceOf(AuditTrailExporter::class, $exporter);

        return $exporter;
    }

    private function context(): ExecutionContext
    {
        $principals = $this->container->get(GlobalJobPrincipals::class);
        $scope = $this->container->get(JobExecutionScope::class);
        self::assertInstanceOf(GlobalJobPrincipals::class, $principals);
        self::assertInstanceOf(JobExecutionScope::class, $scope);

        return $principals->context(
            AuditTamperEvidenceMigration::ANCHOR_JOB_TYPE,
            $scope,
            'audit-integration-' . bin2hex(random_bytes(8)),
            'audit-integration',
        );
    }
}
