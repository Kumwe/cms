<?php

declare(strict_types=1);

namespace Kumwe\App\Content\Application;

use DateTimeImmutable;
use Kumwe\Context\Value\SiteContext;

/**
 * Content persistence whose every read is bounded by the site that owns the entry.
 *
 * One Kumwe installation serves several sites out of one set of tables, so a lookup by slug or by
 * identifier is only correct once the site is part of the question — two sites may legitimately both
 * publish `about-us`. A store able to enforce that bound implements this in place of plain
 * `ContentRepository`, and `ContentService` prefers these methods whenever the repository it was given
 * provides them, falling back to the installation-wide ones only for a store that cannot scope. An
 * entry owned by another site must read as absent here rather than be filtered out downstream, so a
 * caller that forgets to check cannot leak it.
 *
 * @since  2.0.0
 */
interface SiteScopedContentRepository extends ContentRepository
{
    /**
     * List one site's stored entries in a bounded window.
     *
     * The caller pages with `$offset` because results are filtered for readability afterwards, so a
     * short batch does not mean the site is exhausted.
     *
     * @param   SiteContext  $site            Site whose entries the listing is confined to.
     * @param   int          $limit           Maximum records to return in this batch.
     * @param   bool         $includeDeleted  Whether trashed entries join the result.
     * @param   int          $offset          Records to skip before collecting the batch.
     *
     * @return  list<ContentRecord>  Empty once the offset has walked past the site's last entry.
     *
     * @since   2.0.0
     */
    public function allForSite(
        SiteContext $site,
        int $limit = 100,
        bool $includeDeleted = false,
        int $offset = 0,
    ): array;

    /**
     * Load one entry by identifier, but only if the named site owns it.
     *
     * @param   SiteContext  $site            Site the entry must belong to.
     * @param   string       $id              UUID of the content entry.
     * @param   bool         $includeDeleted  Whether a trashed entry still counts as found.
     *
     * @return  ?ContentRecord  Null when the entry is absent, trashed and unwanted, or owned elsewhere.
     *
     * @since   2.0.0
     */
    public function findForSite(SiteContext $site, string $id, bool $includeDeleted = false): ?ContentRecord;

    /**
     * Load one of the site's entries by identifier only if it is publicly visible at the given instant.
     *
     * @param   SiteContext        $site  Site the entry must belong to.
     * @param   string             $id    UUID of the content entry.
     * @param   DateTimeImmutable  $time  Instant the visibility rules are evaluated at.
     *
     * @return  ?ContentRecord  Null when the entry is out of reach, unpublished, or out of window.
     *
     * @since   2.0.0
     */
    public function findPublishedByIdForSite(
        SiteContext $site,
        string $id,
        DateTimeImmutable $time,
    ): ?ContentRecord;

    /**
     * Load one of the site's entries by slug only if it is publicly visible at the given instant.
     *
     * This is the lookup the public delivery path uses, and the reason the slug alone is not a key:
     * the same segment may be published by several sites at once.
     *
     * @param   SiteContext        $site  Site the entry must belong to.
     * @param   string             $slug  Route segment the public URL carries.
     * @param   DateTimeImmutable  $time  Instant the visibility rules are evaluated at.
     *
     * @return  ?ContentRecord  Null when the site has no such slug, or the entry is not visible then.
     *
     * @since   2.0.0
     */
    public function findPublishedBySlugForSite(
        SiteContext $site,
        string $slug,
        DateTimeImmutable $time,
    ): ?ContentRecord;
}
