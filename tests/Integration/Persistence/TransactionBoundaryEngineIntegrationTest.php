<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use DomainException;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Audit\Infrastructure\Persistence\DoctrineAuditRecorder;
use Kumwe\App\BusinessIntegration\Application\EventContractRegistry;
use Kumwe\App\BusinessIntegration\Domain\EventSchemaDefinition;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\EventSensitivity;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\IntegrationEvent;
use Kumwe\App\BusinessIntegration\Domain\RecordedIntegrationEvent;
use Kumwe\App\BusinessIntegration\Infrastructure\DoctrineOutboxStore;
use Kumwe\Sequence\Contract\NumberSequenceAllocator;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessNumberSequenceAllocator;
use Kumwe\App\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

#[CoversClass(DoctrineTransactionManager::class)]
#[CoversClass(DoctrineBusinessNumberSequenceAllocator::class)]
#[CoversClass(DoctrineAuditRecorder::class)]
#[CoversClass(DoctrineOutboxStore::class)]
/**
 * Proves the `TransactionManager` boundary's semantics on the engine the suite is pointed at.
 *
 * Work package `P3-A` owes the evidence the seam extraction deliberately did not produce: commit,
 * rollback, exception translation, retryable contention, non-retryable domain failure, nested call
 * semantics, and audit and outbox atomicity, each exercised through the seam rather than asserted by
 * the code paths that use it. Every test here runs identically on MariaDB, MySQL and PostgreSQL via the
 * merge workflow's engine matrix; durability and residue are always asserted from a second database
 * session, so a commit is proven physical rather than merely visible to the writing session.
 *
 * @since  2.0.0
 */
