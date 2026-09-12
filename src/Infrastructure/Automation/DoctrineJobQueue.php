<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Automation;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use JsonException;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\App\Application\Automation\ExpiredJobLease;
use Kumwe\App\Application\Automation\JobExecutionClass;
use Kumwe\App\Application\Automation\JobQueue;
use Kumwe\App\Application\Automation\JobExecutionScope;
use Kumwe\App\Application\Automation\QueueRuntimePolicy;
use Kumwe\App\Application\Automation\QueueRuntimePolicyCatalog;
use Kumwe\App\Application\Automation\StoredJob;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

/**
 * Doctrine-backed job queue that hands each job to exactly one worker under a fenced, expiring lease.
 *
 * This is the `JobQueue` the runtime wires, and it keeps the whole queue in the `jobs` and `failed_jobs`
 * tables rather than in a broker. A claim takes one row with `FOR UPDATE SKIP LOCKED`, so any number of
 * workers may poll the same queue without blocking each other, and stamps a fresh UUID token onto it.
 * Every later write matches on that token as well as the identifier, so a worker whose lease expired
 * while a sibling re-claimed the row changes nothing instead of overwriting the new holder's outcome —
 * which is what makes an expired lease safe to reap. Rows that have used their attempt budget are
 * dead-lettered as the claim scan passes them, up to `EXHAUSTED_REAP_LIMIT` per call, so an exhausted
 * backlog is cleared by the workers themselves rather than by a separate sweeper.
 *
 * A contributed queue additionally locks one `job_queue_runtime` row before it counts live reservations
 * and selects work. That shared lock makes the signed in-flight ceiling durable across processes. Its
 * lease and attempt limits are resolved from the same active trusted catalog; undeclared core queues
 * skip that layer and retain their original behavior.
 *
 * A site-local row is only claimable through the ownership table joined to an enabled site, so a
 * disabled or retired site quietly stops yielding work instead of offering jobs nothing could run. Every
 * entry point checks a capability before it touches a row: `automation.manage` for producers and
 * operators, `system.worker.operate` for the worker loop, and `all()` filters row by row rather than
 * refusing the caller outright.
 *
 * @since  2.0.0
 */
