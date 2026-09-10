<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation\Job;

use DateTimeImmutable;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Contract for the store the recurring automation schedules are kept in.
 *
 * A schedule is the durable half of automation: it says which job type runs, how often, in which
 * timezone and on which queue, while the scheduler turns due schedules into queued jobs.
 * `AutomationManagementService` is the intended caller, and every method takes an `ExecutionContext`
 * because a schedule belongs either to one site or to the installation as a whole — an implementation
 * authorizes and scopes against that context rather than trusting the identifier it was handed.
 * The mutating methods take the version the caller last read, so a concurrent edit is refused instead
 * of silently overwritten.
 *
 * @since  2.0.0
 */
interface ScheduleRepository
{
    /**
     * Store a new schedule and return the identifier it is addressed by from then on.
     *
     * An implementation validates the recurrence, timezone, job type and queue before writing, so a
     * schedule that could never dispatch is rejected once here rather than on every scheduler pass.
     *
     * @param   ExecutionContext      $context         Actor and site the schedule is created for and owned by.
     * @param   string                $name            Operator-facing label shown in the automation views.
     * @param   string                $cronExpression  Five-field cron expression fixing the recurrence.
     * @param   string                $timezone        IANA identifier the recurrence is evaluated in.
     * @param   string                $jobType         Registered handler type every occurrence dispatches.
     * @param   array<string, mixed>  $payload         Job payload copied onto each dispatched occurrence.
     * @param   string                $queue           Queue name the occurrences are enqueued on.
     * @param   DateTimeImmutable     $firstRun        Earliest instant the first occurrence may run.
     *
     * @return  string  Identifier of the stored schedule.
     *
     * @since   2.0.0
     */
    public function create(
        ExecutionContext $context,
        string $name,
        string $cronExpression,
        string $timezone,
        string $jobType,
        array $payload,
        string $queue,
        DateTimeImmutable $firstRun,
    ): string;

    /**
     * List the schedules this context is entitled to manage.
     *
     * Schedules the context may not manage are filtered out rather than refused, so a site
     * administrator sees their own site's schedules and none of another site's.
     *
     * @param   ExecutionContext  $context  Actor and site the listing is filtered for.
     *
     * @return  list<array<string, mixed>>  Schedule rows; empty when the context manages none.
     *
     * @since   2.0.0
     */
    public function all(ExecutionContext $context): array;

    /**
     * Read a single schedule by identifier.
     *
     * Null means no schedule carries that identifier. A schedule that exists but belongs elsewhere is
     * refused, not reported as missing, so a caller can tell an unknown identifier from a forbidden one.
     *
     * @param   ExecutionContext  $context  Actor and site the schedule is authorized against.
     * @param   string            $id       Identifier returned by `create()`.
     *
     * @return  array<string, mixed>|null  The schedule row, or null when no such schedule exists.
     *
     * @since   2.0.0
     */
    public function find(ExecutionContext $context, string $id): ?array;

    /**
     * Enable or disable a schedule, refusing the change when it has moved on since it was read.
     *
     * Disabling is the way to stop a schedule without losing it: the row keeps its recurrence and its
     * history, and the scheduler stops selecting it as due.
     *
     * @param   ExecutionContext  $context          Actor and site the schedule is authorized against.
     * @param   string            $id               Identifier of the schedule to switch.
     * @param   int               $expectedVersion  Version the caller read; a newer stored row is refused.
     * @param   bool              $enabled          True to let the scheduler dispatch it, false to hold it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function setEnabled(ExecutionContext $context, string $id, int $expectedVersion, bool $enabled): void;

    /**
     * Remove a schedule permanently, refusing the delete when it has moved on since it was read.
     *
     * Only the recurrence goes. Jobs already dispatched from the schedule keep running on the queue,
     * because they were handed over the moment they were enqueued.
     *
     * @param   ExecutionContext  $context          Actor and site the schedule is authorized against.
     * @param   string            $id               Identifier of the schedule to remove.
     * @param   int               $expectedVersion  Version the caller read; a newer stored row is refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function delete(ExecutionContext $context, string $id, int $expectedVersion): void;
}
