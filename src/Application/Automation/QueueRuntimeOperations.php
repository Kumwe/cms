<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation;

use Kumwe\Context\Value\ExecutionContext;

/**
 * Authenticated operator read and retention surface for contributed durable queues.
 *
 * @since  2.0.0
 */
interface QueueRuntimeOperations
{
    /**
     * Inspect active policies together with live backlog and retention counts.
     *
     * @param   ExecutionContext  $context  Authenticated automation operator.
     *
     * @return  list<array<string, mixed>>  Queue policies and durable runtime counters.
     *
     * @since   2.0.0
     */
    public function inventory(ExecutionContext $context): array;

    /**
     * Dispose one bounded batch of terminal evidence whose declared retention window elapsed.
     *
     * @param   ExecutionContext  $context  Authenticated automation operator.
     * @param   string            $queue    Active contributed queue to retain.
     * @param   int               $limit    Maximum terminal records deleted or compacted in this call.
     *
     * @return  int  Terminal jobs deleted plus inbox receipt detail compacted.
     *
     * @since   2.0.0
     */
    public function purge(ExecutionContext $context, string $queue, int $limit = 100): int;
}
