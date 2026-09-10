<?php

declare(strict_types=1);

namespace Kumwe\App\Portal\Application;

use Kumwe\App\BusinessSecurity\Application\MembershipDirectory;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Portal\Application\PortalContext;
use Kumwe\Context\Value\SiteContext;

/**
 * Deterministic portal context resolver over the canonical live membership directory.
 *
 * @since  2.0.0
 */
final readonly class MembershipPortalContextResolver implements PortalContextResolver
{
    /**
     * Bind resolution to a host-selected site and the canonical membership directory.
     *
     * @param  MembershipDirectory  $memberships  Live status, time-bound, workspace, and generation checks.
     * @param  SiteContext          $site         Site selected by trusted host routing.
     *
     * @since  2.0.0
     */
    public function __construct(
        private MembershipDirectory $memberships,
        private SiteContext $site,
    ) {
    }

    /**
     * Match a hint only against returned live selections and resolve the exact selection again.
     *
     * With no hint the first server-sorted live selection is used. A hint may name the organization or
     * workspace only when that identifies exactly one selection; ambiguous and unknown hints fail closed.
     *
     * @param   AuthenticatedPrincipal  $principal      Authenticated actor.
     * @param   ?string                 $workspaceHint  Optional selection hint.
     *
     * @return  ?PortalContext  Exact live membership context or null.
     *
     * @since   2.0.0
     */
    public function resolve(AuthenticatedPrincipal $principal, ?string $workspaceHint): ?PortalContext
    {
        $selections = $this->memberships->selections($principal->subject(), $this->site);
        if ($workspaceHint !== null) {
            $selections = array_values(array_filter(
                $selections,
                static fn (array $selection): bool => $selection['workspace'] === $workspaceHint
                    || $selection['organization'] === $workspaceHint,
            ));
            if (count($selections) !== 1) {
                return null;
            }
        }
        $selected = $selections[0] ?? null;
        if (!is_array($selected)) {
            return null;
        }
        $membership = $this->memberships->resolve(
            $principal->subject(),
            $this->site,
            $selected['organization'],
            $selected['workspace'],
        );
        if (
            $membership === null
            || $membership->membershipId() !== $selected['membership_id']
            || $membership->membershipVersion() !== $selected['membership_version']
            || $membership->policyGeneration() !== $selected['policy_generation']
        ) {
            return null;
        }

        return new PortalContext($this->site, $membership);
    }
}
