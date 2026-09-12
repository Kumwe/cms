<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\Infrastructure\Persistence\Migration\Migration;
use Kumwe\App\Infrastructure\Persistence\Migration\MigrationLock;
use Kumwe\App\Infrastructure\Persistence\Migration\MigrationPlan;
use Kumwe\App\Infrastructure\Persistence\Migration\MigrationRepository;
use Kumwe\App\Infrastructure\Persistence\Migration\MigrationResult;
use Kumwe\App\Infrastructure\Persistence\Migration\MigrationRunner;
use Kumwe\App\Infrastructure\Persistence\Migration\NonTransactionalMigrationAction;
use Kumwe\App\Infrastructure\Persistence\Migration\NonTransactionalMigrationRecovery;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(MigrationRunner::class)]
#[CoversClass(MigrationResult::class)]
#[CoversClass(MigrationPlan::class)]
final class MigrationRunnerTest extends TestCase
{
    public function testMigrationsAreAppliedInLexicalOrderAndOnlyOnce(): void
    {
        $repository = new InMemoryMigrationRepository();
        $calls = new MigrationCallLog();
        $runner = $this->runner($repository, [
            new RecordingMigration('20260804000200_second', $calls),
            new RecordingMigration('20260804000100_first', $calls),
        ]);

        self::assertSame(
            ['20260804000100_first', '20260804000200_second'],
            $runner->migrate($this->context())->applied,
        );
        self::assertSame(['20260804000100_first', '20260804000200_second'], $calls->ids);
        self::assertFalse($runner->migrate($this->context())->changed());
    }

    public function testChecksumDriftIsRejected(): void
    {
        $repository = new InMemoryMigrationRepository();
        $repository->checksums['20260804000100_first'] = str_repeat('0', 64);
        $runner = $this->runner($repository, [
            new RecordingMigration('20260804000100_first', new MigrationCallLog()),
        ]);
        $this->expectException(RuntimeException::class);

        $runner->migrate($this->context());
    }

    public function testUnknownNewerMigrationIsRejected(): void
    {
        $repository = new InMemoryMigrationRepository();
        $repository->checksums['20260804000100_first'] = hash('sha256', '20260804000100_first');
        $repository->checksums['20260804000300_future'] = hash('sha256', '20260804000300_future');
        $runner = $this->runner($repository, [
            new RecordingMigration('20260804000100_first', new MigrationCallLog()),
        ]);
        $this->expectException(RuntimeException::class);

        $runner->migrate($this->context());
    }

    public function testMigrationGapIsRejected(): void
    {
        $repository = new InMemoryMigrationRepository();
        $repository->checksums['20260804000200_second'] = hash('sha256', '20260804000200_second');
        $runner = $this->runner($repository, [
            new RecordingMigration('20260804000100_first', new MigrationCallLog()),
            new RecordingMigration('20260804000200_second', new MigrationCallLog()),
        ]);
        $this->expectException(RuntimeException::class);

        $runner->migrate($this->context());
    }

    public function testPendingRejectsChecksumDrift(): void
    {
        $repository = new InMemoryMigrationRepository();
        $repository->checksums['20260804000100_first'] = str_repeat('f', 64);
        $runner = $this->runner($repository, [
            new RecordingMigration('20260804000100_first', new MigrationCallLog()),
        ]);
        $this->expectException(RuntimeException::class);

        $runner->pending($this->context());
    }

    public function testHistoricalChecksumCompatibilityIsExplicitAndExact(): void
    {
        $migration = new RecordingMigration(
            '20260805030000_recover_jobs_and_idempotency',
            new MigrationCallLog(),
        );
        $historical = [
            '5e55e74ae3027ecc5d4843e045cf19a3e07d0b7be1f2ce556807bb67eda61947',
            '4d7fc30104c21bda0c00947fb82bce1333daa0d542e7292ee4e96bbda1c83b5d',
        ];
        $plan = new MigrationPlan([$migration], [$migration->id() => $historical]);

        foreach ($historical as $checksum) {
            self::assertTrue($plan->complete([$migration->id() => $checksum]));
        }
        $this->expectException(RuntimeException::class);
        $plan->complete([$migration->id() => str_repeat('b', 64)]);
    }

