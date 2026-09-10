<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Infrastructure;

use Kumwe\App\Application\Automation\JobQueue;
use Kumwe\App\BusinessReporting\Application\ExportJobDispatcher;
use Kumwe\App\BusinessReporting\Application\ExportQueueProducerContextProvider;
use Kumwe\Context\Value\ExecutionContext;
use Psr\Clock\ClockInterface;

/**
 * Enqueues opaque export ids with a narrow internal producer rather than user automation authority.
 *
 * @since  2.0.0
 */
final readonly class JobQueueExportJobDispatcher implements ExportJobDispatcher
{
    /**
     * Wire durable queueing to producer-context resolution.
     *
     * @param  JobQueue                            $queue     Durable job queue.
     * @param  ExportQueueProducerContextProvider  $producer  Narrow internal producer resolver.
     * @param  ClockInterface                      $clock     Trusted availability clock.
     *
     * @since  2.0.0
     */
    public function __construct(
        private JobQueue $queue,
        private ExportQueueProducerContextProvider $producer,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Enqueue one opaque artifact id on the dedicated exports queue.
     *
     * @param   ExecutionContext  $requestContext  Original request scope.
     * @param   string            $artifactId      Canonical artifact UUID.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function dispatch(ExecutionContext $requestContext, string $artifactId): void
    {
        $this->queue->enqueue(
            $this->producer->forRequest($requestContext),
            'business_reporting.export.generate',
            ['artifact_id' => $artifactId],
            $this->clock->now(),
            'exports',
            0,
            5,
        );
    }
}
