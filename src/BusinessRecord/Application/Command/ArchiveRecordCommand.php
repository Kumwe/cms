<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application\Command;

use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Application\Automation\IdempotencyKey;
use Kumwe\App\BusinessRecord\Application\RecordRequestGuard;

/**
 * Request to withdraw one business record from ordinary reads without deleting it.
 *
 * Construction is where a delivery-layer request stops being untrusted text: `RecordRequestGuard`
 * rejects a malformed definition identifier, record identity, version or organization scope here, so
 * `BusinessRecordService::archive()` can treat every field as already checked and no half-validated
 * command can exist. The other two fields are what make the operation safe to repeat — the expected
 * version pins the record the caller actually read, and the idempotency key makes a retry of the
 * same request replay the first outcome instead of archiving a second time.
 *
 * @since  2.0.0
 */
final readonly class ArchiveRecordCommand
{
    /**
     * Validate an archive request and freeze it as one command.
     *
     * @param   ExecutionContext  $context                 Actor, site and request the archive runs under.
     * @param   string            $definitionIdentifier    UUID or handle of the record's entity type.
     * @param   string            $recordId                Public identity of the record to archive.
     * @param   int               $expectedVersion         Version the caller read; the archive is refused when
     *          the stored record has moved past it.
     * @param   IdempotencyKey    $idempotencyKey          Token a retry repeats to replay this outcome instead
     *          of archiving again.
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
