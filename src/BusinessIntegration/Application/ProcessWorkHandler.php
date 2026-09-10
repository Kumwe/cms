<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Application;

use Kumwe\App\BusinessIntegration\Domain\ProcessWorkKind;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Idempotent handler for one durable process work contract.
 *
 * @since  2.0.0
 */
interface ProcessWorkHandler
{
    /**
     * Determine whether this handler supports the supplied work kind and name.
     *
     * @param   ProcessWorkKind  $kind  Process-work kind proposed for dispatch.
     * @param   string           $name  Stable contribution or option name being addressed.
     *
     * @return  bool  Whether this handler owns the kind/name pair.
     *
     * @since   2.0.0
     */
    public function supports(ProcessWorkKind $kind, string $name): bool;

    /**
     * Process the supplied item under its authenticated execution context.
     *
     * @param   ProcessWorkLease  $lease    Fenced lease proving ownership of the durable item.
     * @param   ExecutionContext  $context  Authenticated execution context for authorization and audit.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function handle(ProcessWorkLease $lease, ExecutionContext $context): void;
}
