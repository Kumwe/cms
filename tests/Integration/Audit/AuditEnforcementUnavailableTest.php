<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Audit;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\SyntaxErrorException;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Application\Authorization\SystemPrincipal;
use Kumwe\App\Application\Automation\Job\VerifyAuditTrailHandler;
use Kumwe\App\Audit\Domain\AuditEnforcementState;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Audit\Infrastructure\Persistence\AuditAppendOnlyGuard;
use Kumwe\App\Audit\Infrastructure\Persistence\AuditEnforcementRefusal;
use Kumwe\App\Audit\Infrastructure\Persistence\DoctrineAuditAnchorWriter;
use Kumwe\App\Audit\Infrastructure\Persistence\DoctrineAuditRecorder;
use Kumwe\App\Audit\Infrastructure\Persistence\DoctrineAuditRetentionService;
use Kumwe\App\Audit\Infrastructure\Persistence\DoctrineAuditTrailExporter;
use Kumwe\App\Audit\Infrastructure\Persistence\DoctrineAuditTrailVerifier;
use Kumwe\App\Audit\Infrastructure\Storage\FilesystemAuditArchiveStorage;
use Kumwe\App\Delivery\Console\Command\VerifyAuditTrailCommand;
use Kumwe\App\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\App\Infrastructure\Persistence\Migration\AuditTamperEvidenceMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\CoreSchemaMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\InstallationGlobalAutomationMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Tests\Support\AllowingAuditAuthorization;
use Kumwe\App\Tests\Support\MovableAuditClock;
use Kumwe\App\Tests\Support\TriggerRefusingConnection;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Proves the platform installs, records, verifies and prunes on a server that refuses trigger creation.
 *
 * This is the regression suite for the failure that made the migration abort on MySQL 8.4: with binary
 * logging enabled and no `SUPER`, `CREATE TRIGGER` answers 1419, and the migration used to let that
 * escape — which made Kumwe uninstallable on Amazon RDS, Cloud SQL and Azure Database for MySQL. Every
 * test here runs against a real database through a connection that refuses exactly that one statement.
 */
#[CoversClass(AuditTamperEvidenceMigration::class)]
#[CoversClass(AuditAppendOnlyGuard::class)]
#[CoversClass(AuditEnforcementRefusal::class)]
#[CoversClass(DoctrineAuditRecorder::class)]
#[CoversClass(DoctrineAuditTrailVerifier::class)]
#[CoversClass(DoctrineAuditRetentionService::class)]
#[CoversClass(VerifyAuditTrailCommand::class)]
final class AuditEnforcementUnavailableTest extends TestCase
{
    private TriggerRefusingConnection $database;

    private TableNames $tables;

    private DoctrineTransactionManager $transactions;

    private DoctrineAuditRecorder $recorder;

    private MovableAuditClock $clock;

    private string $archiveRoot;

