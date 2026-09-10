<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Context\Value\SiteContext;
use RuntimeException;

/** Seeds the document-driven core content types that give the public site several distinct layouts. */
final readonly class DocumentContentTypesMigration implements Migration
{
    public const string ID = '20260812010000_document_content_types';

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
            throw new RuntimeException('The document content types migration checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $checksum);
    }

    public function up(Connection $database): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        foreach ($this->types() as $id => $type) {
            $exists = $database->fetchOne(sprintf(
                'SELECT id FROM %s WHERE id = ?',
                $this->tables->quoted('content_types'),
            ), [$id]);
            if ($exists === false) {
                $database->insert($this->tables->raw('content_types'), [
                    'id' => $id,
                    'workflow_id' => ContentService::CORE_WORKFLOW_ID,
                    'handle' => $type['handle'],
                    'name' => $type['name'],
                    'field_schema' => $type['schema'],
                    'version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], [
                    'field_schema' => Types::JSON,
                    'created_at' => Types::DATETIME_IMMUTABLE,
                    'updated_at' => Types::DATETIME_IMMUTABLE,
                ]);
            }

            $versionExists = $database->fetchOne(sprintf(
                'SELECT version FROM %s WHERE content_type_id = ? AND version = ?',
                $this->tables->quoted('content_type_definition_versions'),
            ), [$id, 1]);
            if ($versionExists === false) {
                $database->insert($this->tables->raw('content_type_definition_versions'), [
                    'content_type_id' => $id,
                    'version' => 1,
                    'site_identifier' => SiteContext::DEFAULT,
                    'handle' => $type['handle'],
                    'name' => $type['name'],
                    'workflow_id' => ContentService::CORE_WORKFLOW_ID,
                    'workflow_version' => 1,
                    'validation_schema' => $type['schema'],
                    'created_at' => $now,
                    'published_at' => $now,
                ], [
                    'validation_schema' => Types::JSON,
                    'created_at' => Types::DATETIME_IMMUTABLE,
                    'published_at' => Types::DATETIME_IMMUTABLE,
                ]);
            }

            $owned = $database->fetchOne(sprintf(
                'SELECT resource_id FROM %s WHERE resource_type = ? AND resource_id = ?',
                $this->tables->quoted('resource_site_ownership'),
            ), ['content_type', $id]);
            if ($owned === false) {
                $database->insert($this->tables->raw('resource_site_ownership'), [
                    'resource_type' => 'content_type',
                    'resource_id' => $id,
                    'site_identifier' => SiteContext::DEFAULT,
                ]);
            }
        }
    }

    /** @return array<string, array{handle: string, name: string, schema: array<string, mixed>}> */
    private function types(): array
    {
        return [
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb410' => [
                'handle' => 'document',
                'name' => 'Document',
                'schema' => $this->documentSchema(),
            ],
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb411' => [
                'handle' => 'guide',
                'name' => 'Guide',
                'schema' => $this->guideSchema(),
            ],
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb412' => [
                'handle' => 'reference',
                'name' => 'Reference',
                'schema' => $this->referenceSchema(),
            ],
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb413' => [
                'handle' => 'faq',
                'name' => 'Questions and answers',
                'schema' => $this->faqSchema(),
            ],
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb414' => [
                'handle' => 'landing',
                'name' => 'Landing page',
                'schema' => $this->landingSchema(),
            ],
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb415' => [
                'handle' => 'article',
                'name' => 'Article',
                'schema' => $this->articleSchema(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function documentSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['heading', 'body', 'sections'],
            'properties' => [
                'eyebrow' => ['type' => 'string', 'maxLength' => 160],
                'heading' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                'summary' => ['type' => 'string', 'maxLength' => 1000],
                'body' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20000],
                'sections' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 24,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['anchor', 'heading', 'body'],
                        'properties' => [
                            'anchor' => $this->anchor(),
                            'heading' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                            'body' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20000],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function guideSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['heading', 'body', 'steps'],
            'properties' => [
                'eyebrow' => ['type' => 'string', 'maxLength' => 160],
                'heading' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                'summary' => ['type' => 'string', 'maxLength' => 1000],
                'body' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20000],
                'steps' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 20,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['anchor', 'heading', 'body'],
                        'properties' => [
                            'anchor' => $this->anchor(),
                            'heading' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                            'body' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20000],
                            'image' => [
                                'type' => 'string',
                                'maxLength' => 500,
                                'x-kumwe-field' => 'media',
                            ],
                            'image_caption' => ['type' => 'string', 'maxLength' => 255],
                        ],
                    ],
                ],
                'outcome' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['body'],
                    'properties' => [
                        'heading' => ['type' => 'string', 'maxLength' => 255],
                        'body' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 8000],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function referenceSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['heading', 'entries'],
            'properties' => [
                'eyebrow' => ['type' => 'string', 'maxLength' => 160],
                'heading' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                'summary' => ['type' => 'string', 'maxLength' => 1000],
                'body' => ['type' => 'string', 'maxLength' => 20000],
                'entries' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 40,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['anchor', 'term', 'body'],
                        'properties' => [
                            'anchor' => $this->anchor(),
                            'term' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                            'kind' => [
                                'type' => 'string',
                                'enum' => ['command', 'concept', 'endpoint', 'field', 'object'],
                            ],
                            'body' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 12000],
                            'example' => ['type' => 'string', 'maxLength' => 4000],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function faqSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['heading', 'items'],
            'properties' => [
                'eyebrow' => ['type' => 'string', 'maxLength' => 160],
                'heading' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                'summary' => ['type' => 'string', 'maxLength' => 1000],
                'items' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 40,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['question', 'body'],
                        'properties' => [
                            'question' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
                            'body' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 12000],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function landingSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['heading', 'features'],
            'properties' => [
                'eyebrow' => ['type' => 'string', 'maxLength' => 160],
                'heading' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                'summary' => ['type' => 'string', 'maxLength' => 1000],
                'logo' => [
                    'type' => 'string',
                    'maxLength' => 500,
                    'x-kumwe-field' => 'media',
                ],
                'primary_action' => $this->action(),
                'secondary_action' => $this->action(),
                'features' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 12,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['heading', 'body'],
                        'properties' => [
                            'anchor' => $this->anchor(),
                            'heading' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                            'body' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 8000],
                        ],
                    ],
                ],
                'closing' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['body'],
                    'properties' => [
                        'heading' => ['type' => 'string', 'maxLength' => 255],
                        'body' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 8000],
                        'action' => $this->action(),
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function articleSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['heading', 'body'],
            'properties' => [
                'eyebrow' => ['type' => 'string', 'maxLength' => 160],
                'heading' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                'summary' => ['type' => 'string', 'maxLength' => 1000],
                'published_label' => ['type' => 'string', 'maxLength' => 160],
                'body' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 50000],
                'highlights' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 8,
                    'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
                ],
                'further_reading' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 12,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['label', 'url'],
                        'properties' => [
                            'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                            'url' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function anchor(): array
    {
        return [
            'type' => 'string',
            'minLength' => 1,
            'maxLength' => 120,
            'pattern' => '^[a-z0-9]+(?:-[a-z0-9]+)*$',
        ];
    }

    /** @return array<string, mixed> */
    private function action(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['label', 'url'],
            'properties' => [
                'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 160],
                'url' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
            ],
        ];
    }
}
