<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Automation;

use Kumwe\App\Kernel\Container;
use Kumwe\App\Application\Automation\JobHandler;
use Kumwe\App\Application\Automation\JobHandlerRegistry;
use Kumwe\App\Application\Automation\JobQueue;
use Kumwe\App\Application\Automation\Worker;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Infrastructure\Automation\DoctrineJobQueue;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use ReflectionProperty;
use RuntimeException;

/**
 * Kills a live worker in the middle of a job and proves the platform's recovery story on real processes.
 *
 * What existed before this drill was a store-level rehearsal: a test moved a lease timestamp into the
 * past and watched the reaper do its job. That proves the reaper, not the recovery. Nothing had ever
 * taken a worker away from work it was actually doing, so the crash-only posture the documentation
 * states — die, be restarted, let the fence sort out who owns what — rested on reasoning.
 *
 * This drill spawns a second operating-system process running the real worker against the real queue,
 * waits until its handler has demonstrably started, and sends it `SIGKILL`. Nothing is simulated: there
 * is no unwind, no `finally`, no settlement, no chance to release the lease — which is exactly the state
 * a power loss or an OOM kill leaves behind. What follows is then observed rather than assumed: the
 * fence holds while the lease is alive, the wall clock alone releases it, the successor claims the same
 * job under a new token with the attempt count moved on, and the effect lands exactly once.
 *
 * @since  2.0.0
 */
#[CoversClass(Worker::class)]
#[CoversClass(DoctrineJobQueue::class)]
final class KilledWorkerRecoveryIntegrationTest extends TestCase
{
    /**
     * Lease the killed worker holds; short enough to expire inside a test, long enough to observe.
     *
     * Long enough matters: the drill asserts that a successor is refused *while* the dead holder's
     * lease is alive, so the lease has to outlive the kill, the reaping check and one refused claim on
     * a loaded machine. Everything after that waits on the wall clock rather than on a moved timestamp.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int LEASE_SECONDS = 8;

    public function testAWorkerKilledMidJobLosesItToTheFenceAndTheEffectLandsExactlyOnce(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $queueName = 'killed-worker-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $directory = sys_get_temp_dir() . '/kumwe-killed-worker-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0o700, true));

        try {
            $queue = $this->queue($container);
            $clock = $container->get(ClockInterface::class);
            self::assertInstanceOf(ClockInterface::class, $clock);
            $jobId = $queue->enqueue(
                TestKernelFactory::administratorContext($container),
                'system.sessions.purge',
                ['drill' => 'killed-worker'],
                $clock->now(),
                $queueName,
            );

            $victim = $this->spawnWorker($queueName, $directory);
            self::assertTrue(
                $this->await($directory . '/handler-entered', 60.0),
                'The worker must actually reach its handler before the drill kills it.',
            );
            $before = $this->jobRow($container, $jobId);
            self::assertSame('reserved', $before['status']);
            self::assertSame('killable-worker', $before['lease_owner']);
            self::assertSame(1, (int) $before['attempts']);

            $killedAt = microtime(true);
            self::assertTrue(posix_kill($victim['pid'], SIGKILL));
            self::assertTrue($this->reaped($victim['process'], 15.0), 'The victim process must actually be gone.');

            // The fence, not the process table, is what protects the job: a successor is refused for as
            // long as the dead worker's lease is alive, even though nothing is executing it any more.
            $successor = $this->workerWith($container, $effect = new EffectRecordingHandler($directory));
            self::assertFalse(
                $successor->runOnce($this->workerContext($container), $queueName, 'worker-successor', 30, 30),
                'A dead holder still owns its lease until the lease itself expires.',
            );
            self::assertSame(0, $effect->runs);

            $this->sleepUntil($killedAt + self::LEASE_SECONDS + 1.5);
            self::assertTrue(
                $successor->runOnce($this->workerContext($container), $queueName, 'worker-successor', 30, 30),
                'The expired lease must let a replacement worker take the job over.',
            );

            $after = $this->jobRow($container, $jobId);
            self::assertSame('completed', $after['status']);
            self::assertSame(2, (int) $after['attempts'], 'The successor runs the second attempt, not a third.');
            self::assertNull($after['lease_owner']);
            self::assertNotSame($before['lease_token'], $after['lease_token'] ?? null);
            self::assertSame(1, $effect->runs, 'The job must complete exactly once across both workers.');
            self::assertSame(
                1,
                count(file($directory . '/effect', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []),
                'A killed attempt must leave no second durable effect behind.',
            );
        } finally {
            $this->cleanUp($directory);
        }
    }

    /**
     * Start the killable worker as a separate process and wait until it is about to claim.
     *
     * @return  array{process: resource, pid: int}
     */
    private function spawnWorker(string $queueName, string $directory): array
    {
        $binary = PHP_BINARY;
        $script = dirname(__DIR__, 2) . '/Support/killable-worker.php';
        $descriptors = [
            1 => ['file', $directory . '/worker-stdout', 'w'],
            2 => ['file', $directory . '/worker-stderr', 'w'],
        ];
        $pipes = [];
        $process = proc_open(
            [$binary, $script, $queueName, $directory, (string) self::LEASE_SECONDS],
            $descriptors,
            $pipes,
            dirname(__DIR__, 3),
        );
        self::assertIsResource($process);
        $status = proc_get_status($process);
        self::assertIsInt($status['pid']);

        return ['process' => $process, 'pid' => $status['pid']];
    }

