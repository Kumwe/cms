<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Console\Command;

use Kumwe\App\Tests\Support\TranslatesConsoleOutput;
use DateTimeImmutable;
use Kumwe\App\Application\Automation\JobHandler;
use Kumwe\App\Application\Automation\JobHandlerRegistry;
use Kumwe\App\Application\Automation\GlobalJobPrincipals;
use Kumwe\App\Application\Automation\JobExecutionScope;
use Kumwe\App\Application\Automation\JobQueue;
use Kumwe\App\Application\Automation\QueueRuntimePolicy;
use Kumwe\App\Application\Automation\QueueRuntimePolicyCatalog;
use Kumwe\App\Application\Automation\StoredJob;
use Kumwe\App\Application\Automation\Worker;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Delivery\Console\Command\QueueWorkCommand;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(QueueWorkCommand::class)]
final class QueueWorkCommandTest extends TestCase
{
    public function testMaximumJobsDrainsWithoutClaimingAdditionalWorkAndDisconnects(): void
    {
        $queue = new DrainQueue(3);
        $command = $this->command($queue, [new DrainHandler()]);
        $output = new DrainOutput();

        self::assertSame(0, $command->execute([
            '--max-jobs=2',
            '--lease-seconds=30',
            '--sleep-ms=50',
        ], $output));
        self::assertSame(2, $queue->completed);
        self::assertSame(1, $queue->remaining());
        self::assertSame(1, $queue->disconnects);
        self::assertStringContainsString('drained after 2 job(s)', $output->lines[1]);
    }

    public function testInvalidLeaseWindowReturnsFailureWithoutConnecting(): void
    {
        $queue = new DrainQueue(0);
        $command = $this->command($queue, []);
        $output = new DrainOutput();

        self::assertSame(1, $command->execute(['--lease-seconds=4'], $output));
        self::assertSame(0, $queue->disconnects);
        self::assertStringContainsString('between 5 and 3600', $output->errors[0]);
    }

    public function testContributedQueueDefaultsToSignedLeaseAndRejectsAContradictingOverride(): void
    {
        $policy = new CommandQueuePolicyCatalog(new QueueRuntimePolicy(
            'acme.example.priority',
            45,
            3,
            2,
            14,
            17,
        ));
        $queue = new DrainQueue(0);
        $command = $this->command($queue, [], $policy);
        $output = new DrainOutput();

        self::assertSame(0, $command->execute(['--queue=acme.example.priority', '--once'], $output));
        self::assertSame([45], $queue->claimLeases);
        self::assertStringContainsString('in-flight 2', $output->lines[1]);

        $refused = new DrainQueue(0);
        self::assertSame(1, $this->command($refused, [], $policy)->execute([
            '--queue=acme.example.priority',
            '--lease-seconds=46',
            '--once',
        ], new DrainOutput()));
        self::assertSame([], $refused->claimLeases);
    }

    /** @param list<JobHandler> $handlers */
    private function command(
        JobQueue $queue,
        array $handlers,
        ?QueueRuntimePolicyCatalog $policies = null,
    ): QueueWorkCommand {
        $ownership = AuthorizationContext::ownership();

        return new QueueWorkCommand(
            new Worker(
                $queue,
                new JobHandlerRegistry($handlers),
                AuthorizationContext::gateway(ownership: $ownership),
                $ownership,
                AuthorizationContext::system(SystemIdentity::Worker),
                new JobExecutionScope(),
                new GlobalJobPrincipals(
                    AuthorizationContext::system(SystemIdentity::InstallationMaintenance),
                    AuthorizationContext::system(SystemIdentity::ExtensionMaterializer),
                ),
            ),
            AuthorizationContext::system(SystemIdentity::Worker),
            policies: $policies,
        );
    }
}

final class DrainQueue implements JobQueue
{
    /** @var list<StoredJob> */
    private array $jobs = [];
    public int $completed = 0;
    public int $disconnects = 0;
    /** @var list<int> */
    public array $claimLeases = [];

    public function __construct(int $jobs)
    {
        for ($index = 1; $index <= $jobs; $index++) {
            $this->jobs[] = new StoredJob(
                sprintf('00000000-0000-7000-8000-%012d', $index),
                'default',
                'drain.test',
                [],
                1,
                1,
                5,
                sprintf('00000000-0000-7000-8001-%012d', $index),
            );
        }
    }

    public function remaining(): int
    {
        return count($this->jobs);
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
        return '';
    }

    public function claim(
        ExecutionContext $context,
        string $queue,
        string $workerId,
        int $leaseSeconds,
    ): ?StoredJob {
        $this->claimLeases[] = $leaseSeconds;
        return array_shift($this->jobs);
    }

    public function renew(
        ExecutionContext $context,
        StoredJob $job,
        string $workerId,
        int $leaseSeconds,
    ): void {
    }

    public function complete(ExecutionContext $context, StoredJob $job, string $workerId): void
    {
        $this->completed++;
    }

    public function fail(
        ExecutionContext $context,
        StoredJob $job,
        string $workerId,
        Throwable $failure,
        bool $permanent,
    ): void {
    }

    public function heartbeat(
        ExecutionContext $context,
        string $workerId,
        string $queue,
        ?string $jobId = null,
    ): void {
    }

    public function disconnect(ExecutionContext $context, string $workerId, string $queue): void
    {
        $this->disconnects++;
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

final readonly class CommandQueuePolicyCatalog implements QueueRuntimePolicyCatalog
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

final class DrainHandler implements JobHandler
{
    public function type(): string
    {
        return 'drain.test';
    }

    public function handle(array $payload, ExecutionContext $context): void
    {
    }
}

final class DrainOutput implements Output
{
    use TranslatesConsoleOutput;

    /** @var list<string> */
    public array $lines = [];
    /** @var list<string> */
    public array $errors = [];

    public function line(string $message): void
    {
        $this->lines[] = $message;
    }

    public function error(string $message): void
    {
        $this->errors[] = $message;
    }
}