final class TransactionBoundaryEngineIntegrationTest extends TestCase
{
    /**
     * A committed scope is durable before any `afterCommit()` hook observes it.
     *
     * The hook itself reads through a second database session, so the assertion pins the ordering the
     * contract promises: an unundoable side effect only ever runs against work that is already durable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACommitIsDurableBeforeAnyAfterCommitHookRuns(): void
    {
        $container = $this->container();
        $transactions = $this->transactions($container);
        $database = $this->connection($container);
        $observer = $this->secondSession($database);
        $table = $this->createProbeTable($database);
        $quoted = $database->getDatabasePlatform()->quoteIdentifier($table);
        $visibleInHook = null;

        try {
            $transactions->transactional(function () use (
                $transactions,
                $database,
                $observer,
                $quoted,
                &$visibleInHook,
            ): void {
                $database->executeStatement(
                    sprintf('INSERT INTO %s (probe_key, probe_value) VALUES (?, ?)', $quoted),
                    ['committed', 1],
                );
                $transactions->afterCommit(static function () use ($observer, $quoted, &$visibleInHook): void {
                    $visibleInHook = (int) $observer->fetchOne(
                        sprintf('SELECT COUNT(*) FROM %s WHERE probe_key = ?', $quoted),
                        ['committed'],
                    );
                });
                self::assertSame(0, (int) $observer->fetchOne(
                    sprintf('SELECT COUNT(*) FROM %s WHERE probe_key = ?', $quoted),
                    ['committed'],
                ), 'An uncommitted write must not be visible to another session.');
            });

            self::assertSame(1, $visibleInHook, 'The commit hook must observe the work as durable.');
        } finally {
            $observer->close();
            $database->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $quoted));
        }
    }

    /**
     * A rolled-back command leaves no residue and hands its gapless number straight back.
     *
     * This is the allocator's documented promise: the number joins the command transaction, so a create
     * that fails after allocating reserves nothing, and the next command receives the same value with no
     * gap torn in the run.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testARolledBackCommandLeavesNoResidueAndHandsItsNumberStraightBack(): void
    {
        $container = $this->container();
        $transactions = $this->transactions($container);
        $database = $this->connection($container);
        $observer = $this->secondSession($database);
        $allocator = $container->get(NumberSequenceAllocator::class);
        $tables = $this->tables($container);
        self::assertInstanceOf(NumberSequenceAllocator::class, $allocator);
        $definitionId = Uuid::uuid7()->toString();
        $now = new DateTimeImmutable('2026-08-18T09:00:00', new DateTimeZone('UTC'));
        $allocate = static fn (): int => $allocator->allocate(
            'default',
            $definitionId,
            'boundary_probe',
            '-',
            '2026',
            $now,
        );
        $counter = static fn (): string => (string) $observer->fetchOne(sprintf(
            'SELECT current_value FROM %s WHERE definition_id = ? AND field_handle = ?',
            (new TableNames($observer, $tables->prefix()))->quoted('business_number_sequences'),
        ), [$definitionId, 'boundary_probe'], [Types::GUID, Types::STRING]);

        try {
            self::assertSame(1, $transactions->transactional($allocate));
            self::assertSame('1', $counter());

            $failure = new RuntimeException('the command fails after allocating');
            $rolledBack = [];
            try {
                $transactions->transactional(function () use (
                    $transactions,
                    $allocate,
                    $failure,
                    &$rolledBack,
                ): void {
                    self::assertSame(2, $allocate(), 'The failing command must be handed the next value.');
                    $transactions->afterRollback(static function () use (&$rolledBack): void {
                        $rolledBack[] = 'compensated';
                    });
                    throw $failure;
                });
                self::fail('The failing command must leave the transaction boundary.');
            } catch (RuntimeException $exception) {
                self::assertSame($failure, $exception);
            }

            self::assertSame('1', $counter(), 'A rolled-back allocation must reserve nothing.');
            self::assertSame(['compensated'], $rolledBack);
            self::assertSame(
                2,
                $transactions->transactional($allocate),
                'The replayed command must receive the exact number the rollback handed back.',
            );
            self::assertSame('2', $counter());
        } finally {
            $observer->close();
        }
    }

    /**
     * A driver refusal crosses the seam as its translated DBAL type and discards the whole scope.
     *
     * The boundary promises translation without invention: the duplicate key surfaces as the typed
     * `UniqueConstraintViolationException` DBAL derives from the driver error, and the write that
     * preceded the refusal is rolled back with the scope rather than surviving it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testADriverRefusalCrossesTheSeamTranslatedAndTheScopeRollsBack(): void
    {
        $container = $this->container();
        $transactions = $this->transactions($container);
        $database = $this->connection($container);
        $observer = $this->secondSession($database);
        $table = $this->createProbeTable($database);
        $quoted = $database->getDatabasePlatform()->quoteIdentifier($table);

        try {
            try {
                $transactions->transactional(function () use ($database, $quoted): void {
                    $insert = sprintf('INSERT INTO %s (probe_key, probe_value) VALUES (?, ?)', $quoted);
                    $database->executeStatement($insert, ['first', 1]);
                    $database->executeStatement($insert, ['first', 2]);
                });
                self::fail('The duplicate key must leave the transaction boundary.');
            } catch (UniqueConstraintViolationException) {
                // The typed translation is the assertion; nothing else may arrive here.
            }

            self::assertSame(0, (int) $observer->fetchOne(
                sprintf('SELECT COUNT(*) FROM %s', $quoted),
            ), 'The write preceding the driver refusal must be discarded with the scope.');
        } finally {
            $observer->close();
            $database->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $quoted));
        }
    }

    /**
     * A retryable contention failure crosses the seam classified, compensated, and cleanly replayable.
     *
     * The scenario is engine-appropriate: on MariaDB and MySQL a bounded lock wait against a row another
     * session holds raises the retryable lock-wait classification, while on PostgreSQL two serializable
     * transactions in write-skew raise a serialization failure, which DBAL classifies retryable under the
     * same `RetryableException` contract. Either way the seam must surface the classified failure
     * unchanged, fire the rollback hooks, leave no residue, and let the identical operation succeed on
     * replay once the contention is gone.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testARetryableContentionFailureCrossesTheSeamAndTheReplaySucceeds(): void
    {
        $container = $this->container();
        $database = $this->connection($container);
        $platform = $database->getDatabasePlatform();
        if ($platform instanceof SQLitePlatform) {
            self::markTestSkipped('SQLite admits one writer at a time; no second session can contend.');
        }

        $table = $this->createProbeTable($database);
        $quoted = $platform->quoteIdentifier($table);
        $insert = sprintf('INSERT INTO %s (probe_key, probe_value) VALUES (?, ?)', $quoted);
        $database->executeStatement($insert, ['r1', 0]);
        $database->executeStatement($insert, ['r2', 0]);

        try {
            if ($platform instanceof AbstractMySQLPlatform) {
                $this->assertLockWaitContentionIsRetryableThroughTheSeam($database, $quoted);
            } else {
                $this->assertSerializationFailureIsRetryableThroughTheSeam($database, $quoted);
            }
        } finally {
            $database->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $quoted));
        }
    }

    /**
     * A non-retryable domain failure crosses the seam as the same instance and is not reclassified.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testANonRetryableDomainFailureCrossesTheSeamUnchanged(): void
    {
        $container = $this->container();
        $transactions = $this->transactions($container);
        $database = $this->connection($container);
        $observer = $this->secondSession($database);
        $table = $this->createProbeTable($database);
        $quoted = $database->getDatabasePlatform()->quoteIdentifier($table);
        $failure = new DomainException('the posting violates a business rule');

        try {
            try {
                $transactions->transactional(function () use ($database, $quoted, $failure): void {
                    $database->executeStatement(
                        sprintf('INSERT INTO %s (probe_key, probe_value) VALUES (?, ?)', $quoted),
                        ['domain', 1],
                    );
                    throw $failure;
                });
                self::fail('The domain failure must leave the transaction boundary.');
            } catch (DomainException $exception) {
                self::assertSame($failure, $exception, 'The seam must not wrap or replace a domain failure.');
                self::assertNotInstanceOf(RetryableException::class, $exception);
            }

            self::assertSame(0, (int) $observer->fetchOne(
                sprintf('SELECT COUNT(*) FROM %s', $quoted),
            ), 'A refused command must leave nothing behind.');
        } finally {
            $observer->close();
            $database->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $quoted));
        }
    }

    /**
     * A nested call joins the one physical transaction, and an outer failure discards the inner work.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testANestedCallJoinsOnePhysicalTransactionWhoseFateIsShared(): void
    {
        $container = $this->container();
        $transactions = $this->transactions($container);
        $database = $this->connection($container);
        $observer = $this->secondSession($database);
        $table = $this->createProbeTable($database);
        $quoted = $database->getDatabasePlatform()->quoteIdentifier($table);
        $failure = new RuntimeException('the outer command fails after the inner one succeeded');

        try {
            try {
                $transactions->transactional(function () use (
                    $transactions,
                    $database,
                    $quoted,
                    $failure,
                ): void {
                    self::assertSame(1, $database->getTransactionNestingLevel());
                    $insert = sprintf('INSERT INTO %s (probe_key, probe_value) VALUES (?, ?)', $quoted);
                    $database->executeStatement($insert, ['outer', 1]);
                    $inner = $transactions->transactional(function () use ($database, $quoted, $insert): string {
                        self::assertSame(
                            1,
                            $database->getTransactionNestingLevel(),
                            'A nested call must join the open transaction, not stack a savepoint.',
                        );
                        $database->executeStatement($insert, ['inner', 2]);

                        return 'inner-result';
                    });
                    self::assertSame('inner-result', $inner);
                    self::assertSame(2, (int) $database->fetchOne(
                        sprintf('SELECT COUNT(*) FROM %s', $quoted),
                    ), 'The outer scope must see the inner scope\'s writes.');
                    throw $failure;
                });
                self::fail('The outer failure must leave the transaction boundary.');
            } catch (RuntimeException $exception) {
                self::assertSame($failure, $exception);
            }

            self::assertSame(0, (int) $observer->fetchOne(
                sprintf('SELECT COUNT(*) FROM %s', $quoted),
            ), 'An outer failure must discard the whole nest, the completed inner scope included.');
        } finally {
            $observer->close();
            $database->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $quoted));
        }
    }

    /**
     * The audit entry and the outbox message commit with the command transaction or not at all.
     *
     * Both adapters deliberately open no transaction of their own, which is the property this pins: a
     * failing command discards its audit entry, its outbox row and its projection journal row together,
     * and a succeeding command makes all of them durable together.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAuditAndOutboxEffectsShareTheCommandTransactionsFate(): void
    {
        $container = $this->container();
        $transactions = $this->transactions($container);
        $database = $this->connection($container);
        $tables = $this->tables($container);
        $observer = $this->secondSession($database);
        $audit = $container->get(AuditRecorder::class);
        $clock = $container->get(ClockInterface::class);
        self::assertInstanceOf(AuditRecorder::class, $audit);
        self::assertInstanceOf(ClockInterface::class, $clock);
        $outbox = new DoctrineOutboxStore($database, $tables, $transactions, $clock, $this->contracts());
        $observerTables = new TableNames($observer, $tables->prefix());
        $count = static fn (string $table, string $column, string $value): int => (int) $observer->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE %s = ?', $observerTables->quoted($table), $column),
            [$value],
        );

        try {
            $discarded = $this->event();
            $discardedAudit = Uuid::uuid7()->toString();
            try {
                $transactions->transactional(function () use ($audit, $outbox, $discarded, $discardedAudit): void {
                    $audit->record($this->auditEvent($discardedAudit, $discarded->aggregateId()));
                    $outbox->append($discarded);
                    throw new RuntimeException('the command fails after recording its effects');
                });
                self::fail('The failing command must leave the transaction boundary.');
            } catch (RuntimeException $exception) {
                self::assertSame('the command fails after recording its effects', $exception->getMessage());
            }
            self::assertSame(0, $count('audit_events', 'id', $discardedAudit));
            self::assertSame(0, $count('integration_outbox', 'event_id', $discarded->eventId()));
            self::assertSame(0, $count('business_projection_source_events', 'event_id', $discarded->eventId()));

            $durable = $this->event();
            $durableAudit = Uuid::uuid7()->toString();
            $transactions->transactional(function () use ($audit, $outbox, $durable, $durableAudit): void {
                $audit->record($this->auditEvent($durableAudit, $durable->aggregateId()));
                $outbox->append($durable);
            });
            self::assertSame(1, $count('audit_events', 'id', $durableAudit));
            self::assertSame(1, $count('integration_outbox', 'event_id', $durable->eventId()));
            self::assertSame(1, $count('business_projection_source_events', 'event_id', $durable->eventId()));
            $database->delete($tables->raw('integration_outbox'), ['event_id' => $durable->eventId()]);
        } finally {
            $observer->close();
        }
    }

    /**
     * Provoke a bounded lock wait on a MySQL-family engine and prove the seam's retryable contract.
     *
     * @param   Connection  $shared  The suite's shared connection, used as the rival that holds the row.
     * @param   string      $quoted  Quoted probe-table name both sessions contend on.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertLockWaitContentionIsRetryableThroughTheSeam(Connection $shared, string $quoted): void
    {
        $seamConnection = $this->secondSession($shared);
        $seamConnection->executeStatement('SET innodb_lock_wait_timeout = 1');
        $seam = new DoctrineTransactionManager($seamConnection);
        $update = sprintf('UPDATE %s SET probe_value = probe_value + 1 WHERE probe_key = ?', $quoted);
        $operation = static function () use ($seamConnection, $update): void {
            $seamConnection->executeStatement($update, ['r1']);
        };

        try {
            $shared->beginTransaction();
            $shared->executeStatement(
                sprintf('SELECT probe_value FROM %s WHERE probe_key = ? FOR UPDATE', $quoted),
                ['r1'],
            );

            $this->assertRetryableCrossesTheSeam($seam, $operation);
            $shared->rollBack();

            $seam->transactional($operation);
            self::assertSame('1', (string) $shared->fetchOne(
                sprintf('SELECT probe_value FROM %s WHERE probe_key = ?', $quoted),
                ['r1'],
            ), 'The replayed operation must succeed once the rival releases the row.');
        } finally {
            if ($shared->isTransactionActive()) {
                $shared->rollBack();
            }
            $seamConnection->close();
        }
    }

    /**
     * Provoke a PostgreSQL serialization failure by write-skew and prove the seam's retryable contract.
     *
     * Two serializable transactions each read the row the other writes; the first committer wins and the
     * second aborts with SQLSTATE 40001, which DBAL classifies under `RetryableException`. The victim is
     * ordinarily the seam side because it commits last; the loop tolerates the checker occasionally
     * choosing the rival instead by rebuilding the skew until the seam side is the one refused.
     *
     * @param   Connection  $shared  The suite's shared connection the probe rows were created on.
     * @param   string      $quoted  Quoted probe-table name both transactions skew across.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertSerializationFailureIsRetryableThroughTheSeam(Connection $shared, string $quoted): void
    {
        $seamConnection = $this->secondSession($shared);
        $rival = $this->secondSession($shared);
        $seam = new DoctrineTransactionManager($seamConnection);
        $read = sprintf('SELECT probe_value FROM %s WHERE probe_key = ?', $quoted);
        $write = sprintf('UPDATE %s SET probe_value = probe_value + 1 WHERE probe_key = ?', $quoted);

        try {
            $refused = false;
            for ($attempt = 0; $attempt < 5 && !$refused; ++$attempt) {
                $refused = $this->attemptWriteSkew($seam, $seamConnection, $rival, $read, $write);
            }
            self::assertTrue($refused, 'The write-skew must refuse the seam side within five attempts.');

            $seam->transactional(static function () use ($seamConnection, $read, $write): void {
                $seamConnection->executeStatement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
                $seamConnection->fetchOne($read, ['r2']);
                $seamConnection->executeStatement($write, ['r1']);
            });
            self::assertGreaterThan(0, (int) $shared->fetchOne($read, ['r1']), 'The replay must succeed.');
        } finally {
            foreach ([$seamConnection, $rival] as $session) {
                if ($session->isTransactionActive()) {
                    $session->rollBack();
                }
                $session->close();
            }
        }
    }

    /**
     * Build one write-skew and report whether the seam side was the serialization victim.
     *
     * @param   TransactionManager  $seam            Boundary under test, bound to the seam connection.
     * @param   Connection          $seamConnection  Session the seam drives.
     * @param   Connection          $rival           Independent session playing the other half.
     * @param   string              $read            Parameterised probe read statement.
     * @param   string              $write           Parameterised probe write statement.
     *
     * @return  bool  True when the seam side was refused with a retryable classification.
     *
     * @since   2.0.0
     */
    private function attemptWriteSkew(
        TransactionManager $seam,
        Connection $seamConnection,
        Connection $rival,
        string $read,
        string $write,
    ): bool {
        try {
            $this->assertRetryableCrossesTheSeam($seam, function () use (
                $seamConnection,
                $rival,
                $read,
                $write,
            ): void {
                $seamConnection->executeStatement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
                $seamConnection->fetchOne($read, ['r2']);
                $seamConnection->executeStatement($write, ['r1']);

                $rival->beginTransaction();
                try {
                    $rival->executeStatement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
                    $rival->fetchOne($read, ['r1']);
                    $rival->executeStatement($write, ['r2']);
                    $rival->commit();
                } catch (DbalException $exception) {
                    $rival->rollBack();
                    throw new SkewRivalRefused('the rival was chosen as the victim', 0, $exception);
                }
            });
        } catch (SkewRivalRefused $chosen) {
            self::assertInstanceOf(
                RetryableException::class,
                $chosen->getPrevious(),
                'Whichever side is refused, the refusal must carry the retryable classification.',
            );

            return false;
        }

        return true;
    }

