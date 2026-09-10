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
 * Gives the posting-period lock somewhere to live and seeds the capabilities that administer it.
 *
 * The temporal lock refuses a business-record mutation whose declared posting date falls inside a
 * closed range, and this step creates the table those ranges are declared in. A row is one declaration:
 * a site, an optional organization, an extension-named stable key, a half-open UTC range, an open or
 * closed status, and the bookkeeping of its most recent close and re-open. Core stores no fiscal
 * meaning beside them — what a period is and when it closes stay the declaring extension's rules,
 * exercised through the capability-gated administration this step also arms.
 *
 * A site-wide declaration spells its absent organization as the empty string rather than as null,
 * because all three engines treat two nulls in a unique index as distinct and the (site, organization,
 * key) identity index is what keeps a key unambiguous; the repository translates back to null at the
 * boundary. The range's integrity is the engine's own rule: a check constraint refuses a row whose end
 * does not lie strictly past its start or whose status spells neither state, so no writer can store a
 * declaration the lock could not evaluate. The foreign-key name is digest-derived, never a readable
 * literal, because constraint names are schema-global on MySQL and MariaDB.
 *
 * Every step is guarded and re-runnable, so an attempt interrupted on a platform whose DDL commits
 * implicitly may simply be replayed.
 *
 * @since  2.0.0
 */
final readonly class PeriodPostingLockMigration implements RepeatableMigration
{
    /**
     * Stable migration identity recorded in the schema ledger.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260821010000_period_posting_lock';

    /**
     * Capabilities this step adds, granted to every existing administrator role.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const ADDED_CAPABILITIES = [
        'business.period.manage',
        'business.period.read',
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
            throw new RuntimeException('The period posting lock migration checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $digest);
    }

    /**
     * Create the posting-period table, bind its range rule, and seed the administering capabilities.
     *
     * @param   Connection  $database  Connection the schema change runs on.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the table or a capability is missing once the step has run, or
     *          the stored administrator role identity is invalid.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects a statement.
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        $this->createPeriodTable($database);
        $this->addRangeCheckConstraint($database);
        $this->synchronizeCapabilities($database);
        $this->assertApplied($database);
    }

    /**
     * Create the posting-period table when it is absent.
     *
     * @param   Connection  $database  Connection the schema change runs on.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the statement.
     *
     * @since   2.0.0
     */
    private function createPeriodTable(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        $name = $this->tables->raw('business_posting_periods');
        if ($manager->tablesExist([$name])) {
            return;
        }

        $sites = $manager->introspectTableByUnquotedName($this->tables->raw('sites'));
        $reference = $sites->getColumn('identifier');

        $table = new Table($name);
        $table->addColumn('id', Types::STRING, ['length' => 36]);
        $table->addColumn('site_identifier', Types::STRING, ['length' => 191]);
        $table->addColumn('organization_identifier', Types::STRING, ['length' => 191, 'default' => '']);
        $table->addColumn('period_key', Types::STRING, ['length' => 64]);
        $table->addColumn('starts_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('ends_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('status', Types::STRING, ['length' => 16]);
        $table->addColumn('closed_by', Types::STRING, ['length' => 191]);
        $table->addColumn('closed_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('reopened_by', Types::STRING, ['length' => 191, 'notnull' => false]);
        $table->addColumn('reopened_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create(),
        );
        $table->addUniqueIndex(
            ['site_identifier', 'organization_identifier', 'period_key'],
            $this->tables->raw('uq_posting_period_identity'),
        );
        $table->addIndex(
            ['site_identifier', 'organization_identifier', 'status', 'starts_at'],
            $this->tables->raw('idx_posting_period_range'),
        );
        $foreignKey = $this->foreignKeyName($name, 'site_identifier');
        $table->addForeignKeyConstraint(
            $this->tables->raw('sites'),
            ['site_identifier'],
            ['identifier'],
            ['onDelete' => 'CASCADE'],
            $foreignKey,
        );
        $this->alignCharacterDefinition($database, $reference, $table->getColumn('site_identifier'));
        $this->alignCharacterDefinition($database, $reference, $table->getColumn('organization_identifier'));

        $manager->createTable($table);
    }

    /**
     * Derive a foreign-key name that cannot collide with another table's constraint.
     *
     * InnoDB keeps constraint names in a dictionary far wider than the table they belong to, and a
     * readable literal such as `fk_posting_period_site` is exactly the kind of name two unrelated
     * tables end up claiming. Hashing the prefixed table and column makes the name unique by
     * construction while staying stable across runs, which lets a replay recognise its own work.
     *
     * @param   string  $table   Prefixed physical table name carrying the constraint.
     * @param   string  $column  Referencing column the constraint is declared on.
     *
     * @return  string  A 27-character constraint name, well inside every engine's identifier limit.
     *
     * @since   2.0.0
     */
    private function foreignKeyName(string $table, string $column): string
    {
        return 'fk_' . substr(hash('sha256', $table . ':' . $column), 0, 24);
    }

    /**
     * Bind the range and status rules to the engine, so a broken declaration cannot be stored at all.
     *
     * MariaDB, MySQL 8.4 and PostgreSQL 17 all enforce a table check constraint. The statement is
     * attempted once and its "already exists" answer is accepted, because a replay must not fail on
     * work the interrupted attempt completed. The constraint name is digest-derived for the same
     * schema-global reason the foreign key's is.
     *
     * @param   Connection  $database  Connection the constraint is added on.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the statement for any reason other
     *          than the constraint already existing.
     *
     * @since   2.0.0
     */
    private function addRangeCheckConstraint(Connection $database): void
    {
        $name = $this->tables->raw('business_posting_periods');
        $constraint = 'ck_' . substr(hash('sha256', $name . ':range'), 0, 24);
        try {
            $database->executeStatement(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s CHECK '
                . "(ends_at > starts_at AND status IN ('open', 'closed'))",
                $this->tables->quoted('business_posting_periods'),
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
     * Seed the capabilities this step adds and grant them to existing administrator roles.
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
     * Give every administrator role a global grant of the new capabilities it does not already hold.
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
     * Prove the table, its identity index and the capabilities this step promises are all in place.
     *
     * @param   Connection  $database  Connection the postconditions are read from.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the table, a column, the identity index or a capability is
     *          missing.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects a statement.
     *
     * @since   2.0.0
     */
    private function assertApplied(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        $name = $this->tables->raw('business_posting_periods');
        if (!$manager->tablesExist([$name])) {
            throw new RuntimeException('The posting period table was not created.');
        }

        $table = $manager->introspectTableByUnquotedName($name);
        $columns = [
            'id',
            'site_identifier',
            'organization_identifier',
            'period_key',
            'starts_at',
            'ends_at',
            'status',
            'closed_by',
            'closed_at',
            'reopened_by',
            'reopened_at',
        ];
        foreach ($columns as $column) {
            if (!$table->hasColumn($column)) {
                throw new RuntimeException(sprintf(
                    'The posting period table is missing its %s column.',
                    $column,
                ));
            }
        }
        if (!$table->hasIndex($this->tables->raw('uq_posting_period_identity'))) {
            throw new RuntimeException('The posting period table has no identity index.');
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
