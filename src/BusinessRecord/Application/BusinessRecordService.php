<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\BusinessDefinition\Domain\ActionDefinition;
use Kumwe\App\BusinessDefinition\Domain\DeleteBehavior;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\App\BusinessDefinition\Domain\IdentityStrategy;
use Kumwe\App\BusinessDefinition\Domain\PortalOperation;
use Kumwe\App\BusinessDefinition\Domain\RelationshipKind;
use Kumwe\App\BusinessDefinition\Domain\ScopeMode;
use Kumwe\App\BusinessRecord\Application\Command\ArchiveRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\DeleteRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\DocumentLineInput;
use Kumwe\App\BusinessRecord\Application\Command\DocumentWriteIntent;
use Kumwe\App\BusinessRecord\Application\Command\ExecuteRecordActionCommand;
use Kumwe\App\BusinessRecord\Application\Command\RelateRecordsCommand;
use Kumwe\App\BusinessRecord\Application\Command\ReorderRecordLinesCommand;
use Kumwe\App\BusinessRecord\Application\Command\RestoreRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\UnrelateRecordsCommand;
use Kumwe\App\BusinessRecord\Application\Command\UpdateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\WriteDocumentCommand;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordActionRejected;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordIdempotencyConflict;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordIdempotencyRace;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordImmutable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordNotFound;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordPostingPeriodUndeclared;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordReferenceConflict;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordValidationFailed;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordVersionConflict;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRelationshipRejected;
use Kumwe\App\BusinessRecord\Application\Query\BrowseOwnedLineFieldChoicesQuery;
use Kumwe\App\BusinessRecord\Application\Query\BrowseRecordsQuery;
use Kumwe\App\BusinessRecord\Application\Query\BrowseRelatedRecordsQuery;
use Kumwe\App\BusinessRecord\Application\Query\OwnedLineFormQuery;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\App\BusinessRecord\Application\Query\RecordHistoryQuery;
use Kumwe\App\BusinessRecord\Domain\BusinessRecord;
use Kumwe\App\BusinessRecord\Domain\BusinessRecordIdempotency;
use Kumwe\App\BusinessRecord\Domain\BusinessRecordIdempotencyState;
use Kumwe\App\BusinessRecord\Domain\BusinessRecordReplayWindow;
use Kumwe\App\BusinessRecord\Domain\RecordScope;
use Kumwe\App\BusinessRecord\Domain\RecordValueGuard;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalBinding;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalDenied;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalService;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessController;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessPlan;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicyConstant;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Conversion\Decimal\ExactDecimal;
use Kumwe\Extension\Spi\Application\Automation\IdempotencyKey;
use Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordQueryPurpose;
use Kumwe\Extension\Spi\BusinessRecord\Value\ZonedDateTimeValue;
use Kumwe\Extension\Spi\BusinessSecurity\Application\FieldAccessUsage;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\Sequence\Contract\NumberSequenceAllocator;
use Kumwe\Sequence\Exception\NumberSequenceUnavailable;
use Kumwe\Sequence\Value\NumberSequenceFormat;
use Kumwe\Sequence\Value\NumberSequenceReset;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * The one transactional boundary through which typed business records are read and changed.
 *
 * Business records deliberately have no REST, console, MCP, portal or administrator adapter of their
 * own, so every caller arrives here and the same sequence runs however it came. One service-owned
 * transaction
 * fences the definition's installation so an installer cannot move the physical tables mid-operation,
 * resolves the exact definition version the operation is entitled to, authorizes the capability,
 * validates the submitted values against that version, applies the write as a compare-and-set on the
 * version the caller read, appends a revision, and records redacted audit metadata — so a refused or
 * failed operation leaves neither a row nor a trail behind.
 *
 * Every mutation is additionally claimed in the idempotency ledger before its effect runs and completed
 * with the result afterwards, which is what makes a repeated command replay its first outcome instead of
 * applying twice. Reads are pinned rather than current: a row is always decoded against the definition
 * version it was written under, so publishing a new version never re-interprets what is already stored,
 * and history stays readable after the record type itself has been withdrawn.
 *
 * @since  2.0.0
 */
