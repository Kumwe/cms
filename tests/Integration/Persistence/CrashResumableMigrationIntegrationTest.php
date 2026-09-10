<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\App\Infrastructure\Persistence\Migration\ApplicationAuthorizationMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\ApplicationAuthorizationMigrationRecovery;
use Kumwe\App\Infrastructure\Persistence\Migration\AuthorizationRecoveryIntegrationMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\CoreSchemaMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\DoctrineMigrationLock;
use Kumwe\App\Infrastructure\Persistence\Migration\DoctrineMigrationRepository;
use Kumwe\App\Infrastructure\Persistence\Migration\DoctrineNonTransactionalMigrationRecovery;
use Kumwe\App\Infrastructure\Persistence\Migration\InstallationGlobalAutomationMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\Migration;
use Kumwe\App\Infrastructure\Persistence\Migration\MigrationLock;
use Kumwe\App\Infrastructure\Persistence\Migration\MigrationPlan;
use Kumwe\App\Infrastructure\Persistence\Migration\MigrationRunner;
use Kumwe\App\Infrastructure\Persistence\Migration\JobRecoveryMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\RepeatableMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\SiteAutomationContextMigration;
use Kumwe\App\Infrastructure\Persistence\ReadinessProbe;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\ContainerFactory;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use RuntimeException;

