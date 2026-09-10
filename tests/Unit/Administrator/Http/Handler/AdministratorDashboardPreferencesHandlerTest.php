<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Administrator\Http\Handler;

use DateTimeImmutable;
use Kumwe\App\Administrator\Http\Handler\AdministratorDashboardPreferencesHandler;
use Kumwe\App\Administrator\Navigation\AdministratorNavigationRegistry;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\App\Administrator\Presentation\RecoveryAdministratorRenderer;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Extension\Spi\Contribution\AdministratorNavigationDefinition;
use Kumwe\Extension\Spi\Contribution\AdministratorWorkspaceDefinition;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Identity\Application\Administration\AdministratorSession;
use Kumwe\App\InterfaceStandard\CustomizationScope;
use Kumwe\App\InterfaceStandard\CustomizationSlot;
use Kumwe\App\InterfaceStandard\PresentationPreferenceKey;
use Kumwe\App\InterfaceStandard\SurfaceId;
use Kumwe\App\Presentation\Application\Dashboard\DashboardComposer;
use Kumwe\App\Application\Presentation\Dashboard\DashboardPreferenceService;
use Kumwe\App\Delivery\Http\Dashboard\DashboardPreferenceQueryDecoder;
use Kumwe\App\Presentation\Twig\AdministratorTwigEnvironment;
use Kumwe\App\Presentation\Twig\RecoveryAdministratorTwigEnvironment;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\DashboardPreferenceTestRuntime;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Loader\ArrayLoader;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;

/**
 * Verifies administrator POST delivery derives a live catalog and exposes only closed redirect results.
 *
 * @since  2.0.0
 */
