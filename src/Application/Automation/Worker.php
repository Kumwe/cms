<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation;

use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Authorization\AuthorizationResourceOwnershipUnknown;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Application\Authorization\ResourceSiteOwnership;
use Kumwe\App\Application\Authorization\SystemPrincipal;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use RuntimeException;
use Throwable;

/**
 * The durable half of job execution: claim one job, run its handler under a fence, settle the row.
 *
 * `queue:work` loops on `runOnce()` and owns nothing else, so every crash-recovery decision lives
 * here. A claimed job is executed under a context built for the job rather than for the worker: a
 * site-scoped job runs as the system principal against the site that durably owns it, while an
 * installation-global one runs as the narrow principal its type declares, which stops a job inheriting
 * the worker's own reach. Every settlement goes back through the queue carrying the claim's fencing
 * token, so a worker that lost its lease cannot complete or fail a job a sibling has since taken. The
 * handler itself runs under a wall-clock alarm, so work that wedges surfaces as a failed job instead
 * of as a worker process that never returns.
 *
 * @since  2.0.0
 */
final readonly class Worker
{
    /**
     * Wire the worker to the queue it drains and the collaborators that scope each job.
     *
     * @param  JobQueue               $queue             Queue claimed from, and settled through.
     * @param  JobHandlerRegistry     $handlers          Lookup from a claimed job's type to its handler.
     * @param  AuthorizationGateway   $authorization     Decides whether the caller may operate a queue.
     * @param  ResourceSiteOwnership  $ownership         Resolves the site that durably owns a job.
     * @param  SystemPrincipal        $system            Issues the context a site-scoped job runs under.
     * @param  JobExecutionScope      $jobScope          Re-checks a row's stored execution class.
     * @param  GlobalJobPrincipals    $globalPrincipals  Issues the context a global job type runs under.
     *
     * @since  2.0.0
     */
    public function __construct(
        private JobQueue $queue,
        private JobHandlerRegistry $handlers,
        private AuthorizationGateway $authorization,
        private ResourceSiteOwnership $ownership,
        private SystemPrincipal $system,
        private JobExecutionScope $jobScope,
        private GlobalJobPrincipals $globalPrincipals,
    ) {
    }

    /**
     * Claim at most one job from a queue, execute it, and settle its row.
     *
     * The return value is the caller's polling signal: false means the queue had nothing to hand out
     * and the loop should sleep. No failure a job produces escapes here. A handler failure is recorded
     * through the queue, permanently for a `PermanentFailure` and for another attempt otherwise, while
     * an unregistered job type, a stored execution class that disagrees with the declaration, and a
     * global type with no principal registered are each failed permanently without a handler running
     * at all. The two races against site retirement are the exception: a job whose owning site
     * disappears between the claim and the execution is left alone for its lease to expire, and a
     * failure that cannot be recorded for that same reason is dropped, because either way the
     * ownership-filtered claim query stops the orphaned row being selected again.
     *
     * @param   ExecutionContext  $context                Caller the worker capability is checked against.
     * @param   string            $queueName              Queue to claim from.
     * @param   string            $workerId               Identity this worker leases and heartbeats under.
     * @param   int               $leaseSeconds           Initial lease a claimed job is reserved for.
     * @param   int               $maximumHandlerSeconds  Wall-clock budget the handler is aborted after.
     *
     * @return  bool  True when a job was claimed, false when the queue had nothing available.
     *
     * @throws  AuthorizationDenied  When the caller may not operate this queue, or when recording a
     *          failure is refused for any reason other than the job's site having been retired.
     *
     * @since   2.0.0
     */
    public function runOnce(
        ExecutionContext $context,
        string $queueName,
        string $workerId,
        int $leaseSeconds = 60,
        int $maximumHandlerSeconds = 240,
    ): bool {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('system.worker.operate'),
            AuthorizationResource::item('queue', $queueName),
        );
        $this->queue->heartbeat($context, $workerId, $queueName);
        $job = $this->queue->claim($context, $queueName, $workerId, $leaseSeconds);

        if ($job === null) {
            return false;
        }

        try {
            $executionClass = $this->jobScope->assertStoredClass($job->type, $job->executionClass);
            $jobContext = $executionClass === JobExecutionClass::Installation
                ? $this->globalPrincipals->context(
                    $job->type,
                    $this->jobScope,
                    'global-job-' . $job->id,
                    $context->correlationId(),
                )
                : $this->system->context(
                    $this->ownership->scopeFor(AuthorizationResource::item('job', $job->id))->requireSite(),
                    'worker-job-' . $job->id,
                    $context->correlationId(),
                );
        } catch (AuthorizationResourceOwnershipUnknown) {
            // A site may be retired immediately after the claim transaction commits.
            // Never execute an orphaned job; its fenced lease will expire, while the
            // ownership-filtered claim query prevents it from being selected again.
            return true;
        } catch (Throwable $failure) {
            $this->queue->fail($context, $job, $workerId, $failure, true);

            return true;
        }
        try {
            $this->queue->heartbeat($context, $workerId, $queueName, $job->id);
            $handler = $this->handlers->find($job->type);

            if ($handler === null) {
                throw new PermanentFailure('No handler is registered for the job type.');
            }

            $this->handleWithinRuntimeLease(function () use (
                $context,
                $handler,
                $job,
                $jobContext,
                $leaseSeconds,
                $workerId,
                $queueName,
            ): void {
                if ($handler instanceof LeaseAwareJobHandler) {
                    $handler->handleWithLease(
                        $job->payload,
                        $jobContext,
                        new JobLeaseContext(
                            $job->id,
                            $leaseSeconds,
                            function (int $seconds) use ($context, $job, $workerId, $queueName): void {
                                $this->queue->renew($context, $job, $workerId, $seconds);
                                $this->queue->heartbeat($context, $workerId, $queueName, $job->id);
                            },
                        ),
                    );
                } else {
                    $handler->handle($job->payload, $jobContext);
                }
            }, $maximumHandlerSeconds);
            $this->queue->complete($context, $job, $workerId);
        } catch (Throwable $failure) {
            try {
                $this->queue->fail(
                    $context,
                    $job,
                    $workerId,
                    $failure,
                    $failure instanceof PermanentFailure,
                );
            } catch (AuthorizationDenied $denied) {
                if ($denied->resourceType !== 'job' || $denied->reason !== 'resource_site_unknown') {
                    throw $denied;
                }
                // Site retirement won the race with execution. Preserve the fence and
                // let the lease expire; disabled/orphaned jobs are excluded from claims.
            }
        }

        return true;
    }

    /**
     * Retire this worker's heartbeat so operators stop seeing it as a live consumer.
     *
     * Call it from the shutdown path of the worker process. Nothing about job recovery depends on it —
     * an in-flight job is recovered by its lease expiring, not by the heartbeat going away — so a
     * worker that dies without disconnecting only leaves a stale row behind.
     *
     * @param   ExecutionContext  $context    Caller the worker capability is checked against.
     * @param   string            $workerId   Identity whose heartbeat row is removed.
     * @param   string            $queueName  Queue the heartbeat was published against.
     *
     * @return  void
     *
     * @throws  AuthorizationDenied  When the caller may not operate this queue.
     *
     * @since   2.0.0
     */
    public function disconnect(ExecutionContext $context, string $workerId, string $queueName): void
    {
        $this->queue->disconnect($context, $workerId, $queueName);
    }

    /**
     * Run one handler invocation under a signal deadline, restoring the previous alarm handler after.
     *
     * A handler that never returns would otherwise pin the worker process for good while its lease
     * quietly expires and a sibling re-claims the same job; the alarm converts that into an ordinary
     * failure the caller can record and move past. The bound itself belongs to `RuntimeDeadline`, which
     * the integration worker shares, so both durable workers wedge and recover the same way rather than
     * one of them having the defence and the other only the intention.
     *
     * @param   callable(): void  $operation              Handler invocation to run under the deadline.
     * @param   int               $maximumHandlerSeconds  Wall-clock seconds before the alarm fires.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the deadline is not positive or the pcntl signal functions are
     *          missing, and when the alarm fires before the invocation returns.
     *
     * @since   2.0.0
     */
    private function handleWithinRuntimeLease(callable $operation, int $maximumHandlerSeconds): void
    {
        (new RuntimeDeadline(
            $maximumHandlerSeconds,
            'The job exceeded its maximum runtime lease.',
        ))->run($operation);
    }
}