#[CoversClass(MigrationRunner::class)]
#[CoversClass(DoctrineNonTransactionalMigrationRecovery::class)]
#[CoversClass(ApplicationAuthorizationMigration::class)]
#[CoversClass(ApplicationAuthorizationMigrationRecovery::class)]
#[CoversClass(InstallationGlobalAutomationMigration::class)]
final class CrashResumableMigrationIntegrationTest extends TestCase
{
    public function testFreshCorePartialImplicitDdlFailureReplaysFromItsCleanBaseline(): void
    {
        $database = $this->mysqlDatabase();
        $tables = $this->uniqueTables($database);
        $core = new CoreSchemaMigration($tables);
        $interrupted = new InterruptOnceCoreMigration($tables, $core->checksum());
        $runner = $this->runner($database, $tables, [$interrupted]);

        try {
            try {
                $runner->migrate($this->context());
                self::fail('The first implicit-DDL Core attempt must be interrupted.');
            } catch (RuntimeException $exception) {
                self::assertSame('simulated implicit-DDL interruption', $exception->getMessage());
            }
            self::assertTrue($database->createSchemaManager()->tablesExist([
                $tables->raw('users'),
                $tables->raw('migration_attempts'),
            ]));

            self::assertSame([CoreSchemaMigration::ID], $runner->migrate($this->context())->applied);
            self::assertTrue($interrupted->observedCleanReplay());
            self::assertSame($core->checksum(), $database->fetchOne(sprintf(
                'SELECT checksum FROM %s WHERE version = ?',
                $tables->quoted('schema_migrations'),
            ), [CoreSchemaMigration::ID]));
            self::assertSame('0', (string) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s',
                $tables->quoted('migration_attempts'),
            )));
        } finally {
            $this->dropPrefixTables($database, $tables);
        }
    }

    public function testApplicationAuthorizationResumesPartialDdlAndSeedStateWithoutChecksumDrift(): void
    {
        $database = $this->mysqlDatabase();
        $tables = $this->uniqueTables($database);

        try {
            $this->createAuthorizationParentSchema($database, $tables);
            $migration = new ApplicationAuthorizationMigration($tables);
            $interrupted = new InterruptOnceAuthorizationMigration($tables, $migration);
            $runner = $this->runner($database, $tables, [$interrupted]);
            try {
                $runner->migrate($this->context());
                self::fail('The first application-authorization attempt must be interrupted.');
            } catch (RuntimeException $exception) {
                self::assertSame('simulated authorization migration interruption', $exception->getMessage());
            }

            self::assertSame(
                [ApplicationAuthorizationMigration::ID],
                $runner->migrate($this->context())->applied,
            );
            self::assertSame($migration->checksum(), $database->fetchOne(sprintf(
                'SELECT checksum FROM %s WHERE version = ?',
                $tables->quoted('schema_migrations'),
            ), [ApplicationAuthorizationMigration::ID]));
            self::assertSame('1', (string) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s WHERE identifier = ?',
                $tables->quoted('sites'),
            ), [SiteContext::DEFAULT]));
            self::assertSame('2', (string) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s WHERE code IN (?, ?)',
                $tables->quoted('capabilities'),
            ), ['themes.site.manage', 'themes.administrator.manage']));
            self::assertSame('2', (string) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s WHERE capability_code IN (?, ?)',
                $tables->quoted('role_capability_grants'),
            ), ['themes.site.manage', 'themes.administrator.manage']));
            self::assertSame('2', (string) $database->fetchOne(sprintf(
                'SELECT security_epoch FROM %s WHERE id = ?',
                $tables->quoted('users'),
            ), [InterruptOnceAuthorizationMigration::USER_ID]));
            self::assertSame('0', (string) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s',
                $tables->quoted('migration_attempts'),
            )));

            $idempotency = $database->createSchemaManager()->introspectTable($tables->raw('idempotency'));
            foreach (['authorization_fingerprint', 'lease_owner', 'lease_expires_at'] as $column) {
                self::assertTrue($idempotency->hasColumn($column));
            }
            $ownership = $database->createSchemaManager()->introspectTable(
                $tables->raw('resource_site_ownership'),
            );
            self::assertTrue($ownership->hasIndex('idx_resource_site'));
            self::assertTrue(array_any(
                $ownership->getForeignKeys(),
                fn (\Doctrine\DBAL\Schema\ForeignKeyConstraint $foreignKey): bool =>
                    array_map(
                        static fn (\Doctrine\DBAL\Schema\Name $name): string => $name->toString(),
                        $foreignKey->getReferencingColumnNames(),
                    ) === ['site_identifier']
                    && $foreignKey->getReferencedTableName()->toString() === $tables->raw('sites'),
            ));
        } finally {
            $this->dropPrefixTables($database, $tables);
        }
    }

    /**
     * An attempt a previous build journaled under a checksum this build still accepts resumes after the upgrade.
     *
     * The nine migrations that changed only their imports for `KUMWE-MIG-2026-004` keep their pre-move
     * checksums accepted; an unfinished attempt one of them left behind must be picked up by the new bytes
     * rather than refused as drift, and a build that does not accept the old checksum must still refuse it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnAttemptJournaledUnderAnAcceptedHistoricalChecksumResumes(): void
    {
        $database = $this->mysqlDatabase();
        $tables = $this->uniqueTables($database);
        $previous = '484705ff88bf14bc4f92a63cff2fcb613a739aa147a0e76c152e4f564f129bf0';

        try {
            $this->createAuthorizationParentSchema($database, $tables);
            $migration = new ApplicationAuthorizationMigration($tables);
            $interrupted = new InterruptOnceAuthorizationMigration($tables, $migration);
            try {
                $this->runner($database, $tables, [$interrupted])->migrate($this->context());
                self::fail('The first application-authorization attempt must be interrupted.');
            } catch (RuntimeException $exception) {
                self::assertSame('simulated authorization migration interruption', $exception->getMessage());
            }
            $database->update(
                $tables->raw('migration_attempts'),
                ['checksum' => $previous],
                ['version' => ApplicationAuthorizationMigration::ID],
            );

            try {
                $this->runner($database, $tables, [$migration])->migrate($this->context());
                self::fail('A build that does not accept the previous checksum must refuse the attempt.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'Interrupted migration checksum drift detected for "20260805010000_application_authorization".',
                    $exception->getMessage(),
                );
            }

            $historical = [ApplicationAuthorizationMigration::ID => [$previous]];
            self::assertSame(
                [ApplicationAuthorizationMigration::ID],
                $this->runner($database, $tables, [$migration], $historical)->migrate($this->context())->applied,
            );
            self::assertSame($migration->checksum(), $database->fetchOne(sprintf(
                'SELECT checksum FROM %s WHERE version = ?',
                $tables->quoted('schema_migrations'),
            ), [ApplicationAuthorizationMigration::ID]));
            self::assertSame('0', (string) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s',
                $tables->quoted('migration_attempts'),
            )));
        } finally {
            $this->dropPrefixTables($database, $tables);
        }
    }

    public function testApplicationAuthorizationRecoveryRejectsDivergentPartialSchema(): void
    {
        $database = $this->mysqlDatabase();
        $tables = $this->uniqueTables($database);

        try {
            $this->createAuthorizationParentSchema($database, $tables);
            $migration = new ApplicationAuthorizationMigration($tables);
            $interrupted = new InterruptOnceAuthorizationMigration($tables, $migration);
            $runner = $this->runner($database, $tables, [$interrupted]);
            try {
                $runner->migrate($this->context());
                self::fail('The first application-authorization attempt must be interrupted.');
            } catch (RuntimeException $exception) {
                self::assertSame('simulated authorization migration interruption', $exception->getMessage());
            }

            $database->executeStatement(sprintf(
                'ALTER TABLE %s ADD unexpected_column VARCHAR(32) DEFAULT NULL',
                $tables->quoted('sites'),
            ));

            try {
                $runner->migrate($this->context());
                self::fail('Divergent partial schema must not be recorded as applied.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('divergent columns', $exception->getMessage());
            }
            self::assertFalse($database->fetchOne(sprintf(
                'SELECT version FROM %s WHERE version = ?',
                $tables->quoted('schema_migrations'),
            ), [ApplicationAuthorizationMigration::ID]));
            self::assertSame('1', (string) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s WHERE version = ?',
                $tables->quoted('migration_attempts'),
            ), [ApplicationAuthorizationMigration::ID]));
        } finally {
            $this->dropPrefixTables($database, $tables);
        }
    }

    public function testApplicationAuthorizationRecoveryRejectsAUniqueOwnershipLookupIndex(): void
    {
        $database = $this->mysqlDatabase();
        $tables = $this->uniqueTables($database);

        try {
            $this->createAuthorizationParentSchema($database, $tables);
            $migration = new ApplicationAuthorizationMigration($tables);
            $runner = $this->runner(
                $database,
                $tables,
                [new InterruptOnceAuthorizationMigration($tables, $migration)],
            );
            try {
                $runner->migrate($this->context());
                self::fail('The first application-authorization attempt must be interrupted.');
            } catch (RuntimeException $exception) {
                self::assertSame('simulated authorization migration interruption', $exception->getMessage());
            }
            $database->executeStatement(sprintf(
                'CREATE UNIQUE INDEX %s ON %s (site_identifier, resource_type)',
                $database->quoteSingleIdentifier('idx_resource_site'),
                $tables->quoted('resource_site_ownership'),
            ));

            try {
                $runner->migrate($this->context());
                self::fail('A unique ownership lookup index must not satisfy the recovery postcondition.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('index "idx_resource_site" is divergent', $exception->getMessage());
            }
        } finally {
            $this->dropPrefixTables($database, $tables);
        }
    }

    public function testApplicationAuthorizationRecoveryRequiresCascadingSiteOwnership(): void
    {
        $database = $this->mysqlDatabase();
        $tables = $this->uniqueTables($database);

        try {
            $this->createAuthorizationParentSchema($database, $tables);
            $migration = new ApplicationAuthorizationMigration($tables);
            $runner = $this->runner(
                $database,
                $tables,
                [new InterruptOnceAuthorizationMigration($tables, $migration)],
            );
            try {
                $runner->migrate($this->context());
                self::fail('The first application-authorization attempt must be interrupted.');
            } catch (RuntimeException $exception) {
                self::assertSame('simulated authorization migration interruption', $exception->getMessage());
            }
            $database->executeStatement(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (site_identifier) REFERENCES %s (identifier) '
                . 'ON DELETE RESTRICT',
                $tables->quoted('resource_site_ownership'),
                $database->quoteSingleIdentifier(
                    'fk_resource_site_' . substr(hash('sha256', $tables->raw('resource_site_ownership')), 0, 16),
                ),
                $tables->quoted('sites'),
            ));

            try {
                $runner->migrate($this->context());
                self::fail('A restrictive ownership foreign key must not satisfy the recovery postcondition.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('ownership foreign key is divergent', $exception->getMessage());
            }
        } finally {
            $this->dropPrefixTables($database, $tables);
        }
    }

    public function testApplicationAuthorizationPurgesLegacyReplayBeforeRecoveryDdl(): void
    {
        $database = $this->mysqlDatabase();
        $tables = $this->uniqueTables($database);

        try {
            $this->createAuthorizationParentSchema($database, $tables);
            $database->insert($tables->raw('idempotency'), [
                'id' => '00000000-0000-7000-8000-000000009004',
                'state' => 'completed',
            ]);
            $migration = new ApplicationAuthorizationMigration($tables);
            $interrupted = new InterruptBeforeAuthorizationMutationMigration($migration);
            $runner = $this->runner($database, $tables, [$interrupted]);

            try {
                $runner->migrate($this->context());
                self::fail('The first application-authorization attempt must be interrupted.');
            } catch (RuntimeException $exception) {
                self::assertSame('simulated pre-mutation interruption', $exception->getMessage());
            }
            self::assertSame('1', (string) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s',
                $tables->quoted('idempotency'),
            )));

            self::assertSame(
                [ApplicationAuthorizationMigration::ID],
                $runner->migrate($this->context())->applied,
            );
            self::assertSame('0', (string) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s',
                $tables->quoted('idempotency'),
            )));
            $idempotency = $database->createSchemaManager()->introspectTable($tables->raw('idempotency'));
            foreach (['authorization_fingerprint', 'lease_owner', 'lease_expires_at'] as $column) {
                self::assertTrue($idempotency->hasColumn($column));
            }
            self::assertSame($migration->checksum(), $database->fetchOne(sprintf(
                'SELECT checksum FROM %s WHERE version = ?',
                $tables->quoted('schema_migrations'),
            ), [ApplicationAuthorizationMigration::ID]));
        } finally {
            $this->dropPrefixTables($database, $tables);
        }
    }

    public function testRepeatableSiteAutomationMigrationResumesAfterImplicitDdlInterruption(): void
    {
        $database = $this->mysqlDatabase();
        $tables = $this->uniqueTables($database);
        $siteAutomation = new SiteAutomationContextMigration($tables);
        $interrupted = new InterruptOnceSiteAutomationMigration($tables, $siteAutomation);

        try {
            $sites = new Table($tables->raw('sites'));
            $sites->addColumn('identifier', Types::STRING, ['length' => 191]);
            $sites->addPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setUnquotedColumnNames('identifier')->create(),
            );
            $database->createSchemaManager()->createTable($sites);
            $runner = $this->runner($database, $tables, [$interrupted]);

            try {
                $runner->migrate($this->context());
                self::fail('The first site-automation migration attempt must be interrupted.');
            } catch (RuntimeException $exception) {
                self::assertSame('simulated site-automation migration interruption', $exception->getMessage());
            }

            $baseline = $database->fetchOne(sprintf(
                'SELECT baseline_tables FROM %s WHERE version = ?',
                $tables->quoted('migration_attempts'),
            ), [SiteAutomationContextMigration::ID]);
            self::assertIsString($baseline);
            self::assertSame([], json_decode($baseline, true, 8, JSON_THROW_ON_ERROR));

            self::assertTrue(
                $database->createSchemaManager()->introspectTable($tables->raw('sites'))->hasColumn('enabled'),
            );
            self::assertSame(
                [SiteAutomationContextMigration::ID],
                $runner->migrate($this->context())->applied,
            );
            self::assertSame('0', (string) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s',
                $tables->quoted('migration_attempts'),
            )));
        } finally {
            $this->dropPrefixTables($database, $tables);
        }
    }

    public function testPostgreSqlRollsBackMigrationDdlAndLedgerTogetherBeforeRetry(): void
    {
        $database = $this->postgresDatabase();
        $tables = $this->uniqueTables($database);
        $migration = new InterruptOnceTransactionalMigration($tables);
        $runner = $this->runner($database, $tables, [$migration]);

        try {
            try {
                $runner->migrate($this->context());
                self::fail('The first PostgreSQL migration transaction must be interrupted.');
            } catch (RuntimeException $exception) {
                self::assertSame('simulated transactional migration interruption', $exception->getMessage());
            }

            self::assertFalse($database->createSchemaManager()->tablesExist([
                $tables->raw('transaction_probe'),
                $tables->raw('migration_attempts'),
            ]));
            self::assertFalse($database->fetchOne(sprintf(
                'SELECT version FROM %s WHERE version = ?',
                $tables->quoted('schema_migrations'),
            ), [$migration->id()]));

            self::assertSame([$migration->id()], $runner->migrate($this->context())->applied);
            self::assertTrue($database->createSchemaManager()->tablesExist([
                $tables->raw('transaction_probe'),
            ]));
            self::assertSame('1', (string) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s WHERE version = ?',
                $tables->quoted('schema_migrations'),
            ), [$migration->id()]));
        } finally {
            $this->dropPrefixTables($database, $tables);
        }
    }

    public function testPushedJobRecoveryChecksumsReconcileThroughTheForwardMigration(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $historicalChecksums = [
            '5e55e74ae3027ecc5d4843e045cf19a3e07d0b7be1f2ce556807bb67eda61947',
            '4d7fc30104c21bda0c00947fb82bce1333daa0d542e7292ee4e96bbda1c83b5d',
        ];

        foreach ($historicalChecksums as $historicalChecksum) {
            $tables = $this->uniqueTables($database);
            try {
                $repository = new DoctrineMigrationRepository($database, $tables);
                $repository->ensureLedger();
                $core = new CoreSchemaMigration($tables);
                $authorization = new ApplicationAuthorizationMigration($tables);
                $jobs = new JobRecoveryMigration($tables);
                $integration = new AuthorizationRecoveryIntegrationMigration($tables);
                foreach ([$core, $authorization, $jobs] as $migration) {
                    $migration->up($database);
                    $repository->record(
                        $migration->id(),
                        $migration === $jobs ? $historicalChecksum : $migration->checksum(),
                        0,
                    );
                }
                $runner = new MigrationRunner(
                    $database,
                    $repository,
                    new DirectCrashRecoveryMigrationLock(),
                    new DoctrineTransactionManager($database),
                    new MigrationPlan(
                        [$core, $authorization, $jobs, $integration],
                        [JobRecoveryMigration::ID => $historicalChecksums],
                    ),
                    AuthorizationContext::gateway(),
                    new DoctrineNonTransactionalMigrationRecovery(
                        $database,
                        $tables,
                        new ApplicationAuthorizationMigrationRecovery($database, $tables),
                    ),
                );

                self::assertSame([$integration->id()], $runner->migrate($this->context())->applied);
                self::assertSame(SiteContext::DEFAULT, $database->fetchOne(sprintf(
                    'SELECT site_identifier FROM %s WHERE resource_type = ? AND resource_id = ?',
                    $tables->quoted('resource_site_ownership'),
                ), ['schedule', '00000000-0000-7000-8000-000000000802']));
            } finally {
                $this->dropPrefixTables($database, $tables);
            }
        }
    }

    public function testUnknownFutureRecoveryAttemptBlocksOlderMigrationAndReadiness(): void
    {
        $database = $this->mysqlDatabase();
        $tables = $this->uniqueTables($database);
        $future = new AlwaysInterruptMigration();
        $repository = new DoctrineMigrationRepository($database, $tables);
        $recovery = new DoctrineNonTransactionalMigrationRecovery(
            $database,
            $tables,
            new ApplicationAuthorizationMigrationRecovery($database, $tables),
        );

        try {
            $futureRunner = new MigrationRunner(
                $database,
                $repository,
                new DoctrineMigrationLock($database, $tables),
                new DoctrineTransactionManager($database),
                new MigrationPlan([$future]),
                AuthorizationContext::gateway(),
                $recovery,
            );
            try {
                $futureRunner->migrate($this->context());
                self::fail('The future migration must leave an unresolved attempt.');
            } catch (RuntimeException $exception) {
                self::assertSame('simulated future migration interruption', $exception->getMessage());
            }

            $olderPlan = new MigrationPlan([]);
            $olderRunner = new MigrationRunner(
                $database,
                $repository,
                new DoctrineMigrationLock($database, $tables),
                new DoctrineTransactionManager($database),
                $olderPlan,
                AuthorizationContext::gateway(),
                $recovery,
            );
            try {
                $olderRunner->migrate($this->context());
                self::fail('An older migration plan must reject a future recovery attempt.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('unknown attempt', $exception->getMessage());
            }
            self::assertFalse((new ReadinessProbe(
                $database,
                new NullLogger(),
                $tables,
                $repository,
                $olderPlan,
                $recovery,
            ))->ready());
        } finally {
            $this->dropPrefixTables($database, $tables);
        }
    }

    public function testInstallationGlobalScopeResumesPartialDdlAndBackfillsBothTables(): void
    {
        $database = $this->mysqlDatabase();
        $tables = $this->uniqueTables($database);

        try {
            $schema = new Schema();
            foreach (['jobs', 'schedules'] as $name) {
                $table = $this->idTable($schema, $tables->raw($name));
                $table->addColumn('job_type', Types::STRING, ['length' => 127]);
            }
            foreach ($schema->toSql($database->getDatabasePlatform()) as $statement) {
                $database->executeStatement($statement);
            }
            foreach (['jobs', 'schedules'] as $name) {
                $database->insert($tables->raw($name), [
                    'id' => $name === 'jobs'
                        ? '00000000-0000-7000-8000-000000009101'
                        : '00000000-0000-7000-8000-000000009102',
                    'job_type' => 'system.idempotency.purge',
                ]);
            }

            $migration = new InstallationGlobalAutomationMigration($tables);
            $interrupted = new InterruptOnceInstallationGlobalMigration($tables, $migration);
            $runner = $this->runner($database, $tables, [$interrupted]);
            try {
                $runner->migrate($this->context());
                self::fail('The first installation-global automation migration must be interrupted.');
            } catch (RuntimeException $exception) {
                self::assertSame('simulated installation-global DDL interruption', $exception->getMessage());
            }

            self::assertSame([$migration->id()], $runner->migrate($this->context())->applied);
            foreach (['jobs', 'schedules'] as $name) {
                self::assertSame('installation', $database->fetchOne(sprintf(
                    'SELECT execution_scope FROM %s',
                    $tables->quoted($name),
                )));
            }
            self::assertSame('0', (string) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s',
                $tables->quoted('migration_attempts'),
            )));
        } finally {
            $this->dropPrefixTables($database, $tables);
        }
    }

    private function mysqlDatabase(): Connection
    {
        $container = (new ContainerFactory())->create(Environment::fromGlobals());
        $database = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);
        if (!$database->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('Implicit-DDL recovery applies to MySQL and MariaDB.');
        }

        return $database;
    }

    private function postgresDatabase(): Connection
    {
        $container = (new ContainerFactory())->create(Environment::fromGlobals());
        $database = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);
        if (!$database->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            self::markTestSkipped('Transactional DDL rollback coverage applies to PostgreSQL.');
        }

        return $database;
    }

    private function uniqueTables(Connection $database): TableNames
    {
        return new TableNames(
            $database,
            'r' . substr(str_replace('-', '', Uuid::uuid7()->toString()), 0, 10) . '_',
        );
    }

    /**
     * Build a runner over the given migrations, accepting the given earlier checksums.
     *
     * @param   Connection                   $database                     Database under test.
     * @param   TableNames                   $tables                       Prefixed table names.
     * @param   list<Migration>              $migrations                   Migrations the plan ships.
     * @param   array<string, list<string>>  $acceptedHistoricalChecksums  Earlier checksums by migration ID.
     *
     * @return  MigrationRunner  Runner whose plan and recovery both accept the earlier checksums.
     *
     * @since   2.0.0
     */
    private function runner(
        Connection $database,
        TableNames $tables,
        array $migrations,
        array $acceptedHistoricalChecksums = [],
    ): MigrationRunner {
        return new MigrationRunner(
            $database,
            new DoctrineMigrationRepository($database, $tables),
            new DirectCrashRecoveryMigrationLock(),
            new DoctrineTransactionManager($database),
            new MigrationPlan($migrations, $acceptedHistoricalChecksums),
            AuthorizationContext::gateway(),
            new DoctrineNonTransactionalMigrationRecovery(
                $database,
                $tables,
                new ApplicationAuthorizationMigrationRecovery($database, $tables),
                $acceptedHistoricalChecksums,
            ),
        );
    }

    private function context(): \Kumwe\Context\Value\ExecutionContext
    {
        return AuthorizationContext::system(SystemIdentity::Migration)->context(
            SiteContext::default(),
            'crash-recovery-migration-test',
        );
    }

    private function createAuthorizationParentSchema(Connection $database, TableNames $tables): void
    {
        $schema = new Schema();
        $this->idTable($schema, $tables->raw('users'));
        $roles = $this->idTable($schema, $tables->raw('roles'));
        $roles->addColumn('code', Types::STRING, ['length' => 191]);
        $userRoles = $schema->createTable($tables->raw('user_roles'));
        $userRoles->addColumn('user_id', Types::GUID);
        $userRoles->addColumn('role_id', Types::GUID);
        $capabilities = $schema->createTable($tables->raw('capabilities'));
        $capabilities->addColumn('code', Types::STRING, ['length' => 191]);
        $capabilities->addColumn('description', Types::STRING, ['length' => 500]);
        $capabilities->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('code')->create(),
        );
        $grants = $this->idTable($schema, $tables->raw('role_capability_grants'));
        $grants->addColumn('role_id', Types::GUID);
        $grants->addColumn('capability_code', Types::STRING, ['length' => 191]);
        $grants->addColumn('scope_type', Types::STRING, ['length' => 32]);
        $grants->addColumn('scope_identifier', Types::STRING, ['length' => 191, 'notnull' => false]);
        $grants->addColumn('granted_at', Types::DATETIME_IMMUTABLE);
        $grants->addColumn('granted_by', Types::GUID, ['notnull' => false]);
        $this->idTable($schema, $tables->raw('administrator_sessions'));
        $this->idTable($schema, $tables->raw('api_tokens'));
        $this->idTable($schema, $tables->raw('content_entries'));
        $extensions = $schema->createTable($tables->raw('extensions'));
        $extensions->addColumn('identifier', Types::STRING, ['length' => 191]);
        $extensions->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('identifier')->create(),
        );
        $this->idTable($schema, $tables->raw('jobs'));
        $this->idTable($schema, $tables->raw('navigation_menus'));
        $this->idTable($schema, $tables->raw('navigation_items'));
        $this->idTable($schema, $tables->raw('schedules'));
        $idempotency = $this->idTable($schema, $tables->raw('idempotency'));
        $idempotency->addColumn('state', Types::STRING, ['length' => 32]);

        foreach ($schema->toSql($database->getDatabasePlatform()) as $statement) {
            $database->executeStatement($statement);
        }
        $database->insert($tables->raw('users'), ['id' => InterruptOnceAuthorizationMigration::USER_ID]);
        $database->insert($tables->raw('roles'), [
            'id' => InterruptOnceAuthorizationMigration::ROLE_ID,
            'code' => 'administrator',
        ]);
        $database->insert($tables->raw('user_roles'), [
            'user_id' => InterruptOnceAuthorizationMigration::USER_ID,
            'role_id' => InterruptOnceAuthorizationMigration::ROLE_ID,
        ]);
    }

    private function idTable(Schema $schema, string $name): Table
    {
        $table = $schema->createTable($name);
        $table->addColumn('id', Types::GUID);
        $table->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create(),
        );

        return $table;
    }

    private function dropPrefixTables(Connection $database, TableNames $tables): void
    {
        $manager = $database->createSchemaManager();
        $ledger = $tables->raw('schema_migrations');
        $prefix = substr($ledger, 0, -strlen('schema_migrations'));
        $names = array_values(array_filter(
            $manager->listTableNames(),
            static fn (string $name): bool => str_starts_with($name, $prefix),
        ));
        foreach ($names as $name) {
            foreach ($manager->introspectTable($name)->getForeignKeys() as $foreignKey) {
                $constraintName = $foreignKey->getObjectName();
                if ($constraintName === null) {
                    throw new RuntimeException('A crash-recovery test foreign key is unnamed.');
                }
                $manager->dropForeignKey($constraintName->getIdentifier()->getValue(), $name);
            }
        }
        foreach (array_reverse($names) as $name) {
            if ($manager->tablesExist([$name])) {
                $manager->dropTable($name);
            }
        }
    }
}

