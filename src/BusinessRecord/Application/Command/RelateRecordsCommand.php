<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application\Command;

use InvalidArgumentException;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Application\Automation\IdempotencyKey;
use Kumwe\App\BusinessRecord\Application\RecordRequestGuard;

/**
 * Request to link one business record to another through a declared relationship.
 *
 * The same command covers both shapes of link. For an ordinary relationship the target already
 * exists and is named by $targetRecordId, and $targetValues must stay empty. For an owned-line
 * collection there is nothing to point at yet: $targetValues carries the line to create, and
 * `BusinessRecordService::relate()` builds it against the line type before storing the link.
 * Construction is where a delivery-layer request stops being untrusted text, with
 * `RecordRequestGuard` checking both record identities, the relationship handle, the definition
 * identifier and the organization scope, and the position bounded here so an ordered collection
 * cannot be handed an absurd slot. The expected version pins the source the caller read, and the
 * idempotency key makes a retry replay the first outcome instead of linking twice.
 *
 * @since  2.0.0
 */
final readonly class RelateRecordsCommand
{
    /**
     * Values of the owned line to create, keyed by field handle.
     *
     * Empty for every relationship that points at an existing record; the service rejects the
     * command when a non-owned relationship arrives with values here.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    public array $targetValues;

    /**
     * Validate a relate request and freeze it as one command.
     *
     * @param   ExecutionContext      $context                 Actor, site and request the link runs under.
     * @param   string                $definitionIdentifier    UUID or handle of the source record's entity
     *          type.
     * @param   string                $recordId                Public identity of the source record.
     * @param   int                   $expectedVersion         Version of the source the caller read; the link
     *          is refused when the stored record has moved past it.
     * @param   string                $relationship            Handle of the relationship as the source
     *          definition declares it.
     * @param   string                $targetRecordId          Public identity of the record to link to, or the
     *          identity to give the owned line being created.
     * @param   IdempotencyKey        $idempotencyKey          Token a retry repeats to replay this outcome
     *          instead of linking again.
     * @param   ?int                  $position                Slot in an ordered collection, from 0 to
     *          1,000,000; null appends after the current highest.
     * @param   ?string               $organizationIdentifier  Organization the records are scoped to, or null
     *          for a type that is not organization-scoped.
     * @param   array<string, mixed>  $targetValues            Values of the line to create; required only for
     *          an owned-line relationship, and empty for every other kind.
     *
     * @throws  InvalidArgumentException  When either record identity, the definition identifier, the
     *          relationship handle, the expected version or the organization identifier fails its format
     *          rule, when the position is negative or above 1,000,000, or when the target values are
     *          oversized or carry an invalid field handle or a value the domain cannot store.
     *
     * @since   2.0.0
     */
    public function __construct(
        public ExecutionContext $context,
        public string $definitionIdentifier,
        public string $recordId,
        public int $expectedVersion,
        public string $relationship,
        public string $targetRecordId,
        public IdempotencyKey $idempotencyKey,
        public ?int $position = null,
        public ?string $organizationIdentifier = null,
        array $targetValues = [],
    ) {
        RecordRequestGuard::definition($definitionIdentifier);
        RecordRequestGuard::record($recordId);
        RecordRequestGuard::record($targetRecordId);
        RecordRequestGuard::expectedVersion($expectedVersion);
        RecordRequestGuard::handle($relationship, 'relationship');
        RecordRequestGuard::organization($organizationIdentifier);
        if ($position !== null && ($position < 0 || $position > 1_000_000)) {
            throw new InvalidArgumentException('A business relationship position is outside its safe bound.');
        }
        RecordRequestGuard::values($targetValues, true);
        $this->targetValues = $targetValues;
    }
}
