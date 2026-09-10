<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionCompatibilityAnalyzer;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionValidator;
use Kumwe\App\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\App\BusinessDefinition\Application\PackageDefinitionSynchronizer;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\App\BusinessDefinition\Domain\DefinitionOwnerType;
use Kumwe\App\BusinessDefinition\Domain\DefinitionStatus;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\App\BusinessSchema\Application\PublishedDefinitionSchemaObserver;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaLifecycleObserver;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use LogicException;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * Applies one extension release's declared business definitions to a site catalog, over Doctrine DBAL.
 *
 * This is the `PackageDefinitionSynchronizer` the extension installer drives while a package is being
 * installed, upgraded, enabled or quarantined. It joins the lifecycle transaction the installer already
 * opened and refuses to start without one, so a release that breaks any rule leaves the catalog, the
 * field-type table and the audit trail exactly as they were. Field types are written straight to
 * `business_field_types` and are immutable once published: an identifier stays with the owner that first
 * claimed it, its canonical checksum may never move, and a type the release stopped declaring is only
 * deactivated, never deleted, so `DoctrinePersistedFieldTypeDefinitionResolver` can still describe the
 * records written under it. Entity definitions go through `BusinessDefinitionRepository` one version at a
 * time, and a handle the release dropped is deprecated rather than removed. Validation runs over the
 * release's definitions together with every published definition they reach that another active owner
 * holds, so a package cannot publish structure that breaks a neighbour's references. Both schema
 * observers are optional, which lets an installation that registers no schema services still apply
 * definitions.
 *
 * @since  2.0.0
 */