    /**
     * Run one operation through the seam and assert the retryable contract on its refusal.
     *
     * @param   TransactionManager  $seam       Boundary under test.
     * @param   callable(): void    $operation  Work expected to be refused with a retryable failure.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertRetryableCrossesTheSeam(TransactionManager $seam, callable $operation): void
    {
        $events = [];
        try {
            $seam->transactional(function () use ($seam, $operation, &$events): void {
                $seam->afterCommit(static function () use (&$events): void {
                    $events[] = 'unexpected-commit';
                });
                $seam->afterRollback(static function () use (&$events): void {
                    $events[] = 'rolled-back';
                });
                $operation();
            });
            self::fail('The contended operation must be refused at the transaction boundary.');
        } catch (SkewRivalRefused $chosen) {
            throw $chosen;
        } catch (DbalException $exception) {
            self::assertInstanceOf(
                RetryableException::class,
                $exception,
                'Contention must reach the caller classified retryable, not as a bare driver failure.',
            );
        }

        self::assertSame(['rolled-back'], $events, 'The refusal must fire exactly the rollback hooks.');
    }

    /**
     * Boot the migrated kernel the boundary adapters are resolved from.
     *
     * @return  Container  Fully wired container over the engine the suite is pointed at.
     *
     * @since   2.0.0
     */
    private function container(): Container
    {
        return TestKernelFactory::create(Environment::fromGlobals());
    }

