<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Application\Authorization\OwnershipScopeLevel;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Extension\Contribution\ContributionDefinitionChecksum;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\App\Extension\Contribution\CoreExtensionContributions;
use Kumwe\App\Extension\Runtime\RuntimeCanonicalJson;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Widens the ownership registry from a bare site identifier to a scope, and declares site groups.
 *
 * Ownership already answered "which site owns this resource" with one row per resource, and that single
 * answer is the property every cross-site denial rests on. This step keeps the row and the primary key
 * exactly as they were and widens what the row may say: a level beside the identifier, so an owner may
 * be one site, a declared group of sites, or the installation. Every row that exists when this runs is a
 * site owner and comes out the far side owning at site scope with the same identifier, which is why an
 * installation that never declares a group behaves identically afterwards.
 *
 * `site_identifier` becomes nullable rather than being replaced, so the foreign key to `sites` and its
 * cascade survive untouched for every site-scoped row; a group-scoped row leaves it null and names its
 * group instead, under a foreign key that refuses to delete a group while it still owns anything. The
 * two owning columns are joined by a check constraint on the engines that support one, so a row that
 * spells no owner, or spells two, cannot be stored at all.
 *
 * MySQL and MariaDB resolve a new table's character set from the database default, which is how a join
 * between two correctly declared tables can still fail with an illegal mix of collations. The group
 * tables and the new owning column therefore copy their character definition from `sites.identifier`,
 * the column they are compared against, rather than trusting the default to agree.
 *
 * Every step is guarded and re-runnable, so an attempt interrupted on a platform whose DDL commits
 * implicitly may simply be replayed.
 *
 * @since  2.0.0
 */
