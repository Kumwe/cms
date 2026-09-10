<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Projection;

use DateTimeImmutable;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Content\Domain\ContentEntry;
use Kumwe\App\Content\Domain\ContentStatus;
use Kumwe\App\Content\Domain\ContentTypeDefinition;
use Kumwe\App\Content\Domain\JsonSchemaValidator;
use Kumwe\App\Content\Domain\PublicationWindow;
use Kumwe\App\Studio\Application\Projection\ContentStudioProjector;
use Kumwe\App\Studio\Application\Projection\StudioContentFieldDisclosure;
use Kumwe\App\Studio\Application\Projection\StudioProjectionRejected;
use Kumwe\Producer\Schema\StudioDocumentSchemaRegistry;
use Kumwe\App\Studio\Domain\Projection\ContentBlueprintBinding;
use Kumwe\App\Studio\Domain\Projection\EntryCompositionOverrides;
use Kumwe\App\Studio\Domain\Projection\StudioProjectionRejection;
use Kumwe\App\Workflow\Domain\WorkflowDefinition;
use Kumwe\App\Workflow\Domain\WorkflowStateDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Proves Content projections are exact, policy-filtered Studio documents or typed whole-document refusals.
 *
 * @since  2.0.0
 */
#[CoversClass(ContentStudioProjector::class)]
#[CoversClass(StudioProjectionRejected::class)]
#[UsesClass(ContentBlueprintBinding::class)]
#[UsesClass(EntryCompositionOverrides::class)]
#[UsesClass(JsonSchemaValidator::class)]
final class ContentStudioProjectorTest extends TestCase
{
    /**
     * Stable Content type used by every projection coordinate in this test.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string TYPE_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bd100';

    /**
     * Stable workflow pinned by the Content type and its entries.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string WORKFLOW_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bd200';

    /**
     * Stable Content entry projected by the entry cases.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string ENTRY_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bd300';

    /**
     * Translation group proving locale siblings keep their logical identity.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string TRANSLATION_GROUP_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bd400';

    /**
     * A recursive, closed Content schema becomes one deterministic schema-valid model without flattening.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRecursiveContentDefinitionProjectsToAnExactStudioModel(): void
    {
        $definition = $this->definition([
            'audiences' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'enum' => ['public', 'member']],
            ],
            'sections' => [
                'type' => 'array',
                'minItems' => 1,
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['heading', 'body'],
                    'properties' => [
                        'heading' => ['type' => 'string', 'maxLength' => 180],
                        'body' => ['type' => 'string'],
                    ],
                ],
            ],
            'body' => [
                'type' => 'string',
                'title' => 'Body',
                'description' => 'Body source text.',
                'minLength' => 1,
                'maxLength' => 20000,
                'default' => 'Start here.',
            ],
            'metadata' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['summary'],
                'properties' => [
                    'summary' => ['type' => 'string'],
                    'featured' => ['type' => 'boolean'],
                ],
            ],
            'category' => [
                'type' => 'string',
                'enum' => ['article', 'guide'],
            ],
            'hero_image' => [
                'type' => 'string',
                'format' => 'uri-reference',
                'x-kumwe-field' => 'media',
            ],
            'published_at' => ['type' => 'string', 'format' => 'date-time'],
            'score' => ['type' => 'number', 'minimum' => 0.25, 'maximum' => 99],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 20],
        ], ['body', 'sections']);
        $binding = new ContentBlueprintBinding(
            SiteContext::default(),
            self::TYPE_ID,
            4,
            'kumwe.blueprints/article',
            '1.4.0',
            'blueprint-revision-19',
            3,
        );

        $model = $this->projector()->contentModel($this->context(), $definition, $binding);

        self::assertTrue(
            StudioDocumentSchemaRegistry::fromVendoredCorpus()->validate('content-model', $model)->valid(),
        );
        self::assertSame('0.1-draft', $model->contractVersion);
        self::assertSame('content-model', $model->kind);
        self::assertSame('content-model:' . self::TYPE_ID, $model->id);
        self::assertSame('0.0.4', $model->version);
        self::assertSame('content-type-v4', $model->revision);
        self::assertSame(
            [
                'title',
                'slug',
                'data_audiences',
                'data_body',
                'data_category',
                'data_hero_image',
                'data_metadata',
                'data_published_at',
                'data_score',
                'data_sections',
                'data_tags',
            ],
            array_map(static fn (stdClass $field): string => $field->id, $model->fields),
        );
        $fields = self::fieldsById($model->fields);
        self::assertSame('string', $fields['data_body']->kind);
        self::assertSame('media', $fields['data_hero_image']->kind);
        self::assertSame('kumwe.app/media-reference', $fields['data_hero_image']->authoring->control);
        self::assertSame('enum', $fields['data_audiences']->itemKind);
        self::assertSame(['public', 'member'], array_map(
            static fn (stdClass $value): string => $value->value,
            $fields['data_audiences']->enumValues,
        ));
        self::assertSame('Start here.', $fields['data_body']->defaultValue);
        self::assertSame(1, $fields['data_body']->constraints->minLength);
        self::assertSame('0.25', $fields['data_score']->constraints->minimum);
        self::assertSame('99', $fields['data_score']->constraints->maximum);
        self::assertSame(['article', 'guide'], array_map(
            static fn (stdClass $value): string => $value->value,
            $fields['data_category']->enumValues,
        ));
        self::assertSame(['featured', 'summary'], array_map(
            static fn (stdClass $field): string => $field->id,
            $fields['data_metadata']->fields,
        ));
        self::assertSame('object', $fields['data_sections']->itemKind);
        self::assertSame(['body', 'heading'], array_map(
            static fn (stdClass $field): string => $field->id,
            $fields['data_sections']->fields,
        ));
        self::assertSame('string', $fields['data_tags']->itemKind);
        self::assertSame('kumwe.blueprints/article', $model->extensions->{'kumwe.app/blueprint-binding'}->id);
        self::assertSame(3, $model->extensions->{'kumwe.app/blueprint-binding'}->bindingRevision);
    }

    /**
     * Title, slug, body, locale, workflow, translation and overrides retain their exact source meaning.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEntryProjectionPreservesStringsAndPinnedHostCoordinates(): void
    {
        $body = "<p>Line & \"quoted\" — not an AST.</p>\n{\"still\":\"text\"}";
        $definition = $this->definition([
            'body' => ['type' => 'string'],
            'metadata' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'summary' => ['type' => 'string'],
                    'visible' => ['type' => 'boolean'],
                ],
            ],
            'sections' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => ['body' => ['type' => 'string']],
                ],
            ],
        ], workflowId: self::WORKFLOW_ID, workflowVersion: 3);
        $record = $this->record(
            [
                'body' => $body,
                'metadata' => ['summary' => 'A literal summary.', 'visible' => true],
                'sections' => [['body' => '<strong>Also text.</strong>']],
            ],
            'legal_review',
            version: 7,
            locale: 'en-GB',
            translationGroupId: self::TRANSLATION_GROUP_ID,
            workflowId: self::WORKFLOW_ID,
            workflowVersion: 3,
        );
        $overrides = new EntryCompositionOverrides(
            SiteContext::default(),
            self::ENTRY_ID,
            (object) [
                'hero/main' => (object) ['tone' => 'quiet', 'enabled' => true],
            ],
            2,
        );

        $entry = $this->projector()->entry(
            $this->context(),
            $record,
            $definition,
            $this->workflow(),
            $overrides,
        );

        self::assertTrue(StudioDocumentSchemaRegistry::fromVendoredCorpus()->validate('entry', $entry)->valid());
        self::assertSame('content-entry:' . self::ENTRY_ID, $entry->id);
        self::assertSame('content-entry-v7', $entry->revision);
        self::assertSame('content-model:' . self::TYPE_ID, $entry->model->id);
        self::assertSame('0.0.4', $entry->model->version);
        self::assertSame('content-type-v4', $entry->model->revision);
        self::assertSame('in-review', $entry->status);
        self::assertSame('kumwe.content-state/s' . bin2hex('legal_review'), $entry->workflowState);
        self::assertSame('en-GB', $entry->locale);
        self::assertSame('content-translation:' . self::TRANSLATION_GROUP_ID, $entry->translationOf);
        self::assertSame('Title "quoted" — exact', $entry->values->title);
        self::assertSame('exact-page', $entry->values->slug);
        self::assertSame($body, $entry->values->data_body);
        self::assertSame('A literal summary.', $entry->values->data_metadata->summary);
        self::assertSame('<strong>Also text.</strong>', $entry->values->data_sections[0]->body);
        self::assertSame('quiet', $entry->compositionOverrides->{'hero/main'}->tone);
        self::assertSame('legal_review', $entry->extensions->{'kumwe.app/content-entry'}->workflowState);
    }

    /**
     * Built-in and custom workflow positions map deterministically while the exact custom key remains encoded.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testWorkflowStatesMapToThePortableLifecycleWithoutLosingTheirKey(): void
    {
        $projector = $this->projector();
        $definition = $this->definition(['body' => ['type' => 'string']]);
        foreach (
            [
                ContentStatus::Draft->value => 'draft',
                ContentStatus::Review->value => 'in-review',
                ContentStatus::Published->value => 'published',
                ContentStatus::Archived->value => 'archived',
            ] as $source => $portable
        ) {
            $entry = $projector->entry($this->context(), $this->record(['body' => 'x'], $source), $definition);
            self::assertSame($portable, $entry->status);
            self::assertSame('kumwe.content-state/s' . bin2hex($source), $entry->workflowState);
        }

        $customDefinition = $this->definition(
            ['body' => ['type' => 'string']],
            workflowId: self::WORKFLOW_ID,
            workflowVersion: 3,
        );
        foreach (['writing' => 'draft', 'legal_review' => 'in-review', 'live' => 'published'] as $source => $portable) {
            $entry = $projector->entry(
                $this->context(),
                $this->record(
                    ['body' => 'x'],
                    $source,
                    workflowId: self::WORKFLOW_ID,
                    workflowVersion: 3,
                ),
                $customDefinition,
                $this->workflow(),
            );
            self::assertSame($portable, $entry->status);
            self::assertSame($source, $entry->extensions->{'kumwe.app/content-entry'}->workflowState);
        }
    }

    /**
     * A denied field disappears from both shape and values and the result names no denied source coordinate.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPolicyOmissionDoesNotBecomeAFieldExistenceOracle(): void
    {
        $definition = $this->definition([
            'body' => ['type' => 'string'],
            'private_notes' => ['type' => 'string'],
            'embargo_reason' => ['type' => 'string'],
        ]);
        $record = $this->record([
            'body' => 'Public text.',
            'private_notes' => 'Do not reveal this.',
            'embargo_reason' => 'Neither this.',
        ]);
        $projector = $this->projector(['private_notes'], ['embargo_reason']);

        $model = $projector->contentModel($this->context(), $definition);
        $entry = $projector->entry($this->context(), $record, $definition);
        $encodedModel = json_encode($model, JSON_THROW_ON_ERROR);
        $encodedEntry = json_encode($entry, JSON_THROW_ON_ERROR);

        self::assertSame(
            ['title', 'slug', 'data_body', 'data_embargo_reason'],
            array_map(static fn (stdClass $field): string => $field->id, $model->fields),
        );
        self::assertObjectNotHasProperty('data_private_notes', $entry->values);
        self::assertObjectNotHasProperty('data_embargo_reason', $entry->values);
        self::assertStringNotContainsString('private_notes', $encodedModel . $encodedEntry);
        self::assertStringNotContainsString('Do not reveal this.', $encodedEntry);
        self::assertStringNotContainsString('Neither this.', $encodedEntry);
    }

    /**
     * Content identifiers and versions round-trip only through the reserved projection coordinate space.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProjectionIdentifiersAndVersionsAreReversibleAndClosed(): void
    {
        $uppercaseType = strtoupper(self::TYPE_ID);
        $uppercaseEntry = strtoupper(self::ENTRY_ID);

        self::assertSame('content-model:' . self::TYPE_ID, ContentStudioProjector::modelId($uppercaseType));
        self::assertSame(self::TYPE_ID, ContentStudioProjector::contentTypeId('content-model:' . $uppercaseType));
        self::assertNull(ContentStudioProjector::contentTypeId('blueprint:' . self::TYPE_ID));
        self::assertNull(ContentStudioProjector::contentTypeId('content-model:not-a-uuid'));
        self::assertSame('content-entry:' . self::ENTRY_ID, ContentStudioProjector::entryId($uppercaseEntry));
        self::assertSame(self::ENTRY_ID, ContentStudioProjector::contentEntryId('content-entry:' . $uppercaseEntry));
        self::assertNull(ContentStudioProjector::contentEntryId('entry:' . self::ENTRY_ID));
        self::assertNull(ContentStudioProjector::contentEntryId('content-entry:not-a-uuid'));
        self::assertSame('0.0.42', ContentStudioProjector::modelVersion(42));
        self::assertSame(42, ContentStudioProjector::contentTypeVersion('0.0.42'));
        self::assertNull(ContentStudioProjector::contentTypeVersion('0.0.0'));
        self::assertNull(ContentStudioProjector::contentTypeVersion('1.0.42'));
        self::assertNull(ContentStudioProjector::contentTypeVersion('0.0.999999999999999999999999'));
        self::assertSame(7, ContentStudioProjector::contentEntryVersion('content-entry-v7'));
        self::assertNull(ContentStudioProjector::contentEntryVersion('entry-v7'));
        self::assertNull(ContentStudioProjector::contentEntryVersion('content-entry-v0'));
        self::assertSame(
            'legal_review',
            ContentStudioProjector::contentWorkflowState('kumwe.content-state/s' . bin2hex('legal_review')),
        );
        self::assertNull(ContentStudioProjector::contentWorkflowState('kumwe.content/legal_review'));
    }

    /**
     * Every supported Content scalar and authoring hint maps to one explicit Studio kind and control.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEverySupportedFieldKindChoosesAnExplicitAuthoringControl(): void
    {
        $model = $this->projector()->contentModel(
            $this->context(),
            $this->definition([
                'active' => ['type' => 'boolean'],
                'birthday' => ['type' => 'string', 'format' => 'date'],
                'contact' => ['type' => 'string', 'format' => 'email'],
                'count' => ['type' => 'integer'],
                'identifier' => ['type' => 'string', 'format' => 'uuid'],
                'link' => ['type' => 'string', 'format' => 'uri'],
                'published_at' => ['type' => 'string', 'format' => 'date-time'],
                'rating' => ['type' => 'number'],
                'settings' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [],
                ],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                'teaser' => ['type' => 'string'],
            ]),
        );
        $fields = self::fieldsById($model->fields);

        self::assertSame('studio.control/switch', $fields['data_active']->authoring->control);
        self::assertSame('studio.control/date', $fields['data_birthday']->authoring->control);
        self::assertSame('kumwe.app/email', $fields['data_contact']->authoring->control);
        self::assertSame('studio.control/number', $fields['data_count']->authoring->control);
        self::assertSame('kumwe.app/uuid', $fields['data_identifier']->authoring->control);
        self::assertSame('kumwe.app/url', $fields['data_link']->authoring->control);
        self::assertSame('studio.control/date-time', $fields['data_published_at']->authoring->control);
        self::assertSame('studio.control/number', $fields['data_rating']->authoring->control);
        self::assertSame('kumwe.app/schema-group', $fields['data_settings']->authoring->control);
        self::assertSame('kumwe.app/schema-group', $fields['data_tags']->authoring->control);
        self::assertSame('studio.control/single-line-text', $fields['data_teaser']->authoring->control);
    }

    /**
     * Unsupported schema constructs report their typed safe path instead of producing a partial model.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnsupportedSchemaShapesAreRefusedAtTheirExactSourcePath(): void
    {
        $cases = [
            'union' => [
                ['type' => 'string', 'oneOf' => [['const' => 'a'], ['const' => 'b']]],
                '/schema/properties/problem/oneOf',
            ],
            'missing type' => [
                ['title' => 'Problem'],
                '/schema/properties/problem/type',
            ],
            'unknown string format' => [
                ['type' => 'string', 'format' => 'duration'],
                '/schema/properties/problem/format',
            ],
            'empty enum' => [
                ['type' => 'string', 'enum' => []],
                '/schema/properties/problem/enum',
            ],
            'non-local enum member' => [
                ['type' => 'string', 'enum' => ['safe', 'Not safe']],
                '/schema/properties/problem/enum/1',
            ],
            'open object' => [
                ['type' => 'object', 'properties' => [], 'additionalProperties' => true],
                '/schema/properties/problem/additionalProperties',
            ],
            'non-map object properties' => [
                ['type' => 'object', 'properties' => ['not-a-schema'], 'additionalProperties' => false],
                '/schema/properties/problem/properties',
            ],
            'malformed object member' => [
                [
                    'type' => 'object',
                    'properties' => ['child' => 'not-a-schema'],
                    'additionalProperties' => false,
                ],
                '/schema/properties/problem/properties',
            ],
            'missing array items' => [
                ['type' => 'array'],
                '/schema/properties/problem/items',
            ],
            'unknown array item type' => [
                ['type' => 'array', 'items' => ['type' => 'mystery']],
                '/schema/properties/problem/items/type',
            ],
            'Studio string bound' => [
                ['type' => 'string', 'maxLength' => 10_000_001],
                '/schema/properties/problem/maxLength',
            ],
            'Studio collection bound' => [
                ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 100_001],
                '/schema/properties/problem/maxItems',
            ],
            'media hint on a non-string field' => [
                ['type' => 'integer', 'x-kumwe-field' => 'media'],
                '/schema/properties/problem/x-kumwe-field',
            ],
            'oversized enum vocabulary' => [
                ['type' => 'string', 'enum' => array_map(
                    static fn (int $index): string => 'choice_' . $index,
                    range(0, 1000),
                )],
                '/schema/properties/problem/enum',
            ],
            'oversized nested property vocabulary' => [
                [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => array_fill_keys(
                        array_map(static fn (int $index): string => 'field_' . $index, range(0, 1000)),
                        ['type' => 'string'],
                    ),
                ],
                '/schema/properties/problem/properties',
            ],
        ];

        foreach ($cases as $label => [$schema, $path]) {
            $this->assertProjectionRefusal(
                fn (): stdClass => $this->projector()->contentModel(
                    $this->context(),
                    $this->definition(['problem' => $schema]),
                ),
                StudioProjectionRejection::UnsupportedField,
                $path,
                $label,
            );
        }

        $this->assertProjectionRefusal(
            fn (): stdClass => $this->projector()->contentModel(
                $this->context(),
                $this->definition(['invalid__separator' => ['type' => 'string']]),
            ),
            StudioProjectionRejection::UnsupportedField,
            '/schema/properties/invalid__separator',
            'Content key outside the Studio local-name grammar',
        );
    }

    /**
     * Defaults are projection data too, so an incompatible default is refused instead of coerced.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testIncompatibleFieldDefaultsAreRefusedAtTheDefaultCoordinate(): void
    {
        $cases = [
            'string' => [['type' => 'string', 'default' => 7], '/schema/properties/problem/default'],
            'integer' => [['type' => 'integer', 'default' => '7'], '/schema/properties/problem/default'],
            'number' => [['type' => 'number', 'default' => INF], '/schema/properties/problem/default'],
            'boolean' => [['type' => 'boolean', 'default' => 1], '/schema/properties/problem/default'],
            'array map' => [
                ['type' => 'array', 'items' => ['type' => 'string'], 'default' => ['key' => 'value']],
                '/schema/properties/problem/default',
            ],
            'object list' => [
                [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [],
                    'default' => ['value'],
                ],
                '/schema/properties/problem/default',
            ],
            'oversized array' => [
                [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'default' => array_fill(0, 10_001, 'value'),
                ],
                '/schema/properties/problem/default',
            ],
        ];

        foreach ($cases as $label => [$schema, $path]) {
            $this->assertProjectionRefusal(
                fn (): stdClass => $this->projector()->contentModel(
                    $this->context(),
                    $this->definition(['problem' => $schema]),
                ),
                StudioProjectionRejection::LossyValue,
                $path,
                $label . ' default',
            );
        }
    }

    /**
     * The Content validator remains the final authority for keywords Studio can otherwise carry opaquely.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnsupportedContentKeywordIsRefusedAfterLosslessFieldMapping(): void
    {
        $definition = $this->definition([
            'problem' => ['type' => 'string', 'readOnly' => true],
        ]);

        $this->assertProjectionRefusal(
            fn (): stdClass => $this->projector()->contentModel($this->context(), $definition),
            StudioProjectionRejection::UnsupportedField,
            '/schema',
            'unsupported model keyword',
        );
        $this->assertProjectionRefusal(
            fn (): stdClass => $this->projector()->entry(
                $this->context(),
                $this->record(['problem' => 'value']),
                $definition,
            ),
            StudioProjectionRejection::UnsupportedField,
            '/schema',
            'unsupported entry keyword',
        );
    }

    /**
     * Dynamic top-level fields and nested collections cannot evade the closed model vocabulary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOpenModelsAndNestedCollectionsFailAsUnsupportedRatherThanSchemaAccidents(): void
    {
        $open = new ContentTypeDefinition(
            self::TYPE_ID,
            SiteContext::default(),
            'article',
            'Article',
            self::WORKFLOW_ID,
            3,
            ['type' => 'object', 'properties' => ['body' => ['type' => 'string']]],
            4,
            self::now(),
            self::now(),
        );
        $this->assertProjectionRefusal(
            fn (): stdClass => $this->projector()->contentModel($this->context(), $open),
            StudioProjectionRejection::UnsupportedField,
            '/schema/additionalProperties',
            'open top-level model',
        );
        $this->assertProjectionRefusal(
            fn (): stdClass => $this->projector()->contentModel(
                $this->context(),
                $this->definition([
                    'matrix' => [
                        'type' => 'array',
                        'items' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ]),
            ),
            StudioProjectionRejection::UnsupportedField,
            '/schema/properties/matrix/items/type',
            'nested collection',
        );
    }

    /**
     * Wrongly typed and dynamically shaped entry values fail with stable non-disclosing reasons.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLossyAndDynamicEntryValuesAreRefusedWithoutCoercion(): void
    {
        $cases = [
            'string' => [['type' => 'string'], 7, StudioProjectionRejection::LossyValue, '/data'],
            'integer' => [['type' => 'integer'], '7', StudioProjectionRejection::LossyValue, '/data'],
            'number' => [['type' => 'number'], '7.5', StudioProjectionRejection::LossyValue, '/data'],
            'boolean' => [['type' => 'boolean'], 1, StudioProjectionRejection::LossyValue, '/data'],
            'associative array' => [
                ['type' => 'array', 'items' => ['type' => 'string']],
                ['member' => 'x'],
                StudioProjectionRejection::LossyValue,
                '/data',
            ],
            'array without item schema' => [
                ['type' => 'array'],
                ['x'],
                StudioProjectionRejection::UnsupportedField,
                '/schema/properties/problem/items',
            ],
            'list where object expected' => [
                ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
                ['x'],
                StudioProjectionRejection::LossyValue,
                '/data',
            ],
            'object without property map' => [
                ['type' => 'object', 'additionalProperties' => false],
                ['x' => 'y'],
                StudioProjectionRejection::LossyValue,
                '/data',
            ],
            'open object value' => [
                ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]],
                ['x' => 'y'],
                StudioProjectionRejection::UnsupportedField,
                '/schema/properties/problem/additionalProperties',
            ],
            'unknown object member' => [
                ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
                ['unknown' => 'y'],
                StudioProjectionRejection::LossyValue,
                '/data',
            ],
            'value under union' => [
                ['type' => 'string', 'oneOf' => [['const' => 'x']]],
                'x',
                StudioProjectionRejection::UnsupportedField,
                '/schema/properties/problem/oneOf',
            ],
            'unknown value type' => [
                ['type' => 'mystery'],
                'x',
                StudioProjectionRejection::UnsupportedField,
                '/schema',
            ],
        ];

        foreach ($cases as $label => [$schema, $value, $reason, $path]) {
            $this->assertProjectionRefusal(
                fn (): stdClass => $this->projector()->entry(
                    $this->context(),
                    $this->record(['problem' => $value]),
                    $this->definition(['problem' => $schema]),
                ),
                $reason,
                $path,
                $label,
            );
        }

        $this->assertProjectionRefusal(
            fn (): stdClass => $this->projector()->entry(
                $this->context(),
                $this->record(['not_declared' => 'value']),
                $this->definition([]),
            ),
            StudioProjectionRejection::LossyValue,
            '/data',
            'unknown top-level member',
        );
        $this->assertProjectionRefusal(
            fn (): stdClass => $this->projector()->entry(
                $this->context(),
                $this->record(['body' => 'x'], 'unmapped_state'),
                $this->definition(['body' => ['type' => 'string']]),
            ),
            StudioProjectionRejection::LossyValue,
            '/status',
            'unknown workflow state',
        );
        $this->assertProjectionRefusal(
            fn (): stdClass => $this->projector()->entry(
                $this->context(),
                $this->record(['problem' => array_fill(0, 10_001, 'x')]),
                $this->definition(['problem' => ['type' => 'array', 'items' => ['type' => 'string']]]),
            ),
            StudioProjectionRejection::LossyValue,
            '/data/problem',
            'Studio JSON array bound',
        );
    }

    /**
     * Required, enum and constraint corruption is caught against the exact pinned definition before mapping.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStoredDataViolatingItsPinnedDefinitionIsRefusedBeforeProjection(): void
    {
        $cases = [
            'required field' => [
                $this->definition(['body' => ['type' => 'string']], ['body']),
                [],
            ],
            'enum member' => [
                $this->definition(['category' => ['type' => 'string', 'enum' => ['article', 'guide']]]),
                ['category' => 'unknown'],
            ],
            'length constraint' => [
                $this->definition(['body' => ['type' => 'string', 'minLength' => 5]]),
                ['body' => 'tiny'],
            ],
        ];

        foreach ($cases as $label => [$definition, $data]) {
            $this->assertProjectionRefusal(
                fn (): stdClass => $this->projector()->entry(
                    $this->context(),
                    $this->record($data),
                    $definition,
                ),
                StudioProjectionRejection::LossyValue,
                '/data',
                $label,
            );
        }
    }

    /**
     * Mismatched model, workflow, Blueprint and override coordinates stop before any document is returned.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPinnedCoordinateMismatchesRefuseTheWholeProjection(): void
    {
        $definition = $this->definition(['body' => ['type' => 'string']]);
        $record = $this->record(['body' => 'x']);
        $projector = $this->projector();
        $wrongType = new ContentRecord(
            $record->entry,
            '018f22e2-7c8b-7ab0-8f3a-88e8026bd999',
            self::WORKFLOW_ID,
            self::now(),
            self::now(),
            contentTypeVersion: 4,
            workflowVersion: 3,
        );
        $this->assertProjectionRefusal(
            fn (): stdClass => $projector->entry($this->context(), $wrongType, $definition),
            StudioProjectionRejection::InvalidDocument,
            '/model',
            'model coordinate',
        );
        $wrongBinding = new ContentBlueprintBinding(
            SiteContext::default(),
            '018f22e2-7c8b-7ab0-8f3a-88e8026bd999',
            4,
            'kumwe.blueprints/article',
            '1.0.0',
            null,
            1,
        );
        $this->assertProjectionRefusal(
            fn (): stdClass => $projector->contentModel($this->context(), $definition, $wrongBinding),
            StudioProjectionRejection::InvalidDocument,
            '/extensions',
            'Blueprint coordinate',
        );
        $wrongOverrides = new EntryCompositionOverrides(
            SiteContext::default(),
            '018f22e2-7c8b-7ab0-8f3a-88e8026bd999',
            (object) ['hero/main' => 'wide'],
            1,
        );
        $this->assertProjectionRefusal(
            fn (): stdClass => $projector->entry($this->context(), $record, $definition, null, $wrongOverrides),
            StudioProjectionRejection::InvalidDocument,
            '/compositionOverrides',
            'override coordinate',
        );
        $customDefinition = $this->definition(
            ['body' => ['type' => 'string']],
            workflowId: self::WORKFLOW_ID,
            workflowVersion: 3,
        );
        $wrongWorkflow = new WorkflowDefinition(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bd999',
            SiteContext::default(),
            'other',
            'Other workflow',
            [
                new WorkflowStateDefinition('writing', 'Writing', true, false),
                new WorkflowStateDefinition('legal_review', 'Legal review', false, false),
            ],
            [],
            3,
            self::now(),
            self::now(),
        );
        $this->assertProjectionRefusal(
            fn (): stdClass => $projector->entry(
                $this->context(),
                $this->record(
                    ['body' => 'x'],
                    'legal_review',
                    workflowId: self::WORKFLOW_ID,
                    workflowVersion: 3,
                ),
                $customDefinition,
                $wrongWorkflow,
            ),
            StudioProjectionRejection::InvalidDocument,
            '/workflowState',
            'workflow coordinate',
        );
    }

    /**
     * Site ownership and public model coordinates are checked before projection reveals any document.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSiteAndPublicCoordinatesNeverCrossProjectionBoundaries(): void
    {
        $foreignSite = SiteContext::fromString('foreign-site');
        $foreignDefinition = new ContentTypeDefinition(
            self::TYPE_ID,
            $foreignSite,
            'article',
            'Article',
            ContentService::CORE_WORKFLOW_ID,
            1,
            [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => ['body' => ['type' => 'string']],
            ],
            4,
            self::now(),
            self::now(),
        );

        $this->assertProjectionRefusal(
            fn (): stdClass => $this->projector()->contentModel($this->context(), $foreignDefinition),
            StudioProjectionRejection::InvalidDocument,
            '/owner',
            'foreign-site model',
        );
        $this->assertProjectionRefusal(
            fn (): stdClass => $this->projector()->publishedValues(
                $this->record(['body' => 'value']),
                $foreignDefinition,
            ),
            StudioProjectionRejection::InvalidDocument,
            '/model',
            'foreign-site public values',
        );

        $foreignContext = ExecutionContext::issueSystem(
            $this,
            SystemIdentity::Worker,
            $foreignSite,
            'studio-foreign-projection-test',
        );
        self::assertSame(
            'content-model:' . self::TYPE_ID,
            $this->projector()->contentModel($foreignContext, $foreignDefinition)->id,
        );
    }

    /**
     * Studio's bounded field vocabulary is enforced after Content disclosure adds entry properties.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testModelRefusesMoreThanOneThousandDisclosedFields(): void
    {
        $properties = [];
        foreach (range(0, 998) as $index) {
            $properties['field_' . $index] = ['type' => 'string'];
        }

        $this->assertProjectionRefusal(
            fn (): stdClass => $this->projector()->contentModel(
                $this->context(),
                $this->definition($properties),
            ),
            StudioProjectionRejection::UnsupportedField,
            '/schema/properties',
            'one-thousand-and-one-field model',
        );
    }

    /**
     * The public value path remains lossless even for schemas unavailable to Studio model authoring.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPublishedValuesValidateCoordinatesAndRefuseAmbiguousValueSchemas(): void
    {
        $nullable = $this->projector()->publishedValues(
            $this->record(['problem' => null]),
            $this->definition(['problem' => ['type' => 'null']]),
        );
        self::assertNull($nullable->data_problem);

        $this->assertProjectionRefusal(
            fn (): stdClass => $this->projector()->publishedValues(
                $this->record(['problem' => ['known' => 'value']]),
                $this->definition([
                    'problem' => [
                        'type' => 'object',
                        'properties' => ['known' => ['type' => 'string']],
                        'additionalProperties' => true,
                    ],
                ]),
            ),
            StudioProjectionRejection::UnsupportedField,
            '/data/problem',
            'open public object',
        );
        $this->assertProjectionRefusal(
            fn (): stdClass => $this->projector()->publishedValues(
                $this->record(['problem' => 'value']),
                $this->definition([
                    'problem' => [
                        'type' => 'string',
                        'oneOf' => [['const' => 'value']],
                    ],
                ]),
            ),
            StudioProjectionRejection::UnsupportedField,
            '/data/problem',
            'combined public value schema',
        );

        $customDefinition = $this->definition(
            ['body' => ['type' => 'string']],
            workflowId: self::WORKFLOW_ID,
            workflowVersion: 3,
        );
        $this->assertProjectionRefusal(
            fn (): stdClass => $this->projector()->entry(
                $this->context(),
                $this->record(
                    ['body' => 'value'],
                    'unlisted',
                    workflowId: self::WORKFLOW_ID,
                    workflowVersion: 3,
                ),
                $customDefinition,
                $this->workflow(),
            ),
            StudioProjectionRejection::LossyValue,
            '/status',
            'custom workflow state absent from its definition',
        );
    }

    /**
     * A projection invalid under the vendored schema is returned only as a typed diagnostic.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryCompletedProjectionIsValidatedAgainstThePinnedSchema(): void
    {
        $this->assertProjectionRefusal(
            fn (): stdClass => $this->projector(
                [ContentStudioProjector::ENTRY_TITLE, ContentStudioProjector::ENTRY_SLUG, 'body'],
            )->contentModel(
                $this->context(),
                $this->definition(['body' => ['type' => 'string']]),
            ),
            StudioProjectionRejection::InvalidDocument,
            '/fields',
            'published model with no disclosed fields',
        );

        $bounded = (new StudioProjectionRejected(
            StudioProjectionRejection::UnsupportedField,
            '/' . str_repeat('x', 1000),
        ))->diagnostic();
        self::assertObjectNotHasProperty('location', $bounded);
    }

    /**
     * Build a projector whose deny lists model shape and entry values independently.
     *
     * @param   list<string>  $hiddenShape   Source fields whose existence may not be described.
     * @param   list<string>  $hiddenValues  Source fields whose current value may not be disclosed.
     *
     * @return  ContentStudioProjector
     *
     * @since   2.0.0
     */
    private function projector(array $hiddenShape = [], array $hiddenValues = []): ContentStudioProjector
    {
        $disclosure = $this->createStub(StudioContentFieldDisclosure::class);
        $disclosure->method('mayDescribe')->willReturnCallback(
            static fn (ExecutionContext $context, ContentTypeDefinition $definition, string $field): bool =>
                !in_array($field, $hiddenShape, true),
        );
        $disclosure->method('mayDisclose')->willReturnCallback(
            static fn (ExecutionContext $context, ContentRecord $record, string $field): bool =>
                !in_array($field, $hiddenValues, true),
        );

        return new ContentStudioProjector(
            StudioDocumentSchemaRegistry::fromVendoredCorpus(),
            $disclosure,
            new JsonSchemaValidator(),
        );
    }

