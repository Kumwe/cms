<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation\Job;

use InvalidArgumentException;
use Kumwe\App\Application\Automation\JobHandler;
use Kumwe\App\Audit\Application\AuditRetentionService;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Scheduled driver for the audit retention window, off unless an operator configures one.
 *
 * The schedule ships disabled and with a zero window, and a zero or absent `retention_days` returns
 * without touching anything, so retention is opt-in twice over. That default is the conservative one for
 * evidence: an unbounded trail costs storage, while a trail silently trimmed by a default nobody chose
 * costs the ability to answer questions about the past. When a window is configured, the pass archives
 * before it removes and records what it removed.
 *
 * @since  2.0.0
 */
final readonly class EnforceAuditRetentionHandler implements JobHandler
{
    /**
     * Bind the handler to the retention service it drives.
     *
     * @param  AuditRetentionService  $retention  Service that archives and prunes one aged range per call.
     *
     * @since  2.0.0
     */
    public function __construct(private AuditRetentionService $retention)
    {
    }

    /**
     * Report the job type a schedule or queued job names this handler by.
     *
     * @return  string  The constant `audit.retention.enforce`.
     *
     * @since   2.0.0
     */
    public function type(): string
    {
        return 'audit.retention.enforce';
    }

    /**
     * Apply the configured retention window, or do nothing when none is configured.
     *
     * @param   array<string, mixed>  $payload  Integer `retention_days`; zero or absent disables the pass.
     * @param   ExecutionContext      $context  System context the audit capability is checked against.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the configured window is not a non-negative integer.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the job context may not
     *          manage the audit trail.
     *
     * @since   2.0.0
     */
    public function handle(array $payload, ExecutionContext $context): void
    {
        $days = $payload['retention_days'] ?? 0;
        if (!is_int($days) || $days < 0) {
            throw new InvalidArgumentException('The audit retention window must be a non-negative integer.');
        }
        if ($days === 0) {
            return;
        }
        $this->retention->prune($context, $days);
    }
}
