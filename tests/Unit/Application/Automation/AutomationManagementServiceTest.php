<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Application\Automation;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Application\Automation\AutomationManagementService;
use Kumwe\App\Application\Automation\Job\ScheduleRepository;
use Kumwe\App\Application\Automation\JobHandler;
use Kumwe\App\Application\Automation\JobHandlerRegistry;
use Kumwe\App\Application\Automation\JobQueue;
use Kumwe\App\Application\Automation\QueueRuntimeOperations;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Kumwe\App\Tests\Support\AuthorizationContext;

#[CoversClass(AutomationManagementService::class)]
#[UsesClass(AuditEvent::class)]
#[UsesClass(JobHandlerRegistry::class)]
final class AutomationManagementServiceTest extends TestCase
{
    private const ACTOR = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';
    private const SCHEDULE = '018f22e2-7c8b-7ab0-8f3a-88e8026bb401';
    private const JOB = '018f22e2-7c8b-7ab0-8f3a-88e8026bb402';

    public function testCreatesOnlyRegisteredJobTypeInsideAuditedTransaction(): void
    {
        $firstRun = new DateTimeImmutable('2026-08-04T11:00:00+00:00');
        $schedules = $this->createMock(ScheduleRepository::class);
        $schedules->expects(self::once())->method('create')->with(
            self::isInstanceOf(ExecutionContext::class),
            'Purge sessions',
            '0 * * * *',
            'UTC',
            'system.sessions.purge',
            [],
            'maintenance',
            $firstRun,
        )->willReturn(self::SCHEDULE);
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::once())->method('record')->with(self::callback(
            static fn (AuditEvent $event): bool => $event->action() === 'automation.schedule.create'
                && $event->subjectId() === self::SCHEDULE
                && $event->metadata() === ['job_type' => 'system.sessions.purge'],
        ));

        $id = $this->service($schedules, audit: $audit)->createSchedule(
            $this->context(),
            'Purge sessions',
            '0 * * * *',
            'UTC',
            'system.sessions.purge',
            [],
            'maintenance',
            $firstRun,
        );

        self::assertSame(self::SCHEDULE, $id);
    }

    public function testRejectsScheduleForUnregisteredJobTypeBeforePersistence(): void
    {
        $schedules = $this->createMock(ScheduleRepository::class);
        $schedules->expects(self::never())->method('create');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no registered handler');

        $this->service($schedules)->createSchedule(
            $this->context(),
            'Unknown job',
            '0 * * * *',
            'UTC',
            'unknown.job',
            [],
            'default',
            new DateTimeImmutable('2026-08-04T11:00:00+00:00'),
        );
    }

    public function testEnablesScheduleWithOptimisticVersionAndAudit(): void
    {
        $schedules = $this->createMock(ScheduleRepository::class);
        $schedules->expects(self::once())->method('setEnabled')->with(
            self::isInstanceOf(ExecutionContext::class),
            self::SCHEDULE,
            3,
            true,
        );
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::once())->method('record')->with(self::callback(
            static fn (AuditEvent $event): bool => $event->action() === 'automation.schedule.enable'
                && $event->subjectId() === self::SCHEDULE,
        ));

        $this->service($schedules, audit: $audit)->setScheduleEnabled(
            $this->context(),
            self::SCHEDULE,
            3,
            true,
        );
    }

    public function testRetriesAndCancelsJobsThroughSharedQueueBoundary(): void
    {
        $jobs = $this->createMock(JobQueue::class);
        $jobs->expects(self::once())->method('retry')->with(
            self::isInstanceOf(ExecutionContext::class),
            self::JOB,
        );
        $jobs->expects(self::once())->method('cancel')->with(
            self::isInstanceOf(ExecutionContext::class),
            self::JOB,
        );
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::exactly(2))->method('record')->with(self::callback(
            static fn (AuditEvent $event): bool => in_array($event->action(), [
                'automation.job.retry',
                'automation.job.cancel',
            ], true),
        ));
        $service = $this->service($this->createStub(ScheduleRepository::class), $jobs, $audit);

        $service->retryJob($this->context(), self::JOB);
        $service->cancelJob($this->context(), self::JOB);
    }

    public function testListsAndPurgesContributedQueuePolicyThroughAuditedOperatorBoundary(): void
    {
        $runtime = $this->createMock(QueueRuntimeOperations::class);
        $runtime->expects(self::once())->method('inventory')->willReturn([[
            'queue' => 'acme.example.priority',
            'in_flight' => 1,
        ]]);
        $runtime->expects(self::once())->method('purge')->with(
            self::isInstanceOf(ExecutionContext::class),
            'acme.example.priority',
            25,
        )->willReturn(3);
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::once())->method('record')->with(self::callback(
            static fn (AuditEvent $event): bool => $event->action() === 'automation.queue.retention.purge'
                && $event->subjectId() === 'acme.example.priority'
                && $event->metadata() === ['purged' => 3, 'limit' => 25],
        ));
        $service = $this->service(
            $this->createStub(ScheduleRepository::class),
            audit: $audit,
            queueRuntime: $runtime,
        );

        self::assertSame([[
            'queue' => 'acme.example.priority',
            'in_flight' => 1,
        ]], $service->queuePolicies($this->context()));
        self::assertSame(3, $service->purgeQueue($this->context(), 'acme.example.priority', 25));
    }

    private function service(
        ScheduleRepository $schedules,
        ?JobQueue $jobs = null,
        ?AuditRecorder $audit = null,
        ?QueueRuntimeOperations $queueRuntime = null,
    ): AutomationManagementService {
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-04T10:00:00+00:00'));

        return new AutomationManagementService(
            $schedules,
            $jobs ?? $this->createStub(JobQueue::class),
            new JobHandlerRegistry([new SessionPurgeHandler()]),
            $transactions,
            $audit ?? $this->createStub(AuditRecorder::class),
            $clock,
            AuthorizationContext::gateway(),
            new \Kumwe\App\Application\Automation\JobExecutionScope(),
            $queueRuntime,
        );
    }

    private function context(): ExecutionContext
    {
        return AuthorizationContext::human(['automation.manage'], self::ACTOR);
    }
}

final class SessionPurgeHandler implements JobHandler
{
    public function type(): string
    {
        return 'system.sessions.purge';
    }

    public function handle(array $payload, ExecutionContext $context): void
    {
    }
}