#[CoversClass(AdministratorDashboardPreferencesHandler::class)]
#[UsesClass(AdministratorRenderer::class)]
#[UsesClass(DashboardComposer::class)]
#[UsesClass(DashboardPreferenceService::class)]
final class AdministratorDashboardPreferencesHandlerTest extends TestCase
{
    /**
     * Proves a capability-backed core widget is persisted and returns the saved dashboard fragment.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSavesFromTheLiveCapabilityFilteredCatalog(): void
    {
        $runtime = new DashboardPreferenceTestRuntime();
        $handler = new AdministratorDashboardPreferencesHandler(
            $runtime->service,
            $runtime->decoder,
            new DashboardPreferenceQueryDecoder(),
            $this->renderer(),
        );
        $request = $this->request([
            'action' => 'dashboard-cards.save',
            'scope' => 'user',
            'scope_id' => AuthorizationContext::SUBJECT,
            'expected_version' => '0',
            'item_0' => 'core.dashboard.content-summary',
            'selected_0' => '1',
            'order_0' => '1',
        ], ['administrator.access', 'content.read'], [
            'dashboard_group_page' => '65',
            'dashboard_group_search' => 'Finance & review',
            'dashboard_workflow_page' => '16',
            'dashboard_workflow_search' => 'Sales orders',
            'return' => 'https://attacker.example/',
        ]);

        $response = $handler->handle($request);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame(
            '/administrator?dashboard_group_search=Finance%20%26%20review&dashboard_group_page=65'
                . '&dashboard_workflow_search=Sales%20orders&dashboard_workflow_page=16'
                . '&dashboard-saved=1#dashboard-customization',
            $response->getHeaderLine('Location'),
        );
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $stored = $runtime->preferences->find(new PresentationPreferenceKey(
            SurfaceId::fromString('core.administrator.dashboard'),
            CustomizationSlot::DashboardCards,
            CustomizationScope::User,
            AuthorizationContext::SUBJECT,
        ));
        self::assertSame(['core.dashboard.content-summary'], $stored?->value()->value());
    }

    /**
     * Proves a withdrawn or forged identifier is translated to the stable invalid-result redirect.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsAnIdentifierOutsideTheLiveCatalog(): void
    {
        $runtime = new DashboardPreferenceTestRuntime();
        $handler = new AdministratorDashboardPreferencesHandler(
            $runtime->service,
            $runtime->decoder,
            new DashboardPreferenceQueryDecoder(),
            $this->renderer(),
        );
        $request = $this->request([
            'action' => 'dashboard-cards.save',
            'scope' => 'user',
            'scope_id' => AuthorizationContext::SUBJECT,
            'expected_version' => '0',
            'item_0' => 'vendor.withdrawn-widget',
            'selected_0' => '1',
            'order_0' => '1',
        ], ['administrator.access']);

        $response = $handler->handle($request);

        self::assertSame(
            '/administrator?dashboard-error=invalid#dashboard-customization',
            $response->getHeaderLine('Location'),
        );
    }

    /**
     * Proves POST validates a bounded submission against the complete current filtered catalogue.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSavesAWorkflowBeyondTheFormerRendererPrefix(): void
    {
        $registries = new ExtensionContributionRegistrySet();
        $owner = ContributionOwner::core();
        $registries->workspaces()->register($owner, new AdministratorWorkspaceDefinition(
            'core.dashboard-volume',
            'Dashboard volume',
            'Regression workflows for the bounded administrator dashboard catalog.',
            100,
        ));
        for ($index = 1; $index <= 500; $index++) {
            $suffix = str_pad((string) $index, 3, '0', STR_PAD_LEFT);
            $registries->navigation()->registerOwned($owner, new AdministratorNavigationDefinition(
                'core.dashboard-volume-' . $suffix,
                'core.dashboard-volume',
                'Workflow ' . $suffix,
                'Open bounded workflow ' . $suffix . '.',
                '/administrator/dashboard-volume-' . $suffix,
                'dashboard',
                'administrator.access',
                $index,
            ));
        }
        $runtime = new DashboardPreferenceTestRuntime();
        $handler = new AdministratorDashboardPreferencesHandler(
            $runtime->service,
            $runtime->decoder,
            new DashboardPreferenceQueryDecoder(),
            $this->renderer($registries->navigation()),
        );
        $request = $this->request([
            'action' => 'navigation-shortcuts.save',
            'scope' => 'user',
            'scope_id' => AuthorizationContext::SUBJECT,
            'expected_version' => '0',
            'item_0' => 'core.dashboard-volume-500',
            'selected_0' => '1',
            'order_0' => '1',
        ], ['administrator.access']);

        $response = $handler->handle($request);

        self::assertSame(
            '/administrator?dashboard-saved=1#dashboard-customization',
            $response->getHeaderLine('Location'),
        );
        $stored = $runtime->preferences->find(new PresentationPreferenceKey(
            SurfaceId::fromString('core.administrator.dashboard'),
            CustomizationSlot::NavigationShortcuts,
            CustomizationScope::User,
            AuthorizationContext::SUBJECT,
        ));
        self::assertSame(['core.dashboard-volume-500'], $stored?->value()->value());
    }

    /**
     * Proves an optimistic mismatch is translated separately from malformed form input.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRedirectsAnOptimisticMismatchAsAConflict(): void
    {
        $runtime = new DashboardPreferenceTestRuntime();
        $handler = new AdministratorDashboardPreferencesHandler(
            $runtime->service,
            $runtime->decoder,
            new DashboardPreferenceQueryDecoder(),
            $this->renderer(),
        );
        $request = $this->request([
            'action' => 'dashboard-cards.save',
            'scope' => 'user',
            'scope_id' => AuthorizationContext::SUBJECT,
            'expected_version' => '1',
        ], ['administrator.access']);

        $response = $handler->handle($request);

        self::assertSame(
            '/administrator?dashboard-error=conflict#dashboard-customization',
            $response->getHeaderLine('Location'),
        );
    }

    /**
     * Build the request shape emitted by administrator authentication and CSRF middleware.
     *
     * @param   array<string, string>  $form          Flat dashboard preference form.
     * @param   list<string>           $capabilities  Capabilities carried by the administrator principal.
     * @param   array<string, string>  $query         Optional untrusted continuation query.
     *
     * @return  ServerRequestInterface  Authenticated POST request with parsed form data.
     *
     * @since   2.0.0
     */
    private function request(array $form, array $capabilities, array $query = []): ServerRequestInterface
    {
        $principal = AuthorizationContext::principal($capabilities);
        $session = new AdministratorSession(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb399',
            $principal,
            'csrf-token',
            new DateTimeImmutable('+1 hour'),
        );

        return (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/administrator/dashboard/preferences')
            ->withQueryParams($query)
            ->withParsedBody($form)
            ->withAttribute(AdministratorSession::REQUEST_ATTRIBUTE, $session)
            ->withAttribute(ExecutionContextAttribute::NAME, $principal->context(
                SiteContext::default(),
                AuthenticationStrength::Password,
                'test-dashboard-preferences',
            ));
    }

    /**
     * Build the renderer used only for its canonical live administrator navigation projection.
     *
     * @param   ?AdministratorNavigationRegistry  $navigation  Optional contribution catalog for a scale scenario.
     *
     * @return  AdministratorRenderer  Core or supplied registry-backed renderer.
     *
     * @since   2.0.0
     */
    private function renderer(?AdministratorNavigationRegistry $navigation = null): AdministratorRenderer
    {
        return new AdministratorRenderer(
            new AdministratorTwigEnvironment(new ArrayLoader()),
            new RecoveryAdministratorRenderer(new RecoveryAdministratorTwigEnvironment(new ArrayLoader())),
            $navigation,
        );
    }
}
