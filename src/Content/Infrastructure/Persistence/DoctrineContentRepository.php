<?php

declare(strict_types=1);

namespace Kumwe\App\Content\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use JsonException;
use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Application\ContentBrowseQuery;
use Kumwe\App\Content\Application\ContentRepository;
use Kumwe\App\Content\Application\ContentSearchRepository;
use Kumwe\App\Content\Application\SiteScopedContentRepository;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Content\Domain\ContentEntry;
use Kumwe\App\Content\Domain\ContentRevision;
use Kumwe\App\Content\Domain\PublicationWindow;
use Kumwe\App\Content\Domain\VersionConflict;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Doctrine DBAL implementation of the site-scoped content repository and the browser's search port.
 *
 * Entries live in `content_entries` and their snapshots in `content_revisions`, both addressed through
 * `TableNames` so the configured prefix is applied and quoted in one place. Four concerns are this
 * adapter's own. Site scoping: `site_identifier` is part of every `WHERE` clause, so an entry owned by
 * another site reads as absent instead of being filtered out downstream. Optimistic concurrency: the
 * writes match on the version the caller read and report a statement that touched no row as
 * `VersionConflict` rather than letting it pass. Public visibility: instead of trusting a status name,
 * the published lookups join `workflow_definition_versions` on the version each entry is pinned to and
 * keep only entries whose state key appears in that version's `public_states` list. Row hygiene: a
 * driver row is untyped, so every column is checked as it is mapped and malformed stored data is
 * refused with `RuntimeException` instead of reaching the application layer.
 *
 * @since  2.0.0
 */
