<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessReporting;

use DateTimeImmutable;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\App\Administrator\Presentation\RecoveryAdministratorRenderer;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationPolicyRegistry;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordQueryPurpose;
use Kumwe\App\BusinessRecord\Application\RecordBrowseResult;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\App\BusinessReporting\Application\BusinessRecordReportReader;
use Kumwe\App\BusinessReporting\Application\ExportService;
use Kumwe\App\BusinessReporting\Application\ReportDefinitionRegistry;
use Kumwe\App\BusinessReporting\Application\ReportScopeResolver;
use Kumwe\App\BusinessReporting\Application\ReportService;
use Kumwe\App\BusinessReporting\Delivery\Administrator\AdministratorReportHandler;
use Kumwe\App\BusinessReporting\Delivery\Api\ReportApiPresenter;
use Kumwe\App\BusinessReporting\Delivery\Portal\PortalReportHandler;
use Kumwe\App\BusinessReporting\Domain\ReportDefinition;
use Kumwe\App\Extension\Contribution\CapabilityDefinitionRegistry;
use Kumwe\App\Identity\Application\Administration\AdministratorSession;
use Kumwe\App\Portal\Application\PortalSession;
use Kumwe\App\Portal\Application\PortalSessionIdentity;
use Kumwe\App\Portal\Contribution\PortalNavigationRegistry;
use Kumwe\App\Portal\Contribution\PortalTemplateRegistry;
use Kumwe\App\Portal\Contribution\PortalWorkspaceRegistry;
use Kumwe\App\Portal\Application\PortalContext;
use Kumwe\App\Portal\Presentation\PortalNavigationVisibility;
use Kumwe\App\Portal\Presentation\PortalRenderer;
use Kumwe\App\Presentation\Twig\AdministratorTwigEnvironment;
use Kumwe\App\Presentation\Twig\RecoveryAdministratorTwigEnvironment;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;

#[CoversClass(AdministratorReportHandler::class)]
#[CoversClass(PortalReportHandler::class)]
final class ReportBrowserErrorResponseTest extends TestCase
{
    public function testAdministratorRendersInvalidJsonAsGenericAccessibleHtml(): void
    {
        $principal = AuthorizationContext::principal(['business.record.report']);
        $context = $principal->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'administrator-report-error-test',
            surface: AuthenticatedSurface::Administrator,
        );
        $session = new AdministratorSession(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb621',
            $principal,
            str_repeat('a', 43),
            new DateTimeImmutable('2026-08-10T12:00:00+00:00'),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/administrator/reports/acme.open_items')
            ->withParsedBody(['parameters_json' => '{commercially-sensitive'])
            ->withAttribute('operation', 'execute')
            ->withAttribute('report', 'acme.open_items')
            ->withAttribute(ExecutionContextAttribute::NAME, $context)
            ->withAttribute(AdministratorSession::REQUEST_ATTRIBUTE, $session);

        $response = (new AdministratorReportHandler(
            $this->reports(),
            $this->exports(),
            new ReportApiPresenter(),
            $this->administratorRenderer(),
            new StreamFactory(),
        ))->handle($request);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('role="alert"', (string) $response->getBody());
        self::assertStringContainsString('data-csrf="' . str_repeat('a', 43) . '"', (string) $response->getBody());
        self::assertStringNotContainsString('commercially-sensitive', (string) $response->getBody());
        self::assertStringNotContainsString('valid JSON', (string) $response->getBody());
    }

    public function testPortalRendersInvalidJsonAsGenericAccessibleHtml(): void
    {
        $principal = AuthorizationContext::principal(['portal.access', 'business.record.report']);
        $context = $principal->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'portal-report-error-test',
            surface: AuthenticatedSurface::Portal,
        );
        $now = new DateTimeImmutable('2026-08-10T10:00:00+00:00');
        $session = new PortalSession(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb622',
            new PortalSessionIdentity($principal, new PortalContext(SiteContext::default(), null), 1),
            str_repeat('b', 43),
            $now,
            null,
            $now->modify('+1 hour'),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/portal/reports/acme.open_items')
            ->withParsedBody(['parameters_json' => '{commercially-sensitive'])
            ->withAttribute('operation', 'execute')
            ->withAttribute('report', 'acme.open_items')
            ->withAttribute(ExecutionContextAttribute::NAME, $context)
            ->withAttribute(PortalSession::REQUEST_ATTRIBUTE, $session);

        $response = (new PortalReportHandler(
            $this->reports(),
            $this->exports(),
            new ReportApiPresenter(),
            $this->portalRenderer(),
            new StreamFactory(),
        ))->handle($request);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('role="alert"', (string) $response->getBody());
        self::assertStringNotContainsString('commercially-sensitive', (string) $response->getBody());
        self::assertStringNotContainsString('valid JSON', (string) $response->getBody());
    }

    private function reports(): ReportService
    {
        return new ReportService(
            new ReportDefinitionRegistry([]),
            new class implements BusinessRecordReportReader {
                public function browse(
                    ExecutionContext $context,
                    string $definitionIdentifier,
                    RecordQuerySpecification $specification,
                    ?string $organizationIdentifier,
                    BusinessRecordQueryPurpose $purpose,
                ): RecordBrowseResult {
                    return new RecordBrowseResult([]);
                }
            },
            $this->createStub(AuthorizationGateway::class),
            new class implements ReportScopeResolver {
                public function resolve(
                    ExecutionContext $context,
                    ReportDefinition $report,
                    ?string $assertedOrganization,
                ): ?string {
                    return $assertedOrganization;
                }
            },
        );
    }

    private function exports(): ExportService
    {
        return (new ReflectionClass(ExportService::class))->newInstanceWithoutConstructor();
    }

    private function administratorRenderer(): AdministratorRenderer
    {
        $template = '<div role="alert" data-csrf="{{ csrf }}">{{ report_error }}</div>';
        return new AdministratorRenderer(
            new AdministratorTwigEnvironment(new ArrayLoader(['business-report.twig' => $template])),
            new RecoveryAdministratorRenderer(new RecoveryAdministratorTwigEnvironment(
                new ArrayLoader(['business-report.twig' => $template]),
            )),
        );
    }

    private function portalRenderer(): PortalRenderer
    {
        $workspaces = new PortalWorkspaceRegistry();
        $capabilities = new CapabilityDefinitionRegistry();
        return new PortalRenderer(
            new Environment(new ArrayLoader([
                'portal/business-report.twig' => '<div role="alert">{{ report_error }}</div>',
            ]), ['strict_variables' => true]),
            new PortalNavigationRegistry($workspaces, $capabilities, new AuthorizationPolicyRegistry()),
            new PortalTemplateRegistry(),
            $this->createStub(PortalNavigationVisibility::class),
        );
    }
}
