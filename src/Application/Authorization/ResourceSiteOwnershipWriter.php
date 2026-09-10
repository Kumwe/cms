<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Authorization;

use Kumwe\Context\Value\SiteContext;

/**
 * Write side of the resource-to-scope registry that `ResourceSiteOwnership` reads.
 *
 * Ownership is only trustworthy when it appears and disappears together with the resource it describes,
 * so implementations are called from inside the caller's transaction rather than opening one of their
 * own. Both failure directions matter: a resource left without a row becomes unreachable, since the
 * gateway denies it with `resource_site_unknown`, while a row outliving its resource keeps asserting an
 * owner that no longer exists. Removal is therefore matched against the owner the caller expects, and
 * says so when the record disagrees rather than deleting whatever it finds.
 *
 * Creation and removal still speak in sites, because a resource is born owned by the site that made it
 * and every existing call site legitimately means exactly that. Changing the owner of a living resource
 * is `reassign()`, which takes a proven `ResourceOwnership` and the owner the caller believes it is
 * replacing, so a concurrent change loses rather than silently wins.
 *
 * @since  2.0.0
 */
interface ResourceSiteOwnershipWriter
{
    /**
     * Record ownership in the same transaction that creates the resource.
     *
     * @param   AuthorizationResource  $resource  Resource being created; a collection has no owner to record.
     * @param   SiteContext            $site      Site that will own it until an operator widens the scope.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function record(AuthorizationResource $resource, SiteContext $site): void;

    /**
     * Remove ownership in the same transaction that physically deletes the resource.
     *
     * The expected site is part of the match, so a caller acting for the wrong site withdraws nothing and
     * is told so, rather than orphaning a resource that belongs to another site. A resource whose scope
     * has been widened past a single site is not removable this way, because the caller's belief about
     * the owner is already wrong.
     *
     * @param   AuthorizationResource  $resource      Resource being deleted; a collection has no row to remove.
     * @param   SiteContext            $expectedSite  Site the caller believes owns the resource.
     *
     * @return  void
     *
     * @throws  ResourceSiteOwnershipConflict  When a record exists but names a different owner.
     * @throws  AuthorizationResourceOwnershipUnknown  When no ownership record exists for the resource.
     *
     * @since   2.0.0
     */
    public function remove(AuthorizationResource $resource, SiteContext $expectedSite): void;

    /**
     * Move a living resource from one owning scope to another.
     *
     * The resource itself does not move; only the scope that owns it changes, so there is no data
     * migration and no window in which the resource is unowned. The expected owner is part of the match,
     * which makes the write a compare-and-set: two operators widening the same resource at once produce
     * one change and one refusal instead of a last-writer-wins race.
     *
     * @param   ResourceOwnership  $owner     Proven pairing of the resource with the scope it moves to.
     * @param   OwnershipScope     $expected  Scope the caller believes owns it now.
     *
     * @return  void
     *
     * @throws  ResourceSiteOwnershipConflict  When a record exists but names an owner other than the
     *          expected one.
     * @throws  AuthorizationResourceOwnershipUnknown  When no ownership record exists for the resource.
     *
     * @since   2.0.0
     */
    public function reassign(ResourceOwnership $owner, OwnershipScope $expected): void;
}
