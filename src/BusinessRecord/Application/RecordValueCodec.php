<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use Kumwe\App\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\App\BusinessDefinition\Domain\ComputationMode;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\App\BusinessDefinition\Domain\IdentityStrategy;
use Kumwe\App\BusinessRecord\Domain\RecordValueGuard;
use Kumwe\App\BusinessSchema\Domain\PhysicalColumnBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalTableBlueprint;
use Kumwe\Conversion\Decimal\ExactDecimal;
use Kumwe\Conversion\Value\MoneyValue;
use Kumwe\Conversion\Value\QuantityValue;
use Kumwe\Extension\Spi\BusinessRecord\Value\ZonedDateTimeValue;
use Kumwe\Secret\Contract\EnvelopeCipher;
use Kumwe\Secret\Value\EncryptedEnvelope;
use Kumwe\Sequence\Value\NumberSequenceFormat;
use Normalizer;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Two-way translation between business-record field values and the portable columns that store them.
 *
 * Every value crossing the record boundary passes through here: `normalize()` settles what a submitted
 * value must become for its declared field type, `encodeColumns()` spreads normalized values across the
 * physical columns a `PhysicalTableBlueprint` installed, and `decodeColumns()` rebuilds field values from
 * a fetched row. The guarantees are representational rather than policy: a PHP float is refused outright
 * so decimal, money, and quantity fields keep every digit they promised, lengths and formats are bounded
 * before a value reaches a driver, and a secret is sealed through `EnvelopeCipher` on the way in and handed
 * back still sealed on the way out, so neither plaintext nor key ever reaches persistence. Required,
 * immutable, and read-only rules belong to `RecordRuleValidator`; identity resolution and the keyset
 * cursor conversions live here because both are the same field-type-to-column question.
 *
 * @since  2.0.0
 */
