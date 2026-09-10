<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation;

use DateTimeImmutable;
use Kumwe\Context\Value\ExecutionContext;
use Throwable;

/**
 * Contract for the durable store a queued job lives in, from enqueue through claim to completion.
 *
 * Producers hand work over with `enqueue()` and never touch it again, workers take one job at a time
 * with `claim()` and settle it with `complete()` or `fail()`, and operators read the backlog with
 * `all()` before pushing individual jobs around with `retry()` and `cancel()`. Every call carries the
 * caller's `ExecutionContext`, so the store rather than the caller decides who may do which of those.
 *
 * The claim is fenced. `claim()` stamps a fresh token onto the `StoredJob` it hands back, and `renew()`,
 * `complete()` and `fail()` only take effect while the calling worker still presents that token, so a
 * worker whose lease expired and whose job was re-claimed elsewhere cannot record an outcome over the
 * top of the worker that now owns it.
 *
 * @since  2.0.0
 */
interface JobQueue
{
    /**
     * Store a new job for a worker to pick up later.
     *
     * @param   ExecutionContext      $context          Actor and site the job is produced under.
     * @param   string                $type             Registered job type deciding which handler runs it.
     * @param   array<string, mixed>  $payload          Job arguments, in the shape the type's schema declares.
     * @param   DateTimeImmutable     $availableAt      Earliest moment a worker may claim the job.
     * @param   string                $queue            Name of the queue workers poll for this job.
     * @param   int                   $priority         Claim-order weight; higher is claimed first.
     * @param   int                   $maximumAttempts  Claims allowed before the job is declared dead.
     *
     * @return  string  Identifier of the stored job, as passed to `retry()` and `cancel()`.
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
    ): string;

    /**
     * Take the next runnable job on a queue under a time-boxed, fenced lease.
     *
     * @param   ExecutionContext  $context       Actor and site the worker runs as.
     * @param   string            $queue         Name of the queue to take work from.
     * @param   string            $workerId      Identity recorded as the holder of the lease.
     * @param   int               $leaseSeconds  How long the claim holds before another worker may take over.
     *
     * @return  ?StoredJob  The job with a fresh fencing token, or null when the queue holds nothing this
     *          worker may run.
     *
     * @since   2.0.0
     */
    public function claim(
        ExecutionContext $context,
        string $queue,
        string $workerId,
        int $leaseSeconds,
    ): ?StoredJob;

    /**
     * Renew an active lease without changing its fencing token.
     *
     * The expiry moves out from now, so a handler renewing at safe checkpoints stays out of reach of the
     * reaper without ever surrendering the token its later `complete()` or `fail()` depends on.
     *
     * @param   ExecutionContext  $context       Actor and site the worker runs as.
     * @param   StoredJob         $job           Job whose lease is extended; carries the token proving it.
     * @param   string            $workerId      Identity that must still hold the lease.
     * @param   int               $leaseSeconds  Length of the new window, measured from now.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function renew(
        ExecutionContext $context,
        StoredJob $job,
        string $workerId,
        int $leaseSeconds,
    ): void;

    /**
     * Record a successful attempt and take the job out of circulation for good.
     *
     * @param   ExecutionContext  $context   Actor and site the worker runs as.
     * @param   StoredJob         $job       Job that finished; carries the token proving the lease.
     * @param   string            $workerId  Identity that must still hold the lease.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function complete(ExecutionContext $context, StoredJob $job, string $workerId): void;

    /**
     * Record a failed attempt, either leaving the job to run again or burying it.
     *
     * @param   ExecutionContext  $context    Actor and site the worker runs as.
     * @param   StoredJob         $job        Job whose attempt failed; carries the token proving the lease.
     * @param   string            $workerId   Identity that must still hold the lease.
     * @param   Throwable         $failure    Value that ended the attempt, kept for the operator record.
     * @param   bool              $permanent  True to bury the job now, whatever attempts remain.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function fail(
        ExecutionContext $context,
        StoredJob $job,
        string $workerId,
        Throwable $failure,
        bool $permanent,
    ): void;

    /**
     * Record that a worker is alive on a queue, and which job it currently holds.
     *
     * @param   ExecutionContext  $context   Actor and site the worker runs as.
     * @param   string            $workerId  Identity of the worker reporting in.
     * @param   string            $queue     Queue the worker is polling.
     * @param   ?string           $jobId     Job in flight, or null while the worker holds none.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function heartbeat(
        ExecutionContext $context,
        string $workerId,
        string $queue,
        ?string $jobId = null,
    ): void;

    /**
     * Retire a worker's liveness record when it stops polling a queue.
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
     * @since   2.0.0
     */
    public function disconnect(ExecutionContext $context, string $workerId, string $queue): void;

    /**
     * List recent jobs for an operator view, whatever state they are in.
     *
     * @param   ExecutionContext  $context  Actor and site the listing is filtered for.
     * @param   int               $limit    Largest number of rows to hand back.
     *
     * @return  list<array<string, mixed>>  Job rows this caller may manage, newest created first.
     *
     * @since   2.0.0
     */
    public function all(ExecutionContext $context, int $limit = 100): array;

    /**
     * Return a buried job to the queue for a fresh run.
     *
     * Only a job that has died is retryable, and it comes back with its attempt counter reset, so the new
     * run gets a full budget instead of dying again on its first failure.
     *
     * @param   ExecutionContext  $context  Actor and site the operator acts as.
     * @param   string            $id       Identifier of the dead job to requeue.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function retry(ExecutionContext $context, string $id): void;

    /**
     * Withdraw a job no worker has claimed yet.
     *
     * Only a pending job can be canceled; once a worker holds the lease the job must be left to finish or
     * fail, because the queue has no way to interrupt a running handler.
     *
     * @param   ExecutionContext  $context  Actor and site the operator acts as.
     * @param   string            $id       Identifier of the pending job to withdraw.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function cancel(ExecutionContext $context, string $id): void;
}
