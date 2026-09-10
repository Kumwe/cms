<?php

declare(strict_types=1);

namespace Kumwe\App\Audit\Application;

use Kumwe\Context\Value\ExecutionContext;

/**
 * Port that seals the audit rows written since the last anchor into a new chained anchor.
 *
 * Anchoring is what upgrades the trail's per-row digests into deletion evidence: once a range is
 * sealed, removing or reordering any row inside it changes the anchored count or rolling digest. The
 * writer is driven by the scheduled `audit.anchor.record` job, whose single fenced lease keeps anchor
 * writes serialized without ever blocking the recorders appending to the trail; the residual exposure
 * is only the tail written after the newest anchor, bounded by the anchor cadence.
 *
 * @since  2.0.0
 */
interface AuditAnchorWriter
{
    /**
     * Seal every audit row past the newest anchor into one new anchor row.
     *
     * @param   ExecutionContext  $context  Actor the anchor write is authorized and audited under.
     *
     * @return  ?int  Sequence number of the anchor written, or null when no unsealed rows existed.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage
     *          the audit trail.
     *
     * @since   2.0.0
     */
    public function anchor(ExecutionContext $context): ?int;
}
