<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation;

use Kumwe\Context\Value\ExecutionContext;

/**
 * Port the scheduling loop drives to turn due recurrences into queued jobs.
 *
 * `schedule:run` holds nothing but this interface, which keeps the loop free of the storage and
 * locking decisions a dispatch pass has to make. An implementation owes the loop two guarantees: one
 * occurrence is enqueued at most once however many scheduler processes run concurrently, and a
 * schedule that cannot be dispatched right now is passed over rather than allowed to stall the pass.
 *
 * @since  2.0.0
 */
interface Scheduler
{
    /**
     * Enqueue a job for every schedule whose next run has arrived, up to a bounded batch.
     *
     * A pass is one-shot; running the loop is the caller's job. The count reports work done, not work
     * outstanding, and zero covers both "nothing was due" and "everything due was passed over", so a
     * caller cannot use it to tell those apart.
     *
     * @param   ExecutionContext  $context  Caller the dispatch capability is checked against.
     * @param   int               $limit    Most schedules to dispatch in this pass.
     *
     * @return  int  How many schedules this pass dispatched and advanced.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the caller may not
     *          dispatch schedules.
     *
     * @since   2.0.0
     */
    public function dispatchDue(ExecutionContext $context, int $limit = 100): int;
}
