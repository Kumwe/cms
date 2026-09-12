<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Delivery\Browser;

use InvalidArgumentException;
use Kumwe\Localization\Application\Translator;

/**
 * Builds and decodes bounded native controls for open structured business fields.
 *
 * Unlike custom contracts, bounded JSON and contributed object fields may declare an open set of keys.
 * This model therefore represents each object property as an explicit key plus a typed value node, and
 * each list member as a typed row. A no-JavaScript configuration submit can change row counts or node
 * types before the final save. Raw JSON is never accepted or emitted by this boundary.
 *
 * @since  2.0.0
 */
final readonly class BusinessStructuredFieldForm
{
    /**
     * Maximum depth admitted by the record value guard.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAX_DEPTH = 8;

    /**
     * Maximum nodes rendered or decoded in one field.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAX_NODES = 4096;

    /**
     * Maximum children for an open nested object or list.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAX_NESTED_MEMBERS = 128;

    /**
     * Maximum caller-authored property-key length.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAX_KEY_BYTES = 191;

    /**
     * Maximum one structured scalar string may occupy before the aggregate byte gate.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAX_STRING_BYTES = 65_536;

    /**
     * Capture one recursive semantic model and optional typed value.
     *
     * @param  array<string, mixed>  $model      Core-owned recursive editor model.
     * @param  mixed                 $value      Decoded value, null before final submission.
     * @param  bool                  $submitted  Whether the value was decoded for a save.
     *
     * @since  2.0.0
     */
    private function __construct(
        public array $model,
        public mixed $value,
        public bool $submitted,
    ) {
    }

    /**
     * Build an object or list editor from retained controls or a normalized initial value.
     *
     * @param   Translator  $translator  Resolves the editor's own display wording.
     * @param   string      $rootKind    Fixed root kind, `object` or `array`.
     * @param   string      $name        Native nested input name for this field.
     * @param   mixed       $input       Retained structured control document, or null on the first render.
     * @param   mixed       $initial     Normalized existing or retained application value.
     * @param   int         $maximum     Signed root property or item maximum.
     * @param   bool        $submitted   Whether to decode the final typed value.
     *
     * @return  self  Recursive editor model and optional typed result.
     *
     * @throws  InvalidArgumentException  When controls are malformed, unknown, too deep, or too wide.
     *
     * @since   2.0.0
     */
    public static function fromInput(
        Translator $translator,
        string $rootKind,
        string $name,
        mixed $input,
        mixed $initial,
        int $maximum,
        bool $submitted,
    ): self {
        if (!in_array($rootKind, ['object', 'array'], true)) {
            throw new InvalidArgumentException('A generated structured field root kind is invalid.');
        }
        if (preg_match('/^(?:structured|target_structured)\[[a-z][a-z0-9_]{0,62}\]$/D', $name) !== 1) {
            throw new InvalidArgumentException('A generated structured field input name is invalid.');
        }
        if ($maximum < 0 || $maximum > 1000) {
            throw new InvalidArgumentException('A generated structured field root bound is invalid.');
        }
        $controls = $input === null ? self::controls($initial, $rootKind, 0) : $input;
        if (!is_array($controls) || array_is_list($controls)) {
            throw new InvalidArgumentException('A generated structured field control document is malformed.');
        }
        $controls = self::objectValue(
            $controls,
            'A generated structured field control document is malformed.',
        );
        $nodes = 0;
        $result = self::node($translator, $controls, $name, $rootKind, $maximum, 0, $nodes, $submitted);

        return new self($result['model'], $submitted ? $result['value'] : null, $submitted);
    }

    /**
     * Build one recursive node and optionally decode its typed value.
     *
     * @param   Translator            $translator  Resolves the editor's own display wording.
     * @param   array<string, mixed>  $input       Current structured control node.
     * @param   string                $name        Native nested input name.
     * @param   ?string               $fixedKind   Fixed root kind, null for a user-selectable nested kind.
     * @param   int                   $maximum     Child bound for this composite node.
     * @param   int                   $depth       Current structural depth.
     * @param   int                   $nodes       Shared rendered-node count.
     * @param   bool                  $submitted   Whether typed decoding is required.
     *
     * @return  array{model: array<string, mixed>, value: mixed}  Semantic node and decoded value.
     *
     * @throws  InvalidArgumentException  When the node contradicts the closed control grammar.
     *
     * @since   2.0.0
     */
    private static function node(
        Translator $translator,
        array $input,
        string $name,
        ?string $fixedKind,
        int $maximum,
        int $depth,
        int &$nodes,
        bool $submitted,
    ): array {
        ++$nodes;
        if ($depth > self::MAX_DEPTH || $nodes > self::MAX_NODES) {
            throw new InvalidArgumentException('A generated structured field exceeds its structural bounds.');
        }
        $kind = $fixedKind ?? ($input['kind'] ?? null);
        if (!is_string($kind) || !in_array($kind, self::kinds(), true)) {
            throw new InvalidArgumentException('A generated structured field value kind is invalid.');
        }
        if ($fixedKind !== null && (($input['kind'] ?? $fixedKind) !== $fixedKind)) {
            throw new InvalidArgumentException('A generated structured field root kind cannot be changed.');
        }
        if (in_array($kind, ['object', 'array'], true)) {
            return self::composite(
                $translator,
                $input,
                $name,
                $kind,
                $fixedKind !== null,
                $maximum,
                $depth,
                $nodes,
                $submitted,
            );
        }

        return self::scalar($translator, $input, $name, $kind, $fixedKind !== null, $submitted);
    }

    /**
     * Build an object property editor or ordered list editor.
     *
     * @param   Translator            $translator  Resolves the editor's own display wording.
     * @param   array<string, mixed>  $input       Current composite controls.
     * @param   string                $name        Native nested input name.
     * @param   string                $kind        `object` or `array`.
     * @param   bool                  $fixed       Whether the kind is fixed by the root field.
     * @param   int                   $maximum     Maximum members for this node.
     * @param   int                   $depth       Current structural depth.
     * @param   int                   $nodes       Shared rendered-node count.
     * @param   bool                  $submitted   Whether typed decoding is required.
     *
     * @return  array{model: array<string, mixed>, value: array<mixed>}  Composite model and value.
     *
     * @since   2.0.0
     */
    private static function composite(
        Translator $translator,
        array $input,
        string $name,
        string $kind,
        bool $fixed,
        int $maximum,
        int $depth,
        int &$nodes,
        bool $submitted,
    ): array {
        self::keys($input, ['kind', 'count', 'entries']);
        $entries = self::objectList(
            $input['entries'] ?? [],
            'A generated structured field member list is malformed or unbounded.',
        );
        if (count($entries) > $maximum) {
            throw new InvalidArgumentException('A generated structured field member list is malformed or unbounded.');
        }
        $count = self::count($input['count'] ?? count($entries), $maximum);
        $entries = array_slice($entries, 0, $count);
        while (count($entries) < $count) {
            $entries[] = $kind === 'object'
                ? ['key' => '', 'node' => ['kind' => 'string', 'value' => '']]
                : ['node' => ['kind' => 'string', 'value' => '']];
        }
        $models = [];
        $value = [];
        $seen = [];
        foreach ($entries as $index => $entry) {
            self::keys($entry, $kind === 'object' ? ['key', 'node'] : ['node']);
            $key = '';
            if ($kind === 'object') {
                $key = self::key($entry['key'] ?? null, $submitted);
                if ($submitted && isset($seen[$key])) {
                    throw new InvalidArgumentException('A generated structured object contains a duplicate key.');
                }
                if ($submitted) {
                    $seen[$key] = true;
                }
            }
            $child = $entry['node'] ?? null;
            if (!is_array($child) || array_is_list($child)) {
                throw new InvalidArgumentException('A generated structured field child node is malformed.');
            }
            $child = self::objectValue($child, 'A generated structured field child node is malformed.');
            $childName = $name . '[entries][' . $index . '][node]';
            $result = self::node(
                $translator,
                $child,
                $childName,
                null,
                self::MAX_NESTED_MEMBERS,
                $depth + 1,
                $nodes,
                $submitted,
            );
            $models[] = [
                'index' => $index,
                'key' => $kind === 'object' ? $key : '',
                'key_name' => $name . '[entries][' . $index . '][key]',
                'node' => $result['model'],
            ];
            if ($submitted) {
                if ($kind === 'object') {
                    $value[$key] = $result['value'];
                } else {
                    $value[] = $result['value'];
                }
            }
        }

        return [
            'model' => [
                'kind' => $kind,
                'fixed_kind' => $fixed,
                'kind_name' => $name . '[kind]',
                'count_name' => $name . '[count]',
                'count' => $count,
                'max_count' => $maximum,
                'entries' => $models,
                'kind_options' => self::kindOptions($translator),
            ],
            'value' => $value,
        ];
    }

    /**
     * Build and optionally coerce one scalar structured value.
     *
     * @param   Translator            $translator  Resolves the editor's own display wording.
     * @param   array<string, mixed>  $input       Current scalar controls.
     * @param   string                $name        Native nested input name.
     * @param   string                $kind        Scalar kind.
     * @param   bool                  $fixed       Whether the kind is fixed by the root.
     * @param   bool                  $submitted   Whether typed decoding is required.
     *
     * @return  array{model: array<string, mixed>, value: mixed}  Scalar model and value.
     *
     * @since   2.0.0
     */
    private static function scalar(
        Translator $translator,
        array $input,
        string $name,
        string $kind,
        bool $fixed,
        bool $submitted,
    ): array {
        self::keys($input, $kind === 'null' ? ['kind'] : ['kind', 'value']);
        $raw = $input['value'] ?? '';
        if ($kind !== 'null' && !is_string($raw)) {
            throw new InvalidArgumentException('A generated structured scalar control is malformed.');
        }
        $rawValue = is_string($raw) ? $raw : '';
        $value = match ($kind) {
            'string' => self::string($rawValue),
            'integer' => self::integer($rawValue, $submitted),
            'boolean' => self::boolean($rawValue, $submitted),
            'null' => null,
            default => throw new InvalidArgumentException('A generated structured scalar kind is unsupported.'),
        };

        return [
            'model' => [
                'kind' => $kind,
                'fixed_kind' => $fixed,
                'kind_name' => $name . '[kind]',
                'value_name' => $name . '[value]',
                'value' => $kind === 'boolean' && is_bool($value) ? ($value ? '1' : '0') : $rawValue,
                'kind_options' => self::kindOptions($translator),
            ],
            'value' => $value,
        ];
    }

    /**
     * Convert an initial typed value to the closed control grammar.
     *
     * @param   mixed    $value      Existing application value.
     * @param   ?string  $fixedKind  Fixed root kind, null for a nested inferred kind.
     * @param   int      $depth      Current conversion depth.
     *
     * @return  array<string, mixed>  Retainable controls.
     *
     * @since   2.0.0
     */
    private static function controls(mixed $value, ?string $fixedKind, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('A generated structured initial value is too deep.');
        }
        if ($fixedKind !== null && $value === null) {
            return ['kind' => $fixedKind, 'count' => 0, 'entries' => []];
        }
        $kind = $fixedKind ?? match (true) {
            is_array($value) && !array_is_list($value) => 'object',
            is_array($value) => 'array',
            is_string($value) => 'string',
            is_int($value) => 'integer',
            is_bool($value) => 'boolean',
            $value === null => 'null',
            default => throw new InvalidArgumentException('A generated structured initial value has no safe type.'),
        };
        if (in_array($kind, ['object', 'array'], true)) {
            if (!is_array($value)) {
                throw new InvalidArgumentException('A generated structured initial value has the wrong root type.');
            }
            if (
                $value !== []
                && (($kind === 'object') === array_is_list($value))
            ) {
                throw new InvalidArgumentException('A generated structured initial value has the wrong root type.');
            }
            $entries = [];
            foreach ($value as $key => $child) {
                $entry = ['node' => self::controls($child, null, $depth + 1)];
                if ($kind === 'object') {
                    $entry = ['key' => (string) $key, ...$entry];
                }
                $entries[] = $entry;
            }
            return ['kind' => $kind, 'count' => count($entries), 'entries' => $entries];
        }

        if ($kind === 'string' && is_string($value)) {
            return ['kind' => $kind, 'value' => $value];
        }
        if ($kind === 'integer' && is_int($value)) {
            return ['kind' => $kind, 'value' => (string) $value];
        }
        if ($kind === 'boolean' && is_bool($value)) {
            return ['kind' => $kind, 'value' => $value ? '1' : '0'];
        }
        if ($kind === 'null' && $value === null) {
            return ['kind' => $kind];
        }

        throw new InvalidArgumentException('A generated structured initial value kind is invalid.');
    }

    /**
     * Validate and narrow one decoded structured-control object.
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
     * Validate and narrow one list of structured-control objects.
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
     * Reject undeclared controls at one structured grammar node.
     *
     * @param   array<string, mixed>  $input    Candidate node.
     * @param   list<string>          $allowed  Exact allowed keys.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function keys(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new InvalidArgumentException('A generated structured field contains an unknown control.');
        }
    }

    /**
     * Parse one requested composite member count.
     *
     * @param   mixed  $value    Candidate integer or canonical integer string.
     * @param   int    $maximum  Signed maximum.
     *
     * @return  int  Member count within the bound.
     *
     * @since   2.0.0
     */
    private static function count(mixed $value, int $maximum): int
    {
        if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]{0,3})$/D', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 0 || $value > $maximum) {
            throw new InvalidArgumentException('A generated structured field member count is invalid.');
        }
        return $value;
    }

    /**
     * Validate one dynamic object property key.
     *
     * @param   mixed  $value      Candidate key.
     * @param   bool   $submitted  Whether an empty retained key must now be rejected.
     *
     * @return  string  Safe key, possibly empty during configuration.
     *
     * @since   2.0.0
     */
    private static function key(mixed $value, bool $submitted): string
    {
        if (
            !is_string($value)
            || strlen($value) > self::MAX_KEY_BYTES
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            || ($submitted && $value === '')
        ) {
            throw new InvalidArgumentException('A generated structured object key is invalid.');
        }
        return $value;
    }

    /**
     * Bound one scalar string.
     *
     * @param   string  $value  Candidate exact text.
     *
     * @return  string  Text unchanged.
     *
     * @since   2.0.0
     */
    private static function string(string $value): string
    {
        if (strlen($value) > self::MAX_STRING_BYTES) {
            throw new InvalidArgumentException('A generated structured string is too long.');
        }
        return $value;
    }

    /**
     * Parse a canonical platform integer on final submission.
     *
     * @param   string  $value      Candidate literal.
     * @param   bool    $submitted  Whether empty retained controls must be rejected.
     *
     * @return  int|string  Parsed integer, or retained text before submission.
     *
     * @since   2.0.0
     */
    private static function integer(string $value, bool $submitted): int|string
    {
        if (!$submitted) {
            return $value;
        }
        if (preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            throw new InvalidArgumentException('A generated structured integer is invalid.');
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($integer)) {
            throw new InvalidArgumentException('A generated structured integer is outside the platform range.');
        }
        return $integer;
    }

    /**
     * Parse a closed structured boolean on final submission.
     *
     * @param   string  $value      Candidate `1` or `0` token.
     * @param   bool    $submitted  Whether invalid retained controls must be rejected.
     *
     * @return  bool|string  Parsed boolean, or retained token before submission.
     *
     * @since   2.0.0
     */
    private static function boolean(string $value, bool $submitted): bool|string
    {
        if (!$submitted) {
            return $value;
        }
        return match ($value) {
            '1' => true,
            '0' => false,
            default => throw new InvalidArgumentException('A generated structured boolean is invalid.'),
        };
    }

    /**
     * Return supported structural type names in stable order.
     *
     * @return  list<string>  Closed type set.
     *
     * @since   2.0.0
     */
    private static function kinds(): array
    {
        return ['string', 'integer', 'boolean', 'null', 'object', 'array'];
    }

    /**
     * Return label-bearing type choices for core-owned templates.
     *
     * @param   Translator  $translator  Resolves the choice labels for the locale in flight.
     *
     * @return  list<array{value: string, label: string}>  Stable type choices.
     *
     * @since   2.0.0
     */
    private static function kindOptions(Translator $translator): array
    {
        return [
            ['value' => 'string', 'label' => $translator->translate('core.business.form.kind_text')],
            ['value' => 'integer', 'label' => $translator->translate('core.business.form.kind_integer')],
            ['value' => 'boolean', 'label' => $translator->translate('core.business.form.kind_boolean')],
            ['value' => 'null', 'label' => $translator->translate('core.business.form.kind_null')],
            ['value' => 'object', 'label' => $translator->translate('core.business.form.kind_object')],
            ['value' => 'array', 'label' => $translator->translate('core.business.form.kind_array')],
        ];
    }
}
