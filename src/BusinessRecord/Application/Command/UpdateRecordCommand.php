<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application\Command;

use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Application\Automation\IdempotencyKey;
use Kumwe\App\BusinessRecord\Application\RecordRequestGuard;

/**
 * Request to change some of the field values on one existing business record.
 *
 * `BusinessRecordService::update()` applies the value map as a patch over the stored values: only the
 * handles present are validated against the pinned definition, so a handle the definition does not
 * declare, or one it marks immutable, read-only or currently uneditable, is reported as a violation
 * rather than silently dropped. Fields the caller omits keep what they held, which makes an empty map
 * a write with no effect — and the constructor refuses one outright. What the audit trail records is
 * the fields whose value actually differs afterwards, not the handles that were submitted.
 *
 * That constructor is the value trust boundary for updates. It bounds the map, checks each handle
 * against the field-handle grammar and walks every value through the domain's runtime-type guard, so
 * the service is never handed a float, an unsupported object or an unbounded nested structure.
 *
 * @since  2.0.0
 */
final readonly class UpdateRecordCommand
{
    /**
     * Field values to write, keyed by field handle.
     *
     * A partial patch, not a replacement: absent handles are left alone rather than cleared. Values
     * are the domain's accepted runtime types — scalars, null, arrays of those, and the value objects
     * `RecordValueGuard` allows — never floats.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    public array $values;

    /**
     * Assemble an update request and reject anything malformed before the service sees it.
     *
     * @param   ExecutionContext      $context                 Actor and site the update runs as.
     * @param   string                $definitionIdentifier    Definition UUID or handle naming the record type.
     * @param   string                $recordId                Identity of the record to change.
     * @param   int                   $expectedVersion         Version the caller last read; a mismatch aborts.
     * @param   array<string, mixed>  $values                  Field handles to write, mapped to their new values.
     * @param   IdempotencyKey        $idempotencyKey          Token under which a retry replays the first outcome.
     * @param   ?string               $organizationIdentifier  Organization the record is scoped to; null when the
     *          definition is not organization-scoped.
     *
     * @throws  \InvalidArgumentException  When an identifier or the expected version is malformed, or
     *          the value map is empty, holds more than 256 entries, carries an invalid field handle,
     *          or contains a value the domain cannot represent.
     *
     * @since   2.0.0
     */
    public function __construct(
        public ExecutionContext $context,
        public string $definitionIdentifier,
        public string $recordId,
        public int $expectedVersion,
        array $values,
        public IdempotencyKey $idempotencyKey,
        public ?string $organizationIdentifier = null,
    ) {
        RecordRequestGuard::definition($definitionIdentifier);
        RecordRequestGuard::record($recordId);
        RecordRequestGuard::expectedVersion($expectedVersion);
        RecordRequestGuard::organization($organizationIdentifier);
        RecordRequestGuard::values($values);
        $this->values = $values;
    }
}
