<?php

declare(strict_types=1);

namespace Kumwe\App\Content\Application;

use Kumwe\Context\Value\SiteContext;

/**
 * Optional repository capability that answers the administrator content browser's filtered queries.
 *
 * `ContentRepository` can only list a site in storage order, which is too coarse for the browser's
 * search, status, type, trash and sort controls. A store able to push those filters down into its own
 * query language implements this alongside it, and `ContentService::browse()` refuses to browse at all
 * when the configured repository does not — an unfiltered fallback would quietly render the wrong
 * screen. What comes back is unfiltered by permission: the service still runs every record past the
 * authorization gateway before it counts towards a page.
 *
 * @since  2.0.0
 */
interface ContentSearchRepository
{
    /**
     * Return one storage-level batch of a site's entries matching the browse query.
     *
     * The window is a storage window, not the caller's page. Readability is decided per record after
     * the store answers, so the service walks batches from increasing offsets and does its own paging;
     * a full batch means "ask again", not "this is the page".
     *
     * @param   SiteContext         $site    Site whose entries the search is confined to.
     * @param   ContentBrowseQuery  $query   Validated filters and ordering to apply in the store.
     * @param   int                 $limit   Maximum records this batch may contain.
     * @param   int                 $offset  Records to skip before collecting the batch.
     *
     * @return  list<ContentRecord>  Matches in the query's order; empty once the offset passes the last row.
     *
     * @since   2.0.0
     */
    public function searchForSite(
        SiteContext $site,
        ContentBrowseQuery $query,
        int $limit,
        int $offset,
    ): array;
}