    public function testUnknownRecoveryAttemptIsRejectedBeforeMigrationExecution(): void
    {
        $calls = new MigrationCallLog();
        $runner = $this->runner(
            new InMemoryMigrationRepository(),
            [new RecordingMigration('20260804000100_first', $calls)],
            recovery: new RecordingNonTransactionalMigrationRecovery(rejectAttempts: true),
        );

        try {
            $runner->migrate($this->context());
            self::fail('An unknown recovery attempt must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('unknown attempt', $exception->getMessage());
        }
        self::assertSame([], $calls->ids);
    }

    public function testMySqlJournalsBeforeExecutionAndCompletesAfterLedgerWrite(): void
    {
        $repository = new InMemoryMigrationRepository();
        $calls = new MigrationCallLog();
        $recovery = new RecordingNonTransactionalMigrationRecovery();
        $runner = $this->runner(
            $repository,
            [new RecordingMigration('20260804000100_first', $calls)],
            new MySQLPlatform(),
            new DirectTransactionManager(),
            $recovery,
        );

        self::assertTrue($runner->migrate($this->context())->changed());
        self::assertSame(['prepare:20260804000100_first', 'complete:20260804000100_first'], $recovery->calls);
        self::assertSame(['20260804000100_first'], $calls->ids);
        self::assertArrayHasKey('20260804000100_first', $repository->checksums);
    }

    public function testRecoveredMySqlPostconditionIsRecordedWithoutRepeatingMigration(): void
    {
        $repository = new InMemoryMigrationRepository();
        $calls = new MigrationCallLog();
        $recovery = new RecordingNonTransactionalMigrationRecovery(
            NonTransactionalMigrationAction::RecordRecovered,
        );
        $runner = $this->runner(
            $repository,
            [new RecordingMigration('20260804000100_first', $calls)],
            new MySQLPlatform(),
            new DirectTransactionManager(),
            $recovery,
        );

        self::assertTrue($runner->migrate($this->context())->changed());
        self::assertSame([], $calls->ids);
        self::assertArrayHasKey('20260804000100_first', $repository->checksums);
        self::assertSame(['prepare:20260804000100_first', 'complete:20260804000100_first'], $recovery->calls);
    }

    public function testPostgreSqlKeepsMigrationAndLedgerInOneTransactionWithoutRecoveryJournal(): void
    {
        $repository = new InMemoryMigrationRepository();
        $calls = new MigrationCallLog();
        $transactions = new RecordingTransactionManager();
        $recovery = new RecordingNonTransactionalMigrationRecovery();
        $runner = $this->runner(
            $repository,
            [new RecordingMigration('20260804000100_first', $calls)],
            new PostgreSQLPlatform(),
            $transactions,
            $recovery,
        );

        self::assertTrue($runner->migrate($this->context())->changed());
        self::assertSame(1, $transactions->calls);
        self::assertSame([], $recovery->calls);
        self::assertSame(['20260804000100_first'], $calls->ids);
        self::assertArrayHasKey('20260804000100_first', $repository->checksums);
    }

    /**
     * @param list<Migration> $migrations
     */
    private function runner(
        InMemoryMigrationRepository $repository,
        array $migrations,
        ?AbstractPlatform $platform = null,
        ?TransactionManager $transactions = null,
        ?NonTransactionalMigrationRecovery $recovery = null,
    ): MigrationRunner {
        $database = $this->createStub(Connection::class);
        $database->method('getDatabasePlatform')->willReturn(
            $platform ?? $this->createStub(AbstractPlatform::class),
        );

        return new MigrationRunner(
            $database,
            $repository,
            new DirectMigrationLock(),
            $transactions ?? new DirectTransactionManager(),
            new MigrationPlan($migrations),
            AuthorizationContext::gateway(),
            $recovery ?? new RecordingNonTransactionalMigrationRecovery(),
        );
    }

    private function context(): \Kumwe\Context\Value\ExecutionContext
    {
        return AuthorizationContext::system(SystemIdentity::Migration)->context(
            SiteContext::default(),
            'migration-test',
        );
    }
}

final class MigrationCallLog
{
    /** @var list<string> */
    public array $ids = [];
}

final readonly class RecordingMigration implements Migration
{
    public function __construct(private string $migrationId, private MigrationCallLog $log)
    {
    }

    public function id(): string
    {
        return $this->migrationId;
    }

    public function checksum(): string
    {
        return hash('sha256', $this->migrationId);
    }

    public function up(Connection $database): void
    {
        $this->log->ids[] = $this->migrationId;
    }
}

final class InMemoryMigrationRepository implements MigrationRepository
{
    /** @var array<string, string> */
    public array $checksums = [];

    public function ensureLedger(): void
    {
    }

    public function applied(): array
    {
        return $this->checksums;
    }

    public function record(string $id, string $checksum, int $executionMilliseconds): void
    {
        $this->checksums[$id] = $checksum;
    }
}

final class DirectMigrationLock implements MigrationLock
{
    public function synchronized(callable $operation): mixed
    {
        return $operation();
    }
}

final class DirectTransactionManager implements TransactionManager
{
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }

    public function afterCommit(callable $operation): void
    {
        $operation();
    }

    public function afterRollback(callable $operation): void
    {
    }
}

final class RecordingTransactionManager implements TransactionManager
{
    public int $calls = 0;

    public function transactional(callable $operation): mixed
    {
        ++$this->calls;

        return $operation();
    }

    public function afterCommit(callable $operation): void
    {
        $operation();
    }

    public function afterRollback(callable $operation): void
    {
    }
}

final class RecordingNonTransactionalMigrationRecovery implements NonTransactionalMigrationRecovery
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(
        private NonTransactionalMigrationAction $action = NonTransactionalMigrationAction::Execute,
        private bool $rejectAttempts = false,
    ) {
    }

    public function assertKnownAttempts(array $knownMigrationIds): void
    {
        if ($this->rejectAttempts) {
            throw new RuntimeException('Migration recovery contains an unknown attempt.');
        }
    }

    public function hasUnresolvedAttempts(): bool
    {
        return false;
    }

    public function prepare(Migration $migration): NonTransactionalMigrationAction
    {
        $this->calls[] = 'prepare:' . $migration->id();

        return $this->action;
    }

    public function complete(Migration $migration): void
    {
        $this->calls[] = 'complete:' . $migration->id();
    }

    public function reconcileApplied(Migration $migration): void
    {
        $this->calls[] = 'reconcile:' . $migration->id();
    }
}
