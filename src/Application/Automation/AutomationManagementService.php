<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Automation\Job\ScheduleRepository;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * The one use-case surface an operator administers automation through: schedules and queued jobs.
 *
 * This service owns the parts of automation management that are policy rather than storage. It puts
 * collection-wide attempts — creating a schedule, discovering which job types are on offer — to the
 * authorization gateway itself, refuses an identifier that is not a canonical UUID before it can reach
 * a query, and wraps every mutation in a single transaction carrying the change and its audit event
 * together, so the trail cannot end up disagreeing with the data. Permission on an individual stored
 * row is left to `ScheduleRepository` and `JobQueue`, which know which site owns it and whether the job
 * type is installation-wide; the read methods here are therefore thin, already-filtered pass-throughs.
 * The REST endpoint, the administrator automation screen and the console command all drive automation
 * through this one surface, so each renders the same named failures in its own way rather than
 * re-deciding any of the policy.
 *
 * @since  2.0.0
 */
final readonly class AutomationManagementService
{
    /**
     * Wire the collaborators every automation read and mutation depends on.
     *
     * @param  ScheduleRepository       $schedules      Store of schedule definitions; filters rows per actor.
     * @param  JobQueue                 $jobs           Queue the job listing is read from and retried against.
     * @param  JobHandlerRegistry       $handlers       Registered job types a new schedule may name.
     * @param  TransactionManager       $transactions   Boundary committing each change with its audit event.
     * @param  AuditRecorder            $audit          Sink every mutation's audit event is written to.
     * @param  ClockInterface           $clock          Source of the instant stamped on those audit events.
     * @param  AuthorizationGateway     $authorization  Decides the collection-wide `automation.manage` attempts.
     * @param  JobExecutionScope        $jobScope       Says which job types act installation-wide, not per site.
     * @param  ?QueueRuntimeOperations  $queueRuntime   Active contributed queue inventory and retention operations;
     *         null keeps isolated core-only service instances backward compatible.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ScheduleRepository $schedules,
        private JobQueue $jobs,
        private JobHandlerRegistry $handlers,
        private TransactionManager $transactions,
        private AuditRecorder $audit,
        private ClockInterface $clock,
        private AuthorizationGateway $authorization,
        private JobExecutionScope $jobScope,
        private ?QueueRuntimeOperations $queueRuntime = null,
    ) {
    }

    /**
     * List the schedules this actor is allowed to manage.
     *
     * The repository puts each stored row to the gateway on its own, so a schedule the actor cannot
     * manage is dropped from the listing rather than refusing the whole request.
     *
     * @param   ExecutionContext  $context  Actor and site the listing is performed for.
     *
     * @return  list<array<string, mixed>>  Normalised schedule rows; empty when none is manageable.
     *
     * @since   2.0.0
     */
    public function schedules(ExecutionContext $context): array
    {
        return $this->schedules->all($context);
    }

    /**
     * Read one schedule by identifier.
     *
     * A schedule that exists but is out of the actor's reach is refused by the repository rather than
     * reported as missing, so `AutomationNotFound` here always means genuinely absent.
     *
     * @param   ExecutionContext  $context  Actor and site the lookup is performed for.
     * @param   string            $id       Canonical lowercase UUID of the schedule to read.
     *
     * @return  array<string, mixed>  The normalised schedule row, keyed by column name.
     *
     * @throws  InvalidArgumentException  When the identifier is not a canonical lowercase UUID.
     * @throws  AutomationNotFound  When no schedule carries that identifier.
     *
     * @since   2.0.0
     */
    public function schedule(ExecutionContext $context, string $id): array
    {
        $this->assertId($id);

        return $this->schedules->find($context, $id)
            ?? throw new AutomationNotFound('The schedule does not exist.');
    }

    /**
     * List the queued jobs this actor is allowed to see, most recent first.
     *
     * The limit counts jobs that survive the per-row check, not stored rows, so a short result means
     * the queue ran out rather than that permission trimmed the page.
     *
     * @param   ExecutionContext  $context  Actor and site the listing is performed for.
     * @param   int               $limit    Visible jobs to return; between 1 and 500.
     *
     * @return  list<array<string, mixed>>  Normalised job rows; empty when none is visible.
     *
     * @throws  InvalidArgumentException  When the limit falls outside 1 to 500.
     *
     * @since   2.0.0
     */
    public function jobs(ExecutionContext $context, int $limit = 100): array
    {
        return $this->jobs->all($context, $limit);
    }

    /**
     * List active contributed queue policies with durable load and retention counters.
     *
     * @param   ExecutionContext  $context  Authenticated automation operator.
     *
     * @return  list<array<string, mixed>>  Active queue runtime inventory; empty for core-only instances.
     *
     * @since   2.0.0
     */
    public function queuePolicies(ExecutionContext $context): array
    {
        return $this->queueRuntime?->inventory($context) ?? [];
    }

    /**
     * Purge one bounded batch of terminal queue evidence past the signed retention cutoff.
     *
     * Evidence disposal and its audit event share the automation transaction. Queue runtime storage locks
     * selected rows before deleting terminal jobs or compacting terminal inbox detail, so concurrent work
     * cannot race the operation and a compacted delivery cannot lose its duplicate tombstone.
     *
     * @param   ExecutionContext  $context  Authenticated automation operator.
     * @param   string            $queue    Active contributed queue identifier.
     * @param   int               $limit    Maximum terminal records deleted or compacted, 1 to 1000.
     *
     * @return  int  Terminal job deletions plus inbox receipt compactions.
     *
     * @throws  \LogicException  When the service was constructed without queue runtime operations.
     *
     * @since   2.0.0
     */
    public function purgeQueue(ExecutionContext $context, string $queue, int $limit = 100): int
    {
        if ($this->queueRuntime === null) {
            throw new \LogicException('Contributed queue runtime operations are unavailable.');
        }
        $actorId = $context->actorId();

        return $this->transactions->transactional(function () use ($context, $queue, $limit, $actorId): int {
            $purged = $this->queueRuntime->purge($context, $queue, $limit);
            $this->record(
                $actorId,
                'automation.queue.retention.purge',
                'queue',
                $queue,
                ['purged' => $purged, 'limit' => $limit],
            );

            return $purged;
        });
    }

    /**
     * List the job types this actor could actually put on a schedule.
     *
     * Every registered handler is offered for site-local work, but an installation-global type is kept
     * only when the actor also holds `automation.manage` over that type as an installation resource.
     * The administrator screen builds its job-type form list from this, so a type missing here is one
     * the actor would be refused on had they named it in `createSchedule()`.
     *
     * @param   ExecutionContext  $context  Actor and site the offer is computed for.
     *
     * @return  list<string>  Usable job type identifiers, in the registry's byte order.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage
     *          automation at all.
     *
     * @since   2.0.0
     */
    public function jobTypes(ExecutionContext $context): array
    {
        $this->authorize($context, AuthorizationResource::collection('job'));
        return array_values(array_filter(
            $this->handlers->types(),
            fn (string $type): bool => !$this->jobScope->isInstallationGlobal($type)
                || $this->authorization->decide(
                    $context,
                    Capability::fromString('automation.manage'),
                    AuthorizationResource::item('automation_installation', $type),
                )->allowed,
        ));
    }

    /**
     * Create a schedule that will enqueue one job each time its cron expression comes due.
     *
     * The job type is checked against the handler registry before anything is written, so a schedule
     * can never be stored naming work no deployed handler can perform. Creation and its audit event
     * share one transaction; the repository re-authorizes against the job type it is given, which is
     * where an installation-global type is refused to a site-scoped actor.
     *
     * @param   ExecutionContext      $context         Actor and site the schedule is created for.
     * @param   string                $name            Operator-facing label the schedule is listed under.
     * @param   string                $cronExpression  Five-field cron expression deciding when it is due.
     * @param   string                $timezone        Timezone the cron expression is evaluated in.
     * @param   string                $jobType         Registered handler type each occurrence enqueues.
     * @param   array<string, mixed>  $payload         Arguments handed to the handler on every occurrence.
     * @param   string                $queue           Queue name the enqueued jobs are placed on.
     * @param   DateTimeImmutable     $firstRun        First run wanted; a past instant is pulled up to now.
     *
     * @return  string  Canonical UUID of the stored schedule.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not create
     *          schedules, or may not use this job type.
     * @throws  InvalidArgumentException  When no handler is registered for the job type, or the
     *          repository rejects the cron expression, timezone, queue or name.
     *
     * @since   2.0.0
     */
    public function createSchedule(
        ExecutionContext $context,
        string $name,
        string $cronExpression,
        string $timezone,
        string $jobType,
        array $payload,
        string $queue,
        DateTimeImmutable $firstRun,
    ): string {
        $this->authorize($context, AuthorizationResource::collection('schedule'));
        $actorId = $context->actorId();
        if ($this->handlers->find($jobType) === null) {
            throw new InvalidArgumentException('The schedule job type has no registered handler.');
        }

        return $this->transactions->transactional(function () use (
            $context,
            $actorId,
            $name,
            $cronExpression,
            $timezone,
            $jobType,
            $payload,
            $queue,
            $firstRun,
        ): string {
            $id = $this->schedules->create(
                $context,
                $name,
                $cronExpression,
                $timezone,
                $jobType,
                $payload,
                $queue,
                $firstRun,
            );
            $this->record($actorId, 'automation.schedule.create', 'schedule', $id, ['job_type' => $jobType]);

            return $id;
        });
    }

    /**
     * Turn a schedule's dispatching on or off without discarding its definition.
     *
     * This is the reversible way to stop work: a disabled schedule keeps its definition and its last-run
     * record, and is simply passed over when the scheduler looks for what is due. The expected version
     * gives optimistic concurrency, so two operators toggling the same schedule cannot overwrite each
     * other unnoticed.
     *
     * @param   ExecutionContext  $context          Actor and site the change is made for.
     * @param   string            $id               Canonical lowercase UUID of the schedule to toggle.
     * @param   int               $expectedVersion  Version the caller last read; must still be current.
     * @param   bool              $enabled          True to resume dispatching, false to suspend it.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the identifier is not a canonical lowercase UUID, or the
     *          schedule no longer exists at the expected version.
     *
     * @since   2.0.0
     */
    public function setScheduleEnabled(
        ExecutionContext $context,
        string $id,
        int $expectedVersion,
        bool $enabled,
    ): void {
        $actorId = $context->actorId();
        $this->assertId($id);
        $this->transactions->transactional(function () use (
            $context,
            $actorId,
            $id,
            $expectedVersion,
            $enabled,
        ): void {
            $this->schedules->setEnabled($context, $id, $expectedVersion, $enabled);
            $this->record(
                $actorId,
                $enabled ? 'automation.schedule.enable' : 'automation.schedule.disable',
                'schedule',
                $id,
            );
        });
    }

    /**
     * Remove a schedule so it produces no further occurrences.
     *
     * Deletion is permanent and takes the definition with it; suspending work an operator may want back
     * is what `setScheduleEnabled()` is for. Jobs the schedule already enqueued are untouched and still
     * run. The expected version guards against deleting a schedule someone else has just edited.
     *
     * @param   ExecutionContext  $context          Actor and site the deletion is made for.
     * @param   string            $id               Canonical lowercase UUID of the schedule to remove.
     * @param   int               $expectedVersion  Version the caller last read; must still be current.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the identifier is not a canonical lowercase UUID, or the
     *          schedule no longer exists at the expected version.
     *
     * @since   2.0.0
     */
    public function deleteSchedule(ExecutionContext $context, string $id, int $expectedVersion): void
    {
        $actorId = $context->actorId();
        $this->assertId($id);
        $this->transactions->transactional(function () use ($context, $actorId, $id, $expectedVersion): void {
            $this->schedules->delete($context, $id, $expectedVersion);
            $this->record($actorId, 'automation.schedule.delete', 'schedule', $id);
        });
    }

    /**
     * Return a job that exhausted its attempts to the queue for another run.
     *
     * Only a dead job can be retried. The attempt counter is reset and its recorded failure discarded,
     * so the job starts again as if newly enqueued; an operator should therefore have fixed whatever
     * made it fail before reaching for this.
     *
     * @param   ExecutionContext  $context  Actor and site the retry is performed for.
     * @param   string            $id       Canonical lowercase UUID of the dead job to requeue.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the identifier is not a canonical lowercase UUID, or the
     *          job does not exist or is not dead.
     *
     * @since   2.0.0
     */
    public function retryJob(ExecutionContext $context, string $id): void
    {
        $actorId = $context->actorId();
        $this->assertId($id);
        $this->transactions->transactional(function () use ($context, $actorId, $id): void {
            $this->jobs->retry($context, $id);
            $this->record($actorId, 'automation.job.retry', 'job', $id);
        });
    }

    /**
     * Withdraw a job that has not started yet, so no worker ever claims it.
     *
     * Only a pending job can be canceled: once a worker holds the lease the job is running, and the
     * queue offers no way to stop it from here.
     *
     * @param   ExecutionContext  $context  Actor and site the cancellation is performed for.
     * @param   string            $id       Canonical lowercase UUID of the pending job to withdraw.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the identifier is not a canonical lowercase UUID, or the
     *          job does not exist or is no longer pending.
     *
     * @since   2.0.0
     */
    public function cancelJob(ExecutionContext $context, string $id): void
    {
        $actorId = $context->actorId();
        $this->assertId($id);
        $this->transactions->transactional(function () use ($context, $actorId, $id): void {
            $this->jobs->cancel($context, $id);
            $this->record($actorId, 'automation.job.cancel', 'job', $id);
        });
    }

    /**
     * Reject an identifier that is not a canonical UUID before it can reach a query.
     *
     * Validity alone is not enough, so the value must also equal its own lowercase form: stored
     * identifiers are written in lower case, and a lookup that missed purely on letter case would
     * surface to the caller as "no such record" rather than as the malformed input it is.
     *
     * @param   string  $id  Identifier taken from a route or a request body.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the value is not a UUID, or is not spelled in lower case.
     *
     * @since   2.0.0
     */
    private function assertId(string $id): void
    {
        if (!Uuid::isValid($id) || strtolower($id) !== $id) {
            throw new InvalidArgumentException('Automation resource identifiers must be canonical UUIDs.');
        }
    }

    /**
     * Require `automation.manage` over a resource before the use case proceeds.
     *
     * Used for the collection-wide checks this service makes on its own account; a decision about one
     * stored schedule or job is made by the repository that fetched the row and knows its owning site.
     *
     * @param   ExecutionContext       $context   Actor and site the check is made for.
     * @param   AuthorizationResource  $resource  Collection or item the attempt is aimed at.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses the actor.
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
     * Write the audit event for one automation change.
     *
     * Always called from inside the transaction carrying the change, so a recorder that cannot store
     * the event rolls the change back with it. The outcome is fixed at `success` because a failed
     * attempt never reaches this point.
     *
     * @param   string                $actorId      Actor accountable for the change, read before the
     *          transaction opened.
     * @param   string                $action       Machine token naming the change, such as
     *          `automation.schedule.create`.
     * @param   string                $subjectType  Kind of record acted on: `schedule` or `job`.
     * @param   string                $subjectId    Canonical UUID of the record acted on.
     * @param   array<string, mixed>  $metadata     Extra context to store verbatim; safe values only.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function record(
        string $actorId,
        string $action,
        string $subjectType,
        string $subjectId,
        array $metadata = [],
    ): void {
        $this->audit->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $this->clock->now(),
            $actorId,
            $action,
            $subjectType,
            $subjectId,
            'success',
            $metadata,
        ));
    }
}