    /**
     * Resolve the shared authoritative connection.
     *
     * @param   Container  $container  Booted kernel container.
     *
     * @return  Connection  The connection every seam consumer in the container writes through.
     *
     * @since   2.0.0
     */
    private function connection(Container $container): Connection
    {
        $connection = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    /**
     * Resolve the transaction boundary under test.
     *
     * @param   Container  $container  Booted kernel container.
     *
     * @return  TransactionManager  The container's seam, adapted by `DoctrineTransactionManager`.
     *
     * @since   2.0.0
     */
    private function transactions(Container $container): TransactionManager
    {
        $transactions = $container->get(TransactionManager::class);
        self::assertInstanceOf(TransactionManager::class, $transactions);

        return $transactions;
    }

    /**
     * Resolve the prefixed physical table map.
     *
     * @param   Container  $container  Booted kernel container.
     *
     * @return  TableNames  Resolver applying the configured prefix.
     *
     * @since   2.0.0
     */
    private function tables(Container $container): TableNames
    {
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(TableNames::class, $tables);

        return $tables;
    }

    /**
     * Open an independent second database session on the same engine and schema.
     *
     * @param   Connection  $source  Connection whose parameters the second session copies.
     *
     * @return  Connection  A session whose reads prove physical durability rather than scope visibility.
     *
     * @since   2.0.0
     */
    private function secondSession(Connection $source): Connection
    {
        $connection = DriverManager::getConnection($source->getParams());
        $platform = $connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $connection->executeStatement("SET time_zone = '+00:00'");
        } elseif ($platform instanceof PostgreSQLPlatform) {
            $connection->executeStatement("SET TIME ZONE 'UTC'");
        }

        return $connection;
    }

