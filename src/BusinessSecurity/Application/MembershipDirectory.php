<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSecurity\Application;

use Kumwe\App\Application\Authorization\MembershipContextValidator;
use Kumwe\Context\Value\MembershipContext;
use Kumwe\Context\Value\SiteContext;

/**
 * Trusted resolver and freshness gate for organization and workspace membership.
 *
 * @since  2.0.0
 */
interface MembershipDirectory extends MembershipContextValidator
{
    /**
     * Resolve an exact selection from live membership storage.
     *
     * Inputs identify a requested stored selection; they never confer authority. The implementation
     * returns a context only when the subject currently belongs to the organization and workspace.
     *
     * @param   string       $subjectId               Canonical user identity.
     * @param   SiteContext  $site                    Exact authenticated site.
     * @param   string       $organizationIdentifier  Organization selected by a trusted session or token.
     * @param   ?string      $workspaceIdentifier     Optional workspace selected by that credential.
     * @param   bool         $lock                    Whether to lock the membership for a mutation.
     *
     * @return  ?MembershipContext  Live versioned membership, or null without revealing which check failed.
     *
     * @since   2.0.0
     */
    public function resolve(
        string $subjectId,
        SiteContext $site,
        string $organizationIdentifier,
        ?string $workspaceIdentifier = null,
        bool $lock = false,
    ): ?MembershipContext;

    /**
     * Recheck that a context's membership and policy generations are still current.
     *
     * @param   string             $subjectId   Actor expected to hold the membership.
     * @param   SiteContext        $site        Exact active site.
     * @param   MembershipContext  $membership  Snapshot to compare against live storage.
     * @param   bool               $lock        Whether to lock it for a following mutation.
     *
     * @return  bool  True only when status, time bounds, workspace assignment and both versions match.
     *
     * @since   2.0.0
     */
    public function current(
        string $subjectId,
        SiteContext $site,
        MembershipContext $membership,
        bool $lock = false,
    ): bool;

    /**
     * List only the active organization/workspace selections a subject may enter on one site.
     *
     * @param   string       $subjectId  Canonical user identity.
     * @param   SiteContext  $site       Exact authenticated site.
     *
     * @return  list<array{organization: string, workspace: ?string, membership_id: string,
     *          membership_version: int, policy_generation: int}>  Server-derived selections.
     *
     * @since   2.0.0
     */
    public function selections(string $subjectId, SiteContext $site): array;
}
