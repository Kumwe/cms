<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Authorization;

use Kumwe\Context\Value\MembershipContext;
use Kumwe\Context\Value\SiteContext;

/**
 * Inward-facing freshness authority for versioned organization membership contexts.
 *
 * A `MembershipContext` is a credential snapshot, not proof that its organization and optional
 * workspace still confer authority. The canonical authorization gateway asks this port on every
 * decision before treating those contextual identifiers as grant scopes. Implementations compare the
 * exact actor, site, membership row, versions, policy generation, and workspace assignment with live
 * state and fail closed when any part cannot be verified.
 *
 * @since  2.0.0
 */
interface MembershipContextValidator
{
    /**
     * Revalidate one exact membership snapshot against its current authority source.
     *
     * @param   string             $subjectId   Actor expected to hold the membership.
     * @param   SiteContext        $site        Exact site the decision executes in.
     * @param   MembershipContext  $membership  Versioned organization and optional workspace snapshot.
     * @param   bool               $lock        Whether to hold the live membership for a following mutation.
     *
     * @return  bool  True only when every identity, lifecycle, time, version, generation, and workspace
     *          binding is still current; false when stale or unverifiable.
     *
     * @since   2.0.0
     */
    public function current(
        string $subjectId,
        SiteContext $site,
        MembershipContext $membership,
        bool $lock = false,
    ): bool;
}