final class DirectCrashRecoveryMigrationLock implements MigrationLock
{
    public function synchronized(callable $operation): mixed
    {
        return $operation();
    }
}

final class InterruptOnceInstallationGlobalMigration implements RepeatableMigration
{
    private bool $interrupted = false;

    public function __construct(
        private TableNames $tables,
        private InstallationGlobalAutomationMigration $migration,
    ) {
    }

    public function id(): string
    {
        return $this->migration->id();
    }

    public function checksum(): string
    {
        return $this->migration->checksum();
    }

    public function up(Connection $database): void
    {
        if ($this->interrupted) {
            $this->migration->up($database);

            return;
        }
        $this->interrupted = true;
        $database->executeStatement(sprintf(
            "ALTER TABLE %s ADD execution_scope VARCHAR(16) NOT NULL DEFAULT 'site'",
            $this->tables->quoted('jobs'),
        ));

        throw new RuntimeException('simulated installation-global DDL interruption');
    }
}

final class InterruptOnceCoreMigration implements Migration
{
    private bool $interrupted = false;
    private bool $cleanReplay = false;

    public function __construct(private TableNames $tables, private string $coreChecksum)
    {
    }

    public function id(): string
    {
        return CoreSchemaMigration::ID;
    }

    public function checksum(): string
    {
        return $this->coreChecksum;
    }

