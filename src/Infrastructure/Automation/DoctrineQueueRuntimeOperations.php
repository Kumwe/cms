<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Automation;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Application\Automation\QueueRuntimeOperations;
use Kumwe\App\Application\Automation\QueueRuntimePolicy;
use Kumwe\App\Application\Automation\QueueRuntimePolicyCatalog;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Doctrine operator surface for active contributed queue policy, load and terminal-row retention.
 *
 * Purge candidates are selected and locked in one bounded transaction. Terminal jobs are removed with
 * their failure and site-ownership evidence; terminal inbox receipts have payload and failure detail
 * compacted while their deduplication tombstone remains. A retry or another purge therefore cannot race
 * evidence disposal, while undeclared core queues keep their established operational policy.
 *
 * @since  2.0.0
 */
final readonly class DoctrineQueueRuntimeOperations implements QueueRuntimeOperations
{
    /**
     * Build the authenticated queue-policy operator service.
     *
     * @param  Connection                 $database       Queue database.
     * @param  TableNames                 $tables         Prefix-aware physical table names.
     * @param  TransactionManager         $transactions   Atomic purge boundary.
     * @param  ClockInterface             $clock          Retention cutoff and live-lease clock.
     * @param  AuthorizationGateway       $authorization  Automation management policy gateway.
     * @param  QueueRuntimePolicyCatalog  $policies       Active trusted queue declarations.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private TransactionManager $transactions,
        private ClockInterface $clock,
        private AuthorizationGateway $authorization,
        private QueueRuntimePolicyCatalog $policies,
    ) {
    }

    /**
     * Return active policy plus durable load and retention counters.
     *
     * @param   ExecutionContext  $context  Authenticated automation operator.
     *
     * @return  list<array<string, mixed>>  Queue policy inventory in queue order.
     *
     * @since   2.0.0
     */
    public function inventory(ExecutionContext $context): array
    {
        $this->authorize($context, AuthorizationResource::collection('queue'));
        $result = [];
        $now = $this->clock->now();
        foreach ($this->policies->policies() as $policy) {
            $cutoff = $this->cutoff($policy, $now);
            $runtime = $this->database->fetchAssociative(sprintf(
                'SELECT last_claimed_at, updated_at FROM %s WHERE queue_id = ?',
                $this->tables->quoted('job_queue_runtime'),
            ), [$policy->queue]);
            $jobPending = $this->countJobs($policy->queue, "status = 'pending'");
            $deliveryPending = $this->countInbox($policy->queue, "status = 'pending'");
            $jobInFlight = $this->countJobs(
                $policy->queue,
                "status = 'reserved' AND lease_expires_at > ?",
                [$now],
                [Types::DATETIME_IMMUTABLE],
            );
            $deliveryInFlight = $this->countInbox(
                $policy->queue,
                "status = 'reserved' AND lease_expires_at > ?",
                [$now],
                [Types::DATETIME_IMMUTABLE],
            );
            $terminalJobs = $this->countJobs(
                $policy->queue,
                "status IN ('completed', 'dead', 'canceled')",
            );
            $terminalDeliveries = $this->countInbox(
                $policy->queue,
                "status IN ('completed', 'poison', 'unavailable')",
            );
            $purgeableJobs = $this->countJobs(
                $policy->queue,
                "((status = 'completed' AND completed_at < ?) "
                . "OR (status IN ('dead', 'canceled') AND updated_at < ?))",
                [$cutoff, $cutoff],
                [Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE],
            );
            $compactableDeliveries = $this->countInbox(
                $policy->queue,
                "status IN ('completed', 'poison', 'unavailable') "
                . 'AND evidence_compacted_at IS NULL AND updated_at < ?',
                [$cutoff],
                [Types::DATETIME_IMMUTABLE],
            );
            $result[] = $policy->toArray() + [
                'job_pending' => $jobPending,
                'delivery_pending' => $deliveryPending,
                'pending' => $jobPending + $deliveryPending,
                'job_in_flight' => $jobInFlight,
                'delivery_in_flight' => $deliveryInFlight,
                'in_flight' => $jobInFlight + $deliveryInFlight,
                'terminal_jobs' => $terminalJobs,
                'terminal_delivery_receipts' => $terminalDeliveries,
                'terminal' => $terminalJobs + $terminalDeliveries,
                'purge_eligible_jobs' => $purgeableJobs,
                'compact_eligible_delivery_receipts' => $compactableDeliveries,
                'purge_eligible' => $purgeableJobs + $compactableDeliveries,
                'compacted_delivery_receipts' => $this->countInbox(
                    $policy->queue,
                    'evidence_compacted_at IS NOT NULL',
                ),
                'last_claimed_at' => $runtime === false ? null : ($runtime['last_claimed_at'] ?? null),
                'policy_updated_at' => $runtime === false ? null : ($runtime['updated_at'] ?? null),
            ];
        }

        return $result;
    }

    /**
     * Purge a bounded locked batch whose signed retention period has elapsed.
     *
     * @param   ExecutionContext  $context  Authenticated automation operator.
     * @param   string            $queue    Active contributed queue.
     * @param   int               $limit    Maximum terminal records deleted or compacted, 1 to 1000.
     *
     * @return  int  Terminal jobs deleted plus inbox receipts whose retained detail was compacted.
     *
     * @throws  InvalidArgumentException  When the queue is undeclared or the limit is invalid.
     * @throws  RuntimeException  When a selected job does not carry a usable identifier.
     *
     * @since   2.0.0
     */
    public function purge(ExecutionContext $context, string $queue, int $limit = 100): int
    {
        $this->authorize($context, AuthorizationResource::item('queue', $queue));
        $policy = $this->policies->policy($queue);
        if (!$policy instanceof QueueRuntimePolicy) {
            throw new InvalidArgumentException('Retention purge requires an active contributed queue policy.');
        }
        if ($limit < 1 || $limit > 1_000) {
            throw new InvalidArgumentException('Queue retention purge limit must be between 1 and 1000.');
        }
        $cutoff = $this->cutoff($policy, $this->clock->now());

        return $this->transactions->transactional(function () use ($queue, $limit, $cutoff): int {
            $rows = $this->database->fetchAllAssociative(sprintf(
                'SELECT id FROM %s WHERE queue = ? AND ((status = \'completed\' AND completed_at < ?) '
                . "OR (status IN ('dead', 'canceled') AND updated_at < ?)) "
                . 'ORDER BY updated_at, id LIMIT %d FOR UPDATE SKIP LOCKED',
                $this->tables->quoted('jobs'),
                $limit,
            ), [$queue, $cutoff, $cutoff], [
                Types::STRING,
                Types::DATETIME_IMMUTABLE,
                Types::DATETIME_IMMUTABLE,
            ]);
            foreach ($rows as $row) {
                $id = $row['id'] ?? null;
                if (!is_string($id) || $id === '') {
                    throw new RuntimeException('A queue retention candidate has an invalid identifier.');
                }
                $this->database->delete(
                    $this->tables->raw('failed_jobs'),
                    ['job_id' => $id],
                    ['job_id' => Types::GUID],
                );
                $this->database->delete($this->tables->raw('resource_site_ownership'), [
                    'resource_type' => 'job',
                    'resource_id' => $id,
                ]);
                $this->database->delete(
                    $this->tables->raw('jobs'),
                    ['id' => $id],
                    ['id' => Types::GUID],
                );
            }

            $remaining = $limit - count($rows);
            if ($remaining < 1) {
                return count($rows);
            }
            $receipts = $this->database->fetchAllAssociative(sprintf(
                "SELECT consumer_id, event_id FROM %s WHERE queue = ? "
                . "AND status IN ('completed', 'poison', 'unavailable') "
                . 'AND evidence_compacted_at IS NULL AND updated_at < ? '
                . 'ORDER BY updated_at, consumer_id, event_id LIMIT %d FOR UPDATE SKIP LOCKED',
                $this->tables->quoted('integration_inbox'),
                $remaining,
            ), [$queue, $cutoff], [Types::STRING, Types::DATETIME_IMMUTABLE]);
            $compactedAt = $this->clock->now();
            foreach ($receipts as $receipt) {
                $consumerId = $receipt['consumer_id'] ?? null;
                $eventId = $receipt['event_id'] ?? null;
                if (!is_string($consumerId) || $consumerId === '' || !is_string($eventId) || $eventId === '') {
                    throw new RuntimeException('A queue retention receipt candidate has invalid identity.');
                }
                $this->database->update($this->tables->raw('integration_inbox'), [
                    'envelope' => new \stdClass(),
                    'lease_owner' => null,
                    'lease_token' => null,
                    'lease_acquired_at' => null,
                    'lease_expires_at' => null,
                    'runtime_generation' => null,
                    'failure_classification' => null,
                    'exception_type' => null,
                    'error_message' => null,
                    'evidence_compacted_at' => $compactedAt,
                ], [
                    'consumer_id' => $consumerId,
                    'event_id' => $eventId,
                ], [
                    'envelope' => Types::JSON,
                    'event_id' => Types::GUID,
                    'evidence_compacted_at' => Types::DATETIME_IMMUTABLE,
                ]);
            }

            return count($rows) + count($receipts);
        });
    }

    /**
     * Count queue rows matching a fixed internal predicate.
     *
     * @param   string        $queue       Queue identifier.
     * @param   string        $predicate   Internal SQL predicate containing placeholders only.
     * @param   list<mixed>   $parameters  Predicate parameters following the queue parameter.
     * @param   list<string>  $types       DBAL types for predicate parameters.
     *
     * @return  int  Matching durable rows.
     *
     * @since   2.0.0
     */
    private function countJobs(
        string $queue,
        string $predicate,
        array $parameters = [],
        array $types = [],
    ): int {
        return $this->databaseCount($this->database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE queue = ? AND %s',
            $this->tables->quoted('jobs'),
            $predicate,
        ), [$queue, ...$parameters], [Types::STRING, ...$types]));
    }

    /**
     * Count delivery receipts matching a fixed internal predicate.
     *
     * @param   string        $queue       Queue identifier.
     * @param   string        $predicate   Internal SQL predicate containing placeholders only.
     * @param   list<mixed>   $parameters  Predicate parameters following the queue parameter.
     * @param   list<string>  $types       DBAL types for predicate parameters.
     *
     * @return  int  Matching durable receipts.
     *
     * @since   2.0.0
     */
    private function countInbox(
        string $queue,
        string $predicate,
        array $parameters = [],
        array $types = [],
    ): int {
        return $this->databaseCount($this->database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE queue = ? AND %s',
            $this->tables->quoted('integration_inbox'),
            $predicate,
        ), [$queue, ...$parameters], [Types::STRING, ...$types]));
    }

    /**
     * Normalize a DBAL aggregate count without accepting another scalar representation.
     *
     * @param   mixed  $value  Raw aggregate value returned by the active database driver.
     *
     * @return  int  Non-negative row count.
     *
     * @throws  RuntimeException  When the driver did not return an integer or decimal integer string.
     *
     * @since   2.0.0
     */
    private function databaseCount(mixed $value): int
    {
        if (is_int($value)) {
            if ($value >= 0) {
                return $value;
            }
        } elseif (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            return (int) $value;
        }

        throw new RuntimeException('A queue runtime count is invalid.');
    }

    /**
     * Calculate the exclusive retention cutoff for one policy.
     *
     * @param   QueueRuntimePolicy  $policy  Active trusted policy.
     * @param   DateTimeImmutable   $now     Authoritative current instant.
     *
     * @return  DateTimeImmutable  Rows strictly older than this instant are purgeable.
     *
     * @since   2.0.0
     */
    private function cutoff(QueueRuntimePolicy $policy, DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->sub(new DateInterval(sprintf('P%dD', $policy->retentionDays)));
    }

    /**
     * Require queue management authority before reading or deleting operational evidence.
     *
     * @param   ExecutionContext       $context   Authenticated operator.
     * @param   AuthorizationResource  $resource  Queue collection or declared queue.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function authorize(ExecutionContext $context, AuthorizationResource $resource): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('automation.manage'),
            $resource,
        );
    }
}
