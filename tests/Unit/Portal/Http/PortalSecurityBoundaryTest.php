<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Portal\Http;

use Kumwe\App\Tests\Support\InterfaceTranslation;
use DateTimeImmutable;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\App\Application\Authorization\AuthorizationDecision;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Identity\Domain\GrantScope;
use Kumwe\App\Portal\Application\CreatedPortalSession;
use Kumwe\App\Portal\Application\PortalExecutionContextFactory;
use Kumwe\App\Portal\Application\PortalPasswordIdentity;
use Kumwe\App\Portal\Application\PortalSession;
use Kumwe\App\Portal\Application\PortalSessionIdentity;
use Kumwe\App\Portal\Application\PortalSessionStore;
use Kumwe\App\Portal\Application\PortalContext;
use Kumwe\App\Portal\Http\Middleware\PortalCsrfMiddleware;
use Kumwe\App\Portal\Http\Middleware\PortalAuthorizationMiddleware;
use Kumwe\App\Portal\Http\Middleware\PortalSessionMiddleware;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use Mezzio\Router\Route;
use Mezzio\Router\RouteResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;

#[CoversClass(PortalSessionMiddleware::class)]
#[CoversClass(PortalCsrfMiddleware::class)]
#[CoversClass(PortalAuthorizationMiddleware::class)]
final class PortalSecurityBoundaryTest extends TestCase
{
    public function testAdministratorCookieCannotAuthenticateThePortalButPortalCookieCan(): void
    {
        [$session, $provenance] = $this->session();
        $store = new BoundaryPortalSessionStore($session);
        $middleware = new PortalSessionMiddleware(
            $store,
            new BoundaryPortalContextFactory($provenance),
            new AllowingPortalAuthorization(),
        );
        $handler = new CapturingPortalHandler();

        $administratorOnly = (new ServerRequest([], [], new Uri('https://example.test/portal'), 'GET'))
            ->withCookieParams(['kumwe_administrator' => 'administrator-token']);
        self::assertSame(303, $middleware->process($administratorOnly, $handler)->getStatusCode());
        self::assertSame([], $store->lookups);

        $outsideBoundary = (new ServerRequest([], [], new Uri('https://example.test/portals'), 'GET'))
            ->withCookieParams([PortalSessionMiddleware::COOKIE_NAME => 'portal-token']);
        self::assertSame(200, $middleware->process($outsideBoundary, $handler)->getStatusCode());
        self::assertSame([], $store->lookups);

        $portal = (new ServerRequest([], [], new Uri('https://example.test/portal'), 'GET'))
            ->withCookieParams([PortalSessionMiddleware::COOKIE_NAME => 'portal-token']);
        self::assertSame(200, $middleware->process($portal, $handler)->getStatusCode());
        self::assertSame(['portal-token'], $store->lookups);
        self::assertInstanceOf(
            PortalSession::class,
            $handler->request?->getAttribute(PortalSession::REQUEST_ATTRIBUTE),
        );
        $context = $handler->request?->getAttribute(ExecutionContextAttribute::NAME);
        self::assertInstanceOf(ExecutionContext::class, $context);
        self::assertSame(AuthenticatedSurface::Portal, $context->surface());
    }

    public function testPortalCsrfTokenIsIndependentAndComparedBeforeDispatch(): void
    {
        [$session] = $this->session();
        $middleware = new PortalCsrfMiddleware(
            InterfaceTranslation::translator(),
            InterfaceTranslation::activeLocale(),
        );
        $handler = new CapturingPortalHandler();
        $request = (new ServerRequest([], [], new Uri('https://example.test/portal/logout'), 'POST'))
            ->withAttribute(PortalSession::REQUEST_ATTRIBUTE, $session)
            ->withParsedBody(['_csrf' => 'administrator-csrf']);

        self::assertSame(403, $middleware->process($request, $handler)->getStatusCode());
        self::assertNull($handler->request);

        $request = $request->withParsedBody([
            '_csrf' => $session->csrfToken,
            'values' => ['name' => 'Nested generated value'],
        ]);
        self::assertSame(200, $middleware->process($request, $handler)->getStatusCode());
        self::assertSame(
            ['_csrf' => $session->csrfToken],
            $handler->request?->getParsedBody(),
        );
        self::assertSame(
            [
                '_csrf' => $session->csrfToken,
                'values' => ['name' => 'Nested generated value'],
            ],
            $handler->request?->getAttribute(PortalCsrfMiddleware::ATTRIBUTE_PARSED_BODY),
        );
    }

