<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Application;

use Kumwe\Context\Value\ExecutionContext;

/**
 * Supplies an internal site-scoped producer context for a user-requested export job.
 *
 * @since  2.0.0
 */
interface ExportQueueProducerContextProvider
{
    /**
     * Resolve the narrow system producer allowed to enqueue internal export work.
     *
     * @param   ExecutionContext  $requestContext  Original authenticated request context.
     *
     * @return  ExecutionContext  Site-scoped background producer, never the requesting user's grants.
     *
     * @since   2.0.0
     */
    public function forRequest(ExecutionContext $requestContext): ExecutionContext;
}