final readonly class DoctrineJobQueue implements JobQueue
{
    /**
     * Most attempt-exhausted rows a single `claim()` call will dead-letter before giving up on that pass.
     *
     * Reaping happens inline on the claim path, so this bounds how long one poll may spend clearing dead
     * work before it returns empty-handed and lets the worker come back around.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int EXHAUSTED_REAP_LIMIT = 100;

    /**
     * Wire the queue to its connection, clock and authorization collaborators.
     *
     * @param  Connection                   $database       Connection the queue, heartbeat and site tables live on.
     * @param  TableNames                   $tables         Resolver for prefixed physical table names.
     * @param  TransactionManager           $transactions   Runs a claim, enqueue or failure as one unit.
     * @param  ClockInterface               $clock          Supplies the instant leases and stamps are taken from.
     * @param  string                       $release        Application release recorded on each heartbeat row.
     * @param  AuthorizationGateway         $authorization  Decides whether a caller may produce, run or manage.
     * @param  ResourceSiteOwnershipWriter  $ownership      Records the owning site of each site-local job.
     * @param  JobExecutionScope            $jobScope       Classifies a job type as installation-wide or site-local.
     * @param  ?QueueRuntimePolicyCatalog   $policies       Active contributed queue and job limits; null preserves
     *         the established behavior for isolated core queue instances.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private TransactionManager $transactions,
        private ClockInterface $clock,
        private string $release,
        private AuthorizationGateway $authorization,
        private ResourceSiteOwnershipWriter $ownership,
        private JobExecutionScope $jobScope,
        private ?QueueRuntimePolicyCatalog $policies = null,
    ) {
    }

    /**
     * Store a new job as pending and return the identifier operators address it by.
     *
     * The insert and, for a site-local type, the ownership row binding the job to the caller's site share
     * one transaction, so a job never becomes claimable without a recorded owner. An `$availableAt` that
     * has already passed is clamped up to now rather than refused, so a producer may hand over a deadline
     * it missed and get an immediately runnable job.
     *
     * @param   ExecutionContext      $context          Actor and site the job is produced under.
     * @param   string                $type             Registered job type deciding which handler runs it.
     * @param   array<string, mixed>  $payload          Handler arguments, stored as a JSON object.
     * @param   DateTimeImmutable     $availableAt      Earliest claimable instant; clamped up to now if past.
     * @param   string                $queue            Name of the queue workers poll for this job.
     * @param   int                   $priority         Claim-order weight from -100 to 100; higher goes first.
     * @param   int                   $maximumAttempts  Claims allowed, 1 to 100, before the job is buried.
     *
     * @return  string  UUID version 7 of the stored row, as passed to `retry()` and `cancel()`.
     *
     * @throws  InvalidArgumentException  When the queue name or job type breaks its naming rule, or the
     *          priority or attempt budget falls outside the accepted range.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the caller may not manage
     *          automation on this queue, or may not enqueue this installation-global job type.
     *
     * @since   2.0.0
     */
    public function enqueue(
        ExecutionContext $context,
        string $type,
        array $payload,
        DateTimeImmutable $availableAt,
        string $queue = 'default',
        int $priority = 0,
        int $maximumAttempts = 5,
    ): string {
        $this->authorize($context, AuthorizationResource::item('queue', $queue));
        $this->assertQueue($queue);
        $this->assertType($type);
        $this->authorizeJobType($context, $type);
        $executionClass = $this->jobScope->executionClass($type);
        if ($priority < -100 || $priority > 100 || $maximumAttempts < 1 || $maximumAttempts > 100) {
            throw new InvalidArgumentException('Job priority or maximum attempts are outside the supported range.');
        }
        $maximumAttempts = $this->policies?->maximumAttempts($queue, $type, $maximumAttempts)
            ?? $maximumAttempts;

        $id = Uuid::uuid7()->toString();
        $now = $this->clock->now();
        $this->transactions->transactional(function () use (
            $context,
            $id,
            $queue,
            $type,
            $payload,
            $executionClass,
            $priority,
            $maximumAttempts,
            $availableAt,
            $now,
        ): void {
            $this->database->insert($this->tables->raw('jobs'), [
                'id' => $id,
                'queue' => $queue,
                'job_type' => $type,
                'execution_scope' => $executionClass->value,
                'schema_version' => 1,
                'payload' => $payload,
                'priority' => $priority,
                'status' => 'pending',
                'available_at' => $availableAt < $now ? $now : $availableAt,
                'lease_owner' => null,
                'lease_token' => null,
                'lease_acquired_at' => null,
                'lease_expires_at' => null,
                'attempts' => 0,
                'maximum_attempts' => $maximumAttempts,
                'schedule_id' => null,
                'scheduled_for' => null,
                'occurrence_key' => null,
                'completed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ], [
                'payload' => Types::JSON,
                'available_at' => Types::DATETIME_IMMUTABLE,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'updated_at' => Types::DATETIME_IMMUTABLE,
            ]);
            if ($executionClass === JobExecutionClass::Site) {
                $this->ownership->record(AuthorizationResource::item('job', $id), $context->site());
            }
        });

        return $id;
    }

    /**
     * Reserve the next runnable job on a queue for this worker under a fenced, expiring lease.
     *
     * The row is taken with `FOR UPDATE SKIP LOCKED` and reserved with a freshly minted UUID token, so
     * concurrent workers take different rows and the token stamped here is the only proof that later
     * settles the job. Both pending rows whose availability has arrived and reserved rows whose lease has
     * lapsed are eligible, which is how work abandoned by a dead worker comes back. Rows that have
     * already spent their attempt budget are dead-lettered instead of claimed and the scan moves on,
     * giving up after `EXHAUSTED_REAP_LIMIT` of them so one poll cannot run indefinitely.
     *
     * A site-local row additionally needs a live enabled owning site, whose ownership row is locked for
     * the rest of the transaction, so a site disabled mid-claim cannot have its work picked up.
     *
     * @param   ExecutionContext  $context       Actor and site the worker runs as.
     * @param   string            $queue         Name of the queue to take work from.
     * @param   string            $workerId      Identity recorded as the holder of the lease.
     * @param   int               $leaseSeconds  Lease length, 5 to 3600 seconds, measured from now.
     *
     * @return  ?StoredJob  The reserved job carrying its fencing token, or null when the queue holds
     *          nothing runnable, the owning site is unusable, or the reap limit was reached first.
     *
     * @throws  InvalidArgumentException  When the queue name, worker identity or lease length is invalid.
     * @throws  RuntimeException  When a claimed row is malformed, its payload is not a JSON object, or
     *          another worker took the reservation between the read and the write.
     * @throws  JsonException  When a row being dead-lettered stores a payload that is not decodable JSON.
     * @throws  \LogicException  When a row's stored execution scope disagrees with its job type.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the caller may not operate
     *          workers on this queue.
     *
     * @since   2.0.0
     */
    public function claim(
        ExecutionContext $context,
        string $queue,
        string $workerId,
        int $leaseSeconds,
    ): ?StoredJob {
        $this->authorizeWorker($context, AuthorizationResource::item('queue', $queue));
        $this->assertQueue($queue);
        $this->assertWorker($workerId);
        if ($leaseSeconds < 5 || $leaseSeconds > 3_600) {
            throw new InvalidArgumentException('A job lease must last between 5 and 3600 seconds.');
        }
        $policy = $this->policies?->policy($queue);
        if ($policy !== null && $leaseSeconds > $policy->leaseSeconds) {
            throw new InvalidArgumentException('A contributed queue lease cannot exceed its signed policy.');
        }
        if ($policy !== null) {
            $this->ensureQueueRuntime($policy);
        }

        return $this->transactions->transactional(function () use (
            $queue,
            $workerId,
            $leaseSeconds,
            $policy,
        ): ?StoredJob {
            $now = $this->clock->now();
            if ($policy !== null && !$this->claimPolicySlot($policy, $now)) {
                return null;
            }
            $reaped = 0;
            $jobOwnershipId = $this->database->getDatabasePlatform() instanceof PostgreSQLPlatform
                ? 'CAST(j.id AS VARCHAR)'
                : 'j.id';

            while ($reaped < self::EXHAUSTED_REAP_LIMIT) {
                $row = $this->database->fetchAssociative(sprintf(
                    'SELECT j.* FROM %s j WHERE j.queue = ? AND (j.execution_scope = ? OR '
                    . '(j.execution_scope = ? AND EXISTS (SELECT 1 FROM %s o INNER JOIN %s s '
                    . 'ON s.identifier = o.site_identifier WHERE o.resource_type = ? '
                    . 'AND o.resource_id = %s AND s.enabled = ?))) AND ('
                    . "(j.status = 'pending' AND j.available_at <= ?) OR "
                    . "(j.status = 'reserved' AND (j.lease_expires_at IS NULL OR j.lease_expires_at <= ?))"
                    . ') ORDER BY j.priority DESC, j.available_at, j.created_at, j.id '
                    . 'LIMIT 1 FOR UPDATE SKIP LOCKED',
                    $this->tables->quoted('jobs'),
                    $this->tables->quoted('resource_site_ownership'),
                    $this->tables->quoted('sites'),
                    $jobOwnershipId,
                ), [
                    $queue,
                    JobExecutionClass::Installation->value,
                    JobExecutionClass::Site->value,
                    'job',
                    true,
                    $now,
                    $now,
                ], [
                    Types::STRING,
                    Types::STRING,
                    Types::STRING,
                    Types::STRING,
                    Types::BOOLEAN,
                    Types::DATETIME_IMMUTABLE,
                    Types::DATETIME_IMMUTABLE,
                ]);

                if ($row === false || !is_string($row['id'] ?? null)) {
                    return null;
                }

                $executionClass = $this->jobScope->assertStoredClass(
                    $this->requiredString($row, 'job_type'),
                    $this->requiredString($row, 'execution_scope'),
                );
                if (
                    $executionClass === JobExecutionClass::Site
                    && !$this->lockEnabledOwner($this->requiredString($row, 'id'))
                ) {
                    return null;
                }

                $attempts = $this->integer($row, 'attempts');
                $storedMaximum = $this->integer($row, 'maximum_attempts');
                $effectiveMaximum = $this->policies?->maximumAttempts(
                    $queue,
                    $this->requiredString($row, 'job_type'),
                    $storedMaximum,
                ) ?? $storedMaximum;
                if ($effectiveMaximum !== $storedMaximum) {
                    $this->database->update(
                        $this->tables->raw('jobs'),
                        ['maximum_attempts' => $effectiveMaximum, 'updated_at' => $now],
                        ['id' => $row['id']],
                        ['maximum_attempts' => Types::SMALLINT, 'updated_at' => Types::DATETIME_IMMUTABLE],
                    );
                    $row['maximum_attempts'] = $effectiveMaximum;
                }
                if ($attempts >= $effectiveMaximum) {
                    $this->deadLetterExpired($row, $now);
                    $reaped++;
                    continue;
                }

                $token = Uuid::uuid7()->toString();
                $affected = $this->database->executeStatement(sprintf(
                    "UPDATE %s SET status = 'reserved', lease_owner = ?, lease_token = ?, lease_acquired_at = ?, "
                    . 'lease_expires_at = ?, attempts = attempts + 1, updated_at = ? WHERE id = ? AND ('
                    . "(status = 'pending' AND available_at <= ?) OR "
                    . "(status = 'reserved' AND (lease_expires_at IS NULL OR lease_expires_at <= ?)))",
                    $this->tables->quoted('jobs'),
                ), [
                    $workerId,
                    $token,
                    $now,
                    $now->add(new DateInterval(sprintf('PT%dS', $leaseSeconds))),
                    $now,
                    $row['id'],
                    $now,
                    $now,
                ], [
                    Types::STRING, Types::STRING, Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE,
                    Types::DATETIME_IMMUTABLE, Types::GUID, Types::DATETIME_IMMUTABLE,
                    Types::DATETIME_IMMUTABLE,
                ]);
                $this->assertLeaseUpdated($affected);
                $row['attempts'] = $attempts + 1;
                $row['lease_token'] = $token;
                if ($policy !== null) {
                    $this->database->update(
                        $this->tables->raw('job_queue_runtime'),
                        ['last_claimed_at' => $now, 'updated_at' => $now],
                        ['queue_id' => $queue],
                        [
                            'last_claimed_at' => Types::DATETIME_IMMUTABLE,
                            'updated_at' => Types::DATETIME_IMMUTABLE,
                        ],
                    );
                }

                return $this->map($row);
            }

            return null;
        });
    }

    /**
     * Push the expiry of a lease this worker still holds further out, keeping its token.
     *
     * The update matches on the worker, the token and an unexpired lease, so a handler renewing at safe
     * checkpoints stays out of reach of the reaper, while a worker that has already lost the row is told
     * so rather than silently reviving a claim another worker now owns.
     *
     * @param   ExecutionContext  $context       Actor and site the worker runs as.
     * @param   StoredJob         $job           Job whose lease is extended; carries the token proving it.
     * @param   string            $workerId      Identity that must still hold the lease.
     * @param   int               $leaseSeconds  Length of the new window, 5 to 3600 seconds, from now.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the worker identity or lease length is invalid.
     * @throws  RuntimeException  When the worker no longer holds an unexpired lease on this job.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the caller may not operate
     *          workers on this job's queue.
     *
     * @since   2.0.0
     */
    public function renew(
        ExecutionContext $context,
        StoredJob $job,
        string $workerId,
        int $leaseSeconds,
    ): void {
        $this->authorizeWorker($context, AuthorizationResource::item('queue', $job->queue));
        $this->assertWorker($workerId);
        if ($leaseSeconds < 5 || $leaseSeconds > 3_600) {
            throw new InvalidArgumentException('A job lease must last between 5 and 3600 seconds.');
        }
        $policy = $this->policies?->policy($job->queue);
        if ($policy !== null && $leaseSeconds > $policy->leaseSeconds) {
            throw new InvalidArgumentException('A contributed queue lease cannot exceed its signed policy.');
        }

        $now = $this->clock->now();
        $this->assertLeaseUpdated($this->database->executeStatement(sprintf(
            'UPDATE %s SET lease_expires_at = ?, updated_at = ? WHERE id = ? '
            . "AND status = 'reserved' AND lease_owner = ? AND lease_token = ? AND lease_expires_at > ?",
            $this->tables->quoted('jobs'),
        ), [
            $now->add(new DateInterval(sprintf('PT%dS', $leaseSeconds))),
            $now,
            $job->id,
            $workerId,
            $job->leaseToken,
            $now,
        ], [
            Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::GUID,
            Types::STRING, Types::STRING, Types::DATETIME_IMMUTABLE,
        ]));
    }

    /**
     * Mark the job completed and clear its lease, taking it out of circulation for good.
     *
     * The write is fenced on the worker's token, so a worker that lost the lease mid-handler cannot
     * report success over a run that a sibling has since taken on.
     *
     * @param   ExecutionContext  $context   Actor and site the worker runs as.
     * @param   StoredJob         $job       Job that finished; carries the token proving the lease.
     * @param   string            $workerId  Identity that must still hold the lease.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the worker identity is invalid.
     * @throws  RuntimeException  When the worker no longer holds an unexpired lease on this job.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the caller may not operate
     *          workers on this job's queue.
     *
     * @since   2.0.0
     */
    public function complete(ExecutionContext $context, StoredJob $job, string $workerId): void
    {
        $this->authorizeWorker($context, AuthorizationResource::item('queue', $job->queue));
        $this->assertWorker($workerId);
        $now = $this->clock->now();
        $this->assertLeaseUpdated($this->database->executeStatement(sprintf(
            "UPDATE %s SET status = 'completed', lease_owner = NULL, lease_token = NULL, lease_acquired_at = NULL, "
            . 'lease_expires_at = NULL, completed_at = ?, updated_at = ? '
            . "WHERE id = ? AND status = 'reserved' AND lease_owner = ? AND lease_token = ? AND lease_expires_at > ?",
            $this->tables->quoted('jobs'),
        ), [$now, $now, $job->id, $workerId, $job->leaseToken, $now], [
            Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::GUID, Types::STRING,
            Types::STRING, Types::DATETIME_IMMUTABLE,
        ]));
    }

    /**
     * Record a failed attempt, either scheduling a retry or burying the job in `failed_jobs`.
     *
     * A job with attempts left returns to pending after a backoff of two to the power of its attempt
     * count in seconds, which the exponent cap holds to 2048 seconds. A permanent failure, or one that
     * spent the last attempt, moves the row to dead and writes a `failed_jobs` record naming the
     * exception class and the leading 4000 bytes of its message; that status change and the record share
     * one transaction, so an operator never sees one without the other. Either branch is fenced on the
     * worker's token, so a worker that lost the lease records nothing.
     *
     * @param   ExecutionContext  $context    Actor and site the worker runs as.
     * @param   StoredJob         $job        Job whose attempt failed; carries the token proving the lease.
     * @param   string            $workerId   Identity that must still hold the lease.
     * @param   Throwable         $failure    Value that ended the attempt, kept for the operator record.
     * @param   bool              $permanent  True to bury the job now, whatever attempts remain.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the worker identity is invalid.
     * @throws  RuntimeException  When the worker no longer holds an unexpired lease on this job.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the caller may not operate
     *          workers on this job's queue.
     *
     * @since   2.0.0
     */
    public function fail(
        ExecutionContext $context,
        StoredJob $job,
        string $workerId,
        Throwable $failure,
        bool $permanent,
    ): void {
        $this->authorizeWorker($context, AuthorizationResource::item('queue', $job->queue));
        $this->assertWorker($workerId);
        $dead = $permanent || $job->attempts >= $job->maximumAttempts;
        $this->transactions->transactional(function () use ($job, $workerId, $failure, $permanent, $dead): void {
            $now = $this->clock->now();
            if (!$dead) {
                $delay = min(3_600, 2 ** min($job->attempts, 11));
                $this->assertLeaseUpdated($this->database->executeStatement(sprintf(
                    "UPDATE %s SET status = 'pending', lease_owner = NULL, lease_token = NULL, "
                    . 'lease_acquired_at = NULL, '
                    . 'lease_expires_at = NULL, available_at = ?, updated_at = ? '
                    . "WHERE id = ? AND status = 'reserved' AND lease_owner = ? "
                    . 'AND lease_token = ? AND lease_expires_at > ?',
                    $this->tables->quoted('jobs'),
                ), [
                    $now->add(new DateInterval(sprintf('PT%dS', $delay))), $now, $job->id,
                    $workerId, $job->leaseToken, $now,
                ], [
                    Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::GUID, Types::STRING,
                    Types::STRING, Types::DATETIME_IMMUTABLE,
                ]));
                return;
            }

            $this->assertLeaseUpdated($this->database->executeStatement(sprintf(
                "UPDATE %s SET status = 'dead', lease_owner = NULL, lease_token = NULL, lease_acquired_at = NULL, "
                . "lease_expires_at = NULL, updated_at = ? WHERE id = ? AND status = 'reserved' AND lease_owner = ? "
                . 'AND lease_token = ? AND lease_expires_at > ?',
                $this->tables->quoted('jobs'),
            ), [$now, $job->id, $workerId, $job->leaseToken, $now], [
                Types::DATETIME_IMMUTABLE, Types::GUID, Types::STRING, Types::STRING,
                Types::DATETIME_IMMUTABLE,
            ]));
            $this->database->insert($this->tables->raw('failed_jobs'), [
                'id' => Uuid::uuid7()->toString(),
                'job_id' => $job->id,
                'queue' => $job->queue,
                'job_type' => $job->type,
                'schema_version' => $job->schemaVersion,
                'payload' => $job->payload,
                'attempts' => $job->attempts,
                'maximum_attempts' => $job->maximumAttempts,
                'failure_classification' => $permanent ? 'permanent' : 'transient',
                'exception_type' => $failure::class,
                'error_message' => substr($failure->getMessage(), 0, 4_000),
                'failed_at' => $now,
                'created_at' => $now,
            ], [
                'payload' => Types::JSON,
                'failed_at' => Types::DATETIME_IMMUTABLE,
                'created_at' => Types::DATETIME_IMMUTABLE,
            ]);
        });
    }

    /**
     * Record that a worker is alive on a queue, and which job it currently holds.
     *
     * The row is inserted on the worker's first report and updated on every one after, so the table
     * holds one current row per worker rather than a history. The operating-system process id and the
     * configured application release are stamped alongside, which is what lets an operator tell a worker
     * left over from an earlier deployment from one running the release now installed.
     *
     * @param   ExecutionContext  $context   Actor and site the worker runs as.
     * @param   string            $workerId  Identity of the worker reporting in.
     * @param   string            $queue     Queue the worker is polling.
     * @param   ?string           $jobId     Job in flight, or null while the worker holds none.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the worker identity or queue name is invalid.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the caller may not operate
     *          workers on this queue.
     *
     * @since   2.0.0
     */
    public function heartbeat(
        ExecutionContext $context,
        string $workerId,
        string $queue,
        ?string $jobId = null,
    ): void {
        $this->authorizeWorker($context, AuthorizationResource::item('queue', $queue));
        $this->assertWorker($workerId);
        $this->assertQueue($queue);
        $now = $this->clock->now();
        $exists = $this->database->fetchOne(sprintf(
            'SELECT worker_id FROM %s WHERE worker_id = ?',
            $this->tables->quoted('worker_heartbeats'),
        ), [$workerId]);
        $processId = getmypid();
        $values = [
            'queue' => $queue,
            'process_id' => $processId === false ? 1 : $processId,
            'application_release' => $this->release,
            'heartbeat_at' => $now,
            'current_job_id' => $jobId,
        ];
        if ($exists === false) {
            $this->database->insert($this->tables->raw('worker_heartbeats'), [
                'worker_id' => $workerId,
                'started_at' => $now,
            ] + $values, ['started_at' => Types::DATETIME_IMMUTABLE, 'heartbeat_at' => Types::DATETIME_IMMUTABLE]);
            return;
        }
        $this->database->update(
            $this->tables->raw('worker_heartbeats'),
            $values,
            ['worker_id' => $workerId],
            ['heartbeat_at' => Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * Delete a worker's liveness record when it stops polling a queue.
     *
     * Jobs the worker still holds are left alone: their leases simply expire and are reaped on a later
     * claim, so a worker shutting down never has to settle work it did not finish.
     *
     * @param   ExecutionContext  $context   Actor and site the worker runs as.
     * @param   string            $workerId  Identity of the worker leaving.
     * @param   string            $queue     Queue it was polling.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the worker identity or queue name is invalid.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the caller may not operate
     *          workers on this queue.
     *
     * @since   2.0.0
     */
    public function disconnect(ExecutionContext $context, string $workerId, string $queue): void
    {
        $this->authorizeWorker($context, AuthorizationResource::item('queue', $queue));
        $this->assertWorker($workerId);
        $this->assertQueue($queue);
        $this->database->delete(
            $this->tables->raw('worker_heartbeats'),
            ['worker_id' => $workerId],
            ['worker_id' => Types::STRING],
        );
    }

    /**
     * List recent jobs the caller may manage, newest created first, with any failure record joined on.
     *
     * Rows are read a page at a time and each is put to an `automation.manage` decision, so the limit
     * counts jobs the caller may actually see rather than rows scanned, and paging continues until that
     * many are collected or the table is exhausted. A refusal drops the row silently, which is why this
     * is the one entry point that does not raise on a denial.
     *
     * @param   ExecutionContext  $context  Actor and site each row is authorized against.
     * @param   int               $limit    Largest number of visible rows to hand back, 1 to 500.
     *
     * @return  list<array<string, mixed>>  Job rows with the payload decoded and the attempt counters
     *          cast to integers; empty when nothing is visible to this caller.
     *
     * @throws  InvalidArgumentException  When the limit is outside 1 to 500.
     * @throws  RuntimeException  When a row is malformed or its payload is not a JSON object.
     * @throws  \LogicException  When a row's stored execution scope disagrees with its job type.
     *
     * @since   2.0.0
     */
    public function all(ExecutionContext $context, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('The job list limit must be between 1 and 500.');
        }

        $result = [];
        $offset = 0;
        $pageSize = min(500, max(50, $limit));
        do {
            $rows = $this->database->fetchAllAssociative(sprintf(
                'SELECT j.*, f.failure_classification, f.exception_type, f.error_message, f.failed_at '
                . 'FROM %s j LEFT JOIN %s f ON f.job_id = j.id '
                . 'ORDER BY j.created_at DESC, j.id DESC LIMIT %d OFFSET %d',
                $this->tables->quoted('jobs'),
                $this->tables->quoted('failed_jobs'),
                $pageSize,
                $offset,
            ));
            foreach (array_map($this->normalize(...), $rows) as $row) {
                if (is_string($row['id'] ?? null) && $this->canManageRow($context, $row)) {
                    $result[] = $row;
                    if (count($result) === $limit) {
                        return $result;
                    }
                }
            }
            $offset += count($rows);
        } while (count($rows) === $pageSize);

        return $result;
    }

    /**
     * Return a buried job to the queue with a fresh attempt budget.
     *
     * The status change and the removal of the job's `failed_jobs` record share one transaction, so an
     * operator never sees a runnable job still carrying a failure record. Only a dead row qualifies, and
     * its attempt counter is reset, so the new run gets a full budget instead of dying on first failure.
     *
     * @param   ExecutionContext  $context  Actor and site the operator acts as.
     * @param   string            $id       Identifier of the dead job to requeue.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When no job carries the identifier, or the job is not dead.
     * @throws  RuntimeException  When the stored row carries no usable job type or execution scope.
     * @throws  \LogicException  When the row's stored execution scope disagrees with its job type.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the caller may not manage
     *          this job.
     *
     * @since   2.0.0
     */
    public function retry(ExecutionContext $context, string $id): void
    {
        $this->authorizeJob($context, $id);
        $this->transactions->transactional(function () use ($id): void {
            $now = $this->clock->now();
            $affected = $this->database->executeStatement(sprintf(
                "UPDATE %s SET status = 'pending', attempts = 0, available_at = ?, lease_owner = NULL, "
                . 'lease_token = NULL, '
                . 'lease_acquired_at = NULL, lease_expires_at = NULL, completed_at = NULL, updated_at = ? '
                . "WHERE id = ? AND status = 'dead'",
                $this->tables->quoted('jobs'),
            ), [$now, $now, $id], [Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::GUID]);

            if ($affected !== 1) {
                throw new InvalidArgumentException('Only an existing dead job can be retried.');
            }

            $this->database->delete($this->tables->raw('failed_jobs'), ['job_id' => $id], ['job_id' => Types::GUID]);
        });
    }

    /**
     * Withdraw a job no worker has claimed yet.
     *
     * Only a pending row can be canceled; once a worker holds the lease the job must be left to finish or
     * fail, because the queue has no way to interrupt a running handler. A row in any other state is
     * refused rather than quietly ignored, so the operator learns the job got away from them.
     *
     * @param   ExecutionContext  $context  Actor and site the operator acts as.
     * @param   string            $id       Identifier of the pending job to withdraw.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When no job carries the identifier, or the job is not pending.
     * @throws  RuntimeException  When the stored row carries no usable job type or execution scope.
     * @throws  \LogicException  When the row's stored execution scope disagrees with its job type.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the caller may not manage
     *          this job.
     *
     * @since   2.0.0
     */
    public function cancel(ExecutionContext $context, string $id): void
    {
        $this->authorizeJob($context, $id);
        $affected = $this->database->executeStatement(sprintf(
            "UPDATE %s SET status = 'canceled', updated_at = ? WHERE id = ? AND status = 'pending'",
            $this->tables->quoted('jobs'),
        ), [$this->clock->now(), $id], [Types::DATETIME_IMMUTABLE, Types::GUID]);

        if ($affected !== 1) {
            throw new InvalidArgumentException('Only an existing pending job can be canceled.');
        }
    }

    /**
     * Build the `StoredJob` a worker receives out of the row `claim()` has just reserved.
     *
     * Drivers differ over whether a JSON column arrives decoded, so a string is decoded here and anything
     * else taken as already decoded. A JSON list is rejected because a payload names its arguments, and a
     * list would silently drop those names on the way to the handler.
     *
     * @param   array<string, mixed>  $row  Reserved row, with the fresh lease token already written onto it.
     *
     * @return  StoredJob  The reservation in the shape a handler is invoked with.
     *
     * @throws  RuntimeException  When the payload is not decodable JSON or is not an object, or a required
     *          column is absent or of the wrong type.
     * @throws  InvalidArgumentException  When the row's lease token is not a canonical UUID, or its stored
     *          execution scope names no known execution class.
     *
     * @since   2.0.0
     */
    private function map(array $row): StoredJob
    {
        try {
            $payload = is_string($row['payload'] ?? null)
                ? json_decode($row['payload'], true, 64, JSON_THROW_ON_ERROR)
                : $row['payload'];
        } catch (JsonException $exception) {
            throw new RuntimeException('A queued job contains invalid JSON.', 0, $exception);
        }
        if (!is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            throw new RuntimeException('A queued job payload must be a JSON object.');
        }
        /** @var array<string, mixed> $payload */

        return new StoredJob(
            $this->requiredString($row, 'id'),
            $this->requiredString($row, 'queue'),
            $this->requiredString($row, 'job_type'),
            $payload,
            $this->integer($row, 'schema_version'),
            $this->integer($row, 'attempts'),
            $this->integer($row, 'maximum_attempts'),
            $this->requiredString($row, 'lease_token'),
            $this->requiredString($row, 'execution_scope'),
        );
    }

    /**
     * Move a row that has spent its attempt budget to dead and write its `failed_jobs` record.
     *
     * This runs inline on the claim path rather than in a separate sweeper, so an exhausted row is
     * cleared by the next worker that trips over it. The failure is recorded as transient and attributed
     * to an expired lease, because arriving here means the final attempt was never settled by the worker
     * that held it.
     *
     * @param   array<string, mixed>  $row  Job row read by the claim scan, still holding its stored payload.
     * @param   DateTimeImmutable     $now  Instant recorded as the update, failure and creation time.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the row is malformed, or another worker moved it between the read
     *          and this write.
     * @throws  JsonException  When the row's stored payload is a string that is not decodable JSON.
     *
     * @since   2.0.0
     */
    private function deadLetterExpired(array $row, DateTimeImmutable $now): void
    {
        $affected = $this->database->executeStatement(sprintf(
            "UPDATE %s SET status = 'dead', lease_owner = NULL, lease_token = NULL, "
            . 'lease_acquired_at = NULL, lease_expires_at = NULL, updated_at = ? WHERE id = ? AND '
            . "((status = 'pending' AND attempts >= maximum_attempts) OR (status = 'reserved' "
            . 'AND attempts >= maximum_attempts AND (lease_expires_at IS NULL OR lease_expires_at <= ?)))',
            $this->tables->quoted('jobs'),
        ), [$now, $this->requiredString($row, 'id'), $now], [
            Types::DATETIME_IMMUTABLE, Types::GUID, Types::DATETIME_IMMUTABLE,
        ]);
        $this->assertLeaseUpdated($affected);
        $this->database->insert($this->tables->raw('failed_jobs'), [
            'id' => Uuid::uuid7()->toString(),
            'job_id' => $this->requiredString($row, 'id'),
            'queue' => $this->requiredString($row, 'queue'),
            'job_type' => $this->requiredString($row, 'job_type'),
            'schema_version' => $this->integer($row, 'schema_version'),
            'payload' => is_string($row['payload'] ?? null)
                ? json_decode($row['payload'], true, 64, JSON_THROW_ON_ERROR)
                : ($row['payload'] ?? []),
            'attempts' => $this->integer($row, 'attempts'),
            'maximum_attempts' => $this->integer($row, 'maximum_attempts'),
            'failure_classification' => 'transient',
            'exception_type' => ExpiredJobLease::class,
            'error_message' => 'The final worker lease expired before the job completed.',
            'failed_at' => $now,
            'created_at' => $now,
        ], [
            'payload' => Types::JSON,
            'failed_at' => Types::DATETIME_IMMUTABLE,
            'created_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * Reshape a listed row so its payload is decoded and its counters are integers.
     *
     * `all()` hands rows straight to an operator view, so the driver differences — a JSON column that
     * arrives as text, counters that arrive as decimal strings — are ironed out once here rather than in
     * every reader. A JSON list is rejected because a payload names its arguments.
     *
     * @param   array<string, mixed>  $row  Joined job and failure row as the driver returned it.
     *
     * @return  array<string, mixed>  The same row with `payload`, `attempts` and `maximum_attempts`
     *          replaced by their decoded values, every other column untouched.
     *
     * @throws  RuntimeException  When the payload is not decodable JSON or is not an object, or a counter
     *          column holds something that is not an integer.
     *
     * @since   2.0.0
     */
    private function normalize(array $row): array
    {
        try {
            $payload = is_string($row['payload'] ?? null)
                ? json_decode($row['payload'], true, 64, JSON_THROW_ON_ERROR)
                : $row['payload'];
        } catch (JsonException $exception) {
            throw new RuntimeException('A queued job contains invalid JSON.', 0, $exception);
        }

        if (!is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            throw new RuntimeException('A queued job payload must be a JSON object.');
        }
        /** @var array<string, mixed> $payload */

        $row['payload'] = $payload;
        $row['attempts'] = $this->integer($row, 'attempts');
        $row['maximum_attempts'] = $this->integer($row, 'maximum_attempts');
        $policy = is_string($row['queue'] ?? null) ? $this->policies?->policy($row['queue']) : null;
        if ($policy !== null) {
            $row['queue_policy'] = $policy->toArray();
        }

        return $row;
    }

    /**
     * Create the durable queue lock row before a claim transaction attempts to lock it.
     *
     * The insert runs before the claim transaction so a PostgreSQL unique collision can roll back its
     * own implicit statement without aborting the transaction that performs the claim. A concurrent
     * first worker may win the insert; the loser treats that expected collision as proof the row now
     * exists and proceeds to the same `FOR UPDATE` lock.
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
            // A concurrent first claimant committed the singleton row.
        }
    }

    /**
     * Serialize declared-queue claims and reserve capacity below the signed in-flight ceiling.
     *
     * Every claimant first takes the same durable policy row `FOR UPDATE`, then counts only live fenced
     * leases. Claim transactions therefore cannot both observe the same spare slot and exceed the
     * ceiling across worker processes. Expired leases do not consume capacity and remain eligible for
     * the normal fenced re-claim path.
     *
     * @param   QueueRuntimePolicy  $policy  Active trusted queue policy.
     * @param   DateTimeImmutable   $now     Instant live leases are compared against.
     *
     * @return  bool  True when another live reservation fits under the ceiling.
     *
     * @throws  RuntimeException  When the policy lock row disappeared unexpectedly.
     *
     * @since   2.0.0
     */
    private function claimPolicySlot(QueueRuntimePolicy $policy, DateTimeImmutable $now): bool
    {
        $locked = $this->database->fetchOne(sprintf(
            'SELECT queue_id FROM %s WHERE queue_id = ? FOR UPDATE',
            $this->tables->quoted('job_queue_runtime'),
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
        ], ['queue_id' => $policy->queue], [
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
        $inFlight = $this->databaseCount($this->database->fetchOne(sprintf(
            "SELECT COUNT(*) FROM %s WHERE queue = ? AND status = 'reserved' AND lease_expires_at > ?",
            $this->tables->quoted('jobs'),
        ), [$policy->queue, $now], [Types::STRING, Types::DATETIME_IMMUTABLE]));

        $inFlight += $this->databaseCount($this->database->fetchOne(sprintf(
            "SELECT COUNT(*) FROM %s WHERE queue = ? AND status = 'reserved' AND lease_expires_at > ?",
            $this->tables->quoted('integration_inbox'),
        ), [$policy->queue, $now], [Types::STRING, Types::DATETIME_IMMUTABLE]));

        return $inFlight < $policy->maximumInFlight;
    }

    /**
     * Read a column that a stored job row is required to carry as a non-empty string.
     *
     * @param   array<string, mixed>  $row    Job row as the driver returned it.
     * @param   string                $field  Column name to read, spelled as the schema spells it.
     *
     * @return  string  The column value, guaranteed non-empty.
     *
     * @throws  RuntimeException  When the column is absent, is not a string, or is empty.
     *
     * @since   2.0.0
     */
    private function requiredString(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Queued job field %s is invalid.', $field));
        }
        return $value;
    }

    /**
     * Read a counter column, accepting the decimal strings some drivers return for integer columns.
     *
     * @param   array<string, mixed>  $row    Job row as the driver returned it.
     * @param   string                $field  Column name to read, spelled as the schema spells it.
     *
     * @return  int  The column value as an integer, sign preserved.
     *
     * @throws  RuntimeException  When the column is neither an integer nor a decimal integer string.
     *
     * @since   2.0.0
     */
    private function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && (!is_string($value) || preg_match('/^-?[0-9]+$/D', $value) !== 1)) {
            throw new RuntimeException(sprintf('Queued job field %s is not an integer.', $field));
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

        throw new RuntimeException('A queued work count is invalid.');
    }

    /**
     * Require that a lease-fenced write matched exactly one row.
     *
     * Every reservation, renewal and settlement matches on the worker's token as well as the identifier,
     * so any count other than one means the lease has moved on and the caller's outcome must not be
     * recorded. The count is compared as text because drivers report it as either an int or a string.
     *
     * @param   int|string  $affected  Rows the fenced statement reported as changed.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the statement matched anything other than exactly one row.
     *
     * @since   2.0.0
     */
    private function assertLeaseUpdated(int|string $affected): void
    {
        if ((string) $affected !== '1') {
            throw new RuntimeException('The worker no longer owns the active job lease.');
        }
    }

    /**
     * Require that a queue name is safe to store and to match rows on.
     *
     * @param   string  $queue  Queue name as supplied by the caller.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the name is not 1 to 64 characters of letters, digits, dot,
     *          underscore, colon or hyphen, opening with a letter or digit.
     *
     * @since   2.0.0
     */
    private function assertQueue(string $queue): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/D', $queue) !== 1) {
            throw new InvalidArgumentException('The queue name is invalid.');
        }
    }

    /**
     * Require that a job type name follows the registered-handler naming rule.
     *
     * @param   string  $type  Job type as supplied by the producer.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the type is not 3 to 128 characters of letters, digits, dot,
     *          underscore, colon or hyphen, opening with a letter.
     *
     * @since   2.0.0
     */
    private function assertType(string $type): void
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9._:-]{2,127}$/D', $type) !== 1) {
            throw new InvalidArgumentException('The job type is invalid.');
        }
    }

    /**
     * Require that a worker identity is safe to record as a lease owner and heartbeat key.
     *
     * @param   string  $worker  Worker identity as supplied by the worker loop.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the identity is not 3 to 128 characters of letters, digits,
     *          dot, underscore, colon or hyphen, opening with a letter or digit.
     *
     * @since   2.0.0
     */
    private function assertWorker(string $worker): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/D', $worker) !== 1) {
            throw new InvalidArgumentException('The worker identifier is invalid.');
        }
    }

    /**
     * Require the caller to hold `automation.manage` over a queue or job resource.
     *
     * This is the producer and operator side of the queue. The worker loop is checked separately by
     * `authorizeWorker()`, so managing automation does not by itself confer the right to run it.
     *
     * @param   ExecutionContext       $context   Actor and site the decision is made for.
     * @param   AuthorizationResource  $resource  Queue, job or job-type resource being acted on.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses the actor
     *          this capability on this resource.
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

    /**
     * Require the caller to hold `system.worker.operate` over a queue.
     *
     * Claiming, renewing, settling and heartbeating all pass through here, so an operator who may
     * schedule and cancel work still cannot take a lease and record outcomes as though it were a worker.
     *
     * @param   ExecutionContext       $context   Actor and site the decision is made for.
     * @param   AuthorizationResource  $resource  Queue the worker is operating on.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses the actor
     *          this capability on this queue.
     *
     * @since   2.0.0
     */
    private function authorizeWorker(ExecutionContext $context, AuthorizationResource $resource): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('system.worker.operate'),
            $resource,
        );
    }

    /**
     * Take a second, type-scoped decision before an installation-global job type may be enqueued.
     *
     * A site-local type passes straight through, having already been authorized against its queue. A
     * global type is judged again against the type itself, so `automation.manage` held for one site
     * cannot be spent enqueueing work whose effect spans the whole installation.
     *
     * @param   ExecutionContext  $context  Actor and site the decision is made for.
     * @param   string            $jobType  Registered job type the producer asked to enqueue.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage
     *          this installation-global job type.
     *
     * @since   2.0.0
     */
    private function authorizeJobType(ExecutionContext $context, string $jobType): void
    {
        if (!$this->jobScope->isInstallationGlobal($jobType)) {
            return;
        }

        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('automation.manage'),
            AuthorizationResource::item('automation_installation', $jobType),
        );
    }

    /**
     * Decide, without raising, whether a listed row belongs in this caller's view.
     *
     * The resource the decision is made against depends on the row's execution class: an
     * installation-global row is judged by its job type, a site-local one by the job identifier, which
     * the ownership registry resolves to the site that owns it.
     *
     * @param   ExecutionContext      $context  Actor and site the decision is made for.
     * @param   array<string, mixed>  $row      Job row read by `all()`.
     *
     * @return  bool  True when the caller may manage the row, false when the decision refused it.
     *
     * @throws  RuntimeException  When the row carries no usable job type, identifier or execution scope.
     * @throws  \LogicException  When the stored execution scope disagrees with the job type's declaration.
     *
     * @since   2.0.0
     */
    private function canManageRow(ExecutionContext $context, array $row): bool
    {
        $jobType = $this->requiredString($row, 'job_type');
        $executionClass = $this->jobScope->assertStoredClass(
            $jobType,
            $this->requiredString($row, 'execution_scope'),
        );
        $resource = $executionClass === JobExecutionClass::Installation
            ? AuthorizationResource::item('automation_installation', $jobType)
            : AuthorizationResource::item('job', $this->requiredString($row, 'id'));

        return $this->authorization->decide(
            $context,
            Capability::fromString('automation.manage'),
            $resource,
        )->allowed;
    }

    /**
     * Load a job by identifier and require `automation.manage` over the resource it belongs to.
     *
     * `retry()` and `cancel()` are given nothing but an identifier, so the row is read first to learn its
     * execution class: an installation-global job is judged by its type, a site-local one by the job
     * itself. A missing row is reported as an argument error before any decision is taken.
     *
     * @param   ExecutionContext  $context  Actor and site the decision is made for.
     * @param   string            $id       Identifier of the job the operator named.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When no job carries the identifier.
     * @throws  RuntimeException  When the stored row carries no usable job type or execution scope.
     * @throws  \LogicException  When the stored execution scope disagrees with the job type's declaration.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage
     *          this job.
     *
     * @since   2.0.0
     */
    private function authorizeJob(ExecutionContext $context, string $id): void
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT id, job_type, execution_scope FROM %s WHERE id = ?',
            $this->tables->quoted('jobs'),
        ), [$id]);
        if ($row === false) {
            throw new InvalidArgumentException('The job does not exist.');
        }

        $jobType = $this->requiredString($row, 'job_type');
        $executionClass = $this->jobScope->assertStoredClass(
            $jobType,
            $this->requiredString($row, 'execution_scope'),
        );
        $this->authorize(
            $context,
            $executionClass === JobExecutionClass::Installation
                ? AuthorizationResource::item('automation_installation', $jobType)
                : AuthorizationResource::item('job', $id),
        );
    }

    /**
     * Lock the ownership row binding a site-local job to its site, and confirm that site is enabled.
     *
     * The `FOR UPDATE` holds the ownership row for the remainder of the claim transaction, so the site a
     * job belongs to cannot be disabled, nor its ownership rewritten, between the eligibility check and
     * the reservation that follows it.
     *
     * @param   string  $jobId  Identifier of the job whose owning site is being pinned.
     *
     * @return  bool  True when an enabled owning site was found and locked; false when the job has no
     *          ownership record or its owner is disabled.
     *
     * @since   2.0.0
     */
    private function lockEnabledOwner(string $jobId): bool
    {
        return $this->database->fetchOne(sprintf(
            'SELECT s.identifier FROM %s o INNER JOIN %s s ON s.identifier = o.site_identifier '
            . 'WHERE o.resource_type = ? AND o.resource_id = ? AND s.enabled = ? FOR UPDATE',
            $this->tables->quoted('resource_site_ownership'),
            $this->tables->quoted('sites'),
        ), ['job', $jobId, true], [Types::STRING, Types::STRING, Types::BOOLEAN]) !== false;
    }
}