    /**
     * Build a closed version-four Content definition over the supplied field map.
     *
     * @param   array<string, array<string, mixed>>  $properties       Top-level Content field schemas.
     * @param   list<string>                         $required         Required top-level fields.
     * @param   string                               $workflowId       Workflow pinned by the definition.
     * @param   int                                  $workflowVersion  Exact workflow version.
     *
     * @return  ContentTypeDefinition
     *
     * @since   2.0.0
     */
    private function definition(
        array $properties,
        array $required = [],
        string $workflowId = ContentService::CORE_WORKFLOW_ID,
        int $workflowVersion = 1,
    ): ContentTypeDefinition {
        return new ContentTypeDefinition(
            self::TYPE_ID,
            SiteContext::default(),
            'article',
            'Article',
            $workflowId,
            $workflowVersion,
            [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => $properties,
                'required' => $required,
            ],
            4,
            self::now(),
            self::now(),
        );
    }

    /**
     * Build an exact stored Content record for projection.
     *
     * @param   array<string, mixed>  $data                Stored Content field values.
     * @param   ContentStatus|string  $status              Built-in or custom workflow key.
     * @param   int                   $version             Optimistic entry version.
     * @param   ?string               $locale              Stored locale, or null when not localized.
     * @param   ?string               $translationGroupId  Logical translation identity, or null.
     * @param   string                $workflowId          Workflow pinned by the record.
     * @param   int                   $workflowVersion     Exact workflow version.
     *
     * @return  ContentRecord
     *
     * @since   2.0.0
     */
    private function record(
        array $data,
        ContentStatus|string $status = ContentStatus::Draft,
        int $version = 1,
        ?string $locale = null,
        ?string $translationGroupId = null,
        string $workflowId = ContentService::CORE_WORKFLOW_ID,
        int $workflowVersion = 1,
    ): ContentRecord {
        $entry = ContentEntry::reconstitute(
            self::ENTRY_ID,
            'Title "quoted" — exact',
            'exact-page',
            $data,
            $status,
            PublicationWindow::unbounded(),
            $version,
            $locale,
            $translationGroupId,
        );

        return new ContentRecord(
            $entry,
            self::TYPE_ID,
            $workflowId,
            self::now(),
            self::now(),
            contentTypeVersion: 4,
            workflowVersion: $workflowVersion,
        );
    }

