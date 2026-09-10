<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Application\Automation;

use DateTimeImmutable;
use Kumwe\App\Application\Automation\JobHandler;
use Kumwe\App\Application\Automation\JobHandlerRegistry;
use Kumwe\App\Application\Automation\GlobalJobPrincipals;
use Kumwe\App\Application\Automation\JobExecutionClass;
use Kumwe\App\Application\Automation\JobExecutionScope;
use Kumwe\App\Application\Automation\JobLeaseContext;
use Kumwe\App\Application\Automation\JobQueue;
use Kumwe\App\Application\Automation\LeaseAwareJobHandler;
use Kumwe\App\Application\Automation\RuntimeDeadline;
use Kumwe\App\Application\Automation\StoredJob;
use Kumwe\App\Application\Automation\Worker;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Authorization\AuthorizationResourceOwnershipUnknown;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Application\Authorization\OwnershipScope;
use Kumwe\App\Application\Authorization\ResourceSiteOwnership;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Throwable;
use Kumwe\Context\Contract\SystemActor;

#[CoversClass(Worker::class)]
#[CoversClass(JobLeaseContext::class)]
#[CoversClass(RuntimeDeadline::class)]
final class WorkerTest extends TestCase
{
    /**
     * Drives the wedged-handler defence with a handler that genuinely never returns on its own.
     *
     * Nothing in the suite had ever made the runtime-lease alarm fire, so the only thing standing
     * between a wedged handler and a worker pinned for good was unexecuted code. The handler here sleeps
     * for thirty seconds against a one-second budget: the alarm is a real signal, delivered by the
     * kernel to this process, and the assertion that `runOnce()` returned in well under the sleep is
     * what distinguishes an aborted handler from one that merely finished.
     */
    public function testAWedgedHandlerIsAbortedByTheRuntimeLeaseAlarmAndSettledAsAFailedAttempt(): void
    {
        $queue = new RecordingJobQueue([$this->job('wedged')]);
        $worker = $this->worker($queue, new JobHandlerRegistry([new WedgedHandler()]));

        $startedAt = hrtime(true);
        $handled = $worker->runOnce($this->context(), 'default', 'worker-one', 30, 1);
        $elapsed = (hrtime(true) - $startedAt) / 1_000_000_000;

        self::assertTrue($handled);
        self::assertLessThan(10.0, $elapsed);
        self::assertSame([], $queue->completed);
        self::assertSame([false], $queue->permanentFailures);
        self::assertCount(1, $queue->failures);
        self::assertSame('The job exceeded its maximum runtime lease.', $queue->failures[0]->getMessage());
    }

    /**
     * Proves the alarm arrangement is put back exactly as it was found, wedge or no wedge.
     *
     * A deadline that leaves its own throwing handler installed would turn the next alarm anywhere in
     * the process into a spurious job failure, so restoration is asserted by arming a real alarm
     * afterwards and observing that the sentinel — not the worker's handler — is what runs.
     */
    public function testTheRuntimeLeaseAlarmRestoresTheHandlerItFound(): void
    {
        $observed = null;
        $sentinel = static function (int $signal) use (&$observed): void {
            $observed = $signal;
        };
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, $sentinel);

