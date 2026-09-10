<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessIntegration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Application\Automation\JitterSource;
use Kumwe\App\Application\Automation\JobHandler;
use Kumwe\App\Application\Automation\JobHandlerRegistry;
use Kumwe\App\Application\Automation\JobQueue;
use Kumwe\App\Application\Automation\RetryPolicy;
use Kumwe\App\Application\Automation\Worker;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\BusinessIntegration\Application\EventContractRegistry;
use Kumwe\App\BusinessIntegration\Application\InboxDisposition;
use Kumwe\App\BusinessIntegration\Application\IntegrationEventConsumerDispatcher;
use Kumwe\App\BusinessIntegration\Application\TrustedRuntimeGenerationGuard;
use Kumwe\Extension\Spi\Application\ExecutionContext as ExtensionExecutionContext;
use Kumwe\Extension\Spi\BusinessIntegration\Application\IntegrationEventHandler;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\ConsumerIdempotency;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\App\BusinessIntegration\Domain\EventSchemaDefinition;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\EventSensitivity;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\IntegrationEvent;
use Kumwe\App\BusinessIntegration\Domain\RecordedIntegrationEvent;
use Kumwe\App\BusinessIntegration\Infrastructure\DoctrineInboxStore;
use Kumwe\App\Infrastructure\Automation\DoctrineJobQueue;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * Runs work that cannot succeed until the platform gives up on it, and checks where it ends up.
 *
 * Poison quarantine and dead-lettering are the two places the platform stops retrying. Both existed and
 * both were covered at the store level, where a test sets a status and reads it back. What was missing
 * is the part an operator actually depends on: that a consumer or a job which fails *every* time walks
 * its own attempt budget down through the real dispatcher, stops being delivered once the budget is
 * spent, leaves a record naming what failed, and — for a quarantined consumer — becomes eligible again
 * exactly once when a new handler revision supersedes the one that could not cope.
 *
 * Nothing here is nudged along: the failures are real exceptions from real handlers, the budgets are
 * spent by running the work, and the terminal state is read from the database afterwards.
 *
 * @since  2.0.0
 */
#[CoversClass(IntegrationEventConsumerDispatcher::class)]
#[CoversClass(DoctrineInboxStore::class)]
#[CoversClass(Worker::class)]
#[CoversClass(DoctrineJobQueue::class)]
final class PoisonAndDeadLetterIntegrationTest extends TestCase
{
    public function testAConsumerThatNeverSucceedsIsQuarantinedAndFreedOnlyByAHandlerUpgrade(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $connection = $this->connection($container);
        $clock = $container->get(ClockInterface::class);
        $transactions = $container->get(TransactionManager::class);
        $guard = $container->get(TrustedRuntimeGenerationGuard::class);
        if (
            !$clock instanceof ClockInterface
            || !$transactions instanceof TransactionManager
            || !$guard instanceof TrustedRuntimeGenerationGuard
        ) {
            throw new RuntimeException('The integration consumer collaborators are unavailable.');
        }
        $suffix = bin2hex(random_bytes(4));
        $eventType = 'poison.drill' . $suffix . '.raised';
        $consumerId = 'poison.drill' . $suffix . '.consumer';
        $generation = $this->generation($container);
        $event = $this->event($eventType);

        $failingDefinition = $this->consumer($consumerId, $eventType, '1.0.0');
        $failing = new BudgetSpendingConsumer(true);
        $dispatcher = $this->dispatcher(
            $container,
            $eventType,
            $failingDefinition,
            $clock,
            $transactions,
            $guard,
        );
        $context = TestKernelFactory::workerContext($container);

        self::assertSame(
            'failed',
            $this->attemptDelivery($dispatcher, $failingDefinition, $event, $failing, $context, $generation),
            'The first attempt must run the handler and be recorded as a failure.',
        );
        self::assertSame(
            'failed',
            $this->attemptDelivery($dispatcher, $failingDefinition, $event, $failing, $context, $generation),
            'The last attempt of the budget must also run.',
        );
        self::assertSame(2, $failing->runs);

        $quarantined = $dispatcher->consume(
            $failingDefinition,
            $event,
            $failing,
            $context,
            'poison-worker',
            $generation,
        );
        self::assertSame(InboxDisposition::POISON, $quarantined, 'A spent budget must quarantine the receipt.');
        self::assertSame(2, $failing->runs, 'A quarantined receipt must not reach the handler again.');

        $row = $connection->fetchAssociative(sprintf(
            'SELECT status, attempts, exception_type, error_message FROM %s WHERE consumer_id = ? AND event_id = ?',
            $this->tables($container)->quoted('integration_inbox'),
        ), [$consumerId, $event->eventId()]);
        self::assertIsArray($row);
        self::assertSame('poison', $row['status']);
        self::assertSame(2, (int) $row['attempts']);
        self::assertIsString($row['error_message']);
        self::assertStringContainsString('cannot be handled', $row['error_message']);

        // A signed handler revision is the audited way out of quarantine, and it is worth exactly one
        // delivery: the receipt is settled by the upgraded handler and duplicates from then on.
        $upgradedDefinition = $this->consumer($consumerId, $eventType, '2.0.0');
        $upgraded = new BudgetSpendingConsumer(false);
        $recovering = $this->dispatcher(
            $container,
            $eventType,
            $upgradedDefinition,
            $clock,
            $transactions,
            $guard,
        );
        self::assertSame(
            InboxDisposition::CLAIMED,
            $recovering->consume(
                $upgradedDefinition,
                $event,
                $upgraded,
                $context,
                'poison-worker',
                $generation,
            ),
        );
        self::assertSame(1, $upgraded->runs);
        self::assertSame(
            InboxDisposition::DUPLICATE,
            $recovering->consume(
                $upgradedDefinition,
                $event,
                $upgraded,
                $context,
                'poison-worker',
                $generation,
            ),
            'A settled receipt must stay settled however often the event is redelivered.',
        );
        self::assertSame(1, $upgraded->runs, 'A handler upgrade buys one delivery, not an open door.');
    }