    /**
     * Build the custom workflow whose initial, review and public states exercise all portable categories.
     *
     * @return  WorkflowDefinition
     *
     * @since   2.0.0
     */
    private function workflow(): WorkflowDefinition
    {
        return new WorkflowDefinition(
            self::WORKFLOW_ID,
            SiteContext::default(),
            'editorial',
            'Editorial workflow',
            [
                new WorkflowStateDefinition('writing', 'Writing', true, false),
                new WorkflowStateDefinition('legal_review', 'Legal review', false, false),
                new WorkflowStateDefinition('live', 'Live', false, true),
            ],
            [],
            3,
            self::now(),
            self::now(),
        );
    }

    /**
     * Build a deterministic site-bound system context for pure projection policy calls.
     *
     * @return  ExecutionContext
     *
     * @since   2.0.0
     */
    private function context(): ExecutionContext
    {
        return ExecutionContext::issueSystem(
            $this,
            SystemIdentity::Worker,
            SiteContext::default(),
            'studio-projection-test',
        );
    }

    /**
     * Assert one operation stops with the stable reason, path and non-disclosing diagnostic envelope.
     *
     * @param   callable(): mixed          $operation  Projection operation expected to refuse.
     * @param   StudioProjectionRejection  $reason     Stable refusal category.
     * @param   string                     $path       Safe JSON Pointer the mapper reports.
     * @param   string                     $label      Case label used when a branch unexpectedly succeeds.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertProjectionRefusal(
        callable $operation,
        StudioProjectionRejection $reason,
        string $path,
        string $label,
    ): void {
        try {
            $operation();
            self::fail(sprintf('The %s projection was accepted.', $label));
        } catch (StudioProjectionRejected $failure) {
            self::assertSame($reason, $failure->rejection, $label);
            self::assertSame($path, $failure->path, $label);
            self::assertSame('The requested content projection is unavailable.', $failure->getMessage());
            $diagnostic = $failure->diagnostic();
            self::assertSame('kumwe.app/projection-' . $reason->value, $diagnostic->code);
            self::assertSame('blocking', $diagnostic->severity);
            self::assertSame($reason->value, $diagnostic->parameters->reason);
            self::assertSame($path, $diagnostic->location->jsonPointer);
            self::assertStringNotContainsString($label, json_encode($diagnostic, JSON_THROW_ON_ERROR));
        }
    }

    /**
     * Index projected fields by local identifier for precise recursive assertions.
     *
     * @param   list<stdClass>  $fields  Projected field list.
     *
     * @return  array<string, stdClass>  Fields keyed by their Studio local name.
     *
     * @since   2.0.0
     */
    private static function fieldsById(array $fields): array
    {
        $indexed = [];
        foreach ($fields as $field) {
            $indexed[$field->id] = $field;
        }

        return $indexed;
    }

    /**
     * Return the fixed publication instant shared by definitions and records.
     *
     * @return  DateTimeImmutable
     *
     * @since   2.0.0
     */
    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-24T12:00:00+00:00');
    }
}
