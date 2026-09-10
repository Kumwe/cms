<?php

declare(strict_types=1);

namespace Kumwe\App\Content\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Content\Domain\ContentEntry;
use Kumwe\App\Content\Domain\ContentRevision;
use Kumwe\App\Content\Domain\ContentStatus;
use Kumwe\App\Content\Domain\ExpectedVersion;
use Kumwe\App\Content\Domain\JsonSchemaValidator;
use Kumwe\App\Content\Domain\PublicationWindow;
use Kumwe\App\Extension\Contribution\OwnedRuntimeContributionRegistry;
use Kumwe\App\Extension\Contribution\TranslationGroupDeclaration;
use Kumwe\Extension\Spi\Contribution\TranslationSetItemAssociation;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Localization\Domain\LocaleTag;
use Kumwe\App\Workflow\Domain\Workflow;
use LogicException;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * The one use-case surface for reading and changing content entries.
 *
 * Every mutation here follows the same sequence: authorize the actor against the exact resource,
 * validate the payload against the content type's published schema, apply the change to the domain
 * entry under the version the caller believed it held, then commit the row, its revision snapshot and
 * its audit event inside a single transaction. Nothing else may write content, which is what keeps
 * optimistic concurrency, revision history and the audit trail from drifting apart.
 *
 * Reads are filtered per record rather than in SQL, because whether an actor may see an entry is a
 * policy question the gateway answers; that is why `list()` and `browse()` over-fetch in batches and
 * count only the records that survive. The service also adapts to what it is given: a
 * `SiteScopedContentRepository` earns the site-bounded lookups, a `ContentSearchRepository` unlocks
 * `browse()`, and the content model collaborators are optional so a minimal installation still runs on
 * the built-in page type and the built-in workflow.
 *
 * @since  2.0.0
 */