    protected function setUp(): void
    {
        $database = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
            'wrapperClass' => TriggerRefusingConnection::class,
        ]);
        self::assertInstanceOf(TriggerRefusingConnection::class, $database);
        $this->database = $database;
        $this->tables = new TableNames($this->database, 'kumwe_');
        $this->transactions = new DoctrineTransactionManager($this->database);
        $this->recorder = new DoctrineAuditRecorder($this->database, $this->tables);
        $this->clock = new MovableAuditClock(new DateTimeImmutable('2026-08-13 09:00:00', new DateTimeZone('UTC')));
        $this->archiveRoot = sys_get_temp_dir() . '/kumwe-audit-degraded-' . bin2hex(random_bytes(8));
        (new CoreSchemaMigration($this->tables))->up($this->database);
        (new InstallationGlobalAutomationMigration($this->tables))->up($this->database);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->archiveRoot . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->archiveRoot)) {
            rmdir($this->archiveRoot);
        }
    }

    public function testTheMigrationCompletesWhenTheServerRefusesToCreateTheTriggers(): void
    {
        $migration = new AuditTamperEvidenceMigration($this->tables);

        $migration->up($this->database);
        // Repeatable: a replay on a still-unprivileged server must be just as uneventful.
        $migration->up($this->database);

        $schema = $this->database->createSchemaManager();
        $events = $schema->introspectTableByUnquotedName($this->tables->raw('audit_events'));
        foreach (['position', 'digest', 'previous_digest'] as $column) {
            self::assertTrue($events->hasColumn($column), sprintf('Column %s is missing.', $column));
        }
        self::assertTrue($schema->tablesExist([$this->tables->raw('audit_anchors')]));
        self::assertSame(
            AuditEnforcementState::NotInstalled,
            AuditAppendOnlyGuard::state($this->database, $this->tables),
            'A server that refused the triggers must never report enforcement as active.',
        );
    }

    public function testTheMigrationStillAbortsWhenTriggerCreationFailsForAnyOtherReason(): void
    {
        $this->database->privilegeRefusal = false;

        $this->expectException(SyntaxErrorException::class);

        (new AuditTamperEvidenceMigration($this->tables))->up($this->database);
    }

    public function testTheTrailIsStillChainedAnchoredAndVerifiableWithoutEnforcement(): void
    {
        $this->migrate();
        $identifiers = $this->record(4);
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT id, position, digest, previous_digest FROM %s ORDER BY position ASC',
            $this->tables->quoted('audit_events'),
        ));

        self::assertSame($identifiers, array_column($rows, 'id'));
        foreach ([1, 2, 3] as $index) {
            self::assertSame($rows[$index - 1]['digest'], $rows[$index]['previous_digest']);
        }
        $this->clock->advance('+40 days');
        self::assertSame(1, $this->anchorWriter()->anchor($this->context()));
        $report = $this->verifier()->verify($this->context());
        self::assertTrue($report->intact(), $report->firstDivergence?->detail ?? '');
        // Four recorded events plus the anchor pass recording itself, which is also part of the trail.
        self::assertSame(5, $report->eventsVerified);
        self::assertSame(1, $report->anchorsVerified);
        // The scheduled pass stays quiet: an absent control is a standing condition, not an incident.
        (new VerifyAuditTrailHandler($this->verifier()))->handle([], $this->context());
    }

    public function testVerificationReportsTheDegradedStateRatherThanACleanBillOfHealth(): void
    {
        $this->migrate();
        $this->record(2);

        $report = $this->verifier()->verify($this->context());

        self::assertTrue($report->intact(), 'The chain itself is sound.');
        self::assertFalse($report->guarded(), 'Nothing is preventing a rewrite on this server.');
        self::assertSame(AuditEnforcementState::NotInstalled, $report->enforcement);
        self::assertStringContainsString('NOT installed', $report->enforcement->summary());
    }

    public function testTamperingIsStillDetectedWhenNothingIsThereToPreventIt(): void
    {
        $this->migrate();
        $identifiers = $this->record(3);
        // No guards exist, so the rewrite the database would normally refuse simply succeeds.
        $this->database->executeStatement(sprintf(
            'UPDATE %s SET outcome = ? WHERE id = ?',
            $this->tables->quoted('audit_events'),
        ), ['denied', $identifiers[1]]);

        $report = $this->verifier()->verify($this->context());

        self::assertFalse($report->intact());
        self::assertSame('event.digest.mismatch', $report->firstDivergence?->code);
        self::assertSame($identifiers[1], $report->firstDivergence?->eventId);
    }

    public function testRetentionPrunesCorrectlyAndInstallsNoGuardOnItsWayOut(): void
    {
        $this->migrate();
        $this->record(3);
        $this->clock->advance('+40 days');
        self::assertSame(1, $this->anchorWriter()->anchor($this->context()));
        $this->record(2);
        $this->clock->advance('+2 hours');

        $result = $this->retention()->prune($this->context(), 30);

        self::assertSame(3, $result->prunedCount);
        self::assertSame(1, $result->fromPosition);
        self::assertSame(3, $result->toPosition);
        self::assertFileExists($this->archiveRoot . '/' . (string) $result->archiveKey);
        self::assertSame('0', (string) $this->database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE position <= 3',
            $this->tables->quoted('audit_events'),
        )));
        self::assertSame(
            AuditEnforcementState::NotInstalled,
            AuditAppendOnlyGuard::state($this->database, $this->tables),
            'Closing a window that was never open must not conjure a guard into existence.',
        );
        self::assertSame('0', (string) $this->database->fetchOne(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger'",
        ));
        self::assertTrue($this->verifier()->verify($this->context())->intact());
    }

    public function testTheConsoleVerdictSeparatesTheDegradedTrailFromAGuardedOne(): void
    {
        $this->migrate();
        $this->record(2);

        $report = $this->verifier()->verify($this->context());

        self::assertSame('not_installed', $report->enforcement->value);
        self::assertNotSame(
            AuditEnforcementState::Active->summary(),
            $report->enforcement->summary(),
            'The two postures must never read alike.',
        );
    }

    /** Applies the tamper-evidence migration on the refusing connection. */
    private function migrate(): void
    {
        (new AuditTamperEvidenceMigration($this->tables))->up($this->database);
    }

    /** @return list<string> */
    private function record(int $count): array
    {
        $identifiers = [];
        for ($index = 0; $index < $count; $index++) {
            $id = Uuid::uuid7()->toString();
            $identifiers[] = $id;
            $this->recorder->record(new AuditEvent(
                $id,
                $this->clock->now(),
                'actor-' . $index,
                'identity.user.activated',
                'identity.user',
                'subject-' . $index,
                'success',
                ['request_id' => 'request-' . $index],
            ));
            $this->clock->advance('+1 minute');
        }

        return $identifiers;
    }

    private function verifier(): DoctrineAuditTrailVerifier
    {
        return new DoctrineAuditTrailVerifier($this->database, $this->tables, new AllowingAuditAuthorization());
    }

    private function anchorWriter(): DoctrineAuditAnchorWriter
    {
        return new DoctrineAuditAnchorWriter(
            $this->database,
            $this->tables,
            $this->transactions,
            $this->recorder,
            $this->clock,
            new AllowingAuditAuthorization(),
        );
    }

    private function retention(): DoctrineAuditRetentionService
    {
        return new DoctrineAuditRetentionService(
            $this->database,
            $this->tables,
            $this->transactions,
            new DoctrineAuditTrailExporter(
                $this->database,
                $this->tables,
                $this->transactions,
                new FilesystemAuditArchiveStorage($this->archiveRoot),
                $this->recorder,
                $this->clock,
                new AllowingAuditAuthorization(),
            ),
            $this->recorder,
            $this->clock,
            new AllowingAuditAuthorization(),
        );
    }

    private function context(): ExecutionContext
    {
        return SystemPrincipal::issue(new \stdClass(), SystemIdentity::InstallationMaintenance)->context(
            SiteContext::default(),
            'audit-degraded-' . bin2hex(random_bytes(8)),
        );
    }
}