    public function testPortalRouteCapabilityIsAuthorizedAgainstTheExactResolvedSessionResource(): void
    {
        [$session, $provenance] = $this->session();
        $context = (new BoundaryPortalContextFactory($provenance))->create($session, 'request-1234');
        $route = new Route(
            '/portal/security',
            $this->createStub(MiddlewareInterface::class),
            ['GET'],
            'portal.security',
        );
        $route->setOptions([
            PortalAuthorizationMiddleware::OPTION_REQUIRED_CAPABILITIES => ['portal.access'],
        ]);
        $gateway = new AllowingPortalAuthorization();
        $request = (new ServerRequest([], [], new Uri('https://example.test/portal/security'), 'GET'))
            ->withAttribute(RouteResult::class, RouteResult::fromRoute($route))
            ->withAttribute(PortalSession::REQUEST_ATTRIBUTE, $session)
            ->withAttribute(ExecutionContextAttribute::NAME, $context);

        self::assertSame(200, (new PortalAuthorizationMiddleware($gateway))->process(
            $request,
            new CapturingPortalHandler(),
        )->getStatusCode());
        self::assertSame([
            ['portal.access', 'portal_session', $session->id],
        ], $gateway->attempts);
    }

    public function testPortalRouteIsDeniedWhenGatewayRefusesEvenIfPrincipalCarriesTheCapability(): void
    {
        [$session, $provenance] = $this->session();
        $context = (new BoundaryPortalContextFactory($provenance))->create($session, 'request-1234');
        $route = new Route(
            '/portal/security',
            $this->createStub(MiddlewareInterface::class),
            ['GET'],
            'portal.security',
        );
        $route->setOptions([
            PortalAuthorizationMiddleware::OPTION_REQUIRED_CAPABILITIES => ['portal.access'],
        ]);
        $request = (new ServerRequest([], [], new Uri('https://example.test/portal/security'), 'GET'))
            ->withAttribute(RouteResult::class, RouteResult::fromRoute($route))
            ->withAttribute(PortalSession::REQUEST_ATTRIBUTE, $session)
            ->withAttribute(ExecutionContextAttribute::NAME, $context);

        self::assertSame(403, (new PortalAuthorizationMiddleware(
            new AllowingPortalAuthorization(false),
        ))->process($request, new CapturingPortalHandler())->getStatusCode());
    }

    /** @return array{PortalSession, object} */
    private function session(): array
    {
        $provenance = new \stdClass();
        $principal = AuthenticatedPrincipal::issueFromStrings(
            $provenance,
            '018f0000-0000-7000-8000-000000000001',
            ['portal.access'],
        );
        $now = new DateTimeImmutable('2026-08-09T10:00:00+00:00');
        $session = new PortalSession(
            '018f0000-0000-7000-8000-000000000002',
            new PortalSessionIdentity($principal, new PortalContext(SiteContext::default(), null), 1),
            str_repeat('c', 43),
            $now,
            null,
            $now->modify('+1 hour'),
        );
        return [$session, $provenance];
    }
}

final class BoundaryPortalSessionStore implements PortalSessionStore
{
    /** @var list<string> */
    public array $lookups = [];

    public function __construct(private PortalSession $session)
    {
    }

    public function create(
        PortalPasswordIdentity $identity,
        PortalContext $context,
        string $userAgent,
    ): CreatedPortalSession {
        throw new \LogicException('unused');
    }

    public function find(string $cookieToken, string $userAgent): ?PortalSession
    {
        $this->lookups[] = $cookieToken;
        return $cookieToken === 'portal-token' ? $this->session : null;
    }

    public function delete(string $sessionId, string $subjectId): void
    {
    }

    public function purgeExpired(): int
    {
        return 0;
    }
}

final readonly class BoundaryPortalContextFactory implements PortalExecutionContextFactory
{
    public function __construct(private object $provenance)
    {
    }

    public function create(PortalSession $session, string $requestId): ExecutionContext
    {
        return ExecutionContext::issueHuman(
            $this->provenance,
            $session->identity->principal,
            $session->identity->context->site,
            AuthenticationStrength::Password,
            $requestId,
            surface: AuthenticatedSurface::Portal,
            membership: $session->identity->context->membership,
            sessionId: $session->id,
        );
    }
}

final class AllowingPortalAuthorization implements AuthorizationGateway
{
    /** @var list<array{string, string, string}> */
    public array $attempts = [];

    public function __construct(private bool $allow = true)
    {
    }

    public function decide(
        ExecutionContext $context,
        Capability $action,
        AuthorizationResource $resource,
    ): AuthorizationDecision {
        throw new \LogicException('unused');
    }

    public function assertAllowed(
        ExecutionContext $context,
        Capability $action,
        AuthorizationResource $resource,
    ): void {
        $this->attempts[] = [$action->value(), $resource->type(), $resource->identifier()];
        if (!$this->allow) {
            throw new \Kumwe\App\Application\Authorization\AuthorizationDenied(
                $context->actorId(),
                $action->value(),
                $resource->type(),
                $resource->identifier(),
                $context->site()->identifier(),
                'test',
                'denied',
            );
        }
    }

    public function assertCanDelegate(
        ExecutionContext $context,
        Capability $action,
        GrantScope $scope,
    ): void {
    }
}

final class CapturingPortalHandler implements RequestHandlerInterface
{
    public ?ServerRequestInterface $request = null;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->request = $request;
        return new HtmlResponse('ok');
    }
}
