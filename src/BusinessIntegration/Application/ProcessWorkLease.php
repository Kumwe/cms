<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Application;

use InvalidArgumentException;
use Kumwe\App\BusinessIntegration\Domain\ProcessWorkItem;
use Kumwe\Context\Value\OrganizationContext;
use Kumwe\Context\Value\SiteContext;
use Ramsey\Uuid\Uuid;

/**
 * Process timer, command or compensation reserved under a fenced generation-pinned lease.
 *
 * @since  2.0.0
 */
final readonly class ProcessWorkLease
{
    /**
     * Capture process work and proof of its current reservation.
     *
     * @param   string           $processId          Parent process UUID.
     * @param   int              $processVersion     Transition version that emitted the work.
     * @param   string           $siteIdentifier     Normalized site that owns the parent process.
     * @param   ?string          $organizationId     Normalized organization partition, when present.
     * @param   ProcessWorkItem  $work               Requested effect.
     * @param   int              $attempts           Attempts including this claim.
     * @param   string           $workerId           Lease owner.
     * @param   string           $leaseToken         Fencing token.
     * @param   string           $runtimeGeneration  Exact runtime generation selecting the handler.
     * @param   ?string          $correlationId      End-to-end identifier the parent process was opened
     *          under, carried so a work item's log lines join the business operation that started it
     *          without a query against the process table; null when the store did not supply one.
     *
     * @throws  InvalidArgumentException  When lease metadata is malformed.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $processId,
        public int $processVersion,
        public string $siteIdentifier,
        public ?string $organizationId,
        public ProcessWorkItem $work,
        public int $attempts,
        public string $workerId,
        public string $leaseToken,
        public string $runtimeGeneration,
        public ?string $correlationId = null,
    ) {
        $site = SiteContext::fromString($siteIdentifier)->identifier();
        $organization = $organizationId === null
            ? null
            : OrganizationContext::fromString($organizationId)->identifier();
        if (
            !Uuid::isValid($processId)
            || $processVersion < 1
            || $site !== $siteIdentifier
            || $organization !== $organizationId
            || $attempts < 1
            || !Uuid::isValid($leaseToken)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/D', $workerId) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D', $runtimeGeneration) !== 1
        ) {
            throw new InvalidArgumentException('The process work lease metadata is invalid.');
        }
    }
}
