<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Application;

use DateTimeImmutable;
use Kumwe\App\BusinessRecord\Domain\BusinessRecordIdempotency;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Port for non-enumerating caller-bound business operation lookup.
 *
 * @since  2.0.0
 */
interface BusinessOperationStatusRepository
{
    /**
     * Find one unexpired operation under the actor's exact site and organization scope.
     *
     * @param   ExecutionContext   $context      Authenticated actor and scope.
     * @param   string             $operationId  Caller-supplied operation identity.
     * @param   DateTimeImmutable  $now          Instant used for expiry filtering.
     *
     * @return  BusinessRecordIdempotency|null  Verified ledger entry, or null for every mismatch.
     *
     * @since   2.0.0
     */
    public function find(
        ExecutionContext $context,
        string $operationId,
        DateTimeImmutable $now,
    ): ?BusinessRecordIdempotency;
}
