<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application\Command;

use InvalidArgumentException;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Application\Automation\IdempotencyKey;
use Kumwe\App\BusinessRecord\Application\RecordRequestGuard;

/**
 * Request to put the members of one ordered relationship into a new position order.
 *
 * `BusinessRecordService::reorder()` turns this into a rewrite of the position column on the
 * relationship's association rows, which makes the list a full replacement rather than a move
 * instruction: the write repository refuses a reorder that does not name every current member of the
 * relationship exactly once. This constructor is where the request is made safe — identifiers, the
 * relationship handle and the organization scope are shape-checked, and the list is capped and
 * rejected outright if it repeats an entry — so the service is only ever handed a well-formed
 * request, and a duplicate is reported to the caller rather than quietly collapsed. Whether the named
 * relationship really is an ordered collection is settled later, against the definition version
 * pinned on the record.
 *
 * @since  2.0.0
 */
final readonly class ReorderRecordLinesCommand
{
    /**
     * Member record identifiers in the position order the relationship should end up in.
     *
     * Re-indexed from the caller's argument, so gaps in the supplied keys never reach the service and
     * the list position is the position that gets stored.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $orderedRecordIds;

    /**
     * Assemble a reorder request and reject anything malformed before the service sees it.
     *
     * @param   ExecutionContext  $context                 Actor and site the reorder runs as.
     * @param   string            $definitionIdentifier    Definition UUID or handle naming the record type.
     * @param   string            $recordId                Identity of the record that owns the relationship.
     * @param   int               $expectedVersion         Version the caller last read; a mismatch aborts the write.
     * @param   string            $relationship            Handle of the ordered relationship being resequenced.
     * @param   list<string>      $orderedRecordIds        Every current member of the relationship, in its new order.
     * @param   IdempotencyKey    $idempotencyKey          Token under which a retry replays the first outcome.
     * @param   ?string           $organizationIdentifier  Organization the record is scoped to; null when the
     *          definition is not organization-scoped.
     *
     * @throws  InvalidArgumentException  When any identifier, the expected version, the relationship
     *          handle or the organization is malformed, or the ordered list repeats an entry or holds
     *          more than 1000 of them.
     *
     * @since   2.0.0
     */
    public function __construct(
        public ExecutionContext $context,
        public string $definitionIdentifier,
        public string $recordId,
        public int $expectedVersion,
        public string $relationship,
        array $orderedRecordIds,
        public IdempotencyKey $idempotencyKey,
        public ?string $organizationIdentifier = null,
    ) {
        RecordRequestGuard::definition($definitionIdentifier);
        RecordRequestGuard::record($recordId);
        RecordRequestGuard::expectedVersion($expectedVersion);
        RecordRequestGuard::handle($relationship, 'relationship');
        RecordRequestGuard::organization($organizationIdentifier);
        if (count($orderedRecordIds) > 1000 || count(array_unique($orderedRecordIds)) !== count($orderedRecordIds)) {
            throw new InvalidArgumentException('A line reorder is duplicated or exceeds 1000 entries.');
        }
        foreach ($orderedRecordIds as $orderedRecordId) {
            RecordRequestGuard::record($orderedRecordId);
        }
        $this->orderedRecordIds = array_values($orderedRecordIds);
    }
}
