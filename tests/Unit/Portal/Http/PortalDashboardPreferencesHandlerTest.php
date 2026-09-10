<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Portal\Http;

use DateTimeImmutable;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\InterfaceStandard\CustomizationScope;
use Kumwe\App\InterfaceStandard\CustomizationSlot;
use Kumwe\App\InterfaceStandard\PresentationPreferenceKey;
use Kumwe\App\InterfaceStandard\SurfaceId;
use Kumwe\App\Portal\Application\PortalSession;
use Kumwe\App\Portal\Application\PortalSessionIdentity;
use Kumwe\Extension\Spi\Portal\Contribution\PortalNavigationDefinition;
use Kumwe\Extension\Spi\Portal\Contribution\PortalWorkspaceDefinition;
use Kumwe\App\Portal\Application\PortalContext;
use Kumwe\App\Portal\Http\Handler\PortalDashboardPreferencesHandler;
use Kumwe\App\Portal\Presentation\PortalNavigationVisibility;
use Kumwe\App\Portal\Presentation\PortalRenderer;
use Kumwe\App\Presentation\Application\Dashboard\DashboardComposer;
use Kumwe\App\Application\Presentation\Dashboard\DashboardPreferenceService;
use Kumwe\App\Application\Presentation\Preference\PresentationAccessGroup;
use Kumwe\App\Delivery\Http\Dashboard\DashboardPreferenceQueryDecoder;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\DashboardPreferenceTestRuntime;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;

/**
 * Verifies portal POST delivery uses the resolved session catalog and preserves role authorization boundaries.
 *
 * @since  2.0.0
 */
#[CoversClass(PortalDashboardPreferencesHandler::class)]
#[UsesClass(PortalRenderer::class)]
#[UsesClass(DashboardComposer::class)]
#[UsesClass(DashboardPreferenceService::class)]
final class PortalDashboardPreferencesHandlerTest extends TestCase
{
    /**
     * Canonical role UUID used by the access-group delivery scenario.
     *
     * @var    string
     * @since  2.0.0
     */
    private const ROLE_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bb303';

