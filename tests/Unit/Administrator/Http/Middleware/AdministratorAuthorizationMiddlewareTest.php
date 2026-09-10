<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Administrator\Http\Middleware;

use DateTimeImmutable;
use Kumwe\App\Administrator\Http\Middleware\AdministratorAuthorizationMiddleware;
use Kumwe\App\Administrator\Navigation\AdministratorNavigationRegistry;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\App\Administrator\Presentation\RecoveryAdministratorRenderer;
use Kumwe\App\Identity\Application\Administration\AdministratorSession;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Localization\Application\ActiveLocale;
use Kumwe\App\Localization\Application\CatalogueTranslator;
use Kumwe\App\Localization\Application\SupportedLocales;
use Kumwe\App\Localization\Domain\LocaleTag;
use Kumwe\App\Localization\Http\Middleware\TranslationScopeMiddleware;
use Kumwe\App\Localization\Infrastructure\ArrayMessageOverrideRepository;
use Kumwe\App\Localization\Infrastructure\CompiledMessageCatalogueRepository;
use Kumwe\App\Localization\Infrastructure\IntlMessagePatternFormatter;
use Kumwe\App\Localization\Presentation\TranslationTwigExtension;
use Kumwe\App\Presentation\Twig\AdministratorTwigEnvironment;
use Kumwe\App\Presentation\Twig\RecoveryAdministratorTwigEnvironment;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\InterfaceTranslation;
use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\ServerRequestFactory;
use LogicException;
use Mezzio\Router\Route;
use Mezzio\Router\RouteResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Twig\Loader\ArrayLoader;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;

#[CoversClass(AdministratorAuthorizationMiddleware::class)]
#[UsesClass(AuthenticatedPrincipal::class)]
#[UsesClass(AdministratorRenderer::class)]
#[UsesClass(RecoveryAdministratorRenderer::class)]
#[UsesClass(AdministratorSession::class)]
final class AdministratorAuthorizationMiddlewareTest extends TestCase
{
    private const SUBJECT = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';

