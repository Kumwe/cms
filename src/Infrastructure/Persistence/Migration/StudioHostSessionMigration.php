<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Name;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
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
 * Adds the opaque trusted-context bindings that fence Studio host-port calls.
 *
 * The table stores no credential, cookie, policy reason or client-asserted identity. Its immutable rows
 * retain only the server-resolved scope and generation needed to compare a later authenticated request.
 *
 * @since  2.0.0
 */
final readonly class StudioHostSessionMigration implements RepeatableMigration
{
    /**
     * Stable append-only migration identity.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260824020000_studio_host_sessions';

    /**
     * Exact mode capabilities introduced with the host-session boundary.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array CAPABILITIES = [
        'studio.mode.blueprint',
        'studio.mode.content',
        'studio.mode.hybrid',
        'studio.mode.model',
        'studio.mode.read-only',
    ];

    /**
     * Bind DDL and catalog reconciliation to prefix-aware table names.
     *
     * @param  TableNames  $tables  Installation table-name compiler.
     *
     * @since  2.0.0
     */
    public function __construct(private TableNames $tables)
    {
    }

    /**
     * Return the immutable ledger identity.
     *
     * @return  string  Stable migration identity.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * Bind applied history to this exact migration source.
     *
     * @return  string  SHA-256 migration checksum.
     *
     * @throws  RuntimeException  When the source digest cannot be read.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        $checksum = hash_file('sha256', __FILE__);
        if (!is_string($checksum)) {
            throw new RuntimeException('The Studio host-session migration checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $checksum);
    }

    /**
     * Create the opaque binding store and reconcile mode capabilities for upgraded installations.
     *
     * @param   Connection  $database  Installation database.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When schema or catalog persistence fails.
     * @throws  RuntimeException  When a partial table or seeded postcondition is invalid.
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        $before = $manager->introspectSchema();
        $after = clone $before;
        $name = $this->tables->raw('studio_host_sessions');
        $table = $after->hasTable($name) ? $after->getTable($name) : $after->createTable($name);

        foreach (
            [
                'resource_context_key' => 240,
                'actor_id' => 191,
                'site_identifier' => 191,
                'surface' => 63,
                'session_binding' => 64,
                'mode' => 20,
                'resource_kind' => 20,
                'resource_identifier' => 191,
                'session_generation' => 200,
            ] as $column => $length
        ) {
            if (!$table->hasColumn($column)) {
                $table->addColumn($column, Types::STRING, ['length' => $length]);
            }
        }
        foreach (['organization_identifier', 'workspace_identifier'] as $column) {
            if (!$table->hasColumn($column)) {
                $table->addColumn($column, Types::STRING, ['length' => 191, 'notnull' => false]);
            }
        }
        $primary = $table->getPrimaryKeyConstraint();
        if ($primary === null) {
            $table->addPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setUnquotedColumnNames('resource_context_key')->create(),
            );
        } else {
            $columns = array_map(
                static fn (Name $column): string => $column->toString(),
                $primary->getColumnNames(),
            );
            if ($columns !== ['resource_context_key']) {
                throw new RuntimeException('A partial Studio host-session table has an incompatible primary key.');
            }
        }
        $scopeIndex = ConstraintNameIsolationMigration::isolatedName($name, 'idx_studio_host_session_scope');
        if (!$table->hasIndex($scopeIndex)) {
            $table->addIndex(['actor_id', 'site_identifier'], $scopeIndex);
        }

        $difference = $manager->createComparator()->compareSchemas($before, $after);
        foreach ($database->getDatabasePlatform()->getAlterSchemaSQL($difference) as $statement) {
            $database->executeStatement($statement);
        }

        $this->synchronizeCapabilities($database);
        foreach (self::CAPABILITIES as $capability) {
            $persisted = $database->fetchOne(sprintf(
                'SELECT code FROM %s WHERE code = ?',
                $this->tables->quoted('capabilities'),
            ), [$capability]);
            if ($persisted !== $capability) {
                throw new RuntimeException('A Studio mode capability was not persisted.');
            }
        }
    }

    /**
     * Reconcile the typed core definitions and global administrator grants idempotently.
     *
     * @param   Connection  $database  Installation database containing the authority catalog.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When a catalog read or write fails.
     * @throws  RuntimeException  When an administrator role identity is malformed.
     *
     * @since   2.0.0
     */
    private function synchronizeCapabilities(Connection $database): void
    {
        $owner = ContributionOwner::core();
        $definitions = [];
        foreach (CoreExtensionContributions::capabilityDefinitions() as $definition) {
            if (!in_array($definition->id, self::CAPABILITIES, true)) {
                continue;
            }
            $definitions[] = $definition->id;
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
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $changedRoles = [];
        $roles = $database->fetchFirstColumn(sprintf(
            'SELECT id FROM %s WHERE code = ? ORDER BY id',
            $this->tables->quoted('roles'),
        ), ['administrator']);
        foreach ($roles as $roleId) {
            if (!is_string($roleId) || $roleId === '') {
                throw new RuntimeException('A stored administrator role identity is invalid.');
            }
            foreach ($definitions as $capability) {
                $grant = $database->fetchOne(sprintf(
                    'SELECT id FROM %s WHERE role_id = ? AND capability_code = ? '
                        . "AND scope_type = 'global' AND scope_identifier IS NULL",
                    $this->tables->quoted('role_capability_grants'),
                ), [$roleId, $capability]);
                if ($grant === false) {
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
                    $changedRoles[$roleId] = true;
                } elseif (is_string($grant) && $grant !== '') {
                    $grantId = $grant;
                } else {
                    throw new RuntimeException('A stored Studio mode grant identity is invalid.');
                }
                $this->ensureDefaultOwnership($database, 'grant', $grantId);
            }
        }
        if ($changedRoles !== []) {
            $placeholders = implode(', ', array_fill(0, count($changedRoles), '?'));
            $database->executeStatement(sprintf(
                'UPDATE %s SET security_epoch = security_epoch + 1 WHERE id IN ('
                    . 'SELECT DISTINCT user_id FROM %s WHERE role_id IN (%s))',
                $this->tables->quoted('users'),
                $this->tables->quoted('user_roles'),
                $placeholders,
            ), array_keys($changedRoles));
        }
    }

    /**
     * Record default-site ownership for a new authority-catalog row when absent.
     *
     * @param   Connection  $database      Installation database.
     * @param   string      $resourceType  Authorization resource type.
     * @param   string      $resourceId    Stable catalog-row identifier.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When ownership persistence fails.
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
}
