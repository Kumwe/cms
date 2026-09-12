<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\Conversion\Decimal\ExactDecimal;
use Kumwe\Conversion\Value\MoneyValue;
use Kumwe\Conversion\Value\QuantityValue;
use Kumwe\Extension\Spi\BusinessRecord\Value\ZonedDateTimeValue;
use Kumwe\Secret\Value\EncryptedEnvelope;

/**
 * Gatekeeper for what may appear inside a business-record value, and how it is spelled for storage.
 *
 * Every field value that reaches a record, a revision, a query or a fingerprint passes through here.
 * `assertValue()` is the admission check: it accepts null, bool, int and string, the domain value
 * objects `ExactDecimal`, `MoneyValue`, `QuantityValue`, `ZonedDateTimeValue` and `EncryptedEnvelope`,
 * `DateTimeImmutable`, and arrays of those — and refuses PHP floats outright, so a decimal, money or
 * quantity field cannot lose digits on its way to a column. `canonical()` is the matching storage
 * spelling, which is the form that actually gets written, checksummed and compared. The two are used
 * together, as `RecordValueCodec` does, because admission bounds the structure and canonicalisation
 * makes it byte-stable.
 *
 * @since  2.0.0
 */
final class RecordValueGuard
{
    /**
     * Refuse a value the record layer has no safe storage spelling for.
     *
     * Arrays are walked to their leaves, bounded by $depth and $nodes so that a deeply nested or very
     * wide payload is rejected before anything tries to canonicalise or hash it. $nodes is taken by
     * reference and shared across the whole recursion, so the budget covers the value as a whole rather
     * than each branch separately; both counters default to the values a caller checking one value from
     * the outside would pass.
     *
     * @param   mixed  $value  Value to admit, at any depth within the structure.
     * @param   int    $depth  Nesting level of $value, 0 at the top; deeper than 8 is refused.
     * @param   int    $nodes  Running count of nodes visited so far; more than 4096 is refused.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the value is a float, is an object or resource outside the
     *          supported set, or breaches the depth or node budget.
     *
     * @since   2.0.0
     */
    public static function assertValue(mixed $value, int $depth = 0, int &$nodes = 0): void
    {
        ++$nodes;
        if ($depth > 8 || $nodes > 4096) {
            throw new InvalidArgumentException('A business-record value exceeds its structural bounds.');
        }
        if (is_float($value)) {
            throw new InvalidArgumentException('Business-record values cannot contain PHP floats.');
        }
        if (
            $value === null || is_bool($value) || is_int($value) || is_string($value)
            || $value instanceof ExactDecimal || $value instanceof MoneyValue
            || $value instanceof QuantityValue || $value instanceof ZonedDateTimeValue
            || $value instanceof EncryptedEnvelope || $value instanceof DateTimeImmutable
        ) {
            return;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                self::assertValue($item, $depth + 1, $nodes);
            }
            return;
        }

        throw new InvalidArgumentException('A business-record value has an unsupported runtime type.');
    }

    /**
     * Reduce a value to the byte-stable form that is stored, checksummed and compared.
     *
     * Each domain value object becomes its storage spelling — a decimal its canonical literal; money,
     * quantities and zoned date-times their arrays; an encrypted envelope its storage array; a
     * `DateTimeImmutable` an ISO-8601 string with microseconds and offset — and string-keyed arrays are
     * sorted with `SORT_STRING` so key order never reaches a digest, while list order, which carries
     * meaning, is left alone. Leaves are admitted by `assertValue()` one at a time, so the depth and node
     * budget is not re-applied to the structure as a whole; a caller that needs that bound asserts the
     * whole value first, which is what `RecordValueCodec` does.
     *
     * @param   mixed  $value  Value to reduce, typically one field value or a map of them by handle.
     *
     * @return  mixed  Null, bool, int, string, or arrays of those; no domain object survives the reduction.
     *
     * @throws  InvalidArgumentException  When a leaf is a float, or an object or resource the guard has no
     *          storage spelling for.
     *
     * @since   2.0.0
     */
    public static function canonical(mixed $value): mixed
    {
        if ($value instanceof ExactDecimal) {
            return $value->value();
        }
        if ($value instanceof MoneyValue || $value instanceof QuantityValue || $value instanceof ZonedDateTimeValue) {
            return $value->toArray();
        }
        if ($value instanceof EncryptedEnvelope) {
            return $value->toStorage();
        }
        if ($value instanceof DateTimeImmutable) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = self::canonical($item);
            }
            if (!array_is_list($result)) {
                ksort($result, SORT_STRING);
            }
            return $result;
        }

        self::assertValue($value);
        return $value;
    }

    /**
     * Prevent instantiation; the guard is a policy reached through its static methods alone.
     *
     * @since  2.0.0
     */
    private function __construct()
    {
    }
}
