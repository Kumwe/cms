<?php

declare(strict_types=1);

namespace Kumwe\App\Portal\Application;

use InvalidArgumentException;
use Kumwe\App\BusinessSecurity\Application\MembershipDirectory;
use Kumwe\App\Portal\Application\PortalContext;
use Kumwe\Context\Value\SiteContext;

/**
 * Composes live principal and canonical membership loaders for portal session resolution.
 *
 * @since  2.0.0
 */
final readonly class MembershipPortalSessionIdentityLoader implements PortalSessionIdentityLoader
{
    /**
     * Bind session resolution to live grants and live membership status.
     *
     * @param  PortalPrincipalLoader  $principals   Active user, role, grant, and epoch loader.
     * @param  MembershipDirectory    $memberships  Active organization/workspace membership directory.
     *
     * @since  2.0.0
     */
    public function __construct(
        private PortalPrincipalLoader $principals,
        private MembershipDirectory $memberships,
    ) {
    }

    /**
     * Rebuild an exact portal session identity, returning null for any stale or malformed coordinate.
     *
     * @param   string   $subjectId               User UUID.
     * @param   string   $siteIdentifier          Stored site identifier.
     * @param   ?string  $organizationIdentifier  Stored organization identifier.
     * @param   ?string  $membershipId            Stored membership UUID.
     * @param   ?string  $workspaceIdentifier     Stored workspace identifier.
     * @param   string   $sessionId               Stored session UUID.
     *
     * @return  ?PortalSessionIdentity  Live identity and context or null.
     *
     * @since   2.0.0
     */
    public function load(
        string $subjectId,
        string $siteIdentifier,
        ?string $organizationIdentifier,
        ?string $membershipId,
        ?string $workspaceIdentifier,
        string $sessionId,
    ): ?PortalSessionIdentity {
        try {
            $site = SiteContext::fromString($siteIdentifier);
        } catch (InvalidArgumentException) {
            return null;
        }
        if ($organizationIdentifier === null) {
            if ($membershipId !== null || $workspaceIdentifier !== null) {
                return null;
            }
            $identity = $this->principals->load($subjectId, 'portal-session:' . $sessionId);
            return $identity instanceof PortalPasswordIdentity
                ? new PortalSessionIdentity(
                    $identity->principal,
                    new PortalContext($site, null),
                    $identity->securityEpoch,
                )
                : null;
        }
        if ($membershipId === null) {
            return null;
        }
        $membership = $this->memberships->resolve(
            $subjectId,
            $site,
            $organizationIdentifier,
            $workspaceIdentifier,
        );
        if ($membership === null || $membership->membershipId() !== $membershipId) {
            return null;
        }
        $identity = $this->principals->load(
            $subjectId,
            'portal-session:' . $sessionId,
            $membership,
        );
        if (!$identity instanceof PortalPasswordIdentity) {
            return null;
        }

        return new PortalSessionIdentity(
            $identity->principal,
            new PortalContext($site, $membership),
            $identity->securityEpoch,
        );
    }
}
