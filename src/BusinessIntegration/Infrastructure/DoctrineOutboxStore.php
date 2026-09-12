<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Infrastructure;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use JsonException;
use Kumwe\App\Application\Automation\FailureClassification;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\BusinessIntegration\Application\EventContractRegistry;
use Kumwe\App\BusinessIntegration\Application\OutboxLease;
use Kumwe\App\BusinessIntegration\Application\OutboxStore;
use Kumwe\App\BusinessIntegration\Domain\RecordedEventEnvelope;
use Kumwe\App\BusinessIntegration\Domain\RecordedIntegrationEvent;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\IntegrationEvent;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Domain\CanonicalJson;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

/**
 * DBAL transactional outbox with expiring leases, fencing, retries, replay and bounded retention.
 *
 * `append()` deliberately opens no transaction: when called within an authoritative Doctrine transaction,
 * its insert is committed or rolled back with that mutation. Dispatch operations use their own short
 * transactions and compare worker, token, generation and unexpired lease on every settlement.
 *
 * @since  2.0.0
 */
final readonly class DoctrineOutboxStore implements OutboxStore
{
    /**
     * Most exhausted events one claim's quarantine pass will bury before getting on with its own work.
     *
     * The pass is a courtesy the claiming dispatcher performs for the queue, so it is bounded: a large
     * backlog of exhausted events is buried over several claims rather than turning one claim into a
     * table-wide write.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int BURY_BATCH = 100;

    /**
     * Bind the durable store to its transaction and schema collaborators.
     *
     * @param   Connection             $database       Shared authoritative connection.
     * @param   TableNames             $tables         Physical table-name compiler.
     * @param   TransactionManager     $transactions   Short dispatch transaction boundary.
     * @param   ClockInterface         $clock          Lease and lifecycle clock.
     * @param   EventContractRegistry  $contracts      Exact trusted event contracts.
     * @param   int                    $retentionDays  Terminal-row retention window.
     *
     * @throws  InvalidArgumentException  When retention falls outside 1 to 3650 days.
     *
     * @since   2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private TransactionManager $transactions,
        private ClockInterface $clock,
        private EventContractRegistry $contracts,
        private int $retentionDays = 90,
    ) {
        if ($retentionDays < 1 || $retentionDays > 3_650) {
            throw new InvalidArgumentException('Outbox retention must be between 1 and 3650 days.');
        }
    }

    /**
     * Insert a validated event on the caller's active authoritative transaction.
     *
     * @param   IntegrationEvent    $event            Durable fact.
     * @param   int                 $maximumAttempts  Dispatch attempt budget.
     * @param   ?DateTimeImmutable  $availableAt      Earliest dispatch time; defaults to now.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the attempt budget is invalid.
     *
     * @since   2.0.0
     */
    public function append(
        IntegrationEvent $event,
        int $maximumAttempts = 10,
        ?DateTimeImmutable $availableAt = null,
    ): void {
        $this->contracts->assertEvent($event);
        if ($maximumAttempts < 1 || $maximumAttempts > 100) {
            throw new InvalidArgumentException('Outbox maximum attempts must be between 1 and 100.');
        }
        $now = $this->clock->now();
        $this->database->insert($this->tables->raw('integration_outbox'), [
            'event_id' => $event->eventId(),
            'event_type' => $event->eventType(),
            'schema_version' => $event->schemaVersion(),
            'sensitivity' => $event->sensitivity()->value,
            'site_identifier' => $event->siteIdentifier(),
            'organization_id' => $event->organizationId(),
            'aggregate_type' => $event->aggregateType(),
            'aggregate_id' => $event->aggregateId(),
            'aggregate_version' => $event->aggregateVersion(),
            'correlation_id' => $event->correlationId(),
            'envelope' => RecordedEventEnvelope::document($event),
            'status' => 'pending',
            'available_at' => $availableAt !== null && $availableAt > $now ? $availableAt : $now,
            'attempts' => 0,
            'maximum_attempts' => $maximumAttempts,
            'lease_owner' => null,
            'lease_token' => null,
            'lease_acquired_at' => null,
            'lease_expires_at' => null,
            'runtime_generation' => null,
            'failure_classification' => null,
            'exception_type' => null,
            'error_message' => null,
            'dispatched_at' => null,
            'retained_until' => $now->add(new DateInterval(sprintf('P%dD', $this->retentionDays))),
            'replay_count' => 0,
            'replayed_at' => null,
            'replayed_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            'envelope' => Types::JSON,
            'available_at' => Types::DATETIME_IMMUTABLE,
            'retained_until' => Types::DATETIME_IMMUTABLE,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
        $journalHead = $this->database->fetchOne(sprintf(
            'SELECT last_sequence FROM %s WHERE singleton_id = 1%s',
            $this->tables->quoted('business_projection_event_head'),
            $this->journalLockClause(),
        ));
        if (
            (!is_int($journalHead) || $journalHead < 0)
            && (!is_string($journalHead) || preg_match('/^[0-9]+$/D', $journalHead) !== 1)
        ) {
            throw new RuntimeException('The projection source journal head is unavailable.');
        }
        $envelope = RecordedEventEnvelope::document($event);
        $this->database->insert($this->tables->raw('business_projection_source_events'), [
            'event_id' => $event->eventId(),
            'event_type' => $event->eventType(),
            'schema_version' => $event->schemaVersion(),
            'sensitivity' => $event->sensitivity()->value,
            'envelope' => $envelope,
            'event_checksum' => CanonicalJson::digest($envelope),
            'recorded_at' => $now,
        ], [
            'event_id' => Types::GUID,
            'schema_version' => Types::INTEGER,
            'envelope' => Types::JSON,
            'recorded_at' => Types::DATETIME_IMMUTABLE,
        ]);
        $sequence = $this->database->fetchOne(sprintf(
            'SELECT source_sequence FROM %s WHERE event_id = ?',
            $this->tables->quoted('business_projection_source_events'),
        ), [$event->eventId()], [Types::GUID]);
        if (!is_int($sequence) && (!is_string($sequence) || preg_match('/^[1-9][0-9]*$/D', $sequence) !== 1)) {
            throw new RuntimeException('The projection source journal did not assign an event sequence.');
        }
        $sequence = (int) $sequence;
        $updatedHead = $this->database->executeStatement(sprintf(
            'UPDATE %s SET last_sequence = ? WHERE singleton_id = 1 AND last_sequence = ?',
            $this->tables->quoted('business_projection_event_head'),
        ), [$sequence, (int) $journalHead], [Types::BIGINT, Types::BIGINT]);
        $this->assertOne(
            $updatedHead,
            'The projection source journal head lost its serialization fence.',
        );
    }

    /**
     * Reserve the oldest runnable event under a fresh token and exact runtime generation.
     *
     * @param   string  $workerId           Lease owner.
     * @param   string  $runtimeGeneration  Exact trusted generation selecting the transport.
     * @param   int     $leaseSeconds       Lease duration, 5 to 3600 seconds.
     *
     * @return  ?OutboxLease  Claimed event, or null when none is runnable.
     *
     * @since   2.0.0
     */
    public function claim(string $workerId, string $runtimeGeneration, int $leaseSeconds): ?OutboxLease
    {
        $this->assertLeaseInput($workerId, $runtimeGeneration, $leaseSeconds);
        return $this->transactions->transactional(function () use (
            $workerId,
            $runtimeGeneration,
            $leaseSeconds,
        ): ?OutboxLease {
            $now = $this->clock->now();
            $this->buryExhausted($now);
            $row = $this->database->fetchAssociative(sprintf(
                'SELECT * FROM %s WHERE attempts < maximum_attempts AND ('
                . "(status = 'pending' AND available_at <= ?) OR "
                . "(status = 'reserved' AND (lease_expires_at IS NULL OR lease_expires_at <= ?))) "
                . 'ORDER BY available_at, created_at, event_id LIMIT 1%s',
                $this->tables->quoted('integration_outbox'),
                $this->lockClause(),
            ), [$now, $now], [Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE]);
            if ($row === false) {
                return null;
            }
            $eventId = $this->requiredString($row, 'event_id');
            $token = Uuid::uuid7()->toString();
            $affected = $this->database->executeStatement(sprintf(
                "UPDATE %s SET status = 'reserved', lease_owner = ?, lease_token = ?, lease_acquired_at = ?, "
                . 'lease_expires_at = ?, runtime_generation = ?, attempts = attempts + 1, updated_at = ? '
                . 'WHERE event_id = ? AND attempts < maximum_attempts AND ('
                . "(status = 'pending' AND available_at <= ?) OR "
                . "(status = 'reserved' AND (lease_expires_at IS NULL OR lease_expires_at <= ?)))",
                $this->tables->quoted('integration_outbox'),
            ), [
                $workerId,
                $token,
                $now,
                $now->add(new DateInterval(sprintf('PT%dS', $leaseSeconds))),
                $runtimeGeneration,
                $now,
                $eventId,
                $now,
                $now,
            ], [
                Types::STRING, Types::GUID, Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE,
                Types::STRING, Types::DATETIME_IMMUTABLE, Types::GUID, Types::DATETIME_IMMUTABLE,
                Types::DATETIME_IMMUTABLE,
            ]);
            $this->assertOne($affected);
            return new OutboxLease(
                $this->event($row),
                $this->integer($row, 'attempts') + 1,
                $this->integer($row, 'maximum_attempts'),
                $workerId,
                $token,
                $runtimeGeneration,
            );
        });
    }

    /**
     * Renew the supplied durable-processing lease.
     *
     * @param   OutboxLease  $lease         Fenced lease proving ownership of the durable item.
     * @param   int          $leaseSeconds  Number of seconds before the worker lease expires.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function renew(OutboxLease $lease, int $leaseSeconds): void
    {
        if ($leaseSeconds < 5 || $leaseSeconds > 3_600) {
            throw new InvalidArgumentException('An outbox lease must last between 5 and 3600 seconds.');
        }
        $now = $this->clock->now();
        $this->assertOne($this->database->executeStatement(sprintf(
            'UPDATE %s SET lease_expires_at = ?, updated_at = ? WHERE event_id = ? '
            . "AND status = 'reserved' AND lease_owner = ? AND lease_token = ? "
            . 'AND runtime_generation = ? AND lease_expires_at > ?',
            $this->tables->quoted('integration_outbox'),
        ), [
            $now->add(new DateInterval(sprintf('PT%dS', $leaseSeconds))), $now, $lease->event->eventId(),
            $lease->workerId, $lease->leaseToken, $lease->runtimeGeneration, $now,
        ], [
            Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::GUID, Types::STRING,
            Types::GUID, Types::STRING, Types::DATETIME_IMMUTABLE,
        ]));
    }

    /**
     * Mark the supplied durable-processing lease complete.
     *
     * @param   OutboxLease  $lease  Fenced lease proving ownership of the durable item.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function complete(OutboxLease $lease): void
    {
        $now = $this->clock->now();
        $this->assertOne($this->database->executeStatement(sprintf(
            "UPDATE %s SET status = 'dispatched', lease_owner = NULL, lease_token = NULL, "
            . 'lease_acquired_at = NULL, lease_expires_at = NULL, runtime_generation = NULL, '
            . 'dispatched_at = ?, updated_at = ? WHERE event_id = ? '
            . "AND status = 'reserved' AND lease_owner = ? AND lease_token = ? "
            . 'AND runtime_generation = ? AND lease_expires_at > ?',
            $this->tables->quoted('integration_outbox'),
        ), [
            $now, $now, $lease->event->eventId(), $lease->workerId, $lease->leaseToken,
            $lease->runtimeGeneration, $now,
        ], [
            Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::GUID, Types::STRING,
            Types::GUID, Types::STRING, Types::DATETIME_IMMUTABLE,
        ]));
    }

    /**
     * Release a fenced outbox claim after downstream queue backpressure.
     *
     * The claim increment is reversed in the same fenced update, so a saturated contributed queue cannot
     * eventually quarantine an otherwise valid event merely by remaining busy. The delay is bounded to
     * prevent either a hot loop or an unobservable long deferral.
     *
     * @param   OutboxLease  $lease         Active fenced outbox claim.
     * @param   int          $delaySeconds  Delay from one to 300 seconds.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the delay is outside the operational bound.
     *
     * @since   2.0.0
     */
    public function defer(OutboxLease $lease, int $delaySeconds = 5): void
    {
        if ($delaySeconds < 1 || $delaySeconds > 300) {
            throw new InvalidArgumentException('An outbox backpressure delay must be between 1 and 300 seconds.');
        }
        $now = $this->clock->now();
        $this->assertOne($this->database->executeStatement(sprintf(
            "UPDATE %s SET status = 'pending', available_at = ?, attempts = attempts - 1, "
            . 'lease_owner = NULL, lease_token = NULL, lease_acquired_at = NULL, lease_expires_at = NULL, '
            . 'runtime_generation = NULL, updated_at = ? WHERE event_id = ? AND attempts = ? '
            . "AND status = 'reserved' AND lease_owner = ? AND lease_token = ? "
            . 'AND runtime_generation = ? AND lease_expires_at > ?',
            $this->tables->quoted('integration_outbox'),
        ), [
            $now->add(new DateInterval(sprintf('PT%dS', $delaySeconds))),
            $now,
            $lease->event->eventId(),
            $lease->attempts,
            $lease->workerId,
            $lease->leaseToken,
            $lease->runtimeGeneration,
            $now,
        ], [
            Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::GUID, Types::SMALLINT,
            Types::STRING, Types::GUID, Types::STRING, Types::DATETIME_IMMUTABLE,
        ]));
    }

    /**
     * Record a failed durable delivery and its retry decision.
     *
     * @param   OutboxLease            $lease           Fenced lease proving ownership of the durable item.
     * @param   FailureClassification  $classification  Failure class controlling retry or quarantine behavior.
     * @param   Throwable              $failure         Failure whose retry classification is being recorded.
     * @param   ?DateTimeImmutable     $retryAt         Next eligible attempt timestamp, or null for quarantine.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function fail(
        OutboxLease $lease,
        FailureClassification $classification,
        Throwable $failure,
        ?DateTimeImmutable $retryAt,
    ): void {
        $retry = $classification === FailureClassification::TRANSIENT
            && $retryAt !== null
            && $lease->attempts < $lease->maximumAttempts;
        $now = $this->clock->now();
        $status = $retry ? 'pending' : 'dead';
        $available = $retry ? ($retryAt < $now ? $now : $retryAt) : $now;
        $this->assertOne($this->database->executeStatement(sprintf(
            'UPDATE %s SET status = ?, available_at = ?, lease_owner = NULL, lease_token = NULL, '
            . 'lease_acquired_at = NULL, lease_expires_at = NULL, runtime_generation = NULL, '
            . 'failure_classification = ?, exception_type = ?, error_message = ?, updated_at = ? '
            . "WHERE event_id = ? AND status = 'reserved' AND lease_owner = ? AND lease_token = ? "
            . 'AND runtime_generation = ? AND lease_expires_at > ?',
            $this->tables->quoted('integration_outbox'),
        ), [
            $status, $available, $classification->value, $failure::class,
            substr($failure->getMessage(), 0, 4_000), $now, $lease->event->eventId(),
            $lease->workerId, $lease->leaseToken, $lease->runtimeGeneration, $now,
        ], [
            Types::STRING, Types::DATETIME_IMMUTABLE, Types::STRING, Types::STRING, Types::STRING,
            Types::DATETIME_IMMUTABLE, Types::GUID, Types::STRING, Types::GUID, Types::STRING,
            Types::DATETIME_IMMUTABLE,
        ]));
    }

    /**
     * Make an operator-authorized event eligible for replay.
     *
     * @param   string              $eventId      Immutable identifier of the event to replay.
     * @param   string              $operatorId   Authenticated operator authorizing the replay.
     * @param   ?DateTimeImmutable  $availableAt  Earliest timestamp at which the event may be claimed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function replay(string $eventId, string $operatorId, ?DateTimeImmutable $availableAt = null): void
    {
        if (!Uuid::isValid($eventId)) {
            throw new InvalidArgumentException('A replay event ID must be a UUID.');
        }
        if ($operatorId === '' || strlen($operatorId) > 191 || preg_match('/[\x00-\x1F\x7F]/D', $operatorId) === 1) {
            throw new InvalidArgumentException('A replay operator identity is invalid.');
        }
        $now = $this->clock->now();
        $this->assertOne($this->database->executeStatement(sprintf(
            "UPDATE %s SET status = 'pending', available_at = ?, attempts = 0, lease_owner = NULL, "
            . 'lease_token = NULL, lease_acquired_at = NULL, lease_expires_at = NULL, runtime_generation = NULL, '
            . 'failure_classification = NULL, exception_type = NULL, error_message = NULL, dispatched_at = NULL, '
            . 'replay_count = replay_count + 1, replayed_at = ?, replayed_by = ?, updated_at = ? '
            . "WHERE event_id = ? AND status IN ('dead', 'dispatched')",
            $this->tables->quoted('integration_outbox'),
        ), [$availableAt !== null && $availableAt > $now ? $availableAt : $now, $now, $operatorId, $now, $eventId], [
            Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::STRING,
            Types::DATETIME_IMMUTABLE, Types::GUID,
        ]));
    }

    /**
     * Purge an operator-bounded batch of expired records.
     *
     * @param   DateTimeImmutable  $now    Authoritative timestamp for the state transition.
     * @param   int                $limit  Maximum number of records the operation may return or change.
     *
     * @return  int  Number of expired outbox records deleted.
     *
     * @since   2.0.0
     */
    public function purgeExpired(DateTimeImmutable $now, int $limit = 1_000): int
    {
        if ($limit < 1 || $limit > 10_000) {
            throw new InvalidArgumentException('An outbox purge limit must be between 1 and 10000.');
        }
        return $this->transactions->transactional(function () use ($now, $limit): int {
            $ids = $this->database->fetchFirstColumn(sprintf(
                "SELECT event_id FROM %s WHERE status IN ('dead', 'dispatched') AND retained_until <= ? "
                . 'ORDER BY retained_until, event_id LIMIT ?',
                $this->tables->quoted('integration_outbox'),
            ), [$now, $limit], [Types::DATETIME_IMMUTABLE, Types::INTEGER]);
            if ($ids === []) {
                return 0;
            }
            return (int) $this->database->executeStatement(sprintf(
                'DELETE FROM %s WHERE event_id IN (?)',
                $this->tables->quoted('integration_outbox'),
            ), [$ids], [ArrayParameterType::STRING]);
        });
    }

    /**
     * Return the most recent operator-visible records.
     *
     * @param   int  $limit  Maximum number of records the operation may return or change.
     *
     * @return  list<array<string, mixed>>  Operator-visible rows in deterministic order.
     *
     * @since   2.0.0
     */
    public function recent(int $limit = 100): array
    {
        if ($limit < 1 || $limit > 1_000) {
            throw new InvalidArgumentException('An outbox list limit must be between 1 and 1000.');
        }
        return $this->database->fetchAllAssociative(sprintf(
            'SELECT event_id, event_type, schema_version, sensitivity, site_identifier, organization_id, '
            . 'aggregate_type, aggregate_id, '
            . 'aggregate_version, correlation_id, status, available_at, attempts, maximum_attempts, lease_owner, '
            . 'runtime_generation, failure_classification, exception_type, error_message, dispatched_at, '
            . 'replay_count, replayed_at, replayed_by, created_at, updated_at FROM %s '
            . 'ORDER BY created_at DESC, event_id DESC LIMIT ?',
            $this->tables->quoted('integration_outbox'),
        ), [$limit], [Types::INTEGER]);
    }

    /**
     * Quarantine eligible records that exhausted their attempt budget.
     *
     * Candidates are gathered first, under the same `SKIP LOCKED` clause the claim itself uses, and only
     * then updated by primary key. The obvious single `UPDATE ... WHERE attempts >= maximum_attempts` is
     * what this replaces, and the reason is contention rather than tidiness: comparing two columns cannot
     * use the claim index, so the engine scans the table and takes a lock on what it reads. Because this
     * pass runs at the head of every `claim()`, one dispatcher holding a row would make every other
     * dispatcher wait behind it here — before ever reaching the `SKIP LOCKED` select that exists precisely
     * so they do not. Skipping a locked candidate costs nothing: it is still exhausted on the next pass.
     *
     * @param   DateTimeImmutable  $now  Authoritative timestamp for the state transition.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function buryExhausted(DateTimeImmutable $now): void
    {
        $condition = 'attempts >= maximum_attempts AND ('
            . "status = 'pending' OR (status = 'reserved' AND (lease_expires_at IS NULL OR lease_expires_at <= ?)))";
        $exhausted = $this->database->fetchFirstColumn(sprintf(
            'SELECT event_id FROM %s WHERE %s ORDER BY available_at, created_at, event_id LIMIT %d%s',
            $this->tables->quoted('integration_outbox'),
            $condition,
            self::BURY_BATCH,
            $this->lockClause(),
        ), [$now], [Types::DATETIME_IMMUTABLE]);
        if ($exhausted === []) {
            return;
        }
        $this->database->executeStatement(sprintf(
            "UPDATE %s SET status = 'dead', lease_owner = NULL, lease_token = NULL, lease_acquired_at = NULL, "
            . 'lease_expires_at = NULL, runtime_generation = NULL, failure_classification = ?, '
            . 'exception_type = ?, error_message = ?, updated_at = ? WHERE event_id IN (?) AND %s',
            $this->tables->quoted('integration_outbox'),
            $condition,
        ), [
            FailureClassification::TRANSIENT->value,
            self::class . '\\ExpiredOutboxLease',
            'The final outbox lease expired before dispatch completed.',
            $now,
            $exhausted,
            $now,
        ], [
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::DATETIME_IMMUTABLE,
            ArrayParameterType::STRING,
            Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * Reconstitute the versioned integration event from its durable row.
     *
     * @param   array<string, mixed>  $row  Durable database row being reconstituted.
     *
     * @return  IntegrationEvent  Versioned integration event reconstituted from the durable row.
     *
     * @since   2.0.0
     */
    private function event(array $row): IntegrationEvent
    {
        $envelope = $row['envelope'] ?? null;
        if (is_string($envelope)) {
            try {
                $envelope = json_decode($envelope, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('An outbox envelope contains invalid JSON.', 0, $exception);
            }
        }
        if (!is_array($envelope) || ($envelope !== [] && array_is_list($envelope))) {
            throw new RuntimeException('An outbox envelope must be a JSON object.');
        }
        /** @var array<string, mixed> $envelope */
        $event = RecordedIntegrationEvent::fromArray($envelope);
        $this->contracts->assertEvent($event);
        return $event;
    }

    /**
     * Read a required non-empty string from the supplied row.
     *
     * @param   array<string, mixed>  $row  Durable database row being reconstituted.
     * @param   string                $key  Array or row key whose value is being read.
     *
     * @return  string  Non-empty string stored under the requested key.
     *
     * @since   2.0.0
     */
    private function requiredString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Outbox field "%s" is invalid.', $key));
        }
        return $value;
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
            throw new RuntimeException(sprintf('Outbox field "%s" is not an integer.', $key));
        }
        return (int) $value;
    }

    /**
     * Return the database-specific row-locking clause.
     *
     * @return  string  Driver-specific SQL suffix used to fence concurrent claims.
     *
     * @since   2.0.0
     */
    private function lockClause(): string
    {
        $platform = $this->database->getDatabasePlatform();
        return $platform instanceof PostgreSQLPlatform || $platform instanceof AbstractMySQLPlatform
            ? ' FOR UPDATE SKIP LOCKED'
            : '';
    }

    /**
     * Return the blocking database-specific row lock used to serialize journal sequence allocation.
     *
     * Unlike work claiming, journal allocation must never skip the locked singleton: waiting ensures
     * source sequence order is also authoritative transaction commit order.
     *
     * @return  string  Driver-specific blocking row-lock suffix.
     *
     * @since   2.0.0
     */
    private function journalLockClause(): string
    {
        $platform = $this->database->getDatabasePlatform();

        return $platform instanceof PostgreSQLPlatform || $platform instanceof AbstractMySQLPlatform
            ? ' FOR UPDATE'
            : '';
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
    private function assertLeaseInput(string $worker, string $generation, int $seconds): void
    {
        if (
            preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/D', $worker) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D', $generation) !== 1
            || $seconds < 5
            || $seconds > 3_600
        ) {
            throw new InvalidArgumentException('The outbox claim lease metadata is invalid.');
        }
    }

    /**
     * Require exactly one row to have been changed by a fenced update.
     *
     * @param   int|string  $affected  Number of rows changed by the fenced statement.
     * @param   string      $message   Safe failure message.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertOne(
        int|string $affected,
        string $message = 'The worker no longer owns the active outbox lease.',
    ): void {
        if ((string) $affected !== '1') {
            throw new RuntimeException($message);
        }
    }
}
