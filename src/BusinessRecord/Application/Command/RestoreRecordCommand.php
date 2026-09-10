<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application\Command;

use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Application\Automation\IdempotencyKey;
use Kumwe\App\BusinessRecord\Application\RecordRequestGuard;

/**
 * Request to bring an archived or soft-deleted record back into normal circulation.
 *
 * `BusinessRecordService::restore()` runs it through the shared lifecycle path with both the archived
 * and the deleted rows in view, which is what lets it reach a record ordinary reads hide: it checks
 * the expected version, clears the archive and soft-delete stamps together, and writes the record
 * back one version higher. A record that is neither archived nor deleted has nothing to restore and
 * the domain refuses it, so this is not a no-op that is safe to fire speculatively; a hard delete is
 * likewise beyond its reach, because no row survives it. The constructor shape-checks the identifiers
 * and the organization scope, leaving the service to reason only about lifecycle state.
 *
 * @since  2.0.0
 */
final readonly class RestoreRecordCommand
{
    /**
     * Assemble a restore request and reject anything malformed before the service sees it.
     *
     * @param   ExecutionContext  $context                 Actor and site the restore runs as.
     * @param   string            $definitionIdentifier    Definition UUID or handle naming the record type.
     * @param   string            $recordId                Identity of the archived or deleted record to bring back.
     * @param   int               $expectedVersion         Version the caller last read; a mismatch aborts the write.
     * @param   IdempotencyKey    $idempotencyKey          Token under which a retry replays the first outcome.
     * @param   ?string           $organizationIdentifier  Organization the record is scoped to; null when the
     *          definition is not organization-scoped.
     *
     * @throws  \InvalidArgumentException  When the definition identifier, record identity, expected
     *          version or organization identifier is malformed.
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