    public function testAJobThatNeverSucceedsIsDeadLetteredAndNeverClaimedAgain(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $queueName = 'dead-letter-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $queue = $container->get(JobQueue::class);
        $clock = $container->get(ClockInterface::class);
        if (!$queue instanceof JobQueue || !$clock instanceof ClockInterface) {
            throw new RuntimeException('The integration queue collaborators are unavailable.');
        }
        $jobId = $queue->enqueue(
            TestKernelFactory::administratorContext($container),
            'system.sessions.purge',
            ['drill' => 'dead-letter'],
            $clock->now(),
            $queueName,
            0,
            2,
        );
        $handler = new AlwaysFailingJobHandler();
        $worker = $this->workerWith($container, $handler);
        $context = TestKernelFactory::workerContext($container);

        self::assertTrue($worker->runOnce($context, $queueName, 'dead-letter-worker', 30, 30));
        self::assertSame('pending', $this->jobStatus($container, $jobId), 'A job with budget left must return.');

        // The queue's own backoff is two seconds after the first attempt; the drill waits it out rather
        // than reaching into the row, so the retry is scheduled by the platform and not by the test.
        usleep(2_600_000);
        self::assertTrue($worker->runOnce($context, $queueName, 'dead-letter-worker', 30, 30));
        self::assertSame(2, $handler->runs, 'Both attempts of the budget must actually run.');
        self::assertSame('dead', $this->jobStatus($container, $jobId));

        $buried = $this->connection($container)->fetchAssociative(sprintf(
            'SELECT attempts, failure_classification, exception_type, error_message FROM %s WHERE job_id = ?',
            $this->tables($container)->quoted('failed_jobs'),
        ), [$jobId]);
        self::assertIsArray($buried, 'A dead job must leave one operator-visible record.');
        self::assertSame(2, (int) $buried['attempts']);
        self::assertSame('transient', $buried['failure_classification']);
        self::assertIsString($buried['error_message']);
        self::assertStringContainsString('never succeeds', $buried['error_message']);

        self::assertFalse(
            $worker->runOnce($context, $queueName, 'dead-letter-worker', 30, 30),
            'A dead-lettered job must never be handed out again.',
        );
        self::assertSame(2, $handler->runs);
    }

    /**
     * Run one delivery that is expected to fail, and report which way it went.
     *
     * @param  EventConsumerDefinition  $definition  Exact signed declaration paired with the handler.
     */
    private function attemptDelivery(
        IntegrationEventConsumerDispatcher $dispatcher,
        EventConsumerDefinition $definition,
        IntegrationEvent $event,
        IntegrationEventHandler $handler,
        ExecutionContext $context,
        string $generation,
    ): string {
        try {
            $dispatcher->consume($definition, $event, $handler, $context, 'poison-worker', $generation);
        } catch (Throwable) {
            return 'failed';
        }

        return 'succeeded';
    }

    private function dispatcher(
        Container $container,
        string $eventType,
        EventConsumerDefinition $definition,
        ClockInterface $clock,
        TransactionManager $transactions,
        TrustedRuntimeGenerationGuard $guard,
    ): IntegrationEventConsumerDispatcher {
        $contracts = new EventContractRegistry(
            [$this->schema($eventType)],
            [$definition],
        );
        $inbox = new DoctrineInboxStore(
            $this->connection($container),
            $this->tables($container),
            $transactions,
            $clock,
            $contracts,
        );

        return new IntegrationEventConsumerDispatcher(
            $inbox,
            $contracts,
            new RetryPolicy($clock, new ImmediateRetryJitter()),
            $guard,
            $transactions,
            new NullLogger(),
        );
    }

