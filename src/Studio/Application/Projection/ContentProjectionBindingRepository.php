<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Projection;

use Kumwe\App\Studio\Domain\Projection\ContentBlueprintBinding;
use Kumwe\App\Studio\Domain\Projection\EntryCompositionOverrides;
use Kumwe\Context\Value\SiteContext;

/**
 * Read boundary for the host-owned coordinates a Content projection adds to Studio documents.
 *
 * Publishing bindings and overrides is deliberately absent: AP-2 is a read-only model projection.
 * A later authorized, audited write service may introduce its own store port without making this
 * model-port service an accidental mutation path.
 *
 * @since  2.0.0
 */
interface ContentProjectionBindingRepository
{
    /**
     * Load the Blueprint selected for one immutable Content definition version.
     *
     * @param   SiteContext  $site                Site whose binding is addressed.
     * @param   string       $contentTypeId       Canonical Content type UUID.
     * @param   int          $contentTypeVersion  Exact published definition version.
     *
     * @return  ?ContentBlueprintBinding  Binding or null when composition has not been configured.
     *
     * @since   2.0.0
     */
    public function blueprint(
        SiteContext $site,
        string $contentTypeId,
        int $contentTypeVersion,
    ): ?ContentBlueprintBinding;

    /**
     * Load the optional per-entry composition overrides.
     *
     * @param   SiteContext  $site     Site whose entry is addressed.
     * @param   string       $entryId  Canonical Content entry UUID.
     *
     * @return  ?EntryCompositionOverrides  Override object or null when the entry inherits completely.
     *
     * @since   2.0.0
     */
    public function overrides(SiteContext $site, string $entryId): ?EntryCompositionOverrides;
}
