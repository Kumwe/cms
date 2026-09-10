<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessReporting;

use DateTimeImmutable;
use Kumwe\App\Application\Authorization\AuthorizationDecision;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordQueryPurpose;
use Kumwe\App\BusinessRecord\Application\RecordBrowseResult;
use Kumwe\App\BusinessRecord\Application\BusinessRecordView;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\App\BusinessReporting\Application\BusinessRecordReportReader;
use Kumwe\App\BusinessReporting\Application\ExportService;
use Kumwe\App\BusinessReporting\Application\ReportDefinitionRegistry;
use Kumwe\App\BusinessReporting\Application\ReportService;
use Kumwe\App\BusinessReporting\Application\ReportScopeResolver;
use Kumwe\App\BusinessReporting\Delivery\Api\ReportApiHandler;
use Kumwe\App\BusinessReporting\Delivery\Api\ReportApiPresenter;
use Kumwe\App\BusinessReporting\Domain\ReportColumnDefinition;
use Kumwe\App\BusinessReporting\Domain\ReportDefinition;
use Kumwe\Extension\Spi\BusinessReporting\Domain\ReportValueType;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;

#[CoversClass(ReportApiHandler::class)]
final class ReportApiDiscoveryTest extends TestCase
{
    public function testReportAppearsAndDisappearsWithTheReconciledRuntimeRegistry(): void
    {
        $context = AuthorizationContext::human(['business.record.report', 'acme.reports.read']);
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/api/v1/business/reports')
            ->withAttribute(ExecutionContextAttribute::NAME, $context)
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $context->principal())
            ->withAttribute(ReportApiHandler::OPERATION_ATTRIBUTE, 'report.list');

        $active = $this->handler(new ReportDefinitionRegistry([$this->report()]))->handle($request);
        $inactive = $this->handler(new ReportDefinitionRegistry([]))->handle($request);
        /** @var array<string, mixed> $activeDocument */
        $activeDocument = json_decode((string) $active->getBody(), true, 32, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $inactiveDocument */
        $inactiveDocument = json_decode((string) $inactive->getBody(), true, 32, JSON_THROW_ON_ERROR);

        self::assertSame('acme.open_items', $activeDocument['items'][0]['id']);
        self::assertSame([], $inactiveDocument['items']);
        self::assertSame('no-store', $active->getHeaderLine('Cache-Control'));
    }

    public function testParameterlessReportAcceptsAnEmptyJsonObject(): void
    {
        $context = AuthorizationContext::human(['business.record.report', 'acme.reports.read']);
        $streams = new StreamFactory();
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/api/v1/business/reports/acme.open_items')
            ->withBody($streams->createStream('{}'))
            ->withAttribute(ExecutionContextAttribute::NAME, $context)
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $context->principal())
            ->withAttribute(ReportApiHandler::OPERATION_ATTRIBUTE, 'report.execute')
            ->withAttribute('report', 'acme.open_items');