final readonly class DoctrineContentRepository implements SiteScopedContentRepository, ContentSearchRepository
{
    /**
     * Bind the repository to the connection and table-name resolver every statement runs through.
     *
     * @param  Connection  $database  DBAL connection all content reads and writes are issued on.
     * @param  TableNames  $tables    Resolver applying the configured prefix to the two content tables.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * List the default site's entries in a bounded window, most recently updated first.
     *
     * This is the installation-wide entry point `ContentRepository` declares, and it is the same query
     * as `allForSite()` aimed at the default site; a multi-site caller should name its site instead.
     *
     * @param   int   $limit           Maximum records this batch may contain, between 1 and 500.
     * @param   bool  $includeDeleted  Whether trashed entries join the result.
     * @param   int   $offset          Records to skip before collecting the batch.
     *
     * @return  list<ContentRecord>  Empty once the offset has walked past the site's last entry.
     *
     * @throws  InvalidArgumentException  When the limit falls outside 1 to 500, or the offset is negative.
     * @throws  RuntimeException  When a stored row holds malformed JSON or a wrongly typed column.
     *
     * @since   2.0.0
     */
    public function all(int $limit = 100, bool $includeDeleted = false, int $offset = 0): array
    {
        return $this->allForSite(SiteContext::default(), $limit, $includeDeleted, $offset);
    }

    /**
     * List one site's entries in a bounded window, most recently updated first.
     *
     * Ordering is `updated_at` descending with the identifier as tie-breaker, so walking successive
     * offsets neither repeats nor skips a row that shares its timestamp with a neighbour.
     *
     * @param   SiteContext  $site            Site whose entries the listing is confined to.
     * @param   int          $limit           Maximum records this batch may contain, between 1 and 500.
     * @param   bool         $includeDeleted  Whether trashed entries join the result.
     * @param   int          $offset          Records to skip before collecting the batch.
     *
     * @return  list<ContentRecord>  Empty once the offset has walked past the site's last entry.
     *
     * @throws  InvalidArgumentException  When the limit falls outside 1 to 500, or the offset is negative.
     * @throws  RuntimeException  When a stored row holds malformed JSON or a wrongly typed column.
     *
     * @since   2.0.0
     */
    public function allForSite(
        SiteContext $site,
        int $limit = 100,
        bool $includeDeleted = false,
        int $offset = 0,
    ): array {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('The content result limit must be between 1 and 500.');
        }
        if ($offset < 0) {
            throw new InvalidArgumentException('The content result offset cannot be negative.');
        }

        $query = $this->database->createQueryBuilder()
            ->select(...$this->columns())
            ->from($this->tables->raw('content_entries'), 'e')
            ->orderBy('e.updated_at', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $query->where('e.site_identifier = :site')->setParameter('site', $site->identifier());

        if (!$includeDeleted) {
            $query->andWhere('e.deleted_at IS NULL');
        }

        return array_map($this->map(...), $query->executeQuery()->fetchAllAssociative());
    }

    /**
     * Push the administrator browser's filters and ordering down into SQL and return one storage batch.
     *
     * The window is a storage window, not the caller's page: readability is decided after the store
     * answers, so a full batch means "ask again from the next offset". Search matches title or slug
     * case-insensitively, every filter is bound as a parameter, and the ordering is chosen through a
     * closed `match`, so no part of the query string ever reaches the SQL grammar.
     *
     * @param   SiteContext         $site    Site whose entries the search is confined to.
     * @param   ContentBrowseQuery  $query   Validated filters and ordering to translate into SQL.
     * @param   int                 $limit   Maximum records this batch may contain, between 1 and 500.
     * @param   int                 $offset  Records to skip before collecting the batch.
     *
     * @return  list<ContentRecord>  Matches in the query's order; empty once the offset passes the last row.
     *
     * @throws  InvalidArgumentException  When the limit falls outside 1 to 500, or the offset is negative.
     * @throws  RuntimeException  When a stored row holds malformed JSON or a wrongly typed column.
     *
     * @since   2.0.0
     */
    public function searchForSite(
        SiteContext $site,
        ContentBrowseQuery $query,
        int $limit,
        int $offset,
    ): array {
        if ($limit < 1 || $limit > 500 || $offset < 0) {
            throw new InvalidArgumentException('The content browser window is invalid.');
        }

        $builder = $this->database->createQueryBuilder()
            ->select(...$this->columns())
            ->from($this->tables->raw('content_entries'), 'e')
            ->where('e.site_identifier = :site')
            ->setParameter('site', $site->identifier())
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($query->scope === 'active') {
            $builder->andWhere('e.deleted_at IS NULL');
        } elseif ($query->scope === 'trashed') {
            $builder->andWhere('e.deleted_at IS NOT NULL');
        }
        if ($query->status !== '') {
            $builder->andWhere('e.workflow_state_key = :workflow_state')
                ->setParameter('workflow_state', $query->status);
        }
        if ($query->contentType !== '') {
            $builder->andWhere('e.content_type_id = :content_type')
                ->setParameter('content_type', $query->contentType);
        }
        if ($query->search !== '') {
            $builder->andWhere('(LOWER(e.title) LIKE :content_search OR LOWER(e.slug) LIKE :content_search)')
                ->setParameter('content_search', '%' . mb_strtolower($query->search) . '%');
        }

        match ($query->sort) {
            'updated_asc' => $builder->orderBy('e.updated_at', 'ASC')->addOrderBy('e.id', 'ASC'),
            'title_asc' => $builder->orderBy('e.title', 'ASC')->addOrderBy('e.id', 'ASC'),
            'title_desc' => $builder->orderBy('e.title', 'DESC')->addOrderBy('e.id', 'DESC'),
            default => $builder->orderBy('e.updated_at', 'DESC')->addOrderBy('e.id', 'DESC'),
        };

        return array_map($this->map(...), $builder->executeQuery()->fetchAllAssociative());
    }

    /**
     * Load one of the default site's entries by identifier.
     *
     * @param   string  $id              UUID of the content entry, matched on the `id` column.
     * @param   bool    $includeDeleted  Whether a trashed entry still counts as found.
     *
     * @return  ?ContentRecord  Null when no row matches, or the row is trashed and was not asked for.
     *
     * @throws  RuntimeException  When the stored row holds malformed JSON or a wrongly typed column.
     *
     * @since   2.0.0
     */
    public function find(string $id, bool $includeDeleted = false): ?ContentRecord
    {
        return $this->findForSite(SiteContext::default(), $id, $includeDeleted);
    }

    /**
     * Load one entry by identifier, but only if the named site owns it.
     *
     * The site predicate is part of the lookup rather than a check afterwards, so an entry belonging to
     * another site is indistinguishable here from one that does not exist.
     *
     * @param   SiteContext  $site            Site the entry must belong to.
     * @param   string       $id              UUID of the content entry.
     * @param   bool         $includeDeleted  Whether a trashed entry still counts as found.
     *
     * @return  ?ContentRecord  Null when the entry is absent, trashed and unwanted, or owned elsewhere.
     *
     * @throws  RuntimeException  When the stored row holds malformed JSON or a wrongly typed column.
     *
     * @since   2.0.0
     */
    public function findForSite(SiteContext $site, string $id, bool $includeDeleted = false): ?ContentRecord
    {
        $query = $this->database->createQueryBuilder()
            ->select(...$this->columns())
            ->from($this->tables->raw('content_entries'), 'e')
            ->where('e.id = :id')
            ->andWhere('e.site_identifier = :site')
            ->setParameter('id', $id)
            ->setParameter('site', $site->identifier());

        if (!$includeDeleted) {
            $query->andWhere('e.deleted_at IS NULL');
        }

        $row = $query->executeQuery()->fetchAssociative();

        return $row === false ? null : $this->map($row);
    }

    /**
     * Load the default site's entry for a slug, only if it is publicly visible at the given instant.
     *
     * @param   string             $slug  Route segment the public URL carries.
     * @param   DateTimeImmutable  $time  Instant the window and the workflow's public states are judged at.
     *
     * @return  ?ContentRecord  Null when the slug is unknown, or the entry is not visible then.
     *
     * @throws  RuntimeException  When the stored row or the workflow's public state list is malformed.
     *
     * @since   2.0.0
     */
    public function findPublishedBySlug(string $slug, DateTimeImmutable $time): ?ContentRecord
    {
        return $this->findPublishedBySlugForSite(SiteContext::default(), $slug, $time);
    }

    /**
     * Load the default site's entry for an identifier, only if it is publicly visible at that instant.
     *
     * @param   string             $id    UUID of the content entry.
     * @param   DateTimeImmutable  $time  Instant the window and the workflow's public states are judged at.
     *
     * @return  ?ContentRecord  Null when the entry is absent, trashed, unpublished, or out of window.
     *
     * @throws  RuntimeException  When the stored row or the workflow's public state list is malformed.
     *
     * @since   2.0.0
     */
    public function findPublishedById(string $id, DateTimeImmutable $time): ?ContentRecord
    {
        return $this->findPublishedByIdForSite(SiteContext::default(), $id, $time);
    }

    /**
     * Load one of the site's entries by identifier, only if it is publicly visible at the given instant.
     *
     * @param   SiteContext        $site  Site the entry must belong to.
     * @param   string             $id    UUID of the content entry.
     * @param   DateTimeImmutable  $time  Instant the window and the workflow's public states are judged at.
     *
     * @return  ?ContentRecord  Null when the entry is out of reach, unpublished, or out of window.
     *
     * @throws  RuntimeException  When the stored row or the workflow's public state list is malformed.
     *
     * @since   2.0.0
     */
    public function findPublishedByIdForSite(
        SiteContext $site,
        string $id,
        DateTimeImmutable $time,
    ): ?ContentRecord {
        return $this->findPublishedForSite($site, 'id', $id, $time);
    }

    /**
     * Load one of the site's entries by slug, only if it is publicly visible at the given instant.
     *
     * This is the lookup the public delivery path uses, and the reason a slug alone is not a key: two
     * sites may each publish the same route segment.
     *
     * @param   SiteContext        $site  Site the entry must belong to.
     * @param   string             $slug  Route segment the public URL carries.
     * @param   DateTimeImmutable  $time  Instant the window and the workflow's public states are judged at.
     *
     * @return  ?ContentRecord  Null when the site has no such slug, or the entry is not visible then.
     *
     * @throws  RuntimeException  When the stored row or the workflow's public state list is malformed.
     *
     * @since   2.0.0
     */
    public function findPublishedBySlugForSite(SiteContext $site, string $slug, DateTimeImmutable $time): ?ContentRecord
    {
        return $this->findPublishedForSite($site, 'slug', $slug, $time);
    }

    /**
     * Resolve the one publicly visible entry a site addresses by `id` or by `slug` at a given instant.
     *
     * Visibility is decided against the workflow definition version each entry is pinned to: the query
     * joins that version and accepts the first candidate whose state key appears in its `public_states`
     * list, so republishing a workflow cannot retroactively expose entries written under an earlier
     * version. A slug need not be unique within a site, so up to fifty candidates are examined in turn
     * and the first public one wins. The column name is checked against a fixed pair before it is
     * interpolated, because it is the only part of the statement that is not a bound parameter.
     *
     * @param   SiteContext        $site            Site the entry must belong to.
     * @param   string             $identityColumn  Column to match on; only `id` and `slug` are accepted.
     * @param   string             $identity        Value the chosen column must equal.
     * @param   DateTimeImmutable  $time            Instant the publication window is judged at.
     *
     * @return  ?ContentRecord  Null when nothing matches, or no candidate sits in a public state.
     *
     * @throws  InvalidArgumentException  When the identity column is neither `id` nor `slug`.
     * @throws  RuntimeException  When the stored public state list or the matched row is malformed.
     *
     * @since   2.0.0
     */
    private function findPublishedForSite(
        SiteContext $site,
        string $identityColumn,
        string $identity,
        DateTimeImmutable $time,
    ): ?ContentRecord {
        if (!in_array($identityColumn, ['id', 'slug'], true)) {
            throw new InvalidArgumentException('The published content identity column is invalid.');
        }
        $query = $this->database->createQueryBuilder()
            ->select(...[...$this->columns(), 'wv.public_states AS definition_public_states'])
            ->from($this->tables->raw('content_entries'), 'e')
            ->where(sprintf('e.%s = :identity', $identityColumn))
            ->innerJoin(
                'e',
                $this->tables->raw('workflow_definition_versions'),
                'wv',
                'wv.workflow_id = e.workflow_id AND wv.version = e.workflow_version',
            )
            ->andWhere('e.site_identifier = :site')
            ->andWhere('e.deleted_at IS NULL')
            ->andWhere('(e.publish_at IS NULL OR e.publish_at <= :visible_at)')
            ->andWhere('(e.unpublish_at IS NULL OR e.unpublish_at > :visible_at)')
            ->setParameter('identity', $identity)
            ->setParameter('site', $site->identifier())
            ->setParameter('visible_at', $time, Types::DATETIME_IMMUTABLE)
            ->setMaxResults(50);
        foreach ($query->executeQuery()->fetchAllAssociative() as $row) {
            try {
                $publicStates = is_string($row['definition_public_states'] ?? null)
                    ? json_decode($row['definition_public_states'], true, 16, JSON_THROW_ON_ERROR)
                    : $row['definition_public_states'];
            } catch (JsonException $exception) {
                throw new RuntimeException('Stored workflow public states are invalid.', 0, $exception);
            }
            if (
                is_array($publicStates)
                && in_array($this->requiredString($row, 'workflow_state_key'), $publicStates, true)
            ) {
                return $this->map($row);
            }
        }
        return null;
    }

    /**
     * Write a newly created entry as one row in the prefixed `content_entries` table.
     *
     * Doctrine converts the body and the timestamp columns through its `json` and `datetime_immutable`
     * types, so the record is handed over as PHP values rather than pre-encoded strings. Nothing here
     * looks for an existing row: a duplicate identifier is left for the primary key to reject.
     *
     * @param   ContentRecord  $record  Record to store, carrying the entry and its site and version pins.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function insert(ContentRecord $record): void
    {
        $entry = $record->entry;
        $this->database->insert($this->tables->raw('content_entries'), [
            'id' => $entry->id(),
            'content_type_id' => $record->contentTypeId,
            'workflow_id' => $record->workflowId,
            'site_identifier' => $record->siteIdentifier,
            'content_type_version' => $record->contentTypeVersion,
            'workflow_version' => $record->workflowVersion,
            'workflow_state_key' => $entry->statusKey(),
            'title' => $entry->title(),
            'slug' => $entry->slug(),
            'data' => $entry->data(),
            'publish_at' => $entry->publicationWindow()->startsAt(),
            'unpublish_at' => $entry->publicationWindow()->endsAt(),
            'version' => $entry->version(),
            'created_at' => $record->createdAt,
            'updated_at' => $record->updatedAt,
            'deleted_at' => $record->deletedAt,
            'locale' => $entry->locale()?->toString(),
            'translation_group_id' => $entry->translationGroupId(),
            'translation_group_site_identifier' => $entry->translationGroupId() === null
                ? null
                : $record->siteIdentifier,
        ], $this->writeTypes());
    }

    /**
     * Overwrite an entry's editable columns, but only while the row still carries the read version.
     *
     * The `WHERE` clause names the expected version and excludes trashed rows, so a concurrent write or
     * a trashing that landed first matches nothing and is reported instead of silently overwritten.
     * Identity, site and the pinned definition versions are never rewritten here. The locale and the
     * translation group are, because `ContentEntry::translate()` is a versioned change like any other
     * and its result has to reach the row the same way a revision does.
     *
     * @param   ContentRecord  $record           Record carrying the already-incremented entry version.
     * @param   int            $expectedVersion  Version the caller read before revising.
     *
     * @return  void
     *
     * @throws  VersionConflict  When no untrashed row matched the identifier at the expected version.
     *
     * @since   2.0.0
     */
    public function update(ContentRecord $record, int $expectedVersion): void
    {
        $entry = $record->entry;
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET workflow_state_key = ?, title = ?, slug = ?, data = ?, publish_at = ?, '
            . 'unpublish_at = ?, version = ?, updated_at = ?, locale = ?, translation_group_id = ?, '
            . 'translation_group_site_identifier = ? '
            . 'WHERE id = ? AND version = ? AND deleted_at IS NULL',
            $this->tables->quoted('content_entries'),
        ), [
            $entry->statusKey(),
            $entry->title(),
            $entry->slug(),
            $entry->data(),
            $entry->publicationWindow()->startsAt(),
            $entry->publicationWindow()->endsAt(),
            $entry->version(),
            $record->updatedAt,
            $entry->locale()?->toString(),
            $entry->translationGroupId(),
            $entry->translationGroupId() === null ? null : $record->siteIdentifier,
            $entry->id(),
            $expectedVersion,
        ], [
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::JSON,
            Types::DATETIME_IMMUTABLE,
            Types::DATETIME_IMMUTABLE,
            Types::INTEGER,
            Types::DATETIME_IMMUTABLE,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::GUID,
            Types::INTEGER,
        ]);
        $this->assertUpdated($affected, $expectedVersion, $entry->id());
    }

    /**
     * Re-pin an entry to the definition versions the record carries, touching nothing the author wrote.
     *
     * Only the content type, its version, the workflow and its version move, guarded by the version the
     * caller read; the entry's own version is deliberately not raised because no revision was made.
     *
     * @param   ContentRecord  $record           Record carrying the adopted type and workflow versions.
     * @param   int            $expectedVersion  Entry version the caller read before adopting.
     *
     * @return  void
     *
     * @throws  VersionConflict  When no untrashed row matched the identifier at the expected version.
     *
     * @since   2.0.0
     */
    public function adopt(ContentRecord $record, int $expectedVersion): void
    {
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET content_type_id = ?, content_type_version = ?, workflow_id = ?, workflow_version = ?, '
            . 'updated_at = ? WHERE id = ? AND version = ? AND deleted_at IS NULL',
            $this->tables->quoted('content_entries'),
        ), [
            $record->contentTypeId,
            $record->contentTypeVersion,
            $record->workflowId,
            $record->workflowVersion,
            $record->updatedAt,
            $record->entry->id(),
            $expectedVersion,
        ], [
            Types::GUID,
            Types::INTEGER,
            Types::GUID,
            Types::INTEGER,
            Types::DATETIME_IMMUTABLE,
            Types::GUID,
            Types::INTEGER,
        ]);
        $this->assertUpdated($affected, $expectedVersion, $record->entry->id());
    }

    /**
     * Move an entry into or out of the trash, raising its version in the same statement.
     *
     * Trashing marks a column instead of deleting the row, so the entry, its data and its revision
     * trail survive and a restore is the same call with a null marker. Unlike `update()` this statement
     * matches trashed rows too, which is what makes the restore reachable at all.
     *
     * @param   string              $id               UUID of the content entry.
     * @param   int                 $expectedVersion  Version the caller read before trashing or restoring.
     * @param   ?DateTimeImmutable  $deletedAt        Instant to mark as trashed, or null to restore.
     * @param   DateTimeImmutable   $updatedAt        Instant recorded as the entry's last modification.
     *
     * @return  void
     *
     * @throws  VersionConflict  When no row matched the identifier at the expected version.
     *
     * @since   2.0.0
     */
    public function setDeletedAt(
        string $id,
        int $expectedVersion,
        ?DateTimeImmutable $deletedAt,
        DateTimeImmutable $updatedAt,
    ): void {
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET deleted_at = ?, updated_at = ?, version = version + 1 WHERE id = ? AND version = ?',
            $this->tables->quoted('content_entries'),
        ), [$deletedAt, $updatedAt, $id, $expectedVersion], [
            Types::DATETIME_IMMUTABLE,
            Types::DATETIME_IMMUTABLE,
            Types::GUID,
            Types::INTEGER,
        ]);
        $this->assertUpdated($affected, $expectedVersion, $id);
    }

    /**
     * Append one snapshot row to an entry's revision trail in `content_revisions`.
     *
     * The insert runs on the caller's connection, so the revision commits with the entry change it
     * describes and disappears with it on rollback. Nothing here allocates the revision number; the
     * caller takes it from `nextRevisionNumber()` inside that same transaction.
     *
     * @param   ContentRevision  $revision  Checksummed snapshot captured from the entry as it now stands.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function appendRevision(ContentRevision $revision): void
    {
        $this->database->insert($this->tables->raw('content_revisions'), [
            'id' => $revision->id(),
            'content_entry_id' => $revision->contentEntryId(),
            'revision_number' => $revision->revisionNumber(),
            'snapshot' => $revision->snapshot(),
            'checksum' => $revision->checksum(),
            'created_at' => $revision->createdAt(),
        ], [
            'snapshot' => Types::JSON,
            'created_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * Report the revision number the next snapshot of an entry should carry.
     *
     * The number is read as `MAX(revision_number) + 1` at call time, so the caller must hold the write
     * transaction across both this read and the matching `appendRevision()` for the sequence to stay
     * unique and gap-free under concurrent edits.
     *
     * @param   string  $contentEntryId  UUID of the content entry the trail belongs to.
     *
     * @return  int  One for an entry with no revisions yet, otherwise one past the highest stored.
     *
     * @throws  RuntimeException  When the driver returns something that is not a whole number.
     *
     * @since   2.0.0
     */
    public function nextRevisionNumber(string $contentEntryId): int
    {
        $result = $this->database->fetchOne(sprintf(
            'SELECT COALESCE(MAX(revision_number), 0) + 1 FROM %s WHERE content_entry_id = ?',
            $this->tables->quoted('content_revisions'),
        ), [$contentEntryId]);

        if (!is_int($result) && (!is_string($result) || preg_match('/^[0-9]+$/D', $result) !== 1)) {
            throw new RuntimeException('The next content revision number is invalid.');
        }

        return (int) $result;
    }

    /**
     * Name the `content_entries` columns every read projects, qualified with the `e` table alias.
     *
     * Keeping the projection in one place is what lets `map()` assume a fixed set of keys, so a column
     * added to a query without being added here would be silently dropped on the way back.
     *
     * @return  list<string>  Qualified select expressions covering every column the mapper reads.
     *
     * @since   2.0.0
     */
    private function columns(): array
    {
        return [
            'e.id',
            'e.site_identifier',
            'e.content_type_id',
            'e.workflow_id',
            'e.content_type_version',
            'e.workflow_version',
            'e.workflow_state_key', 'e.title', 'e.slug',
            'e.data', 'e.publish_at', 'e.unpublish_at', 'e.version', 'e.created_at', 'e.updated_at', 'e.deleted_at',
            'e.locale', 'e.translation_group_id',
        ];
    }

    /**
     * Name the columns Doctrine must convert on insert, and the DBAL type that converts each of them.
     *
     * @return  array<string, string>  Column name to DBAL type constant; omitted columns bind as-is.
     *
     * @since   2.0.0
     */
    private function writeTypes(): array
    {
        return [
            'data' => Types::JSON,
            'publish_at' => Types::DATETIME_IMMUTABLE,
            'unpublish_at' => Types::DATETIME_IMMUTABLE,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
            'deleted_at' => Types::DATETIME_IMMUTABLE,
        ];
    }

    /**
     * Rebuild a `ContentRecord` from one driver row, re-checking every column on the way through.
     *
     * The store is not trusted to hand back well-formed values: the body must decode to a JSON object
     * rather than a list, identifiers and titles must be non-empty strings, and versions must be whole
     * numbers. A row failing any of these is refused here, so malformed storage surfaces at the read
     * that touched it instead of as invalid content somewhere downstream.
     *
     * @param   array<string, mixed>  $row  Associative row as fetched, keyed by unqualified column name.
     *
     * @return  ContentRecord  The stored entry with its publication window and metadata reassembled.
     *
     * @throws  RuntimeException  When the body is not a JSON object, or a column is absent or wrongly
     *          typed.
     *
     * @since   2.0.0
     */
    private function map(array $row): ContentRecord
    {
        try {
            $data = is_string($row['data'] ?? null)
                ? json_decode($row['data'], true, 64, JSON_THROW_ON_ERROR)
                : $row['data'];
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored content JSON is invalid.', 0, $exception);
        }

        if (!is_array($data) || array_is_list($data)) {
            throw new RuntimeException('Stored content data must be a JSON object.');
        }

        $window = new PublicationWindow(
            $this->nullableDateTime($row['publish_at'] ?? null),
            $this->nullableDateTime($row['unpublish_at'] ?? null),
        );
        $entry = ContentEntry::reconstitute(
            $this->requiredString($row, 'id'),
            $this->requiredString($row, 'title'),
            $this->requiredString($row, 'slug'),
            $data,
            $this->requiredString($row, 'workflow_state_key'),
            $window,
            $this->integer($row, 'version'),
            $this->optionalString($row, 'locale'),
            $this->optionalString($row, 'translation_group_id'),
        );

        return new ContentRecord(
            $entry,
            $this->requiredString($row, 'content_type_id'),
            $this->requiredString($row, 'workflow_id'),
            $this->dateTime($row['created_at'] ?? null),
            $this->dateTime($row['updated_at'] ?? null),
            $this->nullableDateTime($row['deleted_at'] ?? null),
            $this->integer($row, 'content_type_version'),
            $this->integer($row, 'workflow_version'),
            $this->requiredString($row, 'site_identifier'),
        );
    }

    /**
     * Turn a versioned statement that matched no row into the concurrency failure it stands for.
     *
     * The version actually stored is re-read with trashed rows included, so the reported conflict names
     * the version a caller would have to retry against; an entry that has since vanished reports zero.
     *
     * @param   int|string  $affected         Row count the driver reported, as an int or numeric string.
     * @param   int         $expectedVersion  Version the failed statement matched on.
     * @param   string      $id               UUID of the content entry the statement targeted.
     *
     * @return  void
     *
     * @throws  VersionConflict  When the statement affected any number of rows other than exactly one.
     *
     * @since   2.0.0
     */
    private function assertUpdated(int|string $affected, int $expectedVersion, string $id): void
    {
        if ((string) $affected !== '1') {
            throw new VersionConflict($expectedVersion, $this->find($id, true)?->entry->version() ?? 0);
        }
    }

    /**
     * Read a column that must hold a non-empty string, refusing anything else.
     *
     * @param   array<string, mixed>  $row  Associative row as fetched from the driver.
     * @param   string                $key  Unqualified name of the column to read.
     *
     * @return  string  The stored value, guaranteed non-empty.
     *
     * @throws  RuntimeException  When the column is absent, not a string, or the empty string.
     *
     * @since   2.0.0
     */
    private function requiredString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Stored content field %s is invalid.', $key));
        }

        return $value;
    }

    /**
     * Read a nullable column, treating an empty string the way the store's NULL is treated.
     *
     * The locale and translation-group columns are absent on every row written before content carried a
     * language dimension, and a driver reading a legacy MySQL row can hand back an empty string where
     * PostgreSQL hands back null. Both mean "not declared", so both reduce to null here rather than
     * reaching the entry as a value it would then have to refuse.
     *
     * @param   array<string, mixed>  $row  Associative row as fetched from the driver.
     * @param   string                $key  Unqualified name of the column to read.
     *
     * @return  ?string  The stored value, or null when the column is absent, null, or empty.
     *
     * @throws  RuntimeException  When the column holds something other than a string or null.
     *
     * @since   2.0.0
     */
    private function optionalString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new RuntimeException(sprintf('Stored content field %s is invalid.', $key));
        }

        return $value;
    }

    /**
     * Read a column that must hold a whole number, accepting the digit strings some drivers return.
     *
     * @param   array<string, mixed>  $row  Associative row as fetched from the driver.
     * @param   string                $key  Unqualified name of the column to read.
     *
     * @return  int  The stored value as an integer.
     *
     * @throws  RuntimeException  When the column is absent, or holds neither an integer nor digits only.
     *
     * @since   2.0.0
     */
    private function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) {
            throw new RuntimeException(sprintf('Stored content field %s is not an integer.', $key));
        }

        return (int) $value;
    }

    /**
     * Normalise whatever the driver returned for a timestamp column into an immutable date.
     *
     * Drivers differ: some hydrate a date object, others hand back the raw string, so both are accepted
     * rather than pinning the mapper to one platform. A bare string is read as UTC, which is the zone
     * every content timestamp is written in.
     *
     * @param   mixed  $value  Raw timestamp column value from a content row.
     *
     * @return  DateTimeImmutable  The instant, converted when the driver returned another date type.
     *
     * @throws  RuntimeException  When the value is neither a date object nor a non-empty string.
     * @throws  \DateMalformedStringException  When the string cannot be read as a date.
     *
     * @since   2.0.0
     */
    private function dateTime(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (!is_string($value) || $value === '') {
            throw new RuntimeException('Stored content timestamp is invalid.');
        }

        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    /**
     * Normalise an optional timestamp column, treating an empty string as an absent value.
     *
     * This is what lets an open-ended publication window and a live entry both read as null, whether
     * the driver reports the unset column as SQL null or as the empty string.
     *
     * @param   mixed  $value  Raw timestamp column value, or null when the column is unset.
     *
     * @return  ?DateTimeImmutable  Null when the column is null or empty, otherwise the parsed instant.
     *
     * @throws  RuntimeException  When a present value is neither a date object nor a readable string.
     * @throws  \DateMalformedStringException  When the string cannot be read as a date.
     *
     * @since   2.0.0
     */
    private function nullableDateTime(mixed $value): ?DateTimeImmutable
    {
        return $value === null || $value === '' ? null : $this->dateTime($value);
    }
}
