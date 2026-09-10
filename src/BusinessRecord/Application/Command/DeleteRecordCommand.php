<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application\Command;

use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Application\Automation\IdempotencyKey;
use Kumwe\App\BusinessRecord\Application\RecordRequestGuard;

/**
 * Request to delete one business record, however its entity type defines deletion.
 *
 * The command says only that the record should go; whether that becomes a soft delete or an erased
 * row is decided by the definition's soft-delete setting when `BusinessRecordService::delete()` runs,
 * and a hard delete additionally clears inbound set-null references first. Construction is where a
 * delivery-layer request stops being untrusted text: `RecordRequestGuard` rejects a malformed
 * definition identifier, record identity, version or organization scope here, so the service can
 * treat every field as already checked. The expected version pins the record the caller read, and
 * the idempotency key makes a retry replay the first outcome rather than delete twice.
 *
 * @since  2.0.0
 */
final readonly class DeleteRecordCommand
{
    /**
     * Validate a delete request and freeze it as one command.
     *
     * @param   ExecutionContext  $context                 Actor, site and request the delete runs under.
     * @param   string            $definitionIdentifier    UUID or handle of the record's entity type.
     * @param   string            $recordId                Public identity of the record to delete.
     * @param   int               $expectedVersion         Version the caller read; the delete is refused when
     *          the stored record has moved past it.
     * @param   IdempotencyKey    $idempotencyKey          Token a retry repeats to replay this outcome instead
     *          of deleting again.
     * @param   ?string           $organizationIdentifier  Organization the record is scoped to, or null for a
     *          type that is not organization-scoped.
     *
     * @throws  \InvalidArgumentException  When the definition identifier, record identity, expected version or
     *          organization identifier fails its format rule.
     *
     * @since   2.0.0
     */
    public function __construct(
        public ExecutionContext $context,
        public string $definitionIdentifier,
        public string $recordId,
        public int $expectedVersion,
        public IdempotencyKey $idempotencyKey,
        public ?string $organizationIdentifier = null,
    ) {
        RecordRequestGuard::definition($definitionIdentifier);
        RecordRequestGuard::record($recordId);
        RecordRequestGuard::expectedVersion($expectedVersion);
        RecordRequestGuard::organization($organizationIdentifier);
    }
}
