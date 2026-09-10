<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessReporting;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Audit\Infrastructure\Persistence\DoctrineAuditRecorder;
use Kumwe\App\BusinessReporting\Application\ExportVersionConflict;
use Kumwe\App\BusinessReporting\Domain\ExportArtifact;
use Kumwe\App\BusinessReporting\Domain\ExportArtifactStatus;
use Kumwe\App\BusinessReporting\Infrastructure\DoctrineExportArtifactRepository;
use Kumwe\App\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\App\Infrastructure\Persistence\Migration\BusinessIntegrationSdkMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\AuditTamperEvidenceMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\CoreSchemaMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\InstallationGlobalAutomationMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RuntimeException;

#[CoversClass(DoctrineExportArtifactRepository::class)]
#[CoversClass(BusinessIntegrationSdkMigration::class)]
final class ExportArtifactPersistenceTest extends TestCase
{
    /**
     * @var    list<array{capability: string, scope_type: string, scope_identifier: ?string}>
     */
    private const AUTHORITY_GRANTS = [[
        'capability' => 'business.record.export',
        'scope_type' => 'global',
        'scope_identifier' => null,
    ]];

    private Connection $database;

    private TableNames $tables;

    private DoctrineTransactionManager $transactions;

    private DoctrineExportArtifactRepository $artifacts;

    protected function setUp(): void
    {
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->tables = new TableNames($this->database, 'kumwe_');
        $this->transactions = new DoctrineTransactionManager($this->database);
        $this->artifacts = new DoctrineExportArtifactRepository(
            $this->database,
            $this->tables,
            $this->transactions,
        );
        (new CoreSchemaMigration($this->tables))->up($this->database);
        (new InstallationGlobalAutomationMigration($this->tables))->up($this->database);
        (new AuditTamperEvidenceMigration($this->tables))->up($this->database);
        $migration = new BusinessIntegrationSdkMigration($this->tables);
        $migration->up($this->database);
        $migration->up($this->database);
    }

    public function testMetadataAuditAndDatabaseJobCommitOrRollbackAsOneUnit(): void
    {
        $audit = new DoctrineAuditRecorder($this->database, $this->tables);
        $rolledBack = $this->artifact();

        try {
            $this->transactions->transactional(function () use ($audit, $rolledBack): void {
                $this->artifacts->add($rolledBack);
                $audit->record($this->audit($rolledBack));
                $this->insertExportJob($rolledBack);
                throw new RuntimeException('fail after every durable write');
            });
            self::fail('The transaction must be rolled back.');
        } catch (RuntimeException $exception) {
            self::assertSame('fail after every durable write', $exception->getMessage());
        }

        self::assertSame(0, $this->rowCount('business_report_export_artifacts'));
        self::assertSame(0, $this->rowCount('audit_events'));
        self::assertSame(0, $this->rowCount('jobs'));
        self::assertNull($this->artifacts->find($rolledBack->id));

        $committed = $this->artifact();
        $this->transactions->transactional(function () use ($audit, $committed): void {
            $this->artifacts->add($committed);
            $audit->record($this->audit($committed));
            $this->insertExportJob($committed);
        });

        self::assertSame(1, $this->rowCount('business_report_export_artifacts'));
        self::assertSame(1, $this->rowCount('audit_events'));
        self::assertSame(1, $this->rowCount('jobs'));
        self::assertSame($committed->toArray(), $this->artifacts->find($committed->id)?->toArray());
    }

