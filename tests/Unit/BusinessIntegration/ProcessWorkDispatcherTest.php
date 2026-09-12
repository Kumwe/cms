<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessIntegration;

use DateTimeImmutable;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Application\Authorization\SystemPrincipal;
use Kumwe\App\Application\Automation\JitterSource;
use Kumwe\App\Application\Automation\JobQueue;
use Kumwe\App\Application\Automation\RetryPolicy;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\BusinessIntegration\Application\JobQueueProcessWorkHandler;
use Kumwe\App\BusinessIntegration\Application\ProcessManagerStore;
use Kumwe\App\BusinessIntegration\Application\ProcessWorkDispatcher;
use Kumwe\App\BusinessIntegration\Application\ProcessWorkHandler;
use Kumwe\App\BusinessIntegration\Application\ProcessWorkLease;
use Kumwe\App\BusinessIntegration\Application\TrustedRuntimeGenerationGuard;
use Kumwe\App\BusinessIntegration\Domain\ProcessWorkItem;
use Kumwe\App\BusinessIntegration\Domain\ProcessWorkKind;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;

#[CoversClass(ProcessWorkDispatcher::class)]
#[CoversClass(JobQueueProcessWorkHandler::class)]
final class ProcessWorkDispatcherTest extends TestCase
{
    public function testEffectAndDurableSettlementShareOneTransaction(): void
    {
        $clock = new ProcessDispatcherClock();
        $lease = new ProcessWorkLease(
            Uuid::uuid7()->toString(),
            2,
            'secondary',
            'organization-7',
            new ProcessWorkItem(
                Uuid::uuid7()->toString(),
                ProcessWorkKind::COMMAND,
                'acme.inventory.reserve',
                ['sku' => 'SKU-7'],
                $clock->now(),
            ),
            1,
            'process-worker-1',
            Uuid::uuid7()->toString(),
            '7',
            'process-correlation-identifier',
        );
        $transactions = new RecordingProcessTransactions();
        $store = $this->createMock(ProcessManagerStore::class);
        $store->expects(self::once())->method('claimWork')->willReturn($lease);
        $store->expects(self::once())->method('completeWork')->with($lease)->willReturnCallback(
            static function () use ($transactions): void {
                self::assertTrue($transactions->active);
            },
        );
        $handler = $this->createMock(ProcessWorkHandler::class);
        $handler->method('supports')->willReturn(true);
        $handler->expects(self::once())->method('handle')->willReturnCallback(
            static function (
                ProcessWorkLease $actualLease,
                ExecutionContext $actualContext
            ) use (
                $transactions,
                $lease,
            ): void {
                self::assertTrue($transactions->active);
                self::assertSame($lease, $actualLease);
                self::assertSame('secondary', $actualContext->site()->identifier());
            },
        );
        $proof = new \stdClass();
        $logger = new TestLogger();
        $dispatcher = new ProcessWorkDispatcher(
            $store,
            [$handler],
            SystemPrincipal::issue($proof, SystemIdentity::Worker),
            new RetryPolicy($clock, new ProcessDispatcherZeroJitter()),
            new ProcessDispatcherCurrentRuntime(),
            $transactions,
            $logger,
        );

        self::assertTrue($dispatcher->dispatchOne('process-worker-1', '7'));
        self::assertFalse($transactions->active);
        self::assertSame(1, $transactions->calls);
        // The work item's log line has to name the business operation, not just the process row.
        self::assertCount(1, $logger->records);
        self::assertSame('process-correlation-identifier', $logger->records[0]['context']['correlation_id'] ?? null);
    }

    public function testJobEnvelopePreservesTheLeaseTenantPartition(): void
    {
        $clock = new ProcessDispatcherClock();
        $lease = new ProcessWorkLease(
            Uuid::uuid7()->toString(),
            3,
            'secondary',
            'organization-7',
            new ProcessWorkItem(
                Uuid::uuid7()->toString(),
                ProcessWorkKind::COMPENSATION,
                'acme.inventory.release',
                ['sku' => 'SKU-7'],
                $clock->now(),
            ),
            1,
            'process-worker-1',
            Uuid::uuid7()->toString(),
            '7',
        );
        $queue = $this->createMock(JobQueue::class);
        $queue->expects(self::once())->method('enqueue')->willReturnCallback(
            static function (
                ExecutionContext $context,
                string $type,
                array $payload,
                DateTimeImmutable $availableAt,
                string $queueName,
                int $priority,
                int $maximumAttempts,
            ) use ($clock): string {
                self::assertSame('secondary', $context->site()->identifier());
                self::assertSame('acme.inventory.release', $type);
                self::assertSame('secondary', $payload['site_identifier'] ?? null);
                self::assertSame('organization-7', $payload['organization_id'] ?? null);
                self::assertSame(3, $payload['process_version'] ?? null);
                self::assertSame('SKU-7', $payload['payload']['sku'] ?? null);
                self::assertEquals($clock->now(), $availableAt);
                self::assertSame('default', $queueName);
                self::assertSame(0, $priority);
                self::assertSame(10, $maximumAttempts);

                return Uuid::uuid7()->toString();
            },
        );
        $context = SystemPrincipal::issue(new \stdClass(), SystemIdentity::Worker)->context(
            SiteContext::fromString('secondary'),
            'process-job-envelope-test',
        );

        (new JobQueueProcessWorkHandler($queue, $clock))->handle($lease, $context);
    }
}

final class RecordingProcessTransactions implements TransactionManager
{
    public bool $active = false;

    public int $calls = 0;

    public function transactional(callable $operation): mixed
    {
        ++$this->calls;
        $this->active = true;
        try {
            return $operation();
        } finally {
            $this->active = false;
        }
    }

    public function afterCommit(callable $operation): void
    {
        $operation();
    }

    public function afterRollback(callable $operation): void
    {
    }
}

final readonly class ProcessDispatcherClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-10T10:00:00+00:00');
    }
}

final readonly class ProcessDispatcherZeroJitter implements JitterSource
{
    public function between(int $minimum, int $maximum): int
    {
        return $minimum;
    }
}

final readonly class ProcessDispatcherCurrentRuntime implements TrustedRuntimeGenerationGuard
{
    public function assertCurrent(string $generation): void
    {
    }
}