final readonly class ResourceOwnershipScopeMigration implements RepeatableMigration
{
    /**
     * Stable migration identity recorded in the schema ledger.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260816010000_resource_ownership_scope';

    /**
     * Capabilities this step adds, mapped to the administrator grant they need to be usable.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const ADDED_CAPABILITIES = [
        'ownership.scope.manage',
        'reports.consolidated.read',
        'sites.group.manage',
    ];

    /**
     * Bind the migration to the prefixed table map.
     *
     * @param  TableNames  $tables  Resolver applying the configured prefix to table names.
     *
     * @since  2.0.0
     */
    public function __construct(private TableNames $tables)
    {
    }

    /**
     * Name the identity recorded for this migration in the schema ledger.
     *
     * @return  string  The stable migration identifier.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * Derive the ledger checksum from this file's bytes so any edit is detected.
     *
     * @return  string  Stable digest binding the recorded version to this exact implementation.
     *
     * @throws  RuntimeException  When the file digest cannot be calculated.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        $digest = hash_file('sha256', __FILE__);
        if (!is_string($digest)) {
            throw new RuntimeException('The resource ownership scope migration checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $digest);
    }

    /**
     * Declare the group tables, widen the ownership row to a scope, and seed the new capabilities.
     *
     * @param   Connection  $database  Connection the schema change runs on.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a required table, column or constraint is missing once the step
     *          has run, or the stored administrator role identity is invalid.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects a statement.
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        $this->createGroupTables($database);
        $this->widenOwnership($database);
        $this->backfillSiteScope($database);
        $this->addOwnerCheckConstraint($database);
        $this->synchronizeCapabilities($database);
        $this->assertApplied($database);
    }

    /**
     * Create the declared-group tables when they are absent.
     *
     * Membership cascades from both sides: dropping a group takes its membership rows with it, and so
     * does retiring a site, which is the same treatment `resource_site_ownership` already gives a site.
     *
     * @param   Connection  $database  Connection the schema change runs on.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the statement.
     *
     * @since   2.0.0
     */
    private function createGroupTables(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        $sites = $manager->introspectTableByUnquotedName($this->tables->raw('sites'));
        $reference = $sites->getColumn('identifier');
        $groupsName = $this->tables->raw('site_groups');
        $membersName = $this->tables->raw('site_group_members');

        if (!$manager->tablesExist([$groupsName])) {
            $groups = new Table($groupsName);
            $groups->addColumn('identifier', Types::STRING, ['length' => 191]);
            $groups->addColumn('name', Types::STRING, ['length' => 191]);
            $groups->addColumn('created_at', Types::DATETIME_IMMUTABLE);
            $groups->addPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setUnquotedColumnNames('identifier')->create(),
            );
            $this->alignCharacterDefinition($database, $reference, $groups->getColumn('identifier'));
            $manager->createTable($groups);
        }

        if (!$manager->tablesExist([$membersName])) {
            $members = new Table($membersName);
            $members->addColumn('group_identifier', Types::STRING, ['length' => 191]);
            $members->addColumn('site_identifier', Types::STRING, ['length' => 191]);
            $members->addColumn('added_at', Types::DATETIME_IMMUTABLE);
            $members->addPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()
                    ->setUnquotedColumnNames('group_identifier', 'site_identifier')
                    ->create(),
            );
            $members->addIndex(['site_identifier'], $this->tables->raw('idx_site_group_member_site'));
            $members->addForeignKeyConstraint(
                $groupsName,
                ['group_identifier'],
                ['identifier'],
                ['onDelete' => 'CASCADE'],
                $this->tables->raw('fk_site_group_member_group'),
            );
            $members->addForeignKeyConstraint(
                $this->tables->raw('sites'),
                ['site_identifier'],
                ['identifier'],
                ['onDelete' => 'CASCADE'],
                $this->tables->raw('fk_site_group_member_site'),
            );
            $this->alignCharacterDefinition($database, $reference, $members->getColumn('group_identifier'));
            $this->alignCharacterDefinition($database, $reference, $members->getColumn('site_identifier'));
            $manager->createTable($members);
        }
    }

    /**
     * Add the scope columns to the ownership row and let the site column stand empty for a wider owner.
     *
     * Nothing existing is dropped: `site_identifier` keeps its foreign key and its index, and only its
     * obligation to be present is lifted, so every stored row keeps the exact meaning it had. Its
     * character definition is re-derived from `sites.identifier` at the same time, so the pin the
     * portability repair established survives this alteration rather than being regenerated from a
     * platform default.
     *
     * @param   Connection  $database  Connection the schema change runs on.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the statement.
     *
     * @since   2.0.0
     */
    private function widenOwnership(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        $name = $this->tables->raw('resource_site_ownership');
        $reference = $manager->introspectTableByUnquotedName($this->tables->raw('sites'))->getColumn('identifier');
        $before = $manager->introspectTableByUnquotedName($name);
        $after = clone $before;

        if (!$after->hasColumn('scope_level')) {
            $after->addColumn('scope_level', Types::STRING, [
                'length' => 16,
                'default' => OwnershipScopeLevel::Site->value,
            ]);
        }
        if (!$after->hasColumn('group_identifier')) {
            $after->addColumn('group_identifier', Types::STRING, ['length' => 191, 'notnull' => false]);
        }
        $this->alignCharacterDefinition($database, $reference, $after->getColumn('group_identifier'));
        $this->alignCharacterDefinition($database, $reference, $after->getColumn('site_identifier'));
        $after->getColumn('site_identifier')->setNotnull(false);
        if (!$after->hasIndex($this->tables->raw('idx_resource_ownership_group'))) {
            $after->addIndex(
                ['group_identifier', 'resource_type'],
                $this->tables->raw('idx_resource_ownership_group'),
            );
        }
        if (!$after->hasForeignKey($this->tables->raw('fk_resource_ownership_group'))) {
            $after->addForeignKeyConstraint(
                $this->tables->raw('site_groups'),
                ['group_identifier'],
                ['identifier'],
                ['onDelete' => 'RESTRICT'],
                $this->tables->raw('fk_resource_ownership_group'),
            );
        }

        $difference = $manager->createComparator()->compareTables($before, $after);
        foreach ($database->getDatabasePlatform()->getAlterTableSQL($difference) as $statement) {
            $database->executeStatement($statement);
        }
    }

    /**
     * Give every row that existed before this step the site scope it already meant.
     *
     * Adding the column with a default already fills stored rows on all three engines; the statement is
     * repeated explicitly because the whole point of the step is that no row's meaning may be left to a
     * platform's behaviour, and because a replayed attempt must reach the same state.
     *
     * @param   Connection  $database  Connection the backfill runs on.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the statement.
     *
     * @since   2.0.0
     */
    private function backfillSiteScope(Connection $database): void
    {
        $database->executeStatement(sprintf(
            'UPDATE %s SET scope_level = ? WHERE scope_level IS NULL OR scope_level = ?',
            $this->tables->quoted('resource_site_ownership'),
        ), [OwnershipScopeLevel::Site->value, '']);
    }

    /**
     * Bind the two owning columns to the level, so an ownership row cannot spell no owner or two.
     *
     * MariaDB, MySQL 8.4 and PostgreSQL 17 all enforce a table check constraint, which makes the
     * exactly-one-owner invariant a property of the schema rather than of the code that writes it. The
     * statement is attempted once and its "already exists" answer is accepted, because a replay must
     * not fail on work the interrupted attempt completed.
     *
     * @param   Connection  $database  Connection the constraint is added on.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function addOwnerCheckConstraint(Connection $database): void
    {
        $constraint = $this->tables->raw('ck_resource_ownership_scope');
        $table = $this->tables->quoted('resource_site_ownership');
        try {
            $database->executeStatement(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s CHECK ('
                . "(scope_level = 'site' AND site_identifier IS NOT NULL AND group_identifier IS NULL) OR "
                . "(scope_level = 'group' AND site_identifier IS NULL AND group_identifier IS NOT NULL) OR "
                . "(scope_level = 'installation' AND site_identifier IS NULL AND group_identifier IS NULL))",
                $table,
                $database->quoteSingleIdentifier($constraint),
            ));
        } catch (\Doctrine\DBAL\Exception $failure) {
            // A replayed attempt meets the constraint the interrupted one already created. Every other
            // driver complaint is a real failure and is allowed to stop the migration.
            if (!$this->alreadyExists($failure)) {
                throw $failure;
            }
        }
    }

    /**
     * Decide whether a driver complaint means the constraint is already in place.
     *
     * @param   \Throwable  $failure  Driver exception raised by the constraint statement.
     *
     * @return  bool  True when the message names an existing or duplicated object.
     *
     * @since   2.0.0
     */
    private function alreadyExists(\Throwable $failure): bool
    {
        $message = strtolower($failure->getMessage());

        return str_contains($message, 'already exists')
            || str_contains($message, 'duplicate check constraint')
            || str_contains($message, 'duplicate key name');
    }

    /**
     * Seed the three capabilities this model adds and grant them to existing administrator roles.
     *
     * The typed core contribution catalogue is the single declaration, so this reconciles from the same
     * objects the live authorization registry consumes rather than repeating a capability list. Only the
     * capabilities this step introduces are touched, which leaves every already reconciled row and its
     * recorded declaration checksum exactly as the release that wrote it left them.
     *
     * @param   Connection  $database  Connection the seeding runs on.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the stored administrator role identity is invalid.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects a statement.
     *
     * @since   2.0.0
     */
    private function synchronizeCapabilities(Connection $database): void
    {
        $owner = ContributionOwner::core();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $granted = [];

        foreach (CoreExtensionContributions::capabilityDefinitions() as $definition) {
            if (!in_array($definition->id, self::ADDED_CAPABILITIES, true)) {
                continue;
            }
            $values = [
                'description' => $definition->description,
                'owner_kind' => 'core',
                'owner_identifier' => $owner->identifier(),
                'allowed_scopes' => RuntimeCanonicalJson::encode($definition->allowedScopes),
                'delegable' => $definition->delegatable,
                'high_impact' => $definition->highImpact,
                'definition_version' => $definition->version,
                'definition_checksum' => ContributionDefinitionChecksum::calculate($owner, $definition),
                'lifecycle_state' => $definition->lifecycle->value,
            ];
            $exists = $database->fetchOne(sprintf(
                'SELECT code FROM %s WHERE code = ?',
                $this->tables->quoted('capabilities'),
            ), [$definition->id]);
            if ($exists === false) {
                $database->insert($this->tables->raw('capabilities'), [
                    'code' => $definition->id,
                    ...$values,
                ], ['delegable' => Types::BOOLEAN, 'high_impact' => Types::BOOLEAN]);
            } else {
                $database->update(
                    $this->tables->raw('capabilities'),
                    $values,
                    ['code' => $definition->id],
                    ['delegable' => Types::BOOLEAN, 'high_impact' => Types::BOOLEAN],
                );
            }
            $this->ensureDefaultOwnership($database, 'capability', $definition->id);
            if ($definition->lifecycle->enforceable() && $definition->allowedScopes !== []) {
                $granted[] = $definition->id;
            }
        }

        $this->grantToAdministrators($database, $granted, $now);
    }

    /**
     * Give every administrator role a global grant of the new capabilities it does not already hold.
     *
     * @param   Connection           $database      Connection the grants are written on.
     * @param   list<string>         $capabilities  Capability codes to grant.
     * @param   \DateTimeImmutable   $now           Instant recorded against each new grant.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the stored administrator role identity is invalid.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects a statement.
     *
     * @since   2.0.0
     */
    private function grantToAdministrators(
        Connection $database,
        array $capabilities,
        \DateTimeImmutable $now,
    ): void {
        if ($capabilities === []) {
            return;
        }

        $changedRoles = [];
        $roles = $database->fetchFirstColumn(sprintf(
            'SELECT id FROM %s WHERE code = ? ORDER BY id',
            $this->tables->quoted('roles'),
        ), ['administrator']);
        foreach ($roles as $roleId) {
            if (!is_string($roleId) || $roleId === '') {
                throw new RuntimeException('The stored administrator role identity is invalid.');
            }
            foreach ($capabilities as $capability) {
                $grant = $database->fetchOne(sprintf(
                    'SELECT id FROM %s WHERE role_id = ? AND capability_code = ? '
                    . "AND scope_type = 'global' AND scope_identifier IS NULL",
                    $this->tables->quoted('role_capability_grants'),
                ), [$roleId, $capability]);
                if ($grant !== false) {
                    continue;
                }
                $grantId = Uuid::uuid5(
                    Uuid::NAMESPACE_URL,
                    'kumwe:administrator:' . $roleId . ':' . $capability,
                )->toString();
                $database->insert($this->tables->raw('role_capability_grants'), [
                    'id' => $grantId,
                    'role_id' => $roleId,
                    'capability_code' => $capability,
                    'scope_type' => 'global',
                    'scope_identifier' => null,
                    'granted_at' => $now,
                    'granted_by' => null,
                ], ['granted_at' => Types::DATETIME_IMMUTABLE]);
                $this->ensureDefaultOwnership($database, 'grant', $grantId);
                $changedRoles[$roleId] = true;
            }
        }

        if ($changedRoles === []) {
            return;
        }
        $placeholders = implode(', ', array_fill(0, count($changedRoles), '?'));
        $database->executeStatement(sprintf(
            'UPDATE %s SET security_epoch = security_epoch + 1 WHERE id IN ('
            . 'SELECT DISTINCT user_id FROM %s WHERE role_id IN (%s))',
            $this->tables->quoted('users'),
            $this->tables->quoted('user_roles'),
            $placeholders,
        ), array_keys($changedRoles));
    }

    /**
     * Record default-site ownership for an installation-wide row that has none yet.
     *
     * @param   Connection  $database      Connection the row is written on.
     * @param   string      $resourceType  Authorization resource type being recorded.
     * @param   string      $resourceId    Identifier of the resource being recorded.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects a statement.
     *
     * @since   2.0.0
     */
    private function ensureDefaultOwnership(
        Connection $database,
        string $resourceType,
        string $resourceId,
    ): void {
        $exists = $database->fetchOne(sprintf(
            'SELECT resource_id FROM %s WHERE resource_type = ? AND resource_id = ?',
            $this->tables->quoted('resource_site_ownership'),
        ), [$resourceType, $resourceId]);
        if ($exists !== false) {
            return;
        }

        $database->insert($this->tables->raw('resource_site_ownership'), [
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'site_identifier' => SiteContext::DEFAULT,
            'scope_level' => OwnershipScopeLevel::Site->value,
            'group_identifier' => null,
        ]);
    }

    /**
     * Copy the character definition of a proven identifier column onto a new one, on MySQL only.
     *
     * PostgreSQL has one collation for the database and needs no repair. On MySQL and MariaDB a table
     * created now resolves its character set from the database default, which may differ from the one
     * `sites.identifier` was created under; copying makes every later equality join between them legal
     * instead of an illegal mix of collations.
     *
     * @param   Connection  $database  Connection whose platform decides whether a copy is needed.
     * @param   Column      $source    Authoritative identifier supplying the character definition.
     * @param   Column      $target    New identifier column receiving it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function alignCharacterDefinition(Connection $database, Column $source, Column $target): void
    {
        if (!$database->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return;
        }
        $charset = $source->getCharset();
        $collation = $source->getCollation();
        if ($charset === null || $collation === null) {
            return;
        }

        $target->setPlatformOption('charset', $charset);
        $target->setPlatformOption('collation', $collation);
    }

    /**
     * Prove the tables, columns and rows this step promises are all in place.
     *
     * @param   Connection  $database  Connection the postconditions are read from.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a required table, column or capability is missing.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects a statement.
     *
     * @since   2.0.0
     */
    private function assertApplied(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        foreach (['site_groups', 'site_group_members'] as $table) {
            if (!$manager->tablesExist([$this->tables->raw($table)])) {
                throw new RuntimeException(sprintf('The %s table was not created.', $table));
            }
        }

        $ownership = $manager->introspectTableByUnquotedName($this->tables->raw('resource_site_ownership'));
        foreach (['scope_level', 'group_identifier'] as $column) {
            if (!$ownership->hasColumn($column)) {
                throw new RuntimeException(sprintf(
                    'The resource ownership table is missing its %s column.',
                    $column,
                ));
            }
        }
        if ($ownership->getColumn('site_identifier')->getNotnull()) {
            throw new RuntimeException('The resource ownership site column still refuses a wider owner.');
        }

        $unscoped = $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE scope_level IS NULL OR scope_level = ?',
            $this->tables->quoted('resource_site_ownership'),
        ), ['']);
        if (!is_int($unscoped) && !is_string($unscoped)) {
            throw new RuntimeException('The ownership scope backfill could not be counted.');
        }
        if ((string) $unscoped !== '0') {
            throw new RuntimeException('Some ownership rows were left without a scope level.');
        }

        foreach (self::ADDED_CAPABILITIES as $capability) {
            $stored = $database->fetchOne(sprintf(
                'SELECT code FROM %s WHERE code = ?',
                $this->tables->quoted('capabilities'),
            ), [$capability]);
            if ($stored !== $capability) {
                throw new RuntimeException(sprintf('The %s capability was not seeded.', $capability));
            }
        }
    }
}
