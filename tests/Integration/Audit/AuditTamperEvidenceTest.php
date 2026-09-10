<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Audit;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Application\Authorization\SystemPrincipal;
use Kumwe\App\Application\Automation\Job\EnforceAuditRetentionHandler;
use Kumwe\App\Application\Automation\Job\RecordAuditAnchorHandler;
use Kumwe\App\Application\Automation\Job\VerifyAuditTrailHandler;
use Kumwe\App\Audit\Application\AuditMetadataRedactor;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Audit\Infrastructure\Persistence\AuditAppendOnlyGuard;
use Kumwe\App\Audit\Infrastructure\Persistence\DoctrineAuditAnchorWriter;
use Kumwe\App\Audit\Infrastructure\Persistence\DoctrineAuditRecorder;
use Kumwe\App\Audit\Infrastructure\Persistence\DoctrineAuditRetentionService;
use Kumwe\App\Audit\Infrastructure\Persistence\DoctrineAuditTrailExporter;
use Kumwe\App\Audit\Infrastructure\Persistence\DoctrineAuditTrailVerifier;
use Kumwe\App\Audit\Infrastructure\Storage\FilesystemAuditArchiveStorage;
use Kumwe\App\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\App\Infrastructure\Persistence\Migration\AuditTamperEvidenceMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\CoreSchemaMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\InstallationGlobalAutomationMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Tests\Support\AllowingAuditAuthorization;
use Kumwe\App\Tests\Support\AuditTamperHarness;
use Kumwe\App\Tests\Support\MovableAuditClock;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

#[CoversClass(AuditTamperEvidenceMigration::class)]
#[CoversClass(AuditAppendOnlyGuard::class)]
#[CoversClass(DoctrineAuditRecorder::class)]
#[CoversClass(DoctrineAuditAnchorWriter::class)]
#[CoversClass(DoctrineAuditTrailVerifier::class)]
#[CoversClass(DoctrineAuditTrailExporter::class)]
#[CoversClass(DoctrineAuditRetentionService::class)]
#[CoversClass(FilesystemAuditArchiveStorage::class)]
#[CoversClass(RecordAuditAnchorHandler::class)]
#[CoversClass(VerifyAuditTrailHandler::class)]
#[CoversClass(EnforceAuditRetentionHandler::class)]
final class AuditTamperEvidenceTest extends TestCase
{
    private Connection $database;

    private TableNames $tables;

    private DoctrineTransactionManager $transactions;

    private DoctrineAuditRecorder $recorder;

    private MovableAuditClock $clock;

    private string $archiveRoot;

    protected function setUp(): void
    {
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->tables = new TableNames($this->database, 'kumwe_');
        $this->transactions = new DoctrineTransactionManager($this->database);
        $this->recorder = new DoctrineAuditRecorder($this->database, $this->tables);
        $this->clock = new MovableAuditClock(new DateTimeImmutable('2026-08-13 09:00:00', new DateTimeZone('UTC')));
        $this->archiveRoot = sys_get_temp_dir() . '/kumwe-audit-' . bin2hex(random_bytes(8));
        (new CoreSchemaMigration($this->tables))->up($this->database);
        (new InstallationGlobalAutomationMigration($this->tables))->up($this->database);
        $migration = new AuditTamperEvidenceMigration($this->tables);
        $migration->up($this->database);
        // The migration declares itself repeatable, so a replayed attempt must be a no-op.
        $migration->up($this->database);
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

    public function testRecordedEventsAreChainedPositionedAndVerifiable(): void
    {
        $identifiers = $this->record(5);
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT id, position, digest, previous_digest FROM %s ORDER BY position ASC',
            $this->tables->quoted('audit_events'),
        ));

