<?php

declare(strict_types=1);

namespace Kumwe\App\Portal\Http\Middleware;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Portal\Application\PortalSession;
use Laminas\Diactoros\Response\JsonResponse;
use LogicException;
use Mezzio\Router\Route;
use Mezzio\Router\RouteResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;

/**
 * Fail-closed capability declaration gate for every matched portal route.
 *
 * @since  2.0.0
 */
final readonly class PortalAuthorizationMiddleware implements MiddlewareInterface
{
    /**
     * Route option containing the non-empty list of required portal capabilities.
     *
     * @var    string
     * @since  2.0.0
     */
    public const OPTION_REQUIRED_CAPABILITIES = 'portal_required_capabilities';

    /**
     * Bind portal route enforcement to the canonical authorization gateway.
     *
     * @param  AuthorizationGateway  $authorization  Gateway evaluating the exact portal-session resource.
     *
     * @since  2.0.0
     */
    public function __construct(private AuthorizationGateway $authorization)
    {
    }

    /**
     * Require every declared capability from the live portal principal.
     *
     * @param   ServerRequestInterface   $request  Routed portal request.
     * @param   RequestHandlerInterface  $handler  Downstream dispatch.
     *
     * @return  ResponseInterface  Downstream response or 403 problem document.
     *
     * @throws  LogicException  When a matched portal route lacks a policy or an authenticated principal.
     *
     * @since   2.0.0
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (($path !== '/portal' && !str_starts_with($path, '/portal/')) || $path === '/portal/login') {
            return $handler->handle($request);
        }
        $routeResult = $request->getAttribute(RouteResult::class);
        if (!$routeResult instanceof RouteResult || !$routeResult->isSuccess()) {
            return $handler->handle($request);
        }
        $context = $request->getAttribute(ExecutionContextAttribute::NAME);
        $session = $request->getAttribute(PortalSession::REQUEST_ATTRIBUTE);
        if (!$context instanceof ExecutionContext || !$session instanceof PortalSession) {
            throw new LogicException('Portal authorization requires a resolved session context.');
        }
        foreach ($this->requiredCapabilities($routeResult) as $capability) {
            try {
                $this->authorization->assertAllowed(
                    $context,
                    $capability,
                    AuthorizationResource::item('portal_session', $session->id),
                );
            } catch (AuthorizationDenied) {
                return new JsonResponse([
                    'type' => 'urn:kumwe:problem:insufficient-capability',
                    'title' => 'Forbidden',
                    'status' => 403,
                    'detail' => sprintf('Capability %s is required for this portal operation.', $capability->value()),
                ], 403, ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store']);
            }
        }

        return $handler->handle($request);
    }

    /**
     * Validate, normalize, deduplicate, and sort one matched route's required capabilities.
     *
     * @param   RouteResult  $routeResult  Successful routing result.
     *
     * @return  non-empty-list<Capability>  Stable capability list.
     *
     * @throws  LogicException  When the route or option is missing or malformed.
     *
     * @since   2.0.0
     */
    private function requiredCapabilities(RouteResult $routeResult): array
    {
        $route = $routeResult->getMatchedRoute();
        if (!$route instanceof Route) {
            throw new LogicException('Portal authorization requires a matched route.');
        }
        $configured = $route->getOptions()[self::OPTION_REQUIRED_CAPABILITIES] ?? null;
        if (!is_array($configured) || !array_is_list($configured) || $configured === []) {
            throw new LogicException('Every portal route must declare required capabilities.');
        }
        $required = [];
        foreach ($configured as $value) {
            if (!is_string($value)) {
                throw new LogicException('Portal route capabilities must be strings.');
            }
            try {
                $capability = Capability::fromString($value);
            } catch (InvalidArgumentException $exception) {
                throw new LogicException('A portal route capability is invalid.', previous: $exception);
            }
            $required[$capability->value()] = $capability;
        }
        ksort($required, SORT_STRING);

        /** @var non-empty-list<Capability> $values */
        $values = array_values($required);
        return $values;
    }
}
