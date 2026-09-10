<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use DateTimeImmutable;
use DateTimeZone;
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
 * Gives the administered half of the message override chain somewhere to live.
 *
 * The chain already resolved core, then extension, then site, then organization, and was proven to
 * resolve in that order — but its two upper steps were served from memory, so nothing an operator did
 * could reach them. This creates the table that holds them and seeds the capability that guards it,
 * which is what turns "relabel Client as Patient" from a fork into an administrative act.
 *
 * The row shape follows the chain rather than the storage engine. A row names its layer, its scope,
 * its exact locale and one message identifier, and carries the ICU pattern that replaces whatever the
 * layer below it said. A unique index over that whole identity is the enforcement of the one property
 * the resolver depends on: at most one pattern per layer, scope, locale and identifier, so resolution
 * can never depend on which of two rows the engine returned first.
 *
 * A site-scoped row spells its absent organization as the empty string rather than as null, and that
 * is the reason the index above can be trusted: all three engines treat two nulls in a unique index
 * as distinct, so a nullable column in the identity would have permitted exactly the duplicate the
 * index exists to refuse. Null remains the shape the application speaks — `MessageOverrideRecord`
 * carries `?string` — and the adapter translates at the boundary, which keeps the storage decision
 * out of the domain.
 *
 * The lookup index is deliberately the render path's whole predicate, in the order the statement
 * spells it, because reading a scope's overrides happens on every page that renders a message and an
 * index that does not cover the predicate would make the override chain a per-request table scan.
 *
 * Every step is guarded and re-runnable, so an attempt interrupted on a platform whose DDL commits
 * implicitly may simply be replayed.
 *
 * @since  2.0.0
 */
final readonly class InterfaceMessageOverrideMigration implements RepeatableMigration
{
    /**
     * Stable migration identity recorded in the schema ledger.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260817010000_interface_message_overrides';

    /**
     * Capability this step adds, granted to every existing administrator role.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const ADDED_CAPABILITIES = ['localization.overrides.manage'];

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
            throw new RuntimeException('The interface message override migration checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $digest);
    }

    /**
     * Create the override table and seed the capability that guards it.
     *
     * @param   Connection  $database  Connection the schema change runs on.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the table or the capability is missing once the step has run, or
     *          the stored administrator role identity is invalid.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects a statement.
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        $this->createOverrideTable($database);
        $this->synchronizeCapabilities($database);
        $this->assertApplied($database);
    }

    /**
     * Create the override table when it is absent.
     *
     * @param   Connection  $database  Connection the schema change runs on.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the statement.
     *
     * @since   2.0.0
     */
    private function createOverrideTable(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        $name = $this->tables->raw('message_overrides');
        if ($manager->tablesExist([$name])) {
            return;
        }

        $sites = $manager->introspectTableByUnquotedName($this->tables->raw('sites'));
        $reference = $sites->getColumn('identifier');

        $table = new Table($name);
        $table->addColumn('id', Types::STRING, ['length' => 36]);
        $table->addColumn('scope_level', Types::STRING, ['length' => 16]);
        $table->addColumn('site_identifier', Types::STRING, ['length' => 191]);
        $table->addColumn('organization_identifier', Types::STRING, ['length' => 191, 'default' => '']);
        $table->addColumn('locale_tag', Types::STRING, ['length' => 35]);
        $table->addColumn('message_identifier', Types::STRING, ['length' => 190]);
        $table->addColumn('pattern', Types::TEXT);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create(),
        );
        $table->addUniqueIndex(
            ['scope_level', 'site_identifier', 'organization_identifier', 'locale_tag', 'message_identifier'],
            $this->tables->raw('uq_message_override_identity'),
        );
        $table->addIndex(
            ['scope_level', 'site_identifier', 'locale_tag'],
            $this->tables->raw('idx_message_override_scope'),
        );
        $table->addForeignKeyConstraint(
            $this->tables->raw('sites'),
            ['site_identifier'],
            ['identifier'],
            ['onDelete' => 'CASCADE'],
            $this->tables->raw('fk_message_override_site'),
        );
        $this->alignCharacterDefinition($database, $reference, $table->getColumn('site_identifier'));
        $this->alignCharacterDefinition($database, $reference, $table->getColumn('organization_identifier'));

        $manager->createTable($table);
    }

    /**
     * Seed the capability this step adds and grant it to existing administrator roles.
     *
     * The typed core contribution catalogue is the single declaration, so this reconciles from the same
     * objects the live authorization registry consumes rather than repeating a capability list.
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
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
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
     * Give every administrator role a global grant of the new capability it does not already hold.
     *
     * @param   Connection         $database      Connection the grants are written on.
     * @param   list<string>       $capabilities  Capability codes to grant.
     * @param   DateTimeImmutable  $now           Instant recorded against each new grant.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the stored administrator role identity is invalid.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects a statement.
     *
     * @since   2.0.0
     */
    private function grantToAdministrators(Connection $database, array $capabilities, DateTimeImmutable $now): void
    {
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
    private function ensureDefaultOwnership(Connection $database, string $resourceType, string $resourceId): void
    {
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
     * `sites.identifier` was created under; copying makes the foreign key and every later equality
     * join between them legal instead of an illegal mix of collations.
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
     * Prove the table, its identity index and the capability this step promises are all in place.
     *
     * @param   Connection  $database  Connection the postconditions are read from.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the table, a column, the identity index or the capability is missing.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects a statement.
     *
     * @since   2.0.0
     */
    private function assertApplied(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        $name = $this->tables->raw('message_overrides');
        if (!$manager->tablesExist([$name])) {
            throw new RuntimeException('The message override table was not created.');
        }

        $table = $manager->introspectTableByUnquotedName($name);
        $columns = [
            'id',
            'scope_level',
            'site_identifier',
            'organization_identifier',
            'locale_tag',
            'message_identifier',
            'pattern',
            'updated_at',
        ];
        foreach ($columns as $column) {
            if (!$table->hasColumn($column)) {
                throw new RuntimeException(sprintf(
                    'The message override table is missing its %s column.',
                    $column,
                ));
            }
        }
        if (!$table->hasIndex($this->tables->raw('uq_message_override_identity'))) {
            throw new RuntimeException('The message override table has no identity index.');
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
