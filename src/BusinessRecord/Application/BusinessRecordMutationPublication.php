<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

use DateTimeImmutable;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\BusinessDefinition\Domain\ComputationMode;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\App\BusinessDefinition\Domain\Sensitivity;
use Kumwe\App\BusinessIntegration\Application\BusinessRecordMutationEventPublisher;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable;
use Kumwe\App\BusinessRecord\Domain\BusinessRecord;
use Kumwe\App\BusinessRecord\Domain\BusinessRecordRevision;
use Kumwe\App\BusinessRecord\Domain\ClientAssertedInstant;
use Kumwe\App\BusinessRecord\Domain\RecordValueGuard;
use Kumwe\Context\Value\ExecutionContext;
use Ramsey\Uuid\Uuid;

/**
 * Publishes the complete disclosure-safe trail for one authoritative record mutation.
 *
 * `BusinessRecordService` remains the owner of the transaction and calls this collaborator only after the
 * authoritative write has succeeded. The revision, audit event, synchronous listeners and durable event are
 * therefore assembled in one independently testable place while still joining the facade's already-open
 * transaction. A failure at any stage escapes to that boundary and rolls the record and every earlier effect
 * back together.
 *
 * @since  2.0.0
 */
final readonly class BusinessRecordMutationPublication
{
    /**
     * Bind publication to the append-only history, audit trail and transactional event path.
     *
     * @param  BusinessRecordRevisionRepository       $revisions     Append-only revision store.
     * @param  AuditRecorder                          $audit         Transactional audit sink.
     * @param  RecordFingerprint                      $fingerprints  Keyed disclosure-safe digest service.
     * @param  ?BusinessRecordMutationEventPublisher  $events        Synchronous and durable event publisher;
     *         nullable only for isolated legacy tests.
     * @param  DocumentCommitTimingRecorder           $timings       Shared collector the document command
     *         reads its revision, audit and event durations from; silent for every other mutation.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessRecordRevisionRepository $revisions,
        private AuditRecorder $audit,
        private RecordFingerprint $fingerprints,
        private ?BusinessRecordMutationEventPublisher $events = null,
        private DocumentCommitTimingRecorder $timings = new DocumentCommitTimingRecorder(),
    ) {
    }

    /**
     * Append the revision, audit event and mutation event for one applied record version.
     *
     * Restricted and secret handles never enter audit metadata or the event payload. Revisions retain the
     * canonical stored snapshot when the definition enables them, with relationship evidence isolated under
     * one reserved key. Calls are deliberately ordered revision, audit, synchronous listeners and outbox; any
     * exception aborts the facade-owned transaction before idempotency can be completed.
     *
     * @param   ExecutionContext        $context        Actor, site and trace of the mutation.
     * @param   EntityTypeDefinition    $definition     Exact definition version used by the write.
     * @param   BusinessRecord          $record         Record version produced by the authoritative write.
     * @param   string                  $operation      Stable mutation label.
     * @param   list<string>            $changedFields  Handles whose stored value changed.
     * @param   DateTimeImmutable       $now            One instant shared by every publication effect.
     * @param   array<string, mixed>    $evidence       Disclosure-safe relationship or aggregate evidence.
     * @param   ?ClientAssertedInstant  $capturedAt     Non-authoritative caller occurrence instant.
     *
     * @return  void
     *
     * @throws  BusinessRecordSchemaUnavailable  When a declared field collides with reserved revision evidence.
     *
     * @since   2.0.0
     */
    public function publish(
        ExecutionContext $context,
        EntityTypeDefinition $definition,
        BusinessRecord $record,
        string $operation,
        array $changedFields,
        DateTimeImmutable $now,
        array $evidence = [],
        ?ClientAssertedInstant $capturedAt = null,
    ): void {
        sort($changedFields, SORT_STRING);
        $snapshot = $this->revisionSnapshot($definition, $record);
        if ($evidence !== []) {
            if (array_key_exists('runtime_relation_evidence', $snapshot)) {
                throw new BusinessRecordSchemaUnavailable('A definition collides with reserved revision evidence.');
            }
            $snapshot['runtime_relation_evidence'] = RecordValueGuard::canonical($evidence);
        }
        if ($definition->revisionsEnabled) {
            $revisionStart = hrtime(true);
            $this->revisions->append(new BusinessRecordRevision(
                Uuid::uuid7()->toString(),
                $record->definitionId,
                $record->definitionVersion,
                $context->site()->identifier(),
                $record->scope->organizationIdentifier,
                $record->recordKey,
                $this->fingerprints->digest($record->recordId),
                $record->version,
                $record->version,
                $operation,
                $snapshot,
                $changedFields,
                $context->actorId(),
                $now,
            ));
            $this->timings->add('revision', (hrtime(true) - $revisionStart) / 1_000_000);
        }

        $metadata = [];
        foreach ($changedFields as $handle) {
            $field = self::optionalField($definition, $handle);
            if (
                $field !== null
                && in_array($field->sensitivity, [Sensitivity::Restricted, Sensitivity::Secret], true)
            ) {
                continue;
            }
            $metadata[] = [
                'field' => $handle,
                'redacted' => false,
            ];
        }
        $auditStart = hrtime(true);
        $this->audit->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $now,
            $context->actorId(),
            'business.record.' . $operation,
            'business_record',
            $record->recordKey,
            'success',
            [
                'definition_id' => $record->definitionId,
                'definition_version' => $record->definitionVersion,
                'record_version' => $record->version,
                'record_identity_digest' => $this->fingerprints->digest($record->recordId),
                'organization_identifier' => $record->scope->organizationIdentifier,
                'changed_fields' => $metadata,
                'mutation_evidence' => RecordValueGuard::canonical($evidence),
                'client_captured_at' => $capturedAt?->toArray(),
            ],
        ));
        $this->timings->add('audit', (hrtime(true) - $auditStart) / 1_000_000);
        $eventStart = hrtime(true);
        $this->events?->publish(
            $context,
            $record->definitionId,
            $record->definitionVersion,
            $record->recordKey,
            $record->version,
            $operation,
            array_column($metadata, 'field'),
            $now,
        );
        $this->timings->add('event', (hrtime(true) - $eventStart) / 1_000_000);
    }

    /**
     * Build the canonical stored snapshot used by revision history.
     *
     * @param   EntityTypeDefinition  $definition  Exact definition governing the record version.
     * @param   BusinessRecord        $record      Stored values to snapshot.
     *
     * @return  array<string, mixed>  Canonical persisted values, excluding virtual computed fields.
     *
     * @since   2.0.0
     */
    private function revisionSnapshot(EntityTypeDefinition $definition, BusinessRecord $record): array
    {
        $snapshot = [];
        foreach ($definition->fields() as $field) {
            if ($field->computed && $field->computationMode === ComputationMode::Virtual) {
                continue;
            }
            if (!array_key_exists($field->handle, $record->values())) {
                continue;
            }
            $snapshot[$field->handle] = RecordValueGuard::canonical($record->values()[$field->handle]);
        }

        return $snapshot;
    }

    /**
     * Find a field by handle without requiring older mutation metadata to remain in the definition.
     *
     * @param   EntityTypeDefinition  $definition  Definition to inspect.
     * @param   string                $handle      Changed handle.
     *
     * @return  ?FieldDefinition  Matching declaration, if retained.
     *
     * @since   2.0.0
     */
    private static function optionalField(EntityTypeDefinition $definition, string $handle): ?FieldDefinition
    {
        foreach ($definition->fields() as $field) {
            if ($field->handle === $handle) {
                return $field;
            }
        }

        return null;
    }
}
