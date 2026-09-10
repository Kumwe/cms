<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application\Command;

use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Application\Automation\IdempotencyKey;
use Kumwe\App\BusinessRecord\Application\RecordRequestGuard;

/**
 * Request to detach one named link between a record and a single target of that relationship.
 *
 * `BusinessRecordService::unrelate()` deletes the association row — or clears the foreign key column
 * where the relationship is stored on the record itself — and re-versions the source record. Neither
 * record is deleted by it, and a relationship the definition marks required cannot be emptied this
 * way. Where the inverse side owns the canonical storage the target's row is what gets written, so
 * that record is re-versioned and audited under its own relationship handle as well.
 *
 * A link that is not there is an error rather than a no-op, since the write repository refuses a
 * detachment that matches no stored row; repeating the command safely is what the idempotency key is
 * for, not re-issuing it under a fresh one. The constructor shape-checks both identities, the
 * relationship handle and the organization scope, leaving the service to decide only whether the link
 * exists and may be broken.
 *
 * @since  2.0.0
 */
final readonly class UnrelateRecordsCommand
{
    /**
     * Assemble an unrelate request and reject anything malformed before the service sees it.
     *
     * @param   ExecutionContext  $context                 Actor and site the detachment runs as.
     * @param   string            $definitionIdentifier    Definition UUID or handle naming the source record type.
     * @param   string            $recordId                Identity of the record that holds the relationship.
     * @param   int               $expectedVersion         Version the caller last read; a mismatch aborts.
     * @param   string            $relationship            Handle of the relationship the link belongs to.
     * @param   string            $targetRecordId          Identity of the linked record or owned line.
     * @param   IdempotencyKey    $idempotencyKey          Token under which a retry replays the first outcome.
     * @param   ?string           $organizationIdentifier  Organization both records are scoped to; null when the
     *          definition is not organization-scoped.
     *
     * @throws  \InvalidArgumentException  When either identity, the definition identifier, the
     *          expected version, the relationship handle or the organization identifier is malformed.
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
        public ?string $organizationIdentifier = null,
    ) {
        RecordRequestGuard::definition($definitionIdentifier);
        RecordRequestGuard::record($recordId);
        RecordRequestGuard::record($targetRecordId);
        RecordRequestGuard::expectedVersion($expectedVersion);
        RecordRequestGuard::handle($relationship, 'relationship');
        RecordRequestGuard::organization($organizationIdentifier);
    }
}
