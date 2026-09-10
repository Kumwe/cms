<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Infrastructure;

use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Authorization\SystemPrincipal;
use Kumwe\App\Application\Automation\PermanentFailure;
use Kumwe\App\BusinessIntegration\Application\DurableOutboundAdapterDispatcher;
use Kumwe\App\BusinessIntegration\Application\InboxDisposition;
use Kumwe\App\BusinessIntegration\Application\IntegrationDeliveryBackpressure;
use Kumwe\App\BusinessIntegration\Application\IntegrationEventFanout;
use Kumwe\App\BusinessIntegration\Application\IntegrationEventConsumerDispatcher;
use Kumwe\Extension\Spi\BusinessIntegration\Application\IntegrationEventHandler;
use Kumwe\Extension\Spi\BusinessIntegration\Application\IntegrationEventTransport;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\EventSensitivity;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\IntegrationEvent;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\WebhookContributionDefinition;
use Kumwe\App\BusinessReporting\Application\ProjectionRuntime;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Runtime\RuntimeMaterializationState;
use RuntimeException;

/**
 * Deterministically fans an outbox fact into every active consumer and outbound adapter.
 *
 * Each target owns an independent inbox receipt. If a later target fails, targets already completed
 * are skipped on the next outbox attempt, preventing duplicate effects while preserving at-least-once
 * recovery for unavailable, reordered, or upgraded consumers.
 *
 * @since  2.0.0
 */
final readonly class RuntimeIntegrationEventTransport implements IntegrationEventFanout
{
    /**
     * Create the runtime integration event transport.
     *
     * @param  ExtensionContributionRegistrySet    $contributions  Active owner-bound runtime contribution registries.
     * @param  IntegrationEventConsumerDispatcher  $consumers      Durable dispatcher for internal consumers.
     * @param  DurableOutboundAdapterDispatcher    $outbound       Durable outbound dispatcher used for webhook fan-out.
     * @param  ProjectionRuntime                   $projections    Idempotent live projection dispatcher.
     * @param  SystemPrincipal                     $worker         Stable identity of the claiming worker.
     * @param  RuntimeMaterializationState         $runtime        Trusted active extension runtime.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ExtensionContributionRegistrySet $contributions,
        private IntegrationEventConsumerDispatcher $consumers,
        private DurableOutboundAdapterDispatcher $outbound,
        private ProjectionRuntime $projections,
        private SystemPrincipal $worker,
        private RuntimeMaterializationState $runtime,
    ) {
    }

    /**
     * Return the stable identifier for the runtime integration event transport.
     *
     * @return  string  Stable identifier of the runtime fan-out transport.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return 'core.runtime-fanout';
    }

    /**
     * Return the highest event sensitivity this contribution may receive.
     *
     * @return  EventSensitivity  Maximum event sensitivity accepted by runtime fan-out.
     *
     * @since   2.0.0
     */
    public function sensitivityCeiling(): EventSensitivity
    {
        return EventSensitivity::SECRET;
    }

    /**
     * Publish the supplied event through this declared transport.
     *
     * @param   IntegrationEvent  $event  Versioned event being validated or processed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function publish(IntegrationEvent $event): void
    {
        if (!$this->runtime->trusted || $this->runtime->generation < 0) {
            throw new RuntimeException('Integration delivery requires a trusted runtime generation.');
        }
        $generation = (string) $this->runtime->generation;
        $workerId = $this->runtime->replicaId . ':integration';
        $context = $this->worker->context(
            SiteContext::fromString($event->siteIdentifier()),
            'integration-' . $event->eventId(),
            $event->correlationId(),
        );

        $this->projections->apply($event);

        foreach ($this->contributions->eventConsumers()->executableEntries() as $entry) {
            $definition = $entry['definition'];
            $handler = $entry['implementation'];
            if (!$definition instanceof EventConsumerDefinition || !$handler instanceof IntegrationEventHandler) {
                throw new PermanentFailure('The trusted consumer registry contains an invalid executable entry.');
            }
            if ($definition->eventType() !== $event->eventType()) {
                continue;
            }
            $disposition = $this->consumers->consume(
                $definition,
                $event,
                $handler,
                $context,
                $workerId,
                $generation,
            );
            if (in_array($disposition, [InboxDisposition::BUSY, InboxDisposition::REORDERED], true)) {
                throw new IntegrationDeliveryBackpressure(
                    'An active integration consumer is temporarily at capacity or awaiting event order.',
                );
            }
            if (!in_array($disposition, [InboxDisposition::CLAIMED, InboxDisposition::DUPLICATE], true)) {
                throw new RuntimeException('An active integration consumer is not currently claimable.');
            }
        }

        foreach ($this->contributions->webhooks()->executableEntries() as $entry) {
            $definition = $entry['definition'];
            $adapter = $entry['implementation'];
            if (
                !$definition instanceof WebhookContributionDefinition
                || !$adapter instanceof IntegrationEventTransport
            ) {
                throw new PermanentFailure('The trusted webhook registry contains an invalid executable entry.');
            }
            if (!in_array($event->eventType(), $definition->eventTypes(), true)) {
                continue;
            }
            $disposition = $this->outbound->dispatch(
                $definition,
                $adapter,
                $event,
                $workerId,
                $generation,
            );
            if (in_array($disposition, [InboxDisposition::BUSY, InboxDisposition::REORDERED], true)) {
                throw new IntegrationDeliveryBackpressure(
                    'An active outbound adapter is temporarily at capacity or awaiting event order.',
                );
            }
            if (!in_array($disposition, [InboxDisposition::CLAIMED, InboxDisposition::DUPLICATE], true)) {
                throw new RuntimeException('An active outbound adapter does not support this event revision.');
            }
        }
    }
}