final readonly class ContentService
{
    /**
     * Workflow recorded against entries governed by the built-in editorial lifecycle.
     *
     * Stamped on records created without a published `WorkflowDefinition`, so an installation running
     * no content model still names a stable workflow instead of leaving the column meaningless.
     *
     * @var    string
     * @since  2.0.0
     */
    public const CORE_WORKFLOW_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bb401';

    /**
     * Content type assumed for an ordinary page when the caller names none.
     *
     * Administrator handlers compare against it to tell plain pages apart from entries of a
     * site-defined type, which is how the settings and navigation screens offer only real pages.
     *
     * @var    string
     * @since  2.0.0
     */
    public const CORE_PAGE_TYPE_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bb402';

    /**
     * Wire the collaborators every content read and mutation depends on.
     *
     * The last two arguments are optional and travel together in practice: without a model repository
     * there are no published content types or workflows to consult, so entries fall back to the
     * built-in page type and the injected `Workflow`, and no schema validation is performed.
     *
     * @param ContentRepository $repository Store holding entries and their revision trail.
     * @param AuditRecorder $audit Sink each mutation's audit event is written to.
     * @param TransactionManager $transactions Boundary committing row, revision and audit as one.
     * @param ClockInterface $clock Source of every timestamp stamped on stored data.
     * @param Workflow $workflow Built-in lifecycle used when no definition applies.
     * @param AuthorizationGateway $authorization Decides whether the actor may read or change an entry.
     * @param ResourceSiteOwnershipWriter $ownership Records which site owns a newly created entry.
     * @param ?ContentModelRepository $models Published types and workflows, or null to skip them.
     * @param ?JsonSchemaValidator $schemas Body validator; one is built per call when null.
     * @param ?TranslationGroupRepository $translations Store recording which logical item a locale belongs
     *         to and the fallback it declares; null leaves `translate()` unavailable.
     * @param  ?OwnedRuntimeContributionRegistry  $contributedTranslationSets  Active extension-declared
     *         translation sets; null leaves `translateContributed()` unavailable.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ContentRepository $repository,
        private AuditRecorder $audit,
        private TransactionManager $transactions,
        private ClockInterface $clock,
        private Workflow $workflow,
        private AuthorizationGateway $authorization,
        private ResourceSiteOwnershipWriter $ownership,
        private ?ContentModelRepository $models = null,
        private ?JsonSchemaValidator $schemas = null,
        private ?TranslationGroupRepository $translations = null,
        private ?OwnedRuntimeContributionRegistry $contributedTranslationSets = null,
    ) {
    }

    /**
     * List the entries of the actor's site that the actor is allowed to read.
     *
     * The limit counts readable records, not stored rows: the repository is walked in batches and each
     * record is put to the gateway individually, so a caller asking for a hundred receives a hundred
     * only when that many survive the check. A short result therefore means the store ran out, never
     * that permission trimmed the page.
     *
     * @param   ExecutionContext  $context         Actor and site the listing is performed for.
     * @param   int               $limit           Readable records to return; between 1 and 500.
     * @param   bool              $includeDeleted  Whether trashed entries join the result.
     *
     * @return  list<ContentRecord>  Readable records, most recently updated first.
     *
     * @throws  InvalidArgumentException  When the limit falls outside 1 to 500.
     *
     * @since   2.0.0
     */
    public function list(ExecutionContext $context, int $limit = 100, bool $includeDeleted = false): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('The content result limit must be between 1 and 500.');
        }
        $result = [];
        $offset = 0;
        $pageSize = min(500, max(50, $limit));

        do {
            $page = $this->repository instanceof SiteScopedContentRepository
                ? $this->repository->allForSite($context->site(), $pageSize, $includeDeleted, $offset)
                : $this->repository->all($pageSize, $includeDeleted, $offset);
            foreach ($page as $record) {
                if (
                    $this->authorization->decide(
                        $context,
                        Capability::fromString('content.read'),
                        AuthorizationResource::item('content', $record->entry->id()),
                    )->allowed
                ) {
                    $result[] = $record;
                    if (count($result) === $limit) {
                        return $result;
                    }
                }
            }
            $offset += count($page);
        } while (count($page) === $pageSize);

        return $result;
    }

    /**
     * Resolve one screen of the administrator content browser.
     *
     * Because readability is decided per record rather than in SQL, the store's offset cannot be the
     * user's offset: batches are fetched, unreadable records dropped, the records belonging to earlier
     * pages skipped, and one record more than the page holds is taken to learn whether a next page
     * exists. That is also why the result carries neighbour flags rather than a total count.
     *
     * @param   ExecutionContext    $context  Actor and site the browse is performed for.
     * @param   ContentBrowseQuery  $query    Validated filters, ordering and page the browser asked for.
     *
     * @return  ContentPage  The readable records for the requested page, with its neighbour flags.
     *
     * @throws  \RuntimeException  When the configured repository cannot answer browse queries.
     *
     * @since   2.0.0
     */
    public function browse(ExecutionContext $context, ContentBrowseQuery $query): ContentPage
    {
        if (!$this->repository instanceof ContentSearchRepository) {
            throw new \RuntimeException('The configured content repository does not support administrator browsing.');
        }

        $skip = ($query->page - 1) * $query->perPage;
        $authorizedSeen = 0;
        $offset = 0;
        $batchSize = 100;
        $items = [];
        do {
            $batch = $this->repository->searchForSite($context->site(), $query, $batchSize, $offset);
            foreach ($batch as $record) {
                if (
                    !$this->authorization->decide(
                        $context,
                        Capability::fromString('content.read'),
                        AuthorizationResource::item('content', $record->entry->id()),
                    )->allowed
                ) {
                    continue;
                }
                if ($authorizedSeen++ < $skip) {
                    continue;
                }
                $items[] = $record;
                if (count($items) > $query->perPage) {
                    break 2;
                }
            }
            $offset += count($batch);
        } while (count($batch) === $batchSize);

        $hasNext = count($items) > $query->perPage;
        if ($hasNext) {
            array_pop($items);
        }

        return new ContentPage($items, $query, $query->page > 1, $hasNext);
    }

    /**
     * Load one entry the actor is allowed to read, failing rather than returning null.
     *
     * Absent, trashed when live entries were asked for, and owned by another site all surface as the
     * same `ContentNotFound`, so the difference cannot be used to probe for entries the actor may not
     * see. Every mutation below reads through here first, which is what gives them all one shared
     * definition of "in reach".
     *
     * @param   ExecutionContext  $context         Actor and site the lookup is performed for.
     * @param   string            $id              UUID of the content entry to load.
     * @param   bool              $includeDeleted  Whether a trashed entry still counts as found.
     *
     * @return  ContentRecord  The stored record, with its site and pinned definition versions.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When `content.read` is refused.
     * @throws  ContentNotFound  When no entry matches within reach of the context.
     *
     * @since   2.0.0
     */
    public function get(ExecutionContext $context, string $id, bool $includeDeleted = false): ContentRecord
    {
        $this->authorize($context, 'content.read', $id);
        $record = $this->repository instanceof SiteScopedContentRepository
            ? $this->repository->findForSite($context->site(), $id, $includeDeleted)
            : $this->repository->find($id, $includeDeleted);

        return $record ?? throw new ContentNotFound($id);
    }

    /**
     * Find the entry a public URL segment resolves to, if it is visible right now.
     *
     * This is the public delivery lookup and deliberately takes no `ExecutionContext`: visibility is
     * decided by workflow state and publication window against the service clock, never by who is
     * asking, so an anonymous visitor and a logged-in editor are shown the same thing.
     *
     * @param   string        $slug  Route segment taken from the public URL.
     * @param   ?SiteContext  $site  Site to resolve within, or null for the default site.
     *
     * @return  ?ContentRecord  Null when the slug is unknown to the site or the entry is not visible now.
     *
     * @since   2.0.0
     */
    public function publishedBySlug(string $slug, ?SiteContext $site = null): ?ContentRecord
    {
        return $this->repository instanceof SiteScopedContentRepository
            ? $this->repository->findPublishedBySlugForSite(
                $site ?? SiteContext::default(),
                $slug,
                $this->clock->now(),
            )
            : $this->repository->findPublishedBySlug($slug, $this->clock->now());
    }

    /**
     * Find an entry by identifier, if it is publicly visible right now.
     *
     * The identifier-keyed twin of `publishedBySlug()`, used where content is referenced by a stored
     * pointer rather than a URL — a configured homepage or a navigation target — so that renaming an
     * entry's slug cannot break the reference. Visibility is judged the same way, unauthenticated.
     *
     * @param   string        $id    UUID of the content entry.
     * @param   ?SiteContext  $site  Site to resolve within, or null for the default site.
     *
     * @return  ?ContentRecord  Null when the entry is out of reach or not visible at this instant.
     *
     * @since   2.0.0
     */
    public function publishedById(string $id, ?SiteContext $site = null): ?ContentRecord
    {
        return $this->repository instanceof SiteScopedContentRepository
            ? $this->repository->findPublishedByIdForSite(
                $site ?? SiteContext::default(),
                $id,
                $this->clock->now(),
            )
            : $this->repository->findPublishedById($id, $this->clock->now());
    }

    /**
     * Create an entry of a content type, validating it and capturing its first revision.
     *
     * The content type decides two things at once: the schema the body must satisfy, and the workflow
     * the entry is governed by — so an entry of a type whose workflow is published opens in that
     * workflow's initial state rather than `Draft`. Both definition versions are pinned onto the
     * record so a later republish cannot retroactively re-validate or re-route it. The row, its site
     * ownership marker, the first revision snapshot and the audit event are committed together.
     *
     * @param   ExecutionContext         $context                Actor and site the entry is created for.
     * @param   string                   $title                  Title as the author typed it.
     * @param   string                   $slug                   Route segment the public URL will carry.
     * @param   array<array-key, mixed>  $data                   Entry body, checked against the type's schema.
     * @param   ?PublicationWindow       $window                 Visibility period, or null for an unbounded one.
     * @param   string                   $contentTypeIdentifier  UUID or handle of the type to author against.
     * @param   ?string                  $entryId                Pre-allocated UUID for the new entry, or null to
     *          mint one; a caller that promised an identity before persisting (the contextual Studio session)
     *          keeps that promise here.
     *
     * @return  ContentRecord  The stored record at version one, definition versions pinned.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When `content.create` is refused.
     * @throws  InvalidArgumentException  When the slug is reserved, or a domain rule rejects the entry.
     * @throws  ContentModelNotFound  When the content type, or the workflow it names, is not published here.
     * @throws  \Kumwe\App\Content\Domain\InvalidContentData  When the body does not satisfy the type's schema.
     *
     * @since   2.0.0
     */
    public function create(
        ExecutionContext $context,
        string $title,
        string $slug,
        array $data,
        ?PublicationWindow $window = null,
        string $contentTypeIdentifier = self::CORE_PAGE_TYPE_ID,
        ?string $entryId = null,
    ): ContentRecord {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('content.create'),
            AuthorizationResource::collection('content'),
        );
        $this->assertPublicSlug($slug);
        if ($entryId !== null && !Uuid::isValid($entryId)) {
            throw new InvalidArgumentException('A pre-allocated content entry identifier must be a UUID.');
        }
        $type = $this->models === null ? null : $this->models->contentType($context->site(), $contentTypeIdentifier);
        if ($this->models !== null && $type === null) {
            throw new ContentModelNotFound('content type', $contentTypeIdentifier);
        }
        $workflowDefinition = $type === null || $this->models === null
            ? null
            : $this->models->workflow($context->site(), $type->workflowId, $type->workflowVersion);
        if ($type !== null && $workflowDefinition === null) {
            throw new ContentModelNotFound('workflow', $type->workflowId, $type->workflowVersion);
        }
        if ($type !== null) {
            ($this->schemas ?? new JsonSchemaValidator())->assertValid($type->schema(), $data);
        }
        $now = $this->clock->now();
        $entry = ContentEntry::create(
            $entryId ?? Uuid::uuid7()->toString(),
            $title,
            $slug,
            $data,
            $workflowDefinition === null ? ContentStatus::Draft : $workflowDefinition->initialState(),
            $window,
        );
        $record = new ContentRecord(
            $entry,
            $type === null ? self::CORE_PAGE_TYPE_ID : $type->id,
            $workflowDefinition === null ? self::CORE_WORKFLOW_ID : $workflowDefinition->id,
            $now,
            $now,
            null,
            $type === null ? 1 : $type->version,
            $workflowDefinition === null ? 1 : $workflowDefinition->version,
            $context->site()->identifier(),
        );

        return $this->transactions->transactional(function () use ($record, $context, $now): ContentRecord {
            $this->repository->insert($record);
            $this->ownership->record(
                AuthorizationResource::item('content', $record->entry->id()),
                $context->site(),
            );
            $this->captureRevision($record->entry, $now);
            $this->recordAudit($context->actorId(), 'content.create', $record->entry, $now);

            return $record;
        });
    }

    /**
     * Replace an entry's authored content, provided nobody has moved it since the caller read it.
     *
     * The body is validated against the exact content type version the entry was authored against —
     * not the current head — so republishing a stricter schema never blocks edits to entries written
     * before it. Editing does not move the entry through its workflow; use `transition()` for that.
     * The new row, its revision snapshot and the audit event are committed together.
     *
     * @param   ExecutionContext         $context          Actor and site the update is performed for.
     * @param   string                   $id               UUID of the content entry to revise.
     * @param   int                      $expectedVersion  Version the editor loaded and believes it is changing.
     * @param   string                   $title            Replacement title.
     * @param   string                   $slug             Replacement route segment.
     * @param   array<array-key, mixed>  $data             Replacement body, checked against the pinned schema.
     * @param   ?PublicationWindow       $window           New visibility period, or null to keep the current one.
     *
     * @return  ContentRecord  The stored record, one version higher.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When `content.update` is refused.
     * @throws  InvalidArgumentException  When the slug is reserved, or a domain rule rejects the entry.
     * @throws  ContentNotFound  When no entry matches within reach of the context.
     * @throws  ContentModelNotFound  When the pinned content type version is no longer published.
     * @throws  \Kumwe\App\Content\Domain\InvalidContentData  When the body does not satisfy the pinned schema.
     * @throws  \Kumwe\App\Content\Domain\VersionConflict  When another writer moved the entry on first.
     *
     * @since   2.0.0
     */
    public function update(
        ExecutionContext $context,
        string $id,
        int $expectedVersion,
        string $title,
        string $slug,
        array $data,
        ?PublicationWindow $window = null,
    ): ContentRecord {
        $this->authorize($context, 'content.update', $id);
        $this->assertPublicSlug($slug);
        $stored = $this->get($context, $id);
        $type = $this->models === null
            ? null
            : $this->models->contentType($context->site(), $stored->contentTypeId, $stored->contentTypeVersion);
        if ($this->models !== null && $type === null) {
            throw new ContentModelNotFound('content type', $stored->contentTypeId, $stored->contentTypeVersion);
        }
        if ($type !== null) {
            ($this->schemas ?? new JsonSchemaValidator())->assertValid($type->schema(), $data);
        }
        $expected = new ExpectedVersion($expectedVersion);
        $entry = $stored->entry->revise($expected, $title, $slug, $data, $window);

        $now = $this->clock->now();
        $updated = $stored->withEntry($entry, $now);

        return $this->transactions->transactional(function () use (
            $updated,
            $expectedVersion,
            $context,
            $now,
        ): ContentRecord {
            $this->repository->update($updated, $expectedVersion);
            $this->captureRevision($updated->entry, $now);
            $this->recordAudit($context->actorId(), 'content.update', $updated->entry, $now);

            return $updated;
        });
    }

    /**
     * Re-pin an entry to another published content type version without changing what it says.
     *
     * Studio's reusable-type outcomes create an immutable successor type version (or a new type) from
     * the design an author composed while editing this very item; the item then adopts that exact
     * coordinate so its values, workflow state and revision history stay intact while its validation
     * contract moves. The stored body is validated against the adopted schema first, the workflow the
     * entry follows must be the one the adopted version pins, and the entry's own optimistic version
     * is left untouched because nothing the author wrote changed. The row and its audit event are
     * committed together.
     *
     * @param   ExecutionContext  $context             Actor and site the adoption is performed for.
     * @param   string            $id                  UUID of the content entry to re-pin.
     * @param   int               $expectedVersion     Version the editor loaded and believes it is changing.
     * @param   string            $contentTypeId       UUID of the content type to adopt.
     * @param   int               $contentTypeVersion  Exact published version of that type to adopt.
     *
     * @return  ContentRecord  The stored record, pinned to the adopted type and workflow versions.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When `content.update` is refused.
     * @throws  ContentNotFound  When no entry matches within reach of the context.
     * @throws  ContentModelNotFound  When the adopted content type version is not published here.
     * @throws  InvalidArgumentException  When the adopted version follows a different workflow.
     * @throws  \Kumwe\App\Content\Domain\InvalidContentData  When the body does not satisfy the adopted schema.
     * @throws  \Kumwe\App\Content\Domain\VersionConflict  When another writer moved the entry on first.
     *
     * @since   2.0.0
     */
    public function adoptContentType(
        ExecutionContext $context,
        string $id,
        int $expectedVersion,
        string $contentTypeId,
        int $contentTypeVersion,
    ): ContentRecord {
        $this->authorize($context, 'content.update', $id);
        $stored = $this->get($context, $id);
        $type = $this->models?->contentType($context->site(), $contentTypeId, $contentTypeVersion);
        if ($type === null) {
            throw new ContentModelNotFound('content type', $contentTypeId, $contentTypeVersion);
        }
        if ($type->workflowId !== $stored->workflowId) {
            throw new InvalidArgumentException('The adopted content type version follows a different workflow.');
        }
        ($this->schemas ?? new JsonSchemaValidator())->assertValid($type->schema(), $stored->entry->data());
        (new ExpectedVersion($expectedVersion))->assertMatches($stored->entry->version());
        $now = $this->clock->now();
        $adopted = new ContentRecord(
            $stored->entry,
            $type->id,
            $type->workflowId,
            $stored->createdAt,
            $now,
            $stored->deletedAt,
            $type->version,
            $type->workflowVersion,
            $stored->siteIdentifier,
        );

        return $this->transactions->transactional(function () use (
            $adopted,
            $expectedVersion,
            $context,
            $now,
        ): ContentRecord {
            $this->repository->adopt($adopted, $expectedVersion);
            $this->recordAudit($context->actorId(), 'content.adopt_type', $adopted->entry, $now);

            return $adopted;
        });
    }

    /**
     * Declare which language an entry is written in, and which logical item it is one locale of.
     *
     * This is how a translation comes into being: the entry being translated is placed in a group, and
     * the newly authored entry in the other language is placed in that same group. Nothing editorial
     * moves — the body, the workflow state and the publication window are all carried across — because
     * saying what language something is in is not a change to it, and a translation going live is its
     * own `transition()`. That separation is exactly what lets English stay published while the
     * language beside it is still drafting.
     *
     * The declared fallback is recorded once, with the group, on the call that creates it. A later call
     * naming the same group leaves the fallback alone, because a fallback that two locales of one item
     * disagreed about would not be a fallback.
     *
     * @param   ExecutionContext  $context             Actor and site the change is performed for.
     * @param   string            $id                  UUID of the content entry to place.
     * @param   int               $expectedVersion     Version the editor loaded and believes it is changing.
     * @param   LocaleTag         $locale              Language this entry is declared to be written in.
     * @param   string            $translationGroupId  UUID of the logical item across locales.
     * @param   ?LocaleTag        $fallback            Locale served when the reader's has nothing published;
     *          defaults to this entry's own locale, which is what the first locale of an item declares.
     *
     * @return  ContentRecord  The stored record, one version higher, carrying its locale and group.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When `content.update` is refused.
     * @throws  ContentNotFound  When no entry matches within reach of the context.
     * @throws  \LogicException  When no translation-group store is wired.
     * @throws  \Kumwe\App\Content\Domain\VersionConflict  When another writer moved the entry on first.
     *
     * @since   2.0.0
     */
    public function translate(
        ExecutionContext $context,
        string $id,
        int $expectedVersion,
        LocaleTag $locale,
        string $translationGroupId,
        ?LocaleTag $fallback = null,
    ): ContentRecord {
        return $this->attachToGroup($context, $id, $expectedVersion, $locale, $translationGroupId, $fallback);
    }

    /**
     * Place an extension-contributed entry into the translation set its package declared at admission.
     *
     * This is the runtime half of the extension content-translation contract. The signed declaration
     * says which sets a package publishes and in which languages; this call is where one stored entry
     * actually joins such a set, and core resolves the association against the active contribution
     * registry before anything is written. An association naming a set its claimed owner has not
     * actively declared is refused, as is a locale the declaration does not carry — the declaration's
     * locale list is a closed claim, and storage is where that claim is enforced against real items.
     *
     * The runtime group and the fallback are both taken from the resolved declaration rather than from
     * the caller: the group is the association's deterministic generation-one derivation, and the
     * fallback is the declared one, restated on every call so a group whose stored declaration ever
     * disagreed with the manifest would refuse the attachment instead of drifting quietly. The audit
     * event carries the owner and set beside the entry, so the association itself is reconstructable
     * from the trail.
     *
     * @param   ExecutionContext               $context          Actor and site the change is performed for.
     * @param   string                         $id               UUID of the content entry to place.
     * @param   int                            $expectedVersion  Version the editor loaded and believes it
     *          is changing.
     * @param   LocaleTag                      $locale           Language this entry is declared to be
     *          written in; must be one the set declares.
     * @param   TranslationSetItemAssociation  $association      Owner-bound claim naming the declared set.
     *
     * @return  ContentRecord  The stored record, one version higher, carrying its locale and group.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When `content.update` is refused.
     * @throws  ContentNotFound  When no entry matches within reach of the context.
     * @throws  InvalidArgumentException  When the set is not an active declaration of the association's
     *          owner, or the locale is not one the declaration carries.
     * @throws  \LogicException  When no contribution registry or translation-group store is wired.
     * @throws  \Kumwe\App\Content\Domain\InvalidTranslationGroup  When the declared fallback contradicts
     *          the group's stored declaration.
     * @throws  \Kumwe\App\Content\Domain\VersionConflict  When another writer moved the entry on first.
     *
     * @since   2.0.0
     */
    public function translateContributed(
        ExecutionContext $context,
        string $id,
        int $expectedVersion,
        LocaleTag $locale,
        TranslationSetItemAssociation $association,
    ): ContentRecord {
        $this->authorize($context, 'content.update', $id);
        $sets = $this->contributedTranslationSets
            ?? throw new LogicException('Contributed content translation requires the contribution registry.');
        $declaration = $sets->definition($association->owner, $association->translationSet);
        if (!$declaration instanceof TranslationGroupDeclaration) {
            throw new InvalidArgumentException(sprintf(
                'Translation set %s is not an active declaration of %s.',
                $association->translationSet,
                $association->owner->identifier(),
            ));
        }
        if (!$declaration->publishes($locale)) {
            throw new InvalidArgumentException(sprintf(
                'Translation set %s does not declare locale %s.',
                $association->translationSet,
                $locale->toString(),
            ));
        }

        return $this->attachToGroup(
            $context,
            $id,
            $expectedVersion,
            $locale,
            $association->groupIdForSite($context->site()->identifier()),
            LocaleTag::fromString($declaration->fallbackLocale),
            [
                'owner' => $association->owner->identifier(),
                'translation_set' => $association->translationSet,
            ],
        );
    }

    /**
     * Attach one entry to a translation group, committing declaration, row, revision and audit as one.
     *
     * The shared tail of `translate()` and `translateContributed()`: by the time this runs, the caller
     * has decided which group the entry joins and which fallback governs it, and what remains is the
     * one transaction both paths must agree on exactly — a contributed attachment that committed
     * differently from a core one would be a second translation model.
     *
     * @param   ExecutionContext      $context             Actor and site the change is performed for.
     * @param   string                $id                  UUID of the content entry to place.
     * @param   int                   $expectedVersion     Version the editor loaded and believes it is
     *          changing.
     * @param   LocaleTag             $locale              Language this entry is declared to be written in.
     * @param   string                $translationGroupId  UUID of the logical item across locales.
     * @param   ?LocaleTag            $fallback            Fallback to verify or record; null leaves an
     *          existing declaration alone.
     * @param   array<string, mixed>  $metadata            Extra audit facts merged in beside the version.
     *
     * @return  ContentRecord  The stored record, one version higher, carrying its locale and group.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When `content.update` is refused.
     * @throws  ContentNotFound  When no entry matches within reach of the context.
     * @throws  \LogicException  When no translation-group store is wired.
     * @throws  \Kumwe\App\Content\Domain\VersionConflict  When another writer moved the entry on first.
     *
     * @since   2.0.0
     */
    private function attachToGroup(
        ExecutionContext $context,
        string $id,
        int $expectedVersion,
        LocaleTag $locale,
        string $translationGroupId,
        ?LocaleTag $fallback,
        array $metadata = [],
    ): ContentRecord {
        $this->authorize($context, 'content.update', $id);
        $translations = $this->translations
            ?? throw new LogicException('Content translation requires a translation group store.');
        $stored = $this->get($context, $id);
        $entry = $stored->entry->translate(new ExpectedVersion($expectedVersion), $locale, $translationGroupId);

        $now = $this->clock->now();
        $updated = $stored->withEntry($entry, $now);

        return $this->transactions->transactional(function () use (
            $updated,
            $expectedVersion,
            $context,
            $translations,
            $translationGroupId,
            $locale,
            $fallback,
            $now,
            $metadata,
        ): ContentRecord {
            $translations->declareGroup($context->site(), $translationGroupId, $locale, $fallback);
            $translations->guardAttachment($context->site(), $translationGroupId, $updated->entry->id());
            $this->repository->update($updated, $expectedVersion);
            $this->captureRevision($updated->entry, $now);
            $this->recordAudit($context->actorId(), 'content.translate', $updated->entry, $now, $metadata);

            return $updated;
        });
    }

    /**
     * Move an entry to another workflow state, if the workflow allows the edge and the actor may travel it.
     *
     * Two separate checks apply and both must pass: the workflow decides whether the edge exists at
     * all, and the capability that edge declares decides whether this actor may take it — which is why
     * publishing can be denied to someone who is free to submit for review. The capability is resolved
     * from the entry's own pinned workflow version, so a republished workflow cannot change the rules
     * under an entry mid-review.
     *
     * @param   ExecutionContext      $context          Actor and site the transition is performed for.
     * @param   string                $id               UUID of the content entry to move.
     * @param   int                   $expectedVersion  Version the actor loaded and believes it is changing.
     * @param   ContentStatus|string  $target           State to move to, as an enum case or a state key.
     *
     * @return  ContentRecord  The stored record, one version higher and in the new state.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the edge's capability is refused.
     * @throws  ContentNotFound  When no entry matches within reach of the context.
     * @throws  ContentModelNotFound  When the pinned workflow version is no longer published.
     * @throws  \DomainException  When a custom state is named but no persisted workflow is configured.
     * @throws  \Kumwe\App\Workflow\Domain\InvalidWorkflowTransition  When the workflow declares no such edge.
     * @throws  \Kumwe\App\Content\Domain\VersionConflict  When another writer moved the entry on first.
     *
     * @since   2.0.0
     */
    public function transition(
        ExecutionContext $context,
        string $id,
        int $expectedVersion,
        ContentStatus|string $target,
    ): ContentRecord {
        $this->authorize($context, 'content.read', $id);
        $stored = $this->get($context, $id);
        $required = $this->transitionCapabilityForRecord($context, $stored, $target);
        $this->authorize($context, $required->value(), $id);
        $definition = $this->models === null
            ? null
            : $this->models->workflow($context->site(), $stored->workflowId, $stored->workflowVersion);
        $entry = $stored->entry->transition(
            new ExpectedVersion($expectedVersion),
            $definition === null ? $this->workflow : new Workflow($definition),
            $target,
        );
        $now = $this->clock->now();
        $updated = $stored->withEntry($entry, $now);

        return $this->transactions->transactional(function () use (
            $updated,
            $expectedVersion,
            $context,
            $now,
        ): ContentRecord {
            $this->repository->update($updated, $expectedVersion);
            $this->captureRevision($updated->entry, $now);
            $this->recordAudit($context->actorId(), 'content.transition', $updated->entry, $now, [
                'status' => $updated->entry->statusKey(),
            ]);

            return $updated;
        });
    }

    /**
     * Report which capability a given transition of this entry would demand.
     *
     * The editor asks this to decide whether to render a button at all, so the answer must come from
     * the same resolution `transition()` uses; asking here and acting there cannot disagree. Being
     * told the capability is not being granted it — the transition still authorizes on its own.
     *
     * @param   ExecutionContext      $context  Actor and site the question is asked for.
     * @param   string                $id       UUID of the content entry the move would apply to.
     * @param   ContentStatus|string  $target   State the move would lead to.
     *
     * @return  Capability  The capability the actor would need to make this exact move.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When `content.read` is refused.
     * @throws  ContentNotFound  When no entry matches within reach of the context.
     * @throws  ContentModelNotFound  When the pinned workflow version is no longer published.
     * @throws  \DomainException  When a custom state is named but no persisted workflow is configured.
     * @throws  \Kumwe\App\Workflow\Domain\InvalidWorkflowTransition  When the workflow declares no such edge.
     *
     * @since   2.0.0
     */
    public function transitionCapability(
        ExecutionContext $context,
        string $id,
        ContentStatus|string $target,
    ): Capability {
        $this->authorize($context, 'content.read', $id);

        return $this->transitionCapabilityForRecord($context, $this->get($context, $id), $target);
    }

    /**
     * Resolve the capability an already-loaded record's transition demands.
     *
     * A persisted workflow answers directly from the edge it declares. Without one, the built-in
     * lifecycle is mapped onto the fixed editorial capabilities below, and because those are written
     * in terms of `ContentStatus` a custom state key cannot be answered for — an installation using
     * custom states must be running a persisted workflow, and is told so rather than silently
     * falling through to `content.update`.
     *
     * @param   ExecutionContext      $context  Actor and site whose model definitions are consulted.
     * @param   ContentRecord         $stored   Record as currently stored, carrying its pinned versions.
     * @param   ContentStatus|string  $target   State the move would lead to.
     *
     * @return  Capability  The capability required for this move under the workflow in force.
     *
     * @throws  ContentModelNotFound  When the pinned workflow version is no longer published.
     * @throws  \DomainException  When a custom state is named but no persisted workflow is configured.
     * @throws  \Kumwe\App\Workflow\Domain\InvalidWorkflowTransition  When the workflow declares no such edge.
     *
     * @since   2.0.0
     */
    private function transitionCapabilityForRecord(
        ExecutionContext $context,
        ContentRecord $stored,
        ContentStatus|string $target,
    ): Capability {
        $definition = $this->models === null
            ? null
            : $this->models->workflow($context->site(), $stored->workflowId, $stored->workflowVersion);
        if ($definition !== null) {
            return $definition->transition(
                $stored->entry->statusKey(),
                $target instanceof ContentStatus ? $target->value : $target,
            )->requiredCapability;
        }
        if ($this->models !== null) {
            throw new ContentModelNotFound('workflow', $stored->workflowId, $stored->workflowVersion);
        }

        $from = $stored->entry->status();
        if (!$from instanceof ContentStatus || !$target instanceof ContentStatus) {
            throw new \DomainException('A persisted workflow definition is required for custom states.');
        }

        return Capability::fromString(match (true) {
            $from === ContentStatus::Draft && $target === ContentStatus::Review => 'content.submit',
            $from === ContentStatus::Review && $target === ContentStatus::Draft => 'content.review',
            $target === ContentStatus::Published => 'content.publish',
            $from === ContentStatus::Published && $target === ContentStatus::Draft => 'content.unpublish',
            $target === ContentStatus::Archived => 'content.archive',
            $from === ContentStatus::Archived && $target === ContentStatus::Draft => 'content.restore',
            default => 'content.update',
        });
    }

    /**
     * Move an entry to the trash without destroying it.
     *
     * Trashing marks the row rather than deleting it, so the entry keeps its body, its workflow state
     * and its whole revision trail and `restore()` is a plain undo. It also does not capture a
     * revision — nothing an author wrote changed — but it is audited, and the record returned is the
     * re-read one carrying the deletion marker.
     *
     * @param   ExecutionContext  $context          Actor and site the entry is trashed for.
     * @param   string            $id               UUID of the content entry to trash.
     * @param   int               $expectedVersion  Version the actor loaded and believes it is trashing.
     *
     * @return  ContentRecord  The trashed record, its `deletedAt` now set.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When `content.delete` is refused.
     * @throws  ContentNotFound  When no entry matches within reach of the context.
     * @throws  \Kumwe\App\Content\Domain\VersionConflict  When another writer moved the entry on first.
     *
     * @since   2.0.0
     */
    public function trash(ExecutionContext $context, string $id, int $expectedVersion): ContentRecord
    {
        $this->authorize($context, 'content.delete', $id);
        $stored = $this->get($context, $id);
        (new ExpectedVersion($expectedVersion))->assertMatches($stored->entry->version());
        $now = $this->clock->now();

        return $this->transactions->transactional(function () use (
            $id,
            $expectedVersion,
            $context,
            $now,
        ): ContentRecord {
            $this->repository->setDeletedAt($id, $expectedVersion, $now, $now);
            $record = $this->get($context, $id, true);
            $this->recordAudit($context->actorId(), 'content.trash', $record->entry, $now);

            return $record;
        });
    }

    /**
     * Bring a trashed entry back, restoring the state and version it was trashed in.
     *
     * Restoring an entry that is already live is a no-op that returns it untouched, without consuming
     * a version or writing an audit event, so a repeated request from an impatient operator is safe.
     * Recovery is exact: the trash marker is the only thing that was ever set.
     *
     * @param   ExecutionContext  $context          Actor and site the entry is restored for.
     * @param   string            $id               UUID of the content entry to restore.
     * @param   int               $expectedVersion  Version the actor loaded and believes it is restoring.
     *
     * @return  ContentRecord  The live record, or the unchanged one when it was never trashed.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When `content.restore` is refused.
     * @throws  ContentNotFound  When no entry matches within reach of the context.
     * @throws  \Kumwe\App\Content\Domain\VersionConflict  When another writer moved the entry on first.
     * @throws  \Kumwe\App\Content\Domain\InvalidTranslationGroup  When restoring a translated entry would
     *          take its group past the live-member ceiling.
     * @throws  LogicException  When translated content is restored without a translation-group store.
     *
     * @since   2.0.0
     */
    public function restore(ExecutionContext $context, string $id, int $expectedVersion): ContentRecord
    {
        $this->authorize($context, 'content.restore', $id);
        $stored = $this->get($context, $id, true);

        if ($stored->deletedAt === null) {
            return $stored;
        }

        (new ExpectedVersion($expectedVersion))->assertMatches($stored->entry->version());
        $now = $this->clock->now();
        $translationGroupId = $stored->entry->translationGroupId();
        $translations = $this->translations;

        return $this->transactions->transactional(function () use (
            $id,
            $expectedVersion,
            $context,
            $now,
            $translationGroupId,
            $translations,
        ): ContentRecord {
            if ($translationGroupId !== null) {
                ($translations ?? throw new LogicException(
                    'Restoring translated content requires a translation group store.',
                ))->guardAttachment($context->site(), $translationGroupId, $id);
            }
            $this->repository->setDeletedAt($id, $expectedVersion, null, $now);
            $record = $this->get($context, $id);
            $this->recordAudit($context->actorId(), 'content.restore', $record->entry, $now);

            return $record;
        });
    }

    /**
     * Append an immutable snapshot of the entry to its revision trail.
     *
     * Always called from inside the caller's transaction, so the trail can never record a version the
     * database ended up rolling back. The revision number is read from the repository rather than
     * derived from the entry version, because trashing and restoring advance one and not the other.
     *
     * @param   ContentEntry       $entry  Entry in the exact shape that was just written.
     * @param   DateTimeImmutable  $time   Instant recorded on the snapshot, shared with the row and audit event.
     *
     * @return  void
     *
     * @throws  \JsonException  When the entry snapshot cannot be encoded for checksumming.
     *
     * @since   2.0.0
     */
    private function captureRevision(ContentEntry $entry, DateTimeImmutable $time): void
    {
        $this->repository->appendRevision(ContentRevision::capture(
            Uuid::uuid7()->toString(),
            $entry,
            $this->repository->nextRevisionNumber($entry->id()),
            $time,
        ));
    }

    /**
     * Demand a capability on one specific entry, throwing when the actor does not hold it.
     *
     * Always item-scoped rather than collection-scoped, so a grant covering some entries never carries
     * across to the one actually named.
     *
     * @param   ExecutionContext  $context  Actor and site the decision is made for.
     * @param   string            $action   Capability name, such as `content.update`.
     * @param   string            $id       UUID of the entry the capability is demanded on.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor lacks the capability.
     *
     * @since   2.0.0
     */
    private function authorize(ExecutionContext $context, string $action, string $id): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString($action),
            AuthorizationResource::item('content', $id),
        );
    }

    /**
     * Refuse a slug that would shadow a system route.
     *
     * Public pages are mounted at the site root, so an entry slugged `administrator` or `api` would
     * compete with the routes those names already own. Comparison is on the trimmed, lowercased slug
     * so no casing or padding trick gets around the list.
     *
     * @param   string  $slug  Slug the author proposed for the entry.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the slug matches a reserved system route.
     *
     * @since   2.0.0
     */
    private function assertPublicSlug(string $slug): void
    {
        if (
            in_array(strtolower(trim($slug)), [
                'administrator',
                'api',
                'assets',
                'health',
                'mcp',
                'media',
                'pages',
            ], true)
        ) {
            throw new InvalidArgumentException('A content slug cannot use a reserved system route.');
        }
    }

    /**
     * Write the audit event for one completed content mutation.
     *
     * Called from inside the mutation's transaction so the trail rolls back with the change it
     * describes. The entry version is always recorded, and callers merge in whatever else makes the
     * decision reconstructable later — the resulting state, for a transition.
     *
     * @param   string                $actorId   Identity credited with the change in the trail.
     * @param   string                $action    Audited action name, such as `content.transition`.
     * @param   ContentEntry          $entry     Entry as it stands after the change, for its id and version.
     * @param   DateTimeImmutable     $time      Instant recorded on the event, shared with the row it describes.
     * @param   array<string, mixed>  $metadata  Extra facts merged in beside the version.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function recordAudit(
        string $actorId,
        string $action,
        ContentEntry $entry,
        DateTimeImmutable $time,
        array $metadata = [],
    ): void {
        $this->audit->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $time,
            $actorId,
            $action,
            'content',
            $entry->id(),
            'success',
            ['version' => $entry->version(), ...$metadata],
        ));
    }
}
