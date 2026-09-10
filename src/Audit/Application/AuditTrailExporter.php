<?php

declare(strict_types=1);

namespace Kumwe\App\Audit\Application;

use Kumwe\Context\Value\ExecutionContext;

/**
 * Port that writes a protected, redacted archive of an audit trail range for preservation.
 *
 * This is the sanctioned read path for the full trail: capability-gated, written straight into private
 * permission-locked storage, credential-shaped metadata redacted on the way out, and the export itself
 * recorded as an audit event so the trail always names who took a copy of it and what that copy
 * contained. Incident response and the retention job both preserve evidence through this port.
 *
 * @since  2.0.0
 */
interface AuditTrailExporter
{
    /**
     * Export one position range of the trail into a checksummed private NDJSON archive.
     *
     * @param   ExecutionContext  $context       Actor the export is authorized and audited under.
     * @param   ?int              $fromPosition  First position to include, or null for the trail's start.
     * @param   ?int              $toPosition    Last position to include, or null for the current head.
     *
     * @return  AuditTrailExport  Manifest naming the archive, its range, counts and anchor reference.
     *
     * @throws  \InvalidArgumentException  When the requested range is inverted or not positive.
     * @throws  \RuntimeException  When the range holds no events or the archive cannot be written.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not export
     *          the audit trail.
     *
     * @since   2.0.0
     */
    public function export(
        ExecutionContext $context,
        ?int $fromPosition = null,
        ?int $toPosition = null,
    ): AuditTrailExport;
}
