<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Application;

use Kumwe\Context\Value\ExecutionContext;

/**
 * Durable internal producer for export generation jobs.
 *
 * @since  2.0.0
 */
interface ExportJobDispatcher
{
    /**
     * Enqueue one artifact id after its metadata transaction commits.
     *
     * @param   ExecutionContext  $requestContext  Original request scope used to choose a producer identity.
     * @param   string            $artifactId      Canonical artifact UUID; no raw parameters enter the queue.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function dispatch(ExecutionContext $requestContext, string $artifactId): void;
}