        try {
            $queue = new RecordingJobQueue([$this->job('wedged')]);
            $worker = $this->worker($queue, new JobHandlerRegistry([new WedgedHandler()]));
            $worker->runOnce($this->context(), 'default', 'worker-one', 30, 1);

            self::assertSame($sentinel, pcntl_signal_get_handler(SIGALRM));

            pcntl_alarm(1);
            $deadline = microtime(true) + 5.0;
            while ($observed === null && microtime(true) < $deadline) {
                usleep(50_000);
            }
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, SIG_DFL);
        }

        self::assertSame(SIGALRM, $observed);
    }

    /**
     * Proves the worker refuses a budget it cannot enforce instead of running the handler unbounded.
     */
    public function testAnUnenforceableRuntimeLimitFailsTheJobWithoutRunningItsHandler(): void
    {
        $queue = new RecordingJobQueue([$this->job('wedged')]);
        $handler = new WedgedHandler();
        $worker = $this->worker($queue, new JobHandlerRegistry([$handler]));

        self::assertTrue($worker->runOnce($this->context(), 'default', 'worker-one', 30, 0));
        self::assertFalse($handler->entered);
        self::assertSame([], $queue->completed);
        self::assertCount(1, $queue->failures);
        self::assertSame(
            'Durable workers require a positive, enforceable handler runtime limit.',
            $queue->failures[0]->getMessage(),
        );
    }

    public function testLeaseAwareHandlerCanRenewAndCompleteFencedJob(): void
    {
        $queue = new RecordingJobQueue([$this->job('lease-aware')]);
        $handler = new RenewingHandler();
        $worker = $this->worker($queue, new JobHandlerRegistry([$handler]));

        self::assertTrue($worker->runOnce($this->context(), 'default', 'worker-one', 30));
        self::assertSame([45], $queue->renewals);
        self::assertSame(['00000000-0000-7000-8000-000000000001'], $queue->completed);
        self::assertSame(3, $queue->heartbeats);
    }

    public function testTransientFailureIsReleasedThroughQueuePolicy(): void
    {
        $queue = new RecordingJobQueue([$this->job('failing')]);
        $worker = $this->worker($queue, new JobHandlerRegistry([new FailingHandler()]));

        self::assertTrue($worker->runOnce($this->context(), 'default', 'worker-one'));
        self::assertSame([false], $queue->permanentFailures);
        self::assertSame([], $queue->completed);
    }

    public function testUnknownTypeIsPermanentlyDeadLettered(): void
    {
        $queue = new RecordingJobQueue([$this->job('unknown')]);
        $worker = $this->worker($queue, new JobHandlerRegistry([]));

        self::assertTrue($worker->runOnce($this->context(), 'default', 'worker-one'));
        self::assertSame([true], $queue->permanentFailures);
    }

    public function testEmptyQueueOnlyPublishesIdleHeartbeat(): void
    {
        $queue = new RecordingJobQueue([]);
        $worker = $this->worker($queue, new JobHandlerRegistry([]));

        self::assertFalse($worker->runOnce($this->context(), 'default', 'worker-one'));
        self::assertSame(1, $queue->heartbeats);
    }

    public function testHandlerReceivesTheDurablyOwnedJobSite(): void
    {
        $queue = new RecordingJobQueue([$this->job('site-aware')]);
        $handler = new SiteCapturingHandler();
        $worker = $this->worker($queue, new JobHandlerRegistry([$handler]), 'corporate');

        self::assertTrue($worker->runOnce($this->context(), 'default', 'worker-one'));
        self::assertSame('corporate', $handler->site);
    }

    public function testOneWorkerConsumesSharedQueueJobsInTheirIndependentSites(): void
    {
        $firstId = '00000000-0000-7000-8000-000000000001';
        $secondId = '00000000-0000-7000-8000-000000000002';
        $queue = new RecordingJobQueue([
            $this->job('multi-site', $firstId),
            $this->job('multi-site', $secondId),
        ]);
        $handler = new MultiSiteCapturingHandler();
        $ownership = new class ($firstId, $secondId) implements ResourceSiteOwnership {
            public function __construct(private string $firstId, private string $secondId)
            {
            }

            public function scopeFor(AuthorizationResource $resource): OwnershipScope
            {
                return OwnershipScope::site(match ($resource->identifier()) {
                    $this->firstId => SiteContext::fromString('corporate'),
                    $this->secondId => SiteContext::fromString('storefront'),
                    default => SiteContext::default(),
                });
            }
        };
        $worker = new Worker(
            $queue,
            new JobHandlerRegistry([$handler]),
            AuthorizationContext::gateway(ownership: $ownership),
            $ownership,
            AuthorizationContext::system(SystemIdentity::Worker),
            new JobExecutionScope(),
            $this->globalPrincipals(),
        );

        self::assertTrue($worker->runOnce($this->context(), 'shared', 'worker-one'));
        self::assertTrue($worker->runOnce($this->context(), 'shared', 'worker-one'));
        self::assertSame(['corporate', 'storefront'], $handler->sites);
        self::assertSame([$firstId, $secondId], $queue->completed);
    }

    public function testJobRetiredBetweenClaimAndExecutionIsNeverHandled(): void
    {
        $queue = new RecordingJobQueue([$this->job('site-aware')]);
        $handler = new SiteCapturingHandler();
        $ownership = new class implements ResourceSiteOwnership {
            public function scopeFor(AuthorizationResource $resource): OwnershipScope
            {
                throw new AuthorizationResourceOwnershipUnknown($resource);
            }
        };
        $worker = new Worker(
            $queue,
            new JobHandlerRegistry([$handler]),
            AuthorizationContext::gateway(ownership: $ownership),
            $ownership,
            AuthorizationContext::system(SystemIdentity::Worker),
            new JobExecutionScope(),
            $this->globalPrincipals(),
        );

        self::assertTrue($worker->runOnce($this->context(), 'default', 'worker-one'));
        self::assertNull($handler->site);
        self::assertSame([], $queue->completed);
        self::assertSame([], $queue->permanentFailures);
        self::assertSame(1, $queue->heartbeats);
    }

    public function testInstallationGlobalJobUsesItsDedicatedPrincipalAndWorkerLeaseAuthority(): void
    {
        $queue = new RecordingJobQueue([$this->job(
            'system.idempotency.purge',
            executionClass: JobExecutionClass::Installation,
        )]);
        $handler = new IdentityCapturingHandler('system.idempotency.purge');
        $worker = $this->worker($queue, new JobHandlerRegistry([$handler]));

        self::assertTrue($worker->runOnce($this->context(), 'default', 'worker-one'));
        self::assertSame(SystemIdentity::InstallationMaintenance, $handler->identity);
        self::assertSame([SystemIdentity::Worker], $queue->completionIdentities);
    }

    private function worker(
        JobQueue $queue,
        JobHandlerRegistry $handlers,
        string $site = SiteContext::DEFAULT,
    ): Worker {
        $ownership = AuthorizationContext::ownership($site);

        return new Worker(
            $queue,
            $handlers,
            AuthorizationContext::gateway(ownership: $ownership),
            $ownership,
            AuthorizationContext::system(SystemIdentity::Worker),
            new JobExecutionScope(),
            $this->globalPrincipals(),
        );
    }

    private function job(
        string $type,
        string $id = '00000000-0000-7000-8000-000000000001',
        JobExecutionClass $executionClass = JobExecutionClass::Site,
    ): StoredJob {
        return new StoredJob(
            $id,
            'default',
            $type,
            [],
            1,
            1,
            5,
            '00000000-0000-7000-8000-000000000101',
            $executionClass->value,
        );
    }

    private function globalPrincipals(): GlobalJobPrincipals
    {
        return new GlobalJobPrincipals(
            AuthorizationContext::system(SystemIdentity::InstallationMaintenance),
            AuthorizationContext::system(SystemIdentity::ExtensionMaterializer),
        );
    }

    private function context(): ExecutionContext
    {
        return AuthorizationContext::system(SystemIdentity::Worker)->context(
            SiteContext::default(),
            'worker-test-request',
        );
    }
}