        self::assertCount(5, $rows);
        self::assertSame($identifiers, array_column($rows, 'id'));
        self::assertSame(['1', '2', '3', '4', '5'], array_map(strval(...), array_column($rows, 'position')));
        self::assertNull($rows[0]['previous_digest']);
        foreach ([1, 2, 3, 4] as $index) {
            self::assertSame($rows[$index - 1]['digest'], $rows[$index]['previous_digest']);
        }
        $report = $this->verifier()->verify($this->context());
        self::assertTrue($report->intact());
        self::assertSame(5, $report->eventsVerified);
        self::assertSame(5, $report->headPosition);
    }

    public function testTheDatabaseRefusesUpdatesAndUnguardedDeletes(): void
    {
        $identifiers = $this->record(2);

        self::assertTrue(AuditAppendOnlyGuard::installed($this->database, $this->tables));
        self::assertTrue(AuditTamperHarness::updateIsRefused($this->database, $this->tables, $identifiers[0]));
        self::assertTrue(AuditTamperHarness::deleteIsRefused($this->database, $this->tables, $identifiers[0]));
        self::assertSame('2', (string) $this->database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s',
            $this->tables->quoted('audit_events'),
        )));
    }

    public function testMutationOfAStoredRowIsDetected(): void
    {
        $identifiers = $this->record(4);
        AuditTamperHarness::disableGuards($this->database, $this->tables);
        $this->database->executeStatement(sprintf(
            'UPDATE %s SET outcome = ? WHERE id = ?',
            $this->tables->quoted('audit_events'),
        ), ['denied', $identifiers[2]]);

        $report = $this->verifier()->verify($this->context());

        self::assertFalse($report->intact());
        self::assertSame('event.digest.mismatch', $report->firstDivergence?->code);
        self::assertSame($identifiers[2], $report->firstDivergence?->eventId);
        self::assertSame(3, $report->firstDivergence?->position);
    }

    public function testDeletionOfAStoredRowIsDetectedByTheBrokenWitnessLink(): void
    {
        $identifiers = $this->record(4);
        AuditTamperHarness::disableGuards($this->database, $this->tables);
        $this->database->executeStatement(sprintf(
            'DELETE FROM %s WHERE id = ?',
            $this->tables->quoted('audit_events'),
        ), [$identifiers[1]]);

        $report = $this->verifier()->verify($this->context());

        self::assertFalse($report->intact());
        self::assertSame('event.link.unresolved', $report->firstDivergence?->code);
        self::assertSame($identifiers[2], $report->firstDivergence?->eventId);
    }

    public function testDeletionInsideAnAnchoredRangeIsDetectedByTheAnchorCount(): void
    {
        $identifiers = $this->record(4);
        $this->clock->advance('+2 hours');
        self::assertSame(1, $this->anchorWriter()->anchor($this->context()));
        AuditTamperHarness::disableGuards($this->database, $this->tables);
        // Remove the row and the link that names it, so only the anchor can still tell.
        $this->database->executeStatement(sprintf(
            'DELETE FROM %s WHERE id = ?',
            $this->tables->quoted('audit_events'),
        ), [$identifiers[1]]);
        $this->database->executeStatement(sprintf(
            'UPDATE %s SET previous_digest = (SELECT digest FROM %s WHERE id = ?) WHERE id = ?',
            $this->tables->quoted('audit_events'),
            $this->tables->quoted('audit_events'),
        ), [$identifiers[0], $identifiers[2]]);

        $report = $this->verifier()->verify($this->context());

        self::assertFalse($report->intact());
        self::assertSame('anchor.count.mismatch', $report->firstDivergence?->code);
    }

    public function testReorderingTwoRowsIsDetectedByTheAnchoredRollingDigest(): void
    {
        $identifiers = $this->record(4);
        $this->clock->advance('+2 hours');
        $this->anchorWriter()->anchor($this->context());
        AuditTamperHarness::disableGuards($this->database, $this->tables);
        AuditTamperHarness::swapPositions($this->database, $this->tables, $identifiers[1], $identifiers[2]);

        $report = $this->verifier()->verify($this->context());

        self::assertFalse($report->intact());
        self::assertSame('anchor.rolling.mismatch', $report->firstDivergence?->code);
    }

    public function testInsertingARowIntoAnAnchoredRangeIsDetected(): void
    {
        $this->record(4);
        $this->clock->advance('+2 hours');
        $this->anchorWriter()->anchor($this->context());
        $this->database->insert($this->tables->raw('audit_events'), [
            'id' => Uuid::uuid7()->toString(),
            'occurred_at' => '2026-08-13 09:00:00',
            'actor_id' => 'forged-actor',
            'action' => 'identity.user.activated',
            'subject_type' => 'identity.user',
            'subject_id' => 'forged-subject',
            'outcome' => 'success',
            'metadata' => '{}',
            'digest' => str_repeat('0', 64),
            'previous_digest' => null,
            'position' => 3_000,
        ]);
        $this->database->executeStatement(sprintf(
            'UPDATE %s SET to_position = 3000 WHERE sequence = 1',
            $this->tables->quoted('audit_anchors'),
        ));

        $report = $this->verifier()->verify($this->context());

        self::assertFalse($report->intact());
        self::assertSame('anchor.digest.mismatch', $report->firstDivergence?->code);
    }

    public function testATamperedAnchorLedgerIsDetectedBeforeTheTrailIsWalked(): void
    {
        $this->record(3);
        $this->clock->advance('+2 hours');
        $this->anchorWriter()->anchor($this->context());
        $this->database->executeStatement(sprintf(
            'UPDATE %s SET row_count = row_count - 1 WHERE sequence = 1',
            $this->tables->quoted('audit_anchors'),
        ));

        $report = $this->verifier()->verify($this->context());

        self::assertFalse($report->intact());
        self::assertSame('anchor.digest.mismatch', $report->firstDivergence?->code);
    }

    public function testAnchoringSkipsRowsInsideTheSettleWindowAndResumesAfterThem(): void
    {
        $this->record(2);
        self::assertNull($this->anchorWriter()->anchor($this->context()), 'Unsettled rows must not be sealed.');
        $this->clock->advance('+2 hours');
        self::assertSame(1, $this->anchorWriter()->anchor($this->context()));
        $first = $this->database->fetchAssociative(sprintf(
            'SELECT from_position, to_position, row_count FROM %s WHERE sequence = 1',
            $this->tables->quoted('audit_anchors'),
        ));
        self::assertNotFalse($first);
        self::assertSame('1', (string) $first['from_position']);
        self::assertSame('2', (string) $first['to_position']);
        $this->record(2);
        $this->clock->advance('+2 hours');
        self::assertSame(2, $this->anchorWriter()->anchor($this->context()));
        $second = $this->database->fetchAssociative(sprintf(
            'SELECT from_position, to_position, previous_digest FROM %s WHERE sequence = 2',
            $this->tables->quoted('audit_anchors'),
        ));
        self::assertNotFalse($second);
        self::assertSame('3', (string) $second['from_position']);
        self::assertSame($this->database->fetchOne(sprintf(
            'SELECT digest FROM %s WHERE sequence = 1',
            $this->tables->quoted('audit_anchors'),
        )), $second['previous_digest']);
        self::assertTrue($this->verifier()->verify($this->context())->intact());
    }

    public function testTheExportWritesARedactedChecksummedArchiveAndAuditsItself(): void
    {
        $this->recorder->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $this->clock->now(),
            'actor-1',
            'identity.token.issued',
            'api_token',
            'token-1',
            'success',
            ['token' => 'plaintext-should-never-leave', 'request_id' => 'request-1'],
        ));

        $export = $this->exporter()->export($this->context());

        self::assertSame(1, $export->eventCount);
        self::assertSame(1, $export->redactedCount);
        $path = $this->archiveRoot . '/' . $export->archive->key;
        self::assertFileExists($path);
        self::assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
        $contents = (string) file_get_contents($path);
        self::assertSame($export->archive->checksum, hash('sha256', $contents));
        self::assertStringNotContainsString('plaintext-should-never-leave', $contents);
        self::assertStringContainsString(AuditMetadataRedactor::PLACEHOLDER, $contents);
        $lines = array_values(array_filter(explode("\n", $contents)));
        self::assertCount(2, $lines);
        $manifest = json_decode($lines[0], true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertSame(1, $manifest['kumwe_audit_archive']);
        self::assertSame('request-1', json_decode($lines[1], true, 8, JSON_THROW_ON_ERROR)['metadata']['request_id']);
        $recorded = $this->database->fetchAssociative(sprintf(
            'SELECT action, subject_type, metadata FROM %s ORDER BY position DESC LIMIT 1',
            $this->tables->quoted('audit_events'),
        ));
        self::assertNotFalse($recorded);
        self::assertSame('audit.trail.exported', $recorded['action']);
        self::assertStringContainsString($export->archive->checksum, (string) $recorded['metadata']);
        self::assertTrue($this->verifier()->verify($this->context())->intact());
    }

    public function testRetentionArchivesAnchorsAndPrunesAgedRowsAndStaysVerifiable(): void
    {
        $this->record(3);
        $this->clock->advance('+40 days');
        self::assertSame(1, $this->anchorWriter()->anchor($this->context()));
        $this->record(2);
        $this->clock->advance('+2 hours');

        $result = $this->retention()->prune($this->context(), 30);

        self::assertSame(3, $result->prunedCount);
        self::assertSame(1, $result->fromPosition);
        self::assertSame(3, $result->toPosition);
        self::assertNotNull($result->archiveSha256);
        self::assertFileExists($this->archiveRoot . '/' . (string) $result->archiveKey);
        self::assertSame('0', (string) $this->database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE position <= 3',
            $this->tables->quoted('audit_events'),
        )));
        $mark = $this->database->fetchAssociative(sprintf(
            'SELECT kind, from_position, to_position, row_count, archive_sha256 FROM %s WHERE sequence = ?',
            $this->tables->quoted('audit_anchors'),
        ), [$result->pruneSequence]);
        self::assertNotFalse($mark);
        self::assertSame('prune', $mark['kind']);
        self::assertSame('3', (string) $mark['row_count']);
        self::assertSame($result->archiveSha256, $mark['archive_sha256']);
        self::assertSame('1', (string) $this->database->fetchOne(sprintf(
            "SELECT COUNT(*) FROM %s WHERE action = 'audit.trail.pruned'",
            $this->tables->quoted('audit_events'),
        )));
        self::assertTrue(
            AuditAppendOnlyGuard::installed($this->database, $this->tables),
            'The retention window must close behind itself.',
        );
        self::assertTrue($this->verifier()->verify($this->context())->intact());
    }

    public function testReinsertingAPrunedRowIsDetected(): void
    {
        $this->record(3);
        $this->clock->advance('+40 days');
        $this->anchorWriter()->anchor($this->context());
        $this->record(1);
        $this->clock->advance('+2 hours');
        $this->retention()->prune($this->context(), 30);
        $this->database->insert($this->tables->raw('audit_events'), [
            'id' => Uuid::uuid7()->toString(),
            'occurred_at' => '2026-08-13 09:00:00',
            'actor_id' => 'forged-actor',
            'action' => 'identity.user.activated',
            'subject_type' => 'identity.user',
            'subject_id' => 'forged-subject',
            'outcome' => 'success',
            'metadata' => '{}',
            'digest' => str_repeat('0', 64),
            'previous_digest' => null,
            'position' => 2,
        ]);

        $report = $this->verifier()->verify($this->context());

        self::assertFalse($report->intact());
        self::assertSame('anchor.prune.reinserted', $report->firstDivergence?->code);
    }

    public function testTheScheduledHandlersDriveTheSameControlsAndRetentionIsOffByDefault(): void
    {
        $this->record(2);
        $this->clock->advance('+40 days');
        (new RecordAuditAnchorHandler($this->anchorWriter()))->handle([], $this->context());
        self::assertSame('1', (string) $this->database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s',
            $this->tables->quoted('audit_anchors'),
        )));
        (new VerifyAuditTrailHandler($this->verifier()))->handle(['batch_size' => 2], $this->context());
        (new EnforceAuditRetentionHandler($this->retention()))->handle([], $this->context());
        self::assertSame('0', (string) $this->database->fetchOne(sprintf(
            "SELECT COUNT(*) FROM %s WHERE kind = 'prune'",
            $this->tables->quoted('audit_anchors'),
        )), 'An unconfigured retention window must never prune.');

        $retentionSchedule = $this->database->fetchAssociative(sprintf(
            'SELECT enabled, payload, execution_scope FROM %s WHERE job_type = ?',
            $this->tables->quoted('schedules'),
        ), [AuditTamperEvidenceMigration::RETENTION_JOB_TYPE]);
        self::assertNotFalse($retentionSchedule);
        self::assertContains($retentionSchedule['enabled'], [0, '0', false], 'Retention ships disabled.');
        self::assertSame('{"retention_days":0}', $retentionSchedule['payload']);
        self::assertSame('installation', $retentionSchedule['execution_scope']);
    }

    public function testTheVerificationHandlerFailsLoudlyOnADivergentTrail(): void
    {
        $identifiers = $this->record(2);
        AuditTamperHarness::disableGuards($this->database, $this->tables);
        $this->database->executeStatement(sprintf(
            'UPDATE %s SET action = ? WHERE id = ?',
            $this->tables->quoted('audit_events'),
        ), ['identity.user.deactivated', $identifiers[1]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/event\.digest\.mismatch/');

        (new VerifyAuditTrailHandler($this->verifier()))->handle([], $this->context());
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

    private function exporter(): DoctrineAuditTrailExporter
    {
        return new DoctrineAuditTrailExporter(
            $this->database,
            $this->tables,
            $this->transactions,
            new FilesystemAuditArchiveStorage($this->archiveRoot),
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
            $this->exporter(),
            $this->recorder,
            $this->clock,
            new AllowingAuditAuthorization(),
        );
    }

    private function context(): ExecutionContext
    {
        return SystemPrincipal::issue(new \stdClass(), SystemIdentity::InstallationMaintenance)->context(
            SiteContext::default(),
            'audit-test-' . bin2hex(random_bytes(8)),
        );
    }
}
