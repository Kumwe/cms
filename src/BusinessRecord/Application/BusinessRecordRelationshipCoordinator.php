<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\App\BusinessDefinition\Domain\IdentityStrategy;
use Kumwe\App\BusinessDefinition\Domain\PortalOperation;
use Kumwe\App\BusinessDefinition\Domain\RelationshipDefinition;
use Kumwe\App\BusinessDefinition\Domain\RelationshipKind;
use Kumwe\App\BusinessDefinition\Domain\ScopeMode;
use Kumwe\App\BusinessRecord\Application\Command\DocumentLineInput;
use Kumwe\App\BusinessRecord\Application\Command\RelateRecordsCommand;
use Kumwe\App\BusinessRecord\Application\Command\WriteDocumentCommand;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordNotFound;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordReferenceConflict;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordValidationFailed;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRelationshipRejected;
use Kumwe\App\BusinessRecord\Domain\BusinessRecord;
use Kumwe\App\BusinessRecord\Domain\RecordScope;
use Kumwe\App\BusinessRecord\Domain\RecordValueGuard;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessPlan;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\BusinessSecurity\Application\FieldAccessUsage;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Decides relationship and owned-line mutations before the record facade writes anything.
 *
 * The coordinator owns the coherent part that used to be spread across four facade methods: resolving a
 * pinned relationship target, enforcing its nested row and field plan, normalizing embedded line values,
 * and turning a whole document collection into an immutable mutation intent. It deliberately owns no
 * transaction, authorization capability, idempotency claim, audit entry or repository write. Those remain
 * with `BusinessRecordService`, which calls this seam inside its one authoritative transaction.
 *
 * @since  2.0.0
 */
