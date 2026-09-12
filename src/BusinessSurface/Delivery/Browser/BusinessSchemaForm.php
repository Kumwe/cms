<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Delivery\Browser;

use InvalidArgumentException;
use JsonException;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessSchema;
use Kumwe\Localization\Application\Translator;

/**
 * Builds and decodes a no-JavaScript editor for the closed custom-business schema subset.
 *
 * Every object property is declared, every array has a signed maximum, and every scalar uses an exact
 * type. This adapter turns that contract into a recursive semantic field tree and back into typed data.
 * Array row counts and optional structured-value presence use opaque path tokens, allowing a native GET
 * or POST round trip to add rows without exposing JSON authoring or accepting arbitrary field names.
 *
 * @phpstan-type FormBase array{
 *             kind: string,
 *             label: string,
 *             description: string,
 *             name: string,
 *             required: bool,
 *             nullable: bool,
 *             path_token: string
 *         }
 *
 * @since  2.0.0
 */
final readonly class BusinessSchemaForm
{
    /**
     * Maximum semantic nodes emitted into one browser form.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAX_NODES = 4096;

    /**
     * Capture one recursive form model and optional typed result.
     *
     * @param  list<array<string, mixed>>  $fields     Root object property controls.
     * @param  array<string, mixed>        $value      Typed object, empty until submitted.
     * @param  bool                        $submitted  Whether typed validation was requested.
     *
     * @since  2.0.0
     */
    private function __construct(
        public array $fields,
        public array $value,
        public bool $submitted,
    ) {
    }

    /**
     * Build one schema form from decoded browser controls.
     *
     * @param   array<string, mixed>  $schema      Closed custom-business root schema.
     * @param   Translator            $translator  Resolves the form's own display wording.
     * @param   string                $prefix      Root HTML input name, such as `parameters` or `input`.
     * @param   array<string, mixed>  $raw         Nested native input values.
     * @param   array<string, mixed>  $counts      Opaque array-path tokens mapped to requested row counts.
     * @param   array<string, mixed>  $presence    Opaque structured-path tokens explicitly included by users.
     * @param   bool                  $submitted   Whether to coerce and validate a typed result now.
     *
     * @return  self  Recursive semantic field tree and optional typed object.
     *
     * @throws  InvalidArgumentException  When schema or controls are malformed, unknown, or unbounded.
     *
     * @since   2.0.0
     */
    public static function fromInput(
        array $schema,
        Translator $translator,
        string $prefix,
        array $raw = [],
        array $counts = [],
        array $presence = [],
        bool $submitted = false,
    ): self {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $prefix) !== 1) {
            throw new InvalidArgumentException('A generated schema form prefix is invalid.');
        }
        self::controlMap($counts, 'row count');
        self::controlMap($presence, 'presence');
        if ($raw !== [] && array_is_list($raw)) {
            throw new InvalidArgumentException('A generated schema form root must be an object.');
        }
        /** @var array<string, true> $usedCounts */
        $usedCounts = [];
        /** @var array<string, true> $usedPresence */
        $usedPresence = [];
        $nodes = 0;
        $result = self::node(
            $translator,
            $schema,
            $prefix,
            $prefix,
            [],
            true,
            $raw,
            $counts,
            $presence,
            $usedCounts,
            $usedPresence,
            $nodes,
            $submitted,
        );
        if (($result['model']['kind'] ?? null) !== 'object') {
            throw new InvalidArgumentException('A generated schema form requires an object root schema.');
        }
        if (array_diff(array_keys($counts), array_keys($usedCounts)) !== []) {
            throw new InvalidArgumentException('A generated schema form contains an unknown row-count control.');
        }
        if (array_diff(array_keys($presence), array_keys($usedPresence)) !== []) {
            throw new InvalidArgumentException('A generated schema form contains an unknown presence control.');
        }
        $root = $result['model'];
        $fields = self::objectList(
            $root['children'] ?? null,
            'A generated schema form produced an invalid root field list.',
        );
        $value = [];
        if ($submitted) {
            $value = self::objectValue(
                $result['value'],
                'A generated schema form produced an invalid root object.',
            );
            CustomBusinessSchema::fromArray($schema)->assertValid($value, 'browser form');
        }

        return new self(
            $fields,
            $value,
            $submitted,
        );
    }

    /**
     * Build and optionally decode one recursive schema node.
     *
     * @param   Translator            $translator    Resolves the form's own display wording.
     * @param   array<string, mixed>  $schema        Current closed schema node.
     * @param   string                $label         Human-readable field label.
     * @param   string                $name          Native nested HTML input name.
     * @param   list<string|int>      $path          Stable property and item path.
     * @param   bool                  $required      Whether the parent requires this node.
     * @param   mixed                 $raw           Decoded native value at this node.
     * @param   array<string, mixed>  $counts        Submitted array row-count controls.
     * @param   array<string, mixed>  $presence      Submitted structured presence controls.
     * @param   array<string, true>   $usedCounts    Row-count tokens reached in the schema graph.
     * @param   array<string, true>   $usedPresence  Presence tokens reached in the schema graph.
     * @param   int                   $nodes         Shared rendered-node count.
     * @param   bool                  $submitted     Whether typed coercion is required.
     *
     * @return  array{model: array<string, mixed>, included: bool, value: mixed}  Model and typed value.
     *
     * @throws  InvalidArgumentException  When schema or input contradicts the supported closed subset.
     *
     * @since   2.0.0
     */
    private static function node(
        Translator $translator,
        array $schema,
        string $label,
        string $name,
        array $path,
        bool $required,
        mixed $raw,
        array $counts,
        array $presence,
        array &$usedCounts,
        array &$usedPresence,
        int &$nodes,
        bool $submitted,
    ): array {
        ++$nodes;
        if ($nodes > self::MAX_NODES) {
            throw new InvalidArgumentException('A generated schema form exceeds 4096 rendered controls.');
        }
        $type = self::primaryType($schema);
        $token = self::pathToken($path);
        $description = is_string($schema['description'] ?? null) ? $schema['description'] : '';
        $base = [
            'kind' => $type,
            'label' => self::schemaLabel($label, $schema['title'] ?? null),
            'description' => $description,
            'name' => $name,
            'required' => $required,
            'nullable' => self::nullable($schema),
            'path_token' => $token,
        ];
        if (array_key_exists('const', $schema)) {
            return self::constant(
                $translator,
                $base,
                $schema['const'],
                $required,
                $presence,
                $usedPresence,
                $submitted,
            );
        }
        if (isset($schema['enum'])) {
            return self::enumeration($translator, $base, $schema, $raw, $required, $submitted);
        }
        if ($type === 'object') {
            return self::object(
                $translator,
                $base,
                $schema,
                $path,
                $required,
                $raw,
                $counts,
                $presence,
                $usedCounts,
                $usedPresence,
                $nodes,
                $submitted,
            );
        }
        if ($type === 'array') {
            return self::array(
                $translator,
                $base,
                $schema,
                $path,
                $required,
                $raw,
                $counts,
                $presence,
                $usedCounts,
                $usedPresence,
                $nodes,
                $submitted,
            );
        }
        if ($type === 'null') {
            return self::constant(
                $translator,
                $base,
                null,
                $required,
                $presence,
                $usedPresence,
                $submitted,
            );
        }
        if (!in_array($type, ['string', 'integer', 'boolean', 'null'], true)) {
            throw new InvalidArgumentException('A generated schema form contains an unsupported type.');
        }

        return self::scalar($base, $schema, $type, $raw, $required, $submitted);
    }

    /**
     * Build one closed object fieldset and recurse through declared properties.
     *
     * @param   Translator            $translator    Resolves the form's own display wording.
     * @param   FormBase              $base          Common semantic model members.
     * @param   array<string, mixed>  $schema        Object schema.
     * @param   list<string|int>      $path          Current path.
     * @param   bool                  $required      Whether the parent requires this object.
     * @param   mixed                 $raw           Nested native object input.
     * @param   array<string, mixed>  $counts        Array row-count controls.
     * @param   array<string, mixed>  $presence      Structured presence controls.
     * @param   array<string, true>   $usedCounts    Reached row-count tokens.
     * @param   array<string, true>   $usedPresence  Reached presence tokens.
     * @param   int                   $nodes         Shared rendered-node count.
     * @param   bool                  $submitted     Whether typed coercion is required.
     *
     * @return  array{model: array<string, mixed>, included: bool, value: mixed}  Object model and value.
     *
     * @since   2.0.0
     */
    private static function object(
        Translator $translator,
        array $base,
        array $schema,
        array $path,
        bool $required,
        mixed $raw,
        array $counts,
        array $presence,
        array &$usedCounts,
        array &$usedPresence,
        int &$nodes,
        bool $submitted,
    ): array {
        if ($raw !== null && (!is_array($raw) || ($raw !== [] && array_is_list($raw)))) {
            throw new InvalidArgumentException('A generated schema object field is malformed.');
        }
        $rawObject = $raw === null
            ? []
            : self::objectValue($raw, 'A generated schema object field is malformed.');
        $properties = self::objectValue(
            $schema['properties'] ?? [],
            'A generated schema object contract is malformed.',
        );
        $requiredProperties = self::stringList(
            $schema['required'] ?? [],
            'A generated schema object contract is malformed.',
        );
        if (array_diff(array_keys($rawObject), array_keys($properties)) !== []) {
            throw new InvalidArgumentException('A generated schema object contains an undeclared property.');
        }
        $included = self::structuredIncluded($base, $required, $presence, $usedPresence);
        $children = [];
        $value = [];
        foreach ($properties as $handle => $property) {
            if (!is_string($handle) || !is_array($property) || array_is_list($property)) {
                throw new InvalidArgumentException('A generated schema object property is malformed.');
            }
            $child = self::node(
                $translator,
                self::objectValue($property, 'A generated schema object property is malformed.'),
                ucfirst(str_replace('_', ' ', $handle)),
                $base['name'] . '[' . $handle . ']',
                [...$path, $handle],
                in_array($handle, $requiredProperties, true),
                $rawObject[$handle] ?? null,
                $counts,
                $presence,
                $usedCounts,
                $usedPresence,
                $nodes,
                $submitted && $included,
            );
            $children[] = [...$child['model'], 'handle' => $handle];
            if ($submitted && $included && $child['included']) {
                $value[$handle] = $child['value'];
            }
        }
        $model = [
            ...$base,
            'kind' => 'object',
            'included' => $included,
            'presence_name' => 'schema_presence[' . $base['path_token'] . ']',
            'children' => $children,
        ];

        return ['model' => $model, 'included' => $included, 'value' => $value];
    }

    /**
     * Build one bounded array fieldset with a native row-count control.
     *
     * @param   FormBase              $base          Common semantic model members.
     * @param   array<string, mixed>  $schema        Array schema.
     * @param   list<string|int>      $path          Current path.
     * @param   bool                  $required      Whether the parent requires this array.
     * @param   mixed                 $raw           Nested native list input.
     * @param   array<string, mixed>  $counts        Array row-count controls.
     * @param   array<string, mixed>  $presence      Structured presence controls.
     * @param   array<string, true>   $usedCounts    Reached row-count tokens.
     * @param   array<string, true>   $usedPresence  Reached presence tokens.
     * @param   int                   $nodes         Shared rendered-node count.
     * @param   bool                  $submitted     Whether typed coercion is required.
     *
     * @return  array{model: array<string, mixed>, included: bool, value: mixed}  Array model and value.
     *
     * @since   2.0.0
     */
    private static function array(
        Translator $translator,
        array $base,
        array $schema,
        array $path,
        bool $required,
        mixed $raw,
        array $counts,
        array $presence,
        array &$usedCounts,
        array &$usedPresence,
        int &$nodes,
        bool $submitted,
    ): array {
        if ($raw !== null && (!is_array($raw) || !array_is_list($raw))) {
            throw new InvalidArgumentException('A generated schema array field is malformed.');
        }
        $rawList = is_array($raw) ? $raw : [];
        $minimum = is_int($schema['minItems'] ?? null) ? $schema['minItems'] : 0;
        $maximum = $schema['maxItems'] ?? null;
        $items = $schema['items'] ?? null;
        if (!is_int($maximum) || $maximum < 0 || $maximum > 200 || !is_array($items) || array_is_list($items)) {
            throw new InvalidArgumentException('A generated schema array contract is malformed.');
        }
        $items = self::objectValue($items, 'A generated schema array contract is malformed.');
        $token = $base['path_token'];
        $usedCounts[$token] = true;
        $requested = self::count($counts[$token] ?? null, $minimum, $maximum);
        $count = max($minimum, count($rawList), $requested ?? ($required && $maximum > 0 ? 1 : 0));
        if ($count > $maximum) {
            throw new InvalidArgumentException('A generated schema array exceeds its signed maximum.');
        }
        $included = self::structuredIncluded($base, $required, $presence, $usedPresence);
        $models = [];
        $value = [];
        for ($index = 0; $index < $count; ++$index) {
            $item = self::node(
                $translator,
                $items,
                $translator->translate('core.business.form.item_number', ['number' => $index + 1]),
                $base['name'] . '[' . $index . ']',
                [...$path, $index],
                true,
                $rawList[$index] ?? null,
                $counts,
                $presence,
                $usedCounts,
                $usedPresence,
                $nodes,
                $submitted && $included,
            );
            $models[] = $item['model'];
            if ($submitted && $included) {
                $value[] = $item['value'];
            }
        }
        $model = [
            ...$base,
            'kind' => 'array',
            'included' => $included,
            'presence_name' => 'schema_presence[' . $token . ']',
            'count_name' => 'schema_counts[' . $token . ']',
            'count' => $count,
            'min_items' => $minimum,
            'max_items' => $maximum,
            'items' => $models,
        ];

        return ['model' => $model, 'included' => $included, 'value' => $value];
    }

    /**
     * Build one scalar input and optionally coerce its exact submitted value.
     *
     * @param   FormBase              $base       Common semantic model members.
     * @param   array<string, mixed>  $schema     Scalar schema.
     * @param   string                $type       Primary scalar type.
     * @param   mixed                 $raw        Native scalar input.
     * @param   bool                  $required   Whether absence is invalid.
     * @param   bool                  $submitted  Whether typed coercion is required.
     *
     * @return  array{model: array<string, mixed>, included: bool, value: mixed}  Scalar model and value.
     *
     * @since   2.0.0
     */
    private static function scalar(
        array $base,
        array $schema,
        string $type,
        mixed $raw,
        bool $required,
        bool $submitted,
    ): array {
        if ($raw !== null && !is_string($raw)) {
            throw new InvalidArgumentException('A generated schema scalar field is malformed.');
        }
        $input = is_string($raw) ? $raw : '';
        $included = $required || $input !== '' || $type === 'null';
        $widget = match ($type) {
            'integer' => 'integer',
            'boolean' => 'select',
            'null' => 'const',
            default => is_string($schema['format'] ?? null) ? $schema['format'] : 'string',
        };
        $options = $type === 'boolean' ? [
            ['value' => self::token(true), 'label' => 'Yes'],
            ['value' => self::token(false), 'label' => 'No'],
        ] : [];
        $model = [
            ...$base,
            'kind' => $widget,
            'input_value' => $input,
            'options' => $options,
            'attributes' => self::attributes($schema),
        ];
        $value = null;
        if ($submitted && $included) {
            if ($input === '') {
                if (self::nullable($schema) || $type === 'null') {
                    return ['model' => $model, 'included' => true, 'value' => null];
                }
                throw new InvalidArgumentException('Required generated schema field ' . $base['label'] . ' is empty.');
            }
            if ($type === 'integer') {
                $value = self::integer($input);
            } elseif ($type === 'boolean') {
                $value = self::untoken($input);
                if (!is_bool($value)) {
                    throw new InvalidArgumentException('A generated schema boolean choice is invalid.');
                }
            } else {
                if (strlen($input) > 65_535) {
                    throw new InvalidArgumentException('A generated schema string is oversized.');
                }
                $value = $input;
            }
        }

        return ['model' => $model, 'included' => $included, 'value' => $value];
    }

    /**
     * Build one exact enum selector and optionally decode its chosen token.
     *
     * @param   Translator            $translator  Resolves the choice display wording.
     * @param   FormBase              $base        Common semantic model members.
     * @param   array<string, mixed>  $schema      Enum schema.
     * @param   mixed                 $raw         Native option token.
     * @param   bool                  $required    Whether absence is invalid.
     * @param   bool                  $submitted   Whether typed coercion is required.
     *
     * @return  array{model: array<string, mixed>, included: bool, value: mixed}  Select model and value.
     *
     * @since   2.0.0
     */
    private static function enumeration(
        Translator $translator,
        array $base,
        array $schema,
        mixed $raw,
        bool $required,
        bool $submitted,
    ): array {
        $values = $schema['enum'];
        if (!is_array($values) || !array_is_list($values) || $values === [] || count($values) > 128) {
            throw new InvalidArgumentException('A generated schema enumeration is malformed.');
        }
        if ($raw !== null && !is_string($raw)) {
            throw new InvalidArgumentException('A generated schema enumeration input is malformed.');
        }
        $input = is_string($raw) ? $raw : '';
        $options = [];
        foreach ($values as $index => $value) {
            $options[] = ['value' => self::token($value), 'label' => self::choiceLabel($translator, $value, $index)];
        }
        $included = $required || $input !== '';
        $model = [
            ...$base,
            'kind' => 'select',
            'input_value' => $input,
            'options' => $options,
            'attributes' => [],
        ];
        $value = null;
        if ($submitted && $included) {
            if ($input === '') {
                if (self::nullable($schema)) {
                    return ['model' => $model, 'included' => true, 'value' => null];
                }
                throw new InvalidArgumentException('A required generated schema choice is empty.');
            }
            $value = self::untoken($input);
            if (!self::containsExact($values, $value)) {
                throw new InvalidArgumentException('A generated schema choice is not declared.');
            }
        }

        return ['model' => $model, 'included' => $included, 'value' => $value];
    }

    /**
     * Build one server-owned constant value with optional presence control.
     *
     * @param   Translator            $translator    Resolves the constant display wording.
     * @param   FormBase              $base          Common semantic model members.
     * @param   mixed                 $value         Exact declared constant.
     * @param   bool                  $required      Whether the parent requires the property.
     * @param   array<string, mixed>  $presence      Structured presence controls.
     * @param   array<string, true>   $usedPresence  Reached presence tokens.
     * @param   bool                  $submitted     Whether typed coercion is required.
     *
     * @return  array{model: array<string, mixed>, included: bool, value: mixed}  Constant model and value.
     *
     * @since   2.0.0
     */
    private static function constant(
        Translator $translator,
        array $base,
        mixed $value,
        bool $required,
        array $presence,
        array &$usedPresence,
        bool $submitted,
    ): array {
        $included = self::structuredIncluded($base, $required, $presence, $usedPresence);
        return [
            'model' => [
                ...$base,
                'kind' => 'const',
                'display' => self::choiceLabel($translator, $value, 0),
                'included' => $included,
                'presence_name' => 'schema_presence[' . $base['path_token'] . ']',
            ],
            'included' => $included,
            'value' => $submitted && $included ? $value : null,
        ];
    }

    /**
     * Determine whether a structured or constant property is included.
     *
     * @param   FormBase              $base          Common semantic model members.
     * @param   bool                  $required      Whether the parent requires the property.
     * @param   array<string, mixed>  $presence      Submitted presence controls.
     * @param   array<string, true>   $usedPresence  Reached presence tokens.
     *
     * @return  bool  True when required or explicitly selected.
     *
     * @since   2.0.0
     */
    private static function structuredIncluded(
        array $base,
        bool $required,
        array $presence,
        array &$usedPresence,
    ): bool {
        if ($base['path_token'] === 'root') {
            return true;
        }
        $token = $base['path_token'];
        $usedPresence[$token] = true;
        $selected = $presence[$token] ?? null;
        if ($selected !== null && $selected !== '1' && $selected !== 1 && $selected !== true) {
            throw new InvalidArgumentException('A generated schema presence control is invalid.');
        }
        return $required || $selected !== null;
    }

    /**
     * Read one optional bounded array row count.
     *
     * @param   mixed  $value    Submitted count.
     * @param   int    $minimum  Signed minimum.
     * @param   int    $maximum  Signed maximum.
     *
     * @return  int|null  Valid count or null when absent.
     *
     * @since   2.0.0
     */
    private static function count(mixed $value, int $minimum, int $maximum): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            $count = $value;
        } elseif (is_string($value) && preg_match('/^(?:0|[1-9][0-9]{0,2})$/D', $value) === 1) {
            $count = (int) $value;
        } else {
            throw new InvalidArgumentException('A generated schema array row count is invalid.');
        }
        if ($count < $minimum || $count > $maximum) {
            throw new InvalidArgumentException('A generated schema array row count exceeds its signed bounds.');
        }
        return $count;
    }

    /**
     * Read the one non-null primary type from a schema node.
     *
     * @param   array<string, mixed>  $schema  Closed schema node.
     *
     * @return  string  Primary exact JSON type.
     *
     * @since   2.0.0
     */
    private static function primaryType(array $schema): string
    {
        $declared = $schema['type'] ?? null;
        $types = is_string($declared) ? [$declared] : $declared;
        if (!is_array($types) || !array_is_list($types) || $types === [] || count($types) > 2) {
            throw new InvalidArgumentException('A generated schema field type is malformed.');
        }
        foreach ($types as $type) {
            if (is_string($type) && $type !== 'null') {
                return $type;
            }
        }
        return 'null';
    }

    /**
     * Report whether a schema node explicitly admits null.
     *
     * @param   array<string, mixed>  $schema  Closed schema node.
     *
     * @return  bool  True for an explicit nullable union or null type.
     *
     * @since   2.0.0
     */
    private static function nullable(array $schema): bool
    {
        $type = $schema['type'] ?? null;
        return $type === 'null' || (is_array($type) && in_array('null', $type, true));
    }

    /**
     * Carry safe scalar bounds into the semantic model.
     *
     * @param   array<string, mixed>  $schema  Scalar schema.
     *
     * @return  array<string, int>  HTML-compatible exact bounds.
     *
     * @since   2.0.0
     */
    private static function attributes(array $schema): array
    {
        $attributes = [];
        foreach (
            [
            'minLength' => 'minlength',
            'maxLength' => 'maxlength',
            'minimum' => 'min',
            'maximum' => 'max',
            ] as $schemaKey => $attribute
        ) {
            if (is_int($schema[$schemaKey] ?? null)) {
                $attributes[$attribute] = $schema[$schemaKey];
            }
        }
        return $attributes;
    }

    /**
     * Parse one canonical platform integer.
     *
     * @param   string  $value  Native integer input.
     *
     * @return  int  Exact platform integer.
     *
     * @since   2.0.0
     */
    private static function integer(string $value): int
    {
        if (preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            throw new InvalidArgumentException('A generated schema integer is malformed.');
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($integer)) {
            throw new InvalidArgumentException('A generated schema integer is outside platform range.');
        }
        return $integer;
    }

    /**
     * Encode one exact JSON value as an opaque native selector token.
     *
     * @param   mixed  $value  Declared exact enum value.
     *
     * @return  string  URL-safe bounded token.
     *
     * @since   2.0.0
     */
    private static function token(mixed $value): string
    {
        $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return rtrim(strtr(base64_encode($encoded), '+/', '-_'), '=');
    }

    /**
     * Decode one exact JSON selector token.
     *
     * @param   string  $token  Native submitted token.
     *
     * @return  mixed  Exact decoded JSON value.
     *
     * @since   2.0.0
     */
    private static function untoken(string $token): mixed
    {
        if ($token === '' || strlen($token) > 262_144 || preg_match('/^[A-Za-z0-9_-]+$/D', $token) !== 1) {
            throw new InvalidArgumentException('A generated schema selector token is invalid.');
        }
        $padding = (4 - strlen($token) % 4) % 4;
        $decoded = base64_decode(strtr($token . str_repeat('=', $padding), '-_', '+/'), true);
        if (!is_string($decoded)) {
            throw new InvalidArgumentException('A generated schema selector token is invalid.');
        }
        try {
            return json_decode($decoded, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('A generated schema selector token is invalid.', 0, $exception);
        }
    }

    /**
     * Compare exact JSON values without PHP's loose scalar rules.
     *
     * @param   list<mixed>  $values     Declared enum values.
     * @param   mixed        $candidate  Decoded submitted value.
     *
     * @return  bool  True when canonical JSON bytes match one declaration.
     *
     * @since   2.0.0
     */
    private static function containsExact(array $values, mixed $candidate): bool
    {
        $encoded = self::token($candidate);
        foreach ($values as $value) {
            if (self::token($value) === $encoded) {
                return true;
            }
        }
        return false;
    }

    /**
     * Produce a safe short label for an exact enum or constant value.
     *
     * @param   Translator  $translator  Resolves the display wording.
     * @param   mixed       $value       Exact declared value.
     * @param   int         $index       Zero-based declaration index.
     *
     * @return  string  Human-readable scalar text or numbered structured choice.
     *
     * @since   2.0.0
     */
    private static function choiceLabel(Translator $translator, mixed $value, int $index): string
    {
        return match (true) {
            $value === null => $translator->translate('core.business.form.not_set'),
            $value === true => $translator->translate('core.business.form.yes'),
            $value === false => $translator->translate('core.business.form.no'),
            is_string($value), is_int($value) => (string) $value,
            default => $translator->translate('core.business.form.declared_choice', ['number' => $index + 1]),
        };
    }

    /**
     * Use a bounded schema title or a label derived from its declared handle.
     *
     * @param   string  $fallback  Derived label.
     * @param   mixed   $title     Optional schema title.
     *
     * @return  string  Operator-facing label.
     *
     * @since   2.0.0
     */
    private static function schemaLabel(string $fallback, mixed $title): string
    {
        return is_string($title) && $title !== '' ? $title : $fallback;
    }

    /**
     * Derive one non-reversible form-control identity from a schema path.
     *
     * @param   list<string|int>  $path  Property and rendered item path.
     *
     * @return  string  Root marker or lowercase SHA-256 prefix.
     *
     * @since   2.0.0
     */
    private static function pathToken(array $path): string
    {
        return $path === [] ? 'root' : 'p' . substr(hash('sha256', json_encode($path, JSON_THROW_ON_ERROR)), 0, 24);
    }

    /**
     * Validate and narrow one decoded object without coercing numeric keys.
     *
     * @param   mixed   $value    Candidate object.
     * @param   string  $message  Safe validation failure.
     *
     * @return  array<string, mixed>  String-keyed object.
     *
     * @since   2.0.0
     */
    private static function objectValue(mixed $value, string $message): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException($message);
        }
        $object = [];
        foreach ($value as $key => $member) {
            if (!is_string($key)) {
                throw new InvalidArgumentException($message);
            }
            $object[$key] = $member;
        }

        return $object;
    }

    /**
     * Validate and narrow one list of decoded objects.
     *
     * @param   mixed   $value    Candidate list.
     * @param   string  $message  Safe validation failure.
     *
     * @return  list<array<string, mixed>>  Validated object list.
     *
     * @since   2.0.0
     */
    private static function objectList(mixed $value, string $message): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException($message);
        }
        $items = [];
        foreach ($value as $item) {
            $items[] = self::objectValue($item, $message);
        }

        return $items;
    }

    /**
     * Validate and narrow one list of strings.
     *
     * @param   mixed   $value    Candidate list.
     * @param   string  $message  Safe validation failure.
     *
     * @return  list<string>  Validated string list.
     *
     * @since   2.0.0
     */
    private static function stringList(mixed $value, string $message): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException($message);
        }
        $items = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new InvalidArgumentException($message);
            }
            $items[] = $item;
        }

        return $items;
    }

    /**
     * Validate one opaque path-control map before recursive consumption.
     *
     * @param   array<string, mixed>  $controls  Submitted control map.
     * @param   string                $kind      Human-readable control kind.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function controlMap(array $controls, string $kind): void
    {
        if (count($controls) > self::MAX_NODES) {
            throw new InvalidArgumentException('A generated schema ' . $kind . ' map is unbounded.');
        }
        foreach (array_keys($controls) as $token) {
            if (!is_string($token) || preg_match('/^p[a-f0-9]{24}$/D', $token) !== 1) {
                throw new InvalidArgumentException('A generated schema ' . $kind . ' token is invalid.');
            }
        }
    }
}
