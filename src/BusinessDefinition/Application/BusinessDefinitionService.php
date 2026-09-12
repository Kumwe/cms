<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Application;

use DateTimeImmutable;
use JsonException;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\BusinessDefinition\Domain\CompatibilityPlan;
use Kumwe\App\BusinessDefinition\Domain\DefinitionOwnerType;
use Kumwe\App\BusinessDefinition\Domain\DefinitionStatus;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\App\BusinessSchema\Application\PublishedDefinitionSchemaObserver;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * The single path by which a business definition is drafted, validated, priced, published and retired.
 *
 * Every surface that touches definitions — the REST handler, the administrator screen, the console command
 * and the MCP tools — goes through this service rather than the repository, which is what keeps them at
 * parity: the same capability check, the same graph validation, the same confirmation gate and the same
 * audit trail apply whichever door a request arrives by. Reads resolve a caller-supplied handle or UUID to
 * a catalog head first and authorize against that head's identity, so a permission is never decided on the
 * string that was sent. Writes are narrower still: an administrator may touch only a version-zero draft the
 * acting site owns, a draft is validated together with the definitions it can reach rather than on its own,
 * and publication is refused when the compatibility plan requires a confirmation the caller did not give.
 *
 * Each mutation runs its repository write, its audit entry and — on a handle's first save — its ownership
 * row inside one transaction, so a refused or failed operation leaves nothing behind. Failures are audited
 * as rejections before they propagate, and the original failure always wins: an audit sink that cannot
 * store a rejection is swallowed rather than allowed to mask what actually went wrong.
 *
 * @since  2.0.0
 */