final class RecordingJobQueue implements JobQueue
{
    /** @var list<StoredJob> */
    private array $jobs;
    /** @var list<int> */
    public array $renewals = [];
    /** @var list<string> */
    public array $completed = [];
    /** @var list<bool> */
    public array $permanentFailures = [];
    /** @var list<Throwable> */
    public array $failures = [];
    /** @var list<SystemActor|null> */
    public array $completionIdentities = [];
    public int $heartbeats = 0;

    /** @param list<StoredJob> $jobs */
    public function __construct(array $jobs)
    {
        $this->jobs = $jobs;
    }

    public function enqueue(
        ExecutionContext $context,
        string $type,
        array $payload,
        DateTimeImmutable $availableAt,
        string $queue = 'default',
        int $priority = 0,
        int $maximumAttempts = 5,
    ): string {
        return '00000000-0000-7000-8000-000000000001';
    }

    public function claim(
        ExecutionContext $context,
        string $queue,
        string $workerId,
        int $leaseSeconds,
    ): ?StoredJob {
        return array_shift($this->jobs);
    }

    public function renew(
        ExecutionContext $context,
        StoredJob $job,
        string $workerId,
        int $leaseSeconds,
    ): void {
        $this->renewals[] = $leaseSeconds;
    }

    public function complete(ExecutionContext $context, StoredJob $job, string $workerId): void
    {
        $this->completed[] = $job->id;
        $this->completionIdentities[] = $context->systemActor();
    }

