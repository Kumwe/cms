<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Application;

use InvalidArgumentException;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable;
use Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordQueryPurpose;
use Kumwe\App\BusinessReporting\Domain\ExportArtifact;
use Kumwe\App\BusinessReporting\Domain\ExportArtifactStatus;
use Kumwe\App\BusinessReporting\Domain\ReportDefinition;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Identity\Application\Authentication\PrincipalGrant;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Requests, inspects and downloads policy-bound queued report exports.
 *
 * @since  2.0.0
 */
final readonly class ExportService
{
    /**
     * Wire export metadata, queueing, authorization, storage and audit boundaries.
     *
     * @param  ReportDefinitionRegistry      $reports        Active report contributions.
     * @param  ReportScopeResolver           $scopes         Installed report source-scope resolver.
     * @param  ExportArtifactRepository      $artifacts      Durable immutable metadata versions.
     * @param  ExportArtifactStorage         $storage        Private verified byte store.
     * @param  ExportJobDispatcher           $jobs           Durable internal queue producer.
     * @param  ExportPolicySnapshotProvider  $policies       Canonical record access-plan snapshots.
     * @param  AuthorizationGateway          $authorization  Deny-by-default capability gateway.
     * @param  TransactionManager            $transactions   Metadata and audit transaction owner.
     * @param  AuditRecorder                 $audit          Redacted audit sink.
     * @param  ClockInterface                $clock          Trusted wall clock.
     * @param  ?RecordExportReportProvider   $recordExports  Derived record-set export reports; null keeps
     *         resolution limited to contributed reports.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ReportDefinitionRegistry $reports,
        private ReportScopeResolver $scopes,
        private ExportArtifactRepository $artifacts,
        private ExportArtifactStorage $storage,
        private ExportJobDispatcher $jobs,
        private ExportPolicySnapshotProvider $policies,
        private AuthorizationGateway $authorization,
        private TransactionManager $transactions,
        private AuditRecorder $audit,
        private ClockInterface $clock,
        private ?RecordExportReportProvider $recordExports = null,
    ) {
    }

    /**
     * Persist and enqueue one export after validating scope, parameters, capability and current policy.
     *
     * @param   ExecutionContext      $context                 Authenticated requesting actor.
     * @param   string                $reportIdentifier        Namespaced report handle.
     * @param   array<string, mixed>  $parameters              Declared typed report parameters.
     * @param   ?string               $organizationIdentifier  Organization record scope.
     * @param   int                   $retentionSeconds        Download lifetime, 60 seconds to seven days.
     *
     * @return  ExportArtifact  Queued immutable artifact metadata.
     *
     * @throws  InvalidArgumentException  When retention or parameters are invalid.
     * @throws  Throwable  When persistence or durable dispatch fails.
     *
     * @since   2.0.0
     */
    public function request(
        ExecutionContext $context,
        string $reportIdentifier,
        array $parameters = [],
        ?string $organizationIdentifier = null,
        int $retentionSeconds = 86_400,
    ): ExportArtifact {
        if ($retentionSeconds < 60 || $retentionSeconds > 604_800) {
            throw new InvalidArgumentException('Export retention must be between one minute and seven days.');
        }
        $report = $this->report($context, $reportIdentifier);
        $principal = AuthenticatedPrincipal::of($context);
        if ($principal === null) {
            throw new InvalidArgumentException('A report export must have an accountable human actor.');
        }
        $authorityGrantRows = self::authorityGrantRows($principal);
        $this->assertSurface($report, $context->surface());
        $this->authorize($context, $report);
        $parameters = $this->bindParameters($report, $parameters);
        return $this->transactions->transactional(function () use (
            $context,
            $report,
            $parameters,
            $organizationIdentifier,
            $retentionSeconds,
            $authorityGrantRows,
        ): ExportArtifact {
            $recordOrganization = $this->scopes->resolve($context, $report, $organizationIdentifier);
            $policy = $this->policies->snapshot(
                $context,
                $report,
                $recordOrganization,
                BusinessRecordQueryPurpose::Export,
            );
            $now = $this->clock->now();
            $organization = $context->organization()?->identifier();
            $workspace = $context->workspace()?->identifier();
            $parameterDigest = CanonicalDefinitionJson::checksum([
                'report_checksum' => $report->checksum(),
                'parameters' => $parameters,
                'organization' => $recordOrganization,
                'workspace' => $workspace,
            ]);
            $id = Uuid::uuid7()->toString();
            $filenameStem = substr(str_replace('.', '_', $report->identifier()), 0, 80);
            $artifact = new ExportArtifact(
                $id,
                $report->identifier(),
                $report->version,
                $report->checksum(),
                $context->actorId(),
                $context->site()->identifier(),
                $organization,
                $workspace,
                $context->surface(),
                $context->approvalFingerprint(),
                $policy,
                $parameters,
                $parameterDigest,
                ExportArtifactStatus::Queued,
                $now,
                $now->modify('+' . $retentionSeconds . ' seconds'),
                null,
                null,
                $filenameStem . '-' . $now->format('Ymd-His') . '.csv',
                null,
                null,
                null,
                null,
                null,
                null,
                1,
                authorityGrantRows: $authorityGrantRows,
            );
            $this->artifacts->add($artifact);
            $this->audit($context, $artifact, 'business.report.export.request', 'success', $now);
            $this->jobs->dispatch($context, $artifact->id);

            return $artifact;
        });
    }

    /**
     * Serialize the requesting credential's exact effective scoped-grant ceiling.
     *
     * @param   AuthenticatedPrincipal  $principal  Accountable human principal requesting the export.
     *
     * @return  list<array{capability: string, scope_type: string, scope_identifier: ?string}>
     *          Canonically ordered grant rows suitable for the private artifact ledger.
     *
     * @since   2.0.0
     */
    private static function authorityGrantRows(AuthenticatedPrincipal $principal): array
    {
        return array_map(static function (PrincipalGrant $grant): array {
            return [
                'capability' => $grant->capability()->value(),
                'scope_type' => $grant->scope()->type(),
                'scope_identifier' => $grant->scope()->identifier(),
            ];
        }, $principal->grants());
    }

    /**
     * Return current metadata only while current actor, authority, report and policy still match.
     *
     * @param   ExecutionContext  $context     Authenticated requesting actor.
     * @param   string            $artifactId  Opaque artifact UUID.
     *
     * @return  ExportArtifact  Current authorized state.
     *
     * @throws  ExportArtifactUnavailable  When any binding differs or the artifact expired.
     *
     * @since   2.0.0
     */
    public function status(ExecutionContext $context, string $artifactId): ExportArtifact
    {
        $this->assertArtifactId($artifactId);

        return $this->transactions->transactional(function () use ($context, $artifactId): ExportArtifact {
            $artifact = $this->artifacts->find($artifactId)
                ?? throw new ExportArtifactUnavailable('The export artifact is unavailable.');
            $this->assertCurrent($context, $artifact);

            return $artifact;
        });
    }

    /**
     * Open one completed artifact only after current authorization, policy and checksum verification.
     *
     * @param   ExecutionContext  $context     Authenticated requesting actor.
     * @param   string            $artifactId  Opaque artifact UUID.
     *
     * @return  ExportDownload  Verified stream and safe response metadata.
     *
     * @throws  ExportArtifactUnavailable  When the artifact is not completed or any binding differs.
     *
     * @since   2.0.0
     */
    public function download(ExecutionContext $context, string $artifactId): ExportDownload
    {
        $this->assertArtifactId($artifactId);

        return $this->transactions->transactional(function () use ($context, $artifactId): ExportDownload {
            $artifact = $this->status($context, $artifactId);
            if (
                $artifact->status !== ExportArtifactStatus::Completed
                || $artifact->storageKey === null || $artifact->size === null || $artifact->checksum === null
            ) {
                throw new ExportArtifactUnavailable('The export artifact is unavailable.');
            }
            $stored = new StoredExportArtifact($artifact->storageKey, $artifact->size, $artifact->checksum);
            $stream = $this->storage->open($stored);
            $this->transactions->afterRollback(static function () use ($stream): void {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            });
            $download = new ExportDownload(
                $stream,
                $artifact->filename,
                $artifact->size,
                $artifact->checksum,
            );
            $this->audit(
                $context,
                $artifact,
                'business.report.export.download',
                'success',
                $this->clock->now(),
            );

            return $download;
        });
    }

    /**
     * Collapse malformed and unavailable artifact identifiers into the same non-enumerating refusal.
     *
     * @param   string  $artifactId  Candidate artifact UUID from an authenticated delivery surface.
     *
     * @return  void
     *
     * @throws  ExportArtifactUnavailable  When the identifier is not one canonical lowercase UUID.
     *
     * @since   2.0.0
     */
    private function assertArtifactId(string $artifactId): void
    {
        if (
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
                $artifactId,
            ) !== 1
        ) {
            throw new ExportArtifactUnavailable('The export artifact is unavailable.');
        }
    }

    /**
     * Require the supplied execution context to remain current and authorized.
     *
     * @param   ExecutionContext  $context   Authenticated execution context for authorization and audit.
     * @param   ExportArtifact    $artifact  Immutable export artifact being transitioned.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertCurrent(ExecutionContext $context, ExportArtifact $artifact): void
    {
        if (
            $artifact->expiresAt <= $this->clock->now()
            || $artifact->actorId !== $context->actorId()
            || $artifact->siteIdentifier !== $context->site()->identifier()
            || $artifact->organizationIdentifier !== $context->organization()?->identifier()
            || $artifact->workspaceIdentifier !== $context->workspace()?->identifier()
            || $artifact->surface !== $context->surface()
            || !hash_equals($artifact->authorityFingerprint, $context->approvalFingerprint())
        ) {
            throw new ExportArtifactUnavailable('The export artifact is unavailable.');
        }
        try {
            $report = $this->report($context, $artifact->reportIdentifier);
            $this->assertSurface($report, $context->surface());
            if (
                $report->version !== $artifact->reportVersion
                || !hash_equals($report->checksum(), $artifact->definitionChecksum)
            ) {
                throw new ExportArtifactUnavailable('The export artifact is unavailable.');
            }
            $this->authorize($context, $report);
            $recordOrganization = $this->scopes->resolve(
                $context,
                $report,
                $artifact->organizationIdentifier,
            );
            $policy = $this->policies->snapshot(
                $context,
                $report,
                $recordOrganization,
                BusinessRecordQueryPurpose::Export,
            );
            if (!hash_equals($artifact->policySnapshot, $policy)) {
                throw new ExportArtifactUnavailable('The export artifact is unavailable.');
            }
        } catch (ExportArtifactUnavailable $exception) {
            throw $exception;
        } catch (
            ReportUnavailable
            | InvalidArgumentException
            | BusinessRecordDefinitionUnavailable
            | BusinessRecordSchemaUnavailable $exception
        ) {
            throw new ExportArtifactUnavailable('The export artifact is unavailable.', 0, $exception);
        }
    }

    /**
     * Resolve a contributed report, or fall back to one derived record-set export report.
     *
     * @param   ExecutionContext  $context     Authenticated actor and site scope.
     * @param   string            $identifier  Namespaced report handle.
     *
     * @return  ReportDefinition  Contributed or derived immutable definition.
     *
     * @throws  ReportUnavailable  When neither the registry nor derivation can answer the handle.
     *
     * @since   2.0.0
     */
    private function report(ExecutionContext $context, string $identifier): ReportDefinition
    {
        try {
            return $this->reports->get($identifier);
        } catch (ReportUnavailable $exception) {
            if ($this->recordExports === null) {
                throw $exception;
            }

            return $this->recordExports->resolve($context, $identifier);
        }
    }

    /**
     * Authorize the current principal for the report and export capabilities inferred from the definition.
     *
     * @param   ExecutionContext  $context  Authenticated execution context for authorization and audit.
     * @param   ReportDefinition  $report   Signed report definition governing query behavior.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function authorize(ExecutionContext $context, ReportDefinition $report): void
    {
        $resource = AuthorizationResource::item('business_report', $report->identifier());
        if (
            !$this->authorization->decide(
                $context,
                Capability::fromString($report->requiredCapability),
                $resource,
            )->allowed || !$this->authorization->decide(
                $context,
                Capability::fromString('business.record.export'),
                $resource,
            )->allowed
        ) {
            throw new ReportUnavailable('The report is unavailable.');
        }
    }

    /**
     * Bind and validate caller-supplied report parameters.
     *
     * @param   ReportDefinition      $report    Signed report definition governing query behavior.
     * @param   array<string, mixed>  $supplied  Caller-provided values keyed by report parameter identifier.
     *
     * @return  array<string, mixed>
     *
     * @since   2.0.0
     */
    private function bindParameters(ReportDefinition $report, array $supplied): array
    {
        $declared = [];
        $bound = [];
        foreach ($report->parameters as $parameter) {
            $declared[$parameter->name] = true;
            $value = array_key_exists($parameter->name, $supplied)
                ? $supplied[$parameter->name]
                : $parameter->defaultValue;
            if ($value === null && !$parameter->required) {
                continue;
            }
            $bound[$parameter->name] = $parameter->assertValue($value);
        }
        if (array_diff_key($supplied, $declared) !== []) {
            throw new InvalidArgumentException('An export contains an undeclared report parameter.');
        }
        ksort($bound, SORT_STRING);

        return $bound;
    }

    /**
     * Require the report to be available on the requested delivery surface.
     *
     * @param   ReportDefinition      $report   Signed report definition governing query behavior.
     * @param   AuthenticatedSurface  $surface  Delivery surface requesting report execution.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertSurface(ReportDefinition $report, AuthenticatedSurface $surface): void
    {
        if (
            ($surface === AuthenticatedSurface::Administrator && !$report->administratorVisible)
            || ($surface === AuthenticatedSurface::Portal && !$report->portalVisible)
            || $surface === AuthenticatedSurface::Recovery
        ) {
            throw new ReportUnavailable('The report is unavailable.');
        }
    }

    /**
     * Append an immutable audit record for the export operation.
     *
     * @param   ExecutionContext    $context   Authenticated execution context for authorization and audit.
     * @param   ExportArtifact      $artifact  Immutable export artifact being transitioned.
     * @param   string              $action    Stable audited action name.
     * @param   string              $outcome   Stable audited result classification.
     * @param   \DateTimeImmutable  $now       Authoritative timestamp for the state transition.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function audit(
        ExecutionContext $context,
        ExportArtifact $artifact,
        string $action,
        string $outcome,
        \DateTimeImmutable $now,
    ): void {
        $this->audit->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $now,
            $context->actorId(),
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
            ],
        ));
    }
}
