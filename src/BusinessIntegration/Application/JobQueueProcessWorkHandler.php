<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Application;

use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Application\Automation\JobQueue;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\IntegrationContractValidator;
use Kumwe\App\BusinessIntegration\Domain\ProcessWorkKind;
use Psr\Clock\ClockInterface;

/**
 * Adapter that hands process commands and compensations to the existing durable job queue.
 *
 * @since  2.0.0
 */
final readonly class JobQueueProcessWorkHandler implements ProcessWorkHandler
{
    /**
     * Configure a queue destination for process effects.
     *
     * @param  JobQueue        $jobs   Existing durable job queue.
     * @param  ClockInterface  $clock  Immediate availability clock.
     * @param  string          $queue  Destination queue.
     *
     * @since  2.0.0
     */
    public function __construct(
        private JobQueue $jobs,
        private ClockInterface $clock,
        private string $queue = 'default',
    ) {
        IntegrationContractValidator::token($queue, 'Process job queue', 64);
    }

    /**
     * Determine whether this handler supports the supplied work kind and name.
     *
     * @param   ProcessWorkKind  $kind  Process-work kind proposed for dispatch.
     * @param   string           $name  Stable contribution or option name being addressed.
     *
     * @return  bool  Whether this handler owns the supplied work kind and name.
     *
     * @since   2.0.0
     */
    public function supports(ProcessWorkKind $kind, string $name): bool
    {
        return in_array($kind, ProcessWorkKind::cases(), true);
    }

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
    public function handle(ProcessWorkLease $lease, ExecutionContext $context): void
    {
        $this->jobs->enqueue(
            $context,
            $lease->work->name(),
            [
                'process_id' => $lease->processId,
                'process_version' => $lease->processVersion,
                'site_identifier' => $lease->siteIdentifier,
                'organization_id' => $lease->organizationId,
                'work_id' => $lease->work->id(),
                'work_kind' => $lease->work->kind()->value,
                'payload' => $lease->work->payload(),
            ],
            $this->clock->now(),
            $this->queue,
            maximumAttempts: $lease->work->maximumAttempts(),
        );
    }
}