    public function fail(
        ExecutionContext $context,
        StoredJob $job,
        string $workerId,
        Throwable $failure,
        bool $permanent,
    ): void {
        $this->permanentFailures[] = $permanent;
        $this->failures[] = $failure;
    }

    public function heartbeat(
        ExecutionContext $context,
        string $workerId,
        string $queue,
        ?string $jobId = null,
    ): void {
        $this->heartbeats++;
    }

    public function disconnect(ExecutionContext $context, string $workerId, string $queue): void
    {
    }

    public function all(ExecutionContext $context, int $limit = 100): array
    {
        return [];
    }

    public function retry(ExecutionContext $context, string $id): void
    {
    }

    public function cancel(ExecutionContext $context, string $id): void
    {
    }
}

final class RenewingHandler implements LeaseAwareJobHandler
{
    public function type(): string
    {
        return 'lease-aware';
    }

    public function handle(array $payload, ExecutionContext $context): void
    {
        throw new \LogicException('The lease-aware entry point must be used.');
    }

    public function handleWithLease(
        array $payload,
        ExecutionContext $context,
        JobLeaseContext $lease,
    ): void {
        $lease->renew(45);
    }
}

final class SiteCapturingHandler implements JobHandler
{
    public ?string $site = null;

    public function type(): string
    {
        return 'site-aware';
    }

    public function handle(array $payload, ExecutionContext $context): void
    {
        $this->site = $context->site()->identifier();
    }
}

final class MultiSiteCapturingHandler implements JobHandler
{
    /** @var list<string> */
    public array $sites = [];

    public function type(): string
    {
        return 'multi-site';
    }

    public function handle(array $payload, ExecutionContext $context): void
    {
        $this->sites[] = $context->site()->identifier();
    }
}

final class IdentityCapturingHandler implements JobHandler
{
    public ?SystemActor $identity = null;

    public function __construct(private string $jobType)
    {
    }

    public function type(): string
    {
        return $this->jobType;
    }

    public function handle(array $payload, ExecutionContext $context): void
    {
        $this->identity = $context->systemActor();
    }
}

final class FailingHandler implements JobHandler
{
    public function type(): string
    {
        return 'failing';
    }

    public function handle(array $payload, ExecutionContext $context): void
    {
        throw new \RuntimeException('Expected failure.');
    }
}

/** A handler that returns only because something outside it intervened. */
final class WedgedHandler implements JobHandler
{
    public bool $entered = false;

    public function type(): string
    {
        return 'wedged';
    }

    public function handle(array $payload, ExecutionContext $context): void
    {
        $this->entered = true;
        $deadline = microtime(true) + 30.0;
        while (microtime(true) < $deadline) {
            sleep(1);
        }
    }
}
