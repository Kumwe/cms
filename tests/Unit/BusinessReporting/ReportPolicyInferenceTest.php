<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessReporting;

use DateTimeImmutable;
use Kumwe\App\Application\Authorization\AuthorizationDecision;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\BusinessRecord\Application\BusinessRecordView;
use Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordQueryPurpose;
use Kumwe\App\BusinessRecord\Application\RecordBrowseResult;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\App\BusinessReporting\Application\BusinessRecordReportReader;
use Kumwe\App\BusinessReporting\Application\ReportDefinitionRegistry;
use Kumwe\App\BusinessReporting\Application\ReportExecutionRequest;
use Kumwe\App\BusinessReporting\Application\ReportService;
use Kumwe\App\BusinessReporting\Application\ReportScopeResolver;
use Kumwe\App\BusinessReporting\Application\ReportUnavailable;
use Kumwe\App\BusinessReporting\Domain\ReportAggregateDefinition;
use Kumwe\App\BusinessReporting\Domain\ReportAggregateFunction;
use Kumwe\App\BusinessReporting\Domain\ReportColumnDefinition;
use Kumwe\App\BusinessReporting\Domain\ReportDefinition;
use Kumwe\App\BusinessReporting\Domain\ReportFilterDefinition;
use Kumwe\App\BusinessReporting\Domain\ReportFilterOperator;
use Kumwe\App\BusinessReporting\Domain\ReportGroupDefinition;
use Kumwe\App\BusinessReporting\Domain\ReportParameterDefinition;
use Kumwe\App\BusinessReporting\Domain\ReportSortDefinition;
use Kumwe\App\BusinessReporting\Domain\ReportSortDirection;
use Kumwe\Extension\Spi\BusinessReporting\Domain\ReportValueType;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReportService::class)]
final class ReportPolicyInferenceTest extends TestCase
{
    public function testCanonicalUuidInspectionIdentifierReturnsSeededRiskScoreRow(): void
    {
        $recordId = '019bc210-0000-7000-8000-000000000101';
        $report = new ReportDefinition(
            'kumwe.asset-inspection-example.inspection-summary',
            1,
            'Asset inspection example summary',
            'kumwe.asset-inspection-example.inspection',
            'kumwe.asset-inspection-example.view',
            [new ReportParameterDefinition('minimum_score', ReportValueType::Integer, defaultValue: 0)],
            [new ReportFilterDefinition(
                'risk_score',
                ReportFilterOperator::GreaterThanOrEqual,
                'minimum_score',
            )],
            [
                new ReportColumnDefinition('inspection_id', 'Inspection ID', 'id', ReportValueType::Identifier),
                new ReportColumnDefinition('reference', 'Reference', 'reference', ReportValueType::String),
                new ReportColumnDefinition(
                    'inspection_date',
                    'Inspection date',
                    'inspection_date',
                    ReportValueType::Date,
                ),
                new ReportColumnDefinition('risk_score', 'Risk score', 'risk_score', ReportValueType::Integer),
            ],
            sorts: [new ReportSortDefinition('risk_score', ReportSortDirection::Descending)],
            synchronousRowCap: 200,
            portalVisible: true,
        );
        $reader = new class ($recordId) implements BusinessRecordReportReader {
            public ?RecordQuerySpecification $specification = null;
            public ?string $definitionIdentifier = null;
            public ?BusinessRecordQueryPurpose $purpose = null;

            public function __construct(private readonly string $recordId)
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
                $this->specification = $specification;
                $this->purpose = $purpose;
                $now = new DateTimeImmutable('2026-08-10T00:00:00+00:00');

                return new RecordBrowseResult([new BusinessRecordView(
                    '019bc200-0000-7000-8000-000000000003',
                    1,
                    $this->recordId,
                    $this->recordId,
                    1,
                    'default',
                    null,
                    'draft',
                    [
                        'id' => $this->recordId,
                        'reference' => 'BROWSER-INSPECT-001',
                        'inspection_date' => '2026-08-10',
                        'risk_score' => 79,
                    ],
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
        $service = new ReportService(
            new ReportDefinitionRegistry([$report]),
            $reader,
            $this->authorization(),
            $this->scopes(),
        );

        $result = $service->execute(new ReportExecutionRequest(
            AuthorizationContext::human([
                'kumwe.asset-inspection-example.view',
                'business.record.report',
            ]),
            $report->identifier(),
            ['minimum_score' => 70],
        ));

        self::assertSame('kumwe.asset-inspection-example.inspection', $reader->definitionIdentifier);
        self::assertSame(BusinessRecordQueryPurpose::Report, $reader->purpose);
        self::assertSame(
            ['id', 'reference', 'inspection_date', 'risk_score'],
            $reader->specification?->projection->fields,
        );
        self::assertSame([
            'type' => 'comparison',
            'field' => 'risk_score',
            'operator' => 'gte',
            'value' => 70,
        ], $reader->specification?->filter?->toArray());
        self::assertSame([[
            'inspection_id' => $recordId,
            'reference' => 'BROWSER-INSPECT-001',
            'inspection_date' => '2026-08-10',
            'risk_score' => 79,
        ]], $result->rows);
    }

    public function testIdentifierValueAcceptsHandlesAndCanonicalLowercaseUuidsOnly(): void
    {
        self::assertTrue(ReportValueType::Identifier->accepts(
            'kumwe.asset-inspection-example.inspection-summary',
        ));
        self::assertTrue(ReportValueType::Identifier->accepts(
            '019bc210-0000-7000-8000-000000000101',
        ));
        self::assertFalse(ReportValueType::Identifier->accepts(
            '019BC210-0000-7000-8000-000000000101',
        ));
        self::assertFalse(ReportValueType::Identifier->accepts(
            '019bc210000070008000000000000101',
        ));
        self::assertFalse(ReportValueType::Identifier->accepts('1not-a-handle'));
    }

    public function testMissingPolicyControlledGroupValueFailsInsteadOfChangingTotals(): void
    {
        $report = new ReportDefinition(
            'acme.revenue',
            1,
            'Revenue',
            'acme.invoice',
            'acme.reports.read',
            [],
            [],
            [
                new ReportColumnDefinition('region', 'Region', 'region', ReportValueType::String),
                new ReportColumnDefinition('amount', 'Amount', 'amount', ReportValueType::Decimal),
            ],
            [new ReportGroupDefinition('region')],
            [new ReportAggregateDefinition('total', ReportAggregateFunction::Sum, 'amount')],
        );
        $reader = new class implements BusinessRecordReportReader {
            public function browse(
                ExecutionContext $context,
                string $definitionIdentifier,
                RecordQuerySpecification $specification,
                ?string $organizationIdentifier,
                BusinessRecordQueryPurpose $purpose,
            ): RecordBrowseResult {
                $now = new DateTimeImmutable('2026-08-10T00:00:00+00:00');
                return new RecordBrowseResult([new BusinessRecordView(
                    '018f22e2-7c8b-7ab0-8f3a-88e8026bb601',
                    1,
                    '018f22e2-7c8b-7ab0-8f3a-88e8026bb602',
                    'INV-1',
                    1,
                    'default',
                    null,
                    null,
                    ['amount' => '10.00'],
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
        $service = new ReportService(
            new ReportDefinitionRegistry([$report]),
            $reader,
            $this->authorization(),
            $this->scopes(),
        );
        $context = AuthorizationContext::human(['acme.reports.read', 'business.record.report']);

        $this->expectException(ReportUnavailable::class);
        $service->execute(new ReportExecutionRequest($context, 'acme.revenue'));
    }

    public function testMissingPolicyControlledPlainColumnFailsWithoutPublishingItsLabel(): void
    {
        $report = new ReportDefinition(
            'acme.inspections',
            1,
            'Inspections',
            'acme.inspection',
            'acme.reports.read',
            [],
            [],
            [
                new ReportColumnDefinition('title', 'Title', 'title', ReportValueType::String),
                new ReportColumnDefinition('internal_note', 'Internal note', 'internal_note', ReportValueType::String),
            ],
        );
        $reader = new class implements BusinessRecordReportReader {
            public function browse(
                ExecutionContext $context,
                string $definitionIdentifier,
                RecordQuerySpecification $specification,
                ?string $organizationIdentifier,
                BusinessRecordQueryPurpose $purpose,
            ): RecordBrowseResult {
                $now = new DateTimeImmutable('2026-08-10T00:00:00+00:00');

                return new RecordBrowseResult([new BusinessRecordView(
                    '018f22e2-7c8b-7ab0-8f3a-88e8026bb611',
                    1,
                    '018f22e2-7c8b-7ab0-8f3a-88e8026bb612',
                    'INSP-1',
                    1,
                    'default',
                    null,
                    null,
                    ['title' => 'Boiler'],
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
        $service = new ReportService(
            new ReportDefinitionRegistry([$report]),
            $reader,
            $this->authorization(),
            $this->scopes(),
        );

        $this->expectException(ReportUnavailable::class);
        $service->execute(new ReportExecutionRequest(
            AuthorizationContext::human(['acme.reports.read', 'business.record.report']),
            'acme.inspections',
        ));
    }

    private function authorization(): AuthorizationGateway
    {
        $authorization = $this->createStub(AuthorizationGateway::class);
        $authorization->method('decide')->willReturnCallback(
            static fn (): AuthorizationDecision => new AuthorizationDecision(true, 'test.report', 'allowed'),
        );

        return $authorization;
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
}
