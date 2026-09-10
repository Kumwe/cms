<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Projection;

use Kumwe\App\Content\Application\ContentBrowseQuery;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Studio\Application\Host\StudioResourceSearchItem;
use Kumwe\App\Studio\Application\Host\StudioResourceSearchPage;
use Kumwe\App\Studio\Application\Host\StudioResourceSearchProvider;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Authorized Content entry browser projected as Studio dynamic resources.
 *
 * @since  2.0.0
 */
final readonly class ContentStudioResourceSearchProvider implements StudioResourceSearchProvider
{
    /**
     * Portable resource family exposed for authorized Content entries.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string RESOURCE_TYPE = 'kumwe.app/content-entry';

    /**
     * Bind resource browsing to the canonical Content application service.
     *
     * @param  ContentService  $content  Policy-aware Content use-case surface.
     *
     * @since  2.0.0
     */
    public function __construct(private ContentService $content)
    {
    }

    /**
     * Name the exact portable resource family owned by this provider.
     *
     * @return  string  Qualified Content-entry resource type.
     *
     * @since   2.0.0
     */
    public function resourceType(): string
    {
        return self::RESOURCE_TYPE;
    }

    /**
     * Project one deterministic page of policy-authorized Content entries.
     *
     * @param   ExecutionContext  $context  Trusted App actor and scope.
     * @param   string            $search   Bounded title or slug search text.
     * @param   int               $offset   Zero-based authorized-result offset.
     * @param   int               $limit    Maximum number of projected entries.
     *
     * @return  StudioResourceSearchPage  Stable references and next-page evidence.
     *
     * @since   2.0.0
     */
    public function search(
        ExecutionContext $context,
        string $search,
        int $offset,
        int $limit,
    ): StudioResourceSearchPage {
        $pageNumber = intdiv($offset, 50) + 1;
        $skip = $offset % 50;
        $items = [];
        $hasNext = false;

        do {
            $page = $this->content->browse($context, new ContentBrowseQuery(
                search: $search,
                sort: 'title_asc',
                page: $pageNumber,
                perPage: 50,
            ));
            foreach (array_slice($page->items, $skip) as $record) {
                $items[] = new StudioResourceSearchItem(
                    'content-entry:' . $record->entry->id(),
                    $record->entry->title(),
                );
                if (count($items) > $limit) {
                    $hasNext = true;
                    break 2;
                }
            }
            $skip = 0;
            if (!$page->hasNext) {
                break;
            }
            $pageNumber++;
        } while ($pageNumber <= 100_000);

        if (count($items) > $limit) {
            array_pop($items);
        } elseif (!$hasNext && $page->hasNext) {
            $hasNext = true;
        }

        return new StudioResourceSearchPage($items, $hasNext);
    }
}
