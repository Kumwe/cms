<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Context\Value\SiteContext;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/** Adds application authorization state without changing the immutable core migration. */
final readonly class ApplicationAuthorizationMigration implements Migration
{
    public const ID = '20260805010000_application_authorization';

    /** @var array<string, array{table: string, column: string}> */
    private const OWNED_RESOURCES = [
        'administrator_session' => ['table' => 'administrator_sessions', 'column' => 'id'],
        'api_token' => ['table' => 'api_tokens', 'column' => 'id'],
        'capability' => ['table' => 'capabilities', 'column' => 'code'],
        'content' => ['table' => 'content_entries', 'column' => 'id'],
        'extension' => ['table' => 'extensions', 'column' => 'identifier'],
        'grant' => ['table' => 'role_capability_grants', 'column' => 'id'],
        'job' => ['table' => 'jobs', 'column' => 'id'],
        'menu' => ['table' => 'navigation_menus', 'column' => 'id'],
        'menu_item' => ['table' => 'navigation_items', 'column' => 'id'],
        'role' => ['table' => 'roles', 'column' => 'id'],
        'schedule' => ['table' => 'schedules', 'column' => 'id'],
        'user' => ['table' => 'users', 'column' => 'id'],
    ];

    public function __construct(private TableNames $tables)
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function checksum(): string
    {
        $checksum = hash_file('sha256', __FILE__);
        if (!is_string($checksum)) {
            throw new RuntimeException('The application authorization migration checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $checksum);
    }

    public function up(Connection $database): void
    {
        // Existing mutation replays cannot safely acquire the new authorization-bound lease.
        $database->executeStatement(sprintf(
            'DELETE FROM %s',
            $this->tables->quoted('idempotency'),
        ));

        $manager = $database->createSchemaManager();
        $before = $manager->introspectSchema();
        $after = clone $before;
        $this->extendSchema($after);
        $difference = $manager->createComparator()->compareSchemas($before, $after);

        foreach ($database->getDatabasePlatform()->getAlterSchemaSQL($difference) as $statement) {
            $database->executeStatement($statement);
        }

        $this->seed($database);
        $this->backfillOwnership($database);
    }

    private function extendSchema(Schema $schema): void
    {
        $schema->getTable($this->tables->raw('users'))
            ->addColumn('security_epoch', Types::INTEGER, ['default' => 1]);

        $sites = $schema->createTable($this->tables->raw('sites'));
        $sites->addColumn('identifier', Types::STRING, ['length' => 191]);
        $sites->addColumn('name', Types::STRING, ['length' => 191]);
        $sites->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $sites->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('identifier')->create(),
        );

        $ownership = $schema->createTable($this->tables->raw('resource_site_ownership'));
        $ownership->addColumn('resource_type', Types::STRING, ['length' => 63]);
        $ownership->addColumn('resource_id', Types::STRING, ['length' => 191]);
        $ownership->addColumn('site_identifier', Types::STRING, ['length' => 191]);
        $ownership->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()
                ->setUnquotedColumnNames('resource_type', 'resource_id')
                ->create(),
        );
        $ownership->addIndex(['site_identifier', 'resource_type'], 'idx_resource_site');
        $ownership->addForeignKeyConstraint(
            $this->tables->raw('sites'),
            ['site_identifier'],
            ['identifier'],
            ['onDelete' => 'CASCADE'],
            'fk_resource_site',
        );

        $idempotency = $schema->getTable($this->tables->raw('idempotency'));
        $idempotency->addColumn('authorization_fingerprint', Types::STRING, ['length' => 64, 'fixed' => true]);
        $idempotency->addColumn('lease_owner', Types::STRING, ['length' => 64]);
        $idempotency->addColumn('lease_expires_at', Types::DATETIME_IMMUTABLE);
    }

    private function seed(Connection $database): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $database->insert($this->tables->raw('sites'), [
            'identifier' => SiteContext::DEFAULT,
            'name' => 'Default site',
            'created_at' => $now,
        ], ['created_at' => Types::DATETIME_IMMUTABLE]);

        $capabilities = [
            'themes.administrator.manage' => 'Manage administrator presentation themes.',
            'themes.site.manage' => 'Manage public site presentation themes.',
        ];
        foreach ($capabilities as $code => $description) {
            $database->insert($this->tables->raw('capabilities'), [
                'code' => $code,
                'description' => $description,
            ]);
        }

        $administratorRoles = $database->fetchFirstColumn(sprintf(
            'SELECT id FROM %s WHERE code = ? ORDER BY id',
            $this->tables->quoted('roles'),
        ), ['administrator']);
        foreach ($administratorRoles as $roleId) {
            if (!is_string($roleId) || $roleId === '') {
                throw new RuntimeException('An administrator role identifier is invalid.');
            }
            foreach (array_keys($capabilities) as $capability) {
                $database->insert($this->tables->raw('role_capability_grants'), [
                    'id' => Uuid::uuid7()->toString(),
                    'role_id' => $roleId,
                    'capability_code' => $capability,
                    'scope_type' => 'global',
                    'scope_identifier' => null,
                    'granted_at' => $now,
                    'granted_by' => null,
                ], ['granted_at' => Types::DATETIME_IMMUTABLE]);
            }
            $database->executeStatement(sprintf(
                'UPDATE %s SET security_epoch = security_epoch + 1 WHERE id IN '
                . '(SELECT user_id FROM %s WHERE role_id = ?)',
                $this->tables->quoted('users'),
                $this->tables->quoted('user_roles'),
            ), [$roleId]);
        }
    }

    private function backfillOwnership(Connection $database): void
    {
        foreach (self::OWNED_RESOURCES as $resourceType => $mapping) {
            $column = $database->quoteSingleIdentifier($mapping['column']);
            $identifiers = $database->fetchFirstColumn(sprintf(
                'SELECT %s FROM %s ORDER BY %s',
                $column,
                $this->tables->quoted($mapping['table']),
                $column,
            ));
            foreach ($identifiers as $identifier) {
                if (!is_string($identifier) || $identifier === '') {
                    throw new RuntimeException(sprintf('A %s ownership identifier is invalid.', $resourceType));
                }
                $database->insert($this->tables->raw('resource_site_ownership'), [
                    'resource_type' => $resourceType,
                    'resource_id' => $identifier,
                    'site_identifier' => SiteContext::DEFAULT,
                ]);
            }
        }
    }
}
