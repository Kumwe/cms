<?php

declare(strict_types=1);

namespace Kumwe\App\Media\Application;

use Kumwe\Context\Value\SiteContext;

/**
 * Optional storage capability for generated media selectors with a hard scan budget.
 *
 * Keeping this separate from `MediaStorage` preserves compatibility with existing stores. A store that
 * cannot promise bounded choice work simply does not implement this port, and generated selectors fail
 * closed instead of falling back to the legacy whole-library browse.
 *
 * @since  2.0.0
 */
interface BoundedMediaChoiceStorage
{
    /**
     * Find a bounded set of media choices without materializing the whole site library.
     *
     * @param   SiteContext  $site       Site whose library is searched.
     * @param   string       $query      Case-insensitive bounded display-name substring.
     * @param   int          $limit      Most validated matching assets to return, from one to fifty.
     * @param   int          $scanLimit  Most directory or index entries to inspect, from `$limit` to 4096.
     *
     * @return  list<MediaAsset>  At most `$limit` validated matches, ordered by display name and identifier.
     *
     * @throws  \InvalidArgumentException  When the query or either bound is invalid.
     *
     * @since   2.0.0
     */
    public function choices(SiteContext $site, string $query, int $limit, int $scanLimit): array;
}
