<?php

declare(strict_types=1);

namespace Kumwe\App\Content\Application;

use DateTimeImmutable;
use Kumwe\App\Content\Domain\ContentRevision;

/**
 * Persistence contract for content entries and the revision trail behind them.
 *
 * `ContentService` is written against this interface alone, which is what keeps optimistic
 * concurrency, soft deletion and revision capture out of the driver and in one place. Two obligations
 * shape every implementation: a write that names a stale version must be rejected rather than
 * silently applied, and trashing must mark the row instead of removing it so that a restore can bring
 * the entry back with its history. Adapters that can scope by site implement `SiteScopedContentRepository`
 * on top of this; the plain methods here answer for the whole installation.
 *
 * @since  2.0.0
 */
interface ContentRepository
{
    /**
     * List stored entries in a bounded window, newest storage order first.
     *
     * Callers page through with `$offset` because the result is filtered for readability afterwards,
     * so a short return does not mean the store is exhausted.
     *
     * @param   int   $limit           Maximum records to return in this batch.
     * @param   bool  $includeDeleted  Whether trashed entries join the result.
     * @param   int   $offset          Records to skip before collecting the batch.
     *
     * @return  list<ContentRecord>  Empty once the offset has walked past the last stored entry.
     *
     * @since   2.0.0
     */
    public function all(int $limit = 100, bool $includeDeleted = false, int $offset = 0): array;

    /**
     * Load one entry by its identifier.
     *
     * @param   string  $id              UUID of the content entry.
     * @param   bool    $includeDeleted  Whether a trashed entry still counts as found.
     *
     * @return  ?ContentRecord  Null when no entry matches, or when it is trashed and not asked for.
     *
     * @since   2.0.0
     */
    public function find(string $id, bool $includeDeleted = false): ?ContentRecord;

    /**
     * Load one entry by identifier only if it is publicly visible at the given instant.
     *
     * Visibility means a workflow state the entry's workflow declares public and a publication window
     * that contains the instant, so this is the lookup the public delivery path uses.
     *
     * @param   string             $id    UUID of the content entry.
     * @param   DateTimeImmutable  $time  Instant the visibility rules are evaluated at.
     *
     * @return  ?ContentRecord  Null when the entry is absent, trashed, unpublished, or out of window.
     *
     * @since   2.0.0
     */
    public function findPublishedById(string $id, DateTimeImmutable $time): ?ContentRecord;

    /**
     * Load one entry by its slug only if it is publicly visible at the given instant.
     *
     * @param   string             $slug  Route segment the public URL carries.
     * @param   DateTimeImmutable  $time  Instant the visibility rules are evaluated at.
     *
     * @return  ?ContentRecord  Null when the slug is unknown, or the entry is not visible then.
     *
     * @since   2.0.0
     */
    public function findPublishedBySlug(string $slug, DateTimeImmutable $time): ?ContentRecord;

    /**
     * Store a newly created entry.
     *
     * @param   ContentRecord  $record  Record to write, already at version one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function insert(ContentRecord $record): void;

    /**
     * Overwrite an entry, but only if the stored row is still at the version the caller read.
     *
     * @param   ContentRecord  $record           Record carrying the already-incremented entry version.
     * @param   int            $expectedVersion  Version the caller read before revising.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Content\Domain\VersionConflict  When another writer moved the entry on first.
     *
     * @since   2.0.0
     */
    public function update(ContentRecord $record, int $expectedVersion): void;

    /**
     * Re-pin an entry to the content type and workflow versions the record carries, leaving the entry as is.
     *
     * `update()` never rewrites the pinned definition versions, and adoption never rewrites the entry: the
     * row keeps its title, slug, data, state and optimistic version and only follows its type to the
     * adopted version, still guarded by the version the caller read.
     *
     * @param   ContentRecord  $record           Record carrying the adopted type and workflow versions.
     * @param   int            $expectedVersion  Entry version the caller read before adopting.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Content\Domain\VersionConflict  When another writer moved the entry on first.
     *
     * @since   2.0.0
     */
    public function adopt(ContentRecord $record, int $expectedVersion): void;

    /**
     * Move an entry into or out of the trash without touching its content or revisions.
     *
     * @param   string              $id               UUID of the content entry.
     * @param   int                 $expectedVersion  Version the caller read before trashing or restoring.
     * @param   ?DateTimeImmutable  $deletedAt        Instant to mark as trashed, or null to restore.
     * @param   DateTimeImmutable   $updatedAt        Instant recorded as the entry's last modification.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Content\Domain\VersionConflict  When another writer moved the entry on first.
     *
     * @since   2.0.0
     */
    public function setDeletedAt(
        string $id,
        int $expectedVersion,
        ?DateTimeImmutable $deletedAt,
        DateTimeImmutable $updatedAt,
    ): void;

    /**
     * Append one immutable snapshot to an entry's revision trail.
     *
     * Callers write the revision inside the same transaction as the entry itself, so the trail never
     * lags behind the row it describes.
     *
     * @param   ContentRevision  $revision  Snapshot captured from the entry as it now stands.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function appendRevision(ContentRevision $revision): void;

    /**
     * Report the revision number the next snapshot of an entry should carry.
     *
     * @param   string  $contentEntryId  UUID of the content entry the trail belongs to.
     *
     * @return  int  One for an entry with no revisions yet, otherwise one past the highest stored.
     *
     * @since   2.0.0
     */
    public function nextRevisionNumber(string $contentEntryId): int;
}
