<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Projection;

use InvalidArgumentException;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Content\Domain\ContentStatus;
use Kumwe\App\Content\Domain\ContentTypeDefinition;
use Kumwe\App\Content\Domain\FieldDefinition;
use Kumwe\App\Content\Domain\InvalidContentData;
use Kumwe\App\Content\Domain\JsonSchemaValidator;
use Kumwe\App\Studio\Domain\Projection\ContentBlueprintBinding;
use Kumwe\App\Studio\Domain\Projection\EntryCompositionOverrides;
use Kumwe\Producer\Canonical\CanonicalJson;
use Kumwe\Producer\Schema\StudioDocumentSchemaRegistry;
use Kumwe\App\Studio\Domain\Projection\StudioProjectionRejection;
use Kumwe\App\Workflow\Domain\WorkflowDefinition;
use stdClass;

/**
 * Lossless, one-way projection of authorized Content definitions and entries into Studio documents.
 *
 * The projector contains mapping rules only. Reads and authorization happen in
 * {@see StudioContentProjectionService}; policy-specific field omission is delegated to
 * {@see StudioContentFieldDisclosure}. Every result validates against Producer's exact pinned Studio
 * schema before it leaves this class. Unsupported unions, dynamic object members, and ambiguous
 * values fail closed instead of being coerced into a superficially compatible field.
 *
 * @since  2.0.0
 */
