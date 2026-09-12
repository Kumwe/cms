<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessIntegration;

use DateTimeImmutable;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Application\Automation\FailureClassification;
use Kumwe\App\Application\Automation\JitterSource;
use Kumwe\App\Application\Automation\QueueRuntimePolicy;
use Kumwe\App\Application\Automation\QueueRuntimePolicyCatalog;
use Kumwe\App\Application\Automation\RetryPolicy;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\BusinessIntegration\Application\EventContractRegistry;
use Kumwe\App\BusinessIntegration\Application\InboxClaimResult;
use Kumwe\App\BusinessIntegration\Application\InboxDisposition;
use Kumwe\App\BusinessIntegration\Application\InboxLease;
use Kumwe\App\BusinessIntegration\Application\InboxStore;
use Kumwe\App\BusinessIntegration\Application\IntegrationEventConsumerDispatcher;
use Kumwe\App\BusinessIntegration\Application\TrustedRuntimeGenerationGuard;
use Kumwe\Extension\Spi\Application\ExecutionContext as ExtensionExecutionContext;
use Kumwe\Extension\Spi\BusinessIntegration\Application\IntegrationEventHandler;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\App\BusinessIntegration\Domain\EventSchemaDefinition;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\EventSensitivity;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\IntegrationEvent;
use Kumwe\App\BusinessIntegration\Domain\RecordedIntegrationEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

#[CoversClass(IntegrationEventConsumerDispatcher::class)]
final class ConsumerDispatcherTest extends TestCase
{
    public function testContributedQueueLeaseIsTheDeliveryDefaultAndCannotBeWidened(): void
    {
        $clock = new DispatcherTestClock();
        $definition = new EventConsumerDefinition(
            'acme.policy-consumer',
            'business.record.changed',
            [1],
            '1.0.0',
            'integration.default',
            false,
        );
        $event = new RecordedIntegrationEvent(
            'business.record.changed',
            1,
            Uuid::uuid7()->toString(),
            $clock->now(),
            null,
            'worker',
            'default',
            null,
            'business.record',
            'record-8',
            1,
            'correlation-2',
            'request-2',
            EventSensitivity::INTERNAL,
            ['record_id' => 'record-8'],
        );
        $registry = new EventContractRegistry([new EventSchemaDefinition(
            'business.record.changed',
            1,
            EventSensitivity::INTERNAL,
            [
                'type' => 'object',
                'required' => ['record_id'],
                'properties' => ['record_id' => ['type' => 'string']],
                'additionalProperties' => false,
            ],
        )], [$definition]);
        $inbox = $this->createMock(InboxStore::class);
        $inbox->expects(self::once())->method('receive')->with(
            $definition,
            $event,
            'consumer-worker-1',
            '7',
            30,
        )->willReturn(new InboxClaimResult(InboxDisposition::DUPLICATE));
        $transactions = $this->createStub(TransactionManager::class);
        $dispatcher = new IntegrationEventConsumerDispatcher(
            $inbox,
            $registry,
            new RetryPolicy($clock, new ZeroJitter()),
            new AlwaysCurrentRuntime(),
            $transactions,
            new NullLogger(),
            new ConsumerDispatcherQueueCatalog(new QueueRuntimePolicy(
                'integration.default',
                30,
                3,
                2,
                14,
                7,
            )),
        );
        $handler = new SuccessfulIntegrationHandler();
        $context = ExecutionContext::issueSystem(
            new \stdClass(),
            SystemIdentity::Worker,
            SiteContext::default(),
            'consumer-test',
        );

        self::assertSame(InboxDisposition::DUPLICATE, $dispatcher->consume(
            $definition,
            $event,
            $handler,
            $context,
            'consumer-worker-1',
            '7',
        ));
        try {
            $dispatcher->consume($definition, $event, $handler, $context, 'consumer-worker-1', '7', 31);
            self::fail('An explicit consumer lease exceeded its signed queue policy.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('signed policy', $exception->getMessage());
        }
    }

    public function testHandlerFailureIsRecordedAndRethrownForTransportRedelivery(): void
    {
        $clock = new DispatcherTestClock();
        $definition = new EventConsumerDefinition(
            'acme.search-index',
            'business.record.changed',
            [1],
            '1.0.0',
        );
        $event = new RecordedIntegrationEvent(
            'business.record.changed',
            1,
            Uuid::uuid7()->toString(),
            $clock->now(),
            null,
            'worker',
            'default',
            null,
            'business.record',
            'record-7',
            1,
            'correlation-1',
            'request-1',
            EventSensitivity::INTERNAL,
            ['record_id' => 'record-7'],
        );
        $registry = new EventContractRegistry([
            new EventSchemaDefinition(
                'business.record.changed',
                1,
                EventSensitivity::INTERNAL,
                [
                    'type' => 'object',
                    'required' => ['record_id'],
                    'properties' => ['record_id' => ['type' => 'string']],
                    'additionalProperties' => false,
                ],
            ),
        ], [$definition]);
        $inbox = new RecordingInboxStore($definition, $event);
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $dispatcher = new IntegrationEventConsumerDispatcher(
            $inbox,
            $registry,
            new RetryPolicy($clock, new ZeroJitter()),
            new AlwaysCurrentRuntime(),
            $transactions,
            new NullLogger(),
        );
        $handler = new FailingIntegrationHandler();
        $context = ExecutionContext::issueSystem(
            new \stdClass(),
            SystemIdentity::Worker,
            SiteContext::default(),
            'consumer-test',
        );

        try {
            $dispatcher->consume($definition, $event, $handler, $context, 'consumer-worker-1', '7');
            self::fail('A failed durable consumer must force transport redelivery.');
        } catch (RuntimeException $exception) {
            self::assertSame('handler unavailable', $exception->getMessage());
        }
        self::assertTrue($inbox->failed);
    }
}

final class RecordingInboxStore implements InboxStore
{
    public bool $failed = false;

