<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use JsonException;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Context\Value\SiteContext;
use RuntimeException;

/** Adds immutable, site-owned content-type and workflow definition versions. */
final readonly class ContentModelRuntimeMigration implements RepeatableMigration
{
    public const ID = '20260805100000_content_model_runtime';

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
            throw new RuntimeException('The content model runtime migration checksum could not be calculated.');
        }
        return hash('sha256', self::ID . ':' . $checksum);
    }

    public function up(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        $before = $manager->introspectSchema();
        $after = clone $before;
        $this->extend($after);
        $difference = $manager->createComparator()->compareSchemas($before, $after);
        foreach ($database->getDatabasePlatform()->getAlterSchemaSQL($difference) as $statement) {
            $database->executeStatement($statement);
        }
        $this->backfill($database);
    }

    private function extend(Schema $schema): void
    {
        $workflows = $schema->getTable($this->tables->raw('workflows'));
        if (!$workflows->hasColumn('site_identifier')) {
            $workflows->addColumn(
                'site_identifier',
                Types::STRING,
                ['length' => 191, 'default' => SiteContext::DEFAULT],
            );
        }
        if ($workflows->hasIndex('uniq_workflow_handle')) {
            $workflows->dropIndex('uniq_workflow_handle');
        }
        if (!$workflows->hasIndex('uniq_workflow_site_handle')) {
            $workflows->addUniqueIndex(['site_identifier', 'handle'], 'uniq_workflow_site_handle');
        }

        $types = $schema->getTable($this->tables->raw('content_types'));
        if (!$types->hasColumn('site_identifier')) {
            $types->addColumn(
                'site_identifier',
                Types::STRING,
                ['length' => 191, 'default' => SiteContext::DEFAULT],
            );
        }
        if ($types->hasIndex('uniq_content_type_handle')) {
            $types->dropIndex('uniq_content_type_handle');
        }
        if (!$types->hasIndex('uniq_content_type_site_handle')) {
            $types->addUniqueIndex(['site_identifier', 'handle'], 'uniq_content_type_site_handle');
        }

        $entries = $schema->getTable($this->tables->raw('content_entries'));
        if (!$entries->hasColumn('site_identifier')) {
            $entries->addColumn(
                'site_identifier',
                Types::STRING,
                ['length' => 191, 'default' => SiteContext::DEFAULT],
            );
        }
        if (!$entries->hasColumn('content_type_version')) {
            $entries->addColumn('content_type_version', Types::INTEGER, ['default' => 1]);
        }
        if (!$entries->hasColumn('workflow_version')) {
            $entries->addColumn('workflow_version', Types::INTEGER, ['default' => 1]);
        }
        if ($entries->hasIndex('uniq_content_slug')) {
            $entries->dropIndex('uniq_content_slug');
        }
        if (!$entries->hasIndex('uniq_content_site_slug')) {
            $entries->addUniqueIndex(['site_identifier', 'slug'], 'uniq_content_site_slug');
        }
        if (!$entries->hasIndex('idx_content_definition_versions')) {
            $entries->addIndex(
                ['content_type_id', 'content_type_version', 'workflow_id', 'workflow_version'],
                'idx_content_definition_versions',
            );
        }
        if (!$entries->hasIndex('idx_content_site_updated')) {
            $entries->addIndex(['site_identifier', 'updated_at', 'id'], 'idx_content_site_updated');
        }

        $workflowVersionsName = $this->tables->raw('workflow_definition_versions');
        if (!$schema->hasTable($workflowVersionsName)) {
            $versions = $schema->createTable($workflowVersionsName);
            $versions->addColumn('workflow_id', Types::GUID);
            $versions->addColumn('version', Types::INTEGER);
            $versions->addColumn('site_identifier', Types::STRING, ['length' => 191]);
            $versions->addColumn('handle', Types::STRING, ['length' => 100]);
            $versions->addColumn('name', Types::STRING, ['length' => 255]);
            $versions->addColumn('states', Types::JSON);
            $versions->addColumn('transitions', Types::JSON);
            $versions->addColumn('public_states', Types::JSON);
            $versions->addColumn('created_at', Types::DATETIME_IMMUTABLE);
            $versions->addColumn('published_at', Types::DATETIME_IMMUTABLE);
            $versions->addPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setUnquotedColumnNames('workflow_id', 'version')->create(),
            );
            $versions->addIndex(['site_identifier', 'handle'], 'idx_workflow_version_site_handle');
        }

        $typeVersionsName = $this->tables->raw('content_type_definition_versions');
        if (!$schema->hasTable($typeVersionsName)) {
            $versions = $schema->createTable($typeVersionsName);
            $versions->addColumn('content_type_id', Types::GUID);
            $versions->addColumn('version', Types::INTEGER);
            $versions->addColumn('site_identifier', Types::STRING, ['length' => 191]);
            $versions->addColumn('handle', Types::STRING, ['length' => 100]);
            $versions->addColumn('name', Types::STRING, ['length' => 255]);
            $versions->addColumn('workflow_id', Types::GUID);
            $versions->addColumn('workflow_version', Types::INTEGER);
            $versions->addColumn('validation_schema', Types::JSON);
            $versions->addColumn('created_at', Types::DATETIME_IMMUTABLE);
            $versions->addColumn('published_at', Types::DATETIME_IMMUTABLE);
            $versions->addPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setUnquotedColumnNames('content_type_id', 'version')->create(),
            );
            $versions->addIndex(['site_identifier', 'handle'], 'idx_content_type_version_site_handle');
        }
    }

    private function backfill(Connection $database): void
    {
        $workflows = $database->fetchAllAssociative(
            sprintf('SELECT * FROM %s ORDER BY id', $this->tables->quoted('workflows')),
        );
        foreach ($workflows as $workflow) {
            $id = $this->string($workflow, 'id');
            $version = $this->integer($workflow, 'version');
            if ($this->versionExists($database, 'workflow_definition_versions', 'workflow_id', $id, $version)) {
                continue;
            }
            $states = $database->fetchAllAssociative(
                sprintf(
                    'SELECT * FROM %s WHERE workflow_id = ? ORDER BY state_key',
                    $this->tables->quoted('workflow_states'),
                ),
                [$id],
            );
            $transitions = $database->fetchAllAssociative(
                sprintf(
                    'SELECT * FROM %s WHERE workflow_id = ? ORDER BY from_state, to_state',
                    $this->tables->quoted('workflow_transitions'),
                ),
                [$id],
            );
            $stateDocuments = [];
            $publicStates = [];
            foreach ($states as $state) {
                $key = $this->string($state, 'state_key');
                $public = $this->boolean($state['is_public'] ?? false);
                $stateDocuments[] = [
                    'key' => $key,
                    'name' => $this->string($state, 'name'),
                    'initial' => $this->boolean($state['is_initial'] ?? false),
                    'public' => $public,
                ];
                if ($public) {
                    $publicStates[] = $key;
                }
            }
            $transitionDocuments = array_map(fn (array $transition): array => [
                'from' => $this->string($transition, 'from_state'),
                'to' => $this->string($transition, 'to_state'),
                'required_capability' => is_string($transition['required_capability'] ?? null)
                    ? $transition['required_capability']
                    : 'content.update',
            ], $transitions);
            $database->insert($this->tables->raw('workflow_definition_versions'), [
                'workflow_id' => $id,
                'version' => $version,
                'site_identifier' => $this->site($workflow),
                'handle' => $this->string($workflow, 'handle'),
                'name' => $this->string($workflow, 'name'),
                'states' => $stateDocuments,
                'transitions' => $transitionDocuments,
                'public_states' => $publicStates,
                'created_at' => $this->date($workflow['created_at'] ?? null),
                'published_at' => $this->date($workflow['updated_at'] ?? null),
            ], [
                'states' => Types::JSON,
                'transitions' => Types::JSON,
                'public_states' => Types::JSON,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'published_at' => Types::DATETIME_IMMUTABLE,
            ]);
            $this->ownership($database, 'workflow', $id, $this->site($workflow));
        }

        $types = $database->fetchAllAssociative(
            sprintf('SELECT * FROM %s ORDER BY id', $this->tables->quoted('content_types')),
        );
        foreach ($types as $type) {
            $id = $this->string($type, 'id');
            $version = $this->integer($type, 'version');
            if (
                !$this->versionExists(
                    $database,
                    'content_type_definition_versions',
                    'content_type_id',
                    $id,
                    $version,
                )
            ) {
                $database->insert($this->tables->raw('content_type_definition_versions'), [
                    'content_type_id' => $id,
                    'version' => $version,
                    'site_identifier' => $this->site($type),
                    'handle' => $this->string($type, 'handle'),
                    'name' => $this->string($type, 'name'),
                    'workflow_id' => $this->string($type, 'workflow_id'),
                    'workflow_version' => $this->workflowVersion(
                        $database,
                        $this->string($type, 'workflow_id'),
                    ),
                    'validation_schema' => $this->jsonObject($type['field_schema'] ?? null),
                    'created_at' => $this->date($type['created_at'] ?? null),
                    'published_at' => $this->date($type['updated_at'] ?? null),
                ], [
                    'validation_schema' => Types::JSON,
                    'created_at' => Types::DATETIME_IMMUTABLE,
                    'published_at' => Types::DATETIME_IMMUTABLE,
                ]);
            }
            $this->ownership($database, 'content_type', $id, $this->site($type));
        }

        $database->executeStatement(sprintf(
            'UPDATE %s SET content_type_version = '
            . 'COALESCE((SELECT t.version FROM %s t WHERE t.id = content_type_id), 1), '
            . 'workflow_version = COALESCE((SELECT w.version FROM %s w WHERE w.id = workflow_id), 1)',
            $this->tables->quoted('content_entries'),
            $this->tables->quoted('content_types'),
            $this->tables->quoted('workflows'),
        ));
    }

    private function ownership(Connection $database, string $type, string $id, string $site): void
    {
        $exists = $database->fetchOne(
            sprintf(
                'SELECT resource_id FROM %s WHERE resource_type = ? AND resource_id = ?',
                $this->tables->quoted('resource_site_ownership'),
            ),
            [$type, $id],
        );
        if ($exists === false) {
            $database->insert($this->tables->raw('resource_site_ownership'), [
                'resource_type' => $type,
                'resource_id' => $id,
                'site_identifier' => $site,
            ]);
        }
    }

    private function versionExists(
        Connection $database,
        string $table,
        string $idColumn,
        string $id,
        int $version,
    ): bool {
        return $database->fetchOne(
            sprintf(
                'SELECT version FROM %s WHERE %s = ? AND version = ?',
                $this->tables->quoted($table),
                $database->quoteSingleIdentifier($idColumn),
            ),
            [$id, $version],
        ) !== false;
    }

    private function workflowVersion(Connection $database, string $id): int
    {
        $value = $database->fetchOne(
            sprintf('SELECT version FROM %s WHERE id = ?', $this->tables->quoted('workflows')),
            [$id],
        );
        return is_numeric($value) ? (int) $value : 1;
    }

    /** @param array<string, mixed> $row */
    private function site(array $row): string
    {
        return is_string($row['site_identifier'] ?? null) ? $row['site_identifier'] : SiteContext::DEFAULT;
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('Stored content model ' . $key . ' is invalid.');
        }
        return $value;
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (!is_numeric($value) || (int) $value < 1) {
            throw new RuntimeException('Stored content model ' . $key . ' is invalid.');
        }
        return (int) $value;
    }

    private function boolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    private function date(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (is_string($value) && $value !== '') {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        }

        throw new RuntimeException('Stored content model timestamp is invalid.');
    }

    /** @return array<string, mixed> */
    private function jsonObject(mixed $value): array
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Stored content model schema is invalid.', 0, $exception);
            }
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new RuntimeException('Stored content model schema must be a JSON object.');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
