<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation;

use Kumwe\Context\Value\ExecutionContext;

/**
 * Contract for the unit of work behind one queued job type.
 *
 * The worker resolves a claimed job's handler by type through `JobHandlerRegistry` and calls it with
 * the decoded payload and a context already narrowed to the job's owner — the owning site's system
 * principal, or the internal identity an installation-global type is pinned to. An implementation must
 * be safe to run more than once, because a process can die after an external side effect but before
 * completion is recorded, and the job then becomes claimable again. Throwing `PermanentFailure` buries
 * the job immediately; any other exception is transient, so the job is retried with backoff until its
 * attempts run out. Work that legitimately outlives its lease implements `LeaseAwareJobHandler`.
 *
 * @since  2.0.0
 */
interface JobHandler
{
    /**
     * Name the job type this handler executes.
     *
     * @return  string  Registered type name, unique across every handler wired into the registry.
     *
     * @since   2.0.0
     */
    public function type(): string;

    /**
     * Execute one claimed job of this handler's type.
     *
     * @param   array<string, mixed>  $payload  Decoded job arguments, in the shape the type's schema declares.
     * @param   ExecutionContext      $context  Authorization context the worker built for the job's owner.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function handle(array $payload, ExecutionContext $context): void;
}
