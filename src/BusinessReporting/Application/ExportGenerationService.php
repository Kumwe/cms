<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Application;

use DateTimeImmutable;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordQueryPurpose;
use Kumwe\App\BusinessReporting\Domain\ExportArtifact;
use Kumwe\App\BusinessReporting\Domain\ExportArtifactStatus;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Idempotent queue-side generation of one authorization-bound export artifact.
 *
 * @since  2.0.0
 */
final readonly class ExportGenerationService
{
    /**
     * Attempt-fenced immutable byte publisher.
     *
     * @var    ExportAttemptPublisher
     * @since  2.0.0
     */
    private ExportAttemptPublisher $publisher;

    /**
     * Wire job execution to fresh actor authority, report execution, storage and metadata.
     *
     * @param  ExportArtifactRepository        $artifacts     Durable metadata ledger.
     * @param  ExportExecutionContextResolver  $contexts      Fresh original-actor context resolver.
     * @param  ExportService                   $exports       Current binding and authorization verifier.
     * @param  ReportService                   $reports       Policy-aware report executor.
     * @param  ReportCsvEncoder                $csv           Deterministic safe CSV encoder.
     * @param  ExportArtifactStorage           $storage       Private immutable artifact store.
     * @param  TransactionManager              $transactions  Metadata/audit transaction owner.
     * @param  AuditRecorder                   $audit         Redacted audit sink.
     * @param  ClockInterface                  $clock         Trusted wall clock.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ExportArtifactRepository $artifacts,
        private ExportExecutionContextResolver $contexts,
        private ExportService $exports,
        private ReportService $reports,
        private ReportCsvEncoder $csv,
        ExportArtifactStorage $storage,
        private TransactionManager $transactions,
        private AuditRecorder $audit,
        private ClockInterface $clock,
    ) {
        $this->publisher = new ExportAttemptPublisher($artifacts, $storage, $transactions);
    }

    /**
     * Generate or safely resume one artifact by id.
     *
     * @param   string            $artifactId     Canonical artifact UUID from the queue payload.
     * @param   ExecutionContext  $workerContext  Narrow system context that claimed the job.
     *
     * @return  void
     *
     * @throws  ExportGenerationRejected  When authority, policy, report or expiry no longer matches.
     * @throws  Throwable  On a transient execution, storage or persistence failure.
     *
     * @since   2.0.0
     */
    public function generate(string $artifactId, ExecutionContext $workerContext): void
    {
        $artifact = $this->artifacts->find($artifactId)
            ?? throw new ExportGenerationRejected('The export request is unavailable.');
        if (
            $artifact->status === ExportArtifactStatus::Completed
            || $artifact->status === ExportArtifactStatus::Failed
        ) {
            return;
        }
        $attempt = $artifact;
        $attemptStarted = false;
        try {
            $this->transactions->transactional(function () use (
                $artifact,
                $workerContext,
                &$attempt,
                &$attemptStarted,
            ): void {
                try {
                    $context = $this->contexts->resolve($artifact, $workerContext);
                } catch (ExportGenerationRejected $exception) {
                    throw new ExportArtifactUnavailable(
                        'The export artifact is unavailable.',
                        0,
                        $exception,
                    );
                }
                $current = $this->exports->status($context, $artifact->id);
                $attempt = $current;
                $active = match ($current->status) {
                    ExportArtifactStatus::Completed, ExportArtifactStatus::Failed => null,
                    ExportArtifactStatus::Queued => $this->startAttempt($current),
                    ExportArtifactStatus::Running => $current,
                };
                if ($active === null) {
                    return;
                }
                $attempt = $active;
                $attemptStarted = true;
                $result = $this->reports->execute(new ReportExecutionRequest(
                    $context,
                    $active->reportIdentifier,
                    $active->parameters,
                    $active->organizationIdentifier,
                    BusinessRecordQueryPurpose::Export,
                ));
                if (!hash_equals($active->definitionChecksum, $result->definitionChecksum)) {
                    throw new ExportGenerationRejected('The report definition changed during export.');
                }
                $this->publisher->publish(
                    $active,
                    $this->csv->encode($result),
                    $this->clock->now(),
                    count($result->rows),
                    $result->queryDigest,
                    function (ExportArtifact $completed): void {
                        $this->audit(
                            $completed,
                            'business.report.export.complete',
                            'success',
                            $completed->completedAt,
                        );
                    },
                );
            });
        } catch (Throwable $exception) {
            if ($exception instanceof ExportArtifactUnavailable) {
                $this->reject($artifact, 'authorization_changed');
                $cause = $exception->getPrevious();
                throw new ExportGenerationRejected(
                    'The export authority or policy changed.',
                    0,
                    $cause instanceof ExportGenerationRejected ? $cause : $exception,
                );
            }
            if ($exception instanceof ReportRowLimitExceeded) {
                $this->reject($artifact, 'row_limit');
                throw new ExportGenerationRejected('The export exceeds its configured row limit.', 0, $exception);
            }
            if ($exception instanceof ExportGenerationRejected) {
                $this->reject($artifact, 'definition_changed');
                throw $exception;
            }
            if ($attemptStarted) {
                $this->recordAttemptFailure($attempt);
            }
            throw $exception;
        }
    }

    /**
     * Persist the running state and its audit record inside the caller's policy-fenced transaction.
     *
     * @param   ExportArtifact  $artifact  Current queued metadata version.
     *
     * @return  ExportArtifact  Running immutable successor.
     *
     * @since   2.0.0
     */
    private function startAttempt(ExportArtifact $artifact): ExportArtifact
    {
        $running = $artifact->start($this->clock->now());
        $this->artifacts->save($running, $artifact->version);
        $this->audit($running, 'business.report.export.start', 'success', $running->startedAt);

        return $running;
    }

    /**
     * Persist transient-attempt evidence only after its policy-fenced transaction has rolled back.
     *
     * @param   ExportArtifact  $artifact  Running attempt whose outer transaction did not commit.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function recordAttemptFailure(ExportArtifact $artifact): void
    {
        $this->transactions->transactional(function () use ($artifact): void {
            $this->audit($artifact, 'business.report.export.attempt', 'failure', $this->clock->now());
        });
    }

    /**
     * Reject export generation with a durable failure classification.
     *
     * @param   ExportArtifact  $artifact  Immutable export artifact being transitioned.
     * @param   string          $code      Sanitized durable rejection code.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function reject(ExportArtifact $artifact, string $code): void
    {
        $current = $this->artifacts->find($artifact->id);
        if (
            $current === null || $current->status === ExportArtifactStatus::Completed
            || $current->status === ExportArtifactStatus::Failed
        ) {
            return;
        }
        $failed = $current->fail($this->clock->now(), $code);
        $this->transactions->transactional(function () use ($current, $failed): void {
            $this->artifacts->save($failed, $current->version);
            $this->audit($failed, 'business.report.export.generate', 'failure', $failed->completedAt);
        });
    }

    /**
     * Append an immutable audit record for the export operation.
     *
     * @param   ExportArtifact      $artifact  Immutable export artifact being transitioned.
     * @param   string              $action    Stable audited action name.
     * @param   string              $outcome   Stable audited result classification.
     * @param   ?DateTimeImmutable  $now       Authoritative timestamp for the state transition.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function audit(
        ExportArtifact $artifact,
        string $action,
        string $outcome,
        ?DateTimeImmutable $now,
    ): void {
        $this->audit->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $now ?? $this->clock->now(),
            $artifact->actorId,
            $action,
            'report_export',
            $artifact->id,
            $outcome,
            [
                'report_identifier' => $artifact->reportIdentifier,
                'definition_checksum' => $artifact->definitionChecksum,
                'parameter_digest' => $artifact->parameterDigest,
                'status' => $artifact->status->value,
                'row_count' => $artifact->rowCount,
                'artifact_checksum' => $artifact->checksum,
                'failure_code' => $artifact->failureCode,
            ],
        ));
    }
}
