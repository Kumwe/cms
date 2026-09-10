<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application\Command;

use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Application\Automation\IdempotencyKey;
use Kumwe\App\BusinessRecord\Application\RecordRequestGuard;

/**
 * Request to create one business record of a given entity type.
 *
 * Construction is where a delivery-layer request stops being untrusted text: `RecordRequestGuard`
 * rejects a malformed definition identifier, organization scope or record identity here, and bounds
 * the value set to at most 256 well-formed field handles carrying values the domain can store, so
 * `BusinessRecordService::create()` never sees an unchecked shape. Field-level rules — required,
 * type, uniqueness — stay with the definition and are applied by the service, not here. There is no
 * expected version because nothing exists yet; the idempotency key alone is what stops a retried
 * request from creating a second record.
 *
 * @since  2.0.0
 */
final readonly class CreateRecordCommand
{
    /**
     * Field values the new record is created from, keyed by field handle.
     *
     * Guarded for shape only: handles match the field-handle pattern and values are storable types.
     * Whether they satisfy the definition is decided later, by the service's rule validator.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    public array $values;

    /**
     * Validate a create request and freeze it as one command.
     *
     * @param   ExecutionContext      $context                 Actor, site and request the create runs under.
     * @param   string                $definitionIdentifier    UUID or handle of the entity type to instantiate.
     * @param   array<string, mixed>  $values                  Field values for the new record, keyed by handle;
     *          must not be empty.
     * @param   IdempotencyKey        $idempotencyKey          Token a retry repeats to replay this outcome
     *          instead of creating a second record.
     * @param   ?string               $organizationIdentifier  Organization to create the record in, or null for
     *          a type that is not organization-scoped.
     * @param   ?string               $recordId                Identity to give the record; null takes it from
     *          the declared identity field, or mints a UUID for a UUID-identity type.
     *
     * @throws  \InvalidArgumentException  When the definition identifier, organization identifier or record
     *          identity fails its format rule, or the value set is empty, oversized, or carries an invalid
     *          field handle or a value the domain cannot store.
     *
     * @since   2.0.0
     */
    public function __construct(
        public ExecutionContext $context,
        public string $definitionIdentifier,
        array $values,
        public IdempotencyKey $idempotencyKey,
        public ?string $organizationIdentifier = null,
        public ?string $recordId = null,
    ) {
        RecordRequestGuard::definition($definitionIdentifier);
        RecordRequestGuard::organization($organizationIdentifier);
        RecordRequestGuard::values($values);
        if ($recordId !== null) {
            RecordRequestGuard::record($recordId);
        }
        $this->values = $values;
    }
}