final readonly class BusinessDefinitionService
{
    /**
     * Wire the service to the collaborators every definition operation composes.
     *
     * @param  BusinessDefinitionRepository             $repository         Store holding catalog heads, drafts
     *         and published versions for every site.
     * @param  BusinessDefinitionValidator              $validator          Checks a definition set as one closed
     *         graph before it is saved or published.
     * @param  BusinessDefinitionCompatibilityAnalyzer  $compatibility      Prices what publishing a draft would
     *         do to the version already in service.
     * @param  BusinessDefinitionContractAdmission      $contractAdmission  Rejects derived public-contract
     *         collisions before publication commits.
     * @param  AuthorizationGateway                     $authorization      Decides every capability question
     *         this service asks.
     * @param  ResourceSiteOwnershipWriter              $ownership          Records the owning site the first
     *         time a definition is saved.
     * @param  AuditRecorder                            $audit              Sink for the success and rejection
     *         entries every operation writes.
     * @param  TransactionManager                       $transactions       Scope a mutation shares with its
     *         audit entry and its ownership row.
     * @param  ClockInterface                           $clock              Supplies the instant stamped on
     *         writes and on the audit entries describing them.
     * @param  ?PublishedDefinitionSchemaObserver       $schemaObserver     Told about a published graph so
     *         schema plans exist for it; null where the installation runs no schema services.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessDefinitionRepository $repository,
        private BusinessDefinitionValidator $validator,
        private BusinessDefinitionCompatibilityAnalyzer $compatibility,
        private BusinessDefinitionContractAdmission $contractAdmission,
        private AuthorizationGateway $authorization,
        private ResourceSiteOwnershipWriter $ownership,
        private AuditRecorder $audit,
        private TransactionManager $transactions,
        private ClockInterface $clock,
        private ?PublishedDefinitionSchemaObserver $schemaObserver = null,
    ) {
    }

    /**
     * List every definition the acting site holds, whoever owns it.
     *
     * The check is made against the collection rather than any one definition, so an actor cleared to read
     * the catalog sees core-, extension- and site-owned entries alike, and sees them without their bytes.
     *
     * @param   ExecutionContext  $context  Actor and site the listing is made for.
     *
     * @return  list<DefinitionCatalogEntry>  Where each handle stands — draft revision, published version
     *          and publication state; empty when the site holds no definitions.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not read
     *          business definitions in this site.
     *
     * @since   2.0.0
     */
    public function catalog(ExecutionContext $context): array
    {
        $this->authorize($context, 'content.read', AuthorizationResource::collection('business_definition'));

        return $this->repository->catalog($context->site());
    }

    /**
     * Load a definition's work in progress, resolved from either its handle or its UUID.
     *
     * A definition that exists but carries no draft is reported as not found, because publication consumes
     * the draft and leaves the handle with nothing in progress until it is edited again.
     *
     * @param   ExecutionContext  $context     Actor and site the read is made for.
     * @param   string            $identifier  The definition's handle or its UUID.
     *
     * @return  DefinitionDraft  The stored draft, with the revision the next write to this handle has to
     *          quote.
     *
     * @throws  BusinessDefinitionNotFound  When this site holds no such definition, or its draft was
     *          consumed by a publication.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not read this
     *          definition.
     *
     * @since   2.0.0
     */
    public function draft(ExecutionContext $context, string $identifier): DefinitionDraft
    {
        $entry = $this->entry($context, $identifier, 'content.read');

        return $this->repository->draft($context->site(), $entry->id)
            ?? throw new BusinessDefinitionNotFound($identifier);
    }

    /**
     * Load one published version, defaulting to whichever version the catalog head currently serves.
     *
     * @param   ExecutionContext  $context     Actor and site the read is made for.
     * @param   string            $identifier  The definition's handle or its UUID.
     * @param   ?int              $version     Version to load, or null for the one the head serves.
     *
     * @return  DefinitionVersionRecord  The published bytes paired with the plan that produced them.
     *
     * @throws  BusinessDefinitionNotFound  When this site holds no such definition, or it never published
     *          that version.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not read this
     *          definition.
     *
     * @since   2.0.0
     */
    public function published(
        ExecutionContext $context,
        string $identifier,
        ?int $version = null,
    ): DefinitionVersionRecord {
        $entry = $this->entry($context, $identifier, 'content.read');

        return $this->repository->published($context->site(), $entry->id, $version)
            ?? throw new BusinessDefinitionNotFound($identifier, $version);
    }

    /**
     * List every version of one definition that was ever published.
     *
     * @param   ExecutionContext  $context     Actor and site the read is made for.
     * @param   string            $identifier  The definition's handle or its UUID.
     *
     * @return  list<DefinitionVersionRecord>  Newest version first; empty when the definition exists but
     *          has never been published.
     *
     * @throws  BusinessDefinitionNotFound  When this site holds no such definition.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not read this
     *          definition.
     *
     * @since   2.0.0
     */
    public function history(ExecutionContext $context, string $identifier): array
    {
        $entry = $this->entry($context, $identifier, 'content.read');

        return $this->repository->history($context->site(), $entry->id);
    }

    /**
     * Take a canonical definition document in as a draft, forcing it back to version zero.
     *
     * An exported document normally carries the status and version it was published at, so rather than
     * refuse it the status and `definition_version` keys are overwritten and the document parsed again: an
     * import always lands as a version-zero draft on the handle it names, never as a published version.
     * A document that cannot be parsed at all is audited under `business_definition.import.reject`, against
     * whatever `id` it claimed, before the parse failure propagates. Everything past parsing — the owner
     * and site rules, the capability check, graph validation and the optimistic write — is the path
     * `saveDraft()` uses, so an imported draft is held to exactly the same standard as an authored one.
     *
     * @param   ExecutionContext      $context           Actor and site the import runs for.
     * @param   array<string, mixed>  $document          Canonical document, as `toArray()` writes it.
     * @param   ?int                  $expectedRevision  Draft revision the import was composed against, or
     *          null when the caller expects to be creating the definition.
     *
     * @return  DefinitionDraft  The stored draft at its new revision.
     *
     * @throws  InvalidBusinessDefinition  When the document cannot be parsed into a definition, names a
     *          handle the acting site does not own, or fails graph validation.
     * @throws  BusinessDefinitionRevisionConflict  When the stored draft is not at the expected revision.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not update
     *          this definition.
     *
     * @since   2.0.0
     */
    public function importDraft(
        ExecutionContext $context,
        array $document,
        ?int $expectedRevision = null,
    ): DefinitionDraft {
        try {
            $definition = EntityTypeDefinition::fromArray($document);
            if ($definition->status !== DefinitionStatus::Draft || $definition->definitionVersion !== 0) {
                $document['status'] = DefinitionStatus::Draft->value;
                $document['definition_version'] = 0;
                $definition = EntityTypeDefinition::fromArray($document);
            }
        } catch (Throwable $failure) {
            $this->auditFailure($context, 'business_definition.import.reject', $document['id'] ?? null, $failure);
            throw $failure;
        }

        return $this->persistDraft(
            $context,
            $definition,
            $expectedRevision,
            'business_definition.import',
        );
    }

    /**
     * Decode a JSON definition document and import it as a draft.
     *
     * The update capability is asserted against the collection before the payload is decoded, so an
     * unauthorized caller never reaches the parser; `importDraft()` then checks it again, against the
     * definition itself once the handle turns out to exist. Malformed JSON and a payload that decodes to
     * anything other than a JSON object — a top-level array included — are both converted into
     * `InvalidBusinessDefinition` and audited as `business_definition.import.reject`, so a delivery
     * surface has one rejection to render whatever was wrong with the upload.
     *
     * @param   ExecutionContext  $context           Actor and site the import runs for.
     * @param   string            $json              Canonical document as JSON; depth is capped at 64.
     * @param   ?int              $expectedRevision  Draft revision the import was composed against, or
     *          null when the caller expects to be creating the definition.
     *
     * @return  DefinitionDraft  The stored draft at its new revision.
     *
     * @throws  InvalidBusinessDefinition  When the payload is not valid JSON, is not a JSON object, or
     *          fails any of the checks `importDraft()` applies.
     * @throws  BusinessDefinitionRevisionConflict  When the stored draft is not at the expected revision.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not update
     *          business definitions.
     *
     * @since   2.0.0
     */
    public function importJson(
        ExecutionContext $context,
        string $json,
        ?int $expectedRevision = null,
    ): DefinitionDraft {
        $this->authorize($context, 'content.update', AuthorizationResource::collection('business_definition'));
        try {
            $document = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $failure = new InvalidBusinessDefinition(
                'The imported business definition is invalid JSON.',
                0,
                $exception,
            );
            $this->auditFailure($context, 'business_definition.import.reject', null, $failure);
            throw $failure;
        }
        if (!is_array($document) || array_is_list($document)) {
            $failure = new InvalidBusinessDefinition('The imported business definition must be a JSON object.');
            $this->auditFailure($context, 'business_definition.import.reject', null, $failure);
            throw $failure;
        }

        /** @var array<string, mixed> $document */
        return $this->importDraft($context, $document, $expectedRevision);
    }

    /**
     * Store an administrator-authored definition as its handle's draft.
     *
     * Accepts only a version-zero draft owned by the site the actor is working in, so this path can neither
     * edit a core- or extension-owned definition nor write a published version. Creating a handle and
     * updating one are the same call: the expected revision is what tells them apart.
     *
     * @param   ExecutionContext      $context           Actor and site the save runs for.
     * @param   EntityTypeDefinition  $definition        Version-zero draft to store, carrying its own site,
     *          handle and owner.
     * @param   ?int                  $expectedRevision  Draft revision the change was composed against, or
     *          null when the caller expects to be creating the definition.
     *
     * @return  DefinitionDraft  The stored draft at its new revision, which the next write has to quote.
     *
     * @throws  InvalidBusinessDefinition  When the definition is not a version-zero draft, is not owned by
     *          the acting site, or fails graph validation.
     * @throws  BusinessDefinitionRevisionConflict  When the stored draft is not at the expected revision.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not update
     *          this definition.
     *
     * @since   2.0.0
     */
    public function saveDraft(
        ExecutionContext $context,
        EntityTypeDefinition $definition,
        ?int $expectedRevision = null,
    ): DefinitionDraft {
        return $this->persistDraft(
            $context,
            $definition,
            $expectedRevision,
            'business_definition.draft',
        );
    }

    /**
     * Run the draft-write path that `saveDraft()` and `importDraft()` share.
     *
     * The order is deliberate. The version-zero and site-ownership rules are settled before anything is
     * read, so a definition belonging elsewhere is refused without a lookup; the catalog head then decides
     * whether the update capability is asserted against the collection, because the handle is new, or
     * against the definition that already exists; and only then is the graph validated. The repository
     * write, the ownership row for a first save and the success audit entry share one transaction, so a
     * definition never becomes reachable without the ownership row the gateway needs to authorize it.
     * Anything that fails is audited under `$auditAction` with a `.reject` suffix and then propagates
     * unchanged.
     *
     * @param   ExecutionContext      $context           Actor and site the write runs for.
     * @param   EntityTypeDefinition  $definition        Version-zero draft to store.
     * @param   ?int                  $expectedRevision  Draft revision the change was composed against, or
     *          null when the caller expects to be creating the definition.
     * @param   string                $auditAction       Action recorded on success; its `.reject` variant
     *          names the rejection.
     *
     * @return  DefinitionDraft  The stored draft at its new revision.
     *
     * @throws  InvalidBusinessDefinition  When the definition is not a version-zero draft, is not owned by
     *          the acting site, or fails graph validation.
     * @throws  BusinessDefinitionRevisionConflict  When the stored draft is not at the expected revision.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not update
     *          this definition.
     *
     * @since   2.0.0
     */
    private function persistDraft(
        ExecutionContext $context,
        EntityTypeDefinition $definition,
        ?int $expectedRevision,
        string $auditAction,
    ): DefinitionDraft {
        try {
            if ($definition->status !== DefinitionStatus::Draft || $definition->definitionVersion !== 0) {
                throw new InvalidBusinessDefinition(
                    'Administrator-authored definitions must be saved as version-zero drafts.',
                );
            }
            if (
                $definition->siteIdentifier !== $context->site()->identifier()
                || $definition->owner->type !== DefinitionOwnerType::Site
                || $definition->owner->identifier !== $context->site()->identifier()
            ) {
                throw new InvalidBusinessDefinition(
                    'An administrator may edit only definitions owned by the current site.',
                );
            }
            $existing = $this->repository->entry($context->site(), $definition->handle);
            $resource = $existing === null
                ? AuthorizationResource::collection('business_definition')
                : AuthorizationResource::item('business_definition', $existing->id);
            $this->authorize($context, 'content.update', $resource);
            $this->validate($definition);
            $now = $this->clock->now();

            return $this->transactions->transactional(function () use (
                $definition,
                $context,
                $now,
                $expectedRevision,
                $existing,
                $auditAction,
            ): DefinitionDraft {
                $draft = $this->repository->saveDraft(
                    $definition,
                    $context->actorId(),
                    $now,
                    $expectedRevision,
                );
                if ($existing === null) {
                    $this->ownership->record(
                        AuthorizationResource::item('business_definition', $definition->id),
                        $context->site(),
                    );
                }
                $this->record(
                    $context,
                    $auditAction,
                    $definition->id,
                    $now,
                    ['revision' => $draft->revision, 'checksum' => $draft->checksum],
                );

                return $draft;
            });
        } catch (Throwable $failure) {
            $this->auditFailure($context, $auditAction . '.reject', $definition->id, $failure);
            throw $failure;
        }
    }

    /**
     * Re-check a stored draft against the definitions it depends on, and record that the check was made.
     *
     * Validation is offered as its own audited step because an author needs to know a draft is sound before
     * deciding whether to publish it. No definition bytes move, but the update capability is still required,
     * because the audit entry this leaves attributes the check to an actor entitled to change the definition.
     *
     * @param   ExecutionContext  $context     Actor and site the check runs for.
     * @param   string            $identifier  The definition's handle or its UUID.
     *
     * @return  DefinitionDraft  The draft that was checked, exactly as it was stored.
     *
     * @throws  BusinessDefinitionNotFound  When this site holds no such definition, or it has no draft.
     * @throws  InvalidBusinessDefinition  When the draft, or a definition it reaches, breaks a rule.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not read or
     *          update this definition.
     *
     * @since   2.0.0
     */
    public function validateDraft(ExecutionContext $context, string $identifier): DefinitionDraft
    {
        $draft = $this->draft($context, $identifier);
        $this->authorize(
            $context,
            'content.update',
            AuthorizationResource::item('business_definition', $draft->definition->id),
        );
        try {
            $this->validate($draft->definition);
            $this->record(
                $context,
                'business_definition.validate',
                $draft->definition->id,
                $this->clock->now(),
                ['revision' => $draft->revision, 'checksum' => $draft->checksum],
            );
            return $draft;
        } catch (Throwable $failure) {
            $this->auditFailure($context, 'business_definition.validate.reject', $draft->definition->id, $failure);
            throw $failure;
        }
    }

    /**
     * Validate a draft and price what publishing it would do to the version in service.
     *
     * The audited counterpart of `previewDraft()`: the validation and the resulting plan are both recorded,
     * the plan under `business_definition.compare`, so the assessment an operator was shown before
     * publishing can be tied back to a moment and an actor. The plan is not kept beside the definition —
     * only publication stores one — so a comparison stays a question rather than a commitment.
     *
     * @param   ExecutionContext  $context     Actor and site the comparison runs for.
     * @param   string            $identifier  The definition's handle or its UUID.
     *
     * @return  CompatibilityPlan  Every classified difference between the published head and the draft; a
     *          first publication reports the creation itself.
     *
     * @throws  BusinessDefinitionNotFound  When this site holds no such definition, or it has no draft.
     * @throws  InvalidBusinessDefinition  When the draft, or a definition it reaches, breaks a rule.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not read or
     *          update this definition.
     *
     * @since   2.0.0
     */
    public function compareDraft(ExecutionContext $context, string $identifier): CompatibilityPlan
    {
        $draft = $this->validateDraft($context, $identifier);
        $before = $this->repository->published($context->site(), $draft->definition->id)?->definition;
        $plan = $this->compatibility->analyze($before, $draft->definition);
        $this->record(
            $context,
            'business_definition.compare',
            $draft->definition->id,
            $this->clock->now(),
            ['revision' => $draft->revision, 'plan' => $plan->toArray()],
        );

        return $plan;
    }

    /**
     * Price what publishing a draft would do, without recording that the question was asked.
     *
     * The read-only counterpart of `compareDraft()`: it asks only for read access and writes no audit
     * entry, so an editing screen or an MCP tool may recompute the plan as often as it likes while the
     * explicit compare operation remains the one that leaves a trail.
     *
     * @param   ExecutionContext  $context     Actor and site the preview runs for.
     * @param   string            $identifier  The definition's handle or its UUID.
     *
     * @return  CompatibilityPlan  Every classified difference between the published head and the draft; a
     *          first publication reports the creation itself.
     *
     * @throws  BusinessDefinitionNotFound  When this site holds no such definition, or it has no draft.
     * @throws  InvalidBusinessDefinition  When the draft, or a definition it reaches, breaks a rule.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not read this
     *          definition.
     *
     * @since   2.0.0
     */
    public function previewDraft(ExecutionContext $context, string $identifier): CompatibilityPlan
    {
        $draft = $this->draft($context, $identifier);
        $this->validate($draft->definition);
        $before = $this->repository->published($context->site(), $draft->definition->id)?->definition;
        return $this->compatibility->analyze($before, $draft->definition);
    }

    /**
     * Publish the stored draft as the handle's next version, once its plan has been accepted.
     *
     * The draft is validated, the plan analysed against the version currently in service, and a plan that
     * requires confirmation is refused unless `$confirmed` says the caller has accepted it — which is what
     * keeps a behaviour- or data-changing publication from happening by accident. The repository write, the
     * audit entry and the schema-observer notification all run inside one transaction, so the version, the
     * trail that accounts for it and the schema plans proposed from it either all land or none do. The
     * observer is told only when every entity the new version reaches is itself published; an incomplete
     * graph is skipped rather than planned from part of itself, and the publication still stands.
     *
     * @param   ExecutionContext  $context                Actor and site the publication runs for.
     * @param   string            $identifier             The definition's handle or its UUID.
     * @param   int               $expectedDraftRevision  Draft revision being published, as the caller
     *          last read it.
     * @param   bool              $confirmed              Whether the caller has accepted a plan that
     *          changes behaviour or data.
     *
     * @return  DefinitionVersionRecord  The stored version, paired with the plan that produced it.
     *
     * @throws  BusinessDefinitionNotFound  When this site holds no such definition, or it has no draft.
     * @throws  InvalidBusinessDefinition  When the draft fails validation, the plan requires a confirmation
     *          that was not given, or the published graph exceeds 128 entities.
     * @throws  BusinessDefinitionRevisionConflict  When the stored draft is no longer at the expected
     *          revision, so it moved after the plan was analysed.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not update
     *          this definition.
     *
     * @since   2.0.0
     */
    public function publish(
        ExecutionContext $context,
        string $identifier,
        int $expectedDraftRevision,
        bool $confirmed = false,
    ): DefinitionVersionRecord {
        $draft = $this->draft($context, $identifier);
        $this->authorize(
            $context,
            'content.update',
            AuthorizationResource::item('business_definition', $draft->definition->id),
        );
        try {
            $this->validate($draft->definition);
            $before = $this->repository->published($context->site(), $draft->definition->id)?->definition;
            $plan = $this->compatibility->analyze($before, $draft->definition);
            if ($plan->requiresConfirmation() && !$confirmed) {
                throw new InvalidBusinessDefinition(
                    'This compatibility plan changes behavior or data and requires explicit confirmation.',
                );
            }
            $published = $draft->definition->published($plan->toVersion);
            $now = $this->clock->now();

            return $this->transactions->transactional(function () use (
                $published,
                $plan,
                $context,
                $now,
                $expectedDraftRevision,
            ): DefinitionVersionRecord {
                $this->repository->lockContractNamespace($context->site());
                $this->contractAdmission->admit(
                    $context->site(),
                    $this->postPublicationContract($context->site(), $published),
                );
                $record = $this->repository->publish(
                    $published,
                    $plan,
                    $context->actorId(),
                    $now,
                    $expectedDraftRevision,
                );
                $this->record(
                    $context,
                    'business_definition.publish',
                    $published->id,
                    $now,
                    [
                        'version' => $published->definitionVersion,
                        'checksum' => $published->checksum(),
                        'plan' => $plan->toArray(),
                    ],
                );
                $graph = $this->publishedGraph($context->site(), $record);
                if ($graph !== null) {
                    $this->schemaObserver?->observePublishedGraph(
                        $context->site(),
                        $graph,
                        $context->actorId(),
                        $now,
                    );
                }
                return $record;
            });
        } catch (Throwable $failure) {
            $this->auditFailure($context, 'business_definition.publish.reject', $draft->definition->id, $failure);
            throw $failure;
        }
    }

    /**
     * Assemble the complete active post-publication definition set for contract-name admission.
     *
     * The candidate replaces its own current head. Core, site, and active-extension heads remain in the
     * set unless rejected; inactive extension definitions cannot appear in the runtime contract and are
     * admitted again against this site when their package is activated. Exact versions are batch-loaded so
     * publication adds a constant number of catalog reads instead of one query per definition.
     *
     * @param   SiteContext           $site       Site whose current catalog is being evaluated.
     * @param   EntityTypeDefinition  $candidate  Definition about to become the published head.
     *
     * @return  list<EntityTypeDefinition>  Canonically ordered post-publication definitions.
     *
     * @throws  \RuntimeException  When a supposedly published catalog head has no matching immutable version.
     *
     * @since   2.0.0
     */
    private function postPublicationContract(
        SiteContext $site,
        EntityTypeDefinition $candidate,
    ): array {
        $requested = [];
        foreach ($this->repository->catalog($site) as $entry) {
            if (
                $entry->id === $candidate->id
                || !$entry->ownerActive
                || $entry->publishedVersion === null
                || $entry->status === DefinitionStatus::Rejected
            ) {
                continue;
            }
            $requested[$entry->id] = $entry->publishedVersion;
        }
        $versions = $this->repository->publishedBatch($site, $requested);
        $definitions = [$candidate->handle => $candidate];
        foreach ($requested as $identifier => $version) {
            $record = $versions[$identifier] ?? null;
            if ($record === null || $record->definition->definitionVersion !== $version) {
                throw new \RuntimeException('A published definition contract version is unavailable.');
            }
            $definitions[$record->definition->handle] = $record->definition;
        }
        ksort($definitions, SORT_STRING);

        return array_values($definitions);
    }

    /**
     * Close a freshly published version over the published versions it depends on.
     *
     * Definitions published together reference one another, and the foreign keys between them cannot be
     * planned from any one of them alone, so the whole reachable set is collected before the schema
     * observer is told anything. The walk follows entity dependencies breadth-first and gives up entirely
     * — returning null rather than a partial set — as soon as one target has no published version, which
     * is how a definition that still points at unpublished work is published without producing a plan.
     *
     * @param   SiteContext              $site  Site whose published versions the walk resolves against.
     * @param   DefinitionVersionRecord  $root  Version just published, and the start of the walk.
     *
     * @return  list<DefinitionVersionRecord>|null  Every reachable version including the root, ordered by
     *          handle so the same graph always arrives the same way; null when a dependency is unpublished.
     *
     * @throws  InvalidBusinessDefinition  When the reachable graph exceeds 128 entities.
     *
     * @since   2.0.0
     */
    private function publishedGraph(SiteContext $site, DefinitionVersionRecord $root): ?array
    {
        $graph = [$root->definition->handle => $root];
        $queue = [$root];
        for ($index = 0; $index < count($queue); ++$index) {
            foreach ($queue[$index]->definition->dependencyGraph()['entities'] as $target) {
                if (isset($graph[$target])) {
                    continue;
                }
                $related = $this->repository->published($site, $target);
                if ($related === null) {
                    return null;
                }
                $graph[$related->definition->handle] = $related;
                $queue[] = $related;
                if (count($queue) > 128) {
                    throw new InvalidBusinessDefinition('A business definition graph exceeds 128 entities.');
                }
            }
        }
        ksort($graph, SORT_STRING);

        return array_values($graph);
    }

    /**
     * Mark a published version as displaced by a newer one, keeping it intact for history.
     *
     * Publishing a successor already supersedes the version before it, so this is the manual route for a
     * version an operator wants to stand down without publishing anything.
     *
     * @param   ExecutionContext  $context     Actor and site the change runs for.
     * @param   string            $identifier  The definition's handle or its UUID.
     * @param   int               $version     Published version to stand down.
     *
     * @return  DefinitionVersionRecord  The version reloaded in its new state.
     *
     * @throws  BusinessDefinitionNotFound  When this site holds no such definition.
     * @throws  InvalidBusinessDefinition  When the definition is owned by core or an extension, whose
     *          version status follows the package lifecycle instead.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not update
     *          this definition.
     *
     * @since   2.0.0
     */
    public function supersede(ExecutionContext $context, string $identifier, int $version): DefinitionVersionRecord
    {
        return $this->changeStatus($context, $identifier, $version, DefinitionStatus::Superseded);
    }

    /**
     * Mark a published version as still serviceable but no longer something to build on.
     *
     * Records already built on the version keep working and the runtime keeps serving it; deprecation is
     * advice to authors rather than a withdrawal. Use `reject()` when the version must stop being served.
     *
     * @param   ExecutionContext  $context     Actor and site the change runs for.
     * @param   string            $identifier  The definition's handle or its UUID.
     * @param   int               $version     Published version to discourage.
     *
     * @return  DefinitionVersionRecord  The version reloaded in its new state.
     *
     * @throws  BusinessDefinitionNotFound  When this site holds no such definition.
     * @throws  InvalidBusinessDefinition  When the definition is owned by core or an extension, whose
     *          version status follows the package lifecycle instead.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not update
     *          this definition.
     *
     * @since   2.0.0
     */
    public function deprecate(ExecutionContext $context, string $identifier, int $version): DefinitionVersionRecord
    {
        return $this->changeStatus($context, $identifier, $version, DefinitionStatus::Deprecated);
    }

    /**
     * Withdraw a published version so the record runtime refuses to serve it.
     *
     * This is the status change with teeth: the version stays on record for history and restore, but
     * definition resolution treats it as a schema that has gone away and fails rather than reading records
     * against it. Reach for it when a contract must stop being honoured, not merely discouraged.
     *
     * @param   ExecutionContext  $context     Actor and site the change runs for.
     * @param   string            $identifier  The definition's handle or its UUID.
     * @param   int               $version     Published version to withdraw.
     *
     * @return  DefinitionVersionRecord  The version reloaded in its new state.
     *
     * @throws  BusinessDefinitionNotFound  When this site holds no such definition.
     * @throws  InvalidBusinessDefinition  When the definition is owned by core or an extension, whose
     *          version status follows the package lifecycle instead.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not update
     *          this definition.
     *
     * @since   2.0.0
     */
    public function reject(ExecutionContext $context, string $identifier, int $version): DefinitionVersionRecord
    {
        return $this->changeStatus($context, $identifier, $version, DefinitionStatus::Rejected);
    }

    /**
     * Check a definition together with everything it can reach, as one closed graph.
     *
     * A definition is never sound on its own: relationship targets, declared inverses and delete behaviour
     * can only be judged against the entities they name, so the reachable set is assembled first and handed
     * to the validator in one piece. Targets resolve against the definition's own site, and a draft is
     * preferred over the published version of the same handle, which is what lets an author see the
     * consequences of work in progress rather than of what is already in service. A target that resolves to
     * nothing is left out instead of raising here, so the validator is the one that names the dangling
     * reference.
     *
     * @param   EntityTypeDefinition  $definition  Definition at the centre of the graph, normally the draft
     *          being saved or published.
     *
     * @return  void
     *
     * @throws  InvalidBusinessDefinition  When the reachable graph exceeds 128 entities, or the validator
     *          refuses the assembled set.
     *
     * @since   2.0.0
     */
    private function validate(EntityTypeDefinition $definition): void
    {
        $graph = [$definition->handle => $definition];
        $queue = [$definition];
        $site = SiteContext::fromString($definition->siteIdentifier);
        for ($index = 0; $index < count($queue); ++$index) {
            foreach ($queue[$index]->dependencyGraph()['entities'] as $target) {
                if (isset($graph[$target])) {
                    continue;
                }
                $related = $this->repository->draft($site, $target)->definition
                    ?? $this->repository->published($site, $target)?->definition;
                if ($related === null) {
                    continue;
                }
                $graph[$related->handle] = $related;
                $queue[] = $related;
                if (count($queue) > 128) {
                    throw new InvalidBusinessDefinition('A business definition graph exceeds 128 entities.');
                }
            }
        }
        $this->validator->validateGraph(array_values($graph));
    }

    /**
     * Move one published version to a later lifecycle state, on behalf of the three public verbs.
     *
     * Only site-owned definitions may be moved this way; what an extension published follows its package's
     * lifecycle instead, so an operator cannot retire it by hand. The status write and its audit entry
     * share one transaction, and the audit action is built from the target state, so superseding,
     * deprecating and rejecting are told apart in the trail. A failure is audited against the identifier
     * the caller supplied rather than a resolved UUID, because the lookup itself may be what failed.
     *
     * @param   ExecutionContext  $context     Actor and site the change runs for.
     * @param   string            $identifier  The definition's handle or its UUID.
     * @param   int               $version     Published version whose lifecycle state is changing.
     * @param   DefinitionStatus  $status      State to move it to; also names the audit action.
     *
     * @return  DefinitionVersionRecord  The version reloaded in its new state.
     *
     * @throws  BusinessDefinitionNotFound  When this site holds no such definition.
     * @throws  InvalidBusinessDefinition  When the definition is owned by core or an extension.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not update
     *          this definition.
     *
     * @since   2.0.0
     */
    private function changeStatus(
        ExecutionContext $context,
        string $identifier,
        int $version,
        DefinitionStatus $status,
    ): DefinitionVersionRecord {
        try {
            $entry = $this->entry($context, $identifier, 'content.update');
            if ($entry->owner->type !== DefinitionOwnerType::Site) {
                throw new InvalidBusinessDefinition('Package-owned definition status follows the extension lifecycle.');
            }
            $now = $this->clock->now();

            return $this->transactions->transactional(function () use (
                $context,
                $entry,
                $version,
                $status,
                $now,
            ): DefinitionVersionRecord {
                $record = $this->repository->changeStatus(
                    $context->site(),
                    $entry->id,
                    $version,
                    $status,
                    $now,
                );
                $this->record(
                    $context,
                    'business_definition.' . $status->value,
                    $entry->id,
                    $now,
                    ['version' => $version],
                );
                return $record;
            });
        } catch (Throwable $failure) {
            $this->auditFailure(
                $context,
                'business_definition.' . $status->value . '.reject',
                $identifier,
                $failure,
            );
            throw $failure;
        }
    }

    /**
     * Resolve a caller-supplied handle or UUID to a catalog head, and authorize against that head.
     *
     * Every operation on a named definition starts here, which is what stops a permission from ever being
     * decided on the string a client sent: the capability is asserted against the definition's own identity
     * once it is known to exist in this site. Because the lookup is site-scoped, a definition another site
     * owns is reported as missing rather than refused, so the catalog does not leak what other sites hold.
     *
     * @param   ExecutionContext  $context     Actor and site the lookup runs for.
     * @param   string            $identifier  The definition's handle or its UUID.
     * @param   string            $capability  Capability code to assert against the resolved definition.
     *
     * @return  DefinitionCatalogEntry  Where the handle stands, with the actor already cleared for the
     *          capability.
     *
     * @throws  BusinessDefinitionNotFound  When this site holds no such definition.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not exercise
     *          the capability on it.
     *
     * @since   2.0.0
     */
    private function entry(ExecutionContext $context, string $identifier, string $capability): DefinitionCatalogEntry
    {
        $entry = $this->repository->entry($context->site(), $identifier)
            ?? throw new BusinessDefinitionNotFound($identifier);
        $this->authorize(
            $context,
            $capability,
            AuthorizationResource::item('business_definition', $entry->id),
        );

        return $entry;
    }

    /**
     * Assert one capability for the acting context, stopping the operation when policy refuses.
     *
     * @param   ExecutionContext       $context     Actor, site and provenance the action runs under.
     * @param   string                 $capability  Capability code such as `content.read`, parsed into a
     *          `Capability` here so the call sites stay literal.
     * @param   AuthorizationResource  $resource    Collection or item the action is aimed at.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses the actor
     *          this action on this resource.
     *
     * @since   2.0.0
     */
    private function authorize(ExecutionContext $context, string $capability, AuthorizationResource $resource): void
    {
        $this->authorization->assertAllowed($context, Capability::fromString($capability), $resource);
    }

    /**
     * Write the success audit entry for an operation that completed.
     *
     * Every mutation records from inside its own transaction, so an entry that cannot be stored takes the
     * change down with it rather than leaving a write the trail does not account for. The read-only
     * validate and compare operations record outside one, where there is nothing to undo.
     *
     * @param   ExecutionContext      $context   Actor and site credited with the operation.
     * @param   string                $action    Audit action, such as `business_definition.publish`.
     * @param   string                $id        Definition UUID the entry is filed against.
     * @param   DateTimeImmutable     $now       Instant recorded on the entry, the same one stamped on the
     *          write it describes.
     * @param   array<string, mixed>  $metadata  Detail kept beside the entry, such as the draft revision,
     *          the canonical checksum or the compatibility plan.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function record(
        ExecutionContext $context,
        string $action,
        string $id,
        DateTimeImmutable $now,
        array $metadata = [],
    ): void {
        $this->audit->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $now,
            $context->actorId(),
            $action,
            'business_definition',
            $id,
            'success',
            $metadata,
        ));
    }

    /**
     * Record that an operation was rejected, without ever displacing the failure that caused it.
     *
     * The reason kept is the failure's own message truncated to 500 characters, and an audit sink that
     * cannot store the entry is deliberately swallowed: by this point any transaction the operation opened
     * has already been discarded, so there is nothing left to protect, and the original failure is what the
     * caller has to see. Successful mutations get the opposite treatment — `record()` runs inside the
     * transaction and lets an audit failure take the change with it.
     *
     * @param   ExecutionContext  $context  Actor and site the rejected operation ran for.
     * @param   string            $action   Audit action, always an operation's `.reject` variant.
     * @param   mixed             $id       Whatever identity was known when the failure happened; filed
     *          without a subject unless it is a non-empty string.
     * @param   Throwable         $failure  Failure being recorded, whose message becomes the reason.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function auditFailure(
        ExecutionContext $context,
        string $action,
        mixed $id,
        Throwable $failure,
    ): void {
        try {
            $this->audit->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $this->clock->now(),
                $context->actorId(),
                $action,
                'business_definition',
                is_string($id) && $id !== '' ? $id : null,
                'rejected',
                ['reason' => substr($failure->getMessage(), 0, 500)],
            ));
        } catch (Throwable) {
            // The original failure remains authoritative. Successful mutations fail closed if auditing fails.
        }
    }
}
