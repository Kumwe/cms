<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Domain;

use Kumwe\Localization\Domain\LocaleTag;
use Ramsey\Uuid\Uuid;

/**
 * The complete, self-validating contract of one business entity: its identity, shape, behaviour, and reach.
 *
 * This is the aggregate the whole business stack agrees on. An author edits it as a draft, publication freezes
 * it at a positive version whose canonical bytes and SHA-256 identify it from then on, the physical schema
 * compiler derives real tables from it, and the record runtime decodes every row against the version it was
 * written under. Construction settles everything one entity can answer for itself — identifier and handle
 * shapes, ownership of the namespace it declares under, bounded labels, a version that agrees with the status,
 * bounded and duplicate-free collections, exactly one field matching the identity strategy, acyclic expression
 * dependencies, views and actions that reference only declared fields and transitions, and no view claiming a
 * surface the entity does not expose. What needs the rest of the catalog — a registered field type, a reachable
 * relationship target, an acyclic ownership graph — is `BusinessDefinitionValidator`'s job.
 *
 * Every state change is a new instance: `published()` and `withStatus()` rebuild through this constructor, so
 * no invariant can be escaped by transitioning around it.
 *
 * @since  2.0.0
 */
final readonly class EntityTypeDefinition
{
    /**
     * Field contract of the entity, in declaration order, with handles already proven unique.
     *
     * @var    list<FieldDefinition>
     * @since  2.0.0
     */
    private array $fields;

    /**
     * Associations to other entities that the definition declares explicitly.
     *
     * @var    list<RelationshipDefinition>
     * @since  2.0.0
     */
    private array $relationships;

    /**
     * Named projections the entity offers, each already checked against its fields and exposure surfaces.
     *
     * @var    list<ViewDefinition>
     * @since  2.0.0
     */
    private array $views;

    /**
     * Operations offered on records, each guarded by a capability and optionally by a workflow transition.
     *
     * @var    list<ActionDefinition>
     * @since  2.0.0
     */
    private array $actions;

    /**
     * Cross-field rules the record runtime evaluates on every write.
     *
     * @var    list<RecordInvariantDefinition>
     * @since  2.0.0
     */
    private array $recordInvariants;

    /**
     * Business-record operations explicitly enabled for the authenticated portal surface.
     *
     * @var    list<PortalOperation>
     * @since  2.0.0
     */
    private array $portalOperations;

    /**
     * Declared intent carried inside the checksummed payload for consumers that read it back out.
     *
     * `SchemaEvolutionHints::fromDefinition()` is its only interpreter today: keys unrelated to schema
     * evolution travel through untouched, while an evolution-looking key it does not recognise is refused.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    private array $compatibilityMetadata;

    /**
     * Translations of the entity's operator-facing labels, keyed by member then by locale tag.
     *
     * Empty for an entity declared in one language, which is what keeps such a definition's canonical
     * document — and the checksum of every published version carrying it — exactly as it was before the
     * locale dimension existed.
     *
     * @var    array<string, array<string, string>>
     * @since  2.0.0
     */
    private array $labelTranslations;

    /**
     * Assemble an entity definition and refuse one that contradicts itself.
     *
     * @param   string                           $id                     Canonical UUID identifying the
     *          definition across all of its versions.
     * @param   DefinitionOwner                  $owner                  Who declares it, and whose namespace
     *          the handle has to sit under.
     * @param   string                           $siteIdentifier         Site the definition belongs to.
     * @param   string                           $handle                 Namespaced, dot-separated entity
     *          handle, unique within the site.
     * @param   string                           $singularLabel          Operator-facing name for one record.
     * @param   string                           $pluralLabel            Operator-facing name for the
     *          collection.
     * @param   DefinitionStatus                 $status                 Lifecycle state; `Draft` is the only
     *          one that pairs with version zero.
     * @param   int                              $definitionVersion      Zero while a draft, positive once
     *          published.
     * @param   StorageMode                      $storageMode            How records are physically kept.
     * @param   IdentityStrategy                 $identityStrategy       Which identity field the entity must
     *          carry, and what the record key is.
     * @param   ScopeMode                        $scope                  Tenancy dimensions records are
     *          partitioned by.
     * @param   bool                             $auditEnabled           Audit policy for the entity's records;
     *          changing it is classified as behaviour-changing.
     * @param   bool                             $revisionsEnabled       Whether every record write also
     *          appends a revision row.
     * @param   list<FieldDefinition>            $fields                 At least one field and at most 256.
     * @param   list<RelationshipDefinition>     $relationships          At most 128 declared associations.
     * @param   list<ViewDefinition>             $views                  At most 64 projections.
     * @param   list<ActionDefinition>           $actions                At most 64 operations.
     * @param   ?WorkflowBinding                 $workflow               State machine records move through,
     *          or null when they have none.
     * @param   array<string, mixed>             $compatibilityMetadata  Declared intent; must be canonically
     *          encodable, since it travels into the checksum.
     * @param   bool                             $administratorExposure  Whether the administrator surface may
     *          serve the entity.
     * @param   bool                             $portalExposure         Whether the portal surface may; any
     *          portal view requires it.
     * @param   bool                             $publicExposure         Whether anonymous delivery may; any
     *          public view requires it.
     * @param   bool                             $softDeleteEnabled      Whether deletion marks a record rather
     *          than removing it.
     * @param   list<RecordInvariantDefinition>  $recordInvariants       Cross-field rules, handles unique.
     * @param   list<PortalOperation>            $portalOperations       Explicit portal operation allowlist;
     *          empty denies every generated business-record operation.
     * @param   array<string, mixed>             $labelTranslations      Translations of `singular_label` and
     *          `plural_label`, keyed by member then by locale tag; empty for an entity whose labels are
     *          declared in one language only.
     *
     * @throws  InvalidBusinessDefinition  When the id is not a UUID, the site, handle, or labels are malformed,
     *          the handle falls outside the owner's namespace, the version disagrees with the status, a
     *          collection is empty or past its ceiling, a handle is duplicated, no exposure surface is
     *          declared, a portal operation is invalid or enabled without portal exposure, a view claims a
     *          surface the entity does not expose, the metadata is not canonically encodable, a label
     *          translation names an untranslatable member, a malformed locale or text over its bound, or
     *          the internal graph is unsound.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $id,
        public DefinitionOwner $owner,
        public string $siteIdentifier,
        public string $handle,
        public string $singularLabel,
        public string $pluralLabel,
        public DefinitionStatus $status,
        public int $definitionVersion,
        public StorageMode $storageMode,
        public IdentityStrategy $identityStrategy,
        public ScopeMode $scope,
        public bool $auditEnabled,
        public bool $revisionsEnabled,
        array $fields,
        array $relationships = [],
        array $views = [],
        array $actions = [],
        public ?WorkflowBinding $workflow = null,
        array $compatibilityMetadata = [],
        public bool $administratorExposure = true,
        public bool $portalExposure = false,
        public bool $publicExposure = false,
        public bool $softDeleteEnabled = false,
        array $recordInvariants = [],
        array $portalOperations = [],
        array $labelTranslations = [],
    ) {
        if (!Uuid::isValid($id)) {
            throw new InvalidBusinessDefinition('A business entity definition ID must be a canonical UUID.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,190}$/D', $siteIdentifier) !== 1) {
            throw new InvalidBusinessDefinition('A business entity definition site is invalid.');
        }
        if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)+$/D', $handle) !== 1 || strlen($handle) > 191) {
            throw new InvalidBusinessDefinition('A business entity handle must be a stable namespaced identifier.');
        }
        $owner->assertOwns($handle);
        if (
            $singularLabel === '' || $pluralLabel === ''
            || max(strlen($singularLabel), strlen($pluralLabel)) > 120
        ) {
            throw new InvalidBusinessDefinition('A business entity requires bounded singular and plural labels.');
        }
        if ($definitionVersion < 0) {
            throw new InvalidBusinessDefinition('A business entity definition version cannot be negative.');
        }
        if ($status === DefinitionStatus::Draft && $definitionVersion !== 0) {
            throw new InvalidBusinessDefinition('A draft business definition must use definition version zero.');
        }
        if ($status !== DefinitionStatus::Draft && $definitionVersion < 1) {
            throw new InvalidBusinessDefinition('A published business definition requires a positive version.');
        }
        if (
            $fields === [] || count($fields) > 256 || count($relationships) > 128
            || count($views) > 64 || count($actions) > 64
        ) {
            throw new InvalidBusinessDefinition('A business entity definition collection is empty or unbounded.');
        }
        $this->fields = self::unique($fields, static fn (FieldDefinition $field): string => $field->handle, 'field');
        $this->relationships = self::unique(
            $relationships,
            static fn (RelationshipDefinition $relationship): string => $relationship->handle,
            'relationship',
        );
        $this->views = self::unique($views, static fn (ViewDefinition $view): string => $view->handle, 'view');
        $this->actions = self::unique(
            $actions,
            static fn (ActionDefinition $action): string => $action->handle,
            'action',
        );
        $this->recordInvariants = self::unique(
            $recordInvariants,
            static fn (RecordInvariantDefinition $invariant): string => $invariant->handle,
            'record invariant',
        );
        $this->portalOperations = self::normalizePortalOperations($portalOperations);
        if (!$administratorExposure && !$portalExposure && !$publicExposure) {
            throw new InvalidBusinessDefinition('A business entity requires at least one declared exposure surface.');
        }
        if (!$portalExposure && $this->portalOperations !== []) {
            throw new InvalidBusinessDefinition('Portal operations require entity-level portal exposure.');
        }
        if (
            !$portalExposure && array_filter(
                $this->views,
                static fn (ViewDefinition $view): bool => $view->portal,
            ) !== []
        ) {
            throw new InvalidBusinessDefinition('A portal view requires entity-level portal exposure.');
        }
        if (
            !$publicExposure && array_filter(
                $this->views,
                static fn (ViewDefinition $view): bool => $view->public,
            ) !== []
        ) {
            throw new InvalidBusinessDefinition('A public view requires entity-level public exposure.');
        }
        CanonicalDefinitionJson::encode($compatibilityMetadata);
        $this->compatibilityMetadata = $compatibilityMetadata;
        $this->labelTranslations = LocalizedDefinitionText::normalize($labelTranslations, [
            'singular_label' => 120,
            'plural_label' => 120,
        ]);
        $this->assertInternalGraph();
    }

    /**
     * Rebuild a definition from the canonical document `toArray()` writes.
     *
     * This is the single entry for every stored or declared payload — a version row's canonical payload, an
     * extension manifest's contribution, a draft assembled by the administrator form mapper — so each of them
     * is put through the full constructor rather than trusted. An unrecognised top-level key is refused rather
     * than dropped, so a document exported by a later version is never imported with part of its meaning
     * silently missing.
     *
     * @param   array<string, mixed>  $document  Canonical definition document, keyed as it is stored.
     *
     * @return  self  The definition, with every construction rule already applied.
     *
     * @throws  InvalidBusinessDefinition  When the document carries an unknown property, an owner that is not a
     *          strict object, a required property that is missing or of the wrong type, an enum-backed property
     *          naming no case, a member document that fails to parse, or an assembled definition that breaks a
     *          construction rule.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $document): self
    {
        $allowed = [
            'id', 'owner', 'site', 'handle', 'singular_label', 'plural_label', 'status', 'definition_version',
            'storage_mode', 'identity_strategy', 'scope', 'audit_enabled', 'revisions_enabled', 'fields',
            'relationships', 'views', 'actions', 'workflow', 'compatibility_metadata', 'administrator_exposure',
            'portal_exposure', 'public_exposure', 'soft_delete_enabled', 'record_invariants', 'portal_operations',
            'label_translations',
        ];
        if (array_diff(array_keys($document), $allowed) !== []) {
            throw new InvalidBusinessDefinition('A business entity definition contains an unknown property.');
        }
        $labelTranslations = $document['label_translations'] ?? [];
        if (!is_array($labelTranslations) || ($labelTranslations !== [] && array_is_list($labelTranslations))) {
            throw new InvalidBusinessDefinition('Business entity label translations must be an object.');
        }
        /** @var array<string, mixed> $labelTranslations */
        $owner = $document['owner'] ?? null;
        if (
            !is_array($owner) || array_is_list($owner)
            || array_diff(array_keys($owner), ['type', 'identifier']) !== []
        ) {
            throw new InvalidBusinessDefinition('A business entity definition owner must be a strict object.');
        }
        /** @var array<string, mixed> $owner */
        $ownerType = DefinitionOwnerType::tryFrom(self::string($owner, 'type'))
            ?? throw new InvalidBusinessDefinition('A business entity definition owner type is invalid.');
        $fields = array_map(
            static fn (array $field): FieldDefinition => FieldDefinition::fromArray($field),
            self::objects($document, 'fields'),
        );
        $relationships = array_map(
            static fn (array $relationship): RelationshipDefinition => RelationshipDefinition::fromArray($relationship),
            self::objects($document, 'relationships'),
        );
        $views = array_map(
            static fn (array $view): ViewDefinition => ViewDefinition::fromArray($view),
            self::objects($document, 'views'),
        );
        $actions = array_map(
            static fn (array $action): ActionDefinition => ActionDefinition::fromArray($action),
            self::objects($document, 'actions'),
        );
        $recordInvariants = array_map(
            static fn (array $invariant): RecordInvariantDefinition => RecordInvariantDefinition::fromArray($invariant),
            self::objects($document, 'record_invariants'),
        );
        $workflowDocument = $document['workflow'] ?? null;
        if ($workflowDocument !== null && (!is_array($workflowDocument) || array_is_list($workflowDocument))) {
            throw new InvalidBusinessDefinition('A business workflow binding must be an object or null.');
        }
        /** @var non-empty-array<string, mixed>|null $workflowDocument */
        $metadata = $document['compatibility_metadata'] ?? [];
        if (!is_array($metadata) || ($metadata !== [] && array_is_list($metadata))) {
            throw new InvalidBusinessDefinition('Business compatibility metadata must be an object.');
        }
        /** @var array<string, mixed> $metadata */

        return new self(
            self::string($document, 'id'),
            new DefinitionOwner($ownerType, self::string($owner, 'identifier')),
            self::string($document, 'site'),
            self::string($document, 'handle'),
            self::string($document, 'singular_label'),
            self::string($document, 'plural_label'),
            DefinitionStatus::tryFrom(self::string($document, 'status'))
                ?? throw new InvalidBusinessDefinition('A business definition status is invalid.'),
            self::integer($document, 'definition_version'),
            StorageMode::tryFrom(self::string($document, 'storage_mode'))
                ?? throw new InvalidBusinessDefinition('A business storage mode is invalid.'),
            IdentityStrategy::tryFrom(self::string($document, 'identity_strategy'))
                ?? throw new InvalidBusinessDefinition('A business identity strategy is invalid.'),
            ScopeMode::tryFrom(self::string($document, 'scope'))
                ?? throw new InvalidBusinessDefinition('A business scope mode is invalid.'),
            self::boolean($document, 'audit_enabled', true),
            self::boolean($document, 'revisions_enabled', true),
            $fields,
            $relationships,
            $views,
            $actions,
            is_array($workflowDocument) ? WorkflowBinding::fromArray($workflowDocument) : null,
            $metadata,
            self::boolean($document, 'administrator_exposure', true),
            self::boolean($document, 'portal_exposure'),
            self::boolean($document, 'public_exposure'),
            self::boolean($document, 'soft_delete_enabled'),
            $recordInvariants,
            self::portalOperationsFromDocument($document),
            $labelTranslations,
        );
    }

    /**
     * Read the name for one record of this entity in the locale an operator is working in.
     *
     * @param   LocaleTag|string  $locale  Locale the surface is rendering in.
     *
     * @return  string  The closest translation the entity carries, otherwise the declared singular label.
     *
     * @throws  \Kumwe\Localization\Domain\InvalidLocaleTag  When the locale is a malformed tag.
     *
     * @since   2.0.0
     */
    public function singularLabelIn(LocaleTag|string $locale): string
    {
        return LocalizedDefinitionText::resolve(
            $this->labelTranslations,
            'singular_label',
            $this->singularLabel,
            $locale,
        );
    }

    /**
     * Read the name for a collection of these records in the locale an operator is working in.
     *
     * @param   LocaleTag|string  $locale  Locale the surface is rendering in.
     *
     * @return  string  The closest translation the entity carries, otherwise the declared plural label.
     *
     * @throws  \Kumwe\Localization\Domain\InvalidLocaleTag  When the locale is a malformed tag.
     *
     * @since   2.0.0
     */
    public function pluralLabelIn(LocaleTag|string $locale): string
    {
        return LocalizedDefinitionText::resolve(
            $this->labelTranslations,
            'plural_label',
            $this->pluralLabel,
            $locale,
        );
    }

    /**
     * Every translation the entity declares for its own labels.
     *
     * @return  array<string, array<string, string>>  Locale-keyed text under `singular_label` and
     *          `plural_label`; empty for an entity declared in one language.
     *
     * @since   2.0.0
     */
    public function labelTranslations(): array
    {
        return $this->labelTranslations;
    }

    /**
     * Field contract of the entity, in the order it was declared.
     *
     * @return  list<FieldDefinition>  Never empty; construction requires at least one field.
     *
     * @since   2.0.0
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * The date field this definition declares as the posting date the temporal lock reads.
     *
     * A definition opts into the posting-period mechanism by setting `posting_date: true` in the
     * configuration of exactly one date-carrying field; `BusinessDefinitionValidator` refuses a second
     * declaration. A definition that declares none returns null and is untouched by the whole
     * mechanism — no period is consulted and no mutation is refused.
     *
     * @return  ?FieldDefinition  The declared posting-date field, or null when the definition makes no
     *          declaration.
     *
     * @since   2.0.0
     */
    public function postingDateField(): ?FieldDefinition
    {
        foreach ($this->fields as $field) {
            if (($field->configuration['posting_date'] ?? null) === true) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Associations the definition declares explicitly, in the order they were declared.
     *
     * Ordered-line fields are not folded in here; `runtimeRelationship()` is the lookup that sees both.
     *
     * @return  list<RelationshipDefinition>  Empty when the entity reaches no other entity by a declared
     *          association.
     *
     * @since   2.0.0
     */
    public function relationships(): array
    {
        return $this->relationships;
    }

    /**
     * Resolves both explicit relationships and the legacy field-shaped ordered-line contract.
     *
     * Ordered lines are always an owned, ordered collection whose lifecycle follows its owner, so a matching
     * `core.ordered_lines` field is answered with a relationship synthesized on the spot: owned-line kind,
     * ordered, cascading on delete, and pointing at the entity named in the field's `target` configuration.
     * This is what lets the record repositories treat a legacy line-item field and a declared association
     * through one code path. Declared relationships are searched first, and construction already refuses an
     * ordered-line field that shares a handle with one, so the two can never disagree.
     *
     * @param   string  $handle  Relationship handle, or the handle of an ordered-line field, to resolve.
     *
     * @return  ?RelationshipDefinition  The association, or null when the handle names neither.
     *
     * @throws  InvalidBusinessDefinition  When a matching ordered-line field declares no string `target`, or
     *          names one that is not a valid entity handle.
     *
     * @since   2.0.0
     */
    public function runtimeRelationship(string $handle): ?RelationshipDefinition
    {
        foreach ($this->relationships as $relationship) {
            if ($relationship->handle === $handle) {
                return $relationship;
            }
        }
        foreach ($this->fields as $field) {
            if ($field->handle !== $handle || $field->type !== 'core.ordered_lines') {
                continue;
            }
            $target = $field->configuration['target'] ?? null;
            if (!is_string($target)) {
                throw new InvalidBusinessDefinition('An ordered-line field requires a declared entity target.');
            }

            return new RelationshipDefinition(
                $field->handle,
                $field->label,
                RelationshipKind::OwnedLineCollection,
                $target,
                null,
                false,
                false,
                true,
                DeleteBehavior::Cascade,
            );
        }

        return null;
    }

    /**
     * Named projections declared on the entity, in the order they were declared.
     *
     * @return  list<ViewDefinition>  Empty when the entity offers no view.
     *
     * @since   2.0.0
     */
    public function views(): array
    {
        return $this->views;
    }

    /**
     * Operations declared on the entity, in the order they were declared.
     *
     * @return  list<ActionDefinition>  Empty when the entity offers no action beyond plain record writes.
     *
     * @since   2.0.0
     */
    public function actions(): array
    {
        return $this->actions;
    }

    /**
     * Cross-field rules the record runtime evaluates on every create and update.
     *
     * @return  list<RecordInvariantDefinition>  Empty when the entity states no rule spanning several fields.
     *
     * @since   2.0.0
     */
    public function recordInvariants(): array
    {
        return $this->recordInvariants;
    }

    /**
     * Name every owned-line collection the entity's invariants reduce, and the line fields they read.
     *
     * This is what a write path asks before it commits: an empty map means no invariant looks past the
     * header, so nothing extra has to be gathered, and a non-empty one is the exact, bounded set of
     * collections and line fields the command must prepare for the rules to be judged once. Construction
     * has already proven every key is a declared owned-line collection of this entity.
     *
     * @return  array<string, list<string>>  Line field handles keyed by owned-line relationship handle,
     *          each list sorted and deduplicated; empty when no invariant aggregates.
     *
     * @since   2.0.0
     */
    public function invariantLineDependencies(): array
    {
        /** @var array<string, list<string>> $collections */
        $collections = [];
        foreach ($this->recordInvariants as $invariant) {
            foreach ($invariant->lineDependencies() as $relationship => $fields) {
                $collections[$relationship] = [...($collections[$relationship] ?? []), ...$fields];
            }
        }
        foreach ($collections as $relationship => $fields) {
            $fields = array_values(array_unique($fields));
            sort($fields, SORT_STRING);
            $collections[$relationship] = $fields;
        }
        ksort($collections, SORT_STRING);

        return $collections;
    }

    /**
     * Business-record operations explicitly enabled for the authenticated portal surface.
     *
     * Entity-level portal exposure is only the outer surface switch. An operation absent from this list
     * remains denied even when a portal view or action describes how it could be presented.
     *
     * @return  list<PortalOperation>  Operations in canonical backing-value order; empty denies all.
     *
     * @since   2.0.0
     */
    public function portalOperations(): array
    {
        return $this->portalOperations;
    }

    /**
     * Decide whether one business-record operation was explicitly opted into the portal.
     *
     * @param   PortalOperation  $operation  Closed operation to test.
     *
     * @return  bool  True only when portal exposure is enabled and the exact operation is allowlisted.
     *
     * @since   2.0.0
     */
    public function allowsPortalOperation(PortalOperation $operation): bool
    {
        return $this->portalExposure && in_array($operation, $this->portalOperations, true);
    }

    /**
     * Declared intent the definition carries for consumers that read it back out of the payload.
     *
     * @return  array<string, mixed>  Exactly as declared; `SchemaEvolutionHints::fromDefinition()` is the only
     *          reader today, and it takes just its four evolution families out of the map.
     *
     * @since   2.0.0
     */
    public function compatibilityMetadata(): array
    {
        return $this->compatibilityMetadata;
    }

    /**
     * Advance a draft to a published version, carrying every other property across unchanged.
     *
     * Publication is the only way out of `Draft`, and this instance — not the draft — is what the canonical
     * payload and its checksum are taken over. That is why the compatibility analyzer advances a draft to its
     * next version before diffing it against the published head, rather than comparing draft bytes to
     * published ones.
     *
     * @param   int  $version  Version number to publish as; must be one or greater.
     *
     * @return  self  A copy carrying `Published` status and that version.
     *
     * @throws  InvalidBusinessDefinition  When this definition is not a draft, or the version is below one.
     *
     * @since   2.0.0
     */
    public function published(int $version): self
    {
        if ($this->status !== DefinitionStatus::Draft || $version < 1) {
            throw new InvalidBusinessDefinition('Only a draft definition can be published to a positive version.');
        }

        return new self(
            $this->id,
            $this->owner,
            $this->siteIdentifier,
            $this->handle,
            $this->singularLabel,
            $this->pluralLabel,
            DefinitionStatus::Published,
            $version,
            $this->storageMode,
            $this->identityStrategy,
            $this->scope,
            $this->auditEnabled,
            $this->revisionsEnabled,
            $this->fields,
            $this->relationships,
            $this->views,
            $this->actions,
            $this->workflow,
            $this->compatibilityMetadata,
            $this->administratorExposure,
            $this->portalExposure,
            $this->publicExposure,
            $this->softDeleteEnabled,
            $this->recordInvariants,
            $this->portalOperations,
            $this->labelTranslations,
        );
    }

    /**
     * Carry an already-published definition on to a later lifecycle status.
     *
     * Nothing brings a definition back to `Draft`, and a version-zero definition has no published lifecycle to
     * move through, so both are refused. Note that status sits inside the canonical payload, so the copy
     * checksums differently from the original: a stored version's status change is recorded in its version row
     * instead, which is what keeps the bytes a published checksum was taken over immutable.
     *
     * @param   DefinitionStatus  $status  Status to carry; any case except `Draft`.
     *
     * @return  self  A copy with the new status and every other property unchanged.
     *
     * @throws  InvalidBusinessDefinition  When `Draft` is requested, or this definition has no positive version.
     *
     * @since   2.0.0
     */
    public function withStatus(DefinitionStatus $status): self
    {
        if ($status === DefinitionStatus::Draft || $this->definitionVersion < 1) {
            throw new InvalidBusinessDefinition('A published definition cannot return to draft status.');
        }

        return new self(
            $this->id,
            $this->owner,
            $this->siteIdentifier,
            $this->handle,
            $this->singularLabel,
            $this->pluralLabel,
            $status,
            $this->definitionVersion,
            $this->storageMode,
            $this->identityStrategy,
            $this->scope,
            $this->auditEnabled,
            $this->revisionsEnabled,
            $this->fields,
            $this->relationships,
            $this->views,
            $this->actions,
            $this->workflow,
            $this->compatibilityMetadata,
            $this->administratorExposure,
            $this->portalExposure,
            $this->publicExposure,
            $this->softDeleteEnabled,
            $this->recordInvariants,
            $this->portalOperations,
            $this->labelTranslations,
        );
    }

    /**
     * Export the definition as the canonical document it is stored, compared, and checksummed as.
     *
     * The exact shape is part of the persisted contract, which is why four members are written only when they
     * carry meaning: `soft_delete_enabled`, `record_invariants`, `portal_operations` and `label_translations`
     * are left out when unset, so definitions published before those properties existed still serialize to the
     * bytes their stored checksum was taken over. Everything else is always present, with nested definitions
     * already exported in declaration order.
     *
     * @return  array<string, mixed>  Every property under its stored key, with enums written as their backing
     *          strings and `workflow` written as null when the entity binds none.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        $document = [
            'id' => $this->id,
            'owner' => $this->owner->toArray(),
            'site' => $this->siteIdentifier,
            'handle' => $this->handle,
            'singular_label' => $this->singularLabel,
            'plural_label' => $this->pluralLabel,
            'status' => $this->status->value,
            'definition_version' => $this->definitionVersion,
            'storage_mode' => $this->storageMode->value,
            'identity_strategy' => $this->identityStrategy->value,
            'scope' => $this->scope->value,
            'audit_enabled' => $this->auditEnabled,
            'revisions_enabled' => $this->revisionsEnabled,
            'fields' => array_map(static fn (FieldDefinition $field): array => $field->toArray(), $this->fields),
            'relationships' => array_map(
                static fn (RelationshipDefinition $relationship): array => $relationship->toArray(),
                $this->relationships,
            ),
            'views' => array_map(static fn (ViewDefinition $view): array => $view->toArray(), $this->views),
            'actions' => array_map(static fn (ActionDefinition $action): array => $action->toArray(), $this->actions),
            'workflow' => $this->workflow?->toArray(),
            'compatibility_metadata' => $this->compatibilityMetadata,
            'administrator_exposure' => $this->administratorExposure,
            'portal_exposure' => $this->portalExposure,
            'public_exposure' => $this->publicExposure,
        ];
        // Preserve the canonical bytes/checksums of Session-2 definitions, where
        // the absent property already meant hard-delete-only behavior.
        if ($this->softDeleteEnabled) {
            $document['soft_delete_enabled'] = true;
        }
        if ($this->recordInvariants !== []) {
            $document['record_invariants'] = array_map(
                static fn (RecordInvariantDefinition $invariant): array => $invariant->toArray(),
                $this->recordInvariants,
            );
        }
        if ($this->portalOperations !== []) {
            $document['portal_operations'] = array_map(
                static fn (PortalOperation $operation): string => $operation->value,
                $this->portalOperations,
            );
        }
        if ($this->labelTranslations !== []) {
            $document['label_translations'] = $this->labelTranslations;
        }

        return $document;
    }

    /**
     * Fingerprint of the canonical document, which is how a published version is identified and re-verified.
     *
     * Schema planning, execution, and package synchronization compare this value rather than the document, so
     * a definition that has drifted from the one a plan was built against is caught before any DDL runs.
     *
     * @return  string  Lowercase hexadecimal SHA-256 of the canonical encoding, 64 characters wide.
     *
     * @throws  InvalidBusinessDefinition  When the assembled document is not canonically encodable — a case
     *          construction leaves open only for a member validated one nesting level shallower than it sits
     *          here, such as compatibility metadata at the depth ceiling.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        return CanonicalDefinitionJson::checksum($this->toArray());
    }

    /**
     * Reduce the definition to the three dependency sets the catalog indexes and closes a definition set by.
     *
     * The repository stores these as dependency rows beside the version. Publication and package
     * synchronization both walk `entities` to close a definition over the entities it reaches, so nothing is
     * validated or published against a target the catalog cannot produce. Every set is deduplicated and
     * sorted, which is what makes two definitions declaring the same dependencies produce identical rows.
     *
     * @return  array{fields: array<string, list<string>>, entities: list<string>, field_types: list<string>}
     *          `fields` maps each field handle to the handles its formula, visibility, and editability
     *          conditions read; `entities` names every entity reached by a declared relationship or by an
     *          entity-reference or ordered-lines field; `field_types` names every field type in use.
     *
     * @since   2.0.0
     */
    public function dependencyGraph(): array
    {
        $fieldDependencies = [];
        foreach ($this->fields as $field) {
            $dependencies = [];
            foreach ([$field->formula, $field->visibilityCondition, $field->editabilityCondition] as $expression) {
                if ($expression !== null) {
                    array_push($dependencies, ...$expression->dependencies());
                }
            }
            $dependencies = array_values(array_unique($dependencies));
            sort($dependencies, SORT_STRING);
            $fieldDependencies[$field->handle] = $dependencies;
        }
        ksort($fieldDependencies, SORT_STRING);
        $relations = array_values(array_unique(array_map(
            static fn (RelationshipDefinition $relationship): string => $relationship->target,
            $this->relationships,
        )));
        foreach ($this->fields as $field) {
            if (!in_array($field->type, ['core.entity_reference', 'core.ordered_lines'], true)) {
                continue;
            }
            $target = $field->configuration['target'] ?? null;
            if (is_string($target)) {
                $relations[] = $target;
            }
        }
        $relations = array_values(array_unique($relations));
        sort($relations, SORT_STRING);
        $types = array_values(array_unique(array_map(
            static fn (FieldDefinition $field): string => $field->type,
            $this->fields,
        )));
        sort($types, SORT_STRING);

        return ['fields' => $fieldDependencies, 'entities' => $relations, 'field_types' => $types];
    }

    /**
     * Prove the definition consistent with itself before construction is allowed to complete.
     *
     * Everything checked here is answerable from this one entity, which is what makes it a construction rule
     * rather than a validator pass: exactly one field of the type the identity strategy demands, no
     * ordered-line field colliding with a declared relationship handle, every expression dependency naming a
     * declared field and no cycle among them, views projecting only declared fields and filtering or sorting
     * only the fields marked for it, document-view roles naming declared non-UUID fields and declared line
     * and party relationships, an action's transition naming an edge the bound workflow declares, and
     * action and invariant conditions reading only declared fields.
     *
     * Line aggregation is settled here too, and in two directions. An invariant may reduce a collection,
     * but only one this entity declares as an owned-line collection, so a rule can never be published
     * against a shape that does not exist. A field formula, a visibility or editability condition, and an
     * action condition may not reduce at all, because each of those is evaluated for one record at a time
     * and there is no collection in scope when they run.
     *
     * @return  void
     *
     * @throws  InvalidBusinessDefinition  When any of those checks fails; the message states which rule was
     *          broken.
     *
     * @since   2.0.0
     */
    private function assertInternalGraph(): void
    {
        $fields = [];
        foreach ($this->fields as $field) {
            $fields[$field->handle] = $field;
        }
        $identityType = $this->identityStrategy === IdentityStrategy::Uuid ? 'core.uuid' : 'core.reference_identity';
        $identityFields = array_values(array_filter(
            $this->fields,
            static fn (FieldDefinition $field): bool => $field->type === $identityType,
        ));
        if (count($identityFields) !== 1) {
            throw new InvalidBusinessDefinition(
                'A business definition requires exactly one field matching its identity strategy.',
            );
        }
        foreach ($this->fields as $field) {
            if ($field->type === 'core.ordered_lines') {
                foreach ($this->relationships as $relationship) {
                    if ($relationship->handle === $field->handle) {
                        throw new InvalidBusinessDefinition(
                            'An ordered-line field and relationship cannot share a handle.',
                        );
                    }
                }
            }
            foreach ([$field->formula, $field->visibilityCondition, $field->editabilityCondition] as $expression) {
                foreach ($expression?->dependencies() ?? [] as $dependency) {
                    if (!isset($fields[$dependency])) {
                        throw new InvalidBusinessDefinition(sprintf(
                            'Business field %s depends on missing field %s.',
                            $field->handle,
                            $dependency,
                        ));
                    }
                }
                if (($expression?->lineDependencies() ?? []) !== []) {
                    throw new InvalidBusinessDefinition(
                        'A field formula or condition is evaluated per record and cannot aggregate owned lines.',
                    );
                }
            }
        }
        $this->assertNoFieldCycle($fields);
        foreach ($this->views as $view) {
            foreach (array_merge($view->fields, $view->filters, $view->sorts) as $field) {
                if (!isset($fields[$field])) {
                    throw new InvalidBusinessDefinition('A business view references missing field ' . $field . '.');
                }
            }
            foreach ($view->filters as $field) {
                if (!$fields[$field]->filterable) {
                    throw new InvalidBusinessDefinition('A business view filters a non-filterable field.');
                }
            }
            foreach ($view->sorts as $field) {
                if (!$fields[$field]->sortable) {
                    throw new InvalidBusinessDefinition('A business view sorts a non-sortable field.');
                }
            }
            $this->assertDocumentRoles($view, $fields);
        }
        $transitions = [];
        foreach ($this->workflow->transitions ?? [] as $transition) {
            $transitions[$transition['handle']] = true;
        }
        foreach ($this->actions as $action) {
            if ($action->transition !== null && !isset($transitions[$action->transition])) {
                throw new InvalidBusinessDefinition('A business action references a missing workflow transition.');
            }
            foreach ($action->condition?->dependencies() ?? [] as $dependency) {
                if (!isset($fields[$dependency])) {
                    throw new InvalidBusinessDefinition('A business action condition references a missing field.');
                }
            }
            if (($action->condition?->lineDependencies() ?? []) !== []) {
                throw new InvalidBusinessDefinition(
                    'A business action condition is evaluated against one record and cannot aggregate owned lines.',
                );
            }
        }
        $collections = [];
        foreach ($this->relationships as $relationship) {
            if ($relationship->kind === RelationshipKind::OwnedLineCollection) {
                $collections[$relationship->handle] = true;
            }
        }
        foreach ($this->recordInvariants as $invariant) {
            foreach ($invariant->condition->dependencies() as $dependency) {
                if (!isset($fields[$dependency])) {
                    throw new InvalidBusinessDefinition('A record invariant references a missing field.');
                }
            }
            foreach (array_keys($invariant->lineDependencies()) as $collection) {
                if (!isset($collections[$collection])) {
                    throw new InvalidBusinessDefinition(sprintf(
                        'Record invariant %s aggregates %s, which is not a declared owned-line collection.',
                        $invariant->handle,
                        $collection,
                    ));
                }
            }
        }
    }

    /**
     * Prove one view's documentary roles against the fields and relationships this entity declares.
     *
     * Beyond existence, the roles carry two documentary guarantees: no role may name a UUID field, so a
     * rendered document never shows a machine key as its number, a meta value, or a total; and every field
     * role must sit inside the view's own projection, so the projection list remains the single disclosure
     * gate the surface catalog filters. Lines must name a declared owned-line collection and every party a
     * declared many-to-one relationship, which is what lets the generated renderer hydrate them as a body
     * table and header cards without a bespoke read path.
     *
     * @param   ViewDefinition                  $view    Declared view whose optional document block is checked.
     * @param   array<string, FieldDefinition>  $fields  Declared fields indexed by handle.
     *
     * @return  void
     *
     * @throws  InvalidBusinessDefinition  When a role names a missing or UUID field, a field role escapes the
     *          view projection, lines is not a declared owned-line collection, or a party is not a declared
     *          many-to-one relationship.
     *
     * @since   2.0.0
     */
    private function assertDocumentRoles(ViewDefinition $view, array $fields): void
    {
        if ($view->document === null) {
            return;
        }
        $projected = array_fill_keys($view->fields, true);
        foreach ($view->document->fieldHandles() as $handle) {
            if (!isset($fields[$handle])) {
                throw new InvalidBusinessDefinition('A document view role references missing field ' . $handle . '.');
            }
            if ($fields[$handle]->type === 'core.uuid') {
                throw new InvalidBusinessDefinition('A document view role cannot reference a UUID field.');
            }
            if (!isset($projected[$handle])) {
                throw new InvalidBusinessDefinition('A document view role must stay inside the view projection.');
            }
        }
        $relationships = [];
        foreach ($this->relationships as $relationship) {
            $relationships[$relationship->handle] = $relationship;
        }
        if ($view->document->lines !== null) {
            $lines = $relationships[$view->document->lines] ?? null;
            if ($lines === null || $lines->kind !== RelationshipKind::OwnedLineCollection) {
                throw new InvalidBusinessDefinition(
                    'A document view lines role must name a declared owned-line collection.',
                );
            }
        }
        foreach ($view->document->parties as $party) {
            $target = $relationships[$party['relationship']] ?? null;
            if ($target === null || $target->kind !== RelationshipKind::ManyToOne) {
                throw new InvalidBusinessDefinition(
                    'A document view party must name a declared many-to-one relationship.',
                );
            }
        }
    }

    /**
     * Refuse a field graph in which an expression can reach back to the field that owns it.
     *
     * A depth-first walk marks the handles it is currently descending through and reports the first one it
     * meets twice on a single path, so a cycle is named at definition time instead of exhausting the stack
     * when the record runtime evaluates it. Run only after every dependency has been proven to name a declared
     * field, since the walk indexes `$fields` directly.
     *
     * @param   array<string, FieldDefinition>  $fields  Declared fields indexed by handle; the walk both starts
     *          from these keys and resolves dependencies through them.
     *
     * @return  void
     *
     * @throws  InvalidBusinessDefinition  When a formula, visibility condition, or editability condition leads
     *          back to its own field, directly or through other fields.
     *
     * @since   2.0.0
     */
    private function assertNoFieldCycle(array $fields): void
    {
        $visiting = [];
        $visited = [];
        $walk = function (string $handle) use (&$walk, &$visiting, &$visited, $fields): void {
            if (isset($visiting[$handle])) {
                throw new InvalidBusinessDefinition(
                    'A business field dependency cycle was detected at ' . $handle . '.',
                );
            }
            if (isset($visited[$handle])) {
                return;
            }
            $visiting[$handle] = true;
            foreach (
                [
                $fields[$handle]->formula,
                $fields[$handle]->visibilityCondition,
                $fields[$handle]->editabilityCondition,
                ] as $expression
            ) {
                foreach ($expression?->dependencies() ?? [] as $dependency) {
                    $walk($dependency);
                }
            }
            unset($visiting[$handle]);
            $visited[$handle] = true;
        };
        foreach (array_keys($fields) as $handle) {
            $walk($handle);
        }
    }

    /**
     * Refuse a collection in which two members claim the same handle, and hand it back unchanged.
     *
     * Every collection an entity carries is addressed by handle at runtime, so a duplicate would leave one
     * member permanently unreachable. The error names the kind and the repeated handle, so an author can find
     * it in the source document.
     *
     * @template T of object
     *
     * @param   list<T>              $values      Members to check, in declaration order.
     * @param   callable(T): string  $identifier  Reads the handle a member is addressed by.
     * @param   string               $kind        Collection name used in the error message, such as `view`.
     *
     * @return  list<T>  The same members in the same order; this filters nothing.
     *
     * @throws  InvalidBusinessDefinition  When two members yield the same handle.
     *
     * @since   2.0.0
     */
    private static function unique(array $values, callable $identifier, string $kind): array
    {
        $seen = [];
        foreach ($values as $value) {
            $key = $identifier($value);
            if (isset($seen[$key])) {
                throw new InvalidBusinessDefinition(sprintf('Business %s %s is duplicated.', $kind, $key));
            }
            $seen[$key] = true;
        }
        return $values;
    }

    /**
     * Read a required, non-blank string property out of a definition document.
     *
     * @param   array<string, mixed>  $document  Document being decoded.
     * @param   string                $key       Property to read, named in the error message.
     *
     * @return  string  The value with surrounding whitespace removed.
     *
     * @throws  InvalidBusinessDefinition  When the property is absent, is not a string, or is empty once
     *          trimmed.
     *
     * @since   2.0.0
     */
    private static function string(array $document, string $key): string
    {
        $value = $document[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidBusinessDefinition('Business entity property ' . $key . ' is required.');
        }
        return trim($value);
    }

    /**
     * Read a required integer property out of a definition document.
     *
     * @param   array<string, mixed>  $document  Document being decoded.
     * @param   string                $key       Property to read, named in the error message.
     *
     * @return  int  The value exactly as declared; a numeric string is rejected, never cast.
     *
     * @throws  InvalidBusinessDefinition  When the property is absent or is not an integer.
     *
     * @since   2.0.0
     */
    private static function integer(array $document, string $key): int
    {
        $value = $document[$key] ?? null;
        if (!is_int($value)) {
            throw new InvalidBusinessDefinition('Business entity property ' . $key . ' must be an integer.');
        }
        return $value;
    }

    /**
     * Read an optional boolean property out of a definition document.
     *
     * @param   array<string, mixed>  $document  Document being decoded.
     * @param   string                $key       Property to read, named in the error message.
     * @param   bool                  $default   Value to stand in when the property is absent or null.
     *
     * @return  bool  The declared value, or the default when the document does not carry the property.
     *
     * @throws  InvalidBusinessDefinition  When the property is present but is not a boolean.
     *
     * @since   2.0.0
     */
    private static function boolean(array $document, string $key, bool $default = false): bool
    {
        $value = $document[$key] ?? $default;
        if (!is_bool($value)) {
            throw new InvalidBusinessDefinition('Business entity property ' . $key . ' must be boolean.');
        }
        return $value;
    }

    /**
     * Read an optional collection of member documents out of a definition document.
     *
     * An absent key yields an empty list, which is how every optional collection defaults. A present key has
     * to be a list whose members are each a string-keyed array, so neither a nested list nor an empty object
     * ever reaches a member's own `fromArray()`.
     *
     * @param   array<string, mixed>  $document  Document being decoded.
     * @param   string                $key       Property holding the collection, named in the error message.
     *
     * @return  list<array<string, mixed>>  Member documents in declaration order; empty when the key is absent.
     *
     * @throws  InvalidBusinessDefinition  When the property is not a list, or a member is not a string-keyed
     *          array.
     *
     * @since   2.0.0
     */
    private static function objects(array $document, string $key): array
    {
        $value = $document[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidBusinessDefinition('Business entity property ' . $key . ' must be a list.');
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw new InvalidBusinessDefinition('Business entity property ' . $key . ' must contain objects.');
            }
            /** @var array<string, mixed> $item */
            $result[] = $item;
        }
        return $result;
    }

    /**
     * Decode the optional portal-operation allowlist from a canonical definition document.
     *
     * @param   array<string, mixed>  $document  Definition document being decoded.
     *
     * @return  list<PortalOperation>  Declared operations, validated and canonicalized by construction.
     *
     * @throws  InvalidBusinessDefinition  When the property is not a list, contains a non-string or unknown
     *          operation, or repeats an operation.
     *
     * @since   2.0.0
     */
    private static function portalOperationsFromDocument(array $document): array
    {
        $values = $document['portal_operations'] ?? [];
        if (!is_array($values) || !array_is_list($values)) {
            throw new InvalidBusinessDefinition('Business entity property portal_operations must be a list.');
        }
        $operations = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new InvalidBusinessDefinition('A portal operation must be a string.');
            }
            $operations[] = PortalOperation::tryFrom($value)
                ?? throw new InvalidBusinessDefinition('A portal operation is invalid.');
        }

        return $operations;
    }

    /**
     * Validate and canonicalize an explicitly supplied portal-operation allowlist.
     *
     * @param   list<PortalOperation>  $operations  Operations supplied to construction.
     *
     * @return  list<PortalOperation>  The same operations sorted by their stable backing values.
     *
     * @throws  InvalidBusinessDefinition  When the collection is not a list, an item is not a
     *          `PortalOperation`, or an operation is repeated.
     *
     * @since   2.0.0
     */
    private static function normalizePortalOperations(array $operations): array
    {
        if (!array_is_list($operations)) {
            throw new InvalidBusinessDefinition('Portal operations must be a list.');
        }
        $seen = [];
        foreach ($operations as $operation) {
            if (!$operation instanceof PortalOperation) {
                throw new InvalidBusinessDefinition('A portal operation must use the closed operation type.');
            }
            if (isset($seen[$operation->value])) {
                throw new InvalidBusinessDefinition('Portal operation ' . $operation->value . ' is duplicated.');
            }
            $seen[$operation->value] = true;
        }
        usort(
            $operations,
            static fn (PortalOperation $left, PortalOperation $right): int => $left->value <=> $right->value,
        );

        return $operations;
    }
}
