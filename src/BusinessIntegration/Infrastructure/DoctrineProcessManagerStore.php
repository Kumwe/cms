<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Infrastructure;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use JsonException;
use Kumwe\App\Application\Automation\FailureClassification;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\BusinessIntegration\Application\ProcessManagerStore;
use Kumwe\App\BusinessIntegration\Application\ProcessWorkLease;
use Kumwe\App\BusinessIntegration\Domain\ProcessInstance;
use Kumwe\App\BusinessIntegration\Domain\ProcessStatus;
use Kumwe\App\BusinessIntegration\Domain\ProcessWorkItem;
use Kumwe\App\BusinessIntegration\Domain\ProcessWorkKind;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

/**
 * DBAL optimistic process-manager repository with durable fenced timers, commands and compensations.
 *
 * @since  2.0.0
 */
final readonly class DoctrineProcessManagerStore implements ProcessManagerStore
{
    /**
     * Bind process state and work to the shared transaction boundary.
     *
     * @param  Connection          $database      Application connection.
     * @param  TableNames          $tables        Physical table-name compiler.
     * @param  TransactionManager  $transactions  Atomic state/work and settlement boundary.
     * @param  ClockInterface      $clock         Lease and lifecycle clock.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private TransactionManager $transactions,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Persist a new process instance and its initial work.
     *
     * @param   ProcessInstance            $process  Current process instance being read or transitioned.
     * @param   iterable<ProcessWorkItem>  $work     Process work emitted by the transition.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function create(ProcessInstance $process, iterable $work = []): void
    {
        if ($process->version() !== 1) {
            throw new InvalidArgumentException('A new process must start at version one.');
        }
        $this->transactions->transactional(function () use ($process, $work): void {
            $this->database->insert($this->tables->raw('business_process_instances'), $this->processRow($process), [
                'state' => Types::JSON,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'updated_at' => Types::DATETIME_IMMUTABLE,
                'completed_at' => Types::DATETIME_IMMUTABLE,
                'cancelled_at' => Types::DATETIME_IMMUTABLE,
            ]);
            $this->insertWork($process, $work);
        });
    }

    /**
     * Load the requested durable record when it exists.
     *
     * @param   string  $processId  Stable identifier of the process instance.
     *
     * @return  ?ProcessInstance  Requested process instance, or null when it does not exist.
     *
     * @since   2.0.0
     */
    public function load(string $processId): ?ProcessInstance
    {
        if (!Uuid::isValid($processId)) {
            throw new InvalidArgumentException('A process ID must be a UUID.');
        }
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE process_id = ?',
            $this->tables->quoted('business_process_instances'),
        ), [$processId], [Types::GUID]);
        return $row === false ? null : $this->process($row);
    }

    /**
     * Find the process instance matching the supplied correlation key.
     *
     * @param   string  $processType     Stable process-manager type used to scope correlation.
     * @param   string  $siteIdentifier  Owning site that isolates the correlation namespace.
     * @param   string  $correlationId   Stable correlation key joining related process events.
     *
     * @return  ?ProcessInstance  Matching process instance, or null when the correlation is new.
     *
     * @since   2.0.0
     */
    public function findByCorrelation(
        string $processType,
        string $siteIdentifier,
        string $correlationId,
    ): ?ProcessInstance {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE process_type = ? AND site_identifier = ? AND correlation_id = ?',
            $this->tables->quoted('business_process_instances'),
        ), [$processType, $siteIdentifier, $correlationId]);
        return $row === false ? null : $this->process($row);
    }

    /**
     * Persist the supplied state with optimistic concurrency protection.
     *
     * @param   ProcessInstance            $process          Current process instance being read or transitioned.
     * @param   int                        $expectedVersion  Version required for optimistic concurrency.
     * @param   iterable<ProcessWorkItem>  $work             Process work emitted by the transition.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function save(ProcessInstance $process, int $expectedVersion, iterable $work = []): void
    {
        if ($expectedVersion < 1 || $process->version() !== $expectedVersion + 1) {
            throw new InvalidArgumentException('A process save must advance exactly one optimistic version.');
        }
        $this->transactions->transactional(function () use ($process, $expectedVersion, $work): void {
            $row = $this->processRow($process);
            unset($row['process_id'], $row['process_type'], $row['correlation_id'], $row['created_at']);
            $affected = $this->database->update(
                $this->tables->raw('business_process_instances'),
                $row,
                ['process_id' => $process->id(), 'version' => $expectedVersion],
                [
                    'state' => Types::JSON,
                    'updated_at' => Types::DATETIME_IMMUTABLE,
                    'completed_at' => Types::DATETIME_IMMUTABLE,
                    'cancelled_at' => Types::DATETIME_IMMUTABLE,
                ],
            );
            if ((string) $affected !== '1') {
                throw new RuntimeException('The process state changed after it was read.');
            }
            $this->insertWork($process, $work);
        });
    }

    /**
     * Claim the next eligible process work item for the named worker.
     *
     * @param   string  $workerId           Stable identity of the claiming worker.
     * @param   string  $runtimeGeneration  Trusted runtime generation that owns the lease.
     * @param   int     $leaseSeconds       Number of seconds before the worker lease expires.
     *
     * @return  ?ProcessWorkLease  Fenced work lease, or null when no work is eligible.
     *
     * @since   2.0.0
     */
    public function claimWork(string $workerId, string $runtimeGeneration, int $leaseSeconds): ?ProcessWorkLease
    {
        $this->assertClaimInput($workerId, $runtimeGeneration, $leaseSeconds);
        return $this->transactions->transactional(function () use (
            $workerId,
            $runtimeGeneration,
            $leaseSeconds,
        ): ?ProcessWorkLease {
            $now = $this->clock->now();
            $this->buryExhausted($now);
            $row = $this->database->fetchAssociative(sprintf(
                'SELECT w.*, p.site_identifier AS process_site_identifier, '
                . 'p.correlation_id AS process_correlation_id, '
                . 'p.organization_id AS process_organization_id FROM %s w '
                . 'INNER JOIN %s p ON p.process_id = w.process_id '
                . "WHERE (p.status = 'running' OR w.work_kind = 'compensation') "
                . 'AND w.attempts < w.maximum_attempts AND ('
                . "(w.status = 'pending' AND w.due_at <= ?) OR "
                . "(w.status = 'reserved' AND (w.lease_expires_at IS NULL OR w.lease_expires_at <= ?))) "
                . 'ORDER BY w.due_at, w.created_at, w.work_id LIMIT 1%s',
                $this->tables->quoted('business_process_work'),
                $this->tables->quoted('business_process_instances'),
                $this->lockClause(true),
            ), [$now, $now], [Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE]);
            if ($row === false) {
                return null;
            }
            $workId = $this->requiredString($row, 'work_id');
            $token = Uuid::uuid7()->toString();
            $affected = $this->database->executeStatement(sprintf(
                "UPDATE %s SET status = 'reserved', attempts = attempts + 1, lease_owner = ?, lease_token = ?, "
                . 'lease_acquired_at = ?, lease_expires_at = ?, runtime_generation = ?, updated_at = ? '
                . 'WHERE work_id = ? AND attempts < maximum_attempts AND ('
                . "(status = 'pending' AND due_at <= ?) OR "
                . "(status = 'reserved' AND (lease_expires_at IS NULL OR lease_expires_at <= ?)))",
                $this->tables->quoted('business_process_work'),
            ), [
                $workerId, $token, $now, $now->add(new DateInterval(sprintf('PT%dS', $leaseSeconds))),
                $runtimeGeneration, $now, $workId, $now, $now,
            ], [
                Types::STRING, Types::GUID, Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE,
                Types::STRING, Types::DATETIME_IMMUTABLE, Types::GUID,
                Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE,
            ]);
            $this->assertOne($affected);
            return new ProcessWorkLease(
                $this->requiredString($row, 'process_id'),
                $this->integer($row, 'process_version'),
                $this->requiredString($row, 'process_site_identifier'),
                $this->nullableString($row, 'process_organization_id'),
                $this->workItem($row),
                $this->integer($row, 'attempts') + 1,
                $workerId,
                $token,
                $runtimeGeneration,
                $this->nullableString($row, 'process_correlation_id'),
            );
        });
    }

    /**
     * Renew the supplied process-work lease.
     *
     * @param   ProcessWorkLease  $lease         Fenced lease proving ownership of the durable item.
     * @param   int               $leaseSeconds  Number of seconds before the worker lease expires.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function renewWork(ProcessWorkLease $lease, int $leaseSeconds): void
    {
        if ($leaseSeconds < 5 || $leaseSeconds > 3_600) {
            throw new InvalidArgumentException('A process work lease must last between 5 and 3600 seconds.');
        }
        $now = $this->clock->now();
        $this->assertOne($this->database->executeStatement(sprintf(
            'UPDATE %s SET lease_expires_at = ?, updated_at = ? WHERE work_id = ? '
            . "AND status = 'reserved' AND lease_owner = ? AND lease_token = ? "
            . 'AND runtime_generation = ? AND lease_expires_at > ?',
            $this->tables->quoted('business_process_work'),
        ), [
            $now->add(new DateInterval(sprintf('PT%dS', $leaseSeconds))), $now, $lease->work->id(),
            $lease->workerId, $lease->leaseToken, $lease->runtimeGeneration, $now,
        ], [
            Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::GUID, Types::STRING,
            Types::GUID, Types::STRING, Types::DATETIME_IMMUTABLE,
        ]));
    }

    /**
     * Mark the supplied process-work lease complete.
     *
     * @param   ProcessWorkLease  $lease  Fenced lease proving ownership of the durable item.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function completeWork(ProcessWorkLease $lease): void
    {
        $now = $this->clock->now();
        $this->assertOne($this->database->executeStatement(sprintf(
            "UPDATE %s SET status = 'completed', lease_owner = NULL, lease_token = NULL, "
            . 'lease_acquired_at = NULL, lease_expires_at = NULL, runtime_generation = NULL, '
            . 'completed_at = ?, updated_at = ? WHERE work_id = ? '
            . "AND status = 'reserved' AND lease_owner = ? AND lease_token = ? "
            . 'AND runtime_generation = ? AND lease_expires_at > ?',
            $this->tables->quoted('business_process_work'),
        ), [
            $now, $now, $lease->work->id(), $lease->workerId, $lease->leaseToken,
            $lease->runtimeGeneration, $now,
        ], [
            Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::GUID, Types::STRING,
            Types::GUID, Types::STRING, Types::DATETIME_IMMUTABLE,
        ]));
    }

    /**
     * Record failed process work and its retry decision.
     *
     * @param   ProcessWorkLease       $lease           Fenced lease proving ownership of the durable item.
     * @param   FailureClassification  $classification  Failure class controlling retry or quarantine behavior.
     * @param   Throwable              $failure         Failure whose retry classification is being recorded.
     * @param   ?DateTimeImmutable     $retryAt         Next eligible attempt timestamp, or null for quarantine.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function failWork(
        ProcessWorkLease $lease,
        FailureClassification $classification,
        Throwable $failure,
        ?DateTimeImmutable $retryAt,
    ): void {
        $retry = $classification === FailureClassification::TRANSIENT
            && $retryAt !== null
            && $lease->attempts < $lease->work->maximumAttempts();
        $now = $this->clock->now();
        $this->assertOne($this->database->executeStatement(sprintf(
            'UPDATE %s SET status = ?, due_at = ?, lease_owner = NULL, lease_token = NULL, '
            . 'lease_acquired_at = NULL, lease_expires_at = NULL, runtime_generation = NULL, '
            . 'failure_classification = ?, exception_type = ?, error_message = ?, updated_at = ? '
            . "WHERE work_id = ? AND status = 'reserved' AND lease_owner = ? AND lease_token = ? "
            . 'AND runtime_generation = ? AND lease_expires_at > ?',
            $this->tables->quoted('business_process_work'),
        ), [
            $retry ? 'pending' : 'dead', $retry && $retryAt > $now ? $retryAt : $now,
            $classification->value, $failure::class, substr($failure->getMessage(), 0, 4_000), $now,
            $lease->work->id(), $lease->workerId, $lease->leaseToken, $lease->runtimeGeneration, $now,
        ], [
            Types::STRING, Types::DATETIME_IMMUTABLE, Types::STRING, Types::STRING, Types::STRING,
            Types::DATETIME_IMMUTABLE, Types::GUID, Types::STRING, Types::GUID, Types::STRING,
            Types::DATETIME_IMMUTABLE,
        ]));
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
        $this->assertLimit($limit);
        return $this->database->fetchAllAssociative(sprintf(
            'SELECT process_id, process_type, correlation_id, site_identifier, organization_id, version, '
            . 'status, state, cancellation_by, cancellation_note, created_at, updated_at, completed_at, '
            . 'cancelled_at FROM %s ORDER BY updated_at DESC, process_id DESC LIMIT ?',
            $this->tables->quoted('business_process_instances'),
        ), [$limit], [Types::INTEGER]);
    }

    /**
     * Return operator-visible work for the requested process.
     *
     * @param   string  $processId  Stable identifier of the process instance.
     * @param   int     $limit      Maximum number of records the operation may return or change.
     *
     * @return  list<array<string, mixed>>  Operator-visible rows in deterministic order.
     *
     * @since   2.0.0
     */
    public function work(string $processId, int $limit = 100): array
    {
        if (!Uuid::isValid($processId)) {
            throw new InvalidArgumentException('A process ID must be a UUID.');
        }
        $this->assertLimit($limit);
        return $this->database->fetchAllAssociative(sprintf(
            'SELECT work_id, process_id, process_version, work_kind, work_name, payload, due_at, status, '
            . 'attempts, maximum_attempts, lease_owner, runtime_generation, failure_classification, '
            . 'exception_type, error_message, completed_at, created_at, updated_at FROM %s '
            . 'WHERE process_id = ? ORDER BY created_at, work_id LIMIT ?',
            $this->tables->quoted('business_process_work'),
        ), [$processId, $limit], [Types::GUID, Types::INTEGER]);
    }

    /**
     * Serialize a process instance into persistence columns.
     *
     * @param   ProcessInstance  $process  Current process instance being read or transitioned.
     *
     * @return  array<string, mixed>
     *
     * @since   2.0.0
     */
    private function processRow(ProcessInstance $process): array
    {
        return [
            'process_id' => $process->id(),
            'process_type' => $process->processType(),
            'correlation_id' => $process->correlationId(),
            'site_identifier' => $process->siteIdentifier(),
            'organization_id' => $process->organizationId(),
            'actor_id' => $process->actorId(),
            'system_identity' => $process->systemIdentity(),
            'version' => $process->version(),
            'status' => $process->status()->value,
            'state' => $process->state(),
            'cancellation_by' => $process->cancellationBy(),
            'cancellation_note' => $process->cancellationNote(),
            'created_at' => $process->createdAt(),
            'updated_at' => $process->updatedAt(),
            'completed_at' => $process->status() === ProcessStatus::COMPLETED ? $process->updatedAt() : null,
            'cancelled_at' => $process->status() === ProcessStatus::CANCELLED ? $process->updatedAt() : null,
        ];
    }

    /**
     * Persist all pending work for the supplied process transition.
     *
     * @param   ProcessInstance            $process  Current process instance being read or transitioned.
     * @param   iterable<ProcessWorkItem>  $work     Process work emitted by the transition.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function insertWork(ProcessInstance $process, iterable $work): void
    {
        foreach ($work as $item) {
            if ($process->status() === ProcessStatus::CANCELLED && $item->kind() !== ProcessWorkKind::COMPENSATION) {
                throw new InvalidArgumentException('A cancelled process may emit only compensation work.');
            }
            $now = $this->clock->now();
            $this->database->insert($this->tables->raw('business_process_work'), [
                'work_id' => $item->id(),
                'process_id' => $process->id(),
                'process_version' => $process->version(),
                'work_kind' => $item->kind()->value,
                'work_name' => $item->name(),
                'payload' => $item->payload(),
                'due_at' => $item->dueAt(),
                'status' => 'pending',
                'attempts' => 0,
                'maximum_attempts' => $item->maximumAttempts(),
                'lease_owner' => null,
                'lease_token' => null,
                'lease_acquired_at' => null,
                'lease_expires_at' => null,
                'runtime_generation' => null,
                'failure_classification' => null,
                'exception_type' => null,
                'error_message' => null,
                'completed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ], [
                'payload' => Types::JSON,
                'due_at' => Types::DATETIME_IMMUTABLE,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'updated_at' => Types::DATETIME_IMMUTABLE,
            ]);
        }
    }

    /**
     * Reconstitute a process instance from its durable row.
     *
     * @param   array<string, mixed>  $row  Durable database row being reconstituted.
     *
     * @return  ProcessInstance  Process instance reconstituted from the durable row.
     *
     * @since   2.0.0
     */
    private function process(array $row): ProcessInstance
    {
        $state = $this->jsonObject($row, 'state');
        $status = ProcessStatus::tryFrom($this->requiredString($row, 'status'))
            ?? throw new RuntimeException('A stored process status is invalid.');
        return new ProcessInstance(
            $this->requiredString($row, 'process_id'),
            $this->requiredString($row, 'process_type'),
            $this->requiredString($row, 'correlation_id'),
            $this->requiredString($row, 'site_identifier'),
            $this->nullableString($row, 'organization_id'),
            $this->nullableString($row, 'actor_id'),
            $this->nullableString($row, 'system_identity'),
            $this->integer($row, 'version'),
            $status,
            $state,
            $this->date($row, 'created_at'),
            $this->date($row, 'updated_at'),
            $this->nullableString($row, 'cancellation_by'),
            $this->nullableString($row, 'cancellation_note'),
        );
    }

    /**
     * Reconstitute a process work item from its durable row.
     *
     * @param   array<string, mixed>  $row  Durable database row being reconstituted.
     *
     * @return  ProcessWorkItem  Process work item reconstituted from the durable row.
     *
     * @since   2.0.0
     */
    private function workItem(array $row): ProcessWorkItem
    {
        $kind = ProcessWorkKind::tryFrom($this->requiredString($row, 'work_kind'))
            ?? throw new RuntimeException('A stored process work kind is invalid.');
        return new ProcessWorkItem(
            $this->requiredString($row, 'work_id'),
            $kind,
            $this->requiredString($row, 'work_name'),
            $this->jsonObject($row, 'payload'),
            $this->date($row, 'due_at'),
            $this->integer($row, 'maximum_attempts'),
        );
    }

    /**
     * Decode an object-shaped JSON value from the supplied row.
     *
     * @param   array<string, mixed>  $row  Durable database row being reconstituted.
     * @param   string                $key  Array or row key whose value is being read.
     *
     * @return  array<string, mixed>
     *
     * @since   2.0.0
     */
    private function jsonObject(array $row, string $key): array
    {
        $value = $row[$key] ?? null;
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException(sprintf('Process field "%s" contains invalid JSON.', $key), 0, $exception);
            }
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new RuntimeException(sprintf('Process field "%s" must be a JSON object.', $key));
        }
        /** @var array<string, mixed> $value */
        return $value;
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
            throw new RuntimeException(sprintf('Process field "%s" is invalid.', $key));
        }
        return $value;
    }

    /**
     * Read an optional string from the supplied data.
     *
     * @param   array<string, mixed>  $row  Durable database row being reconstituted.
     * @param   string                $key  Array or row key whose value is being read.
     *
     * @return  ?string  String stored under the key, or null when the member is absent.
     *
     * @since   2.0.0
     */
    private function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new RuntimeException(sprintf('Process field "%s" is invalid.', $key));
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
            throw new RuntimeException(sprintf('Process field "%s" is not an integer.', $key));
        }
        return (int) $value;
    }

    /**
     * Read an immutable timestamp from the supplied row.
     *
     * @param   array<string, mixed>  $row  Durable database row being reconstituted.
     * @param   string                $key  Array or row key whose value is being read.
     *
     * @return  DateTimeImmutable  Timestamp stored under the requested key.
     *
     * @since   2.0.0
     */
    private function date(array $row, string $key): DateTimeImmutable
    {
        $value = $row[$key] ?? null;
        if ($value instanceof \DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (is_string($value) && $value !== '') {
            return new DateTimeImmutable($value);
        }
        throw new RuntimeException(sprintf('Process field "%s" is not a date.', $key));
    }

    /**
     * Quarantine eligible records that exhausted their attempt budget.
     *
     * @param   DateTimeImmutable  $now  Authoritative timestamp for the state transition.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function buryExhausted(DateTimeImmutable $now): void
    {
        $this->database->executeStatement(sprintf(
            "UPDATE %s SET status = 'dead', lease_owner = NULL, lease_token = NULL, lease_acquired_at = NULL, "
            . 'lease_expires_at = NULL, runtime_generation = NULL, failure_classification = ?, '
            . 'exception_type = ?, error_message = ?, updated_at = ? WHERE attempts >= maximum_attempts AND ('
            . "status = 'pending' OR (status = 'reserved' AND (lease_expires_at IS NULL OR lease_expires_at <= ?)))",
            $this->tables->quoted('business_process_work'),
        ), [
            FailureClassification::TRANSIENT->value,
            self::class . '\\ExpiredProcessWorkLease',
            'The final process work lease expired before completion.',
            $now,
            $now,
        ], [Types::STRING, Types::STRING, Types::STRING, Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE]);
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
            throw new InvalidArgumentException('The process work claim metadata is invalid.');
        }
    }

    /**
     * Require an operator query limit within the supported bounds.
     *
     * @param   int  $limit  Maximum number of records the operation may return or change.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 1_000) {
            throw new InvalidArgumentException('A process query limit must be between 1 and 1000.');
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
            throw new RuntimeException('The worker no longer owns the active process work lease.');
        }
    }
}