final readonly class BusinessRecordService implements BusinessRecordCustomActionGuard
{
    /**
     * Most inbound set-null references a hard delete will clear within its own transaction.
     *
     * The read side is asked for one row beyond this so an overflow is detected rather than silently
     * truncated. A record with more inbound references than this has its delete refused, because
     * clearing them synchronously would make one request's transaction unboundedly large.
     *
     * @var    int
     * @since  2.0.0
     */
    private const INBOUND_DELETE_LIMIT = 500;

    /**
     * Wire the service to every collaborator a record operation composes.
     *
     * @param  BusinessRecordWriteRepository          $writes          Store every record and relationship
     *         write is applied through.
     * @param  BusinessRecordReadRepository           $reads           Store rows, views and browse pages are
     *         read back from.
     * @param  BusinessRecordRevisionRepository       $revisions       Append-only log this service writes
     *         history to and pages back.
     * @param  BusinessRecordIdempotencyRepository    $idempotency     Ledger that claims a command's key and
     *         holds its result for replay.
     * @param  BusinessRecordMutationFence            $mutationFence   Lock held over a definition's
     *         installation for the whole operation.
     * @param  BusinessRecordDefinitionResolver       $definitions     Resolver pairing a published definition
     *         version with its installed schema.
     * @param  NumberSequenceAllocator                $numbers         Counter every `core.sequence` field
     *         draws its gapless document number from, inside this service's own transaction.
     * @param  RecordValueCodec                       $values          Value codec, used here to normalize a
     *         caller-supplied record identity.
     * @param  RecordRuleValidator                    $rules           Field-rule validator that turns
     *         submitted values into a stored value set.
     * @param  BusinessRecordAccessController         $recordAccess    Canonical row, field, action, and relation
     *         policy planner applied before every repository call.
     * @param  BusinessRecordRelationshipCoordinator  $relationships   Typed relationship and owned-line
     *         validation seam invoked inside this service's transaction.
     * @param  ApprovalService                        $approvals       Generic maker-checker and step-up workflow.
     * @param  ResourceSiteOwnershipWriter            $ownership       Records approval-resource ownership with
     *         create and removes it only with a physical delete.
     * @param  AuthorizationGateway                   $authorization   Gateway asked for the operation, action
     *         and transition capabilities.
     * @param  TransactionManager                     $transactions    Owner of the single transaction each
     *         operation runs inside.
     * @param  BusinessRecordMutationPublication      $publication     Revision, audit and event coordinator
     *         invoked inside this service's authoritative transaction.
     * @param  RecordFingerprint                      $fingerprints    Keyed digest used for idempotency
     *         scopes and for identities held in the trail.
     * @param  ClockInterface                         $clock           Supplies the one instant stamped on
     *         every row a mutation touches.
     * @param  PostingPeriodLock                      $postingPeriods  Declarative temporal lock evaluated
     *         before the mutation fence for an addressed record and immediately before any source record
     *         an inbound set-null delete sweep would rewrite.
     * @param  PostingPeriodCalendar                  $periodCalendar  Containment seam a `fiscal-period`
     *         number sequence resolves its counter's period key through, from the record's declared
     *         posting date.
     * @param  BusinessRecordReplayWindow             $replayWindow    Declared horizons over which a
     *         caller-minted operation identifier replays, and over which it is remembered so a late
     *         repeat is refused by name instead of applied twice.
     * @param  DocumentWriteBudget                    $documentBudget  Declared command-level ceilings the
     *         aggregate document command refuses to exceed rather than quietly outgrow.
     * @param  DocumentCommitTimingRecorder           $commitTimings   Shared collector the document command
     *         reports its validation, lock-wait, write and publication durations through.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessRecordWriteRepository $writes,
        private BusinessRecordReadRepository $reads,
        private BusinessRecordRevisionRepository $revisions,
        private BusinessRecordIdempotencyRepository $idempotency,
        private BusinessRecordMutationFence $mutationFence,
        private BusinessRecordDefinitionResolver $definitions,
        private NumberSequenceAllocator $numbers,
        private RecordValueCodec $values,
        private RecordRuleValidator $rules,
        private BusinessRecordAccessController $recordAccess,
        private BusinessRecordRelationshipCoordinator $relationships,
        private ApprovalService $approvals,
        private ResourceSiteOwnershipWriter $ownership,
        private AuthorizationGateway $authorization,
        private TransactionManager $transactions,
        private BusinessRecordMutationPublication $publication,
        private RecordFingerprint $fingerprints,
        private ClockInterface $clock,
        private PostingPeriodLock $postingPeriods,
        private PostingPeriodCalendar $periodCalendar,
        private BusinessRecordReplayWindow $replayWindow = new BusinessRecordReplayWindow(),
        private DocumentWriteBudget $documentBudget = new DocumentWriteBudget(),
        private DocumentCommitTimingRecorder $commitTimings = new DocumentCommitTimingRecorder(),
    ) {
    }

    /**
     * Create one record of the command's entity type and report the version it was stored at.
     *
     * Identity is settled before anything is written: the command's explicit record id wins, otherwise the
     * value in the definition's identity field is used, and a UUID-identity type mints one when neither is
     * given. That identity also decides the row's internal storage key — a UUID-identity type reuses it,
     * while a reference-identity type is given a fresh UUID so the caller-facing id stays free to be a
     * business number. Entity references among the submitted values are exchanged for the target's
     * internal key, and the insert, the revision and the audit entry all share the one transaction.
     *
     * @param   CreateRecordCommand  $command  Validated create request: context, entity type, field values,
     *          idempotency key, and optional organization scope and identity.
     *
     * @return  RecordMutationResult  Keys, version and workflow state of the stored record; `replayed` is
     *          true when an earlier command under the same key produced it and nothing was written now.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not create
     *          business records.
     * @throws  BusinessRecordIdempotencyConflict  When the key was reused for a different request or
     *          authority, has expired, or its stored entry cannot be replayed.
     * @throws  BusinessRecordTemporarilyUnavailable  When the installation moved between resolve and lock,
     *          the database refused the write transiently, or three idempotency races were lost.
     *
     * @since   2.0.0
     */
    public function create(CreateRecordCommand $command): RecordMutationResult
    {
        $this->authorize($command->context, 'business.record.create');
        $this->assertPostingPeriodOpen(
            $command->context,
            $command->definitionIdentifier,
            $command->organizationIdentifier,
            'business.record.create',
            null,
            $command->values,
            true,
        );

        return $this->idempotent(
            $command->context,
            $command->definitionIdentifier,
            $command->organizationIdentifier,
            'business.record.create',
            $command->idempotencyKey,
            [
                'definition' => $command->definitionIdentifier,
                'record_id' => $command->recordId,
                'values' => $command->values,
            ],
            function (
                DateTimeImmutable $now,
                BusinessRecordMutationGeneration $generation,
            ) use ($command): RecordMutationResult {
                $resolved = $this->definitions->forCreate($command->context, $command->definitionIdentifier);
                $generation->assertMatches($resolved);
                $scope = $this->scope($resolved, $command->context, $command->organizationIdentifier);
                $access = $this->recordAccess->plan(
                    $command->context,
                    'business.record.create',
                    $resolved,
                    $scope,
                );
                $this->assertFieldInput($access, FieldAccessUsage::Create, array_keys($command->values));
                try {
                    $recordId = $this->values->identity(
                        $resolved->definition,
                        $command->values,
                        $command->recordId,
                    );
                } catch (InvalidArgumentException $exception) {
                    throw $this->validationForAccess(
                        new BusinessRecordValidationFailed([
                            new ValidationViolation(
                                $this->identityField($resolved->definition)->handle,
                                'identity',
                                $exception->getMessage(),
                            ),
                        ]),
                        $command->context,
                        $resolved->definition,
                        $access,
                        FieldAccessUsage::Create,
                    );
                }
                $recordKey = $resolved->definition->identityStrategy === IdentityStrategy::Uuid
                    ? $recordId
                    : Uuid::uuid7()->toString();
                try {
                    $values = $this->rules->create(
                        $resolved->definition,
                        $command->values,
                        $resolved->definition->siteIdentifier,
                        $recordKey,
                        $recordId,
                        $this->allocateNumbers($resolved, $scope, $now, $command->values),
                        $this->relationships->invariantLineValues($command->context, $resolved, null),
                    );
                } catch (BusinessRecordValidationFailed $exception) {
                    throw $this->validationForAccess(
                        $exception,
                        $command->context,
                        $resolved->definition,
                        $access,
                        FieldAccessUsage::Create,
                    );
                }
                $values = $this->resolveEntityReferences(
                    $command->context,
                    $resolved->definition,
                    $scope,
                    $access,
                    $values,
                    array_keys($values),
                );
                $record = new BusinessRecord(
                    $resolved->definition->id,
                    $resolved->definition->definitionVersion,
                    $recordKey,
                    $recordId,
                    $scope,
                    1,
                    $resolved->definition->workflow?->initialState,
                    $values,
                    $command->context->actorId(),
                    $now,
                    $command->context->actorId(),
                    $now,
                );
                if (!$access->records->allows($record->values())) {
                    throw new BusinessRecordValidationFailed([
                        new ValidationViolation('record', 'access', 'The submitted record is unavailable.'),
                    ]);
                }
                $this->writes->insert($resolved, $record);
                $this->ownership->record(
                    AuthorizationResource::item('business_record', $this->recordResourceIdentifier($record)),
                    $command->context->site(),
                );
                $changed = array_keys($record->values());
                $this->publication->publish(
                    $command->context,
                    $resolved->definition,
                    $record,
                    'create',
                    $changed,
                    $now,
                );

                return $this->result($record, 'create');
            },
        );
    }

    /**
     * Read one record by its public identity and project it to what the caller is allowed to see.
     *
     * The read takes a shared fence on the definition's installation rather than an exclusive one, so it
     * runs alongside other readers while still keeping an installer from moving the schema underneath it.
     * The row is decoded against the definition version it was written under, not the installed one, and
     * archived or soft-deleted records stay invisible unless the query opts into them.
     *
     * @param   ReadRecordQuery  $query  Validated read request: context, entity type, record identity,
     *          organization scope, projection, and the two lifecycle switches.
     *
     * @return  BusinessRecordView  The record narrowed to the readable fields the projection kept, with
     *          restricted, secret and unresolved-reference handles omitted.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not read
     *          business records.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable  When no
     *          definition on this site matches the identifier, or its owner is disabled.
     * @throws  BusinessRecordSchemaUnavailable  When the definition's schema is not installed and active,
     *          or a stored value does not match the column the blueprint describes.
     * @throws  BusinessRecordValidationFailed  When the organization scope is not one this definition
     *          accepts.
     * @throws  BusinessRecordNotFound  When no record answers that identity within the caller's scope, or
     *          the identity is not a usable one for this definition.
     * @throws  BusinessRecordReferenceConflict  When the row's pinned definition resolves to a different
     *          scope, or its storage key disagrees with the identity that addressed it.
     * @throws  BusinessRecordTemporarilyUnavailable  When the installation moved between the resolve and
     *          the fence, or the shared lock could not be taken.
     *
     * @since   2.0.0
     */
    public function read(ReadRecordQuery $query): BusinessRecordView
    {
        $this->authorize($query->context, 'business.record.read');

        return $this->transactions->transactional(function () use ($query): BusinessRecordView {
            $generation = $this->mutationFence->shared(
                $query->context->site(),
                $query->definitionIdentifier,
            );
            [$resolved, , $record, $access] = $this->load(
                $query->context,
                $query->definitionIdentifier,
                $query->recordId,
                $query->organizationIdentifier,
                'business.record.read',
                $query->includeArchived,
                $query->includeDeleted,
                $generation,
            );
            $this->assertPortalIncludes(
                $query->context,
                $resolved->definition,
                $access,
                $query->includes,
                PortalOperation::Read,
            );

            return $this->reads->view(
                $resolved,
                $record->scope,
                $record,
                $access,
                $query->projection,
                $query->includes,
                $query->includeArchived,
                $query->includeDeleted,
            );
        });
    }

    /**
     * List one bounded page of records of a definition, as the query's specification describes it.
     *
     * Only the scope is decided here: the definition is resolved at its installed version under a shared
     * fence, and the specification is handed whole to the read side, which compiles it into a single
     * statement. Rows on the page are still decoded against the version each was written under, so a page
     * may span several shapes, and requested aggregates are computed over the whole match, not the page.
     *
     * @param   BrowseRecordsQuery  $query  Validated browse request: context, entity type, organization
     *          scope, and the filter, sort, cursor and projection to compile.
     *
     * @return  RecordBrowseResult  Projected records for this page, a continuation cursor present only
     *          while further rows remain, and any aggregates the specification asked for.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not browse
     *          business records.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable  When no
     *          definition on this site matches the identifier, or its owner is disabled.
     * @throws  BusinessRecordSchemaUnavailable  When the definition's schema is not installed and active,
     *          or the query names something the installed columns do not carry.
     * @throws  BusinessRecordValidationFailed  When the organization scope is not one this definition
     *          accepts.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\InvalidBusinessRecordQuery  When the
     *          specification cannot be compiled, such as a cursor raised against a different query.
     * @throws  BusinessRecordTemporarilyUnavailable  When the installation moved between the resolve and
     *          the fence, or the shared lock could not be taken.
     *
     * @since   2.0.0
     */
    public function browse(BrowseRecordsQuery $query): RecordBrowseResult
    {
        $purpose = $query->purpose;
        if (
            $purpose === BusinessRecordQueryPurpose::Browse
            && $query->specification->projection->aggregates !== []
        ) {
            $purpose = BusinessRecordQueryPurpose::Report;
        }
        $operation = 'business.record.' . $purpose->value;
        $this->authorize($query->context, $operation);

        return $this->transactions->transactional(function () use ($query, $operation): RecordBrowseResult {
            $generation = $this->mutationFence->shared(
                $query->context->site(),
                $query->definitionIdentifier,
            );
            $resolved = $this->definitions->forCreate($query->context, $query->definitionIdentifier);
            $generation->assertMatches($resolved);
            $scope = $this->scope($resolved, $query->context, $query->organizationIdentifier);
            $access = $this->recordAccess->plan(
                $query->context,
                $operation,
                $resolved,
                $scope,
            );
            $this->assertPortalIncludes(
                $query->context,
                $resolved->definition,
                $access,
                $query->specification->projection->includes,
                PortalOperation::Browse,
            );

            return $this->reads->browse($resolved, $scope, $query->specification, $access);
        });
    }

    /**
     * List a bounded page of records selectable through one exact source relationship or reference field.
     *
     * This is intentionally not a normal target browse. Authorization starts from the source definition's
     * operation plan and uses only the nested plan keyed by the requested source handle, so direct target
     * visibility cannot widen a selector and a denied relation or reference returns no identities. The target
     * definition is independently fenced and its query is compiled with that nested row and field plan.
     * Owned-line collections are excluded because their rows exist only under an owner and are created from
     * embedded values instead of selected from a global target table.
     *
     * @param   BrowseRelatedRecordsQuery  $query  Source definition, related handle and bounded target query.
     *
     * @return  RelatedRecordBrowseResult  Active target definition and its policy-filtered page.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor cannot relate records.
     * @throws  BusinessRecordNotFound  When the source handle, nested access plan, target, or scope is unavailable.
     * @throws  BusinessRecordTemporarilyUnavailable  When either installation changes under its shared fence.
     *
     * @since   2.0.0
     */
    public function browseRelated(BrowseRelatedRecordsQuery $query): RelatedRecordBrowseResult
    {
        $operation = $query->operation;
        $this->authorize($query->context, $operation);

        return $this->transactions->transactional(function () use ($query, $operation): RelatedRecordBrowseResult {
            $sourceGeneration = $this->mutationFence->shared(
                $query->context->site(),
                $query->definitionIdentifier,
            );
            if ($query->sourceRecordId === null) {
                $source = $this->definitions->forCreate($query->context, $query->definitionIdentifier);
                $sourceGeneration->assertMatches($source);
                $sourceScope = $this->scope($source, $query->context, $query->organizationIdentifier);
                $sourceAccess = $this->recordAccess->plan(
                    $query->context,
                    $operation,
                    $source,
                    $sourceScope,
                );
            } else {
                [$source, $sourceScope, , $sourceAccess] = $this->load(
                    $query->context,
                    $query->definitionIdentifier,
                    $query->sourceRecordId,
                    $query->organizationIdentifier,
                    $operation,
                    generation: $sourceGeneration,
                );
            }
            $relatedAccess = $sourceAccess->related($query->relatedHandle)
                ?? throw new BusinessRecordNotFound();
            [$targetIdentifier, $relationship] = $this->relationships->relatedTarget(
                $source->definition,
                $query->relatedHandle,
            );
            if (
                $relationship?->kind === RelationshipKind::OwnedLineCollection
                || ($relationship === null && $operation === 'business.record.relate')
                || ($relationship !== null && $operation !== 'business.record.relate')
            ) {
                throw new BusinessRecordNotFound();
            }

            if ($relationship === null) {
                $field = $this->optionalField($source->definition, $query->relatedHandle);
                $usage = $operation === 'business.record.create'
                    ? FieldAccessUsage::Create
                    : FieldAccessUsage::Update;
                if (
                    $field?->type !== 'core.entity_reference'
                    || !$sourceAccess->fields->allows($usage, $field->handle)
                    || !$this->relationships->inputFieldAvailable($field, $usage)
                ) {
                    throw new BusinessRecordNotFound();
                }
            }

            $targetGeneration = $this->mutationFence->shared($query->context->site(), $targetIdentifier);
            $target = $this->definitions->forCreate($query->context, $targetIdentifier);
            $targetGeneration->assertMatches($target);
            $targetScope = $this->scope($target, $query->context, $query->organizationIdentifier);
            $this->relationships->assertPortalTargetOperation(
                $query->context,
                $target->definition,
                PortalOperation::Browse,
            );
            if ($relationship !== null && $sourceScope->toArray() !== $targetScope->toArray()) {
                throw new BusinessRecordNotFound();
            }
            if (!$this->relationships->relatedTargetAccessible($target->definition, $relatedAccess)) {
                return new RelatedRecordBrowseResult($target->definition, new RecordBrowseResult([]));
            }

            return new RelatedRecordBrowseResult(
                $target->definition,
                $this->reads->browse($target, $targetScope, $query->specification, $relatedAccess),
                $this->relatedSearchFields($target->definition, $relatedAccess),
            );
        });
    }

    /**
     * List entity-reference choices through an owned line's exact two-hop access plan.
     *
     * The source row is loaded under `business.record.relate`, the owned relationship selects its nested
     * line-target plan, and the requested line field selects the second nested target plan. Both definition
     * installations are fenced, and only that final plan reaches the query repository. This prevents a direct
     * browse of either target definition from widening a selector embedded in an owned-line create form.
     *
     * @param   BrowseOwnedLineFieldChoicesQuery  $query  Source, owned relationship, field, and bounded query.
     *
     * @return  RelatedRecordBrowseResult  Policy-filtered entity-reference target choices.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When relate authority is absent.
     * @throws  BusinessRecordNotFound  When either target, scope, field, or nested plan is unavailable.
     * @throws  BusinessRecordTemporarilyUnavailable  When an installation changes under its shared fence.
     *
     * @since   2.0.0
     */
    public function browseOwnedLineFieldChoices(
        BrowseOwnedLineFieldChoicesQuery $query,
    ): RelatedRecordBrowseResult {
        $operation = 'business.record.relate';
        $this->authorize($query->context, $operation);

        return $this->transactions->transactional(function () use ($query, $operation): RelatedRecordBrowseResult {
            $sourceGeneration = $this->mutationFence->shared(
                $query->context->site(),
                $query->definitionIdentifier,
            );
            [$source, $sourceScope, , $sourceAccess] = $this->load(
                $query->context,
                $query->definitionIdentifier,
                $query->sourceRecordId,
                $query->organizationIdentifier,
                $operation,
                generation: $sourceGeneration,
            );
            $relationship = $source->definition->runtimeRelationship($query->relationship);
            $lineAccess = $sourceAccess->related($query->relationship);
            if ($relationship?->kind !== RelationshipKind::OwnedLineCollection || $lineAccess === null) {
                throw new BusinessRecordNotFound();
            }
            $table = $source->installation->blueprint->table('line:' . $relationship->handle);
            $version = $table?->options['target_definition_version'] ?? null;
            if (!is_int($version)) {
                throw new BusinessRecordNotFound();
            }
            $lineGeneration = $this->mutationFence->shared($query->context->site(), $relationship->target);
            $line = $this->definitions->pinned($query->context, $relationship->target, $version);
            $lineGeneration->assertMatches($line);
            $lineScope = $this->scope($line, $query->context, $query->organizationIdentifier);
            $field = $this->optionalField($line->definition, $query->field);
            $choiceAccess = $lineAccess->related($query->field);
            $this->relationships->assertPortalTargetOperation(
                $query->context,
                $line->definition,
                PortalOperation::Relation,
            );
            $this->relationships->assertRelatedTargetAccess($line->definition, $lineAccess);
            if (
                $sourceScope->toArray() !== $lineScope->toArray()
                || $field?->type !== 'core.entity_reference'
                || !$field->createVisible
                || $field->readOnly
                || $field->computed
                || $field->serverOnly
                || !$lineAccess->fields->allows(FieldAccessUsage::Create, $field->handle)
                || $choiceAccess === null
            ) {
                throw new BusinessRecordNotFound();
            }
            [$choiceIdentifier, $nestedRelationship] = $this->relationships->relatedTarget(
                $line->definition,
                $query->field,
            );
            if ($nestedRelationship !== null) {
                throw new BusinessRecordNotFound();
            }
            $choiceGeneration = $this->mutationFence->shared($query->context->site(), $choiceIdentifier);
            $choice = $this->definitions->forCreate($query->context, $choiceIdentifier);
            $choiceGeneration->assertMatches($choice);
            $choiceScope = $this->scope($choice, $query->context, $query->organizationIdentifier);
            $this->relationships->assertPortalTargetOperation(
                $query->context,
                $choice->definition,
                PortalOperation::Browse,
            );
            $this->relationships->assertRelatedTargetAccess($choice->definition, $choiceAccess);

            return new RelatedRecordBrowseResult(
                $choice->definition,
                $this->reads->browse($choice, $choiceScope, $query->specification, $choiceAccess),
                $this->relatedSearchFields($choice->definition, $choiceAccess),
            );
        });
    }

    /**
     * Resolve the authorized create fields for one owned-line editor.
     *
     * The source record is loaded under the exact relate operation before its nested target plan is read.
     * The target version is pinned by the installed owned-line table blueprint, and only fields granted for
     * Create by that nested plan leave the service. No standalone target browse or target create capability
     * can widen what the source relationship allows.
     *
     * @param   OwnedLineFormQuery  $query  Existing source record and owned-line relationship.
     *
     * @return  OwnedLineFormResult  Pinned target definition and policy-authorized create handles.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When relate authority is absent.
     * @throws  BusinessRecordNotFound  When the source, relationship, target, scope, or nested plan is unavailable.
     * @throws  BusinessRecordTemporarilyUnavailable  When an installation changes under a shared fence.
     *
     * @since   2.0.0
     */
    public function ownedLineForm(OwnedLineFormQuery $query): OwnedLineFormResult
    {
        $operation = 'business.record.relate';
        $this->authorize($query->context, $operation);

        return $this->transactions->transactional(function () use ($query, $operation): OwnedLineFormResult {
            $sourceGeneration = $this->mutationFence->shared(
                $query->context->site(),
                $query->definitionIdentifier,
            );
            [$source, $sourceScope, , $sourceAccess] = $this->load(
                $query->context,
                $query->definitionIdentifier,
                $query->sourceRecordId,
                $query->organizationIdentifier,
                $operation,
                generation: $sourceGeneration,
            );
            $relationship = $source->definition->runtimeRelationship($query->relationship);
            $relatedAccess = $sourceAccess->related($query->relationship);
            if ($relationship?->kind !== RelationshipKind::OwnedLineCollection || $relatedAccess === null) {
                throw new BusinessRecordNotFound();
            }
            $table = $source->installation->blueprint->table('line:' . $relationship->handle);
            $version = $table?->options['target_definition_version'] ?? null;
            if (!is_int($version)) {
                throw new BusinessRecordNotFound();
            }
            $targetGeneration = $this->mutationFence->shared($query->context->site(), $relationship->target);
            $target = $this->definitions->pinned($query->context, $relationship->target, $version);
            $targetGeneration->assertMatches($target);
            $targetScope = $this->scope($target, $query->context, $query->organizationIdentifier);
            $this->relationships->assertPortalTargetOperation(
                $query->context,
                $target->definition,
                PortalOperation::Relation,
            );
            $this->relationships->assertRelatedTargetAccess($target->definition, $relatedAccess);
            if ($sourceScope->toArray() !== $targetScope->toArray()) {
                throw new BusinessRecordNotFound();
            }
            $fields = [];
            foreach ($target->definition->fields() as $field) {
                if (
                    $field->createVisible
                    && !$field->readOnly
                    && !$field->computed
                    && !$field->serverOnly
                    && $relatedAccess->fields->allows(FieldAccessUsage::Create, $field->handle)
                ) {
                    $fields[] = $field->handle;
                }
            }

            return new OwnedLineFormResult($target->definition, $fields);
        });
    }

    /**
     * Apply the command's value patch to one record and report the version it moved to.
     *
     * The patch is merged over the stored values, so only the handles the caller named are validated and
     * only entity references among those handles are re-resolved; everything else keeps what it held. What
     * the revision and the audit entry record is the set of fields whose canonical value actually differs
     * afterwards, which is not the same as the set that was submitted — resending a value unchanged, or in
     * a different spelling of the same decimal or timestamp, registers as no change at all.
     *
     * @param   UpdateRecordCommand  $command  Validated update request: context, entity type, record
     *          identity, expected version, the value patch, idempotency key and organization scope.
     *
     * @return  RecordMutationResult  Keys, new version and workflow state of the stored record; `replayed`
     *          is true when an earlier command under the same key produced it.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not update
     *          business records.
     * @throws  BusinessRecordIdempotencyConflict  When the key was reused for a different request or
     *          authority, has expired, or its stored entry cannot be replayed.
     * @throws  BusinessRecordTemporarilyUnavailable  When the installation moved between resolve and lock,
     *          the database refused the write transiently, or three idempotency races were lost.
     *
     * @since   2.0.0
     */
    public function update(UpdateRecordCommand $command): RecordMutationResult
    {
        $this->authorize($command->context, 'business.record.update');
        $this->assertPostingPeriodOpen(
            $command->context,
            $command->definitionIdentifier,
            $command->organizationIdentifier,
            'business.record.update',
            $command->recordId,
            $command->values,
        );

        return $this->idempotent(
            $command->context,
            $command->definitionIdentifier,
            $command->organizationIdentifier,
            'business.record.update',
            $command->idempotencyKey,
            [
                'definition' => $command->definitionIdentifier,
                'record_id' => $command->recordId,
                'expected_version' => $command->expectedVersion,
                'values' => $command->values,
            ],
            function (
                DateTimeImmutable $now,
                BusinessRecordMutationGeneration $generation,
            ) use ($command): RecordMutationResult {
                [$resolved, $scope, $record, $access] = $this->load(
                    $command->context,
                    $command->definitionIdentifier,
                    $command->recordId,
                    $command->organizationIdentifier,
                    'business.record.update',
                    generation: $generation,
                );
                $this->expected($record, $command->expectedVersion);
                $this->assertRecordMutable($resolved, $record);
                $this->assertFieldInput($access, FieldAccessUsage::Update, array_keys($command->values));
                try {
                    $values = $this->rules->update(
                        $resolved->definition,
                        $record->values(),
                        $command->values,
                        $resolved->definition->siteIdentifier,
                        $record->recordKey,
                        $record->recordId,
                        $this->relationships->invariantLineValues($command->context, $resolved, $record),
                    );
                } catch (BusinessRecordValidationFailed $exception) {
                    throw $this->validationForAccess(
                        $exception,
                        $command->context,
                        $resolved->definition,
                        $access,
                        FieldAccessUsage::Update,
                    );
                }
                $values = $this->resolveEntityReferences(
                    $command->context,
                    $resolved->definition,
                    $scope,
                    $access,
                    $values,
                    array_keys($command->values),
                );
                $updated = $record->updated($values, $command->context->actorId(), $now);
                $changed = $this->changed($record->values(), $updated->values());
                $this->writes->update($resolved, $updated, $command->expectedVersion);
                $this->publication->publish(
                    $command->context,
                    $resolved->definition,
                    $updated,
                    'update',
                    $changed,
                    $now,
                );

                return $this->result($updated, 'update');
            },
        );
    }

    /**
     * Withdraw one record from ordinary reads without deleting it, and report its new version.
     *
     * Archiving is a marking rather than a removal: values, identity and workflow state survive it, and
     * `restore()` reverses it. The shared lifecycle path loads the record with archived and deleted rows
     * both out of view, so a record that is already archived, or that has been soft-deleted, is reported
     * as not found instead of being archived a second time over its original stamp.
     *
     * @param   ArchiveRecordCommand  $command  Validated archive request: context, entity type, record
     *          identity, expected version, idempotency key and organization scope.
     *
     * @return  RecordMutationResult  Keys, new version and workflow state of the archived record;
     *          `replayed` is true when an earlier command under the same key produced it.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not archive
     *          business records.
     * @throws  BusinessRecordIdempotencyConflict  When the key was reused for a different request or
     *          authority, has expired, or its stored entry cannot be replayed.
     * @throws  BusinessRecordTemporarilyUnavailable  When the installation moved between resolve and lock,
     *          the database refused the write transiently, or three idempotency races were lost.
     *
     * @since   2.0.0
     */
    public function archive(ArchiveRecordCommand $command): RecordMutationResult
    {
        $this->authorize($command->context, 'business.record.archive');

        return $this->lifecycle(
            $command->context,
            $command->definitionIdentifier,
            $command->recordId,
            $command->organizationIdentifier,
            $command->expectedVersion,
            $command->idempotencyKey,
            'archive',
        );
    }

    /**
     * Delete one record the way its entity type defines deletion, and report the outcome.
     *
     * The definition decides which of two paths runs. With soft delete enabled the row is marked and
     * keeps its values, so `restore()` can bring it back. Without it the row is erased outright, which
     * first requires clearing every inbound set-null reference to it — a bounded, synchronous sweep that
     * refuses rather than proceeds when too many records point at this one. An archived record can still
     * be deleted, but an already soft-deleted one is reported as not found rather than deleted twice.
     *
     * @param   DeleteRecordCommand  $command  Validated delete request: context, entity type, record
     *          identity, expected version, idempotency key and organization scope.
     *
     * @return  RecordMutationResult  Keys and new version of the record, with `deleted` raised; the
     *          version is reported even on the hard-delete path, where no row survives to carry it.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not delete
     *          business records.
     * @throws  BusinessRecordIdempotencyConflict  When the key was reused for a different request or
     *          authority, has expired, or its stored entry cannot be replayed.
     * @throws  BusinessRecordTemporarilyUnavailable  When the installation moved between resolve and lock,
     *          the database refused the write transiently, or three idempotency races were lost.
     *
     * @since   2.0.0
     */
    public function delete(DeleteRecordCommand $command): RecordMutationResult
    {
        $this->authorize($command->context, 'business.record.delete');

        return $this->lifecycle(
            $command->context,
            $command->definitionIdentifier,
            $command->recordId,
            $command->organizationIdentifier,
            $command->expectedVersion,
            $command->idempotencyKey,
            'delete',
        );
    }

    /**
     * Bring an archived or soft-deleted record back into ordinary circulation.
     *
     * This is the one lifecycle operation that loads with both archived and deleted rows in view, which is
     * how it reaches a record ordinary reads hide. Restoring does not distinguish undeleting from
     * unarchiving: both stamps are cleared together, so a record that was archived and then deleted comes
     * back live rather than archived. A record that is neither archived nor deleted has nothing to
     * restore and the domain refuses it, so this is not a no-op that is safe to fire speculatively, and a
     * hard-deleted record is beyond its reach because no row survived to be found.
     *
     * @param   RestoreRecordCommand  $command  Validated restore request: context, entity type, record
     *          identity, expected version, idempotency key and organization scope.
     *
     * @return  RecordMutationResult  Keys, new version and workflow state of the live record; `replayed`
     *          is true when an earlier command under the same key produced it.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not restore
     *          business records.
     * @throws  BusinessRecordIdempotencyConflict  When the key was reused for a different request or
     *          authority, has expired, or its stored entry cannot be replayed.
     * @throws  BusinessRecordTemporarilyUnavailable  When the installation moved between resolve and lock,
     *          the database refused the write transiently, or three idempotency races were lost.
     *
     * @since   2.0.0
     */
    public function restore(RestoreRecordCommand $command): RecordMutationResult
    {
        $this->authorize($command->context, 'business.record.restore');

        return $this->lifecycle(
            $command->context,
            $command->definitionIdentifier,
            $command->recordId,
            $command->organizationIdentifier,
            $command->expectedVersion,
            $command->idempotencyKey,
            'restore',
        );
    }

    /**
     * Run one definition-declared action against a record, performing the workflow transition it names.
     *
     * An action is the only route to a workflow move: a caller never sets a state directly. Three further
     * gates stand past the operation capability — the action's own capability, its optional condition
     * evaluated against the record's scalar values, and the transition's capability — and the transition
     * must be one that leaves the state the record is actually in. Because two of those turn on the record
     * rather than the definition, the same action can be accepted for one record and refused for another,
     * and retrying without a state change fails identically. Action input is rejected outright, before the
     * idempotency ledger is touched, because no definition can declare a typed input yet.
     *
     * @param   ExecuteRecordActionCommand  $command  Validated action request: context, entity type, record
     *          identity, expected version, action handle, idempotency key and organization scope.
     *
     * @return  RecordMutationResult  Keys, new version and the workflow state the record was moved into;
     *          `replayed` is true when an earlier command under the same key produced it.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor holds neither the
     *          action capability nor the transition capability, or may not run record actions at all.
     * @throws  BusinessRecordActionRejected  When the command carries action input, the pinned definition
     *          declares no such action, its condition does not evaluate to true, or it names no transition
     *          out of the state the record currently holds.
     * @throws  BusinessRecordIdempotencyConflict  When the key was reused for a different request or
     *          authority, has expired, or its stored entry cannot be replayed.
     * @throws  BusinessRecordTemporarilyUnavailable  When the installation moved between resolve and lock,
     *          the database refused the write transiently, or three idempotency races were lost.
     *
     * @since   2.0.0
     */
    public function action(ExecuteRecordActionCommand $command): RecordMutationResult
    {
        $this->authorize($command->context, 'business.record.action');
        if ($command->input !== []) {
            throw new BusinessRecordActionRejected('This definition declares no typed action input.');
        }

        return $this->idempotent(
            $command->context,
            $command->definitionIdentifier,
            $command->organizationIdentifier,
            'business.record.action',
            $command->idempotencyKey,
            [
                'definition' => $command->definitionIdentifier,
                'record_id' => $command->recordId,
                'expected_version' => $command->expectedVersion,
                'action' => $command->action,
                'input' => $command->input,
            ],
            function (
                DateTimeImmutable $now,
                BusinessRecordMutationGeneration $generation,
            ) use ($command): RecordMutationResult {
                [$resolved, , $record, $access] = $this->load(
                    $command->context,
                    $command->definitionIdentifier,
                    $command->recordId,
                    $command->organizationIdentifier,
                    'business.record.action',
                    generation: $generation,
                );
                $this->expected($record, $command->expectedVersion);
                if (!$access->allowsAction($command->action)) {
                    throw new BusinessRecordNotFound();
                }
                $action = $this->actionDefinition($resolved->definition, $command->action);
                $this->authorization->assertAllowed(
                    $command->context,
                    Capability::fromString($action->capability),
                    AuthorizationResource::collection('business_record'),
                );
                if (
                    $action->condition !== null
                    && $action->condition->evaluate($this->expressionValues($record)) !== true
                ) {
                    throw new BusinessRecordActionRejected('The action precondition rejected this record.');
                }
                if ($action->transition === null || $resolved->definition->workflow === null) {
                    throw new BusinessRecordActionRejected('The action has no executable workflow transition.');
                }
                $transition = null;
                foreach ($resolved->definition->workflow->transitions as $candidate) {
                    if ($candidate['handle'] === $action->transition && $candidate['from'] === $record->workflowState) {
                        $transition = $candidate;
                        break;
                    }
                }
                if ($transition === null) {
                    throw new BusinessRecordActionRejected(
                        'The workflow transition is invalid from the current state.',
                    );
                }
                $this->authorization->assertAllowed(
                    $command->context,
                    Capability::fromString('business.record.transition'),
                    AuthorizationResource::collection('business_record'),
                );
                $this->authorization->assertAllowed(
                    $command->context,
                    Capability::fromString($transition['capability']),
                    AuthorizationResource::collection('business_record'),
                );
                if ($action->highImpact) {
                    $approvalRequestId = $command->approvalRequestId ?? throw new ApprovalDenied();
                    $this->approvals->consume(
                        $command->context,
                        $approvalRequestId,
                        $this->actionApprovalBinding($command, $record, $action),
                    );
                }
                $updated = $record->transitioned($transition['to'], $command->context->actorId(), $now);
                $this->writes->update($resolved, $updated, $command->expectedVersion);
                $this->publication->publish(
                    $command->context,
                    $resolved->definition,
                    $updated,
                    'action.' . $action->handle,
                    [],
                    $now,
                );

                return $this->result($updated, 'action');
            },
        );
    }

    /**
     * Apply the canonical record, action, condition, capability, concurrency, and approval guards for a
     * typed custom action without selecting or invoking extension code.
     *
     * `CustomBusinessActionExecutor` calls this only after taking the canonical idempotency claim and
     * definition fence in its outer transaction. Approval consumption therefore rolls back with a handler
     * failure, while the business-record layer remains independent of custom registries and result schemas.
     *
     * @param   ExecuteRecordActionCommand        $command     Validated custom action attempt.
     * @param   BusinessRecordMutationGeneration  $generation  Installed generation held by the caller.
     *
     * @return  void
     *
     * @throws  BusinessRecordNotFound  When the target or action is absent, denied, or outside row policy.
     * @throws  BusinessRecordVersionConflict  When the expected version is stale.
     * @throws  BusinessRecordActionRejected  When the declaration is not custom or its condition rejects.
     * @throws  ApprovalDenied  When high-impact execution lacks an exact approved request.
     *
     * @since   2.0.0
     */
    public function guardCustomAction(
        ExecuteRecordActionCommand $command,
        BusinessRecordMutationGeneration $generation,
    ): void {
        $this->authorize($command->context, 'business.record.action');
        [$resolved, , $record, $access] = $this->load(
            $command->context,
            $command->definitionIdentifier,
            $command->recordId,
            $command->organizationIdentifier,
            'business.record.action',
            generation: $generation,
        );
        $this->expected($record, $command->expectedVersion);
        if (!$access->allowsAction($command->action)) {
            throw new BusinessRecordNotFound();
        }
        $action = $this->actionDefinition($resolved->definition, $command->action);
        if ($action->handler === null || $action->schema === null || $action->transition !== null) {
            throw new BusinessRecordActionRejected('The action is not a typed custom action.');
        }
        $this->authorization->assertAllowed(
            $command->context,
            Capability::fromString($action->capability),
            AuthorizationResource::collection('business_record'),
        );
        if (
            $action->condition !== null
            && $action->condition->evaluate($this->expressionValues($record)) !== true
        ) {
            throw new BusinessRecordActionRejected('The action precondition rejected this record.');
        }
        if ($action->highImpact) {
            $approvalRequestId = $command->approvalRequestId ?? throw new ApprovalDenied();
            $this->approvals->consume(
                $command->context,
                $approvalRequestId,
                $this->actionApprovalBinding($command, $record, $action),
            );
        }
    }

    /**
     * Evaluate the posting-period lock for one custom action attempt, before any fence is taken.
     *
     * `CustomBusinessActionExecutor` calls this ahead of its transaction, so a custom action against a
     * record dated in a closed period refuses without the definition's exclusive fence being acquired.
     * Declared workflow-transition actions deliberately take no such gate — see `PostingPeriodLock` for
     * that decision — which is why this hook exists for the custom path alone.
     *
     * @param   ExecuteRecordActionCommand  $command  Validated custom action attempt.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordPostingPeriodClosed  When the
     *          record's declared posting date falls inside a closed period.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable  When
     *          no definition matches the identifier on this site, or its owner is disabled.
     *
     * @since   2.0.0
     */
    public function guardCustomActionPostingPeriod(ExecuteRecordActionCommand $command): void
    {
        $this->assertPostingPeriodOpen(
            $command->context,
            $command->definitionIdentifier,
            $command->organizationIdentifier,
            'business.record.action',
            $command->recordId,
        );
    }

    /**
     * Request generic maker-checker approval for one exact high-impact record action.
     *
     * The same policy-filtered load, version, action capability, condition, and workflow transition
     * checks used by execution run before the request is stored. The returned request is therefore
     * bound to a record and payload that the actor could genuinely attempt, without mutating it.
     *
     * @param   ExecuteRecordActionCommand  $command  Exact action attempt without an approval request id.
     *
     * @return  ?string  New approval UUID, or null when no active rule requires approval.
     *
     * @throws  BusinessRecordActionRejected  When the action is not high-impact or is not currently executable.
     *
     * @since   2.0.0
     */
    public function requestActionApproval(ExecuteRecordActionCommand $command): ?string
    {
        $this->authorize($command->context, 'business.record.action');
        if ($command->approvalRequestId !== null) {
            throw new BusinessRecordActionRejected('An approval request requires an unconsumed action attempt.');
        }

        return $this->idempotentApprovalRequest(
            $command->context,
            $command->definitionIdentifier,
            $command->organizationIdentifier,
            $command->idempotencyKey,
            [
                'definition' => $command->definitionIdentifier,
                'record_id' => $command->recordId,
                'expected_version' => $command->expectedVersion,
                'action' => $command->action,
                'input' => $command->input,
            ],
            function (
                DateTimeImmutable $_now,
                BusinessRecordMutationGeneration $generation,
            ) use ($command): ?string {
                [$resolved, , $record, $access] = $this->load(
                    $command->context,
                    $command->definitionIdentifier,
                    $command->recordId,
                    $command->organizationIdentifier,
                    'business.record.action',
                    generation: $generation,
                );
                $this->expected($record, $command->expectedVersion);
                if (!$access->allowsAction($command->action)) {
                    throw new BusinessRecordNotFound();
                }
                $action = $this->actionDefinition($resolved->definition, $command->action);
                if (!$action->highImpact) {
                    throw new BusinessRecordActionRejected(
                        'The action does not require maker-checker approval.',
                    );
                }
                $this->authorization->assertAllowed(
                    $command->context,
                    Capability::fromString($action->capability),
                    AuthorizationResource::collection('business_record'),
                );
                if (
                    $action->condition !== null
                    && $action->condition->evaluate($this->expressionValues($record)) !== true
                ) {
                    throw new BusinessRecordActionRejected('The action precondition rejected this record.');
                }
                $custom = $action->handler !== null
                    && $action->schema !== null
                    && $action->transition === null;
                if (!$custom && $command->input !== []) {
                    throw new BusinessRecordActionRejected('This definition declares no typed action input.');
                }
                if ($custom) {
                    return $this->approvals->request(
                        $command->context,
                        $this->actionApprovalBinding($command, $record, $action),
                    );
                }
                if ($action->transition === null || $resolved->definition->workflow === null) {
                    throw new BusinessRecordActionRejected('The action has no executable workflow transition.');
                }
                $transition = null;
                foreach ($resolved->definition->workflow->transitions as $candidate) {
                    if (
                        $candidate['handle'] === $action->transition
                        && $candidate['from'] === $record->workflowState
                    ) {
                        $transition = $candidate;
                        break;
                    }
                }
                if ($transition === null) {
                    throw new BusinessRecordActionRejected(
                        'The workflow transition is invalid from the current state.',
                    );
                }
                $this->authorization->assertAllowed(
                    $command->context,
                    Capability::fromString('business.record.transition'),
                    AuthorizationResource::collection('business_record'),
                );
                $this->authorization->assertAllowed(
                    $command->context,
                    Capability::fromString($transition['capability']),
                    AuthorizationResource::collection('business_record'),
                );

                return $this->approvals->request(
                    $command->context,
                    $this->actionApprovalBinding($command, $record, $action),
                );
            },
        );
    }

    /**
     * Link a record to another through one declared relationship, creating the owned line where there is
     * no target yet.
     *
     * The two shapes diverge only in how the far end is obtained. An owned-line collection has nothing to
     * point at, so the command's target values are validated against the pinned line type and the line is
     * created as part of the same write. Every other kind names an existing record, which is loaded under
     * its own fence and required to sit in exactly the same site and organization as the source, so a link
     * never crosses a scope boundary. The source is always re-versioned; when the relationship's canonical
     * storage belongs to the inverse side the target's row moves too, and it is then re-versioned and
     * audited under the handle that side actually stores.
     *
     * @param   RelateRecordsCommand  $command  Validated relate request: context, source entity type and
     *          identity, expected version, relationship handle, target identity or line values, position,
     *          idempotency key and organization scope.
     *
     * @return  RecordMutationResult  Keys and new version of the source record; the target's own new
     *          version, where one was written, is recorded in the trail rather than returned here.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not relate
     *          business records.
     * @throws  BusinessRecordIdempotencyConflict  When the key was reused for a different request or
     *          authority, has expired, or its stored entry cannot be replayed.
     * @throws  BusinessRecordTemporarilyUnavailable  When an installation moved between resolve and lock,
     *          the database refused the write transiently, or three idempotency races were lost.
     *
     * @since   2.0.0
     */
    public function relate(RelateRecordsCommand $command): RecordMutationResult
    {
        $this->authorize($command->context, 'business.record.relate');
        $this->assertPostingPeriodOpen(
            $command->context,
            $command->definitionIdentifier,
            $command->organizationIdentifier,
            'business.record.relate',
            $command->recordId,
        );

        return $this->idempotent(
            $command->context,
            $command->definitionIdentifier,
            $command->organizationIdentifier,
            'business.record.relate',
            $command->idempotencyKey,
            [
                'definition' => $command->definitionIdentifier,
                'record_id' => $command->recordId,
                'expected_version' => $command->expectedVersion,
                'relationship' => $command->relationship,
                'target_record_id' => $command->targetRecordId,
                'target_values' => $command->targetValues,
                'position' => $command->position,
            ],
            function (
                DateTimeImmutable $now,
                BusinessRecordMutationGeneration $generation,
            ) use ($command): RecordMutationResult {
                [$resolved, $scope, $source, $access] = $this->load(
                    $command->context,
                    $command->definitionIdentifier,
                    $command->recordId,
                    $command->organizationIdentifier,
                    'business.record.relate',
                    generation: $generation,
                );
                $this->expected($source, $command->expectedVersion);
                $this->assertRecordMutable($resolved, $source);
                $relationship = $this->relationships->relationship(
                    $resolved->definition,
                    $command->relationship,
                );
                $relatedAccess = $access->related($relationship->handle)
                    ?? throw new BusinessRecordNotFound();
                $targetKey = '';
                $targetResolved = null;
                $target = null;
                $lineDefinition = null;
                $lineValues = [];
                if ($relationship->kind === RelationshipKind::OwnedLineCollection) {
                    $intent = $this->relationships->prepareOwnedLineCreate(
                        $command,
                        $resolved,
                        $scope,
                        $relationship,
                        $relatedAccess,
                    );
                    $targetKey = $intent->recordKey;
                    $lineDefinition = $intent->line->definition;
                    $lineValues = $intent->values;
                } else {
                    if ($command->targetValues !== []) {
                        throw new BusinessRelationshipRejected('Only owned lines accept embedded target values.');
                    }
                    $targetGeneration = $this->mutationFence->lock(
                        $command->context,
                        $relationship->target,
                    );
                    $targetResolved = $this->definitions->forCreate(
                        $command->context,
                        $relationship->target,
                    );
                    $targetGeneration->assertMatches($targetResolved);
                    $this->relationships->assertPortalTargetOperation(
                        $command->context,
                        $targetResolved->definition,
                        PortalOperation::Relation,
                    );
                    $this->relationships->assertRelatedTargetAccess(
                        $targetResolved->definition,
                        $relatedAccess,
                    );
                    [$loadedTarget, , $target] = $this->load(
                        $command->context,
                        $relationship->target,
                        $command->targetRecordId,
                        $command->organizationIdentifier,
                        'business.record.relate',
                        generation: $targetGeneration,
                    );
                    if ($loadedTarget->definition->id !== $targetResolved->definition->id) {
                        throw new BusinessRecordReferenceConflict();
                    }
                    $targetResolved = $loadedTarget;
                    $this->relationships->assertExistingTarget(
                        $command->context,
                        $source,
                        $targetResolved->definition,
                        $target,
                        $relatedAccess,
                        PortalOperation::Relation,
                    );
                    $targetKey = $target->recordKey;
                }
                $write = $this->writes->relate(
                    $resolved,
                    $source,
                    $relationship,
                    $targetKey,
                    $command->position,
                    $command->context->actorId(),
                    $now,
                    $command->expectedVersion,
                    $targetResolved,
                    $target,
                    $lineDefinition,
                    $lineValues,
                );
                if ($write->target !== null && $targetResolved !== null) {
                    $this->assertRecordMutable($targetResolved, $write->target);
                }
                $updated = $write->source;
                $this->relationships->assertAggregateInvariants($command->context, $resolved, $updated);
                $this->publication->publish(
                    $command->context,
                    $resolved->definition,
                    $updated,
                    'relate.' . $relationship->handle,
                    [$relationship->handle],
                    $now,
                    $this->relationshipEvidence(
                        $relationship->handle,
                        $command->targetRecordId,
                        $command->position,
                        $command->targetValues,
                    ),
                );
                if ($write->target !== null && $targetResolved !== null && $write->targetRelationship !== null) {
                    $this->publication->publish(
                        $command->context,
                        $targetResolved->definition,
                        $write->target,
                        'relate.' . $write->targetRelationship,
                        [$write->targetRelationship],
                        $now,
                        $this->relationshipEvidence(
                            $write->targetRelationship,
                            $command->recordId,
                            $command->position,
                        ),
                    );
                }

                return $this->result($updated, 'relate');
            },
        );
    }

    /**
     * Detach one named link between a record and a single target of that relationship.
     *
     * Detaching is authorized under the same capability as linking, and neither record is deleted by it —
     * except an owned line, which has no existence apart from its owner and so goes with the link. The
     * target of an ordinary relationship is loaded with archived and soft-deleted rows in view, so a link
     * to a record that has since been withdrawn can still be broken. A link that is not there is an error
     * rather than a no-op; repeating the command safely is what the idempotency key is for. As with
     * `relate()`, a relationship whose canonical storage belongs to the inverse side moves the target's
     * row, which is then re-versioned and audited under its own handle.
     *
     * @param   UnrelateRecordsCommand  $command  Validated unrelate request: context, source entity type
     *          and identity, expected version, relationship handle, target identity, idempotency key and
     *          organization scope.
     *
     * @return  RecordMutationResult  Keys and new version of the source record; the target's own new
     *          version, where one was written, is recorded in the trail rather than returned here.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not relate
     *          business records.
     * @throws  BusinessRecordIdempotencyConflict  When the key was reused for a different request or
     *          authority, has expired, or its stored entry cannot be replayed.
     * @throws  BusinessRecordTemporarilyUnavailable  When an installation moved between resolve and lock,
     *          the database refused the write transiently, or three idempotency races were lost.
     *
     * @since   2.0.0
     */
    public function unrelate(UnrelateRecordsCommand $command): RecordMutationResult
    {
        $this->authorize($command->context, 'business.record.relate');
        $this->assertPostingPeriodOpen(
            $command->context,
            $command->definitionIdentifier,
            $command->organizationIdentifier,
            'business.record.relate',
            $command->recordId,
        );

        return $this->idempotent(
            $command->context,
            $command->definitionIdentifier,
            $command->organizationIdentifier,
            'business.record.unrelate',
            $command->idempotencyKey,
            [
                'definition' => $command->definitionIdentifier,
                'record_id' => $command->recordId,
                'expected_version' => $command->expectedVersion,
                'relationship' => $command->relationship,
                'target_record_id' => $command->targetRecordId,
            ],
            function (
                DateTimeImmutable $now,
                BusinessRecordMutationGeneration $generation,
            ) use ($command): RecordMutationResult {
                [$resolved, , $source, $access] = $this->load(
                    $command->context,
                    $command->definitionIdentifier,
                    $command->recordId,
                    $command->organizationIdentifier,
                    'business.record.relate',
                    generation: $generation,
                );
                $this->expected($source, $command->expectedVersion);
                $this->assertRecordMutable($resolved, $source);
                $relationship = $this->relationships->relationship(
                    $resolved->definition,
                    $command->relationship,
                );
                $relatedAccess = $access->related($relationship->handle)
                    ?? throw new BusinessRecordNotFound();
                $targetResolved = null;
                $target = null;
                if ($relationship->kind === RelationshipKind::OwnedLineCollection) {
                    $targetKey = $this->relationships->ownedLineKey(
                        $command->context,
                        $resolved,
                        $source,
                        $relationship,
                        $relatedAccess,
                        $command->targetRecordId,
                        PortalOperation::Relation,
                    );
                } else {
                    $targetGeneration = $this->mutationFence->lock(
                        $command->context,
                        $relationship->target,
                    );
                    $targetResolved = $this->definitions->forCreate(
                        $command->context,
                        $relationship->target,
                    );
                    $targetGeneration->assertMatches($targetResolved);
                    $this->relationships->assertPortalTargetOperation(
                        $command->context,
                        $targetResolved->definition,
                        PortalOperation::Relation,
                    );
                    $this->relationships->assertRelatedTargetAccess(
                        $targetResolved->definition,
                        $relatedAccess,
                    );
                    [$loadedTarget, , $target] = $this->load(
                        $command->context,
                        $relationship->target,
                        $command->targetRecordId,
                        $command->organizationIdentifier,
                        'business.record.relate',
                        true,
                        true,
                        $targetGeneration,
                    );
                    if ($loadedTarget->definition->id !== $targetResolved->definition->id) {
                        throw new BusinessRecordReferenceConflict();
                    }
                    $targetResolved = $loadedTarget;
                    $this->relationships->assertTargetRow($target, $relatedAccess);
                    $targetKey = $target->recordKey;
                }
                $write = $this->writes->unrelate(
                    $resolved,
                    $source,
                    $relationship,
                    $targetKey,
                    $command->context->actorId(),
                    $now,
                    $command->expectedVersion,
                    $targetResolved,
                    $target,
                );
                if ($write->target !== null && $targetResolved !== null) {
                    $this->assertRecordMutable($targetResolved, $write->target);
                }
                $updated = $write->source;
                $this->relationships->assertAggregateInvariants($command->context, $resolved, $updated);
                $this->publication->publish(
                    $command->context,
                    $resolved->definition,
                    $updated,
                    'unrelate.' . $relationship->handle,
                    [$relationship->handle],
                    $now,
                    $this->relationshipEvidence($relationship->handle, $command->targetRecordId),
                );
                if ($write->target !== null && $targetResolved !== null && $write->targetRelationship !== null) {
                    $this->publication->publish(
                        $command->context,
                        $targetResolved->definition,
                        $write->target,
                        'unrelate.' . $write->targetRelationship,
                        [$write->targetRelationship],
                        $now,
                        $this->relationshipEvidence($write->targetRelationship, $command->recordId),
                    );
                }

                return $this->result($updated, 'unrelate');
            },
        );
    }

    /**
     * Put the members of one ordered relationship into the position order the command lists.
     *
     * The list is a full replacement rather than a move instruction: the write side refuses an order that
     * does not name every currently stored member exactly once. Each caller-facing identity is first
     * normalized to the storage key behind it — owned lines through their owner, other members by loading
     * each one with archived and deleted rows in view — and every member must belong to the relationship's
     * own definition and to the owner's scope, a check the owned-line lookup gets for free by being scoped
     * to the owner in the first place. Duplicates are re-checked after that normalization, since
     * two distinct identities can still resolve to one row. What the trail records is a digest of the
     * requested order and its length, never the identities themselves.
     *
     * @param   ReorderRecordLinesCommand  $command  Validated reorder request: context, entity type, owner
     *          identity, expected version, relationship handle, the new order, idempotency key and
     *          organization scope.
     *
     * @return  RecordMutationResult  Keys and new version of the owning record, whose collection is
     *          renumbered from zero in the order given.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not relate
     *          business records.
     * @throws  BusinessRecordIdempotencyConflict  When the key was reused for a different request or
     *          authority, has expired, or its stored entry cannot be replayed.
     * @throws  BusinessRecordTemporarilyUnavailable  When an installation moved between resolve and lock,
     *          the database refused the write transiently, or three idempotency races were lost.
     *
     * @since   2.0.0
     */
    public function reorder(ReorderRecordLinesCommand $command): RecordMutationResult
    {
        $this->authorize($command->context, 'business.record.relate');
        $this->assertPostingPeriodOpen(
            $command->context,
            $command->definitionIdentifier,
            $command->organizationIdentifier,
            'business.record.relate',
            $command->recordId,
        );

        return $this->idempotent(
            $command->context,
            $command->definitionIdentifier,
            $command->organizationIdentifier,
            'business.record.reorder',
            $command->idempotencyKey,
            [
                'definition' => $command->definitionIdentifier,
                'record_id' => $command->recordId,
                'expected_version' => $command->expectedVersion,
                'relationship' => $command->relationship,
                'ordered_record_ids' => $command->orderedRecordIds,
            ],
            function (
                DateTimeImmutable $now,
                BusinessRecordMutationGeneration $generation,
            ) use ($command): RecordMutationResult {
                [$resolved, , $source, $access] = $this->load(
                    $command->context,
                    $command->definitionIdentifier,
                    $command->recordId,
                    $command->organizationIdentifier,
                    'business.record.relate',
                    generation: $generation,
                );
                $this->expected($source, $command->expectedVersion);
                $this->assertRecordMutable($resolved, $source);
                $relationship = $this->relationships->relationship(
                    $resolved->definition,
                    $command->relationship,
                );
                $relatedAccess = $access->related($relationship->handle)
                    ?? throw new BusinessRecordNotFound();
                $keys = [];
                $targetResolved = null;
                if ($relationship->kind === RelationshipKind::OwnedLineCollection) {
                    $keys = $this->relationships->ownedLineKeys(
                        $command->context,
                        $resolved,
                        $source,
                        $relationship,
                        $relatedAccess,
                        $command->orderedRecordIds,
                        PortalOperation::Reorder,
                    );
                } else {
                    $targetGeneration = $this->mutationFence->lock(
                        $command->context,
                        $relationship->target,
                    );
                    $targetResolved = $this->definitions->forCreate($command->context, $relationship->target);
                    $targetGeneration->assertMatches($targetResolved);
                    $this->relationships->assertPortalTargetOperation(
                        $command->context,
                        $targetResolved->definition,
                        PortalOperation::Reorder,
                    );
                    $this->relationships->assertRelatedTargetAccess(
                        $targetResolved->definition,
                        $relatedAccess,
                    );
                    foreach ($command->orderedRecordIds as $recordId) {
                        [$loadedTarget, , $target] = $this->load(
                            $command->context,
                            $relationship->target,
                            $recordId,
                            $command->organizationIdentifier,
                            'business.record.relate',
                            true,
                            true,
                            $targetGeneration,
                        );
                        if ($loadedTarget->definition->id !== $targetResolved->definition->id) {
                            throw new BusinessRecordReferenceConflict();
                        }
                        $this->relationships->assertExistingTarget(
                            $command->context,
                            $source,
                            $targetResolved->definition,
                            $target,
                            $relatedAccess,
                            PortalOperation::Reorder,
                        );
                        $keys[] = $target->recordKey;
                    }
                }
                $this->relationships->assertUniqueTargetKeys($keys);
                $updated = $this->writes->reorder(
                    $resolved,
                    $source,
                    $relationship,
                    $keys,
                    $command->context->actorId(),
                    $now,
                    $command->expectedVersion,
                    $targetResolved,
                );
                $this->publication->publish(
                    $command->context,
                    $resolved->definition,
                    $updated,
                    'reorder.' . $relationship->handle,
                    [$relationship->handle],
                    $now,
                    [
                        'relationship' => $relationship->handle,
                        'ordered_identity_digest' => $this->fingerprints->digest($command->orderedRecordIds),
                        'ordered_count' => count($command->orderedRecordIds),
                    ],
                );

                return $this->result($updated, 'reorder');
            },
        );
    }

    /**
     * Write one whole document — a header and the owned lines belonging to it — as a single command.
     *
     * This is the primitive every document-shaped business object is built on, and core owns the write
     * without owning a single rule about what the document means. Header and lines are settled together
     * and committed together: there is no instant at which a reader can see a header without its lines, or
     * lines whose header has already moved on, because both halves and the revision, the audit entry and
     * the event that describe them share the one transaction the definition's exclusive fence is held for.
     * Anything that refuses — a field rule, an aggregate invariant, a stale version, a unique collision on
     * the nine hundredth line — takes the whole document with it and leaves no row behind.
     *
     * The line list is the collection as it is to end up, not a set of edits: a line naming an identity the
     * document holds is amended, a line naming none is added, a stored line the list does not name is
     * removed, and each line's slot is its index in the list. Positions are therefore dense and unique by
     * construction rather than by convention, and identity is meaningful only inside the document.
     *
     * Concurrency is settled at the document, not the line. `$command->expectedVersion` is the header's
     * version and every line write is guarded by it, so two callers amending one document contend for one
     * value: the second to arrive is refused as a stale conflict rather than interleaved into a document
     * neither of them wrote.
     *
     * What a write costs is bounded by the change rather than by the collection. A create issues one
     * batched insert per hundred lines; an amend additionally reads the collection once, deletes what the
     * document dropped in batches, and writes an existing line only when its values or its slot actually
     * moved — so resubmitting a document unchanged writes nothing at all, and an aggregate invariant is
     * evaluated once for the command rather than once per line.
     *
     * @param   WriteDocumentCommand  $command  Validated document write: context, header type and values,
     *          the owned-line collection and its whole line list, intent, expected aggregate version,
     *          identity, idempotency key and organization scope.
     *
     * @return  RecordMutationResult  Keys, the aggregate's new version and the header's workflow state;
     *          `replayed` is true when an earlier command under the same key wrote this document and
     *          nothing was written now.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not create or
     *          update business records, or may not relate them.
     * @throws  BusinessRecordImmutable  When an amendment reaches a document the definition's workflow
     *          closes in its current state; a closed document is corrected by a linked reversal.
     * @throws  BusinessRecordValidationFailed  When a header field rule, a line field rule or a record
     *          invariant — including one that reduces the whole collection — is breached.
     * @throws  BusinessRelationshipRejected  When the named relationship is not an owned-line collection,
     *          the collection is larger than one command may write, the line type declares an aggregate
     *          invariant of its own, or a line moved while the document was being written.
     * @throws  BusinessRecordVersionConflict  When the document moved past the version the caller read.
     * @throws  BusinessRecordIdempotencyConflict  When the key was reused for a different request or
     *          authority, has expired, or its stored entry cannot be replayed.
     * @throws  BusinessRecordTemporarilyUnavailable  When the installation moved between resolve and lock,
     *          the database refused the write transiently, or three idempotency races were lost.
     *
     * @since   2.0.0
     */
    public function writeDocument(WriteDocumentCommand $command): RecordMutationResult
    {
        $creating = $command->intent === DocumentWriteIntent::Create;
        $this->authorize($command->context, $creating ? 'business.record.create' : 'business.record.update');
        $this->authorize($command->context, 'business.record.relate');
        $this->assertPostingPeriodOpen(
            $command->context,
            $command->definitionIdentifier,
            $command->organizationIdentifier,
            $creating ? 'business.record.create' : 'business.record.update',
            $creating ? null : (string) $command->recordId,
            $command->values,
            $creating,
        );

        $request = [
            'definition' => $command->definitionIdentifier,
            'record_id' => $command->recordId,
            'expected_version' => $command->expectedVersion,
            'relationship' => $command->relationship,
            'values' => $command->values,
            'lines' => array_map(
                static fn (DocumentLineInput $line): array => [
                    'record_id' => $line->recordId,
                    'values' => $line->values,
                ],
                $command->lines,
            ),
            'captured_at' => $command->capturedAt?->toPortableString(),
        ];
        $this->documentBudget->assertPayloadWithin(strlen((string) json_encode($request)));
        $this->commitTimings->begin();
        $commandStart = hrtime(true);
        try {
            $result = $this->idempotent(
                $command->context,
                $command->definitionIdentifier,
                $command->organizationIdentifier,
                'business.record.document_' . $command->intent->value,
                $command->idempotencyKey,
                $request,
                fn (
                    DateTimeImmutable $now,
                    BusinessRecordMutationGeneration $generation,
                ): RecordMutationResult => $this->applyDocument($command, $now, $generation),
            );
        } catch (Throwable $exception) {
            $this->commitTimings->abandon();
            throw $exception;
        }
        $this->commitTimings->commit((hrtime(true) - $commandStart) / 1_000_000);

        return $result;
    }

    /**
     * Read one bounded, newest-first window of a record's revision log.
     *
     * History outlives both the row and the record type, so this is the only operation that fences and
     * resolves in history mode, which admits a withdrawn installation and a deactivated owner. Which
     * lookup runs depends on whether the row is still there: while it resolves the log is addressed by its
     * internal storage key, and once it is gone the log is addressed by the keyed digest of the record's
     * identity instead — the only handle that survives a hard delete. A digest is not proof of a single
     * subject, so how many generations it covers is settled over the whole scope **before** the page bound
     * is applied, and a digest naming more than one is refused rather than merged. Resolving it page-first
     * could not see a generation the requested window happened to exclude, which is how a small page or a
     * cursor deep in the log used to return one subject's history under an identity two subjects had held.
     * Each revision is rendered against the definition version it was written under, so a window spanning
     * an upgrade holds views of different shapes.
     *
     * @param   RecordHistoryQuery  $query  Validated history request: context, entity type, record
     *          identity, organization scope, window size and the version to page back from.
     *
     * @return  RecordHistoryResult  Up to `$query->limit` revision views, newest first, and whether older
     *          revisions remain — established by fetching one row past the limit and discarding it.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not read
     *          business-record history.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable  When no
     *          definition on this site matches the identifier, or the requested version is not published.
     * @throws  BusinessRecordSchemaUnavailable  When no retained installation exists for the definition, or
     *          a stored row disagrees with the checksum written beside it.
     * @throws  BusinessRecordValidationFailed  When the organization scope is not one this definition
     *          accepts.
     * @throws  BusinessRecordNotFound  When the identity is not usable for this definition, or neither the
     *          row nor any revision under its identity digest exists in scope.
     * @throws  BusinessRecordReferenceConflict  When the definition declares no identity field, the row's
     *          storage key disagrees with the identity that addressed it, or revisions found by digest
     *          belong to more than one record.
     * @throws  BusinessRecordTemporarilyUnavailable  When the installation moved between the resolve and
     *          the fence, or the shared lock could not be taken.
     *
     * @since   2.0.0
     */
    public function history(RecordHistoryQuery $query): RecordHistoryResult
    {
        $this->authorize($query->context, 'business.record.history');

        return $this->transactions->transactional(function () use ($query): RecordHistoryResult {
            $generation = $this->mutationFence->shared(
                $query->context->site(),
                $query->definitionIdentifier,
                true,
            );
            $installed = $this->definitions->forHistory($query->context, $query->definitionIdentifier);
            $generation->assertMatches($installed, true);
            $scope = $this->scope($installed, $query->context, $query->organizationIdentifier);
            try {
                $recordId = $this->values->identity(
                    $installed->definition,
                    [$this->identityField($installed->definition)->handle => $query->recordId],
                    null,
                );
            } catch (InvalidArgumentException) {
                throw new BusinessRecordNotFound();
            }
            $cursor = $query->beforeVersion === null
                ? null
                : BusinessRecordRevisionCursor::atVersion($query->beforeVersion);
            $installedAccess = $this->recordAccess->plan(
                $query->context,
                'business.record.history',
                $installed,
                $scope,
            );
            $identity = $this->reads->identity($installed, $scope, $installedAccess, $recordId, true);
            if ($identity !== null) {
                $pinned = $this->definitions->forHistory(
                    $query->context,
                    $query->definitionIdentifier,
                    $identity->definitionVersion,
                );
                $access = $this->recordAccess->plan(
                    $query->context,
                    'business.record.history',
                    $pinned,
                    $scope,
                );
                $record = $this->reads->get($pinned, $scope, $access, $recordId, true, true)
                    ?? throw new BusinessRecordNotFound();
                if (!hash_equals($identity->recordKey, $record->recordKey)) {
                    throw new BusinessRecordReferenceConflict();
                }
                $revisions = $this->revisions->history(
                    $record->definitionId,
                    $record->recordKey,
                    $query->limit + 1,
                    $cursor,
                );
            } else {
                $digest = $this->fingerprints->digest($recordId);
                $generations = $this->revisions->recordKeysForIdentityDigest(
                    $installed,
                    $scope,
                    $installedAccess,
                    $digest,
                    2,
                );
                if ($generations === []) {
                    throw new BusinessRecordNotFound();
                }
                if (count($generations) !== 1) {
                    throw new BusinessRecordReferenceConflict();
                }
                $revisions = $this->revisions->historyByIdentityDigest(
                    $installed,
                    $scope,
                    $installedAccess,
                    $digest,
                    $query->limit + 1,
                    $cursor,
                );
                if ($revisions === []) {
                    throw new BusinessRecordNotFound();
                }
                $latest = $revisions[0];
                $pinned = $this->definitions->forHistory(
                    $query->context,
                    $query->definitionIdentifier,
                    $latest->definitionVersion,
                );
                $access = $this->recordAccess->plan(
                    $query->context,
                    'business.record.history',
                    $pinned,
                    $scope,
                );
                if (!$access->records->allows($latest->snapshot())) {
                    throw new BusinessRecordNotFound();
                }
            }
            $hasMore = count($revisions) > $query->limit;
            if ($hasMore) {
                array_pop($revisions);
            }

            $views = [];
            foreach ($revisions as $revision) {
                $pinned = $this->definitions->forHistory(
                    $query->context,
                    $query->definitionIdentifier,
                    $revision->definitionVersion,
                );
                $revisionAccess = $this->recordAccess->plan(
                    $query->context,
                    'business.record.history',
                    $pinned,
                    $scope,
                );
                if (!$revisionAccess->records->allows($revision->snapshot())) {
                    throw new BusinessRecordNotFound();
                }
                $views[] = BusinessRecordRevisionView::fromRevision(
                    $revision,
                    $pinned->definition,
                    $revisionAccess->fields,
                );
            }

            return new RecordHistoryResult($views, $hasMore);
        });
    }

    /**
     * Settle and apply one whole document inside the claim, the fence and the transaction already held.
     *
     * The order of what happens here is the contract. Everything is decided before anything is written:
     * the header is resolved or loaded and its version proved, the collection is read once, every line is
     * validated against the line type, the whole prepared collection is held against the definition's
     * aggregate invariants, and only then does the first statement run. That is what makes a refusal on
     * the last line indistinguishable from a refusal on the first — neither leaves a row.
     *
     * The header is written before the lines, always, so the row locks a document takes are taken in one
     * stable order however many lines it carries, and the header's compare-and-set is the single point
     * where two concurrent amendments are separated.
     *
     * The declared lock order of the whole command is: the command's own definition fence, then the
     * idempotency claim's unique key, then the line-type fence, then the line collection's
     * reference-target fences sorted by target handle, then the sequence counter rows in field
     * declaration order, then the header's reference-target fences sorted by target handle, then the
     * header row, then the line rows — delete, park, rewrite, renumber, insert — and finally the
     * revision, audit and outbox appends. Each reference set sorts on the fence's own lock key, the
     * target handle, so two documents naming the same targets through differently-named or
     * differently-ordered fields acquire that pair identically. What one command cannot order is another
     * definition's opposite-direction cycle — A referencing B while B references A — which every command
     * enters through its own fence first; the engine detects that as a deadlock and this command's
     * three-attempt envelope retries it rather than hanging.
     *
     * @param   WriteDocumentCommand              $command     Validated document write being applied.
     * @param   DateTimeImmutable                 $now         Instant stamped on every row this writes.
     * @param   BusinessRecordMutationGeneration  $generation  Generation the fence observed, asserted
     *          against every definition this resolves.
     *
     * @return  RecordMutationResult  Keys, the aggregate's new version and the header's workflow state.
     *
     * @throws  BusinessRecordValidationFailed  When a header field rule, a line field rule or a record
     *          invariant is breached.
     * @throws  BusinessRelationshipRejected  When the relationship is not an owned-line collection, the
     *          stored collection overflows what one command may write, the line type declares an aggregate
     *          invariant of its own, or two lines claim one identity.
     * @throws  BusinessRecordNotFound  When the actor's row policy hides the collection, one of its stored
     *          lines, or a line the command would write.
     * @throws  BusinessRecordVersionConflict  When the document moved past the version the caller read.
     *
     * @since   2.0.0
     */
    private function applyDocument(
        WriteDocumentCommand $command,
        DateTimeImmutable $now,
        BusinessRecordMutationGeneration $generation,
    ): RecordMutationResult {
        $creating = $command->intent === DocumentWriteIntent::Create;
        $operation = $creating ? 'business.record.create' : 'business.record.update';
        $header = null;
        if ($creating) {
            $resolved = $this->definitions->forCreate($command->context, $command->definitionIdentifier);
            $generation->assertMatches($resolved);
            $scope = $this->scope($resolved, $command->context, $command->organizationIdentifier);
            $access = $this->recordAccess->plan($command->context, $operation, $resolved, $scope);
        } else {
            [$resolved, $scope, $header, $access] = $this->load(
                $command->context,
                $command->definitionIdentifier,
                (string) $command->recordId,
                $command->organizationIdentifier,
                $operation,
                generation: $generation,
            );
            $this->expected($header, (int) $command->expectedVersion);
            $this->assertRecordMutable($resolved, $header);
        }
        $memoryStart = memory_get_usage();
        $transactionStart = hrtime(true);
        $validationStart = hrtime(true);
        $lockWaitBefore = $this->commitTimings->accumulated('lock_wait');
        $lineIntent = $this->relationships->prepareDocumentMutation(
            $command,
            $resolved,
            $scope,
            $header,
            $access,
        );
        $relationship = $lineIntent->relationship;
        $collections = $this->relationships->invariantLineValues($command->context, $resolved, $header, [
            $relationship->handle => $lineIntent->invariantValues(),
        ]);
        $this->commitTimings->add(
            'validation',
            (hrtime(true) - $validationStart) / 1_000_000
                - ($this->commitTimings->accumulated('lock_wait') - $lockWaitBefore),
        );
        $writeStart = hrtime(true);
        $lockWaitBefore = $this->commitTimings->accumulated('lock_wait');
        [$record, $changed] = $creating
            ? $this->createDocumentHeader($command, $resolved, $scope, $access, $collections, $now)
            : $this->amendDocumentHeader($command, $resolved, $scope, $access, $header, $collections, $now);
        $this->writes->writeOwnedLines(
            $resolved,
            $record,
            $relationship,
            $lineIntent->line->definition,
            $lineIntent->writes,
            $lineIntent->removed,
            $lineIntent->renumber,
            $command->context->actorId(),
            $now,
        );
        $this->commitTimings->add(
            'write',
            (hrtime(true) - $writeStart) / 1_000_000
                - ($this->commitTimings->accumulated('lock_wait') - $lockWaitBefore),
        );
        $this->documentBudget->assertMemoryWithin($memoryStart);
        $this->documentBudget->assertElapsedWithin($transactionStart);
        $this->publication->publish(
            $command->context,
            $resolved->definition,
            $record,
            'document.' . $command->intent->value,
            [...$changed, $relationship->handle],
            $now,
            $this->documentEvidence($relationship->handle, $lineIntent->writes, $lineIntent->removed),
            $command->capturedAt,
        );
        $this->documentBudget->assertMemoryWithin($memoryStart);
        $this->documentBudget->assertElapsedWithin($transactionStart);

        return $this->result($record, 'document.' . $command->intent->value);
    }

    /**
     * Write the header of a document being created and report which of its fields the trail should name.
     *
     * This is the create path of the ordinary record command, reached with the collection already prepared
     * so the definition's aggregate invariants are judged against the lines this same command is about to
     * store rather than against nothing.
     *
     * @param   WriteDocumentCommand                             $command      Document write being applied.
     * @param ResolvedBusinessDefinition $resolved Pinned header definition and its installation.
     * @param RecordScope $scope Resolved site and organization for the document.
     * @param BusinessRecordAccessPlan $access Header row and field policy for the create.
     * @param   array<string, list<array<string, scalar|null>>>  $collections  Owned-line collections the
     *          definition's invariants reduce, as this command will leave them.
     * @param   DateTimeImmutable                                $now          Instant stamped on the header row.
     *
     * @return  array{BusinessRecord, list<string>}  The stored header at version one, and the handles the
     *          revision and audit entry name as changed.
     *
     * @throws  BusinessRecordValidationFailed  When the header breaks a field rule or a record invariant,
     *          including one that reduces the collection.
     *
     * @since   2.0.0
     */
    private function createDocumentHeader(
        WriteDocumentCommand $command,
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        BusinessRecordAccessPlan $access,
        array $collections,
        DateTimeImmutable $now,
    ): array {
        $this->assertFieldInput($access, FieldAccessUsage::Create, array_keys($command->values));
        try {
            $recordId = $this->values->identity($resolved->definition, $command->values, $command->recordId);
        } catch (InvalidArgumentException $exception) {
            throw $this->validationForAccess(
                new BusinessRecordValidationFailed([
                    new ValidationViolation(
                        $this->identityField($resolved->definition)->handle,
                        'identity',
                        $exception->getMessage(),
                    ),
                ]),
                $command->context,
                $resolved->definition,
                $access,
                FieldAccessUsage::Create,
            );
        }
        $recordKey = $resolved->definition->identityStrategy === IdentityStrategy::Uuid
            ? $recordId
            : Uuid::uuid7()->toString();
        try {
            $values = $this->rules->create(
                $resolved->definition,
                $command->values,
                $resolved->definition->siteIdentifier,
                $recordKey,
                $recordId,
                $this->allocateNumbers($resolved, $scope, $now, $command->values),
                $collections,
            );
        } catch (BusinessRecordValidationFailed $exception) {
            throw $this->validationForAccess(
                $exception,
                $command->context,
                $resolved->definition,
                $access,
                FieldAccessUsage::Create,
            );
        }
        $values = $this->resolveEntityReferences(
            $command->context,
            $resolved->definition,
            $scope,
            $access,
            $values,
            array_keys($values),
        );
        $record = new BusinessRecord(
            $resolved->definition->id,
            $resolved->definition->definitionVersion,
            $recordKey,
            $recordId,
            $scope,
            1,
            $resolved->definition->workflow?->initialState,
            $values,
            $command->context->actorId(),
            $now,
            $command->context->actorId(),
            $now,
        );
        if (!$access->records->allows($record->values())) {
            throw new BusinessRecordValidationFailed([
                new ValidationViolation('record', 'access', 'The submitted record is unavailable.'),
            ]);
        }
        $this->writes->insert($resolved, $record);
        $this->ownership->record(
            AuthorizationResource::item('business_record', $this->recordResourceIdentifier($record)),
            $command->context->site(),
        );

        return [$record, array_keys($record->values())];
    }

    /**
     * Write the header of a document being amended, as a compare-and-set on the version the caller read.
     *
     * This single statement is where two concurrent amendments are separated. Both callers may prepare a
     * whole document against the same version, but only one of them can move the header off it; the other
     * is refused as a version conflict before any of its lines are written, which is what stops a
     * line-level change from landing against a header that has already moved on.
     *
     * @param   WriteDocumentCommand                             $command      Document write being applied.
     * @param ResolvedBusinessDefinition $resolved Pinned header definition and its installation.
     * @param RecordScope $scope Resolved site and organization for the document.
     * @param BusinessRecordAccessPlan $access Header row and field policy for the update.
     * @param BusinessRecord $header Document header as it was read, at the version
     *          the caller expects it to still carry.
     * @param   array<string, list<array<string, scalar|null>>>  $collections  Owned-line collections the
     *          definition's invariants reduce, as this command will leave them.
     * @param   DateTimeImmutable                                $now          Instant stamped on the header row.
     *
     * @return  array{BusinessRecord, list<string>}  The header at its new version, and the handles whose
     *          value actually differs afterwards.
     *
     * @throws  BusinessRecordValidationFailed  When the header breaks a field rule or a record invariant,
     *          including one that reduces the collection.
     * @throws  BusinessRecordVersionConflict  When the document moved past the version the caller read.
     *
     * @since   2.0.0
     */
    private function amendDocumentHeader(
        WriteDocumentCommand $command,
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        BusinessRecordAccessPlan $access,
        BusinessRecord $header,
        array $collections,
        DateTimeImmutable $now,
    ): array {
        $this->assertFieldInput($access, FieldAccessUsage::Update, array_keys($command->values));
        try {
            $values = $this->rules->update(
                $resolved->definition,
                $header->values(),
                $command->values,
                $resolved->definition->siteIdentifier,
                $header->recordKey,
                $header->recordId,
                $collections,
            );
        } catch (BusinessRecordValidationFailed $exception) {
            throw $this->validationForAccess(
                $exception,
                $command->context,
                $resolved->definition,
                $access,
                FieldAccessUsage::Update,
            );
        }
        $values = $this->resolveEntityReferences(
            $command->context,
            $resolved->definition,
            $scope,
            $access,
            $values,
            array_keys($command->values),
        );
        $updated = $header->updated($values, $command->context->actorId(), $now);
        $changed = $this->changed($header->values(), $updated->values());
        $this->writes->update($resolved, $updated, (int) $command->expectedVersion);

        return [$updated, $changed];
    }

    /**
     * Reduce a document write to the bounded summary its revision, audit entry and event carry.
     *
     * A thousand-line document produces one entry, and that entry describes the shape of the change rather
     * than embedding the change: how many lines the document now holds, how many were added, amended and
     * removed, and one keyed digest over the line identities. The digest is what lets a later request be
     * compared against what was written without the trail itself holding identities that may be business
     * references.
     *
     * @param   string                $relationship  Owned-line collection the document was written over.
     * @param   list<OwnedLineWrite>  $lines         The document's whole collection as it was stored.
     * @param   list<string>          $removed       Storage keys of the lines the document dropped.
     *
     * @return  array<string, mixed>  The relationship handle, the four counts, and the identity digest.
     *
     * @since   2.0.0
     */
    private function documentEvidence(string $relationship, array $lines, array $removed): array
    {
        $added = 0;
        $amended = 0;
        foreach ($lines as $line) {
            if ($line->storedVersion === null) {
                ++$added;
                continue;
            }
            if ($line->modified) {
                ++$amended;
            }
        }

        return [
            'relationship' => $relationship,
            'line_count' => count($lines),
            'lines_added' => $added,
            'lines_amended' => $amended,
            'lines_removed' => count($removed),
            'line_identity_digest' => $this->fingerprints->digest(array_map(
                static fn (OwnedLineWrite $line): string => $line->recordId,
                $lines,
            )),
        ];
    }

    /**
     * Run archive, delete or restore through the one idempotent, fenced path the three share.
     *
     * The operation name is not decoration: it decides which rows are in view when the record is loaded —
     * archive sees only live ones, delete also sees archived ones, restore sees everything — and which
     * domain transition is then applied. Deletion splits again on the definition's soft-delete setting;
     * where it is off, inbound set-null references are cleared before the row is erased, and the record is
     * still marked deleted in memory so the revision and audit entry describe the version the delete
     * produced even though no row survives to carry it.
     *
     * @param   ExecutionContext  $context                 Actor and site the lifecycle change runs as.
     * @param   string            $definitionIdentifier    Definition UUID or handle naming the record type.
     * @param   string            $recordId                Caller-facing identity of the record to move.
     * @param   ?string           $organizationIdentifier  Organization the record is scoped to, or null.
     * @param   int               $expectedVersion         Version the caller read; a mismatch aborts.
     * @param   IdempotencyKey    $key                     Token a retry repeats to replay this outcome.
     * @param   string            $operation               `archive`, `delete` or `restore`; also the suffix
     *          of the idempotency operation name and the label the trail is written under.
     *
     * @return  RecordMutationResult  Keys and new version of the record, with `deleted` raised only for
     *          the delete operation.
     *
     * @throws  BusinessRecordIdempotencyConflict  When the key was reused for a different request or
     *          authority, has expired, or its stored entry cannot be replayed.
     * @throws  BusinessRecordTemporarilyUnavailable  When the installation moved between resolve and lock,
     *          the database refused the write transiently, or three idempotency races were lost.
     *
     * @since   2.0.0
     */
    private function lifecycle(
        ExecutionContext $context,
        string $definitionIdentifier,
        string $recordId,
        ?string $organizationIdentifier,
        int $expectedVersion,
        IdempotencyKey $key,
        string $operation,
    ): RecordMutationResult {
        $this->assertPostingPeriodOpen(
            $context,
            $definitionIdentifier,
            $organizationIdentifier,
            'business.record.' . $operation,
            $recordId,
            [],
            false,
            $operation !== 'archive',
            $operation === 'restore',
        );

        return $this->idempotent(
            $context,
            $definitionIdentifier,
            $organizationIdentifier,
            'business.record.' . $operation,
            $key,
            [
                'definition' => $definitionIdentifier,
                'record_id' => $recordId,
                'expected_version' => $expectedVersion,
            ],
            function (
                DateTimeImmutable $now,
                BusinessRecordMutationGeneration $generation,
            ) use (
                $context,
                $definitionIdentifier,
                $recordId,
                $organizationIdentifier,
                $expectedVersion,
                $operation,
            ): RecordMutationResult {
                [$resolved, , $record] = $this->load(
                    $context,
                    $definitionIdentifier,
                    $recordId,
                    $organizationIdentifier,
                    'business.record.' . $operation,
                    $operation !== 'archive',
                    $operation === 'restore',
                    $generation,
                );
                $this->expected($record, $expectedVersion);
                if ($operation !== 'delete') {
                    $this->assertRecordMutable($resolved, $record);
                }
                if ($operation === 'archive') {
                    $updated = $record->archived($context->actorId(), $now);
                    $this->writes->update($resolved, $updated, $expectedVersion);
                } elseif ($operation === 'restore') {
                    $updated = $record->restored($context->actorId(), $now);
                    $this->writes->update($resolved, $updated, $expectedVersion);
                } elseif ($resolved->definition->softDeleteEnabled) {
                    $updated = $record->softDeleted($context->actorId(), $now);
                    $this->writes->update($resolved, $updated, $expectedVersion);
                } else {
                    $record = $this->clearInboundSetNull($context, $resolved, $record, $now);
                    $updated = $record->softDeleted($context->actorId(), $now);
                    $this->writes->hardDelete($resolved, $record, $record->version);
                    $this->ownership->remove(
                        AuthorizationResource::item('business_record', $this->recordResourceIdentifier($record)),
                        $context->site(),
                    );
                }
                $this->publication->publish(
                    $context,
                    $resolved->definition,
                    $updated,
                    $operation,
                    [],
                    $now,
                );

                return $this->result($updated, $operation, $operation === 'delete');
            },
        );
    }

    /**
     * Clear every inbound set-null reference to a record whose row is about to be erased.
     *
     * A hard delete leaves nothing behind for a foreign key to point at, so the graph has to be emptied
     * first. Every active installed definition on the site is scanned for declared relationships aimed at
     * this one whose target column sits directly on the referencing record's own table; each match is
     * fenced, re-pinned to the version its rows were written under, and unrelated one row at a time so
     * that every source is re-versioned and audited in its own right. A source's posting period is judged
     * immediately before that rewrite inside the deleting transaction; a closed source therefore refuses
     * the delete by name and rolls back every earlier source version, revision and audit entry in the same
     * sweep. What the declared delete behaviour
     * decides is which of three things happens: a cascading relationship is refused outright — whether or
     * not any row currently uses it — because non-owned cascade deletion needs a bounded workflow this
     * path does not provide, a restricting one is detected through a one-row internal integrity probe, and
     * only a set-null one is cleared here. That probe deliberately bypasses actor row disclosure: hidden
     * referrers are never returned to the caller, but neither can they turn a later foreign-key failure into
     * an existence side channel. The sweep is bounded, so a record too widely referenced fails the delete.
     *
     * @param   ExecutionContext            $context         Actor and site the sweep runs as.
     * @param   ResolvedBusinessDefinition  $targetResolved  Pinned definition of the record being deleted.
     * @param   BusinessRecord              $target          Record being deleted, as loaded for the delete.
     * @param   DateTimeImmutable           $now             Instant stamped on every row the sweep rewrites.
     *
     * @return  BusinessRecord  The record being deleted, replaced by the version a write produced when it
     *          held one of the cleared references itself; otherwise the record that was passed in.
     *
     * @throws  BusinessRelationshipRejected  When a matching relationship cascades on delete, or more than
     *          `INBOUND_DELETE_LIMIT` records hold a set-null reference to this one.
     * @throws  BusinessRecordImmutable  When a record holding a set-null reference to the one being
     *          deleted is closed by its workflow state, because clearing the reference would rewrite a
     *          closed document's own fields.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordPostingPeriodClosed  When a
     *          record holding a set-null reference is dated in a closed posting period; the whole delete
     *          transaction, including earlier source rewrites, is rolled back.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable  When a
     *          referencing definition or the version one of its rows was written under cannot be resolved.
     * @throws  BusinessRecordSchemaUnavailable  When an active installation disagrees with the definition
     *          version it records, or describes no storage for a relationship being cleared.
     * @throws  BusinessRecordNotFound  When a referencing row disappeared between being listed and written.
     * @throws  BusinessRecordVersionConflict  When a referencing row moved between being listed and written.
     * @throws  BusinessRecordTemporarilyUnavailable  When a referencing definition's installation moved
     *          under the sweep, or the database refused a write for a transient reason.
     *
     * @since   2.0.0
     */
    private function clearInboundSetNull(
        ExecutionContext $context,
        ResolvedBusinessDefinition $targetResolved,
        BusinessRecord $target,
        DateTimeImmutable $now,
    ): BusinessRecord {
        foreach ($this->definitions->activeInstalled($context) as $candidate) {
            foreach ($candidate->definition->relationships() as $relationship) {
                if ($relationship->target !== $targetResolved->definition->handle) {
                    continue;
                }
                $direct = $candidate->installation->blueprint->table('record')?->column(
                    'relation:' . $relationship->handle . '.target_id',
                );
                if ($direct === null) {
                    continue;
                }
                if ($relationship->onDelete === DeleteBehavior::Cascade) {
                    throw new BusinessRelationshipRejected(
                        'Non-owned cascade deletion requires an explicit bounded delete workflow.',
                    );
                }
                if (
                    !in_array(
                        $relationship->onDelete,
                        [DeleteBehavior::Restrict, DeleteBehavior::SetNull],
                        true,
                    )
                ) {
                    continue;
                }
                $generation = $this->mutationFence->lock($context, $candidate->definition->handle);
                $sourceInstalled = $this->definitions->forCreate($context, $candidate->definition->handle);
                $generation->assertMatches($sourceInstalled);
                $sources = $this->reads->referencingForDeleteIntegrity(
                    $sourceInstalled,
                    $target->scope,
                    $relationship,
                    $target->recordKey,
                    $relationship->onDelete === DeleteBehavior::Restrict
                        ? 1
                        : self::INBOUND_DELETE_LIMIT + 1,
                );
                if ($relationship->onDelete === DeleteBehavior::Restrict) {
                    if ($sources !== []) {
                        throw new BusinessRecordReferenceConflict($relationship->handle);
                    }
                    continue;
                }
                if (count($sources) > self::INBOUND_DELETE_LIMIT) {
                    throw new BusinessRelationshipRejected(
                        'Inbound set-null deletion exceeds the bounded synchronous relationship limit.',
                    );
                }
                foreach ($sources as $source) {
                    $sourceResolved = $this->definitions->pinned(
                        $context,
                        $candidate->definition->handle,
                        $source->definitionVersion,
                    );
                    $generation->assertMatches($sourceResolved);
                    $this->postingPeriods->assertMutationOpen(
                        $sourceResolved->definition,
                        $source->scope,
                        $source,
                    );
                    $this->assertRecordMutable($sourceResolved, $source);
                    $write = $this->writes->unrelate(
                        $sourceResolved,
                        $source,
                        $relationship,
                        $target->recordKey,
                        $context->actorId(),
                        $now,
                        $source->version,
                        $targetResolved,
                        $target,
                    );
                    $this->publication->publish(
                        $context,
                        $sourceResolved->definition,
                        $write->source,
                        'unrelate.' . $relationship->handle,
                        [$relationship->handle],
                        $now,
                        $this->relationshipEvidence($relationship->handle, $target->recordId),
                    );
                    if (
                        $source->definitionId === $target->definitionId
                        && hash_equals($source->recordKey, $target->recordKey)
                    ) {
                        $target = $write->source;
                    }
                }
            }
        }

        return $target;
    }

    /**
     * Evaluate the posting-period lock for one mutation, before its fence acquires anything.
     *
     * This runs ahead of the transaction each mutation opens, so a closed period refuses without the
     * definition's exclusive installation lock ever being taken. A definition that declares no posting
     * date returns after the resolve alone. When a record is addressed it is loaded through the same
     * policy-filtered path the mutation itself will use; a record that path cannot see is left for the
     * mutation to judge, which is also what keeps an idempotent replay of a completed delete reachable.
     * The refusal decision itself — which dates are read and which are exempt, including the deliberate
     * exemption of workflow transitions — is `PostingPeriodLock`'s and is documented there.
     *
     * @param   ExecutionContext      $context                 Actor and site the mutation runs as.
     * @param   string                $definitionIdentifier    Definition UUID or handle being mutated.
     * @param   ?string               $organizationIdentifier  Organization the command is scoped to.
     * @param   string                $operation               Operation whose access plan the record
     *          load uses, matching the mutation's own.
     * @param   ?string               $recordId                Addressed record identity, or null for a
     *          create.
     * @param   array<string, mixed>  $values                  Values the command submits.
     * @param   bool                  $creating                True for a create, so an omitted posting
     *          value is judged at the field's declared default.
     * @param   bool                  $includeArchived         Whether the addressed record may be
     *          archived, mirroring the mutation's own load.
     * @param   bool                  $includeDeleted          Whether it may be soft-deleted, likewise.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordPostingPeriodClosed  When a
     *          posting date the mutation touches falls inside a closed period.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable  When
     *          no definition matches the identifier on this site, or its owner is disabled.
     * @throws  BusinessRecordValidationFailed  When the organization scope is not one this definition
     *          accepts.
     *
     * @since   2.0.0
     */
    private function assertPostingPeriodOpen(
        ExecutionContext $context,
        string $definitionIdentifier,
        ?string $organizationIdentifier,
        string $operation,
        ?string $recordId,
        array $values = [],
        bool $creating = false,
        bool $includeArchived = false,
        bool $includeDeleted = false,
    ): void {
        $resolved = $this->definitions->forCreate($context, $definitionIdentifier);
        if ($resolved->definition->postingDateField() === null) {
            return;
        }
        $scope = $this->scope($resolved, $context, $organizationIdentifier);
        $record = null;
        if ($recordId !== null) {
            try {
                // A short read-only transaction of its own, because the policy planner demands one;
                // the mutation fence is deliberately never taken here.
                $record = $this->transactions->transactional(
                    fn (): BusinessRecord => $this->load(
                        $context,
                        $definitionIdentifier,
                        $recordId,
                        $organizationIdentifier,
                        $operation,
                        $includeArchived,
                        $includeDeleted,
                    )[2],
                );
            } catch (BusinessRecordNotFound) {
                // Absence is the mutation's own verdict: it reports not-found itself, or replays the
                // completed command this key already ran — a gate must not pre-empt either answer.
                return;
            }
        }
        $this->postingPeriods->assertMutationOpen($resolved->definition, $scope, $record, $values, $creating);
    }

    /**
     * Run one mutation at most once per idempotency key, retrying the races that make that possible.
     *
     * The scope digest binds the key to the site, organization, actor and operation it was presented for,
     * and the entry additionally stores digests of the canonical request and of the caller's authority, so
     * the same key offered for a different request or under different authority is refused rather than
     * replayed. The ledger's operation name and the policy operation are allowed to differ: an unrelate
     * and a reorder are authorized as a relate, and a document write as the create or update of its
     * header, so each command still claims its own key while the policy catalogue keeps its closed set.
     * Claiming the entry, running the effect and completing it share the one transaction that also holds
     * the definition's exclusive fence, which is what makes an abandoned command roll its claim back with
     * the work it guarded, and what lets the effect assume the schema cannot move underneath it.
     *
     * Two failures are retried from the top rather than reported. A lost claim race is retried because the
     * next attempt finds the winner's completed entry and replays it; a transient failure is retried
     * because it may not recur. Three attempts is the ceiling for both, after which the operation is
     * reported as temporarily unavailable. Anything else the effect throws propagates unchanged, taking
     * the transaction and the claim with it.
     *
     * @param ExecutionContext $context Actor and site the mutation runs as.
     * @param string $definitionIdentifier Definition UUID or handle fenced for the whole attempt.
     * @param ?string $organizationIdentifier Organization the command is scoped to, or null.
     * @param string $operation Dotted operation name the ledger entry is keyed by.
     * @param IdempotencyKey $key Caller-supplied token identifying this logical command.
     * @param array<string, mixed> $request Canonical request body, fingerprinted so that a
     *          repeat under the same key is proved to be the same request.
     * @param callable(DateTimeImmutable, BusinessRecordMutationGeneration): RecordMutationResult $effect The mutation
     *          to run under the claim; it receives the instant to stamp on every row it writes and
     *          the generation the fence observed.
     *
     * @return  RecordMutationResult  What the effect produced, or the stored result of the earlier command
     *          that already ran under this key, flagged as a replay.
     *
     * @throws  BusinessRecordIdempotencyConflict  When the key was reused for a different request or
     *          authority, has expired, or its stored entry is in progress or fails its checksum.
     * @throws  BusinessRecordTemporarilyUnavailable  When three attempts were lost to idempotency races or
     *          transient failures, or the fence could not be taken.
     *
     * @since   2.0.0
     */
    private function idempotent(
        ExecutionContext $context,
        string $definitionIdentifier,
        ?string $organizationIdentifier,
        string $operation,
        IdempotencyKey $key,
        array $request,
        callable $effect,
    ): RecordMutationResult {
        $requestFingerprint = $this->fingerprints->digest($request);
        $authenticatedOrganization = $context->organization()?->identifier();
        $scopeDigest = $this->fingerprints->digest([
            'site' => $context->site()->identifier(),
            'organization' => $authenticatedOrganization,
            'actor' => $context->actorId(),
            'operation' => $operation,
            'key' => $key->value(),
        ]);
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            try {
                return $this->transactions->transactional(function () use (
                    $context,
                    $definitionIdentifier,
                    $organizationIdentifier,
                    $operation,
                    $key,
                    $effect,
                    $scopeDigest,
                    $requestFingerprint,
                    $authenticatedOrganization,
                ): RecordMutationResult {
                    $fenceStart = hrtime(true);
                    $generation = $this->mutationFence->lock($context, $definitionIdentifier);
                    $this->commitTimings->add('lock_wait', (hrtime(true) - $fenceStart) / 1_000_000);
                    $resolved = $this->definitions->forCreate($context, $definitionIdentifier);
                    $generation->assertMatches($resolved);
                    $scope = $this->scope($resolved, $context, $organizationIdentifier);
                    $policyOperation = match (true) {
                        in_array(
                            $operation,
                            ['business.record.unrelate', 'business.record.reorder'],
                            true,
                        ) => 'business.record.relate',
                        $operation === 'business.record.document_create' => 'business.record.create',
                        $operation === 'business.record.document_amend' => 'business.record.update',
                        default => $operation,
                    };
                    $access = $this->recordAccess->plan($context, $policyOperation, $resolved, $scope);
                    $authorizationFingerprint = $this->fingerprints->digest([
                        'context' => $context->authorizationFingerprint(),
                        'record_access' => $access->digest(),
                    ]);
                    $now = $this->clock->now();
                    $existing = $this->idempotency->find($scopeDigest);
                    if ($existing !== null) {
                        return $this->replay(
                            $existing,
                            $requestFingerprint,
                            $authorizationFingerprint,
                            $now,
                        );
                    }
                    $entry = new BusinessRecordIdempotency(
                        Uuid::uuid7()->toString(),
                        $scopeDigest,
                        $context->site()->identifier(),
                        $authenticatedOrganization,
                        $context->actorId(),
                        $operation,
                        $key->value(),
                        $requestFingerprint,
                        $authorizationFingerprint,
                        BusinessRecordIdempotencyState::InProgress,
                        null,
                        null,
                        $now,
                        null,
                        $this->replayWindow->expiryFrom($now),
                    );
                    $this->idempotency->begin($entry);
                    $result = $effect($now, $generation);
                    $resultChecksum = $this->fingerprints->digest($result->toArray());
                    $this->idempotency->complete($entry->id, $result->toArray(), $resultChecksum, $now);

                    return $result;
                });
            } catch (BusinessRecordIdempotencyRace) {
                continue;
            } catch (BusinessRecordTemporarilyUnavailable $exception) {
                if ($attempt === 2) {
                    throw $exception;
                }
            }
        }
        throw new BusinessRecordTemporarilyUnavailable();
    }

    /**
     * Claim and replay one approval request through the record mutation ledger.
     *
     * Approval creation is deliberately kept inside the same fence, policy digest and transaction as
     * record mutations. A network retry therefore receives the original request identifier instead of
     * creating a second maker-checker request, while a reused key, changed authority or moved definition
     * generation is refused before the approval store is touched.
     *
     * @param ExecutionContext $context Actor and site requesting approval.
     * @param string $definitionIdentifier Definition UUID or handle fenced for the attempt.
     * @param string|null $organizationIdentifier Organization asserted by the command, or null.
     * @param IdempotencyKey $key Caller-supplied logical operation identifier.
     * @param array<string, mixed> $request Canonical approval attempt fingerprint input.
     * @param callable(DateTimeImmutable, BusinessRecordMutationGeneration): ?string $effect Approval
     *          request effect, returning its UUID or null when no active rule requires approval.
     *
     * @return  string|null  The newly created or replayed approval request UUID.
     *
     * @throws  BusinessRecordIdempotencyConflict  When the key cannot be safely replayed.
     * @throws  BusinessRecordTemporarilyUnavailable  When three transactional attempts fail transiently.
     *
     * @since   2.0.0
     */
    private function idempotentApprovalRequest(
        ExecutionContext $context,
        string $definitionIdentifier,
        ?string $organizationIdentifier,
        IdempotencyKey $key,
        array $request,
        callable $effect,
    ): ?string {
        $operation = 'business.record.action_approval_request';
        $requestFingerprint = $this->fingerprints->digest($request);
        $authenticatedOrganization = $context->organization()?->identifier();
        $scopeDigest = $this->fingerprints->digest([
            'site' => $context->site()->identifier(),
            'organization' => $authenticatedOrganization,
            'actor' => $context->actorId(),
            'operation' => $operation,
            'key' => $key->value(),
        ]);
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            try {
                return $this->transactions->transactional(function () use (
                    $context,
                    $definitionIdentifier,
                    $organizationIdentifier,
                    $operation,
                    $key,
                    $effect,
                    $scopeDigest,
                    $requestFingerprint,
                    $authenticatedOrganization,
                ): ?string {
                    $generation = $this->mutationFence->lock($context, $definitionIdentifier);
                    $resolved = $this->definitions->forCreate($context, $definitionIdentifier);
                    $generation->assertMatches($resolved);
                    $scope = $this->scope($resolved, $context, $organizationIdentifier);
                    $access = $this->recordAccess->plan(
                        $context,
                        'business.record.action',
                        $resolved,
                        $scope,
                    );
                    $authorizationFingerprint = $this->fingerprints->digest([
                        'context' => $context->authorizationFingerprint(),
                        'record_access' => $access->digest(),
                    ]);
                    $now = $this->clock->now();
                    $existing = $this->idempotency->find($scopeDigest);
                    if ($existing !== null) {
                        return $this->replayApprovalRequest(
                            $existing,
                            $requestFingerprint,
                            $authorizationFingerprint,
                            $now,
                        );
                    }
                    $entry = new BusinessRecordIdempotency(
                        Uuid::uuid7()->toString(),
                        $scopeDigest,
                        $context->site()->identifier(),
                        $authenticatedOrganization,
                        $context->actorId(),
                        $operation,
                        $key->value(),
                        $requestFingerprint,
                        $authorizationFingerprint,
                        BusinessRecordIdempotencyState::InProgress,
                        null,
                        null,
                        $now,
                        null,
                        $this->replayWindow->expiryFrom($now),
                    );
                    $this->idempotency->begin($entry);
                    $approvalRequestId = $effect($now, $generation);
                    $result = [
                        'definition_id' => $resolved->definition->id,
                        'approval_request_id' => $approvalRequestId,
                    ];
                    $checksum = $this->fingerprints->digest($result);
                    $this->idempotency->complete($entry->id, $result, $checksum, $now);

                    return $approvalRequestId;
                });
            } catch (BusinessRecordIdempotencyRace) {
                continue;
            } catch (BusinessRecordTemporarilyUnavailable $exception) {
                if ($attempt === 2) {
                    throw $exception;
                }
            }
        }

        throw new BusinessRecordTemporarilyUnavailable();
    }

    /**
     * Prove and recover a completed approval-request outcome from the shared ledger.
     *
     * @param   BusinessRecordIdempotency  $entry                     Stored command claim.
     * @param   string                     $requestFingerprint        Digest of the approval attempt now presented.
     * @param   string                     $authorizationFingerprint  Digest of the caller's current authority.
     * @param   DateTimeImmutable          $now                       Instant the declared replay window and
     *          the entry's retention are both checked against.
     *
     * @return  string|null  The original approval request UUID, or null for the original no-rule outcome.
     *
     * @throws  BusinessRecordIdempotencyConflict  When any replay proof fails.
     *
     * @since   2.0.0
     */
    private function replayApprovalRequest(
        BusinessRecordIdempotency $entry,
        string $requestFingerprint,
        string $authorizationFingerprint,
        DateTimeImmutable $now,
    ): ?string {
        if (!$entry->matches($requestFingerprint, $authorizationFingerprint)) {
            throw new BusinessRecordIdempotencyConflict('key_reused');
        }
        if (!$this->replayWindow->admitsReplay($entry->createdAt, $now) || $now >= $entry->expiresAt) {
            throw new BusinessRecordIdempotencyConflict('replay_window_elapsed');
        }
        $result = $entry->result();
        if (!$entry->isCompleted() || $result === null) {
            throw new BusinessRecordIdempotencyConflict('in_progress');
        }
        if (
            $entry->resultChecksum === null
            || !hash_equals($entry->resultChecksum, $this->fingerprints->digest($result))
            || array_keys($result) !== ['definition_id', 'approval_request_id']
        ) {
            throw new BusinessRecordIdempotencyConflict('corrupt');
        }
        $definitionId = $result['definition_id'];
        $approvalRequestId = $result['approval_request_id'];
        if (
            !is_string($definitionId) || !Uuid::isValid($definitionId)
            || (
                $approvalRequestId !== null
                && (!is_string($approvalRequestId) || !Uuid::isValid($approvalRequestId))
            )
        ) {
            throw new BusinessRecordIdempotencyConflict('corrupt');
        }

        return $approvalRequestId;
    }

    /**
     * Reconstitute the outcome of the earlier command that already ran under this idempotency key.
     *
     * Four proofs stand between a stored entry and a replay: it must have been claimed for the same
     * request and the same authority, it must still be inside the declared replay window, it must have
     * completed rather than still be in progress, and its stored result must still match the checksum
     * written beside it. Any of them failing is reported as a conflict, because handing back a result
     * that cannot be proved to describe this command would be worse than refusing it.
     *
     * The window proof is separated from the fingerprint proof and answers with its own name. A repeat
     * that arrives after the window closed is not a reused key: it is the same command, unchanged,
     * arriving after the platform stopped promising to remember its outcome. Refusing it as
     * `replay_window_elapsed` is what stops a terminal that was disconnected for a long time from
     * quietly producing a second effect, and tells the operator plainly that this one needs
     * reconciling.
     *
     * @param   BusinessRecordIdempotency  $entry                     Stored ledger entry found for this
     *          command's scope digest.
     * @param   string                     $requestFingerprint        Digest of the canonical request now
     *          being presented.
     * @param   string                     $authorizationFingerprint  Digest of the authority the caller
     *          holds now.
     * @param   DateTimeImmutable          $now                       Instant the replay window and the
     *          entry's retention are both measured against.
     *
     * @return  RecordMutationResult  The stored outcome, rebuilt and flagged as a replay so the caller can
     *          tell it apart from a mutation applied by this call.
     *
     * @throws  BusinessRecordIdempotencyConflict  When the entry describes a different request or
     *          authority, when the declared replay window has closed, when the first attempt has not
     *          finished, or when the stored result fails its checksum or cannot be rebuilt.
     *
     * @since   2.0.0
     */
    private function replay(
        BusinessRecordIdempotency $entry,
        string $requestFingerprint,
        string $authorizationFingerprint,
        DateTimeImmutable $now,
    ): RecordMutationResult {
        if (!$entry->matches($requestFingerprint, $authorizationFingerprint)) {
            throw new BusinessRecordIdempotencyConflict('key_reused');
        }
        if (!$this->replayWindow->admitsReplay($entry->createdAt, $now) || $now >= $entry->expiresAt) {
            throw new BusinessRecordIdempotencyConflict('replay_window_elapsed');
        }
        $result = $entry->result();
        if (!$entry->isCompleted() || $result === null) {
            throw new BusinessRecordIdempotencyConflict('in_progress');
        }
        if (
            $entry->resultChecksum === null
            || !hash_equals($entry->resultChecksum, $this->fingerprints->digest($result))
        ) {
            throw new BusinessRecordIdempotencyConflict('corrupt');
        }

        try {
            return RecordMutationResult::fromArray($result)->asReplay();
        } catch (InvalidArgumentException) {
            throw new BusinessRecordIdempotencyConflict('corrupt');
        }
    }

    /**
     * Resolve a caller-facing identity to the pinned definition, scope and decoded record behind it.
     *
     * The definition is resolved twice on purpose. The installed version is used first, only to normalize
     * the identity and to learn which definition version the stored row was actually written under; the
     * definition is then resolved again pinned to that version and the scope recomputed against it, and
     * the two scopes must agree before a single value is decoded. The storage key read back with the row
     * must also match the one the identity resolved to, so a row exchanged underneath the lookup is
     * refused rather than returned. An identity the definition cannot parse is reported as not found, not
     * as a validation failure, so a caller cannot probe identities it has no access to.
     *
     * @param   ExecutionContext                   $context                 Actor and site the load runs as.
     * @param   string                             $definitionIdentifier    Definition UUID or handle naming
     *          the record type.
     * @param   string                             $recordId                Caller-facing identity to
     *          resolve, before normalization.
     * @param   ?string                            $organizationIdentifier  Organization the row must
     *          belong to, or null for a definition that carries no organization scope.
     * @param   string                             $operation               Operation whose row and field
     *          policy must admit the record.
     * @param   bool                               $includeArchived         True to also reach an archived
     *          row.
     * @param   bool                               $includeDeleted          True to also reach a
     *          soft-deleted row.
     * @param   ?BusinessRecordMutationGeneration  $generation              Generation the caller's fence
     *          observed, asserted against the resolved definition; null when the caller holds no fence.
     *
     * @return  array{ResolvedBusinessDefinition, RecordScope, BusinessRecord, BusinessRecordAccessPlan}
     *          The pinned definition, exact scope, decoded record and operation-specific access decision.
     *
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable  When no
     *          definition on this site matches the identifier, or the pinned version is not published.
     * @throws  BusinessRecordSchemaUnavailable  When the schema is not installed and active, or a stored
     *          value does not match the column the blueprint describes.
     * @throws  BusinessRecordValidationFailed  When the organization scope is not one this definition
     *          accepts.
     * @throws  BusinessRecordNotFound  When the identity is not usable for this definition, or no row it
     *          admits answers it in this scope.
     * @throws  BusinessRecordReferenceConflict  When the definition declares no identity field, the pinned
     *          definition resolves to a different scope, or the row's storage key disagrees with the
     *          identity that addressed it.
     * @throws  BusinessRecordTemporarilyUnavailable  When the resolved installation is not the generation
     *          the caller's fence is holding.
     *
     * @since   2.0.0
     */
    private function load(
        ExecutionContext $context,
        string $definitionIdentifier,
        string $recordId,
        ?string $organizationIdentifier,
        string $operation,
        bool $includeArchived = false,
        bool $includeDeleted = false,
        ?BusinessRecordMutationGeneration $generation = null,
    ): array {
        $installed = $this->definitions->forCreate($context, $definitionIdentifier);
        $generation?->assertMatches($installed);
        $scope = $this->scope($installed, $context, $organizationIdentifier);
        $installedAccess = $this->recordAccess->plan($context, $operation, $installed, $scope);
        try {
            $normalizedId = $this->values->identity(
                $installed->definition,
                [$this->identityField($installed->definition)->handle => $recordId],
                null,
            );
        } catch (InvalidArgumentException) {
            throw new BusinessRecordNotFound();
        }
        $identity = $this->reads->identity($installed, $scope, $installedAccess, $normalizedId, $includeDeleted)
            ?? throw new BusinessRecordNotFound();
        $resolved = $this->definitions->pinned($context, $definitionIdentifier, $identity->definitionVersion);
        $pinnedScope = $this->scope($resolved, $context, $organizationIdentifier);
        if ($pinnedScope->toArray() !== $scope->toArray()) {
            throw new BusinessRecordReferenceConflict();
        }
        $access = $this->recordAccess->plan($context, $operation, $resolved, $pinnedScope);
        $record = $this->reads->get(
            $resolved,
            $pinnedScope,
            $access,
            $normalizedId,
            $includeArchived,
            $includeDeleted,
        ) ?? throw new BusinessRecordNotFound();
        if (!hash_equals($record->recordKey, $identity->recordKey)) {
            throw new BusinessRecordReferenceConflict();
        }
        if (!$access->records->allows($record->values())) {
            throw new BusinessRecordNotFound();
        }

        return [$resolved, $pinnedScope, $record, $access];
    }

    /**
     * Reserve one document number for every `core.sequence` field the definition declares.
     *
     * This runs inside the create command's transaction, behind the mutation fence and after the record
     * access plan, which is the whole point: the number, the row, the revision and the audit entry commit
     * together, so a create that is later refused by policy, loses an optimistic check or fails validation
     * gives its number straight back and leaves no hole in the run. It also means the allocation composes
     * with authorization rather than bypassing it — a caller who may not create the record never reaches
     * this method, and the counter it would have advanced is chosen from the record's own resolved scope
     * rather than from anything the caller sent.
     *
     * A definition declaring no sequence field allocates nothing and takes no lock.
     *
     * The period key is composed here, per reset case. The calendar resets read the command instant, so
     * their behaviour is untouched by anything declared over the posting timeline; a `fiscal-period`
     * reset instead reads the record's declared posting date and resolves the declared posting period
     * containing it through the `PostingPeriodCalendar` seam, because a fiscal period is about when the
     * document is posted and not about when the command happens to run.
     *
     * A record mode without a site dimension deliberately falls back to the definition's catalog site.
     * That coordinate is stable rather than mutable ownership state: `business_definition` and
     * `business_record` are site-only resource categories, and the catalog repository refuses any change
     * to a definition's identity, site, handle or owner. An attempted widening or move therefore fails
     * before allocation and cannot restart an existing run under another site coordinate.
     *
     * @param   ResolvedBusinessDefinition  $resolved         Definition and installed schema the record is
     *          being created against.
     * @param   RecordScope                 $scope            Resolved site and organization the record
     *          belongs to.
     * @param   DateTimeImmutable           $now              Instant the command runs at; also what decides
     *          which calendar period a resetting counter allocates from.
     * @param   array<string, mixed>        $submittedValues  Values the create command submits, read only
     *          for the declared posting-date field a `fiscal-period` reset keys its counter on.
     *
     * @return  array<string, string>  Rendered numbers keyed by the field handle each belongs to; empty
     *          when the definition declares no allocated-number field.
     *
     * @throws  \InvalidArgumentException  When a published definition carries a sequence declaration this
     *          runtime cannot allocate under, which `BusinessDefinitionValidator` should have refused.
     * @throws  BusinessRecordPostingPeriodUndeclared  When a `fiscal-period` counter's posting date is
     *          contained by no declared posting period, or the record declares no posting date at all.
     * @throws  BusinessRecordValidationFailed  When the submitted posting date a `fiscal-period` counter
     *          must be keyed on is malformed.
     * @throws  BusinessRecordTemporarilyUnavailable  When another allocator holds the counter and this
     *          command must be replayed rather than guess at a number; the port's `NumberSequenceUnavailable`
     *          is translated here so callers keep seeing one transient refusal with the driver failure chained.
     *
     * @since   2.0.0
     */
    private function allocateNumbers(
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        DateTimeImmutable $now,
        array $submittedValues,
    ): array {
        $allocated = [];
        foreach ($resolved->definition->fields() as $field) {
            if ($field->type !== 'core.sequence') {
                continue;
            }
            $format = NumberSequenceFormat::fromConfiguration($field->configuration);
            $counter = $format->reset === NumberSequenceReset::FiscalPeriod
                ? [
                    'scope' => $format->scope->key($scope->organizationIdentifier),
                    'period' => $this->fiscalPeriodKey($resolved, $scope, $submittedValues),
                ]
                : $format->counter($scope->organizationIdentifier, $now);
            $sequenceStart = hrtime(true);
            try {
                $value = $this->numbers->allocate(
                    $scope->siteIdentifier ?? $resolved->definition->siteIdentifier,
                    $resolved->definition->id,
                    $field->handle,
                    $counter['scope'],
                    $counter['period'],
                    $now,
                );
            } catch (NumberSequenceUnavailable $unavailable) {
                throw new BusinessRecordTemporarilyUnavailable($unavailable);
            }
            $allocated[$field->handle] = $format->render($value, $counter['period']);
            $this->commitTimings->add('lock_wait', (hrtime(true) - $sequenceStart) / 1_000_000);
        }

        return $allocated;
    }

    /**
     * Resolve the stable key of the declared posting period a fiscal-period counter belongs to.
     *
     * The instant read is the record's declared posting date — the submitted value of the definition's
     * `posting_date` field, or that field's declared default when the create omits it — never the
     * allocation instant, because a backdated document belongs to the period it is posted in. The date
     * is exchanged for a declared period through the `PostingPeriodCalendar` seam, in the record's own
     * resolved scope. A date no declaration contains refuses by name rather than allocating under an
     * empty key: an empty key is the lifetime run, and handing a fiscal document a lifetime number
     * would be a lie the rendered value repeats forever. A malformed submitted date is reported as the
     * same field violation the validation pass would raise, so the caller sees one kind of refusal for
     * one kind of mistake.
     *
     * @param   ResolvedBusinessDefinition  $resolved         Definition declaring the posting-date field.
     * @param   RecordScope                 $scope            Site and organization whose declared periods
     *          answer.
     * @param   array<string, mixed>        $submittedValues  Values the create command submits.
     *
     * @return  string  Stable key of the declared period containing the posting date.
     *
     * @throws  \InvalidArgumentException  When the definition declares no posting-date field, which
     *          `BusinessDefinitionValidator` refuses at publication.
     * @throws  BusinessRecordValidationFailed  Carrying one violation on the posting-date field, when
     *          the submitted value is malformed.
     * @throws  BusinessRecordPostingPeriodUndeclared  When the record carries no posting date, or no
     *          declared period contains it.
     *
     * @since   2.0.0
     */
    private function fiscalPeriodKey(
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        array $submittedValues,
    ): string {
        $definition = $resolved->definition;
        $field = $definition->postingDateField() ?? throw new InvalidArgumentException(
            'A fiscal-period number sequence requires a declared posting date field.',
        );
        $value = array_key_exists($field->handle, $submittedValues)
            ? $submittedValues[$field->handle]
            : $field->default;
        $instant = null;
        if ($value !== null) {
            try {
                $normalized = $this->values->normalize(
                    $field,
                    $value,
                    $definition->siteIdentifier,
                    $definition->id,
                    '',
                );
            } catch (InvalidArgumentException $exception) {
                throw new BusinessRecordValidationFailed([
                    new ValidationViolation($field->handle, 'invalid_type', $exception->getMessage()),
                ]);
            }
            if ($normalized instanceof DateTimeImmutable) {
                $instant = $normalized;
            } elseif ($normalized instanceof ZonedDateTimeValue) {
                $instant = $normalized->instant;
            }
        }
        if ($instant === null) {
            throw new BusinessRecordPostingPeriodUndeclared(null);
        }
        $period = $this->periodCalendar->periodContaining(
            $scope->siteIdentifier ?? $definition->siteIdentifier,
            $scope->organizationIdentifier,
            $instant,
        );
        if ($period === null) {
            throw new BusinessRecordPostingPeriodUndeclared($instant);
        }

        return $period->key;
    }

    /**
     * Derive the scope an operation runs in, reporting a bad organization as a validation failure.
     *
     * The site half comes from the execution context and is never caller-supplied; only the organization
     * is, and the definition's scope mode decides whether it is required, rejected or ignored. Translating
     * the domain's argument error into a violation on the `scope` field is what lets a delivery adapter
     * report a wrong organization beside a form's other field problems instead of as a request fault.
     *
     * @param   ResolvedBusinessDefinition  $resolved                Definition whose scope mode applies.
     * @param   ExecutionContext            $context                 Actor and site the operation runs as.
     * @param   ?string                     $organizationIdentifier  Organization the caller asked to work
     *          in, or null.
     *
     * @return  RecordScope  Site and organization every read and write of this operation is confined to.
     *
     * @throws  BusinessRecordValidationFailed  Carrying one violation on `scope`, when the organization is
     *          malformed, missing for a mode that requires it, or supplied for a mode that does not.
     *
     * @since   2.0.0
     */
    private function scope(
        ResolvedBusinessDefinition $resolved,
        ExecutionContext $context,
        ?string $organizationIdentifier,
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
                    && $organizationIdentifier !== null
                    && $organizationIdentifier !== $authenticatedOrganization)
                || (!$organizationScoped && $organizationIdentifier !== null)
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
     * Exchange entity-reference values for the internal storage key each one points at.
     *
     * Only the handles named are considered, which is what keeps an update from re-resolving references it
     * never touched. Each target is fenced, resolved, and looked up in the same organization as the record
     * being written. Resolution consumes the source operation's exact nested access plan rather than
     * independently authorizing a target read, so a submitted identifier cannot bypass the row and
     * public-reference policy that produced a graphical selector. A target that is missing, out of scope,
     * or whose field declares no usable target is reported as a violation on that field rather than
     * aborting the pass, so every bad reference in a submission is reported at once.
     *
     * @param   ExecutionContext          $context     Actor and site the resolution runs as.
     * @param   EntityTypeDefinition      $definition  Definition whose fields are inspected for references.
     * @param   RecordScope               $scope       Scope the source record belongs to, which every target
     *          must share.
     * @param   BusinessRecordAccessPlan  $access      Source operation plan carrying each field's exact nested
     *          target row and field-disclosure decision.
     * @param   array<string, mixed>      $values      Value set to rewrite, keyed by field handle.
     * @param   list<string>              $handles     Handles this pass is allowed to touch; anything outside
     *          it is left exactly as it arrived.
     *
     * @return  array<string, mixed>  The same value set with each named reference replaced by the target's
     *          internal record key, ready to be written to the column.
     *
     * @throws  BusinessRecordValidationFailed  Carrying one violation per unusable reference, when a value
     *          or its declared target is not a string, or names no record in this scope; and carrying a
     *          `scope` violation when a target definition will not accept the source's organization.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable  When a
     *          field's declared target definition does not exist on this site, or its owner is disabled.
     * @throws  BusinessRecordSchemaUnavailable  When a target definition's schema is not installed and
     *          active, or a stored identity is malformed.
     * @throws  BusinessRecordTemporarilyUnavailable  When a target's installation moved between its
     *          resolve and its lock.
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
        // Classified in declaration order first, so the violations a caller sees keep their exact order,
        // then resolved in fence-acquisition order — the target handle is the lock key, and taking those
        // row locks in one order for every command is what a stable lock order means here. Iterating the
        // fields as declared while locking would let two definitions that name the same pair of targets
        // through differently-ordered fields take that fence pair in opposite orders.
        /** @var array<string, string> $resolvable */
        $resolvable = [];
        /** @var list<string> $invalid */
        $invalid = [];
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
                $invalid[] = $field->handle;
                continue;
            }
            $resolvable[$field->handle] = $targetHandle;
        }

        /** @var array<string, string> $resolved */
        $resolved = [];
        /** @var list<string> $unresolved */
        $unresolved = [];
        foreach (BusinessRecordRelationshipCoordinator::referenceAcquisitionOrder($resolvable) as $handle) {
            $value = $values[$handle] ?? null;
            if (!is_string($value)) {
                continue;
            }
            try {
                $targetAccess = $access->related($handle);
                if ($targetAccess === null) {
                    throw new BusinessRecordNotFound();
                }
                $lockStart = hrtime(true);
                $targetGeneration = $this->mutationFence->lock($context, $resolvable[$handle]);
                $this->commitTimings->add('lock_wait', (hrtime(true) - $lockStart) / 1_000_000);
                $target = $this->definitions->forCreate($context, $resolvable[$handle]);
                $targetGeneration->assertMatches($target);
                $targetScope = $this->scope($target, $context, $scope->organizationIdentifier);
                $identityField = $this->identityField($target->definition);
                $this->relationships->assertPortalTargetOperation(
                    $context,
                    $target->definition,
                    PortalOperation::Read,
                );
                $this->relationships->assertRelatedTargetAccess($target->definition, $targetAccess);
                $targetId = $this->values->identity(
                    $target->definition,
                    [$identityField->handle => $value],
                    null,
                );
                $identity = $this->reads->identity($target, $targetScope, $targetAccess, $targetId);
                if ($identity === null) {
                    throw new BusinessRecordNotFound();
                }
                $resolved[$handle] = $identity->recordKey;
            } catch (BusinessRecordNotFound | InvalidArgumentException) {
                $unresolved[] = $handle;
            }
        }

        $violations = [];
        foreach ($definition->fields() as $field) {
            if (in_array($field->handle, $invalid, true)) {
                $violations[] = new ValidationViolation(
                    $field->handle,
                    'reference',
                    'The entity reference or target definition is invalid.',
                );
                continue;
            }
            if (in_array($field->handle, $unresolved, true)) {
                $violations[] = new ValidationViolation(
                    $field->handle,
                    'reference',
                    'The referenced business record does not exist in this scope.',
                );
                continue;
            }
            if (isset($resolved[$field->handle])) {
                $values[$field->handle] = $resolved[$field->handle];
            }
        }
        if ($violations !== []) {
            throw new BusinessRecordValidationFailed($violations);
        }

        return $values;
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
     * @throws  BusinessRecordValidationFailed  With one generic violation when any handle is unavailable.
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
                    new ValidationViolation('record', 'field_access', 'One or more submitted fields are unavailable.'),
                ]);
            }
        }
    }

    /**
     * Project validator failures through the exact operation field-disclosure plan.
     *
     * A definition validator necessarily sees required, conditional, formula, and invariant dependencies
     * that the caller may not. Violations on permitted input handles retain their useful field-specific
     * detail. Any number of violations on unavailable handles collapses to one generic record failure, so
     * neither names nor counts can be recovered from REST, CLI, MCP, or generated form errors.
     *
     * A breached record invariant is reported as itself rather than collapsed. Its handle and its message
     * are declared in the published definition and describe a rule, not a value, so they disclose nothing
     * about the record — and collapsing them would make the operator-facing wording the definition author
     * wrote unreachable, which is the whole reason a rule carries one. That matters most for the rule that
     * spans a document: an operator told that a total disagrees with its lines can fix it, while an
     * operator told that "one or more submitted fields are unavailable" cannot.
     *
     * @param   BusinessRecordValidationFailed  $failure     Complete trusted validator result.
     * @param   ExecutionContext                $context     Authenticated site and surface.
     * @param   EntityTypeDefinition            $definition  Definition whose immutable flags are ceilings.
     * @param   BusinessRecordAccessPlan        $access      Exact create/update or owned-line access plan.
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
     * Prove a validator-visible reference field has the exact nested public identity required by metadata.
     *
     * Non-reference inputs need no second resource boundary. Entity-reference fields fail closed when the
     * target is unavailable, the nested plan belongs to another definition, or its public-reference grant
     * omits the target's real identity field. Submitted known-reference failures are raised later by
     * `resolveEntityReferences()` and therefore retain their useful source handle when this field is visible.
     *
     * @param   ExecutionContext          $context  Authenticated site used to resolve the declared target.
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

        return hash_equals($target->definition->id, $targetAccess->resourceIdentifier)
            && $targetAccess->fields->allows(
                FieldAccessUsage::PublicReference,
                $this->identityField($target->definition)->handle,
            );
    }

    /**
     * Refuse the mutation unless the record still sits at the version the caller read.
     *
     * @param   BusinessRecord  $record           Record as it was just loaded.
     * @param   int             $expectedVersion  Version the caller submitted the mutation against.
     *
     * @return  void
     *
     * @throws  BusinessRecordVersionConflict  When the stored record moved past the expected version.
     *
     * @since   2.0.0
     */
    private function expected(BusinessRecord $record, int $expectedVersion): void
    {
        if ($record->version !== $expectedVersion) {
            throw new BusinessRecordVersionConflict($expectedVersion, $record->version);
        }
    }

    /**
     * Refuse every content mutation of a record whose definition closes it in its current workflow state.
     *
     * This is the whole document-immutability rule at its single seam: each path that would rewrite the
     * record's own row or its owned lines — update, archive, restore, relate, unrelate, reorder, the
     * document amend, and the set-null sweep a hard delete of another record runs — passes the record it
     * loaded through here before anything is written. The record is judged against the definition version
     * it was written under, exactly as the rest of the operation is. Two things deliberately do not pass
     * through: workflow transitions, because immutability freezes content and not the state machine, and
     * the record's own audited delete lifecycle, which the decision record leaves unchanged.
     *
     * @param   ResolvedBusinessDefinition  $resolved  Pinned definition whose workflow binding declares the
     *          immutable states, when it declares any.
     * @param   BusinessRecord              $record    Record about to be mutated, as it was loaded.
     *
     * @return  void
     *
     * @throws  BusinessRecordImmutable  When the binding names the record's current state immutable.
     *
     * @since   2.0.0
     */
    private function assertRecordMutable(ResolvedBusinessDefinition $resolved, BusinessRecord $record): void
    {
        if ($resolved->definition->workflow?->immutableIn($record->workflowState) === true) {
            throw new BusinessRecordImmutable((string) $record->workflowState);
        }
    }

    /**
     * Ask the gateway whether the actor may perform one record operation at all.
     *
     * The question is put against the `business_record` collection rather than an individual record, so it
     * is settled before any definition is resolved or any row is read — a caller without the capability
     * never learns whether the record it named exists. An action's own capability and that of the workflow
     * transition it performs are asked for separately, by `action()`, once the record itself is in hand.
     *
     * @param   ExecutionContext  $context     Actor, site and authority the operation runs under.
     * @param   string            $capability  Dotted capability the operation requires, such as
     *          `business.record.update`.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses the actor this
     *          operation on business records in this site.
     *
     * @since   2.0.0
     */
    private function authorize(ExecutionContext $context, string $capability): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString($capability),
            AuthorizationResource::collection('business_record'),
        );
    }

    /**
     * Describe a stored record as the outcome both the caller and the idempotency ledger receive.
     *
     * The same object is returned to the caller and checksummed into the ledger entry, which is what makes
     * a later replay answerable without re-reading the record.
     *
     * @param   BusinessRecord  $record     Record at the version this mutation left it.
     * @param   string          $operation  Name the mutation is reported under, matching the label used in
     *          the revision and the audit entry.
     * @param   bool            $deleted    True when no live row survives the mutation.
     *
     * @return  RecordMutationResult  Identity, version and workflow state of the record, marked as applied
     *          rather than replayed.
     *
     * @since   2.0.0
     */
    private function result(BusinessRecord $record, string $operation, bool $deleted = false): RecordMutationResult
    {
        return new RecordMutationResult(
            $record->definitionId,
            $record->definitionVersion,
            $record->recordKey,
            $record->recordId,
            $record->version,
            $record->workflowState,
            $operation,
            $deleted,
        );
    }

    /**
     * Build the disclosure-safe evidence a relationship mutation records about its far end.
     *
     * The target's identity and any embedded line values are reduced to keyed digests rather than stored,
     * so a later request can be compared against what was recorded without the trail holding the values
     * themselves — which matters because a line's values are ordinary record data and its identity may be
     * a business reference.
     *
     * @param   string                $relationship    Handle of the relationship that was written.
     * @param   string                $targetIdentity  Caller-facing identity of the far end, digested
     *          rather than stored.
     * @param   ?int                  $position        Slot the caller asked for in an ordered collection,
     *          or null when it appended or the relationship is unordered.
     * @param   array<string, mixed>  $embeddedValues  Values of an owned line created by the same write;
     *          empty for every other kind of relationship.
     *
     * @return  array<string, mixed>  The relationship handle, the digest of the target identity, the
     *          requested position, and the digest of the embedded values or null when there were none.
     *
     * @since   2.0.0
     */
    private function relationshipEvidence(
        string $relationship,
        string $targetIdentity,
        ?int $position = null,
        array $embeddedValues = [],
    ): array {
        return [
            'relationship' => $relationship,
            'target_identity_digest' => $this->fingerprints->digest($targetIdentity),
            'position' => $position,
            'embedded_values_digest' => $embeddedValues === []
                ? null
                : $this->fingerprints->digest($embeddedValues),
        ];
    }

    /**
     * Name the field handles whose value actually differs between two value sets.
     *
     * Comparison is made on the canonical spelling rather than the raw value, so resubmitting a decimal or
     * a timestamp in another equivalent form does not register as a change and does not appear in the
     * trail. A handle present on only one side is compared against null, which means a field that went
     * from absent to explicitly null counts as unchanged.
     *
     * @param   array<string, mixed>  $before  Values the record held before the mutation.
     * @param   array<string, mixed>  $after   Values it holds afterwards.
     *
     * @return  list<string>  Changed handles in ascending string order; empty when the two sets are
     *          equivalent, which is what a write with no effect produces.
     *
     * @since   2.0.0
     */
    private function changed(array $before, array $after): array
    {
        $handles = array_values(array_unique([...array_keys($before), ...array_keys($after)]));
        $changed = [];
        foreach ($handles as $handle) {
            if (
                RecordValueGuard::canonical($before[$handle] ?? null)
                !== RecordValueGuard::canonical($after[$handle] ?? null)
            ) {
                $changed[] = $handle;
            }
        }
        sort($changed, SORT_STRING);

        return $changed;
    }

    /**
     * Find the field that carries a definition's caller-facing identity.
     *
     * Which field that is follows from the identity strategy rather than from a name: a UUID-identity type
     * declares a `core.uuid` field, and one identified by a business reference declares a
     * `core.reference_identity` field. A definition that declares neither cannot address its own records,
     * so this refuses rather than guessing at the first plausible field.
     *
     * @param   EntityTypeDefinition  $definition  Definition whose identity field is wanted.
     *
     * @return  FieldDefinition  The first field of the type the identity strategy requires.
     *
     * @throws  BusinessRecordReferenceConflict  When the definition declares no field of that type.
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
     * Look a field up by handle without insisting that the definition still declares it.
     *
     * Used while annotating an audit entry, where a handle that changed under an older definition version
     * may have been dropped since. Not finding it costs the entry its redaction marking, which is why the
     * caller treats an unknown handle as not sensitive rather than as an error.
     *
     * @param   EntityTypeDefinition  $definition  Definition to search.
     * @param   string                $handle      Field handle to look for.
     *
     * @return  ?FieldDefinition  The field, or null when this definition version declares none under that
     *          handle.
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
     * Reject a portal include unless its exact nested target remains discoverable for this read operation.
     *
     * Generated adapters validate includes against the shared catalog, but the canonical service also
     * defends its own direct callers. A target with no public identity, no explicit portal operation, or a
     * statically empty row policy is indistinguishable from an undeclared relationship.
     *
     * @param   ExecutionContext          $context     Authenticated surface and tenant.
     * @param   EntityTypeDefinition      $definition  Source definition declaring the include handles.
     * @param   BusinessRecordAccessPlan  $access      Exact source read or browse access plan.
     * @param   list<string>              $includes    Requested relationship handles.
     * @param   PortalOperation           $operation   Target-side portal operation required.
     *
     * @return  void
     *
     * @throws  BusinessRecordNotFound  When a portal include is absent from its policy-filtered catalog.
     *
     * @since   2.0.0
     */
    private function assertPortalIncludes(
        ExecutionContext $context,
        EntityTypeDefinition $definition,
        BusinessRecordAccessPlan $access,
        array $includes,
        PortalOperation $operation,
    ): void {
        if ($context->surface() !== AuthenticatedSurface::Portal) {
            return;
        }
        foreach ($includes as $handle) {
            $relationship = $definition->runtimeRelationship($handle)
                ?? throw new BusinessRecordNotFound();
            $targetAccess = $access->related($handle)
                ?? throw new BusinessRecordNotFound();
            try {
                $target = $this->definitions->forCreate($context, $relationship->target);
            } catch (BusinessRecordDefinitionUnavailable | BusinessRecordSchemaUnavailable) {
                throw new BusinessRecordNotFound();
            }
            $this->relationships->assertPortalTargetOperation($context, $target->definition, $operation);
            $this->relationships->assertRelatedTargetAccess($target->definition, $targetAccess);
            if (!$this->maySelectRelatedRows($targetAccess)) {
                throw new BusinessRecordNotFound();
            }
        }
    }

    /**
     * Report whether a nested row plan could select at least one target without loading business rows.
     *
     * @param   BusinessRecordAccessPlan  $access  Exact nested target plan.
     *
     * @return  bool  False only for default/constant deny-all plans.
     *
     * @since   2.0.0
     */
    private function maySelectRelatedRows(BusinessRecordAccessPlan $access): bool
    {
        foreach ($access->records->denies as $deny) {
            if ($deny instanceof RecordPolicyConstant && $deny->value) {
                return false;
            }
        }
        foreach ($access->records->allows as $allow) {
            if (!($allow instanceof RecordPolicyConstant) || $allow->value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply immutable field ceilings before a reference selector can enumerate choices.
     *
     * @param   FieldDefinition   $field  Entity-reference field requesting target choices.
     * @param   FieldAccessUsage  $usage  Create or update use selected by the source operation.
     *
     * @return  bool  True only when the definition itself permits input for this operation.
     *
     * @since   2.0.0
     */
    private function inputFieldAvailable(FieldDefinition $field, FieldAccessUsage $usage): bool
    {
        if (
            $field->readOnly
            || $field->computed
            || $field->serverOnly
            || $field->formula !== null
        ) {
            return false;
        }

        return match ($usage) {
            FieldAccessUsage::Create => $field->createVisible,
            FieldAccessUsage::Update => $field->updateVisible && !$field->immutableAfterCreate,
            default => false,
        };
    }

    /**
     * Describe at most sixteen target fields the nested selector plan authorizes for text search.
     *
     * A conditional-visibility field is deliberately absent even when its static definition says searchable:
     * until a row-correlated visibility predicate can be compiled, offering it as a selector predicate would
     * let result membership disclose a value hidden on some rows. Declaration order is retained for stable UI.
     *
     * @param   EntityTypeDefinition      $definition  Target definition being browsed.
     * @param   BusinessRecordAccessPlan  $access      Nested related-target authorization plan.
     *
     * @return  list<array{handle: string, label: string}>  Bounded policy-safe search controls.
     *
     * @since   2.0.0
     */
    private function relatedSearchFields(
        EntityTypeDefinition $definition,
        BusinessRecordAccessPlan $access,
    ): array {
        $fields = [];
        foreach ($definition->fields() as $field) {
            if (
                !$field->searchable
                || !$field->readVisible
                || $field->visibilityCondition !== null
                || !$access->fields->allows(FieldAccessUsage::Search, $field->handle)
            ) {
                continue;
            }
            $fields[] = ['handle' => $field->handle, 'label' => $field->label];
            if (count($fields) === 16) {
                break;
            }
        }

        return $fields;
    }

    /**
     * Rebuild the non-transferable binding shared by approval request and action execution.
     *
     * @param   ExecuteRecordActionCommand  $command  Validated action attempt.
     * @param   BusinessRecord              $record   Policy-visible current record version.
     * @param   ActionDefinition            $action   Immutable definition-declared action.
     *
     * @return  ApprovalBinding  Exact actor, scope, action, record, version, and payload binding.
     *
     * @since   2.0.0
     */
    private function actionApprovalBinding(
        ExecuteRecordActionCommand $command,
        BusinessRecord $record,
        ActionDefinition $action,
    ): ApprovalBinding {
        return ApprovalBinding::fromContext(
            $command->context,
            'business.record.action:' . $action->handle,
            'business_record',
            $this->recordResourceIdentifier($record),
            $record->version,
            $this->fingerprints->digest([
                'definition_id' => $record->definitionId,
                'definition_version' => $record->definitionVersion,
                'record_key' => $record->recordKey,
                'record_id' => $record->recordId,
                'record_version' => $record->version,
                'action' => $action->handle,
                'input' => $command->input,
            ]),
        );
    }

    /**
     * Build the collision-safe authorization identity shared by ownership and record approvals.
     *
     * Record keys are unique only within a definition's physical table, so a bare UUID cannot identify
     * one resource across the site-wide ownership registry. Pairing the immutable definition UUID with
     * the storage key keeps two definitions free to carry the same caller-selected UUID record key.
     *
     * @param   BusinessRecord  $record  Record whose authorization resource is addressed.
     *
     * @return  string  `<definition UUID>:<record key UUID>`.
     *
     * @since   2.0.0
     */
    private function recordResourceIdentifier(BusinessRecord $record): string
    {
        return $record->definitionId . ':' . $record->recordKey;
    }

    /**
     * Find the action a command named among those the pinned definition declares.
     *
     * Searching the pinned version rather than the installed one means an action introduced after a record
     * was written cannot be run against it until that record is brought forward.
     *
     * @param   EntityTypeDefinition  $definition  Pinned definition the record was written under.
     * @param   string                $handle      Action handle the command named.
     *
     * @return  ActionDefinition  The declared action, carrying its capability, condition and transition.
     *
     * @throws  BusinessRecordActionRejected  When the pinned definition declares no action under that
     *          handle.
     *
     * @since   2.0.0
     */
    private function actionDefinition(EntityTypeDefinition $definition, string $handle): ActionDefinition
    {
        foreach ($definition->actions() as $action) {
            if ($action->handle === $handle) {
                return $action;
            }
        }
        throw new BusinessRecordActionRejected('The action is not declared by the pinned definition.');
    }

    /**
     * Flatten a record's values into the scalar map an action condition is evaluated against.
     *
     * Exact decimals become their canonical base-10 literal and timestamps an ISO-8601 string with
     * microseconds and offset, so a condition compares text and never a float. Everything else that is not
     * already a scalar — money, quantities, arrays, embedded values, encrypted envelopes — is presented as
     * null, which means a precondition can test such a field for absence but can never branch on its
     * content.
     *
     * @param   BusinessRecord  $record  Record the condition is being evaluated against.
     *
     * @return  array<string, scalar|null>  Every field handle the record carries, mapped to a scalar or to
     *          null where the value has no scalar spelling.
     *
     * @since   2.0.0
     */
    private function expressionValues(BusinessRecord $record): array
    {
        $values = [];
        foreach ($record->values() as $handle => $value) {
            $values[$handle] = match (true) {
                $value instanceof ExactDecimal => $value->value(),
                $value instanceof DateTimeImmutable => $value->format('Y-m-d\TH:i:s.uP'),
                is_scalar($value), $value === null => $value,
                default => null,
            };
        }

        return $values;
    }
}