    /**
     * Proves portal delivery can mutate an exact role through the manager when `users.manage` is present.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSavesAnAuthorizedAccessGroupAgainstTheSessionVisibleCatalog(): void
    {
        $group = PresentationAccessGroup::fromRole(self::ROLE_ID, 'operations', 'Operations');
        $runtime = new DashboardPreferenceTestRuntime([$group]);
        $handler = new PortalDashboardPreferencesHandler(
            $runtime->service,
            $runtime->decoder,
            new DashboardPreferenceQueryDecoder(),
            $this->renderer(),
        );
        $request = $this->request([
            'action' => 'dashboard-cards.save',
            'scope' => 'role-workspace',
            'scope_id' => $group->id,
            'expected_version' => '0',
            'item_0' => 'core.dashboard.access-context',
            'selected_0' => '1',
            'order_0' => '1',
        ], ['portal.access', 'users.manage'], [
            'dashboard_group_page' => '65',
            'dashboard_group_search' => 'Operations',
            'dashboard_workflow_page' => '16',
            'dashboard_workflow_search' => 'Approvals',
            'return' => '//attacker.example/',
        ]);

        $response = $handler->handle($request);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame(
            '/portal?dashboard_group_search=Operations&dashboard_group_page=65'
                . '&dashboard_workflow_search=Approvals&dashboard_workflow_page=16'
                . '&dashboard-saved=1#dashboard-customization',
            $response->getHeaderLine('Location'),
        );
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $stored = $runtime->preferences->find(new PresentationPreferenceKey(
            SurfaceId::fromString('core.portal.home'),
            CustomizationSlot::DashboardCards,
            CustomizationScope::RoleWorkspace,
            $group->id,
        ));
        self::assertSame(['core.dashboard.access-context'], $stored?->value()->value());
    }

    /**
     * Proves a submitted workflow absent from the session-filtered portal catalog fails closed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsAWorkflowOutsideTheResolvedPortalCatalog(): void
    {
        $runtime = new DashboardPreferenceTestRuntime();
        $handler = new PortalDashboardPreferencesHandler(
            $runtime->service,
            $runtime->decoder,
            new DashboardPreferenceQueryDecoder(),
            $this->renderer(),
        );
        $request = $this->request([
            'action' => 'navigation-shortcuts.save',
            'scope' => 'user',
            'scope_id' => AuthorizationContext::SUBJECT,
            'expected_version' => '0',
            'item_0' => 'vendor.hidden-workflow',
            'selected_0' => '1',
            'order_0' => '1',
        ], ['portal.access']);

        $response = $handler->handle($request);

        self::assertSame(
            '/portal?dashboard-error=invalid#dashboard-customization',
            $response->getHeaderLine('Location'),
        );
    }

    /**
     * Proves POST validates a bounded submission against the complete session-visible catalogue.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSavesAWorkflowBeyondTheFormerRendererPrefix(): void
    {
        $registries = new ExtensionContributionRegistrySet();
        $owner = ContributionOwner::core();
        $registries->portalWorkspaces()->register($owner, new PortalWorkspaceDefinition(
            'core.portal-dashboard-volume',
            'Dashboard volume',
            'Regression workflows for the bounded portal dashboard catalog.',
            100,
        ));
        for ($index = 1; $index <= 500; $index++) {
            $suffix = str_pad((string) $index, 3, '0', STR_PAD_LEFT);
            $registries->portalNavigation()->register($owner, new PortalNavigationDefinition(
                'core.portal-dashboard-volume-' . $suffix,
                'core.portal-dashboard-volume',
                'Workflow ' . $suffix,
                'Open bounded workflow ' . $suffix . '.',
                '/portal/dashboard-volume-' . $suffix,
                'dashboard',
                'portal.access',
                $index,
            ));
        }
        $runtime = new DashboardPreferenceTestRuntime();
        $handler = new PortalDashboardPreferencesHandler(
            $runtime->service,
            $runtime->decoder,
            new DashboardPreferenceQueryDecoder(),
            $this->renderer($registries),
        );
        $request = $this->request([
            'action' => 'navigation-shortcuts.save',
            'scope' => 'user',
            'scope_id' => AuthorizationContext::SUBJECT,
            'expected_version' => '0',
            'item_0' => 'core.portal-dashboard-volume-500',
            'selected_0' => '1',
            'order_0' => '1',
        ], ['portal.access']);

        $response = $handler->handle($request);

        self::assertSame(
            '/portal?dashboard-saved=1#dashboard-customization',
            $response->getHeaderLine('Location'),
        );
        $stored = $runtime->preferences->find(new PresentationPreferenceKey(
            SurfaceId::fromString('core.portal.home'),
            CustomizationSlot::NavigationShortcuts,
            CustomizationScope::User,
            AuthorizationContext::SUBJECT,
        ));
        self::assertSame(['core.portal-dashboard-volume-500'], $stored?->value()->value());
    }

    /**
     * Proves a stale portal preference version receives the closed conflict redirect.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRedirectsAnOptimisticMismatchAsAConflict(): void
    {
        $runtime = new DashboardPreferenceTestRuntime();
        $handler = new PortalDashboardPreferencesHandler(
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
        ], ['portal.access']);

        $response = $handler->handle($request);

        self::assertSame(
            '/portal?dashboard-error=conflict#dashboard-customization',
            $response->getHeaderLine('Location'),
        );
    }

    /**
     * Build the request shape emitted by portal authentication, policy, and CSRF middleware.
     *
     * @param   array<string, string>  $form          Flat dashboard preference form.
     * @param   list<string>           $capabilities  Capabilities carried by the portal principal.
     * @param   array<string, string>  $query         Optional untrusted continuation query.
     *
     * @return  ServerRequestInterface  Resolved portal POST request with parsed form data.
     *
     * @since   2.0.0
     */
    private function request(array $form, array $capabilities, array $query = []): ServerRequestInterface
    {
        $now = new DateTimeImmutable('2026-08-15T10:00:00+00:00');
        $principal = AuthorizationContext::principal($capabilities);
        $session = new PortalSession(
            '018f0000-0000-7000-8000-000000000002',
            new PortalSessionIdentity($principal, new PortalContext(SiteContext::default(), null), 1),
            str_repeat('c', 43),
            $now,
            null,
            $now->modify('+1 hour'),
        );

        return (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/portal/dashboard/preferences')
            ->withQueryParams($query)
            ->withParsedBody($form)
            ->withAttribute(PortalSession::REQUEST_ATTRIBUTE, $session)
            ->withAttribute(ExecutionContextAttribute::NAME, $principal->context(
                SiteContext::default(),
                AuthenticationStrength::Password,
                'test-portal-dashboard-preferences',
            ));
    }

    /**
     * Build the renderer used only for its canonical live session-filtered portal navigation projection.
     *
     * @param   ?ExtensionContributionRegistrySet  $registries  Optional contribution catalog for a scale scenario.
     *
     * @return  PortalRenderer  Core or supplied contribution registry-backed renderer.
     *
     * @since   2.0.0
     */
    private function renderer(?ExtensionContributionRegistrySet $registries = null): PortalRenderer
    {
        $visibility = $this->createStub(PortalNavigationVisibility::class);
        $visibility->method('visible')->willReturn(true);
        $registries ??= new ExtensionContributionRegistrySet();

        return new PortalRenderer(
            new Environment(new ArrayLoader()),
            $registries->portalNavigation(),
            $registries->portalTemplates(),
            $visibility,
        );
    }
}
