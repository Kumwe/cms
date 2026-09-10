<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Authorization;

use Kumwe\Context\Value\SiteContext;

/**
 * The single owner of a resource, held at a site, a declared group of sites, or the installation.
 *
 * Every resource keeps exactly one owner; this is that owner, widened from a bare site identifier to a
 * level plus the membership it resolves to. The membership is carried on the instance rather than looked
 * up during a decision, so `contains()` is a pure test the authorization gateway can run on the hot path
 * without a second query — group membership is administrative state resolved once, not transactional
 * state resolved per call. For a site scope `contains()` reduces to exactly the identifier equality it
 * replaced, which is the property that makes the widening safe for every resource owned today.
 *
 * @since  2.0.0
 */
final readonly class OwnershipScope
{
    /**
     * Member site identifiers this scope resolves to, sorted; empty for the installation level.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $sites;

    /**
     * Hold an already resolved scope.
     *
     * @param  OwnershipScopeLevel  $level       Level the owner is held at.
     * @param  string               $identifier  Site identifier, group identifier, or `*` for the installation.
     * @param  list<string>         $sites       Resolved member sites; empty at installation level.
     *
     * @since  2.0.0
     */
    private function __construct(
        public OwnershipScopeLevel $level,
        public string $identifier,
        array $sites,
    ) {
        $this->sites = $sites;
    }

    /**
     * Own a resource at one site, which is what every ownership row meant before groups existed.
     *
     * This is the constructor every existing `record()` call site translates to, so a caller that
     * legitimately means "this site owns it" keeps saying so and keeps the behaviour it had.
     *
     * @param   SiteContext  $site  Site that owns the resource.
     *
     * @return  self  A site-level scope whose membership is that one site.
     *
     * @since   2.0.0
     */
    public static function site(SiteContext $site): self
    {
        return new self(OwnershipScopeLevel::Site, $site->identifier(), [$site->identifier()]);
    }

    /**
     * Own a resource at a declared group, visible to that group's members and to nobody else.
     *
     * @param   SiteGroup  $group  Declared group, already resolved to the members it currently has.
     *
     * @return  self  A group-level scope carrying the group's membership.
     *
     * @since   2.0.0
     */
    public static function group(SiteGroup $group): self
    {
        return new self(OwnershipScopeLevel::Group, $group->identifier, $group->members);
    }

    /**
     * Own a resource at the installation, which a human may only reach with a global grant.
     *
     * The membership list is deliberately empty: an installation scope is not a set of sites an operator
     * maintains, and `DenyByDefaultAuthorizationGateway` answers it with the global-grant requirement
     * rather than with a containment test.
     *
     * @return  self  The installation-level scope.
     *
     * @since   2.0.0
     */
    public static function installation(): self
    {
        return new self(OwnershipScopeLevel::Installation, '*', []);
    }

    /**
     * Whether a site is inside this owning scope.
     *
     * For a site scope this is the identifier equality it replaced, byte for byte. For a group scope it
     * is membership of the declared set. For the installation scope it is true, because the installation
     * owns every site — the gateway still demands a global grant there, so answering true widens nothing.
     *
     * @param   SiteContext  $site  Site the caller is executing in.
     *
     * @return  bool  True when the site may reach resources this scope owns.
     *
     * @since   2.0.0
     */
    public function contains(SiteContext $site): bool
    {
        if ($this->level === OwnershipScopeLevel::Installation) {
            return true;
        }

        return in_array($site->identifier(), $this->sites, true);
    }

    /**
     * Whether this scope is the installation itself.
     *
     * @return  bool  True only at installation level, where a global human grant is required.
     *
     * @since   2.0.0
     */
    public function isInstallation(): bool
    {
        return $this->level === OwnershipScopeLevel::Installation;
    }

    /**
     * Whether two scopes name the same owner.
     *
     * Only the level and the identifier are compared. Two readings of one group taken either side of a
     * membership change are the same owner, which is what a compare-and-set on ownership must mean.
     *
     * @param   self  $other  Scope being compared against.
     *
     * @return  bool  True when both name the same owner.
     *
     * @since   2.0.0
     */
    public function equals(self $other): bool
    {
        return $this->level === $other->level && $this->identifier === $other->identifier;
    }

    /**
     * The single owning site, when there is one.
     *
     * @return  ?SiteContext  The owning site at site level, or null for a group or the installation.
     *
     * @since   2.0.0
     */
    public function siteOrNull(): ?SiteContext
    {
        return $this->level === OwnershipScopeLevel::Site ? SiteContext::fromString($this->identifier) : null;
    }

    /**
     * The single owning site, for a caller that can only act on behalf of one.
     *
     * Durable background work — a job, a schedule — runs as the site that owns it, so a caller building
     * an execution context needs one site rather than a set. Categories that carry such work are declared
     * site-only in `ResourceOwnershipScopePolicy`, so this refuses rather than choosing a member.
     *
     * @return  SiteContext  The owning site.
     *
     * @throws  OwnershipScopeNotSiteBound  When the scope is a group or the installation.
     *
     * @since   2.0.0
     */
    public function requireSite(): SiteContext
    {
        $site = $this->siteOrNull();
        if ($site === null) {
            throw new OwnershipScopeNotSiteBound($this);
        }

        return $site;
    }

    /**
     * Render the scope for an audit entry, a denial reason, or an operator-facing message.
     *
     * @return  string  `site:<identifier>`, `group:<identifier>`, or `installation:*`.
     *
     * @since   2.0.0
     */
    public function describe(): string
    {
        return $this->level->value . ':' . $this->identifier;
    }
}
