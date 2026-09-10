<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Application;

use DateTimeImmutable;
use Kumwe\App\BusinessDefinition\Domain\CompatibilityPlan;
use Kumwe\App\BusinessDefinition\Domain\DefinitionStatus;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\Context\Value\SiteContext;

/**
 * Store behind a site's definition catalog: the draft in progress and every version already published.
 *
 * A business definition is versioned content, so the store keeps three things apart — a catalog head
 * recording where each handle stands, at most one draft per handle, and the history of published versions,
 * whose bytes never change once written. Every read is scoped to a single site and takes either the
 * definition's UUID or its handle, so callers never have to resolve one into the other first. The two
 * writes that advance a handle are optimistic: `saveDraft()` and `publish()` each state the revision they
 * were composed against and refuse rather than overwrite a concurrent edit. Implementations run their
 * mutations inside the transaction the caller has already opened, so a refused write leaves the catalog,
 * the version history and the audit trail exactly as they were.
 *
 * @since  2.0.0
 */
interface BusinessDefinitionRepository
{
    /**
     * List every definition the site holds, whoever owns it.
     *
     * @param   SiteContext  $site  Site whose catalog is being read.
     *
     * @return  list<DefinitionCatalogEntry>  Where each handle stands — draft revision, published version
     *          and publication state — in handle order, capped at 4096; empty when the site holds none.
     *
     * @throws  \RuntimeException  When the site's catalog exceeds the supported bound.
     *
     * @since   2.0.0
     */
    public function catalog(SiteContext $site): array;

    /**
     * Serialize publication-time claims over one site's derived public-contract namespace.
     *
     * The lock is taken on the site's stable authority row rather than on definition rows, because the first
     * definition has no catalog row to lock and two differently spelled handles may normalize to the same
     * public component name. It is held by the caller's transaction through admission and publication.
     *
     * @param   SiteContext  $site  Site whose complete component namespace is about to be admitted.
     *
     * @return  void
     *
     * @throws  \LogicException  When the caller has no transaction open.
     * @throws  \RuntimeException  When the stable site authority row is unavailable.
     *
     * @since   2.0.0
     */
    public function lockContractNamespace(SiteContext $site): void;

    /**
     * Resolve one catalog head, without loading its draft or any published version.
     *
     * This is the cheap existence-and-ownership check the service runs before authorizing an operation
     * against a definition, since the head carries the identity the authorization resource is keyed on.
     *
     * @param   SiteContext  $site        Site the definition must belong to.
     * @param   string       $identifier  The definition's UUID or its handle.
     *
     * @return  ?DefinitionCatalogEntry  Where the handle stands, or null when this site holds no such
     *          definition.
     *
     * @since   2.0.0
     */
    public function entry(SiteContext $site, string $identifier): ?DefinitionCatalogEntry;

    /**
     * Load the definition's work in progress, as it was last saved.
     *
     * @param   SiteContext  $site        Site the definition must belong to.
     * @param   string       $identifier  The definition's UUID or its handle.
     *
     * @return  ?DefinitionDraft  The stored draft with the revision a further write must quote, or null
     *          when this site holds no such definition or its draft was consumed by a publication.
     *
     * @since   2.0.0
     */
    public function draft(SiteContext $site, string $identifier): ?DefinitionDraft;

    /**
     * Load one published version, defaulting to the one the catalog currently serves.
     *
     * @param   SiteContext  $site        Site the definition must belong to.
     * @param   string       $identifier  The definition's UUID or its handle.
     * @param   ?int         $version     Version to load, or null for whichever version the head publishes.
     *
     * @return  ?DefinitionVersionRecord  The version paired with the compatibility plan that produced it,
     *          or null when this site holds no such definition or never published that version.
     *
     * @since   2.0.0
     */
    public function published(SiteContext $site, string $identifier, ?int $version = null): ?DefinitionVersionRecord;

    /**
     * Load exact published versions for a bounded set of definition UUIDs in batches.
     *
     * Generated navigation and contract discovery use this instead of issuing one version query per
     * catalog entry. Implementations may split the request into driver-safe chunks, but must keep query
     * count proportional to chunks rather than definitions and must return only rows from the supplied site.
     *
     * @param   SiteContext         $site      Site every requested definition must belong to.
     * @param   array<string, int>  $versions  Definition UUID to exact positive published version, at most
     *          4096 unique entries.
     *
     * @return  array<string, DefinitionVersionRecord>  Records keyed by definition UUID; absent versions
     *          are omitted.
     *
     * @since   2.0.0
     */
    public function publishedBatch(SiteContext $site, array $versions): array;