    public function up(Connection $database): void
    {
        $partial = $this->tables->raw('users');
        if (!$this->interrupted) {
            $this->interrupted = true;
            $database->createSchemaManager()->createTable($this->probe($partial));
            throw new RuntimeException('simulated implicit-DDL interruption');
        }

        $this->cleanReplay = !$database->createSchemaManager()->tablesExist([$partial]);
        if (!$this->cleanReplay) {
            throw new RuntimeException('The interrupted Core table was not recovered before replay.');
        }
        $database->createSchemaManager()->createTable($this->probe($partial));
        $database->createSchemaManager()->createTable($this->probe($this->tables->raw('roles')));
    }

    public function observedCleanReplay(): bool
    {
        return $this->cleanReplay;
    }

    private function probe(string $name): Table
    {
        $table = new Table($name);
        $table->addColumn('id', Types::INTEGER);
        $table->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create(),
        );

        return $table;
    }
}

final class InterruptOnceAuthorizationMigration implements Migration
{
    public const USER_ID = '00000000-0000-7000-8000-000000009001';
    public const ROLE_ID = '00000000-0000-7000-8000-000000009002';

    private bool $interrupted = false;

    public function __construct(
        private TableNames $tables,
        private ApplicationAuthorizationMigration $migration,
    ) {
    }

