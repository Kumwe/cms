<?php

declare(strict_types=1);

namespace Kumwe\App\Portal\Application;

use Kumwe\Context\Value\MembershipContext;

/**
 * Live user-role loader shared by portal password login and session resolution.
 *
 * @since  2.0.0
 */
interface PortalPrincipalLoader
{
    /**
     * Rebuild an active user's principal and authorization epoch from current role grants.
     *
     * @param   string              $subjectId     User UUID.
     * @param   string              $credentialId  Bounded provenance label for the principal.
     * @param   ?MembershipContext  $membership    Exact live membership whose role grants may be added.
     *
     * @return  ?PortalPasswordIdentity  Live principal and epoch, or null for inactive or unknown users.
     *
     * @since   2.0.0
     */
    public function load(
        string $subjectId,
        string $credentialId,
        ?MembershipContext $membership = null,
    ): ?PortalPasswordIdentity;
}
