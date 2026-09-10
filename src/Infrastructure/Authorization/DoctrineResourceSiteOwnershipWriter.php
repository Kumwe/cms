<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Authorization;

use Doctrine\DBAL\Connection;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Authorization\AuthorizationResourceOwnershipUnknown;
use Kumwe\App\Application\Authorization\OwnershipScope;
use Kumwe\App\Application\Authorization\OwnershipScopeLevel;
use Kumwe\App\Application\Authorization\ResourceOwnership;
use Kumwe\App\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\App\Application\Authorization\ResourceSiteOwnershipConflict;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Infrastructure\Persistence\TableNames;

/**
 * Write side of the resource-to-scope registry, kept in the prefixed `resource_site_ownership` table.
 *
 * This is the `ResourceSiteOwnershipWriter` the container wires, and the only supported way to create,
 * move or withdraw the rows `DoctrineResourceSiteOwnership` reads. It opens no transaction of its own, so
 * a caller must invoke it inside the transaction that creates, reassigns or deletes the resource itself;
 * that is what keeps a resource and its ownership row from ever existing apart. Withdrawal and
 * reassignment both match on the owner the caller expects as well as on the resource, so a caller acting
 * for the wrong owner changes nothing and is told which owner actually holds the record, and a resource
 * with no record at all is reported rather than passed over. Collection targets are refused outright,
 * since `*` names a family and no single row owns it.
 *
 * @since  2.0.0
 */
