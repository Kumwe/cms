<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Domain;

use Kumwe\Localization\Domain\LocaleTag;

/**
 * One field of a business entity's contract, validated the moment it is constructed.
 *
 * A field is the unit the rest of the business stack agrees on: the schema compiler turns it into a
 * column with its indexes, `RecordValueCodec` normalizes values through it, `RecordRuleValidator`
 * enforces its required, immutable, and read-only rules on every write, and the compatibility analyzer
 * diffs two of them to classify what a new version does to stored data. Construction refuses a field
 * that contradicts itself — required and nullable at once, computed without a formula, an encrypted
 * secret that claims to be searchable — and canonicalizes what the published checksum is taken over, so
 * that two fields declaring the same thing serialize to the same bytes. Rules needing the wider graph,
 * such as whether the declared type is registered or a referenced entity is reachable, belong to
 * `BusinessDefinitionValidator` instead.
 *
 * @since  2.0.0
 */
final readonly class FieldDefinition
{
    /**
     * Normalizer identifiers applied to a submitted value, in declared order, before validation.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $normalizers;

    /**
     * Validation rules run after normalization, each a `rule` document with the rule's own arguments.
     *
     * @var    list<array<string, mixed>>
     * @since  2.0.0
     */
    public array $validators;

    /**
     * Surfaces the field is rendered on: `list`, `detail`, `form`, `history`, or `relation`.
     *
     * Stored deduplicated and sorted, so declaring the same surfaces in another order yields one
     * canonical document.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $placements;

    /**
     * Type-specific settings, keyed by name and sorted so the canonical document is deterministic.
     *
     * Which keys are meaningful belongs to the declared field type; `BusinessDefinitionValidator`
     * rejects any key that type does not register.
     *
     * @var    array<string, scalar|list<scalar|null>|null>
     * @since  2.0.0
     */
    public array $configuration;

    /**
     * Translations of the field's operator-facing wording, keyed by member then by locale tag.
     *
     * Empty for a field declared in one language, which is what keeps such a field's canonical document
     * — and therefore the checksum of every published version carrying it — exactly as it was before the
     * locale dimension existed.
     *
     * @var    array<string, array<string, string>>
     * @since  2.0.0
     */
    public array $textTranslations;

    /**
     * Capture a field declaration and reject one the runtime could not honour.
     *
     * These rules are settled once, at construction, so every consumer downstream may treat a field it
     * is handed as internally consistent and already canonicalized.
     *
     * @param   string                                        $handle                Stable snake_case field identifier.
     * @param   string                                        $label                 Operator-facing name for the field.
     * @param   string                                        $type                  Namespaced field-type identifier.
     * @param   string                                        $description           Editor-facing note on its purpose.
     * @param   bool                                          $required              Whether a value must be supplied.
     * @param   bool                                          $nullable              Whether null is an accepted value.
     * @param   mixed                                         $default               Value used when none is supplied.
     * @param   ?int                                          $length                Bounded character length, or null.
     * @param   ?int                                          $precision             Total digits of an exact numeric.
     * @param   ?int                                          $scale                 Fractional digits of the numeric.
     * @param   array<string, scalar|list<scalar|null>|null>  $configuration         Type-specific settings by key.
     * @param   list<string>                                  $normalizers           Normalizer identifiers, in order.
     * @param   list<array<string, mixed>>                    $validators            Validation rules to apply.
     * @param   bool                                          $unique                Whether values must be unique.
     * @param   bool                                          $indexed               Whether storage carries an index.
     * @param   bool                                          $immutableAfterCreate  Whether updates may not change it.
     * @param   bool                                          $serverOnly            Whether no caller may supply it.
     * @param   bool                                          $computed              Whether the server derives it.
     * @param   bool                                          $readOnly              Whether callers may not write it.
     * @param   bool                                          $createVisible         Whether create surfaces expose it.
     * @param   bool                                          $updateVisible         Whether update surfaces expose it.
     * @param   bool                                          $readVisible           Whether reads may return the value.
     * @param   bool                                          $searchable            Whether search may target it.
     * @param   bool                                          $filterable            Whether queries may filter on it.
     * @param   bool                                          $sortable              Whether queries may sort on it.
     * @param   bool                                          $reportable            Whether aggregates may cover it.
     * @param   bool                                          $exportable            Whether exports may include it.
     * @param   Sensitivity                                   $sensitivity           Handling class for redaction.
     * @param   bool                                          $localized             Whether the value is translated.
     * @param   string                                        $helpText              Short hint rendered by the form.
     * @param   string                                        $formGroup             Form section the field sits in.
     * @param   int                                           $order                 Sort weight within that group.
     * @param   list<string>                                  $placements            Surfaces the field renders on.
     * @param   ?Expression                                   $visibilityCondition   Condition gating display.
     * @param   ?Expression                                   $editabilityCondition  Condition gating edits.
     * @param   ?Expression                                   $formula               Expression deriving the value.
     * @param   ComputationMode                               $computationMode       Whether the result is stored.
     * @param   array<string, mixed>                          $textTranslations      Translations of `label`,
     *          `description` and `help_text`, keyed by member then by locale tag; empty for a field whose
     *          wording is declared in one language only.
     *
     * @throws  InvalidBusinessDefinition  When an identifier, label, or numeric bound is malformed, a
     *          combination of flags contradicts itself, the default or the configuration is not
     *          canonically serializable, a computed field is missing the formula and the server-only and
     *          read-only rules it needs, the normalizer or validator lists are over length or repeat an
     *          entry, the placements are empty or name a surface that does not exist, or a translation
     *          names an untranslatable member, a malformed locale or text over its member's bound.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $handle,
        public string $label,
        public string $type,
        public string $description = '',
        public bool $required = false,
        public bool $nullable = true,
        public mixed $default = null,
        public ?int $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
        array $configuration = [],
        array $normalizers = [],
        array $validators = [],
        public bool $unique = false,
        public bool $indexed = false,
        public bool $immutableAfterCreate = false,
        public bool $serverOnly = false,
        public bool $computed = false,
        public bool $readOnly = false,
        public bool $createVisible = true,
        public bool $updateVisible = true,
        public bool $readVisible = true,
        public bool $searchable = false,
        public bool $filterable = false,
        public bool $sortable = false,
        public bool $reportable = false,
        public bool $exportable = false,
        public Sensitivity $sensitivity = Sensitivity::Internal,
        public bool $localized = false,
        public string $helpText = '',
        public string $formGroup = 'general',
        public int $order = 0,
        array $placements = ['form', 'detail'],
        public ?Expression $visibilityCondition = null,
        public ?Expression $editabilityCondition = null,
        public ?Expression $formula = null,
        public ComputationMode $computationMode = ComputationMode::Virtual,
        array $textTranslations = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $handle) !== 1) {
            throw new InvalidBusinessDefinition('A business field handle is invalid.');
        }
        if ($label === '' || strlen($label) > 120 || strlen($description) > 1000 || strlen($helpText) > 1000) {
            throw new InvalidBusinessDefinition('A business field has invalid human-readable metadata.');
        }
        if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)+$/D', $type) !== 1) {
            throw new InvalidBusinessDefinition('A business field type identifier is invalid.');
        }
        if ($required && $nullable) {
            throw new InvalidBusinessDefinition('A required business field cannot also be nullable.');
        }
        if ($default !== null) {
            CanonicalDefinitionJson::encode($default);
        }
        if ($length !== null && ($length < 1 || $length > 1_000_000)) {
            throw new InvalidBusinessDefinition('A business field length is outside the supported bounds.');
        }
        if (($precision === null) !== ($scale === null)) {
            throw new InvalidBusinessDefinition('Business field precision and scale must be declared together.');
        }
        if (
            $precision !== null && $scale !== null
            && ($precision < 1 || $precision > 65 || $scale < 0 || $scale > 30 || $scale > $precision)
        ) {
            throw new InvalidBusinessDefinition(
                'A business field precision or scale exceeds the portable DECIMAL(65, 30) bounds.',
            );
        }
        if (in_array($type, ['core.decimal', 'core.money', 'core.quantity'], true) && $precision === null) {
            throw new InvalidBusinessDefinition('Exact numeric fields require explicit precision and scale.');
        }
        if ($configuration !== [] && array_is_list($configuration)) {
            throw new InvalidBusinessDefinition('Business field configuration must be an object.');
        }
        foreach ($configuration as $key => $value) {
            if (!is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $key) !== 1) {
                throw new InvalidBusinessDefinition('A business field configuration key is invalid.');
            }
            if (is_array($value)) {
                if (!array_is_list($value) || count($value) > 256) {
                    throw new InvalidBusinessDefinition('A business field configuration list is invalid.');
                }
                foreach ($value as $item) {
                    if ((!is_scalar($item) && $item !== null) || is_float($item)) {
                        throw new InvalidBusinessDefinition('A business field configuration value is invalid.');
                    }
                }
                continue;
            }
            if ((!is_scalar($value) && $value !== null) || is_float($value)) {
                throw new InvalidBusinessDefinition('A business field configuration value is invalid.');
            }
        }
        CanonicalDefinitionJson::encode($configuration);
        ksort($configuration, SORT_STRING);
        $this->configuration = $configuration;
        if ($computed && (!$readOnly || !$serverOnly || $formula === null)) {
            throw new InvalidBusinessDefinition('A computed field must be server-only, read-only, and have a formula.');
        }
        if (!$computed && $computationMode !== ComputationMode::Virtual) {
            throw new InvalidBusinessDefinition('Only a computed field can use stored computation.');
        }
        if (
            $computationMode === ComputationMode::Stored
            && ($formula === null || !in_array(
                $formula->type,
                ['boolean', 'integer', 'decimal', 'string', 'date', 'time', 'datetime'],
                true,
            ))
        ) {
            throw new InvalidBusinessDefinition('A stored computation requires a portable scalar result type.');
        }
        if (
            $computationMode === ComputationMode::Stored && $formula?->type === 'decimal'
            && ($precision === null || $scale === null)
        ) {
            throw new InvalidBusinessDefinition('A stored decimal computation requires precision and scale.');
        }
        if ($type === 'core.secret' && ($searchable || $filterable || $sortable || $reportable || $exportable)) {
            throw new InvalidBusinessDefinition(
                'A secret field cannot be searched, filtered, sorted, reported, or exported.',
            );
        }
        if ($type === 'core.secret' && $sensitivity !== Sensitivity::Secret) {
            throw new InvalidBusinessDefinition('An encrypted secret field requires secret sensitivity.');
        }
        if (
            $visibilityCondition !== null
            && ($searchable || $filterable || $sortable || $reportable || $exportable)
        ) {
            throw new InvalidBusinessDefinition(
                'A conditionally visible field cannot be queried, reported, or exported.',
            );
        }
        if (preg_match('/^[a-z][a-z0-9_-]{0,62}$/D', $formGroup) !== 1 || $order < 0 || $order > 100_000) {
            throw new InvalidBusinessDefinition('A business field form placement is invalid.');
        }
        $this->normalizers = self::identifiers($normalizers, 'normalizer', 32);
        if (count($validators) > 32) {
            throw new InvalidBusinessDefinition('A business field has too many validators.');
        }
        foreach ($validators as $validator) {
            CanonicalDefinitionJson::encode($validator);
        }
        $this->validators = $validators;
        $allowedPlacements = ['list', 'detail', 'form', 'history', 'relation'];
        $placements = array_values(array_unique($placements));
        if ($placements === [] || array_diff($placements, $allowedPlacements) !== []) {
            throw new InvalidBusinessDefinition('A business field placement is invalid.');
        }
        sort($placements, SORT_STRING);
        $this->placements = $placements;
        $this->textTranslations = LocalizedDefinitionText::normalize($textTranslations, [
            'label' => 120,
            'description' => 1000,
            'help_text' => 1000,
        ]);
    }

    /**
     * Rebuild a field from the canonical document `toArray()` writes.
     *
     * The keys are the snake_case ones a published definition stores, and an unknown key is refused
     * rather than ignored, so a definition exported by a later release is never imported with part of
     * its meaning quietly dropped.
     *
     * @param   array<string, mixed>  $document  Canonical field document, keyed as it is stored.
     *
     * @return  self  The field, with every construction rule already applied.
     *
     * @throws  InvalidBusinessDefinition  When a key is unknown, a member has the wrong type, the
     *          sensitivity or computation mode is unrecognised, or the resulting field breaks a
     *          construction rule.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $document): self
    {
        $allowed = [
            'handle', 'label', 'type', 'description', 'required', 'nullable', 'default', 'length', 'precision',
            'scale', 'configuration', 'normalizers', 'validators', 'unique', 'indexed',
            'immutable_after_create', 'server_only',
            'computed', 'read_only', 'create_visible', 'update_visible', 'read_visible', 'searchable', 'filterable',
            'sortable', 'reportable', 'exportable', 'sensitivity', 'localized', 'help_text', 'form_group', 'order',
            'placements', 'visibility_condition', 'editability_condition', 'formula', 'computation_mode',
            'text_translations',
        ];
        if (array_diff(array_keys($document), $allowed) !== []) {
            throw new InvalidBusinessDefinition('A business field contains an unknown property.');
        }
        $translations = $document['text_translations'] ?? [];
        if (!is_array($translations) || ($translations !== [] && array_is_list($translations))) {
            throw new InvalidBusinessDefinition('Business field text translations must be an object.');
        }
        /** @var array<string, mixed> $translations */

        return new self(
            self::string($document, 'handle'),
            self::string($document, 'label'),
            self::string($document, 'type'),
            self::optionalString($document, 'description'),
            self::boolean($document, 'required'),
            self::boolean($document, 'nullable', true),
            $document['default'] ?? null,
            self::optionalInteger($document, 'length'),
            self::optionalInteger($document, 'precision'),
            self::optionalInteger($document, 'scale'),
            self::configuration($document),
            self::stringList($document, 'normalizers'),
            self::objectList($document, 'validators'),
            self::boolean($document, 'unique'),
            self::boolean($document, 'indexed'),
            self::boolean($document, 'immutable_after_create'),
            self::boolean($document, 'server_only'),
            self::boolean($document, 'computed'),
            self::boolean($document, 'read_only'),
            self::boolean($document, 'create_visible', true),
            self::boolean($document, 'update_visible', true),
            self::boolean($document, 'read_visible', true),
            self::boolean($document, 'searchable'),
            self::boolean($document, 'filterable'),
            self::boolean($document, 'sortable'),
            self::boolean($document, 'reportable'),
            self::boolean($document, 'exportable'),
            Sensitivity::tryFrom(self::optionalString($document, 'sensitivity', 'internal'))
                ?? throw new InvalidBusinessDefinition('A business field sensitivity is invalid.'),
            self::boolean($document, 'localized'),
            self::optionalString($document, 'help_text'),
            self::optionalString($document, 'form_group', 'general'),
            self::integer($document, 'order'),
            self::stringList($document, 'placements', ['form', 'detail']),
            self::expression($document, 'visibility_condition'),
            self::expression($document, 'editability_condition'),
            self::expression($document, 'formula'),
            ComputationMode::tryFrom(self::optionalString($document, 'computation_mode', 'virtual'))
                ?? throw new InvalidBusinessDefinition('A business field computation mode is invalid.'),
            $translations,
        );
    }

    /**
     * Read the field's name in the locale an operator is working in.
     *
     * @param   LocaleTag|string  $locale  Locale the surface is rendering in.
     *
     * @return  string  The closest translation the field carries, otherwise the declared label.
     *
     * @throws  \Kumwe\Localization\Domain\InvalidLocaleTag  When the locale is a malformed tag.
     *
     * @since   2.0.0
     */
    public function labelIn(LocaleTag|string $locale): string
    {
        return LocalizedDefinitionText::resolve($this->textTranslations, 'label', $this->label, $locale);
    }

    /**
     * Read the field's editor-facing note in the locale an operator is working in.
     *
     * @param   LocaleTag|string  $locale  Locale the surface is rendering in.
     *
     * @return  string  The closest translation the field carries, otherwise the declared description,
     *          which is the empty string when the field declares none.
     *
     * @throws  \Kumwe\Localization\Domain\InvalidLocaleTag  When the locale is a malformed tag.
     *
     * @since   2.0.0
     */
    public function descriptionIn(LocaleTag|string $locale): string
    {
        return LocalizedDefinitionText::resolve($this->textTranslations, 'description', $this->description, $locale);
    }

    /**
     * Read the field's form hint in the locale an operator is working in.
     *
     * @param   LocaleTag|string  $locale  Locale the surface is rendering in.
     *
     * @return  string  The closest translation the field carries, otherwise the declared help text, which
     *          is the empty string when the field declares none.
     *
     * @throws  \Kumwe\Localization\Domain\InvalidLocaleTag  When the locale is a malformed tag.
     *
     * @since   2.0.0
     */
    public function helpTextIn(LocaleTag|string $locale): string
    {
        return LocalizedDefinitionText::resolve($this->textTranslations, 'help_text', $this->helpText, $locale);
    }

    /**
     * Export the field as the canonical document the definition checksum is taken over.
     *
     * `computation_mode` is written only for a stored computation, because a definition published
     * before that key existed described a virtual one implicitly and must keep its original bytes.
     * `text_translations` follows the same rule for the same reason: a field whose wording is declared
     * in one language writes nothing, so its bytes are the bytes its checksum was taken over.
     *
     * @return  array<string, mixed>  Every declared property under its snake_case key, with enums and
     *          expressions flattened to their own document form.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        $document = [
            'handle' => $this->handle,
            'label' => $this->label,
            'type' => $this->type,
            'description' => $this->description,
            'required' => $this->required,
            'nullable' => $this->nullable,
            'default' => $this->default,
            'length' => $this->length,
            'precision' => $this->precision,
            'scale' => $this->scale,
            'configuration' => $this->configuration,
            'normalizers' => $this->normalizers,
            'validators' => $this->validators,
            'unique' => $this->unique,
            'indexed' => $this->indexed,
            'immutable_after_create' => $this->immutableAfterCreate,
            'server_only' => $this->serverOnly,
            'computed' => $this->computed,
            'read_only' => $this->readOnly,
            'create_visible' => $this->createVisible,
            'update_visible' => $this->updateVisible,
            'read_visible' => $this->readVisible,
            'searchable' => $this->searchable,
            'filterable' => $this->filterable,
            'sortable' => $this->sortable,
            'reportable' => $this->reportable,
            'exportable' => $this->exportable,
            'sensitivity' => $this->sensitivity->value,
            'localized' => $this->localized,
            'help_text' => $this->helpText,
            'form_group' => $this->formGroup,
            'order' => $this->order,
            'placements' => $this->placements,
            'visibility_condition' => $this->visibilityCondition?->toArray(),
            'editability_condition' => $this->editabilityCondition?->toArray(),
            'formula' => $this->formula?->toArray(),
        ];
        // Session-2 definitions implicitly described virtual calculations. Keep
        // those immutable bytes unchanged unless stored computation is explicit.
        if ($this->computationMode === ComputationMode::Stored) {
            $document['computation_mode'] = $this->computationMode->value;
        }
        if ($this->textTranslations !== []) {
            $document['text_translations'] = $this->textTranslations;
        }

        return $document;
    }

    /**
     * Read a mandatory string property, trimmed.
     *
     * @param   array<string, mixed>  $document  Document the property is read from.
     * @param   string                $key       Property name, which is also named in the failure.
     *
     * @return  string  The value with surrounding whitespace removed.
     *
     * @throws  InvalidBusinessDefinition  When the property is absent, not a string, or blank.
     *
     * @since   2.0.0
     */
    private static function string(array $document, string $key): string
    {
        $value = $document[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidBusinessDefinition('Business field property ' . $key . ' is required.');
        }
        return trim($value);
    }

    /**
     * Read a string property that falls back to a default when the document omits it.
     *
     * @param   array<string, mixed>  $document  Document the property is read from.
     * @param   string                $key       Property name, which is also named in the failure.
     * @param   string                $default   Value substituted when the key is absent.
     *
     * @return  string  The value with surrounding whitespace removed.
     *
     * @throws  InvalidBusinessDefinition  When the property is present but not a string.
     *
     * @since   2.0.0
     */
    private static function optionalString(array $document, string $key, string $default = ''): string
    {
        $value = $document[$key] ?? $default;
        if (!is_string($value)) {
            throw new InvalidBusinessDefinition('Business field property ' . $key . ' must be a string.');
        }
        return trim($value);
    }

    /**
     * Read a flag, falling back to the declared default when the document omits it.
     *
     * @param   array<string, mixed>  $document  Document the property is read from.
     * @param   string                $key       Property name, which is also named in the failure.
     * @param   bool                  $default   Value substituted when the key is absent.
     *
     * @return  bool  The declared flag, or the default.
     *
     * @throws  InvalidBusinessDefinition  When the property is present but not a boolean.
     *
     * @since   2.0.0
     */
    private static function boolean(array $document, string $key, bool $default = false): bool
    {
        $value = $document[$key] ?? $default;
        if (!is_bool($value)) {
            throw new InvalidBusinessDefinition('Business field property ' . $key . ' must be boolean.');
        }
        return $value;
    }

    /**
     * Read an integer property, treating an absent one as zero.
     *
     * @param   array<string, mixed>  $document  Document the property is read from.
     * @param   string                $key       Property name, which is also named in the failure.
     *
     * @return  int  The declared value, or zero when the key is absent.
     *
     * @throws  InvalidBusinessDefinition  When the property is present but not an integer.
     *
     * @since   2.0.0
     */
    private static function integer(array $document, string $key): int
    {
        $value = $document[$key] ?? 0;
        if (!is_int($value)) {
            throw new InvalidBusinessDefinition('Business field property ' . $key . ' must be an integer.');
        }
        return $value;
    }

    /**
     * Read an integer property that may legitimately be undeclared.
     *
     * @param   array<string, mixed>  $document  Document the property is read from.
     * @param   string                $key       Property name, which is also named in the failure.
     *
     * @return  ?int  The declared bound, or null when the field leaves it open.
     *
     * @throws  InvalidBusinessDefinition  When the property is present but neither an integer nor null.
     *
     * @since   2.0.0
     */
    private static function optionalInteger(array $document, string $key): ?int
    {
        $value = $document[$key] ?? null;
        if ($value !== null && !is_int($value)) {
            throw new InvalidBusinessDefinition('Business field property ' . $key . ' must be an integer or null.');
        }
        return $value;
    }

    /**
     * Read the type-specific configuration object.
     *
     * Only the container's shape is settled here — a JSON list is refused because configuration is
     * keyed — while the constructor is what checks each key and value.
     *
     * @param   array<string, mixed>  $document  Document the configuration is read from.
     *
     * @return  array<string, scalar|list<scalar|null>|null>  The declared settings, empty when the field
     *          configures nothing.
     *
     * @throws  InvalidBusinessDefinition  When the configuration is present but not an object.
     *
     * @since   2.0.0
     */
    private static function configuration(array $document): array
    {
        $value = $document['configuration'] ?? [];
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidBusinessDefinition('Business field configuration must be an object.');
        }
        /** @var array<string, scalar|list<scalar|null>|null> $value */
        return $value;
    }

    /**
     * Read a list of strings, such as the normalizer or placement names.
     *
     * @param   array<string, mixed>  $document  Document the property is read from.
     * @param   string                $key       Property name, which is also named in the failure.
     * @param   list<string>          $default   List substituted when the key is absent.
     *
     * @return  list<string>  The declared entries in document order, unvalidated beyond their type.
     *
     * @throws  InvalidBusinessDefinition  When the property is not a list, or holds a non-string entry.
     *
     * @since   2.0.0
     */
    private static function stringList(array $document, string $key, array $default = []): array
    {
        $value = $document[$key] ?? $default;
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidBusinessDefinition('Business field property ' . $key . ' must be a list.');
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new InvalidBusinessDefinition('Business field property ' . $key . ' must contain strings.');
            }
            $result[] = $item;
        }
        return $result;
    }

    /**
     * Read a list of objects, which is the shape the validator rules arrive in.
     *
     * @param   array<string, mixed>  $document  Document the property is read from.
     * @param   string                $key       Property name, which is also named in the failure.
     *
     * @return  list<array<string, mixed>>  The declared entries in document order, empty when absent.
     *
     * @throws  InvalidBusinessDefinition  When the property is not a list, or holds an entry that is not
     *          a keyed object.
     *
     * @since   2.0.0
     */
    private static function objectList(array $document, string $key): array
    {
        $value = $document[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidBusinessDefinition('Business field property ' . $key . ' must be a list.');
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw new InvalidBusinessDefinition('Business field property ' . $key . ' must contain objects.');
            }
            /** @var array<string, mixed> $item */
            $result[] = $item;
        }
        return $result;
    }

    /**
     * Parse an optional condition or formula into its typed expression tree.
     *
     * @param   array<string, mixed>  $document  Document the expression is read from.
     * @param   string                $key       Property name, which is also named in the failure.
     *
     * @return  ?Expression  The parsed expression, or null when the field declares none.
     *
     * @throws  InvalidBusinessDefinition  When the property is not an object, or the expression exceeds
     *          the parser's size, depth, arity, or type limits.
     *
     * @since   2.0.0
     */
    private static function expression(array $document, string $key): ?Expression
    {
        $value = $document[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidBusinessDefinition('Business field expression ' . $key . ' must be an object.');
        }
        /** @var array<string, mixed> $value */
        return Expression::fromArray($value);
    }

    /**
     * Check a bounded list of identifiers for duplicates, length, and shape.
     *
     * @param   list<string>  $values   Declared identifiers, in the order they are applied.
     * @param   string        $kind     Word naming the list in the failure message, such as `normalizer`.
     * @param   int           $maximum  Largest number of entries this list may carry.
     *
     * @return  list<string>  The values unchanged, order preserved, once every one of them is valid.
     *
     * @throws  InvalidBusinessDefinition  When the list is over length, repeats an entry, or holds one
     *          that is not a bounded lowercase identifier.
     *
     * @since   2.0.0
     */
    private static function identifiers(array $values, string $kind, int $maximum): array
    {
        if (count($values) > $maximum || count($values) !== count(array_unique($values))) {
            throw new InvalidBusinessDefinition('Business field ' . $kind . ' entries are duplicated or unbounded.');
        }
        foreach ($values as $value) {
            if (preg_match('/^[a-z][a-z0-9._-]{0,62}$/D', $value) !== 1) {
                throw new InvalidBusinessDefinition('A business field ' . $kind . ' identifier is invalid.');
            }
        }
        return $values;
    }
}
