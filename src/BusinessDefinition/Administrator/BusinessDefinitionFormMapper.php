<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Administrator;

use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\App\BusinessDefinition\Domain\DefinitionStatus;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\Context\Value\SiteContext;
use Ramsey\Uuid\Uuid;

/**
 * Turns the administrator definition editor's flat form post into a strict `EntityTypeDefinition` draft.
 *
 * The editor offers only bounded controls — text boxes, checkboxes, selects and indexed row sets — and this
 * mapper is what makes that boundary meaningful: formulas and conditions are assembled here from picked field
 * names, operators and typed literals, so nothing an operator types ever reaches a stored definition as an
 * expression. Anything the graphical controls cannot draw — an extension field type's configuration, extra
 * validators, a hand-authored expression — travels in hidden `*_preserved` JSON inputs and is merged back, so
 * editing a definition through the screen never silently drops the parts it cannot render.
 *
 * Only shape is checked here: required inputs, integer syntax and well-formed preserved JSON. Every
 * cross-field rule belongs to `EntityTypeDefinition::fromArray()`, which the assembled document is put through
 * before it is returned, so a form that maps cleanly can still be refused as a definition.
 *
 * @since  2.0.0
 */
final readonly class BusinessDefinitionFormMapper
{
    /**
     * Assemble a posted editor form into a draft definition owned by the current site.
     *
     * Field rows are read from a bounded index range and a row whose handle is blank is skipped rather than
     * ending the scan, so the editor may post a sparse set after a row is deleted. A field whose type matches
     * the chosen identity strategy is treated as the identity field and is forced unique, indexed and
     * immutable after create whatever its own checkboxes say; a computed field is likewise forced server-only
     * and read-only and hidden from the create and update forms.
     *
     * The result is always a draft at version zero with relational storage — publication, versioning and
     * authorization belong to `BusinessDefinitionService`. Auditing and revisions are on unless the operator
     * switches them off, and exposure beyond the administrator is off unless switched on.
     *
     * @param   array<string, string>  $form  Flattened administrator form, keyed by input name.
     * @param   SiteContext            $site  Site that owns the definition and namespaces its handles.
     *
     * @return  EntityTypeDefinition  The draft; a blank `id` input mints a new UUIDv7 rather than updating.
     *
     * @throws  InvalidArgumentException  When a required input is blank, a number is not an integer, a
     *          preserved JSON input is unusable, or no field row was filled in at all.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When the assembled document
     *          breaks a construction rule, such as an exact numeric field left without a precision.
     *
     * @since   2.0.0
     */
    public function definition(array $form, SiteContext $site): EntityTypeDefinition
    {
        $fields = [];
        for ($index = 0; $index < 256; ++$index) {
            $handle = $this->value($form, "field_{$index}_handle");
            if ($handle === '') {
                continue;
            }
            $type = $this->required($form, "field_{$index}_type");
            $required = $this->checked($form, "field_{$index}_required");
            $computed = $this->checked($form, "field_{$index}_computed");
            $identity = ($this->value($form, 'identity_strategy', 'uuid') === 'uuid' && $type === 'core.uuid')
                || (
                    $this->value($form, 'identity_strategy', 'uuid') === 'reference'
                    && $type === 'core.reference_identity'
                );
            $precision = $this->integerOrNull($form, "field_{$index}_precision");
            $scale = $this->integerOrNull($form, "field_{$index}_scale");
            $fields[] = [
                'handle' => $handle,
                'label' => $this->required($form, "field_{$index}_label"),
                'type' => $type,
                'description' => $this->value($form, "field_{$index}_description"),
                'required' => $required,
                'nullable' => !$required,
                'default' => $this->defaultValue($form, $index, $type),
                'length' => $this->integerOrNull($form, "field_{$index}_length"),
                'precision' => $precision,
                'scale' => $scale,
                'configuration' => $this->configuration($form, $index),
                'normalizers' => $this->list($form, "field_{$index}_normalizers"),
                'validators' => $this->validators($form, $index),
                'unique' => $identity || $this->checked($form, "field_{$index}_unique"),
                'indexed' => $identity || $this->checked($form, "field_{$index}_indexed"),
                'immutable_after_create' => $identity || $this->checked($form, "field_{$index}_immutable"),
                'server_only' => $computed || $this->checked($form, "field_{$index}_server_only"),
                'computed' => $computed,
                'computation_mode' => $computed && $this->checked($form, "field_{$index}_stored")
                    ? 'stored'
                    : 'virtual',
                'read_only' => $computed || $this->checked($form, "field_{$index}_read_only"),
                'create_visible' => !$computed && !$this->checked($form, "field_{$index}_hide_create"),
                'update_visible' => !$computed && !$this->checked($form, "field_{$index}_hide_update"),
                'read_visible' => !$this->checked($form, "field_{$index}_hide_read"),
                'searchable' => $this->checked($form, "field_{$index}_searchable"),
                'filterable' => $this->checked($form, "field_{$index}_filterable"),
                'sortable' => $this->checked($form, "field_{$index}_sortable"),
                'reportable' => $this->checked($form, "field_{$index}_reportable"),
                'exportable' => $this->checked($form, "field_{$index}_exportable"),
                'sensitivity' => $this->value($form, "field_{$index}_sensitivity", 'internal'),
                'localized' => $this->checked($form, "field_{$index}_localized"),
                'help_text' => $this->value($form, "field_{$index}_help"),
                'form_group' => $this->value($form, "field_{$index}_group", 'general'),
                'order' => $this->integer($form, "field_{$index}_order", $index * 10),
                'placements' => $this->list($form, "field_{$index}_placements", ['form', 'detail']),
                'visibility_condition' => $this->condition($form, "field_{$index}_visibility"),
                'editability_condition' => $this->condition($form, "field_{$index}_editability"),
                'formula' => $computed ? $this->formula($form, $index) : null,
            ];
        }
        if ($fields === []) {
            throw new InvalidArgumentException('Add at least one field with an identity field before saving.');
        }

        $id = $this->value($form, 'id');
        return EntityTypeDefinition::fromArray([
            'id' => $id === '' ? Uuid::uuid7()->toString() : $id,
            'owner' => DefinitionOwner::site($site->identifier())->toArray(),
            'site' => $site->identifier(),
            'handle' => $this->required($form, 'handle'),
            'singular_label' => $this->required($form, 'singular_label'),
            'plural_label' => $this->required($form, 'plural_label'),
            'status' => DefinitionStatus::Draft->value,
            'definition_version' => 0,
            'storage_mode' => 'relational',
            'identity_strategy' => $this->value($form, 'identity_strategy', 'uuid'),
            'scope' => $this->value($form, 'scope', 'site'),
            'audit_enabled' => !$this->checked($form, 'audit_disabled'),
            'revisions_enabled' => !$this->checked($form, 'revisions_disabled'),
            'soft_delete_enabled' => $this->checked($form, 'soft_delete_enabled'),
            'fields' => $fields,
            'relationships' => $this->relationships($form),
            'views' => $this->views($form),
            'actions' => $this->actions($form),
            'workflow' => $this->workflow($form),
            'compatibility_metadata' => [],
            'administrator_exposure' => !$this->checked($form, 'administrator_hidden'),
            'portal_exposure' => $this->checked($form, 'portal_exposure'),
            'public_exposure' => $this->checked($form, 'public_exposure'),
        ]);
    }

    /**
     * Collect the relationship rows the editor posted into definition documents.
     *
     * Up to 128 indexed rows are scanned and a row with a blank handle is skipped, so deleting a row in the
     * screen may leave a gap in the numbering without hiding the rows after it.
     *
     * @param   array<string, string>  $form  Flattened administrator form, keyed by input name.
     *
     * @return  list<array<string, mixed>>  One document per filled row, in index order; a blank inverse handle
     *          becomes null, meaning the relationship is not navigable from the target.
     *
     * @throws  InvalidArgumentException  When a filled row leaves its label, kind or target blank.
     *
     * @since   2.0.0
     */
    private function relationships(array $form): array
    {
        $result = [];
        for ($index = 0; $index < 128; ++$index) {
            if (($handle = $this->value($form, "relationship_{$index}_handle")) === '') {
                continue;
            }
            $inverse = $this->value($form, "relationship_{$index}_inverse");
            $result[] = [
                'handle' => $handle,
                'label' => $this->required($form, "relationship_{$index}_label"),
                'kind' => $this->required($form, "relationship_{$index}_kind"),
                'target' => $this->required($form, "relationship_{$index}_target"),
                'inverse' => $inverse === '' ? null : $inverse,
                'required' => $this->checked($form, "relationship_{$index}_required"),
                'unique' => $this->checked($form, "relationship_{$index}_unique"),
                'ordered' => $this->checked($form, "relationship_{$index}_ordered"),
                'on_delete' => $this->value($form, "relationship_{$index}_delete", 'restrict'),
            ];
        }
        return $result;
    }

    /**
     * Collect the view rows the editor posted into definition documents.
     *
     * Up to 64 indexed rows are scanned and a row with a blank handle is skipped. Each view is exposed to the
     * administrator unless its hide box is ticked, while portal and public exposure are opt-in.
     *
     * @param   array<string, string>  $form  Flattened administrator form, keyed by input name.
     *
     * @return  list<array<string, mixed>>  One document per filled row, in index order; its field, filter and
     *          sort entries are split out of the comma or newline separated text the operator typed.
     *
     * @throws  InvalidArgumentException  When a filled row leaves its label or kind blank.
     *
     * @since   2.0.0
     */
    private function views(array $form): array
    {
        $result = [];
        for ($index = 0; $index < 64; ++$index) {
            if (($handle = $this->value($form, "view_{$index}_handle")) === '') {
                continue;
            }
            $result[] = [
                'handle' => $handle,
                'label' => $this->required($form, "view_{$index}_label"),
                'kind' => $this->required($form, "view_{$index}_kind"),
                'fields' => $this->list($form, "view_{$index}_fields"),
                'filters' => $this->list($form, "view_{$index}_filters"),
                'sorts' => $this->list($form, "view_{$index}_sorts"),
                'administrator' => !$this->checked($form, "view_{$index}_administrator_hidden"),
                'portal' => $this->checked($form, "view_{$index}_portal"),
                'public' => $this->checked($form, "view_{$index}_public"),
            ];
        }
        return $result;
    }

    /**
     * Collect the action rows the editor posted into definition documents.
     *
     * Up to 64 indexed rows are scanned and a row with a blank handle is skipped. An action authored through
     * this screen is always administrator-facing and never public; only portal exposure is left to the
     * operator, so a screen-authored action can never be reached anonymously.
     *
     * @param   array<string, string>  $form  Flattened administrator form, keyed by input name.
     *
     * @return  list<array<string, mixed>>  One document per filled row, in index order; a blank transition
     *          becomes null, meaning the action moves the record through no workflow state.
     *
     * @throws  InvalidArgumentException  When a filled row leaves its label or capability blank, or its
     *          condition inputs cannot be read.
     *
     * @since   2.0.0
     */
    private function actions(array $form): array
    {
        $result = [];
        for ($index = 0; $index < 64; ++$index) {
            if (($handle = $this->value($form, "action_{$index}_handle")) === '') {
                continue;
            }
            $transition = $this->value($form, "action_{$index}_transition");
            $result[] = [
                'handle' => $handle,
                'label' => $this->required($form, "action_{$index}_label"),
                'capability' => $this->required($form, "action_{$index}_capability"),
                'bulk' => $this->checked($form, "action_{$index}_bulk"),
                'administrator' => true,
                'portal' => $this->checked($form, "action_{$index}_portal"),
                'public' => false,
                'high_impact' => $this->checked($form, "action_{$index}_high_impact"),
                'condition' => $this->condition($form, "action_{$index}_condition"),
                'transition' => $transition === '' ? null : $transition,
            ];
        }
        return $result;
    }

    /**
     * Assemble the workflow document, or nothing at all when the editor left workflow switched off.
     *
     * The enable box is read first, so a form still carrying transition rows from a workflow the operator has
     * just turned off contributes none of them.
     *
     * @param   array<string, string>  $form  Flattened administrator form, keyed by input name.
     *
     * @return  array<string, mixed>|null  The initial state, the declared states and up to 128 transitions, or
     *          null when `workflow_enabled` is unticked and the definition carries no workflow.
     *
     * @throws  InvalidArgumentException  When workflow is enabled but the initial state is blank, or a filled
     *          transition row leaves its from, to or capability blank.
     *
     * @since   2.0.0
     */
    private function workflow(array $form): ?array
    {
        if (!$this->checked($form, 'workflow_enabled')) {
            return null;
        }
        $transitions = [];
        for ($index = 0; $index < 128; ++$index) {
            if (($handle = $this->value($form, "transition_{$index}_handle")) === '') {
                continue;
            }
            $transitions[] = [
                'handle' => $handle,
                'from' => $this->required($form, "transition_{$index}_from"),
                'to' => $this->required($form, "transition_{$index}_to"),
                'capability' => $this->required($form, "transition_{$index}_capability"),
            ];
        }
        return [
            'initial_state' => $this->required($form, 'workflow_initial_state'),
            'states' => $this->list($form, 'workflow_states'),
            'transitions' => $transitions,
        ];
    }

    /**
     * Build the expression document a computed field is evaluated from, out of its picked operands.
     *
     * Both operands are field handles chosen from selects, so the result is a closed tree of `field` and
     * operator nodes and never text the operator composed. Leaving the left operand unpicked falls back to the
     * expression preserved from the last save, which is the only way a formula richer than the two-operand
     * form survives a round trip through the screen. Dividing decimals additionally carries the result scale,
     * because the quotient's scale cannot be inferred from its operands.
     *
     * @param   array<string, string>  $form   Flattened administrator form, keyed by input name.
     * @param   int                    $index  Zero-based row number of the field being mapped.
     *
     * @return  array<string, mixed>  A bare `field` node when no operator was picked, otherwise the operator
     *          node over both operands.
     *
     * @throws  InvalidArgumentException  When no operand was picked and no preserved expression remains, when
     *          the right-hand field is blank, or when the division scale is not an integer.
     *
     * @since   2.0.0
     */
    private function formula(array $form, int $index): array
    {
        $type = $this->value($form, "field_{$index}_formula_type", 'string');
        $leftField = $this->value($form, "field_{$index}_formula_left");
        if ($leftField === '') {
            return $this->preservedExpression($form, "field_{$index}_formula")
                ?? throw new InvalidArgumentException('A computed field requires a safe formula.');
        }
        $left = ['op' => 'field', 'type' => $type, 'field' => $leftField];
        $operator = $this->value($form, "field_{$index}_formula_operator", 'field');
        if ($operator === 'field') {
            return $left;
        }
        $document = [
            'op' => $operator,
            'type' => $type,
            'args' => [$left, [
                'op' => 'field',
                'type' => $type,
                'field' => $this->required($form, "field_{$index}_formula_right"),
            ]],
        ];
        if ($operator === 'divide' && $type === 'decimal') {
            $document['scale'] = $this->integer($form, "field_{$index}_formula_scale", 2);
        }
        return $document;
    }

    /**
     * Build the boolean document a visibility, editability or action gate is expressed as.
     *
     * The comparison is assembled from a picked field, a picked operator and a literal coerced to the picked
     * type, so a gate can never smuggle an expression in as text. An `is_null` test carries the field operand
     * alone; every other operator carries the literal as a second operand.
     *
     * @param   array<string, string>  $form    Flattened administrator form, keyed by input name.
     * @param   string                 $prefix  Input-name prefix the condition's own controls share.
     *
     * @return  array<string, mixed>|null  The boolean node, or null when neither a field nor a preserved
     *          expression was posted, which the definition reads as an unconditional gate.
     *
     * @throws  InvalidArgumentException  When the preserved expression cannot be read, or the literal does not
     *          match the type picked beside it.
     *
     * @since   2.0.0
     */
    private function condition(array $form, string $prefix): ?array
    {
        $field = $this->value($form, $prefix . '_field');
        if ($field === '') {
            return $this->preservedExpression($form, $prefix);
        }
        $operator = $this->value($form, $prefix . '_operator', 'eq');
        $type = $this->value($form, $prefix . '_type', 'string');
        $arguments = [['op' => 'field', 'type' => $type, 'field' => $field]];
        if ($operator !== 'is_null') {
            $arguments[] = [
                'op' => 'literal',
                'type' => $type,
                'value' => $this->literal($this->value($form, $prefix . '_value'), $type),
            ];
        }
        return ['op' => $operator, 'type' => 'boolean', 'args' => $arguments];
    }

    /**
     * Read back an expression the graphical controls cannot draw, carried across saves in a hidden input.
     *
     * The screen renders the stored expression into `<prefix>_preserved` whenever it cannot offer controls for
     * it, so re-saving an untouched definition reproduces it rather than flattening it. Only the outer shape
     * is checked here; whether the nodes form a valid expression is decided during construction.
     *
     * @param   array<string, string>  $form    Flattened administrator form, keyed by input name.
     * @param   string                 $prefix  Input-name prefix; the value is read from `<prefix>_preserved`.
     *
     * @return  array<string, mixed>|null  The decoded expression object, or null when nothing was preserved.
     *
     * @throws  InvalidArgumentException  When the hidden input is not valid JSON within its depth limit, or
     *          does not decode to an object carrying at least one member.
     *
     * @since   2.0.0
     */
    private function preservedExpression(array $form, string $prefix): ?array
    {
        $json = $this->value($form, $prefix . '_preserved');
        if ($json === '') {
            return null;
        }
        try {
            $expression = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException('A preserved expression is invalid JSON.', 0, $exception);
        }
        if (!is_array($expression) || array_is_list($expression)) {
            throw new InvalidArgumentException('A preserved expression must be an object.');
        }
        /** @var array<string, mixed> $expression */
        return $expression;
    }

    /**
     * Coerce a condition's literal from the text posted for it into the type picked beside it.
     *
     * @param   string  $value  Trimmed text posted as the comparison value.
     * @param   string  $type   Literal type picked beside it: `null`, `boolean`, `integer`, or anything else
     *          for a plain string.
     *
     * @return  string|int|bool|null  The typed literal; null only when the `null` type was picked, never as a
     *          signal that the value was missing.
     *
     * @throws  InvalidArgumentException  When a boolean is spelled as anything but true, 1, false or 0, or an
     *          integer is not an optionally signed run of decimal digits.
     *
     * @since   2.0.0
     */
    private function literal(string $value, string $type): string|int|bool|null
    {
        return match ($type) {
            'null' => null,
            'boolean' => match ($value) {
                'true', '1' => true,
                'false', '0' => false,
                default => throw new InvalidArgumentException('A boolean condition value must be true or false.'),
            },
            'integer' => $this->validatedInteger($value, 'condition value'),
            default => $value,
        };
    }

    /**
     * Collect the validators for one field, preferring the rule picked in the editor.
     *
     * The screen offers a single rule per field, so picking one replaces the stored set outright. Picking none
     * restores the list preserved from the last save unchanged, which is how a field validated by rules the
     * screen cannot draw survives being edited for some unrelated reason.
     *
     * @param   array<string, string>  $form   Flattened administrator form, keyed by input name.
     * @param   int                    $index  Zero-based row number of the field being mapped.
     *
     * @return  list<array<string, mixed>>  Exactly one rule when the editor picked one, otherwise the
     *          preserved list, otherwise empty for a field with no validators.
     *
     * @throws  InvalidArgumentException  When the preserved input is not valid JSON, does not decode to a
     *          list, or holds an entry that is not an object.
     *
     * @since   2.0.0
     */
    private function validators(array $form, int $index): array
    {
        $rule = $this->value($form, "field_{$index}_validator");
        if ($rule === '') {
            $json = $this->value($form, "field_{$index}_validators_preserved");
            if ($json === '') {
                return [];
            }
            try {
                $validators = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new InvalidArgumentException('Preserved field validators are invalid JSON.', 0, $exception);
            }
            if (!is_array($validators) || !array_is_list($validators)) {
                throw new InvalidArgumentException('Preserved field validators must be a list.');
            }
            $result = [];
            foreach ($validators as $validator) {
                if (!is_array($validator) || array_is_list($validator)) {
                    throw new InvalidArgumentException('Every preserved field validator must be an object.');
                }
                /** @var array<string, mixed> $validator */
                $result[] = $validator;
            }
            return $result;
        }
        return [[
            'rule' => $rule,
            'value' => $this->value($form, "field_{$index}_validator_value"),
        ]];
    }

    /**
     * Lay the field configuration the editor controls over whatever was preserved from the last save.
     *
     * The five keys the screen owns — options, currency, unit, target and maximum bytes — are stripped from
     * the preserved document first and only then re-added from the form, so emptying a control clears its key
     * instead of leaving the previous value showing through. Keys belonging to an extension field type are
     * never touched, which is what lets a custom type keep its settings across an edit.
     *
     * @param   array<string, string>  $form   Flattened administrator form, keyed by input name.
     * @param   int                    $index  Zero-based row number of the field being mapped.
     *
     * @return  array<string, scalar|list<scalar|null>|null>  Configuration keyed by option name; empty when
     *          neither the form nor the preserved document supplied one.
     *
     * @throws  InvalidArgumentException  When the preserved input is not valid JSON within its depth limit, or
     *          decodes to a non-empty list.
     *
     * @since   2.0.0
     */
    private function configuration(array $form, int $index): array
    {
        $json = $this->value($form, "field_{$index}_configuration_preserved");
        try {
            $configuration = $json === '' ? [] : json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException('Preserved field configuration is invalid JSON.', 0, $exception);
        }
        if (!is_array($configuration) || ($configuration !== [] && array_is_list($configuration))) {
            throw new InvalidArgumentException('Preserved field configuration must be an object.');
        }
        foreach (['options', 'currency', 'unit', 'target', 'max_bytes'] as $knownKey) {
            unset($configuration[$knownKey]);
        }
        $options = $this->list($form, "field_{$index}_options");
        if ($options !== []) {
            $configuration['options'] = $options;
        }
        foreach (['currency', 'unit', 'target'] as $key) {
            $value = $this->value($form, "field_{$index}_{$key}");
            if ($value !== '') {
                $configuration[$key] = $value;
            }
        }
        $maxBytes = $this->integerOrNull($form, "field_{$index}_max_bytes");
        if ($maxBytes !== null) {
            $configuration['max_bytes'] = $maxBytes;
        }
        /** @var array<string, scalar|list<scalar|null>|null> $configuration */
        return $configuration;
    }

    /**
     * Coerce a field's posted default into the shape that field's type stores.
     *
     * Only the two types whose defaults are not text are parsed; every other type keeps the operator's string,
     * leaving the definition's own field rules to accept or refuse it.
     *
     * @param   array<string, string>  $form   Flattened administrator form, keyed by input name.
     * @param   int                    $index  Zero-based row number of the field being mapped.
     * @param   string                 $type   Field type handle deciding how the posted text is read.
     *
     * @return  mixed  The typed default, or null when the control was left blank, meaning no default at all.
     *
     * @throws  InvalidArgumentException  When an integer default is not an optionally signed run of decimal
     *          digits, or a boolean default is spelled as anything but true, 1, false or 0.
     *
     * @since   2.0.0
     */
    private function defaultValue(array $form, int $index, string $type): mixed
    {
        $value = $this->value($form, "field_{$index}_default");
        if ($value === '') {
            return null;
        }
        return match ($type) {
            'core.integer' => $this->validatedInteger($value, 'field default'),
            'core.boolean' => match ($value) {
                'true', '1' => true,
                'false', '0' => false,
                default => throw new InvalidArgumentException('A boolean default must be true or false.'),
            },
            default => $value,
        };
    }

    /**
     * Split one free-text control into the clean list of handles a definition can hold.
     *
     * Entries may be separated by commas or newlines in any mix; each is trimmed, blanks are discarded and
     * repeats are dropped, so loose typing in a textarea still yields a well-formed list.
     *
     * @param   array<string, string>  $form     Flattened administrator form, keyed by input name.
     * @param   string                 $key      Input name of the control to read.
     * @param   list<string>           $default  Returned whole when the control is blank.
     *
     * @return  list<string>  Trimmed, de-duplicated entries in the order they were typed, renumbered from
     *          zero; empty when the control held only separators.
     *
     * @since   2.0.0
     */
    private function list(array $form, string $key, array $default = []): array
    {
        $value = $this->value($form, $key);
        if ($value === '') {
            return $default;
        }
        $items = preg_split('/[\r\n,]+/u', $value);
        if ($items === false) {
            $items = [];
        }
        return array_values(array_unique(array_filter(
            array_map('trim', $items),
            static fn (string $item): bool => $item !== '',
        )));
    }

    /**
     * Read an input the definition cannot be assembled without.
     *
     * @param   array<string, string>  $form  Flattened administrator form, keyed by input name.
     * @param   string                 $key   Input name to read.
     *
     * @return  string  The trimmed value, never the empty string.
     *
     * @throws  InvalidArgumentException  When the input is absent or trims to nothing; the message names the
     *          input in words so the operator can find the control on the screen.
     *
     * @since   2.0.0
     */
    private function required(array $form, string $key): string
    {
        $value = $this->value($form, $key);
        if ($value === '') {
            throw new InvalidArgumentException('The ' . str_replace('_', ' ', $key) . ' field is required.');
        }
        return $value;
    }

    /**
     * Read an optional input, trimmed.
     *
     * The default stands in only for an input that was never posted. A control that posts an empty string — an
     * untouched text box, a select on its blank option — yields the empty string, which every caller reads as
     * "this is absent" rather than falling back.
     *
     * @param   array<string, string>  $form     Flattened administrator form, keyed by input name.
     * @param   string                 $key      Input name to read.
     * @param   string                 $default  Value used when the key is missing from the form entirely.
     *
     * @return  string  The trimmed value, the trimmed default, or the empty string.
     *
     * @since   2.0.0
     */
    private function value(array $form, string $key, string $default = ''): string
    {
        return trim($form[$key] ?? $default);
    }

    /**
     * Decide whether a checkbox was ticked.
     *
     * The raw value is compared rather than the trimmed one, so only the literal `1` the editor's checkboxes
     * post counts; an absent key is false, which is exactly what an unticked HTML checkbox sends.
     *
     * @param   array<string, string>  $form  Flattened administrator form, keyed by input name.
     * @param   string                 $key   Input name of the checkbox.
     *
     * @return  bool  True only when the input holds exactly `1`.
     *
     * @since   2.0.0
     */
    private function checked(array $form, string $key): bool
    {
        return ($form[$key] ?? '') === '1';
    }

    /**
     * Read an optional whole-number input, falling back to a value the caller supplies.
     *
     * @param   array<string, string>  $form     Flattened administrator form, keyed by input name.
     * @param   string                 $key      Input name to read.
     * @param   int                    $default  Value used when the control is left blank.
     *
     * @return  int  The parsed number, or the default.
     *
     * @throws  InvalidArgumentException  When the control holds text that is not an integer.
     *
     * @since   2.0.0
     */
    private function integer(array $form, string $key, int $default): int
    {
        $value = $this->value($form, $key);
        return $value === '' ? $default : $this->validatedInteger($value, $key);
    }

    /**
     * Read an optional whole-number input that is genuinely absent when blank.
     *
     * Used for the field properties where "unset" and zero are different answers — length, precision, scale
     * and the maximum byte size — so leaving the control blank leaves the property unconstrained rather than
     * constraining it to nothing.
     *
     * @param   array<string, string>  $form  Flattened administrator form, keyed by input name.
     * @param   string                 $key   Input name to read.
     *
     * @return  ?int  The parsed number, or null when the control is blank.
     *
     * @throws  InvalidArgumentException  When the control holds text that is not an integer.
     *
     * @since   2.0.0
     */
    private function integerOrNull(array $form, string $key): ?int
    {
        $value = $this->value($form, $key);
        return $value === '' ? null : $this->validatedInteger($value, $key);
    }

    /**
     * Parse a whole number, refusing anything a numeric definition property could not hold.
     *
     * Only an optional minus sign followed by decimal digits is accepted, so the spellings PHP's own cast
     * would silently swallow — a leading plus, an exponent, trailing rubbish — are refused instead.
     *
     * @param   string  $value  Trimmed text to parse.
     * @param   string  $key    Name quoted back in the failure message — an input name for a control the
     *          operator can point at, or a phrase such as `field default` where there is none.
     *
     * @return  int  The parsed value.
     *
     * @throws  InvalidArgumentException  When the text is not an optionally signed run of decimal digits.
     *
     * @since   2.0.0
     */
    private function validatedInteger(string $value, string $key): int
    {
        if (preg_match('/^-?[0-9]+$/D', $value) !== 1) {
            throw new InvalidArgumentException('The ' . str_replace('_', ' ', $key) . ' field must be an integer.');
        }
        return (int) $value;
    }
}