final readonly class ContentStudioProjector
{
    /**
     * Contract discriminator carried by the pinned protocol corpus.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string CONTRACT_VERSION = '0.1-draft';

    /**
     * Source name used for ContentEntry's title property in disclosure decisions.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ENTRY_TITLE = '@title';

    /**
     * Source name used for ContentEntry's slug property in disclosure decisions.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ENTRY_SLUG = '@slug';

    /**
     * Canonical UUID source shared by the two reversible projection identifier patterns.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string UUID_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}'
        . '-[89ab][0-9a-f]{3}-[0-9a-f]{12}';

    /**
     * Local field identifier grammar carried by the pinned Studio common schema.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string LOCAL_NAME = '/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D';

    /**
     * Bind the projection to the canonical schemas and the host's field-disclosure policy.
     *
     * @param  StudioDocumentSchemaRegistry  $schemas        Producer's exact pinned schema interpreter.
     * @param  StudioContentFieldDisclosure  $disclosure     Field description and value policy.
     * @param  JsonSchemaValidator           $contentSchema  Authoritative Content schema validator.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioDocumentSchemaRegistry $schemas,
        private StudioContentFieldDisclosure $disclosure,
        private JsonSchemaValidator $contentSchema,
    ) {
    }

    /**
     * Project one authorized Content definition into a canonical Studio content model.
     *
     * Content `title` and `slug` live outside the definition's data schema, so the projection names
     * them explicitly and prefixes schema-owned fields with `data_`. The prefix makes the mapping
     * reversible even when a content type itself declares fields called title or slug.
     *
     * @param   ExecutionContext          $context     Authorized actor and site.
     * @param   ContentTypeDefinition     $definition  Exact published Content definition version.
     * @param   ?ContentBlueprintBinding  $binding     Optional Blueprint selected for that exact version.
     *
     * @return  stdClass  Schema-valid Studio `content-model` document.
     *
     * @throws  StudioProjectionRejected  When a field has no exact mapping or the result fails validation.
     *
     * @since   2.0.0
     */
    public function contentModel(
        ExecutionContext $context,
        ContentTypeDefinition $definition,
        ?ContentBlueprintBinding $binding = null,
    ): stdClass {
        if ($definition->site->identifier() !== $context->site()->identifier()) {
            throw new StudioProjectionRejected(StudioProjectionRejection::InvalidDocument, '/owner');
        }

        $fields = [];
        if ($this->disclosure->mayDescribe($context, $definition, self::ENTRY_TITLE)) {
            $fields[] = $this->entryPropertyField('title', 'Title', 'studio.semantic/title', 0);
        }
        if ($this->disclosure->mayDescribe($context, $definition, self::ENTRY_SLUG)) {
            $fields[] = $this->entryPropertyField('slug', 'Slug', 'kumwe.content/slug', 1);
        }

        $definitions = $this->definitionFields($definition);
        usort($definitions, static fn (FieldDefinition $left, FieldDefinition $right): int =>
            strcmp($left->key, $right->key));
        $order = 10;
        foreach ($definitions as $field) {
            if (!$this->disclosure->mayDescribe($context, $definition, $field->key)) {
                continue;
            }
            $fields[] = $this->field(
                'data_' . $field->key,
                $field->key,
                $field->schema,
                $field->required,
                [$field->key],
                $order++,
            );
        }
        if (count($fields) > 1000) {
            throw new StudioProjectionRejected(
                StudioProjectionRejection::UnsupportedField,
                '/schema/properties',
            );
        }
        try {
            $this->contentSchema->assertSupported($definition->schema());
        } catch (InvalidArgumentException) {
            throw new StudioProjectionRejected(StudioProjectionRejection::UnsupportedField, '/schema');
        }

        $document = new stdClass();
        $document->contractVersion = self::CONTRACT_VERSION;
        $document->kind = 'content-model';
        $document->id = self::modelId($definition->id);
        $document->version = self::modelVersion($definition->version);
        $document->revision = self::modelRevision($definition->version);
        $document->owner = (object) ['id' => 'kumwe.app/content', 'version' => '2.0.0'];
        $document->status = 'published';
        $document->label = $this->message('model', $definition->handle, $definition->name);
        $document->fields = $fields;
        $document->relationships = [];
        $extensions = new stdClass();
        $extensions->{'kumwe.app/content-definition'} = (object) [
            'id' => $definition->id,
            'version' => $definition->version,
            'workflowId' => $definition->workflowId,
            'workflowVersion' => $definition->workflowVersion,
        ];
        if ($binding !== null) {
            if (
                $binding->site->identifier() !== $definition->site->identifier()
                || $binding->contentTypeId !== $definition->id
                || $binding->contentTypeVersion !== $definition->version
            ) {
                throw new StudioProjectionRejected(StudioProjectionRejection::InvalidDocument, '/extensions');
            }
            $coordinate = (object) [
                'id' => $binding->blueprintId,
                'version' => $binding->blueprintVersion,
                'bindingRevision' => $binding->revision,
            ];
            if ($binding->blueprintRevision !== null) {
                $coordinate->revision = $binding->blueprintRevision;
            }
            $extensions->{'kumwe.app/blueprint-binding'} = $coordinate;
        }
        $document->extensions = $extensions;

        return $this->validated('content-model', $document);
    }

    /**
     * Project one authorized Content record into a canonical Studio entry.
     *
     * Title, slug, and all string-valued data remain strings byte for byte; a body is never guessed to
     * be Studio rich text. Custom workflow states retain their exact key in an extension while the
     * portable status receives the nearest explicit lifecycle category.
     *
     * @param   ExecutionContext            $context     Authorized actor and site.
     * @param   ContentRecord               $record      Authorized Content entry and pinned definition facts.
     * @param   ContentTypeDefinition       $definition  Exact definition version the record pins.
     * @param   ?WorkflowDefinition         $workflow    Exact custom workflow version, null for the built-in one.
     * @param   ?EntryCompositionOverrides  $overrides   Optional host-owned composition overrides.
     *
     * @return  stdClass  Schema-valid Studio `entry` document.
     *
     * @throws  StudioProjectionRejected  When the pinned coordinates disagree, a value is lossy, or validation fails.
     *
     * @since   2.0.0
     */
    public function entry(
        ExecutionContext $context,
        ContentRecord $record,
        ContentTypeDefinition $definition,
        ?WorkflowDefinition $workflow = null,
        ?EntryCompositionOverrides $overrides = null,
    ): stdClass {
        if (
            $record->siteIdentifier !== $context->site()->identifier()
            || $definition->site->identifier() !== $context->site()->identifier()
            || $record->contentTypeId !== $definition->id
            || $record->contentTypeVersion !== $definition->version
            || $record->workflowId !== $definition->workflowId
            || $record->workflowVersion !== $definition->workflowVersion
        ) {
            throw new StudioProjectionRejected(StudioProjectionRejection::InvalidDocument, '/model');
        }
        $coreWorkflow = $record->workflowId === ContentService::CORE_WORKFLOW_ID;
        if (
            ($coreWorkflow && ($record->workflowVersion !== 1 || $workflow !== null))
            || (!$coreWorkflow && (
                $workflow === null
                || $workflow->site->identifier() !== $context->site()->identifier()
                || $workflow->id !== $record->workflowId
                || $workflow->version !== $record->workflowVersion
            ))
        ) {
            throw new StudioProjectionRejected(StudioProjectionRejection::InvalidDocument, '/workflowState');
        }

        $entry = $record->entry;
        $this->assertValidData($definition, $entry->data());
        $this->contentModel($context, $definition);
        $values = $this->values($record, $definition, $context, false);
        $document = new stdClass();
        $document->contractVersion = self::CONTRACT_VERSION;
        $document->kind = 'entry';
        $document->id = self::entryId($entry->id());
        $document->revision = self::entryRevision($entry->version());
        $document->model = (object) [
            'id' => self::modelId($definition->id),
            'version' => self::modelVersion($definition->version),
            'revision' => self::modelRevision($definition->version),
        ];
        $document->status = $this->portableStatus($entry->statusKey(), $workflow);
        if ($entry->locale() !== null) {
            $document->locale = $entry->locale()->toString();
        }
        if ($entry->translationGroupId() !== null) {
            $document->translationOf = 'content-translation:' . $entry->translationGroupId();
        }
        $document->workflowState = self::workflowState($entry->statusKey());
        $document->values = $values;
        if ($overrides !== null) {
            if (
                $overrides->site->identifier() !== $context->site()->identifier()
                || $overrides->entryId !== $entry->id()
            ) {
                throw new StudioProjectionRejected(
                    StudioProjectionRejection::InvalidDocument,
                    '/compositionOverrides',
                );
            }
            $document->compositionOverrides = $overrides->values();
        }
        $document->extensions = (object) [
            'kumwe.app/content-entry' => (object) [
                'id' => $entry->id(),
                'version' => $entry->version(),
                'workflowId' => $record->workflowId,
                'workflowVersion' => $record->workflowVersion,
                'workflowState' => $entry->statusKey(),
                'publicationWindow' => (object) [
                    'startsAt' => $entry->publicationWindow()->startsAt()?->format('Y-m-d\TH:i:s.uP'),
                    'endsAt' => $entry->publicationWindow()->endsAt()?->format('Y-m-d\TH:i:s.uP'),
                ],
            ],
        ];
        if ($overrides !== null) {
            $document->extensions->{'kumwe.app/composition-override'} = (object) [
                'revision' => $overrides->revision,
            ];
        }

        return $this->validated('entry', $document);
    }

    /**
     * Project the complete public values of one published Content record without inventing actor authority.
     *
     * Public page resolution has already established that the record is publishable. This path therefore
     * exposes the whole pinned definition rather than fabricating an administrator execution context, while
     * retaining the same exact schema validation and lossless JSON conversion used by {@see entry()}.
     *
     * @param   ContentRecord          $record      Published record selected by the public Content boundary.
     * @param   ContentTypeDefinition  $definition  Exact definition version pinned by that record.
     *
     * @return  stdClass  Complete Studio field-value object for safe public composition rendering.
     *
     * @throws  StudioProjectionRejected  When the record and definition disagree or a value is not lossless.
     *
     * @since   2.0.0
     */
    public function publishedValues(ContentRecord $record, ContentTypeDefinition $definition): stdClass
    {
        if (
            $record->siteIdentifier !== $definition->site->identifier()
            || $record->contentTypeId !== $definition->id
            || $record->contentTypeVersion !== $definition->version
            || $record->workflowId !== $definition->workflowId
            || $record->workflowVersion !== $definition->workflowVersion
        ) {
            throw new StudioProjectionRejected(StudioProjectionRejection::InvalidDocument, '/model');
        }

        return $this->values($record, $definition, null, true);
    }

    /**
     * Encode a Content type UUID as a collision-free Studio stable identifier.
     *
     * @param   string  $contentTypeId  Canonical Content type UUID.
     *
     * @return  string  `content-model:<uuid>`, reversible by {@see contentTypeId()}.
     *
     * @since   2.0.0
     */
    public static function modelId(string $contentTypeId): string
    {
        return 'content-model:' . strtolower($contentTypeId);
    }

    /**
     * Decode the Content UUID from a projected Studio model identifier.
     *
     * @param   string  $modelId  Studio stable identifier.
     *
     * @return  ?string  Canonical lowercase UUID, or null when the identifier is not a Content projection.
     *
     * @since   2.0.0
     */
    public static function contentTypeId(string $modelId): ?string
    {
        if (preg_match('/^content-model:(' . self::UUID_PATTERN . ')$/iD', $modelId, $match) !== 1) {
            return null;
        }

        return strtolower($match[1]);
    }

    /**
     * Encode a Content entry UUID as a collision-free Studio stable identifier.
     *
     * @param   string  $contentEntryId  Canonical Content entry UUID.
     *
     * @return  string  `content-entry:<uuid>`, reversible by {@see contentEntryId()}.
     *
     * @since   2.0.0
     */
    public static function entryId(string $contentEntryId): string
    {
        return 'content-entry:' . strtolower($contentEntryId);
    }

    /**
     * Decode the Content UUID from a projected Studio entry identifier.
     *
     * @param   string  $entryId  Studio stable identifier.
     *
     * @return  ?string  Canonical lowercase UUID, or null when the identifier is not a Content projection.
     *
     * @since   2.0.0
     */
    public static function contentEntryId(string $entryId): ?string
    {
        if (preg_match('/^content-entry:(' . self::UUID_PATTERN . ')$/iD', $entryId, $match) !== 1) {
            return null;
        }

        return strtolower($match[1]);
    }

    /**
     * Encode an integer Content definition version as an exact semantic version.
     *
     * @param   int  $version  Positive Content definition version.
     *
     * @return  string  Semantic version under the reserved `0.0` projection line.
     *
     * @since   2.0.0
     */
    public static function modelVersion(int $version): string
    {
        return '0.0.' . $version;
    }

    /**
     * Decode an exact projected semantic version back to the Content definition version.
     *
     * @param   string  $version  Projected Studio semantic version.
     *
     * @return  ?int  Positive Content version, or null when the coordinate is outside this mapping.
     *
     * @since   2.0.0
     */
    public static function contentTypeVersion(string $version): ?int
    {
        if (preg_match('/^0\.0\.([1-9][0-9]*)$/D', $version, $match) !== 1) {
            return null;
        }

        $decoded = filter_var($match[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($decoded) ? $decoded : null;
    }

    /**
     * Decode an entry projection revision back to Content's optimistic version.
     *
     * @param   string  $revision  Projected Studio entry revision.
     *
     * @return  ?int  Positive Content version, or null for a revision outside this mapping.
     *
     * @since   2.0.0
     */
    public static function contentEntryVersion(string $revision): ?int
    {
        if (preg_match('/^content-entry-v([1-9][0-9]*)$/D', $revision, $match) !== 1) {
            return null;
        }
        $decoded = filter_var($match[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($decoded) ? $decoded : null;
    }

    /**
     * Decode the exact Content workflow key carried by a projected qualified state.
     *
     * @param   string  $workflowState  Qualified Studio workflow state.
     *
     * @return  ?string  Exact source key, or null when the state was not produced by this projector.
     *
     * @since   2.0.0
     */
    public static function contentWorkflowState(string $workflowState): ?string
    {
        if (preg_match('/^kumwe\.content-state\/s((?:[0-9a-f]{2})+)$/D', $workflowState, $match) !== 1) {
            return null;
        }
        $decoded = hex2bin($match[1]);

        return is_string($decoded) && $decoded !== '' ? $decoded : null;
    }

    /**
     * Build one lossless Studio value map, optionally applying an authenticated disclosure policy.
     *
     * A null context is reserved for the public publication path, after the public Content boundary has
     * selected a published record. It means "all schema-declared public values", not anonymous authority.
     *
     * @param   ContentRecord          $record      Record whose values are projected.
     * @param   ContentTypeDefinition  $definition  Exact schema pinned by the record.
     * @param   ?ExecutionContext      $context     Authorized actor, or null for public publication.
     * @param   bool                   $validate    Whether to validate raw data before mapping it.
     *
     * @return  stdClass  Losslessly normalized Studio field values.
     *
     * @throws  StudioProjectionRejected  When the schema or a stored value cannot be projected exactly.
     *
     * @since   2.0.0
     */
    private function values(
        ContentRecord $record,
        ContentTypeDefinition $definition,
        ?ExecutionContext $context,
        bool $validate,
    ): stdClass {
        $entry = $record->entry;
        $data = $entry->data();
        if ($validate) {
            $this->assertValidData($definition, $data);
        }

        $values = new stdClass();
        if (
            $context === null
            || (
                $this->disclosure->mayDescribe($context, $definition, self::ENTRY_TITLE)
                && $this->disclosure->mayDisclose($context, $record, self::ENTRY_TITLE)
            )
        ) {
            $values->title = $entry->title();
        }
        if (
            $context === null
            || (
                $this->disclosure->mayDescribe($context, $definition, self::ENTRY_SLUG)
                && $this->disclosure->mayDisclose($context, $record, self::ENTRY_SLUG)
            )
        ) {
            $values->slug = $entry->slug();
        }

        $byKey = [];
        foreach ($this->definitionFields($definition) as $field) {
            $byKey[$field->key] = $field;
        }
        $dataKeys = array_keys($data);
        sort($dataKeys, SORT_STRING);
        foreach ($dataKeys as $key) {
            $field = $byKey[$key] ?? null;
            if (!$field instanceof FieldDefinition) {
                throw new StudioProjectionRejected(
                    StudioProjectionRejection::UnsupportedField,
                    '/data/' . self::escapePointer($key),
                );
            }
            if (
                $context !== null
                && (
                    !$this->disclosure->mayDescribe($context, $definition, $key)
                    || !$this->disclosure->mayDisclose($context, $record, $key)
                )
            ) {
                continue;
            }
            $member = 'data_' . $key;
            $values->{$member} = $this->value($data[$key], $field->schema, '/data/' . self::escapePointer($key));
        }

        return $values;
    }

    /**
     * Validate raw Content data before either model or value projection changes the refusal location.
     *
     * @param   ContentTypeDefinition  $definition  Exact schema pinned by the record.
     * @param   array<string, mixed>   $data        Stored Content payload.
     *
     * @return  void
     *
     * @throws  StudioProjectionRejected  When the schema is unsupported or the value violates it.
     *
     * @since   2.0.0
     */
    private function assertValidData(ContentTypeDefinition $definition, array $data): void
    {
        try {
            $this->contentSchema->assertValid($definition->schema(), $data);
        } catch (InvalidContentData) {
            throw new StudioProjectionRejected(StudioProjectionRejection::LossyValue, '/data');
        } catch (InvalidArgumentException) {
            throw new StudioProjectionRejected(StudioProjectionRejection::UnsupportedField, '/schema');
        }
    }

    /**
     * Return every fixed top-level field only after proving the Content object is closed.
     *
     * Content accepts open object schemas, but Studio content models enumerate their fields. Projecting
     * an open schema would therefore claim completeness while later entries could carry undeclared data.
     * The model port refuses that shape before exposing a partial field list.
     *
     * @param   ContentTypeDefinition  $definition  Exact Content definition being projected.
     *
     * @return  list<FieldDefinition>  Every declared field in source order.
     *
     * @throws  StudioProjectionRejected  When the top-level object is open, combined, or malformed.
     *
     * @since   2.0.0
     */
    private function definitionFields(ContentTypeDefinition $definition): array
    {
        $schema = $definition->schema();
        foreach (['allOf', 'anyOf', 'oneOf'] as $union) {
            if (array_key_exists($union, $schema)) {
                throw new StudioProjectionRejected(
                    StudioProjectionRejection::UnsupportedField,
                    '/schema/' . $union,
                );
            }
        }
        if (($schema['additionalProperties'] ?? true) !== false) {
            throw new StudioProjectionRejected(
                StudioProjectionRejection::UnsupportedField,
                '/schema/additionalProperties',
            );
        }
        $properties = $schema['properties'] ?? [];
        if (!is_array($properties) || ($properties !== [] && array_is_list($properties))) {
            throw new StudioProjectionRejected(
                StudioProjectionRejection::UnsupportedField,
                '/schema/properties',
            );
        }

        $fields = $definition->fields();
        if (count($fields) !== count($properties)) {
            throw new StudioProjectionRejected(
                StudioProjectionRejection::UnsupportedField,
                '/schema/properties',
            );
        }

        return $fields;
    }

    /**
     * Build the synthetic field representing one ContentEntry property.
     *
     * @param   string  $id            Projected field identifier.
     * @param   string  $label         Default human label.
     * @param   string  $semanticRole  Portable meaning of the property.
     * @param   int     $order         Stable authoring order.
     *
     * @return  stdClass  Studio field document.
     *
     * @since   2.0.0
     */
    private function entryPropertyField(string $id, string $label, string $semanticRole, int $order): stdClass
    {
        $field = (object) [
            'id' => $id,
            'kind' => 'string',
            'label' => $this->message('entry-property', $id, $label),
            'required' => true,
            'localized' => true,
            'cardinality' => 'one',
            'semanticRole' => $semanticRole,
            'authoring' => (object) [
                'control' => 'studio.control/single-line-text',
                'group' => 'identity',
                'order' => $order,
                'width' => 'full',
            ],
            'extensions' => (object) [
                'kumwe.app/source-field' => (object) ['storage' => 'entry', 'key' => $id],
            ],
        ];
        if ($id === 'title') {
            $field->constraints = (object) ['minLength' => 1, 'maxLength' => 255];
        } else {
            $field->constraints = (object) ['minLength' => 1, 'maxLength' => 160];
        }

        return $field;
    }

    /**
     * Project one Content JSON Schema field without discarding its enforceable semantics.
     *
     * @param   string                $id         Projected field identifier.
     * @param   string                $sourceKey  Exact source member name.
     * @param   array<string, mixed>  $schema     Content field schema.
     * @param   bool                  $required   Whether the containing object requires the field.
     * @param   list<string>          $path       Source field path for diagnostics and stable labels.
     * @param   int                   $order      Stable authoring order.
     *
     * @return  stdClass  Studio field document.
     *
     * @throws  StudioProjectionRejected  When the schema cannot be expressed without guessing.
     *
     * @since   2.0.0
     */
    private function field(
        string $id,
        string $sourceKey,
        array $schema,
        bool $required,
        array $path,
        int $order,
    ): stdClass {
        $pointer = '/schema/properties/' . implode('/properties/', array_map(self::escapePointer(...), $path));
        if (preg_match(self::LOCAL_NAME, $id) !== 1 || strlen($id) > 100) {
            throw new StudioProjectionRejected(StudioProjectionRejection::UnsupportedField, $pointer);
        }
        if ($schema !== [] && array_is_list($schema)) {
            throw new StudioProjectionRejected(StudioProjectionRejection::UnsupportedField, $pointer);
        }
        foreach (['allOf', 'anyOf', 'oneOf'] as $union) {
            if (array_key_exists($union, $schema)) {
                throw new StudioProjectionRejected(
                    StudioProjectionRejection::UnsupportedField,
                    $pointer . '/' . $union,
                );
            }
        }
        $type = $schema['type'] ?? null;
        if (!is_string($type)) {
            throw new StudioProjectionRejected(StudioProjectionRejection::UnsupportedField, $pointer . '/type');
        }
        foreach (['minLength' => 10_000_000, 'maxLength' => 10_000_000] as $name => $maximum) {
            if (is_int($schema[$name] ?? null) && $schema[$name] > $maximum) {
                throw new StudioProjectionRejected(
                    StudioProjectionRejection::UnsupportedField,
                    $pointer . '/' . $name,
                );
            }
        }
        foreach (['minItems' => 100_000, 'maxItems' => 100_000] as $name => $maximum) {
            if (is_int($schema[$name] ?? null) && $schema[$name] > $maximum) {
                throw new StudioProjectionRejected(
                    StudioProjectionRejection::UnsupportedField,
                    $pointer . '/' . $name,
                );
            }
        }

        $kind = $this->kind($schema, $pointer);
        $title = is_string($schema['title'] ?? null) && trim($schema['title']) !== ''
            ? trim($schema['title'])
            : self::humanize($sourceKey);
        $field = (object) [
            'id' => $id,
            'kind' => $kind,
            'label' => $this->message('field', implode('.', $path), $title),
            'required' => $required,
            'localized' => true,
            'cardinality' => $type === 'array' ? 'many' : 'one',
            'authoring' => (object) [
                'control' => $this->control($schema, $kind),
                'group' => 'content',
                'order' => $order,
                'width' => $type === 'boolean' ? 'half' : 'full',
            ],
            'extensions' => (object) [
                'kumwe.app/source-field' => (object) [
                    'storage' => 'data',
                    'key' => $sourceKey,
                    'schema' => $this->schemaObject($schema),
                ],
            ],
        ];
        if (is_string($schema['description'] ?? null) && trim($schema['description']) !== '') {
            $field->description = $this->message(
                'field-description',
                implode('.', $path),
                trim($schema['description']),
            );
        }

        $constraints = $this->constraints($schema);
        if (get_object_vars($constraints) !== []) {
            $field->constraints = $constraints;
        }
        if (array_key_exists('default', $schema)) {
            $field->defaultValue = $this->value($schema['default'], $schema, $pointer . '/default');
        }
        if ($kind === 'enum') {
            $field->enumValues = $this->enumValues($schema, $path, $pointer);
        }
        if ($type === 'object') {
            $field->fields = $this->nestedFields($schema, $path, $pointer);
        }
        if ($type === 'array') {
            $items = self::schemaMap($schema['items'] ?? null, $pointer . '/items');
            if (($items['type'] ?? null) === 'array') {
                throw new StudioProjectionRejected(
                    StudioProjectionRejection::UnsupportedField,
                    $pointer . '/items/type',
                );
            }
            $field->itemKind = $this->kind($items, $pointer . '/items');
            if ($field->itemKind === 'enum') {
                $field->enumValues = $this->enumValues($items, [...$path, 'item'], $pointer . '/items');
            }
            if (($items['type'] ?? null) === 'object') {
                $field->fields = $this->nestedFields($items, [...$path, 'item'], $pointer . '/items');
            }
        }

        return $field;
    }

    /**
     * Resolve a Content schema node to Studio's closed field-kind vocabulary.
     *
     * @param   array<string, mixed>  $schema   Content field schema.
     * @param   string                $pointer  Safe source location for refusal.
     *
     * @return  string  Studio field kind.
     *
     * @throws  StudioProjectionRejected  When the node is ambiguous or outside the vocabulary.
     *
     * @since   2.0.0
     */
    private function kind(array $schema, string $pointer): string
    {
        foreach (['allOf', 'anyOf', 'oneOf'] as $union) {
            if (array_key_exists($union, $schema)) {
                throw new StudioProjectionRejected(
                    StudioProjectionRejection::UnsupportedField,
                    $pointer . '/' . $union,
                );
            }
        }
        $type = $schema['type'] ?? null;
        if (!is_string($type)) {
            throw new StudioProjectionRejected(StudioProjectionRejection::UnsupportedField, $pointer . '/type');
        }
        if (($schema['x-kumwe-field'] ?? null) === 'media') {
            if ($type !== 'string') {
                throw new StudioProjectionRejected(
                    StudioProjectionRejection::UnsupportedField,
                    $pointer . '/x-kumwe-field',
                );
            }

            return 'media';
        }
        if (isset($schema['enum'])) {
            return 'enum';
        }

        return match ($type) {
            'string' => match ($schema['format'] ?? null) {
                'date' => 'date',
                'date-time' => 'date-time',
                null, 'email', 'uri', 'uri-reference', 'uuid' => 'string',
                default => throw new StudioProjectionRejected(
                    StudioProjectionRejection::UnsupportedField,
                    $pointer . '/format',
                ),
            },
            'integer' => 'integer',
            'number' => 'decimal',
            'boolean' => 'boolean',
            'object' => 'object',
            'array' => 'collection',
            default => throw new StudioProjectionRejected(
                StudioProjectionRejection::UnsupportedField,
                $pointer . '/type',
            ),
        };
    }

    /**
     * Choose a host-owned authoring control without changing the value representation.
     *
     * @param   array<string, mixed>  $schema  Content field schema.
     * @param   string                $kind    Resolved Studio field kind.
     *
     * @return  string  Qualified control identifier.
     *
     * @since   2.0.0
     */
    private function control(array $schema, string $kind): string
    {
        if (($schema['x-kumwe-field'] ?? null) === 'media') {
            return 'kumwe.app/media-reference';
        }

        return match ($schema['format'] ?? null) {
            'email' => 'kumwe.app/email',
            'uri', 'uri-reference' => 'kumwe.app/url',
            'uuid' => 'kumwe.app/uuid',
            default => match ($kind) {
                'boolean' => 'studio.control/switch',
                'date' => 'studio.control/date',
                'date-time' => 'studio.control/date-time',
                'enum' => 'studio.control/select',
                'integer', 'decimal' => 'studio.control/number',
                'object', 'collection' => 'kumwe.app/schema-group',
                default => 'studio.control/single-line-text',
            },
        };
    }

    /**
     * Map the constraint subset Studio represents directly; the source schema remains in extensions.
     *
     * @param   array<string, mixed>  $schema  Content field schema.
     *
     * @return  stdClass  Studio constraints, possibly empty.
     *
     * @since   2.0.0
     */
    private function constraints(array $schema): stdClass
    {
        $constraints = new stdClass();
        foreach (['minLength', 'maxLength', 'minItems', 'maxItems'] as $name) {
            if (is_int($schema[$name] ?? null)) {
                $constraints->{$name} = $schema[$name];
            }
        }
        foreach (['minimum', 'maximum'] as $name) {
            if (is_int($schema[$name] ?? null) || is_float($schema[$name] ?? null)) {
                $constraints->{$name} = CanonicalJson::stringify($schema[$name]);
            }
        }

        return $constraints;
    }

    /**
     * Project enum members only when Studio can identify every value without rewriting it.
     *
     * @param   array<string, mixed>  $schema   Content field schema.
     * @param   list<string>          $path     Source field path.
     * @param   string                $pointer  Safe refusal location.
     *
     * @return  list<stdClass>  Studio enum value documents in source order.
     *
     * @throws  StudioProjectionRejected  When a value is not an exact Studio local name.
     *
     * @since   2.0.0
     */
    private function enumValues(array $schema, array $path, string $pointer): array
    {
        $values = $schema['enum'] ?? null;
        if (!is_array($values) || !array_is_list($values) || $values === []) {
            throw new StudioProjectionRejected(StudioProjectionRejection::UnsupportedField, $pointer . '/enum');
        }
        if (count($values) > 1000) {
            throw new StudioProjectionRejected(StudioProjectionRejection::UnsupportedField, $pointer . '/enum');
        }
        $projected = [];
        foreach ($values as $index => $value) {
            if (!is_string($value) || preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D', $value) !== 1) {
                throw new StudioProjectionRejected(
                    StudioProjectionRejection::UnsupportedField,
                    $pointer . '/enum/' . $index,
                );
            }
            $projected[] = (object) [
                'value' => $value,
                'label' => $this->message('enum', implode('.', [...$path, $value]), self::humanize($value)),
            ];
        }

        return $projected;
    }

    /**
     * Project a closed nested object schema into nested Studio fields.
     *
     * @param   array<string, mixed>  $schema   Object schema.
     * @param   list<string>          $path     Source object path.
     * @param   string                $pointer  Safe refusal location.
     *
     * @return  list<stdClass>  Nested fields in canonical key order.
     *
     * @throws  StudioProjectionRejected  When the object is open or declares no fixed property map.
     *
     * @since   2.0.0
     */
    private function nestedFields(array $schema, array $path, string $pointer): array
    {
        $properties = self::schemaMap($schema['properties'] ?? null, $pointer . '/properties');
        if (($schema['additionalProperties'] ?? true) !== false) {
            throw new StudioProjectionRejected(
                StudioProjectionRejection::UnsupportedField,
                $pointer . '/additionalProperties',
            );
        }
        if (count($properties) > 1000) {
            throw new StudioProjectionRejected(
                StudioProjectionRejection::UnsupportedField,
                $pointer . '/properties',
            );
        }
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $keys = array_keys($properties);
        sort($keys, SORT_STRING);
        $fields = [];
        foreach ($keys as $index => $key) {
            $child = self::schemaMap(
                $properties[$key],
                $pointer . '/properties',
            );
            $fields[] = $this->field(
                $key,
                $key,
                $child,
                in_array($key, $required, true),
                [...$path, $key],
                $index,
            );
        }

        return $fields;
    }

    /**
     * Convert one stored Content value according to its exact schema, preserving strings unchanged.
     *
     * @param   mixed                 $value    Source value.
     * @param   array<string, mixed>  $schema   Exact schema node governing it.
     * @param   string                $pointer  Source JSON Pointer.
     *
     * @return  mixed  JSON value using `stdClass` for objects and lists for arrays.
     *
     * @throws  StudioProjectionRejected  When the value would need coercion or dynamic members.
     *
     * @since   2.0.0
     */
    private function value(mixed $value, array $schema, string $pointer): mixed
    {
        foreach (['allOf', 'anyOf', 'oneOf'] as $union) {
            if (array_key_exists($union, $schema)) {
                throw new StudioProjectionRejected(StudioProjectionRejection::UnsupportedField, $pointer);
            }
        }
        $type = $schema['type'] ?? null;
        return match ($type) {
            'string' => is_string($value)
                ? $value
                : throw new StudioProjectionRejected(StudioProjectionRejection::LossyValue, $pointer),
            'integer' => is_int($value)
                ? $value
                : throw new StudioProjectionRejected(StudioProjectionRejection::LossyValue, $pointer),
            'number' => is_int($value) || (is_float($value) && is_finite($value))
                ? $value
                : throw new StudioProjectionRejected(StudioProjectionRejection::LossyValue, $pointer),
            'boolean' => is_bool($value)
                ? $value
                : throw new StudioProjectionRejected(StudioProjectionRejection::LossyValue, $pointer),
            'null' => $value === null
                ? null
                : throw new StudioProjectionRejected(StudioProjectionRejection::LossyValue, $pointer),
            'array' => $this->arrayValue($value, $schema, $pointer),
            'object' => $this->objectValue($value, $schema, $pointer),
            default => throw new StudioProjectionRejected(StudioProjectionRejection::UnsupportedField, $pointer),
        };
    }

    /**
     * Convert one schema-governed PHP list to a JSON list.
     *
     * @param   mixed                 $value    Candidate list.
     * @param   array<string, mixed>  $schema   Array schema carrying its item schema.
     * @param   string                $pointer  Source JSON Pointer.
     *
     * @return  list<mixed>  Recursively normalized list.
     *
     * @throws  StudioProjectionRejected  When the value or item schema is not exact.
     *
     * @since   2.0.0
     */
    private function arrayValue(mixed $value, array $schema, string $pointer): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new StudioProjectionRejected(StudioProjectionRejection::LossyValue, $pointer);
        }
        $items = self::schemaMap($schema['items'] ?? null, $pointer . '/items');
        if (count($value) > 10_000) {
            throw new StudioProjectionRejected(StudioProjectionRejection::LossyValue, $pointer);
        }
        $result = [];
        foreach ($value as $index => $item) {
            $result[] = $this->value($item, $items, $pointer . '/' . $index);
        }

        return $result;
    }

    /**
     * Convert one closed schema-governed PHP map to a JSON object.
     *
     * @param   mixed                 $value    Candidate map.
     * @param   array<string, mixed>  $schema   Closed object schema.
     * @param   string                $pointer  Source JSON Pointer.
     *
     * @return  stdClass  Recursively normalized object.
     *
     * @throws  StudioProjectionRejected  When a dynamic or wrongly typed member would be guessed.
     *
     * @since   2.0.0
     */
    private function objectValue(mixed $value, array $schema, string $pointer): stdClass
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new StudioProjectionRejected(StudioProjectionRejection::LossyValue, $pointer);
        }
        $properties = self::schemaMap($schema['properties'] ?? null, $pointer . '/properties');
        if (($schema['additionalProperties'] ?? true) !== false) {
            throw new StudioProjectionRejected(StudioProjectionRejection::UnsupportedField, $pointer);
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        $result = new stdClass();
        foreach ($keys as $key) {
            $child = $properties[$key] ?? null;
            if (!is_string($key)) {
                throw new StudioProjectionRejected(
                    StudioProjectionRejection::LossyValue,
                    $pointer . '/' . self::escapePointer((string) $key),
                );
            }
            $child = self::schemaMap($child, $pointer . '/properties/' . self::escapePointer($key));
            $result->{$key} = $this->value($value[$key], $child, $pointer . '/' . self::escapePointer($key));
        }

        return $result;
    }

    /**
     * Convert a Content JSON Schema array into an object-preserving JSON value for extensions.
     *
     * @param   array<string, mixed>  $schema  Schema node represented as PHP arrays.
     *
     * @return  stdClass  Equivalent JSON Schema object.
     *
     * @throws  StudioProjectionRejected  When a nested schema node is not a JSON object.
     *
     * @since   2.0.0
     */
    private function schemaObject(array $schema): stdClass
    {
        $object = new stdClass();
        foreach ($schema as $keyword => $operand) {
            if (!is_string($keyword)) {
                continue;
            }
            if ($keyword === 'properties' && is_array($operand) && ($operand === [] || !array_is_list($operand))) {
                $members = new stdClass();
                foreach ($operand as $name => $child) {
                    if (is_string($name) && is_array($child)) {
                        $members->{$name} = $this->schemaObject(
                            self::schemaMap($child, '/schema/properties/' . self::escapePointer($name)),
                        );
                    }
                }
                $object->{$keyword} = $members;
                continue;
            }
            if ($keyword === 'items' && is_array($operand)) {
                $object->{$keyword} = $this->schemaObject(self::schemaMap($operand, '/schema/items'));
                continue;
            }
            if (in_array($keyword, ['allOf', 'anyOf', 'oneOf'], true) && is_array($operand)) {
                $object->{$keyword} = array_map(
                    fn (mixed $child): mixed => is_array($child)
                        ? $this->schemaObject(self::schemaMap($child, '/schema/' . $keyword))
                        : $child,
                    $operand,
                );
                continue;
            }
            $object->{$keyword} = self::genericJson($operand);
        }

        return $object;
    }

    /**
     * Normalize a generic JSON value where object-versus-list is unambiguous from PHP's array shape.
     *
     * @param   mixed  $value  JSON-compatible source value.
     *
     * @return  mixed  Value using `stdClass` for associative maps.
     *
     * @since   2.0.0
     */
    private static function genericJson(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::genericJson(...), $value);
        }
        $object = new stdClass();
        foreach ($value as $name => $member) {
            $object->{(string) $name} = self::genericJson($member);
        }

        return $object;
    }

    /**
     * Map a Content workflow key onto Studio's portable lifecycle while preserving the exact key elsewhere.
     *
     * @param   string               $state     Exact stored Content workflow key.
     * @param   ?WorkflowDefinition  $workflow  Custom workflow definition, null for the built-in lifecycle.
     *
     * @return  string  One of Studio entry's four statuses.
     *
     * @throws  StudioProjectionRejected  When a custom workflow does not declare the stored state.
     *
     * @since   2.0.0
     */
    private function portableStatus(string $state, ?WorkflowDefinition $workflow): string
    {
        if ($workflow === null) {
            $builtIn = ContentStatus::tryFrom($state);
            if ($builtIn === null) {
                throw new StudioProjectionRejected(StudioProjectionRejection::LossyValue, '/status');
            }

            return match ($builtIn) {
                ContentStatus::Draft => 'draft',
                ContentStatus::Review => 'in-review',
                ContentStatus::Published => 'published',
                ContentStatus::Archived => 'archived',
            };
        }
        foreach ($workflow->states() as $candidate) {
            if ($candidate->key === $state) {
                return $candidate->public ? 'published' : ($candidate->initial ? 'draft' : 'in-review');
            }
        }

        throw new StudioProjectionRejected(StudioProjectionRejection::LossyValue, '/status');
    }

    /**
     * Return one schema-valid message reference with a bounded deterministic key.
     *
     * @param   string  $kind     Message category.
     * @param   string  $source   Stable source coordinate.
     * @param   string  $default  Human-readable fallback.
     *
     * @return  stdClass  Studio message reference.
     *
     * @since   2.0.0
     */
    private function message(string $kind, string $source, string $default): stdClass
    {
        return (object) [
            'key' => 'kumwe.content/' . $kind . '-' . substr(hash('sha256', $source), 0, 32),
            'defaultMessage' => mb_substr($default, 0, 500),
        ];
    }

    /**
     * Validate a completed projection against Producer's exact pinned Studio schema.
     *
     * @param   string    $kind      `content-model` or `entry`.
     * @param   stdClass  $document  Completed projection.
     *
     * @return  stdClass  The same document after successful validation.
     *
     * @throws  StudioProjectionRejected  When the pinned schema refuses the result.
     *
     * @since   2.0.0
     */
    private function validated(string $kind, stdClass $document): stdClass
    {
        $validation = $this->schemas->validate($kind, $document);
        if (!$validation->valid()) {
            $diagnostics = $validation->diagnostics();
            if ($diagnostics === []) {
                throw new StudioProjectionRejected(StudioProjectionRejection::InvalidDocument, '');
            }
            $diagnostic = $diagnostics[0];
            throw new StudioProjectionRejected(
                StudioProjectionRejection::InvalidDocument,
                $diagnostic->instancePath,
            );
        }

        return $document;
    }

    /**
     * Require one JSON Schema node represented as a PHP string-keyed map.
     *
     * Empty PHP arrays are accepted because decoding an empty JSON object loses its object/list distinction.
     * Non-empty lists and integer-keyed maps are refused instead of being interpreted as schema objects.
     *
     * @param   mixed   $value    Candidate schema node.
     * @param   string  $pointer  Safe refusal location.
     *
     * @return  array<string, mixed>  Validated schema map.
     *
     * @throws  StudioProjectionRejected  When the value cannot represent a JSON Schema object.
     *
     * @since   2.0.0
     */
    private static function schemaMap(mixed $value, string $pointer): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new StudioProjectionRejected(StudioProjectionRejection::UnsupportedField, $pointer);
        }
        $map = [];
        foreach ($value as $key => $member) {
            if (!is_string($key)) {
                throw new StudioProjectionRejected(StudioProjectionRejection::UnsupportedField, $pointer);
            }
            $map[$key] = $member;
        }

        return $map;
    }

    /**
     * Build the immutable Studio model revision from a Content definition version.
     *
     * @param   int  $version  Positive Content definition version.
     *
     * @return  string  Stable revision token.
     *
     * @since   2.0.0
     */
    public static function modelRevision(int $version): string
    {
        return 'content-type-v' . $version;
    }

    /**
     * Build the immutable Studio entry revision from Content's optimistic version.
     *
     * @param   int  $version  Positive Content entry version.
     *
     * @return  string  Stable revision token.
     *
     * @since   2.0.0
     */
    public static function entryRevision(int $version): string
    {
        return 'content-entry-v' . $version;
    }

    /**
     * Encode an arbitrary valid Content workflow key into a reversible qualified Studio name.
     *
     * @param   string  $state  Exact Content workflow key.
     *
     * @return  string  Qualified name whose local part is `s` followed by the key's hexadecimal bytes.
     *
     * @since   2.0.0
     */
    private static function workflowState(string $state): string
    {
        return 'kumwe.content-state/s' . bin2hex($state);
    }

    /**
     * Turn a machine field key into a readable fallback without altering the source coordinate.
     *
     * @param   string  $key  Content field or enum key.
     *
     * @return  string  Words separated by spaces with an initial capital.
     *
     * @since   2.0.0
     */
    private static function humanize(string $key): string
    {
        return ucfirst(str_replace(['_', '-'], ' ', $key));
    }

    /**
     * Escape one source member for a JSON Pointer.
     *
     * @param   string  $token  Raw member name.
     *
     * @return  string  Pointer-safe token.
     *
     * @since   2.0.0
     */
    private static function escapePointer(string $token): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $token);
    }
}
