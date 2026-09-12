<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(DoctrineTransactionManager::class)]
final class DoctrineTransactionManagerTest extends TestCase
{
    public function testNestedCallbacksWaitForTheOutermostCommit(): void
    {
        $transactions = TestKernelFactory::create(Environment::fromGlobals())->get(TransactionManager::class);
        self::assertInstanceOf(TransactionManager::class, $transactions);
        $events = [];

        $transactions->transactional(function () use ($transactions, &$events): void {
            $transactions->afterCommit(static function () use (&$events): void {
                $events[] = 'outer-commit';
            });
            $transactions->transactional(function () use ($transactions, &$events): void {
                $transactions->afterCommit(static function () use (&$events): void {
                    $events[] = 'inner-commit';
                });
            });
            self::assertSame([], $events);
        });

        self::assertSame(['outer-commit', 'inner-commit'], $events);
    }

    public function testNestedRollbackCallbacksRunAndCommitCallbacksAreDiscarded(): void
    {
        $transactions = TestKernelFactory::create(Environment::fromGlobals())->get(TransactionManager::class);
        self::assertInstanceOf(TransactionManager::class, $transactions);
        $events = [];

        try {
            $transactions->transactional(function () use ($transactions, &$events): void {
                $transactions->afterCommit(static function () use (&$events): void {
                    $events[] = 'unexpected-commit';
                });
                $transactions->afterRollback(static function () use (&$events): void {
                    $events[] = 'outer-rollback';
                });
                $transactions->transactional(function () use ($transactions, &$events): void {
                    $transactions->afterRollback(static function () use (&$events): void {
                        $events[] = 'inner-rollback';
                    });
                });
                throw new RuntimeException('force outer rollback');
            });
            self::fail('The outer transaction must fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('force outer rollback', $exception->getMessage());
        }

        self::assertSame(['outer-rollback', 'inner-rollback'], $events);
    }

    /**
     * A caught nested failure must still roll back every write in the enclosing physical transaction.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACaughtNestedFailureDoomsTheWholeTransaction(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $transactions = $container->get(TransactionManager::class);
        $database = $container->get(Connection::class);
        self::assertInstanceOf(TransactionManager::class, $transactions);
        self::assertInstanceOf(Connection::class, $database);

        $table = 'kumwe_transaction_probe_' . bin2hex(random_bytes(8));
        $quoted = $database->getDatabasePlatform()->quoteIdentifier($table);
        $database->executeStatement(sprintf(
            'CREATE TABLE %s (probe_value VARCHAR(64) NOT NULL PRIMARY KEY)',
            $quoted,
        ));
        $events = [];
        $failure = new RuntimeException('force nested rollback');

        try {
            try {
                $transactions->transactional(function () use (
                    $transactions,
                    $database,
                    $quoted,
                    $failure,
                    &$events,
                ): void {
                    $database->executeStatement(
                        sprintf('INSERT INTO %s (probe_value) VALUES (?)', $quoted),
                        ['outer-before'],
                    );
                    $transactions->afterCommit(static function () use (&$events): void {
                        $events[] = 'unexpected-outer-commit';
                    });
                    $transactions->afterRollback(static function () use (&$events): void {
                        $events[] = 'outer-rollback';
                    });

                    try {
                        $transactions->transactional(function () use (
                            $transactions,
                            $database,
                            $quoted,
                            $failure,
                            &$events,
                        ): void {
                            $database->executeStatement(
                                sprintf('INSERT INTO %s (probe_value) VALUES (?)', $quoted),
                                ['inner'],
                            );
                            $transactions->afterCommit(static function () use (&$events): void {
                                $events[] = 'unexpected-inner-commit';
                            });
                            $transactions->afterRollback(static function () use (&$events): void {
                                $events[] = 'inner-rollback';
                            });
                            throw $failure;
                        });
                    } catch (RuntimeException $exception) {
                        self::assertSame($failure, $exception);
                    }

                    $database->executeStatement(
                        sprintf('INSERT INTO %s (probe_value) VALUES (?)', $quoted),
                        ['outer-after'],
                    );
                });
                self::fail('The caught nested failure must be rethrown at the outer boundary.');
            } catch (RuntimeException $exception) {
                self::assertSame($failure, $exception);
            }

            self::assertSame(0, (int) $database->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $quoted)));
            self::assertSame(['inner-rollback', 'outer-rollback'], $events);
        } finally {
            $database->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $quoted));
        }
    }

    /**
     * A failing rollback callback cannot mask the operation failure or prevent later compensations.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRollbackHookFailuresDoNotReplaceTheOperationFailure(): void
    {
        $transactions = TestKernelFactory::create(Environment::fromGlobals())->get(TransactionManager::class);
        self::assertInstanceOf(TransactionManager::class, $transactions);
        $failure = new RuntimeException('operation failed');
        $events = [];

        try {
            $transactions->transactional(function () use ($transactions, $failure, &$events): void {
                $transactions->afterRollback(static function (): never {
                    throw new RuntimeException('rollback hook failed');
                });
                $transactions->afterRollback(static function () use (&$events): void {
                    $events[] = 'second-rollback-hook';
                });
                throw $failure;
            });
            self::fail('The operation failure must leave the transaction boundary.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame(['second-rollback-hook'], $events);
    }

    /**
     * An exception the outer operation lets escape remains its caller-visible failure after a nested refusal.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnOuterFailureAfterACaughtNestedFailureRemainsUnchanged(): void
    {
        $transactions = TestKernelFactory::create(Environment::fromGlobals())->get(TransactionManager::class);
        self::assertInstanceOf(TransactionManager::class, $transactions);
        $nested = new RuntimeException('nested failure');
        $outer = new RuntimeException('outer failure');

        try {
            $transactions->transactional(function () use ($transactions, $nested, $outer): never {
                try {
                    $transactions->transactional(static function () use ($nested): never {
                        throw $nested;
                    });
                } catch (RuntimeException $exception) {
                    self::assertSame($nested, $exception);
                }

                throw $outer;
            });
            self::fail('The outer operation failure must leave the transaction boundary.');
        } catch (RuntimeException $exception) {
            self::assertSame($outer, $exception);
        }
    }
}