    /**
     * Confirm the killed process really left the process table rather than merely being signalled.
     *
     * @param  resource  $process  Handle of the spawned worker.
     */
    private function reaped($process, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($process);
            if ($status['running'] === false) {
                return $status['signaled'] === true || $status['termsig'] === SIGKILL || $status['exitcode'] !== 0;
            }
            usleep(50_000);
        }

        return false;
    }

    private function await(string $path, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            clearstatcache(true, $path);
            if (is_file($path)) {
                return true;
            }
            usleep(25_000);
        }

        return false;
    }

    private function sleepUntil(float $instant): void
    {
        $remaining = $instant - microtime(true);
        if ($remaining > 0) {
            usleep((int) ($remaining * 1_000_000));
        }
    }

    /** @return array<string, mixed> */
    private function jobRow(Container $container, string $jobId): array
    {
        $connection = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        if (!$connection instanceof Connection || !$tables instanceof TableNames) {
            throw new RuntimeException('The integration persistence services are unavailable.');
        }
        $row = $connection->fetchAssociative(sprintf(
            'SELECT status, attempts, lease_owner, lease_token FROM %s WHERE id = ?',
            $tables->quoted('jobs'),
        ), [$jobId]);
        self::assertIsArray($row);

        return $row;
    }

    private function queue(Container $container): JobQueue
    {
        $queue = $container->get(JobQueue::class);
        if (!$queue instanceof JobQueue) {
            throw new RuntimeException('The integration job queue is unavailable.');
        }

        return $queue;
    }

    private function workerContext(Container $container): ExecutionContext
    {
        return TestKernelFactory::workerContext($container);
    }

    /**
     * Rebuild the wired worker around one drill handler, leaving every other collaborator production-wired.
     */
    private function workerWith(Container $container, JobHandler $handler): Worker
    {
        $wired = $container->get(Worker::class);
        if (!$wired instanceof Worker) {
            throw new RuntimeException('The integration worker is unavailable.');
        }
        $collaborator = static fn (string $property): mixed
            => (new ReflectionProperty(Worker::class, $property))->getValue($wired);

        return new Worker(
            $collaborator('queue'),
            new JobHandlerRegistry([$handler]),
            $collaborator('authorization'),
            $collaborator('ownership'),
            $collaborator('system'),
            $collaborator('jobScope'),
            $collaborator('globalPrincipals'),
        );
    }

    private function cleanUp(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}

/**
 * A handler whose only job is to leave a durable trace, so "exactly once" can be counted rather than assumed.
 *
 * @since  2.0.0
 */
final class EffectRecordingHandler implements JobHandler
{
    /**
     * Number of times this handler has been entered in this process.
     *
     * @var    int
     * @since  2.0.0
     */
    public int $runs = 0;

    /**
     * Bind the handler to the directory its trace is appended in.
     *
     * @param  string  $directory  Handshake directory shared with the killed worker.
     *
     * @since  2.0.0
     */
    public function __construct(private readonly string $directory)
    {
    }

    /**
     * Claim the same job type the killed worker was executing.
     *
     * @return  string  Job type this handler answers for.
     *
     * @since   2.0.0
     */
    public function type(): string
    {
        return 'system.sessions.purge';
    }

    /**
     * Record one effect, both in memory and on disk.
     *
     * @param   array<string, mixed>  $payload  Stored job payload.
     * @param   ExecutionContext      $context  Context the job runs under.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function handle(array $payload, ExecutionContext $context): void
    {
        $this->runs++;
        file_put_contents($this->directory . '/effect', getmypid() . "\n", FILE_APPEND);
    }
}
