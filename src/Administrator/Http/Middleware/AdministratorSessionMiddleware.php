<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Http\Middleware;

use Kumwe\Extension\Spi\Http\ExtensionRequest;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Http\Middleware\RequestIdMiddleware;
use Kumwe\App\Identity\Application\Administration\AdministratorSession;
use Kumwe\App\Identity\Application\Administration\AdministratorSessionStore;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Kumwe\App\Extension\Runtime\ExtensionExecutionContext;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;

/**
 * Turns the administrator cookie into the session, principal and execution context the back office runs on.
 *
 * Every `/administrator` path bar the sign-in form passes through here first: the opaque cookie token is
 * exchanged with `AdministratorSessionStore` for a live session, that session's principal mints the
 * `ExecutionContext` the request is served under, and `administrator.access` is required on the session
 * before anything downstream sees the request. Handlers therefore read all three back off request
 * attributes instead of resolving them, and `AdministratorAuthorizationMiddleware` runs next to enforce
 * the capabilities each individual route declares. A caller with no live session is refused in the shape
 * its method deserves — a browser navigation is redirected to the sign-in form, anything else receives
 * an `application/problem+json` document — whereas a session that resolves but is denied the capability
 * is always answered with a 403 problem document, since signing in again would not help it.
 *
 * @since  2.0.0
 */
final readonly class AdministratorSessionMiddleware implements MiddlewareInterface
{
    /**
     * Name of the cookie the browser presents the opaque administrator session token in.
     *
     * `AdministratorLoginHandler` sets it and `AdministratorLogoutHandler` clears it through this same
     * constant, so the three cannot drift apart on the spelling.
     *
     * @var    string
     * @since  2.0.0
     */
    public const COOKIE_NAME = 'kumwe_administrator';

    /**
     * Wire the middleware to the session store, the authorization port, and the site it guards.
     *
     * @param  AdministratorSessionStore  $sessions       Exchanges a cookie token for the session it names.
     * @param  AuthorizationGateway       $authorization  Asked for `administrator.access` on every resolved
     *         session before the request is forwarded.
     * @param  ?SiteContext               $site           Site the execution context is issued for; null falls
     *         back to the single-site default context.
     *
     * @since  2.0.0
     */
    public function __construct(
        private AdministratorSessionStore $sessions,
        private AuthorizationGateway $authorization,
        private ?SiteContext $site = null,
    ) {
    }

    /**
     * Resolve the administrator session behind this request, or answer the caller that has none.
     *
     * Paths outside `/administrator`, and the sign-in form itself, are forwarded untouched. The lookup
     * passes the request's `User-Agent`, so a token replayed from another client resolves to nothing and
     * is treated exactly like a missing cookie. A session that does resolve but is refused
     * `administrator.access` is answered 403 rather than being sent to the sign-in form, because signing
     * in again would not change the decision.
     *
     * @param   ServerRequestInterface   $request  Incoming request; only `/administrator` paths are examined.
     * @param   RequestHandlerInterface  $handler  Next handler, reached with the session, the principal and the
     *          execution context attached as request attributes.
     *
     * @return  ResponseInterface  The handler's response, a 303 to the sign-in form, or a 401 or 403 problem
     *          document.
     *
     * @since   2.0.0
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if (!str_starts_with($path, '/administrator') || $path === '/administrator/login') {
            return $handler->handle($request);
        }

        $token = $request->getCookieParams()[self::COOKIE_NAME] ?? null;

        if (!is_string($token)) {
            return $this->unauthenticated($request);
        }

        $session = $this->sessions->find($token, $request->getHeaderLine('User-Agent'));

        if ($session === null) {
            return $this->unauthenticated($request);
        }

        $context = $session->principal->context(
            $session->site ?? $this->site ?? SiteContext::default(),
            AuthenticationStrength::Password,
            $this->requestId($request),
            surface: AuthenticatedSurface::Administrator,
            membership: $session->membership,
            sessionId: $session->id,
        );
        try {
            $this->authorization->assertAllowed(
                $context,
                Capability::fromString('administrator.access'),
                AuthorizationResource::item('administrator_session', $session->id),
            );
        } catch (AuthorizationDenied) {
            return new JsonResponse([
                'type' => 'about:blank',
                'title' => 'Forbidden',
                'status' => 403,
                'detail' => 'Administrator access is not granted for this session.',
            ], 403, ['Content-Type' => 'application/problem+json']);
        }

        return $handler->handle(
            $request
                ->withAttribute(AdministratorSession::REQUEST_ATTRIBUTE, $session)
                ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $session->principal)
                ->withAttribute(ExecutionContextAttribute::NAME, $context)
                ->withAttribute(ExtensionRequest::CONTEXT, ExtensionExecutionContext::of($context))
                ->withAttribute(ExtensionRequest::CSRF_TOKEN, $session->csrfToken),
        );
    }

    /**
     * Answer a request whose cookie names no live session, in the shape its method deserves.
     *
     * A `GET` or `HEAD` is a browser navigation, so it is redirected to the sign-in form; any other
     * method is a submission or a scripted call that a redirect would silently swallow, so it is refused
     * with a problem document instead. The redirect is marked `no-store` so a shared browser cannot go
     * back to the page that was refused.
     *
     * @param   ServerRequestInterface  $request  Request whose method decides the shape of the refusal.
     *
     * @return  ResponseInterface  A 303 redirect to `/administrator/login`, or a 401
     *          `application/problem+json` document.
     *
     * @since   2.0.0
     */
    private function unauthenticated(ServerRequestInterface $request): ResponseInterface
    {
        if (in_array(strtoupper($request->getMethod()), ['GET', 'HEAD'], true)) {
            return new RedirectResponse('/administrator/login', 303, ['Cache-Control' => 'no-store']);
        }

        return new JsonResponse([
            'type' => 'about:blank',
            'title' => 'Unauthorized',
            'status' => 401,
            'detail' => 'A valid administrator session is required.',
        ], 401, ['Content-Type' => 'application/problem+json']);
    }

    /**
     * Read the correlation identifier for the execution context, minting one when the pipeline has none.
     *
     * @param   ServerRequestInterface  $request  Request that `RequestIdMiddleware` would normally have stamped.
     *
     * @return  string  The pipeline request id, or a freshly generated `request-<hex>` substitute.
     *
     * @since   2.0.0
     */
    private function requestId(ServerRequestInterface $request): string
    {
        $requestId = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);

        return is_string($requestId) && $requestId !== ''
            ? $requestId
            : 'request-' . bin2hex(random_bytes(16));
    }
}