    private InboxLease $lease;

    public function __construct(EventConsumerDefinition $consumer, IntegrationEvent $event)
    {
        $this->lease = new InboxLease(
            $consumer,
            $event,
            1,
            'consumer-worker-1',
            Uuid::uuid7()->toString(),
            '7',
        );
    }

    public function receive(
        EventConsumerDefinition $consumer,
        IntegrationEvent $event,
        string $workerId,
        string $runtimeGeneration,
        int $leaseSeconds,
    ): InboxClaimResult {
        return new InboxClaimResult(InboxDisposition::CLAIMED, $this->lease);
    }

    public function renew(InboxLease $lease, int $leaseSeconds): void
    {
    }

    public function complete(InboxLease $lease): void
    {
    }

    public function fail(
        InboxLease $lease,
        FailureClassification $classification,
        Throwable $failure,
        ?DateTimeImmutable $retryAt,
    ): void {
        $this->failed = true;
    }

    public function recent(string $consumerId, int $limit = 100): array
    {
        return [];
    }
}

final readonly class FailingIntegrationHandler implements IntegrationEventHandler
{
    public function handle(
        EventConsumerDefinition $definition,
        IntegrationEvent $event,
        ExtensionExecutionContext $context,
    ): void {
        throw new RuntimeException('handler unavailable');
    }
}

final readonly class SuccessfulIntegrationHandler implements IntegrationEventHandler
{
    public function handle(
        EventConsumerDefinition $definition,
        IntegrationEvent $event,
        ExtensionExecutionContext $context,
    ): void {
    }
}

final readonly class AlwaysCurrentRuntime implements TrustedRuntimeGenerationGuard
{
    public function assertCurrent(string $generation): void
    {
    }
}

final readonly class DispatcherTestClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-10T10:00:00+00:00');
    }
}

final readonly class ZeroJitter implements JitterSource
{
    public function between(int $minimum, int $maximum): int
    {
        return $minimum;
    }
}

final readonly class ConsumerDispatcherQueueCatalog implements QueueRuntimePolicyCatalog
{
    public function __construct(private QueueRuntimePolicy $policy)
    {
    }

    public function policy(string $queue): ?QueueRuntimePolicy
    {
        return $queue === $this->policy->queue ? $this->policy : null;
    }

    public function maximumAttempts(string $queue, string $jobType, int $requested): int
    {
        return $this->policy($queue) === null ? $requested : min($requested, $this->policy->maximumAttempts);
    }

    public function policies(): array
    {
        return [$this->policy];
    }
}
