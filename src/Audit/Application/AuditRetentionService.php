<?php

declare(strict_types=1);

namespace Kumwe\App\Audit\Application;

use Kumwe\Context\Value\ExecutionContext;

/**
 * Port that enforces a configured audit retention window by archiving and then pruning aged rows.
 *
 * Retention is off unless a positive window is configured on the `audit.retention.enforce` schedule,
 * so an unconfigured installation keeps its trail unbounded. When enabled, a pass only ever removes
 * rows that are both older than the window and already sealed by an anchor: the range is exported to
 * a private checksummed archive first, a prune mark carrying the removed range's rolling digest and
 * the archive checksum is chained into the anchor ledger, and the deletion itself is recorded as an
 * audit event — so evidence is transformed into archived evidence, never silently destroyed.
 *
 * @since  2.0.0
 */
interface AuditRetentionService
{
    /**
     * Archive and prune every anchored audit row older than the retention window.
     *
     * @param   ExecutionContext  $context        Actor the pass is authorized and audited under.
     * @param   int               $retentionDays  Window in days; rows older than this become prunable.
     *
     * @return  AuditRetentionResult  What was archived and pruned, with its ledger and archive evidence.
     *
     * @throws  \InvalidArgumentException  When the window is not a positive number of days.
     * @throws  \RuntimeException  When archiving or the guarded delete cannot complete safely.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage
     *          the audit trail.
     *
     * @since   2.0.0
     */
    public function prune(ExecutionContext $context, int $retentionDays): AuditRetentionResult;
}
