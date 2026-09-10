<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Application;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Domain\ComputationMode;
use Kumwe\App\BusinessDefinition\Domain\DeleteBehavior;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\App\BusinessDefinition\Domain\IdentityStrategy;
use Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\App\BusinessDefinition\Domain\RelationshipDefinition;
use Kumwe\App\BusinessDefinition\Domain\RelationshipKind;
use Kumwe\App\BusinessDefinition\Domain\ScopeMode;
use Kumwe\App\BusinessDefinition\Domain\Sensitivity;
use Kumwe\Extension\Spi\BusinessSurface\Presentation\Field\FieldPresentationConfiguration;
use Kumwe\Sequence\Value\NumberSequenceFormat;
use Kumwe\Sequence\Value\NumberSequenceReset;
use Kumwe\Sequence\Value\NumberSequenceScope;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Checks the business-definition rules no single declaration can answer for itself.
 *
 * `FieldDefinition` and `RelationshipDefinition` settle what one declaration can decide alone, and
 * `EntityTypeDefinition` settles what is internal to one entity. What is left needs the whole set at once:
 * whether a declared field type is registered and the configuration keys behind it belong to that type,
 * whether a referenced entity exists, sits in the same site and scope, and names a reciprocal inverse, and
 * whether ownership edges stay acyclic so cascade deletion terminates. The same pass applies the limits that
 * keep a definition portable across the supported database engines — declared lengths, sortable columns
 * bounded for keyset pagination, and defaults the emitted column could actually hold.
 *
 * Callers hand over a self-contained set: `BusinessDefinitionContributionRegistry` passes everything core and
 * the enabled extensions contributed at bootstrap, and `BusinessDefinitionService` closes a draft over its
 * dependency graph before saving or publishing it. A reference leaving that set is a failure, not a deferral,
 * so nothing reaches the schema compiler with a dangling target.
 *
 * @since  2.0.0
 */