    /**
     * List every version of one definition that was ever published.
     *
     * @param   SiteContext  $site        Site the definition must belong to.
     * @param   string       $identifier  The definition's UUID or its handle.
     *
     * @return  list<DefinitionVersionRecord>  Newest version first; empty when this site holds no such
     *          definition or it has never been published.
     *
     * @since   2.0.0
     */
    public function history(SiteContext $site, string $identifier): array;

    /**
     * Write the draft for a handle, creating its catalog entry when this is the first save.
     *
     * The expected revision is the caller's proof that it composed the change against what is stored now:
     * null claims the handle has no catalog entry yet, and any other value has to equal the stored draft
     * revision exactly. The globally unique definition identity, catalog site, handle and owner are settled
     * when the entry is created and cannot be moved by a later save, so a handle stays attributable to the
     * same catalog and whoever first introduced it.
     *
     * @param   EntityTypeDefinition  $definition        Version-zero draft to store, carrying its own site,
     *          handle and owner.
     * @param   string                $actorId           Actor recorded as having last touched the draft.
     * @param   DateTimeImmutable     $now               Instant recorded against the write.
     * @param   ?int                  $expectedRevision  Draft revision the change was composed against, or
     *          null when the caller expects to be creating the definition.
     *
     * @return  DefinitionDraft  The stored draft at its new revision, which the next write must quote.
     *
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When the submitted identity
     *          is already claimed under another site, handle or owner.
     * @throws  BusinessDefinitionRevisionConflict  When the stored draft is not at the expected revision, or
     *          another writer created or advanced the same handle or definition identity first.
     *
     * @since   2.0.0
     */
    public function saveDraft(
        EntityTypeDefinition $definition,
        string $actorId,
        DateTimeImmutable $now,
        ?int $expectedRevision,
    ): DefinitionDraft;

    /**
     * Promote the stored draft to a published version and retire the one it replaces.
     *
     * The plan is stored beside the bytes it describes, so a later reader can see what publishing this
     * version cost without recomputing it against definitions that have since moved on. Publication
     * consumes the draft — the handle is left with no work in progress — and marks the version it replaced
     * as superseded, leaving that version's bytes untouched.
     *
     * @param   EntityTypeDefinition  $definition             Definition already advanced to the version the
     *          plan targets, whose checksum the plan names.
     * @param   CompatibilityPlan     $plan                   Plan analysed for exactly these bytes.
     * @param   string                $actorId                Actor recorded as the publisher.
     * @param   DateTimeImmutable     $now                    Instant recorded as the publication time.
     * @param   int                   $expectedDraftRevision  Draft revision being published, as the caller
     *          last read it.
     *
     * @return  DefinitionVersionRecord  The stored version, published and paired with its plan.
     *
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When the supplied version
     *          moves the stored definition's catalog site, handle or owner.
     * @throws  BusinessDefinitionRevisionConflict  When the stored draft is no longer at the expected
     *          revision, so another writer changed it after the plan was analysed.
     *
     * @since   2.0.0
     */
    public function publish(
        EntityTypeDefinition $definition,
        CompatibilityPlan $plan,
        string $actorId,
        DateTimeImmutable $now,
        int $expectedDraftRevision,
    ): DefinitionVersionRecord;

    /**
     * Move one published version to a later lifecycle state.
     *
     * Never rewrites published bytes: only that version's status changes, plus the catalog head's
     * publication state when the version being moved is the one the head serves.
     *
     * @param   SiteContext        $site        Site the definition must belong to.
     * @param   string             $identifier  The definition's UUID or its handle.
     * @param   int                $version     Published version whose lifecycle state is changing.
     * @param   DefinitionStatus   $status      State to move it to, such as superseded or deprecated.
     * @param   DateTimeImmutable  $now         Instant recorded against the catalog head.
     *
     * @return  DefinitionVersionRecord  The version reloaded in its new state.
     *
     * @since   2.0.0
     */
    public function changeStatus(
        SiteContext $site,
        string $identifier,
        int $version,
        DefinitionStatus $status,
        DateTimeImmutable $now,
    ): DefinitionVersionRecord;

    /**
     * Flip the availability of everything one extension owns, without republishing any of it.
     *
     * Enabling or disabling an installed extension is not a publication: its versions keep their bytes,
     * their numbering and their history, and only whether the runtime may use them changes. The same flag
     * covers the field types the extension contributed, since its definitions cannot be read without them.
     * The switch is owner-wide and crosses sites, because an extension is installed once per installation.
     *
     * @param   string             $ownerIdentifier  Owning extension, as `vendor/name`.
     * @param   bool               $active           Whether its definitions become available again.
     * @param   DateTimeImmutable  $now              Instant recorded against the affected rows.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function setOwnerActive(string $ownerIdentifier, bool $active, DateTimeImmutable $now): void;
}
