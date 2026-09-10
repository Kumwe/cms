<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Authorization;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Application\Authorization\SiteGroup;
use Kumwe\App\Application\Authorization\SiteGroupUnknown;
use Kumwe\App\Application\Authorization\SiteGroupWriter;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Context\Value\SiteContext;
use Psr\Clock\ClockInterface;

/**
 * Write side of the declared-group registry, kept in the prefixed `site_groups` tables.
 *
 * Declarations and membership are written together inside the caller's transaction, so a group is never
 * durable without the sites it names. The foreign key on membership does the rest: a site that does not
 * exist cannot be included, and retiring a site withdraws it from every group it was declared in. After
 * each change the memoized read side is told to forget what it resolved, so an operator sees the effect
 * of an inclusion immediately rather than at the next process start.
 *
 * @since  2.0.0
 */
final readonly class DoctrineSiteGroupWriter implements SiteGroupWriter
{
    /**
     * Bind the writer to the connection, table map, read side and clock it records with.
     *
     * @param  Connection                 $database  DBAL connection the declarations are written on.
     * @param  TableNames                 $tables    Resolver applying the configured prefix to the group tables.
     * @param  DoctrineSiteGroupRegistry  $registry  Memoized read side invalidated after every change.
     * @param  ClockInterface             $clock     Source of the instants stored against a declaration.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private DoctrineSiteGroupRegistry $registry,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Create or replace one declaration and its complete membership.
     *
     * The membership is rewritten as a set rather than merged, because the declaration the caller hands
     * over is the whole of what the group should end up being; merging would make an omission invisible.
     *
     * @param   SiteGroup  $group  Declaration to store, carrying the exact membership it should end with.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects a statement, an unknown member site
     *          included.
     *
     * @since   2.0.0
     */
    public function save(SiteGroup $group): void
    {
        $now = $this->clock->now();
        $existing = $this->database->fetchOne(sprintf(
            'SELECT identifier FROM %s WHERE identifier = ?',
            $this->tables->quoted('site_groups'),
        ), [$group->identifier]);

        if ($existing === false) {
            $this->database->insert($this->tables->raw('site_groups'), [
                'identifier' => $group->identifier,
                'name' => $group->name,
                'created_at' => $now,
            ], ['created_at' => Types::DATETIME_IMMUTABLE]);
        } else {
            $this->database->update(
                $this->tables->raw('site_groups'),
                ['name' => $group->name],
                ['identifier' => $group->identifier],
            );
        }

        $placeholders = implode(', ', array_fill(0, count($group->members), '?'));
        $this->database->executeStatement(sprintf(
            'DELETE FROM %s WHERE group_identifier = ? AND site_identifier NOT IN (%s)',
            $this->tables->quoted('site_group_members'),
            $placeholders,
        ), [$group->identifier, ...$group->members]);
        foreach ($group->members as $member) {
            $this->insertMember($group->identifier, $member, $now);
        }
        $this->registry->forget();
    }

    /**
     * Bring one site into an existing declaration.
     *
     * @param   string       $group  Identifier of the declared group.
     * @param   SiteContext  $site   Site being included.
     *
     * @return  void
     *
     * @throws  SiteGroupUnknown  When no such group is declared.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the insert.
     *
     * @since   2.0.0
     */
    public function addSite(string $group, SiteContext $site): void
    {
        $identifier = $this->assertDeclared($group);
        $this->insertMember($identifier, $site->identifier(), $this->clock->now());
        $this->registry->forget();
    }

    /**
     * Take one site back out of an existing declaration.
     *
     * @param   string       $group  Identifier of the declared group.
     * @param   SiteContext  $site   Site being excluded.
     *
     * @return  void
     *
     * @throws  SiteGroupUnknown  When no such group is declared.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the delete.
     *
     * @since   2.0.0
     */
    public function removeSite(string $group, SiteContext $site): void
    {
        $identifier = $this->assertDeclared($group);
        $this->database->delete($this->tables->raw('site_group_members'), [
            'group_identifier' => $identifier,
            'site_identifier' => $site->identifier(),
        ]);
        $this->registry->forget();
    }

    /**
     * Add one membership row, leaving an existing one alone.
     *
     * @param   string              $group  Identifier of the declared group.
     * @param   string              $site   Identifier of the member site.
     * @param   \DateTimeImmutable  $now    Instant recorded against a new membership row.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the insert.
     *
     * @since   2.0.0
     */
    private function insertMember(string $group, string $site, \DateTimeImmutable $now): void
    {
        $existing = $this->database->fetchOne(sprintf(
            'SELECT site_identifier FROM %s WHERE group_identifier = ? AND site_identifier = ?',
            $this->tables->quoted('site_group_members'),
        ), [$group, $site]);
        if ($existing !== false) {
            return;
        }

        $this->database->insert($this->tables->raw('site_group_members'), [
            'group_identifier' => $group,
            'site_identifier' => $site,
            'added_at' => $now,
        ], ['added_at' => Types::DATETIME_IMMUTABLE]);
    }

    /**
     * Refuse a membership change aimed at a group nobody has declared.
     *
     * The declaration row is read rather than the resolved group, so a group whose sites are all
     * currently disabled can still be repaired by including an enabled one.
     *
     * @param   string  $group  Identifier the caller named.
     *
     * @return  string  The normalised identifier as stored.
     *
     * @throws  SiteGroupUnknown  When no such group is declared.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the lookup.
     *
     * @since   2.0.0
     */
    private function assertDeclared(string $group): string
    {
        $identifier = strtolower(trim($group));
        $stored = $this->database->fetchOne(sprintf(
            'SELECT identifier FROM %s WHERE identifier = ?',
            $this->tables->quoted('site_groups'),
        ), [$identifier]);
        if (!is_string($stored) || $stored === '') {
            throw new SiteGroupUnknown($group);
        }

        return $stored;
    }
}