        $response = $this->handler(new ReportDefinitionRegistry([$this->report()]))->handle($request);
        /** @var array<string, mixed> $document */
        $document = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $document['rows']);
        self::assertSame(0, $document['row_count']);
    }

    public function testMalformedRawJsonReturnsAStableNoStoreValidationProblem(): void
    {
        $context = AuthorizationContext::human(['business.record.report', 'acme.reports.read']);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/api/v1/business/reports/acme.open_items')
            ->withBody((new StreamFactory())->createStream('{"parameters":'))
            ->withAttribute(ExecutionContextAttribute::NAME, $context)
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $context->principal())
            ->withAttribute(ReportApiHandler::OPERATION_ATTRIBUTE, 'report.execute')
            ->withAttribute('report', 'acme.open_items');

        $response = $this->handler(new ReportDefinitionRegistry([$this->report()]))->handle($request);
        /** @var array<string, mixed> $document */
        $document = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('urn:kumwe:problem:business-report-validation-failed', $document['type']);
        self::assertSame('The report request parameters are invalid.', $document['detail']);
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testRejectedReportParameterDoesNotLeakItsValue(): void
    {
        $context = AuthorizationContext::human(['business.record.report', 'acme.reports.read']);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/api/v1/business/reports/acme.open_items')
            ->withBody((new StreamFactory())->createStream(
                '{"parameters":{"undeclared":"commercially-sensitive-value"}}',
            ))
            ->withAttribute(ExecutionContextAttribute::NAME, $context)
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $context->principal())
            ->withAttribute(ReportApiHandler::OPERATION_ATTRIBUTE, 'report.execute')
            ->withAttribute('report', 'acme.open_items');

        $response = $this->handler(new ReportDefinitionRegistry([$this->report()]))->handle($request);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringNotContainsString('undeclared', (string) $response->getBody());
        self::assertStringNotContainsString('commercially-sensitive-value', (string) $response->getBody());
    }

    public function testUnknownAndUnauthorizedReportsHaveTheSameNoStoreResponse(): void
    {
        $context = AuthorizationContext::human(['business.record.report', 'acme.reports.read']);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/api/v1/business/reports/acme.open_items')
            ->withBody((new StreamFactory())->createStream('{}'))
            ->withAttribute(ExecutionContextAttribute::NAME, $context)
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $context->principal())
            ->withAttribute(ReportApiHandler::OPERATION_ATTRIBUTE, 'report.execute')
            ->withAttribute('report', 'acme.open_items');

        $unknown = $this->handler(new ReportDefinitionRegistry([]))->handle($request);
        $unauthorized = $this->handler(
            new ReportDefinitionRegistry([$this->report()]),
            allowed: false,
        )->handle($request);

        self::assertSame(404, $unknown->getStatusCode());
        self::assertSame($unknown->getStatusCode(), $unauthorized->getStatusCode());
        self::assertSame((string) $unknown->getBody(), (string) $unauthorized->getBody());
        self::assertSame('no-store', $unknown->getHeaderLine('Cache-Control'));
        self::assertSame($unknown->getHeaderLine('Cache-Control'), $unauthorized->getHeaderLine('Cache-Control'));
    }

    public function testSynchronousRowLimitReturnsAStableExportGuidanceProblem(): void
    {
        $context = AuthorizationContext::human(['business.record.report', 'acme.reports.read']);
        $now = new DateTimeImmutable('2026-08-10T00:00:00+00:00');
        $rows = [
            $this->record('018f22e2-7c8b-7ab0-8f3a-88e8026bb611', 'ITEM-1', $now),
            $this->record('018f22e2-7c8b-7ab0-8f3a-88e8026bb612', 'ITEM-2', $now),
        ];
        $reader = new class ($rows) implements BusinessRecordReportReader {
            /** @param list<BusinessRecordView> $rows */
            public function __construct(private array $rows)
            {
            }

            public function browse(
                ExecutionContext $context,
                string $definitionIdentifier,
                RecordQuerySpecification $specification,
                ?string $organizationIdentifier,
                BusinessRecordQueryPurpose $purpose,
            ): RecordBrowseResult {
                return new RecordBrowseResult($this->rows);
            }
        };
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/api/v1/business/reports/acme.open_items')
            ->withBody((new StreamFactory())->createStream('{}'))
            ->withAttribute(ExecutionContextAttribute::NAME, $context)
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $context->principal())
            ->withAttribute(ReportApiHandler::OPERATION_ATTRIBUTE, 'report.execute')
            ->withAttribute('report', 'acme.open_items');

        $response = $this->handler(
            new ReportDefinitionRegistry([$this->report(1)]),
            $reader,
        )->handle($request);
        /** @var array<string, mixed> $document */
        $document = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('urn:kumwe:problem:business-report-row-limit-exceeded', $document['type']);
        self::assertStringContainsString('queued export', $document['detail']);
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    private function handler(
        ReportDefinitionRegistry $definitions,
        ?BusinessRecordReportReader $reader = null,
        bool $allowed = true,
    ): ReportApiHandler {
        $authorization = $this->createStub(AuthorizationGateway::class);
        $authorization->method('decide')->willReturnCallback(
            static fn (): AuthorizationDecision => new AuthorizationDecision(
                $allowed,
                'test.report',
                $allowed ? 'allowed' : 'denied',
            ),
        );
        $reader ??= new class implements BusinessRecordReportReader {
            public function browse(
                ExecutionContext $context,
                string $definitionIdentifier,
                RecordQuerySpecification $specification,
                ?string $organizationIdentifier,
                BusinessRecordQueryPurpose $purpose,
            ): RecordBrowseResult {
                return new RecordBrowseResult([]);
            }
        };

        return new ReportApiHandler(
            new ReportService($definitions, $reader, $authorization, new class implements ReportScopeResolver {
                public function resolve(
                    ExecutionContext $context,
                    ReportDefinition $report,
                    ?string $assertedOrganization,
                ): ?string {
                    return $assertedOrganization;
                }
            }),
            (new \ReflectionClass(ExportService::class))->newInstanceWithoutConstructor(),
            new ReportApiPresenter(),
            new StreamFactory(),
            new ProblemDetailsResponseFactory(),
        );
    }

    private function report(int $synchronousRowCap = 1000): ReportDefinition
    {
        return new ReportDefinition(
            'acme.open_items',
            1,
            'Open items',
            'acme.item',
            'acme.reports.read',
            [],
            [],
            [new ReportColumnDefinition('number', 'Number', 'number', ReportValueType::String)],
            synchronousRowCap: $synchronousRowCap,
        );
    }

    private function record(string $key, string $number, DateTimeImmutable $now): BusinessRecordView
    {
        return new BusinessRecordView(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb601',
            1,
            $key,
            $number,
            1,
            'default',
            null,
            null,
            ['number' => $number],
            AuthorizationContext::SUBJECT,
            $now,
            AuthorizationContext::SUBJECT,
            $now,
            null,
            null,
            null,
            null,
        );
    }
}
