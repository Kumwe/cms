<?php

declare(strict_types=1);

namespace Kumwe\App\Portal\Http\Middleware;

use Kumwe\Extension\Spi\Http\ExtensionRequest;
use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Http\Middleware\RequestIdMiddleware;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Portal\Application\PortalExecutionContextFactory;
use Kumwe\App\Portal\Application\PortalSession;
use Kumwe\App\Portal\Application\PortalSessionStore;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Kumwe\App\Extension\Runtime\ExtensionExecutionContext;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;

/**
 * Exchanges only the portal cookie for a live portal session and portal-surface execution context.
 *
 * @since  2.0.0
 */
final readonly class PortalSessionMiddleware implements MiddlewareInterface
{
    /**
     * Portal-only cookie name, deliberately distinct from `kumwe_administrator`.
     *
     * @var    string
     * @since  2.0.0
     */
    public const COOKIE_NAME = 'kumwe_portal';

    /**
     * Bind authentication to the portal store, context issuer, and shared authorization gateway.
     *
     * @param  PortalSessionStore             $sessions       Dedicated portal session store.
     * @param  PortalExecutionContextFactory  $contexts       Portal provenance authority.
     * @param  AuthorizationGateway           $authorization  Same deny-by-default policy gateway as other surfaces.
     *
     * @since  2.0.0
     */
    public function __construct(
        private PortalSessionStore $sessions,
        private PortalExecutionContextFactory $contexts,
        private AuthorizationGateway $authorization,
    ) {
    }

    /**
     * Authenticate `/portal` except its login route and require the independent `portal.access` grant.
     *
     * @param   ServerRequestInterface   $request  Incoming request.
     * @param   RequestHandlerInterface  $handler  Next portal middleware or handler.
     *
     * @return  ResponseInterface  Downstream response, login redirect, or non-redirecting 401/403 problem.
     *
     * @since   2.0.0
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (($path !== '/portal' && !str_starts_with($path, '/portal/')) || $path === '/portal/login') {
            return $handler->handle($request);
        }
        $token = $request->getCookieParams()[self::COOKIE_NAME] ?? null;
        if (!is_string($token)) {
            return $this->unauthenticated($request);
        }
        $session = $this->sessions->find($token, $request->getHeaderLine('User-Agent'));
        if (!$session instanceof PortalSession) {
            return $this->unauthenticated($request);
        }
        $context = $this->contexts->create($session, $this->requestId($request));
        try {
            $this->authorization->assertAllowed(
                $context,
                Capability::fromString('portal.access'),
                AuthorizationResource::item('portal_session', $session->id),
            );
        } catch (AuthorizationDenied) {
            return new JsonResponse([
                'type' => 'about:blank',
                'title' => 'Forbidden',
                'status' => 403,
                'detail' => 'Portal access is not granted for this session.',
            ], 403, ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store']);
        }

        return $handler->handle(
            $request
                ->withAttribute(PortalSession::REQUEST_ATTRIBUTE, $session)
                ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $session->identity->principal)
                ->withAttribute(ExecutionContextAttribute::NAME, $context)
                ->withAttribute(ExtensionRequest::CONTEXT, ExtensionExecutionContext::of($context))
                ->withAttribute(ExtensionRequest::CSRF_TOKEN, $session->csrfToken),
        );
    }

    /**
     * Return a browser redirect only for safe navigation, never for a state-changing request.
     *
     * @param   ServerRequestInterface  $request  Unauthenticated request.
     *
     * @return  ResponseInterface  303 login redirect or 401 problem document.
     *
     * @since   2.0.0
     */
    private function unauthenticated(ServerRequestInterface $request): ResponseInterface
    {
        if (in_array(strtoupper($request->getMethod()), ['GET', 'HEAD'], true)) {
            return new RedirectResponse('/portal/login', 303, ['Cache-Control' => 'no-store']);
        }

        return new JsonResponse([
            'type' => 'about:blank',
            'title' => 'Unauthorized',
            'status' => 401,
            'detail' => 'A valid portal session is required.',
        ], 401, ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store']);
    }

    /**
     * Read the request id supplied by the outer pipeline or mint a bounded fallback.
     *
     * @param   ServerRequestInterface  $request  Current request.
     *
     * @return  string  Request correlation identifier.
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
