<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Portal\Presentation;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\App\Application\Authorization\AuthorizationPolicyRegistry;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Extension\Contribution\CapabilityDefinitionRegistry;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Portal\Application\PortalContext;
use Kumwe\App\Portal\Application\PortalSession;
use Kumwe\App\Portal\Application\PortalSessionIdentity;
use Kumwe\App\Portal\Contribution\PortalNavigationRegistry;
use Kumwe\App\Portal\Contribution\PortalTemplateRegistry;
use Kumwe\App\Portal\Contribution\PortalWorkspaceRegistry;
use Kumwe\App\Portal\Presentation\PortalContributionRenderer;
use Kumwe\App\Portal\Presentation\PortalNavigationVisibility;
use Kumwe\App\Portal\Presentation\PortalRenderer;
use Kumwe\App\Presentation\Twig\IsolatedTwigEnvironmentFactory;
use Kumwe\Extension\Spi\Binding\Http\PortalRouteRenderer;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\Extension\Spi\Portal\Contribution\PortalTemplateDefinition;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;

#[CoversClass(PortalContributionRenderer::class)]
/**
 * Proves portal extension route rendering stays template-bound and refuses untrusted requests.
 *
 * @since  2.0.0
 */
final class PortalContributionRendererTest extends TestCase
{
    /**
     * Prove each route renderer keeps its own template, navigation id and derived capabilities, ignoring
     * caller-supplied template, active_navigation and capabilities overrides.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCapabilitiesAreTemplateBoundAndCannotCrossRender(): void
    {
        [$renderer, $owner, $provenance] = $this->renderer();
        $first = $renderer->forExtensionRoute($owner->identifier(), 'acme.routes.one', 'acme.routes.nav-one');
        $second = $renderer->forExtensionRoute($owner->identifier(), 'acme.routes.two', 'acme.routes.nav-two');
        $request = $this->request($provenance, AuthenticatedSurface::Portal);

        self::assertInstanceOf(PortalRouteRenderer::class, $first);
        self::assertNotSame($first, $second);
        self::assertSame(
            'one|safe|acme.routes.nav-one|yes',
            $first->render([
                'marker' => 'safe',
                'active_navigation' => 'acme.routes.nav-two',
                'capabilities' => ['acme.routes.forged' => true],
                'template' => 'acme.routes.two',
            ], $request),
        );
        self::assertSame(
            'two|safe|acme.routes.nav-two|yes',
            $second->render(['marker' => 'safe', 'template' => 'acme.routes.one'], $request),
        );
    }

    /**
     * Prove rendering refuses fabricated request attributes, foreign provenance, and the wrong surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testFabricatedOrWrongSurfaceRequestsAreRefused(): void
    {
        [$renderer, $owner, $provenance] = $this->renderer();
        $capability = $renderer->forExtensionRoute(
            $owner->identifier(),
            'acme.routes.one',
            'acme.routes.nav-one',
        );
        $fabricated = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/portal/extensions/acme/routes')
            ->withAttribute(PortalSession::REQUEST_ATTRIBUTE, new stdClass())
            ->withAttribute(ExecutionContextAttribute::NAME, new stdClass());

        $this->assertRefused(static fn (): string => $capability->render([], $fabricated));
        $this->assertRefused(fn (): string => $capability->render(
            [],
            $this->request(new stdClass(), AuthenticatedSurface::Portal),
        ));
        $this->assertRefused(fn (): string => $capability->render(
            [],
            $this->request($provenance, AuthenticatedSurface::Administrator),
        ));
    }

    /**
     * Build a portal renderer with two registered extension templates over an isolated Twig namespace.
     *
     * @return array{PortalRenderer, ContributionOwner, object}
     *
     * @since   2.0.0
     */
    private function renderer(): array
    {
        $provenance = new stdClass();
        $owner = ContributionOwner::extension('acme/routes');
        $templates = new PortalTemplateRegistry();
        $templates->register($owner, new PortalTemplateDefinition('acme.routes.one', 'one.twig'));
        $templates->register($owner, new PortalTemplateDefinition('acme.routes.two', 'two.twig'));
        $namespace = IsolatedTwigEnvironmentFactory::extensionNamespace($owner->identifier());
        $template = static fn (string $name): string => $name
            . '|{{ marker }}|{{ active_navigation }}|'
            . "{{ capabilities['acme.routes.open'] ? 'yes' : 'no' }}";
        $navigation = new PortalNavigationRegistry(
            new PortalWorkspaceRegistry(),
            new CapabilityDefinitionRegistry(),
            new AuthorizationPolicyRegistry(),
        );

        return [new PortalRenderer(
            new Environment(new ArrayLoader([
                '@' . $namespace . '/one.twig' => $template('one'),
                '@' . $namespace . '/two.twig' => $template('two'),
            ])),
            $navigation,
            $templates,
            $this->createStub(PortalNavigationVisibility::class),
            extensionRequestProvenance: $provenance,
        ), $owner, $provenance];
    }

    /**
     * Build a portal request carrying a live session and an execution context for the given surface.
     *
     * @param   object                $provenance  Provenance token the execution context is issued under.
     * @param   AuthenticatedSurface  $surface     Surface the execution context is issued for.
     *
     * @return  ServerRequestInterface  Request with session and execution-context attributes attached.
     *
     * @since   2.0.0
     */
    private function request(object $provenance, AuthenticatedSurface $surface): ServerRequestInterface
    {
        $principal = AuthenticatedPrincipal::issueFromStrings(
            $provenance,
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb399',
            ['acme.routes.open'],
        );
        $identity = new PortalSessionIdentity(
            $principal,
            new PortalContext(SiteContext::default(), null),
            1,
        );
        $session = new PortalSession(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb400',
            $identity,
            str_repeat('c', 32),
            new DateTimeImmutable('-1 hour'),
            null,
            new DateTimeImmutable('+1 hour'),
        );
        $context = ExecutionContext::issueHuman(
            $provenance,
            $principal,
            SiteContext::default(),
            AuthenticationStrength::Password,
            'route-render-request',
            surface: $surface,
            sessionId: $session->id,
        );

        return (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/portal/extensions/acme/routes')
            ->withAttribute(PortalSession::REQUEST_ATTRIBUTE, $session)
            ->withAttribute(ExecutionContextAttribute::NAME, $context);
    }

    /**
     * Assert one rendering attempt is refused with an InvalidArgumentException.
     *
     * @param callable(): string $render
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertRefused(callable $render): void
    {
        try {
            $render();
            self::fail('An untrusted portal rendering request must be refused.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }
}