    public function testAppendOnlyVersionsRoundTripEmptyParametersAndRejectStaleWriters(): void
    {
        $queued = $this->artifact(authorityGrantRows: self::AUTHORITY_GRANTS);
        $this->artifacts->add($queued);
        $running = $queued->start(new DateTimeImmutable('2026-08-10T10:01:00+00:00'));
        $this->artifacts->save($running, 1);

        self::assertSame([], $this->artifacts->find($queued->id)?->parameters);
        self::assertSame(self::AUTHORITY_GRANTS, $this->artifacts->find($queued->id)?->authorityGrantRows);
        self::assertSame(ExportArtifactStatus::Running, $this->artifacts->find($queued->id)?->status);
        self::assertSame(2, $this->artifacts->find($queued->id)?->version);

        try {
            $this->artifacts->save(
                $queued->fail(new DateTimeImmutable('2026-08-10T10:02:00+00:00'), 'stale_worker'),
                1,
            );
            self::fail('A stale metadata transition must be rejected.');
        } catch (ExportVersionConflict) {
        }

        $completed = $running->complete(
            new DateTimeImmutable('2026-08-10T10:03:00+00:00'),
            strtolower($queued->id) . '.' . str_repeat('1', 32) . '.csv',
            24,
            str_repeat('e', 64),
            1,
            str_repeat('f', 64),
        );
        $this->artifacts->save($completed, 2);

        self::assertSame(3, $this->rowCount('business_report_export_artifacts'));
        self::assertSame($completed->toArray(), $this->artifacts->find($queued->id)?->toArray());
    }

    public function testArtifactIdentityRejectsNonCanonicalUppercaseUuids(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('canonical lowercase UUID');

        $this->artifact(strtoupper(Uuid::uuid7()->toString()));
    }

    public function testRepeatableMigrationRestoresAnAbsentExportLedger(): void
    {
        $manager = $this->database->createSchemaManager();
        $table = $this->tables->raw('business_report_export_artifacts');
        $manager->dropTable($table);
        self::assertFalse($manager->tablesExist([$table]));

        (new BusinessIntegrationSdkMigration($this->tables))->up($this->database);

        self::assertTrue($manager->tablesExist([$table]));
        $artifact = $this->artifact();
        $this->artifacts->add($artifact);
        self::assertArrayNotHasKey('authority_grants', $artifact->toArray());
        self::assertSame($artifact->toArray(), $this->artifacts->find($artifact->id)?->toArray());
    }

    /**
     * @param  ?list<array{capability: string, scope_type: string, scope_identifier: ?string}>
     *         $authorityGrantRows  Exact request authority, or null for the legacy document shape.
     */
    private function artifact(?string $id = null, ?array $authorityGrantRows = null): ExportArtifact
    {
        $createdAt = new DateTimeImmutable('2026-08-10T10:00:00+00:00');

        return new ExportArtifact(
            $id ?? Uuid::uuid7()->toString(),
            'core.asset_register',
            1,
            str_repeat('a', 64),
            'operator-1',
            'default',
            null,
            null,
            AuthenticatedSurface::Api,
            str_repeat('b', 64),
            str_repeat('c', 64),
            [],
            str_repeat('d', 64),
            ExportArtifactStatus::Queued,
            $createdAt,
            $createdAt->modify('+1 hour'),
            null,
            null,
            'core_asset_register-20260810-100000.csv',
            null,
            null,
            null,
            null,
            null,
            null,
            1,
            authorityGrantRows: $authorityGrantRows,
        );
    }

    private function audit(ExportArtifact $artifact): AuditEvent
    {
        return new AuditEvent(
            Uuid::uuid7()->toString(),
            $artifact->createdAt,
            $artifact->actorId,
            'business.report.export.request',
            'report_export',
            $artifact->id,
            'success',
            ['status' => $artifact->status->value],
        );
    }

    private function insertExportJob(ExportArtifact $artifact): void
    {
        $this->database->insert($this->tables->raw('jobs'), [
            'id' => Uuid::uuid7()->toString(),
            'queue' => 'exports',
            'job_type' => 'business_reporting.export.generate',
            'schema_version' => 1,
            'payload' => ['artifact_id' => $artifact->id],
            'priority' => 0,
            'status' => 'pending',
            'available_at' => $artifact->createdAt,
            'lease_owner' => null,
            'lease_acquired_at' => null,
            'lease_expires_at' => null,
            'attempts' => 0,
            'maximum_attempts' => 5,
            'schedule_id' => null,
            'scheduled_for' => null,
            'occurrence_key' => null,
            'completed_at' => null,
            'created_at' => $artifact->createdAt,
            'updated_at' => $artifact->createdAt,
        ], [
            'payload' => Types::JSON,
            'available_at' => Types::DATETIME_IMMUTABLE,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    private function rowCount(string $table): int
    {
        return (int) $this->database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s',
            $this->tables->quoted($table),
        ));
    }
}