final readonly class RecordValueCodec
{
    /**
     * Field types this codec resolves contributed, non-`core.*` type identifiers against.
     *
     * @var    FieldTypeRegistry
     * @since  2.0.0
     */
    private FieldTypeRegistry $fieldTypes;

    /**
     * Wire the codec to the cipher guarding secret fields and the registry of known field types.
     *
     * @param  EnvelopeCipher      $secrets     Cipher sealing every `core.secret` value before storage.
     * @param  ?FieldTypeRegistry  $fieldTypes  Registry resolving contributed field types; null builds one
     *         seeded with the core built-ins.
     *
     * @since  2.0.0
     */
    public function __construct(private EnvelopeCipher $secrets, ?FieldTypeRegistry $fieldTypes = null)
    {
        $this->fieldTypes = $fieldTypes ?? new FieldTypeRegistry();
    }

    /**
     * Settle the caller-facing identity of a record from its declared identity field.
     *
     * A UUID definition lowercases whatever it is given and mints a UUIDv7 when neither the request nor
     * the submitted values name one, so a create needs no identity at all. A reference-identity
     * definition has no such fallback: the identity is authored, runs through the field's normalizers,
     * and must fit the field's length. Where a requested record id and an identity-field value both
     * arrive they must agree, which is what stops a URL and a payload from naming different records.
     *
     * @param   EntityTypeDefinition  $definition         Definition whose strategy decides the rules.
     * @param   array<string, mixed>  $input              Submitted field values keyed by handle.
     * @param   ?string               $requestedRecordId  Identity named by the request, or null.
     *
     * @return  string  Lowercased UUID, or the normalized reference identity; never empty.
     *
     * @throws  InvalidArgumentException  When the identity is not a string, is an invalid UUID, is absent
     *          or over length for a reference identity, or disagrees with the requested record id.
     *
     * @since   2.0.0
     */
    public function identity(
        EntityTypeDefinition $definition,
        array $input,
        ?string $requestedRecordId,
    ): string {
        $field = $this->identityField($definition);
        $provided = $input[$field->handle] ?? null;
        if ($provided !== null && !is_string($provided)) {
            throw new InvalidArgumentException('A business-record identity must be a string.');
        }
        if ($field->type === 'core.uuid') {
            $normalizedProvided = is_string($provided) ? strtolower($provided) : null;
            $normalizedRequested = $requestedRecordId !== null ? strtolower($requestedRecordId) : null;
            $identity = $normalizedRequested ?? $normalizedProvided ?? Uuid::uuid7()->toString();
            if (!Uuid::isValid($identity)) {
                throw new InvalidArgumentException('A UUID business-record identity is invalid.');
            }
        } else {
            $normalizedProvided = is_string($provided) ? $this->normalizeString($provided, $field) : null;
            $normalizedRequested = $requestedRecordId !== null
                ? $this->normalizeString($requestedRecordId, $field)
                : null;
            $identity = $normalizedRequested ?? $normalizedProvided;
            if (!is_string($identity) || $identity === '') {
                throw new InvalidArgumentException('A reference-identity record requires an explicit identity.');
            }
            if (strlen($identity) > ($field->length ?? 191)) {
                throw new InvalidArgumentException('A reference identity exceeds its configured length.');
            }
        }
        if (
            isset($normalizedProvided, $normalizedRequested)
            && !hash_equals($normalizedRequested, $normalizedProvided)
        ) {
            throw new InvalidArgumentException('The record ID and declared identity field disagree.');
        }

        return $identity;
    }

    /**
     * Convert one submitted value into the representation its field type stores.
     *
     * Null passes straight through, and a PHP float is refused before anything else so that no exact
     * field can quietly lose digits. The field's declared normalizers run first, then the value is
     * admitted against its type. `core.ordered_lines` is refused outright, because owned lines are
     * written by the relationship and reorder commands rather than as a field value.
     *
     * @param   FieldDefinition  $field           Field contract the value is admitted against.
     * @param   mixed            $value           Submitted value, or null.
     * @param   string           $siteIdentifier  Site owning the record; only used to bind a secret.
     * @param   string           $definitionId    Definition UUID; only used to bind a secret.
     * @param   string           $recordId        Record identity; only used to bind a secret.
     *
     * @return  mixed  Null, a scalar, or the domain value object the type stores, such as `ExactDecimal`,
     *          `MoneyValue`, `ZonedDateTimeValue`, `DateTimeImmutable`, or a sealed `EncryptedEnvelope`.
     *
     * @throws  InvalidArgumentException  When the value is a float, breaks the field's type, length,
     *          format, or option rules, or the field names a normalizer this codec does not implement.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When a contributed field
     *          type is not registered in this process.
     *
     * @since   2.0.0
     */
    public function normalize(
        FieldDefinition $field,
        mixed $value,
        string $siteIdentifier,
        string $definitionId,
        string $recordId,
    ): mixed {
        if ($value === null) {
            return null;
        }
        if (is_float($value)) {
            throw new InvalidArgumentException('Business-record values cannot use PHP floats.');
        }
        $value = $this->applyNormalizers($value, $field);

        return match ($field->type) {
            'core.uuid', 'core.media_reference' => $this->uuid($value),
            'core.entity_reference' => $this->referenceIdentity($value, $field),
            'core.reference_identity', 'core.text', 'core.rich_text' =>
                $this->boundedString($value, $field->length ?? ($field->type === 'core.rich_text' ? 1_000_000 : 191)),
            'core.sequence' => $this->boundedString($value, NumberSequenceFormat::MAXIMUM_LENGTH),
            'core.computed' => $this->computed($value, $field),
            'core.integer' => $this->integer($value),
            'core.decimal' => $this->decimal($value, $field),
            'core.money' => $this->money($value, $field),
            'core.quantity' => $this->quantity($value, $field),
            'core.boolean' => $this->boolean($value),
            'core.enum' => $this->enumeration($value, $field),
            'core.date' => $this->date($value),
            'core.local_time' => $this->time($value),
            'core.instant' => $this->instant($value),
            'core.zoned_datetime' => $this->zonedDateTime($value),
            'core.email' => $this->email($value, $field),
            'core.url' => $this->url($value, $field),
            'core.phone' => $this->phone($value, $field),
            'core.embedded_value', 'core.bounded_json' => $this->boundedJson($value, $field),
            'core.secret' => $this->secret($value, $siteIdentifier, $definitionId, $recordId, $field->handle),
            'core.ordered_lines' => throw new InvalidArgumentException(
                'Ordered lines must be changed through relationship and reorder commands.',
            ),
            default => $this->custom($value, $field),
        };
    }

    /**
     * Spread a record's normalized values across the physical columns that hold them.
     *
     * A field is skipped when the submitted values do not mention it, when it is the UUID identity whose
     * value already lives in the table's record key, when it is a virtual computation with no column of
     * its own, or when the installed table carries no column for it. Composite types fan out: money,
     * quantity, zoned date-time, and secret fields each write several columns from one value.
     *
     * @param   EntityTypeDefinition    $definition  Definition naming the fields, in declared order.
     * @param   PhysicalTableBlueprint  $table       Installed table the logical names resolve against.
     * @param   array<string, mixed>    $values
     *
     * @return  array<string, mixed>  Physical column names to DBAL values.
     *
     * @throws  InvalidArgumentException  When a value is not the domain type its field normalizes to.
     *
     * @since   2.0.0
     */
    public function encodeColumns(
        EntityTypeDefinition $definition,
        PhysicalTableBlueprint $table,
        array $values,
    ): array {
        return $this->encodePlanned($this->encodingPlan($definition, $table), $values);
    }

    /**
     * Compile every per-field encoding decision one (definition, table) pair implies, once.
     *
     * The skip rules and the logical-to-physical column resolution depend only on the definition and the
     * installed table, never on a row, so a command writing a whole collection compiles this once and
     * pays per row only for converting values. `encodeColumns()` delegates through the same plan, which
     * is what keeps the single-row and bulk paths one implementation instead of two that agree today.
     *
     * @param   EntityTypeDefinition    $definition  Definition naming the fields, in declared order.
     * @param   PhysicalTableBlueprint  $table       Installed table the logical names resolve against.
     *
     * @return  RecordColumnEncodingPlan  The writable fields with their resolved storage columns.
     *
     * @since   2.0.0
     */
    public function encodingPlan(
        EntityTypeDefinition $definition,
        PhysicalTableBlueprint $table,
    ): RecordColumnEncodingPlan {
        $fields = [];
        foreach ($definition->fields() as $field) {
            if (
                ($field === $this->identityField($definition)
                    && $definition->identityStrategy === IdentityStrategy::Uuid)
                || ($field->formula !== null && $field->computationMode === ComputationMode::Virtual)
                || $this->columns($table, $field) === []
            ) {
                continue;
            }
            $columns = [];
            foreach ($this->columns($table, $field) as $column) {
                $columns[$column->logicalName] = $column->physicalName;
            }
            $fields[] = new PlannedFieldEncoding($field, $columns);
        }

        return new RecordColumnEncodingPlan($fields);
    }

    /**
     * Spread one row's values across physical columns using an already-compiled plan.
     *
     * @param   RecordColumnEncodingPlan  $plan    Compiled decisions for the definition and table.
     * @param   array<string, mixed>      $values  Normalized values keyed by field handle.
     *
     * @return  array<string, mixed>  Physical column names to DBAL values.
     *
     * @throws  InvalidArgumentException  When a value is not the domain type its field normalizes to.
     *
     * @since   2.0.0
     */
    public function encodePlanned(RecordColumnEncodingPlan $plan, array $values): array
    {
        $encoded = [];
        foreach ($plan->fields as $planned) {
            if (!array_key_exists($planned->field->handle, $values)) {
                continue;
            }
            foreach ($this->storageValues($planned->field, $values[$planned->field->handle]) as $logical => $value) {
                $physical = $planned->columns[$logical] ?? null;
                if ($physical !== null) {
                    $encoded[$physical] = $value;
                }
            }
        }

        return $encoded;
    }

    /**
     * Rebuild a record's field values from one fetched row.
     *
     * The inverse of `encodeColumns()`. A UUID definition takes its identity from the record key rather
     * than from a column, ordered-line and virtual computed fields are skipped, and a field whose every
     * physical column came back NULL decodes to null. Secret fields are returned as sealed
     * `EncryptedEnvelope` values — nothing is decrypted here — so the site and record coordinates are
     * carried for symmetry with the encode path rather than consumed on this side.
     *
     * @param   EntityTypeDefinition    $definition      Definition naming the fields to rebuild.
     * @param   PhysicalTableBlueprint  $table           Installed table the row was fetched from.
     * @param   array<string, mixed>    $row
     * @param   string                  $siteIdentifier  Site owning the record.
     * @param   string                  $recordKey       Storage key of the row, and a UUID identity.
     *
     * @return  array<string, mixed>  Field values keyed by handle; a handle is absent when the row
     *          selected none of its columns.
     *
     * @throws  InvalidArgumentException  When a stored value contradicts the physical type declared for
     *          its column, or a component of a composite field is missing from the row.
     * @throws  \DateMalformedStringException  When a stored date-time column holds an unparsable string.
     *
     * @since   2.0.0
     */
    public function decodeColumns(
        EntityTypeDefinition $definition,
        PhysicalTableBlueprint $table,
        array $row,
        string $siteIdentifier,
        string $recordKey,
    ): array {
        $values = [];
        $identity = $this->identityField($definition);
        if ($definition->identityStrategy === IdentityStrategy::Uuid) {
            $values[$identity->handle] = $recordKey;
        }
        foreach ($definition->fields() as $field) {
            if (
                ($field === $identity && $definition->identityStrategy === IdentityStrategy::Uuid)
                || $field->type === 'core.ordered_lines'
                || ($field->formula !== null && $field->computationMode === ComputationMode::Virtual)
            ) {
                continue;
            }
            $columns = $this->columns($table, $field);
            if ($columns === []) {
                continue;
            }
            $present = false;
            foreach ($columns as $column) {
                $present = $present || array_key_exists($column->physicalName, $row);
            }
            if (!$present) {
                continue;
            }
            $storage = [];
            $allNull = true;
            foreach ($columns as $column) {
                $item = $this->decodePhysical($column, $row[$column->physicalName] ?? null);
                $storage[$column->logicalName] = $item;
                $allNull = $allNull && $item === null;
            }
            $values[$field->handle] = $allNull
                ? null
                : $this->fromStorage($field, $storage, $siteIdentifier, $definition->id, $recordKey);
        }

        return $values;
    }

    /**
     * Convert a stored column value into the portable form a keyset cursor carries.
     *
     * A cursor is handed to the client and comes back later, so every sort value has to survive a JSON
     * round trip: temporal columns become canonical strings, bigints and decimals keep their exact
     * spelling instead of becoming floats, and a binary or structured column is refused rather than
     * truncated, which is what keeps such fields out of sortable positions.
     *
     * @param   PhysicalColumnBlueprint  $column  Column read from; its Doctrine type picks the rule.
     * @param   mixed                    $value   Raw driver value from the last row of the page.
     *
     * @return  mixed  Null when the column was NULL, otherwise a bool, int, or string safe to embed in
     *          a cursor payload.
     *
     * @throws  InvalidArgumentException  When the stored value contradicts the column's declared type,
     *          or that column type cannot appear in a cursor at all.
     * @throws  \DateMalformedStringException  When a stored date-time column holds an unparsable string.
     *
     * @since   2.0.0
     */
    public function cursorValue(PhysicalColumnBlueprint $column, mixed $value): mixed
    {
        $decoded = $this->decodePhysical($column, $value);
        if ($decoded === null) {
            return null;
        }

        return match ($column->doctrineType) {
            'boolean', 'integer', 'smallint' => $decoded,
            'bigint' => $this->cursorBigint($decoded),
            'decimal' => $this->cursorDecimal($decoded),
            'ascii_string', 'guid', 'string', 'text' => is_string($decoded)
                ? $decoded
                : throw new InvalidArgumentException('A stored textual cursor value is invalid.'),
            'date_immutable' => $this->cursorDateTime($decoded)->format('Y-m-d'),
            'time_immutable' => $this->cursorDateTime($decoded)->format('H:i:s.u'),
            'datetime_immutable', 'datetimetz_immutable' => $this->cursorDateTime($decoded)
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.u\Z'),
            default => throw new InvalidArgumentException(
                'A binary or structured physical field cannot be used in a keyset cursor.',
            ),
        };
    }

    /**
     * Convert a value read back out of a cursor into the form its column is bound with.
     *
     * The inverse of `cursorValue()`. A cursor is signed but still arrives from outside, so each value is
     * re-admitted through the same rules a submitted value faces before it becomes a query parameter.
     *
     * @param   PhysicalColumnBlueprint  $column  Column the value will be compared against.
     * @param   mixed                    $value   Sort value decoded from the cursor, or null.
     *
     * @return  mixed  Null when the cursor carried null, otherwise the bool, int, string, or
     *          `DateTimeImmutable` that DBAL binds for that column.
     *
     * @throws  InvalidArgumentException  When the value does not fit the column's declared type, or that
     *          column type cannot appear in a cursor at all.
     *
     * @since   2.0.0
     */
    public function cursorStorageValue(PhysicalColumnBlueprint $column, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($column->doctrineType) {
            'boolean' => $this->boolean($value),
            'integer', 'smallint' => $this->integer($value),
            'bigint' => $this->cursorBigint($value),
            'decimal' => $this->cursorDecimal($value),
            'ascii_string', 'string', 'text' => is_string($value)
                ? $value
                : throw new InvalidArgumentException('A textual cursor value is invalid.'),
            'guid' => $this->uuid($value),
            'date_immutable' => $this->date($value),
            'time_immutable' => $this->time($value),
            'datetime_immutable', 'datetimetz_immutable' => $this->instant($value),
            default => throw new InvalidArgumentException(
                'A binary or structured physical field cannot be restored from a keyset cursor.',
            ),
        };
    }

    /**
     * Report the identity a caller sees for a record that has just been decoded.
     *
     * A UUID definition stores its identity as the record key itself, so the decoded values are not
     * consulted at all. A reference-identity definition keeps it in a field of its own, and a row that
     * decoded no usable value there is treated as corrupt rather than as a record without an identity.
     *
     * @param   EntityTypeDefinition  $definition  Definition whose strategy decides the source.
     * @param   string                $recordKey   Storage key of the row.
     * @param   array<string, mixed>  $values
     *
     * @return  string  Identity to hand back to callers; never empty.
     *
     * @throws  InvalidArgumentException  When a reference-identity record decoded no usable identity.
     *
     * @since   2.0.0
     */
    public function publicIdentity(
        EntityTypeDefinition $definition,
        string $recordKey,
        array $values,
    ): string {
        if ($definition->identityStrategy === IdentityStrategy::Uuid) {
            return $recordKey;
        }
        $identity = $this->identityField($definition);
        $value = $values[$identity->handle] ?? null;
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException('A decoded record has no public reference identity.');
        }

        return $value;
    }

    /**
     * Run a field's normalizers over a string and keep the result a string.
     *
     * The normalizer pipeline is typed to accept any value, so its result is narrowed again here before
     * an identity is built out of it.
     *
     * @param   string           $value  Text to normalize.
     * @param   FieldDefinition  $field  Field whose declared normalizers are applied.
     *
     * @return  string  The normalized text.
     *
     * @throws  InvalidArgumentException  When a normalizer is unknown, or leaves a non-string behind.
     *
     * @since   2.0.0
     */
    private function normalizeString(string $value, FieldDefinition $field): string
    {
        $normalized = $this->applyNormalizers($value, $field);
        return is_string($normalized)
            ? $normalized
            : throw new InvalidArgumentException('String normalization produced a non-string value.');
    }

    /**
     * Run a field's declared normalizers over a submitted value, in declaration order.
     *
     * `decimal_scale` is an assertion rather than a transformation: it only insists the value arrived as
     * an exact type, so a float can never be rounded into a decimal field. Every other normalizer is
     * textual and demands a string, which is why a mistyped value fails here rather than later.
     *
     * @param   mixed            $value  Submitted value, before any type admission.
     * @param   FieldDefinition  $field  Field carrying the normalizer list.
     *
     * @return  mixed  The value after every normalizer has run, unchanged when the field declares none.
     *
     * @throws  InvalidArgumentException  When `decimal_scale` meets an inexact value, a text normalizer
     *          meets a non-string, phone normalization fails, or a normalizer is not implemented here.
     *
     * @since   2.0.0
     */
    private function applyNormalizers(mixed $value, FieldDefinition $field): mixed
    {
        foreach ($field->normalizers as $normalizer) {
            if ($normalizer === 'decimal_scale') {
                if (!is_string($value) && !is_int($value) && !$value instanceof ExactDecimal) {
                    throw new InvalidArgumentException('Decimal-scale normalization requires an exact value.');
                }
                continue;
            }
            if (!is_string($value)) {
                throw new InvalidArgumentException('A text normalizer requires a string value.');
            }
            $value = match ($normalizer) {
                'trim' => trim($value),
                'lowercase' => mb_strtolower($value, 'UTF-8'),
                'uppercase' => mb_strtoupper($value, 'UTF-8'),
                'unicode_nfc' => $this->unicodeNfc($value),
                'email' => mb_strtolower(trim($value), 'UTF-8'),
                'url' => trim($value),
                'phone' => preg_replace('/[\s().-]+/u', '', trim($value))
                    ?? throw new InvalidArgumentException('Phone normalization failed.'),
                default => throw new InvalidArgumentException(
                    'An unregistered business-field normalizer was requested.',
                ),
            };
        }

        return $value;
    }

    /**
     * Fold a string into Unicode normalization form C.
     *
     * Composed form is what makes two visually identical strings compare, hash, and index as one value,
     * so an identity or unique field agrees with the database rather than with the keystrokes typed.
     *
     * @param   string  $value  Text in any Unicode form.
     *
     * @return  string  The composed form of the input.
     *
     * @throws  InvalidArgumentException  When the intl normalizer cannot process the value.
     *
     * @since   2.0.0
     */
    private function unicodeNfc(string $value): string
    {
        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);
        if (!is_string($normalized)) {
            throw new InvalidArgumentException('Unicode normalization failed.');
        }

        return $normalized;
    }

    /**
     * Admit a canonical UUID and fold it to lowercase.
     *
     * @param   mixed  $value  Submitted reference value.
     *
     * @return  string  The UUID in lowercase, the spelling every stored key uses.
     *
     * @throws  InvalidArgumentException  When the value is not a string, or is not a valid UUID.
     *
     * @since   2.0.0
     */
    private function uuid(mixed $value): string
    {
        if (!is_string($value) || !Uuid::isValid($value)) {
            throw new InvalidArgumentException('A business-record reference must be a canonical UUID.');
        }

        return strtolower($value);
    }

    /**
     * Admit a string no longer than the character budget its field declares.
     *
     * Length is counted in UTF-8 characters rather than bytes, so the limit means the same thing to an
     * author whatever alphabet they write in.
     *
     * @param   mixed  $value  Submitted value.
     * @param   int    $limit  Largest number of UTF-8 characters accepted.
     *
     * @return  string  The value unchanged.
     *
     * @throws  InvalidArgumentException  When the value is not a string, or exceeds the limit.
     *
     * @since   2.0.0
     */
    private function boundedString(mixed $value, int $limit): string
    {
        if (!is_string($value) || mb_strlen($value, 'UTF-8') > $limit) {
            throw new InvalidArgumentException('A business-record string value has an invalid type or length.');
        }

        return $value;
    }

    /**
     * Admit the identity one record uses to point at another.
     *
     * The value is bounded to 191 characters however much the field declares, which is the widest
     * identity a reference may carry, and control characters are refused so a stored pointer cannot
     * smuggle terminators into a log line or a URL.
     *
     * @param   mixed            $value  Submitted reference identity.
     * @param   FieldDefinition  $field  Field declaring the length budget.
     *
     * @return  string  The bounded identity, unchanged.
     *
     * @throws  InvalidArgumentException  When the value is not a bounded string, is empty, or carries
     *          control characters.
     *
     * @since   2.0.0
     */
    private function referenceIdentity(mixed $value, FieldDefinition $field): string
    {
        $value = $this->boundedString($value, min($field->length ?? 191, 191));
        if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('An entity-reference identity is empty or contains controls.');
        }

        return $value;
    }

    /**
     * Admit an integer every supported engine can hold in a 32-bit column.
     *
     * @param   mixed  $value  Submitted value.
     *
     * @return  int  The value unchanged.
     *
     * @throws  InvalidArgumentException  When the value is not an integer, or falls outside the portable
     *          signed 32-bit range.
     *
     * @since   2.0.0
     */
    private function integer(mixed $value): int
    {
        if (!is_int($value) || $value < -2_147_483_648 || $value > 2_147_483_647) {
            throw new InvalidArgumentException('An integer field requires a portable signed 32-bit PHP integer.');
        }

        return $value;
    }

    /**
     * Admit an exact base-10 value at the precision and scale its field declares.
     *
     * An `ExactDecimal` carrying a different precision or scale is refused rather than re-scaled, because
     * rescaling is the one operation that could drop a digit the field promised to keep. Integers and
     * string literals are canonicalised through the `ExactDecimal` factories; floats never arrive here,
     * having been refused by `normalize()`.
     *
     * @param   mixed            $value  An `ExactDecimal`, an integer, or a decimal literal.
     * @param   FieldDefinition  $field  Field declaring the precision and scale.
     *
     * @return  ExactDecimal  The canonical value, padded to the field scale.
     *
     * @throws  InvalidArgumentException  When an exact value carries a different precision or scale, the
     *          value is neither string nor integer, the field declares no precision or scale, or the
     *          literal does not fit the declared budget.
     *
     * @since   2.0.0
     */
    private function decimal(mixed $value, FieldDefinition $field): ExactDecimal
    {
        if ($value instanceof ExactDecimal) {
            if ($value->precision !== $field->precision || $value->scale !== $field->scale) {
                throw new InvalidArgumentException('An exact decimal uses a different field precision or scale.');
            }
            return $value;
        }
        if (is_int($value)) {
            return ExactDecimal::fromInt($value, $this->precision($field), $this->scale($field));
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('An exact decimal requires a string or integer, never a float.');
        }

        return ExactDecimal::fromString($value, $this->precision($field), $this->scale($field));
    }

    /**
     * Admit a money value as an amount and currency pair.
     *
     * A `MoneyValue` is taken as already settled. An object form must carry exactly `amount` and
     * `currency`, so a payload that forgets the currency or smuggles an extra key is refused instead of
     * half stored; its code is uppercased, and where the field pins a currency the two must match.
     *
     * @param   mixed            $value  A `MoneyValue`, or an object with `amount` and `currency`.
     * @param   FieldDefinition  $field  Field declaring precision, scale, and any pinned currency.
     *
     * @return  MoneyValue  The pair, with its amount canonicalised to the field scale.
     *
     * @throws  InvalidArgumentException  When the properties are missing, extra, or mistyped, the amount
     *          is not exact, or the currency is not an ISO 4217 code or is not the one the field pins.
     *
     * @since   2.0.0
     */
    private function money(mixed $value, FieldDefinition $field): MoneyValue
    {
        if ($value instanceof MoneyValue) {
            return $value;
        }
        if (
            !is_array($value) || array_is_list($value)
            || array_diff(array_keys($value), ['amount', 'currency']) !== []
            || array_diff(['amount', 'currency'], array_keys($value)) !== []
        ) {
            throw new InvalidArgumentException('A money field requires exact amount and currency properties.');
        }
        $currency = $value['currency'];
        if (!is_string($currency)) {
            throw new InvalidArgumentException('A money currency must be a string.');
        }
        $money = new MoneyValue($this->decimal($value['amount'], $field), strtoupper($currency));
        $configured = $field->configuration['currency'] ?? null;
        if (is_string($configured) && !hash_equals(strtoupper($configured), $money->currency)) {
            throw new InvalidArgumentException('A money currency differs from the field currency.');
        }

        return $money;
    }

    /**
     * Admit a quantity as an amount and unit pair.
     *
     * A `QuantityValue` is taken as already settled. An object form must carry exactly `amount` and
     * `unit`, so a bare number can never be stored as though its unit were obvious, and where the field
     * pins a unit the two must match exactly, case included, because unit symbols are case bearing.
     *
     * @param   mixed            $value  A `QuantityValue`, or an object with `amount` and `unit`.
     * @param   FieldDefinition  $field  Field declaring precision, scale, and any pinned unit.
     *
     * @return  QuantityValue  The pair, with its amount canonicalised to the field scale.
     *
     * @throws  InvalidArgumentException  When the properties are missing, extra, or mistyped, the amount
     *          is not exact, or the unit is not a portable identifier or is not the one the field pins.
     *
     * @since   2.0.0
     */
    private function quantity(mixed $value, FieldDefinition $field): QuantityValue
    {
        if ($value instanceof QuantityValue) {
            return $value;
        }
        if (
            !is_array($value) || array_is_list($value)
            || array_diff(array_keys($value), ['amount', 'unit']) !== []
            || array_diff(['amount', 'unit'], array_keys($value)) !== []
        ) {
            throw new InvalidArgumentException('A quantity field requires exact amount and unit properties.');
        }
        if (!is_string($value['unit'])) {
            throw new InvalidArgumentException('A quantity unit must be a string.');
        }
        $quantity = new QuantityValue($this->decimal($value['amount'], $field), $value['unit']);
        $configured = $field->configuration['unit'] ?? null;
        if (is_string($configured) && !hash_equals($configured, $quantity->unit)) {
            throw new InvalidArgumentException('A quantity unit differs from the field unit.');
        }

        return $quantity;
    }

    /**
     * Admit a boolean without coercing the strings and integers a caller might offer instead.
     *
     * @param   mixed  $value  Submitted value.
     *
     * @return  bool  The value unchanged.
     *
     * @throws  InvalidArgumentException  When the value is not a PHP boolean.
     *
     * @since   2.0.0
     */
    private function boolean(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new InvalidArgumentException('A boolean field requires a PHP boolean.');
        }

        return $value;
    }

    /**
     * Admit a value the field's declared option list contains.
     *
     * Membership is compared strictly, so a digit string never matches an integer option.
     *
     * @param   mixed            $value  Submitted option value.
     * @param   FieldDefinition  $field  Field whose `options` configuration lists what is allowed.
     *
     * @return  string  The chosen option, unchanged.
     *
     * @throws  InvalidArgumentException  When the value is not a bounded string, or is outside the
     *          declared options.
     *
     * @since   2.0.0
     */
    private function enumeration(mixed $value, FieldDefinition $field): string
    {
        $value = $this->boundedString($value, $field->length ?? 191);
        $options = $field->configuration['options'] ?? [];
        if (!is_array($options) || !in_array($value, $options, true)) {
            throw new InvalidArgumentException('An enum value is outside its declared options.');
        }

        return $value;
    }

    /**
     * Admit a calendar date carrying no time of day.
     *
     * A `DateTimeImmutable` is accepted only when it sits exactly at midnight, and a string must be
     * canonical `YYYY-MM-DD` — a shape such as `2026-2-3` is refused rather than repaired, so what is
     * stored is always what was written. The year is held to 1000-9999, the range every supported engine
     * stores identically.
     *
     * @param   mixed  $value  Submitted date.
     *
     * @return  DateTimeImmutable  The day at midnight; a parsed string is built in UTC.
     *
     * @throws  InvalidArgumentException  When the value is neither a midnight instant nor a canonical
     *          date string, or its year is outside the portable range.
     *
     * @since   2.0.0
     */
    private function date(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable && $value->format('H:i:s.u') === '00:00:00.000000') {
            if ($this->portableYear($value)) {
                return $value;
            }
            throw new InvalidArgumentException('A date field is outside the portable database year range.');
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('A date field requires YYYY-MM-DD.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value || !$this->portableYear($date)) {
            throw new InvalidArgumentException('A date field requires a valid portable canonical YYYY-MM-DD value.');
        }

        return $date;
    }

    /**
     * Admit a local time of day, carrying no date and no zone.
     *
     * A `DateTimeImmutable` is taken as given. A string must be canonical `HH:MM:SS`, optionally with
     * microseconds, and is re-rendered and compared against what arrived, so a shape such as `9:05:00`
     * is refused rather than repaired.
     *
     * @param   mixed  $value  Submitted time.
     *
     * @return  DateTimeImmutable  Instant carrying the time of day; only its time part is stored.
     *
     * @throws  InvalidArgumentException  When the value is neither an instant nor a canonical time
     *          string.
     *
     * @since   2.0.0
     */
    private function time(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('A local-time field requires a canonical time string.');
        }
        $format = str_contains($value, '.') ? '!H:i:s.u' : '!H:i:s';
        $time = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
        if (!$time instanceof DateTimeImmutable || $time->format(ltrim($format, '!')) !== $value) {
            throw new InvalidArgumentException('A local-time field requires HH:MM:SS with optional microseconds.');
        }

        return $time;
    }

    /**
     * Admit a point in time and express it in UTC.
     *
     * A string must be RFC 3339 with a `Z` or `+00:00` offset, checked before parsing so a local-looking
     * timestamp is never reinterpreted against the server's zone. Parser warnings count as failures,
     * because they are how an impossible date such as a 31st of February arrives looking successful.
     *
     * @param   mixed  $value  Submitted instant.
     *
     * @return  DateTimeImmutable  The same moment, carried in UTC.
     *
     * @throws  InvalidArgumentException  When the value is neither an instant nor an RFC 3339 UTC
     *          string, the parse raised warnings, or the year is outside the portable range.
     *
     * @since   2.0.0
     */
    private function instant(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            if (!$this->portableYear($value)) {
                throw new InvalidArgumentException('An instant field is outside the portable database year range.');
            }

            return $value->setTimezone(new DateTimeZone('UTC'));
        }
        if (
            !is_string($value) || preg_match(
                '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}'
                . '(?:\.[0-9]{1,6})?(?:Z|\+00:00)$/D',
                $value,
            ) !== 1
        ) {
            throw new InvalidArgumentException('An instant field requires an RFC 3339 UTC string.');
        }
        try {
            $instant = new DateTimeImmutable($value);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('An instant field is invalid.', 0, $exception);
        }
        $errors = DateTimeImmutable::getLastErrors();
        if (
            $instant->format('P') !== '+00:00' || !$this->portableYear($instant)
            || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
        ) {
            throw new InvalidArgumentException('An instant field must use valid portable UTC time.');
        }

        return $instant->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Report whether a year fits the range every supported engine stores identically.
     *
     * @param   DateTimeImmutable  $value  Instant whose year is examined.
     *
     * @return  bool  True when the year is between 1000 and 9999 inclusive.
     *
     * @since   2.0.0
     */
    private function portableYear(DateTimeImmutable $value): bool
    {
        $year = (int) $value->format('Y');

        return $year >= 1000 && $year <= 9999;
    }

    /**
     * Admit an instant paired with the IANA zone it was authored in.
     *
     * A `ZonedDateTimeValue` is taken as already settled; an object form must carry exactly `instant`
     * and `timezone`. Keeping the zone name rather than a fixed offset is what lets the value still be
     * rendered as its author meant after that zone changes its rules.
     *
     * @param   mixed  $value  A `ZonedDateTimeValue`, or an object with `instant` and `timezone`.
     *
     * @return  ZonedDateTimeValue  The pair, with its instant normalised to UTC.
     *
     * @throws  InvalidArgumentException  When the properties are missing, extra, or not strings, or the
     *          instant or zone name is invalid.
     *
     * @since   2.0.0
     */
    private function zonedDateTime(mixed $value): ZonedDateTimeValue
    {
        if ($value instanceof ZonedDateTimeValue) {
            return $value;
        }
        if (
            !is_array($value) || array_is_list($value)
            || array_diff(array_keys($value), ['instant', 'timezone']) !== []
            || array_diff(['instant', 'timezone'], array_keys($value)) !== []
        ) {
            throw new InvalidArgumentException('A zoned date-time requires instant and timezone properties.');
        }
        if (!is_string($value['instant']) || !is_string($value['timezone'])) {
            throw new InvalidArgumentException('A zoned date-time instant and timezone must be strings.');
        }

        return ZonedDateTimeValue::fromStrings($value['instant'], $value['timezone']);
    }

    /**
     * Admit an address the platform's email validator accepts.
     *
     * @param   mixed            $value  Submitted address.
     * @param   FieldDefinition  $field  Field declaring the length budget; 320 characters by default.
     *
     * @return  string  The address unchanged; case folding is the field's normalizer job.
     *
     * @throws  InvalidArgumentException  When the value is not a bounded string, or is not a valid
     *          address.
     *
     * @since   2.0.0
     */
    private function email(mixed $value, FieldDefinition $field): string
    {
        $value = $this->boundedString($value, $field->length ?? 320);
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('An email field requires a valid address.');
        }

        return $value;
    }

    /**
     * Admit an absolute HTTP or HTTPS URL.
     *
     * The scheme is checked separately from the validator, so a well-formed `javascript:` or `data:` URL
     * is refused here rather than stored and later rendered into a page.
     *
     * @param   mixed            $value  Submitted URL.
     * @param   FieldDefinition  $field  Field declaring the length budget; 4096 characters by default.
     *
     * @return  string  The URL unchanged.
     *
     * @throws  InvalidArgumentException  When the value is not a bounded string, is not a valid URL, or
     *          carries a scheme other than HTTP or HTTPS.
     *
     * @since   2.0.0
     */
    private function url(mixed $value, FieldDefinition $field): string
    {
        $value = $this->boundedString($value, $field->length ?? 4096);
        $parts = parse_url($value);
        if (
            filter_var($value, FILTER_VALIDATE_URL) === false || !is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
        ) {
            throw new InvalidArgumentException('A URL field requires an absolute HTTP or HTTPS URL.');
        }

        return $value;
    }

    /**
     * Admit a telephone number in the portable shape the normalizers leave behind.
     *
     * The pattern deliberately accepts an optional leading `+`, digits, spaces, and the `x`, `#`, and `*`
     * an extension needs, rather than trying to be a dialling-plan validator.
     *
     * @param   mixed            $value  Submitted number.
     * @param   FieldDefinition  $field  Field declaring the length budget; 64 characters by default.
     *
     * @return  string  The number unchanged.
     *
     * @throws  InvalidArgumentException  When the value is not a bounded string, or does not match the
     *          portable shape.
     *
     * @since   2.0.0
     */
    private function phone(mixed $value, FieldDefinition $field): string
    {
        $value = $this->boundedString($value, $field->length ?? 64);
        if (preg_match('/^\+?[0-9][0-9 x#*]{2,62}$/D', $value) !== 1) {
            throw new InvalidArgumentException('A phone field has an invalid portable shape.');
        }

        return $value;
    }

    /**
     * Admit a structured value and hold its canonical JSON to a byte budget.
     *
     * `RecordValueGuard` decides what may appear at all — floats and unsupported objects are refused —
     * and its canonical spelling is what gets measured and returned, so the budget covers the bytes that
     * actually reach the column rather than the shape that was submitted.
     *
     * @param   mixed            $value  Submitted structure.
     * @param   FieldDefinition  $field  Field whose `max_bytes` configuration sets the budget.
     *
     * @return  mixed  The canonical spelling of the value, ready to be stored as JSON.
     *
     * @throws  InvalidArgumentException  When the value is not admissible, cannot be encoded, the
     *          configured budget is not a usable byte count, or the canonical JSON exceeds it.
     *
     * @since   2.0.0
     */
    private function boundedJson(mixed $value, FieldDefinition $field): mixed
    {
        RecordValueGuard::assertValue($value);
        $canonical = RecordValueGuard::canonical($value);
        try {
            $json = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('A bounded JSON value cannot be encoded.', 0, $exception);
        }
        $maximum = $field->configuration['max_bytes'] ?? 65_536;
        if (!is_int($maximum) || $maximum < 2 || $maximum > 1_000_000 || strlen($json) > $maximum) {
            throw new InvalidArgumentException('A bounded JSON value exceeds its configured byte limit.');
        }

        return $canonical;
    }

    /**
     * Seal a secret value into the envelope stored in its place.
     *
     * An already sealed envelope passes through untouched, which is what lets an unchanged secret survive
     * a decode, validate, and re-encode round trip without being encrypted again. Otherwise the value is
     * sealed under a binding built from the site, definition, record, and field, so an envelope copied
     * into another cell no longer authenticates.
     *
     * @param   mixed   $value           Plaintext secret, or an already sealed `EncryptedEnvelope`.
     * @param   string  $siteIdentifier  Site owning the record.
     * @param   string  $definitionId    UUID of the business definition.
     * @param   string  $recordId        Caller-facing identity of the record.
     * @param   string  $field           Handle of the secret field within that record.
     *
     * @return  EncryptedEnvelope  The sealed value; the plaintext is never returned or stored.
     *
     * @throws  InvalidArgumentException  When the value is neither a string nor an envelope.
     *
     * @since   2.0.0
     */
    private function secret(
        mixed $value,
        string $siteIdentifier,
        string $definitionId,
        string $recordId,
        string $field,
    ): EncryptedEnvelope {
        if ($value instanceof EncryptedEnvelope) {
            return $value;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('A secret field requires a string.');
        }

        return $this->secrets->encrypt(
            $value,
            SecretAssociatedData::for($siteIdentifier, $definitionId, $recordId, $field),
        );
    }

    /**
     * Admit a value for a contributed field type using the storage family it registered.
     *
     * Contributed types get no admission code of their own; they borrow the core rule matching the
     * physical family they declared, which is what stops an extension inventing a storage shape the
     * schema compiler cannot install.
     *
     * @param   mixed            $value  Submitted value.
     * @param   FieldDefinition  $field  Field naming the contributed type.
     *
     * @return  mixed  The value in the representation its storage family stores.
     *
     * @throws  InvalidArgumentException  When the value breaks the borrowed rule, or the type declares a
     *          storage family this codec has no conversion for.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When the field type is
     *          not registered in this process.
     *
     * @since   2.0.0
     */
    private function custom(mixed $value, FieldDefinition $field): mixed
    {
        $type = $this->fieldTypes->get($field->type);

        return match ($type->storageType) {
            'guid' => $this->uuid($value),
            'string' => $this->boundedString($value, min($field->length ?? 191, 1000)),
            'text' => $this->boundedString($value, $field->length ?? 1_000_000),
            'integer' => $this->integer($value),
            'boolean' => $this->boolean($value),
            'date' => $this->date($value),
            'time' => $this->time($value),
            'datetime' => $this->instant($value),
            'json' => $this->customJson($value, $field, $type->valueType),
            default => throw new InvalidArgumentException(
                'A contributed field has no executable portable storage conversion.',
            ),
        };
    }

    /**
     * Check a contributed JSON value against its declared value family, then bound it.
     *
     * The family is what the type promised callers it exchanges, so an object arriving where a collection
     * was declared is refused before the value is canonicalised and measured.
     *
     * @param   mixed            $value      Submitted structure.
     * @param   FieldDefinition  $field      Field whose `max_bytes` configuration sets the budget.
     * @param   string           $valueType  Declared value family the contributed type exchanges.
     *
     * @return  mixed  The canonical spelling of the value, ready to be stored as JSON.
     *
     * @throws  InvalidArgumentException  When the value does not match the declared family, or fails the
     *          bounded-JSON rules.
     *
     * @since   2.0.0
     */
    private function customJson(mixed $value, FieldDefinition $field, string $valueType): mixed
    {
        $valid = match ($valueType) {
            'string', 'reference' => is_string($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            'object' => is_array($value) && !array_is_list($value),
            'collection' => is_array($value) && array_is_list($value),
            default => false,
        };
        if (!$valid) {
            throw new InvalidArgumentException(
                'A contributed JSON field value does not match its declared value family.',
            );
        }

        return $this->boundedJson($value, $field);
    }

    /**
     * Collect the physical columns one field occupies.
     *
     * A simple field owns the column named after its handle; a composite one owns the `handle.part`
     * columns, which is why the match is a prefix rather than an equality.
     *
     * @param   PhysicalTableBlueprint  $table  Installed table to search.
     * @param   FieldDefinition         $field  Field whose handle names the columns.
     *
     * @return  list<PhysicalColumnBlueprint>  Matching columns in table order; empty when the field has
     *          no installed storage.
     *
     * @since   2.0.0
     */
    private function columns(PhysicalTableBlueprint $table, FieldDefinition $field): array
    {
        $prefix = $field->handle . '.';

        return array_values(array_filter(
            $table->columns(),
            static fn (PhysicalColumnBlueprint $column): bool =>
                $column->logicalName === $field->handle || str_starts_with($column->logicalName, $prefix),
        ));
    }

    /**
     * Split one normalized value into the logical column names that store it.
     *
     * A null value still yields every one of the field's logical names, mapped to null, so clearing a
     * composite field writes NULL to all of its columns instead of leaving half a value behind.
     *
     * @param   FieldDefinition  $field  Field being written.
     * @param   mixed            $value  Normalized value, or null.
     *
     * @return  array<string, mixed>  Logical column names to the values a driver binds.
     *
     * @throws  InvalidArgumentException  When the value is not the domain type its field normalizes to.
     *
     * @since   2.0.0
     */
    private function storageValues(FieldDefinition $field, mixed $value): array
    {
        if ($value === null) {
            return array_fill_keys(array_map(
                static fn (string $suffix): string => $field->handle . $suffix,
                $this->suffixes($field),
            ), null);
        }

        return match ($field->type) {
            'core.decimal' => [$field->handle => $this->exact($value)->value()],
            'core.computed' => [$field->handle => $this->computedStorage($value, $field)],
            'core.money' => [
                $field->handle . '.amount' => $this->moneyValue($value)->amount->value(),
                $field->handle . '.currency' => $this->moneyValue($value)->currency,
            ],
            'core.quantity' => [
                $field->handle . '.amount' => $this->quantityValue($value)->amount->value(),
                $field->handle . '.unit' => $this->quantityValue($value)->unit,
            ],
            'core.zoned_datetime' => [
                $field->handle . '.instant' => $this->zonedValue($value)->instant,
                $field->handle . '.timezone' => $this->zonedValue($value)->timezone,
            ],
            'core.secret' => [
                $field->handle . '.ciphertext' => $this->envelope($value)->ciphertext,
                $field->handle . '.nonce' => $this->envelope($value)->nonce,
                $field->handle . '.key_id' => $this->envelope($value)->keyId,
                $field->handle . '.algorithm' => $this->envelope($value)->algorithm,
            ],
            default => [$field->handle => $value],
        };
    }

    /**
     * Name the parts a field's value is spread across.
     *
     * @param   FieldDefinition  $field  Field being written.
     *
     * @return  list<string>  Suffixes to append to the handle; a single empty string for a field that
     *          occupies one column.
     *
     * @since   2.0.0
     */
    private function suffixes(FieldDefinition $field): array
    {
        return match ($field->type) {
            'core.money' => ['.amount', '.currency'],
            'core.quantity' => ['.amount', '.unit'],
            'core.zoned_datetime' => ['.instant', '.timezone'],
            'core.secret' => ['.ciphertext', '.nonce', '.key_id', '.algorithm'],
            default => [''],
        };
    }

    /**
     * Rebuild one field value from the logical columns that stored it.
     *
     * Every component a composite field declared has to be present: a partly selected row is a
     * programming error rather than a value with holes, so it is refused instead of quietly rebuilt.
     * Secrets come back sealed, so the site, definition, and record arguments are carried for symmetry
     * with the encode path rather than used here.
     *
     * @param   FieldDefinition       $field           Field being rebuilt.
     * @param   array<string, mixed>  $storage
     * @param   string                $siteIdentifier  Site owning the record.
     * @param   string                $definitionId    UUID of the business definition.
     * @param   string                $recordId        Caller-facing identity of the record.
     *
     * @return  mixed  The field value in the representation callers exchange.
     *
     * @throws  InvalidArgumentException  When a component is missing, or a stored component does not
     *          rebuild into the value object its field declares.
     * @throws  \DateMalformedStringException  When a stored zoned instant is an unparsable string.
     *
     * @since   2.0.0
     */
    private function fromStorage(
        FieldDefinition $field,
        array $storage,
        string $siteIdentifier,
        string $definitionId,
        string $recordId,
    ): mixed {
        $get = static function (array $values, string $key): mixed {
            if (!array_key_exists($key, $values)) {
                throw new InvalidArgumentException('A stored business field is missing a physical component.');
            }
            return $values[$key];
        };

        return match ($field->type) {
            'core.decimal' => ExactDecimal::fromString(
                $this->storedString($get($storage, $field->handle)),
                $this->precision($field),
                $this->scale($field),
            ),
            'core.money' => new MoneyValue(
                ExactDecimal::fromString(
                    $this->storedString($get($storage, $field->handle . '.amount')),
                    $this->precision($field),
                    $this->scale($field),
                ),
                $this->storedString($get($storage, $field->handle . '.currency')),
            ),
            'core.quantity' => new QuantityValue(
                ExactDecimal::fromString(
                    $this->storedString($get($storage, $field->handle . '.amount')),
                    $this->precision($field),
                    $this->scale($field),
                ),
                $this->storedString($get($storage, $field->handle . '.unit')),
            ),
            'core.zoned_datetime' => ZonedDateTimeValue::fromStrings(
                $this->dateTimeString($get($storage, $field->handle . '.instant')),
                $this->storedString($get($storage, $field->handle . '.timezone')),
            ),
            'core.secret' => new EncryptedEnvelope(
                $this->storedString($get($storage, $field->handle . '.ciphertext')),
                $this->storedString($get($storage, $field->handle . '.nonce')),
                $this->storedString($get($storage, $field->handle . '.key_id')),
                $this->storedString($get($storage, $field->handle . '.algorithm')),
            ),
            'core.computed' => $this->computedFromStorage($field, $get($storage, $field->handle)),
            default => $get($storage, $field->handle),
        };
    }

    /**
     * Admit a formula result against the type the formula declares.
     *
     * The result is checked as strictly as an authored value, because a stored computation reaches a real
     * column and a virtual one still reaches the caller.
     *
     * @param   mixed            $value  Value the formula evaluated to.
     * @param   FieldDefinition  $field  Field whose formula names the result type.
     *
     * @return  mixed  The result in the representation its declared type stores.
     *
     * @throws  InvalidArgumentException  When the result breaks its declared type, or the formula names a
     *          result type with no portable storage.
     *
     * @since   2.0.0
     */
    private function computed(mixed $value, FieldDefinition $field): mixed
    {
        return match ($field->formula?->type) {
            'boolean' => $this->boolean($value),
            'integer' => $this->integer($value),
            'decimal' => $this->decimal($value, $field),
            'string' => $this->boundedString($value, $field->length ?? 191),
            'date' => $this->date($value),
            'time' => $this->time($value),
            'datetime' => $this->instant($value),
            default => throw new InvalidArgumentException('A computed field has an unsupported formula result type.'),
        };
    }

    /**
     * Spell a stored computation's result the way its column takes it.
     *
     * @param   mixed            $value  Normalized formula result.
     * @param   FieldDefinition  $field  Field whose formula names the result type.
     *
     * @return  mixed  The canonical literal for a decimal formula, otherwise the value as it stands.
     *
     * @throws  InvalidArgumentException  When a decimal formula's result is not an `ExactDecimal`.
     *
     * @since   2.0.0
     */
    private function computedStorage(mixed $value, FieldDefinition $field): mixed
    {
        return $field->formula?->type === 'decimal' ? $this->exact($value)->value() : $value;
    }

    /**
     * Rebuild a stored computation's result from its column.
     *
     * @param   FieldDefinition  $field  Field whose formula names the result type.
     * @param   mixed            $value  Decoded column value.
     *
     * @return  mixed  An `ExactDecimal` for a decimal formula, otherwise the value as it was stored.
     *
     * @throws  InvalidArgumentException  When a decimal result is not stored as a string, or does not fit
     *          the field's precision and scale.
     *
     * @since   2.0.0
     */
    private function computedFromStorage(FieldDefinition $field, mixed $value): mixed
    {
        if ($field->formula?->type !== 'decimal') {
            return $value;
        }

        return ExactDecimal::fromString(
            $this->storedString($value),
            $this->precision($field),
            $this->scale($field),
        );
    }

    /**
     * Render a stored instant as the canonical UTC string a value object is rebuilt from.
     *
     * @param   mixed  $value  Decoded column value: an instant, or the string a driver returned.
     *
     * @return  string  RFC 3339 UTC with microseconds, the spelling `ZonedDateTimeValue` parses.
     *
     * @throws  InvalidArgumentException  When the value is neither an instant nor a string.
     * @throws  \DateMalformedStringException  When the string cannot be parsed as a date and time.
     *
     * @since   2.0.0
     */
    private function dateTimeString(mixed $value): string
    {
        if ($value instanceof DateTimeImmutable) {
            return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        }
        if (is_string($value)) {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        }
        throw new InvalidArgumentException('A stored date-time value is invalid.');
    }

    /**
     * Turn one raw driver value into the PHP type its column's Doctrine type promises.
     *
     * Drivers disagree about what they hand back: a stream for a large object, a string for an integer or
     * a bigint, `0` or `'0'` for a boolean. This is the single place those differences are settled, so
     * every later step reads the same shapes whichever engine the site runs on.
     *
     * @param   PhysicalColumnBlueprint  $column  Column the value was read from.
     * @param   mixed                    $value   Raw driver value, or null.
     *
     * @return  mixed  Null when the column was NULL, otherwise the value in the type its column declares.
     *
     * @throws  InvalidArgumentException  When a stream cannot be read, or the value contradicts the
     *          column's declared type.
     * @throws  \DateMalformedStringException  When a date-time column holds an unparsable string.
     *
     * @since   2.0.0
     */
    private function decodePhysical(PhysicalColumnBlueprint $column, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if (is_resource($value)) {
            $contents = stream_get_contents($value);
            if (!is_string($contents)) {
                throw new InvalidArgumentException('A stored binary field could not be read.');
            }
            $value = $contents;
        }

        return match ($column->doctrineType) {
            'integer', 'smallint' => is_int($value) ? $value : $this->storedInteger($value),
            'bigint' => is_int($value) || is_string($value)
                ? $value
                : throw new InvalidArgumentException('A stored bigint field is invalid.'),
            'boolean' => $this->storedBoolean($value),
            'date_immutable' => $value instanceof DateTimeImmutable
                ? $value
                : $this->date($this->storedString($value)),
            'time_immutable' => $value instanceof DateTimeImmutable ? $value : $this->storedTime($value),
            'datetime_immutable', 'datetimetz_immutable' => $value instanceof DateTimeImmutable
                ? $value
                : new DateTimeImmutable($this->storedString($value), new DateTimeZone('UTC')),
            'json' => $this->storedJson($value),
            default => $value,
        };
    }

    /**
     * Read an integer a driver returned as a decimal string.
     *
     * @param   mixed  $value  Raw driver value.
     *
     * @return  int  The parsed integer.
     *
     * @throws  InvalidArgumentException  When the value is not a canonical decimal string, or does not
     *          fit a PHP integer.
     *
     * @since   2.0.0
     */
    private function storedInteger(mixed $value): int
    {
        if (!is_string($value) || preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            throw new InvalidArgumentException('A stored integer field is invalid.');
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($integer)) {
            throw new InvalidArgumentException('A stored integer exceeds the PHP integer range.');
        }

        return $integer;
    }

    /**
     * Insist a decoded column value really is a string before it is parsed further.
     *
     * @param   mixed  $value  Decoded column value.
     *
     * @return  string  The value unchanged.
     *
     * @throws  InvalidArgumentException  When the stored value is not a string.
     *
     * @since   2.0.0
     */
    private function storedString(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('A stored textual field is invalid.');
        }

        return $value;
    }

    /**
     * Read a time-of-day column, padding the fraction out to the six digits the canonical form carries.
     *
     * @param   mixed  $value  Raw driver value.
     *
     * @return  DateTimeImmutable  Instant carrying the stored time of day.
     *
     * @throws  InvalidArgumentException  When the value is not an `HH:MM:SS` string, with or without a
     *          fractional part.
     *
     * @since   2.0.0
     */
    private function storedTime(mixed $value): DateTimeImmutable
    {
        if (
            !is_string($value)
            || preg_match('/^(\d{2}:\d{2}:\d{2})(?:\.([0-9]{1,6}))?$/D', $value, $matches) !== 1
        ) {
            throw new InvalidArgumentException('A stored local-time field is invalid.');
        }
        $canonical = $matches[1] . '.' . str_pad($matches[2] ?? '', 6, '0');

        return $this->time($canonical);
    }

    /**
     * Read a boolean column across the spellings the supported drivers return.
     *
     * A native boolean, the integers `0` and `1`, and their string forms are all accepted, because that
     * is the whole set the portable schema can produce; anything else is a corrupt column rather than a
     * value worth guessing at.
     *
     * @param   mixed  $value  Raw driver value.
     *
     * @return  bool  The stored flag.
     *
     * @throws  InvalidArgumentException  When the value is none of the accepted spellings.
     *
     * @since   2.0.0
     */
    private function storedBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === '0') {
            return false;
        }
        if ($value === 1 || $value === '1') {
            return true;
        }
        throw new InvalidArgumentException('A stored boolean field is invalid.');
    }

    /**
     * Carry a bigint through a cursor without narrowing it to a PHP integer.
     *
     * A 64-bit column can hold values a driver hands back as a string, so a string stays a string rather
     * than being cast.
     *
     * @param   mixed  $value  Driver value, or a value decoded from a cursor.
     *
     * @return  int|string  The integer, or its canonical decimal string.
     *
     * @throws  InvalidArgumentException  When the value is neither an integer nor a canonical decimal
     *          string.
     *
     * @since   2.0.0
     */
    private function cursorBigint(mixed $value): int|string
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            throw new InvalidArgumentException('A stored bigint cursor value is invalid.');
        }

        return $value;
    }

    /**
     * Carry a decimal through a cursor as a literal rather than a float.
     *
     * @param   mixed  $value  Driver value, or a value decoded from a cursor.
     *
     * @return  string  Canonical decimal literal, with no exponent and nothing padded onto it.
     *
     * @throws  InvalidArgumentException  When the value is neither an integer nor a canonical decimal
     *          literal.
     *
     * @since   2.0.0
     */
    private function cursorDecimal(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (!is_string($value) || preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value) !== 1) {
            throw new InvalidArgumentException('A stored decimal cursor value is invalid.');
        }

        return $value;
    }

    /**
     * Insist a temporal cursor column decoded to an instant before it is formatted.
     *
     * @param   mixed  $value  Value `decodePhysical()` produced for a temporal column.
     *
     * @return  DateTimeImmutable  The instant unchanged.
     *
     * @throws  InvalidArgumentException  When the decoded value is not an instant.
     *
     * @since   2.0.0
     */
    private function cursorDateTime(mixed $value): DateTimeImmutable
    {
        if (!$value instanceof DateTimeImmutable) {
            throw new InvalidArgumentException('A stored temporal cursor value is invalid.');
        }

        return $value;
    }

    /**
     * Read a JSON column, whether the driver decoded it or handed back the text.
     *
     * Large integers are kept as strings so a 64-bit value inside a document does not come out as a
     * float, and the result goes through `RecordValueGuard` because a stored document is untrusted input
     * once anything else can write to the database.
     *
     * @param   mixed  $value  Raw driver value: a decoded structure, or JSON text.
     *
     * @return  mixed  The decoded value, admitted by the record value rules.
     *
     * @throws  InvalidArgumentException  When the text is not valid JSON, or the decoded value is not
     *          admissible as a record value.
     *
     * @since   2.0.0
     */
    private function storedJson(mixed $value): mixed
    {
        if (!is_string($value)) {
            RecordValueGuard::assertValue($value);
            return $value;
        }
        try {
            $decoded = json_decode($value, true, 16, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('A stored JSON field is invalid.', 0, $exception);
        }
        RecordValueGuard::assertValue($decoded);

        return $decoded;
    }

    /**
     * Insist a decimal field really normalized to an `ExactDecimal` before it is stored.
     *
     * @param   mixed  $value  Normalized field value.
     *
     * @return  ExactDecimal  The value unchanged.
     *
     * @throws  InvalidArgumentException  When the value did not come through decimal normalization.
     *
     * @since   2.0.0
     */
    private function exact(mixed $value): ExactDecimal
    {
        return $value instanceof ExactDecimal
            ? $value
            : throw new InvalidArgumentException('A normalized decimal field is invalid.');
    }

    /**
     * Insist a money field really normalized to a `MoneyValue` before it is stored.
     *
     * @param   mixed  $value  Normalized field value.
     *
     * @return  MoneyValue  The value unchanged.
     *
     * @throws  InvalidArgumentException  When the value did not come through money normalization.
     *
     * @since   2.0.0
     */
    private function moneyValue(mixed $value): MoneyValue
    {
        return $value instanceof MoneyValue
            ? $value
            : throw new InvalidArgumentException('A normalized money field is invalid.');
    }

    /**
     * Insist a quantity field really normalized to a `QuantityValue` before it is stored.
     *
     * @param   mixed  $value  Normalized field value.
     *
     * @return  QuantityValue  The value unchanged.
     *
     * @throws  InvalidArgumentException  When the value did not come through quantity normalization.
     *
     * @since   2.0.0
     */
    private function quantityValue(mixed $value): QuantityValue
    {
        return $value instanceof QuantityValue
            ? $value
            : throw new InvalidArgumentException('A normalized quantity field is invalid.');
    }

    /**
     * Insist a zoned date-time field really normalized to a `ZonedDateTimeValue` before it is stored.
     *
     * @param   mixed  $value  Normalized field value.
     *
     * @return  ZonedDateTimeValue  The value unchanged.
     *
     * @throws  InvalidArgumentException  When the value did not come through zoned date-time
     *          normalization.
     *
     * @since   2.0.0
     */
    private function zonedValue(mixed $value): ZonedDateTimeValue
    {
        return $value instanceof ZonedDateTimeValue
            ? $value
            : throw new InvalidArgumentException('A normalized zoned date-time field is invalid.');
    }

    /**
     * Insist a secret field really normalized to a sealed envelope before it is stored.
     *
     * This is the last point at which a plaintext secret could reach a column, so an unsealed value is
     * refused rather than written.
     *
     * @param   mixed  $value  Normalized field value.
     *
     * @return  EncryptedEnvelope  The value unchanged.
     *
     * @throws  InvalidArgumentException  When the value was not sealed by secret normalization.
     *
     * @since   2.0.0
     */
    private function envelope(mixed $value): EncryptedEnvelope
    {
        return $value instanceof EncryptedEnvelope
            ? $value
            : throw new InvalidArgumentException('A normalized secret field is invalid.');
    }

    /**
     * Read the digit budget an exact field has to declare.
     *
     * @param   FieldDefinition  $field  Field carrying the numeric declaration.
     *
     * @return  int  Total number of digits the field stores.
     *
     * @throws  InvalidArgumentException  When the field declares no precision.
     *
     * @since   2.0.0
     */
    private function precision(FieldDefinition $field): int
    {
        return $field->precision
            ?? throw new InvalidArgumentException('An exact field has no configured precision.');
    }

    /**
     * Read the fractional digit count an exact field has to declare.
     *
     * @param   FieldDefinition  $field  Field carrying the numeric declaration.
     *
     * @return  int  Number of digits kept after the decimal point.
     *
     * @throws  InvalidArgumentException  When the field declares no scale.
     *
     * @since   2.0.0
     */
    private function scale(FieldDefinition $field): int
    {
        return $field->scale ?? throw new InvalidArgumentException('An exact field has no configured scale.');
    }

    /**
     * Find the field a definition's identity strategy nominates.
     *
     * The strategy names a field type rather than a handle — `core.uuid` or `core.reference_identity` —
     * and a well-formed definition carries exactly one field of that type, so reaching the failure here
     * means the definition was assembled without that invariant.
     *
     * @param   EntityTypeDefinition  $definition  Definition to search.
     *
     * @return  FieldDefinition  The field carrying the record's identity.
     *
     * @throws  InvalidArgumentException  When the definition declares no field of the required type.
     *
     * @since   2.0.0
     */
    private function identityField(EntityTypeDefinition $definition): FieldDefinition
    {
        $type = $definition->identityStrategy === IdentityStrategy::Uuid
            ? 'core.uuid'
            : 'core.reference_identity';
        foreach ($definition->fields() as $field) {
            if ($field->type === $type) {
                return $field;
            }
        }
        throw new InvalidArgumentException('A business definition has no identity field.');
    }
}
