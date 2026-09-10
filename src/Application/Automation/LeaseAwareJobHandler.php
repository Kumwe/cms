<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation;

use Kumwe\Context\Value\ExecutionContext;

/**
 * Opt-in contract for work that may outlive the lease it was claimed under.
 *
 * `Worker` calls this method in place of `JobHandler::handle()` whenever the handler it resolved
 * implements this interface, handing over a `JobLeaseContext` to renew at safe checkpoints; every other
 * handler is untouched and keeps using `handle()`. Renewing pushes the job's lease expiry out and
 * refreshes the worker heartbeat, which is what stops a sibling worker from reaping and re-running a job
 * that is still being worked on. It does not lift the worker's own runtime ceiling — the handler is still
 * aborted once the worker's maximum handler runtime elapses — so this buys elasticity, not unbounded
 * runtime.
 *
 * @since  2.0.0
 */
interface LeaseAwareJobHandler extends JobHandler
{
    /**
     * Execute one claimed job, with a handle for extending its own lease while it works.
     *
     * @param   array<string, mixed>  $payload  Decoded job arguments, in the shape the type's schema declares.
     * @param   ExecutionContext      $context  Authorization context the worker built for the job's owner.
     * @param   JobLeaseContext       $lease    Handle pushing the lease expiry out at safe checkpoints.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function handleWithLease(
        array $payload,
        ExecutionContext $context,
        JobLeaseContext $lease,
    ): void;
}