    public function id(): string
    {
        return $this->migration->id();
    }

    public function checksum(): string
    {
        return $this->migration->checksum();
    }

    public function up(Connection $database): void
    {
        if ($this->interrupted) {
            $this->migration->up($database);

            return;
        }
        $this->interrupted = true;
        $database->executeStatement(sprintf(
            'ALTER TABLE %s ADD security_epoch INTEGER NOT NULL DEFAULT 1',
            $this->tables->quoted('users'),
        ));
        $sites = new Table($this->tables->raw('sites'));
        $sites->addColumn('identifier', Types::STRING, ['length' => 191]);
        $sites->addColumn('name', Types::STRING, ['length' => 191]);
        $sites->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $sites->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('identifier')->create(),
        );
        $database->createSchemaManager()->createTable($sites);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $database->insert($this->tables->raw('sites'), [
            'identifier' => SiteContext::DEFAULT,
            'name' => 'Default site',
            'created_at' => $now,
        ], ['created_at' => Types::DATETIME_IMMUTABLE]);
        $database->insert($this->tables->raw('capabilities'), [
            'code' => 'themes.site.manage',
            'description' => 'Manage public site presentation themes.',
        ]);
        $database->insert($this->tables->raw('role_capability_grants'), [
            'id' => '00000000-0000-7000-8000-000000009003',
            'role_id' => self::ROLE_ID,
            'capability_code' => 'themes.site.manage',
            'scope_type' => 'global',
            'scope_identifier' => null,
            'granted_at' => $now,
            'granted_by' => null,
        ], ['granted_at' => Types::DATETIME_IMMUTABLE]);
        $ownership = new Table($this->tables->raw('resource_site_ownership'));
        $ownership->addColumn('resource_type', Types::STRING, ['length' => 63]);
        $ownership->addColumn('resource_id', Types::STRING, ['length' => 191]);
        $ownership->addColumn('site_identifier', Types::STRING, ['length' => 191]);
        $ownership->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()
                ->setUnquotedColumnNames('resource_type', 'resource_id')
                ->create(),
        );
        $database->createSchemaManager()->createTable($ownership);

        throw new RuntimeException('simulated authorization migration interruption');
    }
}

