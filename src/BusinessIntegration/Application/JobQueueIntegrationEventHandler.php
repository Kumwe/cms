<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Application;

use LogicException;
use Kumwe\Context\Value\ExecutionContext as HostExecutionContext;
use Kumwe\App\Application\Automation\JobQueue;
use Kumwe\App\BusinessIntegration\Domain\RecordedEventEnvelope;
use Kumwe\Extension\Spi\Application\ExecutionContext;
use Kumwe\Extension\Spi\BusinessIntegration\Application\IntegrationEventHandler;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\IntegrationContractValidator;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\IntegrationEvent;
use Psr\Clock\ClockInterface;
use Kumwe\App\Extension\Runtime\ExtensionExecutionContext;

/**
 * Durable consumer that hands a validated event envelope to the existing job queue.
 *
 * The original event ID is retained in the job payload as the handler's idempotency key. Queue enqueue
 * remains at-least-once, so the target job handler must durably deduplicate that ID before side effects.
 *
 * @since  2.0.0
 */
final readonly class JobQueueIntegrationEventHandler implements IntegrationEventHandler
{
    /**
     * Configure the destination job type.
     *
     * @param  JobQueue        $jobs     Existing durable queue.
     * @param  ClockInterface  $clock    Supplies immediate availability.
     * @param  string          $jobType  Registered target job handler type.
     *
     * @since  2.0.0
     */
    public function __construct(
        private JobQueue $jobs,
        private ClockInterface $clock,
        private string $jobType,
    ) {
        IntegrationContractValidator::identifier($jobType, 'Integration-event job type');
    }

    /**
     * Enqueue the complete validated envelope with its stable event ID.
     *
     * @param   EventConsumerDefinition  $definition  Exact signed consumer declaration.
     * @param   IntegrationEvent         $event       Durable event being consumed.
     * @param   ExecutionContext         $context     Freshly authorised worker context.
     *
     * @return  void
     *
     * @throws  LogicException  When a context not issued by this App reaches the host queue.
     *
     * @since   2.0.0
     */
    public function handle(
        EventConsumerDefinition $definition,
        IntegrationEvent $event,
        ExecutionContext $context,
    ): void {
        $host = ExtensionExecutionContext::host($context);
        if ($host === null) {
            throw new LogicException('An integration-event job requires an App-issued execution context.');
        }
        $this->jobs->enqueue(
            $host,
            $this->jobType,
            ['event_id' => $event->eventId(), 'event' => RecordedEventEnvelope::document($event)],
            $this->clock->now(),
            $definition->queue(),
            maximumAttempts: $definition->maximumAttempts(),
        );
    }
}
