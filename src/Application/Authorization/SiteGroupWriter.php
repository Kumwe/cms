<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Authorization;

use Kumwe\Context\Value\SiteContext;

/**
 * Write side of the declared-group registry that `SiteGroupRegistry` reads.
 *
 * A group is a declaration an operator makes, so both halves of it are explicit: which sites are in, and
 * which are out. An implementation writes the declaration and its membership together, inside the
 * caller's transaction, because a group that exists with no membership owns resources nobody can reach.
 * Nothing here authorizes or audits — `SiteGroupAdministration` owns both, so every route to a
 * declaration change passes the same capability and leaves the same trail.
 *
 * @since  2.0.0
 */
interface SiteGroupWriter
{
    /**
     * Create or replace one declaration and its complete membership.
     *
     * @param   SiteGroup  $group  Declaration to store, carrying the exact membership it should end with.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function save(SiteGroup $group): void;

    /**
     * Bring one site into an existing declaration.
     *
     * @param   string       $group  Identifier of the declared group.
     * @param   SiteContext  $site   Site being included.
     *
     * @return  void
     *
     * @throws  SiteGroupUnknown  When no such group is declared.
     *
     * @since   2.0.0
     */
    public function addSite(string $group, SiteContext $site): void;

    /**
     * Take one site back out of an existing declaration.
     *
     * @param   string       $group  Identifier of the declared group.
     * @param   SiteContext  $site   Site being excluded.
     *
     * @return  void
     *
     * @throws  SiteGroupUnknown  When no such group is declared.
     *
     * @since   2.0.0
     */
    public function removeSite(string $group, SiteContext $site): void;
}
