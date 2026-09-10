<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Administrator\Content;

use DateTimeImmutable;
use Kumwe\App\Administrator\Content\ContentFormDataMapper;
use Kumwe\App\Administrator\Content\ContentFormPresenter;
use Kumwe\App\Administrator\Content\ContentModelFormMapper;
use Kumwe\App\Content\Domain\ContentTypeDefinition;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContentFormDataMapper::class)]
#[CoversClass(ContentFormPresenter::class)]
#[CoversClass(ContentModelFormMapper::class)]
#[UsesClass(ContentTypeDefinition::class)]
final class ContentFormTest extends TestCase
{
    public function testPresentsAndMapsTypedSchemaFieldsWithoutJsonAuthoring(): void
    {
        $definition = $this->definition();
        $fields = (new ContentFormPresenter())->fields($definition, [
            'body' => 'Graphical rich text',
            'hero_image' => '/media/example/hero.jpg',
            'priority' => 2,
            'featured' => true,
            'metadata' => ['summary' => 'Visible summary'],
        ]);

        $fieldsByKey = array_column($fields, null, 'key');
        self::assertSame('rich-text', $fieldsByKey['body']['input']);
        self::assertSame('content-field-hero_image', $fieldsByKey['hero_image']['id']);
        self::assertTrue($fieldsByKey['hero_image']['accepts_media']);
        self::assertSame('number', $fieldsByKey['priority']['input']);
        self::assertSame('checkbox', $fieldsByKey['featured']['input']);
        self::assertSame('group', $fieldsByKey['metadata']['kind']);

        $mapped = (new ContentFormDataMapper())->map($definition, [
            'field__hero_image' => '/media/example/hero.jpg',
            'field__priority' => '3',
            'field__featured' => '1',
            'field__metadata__summary' => 'A graphical nested field',
        ]);
        self::assertSame([
            'hero_image' => '/media/example/hero.jpg',
            'priority' => 3,
            'featured' => true,
            'metadata' => ['summary' => 'A graphical nested field'],
        ], $mapped);
    }

    public function testPresentsAndMapsRepeatableObjectRowsThroughIndexedNames(): void
    {
        $definition = $this->sectionedDefinition();
        $fields = (new ContentFormPresenter())->fields($definition, [
            'heading' => 'Handbook',
            'sections' => [
                ['heading' => 'First part', 'body' => 'First prose.'],
                ['heading' => 'Second part', 'body' => 'Second prose.'],
            ],
        ]);

        $fieldsByKey = array_column($fields, null, 'key');
        $sections = $fieldsByKey['sections'];
        self::assertSame('repeatable', $sections['kind']);
        self::assertCount(2, $sections['rows']);
        self::assertSame('field__sections__0__heading', $sections['rows'][0][0]['name']);
        self::assertSame('First part', $sections['rows'][0][0]['value']);
        self::assertSame('field__sections__1__body', $sections['rows'][1][1]['name']);
        self::assertSame('field__sections__INDEX__heading', $sections['template'][0]['name']);
        self::assertSame(24, $sections['max_items']);

        $mapped = (new ContentFormDataMapper())->map($definition, [
            'field__heading' => 'Handbook',
            'field__sections__0__heading' => 'First part',
            'field__sections__0__body' => 'First prose.',
            'field__sections__2__heading' => 'Third part',
            'field__sections__2__body' => 'Kept after a removed row.',
            'field__sections__10__heading' => 'Tenth part',
            'field__sections__10__body' => 'Sorts numerically after two.',
        ]);
        self::assertSame([
            'heading' => 'Handbook',
            'sections' => [
                ['heading' => 'First part', 'body' => 'First prose.'],
                ['heading' => 'Third part', 'body' => 'Kept after a removed row.'],
                ['heading' => 'Tenth part', 'body' => 'Sorts numerically after two.'],
            ],
        ], $mapped);

        self::assertSame(
            ['heading' => 'Handbook', 'sections' => []],
            (new ContentFormDataMapper())->map($definition, ['field__heading' => 'Handbook']),
        );
    }

    public function testBuildsSchemaAndWorkflowFromGraphicalRows(): void
    {
        $mapper = new ContentModelFormMapper();
        $schema = $mapper->contentTypeSchema([
            'field_0_key' => 'body',
            'field_0_title' => 'Article body',
            'field_0_type' => 'text',
            'field_0_required' => '1',
            'field_0_minimum' => '10',
            'field_1_key' => 'hero_image',
            'field_1_title' => 'Hero image',
            'field_1_type' => 'media',
        ]);

        self::assertSame('string', $schema['properties']['body']['type']);
        self::assertSame(10, $schema['properties']['body']['minLength']);
        self::assertSame('uri-reference', $schema['properties']['hero_image']['format']);
        self::assertSame('media', $schema['properties']['hero_image']['x-kumwe-field']);
        self::assertSame(['body'], $schema['required']);

        $states = $mapper->workflowStates([
            'initial_state_key' => 'draft',
            'state_0_key' => 'draft',
            'state_0_name' => 'Draft',
            'state_1_key' => 'published',
            'state_1_name' => 'Published',
            'state_1_public' => '1',
        ]);
        $transitions = $mapper->workflowTransitions([
            'transition_0_from' => 'draft',
            'transition_0_to' => 'published',
            'transition_0_capability' => 'content.publish',
        ]);

        self::assertTrue($states[0]['initial']);
        self::assertTrue($states[1]['public']);
        self::assertSame('content.publish', $transitions[0]['required_capability']);
    }

    private function sectionedDefinition(): ContentTypeDefinition
    {
        $time = new DateTimeImmutable('2026-08-12T12:00:00+00:00');

        return new ContentTypeDefinition(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb162',
            SiteContext::default(),
            'document',
            'Document',
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb161',
            1,
            [
                'type' => 'object',
                'properties' => [
                    'heading' => ['type' => 'string'],
                    'sections' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'maxItems' => 24,
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'heading' => ['type' => 'string'],
                                'body' => ['type' => 'string'],
                            ],
                            'required' => ['heading', 'body'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['heading', 'sections'],
                'additionalProperties' => false,
            ],
            1,
            $time,
            $time,
        );
    }

    private function definition(): ContentTypeDefinition
    {
        $time = new DateTimeImmutable('2026-08-06T12:00:00+00:00');

        return new ContentTypeDefinition(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb160',
            SiteContext::default(),
            'article',
            'Article',
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb161',
            1,
            [
                'type' => 'object',
                'properties' => [
                    'body' => ['type' => 'string', 'title' => 'Body'],
                    'hero_image' => [
                        'type' => 'string',
                        'format' => 'uri-reference',
                        'x-kumwe-field' => 'media',
                        'title' => 'Hero image',
                    ],
                    'priority' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                    'featured' => ['type' => 'boolean'],
                    'metadata' => [
                        'type' => 'object',
                        'properties' => ['summary' => ['type' => 'string']],
                        'required' => ['summary'],
                    ],
                ],
                'required' => ['hero_image', 'priority', 'metadata'],
                'additionalProperties' => false,
            ],
            1,
            $time,
            $time,
        );
    }
}
