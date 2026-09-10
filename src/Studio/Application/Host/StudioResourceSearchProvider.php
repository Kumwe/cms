<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use Kumwe\Context\Value\ExecutionContext;

/**
 * Host-owned, policy-aware source for one exact Studio resource family.
 *
 * Implementations call existing application services. They never expose a repository, SQL, a filter
 * expression, or a client-selected service to Studio.
 *
 * @since  2.0.0
 */
interface StudioResourceSearchProvider
{
    /**
     * Return the exact qualified resource type this provider owns.
     *
     * @return  string  Stable Studio resource type.
     *
     * @since   2.0.0
     */
    public function resourceType(): string;

    /**
     * Return one authorized deterministic slice.
     *
     * @param   ExecutionContext  $context  Trusted App actor and scope.
     * @param   string            $search   Bounded human search text, or an empty string.
     * @param   int               $offset   Zero-based authorized-result offset.
     * @param   int               $limit    Requested item limit from one through one hundred.
     *
     * @return  StudioResourceSearchPage  Authorized results and next-page evidence.
     *
     * @since   2.0.0
     */
    public function search(
        ExecutionContext $context,
        string $search,
        int $offset,
        int $limit,
    ): StudioResourceSearchPage;
}