    private function consumer(string $consumerId, string $eventType, string $handlerVersion): EventConsumerDefinition
    {
        return new EventConsumerDefinition(
            $consumerId,
            $eventType,
            [1],
            $handlerVersion,
            'integration.default',
            true,
            ConsumerIdempotency::EVENT_ID,
            2,
        );
    }

    private function schema(string $eventType): EventSchemaDefinition
    {
        return new EventSchemaDefinition(
            $eventType,
            1,
            EventSensitivity::INTERNAL,
            [
                'type' => 'object',
                'required' => ['subject'],
                'properties' => ['subject' => ['type' => 'string']],
                'additionalProperties' => false,
            ],
        );
    }

    private function event(string $eventType): IntegrationEvent
    {
        return new RecordedIntegrationEvent(
            $eventType,
            1,
            Uuid::uuid7()->toString(),
            new DateTimeImmutable('2026-08-14T09:00:00+00:00'),
            null,
            'poison-drill',
            'default',
            null,
            'poison.drill',
            'subject-one',
            1,
            'correlation-poison-drill',
            'request-poison-drill',
            EventSensitivity::INTERNAL,
            ['subject' => 'subject-one'],
        );
    }

    private function generation(Container $container): string
    {
        $state = $container->get(\Kumwe\App\Extension\Runtime\RuntimeMaterializationState::class);
        if (!$state instanceof \Kumwe\App\Extension\Runtime\RuntimeMaterializationState) {
            throw new RuntimeException('The integration runtime generation is unavailable.');
        }

        return (string) $state->generation;
    }

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

    private function jobStatus(Container $container, string $jobId): string
    {
        $status = $this->connection($container)->fetchOne(sprintf(
            'SELECT status FROM %s WHERE id = ?',
            $this->tables($container)->quoted('jobs'),
        ), [$jobId]);
        self::assertIsString($status);

        return $status;
    }

    private function tables(Container $container): TableNames
    {
        $tables = $container->get(TableNames::class);
        if (!$tables instanceof TableNames) {
            throw new RuntimeException('The integration table map is unavailable.');
        }

        return $tables;
    }

    private function connection(Container $container): Connection
    {
        $connection = $container->get(Connection::class);
        if (!$connection instanceof Connection) {
            throw new RuntimeException('The integration connection is unavailable.');
        }

        return $connection;
    }
}

/**
 * A consumer that spends its attempt budget, and can be re-declared as a later handler revision.
 *
 * @since  2.0.0
 */
final class BudgetSpendingConsumer implements IntegrationEventHandler
{
    /**
     * Number of times this handler has actually been entered.
     *
     * @var    int
     * @since  2.0.0
     */
    public int $runs = 0;

    /**
     * Decide whether this executable revision can cope with its canonical declaration.
     *
     * @param  bool  $fails  Whether every delivery raises.
     *
     * @since  2.0.0
     */
    public function __construct(private readonly bool $fails)
    {
    }

    /**
     * Consume one event, failing for as long as this revision is the one that cannot cope.
     *
     * @param   EventConsumerDefinition  $definition  Exact signed consumer declaration.
     * @param   IntegrationEvent         $event       Event being delivered.
     * @param   ExtensionExecutionContext  $context   Context the consumer runs under.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function handle(
        EventConsumerDefinition $definition,
        IntegrationEvent $event,
        ExtensionExecutionContext $context,
    ): void {
        $this->runs++;
        if ($this->fails) {
            throw new RuntimeException('This event cannot be handled by this consumer revision.');
        }
    }
}

/**
 * A job handler with no path to success, used to walk a queue attempt budget down to burial.
 *
 * @since  2.0.0
 */
final class AlwaysFailingJobHandler implements JobHandler
{
    /**
     * Number of attempts this handler has run.
     *
     * @var    int
     * @since  2.0.0
     */
    public int $runs = 0;

    /**
     * Claim the job type the drill enqueues.
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
     * Fail every attempt, transiently, so the budget rather than the classification decides the burial.
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
        throw new RuntimeException('This drill job never succeeds.');
    }
}

/**
 * A jitter source that always returns the shortest legal delay, so a drill spends time on work only.
 *
 * @since  2.0.0
 */
final class ImmediateRetryJitter implements JitterSource
{
    /**
     * Return the lower bound of the requested window.
     *
     * @param   int  $minimum  Lowest value the policy will accept.
     * @param   int  $maximum  Highest value the policy will accept.
     *
     * @return  int  Always the minimum.
     *
     * @since   2.0.0
     */
    public function between(int $minimum, int $maximum): int
    {
        return $minimum;
    }
}
