<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Application;

use DateTimeImmutable;
use Kumwe\App\BusinessIntegration\Domain\RecordedDomainEvent;
use Kumwe\App\BusinessIntegration\Domain\RecordedIntegrationEvent;
use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\BusinessIntegration\Application\DomainEventHandler;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\DomainListenerDefinition;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\EventSensitivity;
use Ramsey\Uuid\Uuid;

/**
 * Publishes the disclosure-safe core record fact inside the authoritative mutation transaction.
 *
 * The caller supplies no record values: only definition and version metadata plus field handles already
 * cleared for disclosure. Synchronous listeners run first and may abort the transaction. The same event
 * identity is then appended to the durable outbox, whose insert shares the caller's DBAL connection and
 * therefore commits or rolls back with the record, revision, audit entry, and idempotency result.
 *
 * @since  2.0.0
 */
final readonly class BusinessRecordMutationEventPublisher
{
    /**
     * Bind event publication to the active contracts, listeners, and transactional outbox.
     *
     * @param  EventContractRegistry             $contracts      Exact trusted event contracts.
     * @param  ExtensionContributionRegistrySet  $contributions  Live owner-bound listener registry.
     * @param  OutboxStore                       $outbox         Transactional durable event store.
     * @param  ExtensionExecutionGate            $execution      Live authority for contributed listeners.
     *
     * @since  2.0.0
     */
    public function __construct(
        private EventContractRegistry $contracts,
        private ExtensionContributionRegistrySet $contributions,
        private OutboxStore $outbox,
        private ExtensionExecutionGate $execution,
    ) {
    }

    /**
     * Dispatch and durably append one record mutation fact.
     *
     * @param   ExecutionContext   $context            Authoritative actor, site, organization, and trace.
     * @param   string             $definitionId       Stable aggregate/entity type identifier.
     * @param   int                $definitionVersion  Definition revision used for the write.
     * @param   string             $recordKey          Internal aggregate identity, never a protected value.
     * @param   int                $recordVersion      Aggregate version after the write.
     * @param   string             $operation          Stable mutation operation.
     * @param   list<string>       $disclosedFields    Non-sensitive changed field handles only.
     * @param   DateTimeImmutable  $occurredAt         Same instant used by the record trail.
     *
     * @return  string  Durable event UUID, preserved by every retry and replay.
     *
     * @since   2.0.0
     */
    public function publish(
        ExecutionContext $context,
        string $definitionId,
        int $definitionVersion,
        string $recordKey,
        int $recordVersion,
        string $operation,
        array $disclosedFields,
        DateTimeImmutable $occurredAt,
    ): string {
        sort($disclosedFields, SORT_STRING);
        $systemIdentity = $context->systemActor()?->identifier();
        $event = new RecordedDomainEvent(
            'core.business_record.mutated',
            1,
            Uuid::uuid7()->toString(),
            $occurredAt,
            $systemIdentity === null ? $context->actorId() : null,
            $systemIdentity,
            $context->site()->identifier(),
            $context->organization()?->identifier(),
            $definitionId,
            $recordKey,
            $recordVersion,
            $context->correlationId(),
            $context->requestId(),
            EventSensitivity::INTERNAL,
            [
                'definition_id' => $definitionId,
                'definition_version' => $definitionVersion,
                'operation' => $operation,
                'changed_fields' => $disclosedFields,
            ],
        );

        $entries = $this->contributions->domainListeners()->executableEntries();
        if ($entries !== []) {
            $this->execution->assertCurrent();
        }
        $handlers = [];
        foreach ($entries as $entry) {
            $definition = $entry['definition'];
            if (
                $definition instanceof DomainListenerDefinition
                && $definition->accepts($event->eventType(), $event->schemaVersion(), $event->sensitivity())
                && $entry['implementation'] instanceof DomainEventHandler
            ) {
                $handlers[] = [
                    'definition' => $definition,
                    'handler' => $entry['implementation'],
                ];
            }
        }
        (new DomainEventDispatcher(
            $this->contracts,
            $handlers,
        ))->dispatch($event);
        $this->outbox->append(RecordedIntegrationEvent::fromDomain($event));

        return $event->eventId();
    }
}
