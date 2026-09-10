<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Authorization;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Authorization\AuthorizationResourceOwnershipUnknown;
use Kumwe\App\Application\Authorization\OwnershipScope;
use Kumwe\App\Application\Authorization\OwnershipScopeLevel;
use Kumwe\App\Application\Authorization\ResourceSiteOwnership;
use Kumwe\App\Application\Authorization\SiteGroupRegistry;
use Kumwe\App\Application\Authorization\SiteGroupUnknown;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Context\Value\SiteContext;

/**
 * Read side of the resource-to-scope registry, answered from the prefixed `resource_site_ownership` table.
 *
 * This is the `ResourceSiteOwnership` the container wires behind `DenyByDefaultAuthorizationGateway`, so
 * every cross-site denial in the installation rests on what it returns. Four families of resource never
 * reach the ownership table: a `site` resource is its own owner, a `site_group` resource is owned by the
 * group it names, a collection has no single row to own, and the installation-wide types `isIntrinsic()`
 * lists belong to the default site. Everything else must produce a stored row that still resolves — a
 * site-scoped row whose site is enabled, or a group-scoped row whose group still has an enabled member —
 * and a resource with no such answer is reported as unowned rather than credited to the calling site,
 * which is what makes the gateway fail closed instead of handing a caller any resource it happens to name.
 * Rows are maintained by `DoctrineResourceSiteOwnershipWriter` — nothing here writes, and no missing
 * record is ever repaired on read.
 *
 * @since  2.0.0
 */
final readonly class DoctrineResourceSiteOwnership implements ResourceSiteOwnership
{
    /**
     * Bind the resolver to the connection, table map and group declarations its lookup runs against.
     *
     * @param  Connection         $database  DBAL connection the ownership rows are read from.
     * @param  TableNames         $tables    Resolver applying the configured prefix to the
     *         `resource_site_ownership` and `sites` tables.
     * @param  SiteGroupRegistry  $groups    Declared groups a group-scoped row resolves its membership from.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private SiteGroupRegistry $groups,
    ) {
    }

    /**
     * Resolve the scope that owns a resource, from stored records rather than from the caller.
     *
     * A `site` resource resolves to itself and a `site_group` resource to the group it names; a collection
     * or an intrinsic installation-wide resource resolves to the default site, all without reading the
     * ownership table. Every other resource is looked up, and an answer that does not come back — no row,
     * a row naming a site an operator has disabled, or a row naming a group whose members are all disabled
     * — is reported as unknown rather than guessed at.
     *
     * @param   AuthorizationResource  $resource  Target whose owning scope is being established.
     *
     * @return  OwnershipScope  The owner, which the gateway tests the caller's site for membership of.
     *
     * @throws  AuthorizationResourceOwnershipUnknown  When no reachable owner is recorded for the resource.
     * @throws  \InvalidArgumentException  When a `site` resource carries an identifier that is not a valid
     *          site identifier, since `AuthorizationResource` accepts a wider alphabet than `SiteContext`.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the ownership lookup.
     *
     * @since   2.0.0
     */
    public function scopeFor(AuthorizationResource $resource): OwnershipScope
    {
        if ($resource->type() === 'site') {
            return OwnershipScope::site(SiteContext::fromString($resource->identifier()));
        }
        if ($resource->type() === 'site_group' && $resource->identifier() !== '*') {
            try {
                return OwnershipScope::group($this->groups->group($resource->identifier()));
            } catch (SiteGroupUnknown) {
                throw new AuthorizationResourceOwnershipUnknown($resource);
            }
        }
        if ($resource->identifier() === '*' || $this->isIntrinsic($resource)) {
            return OwnershipScope::site(SiteContext::default());
        }

        $scope = $this->lookup($resource);
        if ($scope === null) {
            throw new AuthorizationResourceOwnershipUnknown($resource);
        }

        return $scope;
    }

    /**
     * Read the owning scope recorded against a resource, restricted to owners that still resolve.
     *
     * The left join on `sites` carries the enabled test the previous inner join made: for a site-scoped
     * row the joined identifier comes back exactly when the old query would have matched, so the
     * fail-closed contract is unchanged byte for byte. A group-scoped row leaves `site_identifier` null,
     * so the join contributes nothing and the membership is resolved from the declared-group registry,
     * which answers from a set it read once rather than from a second query per decision.
     *
     * @param   AuthorizationResource  $resource  Target whose ownership row is being read.
     *
     * @return  ?OwnershipScope  The owning scope, or null when no reachable owner claims the resource.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the lookup.
     *
     * @since   2.0.0
     */
    private function lookup(AuthorizationResource $resource): ?OwnershipScope
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT o.scope_level, o.group_identifier, s.identifier AS enabled_site FROM %s o '
            . 'LEFT JOIN %s s ON s.identifier = o.site_identifier AND s.enabled = ? '
            . 'WHERE o.resource_type = ? AND o.resource_id = ?',
            $this->tables->quoted('resource_site_ownership'),
            $this->tables->quoted('sites'),
        ), [true, $resource->type(), $resource->identifier()], [
            Types::BOOLEAN,
            Types::STRING,
            Types::STRING,
        ]);

        if ($row === false) {
            return null;
        }

        $level = is_string($row['scope_level'] ?? null)
            ? OwnershipScopeLevel::tryFrom($row['scope_level'])
            : null;
        $site = $row['enabled_site'] ?? null;
        $group = $row['group_identifier'] ?? null;

        return match ($level) {
            OwnershipScopeLevel::Site => is_string($site) && $site !== ''
                ? OwnershipScope::site(SiteContext::fromString($site))
                : null,
            OwnershipScopeLevel::Group => $this->groupScope($group),
            OwnershipScopeLevel::Installation => OwnershipScope::installation(),
            default => null,
        };
    }

    /**
     * Resolve a group-scoped row against the declared groups, failing closed on anything unusable.
     *
     * @param   mixed  $identifier  Group identifier as the driver returned it.
     *
     * @return  ?OwnershipScope  The group scope, or null when the value is unusable or the group has no
     *          enabled member left.
     *
     * @since   2.0.0
     */
    private function groupScope(mixed $identifier): ?OwnershipScope
    {
        if (!is_string($identifier) || $identifier === '') {
            return null;
        }

        try {
            return OwnershipScope::group($this->groups->group($identifier));
        } catch (SiteGroupUnknown) {
            return null;
        }
    }

    /**
     * Decide whether a resource belongs to the installation itself rather than to one of its sites.
     *
     * These types are never given a `resource_site_ownership` row, so without this test they would all
     * resolve as unowned and be denied. `database_schema`, `extension_runtime_map` and `theme` qualify only
     * for their singleton identifiers, which keeps any other identifier under those types on the ordinary
     * lookup path instead of granting it the default site by accident.
     *
     * @param   AuthorizationResource  $resource  Target being classified.
     *
     * @return  bool  True when the resource is installation-wide and therefore owned by the default site.
     *
     * @since   2.0.0
     */
    private function isIntrinsic(AuthorizationResource $resource): bool
    {
        return match ($resource->type()) {
            'administrator' => true,
            'audit_trail' => true,
            'automation_installation' => true,
            'database_schema' => $resource->identifier() === 'current',
            'extension_runtime_map' => $resource->identifier() === 'active',
            'extension_trust_key' => true,
            // Queues are configured transport partitions, not durable business resources.
            'queue' => true,
            'theme' => in_array($resource->identifier(), ['site', 'administrator'], true),
            default => false,
        };
    }
}