final readonly class BusinessDefinitionValidator
{
    /**
     * Bind the validator to the resolver that supplies field-type structure.
     *
     * @param  FieldTypeDefinitionResolver  $fieldTypes  Answers what a declared field-type identifier means,
     *         including for a type whose owning extension is no longer running.
     *
     * @since  2.0.0
     */
    public function __construct(private FieldTypeDefinitionResolver $fieldTypes)
    {
    }

    /**
     * Check a set of entity definitions as one graph, raising on the first rule it breaks.
     *
     * The set has to be self-contained and bounded: an empty graph and one above 128 entities are both
     * refused, a handle may appear only once, and every entity a field or relationship targets has to be
     * present here. Beyond resolving references this is where the runtime's own restrictions are applied —
     * `runtime_relation_evidence` is reserved as a field handle, a required relationship is refused because
     * creating both sides atomically is not supported, cascade deletion is reserved for owned line
     * collections, and set-null for singular associations. Nothing is collected into a report; the first
     * failure raises.
     *
     * @param   list<EntityTypeDefinition>  $definitions  The complete set to check, in any order.
     *
     * @return  void
     *
     * @throws  InvalidBusinessDefinition  When the set is empty or above 128 entities, a handle is
     *          duplicated, a field type or a targeted entity cannot be resolved, a field carries
     *          configuration its type does not register, an entity declares more than one posting date
     *          field or a fiscal-period number sequence without one, a reference crosses site or scope,
     *          a declared inverse is missing, ambiguous or not reciprocal, a delete behaviour does not
     *          suit its cardinality, or owned collections form a cycle.
     *
     * @since   2.0.0
     */
    public function validateGraph(array $definitions): void
    {
        if ($definitions === [] || count($definitions) > 128) {
            throw new InvalidBusinessDefinition('A business definition graph is empty or unbounded.');
        }
        $byHandle = [];
        $fieldTargets = [];
        foreach ($definitions as $definition) {
            if (isset($byHandle[$definition->handle])) {
                throw new InvalidBusinessDefinition('Business entity ' . $definition->handle . ' is duplicated.');
            }
            $byHandle[$definition->handle] = $definition;
            $this->validateIdentity($definition);
            $postingDates = 0;
            $fiscalSequence = null;
            foreach ($definition->fields() as $field) {
                if (($field->configuration['posting_date'] ?? null) === true) {
                    ++$postingDates;
                }
                if (
                    $field->type === 'core.sequence'
                    && ($field->configuration['reset'] ?? null) === NumberSequenceReset::FiscalPeriod->value
                ) {
                    $fiscalSequence ??= $field->handle;
                }
                if ($field->handle === 'runtime_relation_evidence') {
                    throw new InvalidBusinessDefinition(
                        'A business field handle is reserved for immutable runtime revision evidence.',
                    );
                }
                try {
                    FieldPresentationConfiguration::fromArray($field->configuration);
                } catch (InvalidArgumentException $exception) {
                    throw new InvalidBusinessDefinition(sprintf(
                        'Business field %s has non-portable presentation configuration: %s',
                        $field->handle,
                        $exception->getMessage(),
                    ), 0, $exception);
                }
                $fieldType = $this->fieldTypes->get($field->type);
                $unknown = array_diff(array_keys($field->configuration), $fieldType->configurationKeys);
                if ($unknown !== []) {
                    throw new InvalidBusinessDefinition(sprintf(
                        'Business field %s has unsupported %s configuration.',
                        $field->handle,
                        implode(', ', $unknown),
                    ));
                }
                $this->validateFieldConfiguration($field->handle, $field->configuration);
                $this->validateFieldRules($field, $definition->scope);
                if (in_array($field->type, ['core.entity_reference', 'core.ordered_lines'], true)) {
                    $target = $field->configuration['target'] ?? null;
                    if (!is_string($target)) {
                        throw new InvalidBusinessDefinition(sprintf(
                            'Business field %s requires a declared entity target.',
                            $field->handle,
                        ));
                    }
                    $fieldTargets[] = [$definition, $field, $target];
                }
            }
            if ($postingDates > 1) {
                throw new InvalidBusinessDefinition(sprintf(
                    'Business entity %s declares more than one posting date field.',
                    $definition->handle,
                ));
            }
            if ($fiscalSequence !== null && $postingDates === 0) {
                throw new InvalidBusinessDefinition(sprintf(
                    'Business field %s declares a fiscal-period number sequence, '
                    . 'but entity %s declares no posting date field to resolve the period from.',
                    $fiscalSequence,
                    $definition->handle,
                ));
            }
        }
        $ownershipEdges = [];
        foreach ($fieldTargets as [$definition, $field, $targetHandle]) {
            $target = $byHandle[$targetHandle] ?? null;
            if (!$target instanceof EntityTypeDefinition) {
                throw new InvalidBusinessDefinition(sprintf(
                    'Business field %s.%s targets an unavailable definition.',
                    $definition->handle,
                    $field->handle,
                ));
            }
            $this->assertCompatibleScope($definition, $target);
            if ($field->type === 'core.ordered_lines') {
                $ownershipEdges[$definition->handle][] = $target->handle;
            }
        }
        foreach ($definitions as $definition) {
            foreach ($definition->relationships() as $relationship) {
                $target = $byHandle[$relationship->target] ?? null;
                if (!$target instanceof EntityTypeDefinition) {
                    throw new InvalidBusinessDefinition(sprintf(
                        'Relationship %s.%s targets an unavailable definition.',
                        $definition->handle,
                        $relationship->handle,
                    ));
                }
                $this->assertCompatibleScope($definition, $target);
                if ($relationship->required) {
                    throw new InvalidBusinessDefinition(
                        'Required relationships need atomic create inputs and are not publishable by this runtime.',
                    );
                }
                if ($relationship->inverse !== null) {
                    $inverse = array_values(array_filter(
                        $target->relationships(),
                        static fn (RelationshipDefinition $candidate): bool =>
                            $candidate->handle === $relationship->inverse,
                    ));
                    if (count($inverse) !== 1 || $inverse[0]->target !== $definition->handle) {
                        throw new InvalidBusinessDefinition('A business relationship inverse is missing or ambiguous.');
                    }
                    if (
                        $inverse[0]->inverse !== $relationship->handle
                        || !$this->inverseKindsMatch($relationship, $inverse[0])
                    ) {
                        throw new InvalidBusinessDefinition(
                            'Business relationship inverses must be reciprocal and cardinality-compatible.',
                        );
                    }
                }
                if ($relationship->kind === RelationshipKind::OwnedLineCollection) {
                    $ownershipEdges[$definition->handle][] = $target->handle;
                    $this->validateLineAggregations($definition, $relationship, $target);
                }
                if ($relationship->kind === RelationshipKind::Reversal) {
                    if ($relationship->target !== $definition->handle) {
                        throw new InvalidBusinessDefinition(
                            'A reversal relationship must target a record of its own definition.',
                        );
                    }
                    if ($relationship->onDelete !== DeleteBehavior::Restrict) {
                        throw new InvalidBusinessDefinition(
                            'A reversal relationship must restrict deletion of the record it reverses.',
                        );
                    }
                }
                if (
                    $relationship->onDelete === DeleteBehavior::Cascade
                    && $relationship->kind !== RelationshipKind::OwnedLineCollection
                ) {
                    throw new InvalidBusinessDefinition(
                        'Only an owned line collection can use automatic cascade deletion.',
                    );
                }
                if (
                    $relationship->onDelete === DeleteBehavior::SetNull
                    && !in_array($relationship->kind, [
                        RelationshipKind::OneToOne,
                        RelationshipKind::ManyToOne,
                    ], true)
                ) {
                    throw new InvalidBusinessDefinition(
                        'Set-null deletion requires a singular relationship with explicit runtime revision handling.',
                    );
                }
            }
        }
        $this->assertAcyclicOwnership($ownershipEdges);
    }

    /**
     * Prove every invariant that reduces one owned-line collection against the entity on the far side.
     *
     * `EntityTypeDefinition` has already settled that the aggregation names a collection this entity
     * declares; what needs the rest of the catalog is the line itself. A summed handle has to be a field
     * the line entity actually carries, it has to hold an exact number rather than text the runtime would
     * have to guess at, and it must not be a value the line keeps sealed — folding an encrypted or
     * restricted field into a header total would leak its magnitude to anyone who can read the header.
     *
     * @param   EntityTypeDefinition    $definition    Entity declaring the invariant and the collection.
     * @param   RelationshipDefinition  $relationship  Owned-line collection being reduced.
     * @param   EntityTypeDefinition    $target        Line entity the collection stores, already resolved.
     *
     * @return  void
     *
     * @throws  InvalidBusinessDefinition  When a reduction names a field the line entity does not declare,
     *          a field whose type carries no exact number, or a restricted or secret field.
     *
     * @since   2.0.0
     */
    private function validateLineAggregations(
        EntityTypeDefinition $definition,
        RelationshipDefinition $relationship,
        EntityTypeDefinition $target,
    ): void {
        $lineFields = [];
        foreach ($target->fields() as $field) {
            $lineFields[$field->handle] = $field;
        }
        foreach ($definition->recordInvariants() as $invariant) {
            foreach ($invariant->lineDependencies() as $collection => $handles) {
                if ($collection !== $relationship->handle) {
                    continue;
                }
                foreach ($handles as $handle) {
                    $field = $lineFields[$handle] ?? null;
                    if (!$field instanceof FieldDefinition) {
                        throw new InvalidBusinessDefinition(sprintf(
                            'Record invariant %s sums %s.%s, which the line entity %s does not declare.',
                            $invariant->handle,
                            $collection,
                            $handle,
                            $target->handle,
                        ));
                    }
                    if (!in_array($field->type, ['core.decimal', 'core.integer'], true)) {
                        throw new InvalidBusinessDefinition(sprintf(
                            'Record invariant %s sums %s.%s, which is not an exact numeric line field.',
                            $invariant->handle,
                            $collection,
                            $handle,
                        ));
                    }
                    if (in_array($field->sensitivity, [Sensitivity::Restricted, Sensitivity::Secret], true)) {
                        throw new InvalidBusinessDefinition(sprintf(
                            'Record invariant %s sums %s.%s, which the line entity keeps restricted.',
                            $invariant->handle,
                            $collection,
                            $handle,
                        ));
                    }
                }
            }
        }
    }

    /**
     * Require the field carrying an entity's identity to work as a stable, unique, non-null key.
     *
     * Which field that is follows the declared strategy: `core.uuid` for a generated identity, and
     * `core.reference_identity` for an operator-visible reference. `EntityTypeDefinition` already requires
     * exactly one field of that type, so what this adds is that the field can serve as a key at all — records
     * are addressed by it, and a value that could be absent, repeated or edited afterwards would break every
     * reference already pointing at it.
     *
     * @param   EntityTypeDefinition  $definition  Entity whose identity field is being checked.
     *
     * @return  void
     *
     * @throws  InvalidBusinessDefinition  When no field matches the declared strategy, or the one that does
     *          is optional, nullable, non-unique, or may be changed after creation.
     *
     * @since   2.0.0
     */
    private function validateIdentity(EntityTypeDefinition $definition): void
    {
        $type = $definition->identityStrategy === IdentityStrategy::Uuid
            ? 'core.uuid'
            : 'core.reference_identity';
        $identity = array_values(array_filter(
            $definition->fields(),
            static fn (FieldDefinition $field): bool => $field->type === $type,
        ))[0] ?? null;
        if (
            !$identity instanceof FieldDefinition
            || !$identity->required || $identity->nullable || !$identity->unique
            || !$identity->immutableAfterCreate
        ) {
            throw new InvalidBusinessDefinition(
                'A business identity field must be required, non-null, unique, and immutable after creation.',
            );
        }
    }

    /**
     * Refuse a reference that would cross a site or a scope boundary.
     *
     * Both ends of a reference have to be partitioned the same way. A definition belonging to one site must
     * never point at another site's data, and two entities declaring different scope modes are divided along
     * different lines, so a join between them would surface rows from a partition the other side does not
     * use. Applied to relationships and to reference-shaped fields alike.
     *
     * @param   EntityTypeDefinition  $source  Entity declaring the field or relationship.
     * @param   EntityTypeDefinition  $target  Entity it points at, already resolved from the graph.
     *
     * @return  void
     *
     * @throws  InvalidBusinessDefinition  When the two entities belong to different sites, or declare
     *          different scope modes.
     *
     * @since   2.0.0
     */
    private function assertCompatibleScope(EntityTypeDefinition $source, EntityTypeDefinition $target): void
    {
        if ($source->siteIdentifier !== $target->siteIdentifier) {
            throw new InvalidBusinessDefinition('Business references cannot cross site scope.');
        }
        if ($source->scope !== $target->scope) {
            throw new InvalidBusinessDefinition('Business references require matching scope modes.');
        }
    }

    /**
     * Decide whether two relationship declarations are cardinality-compatible as each other's inverse.
     *
     * Each kind admits a closed partner set: one-to-one pairs with one-to-one, many-to-one and reversal
     * each pair with one-to-many — which therefore accepts either of them back — and many-to-many with
     * many-to-many, where the two sides must additionally agree on whether members are ordered. An owned
     * line collection never pairs, on either side, because its lines belong to their owner rather than
     * standing as an entity that declares a relationship back.
     *
     * @param   RelationshipDefinition  $relationship  Side being checked, as its own entity declared it.
     * @param   RelationshipDefinition  $inverse       Relationship the target entity declares back at it.
     *
     * @return  bool  True when the pair could describe one association read from both ends.
     *
     * @since   2.0.0
     */
    private function inverseKindsMatch(
        RelationshipDefinition $relationship,
        RelationshipDefinition $inverse,
    ): bool {
        if (
            $relationship->kind === RelationshipKind::OwnedLineCollection
            || $inverse->kind === RelationshipKind::OwnedLineCollection
        ) {
            return false;
        }
        if ($relationship->kind === RelationshipKind::OneToOne) {
            $expected = [RelationshipKind::OneToOne];
        } elseif (
            $relationship->kind === RelationshipKind::ManyToOne
            || $relationship->kind === RelationshipKind::Reversal
        ) {
            $expected = [RelationshipKind::OneToMany];
        } elseif ($relationship->kind === RelationshipKind::OneToMany) {
            $expected = [RelationshipKind::ManyToOne, RelationshipKind::Reversal];
        } else {
            $expected = [RelationshipKind::ManyToMany];
        }

        return in_array($inverse->kind, $expected, true)
            && ($relationship->kind !== RelationshipKind::ManyToMany
                || $relationship->ordered === $inverse->ordered);
    }

    /**
     * Apply every per-field rule that needs the field type resolved or the storage engine in mind.
     *
     * Portable length and sortability are settled first, then the combinations `FieldDefinition` could not
     * judge without knowing the type: a secret declaring a reusable plaintext default, an optional non-null
     * field with nothing to fall back on, an ordered-line field claiming scalar storage rules, a virtual
     * computation claiming physical index or uniqueness capabilities, and an enum whose options are not
     * distinct, do not fit the storage length, or whose default is not among them. The declared default is
     * checked next, and finally the normalizer and validator lists, each entry of which has to name a rule
     * this runtime implements, carry exactly that rule's arguments, and suit the value family the field
     * actually produces at runtime.
     *
     * @param   FieldDefinition  $field  Field to check, whose type the caller has already resolved.
     * @param   ScopeMode        $scope  Tenancy dimensions the owning entity's records actually carry.
     *
     * @return  void
     *
     * @throws  InvalidBusinessDefinition  When any of those rules fails, including a length or a sortable
     *          field the supported engines could not carry portably, a default the declared type cannot
     *          hold, an unknown or incompatible normalizer or validator, a validator whose arguments are the
     *          wrong shape or out of bounds, or a pattern using the backtracking constructs this runtime
     *          refuses.
     *
     * @since   2.0.0
     */
    private function validateFieldRules(FieldDefinition $field, ScopeMode $scope): void
    {
        $this->validatePortableLength($field);
        $this->validatePortableSort($field);
        if ($field->type === 'core.secret' && $field->default !== null) {
            throw new InvalidBusinessDefinition(
                'An encrypted secret field cannot declare a reusable plaintext default.',
            );
        }
        if (!$field->required && !$field->nullable && $field->default === null && !$field->computed) {
            throw new InvalidBusinessDefinition(
                'An optional non-null business field requires a non-null default or stored computation.',
            );
        }
        if (
            $field->type === 'core.ordered_lines'
            && (
                $field->required || !$field->nullable || $field->default !== null
                || $field->unique || $field->indexed || $field->computed
                || $field->searchable || $field->filterable || $field->sortable || $field->reportable
            )
        ) {
            throw new InvalidBusinessDefinition(
                'An ordered-line field is an optional owned collection and cannot use scalar storage rules.',
            );
        }
        if (
            $field->computed && $field->computationMode === ComputationMode::Virtual
            && ($field->unique || $field->indexed || $field->searchable || $field->filterable
                || $field->sortable || $field->reportable)
        ) {
            throw new InvalidBusinessDefinition(
                'A virtual computed field cannot declare physical query, index, or uniqueness capabilities.',
            );
        }
        if ($field->type === 'core.sequence') {
            $this->validateSequence($field, $scope);
        }
        if ($field->type === 'core.enum') {
            $options = $field->configuration['options'] ?? null;
            if (
                !is_array($options) || $options === []
                || count(array_unique($options, SORT_STRING)) !== count($options)
            ) {
                throw new InvalidBusinessDefinition('An enum field requires distinct declared options.');
            }
            $maximum = $field->length ?? 191;
            foreach ($options as $option) {
                if (!is_string($option) || mb_strlen($option, 'UTF-8') > $maximum) {
                    throw new InvalidBusinessDefinition('An enum option exceeds the field storage length.');
                }
            }
            if (
                $field->default !== null
                && (!is_string($field->default) || !in_array($field->default, $options, true))
            ) {
                throw new InvalidBusinessDefinition('An enum field default must be one of its declared options.');
            }
        }
        $this->validateDefault($field);
        $normalizers = [
            'trim', 'lowercase', 'uppercase', 'unicode_nfc', 'email', 'url', 'phone', 'decimal_scale',
        ];
        if (array_diff($field->normalizers, $normalizers) !== []) {
            throw new InvalidBusinessDefinition('Business field ' . $field->handle . ' uses an unknown normalizer.');
        }
        foreach ($field->normalizers as $normalizer) {
            $compatible = $normalizer === 'decimal_scale'
                ? $this->runtimeDecimal($field)
                : $this->normalizerStringInput($field);
            if (!$compatible) {
                throw new InvalidBusinessDefinition(sprintf(
                    'Business field %s normalizer %s is incompatible with its value type.',
                    $field->handle,
                    $normalizer,
                ));
            }
        }

        $allowed = [
            'pattern' => ['rule', 'value'],
            'min_length' => ['rule', 'value'],
            'max_length' => ['rule', 'value'],
            'min' => ['rule', 'value'],
            'max' => ['rule', 'value'],
            'one_of' => ['rule', 'value'],
            'email' => ['rule'],
            'url' => ['rule'],
            'uuid' => ['rule'],
            'integer' => ['rule'],
            'decimal' => ['rule'],
        ];
        foreach ($field->validators as $validator) {
            $rule = $validator['rule'] ?? null;
            if (!is_string($rule) || !isset($allowed[$rule])) {
                throw new InvalidBusinessDefinition('Business field ' . $field->handle . ' uses an unknown validator.');
            }
            $keys = array_keys($validator);
            sort($keys, SORT_STRING);
            $expected = $allowed[$rule];
            sort($expected, SORT_STRING);
            if ($keys !== $expected) {
                throw new InvalidBusinessDefinition(
                    'Business field ' . $field->handle . ' has an invalid validator shape.',
                );
            }
            if (!$this->validatorCompatible($field, $rule)) {
                throw new InvalidBusinessDefinition(sprintf(
                    'Business field %s validator %s is incompatible with its value type.',
                    $field->handle,
                    $rule,
                ));
            }
            $value = $validator['value'] ?? null;
            if (
                in_array($rule, ['min_length', 'max_length'], true)
                && (!is_int($value) || $value < 0 || $value > 1_000_000)
            ) {
                throw new InvalidBusinessDefinition(
                    'Business field ' . $field->handle . ' has an invalid length validator.',
                );
            }
            if (
                in_array($rule, ['min', 'max'], true)
                && (!is_string($value) || preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value) !== 1)
            ) {
                throw new InvalidBusinessDefinition(
                    'Business field ' . $field->handle . ' has an invalid exact numeric validator.',
                );
            }
            if (
                $rule === 'one_of'
                && (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 256)
            ) {
                throw new InvalidBusinessDefinition(
                    'Business field ' . $field->handle . ' has an invalid one-of validator.',
                );
            }
            if ($rule === 'pattern') {
                if (
                    !is_string($value) || $value === '' || strlen($value) > 512
                    || preg_match('/\(\?(?:[=!<]|R|[0-9]|P|\()|\\\\[1-9]/', $value) === 1
                    || @preg_match('~' . str_replace('~', '\\~', $value) . '~uD', '') === false
                ) {
                    throw new InvalidBusinessDefinition(
                        'Business field ' . $field->handle . ' has an unsafe pattern validator.',
                    );
                }
            }
        }
    }

    /**
     * Hold an allocated-number field to the shape its allocator can actually honour.
     *
     * A `core.sequence` field is the only field type whose value is produced by taking a database lock, so
     * the declaration has to be refused here rather than at the moment an invoice is created. Three things
     * are checked. The format itself must parse, because a definition that reaches storage with
     * `"reset": "quarterly"` would produce numbers that do not mean what the definition says. The field
     * must be closed to callers — server-only, read-only, immutable after create and never defaulted —
     * because an allocated number that a caller may supply or change is not an allocated number. And it
     * must be required and non-null, since there is no such thing as an optional position in a contiguous
     * run.
     *
     * Uniqueness is required as well. It is not what makes the run gapless — the counter row does that —
     * but it is the independent check that says so: if the allocator, an import or an operator ever put
     * the same number on two records, the index refuses the write instead of leaving two invoices sharing
     * a number.
     *
     * The declared scope must also be one the entity's own tenancy can compose a counter key from. A
     * per-organization run keys its counter on the record's resolved organization, so a definition whose
     * scope mode carries no organization dimension has nothing to key it on; accepting that declaration
     * here would defer the failure to the first create, which is the exact deferral this method exists to
     * prevent.
     *
     * @param   FieldDefinition  $field  Field declaring `core.sequence`.
     * @param   ScopeMode        $scope  Tenancy dimensions the owning entity's records actually carry.
     *
     * @return  void
     *
     * @throws  InvalidBusinessDefinition  When the format is unusable, the declared scope names a tenancy
     *          dimension the entity's scope mode does not carry, the field is open to callers, or it is
     *          optional, nullable, non-unique or narrower than the numbers it would render.
     *
     * @since   2.0.0
     */
    private function validateSequence(FieldDefinition $field, ScopeMode $scope): void
    {
        try {
            $format = NumberSequenceFormat::fromConfiguration($field->configuration);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidBusinessDefinition(sprintf(
                'Business field %s declares an unusable number sequence: %s',
                $field->handle,
                $exception->getMessage(),
            ), 0, $exception);
        }
        if (
            $format->scope === NumberSequenceScope::Organization
            && !in_array($scope, [ScopeMode::Organization, ScopeMode::SiteOrganization], true)
        ) {
            throw new InvalidBusinessDefinition(sprintf(
                'Business field %s declares a per-organization number sequence, '
                . 'but its entity scope mode carries no organization dimension to key the counter on.',
                $field->handle,
            ));
        }
        if (!$field->serverOnly || !$field->readOnly || !$field->immutableAfterCreate || $field->default !== null) {
            throw new InvalidBusinessDefinition(
                'An allocated-number field must be server-only, read-only, immutable after create, and undefaulted.',
            );
        }
        if (!$field->required || $field->nullable || !$field->unique) {
            throw new InvalidBusinessDefinition(
                'An allocated-number field must be required, non-null and unique.',
            );
        }
        if ($field->computed || $field->formula !== null) {
            throw new InvalidBusinessDefinition(
                'An allocated-number field is reserved by the allocator, never derived by a formula.',
            );
        }
        if (($field->length ?? NumberSequenceFormat::MAXIMUM_LENGTH) < NumberSequenceFormat::MAXIMUM_LENGTH) {
            throw new InvalidBusinessDefinition(sprintf(
                'An allocated-number field needs at least %d characters to hold every number it can render.',
                NumberSequenceFormat::MAXIMUM_LENGTH,
            ));
        }
    }

    /**
     * Decide whether a text normalizer would have a string to work on for this field.
     *
     * A computed field is judged by its formula's result type. An encrypted secret counts here — unlike in
     * `runtimeString()`, which refuses it — because `RecordValueCodec` runs the normalizers over the
     * submitted value before that value is encrypted. The exact-numeric and temporal core types are refused
     * outright even though several of them travel as strings, so trimming or case folding can never be
     * attached to a date or a decimal. Everything else follows the value family the field type registered,
     * where a reference counts as text.
     *
     * @param   FieldDefinition  $field  Field whose declared normalizers are being checked.
     *
     * @return  bool  True when the field's submitted value is text a normalizer may rewrite.
     *
     * @since   2.0.0
     */
    private function normalizerStringInput(FieldDefinition $field): bool
    {
        if ($field->type === 'core.computed') {
            return $field->formula?->type === 'string';
        }
        if ($field->type === 'core.secret') {
            return true;
        }
        if (
            in_array($field->type, [
            'core.decimal',
            'core.date',
            'core.local_time',
            'core.instant',
            'core.zoned_datetime',
            ], true)
        ) {
            return false;
        }

        return in_array($this->fieldTypes->get($field->type)->valueType, ['string', 'reference'], true);
    }

    /**
     * Decide whether one validator rule can be applied to this field's runtime value.
     *
     * Rules are matched against the value family the field produces rather than against its declared type, so
     * a computed field is judged by its formula and a contributed type by the family it registered. The
     * string rules need text — which excludes encrypted secrets, dates, times and exact numerics — `min` and
     * `max` need a number of either kind, and `one_of` additionally accepts a boolean.
     *
     * @param   FieldDefinition  $field  Field the rule was declared on.
     * @param   string           $rule   Validator rule name, already known to be one this runtime supports.
     *
     * @return  bool  True when the rule and the field's value family fit; false for a rule outside the
     *          supported set.
     *
     * @since   2.0.0
     */
    private function validatorCompatible(FieldDefinition $field, string $rule): bool
    {
        $string = $this->runtimeString($field);
        $integer = $this->runtimeInteger($field);
        $decimal = $this->runtimeDecimal($field);

        return match ($rule) {
            'pattern', 'min_length', 'max_length', 'email', 'url', 'uuid' => $string,
            'min', 'max' => $integer || $decimal,
            'one_of' => $string || $integer || $this->runtimeBoolean($field),
            'integer' => $integer,
            'decimal' => $decimal,
            default => false,
        };
    }

    /**
     * Decide whether the field's values reach a validator as text.
     *
     * A computed field is judged by its formula's result type. The exact-numeric and temporal core types are
     * refused even though several of them travel as strings, so a pattern or a length rule cannot be attached
     * to what is really a serialization of a date or a number, and `core.secret` is refused because what the
     * validator would see is an encrypted envelope rather than the value. Everything else follows the value
     * family the field type registered, where a reference counts as text.
     *
     * @param   FieldDefinition  $field  Field whose value family is being classified.
     *
     * @return  bool  True when a string-oriented rule may target the field.
     *
     * @since   2.0.0
     */
    private function runtimeString(FieldDefinition $field): bool
    {
        if ($field->type === 'core.computed') {
            return $field->formula?->type === 'string';
        }
        if (
            in_array($field->type, [
            'core.decimal',
            'core.date',
            'core.local_time',
            'core.instant',
            'core.zoned_datetime',
            'core.secret',
            ], true)
        ) {
            return false;
        }

        return in_array($this->fieldTypes->get($field->type)->valueType, ['string', 'reference'], true);
    }

    /**
     * Decide whether the field's values are whole numbers at runtime.
     *
     * @param   FieldDefinition  $field  Field whose value family is being classified.
     *
     * @return  bool  True for a computed field with an integer formula, or a type registered as integer.
     *
     * @since   2.0.0
     */
    private function runtimeInteger(FieldDefinition $field): bool
    {
        return $field->type === 'core.computed'
            ? $field->formula?->type === 'integer'
            : $this->fieldTypes->get($field->type)->valueType === 'integer';
    }

    /**
     * Decide whether the field's values are booleans at runtime.
     *
     * @param   FieldDefinition  $field  Field whose value family is being classified.
     *
     * @return  bool  True for a computed field with a boolean formula, or a type registered as boolean.
     *
     * @since   2.0.0
     */
    private function runtimeBoolean(FieldDefinition $field): bool
    {
        return $field->type === 'core.computed'
            ? $field->formula?->type === 'boolean'
            : $this->fieldTypes->get($field->type)->valueType === 'boolean';
    }

    /**
     * Decide whether the field's values are bare exact decimals at runtime.
     *
     * Only `core.decimal` and a computed field with a decimal formula qualify. Money and quantity do not,
     * even though each carries an amount, because their value is a composite of which the amount is one
     * member — a numeric rule declared against the whole field would have nothing single to measure.
     *
     * @param   FieldDefinition  $field  Field whose value family is being classified.
     *
     * @return  bool  True when the whole value is one exact decimal.
     *
     * @since   2.0.0
     */
    private function runtimeDecimal(FieldDefinition $field): bool
    {
        return $field->type === 'core.decimal'
            || ($field->type === 'core.computed' && $field->formula?->type === 'decimal');
    }

    /**
     * Keep a declared length inside what the emitted column can portably hold for that field type.
     *
     * A field that declares no length is left alone, and so is a type with no ceiling worth enforcing — an
     * integer or a JSON document, say. The rest are capped where the type's physical storage is: 191
     * characters for identifiers, entity references, enum values and phone numbers, 320 for an email address,
     * and 1000 for free text, which is also where a string-valued computation and a contributed type stored
     * as a string land.
     *
     * @param   FieldDefinition  $field  Field whose declared length is being checked.
     *
     * @return  void
     *
     * @throws  InvalidBusinessDefinition  When the declared length is above the ceiling for its type.
     *
     * @since   2.0.0
     */
    private function validatePortableLength(FieldDefinition $field): void
    {
        if ($field->length === null) {
            return;
        }
        $maximum = match ($field->type) {
            'core.reference_identity', 'core.entity_reference', 'core.enum', 'core.phone' => 191,
            'core.email' => 320,
            'core.sequence' => NumberSequenceFormat::MAXIMUM_LENGTH,
            'core.text' => 1000,
            'core.computed' => $field->formula?->type === 'string' ? 1000 : null,
            default => !str_starts_with($field->type, 'core.')
                && $this->fieldTypes->get($field->type)->storageType === 'string'
                    ? 1000
                    : null,
        };
        if ($maximum !== null && $field->length > $maximum) {
            throw new InvalidBusinessDefinition(sprintf(
                'Business field %s length exceeds its portable physical storage limit of %d.',
                $field->handle,
                $maximum,
            ));
        }
    }

    /**
     * Refuse a sortable field that could not serve as a keyset cursor across the supported engines.
     *
     * A field nobody may sort on passes untouched. For the rest, the sort key ends up encoded into a
     * stateless cursor and compared by the database, so it has to be a bounded scalar the caller is allowed
     * to see: a hidden or redacted value cannot be a cursor component, a fixed list of core types is refused
     * outright, and a string key is capped at 512 characters so the encoded cursor stays bounded. A
     * contributed type is refused on the same grounds when it registered `json` or `text` storage.
     *
     * @param   FieldDefinition  $field  Field to check; one that is not sortable passes untouched.
     *
     * @return  void
     *
     * @throws  InvalidBusinessDefinition  When a sortable field is not read-visible, is restricted or secret,
     *          uses storage without portable ordering, or declares a string key longer than 512 characters.
     *
     * @since   2.0.0
     */
    private function validatePortableSort(FieldDefinition $field): void
    {
        if (!$field->sortable) {
            return;
        }
        if (
            !$field->readVisible
            || in_array($field->sensitivity, [Sensitivity::Restricted, Sensitivity::Secret], true)
        ) {
            throw new InvalidBusinessDefinition(
                'A sortable business field must be read-visible and cannot contain redacted values.',
            );
        }
        $nonPortableCore = [
            'core.bounded_json',
            'core.embedded_value',
            'core.money',
            'core.ordered_lines',
            'core.quantity',
            'core.rich_text',
            'core.secret',
            'core.url',
            'core.zoned_datetime',
        ];
        $fieldType = $this->fieldTypes->get($field->type);
        if (
            in_array($field->type, $nonPortableCore, true)
            || (!str_starts_with($field->type, 'core.') && in_array($fieldType->storageType, ['json', 'text'], true))
        ) {
            throw new InvalidBusinessDefinition(
                'A sortable business field requires bounded scalar storage with portable keyset semantics.',
            );
        }
        $stringLength = match ($field->type) {
            'core.email' => $field->length ?? 320,
            'core.enum', 'core.phone', 'core.reference_identity', 'core.text' => $field->length ?? 191,
            'core.sequence' => $field->length ?? NumberSequenceFormat::MAXIMUM_LENGTH,
            'core.computed' => $field->formula?->type === 'string' ? ($field->length ?? 191) : null,
            default => !str_starts_with($field->type, 'core.') && $fieldType->storageType === 'string'
                ? ($field->length ?? 191)
                : null,
        };
        if ($stringLength !== null && $stringLength > 512) {
            throw new InvalidBusinessDefinition(
                'A sortable string field cannot exceed the 512-character stateless cursor bound.',
            );
        }
    }

    /**
     * Check that a declared default is a value the field's own type could actually hold.
     *
     * A field with no default passes immediately. Otherwise the check is per type and as strict as the column
     * the schema compiler will emit: exact numerics are measured against the declared precision and scale,
     * temporal values have to be the canonical UTC literals, composites have to carry exactly their own
     * members and agree with any currency or unit the field configured, and JSON has to fit the field's byte
     * budget. Computed, secret and ordered-line fields may not declare a default at all. A contributed type
     * falls through to the storage family it registered.
     *
     * @param   FieldDefinition  $field  Field whose declared default is being checked.
     *
     * @return  void
     *
     * @throws  InvalidBusinessDefinition  When the default is not a value of the declared type, or the type
     *          admits no default.
     *
     * @since   2.0.0
     */
    private function validateDefault(FieldDefinition $field): void
    {
        $value = $field->default;
        if ($value === null) {
            return;
        }
        $valid = match ($field->type) {
            'core.boolean' => is_bool($value),
            'core.integer' => is_int($value) && $value >= -2_147_483_648 && $value <= 2_147_483_647,
            'core.decimal' => $this->exactDefault($value, $field),
            'core.money' => $this->moneyDefault($value, $field),
            'core.quantity' => $this->quantityDefault($value, $field),
            'core.date' => $this->dateDefault($value),
            'core.local_time' => $this->timeDefault($value),
            'core.instant' => $this->instantDefault($value),
            'core.zoned_datetime' => $this->zonedDefault($value),
            'core.uuid', 'core.media_reference' => is_string($value) && Uuid::isValid($value),
            'core.reference_identity', 'core.entity_reference' => $this->referenceDefault($value, $field),
            'core.text' => $this->stringDefault($value, $field->length ?? 191),
            'core.rich_text' => $this->stringDefault($value, $field->length ?? 1_000_000),
            'core.email' => $this->emailDefault($value, $field),
            'core.url' => $this->urlDefault($value, $field),
            'core.phone' => $this->phoneDefault($value, $field),
            'core.enum' => is_string($value)
                && in_array($value, is_array($field->configuration['options'] ?? null)
                    ? $field->configuration['options'] : [], true),
            'core.embedded_value', 'core.bounded_json' => $this->jsonDefault($value, $field),
            'core.computed', 'core.secret', 'core.ordered_lines', 'core.sequence' => false,
            default => $this->customDefault($value, $field),
        };
        if (!$valid) {
            throw new InvalidBusinessDefinition(sprintf(
                'Business field %s has an invalid default for %s.',
                $field->handle,
                $field->type,
            ));
        }
    }

    /**
     * Decide whether a default is an exact decimal that fits the field's declared precision and scale.
     *
     * The literal is read as base-10 text — an integer is accepted and stringified — because an exact numeric
     * is never carried as a float anywhere in a definition. Digits are then counted on each side of the
     * point: the fraction may not exceed the scale, and the integer part may not exceed what the precision
     * leaves once the scale is taken, with a lone leading zero counting as no integer digits at all.
     *
     * @param   mixed            $value  Declared default, or one member of a composite default.
     * @param   FieldDefinition  $field  Field supplying the precision and scale to measure against.
     *
     * @return  bool  True when the literal is exact and fits; false when the field declares neither bound.
     *
     * @since   2.0.0
     */
    private function exactDefault(mixed $value, FieldDefinition $field): bool
    {
        if ((!is_int($value) && !is_string($value)) || $field->precision === null || $field->scale === null) {
            return false;
        }
        $value = (string) $value;
        if (preg_match('/^-?(0|[1-9][0-9]*)(?:\.([0-9]+))?$/D', $value, $matches) !== 1) {
            return false;
        }
        $integerDigits = $matches[1] === '0' ? 0 : strlen($matches[1]);
        $fractionDigits = strlen($matches[2] ?? '');

        return $fractionDigits <= $field->scale && $integerDigits <= $field->precision - $field->scale;
    }

    /**
     * Check a default for a contributed field type against the storage family that type registered.
     *
     * Core types are recognised by identifier; a type an extension shipped declares only how it is stored, so
     * that is what the default is measured against, reusing the same literal forms core uses for UUIDs,
     * dates, times and instants. A string default is additionally capped at 1000 characters however long the
     * field declared itself, which is the same portable ceiling `validatePortableLength()` applies.
     *
     * @param   mixed            $value  Declared default, as the definition carries it.
     * @param   FieldDefinition  $field  Field whose contributed type supplies the storage family.
     *
     * @return  bool  True when the default is a value that storage family can hold.
     *
     * @since   2.0.0
     */
    private function customDefault(mixed $value, FieldDefinition $field): bool
    {
        $fieldType = $this->fieldTypes->get($field->type);

        return match ($fieldType->storageType) {
            'guid' => is_string($value) && Uuid::isValid($value),
            'string' => $this->stringDefault($value, min($field->length ?? 191, 1000)),
            'text' => $this->stringDefault($value, $field->length ?? 1_000_000),
            'integer' => is_int($value) && $value >= -2_147_483_648 && $value <= 2_147_483_647,
            'boolean' => is_bool($value),
            'date' => $this->dateDefault($value),
            'time' => $this->timeDefault($value),
            'datetime' => $this->instantDefault($value),
            'json' => $this->customJsonDefault($value, $field, $fieldType->valueType),
            default => false,
        };
    }

    /**
     * Check a default for a contributed type stored as JSON against the value family it declares.
     *
     * Storage alone does not say enough here, because a JSON column may back a scalar, an object or a
     * collection. The default's PHP shape therefore has to match the family the type registered — a keyed
     * array for an object, a list for a collection — before its encoded bytes are measured against the
     * field's budget.
     *
     * @param   mixed            $value      Declared default, as the definition carries it.
     * @param   FieldDefinition  $field      Field supplying the `max_bytes` budget.
     * @param   string           $valueType  Value family the contributed type registered.
     *
     * @return  bool  True when the shape matches the family and the encoding fits the budget.
     *
     * @since   2.0.0
     */
    private function customJsonDefault(mixed $value, FieldDefinition $field, string $valueType): bool
    {
        $shape = match ($valueType) {
            'string', 'reference' => is_string($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            'object' => is_array($value) && !array_is_list($value),
            'collection' => is_array($value) && array_is_list($value),
            default => false,
        };

        return $shape && $this->jsonDefault($value, $field);
    }

    /**
     * Check a money default: an amount fitting the field's precision and scale, beside its currency.
     *
     * The object has to carry exactly `amount` and `currency` and nothing else, and when the field configures
     * a currency the default has to name that same one — compared with `hash_equals()` — so a definition
     * cannot ship a default denominated in a currency the field will never accept.
     *
     * @param   mixed            $value  Declared default, as the definition carries it.
     * @param   FieldDefinition  $field  Field supplying the precision, scale and any configured currency.
     *
     * @return  bool  True when both members are present and valid, and a configured currency matches.
     *
     * @since   2.0.0
     */
    private function moneyDefault(mixed $value, FieldDefinition $field): bool
    {
        if (!is_array($value) || array_is_list($value) || !$this->compositeDefault($value, ['amount', 'currency'])) {
            return false;
        }
        /** @var array{amount: mixed, currency: mixed} $value */
        $currency = $value['currency'];
        $configured = $field->configuration['currency'] ?? null;

        return $this->exactDefault($value['amount'], $field)
            && is_string($currency)
            && preg_match('/^[A-Z]{3}$/D', $currency) === 1
            && (!is_string($configured) || hash_equals($configured, $currency));
    }

    /**
     * Check a quantity default: an amount fitting the field's precision and scale, beside its unit.
     *
     * The object has to carry exactly `amount` and `unit` and nothing else. The unit is a bounded token
     * rather than a member of a fixed list, since units are domain vocabulary, and when the field configures
     * one the default has to name that same unit.
     *
     * @param   mixed            $value  Declared default, as the definition carries it.
     * @param   FieldDefinition  $field  Field supplying the precision, scale and any configured unit.
     *
     * @return  bool  True when both members are present and valid, and a configured unit matches.
     *
     * @since   2.0.0
     */
    private function quantityDefault(mixed $value, FieldDefinition $field): bool
    {
        if (!is_array($value) || array_is_list($value) || !$this->compositeDefault($value, ['amount', 'unit'])) {
            return false;
        }
        /** @var array{amount: mixed, unit: mixed} $value */
        $unit = $value['unit'];
        $configured = $field->configuration['unit'] ?? null;

        return $this->exactDefault($value['amount'], $field)
            && is_string($unit)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,62}$/D', $unit) === 1
            && (!is_string($configured) || hash_equals($configured, $unit));
    }

    /**
     * Decide whether a value is a keyed object carrying exactly the expected members.
     *
     * Neither a missing member nor an extra one is tolerated, so a composite default cannot be half-declared
     * and silently completed later by whichever consumer reads it first.
     *
     * @param   mixed         $value  Declared default, as the definition carries it.
     * @param   list<string>  $keys   Member names the composite must carry, no more and no fewer.
     *
     * @return  bool  True when the value is a keyed array whose key set is exactly `$keys`.
     *
     * @since   2.0.0
     */
    private function compositeDefault(mixed $value, array $keys): bool
    {
        return is_array($value) && !array_is_list($value)
            && count($value) === count($keys)
            && array_diff(array_keys($value), $keys) === []
            && array_diff($keys, array_keys($value)) === [];
    }

    /**
     * Decide whether a default is a calendar date that exists, written as `YYYY-MM-DD`.
     *
     * The literal is re-formatted after being parsed in UTC and compared with what was supplied, so a
     * well-shaped but impossible date such as the thirty-first of February is rejected rather than rolled
     * forward into March. Years below 1000 are refused so the four-digit form stays unambiguous.
     *
     * @param   mixed  $value  Declared default, as the definition carries it.
     *
     * @return  bool  True when the literal names a real date.
     *
     * @since   2.0.0
     */
    private function dateDefault(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) !== 1) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));

        return $date instanceof DateTimeImmutable
            && $date->format('Y-m-d') === $value
            && (int) substr($value, 0, 4) >= 1000;
    }

    /**
     * Decide whether a default is a wall-clock time written as `HH:MM:SS`, optionally with microseconds.
     *
     * The time carries no date and no zone, so it names a reading of the clock rather than an instant; a
     * fractional part, when present, has to be exactly six digits.
     *
     * @param   mixed  $value  Declared default, as the definition carries it.
     *
     * @return  bool  True when the literal is a valid 24-hour time of day.
     *
     * @since   2.0.0
     */
    private function timeDefault(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9](?:\.[0-9]{6})?$/D', $value) === 1;
    }

    /**
     * Decide whether a default is an instant expressed in UTC.
     *
     * Only a `Z` or `+00:00` designator is accepted and the parsed value has to report a zero offset, so a
     * default can never mean a different moment depending on where it is read. A parse failure is caught and
     * answered as false rather than propagated, warnings left behind by a lenient parse count as failures,
     * and years below 1000 are refused as they are for dates.
     *
     * @param   mixed  $value  Declared default, or the instant member of a zoned composite.
     *
     * @return  bool  True when the literal is a UTC instant this runtime reproduces exactly.
     *
     * @since   2.0.0
     */
    private function instantDefault(mixed $value): bool
    {
        if (
            !is_string($value) || preg_match(
                '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}'
                . '(?:\.[0-9]{1,6})?(?:Z|\+00:00)$/D',
                $value,
            ) !== 1
        ) {
            return false;
        }
        try {
            $instant = new DateTimeImmutable($value);
        } catch (Throwable) {
            return false;
        }
        $errors = DateTimeImmutable::getLastErrors();

        return ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $instant->getOffset() === 0
            && (int) substr($value, 0, 4) >= 1000;
    }

    /**
     * Decide whether a default pairs a UTC instant with the zone it is to be read in.
     *
     * The object carries exactly `instant` and `timezone`: the moment itself is stored in UTC, and the zone
     * travels beside it as an IANA identifier this installation's timezone database recognises rather than as
     * a fixed offset.
     *
     * @param   mixed  $value  Declared default, as the definition carries it.
     *
     * @return  bool  True when both members are present and each is valid on its own.
     *
     * @since   2.0.0
     */
    private function zonedDefault(mixed $value): bool
    {
        if (!is_array($value) || array_is_list($value) || !$this->compositeDefault($value, ['instant', 'timezone'])) {
            return false;
        }
        /** @var array{instant: mixed, timezone: mixed} $value */
        return $this->instantDefault($value['instant'])
            && is_string($value['timezone'])
            && in_array($value['timezone'], DateTimeZone::listIdentifiers(), true);
    }

    /**
     * Decide whether a default can stand as a reference key.
     *
     * The value is capped at 191 characters however long the field declared itself, may not be empty, and may
     * not contain C0 or DEL control characters. It is the same test for a reference identity and for an
     * entity reference, since both name a record by its operator-visible key.
     *
     * @param   mixed            $value  Declared default, as the definition carries it.
     * @param   FieldDefinition  $field  Field supplying its declared length, when it has one.
     *
     * @return  bool  True when the value is a bounded, printable reference key.
     *
     * @since   2.0.0
     */
    private function referenceDefault(mixed $value, FieldDefinition $field): bool
    {
        return is_string($value)
            && $this->stringDefault($value, min($field->length ?? 191, 191))
            && $value !== ''
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    /**
     * Decide whether a default is a string inside a character budget.
     *
     * Length is counted in UTF-8 characters rather than bytes, which is the unit a field's declared length is
     * expressed in, so a default written in a non-Latin script is measured the same way as an ASCII one.
     *
     * @param   mixed  $value    Declared default, as the definition carries it.
     * @param   int    $maximum  Largest number of characters the calling check allows.
     *
     * @return  bool  True when the value is a string no longer than the budget.
     *
     * @since   2.0.0
     */
    private function stringDefault(mixed $value, int $maximum): bool
    {
        return is_string($value) && mb_strlen($value, 'UTF-8') <= $maximum;
    }

    /**
     * Decide whether a default is an address the runtime's own email filter accepts.
     *
     * The same filter that will validate submitted values is used here, so a default cannot be a value the
     * field would refuse on a write. The length budget falls back to 320 characters when the field declares
     * none.
     *
     * @param   mixed            $value  Declared default, as the definition carries it.
     * @param   FieldDefinition  $field  Field supplying its declared length, when it has one.
     *
     * @return  bool  True when the value is a bounded, well-formed address.
     *
     * @since   2.0.0
     */
    private function emailDefault(mixed $value, FieldDefinition $field): bool
    {
        return is_string($value)
            && $this->stringDefault($value, $field->length ?? 320)
            && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Decide whether a default is a web URL this runtime will hand back to a browser.
     *
     * Passing the URL filter is not enough on its own: the scheme is read back and has to be `http` or
     * `https`, so a default cannot ship a `javascript:`, `data:` or `file:` URL into whatever renders the
     * field. The length budget falls back to 4096 characters when the field declares none.
     *
     * @param   mixed            $value  Declared default, as the definition carries it.
     * @param   FieldDefinition  $field  Field supplying its declared length, when it has one.
     *
     * @return  bool  True when the value is a bounded HTTP or HTTPS URL.
     *
     * @since   2.0.0
     */
    private function urlDefault(mixed $value, FieldDefinition $field): bool
    {
        if (
            !is_string($value)
            || !$this->stringDefault($value, $field->length ?? 4096)
            || filter_var($value, FILTER_VALIDATE_URL) === false
        ) {
            return false;
        }
        $scheme = parse_url($value, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true);
    }

    /**
     * Decide whether a default is a dialable phone number.
     *
     * The form is deliberately loose — an optional leading `+`, then digits with spaces, extension markers
     * and tone characters — because numbering plans differ by country and this is a definition-time bound,
     * not a national format check. The length budget falls back to 64 characters when the field declares
     * none.
     *
     * @param   mixed            $value  Declared default, as the definition carries it.
     * @param   FieldDefinition  $field  Field supplying its declared length, when it has one.
     *
     * @return  bool  True when the value is a bounded number built from dialable characters.
     *
     * @since   2.0.0
     */
    private function phoneDefault(mixed $value, FieldDefinition $field): bool
    {
        return is_string($value)
            && $this->stringDefault($value, $field->length ?? 64)
            && preg_match('/^\+?[0-9][0-9 x#*]{2,62}$/D', $value) === 1;
    }

    /**
     * Decide whether a JSON default fits the byte budget the field configured.
     *
     * The value is measured after canonical encoding rather than as it was written, so key order and
     * whitespace cannot change whether a default fits. The budget defaults to 65,536 bytes, and a
     * `max_bytes` that is not an integer fails the default rather than being ignored.
     *
     * @param   mixed            $value  Declared default, as the definition carries it.
     * @param   FieldDefinition  $field  Field supplying the `max_bytes` budget.
     *
     * @return  bool  True when the canonical encoding is within the budget.
     *
     * @since   2.0.0
     */
    private function jsonDefault(mixed $value, FieldDefinition $field): bool
    {
        $maximum = $field->configuration['max_bytes'] ?? 65_536;
        if (!is_int($maximum)) {
            return false;
        }

        return strlen(\Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson::encode($value)) <= $maximum;
    }

    /**
     * Check the values behind the configuration keys the core field types share.
     *
     * Whether a key may appear at all was already settled against the field type's registration; this is the
     * value side, and it runs over whatever keys are present rather than switching on the field's type. Only
     * the shape of `target` is judged here — that it reads as a namespaced entity handle — because resolving
     * it to a definition needs the rest of the graph, which `validateGraph()` does in a later pass.
     *
     * @param   string                                        $field          Field handle, used to name the
     *          offending field in the failure message.
     * @param   array<string, scalar|list<scalar|null>|null>  $configuration  Declared settings, already
     *          restricted to the keys the field's type registers.
     *
     * @return  void
     *
     * @throws  InvalidBusinessDefinition  When `options` is not a bounded list of non-empty strings, the
     *          currency is not a three-letter uppercase code, the unit is not a bounded token, the target
     *          does not read as a namespaced handle, the JSON byte bound falls outside 2 to 1,000,000, or
     *          the posting date declaration is not a boolean.
     *
     * @since   2.0.0
     */
    private function validateFieldConfiguration(string $field, array $configuration): void
    {
        $options = $configuration['options'] ?? null;
        if ($options !== null) {
            if (!is_array($options) || !array_is_list($options) || $options === [] || count($options) > 256) {
                throw new InvalidBusinessDefinition('Business field ' . $field . ' has invalid options.');
            }
            foreach ($options as $option) {
                if (!is_string($option) || $option === '' || strlen($option) > 191) {
                    throw new InvalidBusinessDefinition('Business field ' . $field . ' has an invalid option.');
                }
            }
        }
        $currency = $configuration['currency'] ?? null;
        if ($currency !== null && (!is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1)) {
            throw new InvalidBusinessDefinition('Business field ' . $field . ' has an invalid ISO currency.');
        }
        $unit = $configuration['unit'] ?? null;
        if (
            $unit !== null
            && (!is_string($unit) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,31}$/D', $unit) !== 1)
        ) {
            throw new InvalidBusinessDefinition('Business field ' . $field . ' has an invalid unit.');
        }
        $target = $configuration['target'] ?? null;
        if (
            $target !== null && (!is_string($target)
            || preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)+$/D', $target) !== 1)
        ) {
            throw new InvalidBusinessDefinition('Business field ' . $field . ' has an invalid entity target.');
        }
        $maxBytes = $configuration['max_bytes'] ?? null;
        if ($maxBytes !== null && (!is_int($maxBytes) || $maxBytes < 2 || $maxBytes > 1_000_000)) {
            throw new InvalidBusinessDefinition('Business field ' . $field . ' has an invalid JSON byte bound.');
        }
        $postingDate = $configuration['posting_date'] ?? null;
        if ($postingDate !== null && !is_bool($postingDate)) {
            throw new InvalidBusinessDefinition(
                'Business field ' . $field . ' has an invalid posting date declaration.',
            );
        }
    }

    /**
     * Refuse an ownership graph in which an entity can transitively own itself.
     *
     * Owned lines are deleted with their owner, so a cycle would leave the cascade the schema compiler emits
     * for it with no base case. Both spellings of ownership contribute edges — a `core.ordered_lines` field
     * and a relationship declared as an owned line collection — so the two cannot be alternated to hide a
     * loop from either check alone. The walk is depth-first, and a handle re-entered while it is still on the
     * stack is the cycle.
     *
     * @param   array<string, list<string>>  $edges  Owned targets for each owning handle; a handle that owns
     *          nothing is simply absent.
     *
     * @return  void
     *
     * @throws  InvalidBusinessDefinition  When following owned collections returns to a handle already being
     *          walked.
     *
     * @since   2.0.0
     */
    private function assertAcyclicOwnership(array $edges): void
    {
        $visiting = [];
        $visited = [];
        $walk = function (string $handle) use (&$walk, &$visiting, &$visited, $edges): void {
            if (isset($visiting[$handle])) {
                throw new InvalidBusinessDefinition('An owned relationship cycle was detected at ' . $handle . '.');
            }
            if (isset($visited[$handle])) {
                return;
            }
            $visiting[$handle] = true;
            foreach ($edges[$handle] ?? [] as $target) {
                $walk($target);
            }
            unset($visiting[$handle]);
            $visited[$handle] = true;
        };
        foreach (array_keys($edges) as $handle) {
            $walk($handle);
        }
    }
}
