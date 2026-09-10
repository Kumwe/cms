<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessReporting;

use DateTimeImmutable;
use Kumwe\App\Application\Authorization\AuthorizationDecision;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessRecord\Application\BusinessRecordDefinitionResolver;
use Kumwe\App\BusinessRecord\Application\BusinessRecordView;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable;
use Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordQueryPurpose;
use Kumwe\App\BusinessRecord\Application\RecordBrowseResult;
use Kumwe\App\BusinessRecord\Application\ResolvedBusinessDefinition;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\App\BusinessReporting\Application\BusinessRecordReportReader;
use Kumwe\App\BusinessReporting\Application\ExportArtifactRepository;
use Kumwe\App\BusinessReporting\Application\ExportArtifactStorage;
use Kumwe\App\BusinessReporting\Application\ExportJobDispatcher;
use Kumwe\App\BusinessReporting\Application\ExportPolicySnapshotProvider;
use Kumwe\App\BusinessReporting\Application\ExportService;
use Kumwe\App\BusinessReporting\Application\RecordExportReportProvider;
use Kumwe\App\BusinessReporting\Application\ReportDefinitionRegistry;
use Kumwe\App\BusinessReporting\Application\ReportExecutionRequest;
use Kumwe\App\BusinessReporting\Application\ReportScopeResolver;
use Kumwe\App\BusinessReporting\Application\ReportService;
use Kumwe\App\BusinessReporting\Application\ReportUnavailable;
use Kumwe\App\BusinessReporting\Domain\ExportArtifact;
use Kumwe\App\BusinessReporting\Domain\ExportArtifactStatus;
use Kumwe\App\BusinessReporting\Domain\ReportDefinition;
use Kumwe\App\BusinessSchema\Domain\PhysicalColumnBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalSchemaBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalTableBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalTableKind;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallation;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(ExportService::class)]
#[CoversClass(ReportService::class)]
#[CoversClass(RecordExportReportProvider::class)]
final class RecordExportPipelineTest extends TestCase
{
    public function testDerivedRecordExportQueuesThroughTheSharedExportService(): void
    {
        $definition = $this->definition();
        $provider = new RecordExportReportProvider($this->resolver($definition), new FieldTypeRegistry());
        $stored = null;
        $dispatched = [];
        $auditActions = [];
        $artifacts = $this->createMock(ExportArtifactRepository::class);
        $artifacts->expects(self::once())
            ->method('add')
            ->willReturnCallback(static function (ExportArtifact $artifact) use (&$stored): void {
                $stored = $artifact;
            });
        $jobs = $this->createMock(ExportJobDispatcher::class);
        $jobs->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (
                ExecutionContext $context,
                string $artifactId,
            ) use (&$dispatched): void {
                $dispatched[] = $artifactId;
            });
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::once())
            ->method('record')
            ->willReturnCallback(static function (AuditEvent $event) use (&$auditActions): void {
                $auditActions[] = $event->action();
            });
        $service = $this->exportService($provider, $artifacts, $jobs, $audit, allowed: true);
        $context = AuthorizationContext::human(['business.record.export']);
        $reportId = RecordExportReportProvider::IDENTIFIER_PREFIX . $definition->handle;

        $created = $service->request($context, $reportId);
        $expected = $provider->forDefinition($context, $definition->handle);

        self::assertInstanceOf(ExportArtifact::class, $stored);
        self::assertSame($created, $stored);
        self::assertSame($reportId, $created->reportIdentifier);
        self::assertSame($expected->version, $created->reportVersion);
        self::assertSame($expected->checksum(), $created->definitionChecksum);
        self::assertSame(ExportArtifactStatus::Queued, $created->status);
        self::assertStringEndsWith('.csv', $created->filename);
        self::assertSame([$created->id], $dispatched);
        self::assertSame(['business.report.export.request'], $auditActions);
    }

    public function testDeniedActorsCannotQueueADerivedRecordExport(): void
    {
        $definition = $this->definition();
        $provider = new RecordExportReportProvider($this->resolver($definition), new FieldTypeRegistry());
        $artifacts = $this->createMock(ExportArtifactRepository::class);
        $artifacts->expects(self::never())->method('add');
        $jobs = $this->createMock(ExportJobDispatcher::class);
        $jobs->expects(self::never())->method('dispatch');
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::never())->method('record');
        $service = $this->exportService($provider, $artifacts, $jobs, $audit, allowed: false);
        $context = AuthorizationContext::human([]);

        $this->expectException(ReportUnavailable::class);
        $service->request(
            $context,
            RecordExportReportProvider::IDENTIFIER_PREFIX . $definition->handle,
        );
    }

    public function testDerivedRecordExportExecutesRowsUnderTheExportPurpose(): void
    {
        $definition = $this->definition();
        $provider = new RecordExportReportProvider($this->resolver($definition), new FieldTypeRegistry());
        $reader = new class ($definition) implements BusinessRecordReportReader {
            public ?string $definitionIdentifier = null;
            public ?BusinessRecordQueryPurpose $purpose = null;

            public function __construct(private readonly EntityTypeDefinition $definition)
            {
            }

            public function browse(
                ExecutionContext $context,
                string $definitionIdentifier,
                RecordQuerySpecification $specification,
                ?string $organizationIdentifier,
                BusinessRecordQueryPurpose $purpose,
            ): RecordBrowseResult {
                $this->definitionIdentifier = $definitionIdentifier;
                $this->purpose = $purpose;
                $now = new DateTimeImmutable('2026-08-10T00:00:00+00:00');
                $recordId = '019bc210-0000-7000-8000-000000000201';

                return new RecordBrowseResult([new BusinessRecordView(
                    $this->definition->id,
                    $this->definition->definitionVersion,
                    $recordId,
                    $recordId,
                    1,
                    'default',
                    null,
                    'draft',
                    ['name' => 'Windhoek order', 'amount' => '12.500000'],
                    AuthorizationContext::SUBJECT,
                    $now,
                    AuthorizationContext::SUBJECT,
                    $now,
                    null,
                    null,
                    null,
                    null,
                )]);
            }
        };
        $authorization = $this->createStub(AuthorizationGateway::class);
        $authorization->method('decide')
            ->willReturn(new AuthorizationDecision(true, 'business.record.export', 'allowed'));
        $service = new ReportService(
            new ReportDefinitionRegistry([]),
            $reader,
            $authorization,
            $this->scopes(),
            recordExports: $provider,
        );
        $context = AuthorizationContext::human(['business.record.export']);

        $result = $service->execute(new ReportExecutionRequest(
            $context,
            RecordExportReportProvider::IDENTIFIER_PREFIX . $definition->handle,
            [],
            null,
            BusinessRecordQueryPurpose::Export,
        ));

        self::assertSame($definition->handle, $reader->definitionIdentifier);
        self::assertSame(BusinessRecordQueryPurpose::Export, $reader->purpose);
        self::assertSame([['name' => 'Windhoek order', 'amount' => '12.500000']], $result->rows);
        self::assertSame(['name' => 'Name', 'amount' => 'Amount'], $result->labels);
    }

    private function exportService(
        RecordExportReportProvider $provider,
        ExportArtifactRepository $artifacts,
        ExportJobDispatcher $jobs,
        AuditRecorder $audit,
        bool $allowed,
    ): ExportService {
        $authorization = $this->createStub(AuthorizationGateway::class);
        $authorization->method('decide')
            ->willReturn(new AuthorizationDecision($allowed, 'business.record.export', 'tested'));
        $policies = $this->createStub(ExportPolicySnapshotProvider::class);
        $policies->method('snapshot')->willReturn(str_repeat('a', 64));
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-10T12:00:00+00:00'));

        return new ExportService(
            new ReportDefinitionRegistry([]),
            $this->scopes(),
            $artifacts,
            $this->createStub(ExportArtifactStorage::class),
            $jobs,
            $policies,
            $authorization,
            new RecordExportPassThroughTransactions(),
            $audit,
            $clock,
            $provider,
        );
    }

    private function scopes(): ReportScopeResolver
    {
        return new class implements ReportScopeResolver {
            public function resolve(
                ExecutionContext $context,
                ReportDefinition $report,
                ?string $assertedOrganization,
            ): ?string {
                return $assertedOrganization;
            }
        };
    }

    private function definition(): EntityTypeDefinition
    {
        $document = NeutralBusinessFixture::document('pipeline', '0191574f-f0b8-7bf3-a9aa-91c6b8244e42');
        $document['portal_exposure'] = true;
        $document['portal_operations'] = ['browse', 'export'];

        return EntityTypeDefinition::fromArray($document)->published(1);
    }

    private function resolver(EntityTypeDefinition $definition): BusinessRecordDefinitionResolver
    {
        $column = new PhysicalColumnBlueprint('record_id', 'c_record_id_12345678901234567890', 'guid');
        $table = new PhysicalTableBlueprint(
            'record',
            'kb_e_record_12345678901234567890',
            PhysicalTableKind::Entity,
            [$column],
            [$column->physicalName],
        );
        $blueprint = new PhysicalSchemaBlueprint(
            $definition->id,
            $definition->definitionVersion,
            $definition->checksum(),
            [$table],
        );
        $at = new DateTimeImmutable('2026-08-10T00:00:00+00:00');
        $resolved = new ResolvedBusinessDefinition($definition, new SchemaInstallation(
            $definition->id,
            $definition->siteIdentifier,
            'core',
            $definition->definitionVersion,
            $definition->checksum(),
            $blueprint->checksum(),
            $blueprint,
            SchemaInstallationStatus::Active,
            $at,
            $at,
        ));

        return new class ($resolved) implements BusinessRecordDefinitionResolver {
            public function __construct(private readonly ResolvedBusinessDefinition $resolved)
            {
            }

            public function activeInstalled(ExecutionContext $context): array
            {
                return [$this->resolved];
            }

            public function forCreate(ExecutionContext $context, string $identifier): ResolvedBusinessDefinition
            {
                if (
                    $identifier !== $this->resolved->definition->handle
                    && $identifier !== $this->resolved->definition->id
                ) {
                    throw new BusinessRecordDefinitionUnavailable();
                }

                return $this->resolved;
            }

            public function pinned(
                ExecutionContext $context,
                string $identifier,
                int $definitionVersion,
            ): ResolvedBusinessDefinition {
                return $this->forCreate($context, $identifier);
            }

            public function forHistory(
                ExecutionContext $context,
                string $identifier,
                ?int $definitionVersion = null,
            ): ResolvedBusinessDefinition {
                return $this->forCreate($context, $identifier);
            }
        };
    }
}

/**
 * Minimal pass-through transaction scope for derived-export queueing assertions.
 */
final class RecordExportPassThroughTransactions implements TransactionManager
{
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }

    public function afterCommit(callable $operation): void
    {
        $operation();
    }

    public function afterRollback(callable $operation): void
    {
    }
}
