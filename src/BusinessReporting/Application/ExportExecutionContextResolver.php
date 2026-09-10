<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Application;

use Kumwe\App\BusinessReporting\Domain\ExportArtifact;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Rehydrates current authority for the original accountable export actor.
 *
 * @since  2.0.0
 */
interface ExportExecutionContextResolver
{
    /**
     * Resolve a fresh context matching artifact actor, site, organization and workspace.
     *
     * @param   ExportArtifact    $artifact       Stored request and authority coordinates.
     * @param   ExecutionContext  $workerContext  Narrow system context that claimed the queue job.
     *
     * @return  ExecutionContext  Fresh original-actor context, never the broad worker identity.
     *
     * @since   2.0.0
     */
    public function resolve(ExportArtifact $artifact, ExecutionContext $workerContext): ExecutionContext;
}