    public function testBypassesRequestsOutsideAdministratorArea(): void
    {
        $response = (new AdministratorAuthorizationMiddleware())->process(
            (new ServerRequestFactory())->createServerRequest('GET', 'https://kumwe.test/'),
            $this->successfulHandler(),
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testAllowsAdministratorWithEveryRequiredCapability(): void
    {
        $principal = AuthorizationContext::principal([
            'navigation.manage',
            'administrator.access',
        ], self::SUBJECT);
        $response = (new AdministratorAuthorizationMiddleware())->process(
            $this->request(['administrator.access', 'navigation.manage'], $principal),
            $this->successfulHandler(),
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testDeniesAdministratorMissingAnExactCapability(): void
    {
        $principal = AuthorizationContext::principal(['administrator.access'], self::SUBJECT);
        $response = (new AdministratorAuthorizationMiddleware())->process(
            $this->request(['navigation.manage'], $principal),
            $this->neverHandler(),
        );
        $problem = json_decode((string) $response->getBody(), true, 8, JSON_THROW_ON_ERROR);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertIsArray($problem);
        self::assertSame(
            'Capability navigation.manage is required for this administrator operation.',
            $problem['detail'] ?? null,
        );
    }

    public function testBrowserNavigationDenialRendersTheThemedPageWithTheActorsOwnNavigation(): void
    {
        $principal = AuthorizationContext::principal(['administrator.access'], self::SUBJECT);
        $middleware = new AdministratorAuthorizationMiddleware($this->renderer());
        $response = $middleware->process(
            $this->request(['navigation.manage'], $principal)
                ->withHeader('Accept', 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8')
                ->withAttribute(AdministratorSession::REQUEST_ATTRIBUTE, $this->session($principal)),
            $this->neverHandler(),
        );
        $body = (string) $response->getBody();

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('missing:navigation.manage', $body);
        self::assertStringContainsString('[core.dashboard]', $body, 'The held navigation must be offered.');
        self::assertStringNotContainsString(
            '[core.navigation]',
            $body,
            'Navigation the actor cannot open must stay hidden on the denial page.',
        );
    }

    /**
     * A trusted organization scope reaches the localized denial that returns before dispatch.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAuthenticatedOrganizationWordingReachesAnEarlyHtmlDenial(): void
    {
        $context = AuthorizationContext::human(
            ['administrator.access'],
            membership: AuthorizationContext::membership('acme'),
        );
        $principal = $context->principal();
        self::assertInstanceOf(AuthenticatedPrincipal::class, $principal);
        $supported = new SupportedLocales();
        $active = new ActiveLocale($supported);
        $active->begin(LocaleTag::fromString('de'));
        $authorization = new AdministratorAuthorizationMiddleware(
            $this->localizedRenderer($active, $supported),
        );
        $terminal = $this->neverHandler();
        $request = $this->request(['navigation.manage'], $principal)
            ->withHeader('Accept', 'text/html')
            ->withAttribute(ExecutionContextAttribute::NAME, $context)
            ->withAttribute(AdministratorSession::REQUEST_ATTRIBUTE, $this->session($principal));

        try {
            $response = (new TranslationScopeMiddleware($active))->process(
                $request,
                new class ($authorization, $terminal) implements RequestHandlerInterface {
                    public function __construct(
                        private readonly AdministratorAuthorizationMiddleware $authorization,
                        private readonly RequestHandlerInterface $terminal,
                    ) {
                    }

                    public function handle(ServerRequestInterface $request): ResponseInterface
                    {
                        return $this->authorization->process($request, $this->terminal);
                    }
                },
            );
        } finally {
            $active->end();
        }

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString(
            'Organisationswort <strong>navigation.manage</strong>',
            (string) $response->getBody(),
        );
    }

    public function testNonHtmlAcceptsKeepTheProblemDocumentEvenWithARendererWired(): void
    {
        $principal = AuthorizationContext::principal(['administrator.access'], self::SUBJECT);
        $middleware = new AdministratorAuthorizationMiddleware($this->renderer());
        $response = $middleware->process(
            $this->request(['navigation.manage'], $principal)
                ->withHeader('Accept', 'application/json')
                ->withAttribute(AdministratorSession::REQUEST_ATTRIBUTE, $this->session($principal)),
            $this->neverHandler(),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
    }

    public function testMutationDenialsKeepTheProblemDocumentEvenWhenTheBrowserAcceptsHtml(): void
    {
        $principal = AuthorizationContext::principal(['administrator.access'], self::SUBJECT);
        $middleware = new AdministratorAuthorizationMiddleware($this->renderer());
        $response = $middleware->process(
            $this->request(['navigation.manage'], $principal, method: 'POST')
                ->withHeader('Accept', 'text/html')
                ->withAttribute(AdministratorSession::REQUEST_ATTRIBUTE, $this->session($principal)),
            $this->neverHandler(),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
    }

    public function testHtmlDenialFallsBackToTheProblemDocumentWithoutASession(): void
    {
        $principal = AuthorizationContext::principal(['administrator.access'], self::SUBJECT);
        $middleware = new AdministratorAuthorizationMiddleware($this->renderer());
        $response = $middleware->process(
            $this->request(['navigation.manage'], $principal)->withHeader('Accept', 'text/html'),
            $this->neverHandler(),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
    }

    /**
     * An administrator URL nothing serves matches the public catch-all and is forwarded to the not-found answer.
     *
     * The public site registers `/{path:.+}`, so a signed-in administrator's mistyped URL always has a
     * matched route; that route declares no administrator policy because it is not an administrator
     * route. Treating it as an undeclared screen turned every typo into a 500; it must forward instead.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testForwardsAnUnknownAdministratorPathMatchedByThePublicCatchAll(): void
    {
        $principal = AuthorizationContext::principal(['administrator.access'], self::SUBJECT);
        $route = new Route('/{path:.+}', $this->createStub(MiddlewareInterface::class), ['GET'], 'site.content.path');
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/administrator/nonexistent')
            ->withAttribute(RouteResult::class, RouteResult::fromRoute($route, ['path' => 'administrator/nonexistent']))
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal);

        $response = (new AdministratorAuthorizationMiddleware())->process($request, $this->successfulHandler());

        self::assertSame(204, $response->getStatusCode());
    }

    public function testFailsClosedWhenAdministratorRouteHasNoCapabilityPolicy(): void
    {
        $principal = AuthorizationContext::principal(['administrator.access'], self::SUBJECT);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must declare required capabilities');

        (new AdministratorAuthorizationMiddleware())->process(
            $this->request([], $principal, false),
            $this->neverHandler(),
        );
    }

    /** @param list<string> $capabilities */
    private function request(
        array $capabilities,
        AuthenticatedPrincipal $principal,
        bool $configurePolicy = true,
        string $method = 'GET',
    ): ServerRequestInterface {
        $route = new Route(
            '/administrator/navigation',
            $this->createStub(MiddlewareInterface::class),
            [$method],
            'administrator.navigation',
        );

        if ($configurePolicy) {
            $route->setOptions([
                AdministratorAuthorizationMiddleware::OPTION_REQUIRED_CAPABILITIES => $capabilities,
            ]);
        }

        return (new ServerRequestFactory())
            ->createServerRequest($method, 'https://kumwe.test/administrator/navigation')
            ->withAttribute(RouteResult::class, RouteResult::fromRoute($route))
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal);
    }

    private function session(AuthenticatedPrincipal $principal): AdministratorSession
    {
        return new AdministratorSession(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb399',
            $principal,
            'csrf-token',
            new DateTimeImmutable('+1 hour'),
        );
    }

    private function renderer(): AdministratorRenderer
    {
        $template = 'missing:{{ missing_capability }}'
            . '{% for item in administrator_navigation %}[{{ item.id }}]{% endfor %}';

        return new AdministratorRenderer(
            new AdministratorTwigEnvironment(new ArrayLoader(['access-denied.twig' => $template])),
            new RecoveryAdministratorRenderer(
                new RecoveryAdministratorTwigEnvironment(new ArrayLoader()),
            ),
            AdministratorNavigationRegistry::core(),
        );
    }

    /**
     * Build an administrator renderer whose denial message has an organization override.
     *
     * @param   ActiveLocale      $active     Locale and scope holder shared with the scope middleware.
     * @param   SupportedLocales  $supported  Locale registry used by the translator.
     *
     * @return  AdministratorRenderer  Renderer using the real translation Twig extension.
     *
     * @since   2.0.0
     */
    private function localizedRenderer(ActiveLocale $active, SupportedLocales $supported): AdministratorRenderer
    {
        $translator = new CatalogueTranslator(
            new CompiledMessageCatalogueRepository(InterfaceTranslation::compiledDirectory()),
            new ArrayMessageOverrideRepository([], [
                'default/acme' => [
                    'de' => [
                        'core.administrator.access_denied.explanation' =>
                            'Organisationswort <strong>{capability}</strong>',
                    ],
                ],
            ]),
            new IntlMessagePatternFormatter(),
            $active,
            $supported,
        );
        $twig = new AdministratorTwigEnvironment(new ArrayLoader([
            'access-denied.twig' => "{{ t_html('core.administrator.access_denied.explanation', "
                . '{capability: missing_capability}) }}',
        ]));
        $twig->addExtension(new TranslationTwigExtension($translator, $active));

        return new AdministratorRenderer(
            $twig,
            new RecoveryAdministratorRenderer(
                new RecoveryAdministratorTwigEnvironment(new ArrayLoader()),
            ),
            AdministratorNavigationRegistry::core(),
        );
    }

    private function successfulHandler(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new TextResponse('', 204);
            }
        };
    }

    private function neverHandler(): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        return $handler;
    }
}