final readonly class DoctrinePackageDefinitionSynchronizer implements PackageDefinitionSynchronizer
{
    /**
     * Wire the synchronizer to the catalog, the field-type table and the observers it notifies.
     *
     * @param  Connection                               $database         Connection this class reads and
     *         writes the field-type table on, and whose already open transaction the work joins.
     * @param  TableNames                               $tables           Physical name compiler for the
     *         `business_field_types` table.
     * @param  BusinessDefinitionRepository             $repository       Catalog the entity definitions
     *         are read from, drafted into and published through.
     * @param  BusinessDefinitionCompatibilityAnalyzer  $compatibility    Prices each publication against
     *         the version it replaces; the resulting plan is stored beside the version it describes.
     * @param  ResourceSiteOwnershipWriter              $ownership        Records the owning site for each
     *         definition the release introduces, so authorization can resolve it afterwards.
     * @param  AuditRecorder                            $audit            Sink the synchronization and
     *         activation entries are recorded to.
     * @param  ClockInterface                           $clock            Supplies every timestamp written
     *         to the field-type rows, the catalog and the audit trail.
     * @param  ?PublishedDefinitionSchemaObserver       $schemaObserver   Handed the complete published
     *         graph so schema plans exist for it; null where the installation runs no schema services.
     * @param  ?BusinessSchemaLifecycleObserver         $schemaLifecycle  Told when the package's
     *         availability changes, so its physical installations follow; null in the same case.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private BusinessDefinitionRepository $repository,
        private BusinessDefinitionCompatibilityAnalyzer $compatibility,
        private ResourceSiteOwnershipWriter $ownership,
        private AuditRecorder $audit,
        private ClockInterface $clock,
        private ?PublishedDefinitionSchemaObserver $schemaObserver = null,
        private ?BusinessSchemaLifecycleObserver $schemaLifecycle = null,
    ) {
    }

    /**
     * Apply one package release's declarations to a site catalog, inside the caller's open transaction.
     *
     * The release is admitted whole or not at all. Field types are reconciled first, then each entity
     * definition is advanced by exactly one version, and only afterwards is the package's availability
     * set and the work audited. What the release declares is validated against a registry holding the
     * core built-ins, every other active owner's persisted field types, and the release's own — its
     * previously stored types are deliberately excluded, because these declarations replace them. Each
     * incoming definition is re-stamped with the target site before anything is compared. When every
     * definition in the validated graph resolves to a published record, the whole graph is handed to the
     * schema observer so plans exist for it.
     *
     * @param   string                      $extensionIdentifier  Owning extension, as `vendor/name`; its
     *          namespace is what every declared identifier has to sit under.
     * @param   string                      $releaseVersion       Release the declarations were read from,
     *          recorded on every field-type row this call touches.
     * @param   SiteContext                 $site                 Site whose catalog is updated; the
     *          release's definitions are re-stamped with it before they are compared or stored.
     * @param   list<FieldTypeDefinition>   $fieldTypes           Field types the release declares; the
     *          complete set, since a type the owner still holds but this list omits is deactivated.
     * @param   list<EntityTypeDefinition>  $definitions          Entity types the release declares, each
     *          already at published status and numbered with the version this call is to publish.
     * @param   bool                        $active               Whether the package's definitions are
     *          available to the runtime once the release has been applied.
     * @param   string                      $actorId              Actor recorded against the audit entry
     *          and against every draft and version written here.
     *
     * @return  void
     *
     * @throws  LogicException  When the caller has not opened the lifecycle transaction that a refused
     *          release has to roll back with.
     * @throws  InvalidBusinessDefinition  When a declaration claims an identifier outside this owner's
     *          namespace, arrives unpublished, replaces another owner's field type or definition, edits
     *          bytes already published, skips a version, or fails validation against the resulting graph.
     * @throws  \Kumwe\App\BusinessDefinition\Application\BusinessDefinitionRevisionConflict  When another
     *          writer advanced one of these handles while the release was being applied.
     * @throws  \Kumwe\App\BusinessSchema\Application\BusinessSchemaConflict  When an installation this
     *          owner holds cannot be returned to service as the new activation state requires.
     *
     * @since   2.0.0
     */
    public function synchronize(
        string $extensionIdentifier,
        string $releaseVersion,
        SiteContext $site,
        array $fieldTypes,
        array $definitions,
        bool $active,
        string $actorId,
    ): void {
        $this->assertTransaction();
        $owner = DefinitionOwner::extension($extensionIdentifier);
        $definitions = array_map(
            static function (EntityTypeDefinition $definition) use ($site): EntityTypeDefinition {
                $document = $definition->toArray();
                $document['site'] = $site->identifier();
                return EntityTypeDefinition::fromArray($document);
            },
            $definitions,
        );
        $validationTypes = new FieldTypeRegistry();
        foreach ($this->activePersistedFieldTypes() as [$persistedOwner, $fieldType]) {
            if ($persistedOwner->toArray() === $owner->toArray()) {
                continue;
            }
            if (!$validationTypes->has($fieldType->id)) {
                $validationTypes->register($persistedOwner, $fieldType);
            }
        }
        foreach ($fieldTypes as $fieldType) {
            $owner->assertOwns($fieldType->id);
            if (!$validationTypes->has($fieldType->id)) {
                $validationTypes->register($owner, $fieldType);
            }
        }
        foreach ($definitions as $definition) {
            if (
                $definition->owner->toArray() !== $owner->toArray()
                || $definition->status !== DefinitionStatus::Published
            ) {
                throw new InvalidBusinessDefinition('A package definition has invalid ownership, site, or status.');
            }
        }
        $resultingGraph = $this->existingDefinitionGraph($site, $owner, $definitions);
        if ($resultingGraph !== []) {
            (new BusinessDefinitionValidator($validationTypes))->validateGraph($resultingGraph);
        }
        $this->synchronizeFieldTypes($owner, $releaseVersion, $fieldTypes, $active);
        $this->synchronizeDefinitions($owner, $site, $definitions, $actorId);
        $publishedGraph = [];
        foreach ($resultingGraph as $definition) {
            $record = $this->repository->published($site, $definition->handle);
            if ($record !== null) {
                $publishedGraph[] = $record;
            }
        }
        if (count($publishedGraph) === count($resultingGraph) && $publishedGraph !== []) {
            $this->schemaObserver?->observePublishedGraph(
                $site,
                $publishedGraph,
                $actorId,
                $this->clock->now(),
            );
        }
        $lifecycleAt = $this->clock->now();
        $this->repository->setOwnerActive($extensionIdentifier, $active, $lifecycleAt);
        $this->schemaLifecycle?->setOwnerActive($extensionIdentifier, $active, $lifecycleAt);
        $this->record($actorId, 'business_definition.package.synchronize', $extensionIdentifier, [
            'release_version' => $releaseVersion,
            'field_types' => count($fieldTypes),
            'definitions' => count($definitions),
            'active' => $active,
        ]);
    }

    /**
     * Flip the availability of a package's already applied definitions without republishing any of them.
     *
     * Ordinary enable and disable both land here, and so does the quarantine that follows a trust-key
     * revocation. Nothing is republished: the stored versions keep their bytes, their numbering and their
     * history, and only whether the runtime may use them changes. The schema lifecycle observer is told
     * inside the same transaction, so the physical installations cannot end up disagreeing with the
     * catalog.
     *
     * @param   string  $extensionIdentifier  Owning extension, as `vendor/name`; the switch is owner-wide
     *          and crosses sites, because an extension is installed once per installation.
     * @param   bool    $active               Whether its definitions become available to the runtime.
     * @param   string  $actorId              Actor recorded against the activation audit entry.
     *
     * @return  void
     *
     * @throws  LogicException  When the caller has not opened the lifecycle transaction.
     * @throws  \Kumwe\App\BusinessSchema\Application\BusinessSchemaConflict  When an installation cannot
     *          be proved to match the schema it claims and so cannot be returned to service.
     *
     * @since   2.0.0
     */
    public function setActive(string $extensionIdentifier, bool $active, string $actorId): void
    {
        $this->assertTransaction();
        $lifecycleAt = $this->clock->now();
        $this->repository->setOwnerActive($extensionIdentifier, $active, $lifecycleAt);
        $this->schemaLifecycle?->setOwnerActive($extensionIdentifier, $active, $lifecycleAt);
        $this->record(
            $actorId,
            $active ? 'business_definition.package.activate' : 'business_definition.package.disable',
            $extensionIdentifier,
            ['active' => $active],
        );
    }

    /**
     * Reconcile the field types one release declares against the rows already stored for its owner.
     *
     * Publication is final: a row that already exists has to belong to this owner and still carry the
     * checksum of the payload being declared, so a release may revise a type only by declaring a new
     * identifier — an existing row is refreshed with the release version and activation flag and nothing
     * else. Rows this owner holds that the release no longer declares are deactivated rather than
     * deleted, which keeps their structure resolvable for the records already written under them.
     *
     * @param   DefinitionOwner            $owner           Extension owner every declared identifier has
     *          to sit under.
     * @param   string                     $releaseVersion  Release the declarations were read from,
     *          stored as each row's source version.
     * @param   list<FieldTypeDefinition>  $definitions     The complete set the release declares;
     *          anything absent from it is deactivated.
     * @param   bool                       $active          Whether this owner's rows are available to the
     *          runtime.
     *
     * @return  void
     *
     * @throws  InvalidBusinessDefinition  When a declared identifier is already stored against another
     *          owner, or its stored checksum differs from the payload now being declared.
     *
     * @since   2.0.0
     */
    private function synchronizeFieldTypes(
        DefinitionOwner $owner,
        string $releaseVersion,
        array $definitions,
        bool $active,
    ): void {
        $declared = [];
        foreach ($definitions as $definition) {
            $declared[] = $definition->id;
            $payload = $definition->toArray();
            $checksum = CanonicalDefinitionJson::checksum($payload);
            $existing = $this->database->fetchAssociative(sprintf(
                'SELECT owner_type, owner_identifier, checksum FROM %s WHERE identifier = ?',
                $this->tables->quoted('business_field_types'),
            ), [$definition->id]);
            if ($existing !== false) {
                if (
                    ($existing['owner_type'] ?? null) !== DefinitionOwnerType::Extension->value
                    || ($existing['owner_identifier'] ?? null) !== $owner->identifier
                ) {
                    throw new InvalidBusinessDefinition('A package attempted to claim another owner\'s field type.');
                }
                if (!is_string($existing['checksum'] ?? null) || !hash_equals($existing['checksum'], $checksum)) {
                    throw new InvalidBusinessDefinition(
                        'Published field type ' . $definition->id . ' is immutable; declare a new identifier.',
                    );
                }
                $this->database->update($this->tables->raw('business_field_types'), [
                    'source_version' => $releaseVersion,
                    'active' => $active,
                    'updated_at' => $this->clock->now(),
                ], ['identifier' => $definition->id], [
                    'active' => Types::BOOLEAN,
                    'updated_at' => Types::DATETIME_IMMUTABLE,
                ]);
                continue;
            }
            $this->database->insert($this->tables->raw('business_field_types'), [
                'identifier' => $definition->id,
                'owner_type' => DefinitionOwnerType::Extension->value,
                'owner_identifier' => $owner->identifier,
                'source_version' => $releaseVersion,
                'active' => $active,
                'checksum' => $checksum,
                'canonical_payload' => $payload,
                'updated_at' => $this->clock->now(),
            ], [
                'active' => Types::BOOLEAN,
                'canonical_payload' => Types::JSON,
                'updated_at' => Types::DATETIME_IMMUTABLE,
            ]);
        }
        $owned = $this->database->fetchFirstColumn(sprintf(
            'SELECT identifier FROM %s WHERE owner_type = ? AND owner_identifier = ? ORDER BY identifier',
            $this->tables->quoted('business_field_types'),
        ), [DefinitionOwnerType::Extension->value, $owner->identifier]);
        foreach ($owned as $identifier) {
            if (is_string($identifier) && !in_array($identifier, $declared, true)) {
                $this->database->update($this->tables->raw('business_field_types'), [
                    'active' => false,
                    'updated_at' => $this->clock->now(),
                ], ['identifier' => $identifier], [
                    'active' => Types::BOOLEAN,
                    'updated_at' => Types::DATETIME_IMMUTABLE,
                ]);
            }
        }
    }

    /**
     * Publish the entity definitions one release declares and deprecate the handles it stopped declaring.
     *
     * A handle advances exactly one version at a time. Re-applying the release a definition already sits
     * at is a no-op once its bytes are confirmed identical, and any version other than the next one is
     * refused. Each definition is saved as a version-zero draft first, so the compatibility plan is
     * analysed against whatever is published now and stored beside the version it describes; the first
     * save of a handle also records the owning site that authorization resolves the definition through.
     * Handles this owner published before and the release no longer declares are moved to deprecated, so
     * the versions and their data stay in place.
     *
     * @param   DefinitionOwner             $owner        Extension owner every declared handle has to
     *          belong to.
     * @param   SiteContext                 $site         Site whose catalog the definitions are
     *          published into.
     * @param   list<EntityTypeDefinition>  $definitions  The complete set the release declares; a handle
     *          this owner published before and this list omits is deprecated.
     * @param   string                      $actorId      Actor recorded as the author of each draft and
     *          each publication.
     *
     * @return  void
     *
     * @throws  InvalidBusinessDefinition  When an already applied version's bytes differ from what the
     *          release declares, a definition does not carry the next version in sequence, or the handle
     *          is already held by another owner.
     * @throws  \Kumwe\App\BusinessDefinition\Application\BusinessDefinitionRevisionConflict  When another
     *          writer advanced the same handle between the draft being saved and published.
     *
     * @since   2.0.0
     */
    private function synchronizeDefinitions(
        DefinitionOwner $owner,
        SiteContext $site,
        array $definitions,
        string $actorId,
    ): void {
        $declared = [];
        foreach ($definitions as $definition) {
            $declared[] = $definition->handle;
            $existing = $this->repository->published($site, $definition->handle);
            if ($existing !== null && $existing->definition->definitionVersion === $definition->definitionVersion) {
                if (!hash_equals($existing->definition->checksum(), $definition->checksum())) {
                    throw new InvalidBusinessDefinition('Applied package definition bytes are immutable.');
                }
                continue;
            }
            $expectedVersion = ($existing?->definition->definitionVersion ?? 0) + 1;
            if ($definition->definitionVersion !== $expectedVersion) {
                throw new InvalidBusinessDefinition(sprintf(
                    'Package definition %s must publish version %d next.',
                    $definition->handle,
                    $expectedVersion,
                ));
            }
            $draftDocument = $definition->toArray();
            $draftDocument['status'] = DefinitionStatus::Draft->value;
            $draftDocument['definition_version'] = 0;
            $draft = EntityTypeDefinition::fromArray($draftDocument);
            $entry = $this->repository->entry($site, $draft->handle);
            if ($entry !== null && $entry->owner->toArray() !== $owner->toArray()) {
                throw new InvalidBusinessDefinition('A package attempted to replace another owner\'s definition.');
            }
            $saved = $this->repository->saveDraft(
                $draft,
                $actorId,
                $this->clock->now(),
                $entry?->draftRevision,
            );
            if ($entry === null) {
                $this->ownership->record(
                    AuthorizationResource::item('business_definition', $draft->id),
                    $site,
                );
            }
            $plan = $this->compatibility->analyze($existing?->definition, $draft);
            $this->repository->publish(
                $definition,
                $plan,
                $actorId,
                $this->clock->now(),
                $saved->revision,
            );
        }
        foreach ($this->repository->catalog($site) as $entry) {
            if (
                $entry->owner->toArray() !== $owner->toArray() || in_array($entry->handle, $declared, true)
                || $entry->publishedVersion === null
            ) {
                continue;
            }
            $this->repository->changeStatus(
                $site,
                $entry->id,
                $entry->publishedVersion,
                DefinitionStatus::Deprecated,
                $this->clock->now(),
            );
        }
    }

    /**
     * Read every currently active field type from the store, verified and paired with its owner.
     *
     * This is the set an incoming release is validated against, so nothing is taken on trust: each row's
     * payload has to decode to a JSON object, carry the identifier the row is filed under, still match
     * the checksum stored beside it, and sit inside the namespace of the owner recorded on it. A row that
     * fails any of those is a refusal for the whole synchronization rather than an entry that is skipped.
     *
     * @return  list<array{0: DefinitionOwner, 1: FieldTypeDefinition}>  Owner and structure per row, in
     *          identifier order; empty when no field type is active.
     *
     * @throws  InvalidBusinessDefinition  When a row's owner type, owner identifier, payload, identifier
     *          or checksum fails verification, or the owner does not own the identifier.
     * @throws  \JsonException  When a stored canonical payload is not well-formed JSON or nests deeper
     *          than 32 levels.
     *
     * @since   2.0.0
     */
    private function activePersistedFieldTypes(): array
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT identifier, owner_type, owner_identifier, checksum, canonical_payload FROM %s '
            . 'WHERE active = ? ORDER BY identifier',
            $this->tables->quoted('business_field_types'),
        ), [true], [Types::BOOLEAN]);
        $result = [];
        foreach ($rows as $row) {
            $ownerTypeValue = $row['owner_type'] ?? null;
            if (!is_string($ownerTypeValue)) {
                throw new InvalidBusinessDefinition('A persisted field-type owner is invalid.');
            }
            $ownerType = DefinitionOwnerType::tryFrom($ownerTypeValue)
                ?? throw new InvalidBusinessDefinition('A persisted field-type owner is invalid.');
            $identifier = $row['owner_identifier'] ?? null;
            $payload = $row['canonical_payload'] ?? null;
            if (!is_string($identifier)) {
                throw new InvalidBusinessDefinition('A persisted field-type owner identifier is invalid.');
            }
            if (is_string($payload)) {
                $payload = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
            }
            if (!is_array($payload) || array_is_list($payload)) {
                throw new InvalidBusinessDefinition('A persisted field-type payload is invalid.');
            }
            /** @var array<string, mixed> $payload */
            $fieldType = FieldTypeDefinition::fromArray($payload);
            $persistedIdentifier = $row['identifier'] ?? null;
            if (!is_string($persistedIdentifier) || $persistedIdentifier !== $fieldType->id) {
                throw new InvalidBusinessDefinition('A persisted field-type identifier is inconsistent.');
            }
            $checksum = $row['checksum'] ?? null;
            if (
                !is_string($checksum)
                || !hash_equals($checksum, CanonicalDefinitionJson::checksum($fieldType->toArray()))
            ) {
                throw new InvalidBusinessDefinition('A persisted field-type checksum is invalid.');
            }
            $persistedOwner = new DefinitionOwner($ownerType, $identifier);
            $persistedOwner->assertOwns($fieldType->id);
            $result[] = [$persistedOwner, $fieldType];
        }
        return $result;
    }

    /**
     * Close the release's definitions over the published definitions they depend on.
     *
     * Validation demands a self-contained graph, so the entities a package definition targets are walked
     * breadth-first and each one another owner already publishes is appended behind it. Definitions this
     * same owner published are left out, because the release supersedes them, as are handles whose owner
     * is inactive or that have never been published. A handle is visited once, so a reference cycle
     * simply terminates; the closure is additionally capped at 128 entities, the same ceiling the
     * validator enforces, so an oversized dependency set is refused before validation ever sees it.
     *
     * @param   SiteContext                 $site                Site the dependencies are resolved
     *          within.
     * @param   DefinitionOwner             $packageOwner        Owner whose own published definitions are
     *          left out, since the release replaces them.
     * @param   list<EntityTypeDefinition>  $packageDefinitions  The release's definitions, already
     *          re-stamped with the target site.
     *
     * @return  list<EntityTypeDefinition>  The release's definitions first, followed by the published
     *          dependencies pulled in behind them.
     *
     * @throws  InvalidBusinessDefinition  When the closure grows past 128 entities.
     *
     * @since   2.0.0
     */
    private function existingDefinitionGraph(
        SiteContext $site,
        DefinitionOwner $packageOwner,
        array $packageDefinitions,
    ): array {
        $graph = $packageDefinitions;
        $known = [];
        foreach ($packageDefinitions as $definition) {
            $known[$definition->handle] = true;
        }
        for ($position = 0; isset($graph[$position]); $position++) {
            foreach ($graph[$position]->dependencyGraph()['entities'] as $handle) {
                if (isset($known[$handle])) {
                    continue;
                }
                $known[$handle] = true;
                $entry = $this->repository->entry($site, $handle);
                if (
                    $entry === null || $entry->publishedVersion === null || !$entry->ownerActive
                    || $entry->owner->toArray() === $packageOwner->toArray()
                ) {
                    continue;
                }
                $published = $this->repository->published($site, $entry->id);
                if ($published === null) {
                    continue;
                }
                $graph[] = $published->definition;
                if (count($graph) > 128) {
                    throw new InvalidBusinessDefinition('A package definition dependency graph is unbounded.');
                }
            }
        }
        return $graph;
    }

    /**
     * Write one audit entry for a package-level change to the definition catalog.
     *
     * @param   string                $actorId   Actor credited with the change.
     * @param   string                $action    Audit action, such as `business_definition.package.activate`.
     * @param   string                $subject   Extension identifier; its `/` becomes `:` in the subject.
     * @param   array<string, mixed>  $metadata  Detail stored with the entry, such as the release version
     *          and how many field types and definitions the release declared.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function record(string $actorId, string $action, string $subject, array $metadata): void
    {
        $this->audit->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $this->clock->now(),
            $actorId,
            $action,
            'business_definition',
            str_replace('/', ':', $subject),
            'success',
            $metadata,
        ));
    }

    /**
     * Refuse to run outside the transaction the extension lifecycle opened.
     *
     * Everything this class writes — field-type rows, drafts, published versions, ownership records and
     * audit entries — has to roll back together, and it deliberately opens no transaction of its own.
     *
     * @return  void
     *
     * @throws  LogicException  When the connection has no active transaction.
     *
     * @since   2.0.0
     */
    private function assertTransaction(): void
    {
        if (!$this->database->isTransactionActive()) {
            throw new LogicException(
                'Package definition synchronization requires the extension lifecycle transaction.',
            );
        }
    }
}