final readonly class DoctrineResourceSiteOwnershipWriter implements ResourceSiteOwnershipWriter
{
    /**
     * Bind the writer to the connection and table map its statements run against.
     *
     * @param  Connection  $database  DBAL connection the ownership rows are written on; the caller's open
     *         transaction, when there is one, is the scope these writes join.
     * @param  TableNames  $tables    Resolver applying the configured prefix to `resource_site_ownership`.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * Insert the row that ties a newly created resource to the site that owns it.
     *
     * The table is keyed on the resource type and identifier, so a second record for the same resource is
     * rejected by the driver instead of quietly reassigning it to another owner. A resource is always born
     * owned by one site; widening it to a group is a later, separately authorized act.
     *
     * @param   AuthorizationResource  $resource  Resource being created; must name one item, not a collection.
     * @param   SiteContext            $site      Site that owns it until an operator widens the scope.
     *
     * @return  void
     *
     * @throws  \InvalidArgumentException  When the resource is a collection, which has no ownership to record.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the insert, a duplicate ownership row
     *          included.
     *
     * @since   2.0.0
     */
    public function record(AuthorizationResource $resource, SiteContext $site): void
    {
        $this->assertItem($resource);

        $this->database->insert($this->tables->raw('resource_site_ownership'), [
            'resource_type' => $resource->type(),
            'resource_id' => $resource->identifier(),
            'site_identifier' => $site->identifier(),
            'scope_level' => OwnershipScopeLevel::Site->value,
            'group_identifier' => null,
        ]);
    }

    /**
     * Withdraw the ownership row for a resource being deleted, and only for the site that owns it.
     *
     * The expected site is part of the delete's match, so exactly one affected row proves the caller was
     * right about the owner and the method returns. Any other count triggers a second read on the resource
     * alone, purely to separate the two outcomes worth telling apart: a record that survives because it
     * names a different owner, and no record at all. The affected count is compared as a string because a
     * driver may report it as a numeric string rather than an int.
     *
     * @param   AuthorizationResource  $resource      Resource being deleted; must name one item, not a
     *          collection.
     * @param   SiteContext            $expectedSite  Site the caller believes owns the resource.
     *
     * @return  void
     *
     * @throws  \InvalidArgumentException  When the resource is a collection, which has no ownership to remove.
     * @throws  ResourceSiteOwnershipConflict  When a record survives the delete because it names another owner.
     * @throws  AuthorizationResourceOwnershipUnknown  When no ownership record exists for the resource.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the delete or the follow-up lookup.
     *
     * @since   2.0.0
     */
    public function remove(AuthorizationResource $resource, SiteContext $expectedSite): void
    {
        $this->assertItem($resource);
        $affected = $this->database->executeStatement(sprintf(
            'DELETE FROM %s WHERE resource_type = ? AND resource_id = ? AND scope_level = ? '
            . 'AND site_identifier = ?',
            $this->tables->quoted('resource_site_ownership'),
        ), [
            $resource->type(),
            $resource->identifier(),
            OwnershipScopeLevel::Site->value,
            $expectedSite->identifier(),
        ]);

        if ((string) $affected === '1') {
            return;
        }

        $this->refuse($resource, OwnershipScope::site($expectedSite));
    }

    /**
     * Move a living resource from the owner the caller expects to the one it has proven permitted.
     *
     * The stored owner is matched in the update itself, which makes the change a compare-and-set: a
     * concurrent widening of the same resource affects no row here and is refused rather than overwritten.
     * Only the owning columns change, so the resource does not move and no window exists in which it is
     * unowned.
     *
     * @param   ResourceOwnership  $owner     Proven pairing of the resource with the scope it moves to.
     * @param   OwnershipScope     $expected  Scope the caller believes owns it now.
     *
     * @return  void
     *
     * @throws  \InvalidArgumentException  When the resource is a collection, which has no ownership to move.
     * @throws  ResourceSiteOwnershipConflict  When a record exists but names an owner other than the
     *          expected one.
     * @throws  AuthorizationResourceOwnershipUnknown  When no ownership record exists for the resource.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the update or the follow-up lookup.
     *
     * @since   2.0.0
     */
    public function reassign(ResourceOwnership $owner, OwnershipScope $expected): void
    {
        $resource = $owner->resource;
        $this->assertItem($resource);
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET scope_level = ?, site_identifier = ?, group_identifier = ? '
            . 'WHERE resource_type = ? AND resource_id = ? AND scope_level = ? '
            . 'AND COALESCE(site_identifier, group_identifier, ?) = ?',
            $this->tables->quoted('resource_site_ownership'),
        ), [
            $owner->scope->level->value,
            $owner->scope->level === OwnershipScopeLevel::Site ? $owner->scope->identifier : null,
            $owner->scope->level === OwnershipScopeLevel::Group ? $owner->scope->identifier : null,
            $resource->type(),
            $resource->identifier(),
            $expected->level->value,
            $expected->identifier,
            $expected->identifier,
        ]);

        if ((string) $affected === '1') {
            return;
        }

        $this->refuse($resource, $expected);
    }

    /**
     * Explain why a matched write changed nothing, by reading the row on the resource alone.
     *
     * @param   AuthorizationResource  $resource  Resource whose ownership the caller tried to change.
     * @param   OwnershipScope         $expected  Owner the caller believed held it.
     *
     * @return  never
     *
     * @throws  ResourceSiteOwnershipConflict  When a record exists but names another owner.
     * @throws  AuthorizationResourceOwnershipUnknown  When no ownership record exists for the resource.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the lookup.
     *
     * @since   2.0.0
     */
    private function refuse(AuthorizationResource $resource, OwnershipScope $expected): never
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT scope_level, site_identifier, group_identifier FROM %s '
            . 'WHERE resource_type = ? AND resource_id = ?',
            $this->tables->quoted('resource_site_ownership'),
        ), [$resource->type(), $resource->identifier()]);

        if ($row !== false) {
            throw new ResourceSiteOwnershipConflict($resource, $expected, $this->storedScope($row));
        }

        throw new AuthorizationResourceOwnershipUnknown($resource);
    }

    /**
     * Read the owner a surviving row names, so a refusal can say who actually holds the resource.
     *
     * A row whose columns do not spell a usable owner is reported as the installation scope rather than
     * guessed at, because the only thing the caller needs to learn is that the owner is not the one it
     * expected.
     *
     * @param   array<string, mixed>  $row  Ownership row as the driver returned it.
     *
     * @return  OwnershipScope  The owner the stored row names.
     *
     * @since   2.0.0
     */
    private function storedScope(array $row): OwnershipScope
    {
        $level = is_string($row['scope_level'] ?? null)
            ? OwnershipScopeLevel::tryFrom($row['scope_level'])
            : null;
        $site = $row['site_identifier'] ?? null;
        $group = $row['group_identifier'] ?? null;

        if ($level === OwnershipScopeLevel::Site && is_string($site) && $site !== '') {
            return OwnershipScope::site(SiteContext::fromString($site));
        }
        if ($level === OwnershipScopeLevel::Group && is_string($group) && $group !== '') {
            return OwnershipScope::group(new \Kumwe\App\Application\Authorization\SiteGroup(
                $group,
                $group,
                [SiteContext::DEFAULT],
            ));
        }

        return OwnershipScope::installation();
    }

    /**
     * Refuse a target that names a whole resource family instead of one owned record.
     *
     * Guards every entry point before any statement runs, so a collection can neither create a row that
     * `DoctrineResourceSiteOwnership` would never look for nor match one that belongs to a real resource.
     *
     * @param   AuthorizationResource  $resource  Target the caller asked to record, move or withdraw
     *          ownership for.
     *
     * @return  void
     *
     * @throws  \InvalidArgumentException  When the resource identifier is `*`.
     *
     * @since   2.0.0
     */
    private function assertItem(AuthorizationResource $resource): void
    {
        if ($resource->identifier() === '*') {
            throw new \InvalidArgumentException('Collection resources cannot have an ownership record.');
        }
    }
}
