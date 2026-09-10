<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Content\Domain\ContentEntry;
use Kumwe\App\Content\Domain\ContentRevision;
use Kumwe\App\Content\Domain\ContentStatus;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Context\Value\SiteContext;
use RuntimeException;

/** Adds typed navigation targets and installs the editable example site without overwriting user content. */
final readonly class DynamicSiteContentMigration implements RepeatableMigration
{
    public const ID = '20260807120000_dynamic_site_content';

    private const HOMEPAGE_ID = '00000000-0000-7000-8000-000000001001';
    private const HOMEPAGE_REVISION_ID = '00000000-0000-7000-8000-000000001002';
    private const MAIN_MENU_ID = '00000000-0000-7000-8000-000000001101';

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
            throw new RuntimeException('The dynamic-site migration checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $checksum);
    }

    public function up(Connection $database): void
    {
        $this->extendNavigation($database);
        $this->backfillNavigationTargets($database);

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $pageVersion = $this->publishPageSchema($database, $now);
        $homepageId = $this->homepage($database, $pageVersion, $now);

        $this->seedSetting($database, 'site.name', 'Kumwe', $now);
        $this->seedSetting($database, 'site.homepage_slug', 'home', $now);
        $this->seedSetting($database, 'site.homepage_content_id', $homepageId, $now, true);
        $this->seedSetting($database, 'site.default_locale', 'en', $now);
        $this->seedSetting($database, 'site.timezone', 'UTC', $now);
        $this->seedSetting($database, 'search.indexing_enabled', true, $now);
        $this->mainMenu($database, $homepageId, $now);
    }

    private function extendNavigation(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        $before = $manager->introspectSchema();
        $after = clone $before;
        $items = $after->getTable($this->tables->raw('navigation_items'));
        if (!$items->hasColumn('target_type')) {
            $items->addColumn('target_type', Types::STRING, ['length' => 16, 'default' => 'content']);
        }
        if (!$items->hasColumn('content_id')) {
            $items->addColumn('content_id', Types::GUID, ['notnull' => false]);
        }
        if (!$items->hasColumn('target_url')) {
            $items->addColumn('target_url', Types::STRING, ['length' => 2048, 'notnull' => false]);
        }
        if (!$items->hasIndex('idx_navigation_content')) {
            $items->addIndex(['content_id'], 'idx_navigation_content');
        }
        $foreignKey = 'fk_' . substr(
            hash('sha256', $items->getObjectName()->toString() . ':content_id'),
            0,
            24,
        );
        if (!$items->hasForeignKey($foreignKey)) {
            $items->addForeignKeyConstraint(
                $this->tables->raw('content_entries'),
                ['content_id'],
                ['id'],
                ['onDelete' => 'SET NULL'],
                $foreignKey,
            );
        }

        $difference = $manager->createComparator()->compareSchemas($before, $after);
        foreach ($database->getDatabasePlatform()->getAlterSchemaSQL($difference) as $statement) {
            $database->executeStatement($statement);
        }
    }

    private function backfillNavigationTargets(Connection $database): void
    {
        $items = $database->fetchAllAssociative(sprintf(
            'SELECT id, menu_id, slug FROM %s WHERE content_id IS NULL AND target_url IS NULL ORDER BY id',
            $this->tables->quoted('navigation_items'),
        ));
        foreach ($items as $item) {
            $id = $this->string($item, 'id');
            $menuId = $this->string($item, 'menu_id');
            $slug = $this->string($item, 'slug');
            $site = $database->fetchOne(sprintf(
                'SELECT site_identifier FROM %s WHERE resource_type = ? AND resource_id = ?',
                $this->tables->quoted('resource_site_ownership'),
            ), ['menu', $menuId]);
            $site = is_string($site) && $site !== '' ? $site : SiteContext::DEFAULT;
            $contentId = $database->fetchOne(sprintf(
                'SELECT id FROM %s WHERE site_identifier = ? AND slug = ? AND deleted_at IS NULL',
                $this->tables->quoted('content_entries'),
            ), [$site, $slug]);
            if (is_string($contentId) && $contentId !== '') {
                $database->executeStatement(sprintf(
                    'UPDATE %s SET target_type = ?, content_id = ?, target_url = NULL WHERE id = ?',
                    $this->tables->quoted('navigation_items'),
                ), ['content', $contentId, $id]);
                continue;
            }

            $database->executeStatement(sprintf(
                'UPDATE %s SET target_type = ?, content_id = NULL, target_url = ? WHERE id = ?',
                $this->tables->quoted('navigation_items'),
            ), ['url', '/pages/' . $slug, $id]);
        }
    }

    private function publishPageSchema(Connection $database, DateTimeImmutable $now): int
    {
        $row = $database->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE id = ?',
            $this->tables->quoted('content_types'),
        ), [ContentService::CORE_PAGE_TYPE_ID]);
        if ($row === false) {
            throw new RuntimeException('The core Page content type is missing.');
        }
        $currentVersion = $this->integer($row, 'version');
        if ($currentVersion !== 1) {
            return $currentVersion;
        }

        $schema = $this->pageSchema();
        $database->executeStatement(sprintf(
            'UPDATE %s SET field_schema = ?, version = ?, updated_at = ? WHERE id = ? AND version = ?',
            $this->tables->quoted('content_types'),
        ), [$schema, 2, $now, ContentService::CORE_PAGE_TYPE_ID, 1], [
            Types::JSON,
            Types::INTEGER,
            Types::DATETIME_IMMUTABLE,
            Types::GUID,
            Types::INTEGER,
        ]);

        $exists = $database->fetchOne(sprintf(
            'SELECT version FROM %s WHERE content_type_id = ? AND version = ?',
            $this->tables->quoted('content_type_definition_versions'),
        ), [ContentService::CORE_PAGE_TYPE_ID, 2]);
        if ($exists === false) {
            $database->insert($this->tables->raw('content_type_definition_versions'), [
                'content_type_id' => ContentService::CORE_PAGE_TYPE_ID,
                'version' => 2,
                'site_identifier' => SiteContext::DEFAULT,
                'handle' => 'page',
                'name' => 'Page',
                'workflow_id' => ContentService::CORE_WORKFLOW_ID,
                'workflow_version' => 1,
                'validation_schema' => $schema,
                'created_at' => $now,
                'published_at' => $now,
            ], [
                'validation_schema' => Types::JSON,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'published_at' => Types::DATETIME_IMMUTABLE,
            ]);
        }

        return 2;
    }

    private function homepage(Connection $database, int $pageVersion, DateTimeImmutable $now): string
    {
        $configured = $this->setting($database, 'site.homepage_content_id');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $legacySlug = $this->setting($database, 'site.homepage_slug');
        $legacySlug = is_string($legacySlug) && $legacySlug !== '' ? $legacySlug : 'home';
        $existing = $database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE site_identifier = ? AND content_type_id = ? AND slug = ? AND deleted_at IS NULL',
            $this->tables->quoted('content_entries'),
        ), [SiteContext::DEFAULT, ContentService::CORE_PAGE_TYPE_ID, $legacySlug]);
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $entry = ContentEntry::create(
            self::HOMEPAGE_ID,
            'Welcome to Kumwe',
            'home',
            $this->homepageData(),
            ContentStatus::Published,
        );
        $database->insert($this->tables->raw('content_entries'), [
            'id' => $entry->id(),
            'site_identifier' => SiteContext::DEFAULT,
            'content_type_id' => ContentService::CORE_PAGE_TYPE_ID,
            'content_type_version' => $pageVersion,
            'workflow_id' => ContentService::CORE_WORKFLOW_ID,
            'workflow_version' => 1,
            'workflow_state_key' => $entry->statusKey(),
            'title' => $entry->title(),
            'slug' => $entry->slug(),
            'data' => $entry->data(),
            'publish_at' => null,
            'unpublish_at' => null,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ], [
            'data' => Types::JSON,
            'publish_at' => Types::DATETIME_IMMUTABLE,
            'unpublish_at' => Types::DATETIME_IMMUTABLE,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
            'deleted_at' => Types::DATETIME_IMMUTABLE,
        ]);

        $revision = ContentRevision::capture(self::HOMEPAGE_REVISION_ID, $entry, 1, $now);
        $database->insert($this->tables->raw('content_revisions'), [
            'id' => $revision->id(),
            'content_entry_id' => $revision->contentEntryId(),
            'revision_number' => $revision->revisionNumber(),
            'snapshot' => $revision->snapshot(),
            'checksum' => $revision->checksum(),
            'created_at' => $revision->createdAt(),
        ], ['snapshot' => Types::JSON, 'created_at' => Types::DATETIME_IMMUTABLE]);
        $this->ownership($database, 'content', $entry->id());

        return $entry->id();
    }

    private function mainMenu(Connection $database, string $homepageId, DateTimeImmutable $now): void
    {
        $existing = $database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE handle = ?',
            $this->tables->quoted('navigation_menus'),
        ), ['main']);
        if ($existing !== false) {
            return;
        }

        $database->insert($this->tables->raw('navigation_menus'), [
            'id' => self::MAIN_MENU_ID,
            'handle' => 'main',
            'title' => 'Main menu',
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['created_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE]);
        $this->ownership($database, 'menu', self::MAIN_MENU_ID);

        $items = [
            ['00000000-0000-7000-8000-000000001102', 'Home', 'home', '/home', 0, 'content', $homepageId, null],
            [
                '00000000-0000-7000-8000-000000001103',
                'Capabilities',
                'capabilities',
                '/capabilities',
                1,
                'anchor',
                $homepageId,
                '#capabilities',
            ],
            [
                '00000000-0000-7000-8000-000000001104',
                'Platform',
                'platform',
                '/platform',
                2,
                'anchor',
                $homepageId,
                '#platform',
            ],
            [
                '00000000-0000-7000-8000-000000001105',
                'Administrator',
                'administrator-link',
                '/administrator-link',
                3,
                'url',
                null,
                '/administrator',
            ],
        ];
        foreach ($items as [$id, $title, $slug, $path, $position, $targetType, $contentId, $targetUrl]) {
            $database->insert($this->tables->raw('navigation_items'), [
                'id' => $id,
                'menu_id' => self::MAIN_MENU_ID,
                'parent_id' => null,
                'title' => $title,
                'slug' => $slug,
                'path' => $path,
                'position' => $position,
                'version' => 1,
                'target_type' => $targetType,
                'content_id' => $contentId,
                'target_url' => $targetUrl,
                'created_at' => $now,
                'updated_at' => $now,
            ], ['created_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE]);
            $this->ownership($database, 'menu_item', $id);
        }
    }

    private function seedSetting(
        Connection $database,
        string $key,
        mixed $value,
        DateTimeImmutable $now,
        bool $replaceNull = false,
    ): void {
        $existing = $database->fetchAssociative(sprintf(
            'SELECT setting_value FROM %s WHERE setting_key = ?',
            $this->tables->quoted('site_settings'),
        ), [$key]);
        if ($existing === false) {
            $database->insert($this->tables->raw('site_settings'), [
                'setting_key' => $key,
                'setting_value' => $value,
                'version' => 1,
                'updated_by' => null,
                'updated_at' => $now,
            ], ['setting_value' => Types::JSON, 'updated_at' => Types::DATETIME_IMMUTABLE]);
            return;
        }
        if (!$replaceNull || $this->decode($existing['setting_value'] ?? null) !== null) {
            return;
        }

        $database->executeStatement(sprintf(
            'UPDATE %s SET setting_value = ?, version = version + 1, updated_at = ? WHERE setting_key = ?',
            $this->tables->quoted('site_settings'),
        ), [$value, $now, $key], [Types::JSON, Types::DATETIME_IMMUTABLE, Types::STRING]);
    }

    private function setting(Connection $database, string $key): mixed
    {
        $stored = $database->fetchOne(sprintf(
            'SELECT setting_value FROM %s WHERE setting_key = ?',
            $this->tables->quoted('site_settings'),
        ), [$key]);

        return $stored === false ? null : $this->decode($stored);
    }

    private function decode(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        return json_decode($value, true, 16, JSON_THROW_ON_ERROR);
    }

    private function ownership(Connection $database, string $type, string $id): void
    {
        $exists = $database->fetchOne(sprintf(
            'SELECT resource_id FROM %s WHERE resource_type = ? AND resource_id = ?',
            $this->tables->quoted('resource_site_ownership'),
        ), [$type, $id]);
        if ($exists !== false) {
            return;
        }

        $database->insert($this->tables->raw('resource_site_ownership'), [
            'resource_type' => $type,
            'resource_id' => $id,
            'site_identifier' => SiteContext::DEFAULT,
        ]);
    }

    /** @return array<string, mixed> */
    private function pageSchema(): array
    {
        $richText = static fn (string $title, string $description): array => [
            'type' => 'string',
            'title' => $title,
            'description' => $description,
            'maxLength' => 50_000,
        ];
        $action = static fn (string $title): array => [
            'type' => 'object',
            'title' => $title,
            'properties' => [
                'label' => ['type' => 'string', 'title' => 'Label', 'maxLength' => 120],
                'url' => ['type' => 'string', 'format' => 'uri-reference', 'title' => 'URL'],
            ],
            'additionalProperties' => false,
        ];
        $section = static fn (string $title): array => [
            'type' => 'object',
            'title' => $title,
            'properties' => [
                'anchor' => [
                    'type' => 'string',
                    'title' => 'Section anchor',
                    'pattern' => '^[A-Za-z][A-Za-z0-9._:-]{0,190}$',
                    'description' => 'The fragment used by a #section menu link.',
                ],
                'heading' => ['type' => 'string', 'title' => 'Heading', 'maxLength' => 255],
                'body' => $richText('Body', 'Rich text displayed in this anchored section.'),
            ],
            'additionalProperties' => false,
        ];

        return [
            'type' => 'object',
            'properties' => [
                'logo' => [
                    'type' => 'string',
                    'format' => 'uri-reference',
                    'x-kumwe-field' => 'media',
                    'title' => 'Page logo',
                    'description' => 'Choose a reusable image from Media.',
                ],
                'brand_logo' => [
                    'type' => 'string',
                    'format' => 'uri-reference',
                    'x-kumwe-field' => 'media',
                    'title' => 'Header logo',
                    'description' => 'Choose the compact logo used by the public site header.',
                ],
                'eyebrow' => ['type' => 'string', 'title' => 'Eyebrow', 'maxLength' => 160],
                'heading' => ['type' => 'string', 'title' => 'Hero heading', 'maxLength' => 255],
                'summary' => ['type' => 'string', 'title' => 'Hero summary', 'maxLength' => 1_000],
                'primary_action' => $action('Primary action'),
                'secondary_action' => $action('Secondary action'),
                'body' => $richText('Page body', 'The main page content.'),
                'capabilities' => $section('Capabilities section'),
                'platform' => $section('Platform section'),
            ],
            'required' => ['body'],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function homepageData(): array
    {
        return [
            'logo' => '/media/00000000-0000-7000-8000-000000000902/kumwe-wordmark.svg',
            'brand_logo' => '/media/00000000-0000-7000-8000-000000000901/kumwe-symbol.svg',
            'eyebrow' => 'Kumwe App 2.0',
            'heading' => 'Content systems ready for what comes next.',
            'summary' => 'A modern publishing foundation for structured content, governed workflows, '
                . 'browser delivery, APIs, automation, and AI-assisted operations.',
            'primary_action' => ['label' => 'Open administrator', 'url' => '/administrator'],
            'secondary_action' => ['label' => 'Explore the platform', 'url' => '#capabilities'],
            'body' => 'Kumwe keeps editorial clarity and engineering discipline in one coherent system. '
                . 'This page, its logo, and every navigation item are real managed content that you can edit.',
            'capabilities' => [
                'anchor' => 'capabilities',
                'heading' => 'Structure once. Publish with confidence.',
                'body' => "- Graphical fields and reusable media\n- Governed publishing workflows and revisions\n"
                    . '- Extensible templates, APIs, CLI, MCP, workers, and database portability',
            ],
            'platform' => [
                'anchor' => 'platform',
                'heading' => 'One content core. Every delivery surface.',
                'body' => 'The browser, REST API, command line, MCP tools, and background workers use the same '
                    . 'application services, authorization rules, content records, and navigation model.',
            ],
        ];
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Migration field %s is invalid.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (!is_numeric($value) || (int) $value < 1) {
            throw new RuntimeException(sprintf('Migration field %s is invalid.', $key));
        }

        return (int) $value;
    }
}
