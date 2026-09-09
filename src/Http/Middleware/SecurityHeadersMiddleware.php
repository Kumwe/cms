<?php

declare(strict_types=1);

namespace Kumwe\App\Http\Middleware;

use Kumwe\App\Http\Security\SecurityHeaders;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Stamps the site-wide security headers onto every response leaving the pipeline.
 *
 * Applying the policy at the boundary rather than in each handler means a newly added route cannot
 * ship without it. `SecurityHeaders` owns the policy itself; this middleware decides only the two
 * things that depend on the live request — what the transport allows, and whether the response is an
 * SVG. HSTS is withheld outside production and off plain HTTP so a development host is never pinned to
 * TLS. `upgrade-insecure-requests` is withheld off plain HTTP alone, because a site served without TLS
 * cannot honour it: the browser would be told to fetch every subresource, and to submit every form,
 * over a scheme nothing is listening on. An `image/svg+xml` response is additionally sandboxed because
 * an SVG is an active document the browser would otherwise run with the site's own origin. A page that
 * mounts the pinned Studio browser module from the configured CDN asks for that one origin through
 * `SCRIPT_ORIGIN_HEADER`; the request is honoured only when it names the configured origin exactly.
 *
 * @since  2.0.0
 */
final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /**
     * Response header a handler sets to ask for exactly one additional script origin on its own response.
     *
     * The middleware honours it only when the value equals the origin the deployment configured for the
     * pinned Studio browser assets, and always removes it before the response leaves the pipeline, so
     * neither a handler nor an upstream body can widen the policy to an arbitrary origin.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string SCRIPT_ORIGIN_HEADER = 'X-Kumwe-Studio-Script-Origin';

    /**
     * Record whether this build is allowed to assert HSTS and which script origin a page may request.
     *
     * @param  bool     $production          Whether the application runs in production, where pinning TLS
     *         is safe.
     * @param  ?string  $studioScriptOrigin  Exact origin the pinned Studio browser assets are served from,
     *         or null when they are same-origin and no response may widen `script-src`.
     *
     * @since  2.0.0
     */
    public function __construct(private bool $production, private ?string $studioScriptOrigin = null)
    {
    }

    /**
     * Delegate to the pipeline, then apply the security headers to whatever it returned.
     *
     * Headers are set rather than appended, so a handler cannot weaken the policy by emitting its own
     * value first. The SVG lock-down runs last and deliberately replaces the site policy with
     * `default-src 'none'` plus `sandbox`, which is stricter than the policy it overwrites.
     *
     * @param   ServerRequestInterface   $request  Request whose scheme decides what the transport allows.
     * @param   RequestHandlerInterface  $handler  Rest of the pipeline, which produces the response.
     *
     * @return  ResponseInterface  The handler's response with the security headers applied.
     *
     * @since   2.0.0
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $secure = $request->getUri()->getScheme() === 'https';

        $policy = new SecurityHeaders($this->production && $secure, $secure);
        $requestedOrigin = $response->getHeaderLine(self::SCRIPT_ORIGIN_HEADER);
        $response = $response->withoutHeader(self::SCRIPT_ORIGIN_HEADER);
        $scriptOrigins = $requestedOrigin !== ''
            && $this->studioScriptOrigin !== null
            && hash_equals($this->studioScriptOrigin, $requestedOrigin)
            ? [$this->studioScriptOrigin]
            : [];
        $headers = $request->getUri()->getPath() === '/administrator/studio/preview'
            ? $policy->previewValues()
            : $policy->values(null, $scriptOrigins);
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        if (str_starts_with(strtolower($response->getHeaderLine('Content-Type')), 'image/svg+xml')) {
            $response = $response
                ->withHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox")
                ->withHeader('Cross-Origin-Resource-Policy', 'same-origin');
        }

        return $response;
    }
}
