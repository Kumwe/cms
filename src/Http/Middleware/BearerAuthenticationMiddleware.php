<?php

declare(strict_types=1);

namespace Kumwe\App\Http\Middleware;

use InvalidArgumentException;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Identity\Application\Authentication\ScopedAccessTokenVerifier;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
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
 * Authenticates the API routes that opt in to bearer tokens and enforces the capabilities they declare.
 *
 * The middleware is piped after routing so it can read the matched route's options: a route is
 * protected only when it sets `OPTION_AUTHENTICATION` to `bearer`, and every other route passes
 * through untouched. Protection is therefore declared beside the route rather than duplicated in each
 * handler. For a protected route the presented token is verified against the route's audience and
 * purpose and the site named by `SITE_HEADER`, each required capability is checked, and the resulting
 * `AuthenticatedPrincipal` and `ExecutionContext` are published as request attributes for the handler
 * to authorize against. Rejections are problem documents carrying the matching `WWW-Authenticate`
 * challenge, and a route configured incorrectly raises `LogicException` instead of silently
 * degrading to a weaker check.
 *
 * @since  2.0.0
 */
final readonly class BearerAuthenticationMiddleware implements MiddlewareInterface
{
    /**
     * Route option naming the scheme that guards a route; only `bearer` engages this middleware.
     *
     * @var    string
     * @since  2.0.0
     */
    public const OPTION_AUTHENTICATION = 'authentication';

    /**
     * Route option listing the capability strings a token must hold before the handler is reached.
     *
     * @var    string
     * @since  2.0.0
     */
    public const OPTION_REQUIRED_CAPABILITIES = 'required_capabilities';

    /**
     * Route option overriding the audience a token must have been issued for.
     *
     * @var    string
     * @since  2.0.0
     */
    public const OPTION_TOKEN_AUDIENCE = 'token_audience';

    /**
     * Route option overriding the purpose a token must have been issued for.
     *
     * @var    string
     * @since  2.0.0
     */
    public const OPTION_TOKEN_PURPOSE = 'token_purpose';

    /**
     * Header naming the site a token is presented against, so one site's token cannot serve another.
     *
     * @var    string
     * @since  2.0.0
     */
    public const SITE_HEADER = 'Kumwe-Site';

    /**
     * The single `OPTION_AUTHENTICATION` value this middleware recognises as its own.
     *
     * @var    string
     * @since  2.0.0
     */
    private const AUTHENTICATION_BEARER = 'bearer';

    /**
     * Wire the token verifier and fix the realm advertised in every challenge.
     *
     * @param   AccessTokenVerifier  $verifier  Verifier that resolves a raw token into a principal.
     * @param   string               $realm     Realm echoed in `WWW-Authenticate`, constrained to a safe identifier.
     *
     * @throws  InvalidArgumentException  When the realm is empty, longer than 64 bytes, or has unsafe characters.
     *
     * @since   2.0.0
     */
    public function __construct(
        private AccessTokenVerifier $verifier,
        private string $realm = 'kumwe-api',
    ) {
        if (preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $realm) !== 1) {
            throw new InvalidArgumentException('A bearer authentication realm must be a safe identifier.');
        }
    }

    /**
     * Authenticate and authorize the request when the matched route opts in to bearer tokens.
     *
     * A request whose route does not opt in is delegated unchanged, which is why this middleware has
     * to sit after routing in the pipeline. A protected request must carry exactly one `Authorization`
     * header and exactly one `Kumwe-Site` header: a repeated header is refused rather than resolved to
     * one of its values, so an ambiguous credential can never be silently interpreted in the client's
     * favour.
     *
     * @param   ServerRequestInterface   $request  Request whose route options decide whether to authenticate.
     * @param   RequestHandlerInterface  $handler  Next handler, reached only once the token satisfies the route.
     *
     * @return  ResponseInterface  The handler's response, or a problem document with status 401 or 403.
     *
     * @throws  LogicException  When the matched route configures audience, purpose or capabilities badly.
     *
     * @since   2.0.0
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $options = $this->routeOptions($request);

        if (($options[self::OPTION_AUTHENTICATION] ?? null) !== self::AUTHENTICATION_BEARER) {
            return $handler->handle($request);
        }

        $authorizationHeaders = $request->getHeader('Authorization');

        if ($authorizationHeaders === []) {
            return $this->unauthorized(null);
        }

        if (count($authorizationHeaders) !== 1) {
            return $this->unauthorized('invalid_request');
        }

        $token = $this->parseToken($authorizationHeaders[0]);

        if ($token === null) {
            return $this->unauthorized('invalid_request');
        }

        $siteIdentifier = $this->siteIdentifier($request);
        if ($siteIdentifier === null) {
            return $this->unauthorized('invalid_request');
        }

        $audience = $this->option($options, self::OPTION_TOKEN_AUDIENCE, 'kumwe-http');
        $purpose = $this->option($options, self::OPTION_TOKEN_PURPOSE, 'api');
        $verified = $this->verifier instanceof ScopedAccessTokenVerifier
            ? $this->verifier->verifyScoped($token, $audience, $purpose, $siteIdentifier)
            : null;
        $principal = $verified !== null
            ? $verified->principal
            : (!($this->verifier instanceof ScopedAccessTokenVerifier)
                ? $this->verifier->verify($token, $audience, $purpose, $siteIdentifier)
                : null);

        if ($principal === null) {
            return $this->unauthorized('invalid_token');
        }
        $context = $verified !== null
            ? $verified->context($this->requestId($request), AuthenticatedSurface::Api)
            : $principal->context(
                SiteContext::fromString($siteIdentifier),
                AuthenticationStrength::BearerToken,
                $this->requestId($request),
                surface: AuthenticatedSurface::Api,
            );

        $required = $this->requiredCapabilities($options);

        foreach ($required as $capability) {
            if (!$principal->hasCapability($capability)) {
                return $this->forbidden($required);
            }
        }

        return $handler->handle(
            $request
                ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
                ->withAttribute(
                    ExecutionContextAttribute::NAME,
                    $context,
                ),
        );
    }

    /**
     * Reduce the `Kumwe-Site` header to the canonical identifier the token is checked against.
     *
     * @param   ServerRequestInterface  $request  Request expected to carry exactly one site header.
     *
     * @return  ?string  The canonical site identifier, or null when the header is absent, repeated or malformed.
     *
     * @since   2.0.0
     */
    private function siteIdentifier(ServerRequestInterface $request): ?string
    {
        $values = $request->getHeader(self::SITE_HEADER);
        if (count($values) !== 1) {
            return null;
        }

        try {
            return SiteContext::fromString($values[0])->identifier();
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Read a string route option, falling back to the pipeline default when the route omits it.
     *
     * @param   array<string, mixed>  $options  Options of the matched route.
     * @param   string                $name     Option key to read.
     * @param   string                $default  Value used when the route leaves the option unset.
     *
     * @return  string  The configured value or the default, never an empty string.
     *
     * @throws  LogicException  When the route sets the option to anything but a non-empty string.
     *
     * @since   2.0.0
     */
    private function option(array $options, string $name, string $default): string
    {
        $value = $options[$name] ?? $default;
        if (!is_string($value) || $value === '') {
            throw new LogicException(sprintf('Bearer route option %s must be a non-empty string.', $name));
        }
        return $value;
    }

    /**
     * Extract the options declared on the route that matched this request.
     *
     * @param   ServerRequestInterface  $request  Request expected to carry the `RouteResult` attribute.
     *
     * @return  array<string, mixed>  The matched route's options; empty when routing did not succeed.
     *
     * @since   2.0.0
     */
    private function routeOptions(ServerRequestInterface $request): array
    {
        $routeResult = $request->getAttribute(RouteResult::class);

        if (!$routeResult instanceof RouteResult || !$routeResult->isSuccess()) {
            return [];
        }

        $route = $routeResult->getMatchedRoute();

        if (!$route instanceof Route) {
            return [];
        }

        /** @var array<string, mixed> $options */
        $options = $route->getOptions();

        return $options;
    }

    /**
     * Extract the credential from an `Authorization` header value.
     *
     * @param   string  $header  Raw header value, expected in `Bearer <token>` form.
     *
     * @return  ?string  The token when it is well formed and 32 to 512 bytes long, null otherwise.
     *
     * @since   2.0.0
     */
    private function parseToken(string $header): ?string
    {
        if (preg_match('/^Bearer ([A-Za-z0-9._~+\/-]+=*)$/iD', $header, $matches) !== 1) {
            return null;
        }

        $token = $matches[1];
        $length = strlen($token);

        return $length >= 32 && $length <= 512 ? $token : null;
    }

    /**
     * Resolve the capability strings a route declares into domain capability values.
     *
     * Results are deduplicated and sorted by capability string, so the `scope` advertised on a 403
     * challenge stays stable no matter what order the route listed them in.
     *
     * @param   array<string, mixed>  $options  Options of the matched route.
     *
     * @return  list<Capability>  Required capabilities, sorted; empty when the route declares none.
     *
     * @throws  LogicException  When the configured capabilities are not a list of unique, valid strings.
     *
     * @since   2.0.0
     */
    private function requiredCapabilities(array $options): array
    {
        $configured = $options[self::OPTION_REQUIRED_CAPABILITIES] ?? [];

        if (!is_array($configured) || !array_is_list($configured)) {
            throw new LogicException('Required bearer capabilities must be configured as a list.');
        }

        $required = [];

        foreach ($configured as $capability) {
            if (!is_string($capability)) {
                throw new LogicException('Required bearer capabilities must contain strings.');
            }

            try {
                $value = Capability::fromString($capability);
            } catch (InvalidArgumentException $exception) {
                throw new LogicException('A configured bearer capability is invalid.', previous: $exception);
            }

            if (isset($required[$value->value()])) {
                throw new LogicException('Required bearer capabilities must be unique.');
            }

            $required[$value->value()] = $value;
        }

        ksort($required, SORT_STRING);

        return array_values($required);
    }

    /**
     * Build the 401 problem document together with the challenge the client should answer.
     *
     * @param   ?string  $error  RFC 6750 error code to advertise, or null when no credential was offered.
     *
     * @return  ResponseInterface  A 401 `application/problem+json` response carrying `WWW-Authenticate`.
     *
     * @since   2.0.0
     */
    private function unauthorized(?string $error): ResponseInterface
    {
        $challenge = sprintf('Bearer realm="%s"', $this->realm);

        if ($error !== null) {
            $challenge .= sprintf(', error="%s"', $error);
        }

        return new JsonResponse([
            'type' => 'about:blank',
            'title' => 'Unauthorized',
            'status' => 401,
            'detail' => 'A valid bearer access token is required.',
        ], 401, [
            'Content-Type' => 'application/problem+json',
            'WWW-Authenticate' => $challenge,
            'Cache-Control' => 'no-store',
        ]);
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

    /**
     * Build the 403 problem document, naming the capabilities the route demands as the challenge scope.
     *
     * @param   list<Capability>  $required  Capabilities the route requires, advertised space separated.
     *
     * @return  ResponseInterface  A 403 `application/problem+json` response carrying `WWW-Authenticate`.
     *
     * @since   2.0.0
     */
    private function forbidden(array $required): ResponseInterface
    {
        $scope = implode(' ', array_map(
            static fn (Capability $capability): string => $capability->value(),
            $required,
        ));
        $challenge = sprintf(
            'Bearer realm="%s", error="insufficient_scope", scope="%s"',
            $this->realm,
            $scope,
        );

        return new JsonResponse([
            'type' => 'about:blank',
            'title' => 'Forbidden',
            'status' => 403,
            'detail' => 'The access token does not grant every required capability.',
        ], 403, [
            'Content-Type' => 'application/problem+json',
            'WWW-Authenticate' => $challenge,
            'Cache-Control' => 'no-store',
        ]);
    }
}
