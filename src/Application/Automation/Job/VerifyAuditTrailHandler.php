<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation\Job;

use InvalidArgumentException;
use Kumwe\App\Application\Automation\JobHandler;
use Kumwe\App\Audit\Application\AuditTrailVerifier;
use Kumwe\Context\Value\ExecutionContext;
use RuntimeException;

/**
 * Scheduled verification of the audit trail's digest chain and anchor ledger.
 *
 * The console command answers the same question on demand; this exists so nobody has to ask. A pass that
 * finds a divergence throws, which turns the finding into a failed job — retried under the queue's
 * backoff and finally dead-lettered, where the failure record carries the divergence class and position
 * for an operator to act on. That is deliberately louder than a log line: a trail that no longer verifies
 * is an incident, not a metric.
 *
 * Absent append-only enforcement is deliberately *not* treated the same way. It is a standing property
 * of the server — a managed MySQL that will not grant the privilege has the same posture tonight as it
 * had last night — so failing the job on it would dead-letter a job every night forever and train
 * operators to ignore the one signal that means something. The report carries the state either way; the
 * surface that reports it is `audit:verify`, which exits 2 for it, and the operations runbook, which
 * names the grant that fixes it. This job stays reserved for the thing that is genuinely an incident.
 *
 * @since  2.0.0
 */
final readonly class VerifyAuditTrailHandler implements JobHandler
{
    /**
     * Bind the handler to the verifier it drives.
     *
     * @param  AuditTrailVerifier  $trail  Verifier that walks the chain and the anchors.
     *
     * @since  2.0.0
     */
    public function __construct(private AuditTrailVerifier $trail)
    {
    }

    /**
     * Report the job type a schedule or queued job names this handler by.
     *
     * @return  string  The constant `audit.trail.verify`.
     *
     * @since   2.0.0
     */
    public function type(): string
    {
        return 'audit.trail.verify';
    }

    /**
     * Walk the trail and fail the job when it no longer verifies.
     *
     * @param   array<string, mixed>  $payload  Optional integer `batch_size` (default 1000, at most 10000).
     * @param   ExecutionContext      $context  System context the audit capability is checked against.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the batch size is not an integer in range.
     * @throws  RuntimeException  When the trail diverges from its recomputed evidence.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the job context may not
     *          manage the audit trail.
     *
     * @since   2.0.0
     */
    public function handle(array $payload, ExecutionContext $context): void
    {
        $batchSize = $payload['batch_size'] ?? 1000;
        if (!is_int($batchSize)) {
            throw new InvalidArgumentException('The audit verification batch size must be an integer.');
        }
        $report = $this->trail->verify($context, $batchSize);
        $divergence = $report->firstDivergence;
        if ($divergence !== null) {
            throw new RuntimeException(sprintf(
                'The audit trail diverged at position %d: %s (%s).',
                $divergence->position,
                $divergence->detail,
                $divergence->code,
            ));
        }
    }
}