final class InterruptBeforeAuthorizationMutationMigration implements Migration
{
    public function __construct(private ApplicationAuthorizationMigration $migration)
    {
    }

    public function id(): string
    {
        return $this->migration->id();
    }

    public function checksum(): string
    {
        return $this->migration->checksum();
    }

    public function up(Connection $database): void
    {
        throw new RuntimeException('simulated pre-mutation interruption');
    }
}

final class InterruptOnceTransactionalMigration implements Migration
{
    private bool $interrupted = false;

    public function __construct(private TableNames $tables)
    {
    }

    public function id(): string
    {
        return '20260805060000_transactional_probe';
    }

    public function checksum(): string
    {
        return hash('sha256', $this->id());
    }

    public function up(Connection $database): void
    {
        $probe = new Table($this->tables->raw('transaction_probe'));
        $probe->addColumn('id', Types::INTEGER);
        $probe->setPrimaryKey(['id']);
        $database->createSchemaManager()->createTable($probe);
        if (!$this->interrupted) {
            $this->interrupted = true;
            throw new RuntimeException('simulated transactional migration interruption');
        }
    }
}

final readonly class AlwaysInterruptMigration implements Migration
{
    public function id(): string
    {
        return '20260806010000_future_interruption';
    }

    public function checksum(): string
    {
        return hash('sha256', $this->id());
    }

    public function up(Connection $database): void
    {
        throw new RuntimeException('simulated future migration interruption');
    }
}

final class InterruptOnceSiteAutomationMigration implements RepeatableMigration
{
    private bool $interrupted = false;

    public function __construct(
        private TableNames $tables,
        private SiteAutomationContextMigration $migration,
    ) {
    }

    public function id(): string
    {
        return $this->migration->id();
    }

    public function checksum(): string
    {
        return $this->migration->checksum();
    }

    public function up(Connection $database): void
    {
        if ($this->interrupted) {
            $this->migration->up($database);

            return;
        }
        $this->interrupted = true;
        $database->executeStatement(sprintf(
            'ALTER TABLE %s ADD enabled BOOLEAN NOT NULL DEFAULT TRUE',
            $this->tables->quoted('sites'),
        ));

        throw new RuntimeException('simulated site-automation migration interruption');
    }
}
