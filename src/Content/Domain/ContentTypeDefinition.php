<?php

declare(strict_types=1);

namespace Kumwe\App\Content\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\Context\Value\SiteContext;

/**
 * One published version of a site's content type: its handle, its schema, and the workflow it pins.
 *
 * Content types are versioned rather than edited, so entries validated against version two keep
 * validating against version two long after version three is published; a definition instance is
 * therefore a specific version, not a mutable type. `ContentModelService` is the only writer, and it
 * pins the workflow's version too, so republishing a workflow cannot silently change how existing
 * types behave. The constructor enforces every invariant a stored row must satisfy, which means a
 * definition read back from the database is either well formed or refuses to exist.
 *
 * @since  2.0.0
 */
final readonly class ContentTypeDefinition
{
    /**
     * JSON Schema document entry data of this type is validated against, always an object schema.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    private array $schema;

    /**
     * Assemble one version of a content type, rejecting anything the store must not round-trip.
     *
     * @param   string                $id               UUID identifying the content type across all of its versions.
     * @param   SiteContext           $site             Site whose content model this definition belongs to.
     * @param   string                $handle           Lowercase name operators and API callers address the type by.
     * @param   string                $name             Human-readable label shown in administrator screens.
     * @param   string                $workflowId       UUID of the workflow entries of this type follow.
     * @param   int                   $workflowVersion  Version of that workflow this definition pins itself to.
     * @param   array<string, mixed>  $schema           JSON object schema entry data must satisfy.
     * @param   int                   $version          Version of this definition, incremented on each publication.
     * @param   DateTimeImmutable     $createdAt        When version one of the content type was created.
     * @param   DateTimeImmutable     $publishedAt      When this particular version was published.
     *
     * @throws  InvalidArgumentException  When an ID is not a UUID, the handle or name is malformed, a
     *          version is below one, or the schema does not describe a JSON object.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $id,
        public SiteContext $site,
        public string $handle,
        public string $name,
        public string $workflowId,
        public int $workflowVersion,
        array $schema,
        public int $version,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $publishedAt,
    ) {
        self::uuid($id);
        if (preg_match('/^[a-z][a-z0-9_-]{0,99}$/D', $handle) !== 1) {
            throw new InvalidArgumentException('A content type handle must be a lowercase identifier.');
        }
        if (mb_strlen(trim($name)) < 1 || mb_strlen(trim($name)) > 255) {
            throw new InvalidArgumentException('A content type name must contain between 1 and 255 characters.');
        }
        self::uuid($workflowId);
        if ($workflowVersion < 1 || $version < 1) {
            throw new InvalidArgumentException('Definition versions must be positive integers.');
        }
        if (($schema['type'] ?? null) !== 'object' || array_is_list($schema)) {
            throw new InvalidArgumentException('A content type schema must describe a JSON object.');
        }
        $this->schema = $schema;
    }

    /**
     * Return the raw schema document, for handing to the validator or the compatibility checker.
     *
     * @return  array<string, mixed>  The stored JSON Schema object, unmodified.
     *
     * @since   2.0.0
     */
    public function schema(): array
    {
        return $this->schema;
    }

    /**
     * Project the schema's top-level properties into field descriptions callers can iterate.
     *
     * Only string-keyed object fragments become fields, so a hand-edited schema yields fewer fields
     * rather than an error; nested objects are not flattened, and the order follows the schema.
     *
     * @return  list<FieldDefinition>  One entry per usable top-level property, empty when the schema
     *          declares none.
     *
     * @since   2.0.0
     */
    public function fields(): array
    {
        $properties = $this->schema['properties'] ?? [];
        if (!is_array($properties) || array_is_list($properties)) {
            return [];
        }
        $required = $this->schema['required'] ?? [];
        if (!is_array($required)) {
            $required = [];
        }
        $fields = [];
        foreach ($properties as $key => $fieldSchema) {
            if (is_string($key) && is_array($fieldSchema) && !array_is_list($fieldSchema)) {
                /** @var array<string, mixed> $fieldSchema */
                $fields[] = new FieldDefinition($key, $fieldSchema, in_array($key, $required, true));
            }
        }
        return $fields;
    }

    /**
     * Flatten the definition into the payload the content model API and console command render.
     *
     * Both the raw `schema` and its `fields` projection are included, so a client can either enforce
     * the contract itself or build a form without a second request. Timestamps are ISO-8601.
     *
     * @return  array<string, mixed>  Snake-cased definition payload keyed for transport.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'site' => $this->site->identifier(),
            'handle' => $this->handle,
            'name' => $this->name,
            'workflow_id' => $this->workflowId,
            'workflow_version' => $this->workflowVersion,
            'schema' => $this->schema,
            'fields' => array_map(static fn (FieldDefinition $field): array => $field->toArray(), $this->fields()),
            'version' => $this->version,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'published_at' => $this->publishedAt->format(DATE_ATOM),
        ];
    }

    /**
     * Refuse an identifier that is not a canonical UUID.
     *
     * @param   string  $value  Candidate definition or workflow identifier.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the identifier is not a canonical UUID.
     *
     * @since   2.0.0
     */
    private static function uuid(string $value): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $value) !== 1) {
            throw new InvalidArgumentException('A definition ID must be a canonical UUID.');
        }
    }
}