final readonly class BusinessRecordRelationshipCoordinator
{
    /**
     * Wire relationship decisions to their definition, policy, value and read-side collaborators.
     *
     * @param  BusinessRecordReadRepository      $reads        Read port for target identities and owned lines.
     * @param  BusinessRecordMutationFence       $fence        Generation fence held while targets are resolved.
     * @param  BusinessRecordDefinitionResolver  $definitions  Resolver for live and pinned target definitions.
     * @param  RecordValueCodec                  $values       Codec settling identities and reference values.
     * @param  RecordRuleValidator               $rules        Validator normalizing line values and invariants.
     * @param  DocumentCommitTimingRecorder      $timings      Shared collector the document command reads
     *         its fence lock-wait durations from; silent for every other command.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessRecordReadRepository $reads,
        private BusinessRecordMutationFence $fence,
        private BusinessRecordDefinitionResolver $definitions,
        private RecordValueCodec $values,
        private RecordRuleValidator $rules,
        private DocumentCommitTimingRecorder $timings = new DocumentCommitTimingRecorder(),
    ) {
    }

    /**
     * Resolve a relationship against the exact definition version a record is pinned to.
     *
     * Legacy ordered-line fields enter through `runtimeRelationship()` and therefore produce the same
     * typed owned-line declaration as a native relationship.
     *
     * @param   EntityTypeDefinition  $definition  Pinned source definition.
     * @param   string                $handle      Relationship or ordered-line handle named by the caller.
     *
     * @return  RelationshipDefinition  Declared runtime relationship.
     *
     * @throws  BusinessRelationshipRejected  When the pinned definition declares no such relationship.
     *
     * @since   2.0.0
     */
    public function relationship(EntityTypeDefinition $definition, string $handle): RelationshipDefinition
    {
        return $definition->runtimeRelationship($handle)
            ?? throw new BusinessRelationshipRejected('The relationship is not declared by the pinned definition.');
    }

    /**
     * Resolve a selector handle to its target and distinguish field references from relationships.
     *
     * @param   EntityTypeDefinition  $definition  Source definition declaring the handle.
     * @param   string                $handle      Relationship or entity-reference field handle.
     *
     * @return  array{string, ?RelationshipDefinition}  Target identifier and relationship when applicable.
     *
     * @throws  BusinessRecordNotFound  When the handle is absent or has no usable target.
     *
     * @since   2.0.0
     */
    public function relatedTarget(EntityTypeDefinition $definition, string $handle): array
    {
        $field = $this->optionalField($definition, $handle);
        if ($field?->type === 'core.entity_reference') {
            $target = $field->configuration['target'] ?? null;
            if (!is_string($target)) {
                throw new BusinessRecordNotFound();
            }

            return [$target, null];
        }
        $relationship = $definition->runtimeRelationship($handle)
            ?? throw new BusinessRecordNotFound();

        return [$relationship->target, $relationship];
    }

    /**
     * Decide whether a definition permits one field to be submitted for the requested operation.
     *
     * @param   FieldDefinition   $field  Field being offered for input.
     * @param   FieldAccessUsage  $usage  Create or update operation being performed.
     *
     * @return  bool  True only when immutable definition flags admit that input.
     *
     * @since   2.0.0
     */
    public function inputFieldAvailable(FieldDefinition $field, FieldAccessUsage $usage): bool
    {
        if ($field->readOnly || $field->computed || $field->serverOnly || $field->formula !== null) {
            return false;
        }

        return match ($usage) {
            FieldAccessUsage::Create => $field->createVisible,
            FieldAccessUsage::Update => $field->updateVisible && !$field->immutableAfterCreate,
            default => false,
        };
    }

    /**
     * Enforce a related target's exact portal exposure without affecting other authenticated surfaces.
     *
     * @param   ExecutionContext      $context    Authenticated site and surface.
     * @param   EntityTypeDefinition  $target     Definition reached through the source relationship.
     * @param   PortalOperation       $operation  Exact target-side portal operation required.
     *
     * @return  void
     *
     * @throws  BusinessRecordNotFound  When a portal target or operation is not explicitly exposed.
     *
     * @since   2.0.0
     */
    public function assertPortalTargetOperation(
        ExecutionContext $context,
        EntityTypeDefinition $target,
        PortalOperation $operation,
    ): void {
        if (
            $context->surface() === AuthenticatedSurface::Portal
            && (!$target->portalExposure || !$target->allowsPortalOperation($operation))
        ) {
            throw new BusinessRecordNotFound();
        }
    }

    /**
     * Decide whether a nested target plan may release the target's public identity.
     *
     * @param   EntityTypeDefinition      $target  Declared target definition.
     * @param   BusinessRecordAccessPlan  $access  Nested plan rooted at the source relationship or field.
     *
     * @return  bool  True only for the exact target and its disclosed identity field.
     *
     * @since   2.0.0
     */
    public function relatedTargetAccessible(
        EntityTypeDefinition $target,
        BusinessRecordAccessPlan $access,
    ): bool {
        return hash_equals($target->id, $access->resourceIdentifier)
            && $access->fields->allows(
                FieldAccessUsage::PublicReference,
                $this->identityField($target)->handle,
            );
    }

    /**
     * Require a nested relation plan to disclose the declared target's public identity.
     *
     * @param   EntityTypeDefinition      $target  Declared target definition.
     * @param   BusinessRecordAccessPlan  $access  Nested plan rooted at the source relationship or field.
     *
     * @return  void
     *
     * @throws  BusinessRecordNotFound  When the plan points elsewhere or withholds the target identity.
     *
     * @since   2.0.0
     */
    public function assertRelatedTargetAccess(
        EntityTypeDefinition $target,
        BusinessRecordAccessPlan $access,
    ): void {
        if (!$this->relatedTargetAccessible($target, $access)) {
            throw new BusinessRecordNotFound();
        }
    }

    /**
     * Reject a submitted field set without revealing which handle was absent or forbidden.
     *
     * @param   BusinessRecordAccessPlan  $access  Authoritative field-use decision for the operation.
     * @param   FieldAccessUsage          $usage   Create or update use being attempted.
     * @param   list<string>              $fields  Caller-submitted handles.
     *
     * @return  void
     *
     * @throws  BusinessRecordValidationFailed  When any submitted handle is unavailable.
     *
     * @since   2.0.0
     */
    private function assertFieldInput(
        BusinessRecordAccessPlan $access,
        FieldAccessUsage $usage,
        array $fields,
    ): void {
        foreach ($fields as $field) {
            if (!$access->fields->allows($usage, $field)) {
                throw new BusinessRecordValidationFailed([
                    new ValidationViolation(
                        'record',
                        'field_access',
                        'One or more submitted fields are unavailable.',
                    ),
                ]);
            }
        }
    }

    /**
     * Project trusted validator failures through the exact operation field-disclosure plan.
     *
     * @param   BusinessRecordValidationFailed  $failure     Complete trusted validator result.
     * @param   ExecutionContext                $context     Authenticated site and surface.
     * @param   EntityTypeDefinition            $definition  Definition whose immutable flags are ceilings.
     * @param   BusinessRecordAccessPlan        $access      Exact operation or owned-line access plan.
     * @param   FieldAccessUsage                $usage       Create or update disclosure use.
     *
     * @return  BusinessRecordValidationFailed  Omission-safe caller-visible validation failure.
     *
     * @since   2.0.0
     */
    private function validationForAccess(
        BusinessRecordValidationFailed $failure,
        ExecutionContext $context,
        EntityTypeDefinition $definition,
        BusinessRecordAccessPlan $access,
        FieldAccessUsage $usage,
    ): BusinessRecordValidationFailed {
        $invariants = [];
        foreach ($definition->recordInvariants() as $invariant) {
            $invariants[$invariant->handle] = true;
        }
        $visible = [];
        $withheld = false;
        foreach ($failure->violations as $violation) {
            if (isset($invariants[$violation->field])) {
                $visible[] = $violation;
                continue;
            }
            $field = $this->optionalField($definition, $violation->field);
            if (
                $field !== null
                && $access->fields->allows($usage, $field->handle)
                && $this->inputFieldAvailable($field, $usage)
                && $this->validationReferenceAvailable($context, $field, $access)
            ) {
                $visible[] = $violation;
            } else {
                $withheld = true;
            }
        }
        if ($withheld) {
            $visible[] = new ValidationViolation(
                'record',
                'field_access',
                'One or more submitted fields are unavailable.',
            );
        }
        if ($visible === []) {
            throw new InvalidArgumentException('A validation failure must retain at least one safe violation.');
        }

        return new BusinessRecordValidationFailed($visible);
    }

    /**
     * Exchange named entity-reference values for the internal storage key each one points at.
     *
     * @param   ExecutionContext          $context     Actor and site the resolution runs as.
     * @param   EntityTypeDefinition      $definition  Definition whose fields are inspected for references.
     * @param   RecordScope               $scope       Scope the source record belongs to.
     * @param   BusinessRecordAccessPlan  $access      Source plan carrying exact nested target decisions.
     * @param   array<string, mixed>      $values      Value set to rewrite, keyed by field handle.
     * @param   list<string>              $handles     Handles this pass is allowed to touch.
     *
     * @return  array<string, mixed>  Values with named references replaced by internal record keys.
     *
     * @throws  BusinessRecordValidationFailed  When one or more submitted references cannot be resolved.
     * @throws  BusinessRecordDefinitionUnavailable  When a declared target is unavailable on this site.
     * @throws  BusinessRecordSchemaUnavailable  When a target schema is not installed or is inconsistent.
     *
     * @since   2.0.0
     */
    private function resolveEntityReferences(
        ExecutionContext $context,
        EntityTypeDefinition $definition,
        RecordScope $scope,
        BusinessRecordAccessPlan $access,
        array $values,
        array $handles,
    ): array {
        $violations = [];
        foreach ($definition->fields() as $field) {
            if ($field->type !== 'core.entity_reference' || !in_array($field->handle, $handles, true)) {
                continue;
            }
            $value = $values[$field->handle] ?? null;
            if ($value === null) {
                continue;
            }
            $targetHandle = $field->configuration['target'] ?? null;
            if (!is_string($value) || !is_string($targetHandle)) {
                $violations[] = new ValidationViolation(
                    $field->handle,
                    'reference',
                    'The entity reference or target definition is invalid.',
                );
                continue;
            }
            try {
                $targetAccess = $access->related($field->handle);
                if ($targetAccess === null) {
                    throw new BusinessRecordNotFound();
                }
                $fenceStart = hrtime(true);
                $generation = $this->fence->lock($context, $targetHandle);
                $this->timings->add('lock_wait', (hrtime(true) - $fenceStart) / 1_000_000);
                $target = $this->definitions->forCreate($context, $targetHandle);
                $generation->assertMatches($target);
                $targetScope = $this->scope($target, $context, $scope->organizationIdentifier);
                $identityField = $this->identityField($target->definition);
                $this->assertPortalTargetOperation($context, $target->definition, PortalOperation::Read);
                $this->assertRelatedTargetAccess($target->definition, $targetAccess);
                $targetId = $this->values->identity(
                    $target->definition,
                    [$identityField->handle => $value],
                    null,
                );
                $identity = $this->reads->identity($target, $targetScope, $targetAccess, $targetId);
                if ($identity === null) {
                    throw new BusinessRecordNotFound();
                }
                $values[$field->handle] = $identity->recordKey;
            } catch (BusinessRecordNotFound | InvalidArgumentException) {
                $violations[] = new ValidationViolation(
                    $field->handle,
                    'reference',
                    'The referenced business record does not exist in this scope.',
                );
            }
        }
        if ($violations !== []) {
            throw new BusinessRecordValidationFailed($violations);
        }

        return $values;
    }

    /**
     * Resolve the pinned definition of the line type an owned-line collection stores.
     *
     * @param   ExecutionContext            $context       Actor and site the resolution runs as.
     * @param   ResolvedBusinessDefinition  $owner         Owner whose blueprint names the line table.
     * @param   RelationshipDefinition      $relationship  Owned-line relationship being written.
     *
     * @return  ResolvedBusinessDefinition  Line type at the version the owner table was generated for.
     *
     * @throws  BusinessRelationshipRejected  When the owner has no usable installed table for the collection.
     * @throws  BusinessRecordDefinitionUnavailable  When the pinned line definition is unavailable.
     * @throws  BusinessRecordSchemaUnavailable  When the line schema is not installed and active.
     *
     * @since   2.0.0
     */
    private function lineDefinition(
        ExecutionContext $context,
        ResolvedBusinessDefinition $owner,
        RelationshipDefinition $relationship,
    ): ResolvedBusinessDefinition {
        $table = $owner->installation->blueprint->table('line:' . $relationship->handle)
            ?? throw new BusinessRelationshipRejected('The owned-line table is unavailable.');
        $version = $table->options['target_definition_version'] ?? null;
        if (!is_int($version)) {
            throw new BusinessRelationshipRejected('The owned-line pinned definition version is unavailable.');
        }

        $fenceStart = hrtime(true);
        $generation = $this->fence->lock($context, $relationship->target);
        $this->timings->add('lock_wait', (hrtime(true) - $fenceStart) / 1_000_000);
        $line = $this->definitions->pinned($context, $relationship->target, $version);
        $generation->assertMatches($line);

        return $line;
    }

    /**
     * Validate and normalize one owned line for the public single-line relate command.
     *
     * @param   RelateRecordsCommand        $command       Public command carrying the line identity and values.
     * @param   ResolvedBusinessDefinition  $owner         Pinned owner definition and installed schema.
     * @param   RecordScope                 $scope         Scope inherited by the line.
     * @param   RelationshipDefinition      $relationship  Owned collection the line is entering.
     * @param   BusinessRecordAccessPlan    $access        Nested row, field and target policy for the line.
     *
     * @return  OwnedLineCreateIntent  Pinned type, key, identity and normalized values ready for persistence.
     *
     * @throws  BusinessRelationshipRejected  When the relationship is not owned or identity is invalid.
     * @throws  BusinessRecordValidationFailed  When line values break field or reference rules.
     * @throws  BusinessRecordNotFound  When nested policy hides the resulting line.
     *
     * @since   2.0.0
     */
    public function prepareOwnedLineCreate(
        RelateRecordsCommand $command,
        ResolvedBusinessDefinition $owner,
        RecordScope $scope,
        RelationshipDefinition $relationship,
        BusinessRecordAccessPlan $access,
    ): OwnedLineCreateIntent {
        if ($relationship->kind !== RelationshipKind::OwnedLineCollection) {
            throw new BusinessRelationshipRejected('Only an owned-line relationship accepts embedded values.');
        }
        $this->assertFieldInput($access, FieldAccessUsage::Create, array_keys($command->targetValues));
        $line = $this->lineDefinition($command->context, $owner, $relationship);
        $this->assertPortalTargetOperation($command->context, $line->definition, PortalOperation::Relation);
        $this->assertRelatedTargetAccess($line->definition, $access);
        try {
            $recordId = $this->values->identity(
                $line->definition,
                $command->targetValues,
                $command->targetRecordId,
            );
        } catch (InvalidArgumentException $exception) {
            throw new BusinessRelationshipRejected($exception->getMessage());
        }
        $recordKey = $line->definition->identityStrategy === IdentityStrategy::Uuid
            ? $recordId
            : Uuid::uuid7()->toString();
        try {
            $values = $this->rules->create(
                $line->definition,
                $command->targetValues,
                $line->definition->siteIdentifier,
                $recordKey,
                $recordId,
            );
        } catch (BusinessRecordValidationFailed $exception) {
            throw $this->validationForAccess(
                $exception,
                $command->context,
                $line->definition,
                $access,
                FieldAccessUsage::Create,
            );
        }
        $values = $this->resolveEntityReferences(
            $command->context,
            $line->definition,
            $scope,
            $access,
            $values,
            array_keys($values),
        );
        if (!$access->records->allows($values)) {
            throw new BusinessRecordNotFound();
        }

        return new OwnedLineCreateIntent($line, $recordKey, $recordId, $values);
    }

    /**
     * Resolve one owned-line identity inside its owner for detach or reorder intent.
     *
     * @param   ExecutionContext            $context       Actor and site the lookup runs as.
     * @param   ResolvedBusinessDefinition  $owner         Pinned owner definition.
     * @param   BusinessRecord              $ownerRecord   Owner record containing the line.
     * @param   RelationshipDefinition      $relationship  Owned collection being addressed.
     * @param   BusinessRecordAccessPlan    $access        Nested target and row policy.
     * @param   string                      $recordId      Caller-facing line identity.
     * @param   PortalOperation             $operation     Portal exposure required for this use case.
     *
     * @return  string  Internal storage key of the owned line.
     *
     * @throws  BusinessRecordNotFound  When the line or its nested authorization is unavailable.
     *
     * @since   2.0.0
     */
    public function ownedLineKey(
        ExecutionContext $context,
        ResolvedBusinessDefinition $owner,
        BusinessRecord $ownerRecord,
        RelationshipDefinition $relationship,
        BusinessRecordAccessPlan $access,
        string $recordId,
        PortalOperation $operation,
    ): string {
        $line = $this->lineDefinition($context, $owner, $relationship);
        $this->assertPortalTargetOperation($context, $line->definition, $operation);
        $this->assertRelatedTargetAccess($line->definition, $access);
        $identity = $this->reads->ownedLineIdentity(
            $owner,
            $ownerRecord,
            $relationship,
            $line,
            $access,
            $recordId,
        ) ?? throw new BusinessRecordNotFound();

        return $identity->recordKey;
    }

    /**
     * Resolve an ordered list of owned-line identities under one pinned target generation.
     *
     * The line definition and nested authorization are settled once for the collection, preserving the
     * original reorder query count instead of repeating definition work for every member.
     *
     * @param   ExecutionContext            $context       Actor and site the lookup runs as.
     * @param   ResolvedBusinessDefinition  $owner         Pinned owner definition.
     * @param   BusinessRecord              $ownerRecord   Owner record containing every line.
     * @param   RelationshipDefinition      $relationship  Owned collection being reordered.
     * @param   BusinessRecordAccessPlan    $access        Nested target and row policy.
     * @param   list<string>                $recordIds     Caller-facing line identities in requested order.
     * @param   PortalOperation             $operation     Portal exposure required for this use case.
     *
     * @return  list<string>  Internal line keys in the same order.
     *
     * @throws  BusinessRecordNotFound  When one line or its nested authorization is unavailable.
     *
     * @since   2.0.0
     */
    public function ownedLineKeys(
        ExecutionContext $context,
        ResolvedBusinessDefinition $owner,
        BusinessRecord $ownerRecord,
        RelationshipDefinition $relationship,
        BusinessRecordAccessPlan $access,
        array $recordIds,
        PortalOperation $operation,
    ): array {
        $line = $this->lineDefinition($context, $owner, $relationship);
        $this->assertPortalTargetOperation($context, $line->definition, $operation);
        $this->assertRelatedTargetAccess($line->definition, $access);
        $keys = [];
        foreach ($recordIds as $recordId) {
            $identity = $this->reads->ownedLineIdentity(
                $owner,
                $ownerRecord,
                $relationship,
                $line,
                $access,
                $recordId,
            ) ?? throw new BusinessRecordNotFound();
            $keys[] = $identity->recordKey;
        }

        return $keys;
    }

    /**
     * Enforce policy and scope invariants on a loaded ordinary relationship target.
     *
     * @param   ExecutionContext          $context  Actor and surface performing the relationship mutation.
     * @param   BusinessRecord            $source   Source record declaring the relationship.
     * @param   EntityTypeDefinition      $target   Pinned target definition.
     * @param   BusinessRecord            $record   Loaded target record.
     * @param   BusinessRecordAccessPlan  $access   Nested target row and identity policy.
     * @param   PortalOperation           $portal   Exact target portal operation required.
     *
     * @return  void
     *
     * @throws  BusinessRecordNotFound  When target policy or portal exposure refuses the row.
     * @throws  BusinessRecordReferenceConflict  When source and target scopes differ.
     *
     * @since   2.0.0
     */
    public function assertExistingTarget(
        ExecutionContext $context,
        BusinessRecord $source,
        EntityTypeDefinition $target,
        BusinessRecord $record,
        BusinessRecordAccessPlan $access,
        PortalOperation $portal,
    ): void {
        $this->assertPortalTargetOperation($context, $target, $portal);
        $this->assertRelatedTargetAccess($target, $access);
        $this->assertTargetRow($record, $access);
        $this->sameScope($source, $record);
    }

    /**
     * Require nested row policy to admit an already loaded relationship target.
     *
     * Unrelate deliberately uses this without a new scope comparison so a legacy or archived link that
     * can no longer be created remains removable under the same public behavior as before extraction.
     *
     * @param   BusinessRecord            $record  Loaded target record.
     * @param   BusinessRecordAccessPlan  $access  Nested target row policy.
     *
     * @return  void
     *
     * @throws  BusinessRecordNotFound  When nested row policy withholds the target.
     *
     * @since   2.0.0
     */
    public function assertTargetRow(BusinessRecord $record, BusinessRecordAccessPlan $access): void
    {
        if (!$access->records->allows($record->values())) {
            throw new BusinessRecordNotFound();
        }
    }

    /**
     * Refuse target identities that collapse onto the same storage row after normalization.
     *
     * @param   list<string>  $keys  Internal target keys in caller-requested order.
     *
     * @return  void
     *
     * @throws  BusinessRelationshipRejected  When one target key occurs more than once.
     *
     * @since   2.0.0
     */
    public function assertUniqueTargetKeys(array $keys): void
    {
        if (count(array_unique($keys)) !== count($keys)) {
            throw new BusinessRelationshipRejected('Normalized relationship identities are duplicated.');
        }
    }

    /**
     * Prepare the whole owned-line replacement described by one aggregate document command.
     *
     * @param   WriteDocumentCommand        $command  Whole-document mutation being applied.
     * @param   ResolvedBusinessDefinition  $owner    Pinned header definition and installed schema.
     * @param   RecordScope                 $scope    Site and organization inherited by every line.
     * @param   ?BusinessRecord             $header   Existing header, or null while creating.
     * @param   BusinessRecordAccessPlan    $access   Header plan carrying the exact nested line plan.
     *
     * @return  OwnedLineMutationIntent  Validated final collection and the persistence changes it implies.
     *
     * @throws  BusinessRelationshipRejected  When the collection cannot be represented by this command.
     * @throws  BusinessRecordValidationFailed  When a line violates its definition.
     * @throws  BusinessRecordNotFound  When nested policy hides a stored or submitted line.
     *
     * @since   2.0.0
     */
    public function prepareDocumentMutation(
        WriteDocumentCommand $command,
        ResolvedBusinessDefinition $owner,
        RecordScope $scope,
        ?BusinessRecord $header,
        BusinessRecordAccessPlan $access,
    ): OwnedLineMutationIntent {
        $relationship = $this->relationship($owner->definition, $command->relationship);
        if ($relationship->kind !== RelationshipKind::OwnedLineCollection) {
            throw new BusinessRelationshipRejected('A document is written over a declared owned-line collection.');
        }
        $relatedAccess = $access->related($relationship->handle) ?? throw new BusinessRecordNotFound();
        $line = $this->lineDefinition($command->context, $owner, $relationship);
        if ($line->definition->invariantLineDependencies() !== []) {
            throw new BusinessRelationshipRejected(
                'A line type declaring its own aggregate invariant needs a command that writes its lines too.',
            );
        }
        $this->assertPortalTargetOperation($command->context, $line->definition, PortalOperation::Relation);
        $this->assertRelatedTargetAccess($line->definition, $relatedAccess);
        $stored = $this->storedDocumentLines($owner, $header, $relationship, $line, $relatedAccess);
        [$writes, $removed, $renumber] = $this->prepareDocumentLines(
            $command,
            $scope,
            $relatedAccess,
            $line->definition,
            $stored,
        );

        return new OwnedLineMutationIntent($relationship, $line, $writes, $removed, $renumber);
    }

    /**
     * Gather every owned-line collection one definition's aggregate invariants reduce.
     *
     * @param   ExecutionContext                                 $context   Actor and site the gather runs as.
     * @param   ResolvedBusinessDefinition                       $owner     Pinned definition declaring rules.
     * @param   ?BusinessRecord                                  $record    Stored header, or null while creating.
     * @param   array<string, list<array<string, scalar|null>>>  $prepared  Already-settled collections.
     *
     * @return  array<string, list<array<string, scalar|null>>>  Collections keyed by relationship handle.
     *
     * @throws  BusinessRelationshipRejected  When one stored collection exceeds the command ceiling.
     *
     * @since   2.0.0
     */
    public function invariantLineValues(
        ExecutionContext $context,
        ResolvedBusinessDefinition $owner,
        ?BusinessRecord $record,
        array $prepared = [],
    ): array {
        $collections = [];
        foreach (array_keys($owner->definition->invariantLineDependencies()) as $handle) {
            if (array_key_exists($handle, $prepared)) {
                $collections[$handle] = $prepared[$handle];
                continue;
            }
            if ($record === null) {
                $collections[$handle] = [];
                continue;
            }
            $relationship = $this->relationship($owner->definition, $handle);
            $line = $this->lineDefinition($context, $owner, $relationship);
            $rows = $this->reads->ownedLinesForDocumentIntegrity(
                $owner,
                $record,
                $relationship,
                $line,
                WriteDocumentCommand::MAXIMUM_LINES + 1,
            );
            if (count($rows) > WriteDocumentCommand::MAXIMUM_LINES) {
                throw new BusinessRelationshipRejected(
                    'A document holds more lines than one aggregate invariant may reduce.',
                );
            }
            $collections[$handle] = array_map(
                static fn (StoredOwnedLine $row): array => RecordExpressionValues::from($row->values),
                $rows,
            );
        }

        return $collections;
    }

    /**
     * Re-judge aggregate invariants after a public single-line relationship mutation.
     *
     * @param   ExecutionContext            $context  Actor and site the write ran as.
     * @param   ResolvedBusinessDefinition  $owner    Pinned owner definition supplying the invariants.
     * @param   BusinessRecord              $record   Owner at the version produced by the write.
     *
     * @return  void
     *
     * @throws  BusinessRecordValidationFailed  When the changed collection breaks an aggregate invariant.
     * @throws  BusinessRelationshipRejected  When a collection is too large to judge as a whole.
     *
     * @since   2.0.0
     */
    public function assertAggregateInvariants(
        ExecutionContext $context,
        ResolvedBusinessDefinition $owner,
        BusinessRecord $record,
    ): void {
        if ($owner->definition->invariantLineDependencies() === []) {
            return;
        }
        $this->rules->assertLineAggregates(
            $owner->definition,
            $record->values(),
            $this->invariantLineValues($context, $owner, $record),
        );
    }

    /**
     * Refuse a link whose two ends do not occupy the same site and organization.
     *
     * @param   BusinessRecord  $source  Record declaring the relationship.
     * @param   BusinessRecord  $target  Record being linked to it.
     *
     * @return  void
     *
     * @throws  BusinessRecordReferenceConflict  When either scope coordinate differs.
     *
     * @since   2.0.0
     */
    private function sameScope(BusinessRecord $source, BusinessRecord $target): void
    {
        if ($source->scope->toArray() !== $target->scope->toArray()) {
            throw new BusinessRecordReferenceConflict();
        }
    }

    /**
     * Read the stored document collection whole and refuse a policy-filtered partial replacement.
     *
     * @param   ResolvedBusinessDefinition  $owner         Pinned header definition.
     * @param   ?BusinessRecord             $header        Existing header, or null while creating.
     * @param   RelationshipDefinition      $relationship  Owned collection being written.
     * @param   ResolvedBusinessDefinition  $line          Pinned line definition.
     * @param   BusinessRecordAccessPlan    $access        Nested row policy for the collection.
     *
     * @return  array<string, StoredOwnedLine>  Stored lines keyed by caller-facing identity.
     *
     * @throws  BusinessRelationshipRejected  When the stored collection exceeds one command.
     * @throws  BusinessRecordNotFound  When row policy hides one stored line.
     *
     * @since   2.0.0
     */
    private function storedDocumentLines(
        ResolvedBusinessDefinition $owner,
        ?BusinessRecord $header,
        RelationshipDefinition $relationship,
        ResolvedBusinessDefinition $line,
        BusinessRecordAccessPlan $access,
    ): array {
        if ($header === null) {
            return [];
        }
        $rows = $this->reads->ownedLinesForDocumentIntegrity(
            $owner,
            $header,
            $relationship,
            $line,
            WriteDocumentCommand::MAXIMUM_LINES + 1,
        );
        if (count($rows) > WriteDocumentCommand::MAXIMUM_LINES) {
            throw new BusinessRelationshipRejected('The stored document holds more lines than one command may write.');
        }
        $stored = [];
        foreach ($rows as $row) {
            if (!$access->records->allows($row->values)) {
                throw new BusinessRecordNotFound();
            }
            $stored[$row->recordId] = $row;
        }

        return $stored;
    }

    /**
     * Turn submitted document lines into the final dense collection and its delete intent.
     *
     * @param   WriteDocumentCommand            $command     Document mutation being prepared.
     * @param   RecordScope                     $scope       Scope inherited by every line.
     * @param   BusinessRecordAccessPlan        $access      Nested line policy.
     * @param   EntityTypeDefinition            $definition  Pinned line definition.
     * @param   array<string, StoredOwnedLine>  $stored      Existing collection keyed by identity.
     *
     * @return  array{list<OwnedLineWrite>, list<string>, bool}  Writes, removals and renumber requirement.
     *
     * @since   2.0.0
     */
    private function prepareDocumentLines(
        WriteDocumentCommand $command,
        RecordScope $scope,
        BusinessRecordAccessPlan $access,
        EntityTypeDefinition $definition,
        array $stored,
    ): array {
        $normalized = [];
        $claimed = [];
        $renumber = false;
        foreach ($command->lines as $position => $line) {
            try {
                $recordId = $this->values->identity($definition, $line->values, $line->recordId);
            } catch (InvalidArgumentException $exception) {
                throw new BusinessRelationshipRejected($exception->getMessage());
            }
            if (isset($claimed[$recordId])) {
                throw new BusinessRelationshipRejected('A document names one line identity more than once.');
            }
            $claimed[$recordId] = true;
            $existing = $stored[$recordId] ?? null;
            if ($existing !== null && $existing->position !== $position) {
                $renumber = true;
            }
            $normalized[] = $this->normalizeDocumentLine(
                $access,
                $definition,
                $line,
                $recordId,
                $position,
                $existing,
            );
        }
        $index = $this->indexDocumentReferences($command->context, $definition, $scope, $access, $normalized);
        $prepared = [];
        foreach ($normalized as $entry) {
            $prepared[] = $this->settleDocumentLine($command->context, $access, $definition, $entry, $index);
        }
        if ($renumber) {
            $prepared = array_map(
                static fn (OwnedLineWrite $line): OwnedLineWrite => $line->storedVersion === null || $line->modified
                    ? $line
                    : new OwnedLineWrite(
                        $line->recordKey,
                        $line->recordId,
                        $line->position,
                        $line->values,
                        $line->storedVersion,
                        true,
                        false,
                    ),
                $prepared,
            );
        }
        $removed = [];
        foreach ($stored as $identity => $row) {
            if (!isset($claimed[$identity])) {
                $removed[] = $row->recordKey;
            }
        }

        return [$prepared, $removed, $renumber];
    }

    /**
     * Normalize one submitted line as far as it can go before its references are known.
     *
     * Everything here is local to the line: field-input policy, identity, and the validator's create or
     * update rules. Nothing here touches the database, which is what lets the whole collection reach this
     * point before a single reference lookup is issued. A validation failure is captured rather than
     * thrown, because the line loop that consumes this has to raise failures in submitted order and the
     * reference pass sits between the two.
     *
     * @param   BusinessRecordAccessPlan  $access      Nested row and field policy.
     * @param   EntityTypeDefinition      $definition  Pinned line definition.
     * @param   DocumentLineInput         $line        Submitted line values and optional identity.
     * @param   string                    $recordId    Settled caller-facing identity.
     * @param   int                       $position    Dense list position assigned by the command.
     * @param   ?StoredOwnedLine          $existing    Stored line, or null for a new line.
     *
     * @return  array{recordKey: string, recordId: string, position: int, values: array<string, mixed>,
     *          handles: list<string>, usage: FieldAccessUsage, existing: ?StoredOwnedLine,
     *          failure: ?BusinessRecordValidationFailed}  The line as far as it normalizes, or the
     *          validation failure to replay in submitted order.
     *
     * @throws  BusinessRelationshipRejected  When the submitted field set is not one this access plan
     *          admits for the usage the line implies.
     *
     * @since   2.0.0
     */
    private function normalizeDocumentLine(
        BusinessRecordAccessPlan $access,
        EntityTypeDefinition $definition,
        DocumentLineInput $line,
        string $recordId,
        int $position,
        ?StoredOwnedLine $existing,
    ): array {
        $usage = $existing === null ? FieldAccessUsage::Create : FieldAccessUsage::Update;
        $this->assertFieldInput($access, $usage, array_keys($line->values));
        $recordKey = $existing->recordKey
            ?? ($definition->identityStrategy === IdentityStrategy::Uuid
                ? $recordId
                : Uuid::uuid7()->toString());
        try {
            $values = $existing === null
                ? $this->rules->create(
                    $definition,
                    $line->values,
                    $definition->siteIdentifier,
                    $recordKey,
                    $recordId,
                )
                : $this->rules->update(
                    $definition,
                    $existing->values,
                    $line->values,
                    $definition->siteIdentifier,
                    $recordKey,
                    $recordId,
                );
        } catch (BusinessRecordValidationFailed $exception) {
            return [
                'recordKey' => $recordKey,
                'recordId' => $recordId,
                'position' => $position,
                'values' => [],
                'handles' => [],
                'usage' => $usage,
                'existing' => $existing,
                'failure' => $exception,
            ];
        }

        return [
            'recordKey' => $recordKey,
            'recordId' => $recordId,
            'position' => $position,
            'values' => $values,
            'handles' => $existing === null ? array_keys($values) : array_keys($line->values),
            'usage' => $usage,
            'existing' => $existing,
            'failure' => null,
        ];
    }

    /**
     * Resolve every entity reference the whole collection names, once per target and once per value.
     *
     * The un-batched path did this inside the line loop, so a thousand lines naming one target took a
     * thousand mutation-fence locks, a thousand definition resolutions and a thousand row lookups. The
     * work does not depend on the line: the target part is identical for every line naming that field, and
     * the lookup differs only in the value. Targets are therefore locked once, in sorted handle order so
     * two documents naming the same pair of targets can never take them in opposite orders, and the
     * distinct values are resolved in bounded batches.
     *
     * A target that cannot be reached fails once and the failure is carried, not thrown: the line loop
     * replays it at the first line that names the field, which is exactly where the un-batched code raised
     * it.
     *
     * @param   ExecutionContext          $context     Actor and site the resolution runs as.
     * @param   EntityTypeDefinition      $definition  Pinned line definition declaring the reference fields.
     * @param   RecordScope               $scope       Scope inherited from the owner.
     * @param   BusinessRecordAccessPlan  $access      Nested row and field policy.
     * @param   list<array{recordKey: string, recordId: string, position: int, values: array<string, mixed>,
     *          handles: list<string>, usage: FieldAccessUsage, existing: ?StoredOwnedLine,
     *          failure: ?BusinessRecordValidationFailed}>  $normalized  The normalized collection.
     *
     * @return  OwnedLineReferenceIndex  Resolved keys and per-field failures for the whole collection.
     *
     * @since   2.0.0
     */
    private function indexDocumentReferences(
        ExecutionContext $context,
        EntityTypeDefinition $definition,
        RecordScope $scope,
        BusinessRecordAccessPlan $access,
        array $normalized,
    ): OwnedLineReferenceIndex {
        /** @var array<string, array<string, string>> $wanted */
        $wanted = [];
        /** @var array<string, string> $targets */
        $targets = [];
        foreach ($definition->fields() as $field) {
            $target = $field->configuration['target'] ?? null;
            if ($field->type !== 'core.entity_reference' || !is_string($target)) {
                continue;
            }
            foreach ($normalized as $entry) {
                if ($entry['failure'] !== null || !in_array($field->handle, $entry['handles'], true)) {
                    continue;
                }
                $value = $entry['values'][$field->handle] ?? null;
                if (is_string($value)) {
                    $wanted[$field->handle][$value] = $value;
                    $targets[$field->handle] = $target;
                }
            }
        }
        if ($wanted === []) {
            return OwnedLineReferenceIndex::empty();
        }

        /** @var array<string, array<string, string>> $keys */
        $keys = [];
        /** @var array<string, Throwable> $failures */
        $failures = [];
        foreach (self::referenceAcquisitionOrder($targets) as $handle) {
            if (!isset($wanted[$handle])) {
                continue;
            }
            try {
                $keys[$handle] = $this->resolveReferenceBatch(
                    $context,
                    $scope,
                    $access,
                    $handle,
                    $targets[$handle],
                    array_values($wanted[$handle]),
                );
            } catch (Throwable $exception) {
                $failures[$handle] = $exception;
            }
        }

        return new OwnedLineReferenceIndex($keys, $failures);
    }

    /**
     * The one order reference-target fences are acquired in, for every command alike.
     *
     * The fence is a row lock on the target definition, so the sort key has to be the lock's own key —
     * the target handle — and never the field handle that happens to point at it. Two definitions naming
     * the same pair of targets through differently-named fields would otherwise take those fences in
     * opposite orders, which is the textbook shape of a deadlock this method exists to make
     * unrepresentable. The field handle only breaks ties between fields sharing one target, where the
     * order no longer matters for locking and merely has to be deterministic.
     *
     * @param   array<string, string>  $targetsByField  Target definition handle keyed by field handle.
     *
     * @return  list<string>  Field handles in fence-acquisition order.
     *
     * @since   2.0.0
     */
    public static function referenceAcquisitionOrder(array $targetsByField): array
    {
        $order = array_keys($targetsByField);
        usort(
            $order,
            static fn (string $a, string $b): int =>
                [$targetsByField[$a], $a] <=> [$targetsByField[$b], $b],
        );

        return $order;
    }

    /**
     * Resolve one reference field's distinct values against its target definition in one batch.
     *
     * @param   ExecutionContext          $context  Actor and site the resolution runs as.
     * @param   RecordScope               $scope    Scope inherited from the owner.
     * @param   BusinessRecordAccessPlan  $access   Nested row and field policy.
     * @param   string                    $handle   Reference field handle being resolved.
     * @param   string                    $target   Target definition handle the field points at.
     * @param   list<string>              $values   Distinct submitted values to resolve.
     *
     * @return  array<string, string>  Storage key of every value that resolved, keyed by that value.
     *
     * @throws  BusinessRecordNotFound  When the target itself is unreachable under this access plan.
     *
     * @since   2.0.0
     */
    private function resolveReferenceBatch(
        ExecutionContext $context,
        RecordScope $scope,
        BusinessRecordAccessPlan $access,
        string $handle,
        string $target,
        array $values,
    ): array {
        $targetAccess = $access->related($handle);
        if ($targetAccess === null) {
            throw new BusinessRecordNotFound();
        }
        $fenceStart = hrtime(true);
        $generation = $this->fence->lock($context, $target);
        $this->timings->add('lock_wait', (hrtime(true) - $fenceStart) / 1_000_000);
        $resolved = $this->definitions->forCreate($context, $target);
        $generation->assertMatches($resolved);
        $targetScope = $this->scope($resolved, $context, $scope->organizationIdentifier);
        $identityField = $this->identityField($resolved->definition);
        $this->assertPortalTargetOperation($context, $resolved->definition, PortalOperation::Read);
        $this->assertRelatedTargetAccess($resolved->definition, $targetAccess);

        /** @var array<string, string> $submittedOf */
        $submittedOf = [];
        foreach ($values as $value) {
            try {
                $targetId = $this->values->identity(
                    $resolved->definition,
                    [$identityField->handle => $value],
                    null,
                );
            } catch (InvalidArgumentException) {
                // A value the target's identity rules refuse resolves to nothing, which is the same
                // violation the caller saw when this ran one line at a time.
                continue;
            }
            $submittedOf[$targetId] = $value;
        }
        $identities = $this->reads->identities(
            $resolved,
            $targetScope,
            $targetAccess,
            array_values(array_map(strval(...), array_keys($submittedOf))),
        );
        /** @var array<string, string> $keys */
        $keys = [];
        foreach ($submittedOf as $targetId => $submitted) {
            $identity = $identities[(string) $targetId] ?? null;
            if ($identity !== null) {
                $keys[$submitted] = $identity->recordKey;
            }
        }

        return $keys;
    }

    /**
     * Finish one normalized line against the collection's resolved references.
     *
     * @param   ExecutionContext          $context     Actor and site the write runs as.
     * @param   BusinessRecordAccessPlan  $access      Nested row and field policy.
     * @param   EntityTypeDefinition      $definition  Pinned line definition.
     * @param   array{recordKey: string, recordId: string, position: int, values: array<string, mixed>,
     *          handles: list<string>, usage: FieldAccessUsage, existing: ?StoredOwnedLine,
     *          failure: ?BusinessRecordValidationFailed}  $entry  One normalized line.
     * @param   OwnedLineReferenceIndex   $index       References resolved for the whole collection.
     *
     * @return  OwnedLineWrite  Fully normalized line write intent.
     *
     * @throws  BusinessRecordValidationFailed  When this line breaks a field rule or names a reference no
     *          visible row answers to.
     * @throws  BusinessRecordNotFound  When the line's own values fall outside the nested row policy.
     *
     * @since   2.0.0
     */
    private function settleDocumentLine(
        ExecutionContext $context,
        BusinessRecordAccessPlan $access,
        EntityTypeDefinition $definition,
        array $entry,
        OwnedLineReferenceIndex $index,
    ): OwnedLineWrite {
        if ($entry['failure'] !== null) {
            throw $this->validationForAccess(
                $entry['failure'],
                $context,
                $definition,
                $access,
                $entry['usage'],
            );
        }
        $values = $entry['values'];
        $violations = [];
        foreach ($definition->fields() as $field) {
            if ($field->type !== 'core.entity_reference' || !in_array($field->handle, $entry['handles'], true)) {
                continue;
            }
            $value = $values[$field->handle] ?? null;
            if ($value === null) {
                continue;
            }
            $failure = $index->failure($field->handle);
            if ($failure !== null && !$failure instanceof BusinessRecordNotFound) {
                throw $failure;
            }
            $target = $field->configuration['target'] ?? null;
            if (!is_string($value) || !is_string($target)) {
                $violations[] = new ValidationViolation(
                    $field->handle,
                    'reference',
                    'The entity reference or target definition is invalid.',
                );
                continue;
            }
            $key = $failure === null ? $index->key($field->handle, $value) : null;
            if ($key === null) {
                $violations[] = new ValidationViolation(
                    $field->handle,
                    'reference',
                    'The referenced business record does not exist in this scope.',
                );
                continue;
            }
            $values[$field->handle] = $key;
        }
        if ($violations !== []) {
            throw $this->validationForAccess(
                new BusinessRecordValidationFailed($violations),
                $context,
                $definition,
                $access,
                $entry['usage'],
            );
        }
        if (!$access->records->allows($values)) {
            throw new BusinessRecordNotFound();
        }
        $existing = $entry['existing'];
        $valuesChanged = $existing === null || $this->valuesDiffer($existing->values, $values);

        return new OwnedLineWrite(
            $entry['recordKey'],
            $entry['recordId'],
            $entry['position'],
            $values,
            $existing?->version,
            $valuesChanged || $existing->position !== $entry['position'],
            $valuesChanged,
        );
    }

    /**
     * Prove a validator-visible reference field has the exact nested target identity required by metadata.
     *
     * @param   ExecutionContext          $context  Authenticated site used to resolve the target.
     * @param   FieldDefinition           $field    Definition field named by a validator violation.
     * @param   BusinessRecordAccessPlan  $access   Exact source operation access plan.
     *
     * @return  bool  True for non-references or an exactly authorized target identity.
     *
     * @since   2.0.0
     */
    private function validationReferenceAvailable(
        ExecutionContext $context,
        FieldDefinition $field,
        BusinessRecordAccessPlan $access,
    ): bool {
        if ($field->type !== 'core.entity_reference') {
            return true;
        }
        $targetHandle = $field->configuration['target'] ?? null;
        $targetAccess = $access->related($field->handle);
        if (!is_string($targetHandle) || $targetAccess === null) {
            return false;
        }
        try {
            $target = $this->definitions->forCreate($context, $targetHandle);
        } catch (BusinessRecordDefinitionUnavailable | BusinessRecordSchemaUnavailable) {
            return false;
        }

        return $this->relatedTargetAccessible($target->definition, $targetAccess);
    }

    /**
     * Derive the exact target scope while preserving the facade's organization validation semantics.
     *
     * @param   ResolvedBusinessDefinition  $resolved      Target definition being addressed.
     * @param   ExecutionContext            $context       Authenticated site and organization context.
     * @param   ?string                     $organization  Organization inherited from the source scope.
     *
     * @return  RecordScope  Scope every target identity read is constrained to.
     *
     * @throws  BusinessRecordValidationFailed  When target and authenticated organization scopes disagree.
     *
     * @since   2.0.0
     */
    private function scope(
        ResolvedBusinessDefinition $resolved,
        ExecutionContext $context,
        ?string $organization,
    ): RecordScope {
        try {
            $organizationScoped = in_array(
                $resolved->definition->scope,
                [ScopeMode::Organization, ScopeMode::SiteOrganization],
                true,
            );
            $authenticatedOrganization = $context->organization()?->identifier();
            if (
                ($organizationScoped && $authenticatedOrganization === null)
                || ($organizationScoped
                    && $organization !== null
                    && $organization !== $authenticatedOrganization)
                || (!$organizationScoped && $organization !== null)
            ) {
                throw new InvalidArgumentException(
                    'The request does not match its authenticated organization context.',
                );
            }

            return RecordScope::forDefinition(
                $resolved->definition->scope,
                $context->site(),
                $organizationScoped ? $authenticatedOrganization : null,
            );
        } catch (InvalidArgumentException $exception) {
            throw new BusinessRecordValidationFailed([
                new ValidationViolation('scope', 'scope', $exception->getMessage()),
            ]);
        }
    }

    /**
     * Find the field carrying one definition's caller-facing identity.
     *
     * @param   EntityTypeDefinition  $definition  Definition whose identity field is required.
     *
     * @return  FieldDefinition  Identity field selected by the definition's strategy.
     *
     * @throws  BusinessRecordReferenceConflict  When the definition declares no matching field.
     *
     * @since   2.0.0
     */
    private function identityField(EntityTypeDefinition $definition): FieldDefinition
    {
        $type = $definition->identityStrategy === IdentityStrategy::Uuid
            ? 'core.uuid'
            : 'core.reference_identity';
        foreach ($definition->fields() as $field) {
            if ($field->type === $type) {
                return $field;
            }
        }
        throw new BusinessRecordReferenceConflict();
    }

    /**
     * Look one field up without requiring the definition to declare it.
     *
     * @param   EntityTypeDefinition  $definition  Definition to search.
     * @param   string                $handle      Field handle to find.
     *
     * @return  ?FieldDefinition  Declared field or null when absent.
     *
     * @since   2.0.0
     */
    private function optionalField(EntityTypeDefinition $definition, string $handle): ?FieldDefinition
    {
        foreach ($definition->fields() as $field) {
            if ($field->handle === $handle) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Compare two value maps in their canonical storage spelling.
     *
     * @param   array<string, mixed>  $before  Values currently stored.
     * @param   array<string, mixed>  $after   Values the mutation would store.
     *
     * @return  bool  True when at least one handle differs canonically.
     *
     * @since   2.0.0
     */
    private function valuesDiffer(array $before, array $after): bool
    {
        $handles = array_unique([...array_keys($before), ...array_keys($after)]);
        foreach ($handles as $handle) {
            if (
                RecordValueGuard::canonical($before[$handle] ?? null)
                !== RecordValueGuard::canonical($after[$handle] ?? null)
            ) {
                return true;
            }
        }

        return false;
    }
}