    /**
     * Create a uniquely named probe table for one test's writes.
     *
     * @param   Connection  $database  Connection the table is created on.
     *
     * @return  string  The unquoted probe-table name; the caller drops it in its `finally`.
     *
     * @since   2.0.0
     */
    private function createProbeTable(Connection $database): string
    {
        $table = 'kumwe_boundary_probe_' . bin2hex(random_bytes(8));
        $database->executeStatement(sprintf(
            'CREATE TABLE %s (probe_key VARCHAR(64) NOT NULL PRIMARY KEY, probe_value INT NOT NULL)',
            $database->getDatabasePlatform()->quoteIdentifier($table),
        ));

        return $table;
    }

    /**
     * Build the contract registry the boundary outbox is validated against.
     *
     * @return  EventContractRegistry  Registry trusting exactly the probe event this test appends.
     *
     * @since   2.0.0
     */
    private function contracts(): EventContractRegistry
    {
        return new EventContractRegistry([
            new EventSchemaDefinition('business.record.changed', 1, EventSensitivity::INTERNAL, [
                'type' => 'object',
                'required' => ['record_id'],
                'properties' => ['record_id' => ['type' => 'string']],
                'additionalProperties' => false,
            ]),
        ], []);
    }

    /**
     * Build one valid durable event addressed to the default site.
     *
     * @return  IntegrationEvent  Event whose aggregate identifier is unique to this call.
     *
     * @since   2.0.0
     */
    private function event(): IntegrationEvent
    {
        $aggregateId = 'boundary-' . bin2hex(random_bytes(8));

        return new RecordedIntegrationEvent(
            'business.record.changed',
            1,
            Uuid::uuid7()->toString(),
            new DateTimeImmutable('2026-08-18T09:00:00', new DateTimeZone('UTC')),
            'boundary-actor',
            null,
            'default',
            null,
            'business.record',
            $aggregateId,
            1,
            'correlation-' . $aggregateId,
            'request-' . $aggregateId,
            EventSensitivity::INTERNAL,
            ['record_id' => $aggregateId],
        );
    }

    /**
     * Build one valid audit event describing the probe command.
     *
     * @param   string  $id         Canonical UUID the audit row is keyed on.
     * @param   string  $subjectId  Aggregate the entry describes, shared with the outbox event.
     *
     * @return  AuditEvent  Event ready for the container's digest-chained recorder.
     *
     * @since   2.0.0
     */
    private function auditEvent(string $id, string $subjectId): AuditEvent
    {
        return new AuditEvent(
            $id,
            new DateTimeImmutable('2026-08-18T09:00:00', new DateTimeZone('UTC')),
            'boundary-actor',
            'boundary.probe.commit',
            'business_record',
            $subjectId,
            'success',
            ['probe' => $subjectId],
        );
    }
}

/**
 * Signals that the write-skew checker refused the rival transaction instead of the seam side.
 *
 * @since  2.0.0
 */
final class SkewRivalRefused extends RuntimeException
{
}
