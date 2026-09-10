<?php

declare(strict_types=1);

namespace Kumwe\App\Content\Application;

use DateTimeImmutable;
use Kumwe\App\Content\Domain\ContentEntry;
use Kumwe\Context\Value\SiteContext;

/**
 * A stored content entry together with the persistence facts the domain entry deliberately omits.
 *
 * `ContentEntry` models title, slug, data, workflow state and version and knows nothing about sites,
 * content models, or rows. Everything the application layer needs to place that entry in a site and
 * pin it to the definition versions it was authored against lives here, which keeps the domain free of
 * storage concerns while giving repositories, presenters and the API one object to move around. The
 * pinned `contentTypeVersion` and `workflowVersion` are what let a definition be republished without
 * silently re-validating or re-routing entries that were written under the previous version.
 *
 * @since  2.0.0
 */
final readonly class ContentRecord
{
    /**
     * Assemble a record from a domain entry and its stored metadata.
     *
     * @param  ContentEntry        $entry               Domain entry carrying title, slug, data, state and version.
     * @param  string              $contentTypeId       UUID of the content type whose schema the data satisfies.
     * @param  string              $workflowId          UUID of the workflow governing this entry's transitions.
     * @param  DateTimeImmutable   $createdAt           When the entry was first written.
     * @param  DateTimeImmutable   $updatedAt           When the entry last changed, revisions included.
     * @param  ?DateTimeImmutable  $deletedAt           When the entry was trashed, or null while it is live.
     * @param  int                 $contentTypeVersion  Content type version this entry was authored against.
     * @param  int                 $workflowVersion     Workflow version whose states this entry's status belongs to.
     * @param  string              $siteIdentifier      Site that owns the entry; every query is scoped by it.
     *
     * @since  2.0.0
     */
    public function __construct(
        public ContentEntry $entry,
        public string $contentTypeId,
        public string $workflowId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $deletedAt = null,
        public int $contentTypeVersion = 1,
        public int $workflowVersion = 1,
        public string $siteIdentifier = SiteContext::DEFAULT,
    ) {
    }

    /**
     * Return a copy carrying a revised domain entry and a fresh modification timestamp.
     *
     * The pinned content type and workflow versions are carried across unchanged, so revising an entry
     * never migrates it onto a definition version that was published after it was written.
     *
     * @param   ContentEntry       $entry      Result of revising or transitioning the current entry.
     * @param   DateTimeImmutable  $updatedAt  Instant the revision was applied.
     *
     * @return  self  A new record; the receiver is left untouched.
     *
     * @since   2.0.0
     */
    public function withEntry(ContentEntry $entry, DateTimeImmutable $updatedAt): self
    {
        return new self(
            $entry,
            $this->contentTypeId,
            $this->workflowId,
            $this->createdAt,
            $updatedAt,
            $this->deletedAt,
            $this->contentTypeVersion,
            $this->workflowVersion,
            $this->siteIdentifier,
        );
    }

    /**
     * Return a copy that moves the entry into or out of the trash.
     *
     * Deletion is a soft marker only: the domain entry, its version and its workflow state are all
     * preserved, so restoring is the same call with a null marker rather than a rebuild.
     *
     * @param   ?DateTimeImmutable  $deletedAt  Instant the entry was trashed, or null to restore it.
     * @param   DateTimeImmutable   $updatedAt  Instant the change was applied.
     *
     * @return  self  A new record; the receiver is left untouched.
     *
     * @since   2.0.0
     */
    public function withDeletedAt(?DateTimeImmutable $deletedAt, DateTimeImmutable $updatedAt): self
    {
        return new self(
            $this->entry,
            $this->contentTypeId,
            $this->workflowId,
            $this->createdAt,
            $updatedAt,
            $deletedAt,
            $this->contentTypeVersion,
            $this->workflowVersion,
            $this->siteIdentifier,
        );
    }

    /**
     * Flatten the record into the associative shape the API, MCP and administrator templates render.
     *
     * The domain snapshot is spread first, so `id`, `title`, `slug`, `data`, `status`,
     * `publication_window` and `version` come straight from the entry and the storage metadata is
     * appended alongside it under snake-case keys.
     *
     * @return  array<string, mixed>  Timestamps are RFC 3339 strings; `deleted_at` is null while live.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            ...$this->entry->snapshot(),
            'content_type_id' => $this->contentTypeId,
            'workflow_id' => $this->workflowId,
            'content_type_version' => $this->contentTypeVersion,
            'workflow_version' => $this->workflowVersion,
            'site' => $this->siteIdentifier,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'updated_at' => $this->updatedAt->format(DATE_ATOM),
            'deleted_at' => $this->deletedAt?->format(DATE_ATOM),
        ];
    }
}
