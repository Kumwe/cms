<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Infrastructure;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\App\Application\Automation\FailureClassification;
use Kumwe\App\Application\Automation\QueueRuntimePolicy;
use Kumwe\App\Application\Automation\QueueRuntimePolicyCatalog;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\BusinessIntegration\Application\EventContractRegistry;
use Kumwe\App\BusinessIntegration\Application\InboxClaimResult;
use Kumwe\App\BusinessIntegration\Application\InboxDisposition;
use Kumwe\App\BusinessIntegration\Application\InboxLease;
use Kumwe\App\BusinessIntegration\Application\InboxStore;
use Kumwe\App\BusinessIntegration\Domain\RecordedEventEnvelope;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\IntegrationEvent;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

/**
 * DBAL consumer inbox with durable deduplication, aggregate checkpoints and fenced delivery leases.
 *
 * Completed receipts remain final across handler upgrades. A poison receipt becomes eligible exactly once
 * a different signed handler version is active, with its attempt budget restarted; this gives an operator a
 * deterministic recovery path without allowing an unchanged failing binary to churn the same message.
 *
 * @since  2.0.0
 */
final readonly class DoctrineInboxStore implements InboxStore
{
    /**
     * Bind the inbox to its durable connection and exact event catalog.
     *
     * @param  Connection                  $database      Shared application connection.
     * @param  TableNames                  $tables        Physical table-name compiler.
     * @param  TransactionManager          $transactions  Receipt and checkpoint transaction boundary.
     * @param  ClockInterface              $clock         Lease and retry clock.
     * @param  EventContractRegistry       $contracts     Exact trusted event catalog.
     * @param  ?QueueRuntimePolicyCatalog  $policies      Active contributed queue limits; null preserves core-only
     *         inbox behavior for isolated instances.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private TransactionManager $transactions,
        private ClockInterface $clock,
        private EventContractRegistry $contracts,
        private ?QueueRuntimePolicyCatalog $policies = null,
    ) {
    }

    /**
     * Claim or deduplicate an event for the declared consumer.
     *
     * @param   EventConsumerDefinition  $consumer           Signed consumer contract governing the receipt.
     * @param   IntegrationEvent         $event              Versioned event being validated or processed.
     * @param   string                   $workerId           Stable identity of the claiming worker.
     * @param   string                   $runtimeGeneration  Trusted runtime generation that owns the lease.
     * @param   int                      $leaseSeconds       Number of seconds before the worker lease expires.
     *
     * @return  InboxClaimResult  Claim disposition and fenced lease, when processing was granted.
     *
     * @since   2.0.0
     */
    public function receive(
        EventConsumerDefinition $consumer,
        IntegrationEvent $event,
        string $workerId,
        string $runtimeGeneration,
        int $leaseSeconds,
    ): InboxClaimResult {
        $this->contracts->assertEvent($event);
        $this->assertClaimInput($workerId, $runtimeGeneration, $leaseSeconds);
        if ($consumer->eventType() !== $event->eventType()) {
            throw new InvalidArgumentException('The consumer does not declare this event type.');
        }
        $consumer = $this->effectiveConsumer($consumer);
        $policy = $this->policies?->policy($consumer->queue());
        if ($policy !== null && $leaseSeconds > $policy->leaseSeconds) {
            throw new InvalidArgumentException('A contributed queue lease cannot exceed its signed policy.');
        }
        if ($policy !== null) {
            $this->ensureQueueRuntime($policy);
        }
        try {
            return $this->receiveTransaction(
                $consumer,
                $event,
                $workerId,
                $runtimeGeneration,
                $leaseSeconds,
                $policy,
            );
        } catch (UniqueConstraintViolationException) {
            return $this->receiveTransaction(
                $consumer,
                $event,
                $workerId,
                $runtimeGeneration,
                $leaseSeconds,
                $policy,
            );
        }
    }

    /**
     * Renew the supplied durable-processing lease.
     *
     * @param   InboxLease  $lease         Fenced lease proving ownership of the durable item.
     * @param   int         $leaseSeconds  Number of seconds before the worker lease expires.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function renew(InboxLease $lease, int $leaseSeconds): void
    {
        if ($leaseSeconds < 5 || $leaseSeconds > 3_600) {
            throw new InvalidArgumentException('An inbox lease must last between 5 and 3600 seconds.');
        }
        $policy = $this->policies?->policy($lease->consumer->queue());
        if ($policy !== null && $leaseSeconds > $policy->leaseSeconds) {
            throw new InvalidArgumentException('A contributed queue lease cannot exceed its signed policy.');
        }
        $now = $this->clock->now();
        $this->assertOne($this->database->executeStatement(sprintf(
            'UPDATE %s SET lease_expires_at = ?, updated_at = ? WHERE consumer_id = ? AND event_id = ? '
            . "AND status = 'reserved' AND lease_owner = ? AND lease_token = ? "
            . 'AND runtime_generation = ? AND lease_expires_at > ?',
            $this->tables->quoted('integration_inbox'),
        ), [
            $now->add(new DateInterval(sprintf('PT%dS', $leaseSeconds))), $now,
            $lease->consumer->identifier(), $lease->event->eventId(), $lease->workerId,
            $lease->leaseToken, $lease->runtimeGeneration, $now,
        ], [
            Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::STRING, Types::GUID,
            Types::STRING, Types::GUID, Types::STRING, Types::DATETIME_IMMUTABLE,
        ]));
    }

    /**
     * Mark the supplied durable-processing lease complete.
     *
     * @param   InboxLease  $lease  Fenced lease proving ownership of the durable item.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function complete(InboxLease $lease): void
    {
        $this->transactions->transactional(function () use ($lease): void {
            $now = $this->clock->now();
            if ($lease->consumer->aggregateOrdered()) {
                $affected = $this->database->executeStatement(sprintf(
                    'UPDATE %s SET aggregate_version = ?, event_id = ?, updated_at = ? WHERE consumer_id = ? '
                    . 'AND scope_checksum = ? AND site_identifier = ? AND organization_scope = ? '
                    . 'AND aggregate_type = ? AND aggregate_id = ? AND aggregate_version = ?',
                    $this->tables->quoted('integration_consumer_checkpoints'),
                ), [
                    $lease->event->aggregateVersion(), $lease->event->eventId(), $now,
                    $lease->consumer->identifier(), $this->scopeChecksum($lease->event),
                    $lease->event->siteIdentifier(), $this->organizationScope($lease->event),
                    $lease->event->aggregateType(), $lease->event->aggregateId(),
                    $lease->event->aggregateVersion() - 1,
                ], [
                    Types::INTEGER, Types::GUID, Types::DATETIME_IMMUTABLE, Types::STRING,
                    Types::STRING, Types::STRING, Types::STRING, Types::STRING, Types::STRING, Types::INTEGER,
                ]);
                if ((string) $affected !== '1') {
                    throw new RuntimeException('The consumer aggregate checkpoint moved during delivery.');
                }
            }
            $this->assertOne($this->database->executeStatement(sprintf(
                "UPDATE %s SET status = 'completed', lease_owner = NULL, lease_token = NULL, "
                . 'lease_acquired_at = NULL, lease_expires_at = NULL, runtime_generation = NULL, '
                . 'completed_at = ?, updated_at = ? WHERE consumer_id = ? AND event_id = ? '
                . "AND status = 'reserved' AND lease_owner = ? AND lease_token = ? "
                . 'AND runtime_generation = ? AND lease_expires_at > ?',
                $this->tables->quoted('integration_inbox'),
            ), [
                $now, $now, $lease->consumer->identifier(), $lease->event->eventId(),
                $lease->workerId, $lease->leaseToken, $lease->runtimeGeneration, $now,
            ], [
                Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::STRING, Types::GUID,
                Types::STRING, Types::GUID, Types::STRING, Types::DATETIME_IMMUTABLE,
            ]));
        });
    }

    /**
     * Record a failed durable delivery and its retry decision.
     *
     * @param   InboxLease             $lease           Fenced lease proving ownership of the durable item.
     * @param   FailureClassification  $classification  Failure class controlling retry or quarantine behavior.
     * @param   Throwable              $failure         Failure whose retry classification is being recorded.
     * @param   ?DateTimeImmutable     $retryAt         Next eligible attempt timestamp, or null for quarantine.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function fail(
        InboxLease $lease,
        FailureClassification $classification,
        Throwable $failure,
        ?DateTimeImmutable $retryAt,
    ): void {
        $retry = $classification === FailureClassification::TRANSIENT
            && $retryAt !== null
            && $lease->attempts < $lease->consumer->maximumAttempts();
        $now = $this->clock->now();
        $this->assertOne($this->database->executeStatement(sprintf(
            'UPDATE %s SET status = ?, available_at = ?, lease_owner = NULL, lease_token = NULL, '
            . 'lease_acquired_at = NULL, lease_expires_at = NULL, runtime_generation = NULL, '
            . 'failure_classification = ?, exception_type = ?, error_message = ?, updated_at = ? '
            . "WHERE consumer_id = ? AND event_id = ? AND status = 'reserved' AND lease_owner = ? "
            . 'AND lease_token = ? AND runtime_generation = ? AND lease_expires_at > ?',
            $this->tables->quoted('integration_inbox'),
        ), [
            $retry ? 'pending' : 'poison', $retry && $retryAt > $now ? $retryAt : $now,
            $classification->value, $failure::class, substr($failure->getMessage(), 0, 4_000), $now,
            $lease->consumer->identifier(), $lease->event->eventId(), $lease->workerId,
            $lease->leaseToken, $lease->runtimeGeneration, $now,
        ], [
            Types::STRING, Types::DATETIME_IMMUTABLE, Types::STRING, Types::STRING, Types::STRING,
            Types::DATETIME_IMMUTABLE, Types::STRING, Types::GUID, Types::STRING, Types::GUID,
            Types::STRING, Types::DATETIME_IMMUTABLE,
        ]));
    }

    /**
     * Return the most recent operator-visible records.
     *
     * @param   string  $consumerId  Stable consumer identifier used to scope receipt history.
     * @param   int     $limit       Maximum number of records the operation may return or change.
     *
     * @return  list<array<string, mixed>>  Operator-visible rows in deterministic order.
     *
     * @since   2.0.0
     */
    public function recent(string $consumerId, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 1_000) {
            throw new InvalidArgumentException('An inbox list limit must be between 1 and 1000.');
        }
        return $this->database->fetchAllAssociative(sprintf(
            'SELECT consumer_id, event_id, queue, event_type, schema_version, handler_version, site_identifier, '
            . 'organization_id, aggregate_type, '
            . 'aggregate_id, aggregate_version, status, attempts, maximum_attempts, available_at, lease_owner, '
            . 'runtime_generation, failure_classification, exception_type, error_message, first_received_at, '
            . 'completed_at, evidence_compacted_at, updated_at FROM %s WHERE consumer_id = ? '
            . 'ORDER BY first_received_at DESC, event_id DESC LIMIT ?',
            $this->tables->quoted('integration_inbox'),
        ), [$consumerId, $limit], [Types::STRING, Types::INTEGER]);
    }

    /**
     * Resolve one consumer receipt atomically under ordering and lease constraints.
     *
     * @param   EventConsumerDefinition  $consumer      Signed consumer contract governing the receipt.
     * @param   IntegrationEvent         $event         Versioned event being validated or processed.
     * @param   string                   $worker        Stable identity of the claiming worker.
     * @param   string                   $generation    Trusted runtime generation that owns the lease.
     * @param   int                      $leaseSeconds  Number of seconds before the worker lease expires.
     * @param   ?QueueRuntimePolicy      $policy        Active contributed queue policy, when declared.
     *
     * @return  InboxClaimResult  Claim disposition and fenced lease resolved atomically.
     *
     * @since   2.0.0
     */
    private function receiveTransaction(
        EventConsumerDefinition $consumer,
        IntegrationEvent $event,
        string $worker,
        string $generation,
        int $leaseSeconds,
        ?QueueRuntimePolicy $policy,
    ): InboxClaimResult {
        return $this->transactions->transactional(function () use (
            $consumer,
            $event,
            $worker,
            $generation,
            $leaseSeconds,
            $policy,
        ): InboxClaimResult {
            $now = $this->clock->now();
            $row = $this->database->fetchAssociative(sprintf(
                'SELECT * FROM %s WHERE consumer_id = ? AND event_id = ?%s',
                $this->tables->quoted('integration_inbox'),
                $this->lockClause(false),
            ), [$consumer->identifier(), $event->eventId()], [Types::STRING, Types::GUID]);
            $handlerUpgraded = $row !== false
                && ($row['handler_version'] ?? null) !== $consumer->handlerVersion();
            if ($row !== false) {
                $terminal = $this->existingDisposition($row, $now, $handlerUpgraded);
                if ($terminal !== null) {
                    return new InboxClaimResult($terminal);
                }
            }

            if (
                !$consumer->acceptsVersion($event->schemaVersion())
                || !$event->sensitivity()->allowedBy($consumer->sensitivityCeiling())
            ) {
                $this->storeUnavailable($consumer, $event, $now, $row !== false);
                return new InboxClaimResult(InboxDisposition::UNAVAILABLE);
            }

            if ($consumer->aggregateOrdered()) {
                $checkpoint = $this->checkpoint($consumer, $event, $now);
                if ($event->aggregateVersion() <= $checkpoint) {
                    $this->storeDuplicate($consumer, $event, $now, $row !== false);
                    return new InboxClaimResult(InboxDisposition::DUPLICATE);
                }
                if ($event->aggregateVersion() !== $checkpoint + 1) {
                    $this->storePending($consumer, $event, $now, $row !== false);
                    return new InboxClaimResult(InboxDisposition::REORDERED);
                }
            }

            if ($policy !== null && !$this->claimPolicySlot($policy, $now)) {
                return new InboxClaimResult(InboxDisposition::BUSY);
            }

            $attempts = $row === false || $handlerUpgraded ? 1 : $this->integer($row, 'attempts') + 1;
            if ($attempts > $consumer->maximumAttempts()) {
                $this->storePoison($consumer, $event, $now, $row !== false);
                return new InboxClaimResult(InboxDisposition::POISON);
            }
            $token = Uuid::uuid7()->toString();
            if ($row === false) {
                $this->insertReceipt(
                    $consumer,
                    $event,
                    'reserved',
                    $now,
                    attempts: $attempts,
                    worker: $worker,
                    token: $token,
                    generation: $generation,
                    expiresAt: $now->add(new DateInterval(sprintf('PT%dS', $leaseSeconds))),
                );
            } else {
                $this->assertOne($this->database->executeStatement(sprintf(
                    "UPDATE %s SET status = 'reserved', queue = ?, handler_version = ?, envelope = ?, attempts = ?, "
                    . 'maximum_attempts = ?, available_at = ?, lease_owner = ?, lease_token = ?, '
                    . 'lease_acquired_at = ?, lease_expires_at = ?, '
                    . 'runtime_generation = ?, failure_classification = NULL, exception_type = NULL, '
                    . 'error_message = NULL, evidence_compacted_at = NULL, updated_at = ? '
                    . 'WHERE consumer_id = ? AND event_id = ? '
                    . "AND status IN ('pending', 'poison', 'reserved', 'unavailable')",
                    $this->tables->quoted('integration_inbox'),
                ), [
                    $consumer->queue(), $consumer->handlerVersion(), RecordedEventEnvelope::document($event), $attempts,
                    $consumer->maximumAttempts(),
                    $now, $worker, $token, $now,
                    $now->add(new DateInterval(sprintf('PT%dS', $leaseSeconds))), $generation, $now,
                    $consumer->identifier(), $event->eventId(),
                ], [
                    Types::STRING, Types::STRING, Types::JSON, Types::INTEGER, Types::INTEGER,
                    Types::DATETIME_IMMUTABLE,
                    Types::STRING, Types::GUID, Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::STRING,
                    Types::DATETIME_IMMUTABLE, Types::STRING, Types::GUID,
                ]));
            }
            if ($policy !== null) {
                $this->database->update(
                    $this->tables->raw('job_queue_runtime'),
                    ['last_claimed_at' => $now, 'updated_at' => $now],
                    ['queue_id' => $policy->queue],
                    [
                        'last_claimed_at' => Types::DATETIME_IMMUTABLE,
                        'updated_at' => Types::DATETIME_IMMUTABLE,
                    ],
                );
            }
            return new InboxClaimResult(InboxDisposition::CLAIMED, new InboxLease(
                $consumer,
                $event,
                $attempts,
                $worker,
                $token,
                $generation,
            ));
        });
    }

    /**
     * Resolve the disposition of an existing receipt at the current timestamp.
     *
     * @param   array<string, mixed>  $row              Durable database row being reconstituted.
     * @param   DateTimeImmutable     $now              Authoritative timestamp for the state transition.
     * @param   bool                  $handlerUpgraded  Whether a new signed handler revision supersedes the receipt.
     *
     * @return  ?InboxDisposition  Current receipt disposition, or null when a new row is required.
     *
     * @since   2.0.0
     */
    private function existingDisposition(
        array $row,
        DateTimeImmutable $now,
        bool $handlerUpgraded,
    ): ?InboxDisposition {
        $status = $row['status'] ?? null;
        if ($status === 'completed') {
            return InboxDisposition::DUPLICATE;
        }
        if ($status === 'poison' && !$handlerUpgraded) {
            return InboxDisposition::POISON;
        }
        if ($status === 'pending') {
            $available = $row['available_at'] ?? null;
            if ($available instanceof \DateTimeInterface) {
                $available = DateTimeImmutable::createFromInterface($available);
            } elseif (is_string($available)) {
                $available = new DateTimeImmutable($available);
            }
            if (!$available instanceof DateTimeImmutable) {
                throw new RuntimeException('A pending inbox receipt has an invalid availability time.');
            }
            if ($available > $now) {
                return InboxDisposition::BUSY;
            }
        }
        if ($status === 'reserved') {
            $expires = $row['lease_expires_at'] ?? null;
            if ($expires instanceof \DateTimeInterface) {
                $expires = DateTimeImmutable::createFromInterface($expires);
            } elseif (is_string($expires)) {
                $expires = new DateTimeImmutable($expires);
            }
            if (!$expires instanceof DateTimeImmutable) {
                throw new RuntimeException('A reserved inbox receipt has an invalid lease expiration time.');
            }
            if ($expires > $now) {
                return InboxDisposition::BUSY;
            }
        }
        return null;
    }

    /**
     * Read the completed aggregate-version checkpoint for an ordered consumer.
     *
     * @param   EventConsumerDefinition  $consumer  Signed consumer contract governing the receipt.
     * @param   IntegrationEvent         $event     Versioned event being validated or processed.
     * @param   DateTimeImmutable        $now       Authoritative timestamp for the state transition.
     *
     * @return  int  Highest aggregate version completed for this ordered consumer.
     *
     * @since   2.0.0
     */
    private function checkpoint(
        EventConsumerDefinition $consumer,
        IntegrationEvent $event,
        DateTimeImmutable $now,
    ): int {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT aggregate_version FROM %s WHERE consumer_id = ? AND scope_checksum = ? '
            . 'AND site_identifier = ? AND organization_scope = ? AND aggregate_type = ? AND aggregate_id = ?%s',
            $this->tables->quoted('integration_consumer_checkpoints'),
            $this->lockClause(false),
        ), [
            $consumer->identifier(), $this->scopeChecksum($event), $event->siteIdentifier(),
            $this->organizationScope($event), $event->aggregateType(), $event->aggregateId(),
        ], [Types::STRING, Types::STRING, Types::STRING, Types::STRING, Types::STRING, Types::STRING]);
        if ($row !== false) {
            return $this->integer($row, 'aggregate_version');
        }
        $baseline = 0;
        $this->database->insert($this->tables->raw('integration_consumer_checkpoints'), [
            'consumer_id' => $consumer->identifier(),
            'scope_checksum' => $this->scopeChecksum($event),
            'site_identifier' => $event->siteIdentifier(),
            'organization_scope' => $this->organizationScope($event),
            'aggregate_type' => $event->aggregateType(),
            'aggregate_id' => $event->aggregateId(),
            'aggregate_version' => $baseline,
            'event_id' => null,
            'updated_at' => $now,
        ], ['updated_at' => Types::DATETIME_IMMUTABLE]);
        return $baseline;
    }

    /**
     * Normalize the nullable event organization into an unambiguous durable checkpoint scope.
     *
     * Empty strings are forbidden by the event envelope, so they safely represent site-wide events.
     *
     * @param   IntegrationEvent  $event  Event whose tenant scope is being checkpointed.
     *
     * @return  string  Organization identity, or the site-wide empty sentinel.
     *
     * @since   2.0.0
     */
    private function organizationScope(IntegrationEvent $event): string
    {
        return $event->organizationId() ?? '';
    }

    /**
     * Compile a bounded tenant-scope key suitable for a portable composite primary key.
     *
     * The NUL separator cannot occur in a validated event identity. Explicit scope columns remain in every
     * predicate so even a theoretical digest collision fails closed instead of crossing a tenant boundary.
     *
     * @param   IntegrationEvent  $event  Event whose tenant scope is being checkpointed.
     *
     * @return  string  Lowercase SHA-256 tenant-scope checksum.
     *
     * @since   2.0.0
     */
    private function scopeChecksum(IntegrationEvent $event): string
    {
        return hash('sha256', $event->siteIdentifier() . "\0" . $this->organizationScope($event));
    }

    /**
     * Persist an unavailable receipt without granting a processing lease.
     *
     * @param   EventConsumerDefinition  $consumer  Signed consumer contract governing the receipt.
     * @param   IntegrationEvent         $event     Versioned event being validated or processed.
     * @param   DateTimeImmutable        $now       Authoritative timestamp for the state transition.
     * @param   bool                     $exists    Existing receipt row, when one has already been recorded.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function storeUnavailable(
        EventConsumerDefinition $consumer,
        IntegrationEvent $event,
        DateTimeImmutable $now,
        bool $exists,
    ): void {
        if (!$exists) {
            $this->insertReceipt($consumer, $event, 'unavailable', $now, error: 'Unsupported schema or sensitivity.');
            return;
        }
        $this->updateSimple($consumer, $event, 'unavailable', $now, 'Unsupported schema or sensitivity.');
    }

    /**
     * Persist or refresh a receipt that represents an already completed delivery.
     *
     * @param   EventConsumerDefinition  $consumer  Signed consumer contract governing the receipt.
     * @param   IntegrationEvent         $event     Versioned event being validated or processed.
     * @param   DateTimeImmutable        $now       Authoritative timestamp for the state transition.
     * @param   bool                     $exists    Existing receipt row, when one has already been recorded.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function storeDuplicate(
        EventConsumerDefinition $consumer,
        IntegrationEvent $event,
        DateTimeImmutable $now,
        bool $exists,
    ): void {
        if (!$exists) {
            $this->insertReceipt($consumer, $event, 'completed', $now, completedAt: $now);
            return;
        }
        $this->updateSimple($consumer, $event, 'completed', $now, completedAt: $now);
    }

    /**
     * Persist a pending receipt while an earlier aggregate version remains incomplete.
     *
     * @param   EventConsumerDefinition  $consumer  Signed consumer contract governing the receipt.
     * @param   IntegrationEvent         $event     Versioned event being validated or processed.
     * @param   DateTimeImmutable        $now       Authoritative timestamp for the state transition.
     * @param   bool                     $exists    Existing receipt row, when one has already been recorded.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function storePending(
        EventConsumerDefinition $consumer,
        IntegrationEvent $event,
        DateTimeImmutable $now,
        bool $exists,
    ): void {
        if (!$exists) {
            $this->insertReceipt(
                $consumer,
                $event,
                'pending',
                $now,
                error: 'Waiting for an earlier aggregate version.',
            );
            return;
        }
        $this->updateSimple($consumer, $event, 'pending', $now, 'Waiting for an earlier aggregate version.');
    }

    /**
     * Persist a quarantined receipt that cannot be delivered safely.
     *
     * @param   EventConsumerDefinition  $consumer  Signed consumer contract governing the receipt.
     * @param   IntegrationEvent         $event     Versioned event being validated or processed.
     * @param   DateTimeImmutable        $now       Authoritative timestamp for the state transition.
     * @param   bool                     $exists    Existing receipt row, when one has already been recorded.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function storePoison(
        EventConsumerDefinition $consumer,
        IntegrationEvent $event,
        DateTimeImmutable $now,
        bool $exists,
    ): void {
        if (!$exists) {
            $this->insertReceipt($consumer, $event, 'poison', $now, error: 'Consumer attempt budget exhausted.');
            return;
        }
        $this->updateSimple($consumer, $event, 'poison', $now, 'Consumer attempt budget exhausted.');
    }

    /**
     * Insert a complete inbox receipt with scope, lease, and failure metadata.
     *
     * @param   EventConsumerDefinition  $consumer     Signed consumer contract governing the receipt.
     * @param   IntegrationEvent         $event        Versioned event being validated or processed.
     * @param   string                   $status       Durable state to record for the receipt.
     * @param   DateTimeImmutable        $now          Authoritative timestamp for the state transition.
     * @param   int                      $attempts     Attempt count to record for this receipt.
     * @param   ?string                  $worker       Stable identity of the claiming worker.
     * @param   ?string                  $token        Opaque lease token used to fence concurrent workers.
     * @param   ?string                  $generation   Trusted runtime generation that owns the lease.
     * @param   ?DateTimeImmutable       $expiresAt    Lease expiration timestamp, when a lease is granted.
     * @param   ?string                  $error        Sanitized failure detail retained for operators.
     * @param   ?DateTimeImmutable       $completedAt  Timestamp at which processing completed, when applicable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function insertReceipt(
        EventConsumerDefinition $consumer,
        IntegrationEvent $event,
        string $status,
        DateTimeImmutable $now,
        int $attempts = 0,
        ?string $worker = null,
        ?string $token = null,
        ?string $generation = null,
        ?DateTimeImmutable $expiresAt = null,
        ?string $error = null,
        ?DateTimeImmutable $completedAt = null,
    ): void {
        $this->database->insert($this->tables->raw('integration_inbox'), [
            'consumer_id' => $consumer->identifier(),
            'event_id' => $event->eventId(),
            'queue' => $consumer->queue(),
            'event_type' => $event->eventType(),
            'schema_version' => $event->schemaVersion(),
            'handler_version' => $consumer->handlerVersion(),
            'site_identifier' => $event->siteIdentifier(),
            'organization_id' => $event->organizationId(),
            'aggregate_type' => $event->aggregateType(),
            'aggregate_id' => $event->aggregateId(),
            'aggregate_version' => $event->aggregateVersion(),
            'envelope' => RecordedEventEnvelope::document($event),
            'status' => $status,
            'attempts' => $attempts,
            'maximum_attempts' => $consumer->maximumAttempts(),
            'available_at' => $now,
            'lease_owner' => $worker,
            'lease_token' => $token,
            'lease_acquired_at' => $worker === null ? null : $now,
            'lease_expires_at' => $expiresAt,
            'runtime_generation' => $generation,
            'failure_classification' => null,
            'exception_type' => null,
            'error_message' => $error,
            'first_received_at' => $now,
            'completed_at' => $completedAt,
            'evidence_compacted_at' => null,
            'updated_at' => $now,
        ], [
            'envelope' => Types::JSON,
            'available_at' => Types::DATETIME_IMMUTABLE,
            'lease_acquired_at' => Types::DATETIME_IMMUTABLE,
            'lease_expires_at' => Types::DATETIME_IMMUTABLE,
            'first_received_at' => Types::DATETIME_IMMUTABLE,
            'completed_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * Update a receipt status without granting or renewing a processing lease.
     *
     * @param   EventConsumerDefinition  $consumer     Signed consumer contract governing the receipt.
     * @param   IntegrationEvent         $event        Versioned event being validated or processed.
     * @param   string                   $status       Durable state to record for the receipt.
     * @param   DateTimeImmutable        $now          Authoritative timestamp for the state transition.
     * @param   ?string                  $error        Sanitized failure detail retained for operators.
     * @param   ?DateTimeImmutable       $completedAt  Timestamp at which processing completed, when applicable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function updateSimple(
        EventConsumerDefinition $consumer,
        IntegrationEvent $event,
        string $status,
        DateTimeImmutable $now,
        ?string $error = null,
        ?DateTimeImmutable $completedAt = null,
    ): void {
        $this->database->update($this->tables->raw('integration_inbox'), [
            'status' => $status,
            'queue' => $consumer->queue(),
            'handler_version' => $consumer->handlerVersion(),
            'maximum_attempts' => $consumer->maximumAttempts(),
            'lease_owner' => null,
            'lease_token' => null,
            'lease_acquired_at' => null,
            'lease_expires_at' => null,
            'runtime_generation' => null,
            'failure_classification' => null,
            'exception_type' => null,
            'error_message' => $error,
            'completed_at' => $completedAt,
            'evidence_compacted_at' => null,
            'envelope' => RecordedEventEnvelope::document($event),
            'updated_at' => $now,
        ], ['consumer_id' => $consumer->identifier(), 'event_id' => $event->eventId()], [
            'completed_at' => Types::DATETIME_IMMUTABLE,
            'envelope' => Types::JSON,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * Narrow a signed consumer attempt budget through its active queue declaration.
     *
     * The executable handler is still reconciled against the unchanged signed definition before the
     * dispatcher reaches this store. This derived value exists only for the durable receipt and retry
     * lease, where the queue ceiling must be no wider than the consumer's own ceiling.
     *
     * @param   EventConsumerDefinition  $consumer  Signed active consumer or outbound-adapter receipt.
     *
     * @return  EventConsumerDefinition  Equivalent receipt contract with the effective attempt ceiling.
     *
     * @since   2.0.0
     */
    private function effectiveConsumer(EventConsumerDefinition $consumer): EventConsumerDefinition
    {
        $policy = $this->policies?->policy($consumer->queue());
        $maximum = $policy === null
            ? $consumer->maximumAttempts()
            : min($consumer->maximumAttempts(), $policy->maximumAttempts);
        if ($maximum === $consumer->maximumAttempts()) {
            return $consumer;
        }

        return new EventConsumerDefinition(
            $consumer->identifier(),
            $consumer->eventType(),
            $consumer->schemaVersions(),
            $consumer->handlerVersion(),
            $consumer->queue(),
            $consumer->aggregateOrdered(),
            $consumer->idempotency(),
            $maximum,
            $consumer->sensitivityCeiling(),
        );
    }

    /**
     * Create the shared queue lock row before the receipt transaction attempts to lock it.
     *
     * @param   QueueRuntimePolicy  $policy  Active trusted queue policy.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function ensureQueueRuntime(QueueRuntimePolicy $policy): void
    {
        if (
            $this->database->fetchOne(sprintf(
                'SELECT queue_id FROM %s WHERE queue_id = ?',
                $this->tables->quoted('job_queue_runtime'),
            ), [$policy->queue]) !== false
        ) {
            return;
        }
        $now = $this->clock->now();
        try {
            $this->database->insert($this->tables->raw('job_queue_runtime'), [
                'queue_id' => $policy->queue,
                'lease_seconds' => $policy->leaseSeconds,
                'maximum_attempts' => $policy->maximumAttempts,
                'maximum_in_flight' => $policy->maximumInFlight,
                'retention_days' => $policy->retentionDays,
                'runtime_generation' => $policy->runtimeGeneration,
                'last_claimed_at' => null,
                'updated_at' => $now,
            ], ['updated_at' => Types::DATETIME_IMMUTABLE]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent job or inbox claimant committed the shared lock row.
        }
    }

    /**
     * Serialize queue-wide capacity across job reservations and inbox delivery leases.
     *
     * @param   QueueRuntimePolicy  $policy  Active trusted queue policy.
     * @param   DateTimeImmutable   $now     Instant live fences are compared against.
     *
     * @return  bool  True when one more live job or delivery fits below the signed ceiling.
     *
     * @throws  RuntimeException  When the shared queue lock row disappeared unexpectedly.
     *
     * @since   2.0.0
     */
    private function claimPolicySlot(QueueRuntimePolicy $policy, DateTimeImmutable $now): bool
    {
        $locked = $this->database->fetchOne(sprintf(
            'SELECT queue_id FROM %s WHERE queue_id = ?%s',
            $this->tables->quoted('job_queue_runtime'),
            $this->lockClause(false),
        ), [$policy->queue]);
        if ($locked === false) {
            throw new RuntimeException('The contributed queue runtime lock is unavailable.');
        }
        $this->database->update($this->tables->raw('job_queue_runtime'), [
            'lease_seconds' => $policy->leaseSeconds,
            'maximum_attempts' => $policy->maximumAttempts,
            'maximum_in_flight' => $policy->maximumInFlight,
            'retention_days' => $policy->retentionDays,
            'runtime_generation' => $policy->runtimeGeneration,
            'updated_at' => $now,
        ], ['queue_id' => $policy->queue], ['updated_at' => Types::DATETIME_IMMUTABLE]);
        $jobs = $this->databaseCount($this->database->fetchOne(sprintf(
            "SELECT COUNT(*) FROM %s WHERE queue = ? AND status = 'reserved' AND lease_expires_at > ?",
            $this->tables->quoted('jobs'),
        ), [$policy->queue, $now], [Types::STRING, Types::DATETIME_IMMUTABLE]));
        $deliveries = $this->databaseCount($this->database->fetchOne(sprintf(
            "SELECT COUNT(*) FROM %s WHERE queue = ? AND status = 'reserved' AND lease_expires_at > ?",
            $this->tables->quoted('integration_inbox'),
        ), [$policy->queue, $now], [Types::STRING, Types::DATETIME_IMMUTABLE]));

        return $jobs + $deliveries < $policy->maximumInFlight;
    }

    /**
     * Read and validate an integer value.
     *
     * @param   array<string, mixed>  $row  Durable database row being reconstituted.
     * @param   string                $key  Array or row key whose value is being read.
     *
     * @return  int  Integer stored under the requested key.
     *
     * @since   2.0.0
     */
    private function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) {
            throw new RuntimeException(sprintf('Inbox field "%s" is not an integer.', $key));
        }
        return (int) $value;
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

        throw new RuntimeException('An integration inbox count is invalid.');
    }

    /**
     * Return the database-specific row-locking clause.
     *
     * @param   bool  $skipLocked  Whether rows held by another worker should be skipped.
     *
     * @return  string  Driver-specific SQL suffix used to fence concurrent claims.
     *
     * @since   2.0.0
     */
    private function lockClause(bool $skipLocked): string
    {
        $platform = $this->database->getDatabasePlatform();
        if (!$platform instanceof PostgreSQLPlatform && !$platform instanceof AbstractMySQLPlatform) {
            return '';
        }
        return $skipLocked ? ' FOR UPDATE SKIP LOCKED' : ' FOR UPDATE';
    }

    /**
     * Validate worker identity, runtime generation, and lease bounds.
     *
     * @param   string  $worker      Stable identity of the claiming worker.
     * @param   string  $generation  Trusted runtime generation that owns the lease.
     * @param   int     $seconds     Requested lease duration in seconds.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertClaimInput(string $worker, string $generation, int $seconds): void
    {
        if (
            preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/D', $worker) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D', $generation) !== 1
            || $seconds < 5
            || $seconds > 3_600
        ) {
            throw new InvalidArgumentException('The inbox claim lease metadata is invalid.');
        }
    }

    /**
     * Require exactly one row to have been changed by a fenced update.
     *
     * @param   int|string  $affected  Number of rows changed by the fenced statement.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertOne(int|string $affected): void
    {
        if ((string) $affected !== '1') {
            throw new RuntimeException('The worker no longer owns the active inbox lease.');
        }
    }
}
