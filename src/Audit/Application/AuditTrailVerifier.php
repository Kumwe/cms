<?php

declare(strict_types=1);

namespace Kumwe\App\Audit\Application;

use Kumwe\App\Audit\Domain\AuditVerificationReport;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Port that re-derives the audit trail's tamper evidence and reports the first divergence.
 *
 * A verification walk recomputes every stored event digest from the row's own fields, resolves every
 * witness link to a strictly earlier row, and re-derives every anchor — chain links, row counts and
 * rolling range digests — against the rows the database holds now. The walk is read-only and safe to
 * run while writers append; rows past the head observed at the start of the pass are simply left for
 * the next pass. Both the `audit:verify` console command and the scheduled verification job speak
 * through this port, so the two surfaces cannot drift apart in what they prove.
 *
 * @since  2.0.0
 */
interface AuditTrailVerifier
{
    /**
     * Walk the whole trail and its anchor ledger, stopping at the first divergence.
     *
     * @param   ExecutionContext  $context    Actor the verification is authorized and audited under.
     * @param   int               $batchSize  Rows fetched per batch during the walk, from 1 to 10000.
     *
     * @return  AuditVerificationReport  Counts of what was re-checked, and the first divergence if any.
     *
     * @throws  \InvalidArgumentException  When the batch size is outside its bounds.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not verify
     *          the audit trail.
     *
     * @since   2.0.0
     */
    public function verify(ExecutionContext $context, int $batchSize = 1000): AuditVerificationReport;
}
