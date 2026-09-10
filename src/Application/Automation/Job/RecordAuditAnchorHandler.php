<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation\Job;

use Kumwe\App\Application\Automation\JobHandler;
use Kumwe\App\Audit\Application\AuditAnchorWriter;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Scheduled driver that seals settled audit rows into the chained anchor ledger.
 *
 * Anchoring is what turns the trail's per-row digests into deletion and reordering evidence, and it is
 * driven from the queue rather than from the recorder for one reason: a recorder that maintained the
 * chain head would put every mutating transaction in the installation behind one row lock. Running the
 * seal as a single-lease installation-global job serializes anchor writes without touching the write
 * path at all. The residual exposure is the tail written since the last run, bounded by this schedule's
 * cadence, which is the tradeoff the design accepts in exchange for contention-free recording.
 *
 * @since  2.0.0
 */
final readonly class RecordAuditAnchorHandler implements JobHandler
{
    /**
     * Bind the handler to the anchor writer it drives.
     *
     * @param  AuditAnchorWriter  $anchors  Writer that seals one settled range per call.
     *
     * @since  2.0.0
     */
    public function __construct(private AuditAnchorWriter $anchors)
    {
    }

    /**
     * Report the job type a schedule or queued job names this handler by.
     *
     * @return  string  The constant `audit.anchor.record`.
     *
     * @since   2.0.0
     */
    public function type(): string
    {
        return 'audit.anchor.record';
    }

    /**
     * Seal whatever has settled since the last anchor.
     *
     * @param   array<string, mixed>  $payload  Unused; the anchor range is derived from the ledger.
     * @param   ExecutionContext      $context  System context the audit capability is checked against.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the job context may not
     *          manage the audit trail.
     *
     * @since   2.0.0
     */
    public function handle(array $payload, ExecutionContext $context): void
    {
        $this->anchors->anchor($context);
    }
}
