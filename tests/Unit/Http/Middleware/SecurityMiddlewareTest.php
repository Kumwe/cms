<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Http\Middleware;

use InvalidArgumentException;
use Kumwe\App\Application\Security\HighImpactAuthenticationRequired;
use Kumwe\App\Http\Middleware\BodyLimitMiddleware;
use Kumwe\App\Http\Middleware\ProblemDetailsMiddleware;
use Kumwe\App\Http\Middleware\RequestIdMiddleware;
use Kumwe\App\Http\Middleware\SecurityHeadersMiddleware;
use Kumwe\App\Http\Middleware\TrustedHostMiddleware;
use Kumwe\App\Http\Security\TrustedHostMatcher;
use Kumwe\App\Identity\Application\Administration\AuthenticationThrottled;
use Kumwe\App\Infrastructure\Observability\CorrelationContext;
use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

#[CoversClass(BodyLimitMiddleware::class)]
#[CoversClass(ProblemDetailsMiddleware::class)]
#[CoversClass(RequestIdMiddleware::class)]
#[CoversClass(SecurityHeadersMiddleware::class)]
#[CoversClass(TrustedHostMiddleware::class)]
final class SecurityMiddlewareTest extends TestCase
{
    public function testTrustedHostPassesToHandler(): void
    {
        $middleware = new TrustedHostMiddleware(new TrustedHostMatcher(['kumwe.test']));
        $response = $middleware->process(
            $this->request()->withHeader('Host', 'kumwe.test'),
            $this->successfulHandler(),
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testUntrustedHostIsRejected(): void
    {
        $middleware = new TrustedHostMiddleware(new TrustedHostMatcher(['kumwe.test']));
        $response = $middleware->process(
            $this->request()->withHeader('Host', 'attacker.test'),
            $this->successfulHandler(),
        );

        self::assertSame(400, $response->getStatusCode());
    }

    /**
     * Proves trusted-host validation cannot mask downstream validation failures.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTrustedHostDoesNotMaskDownstreamValidationFailures(): void
    {
        $handler = new class implements RequestHandlerInterface {
            /**
             * Simulate a downstream application validation failure.
             *
             * @param   ServerRequestInterface  $request  Trusted request passed by the middleware.
             *
             * @return  ResponseInterface  This handler always throws before returning a response.
             *
             * @since   2.0.0
             */
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new InvalidArgumentException('downstream validation failed');
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('downstream validation failed');

        (new TrustedHostMiddleware(new TrustedHostMatcher(['kumwe.test'])))->process(
            $this->request()->withHeader('Host', 'kumwe.test'),
            $handler,
        );
    }

    public function testOversizedBodyIsRejected(): void
    {
        $response = (new BodyLimitMiddleware(10))->process(
            $this->request()->withHeader('Content-Length', '11'),
            $this->successfulHandler(),
        );

        self::assertSame(413, $response->getStatusCode());
    }

    /**
     * Proves streamed bodies are bounded without relying on Content-Length and accepted bytes are preserved.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOversizedUndeclaredBodyIsRejectedAndBoundedBodyIsPreserved(): void
    {
        $streams = new StreamFactory();
        $middleware = new BodyLimitMiddleware(10);
        $oversized = $middleware->process(
            $this->request()->withBody($streams->createStream('12345678901')),
            $this->successfulHandler(),
        );

        self::assertSame(413, $oversized->getStatusCode());

        $echo = new class implements RequestHandlerInterface {
            /**
             * Echo the bounded request bytes after middleware inspection.
             *
             * @param   ServerRequestInterface  $request  Request whose body passed the limit.
             *
             * @return  ResponseInterface  Text response carrying the unchanged body.
             *
             * @since   2.0.0
             */
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new TextResponse((string) $request->getBody());
            }
        };
        $bounded = $middleware->process(
            $this->request()->withBody($streams->createStream('1234567890')),
            $echo,
        );

        self::assertSame('1234567890', (string) $bounded->getBody());
    }

    public function testRequestIdIsGeneratedAndReturned(): void
    {
        $response = (new RequestIdMiddleware(new CorrelationContext()))
            ->process($this->request(), $this->successfulHandler());

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $response->getHeaderLine('X-Request-ID'));
    }

    public function testProblemResponseDoesNotDiscloseProductionException(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('database-password-was-here');
            }
        };
        $response = (new ProblemDetailsMiddleware(new NullLogger(), false))->process($this->request(), $handler);

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('database-password-was-here', (string) $response->getBody());
    }

    public function testHighImpactAuthenticationFailureIsAProtectedStepUpProblem(): void
    {
        $response = (new ProblemDetailsMiddleware(new NullLogger(), false))->process(
            $this->request(),
            $this->failingHandler(new HighImpactAuthenticationRequired('credential-was-here')),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('high-impact-authentication-required', (string) $response->getBody());
        self::assertStringNotContainsString('credential-was-here', (string) $response->getBody());
    }

    public function testAuthenticationThrottleIsAProtectedRateLimitProblem(): void
    {
        $response = (new ProblemDetailsMiddleware(new NullLogger(), false))->process(
            $this->request(),
            $this->failingHandler(new AuthenticationThrottled()),
        );

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('authentication-throttled', (string) $response->getBody());
    }

    public function testSecurityHeadersAreApplied(): void
    {
        $response = (new SecurityHeadersMiddleware(true))->process($this->request(), $this->successfulHandler());

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertNotSame('', $response->getHeaderLine('Strict-Transport-Security'));
    }

    /**
     * Only the exact authenticated preview document route receives same-origin framing headers.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testPreviewDocumentRouteReceivesTheDedicatedFramePolicy(): void
    {
        $middleware = new SecurityHeadersMiddleware(true);
        $preview = $middleware->process(
            (new ServerRequestFactory())->createServerRequest(
                'GET',
                'https://kumwe.test/administrator/studio/preview',
            ),
            $this->successfulHandler(),
        );
        $ordinary = $middleware->process(
            (new ServerRequestFactory())->createServerRequest(
                'GET',
                'https://kumwe.test/administrator/studio/preview-adjacent',
            ),
            $this->successfulHandler(),
        );

        self::assertSame('SAMEORIGIN', $preview->getHeaderLine('X-Frame-Options'));
        self::assertStringContainsString("frame-src 'self'", $preview->getHeaderLine('Content-Security-Policy'));
        self::assertSame('DENY', $ordinary->getHeaderLine('X-Frame-Options'));
        self::assertStringNotContainsString(
            'frame-src',
            $ordinary->getHeaderLine('Content-Security-Policy'),
        );
    }

    public function testSvgMediaReceivesAnIsolatedContentSecurityPolicy(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new TextResponse('<svg/>', 200, ['Content-Type' => 'image/svg+xml']);
            }
        };

        $response = (new SecurityHeadersMiddleware(true))->process($this->request(), $handler);

        self::assertSame(
            "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            $response->getHeaderLine('Content-Security-Policy'),
        );
        self::assertSame('same-origin', $response->getHeaderLine('Cross-Origin-Resource-Policy'));
    }

    private function request(): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', 'https://kumwe.test/');
    }

    private function successfulHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new TextResponse('', 204);
            }
        };
    }

    private function failingHandler(\Throwable $exception): RequestHandlerInterface
    {
        return new class ($exception) implements RequestHandlerInterface {
            public function __construct(private \Throwable $exception)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw $this->exception;
            }
        };
    }

    /**
     * The transport the request arrived on decides whether subresources are told to upgrade.
     *
     * This is the end of the path the defect travelled: the policy builder is gated correctly only if
     * the middleware hands it the live scheme. Pinning both directions here means a future refactor that
     * drops the argument, or passes the production flag by mistake, fails rather than silently returning
     * an HTTP-only deployment to a policy Safari cannot honour.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSubresourceUpgradeFollowsTheRequestTransport(): void
    {
        $middleware = new SecurityHeadersMiddleware(false);
        $handler = new class implements RequestHandlerInterface {
            /**
             * Answer with a bare response for the middleware to stamp.
             *
             * @param   ServerRequestInterface  $request  Request the middleware passed through.
             *
             * @return  ResponseInterface  An empty text response.
             *
             * @since   2.0.0
             */
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new TextResponse('');
            }
        };
        $factory = new ServerRequestFactory();

        $overHttp = $middleware->process(
            $factory->createServerRequest('GET', 'http://kumwe.test/administrator/login'),
            $handler,
        );
        $overHttps = $middleware->process(
            $factory->createServerRequest('GET', 'https://kumwe.test/administrator/login'),
            $handler,
        );

        self::assertStringNotContainsString(
            'upgrade-insecure-requests',
            $overHttp->getHeaderLine('Content-Security-Policy'),
        );
        self::assertStringContainsString(
            'upgrade-insecure-requests',
            $overHttps->getHeaderLine('Content-Security-Policy'),
        );
    }

    /**
     * A response asking for exactly the configured Studio origin has `script-src` widened by that origin only.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheConfiguredStudioScriptOriginIsAdmittedAndTheMarkerIsStripped(): void
    {
        $middleware = new SecurityHeadersMiddleware(false, 'https://cdn.jsdelivr.net');

        $response = $middleware->process($this->request(), $this->handlerRequesting('https://cdn.jsdelivr.net'));

        self::assertStringContainsString(
            "script-src 'self' https://cdn.jsdelivr.net;",
            $response->getHeaderLine('Content-Security-Policy'),
        );
        self::assertFalse($response->hasHeader(SecurityHeadersMiddleware::SCRIPT_ORIGIN_HEADER));
    }

    /**
     * A marker naming any other origin, or one sent while no origin is configured, changes nothing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAForeignOrUnconfiguredScriptOriginRequestIsIgnored(): void
    {
        $foreign = (new SecurityHeadersMiddleware(false, 'https://cdn.jsdelivr.net'))
            ->process($this->request(), $this->handlerRequesting('https://evil.example'));
        $unconfigured = (new SecurityHeadersMiddleware(false))
            ->process($this->request(), $this->handlerRequesting('https://cdn.jsdelivr.net'));

        foreach ([$foreign, $unconfigured] as $response) {
            self::assertStringContainsString("script-src 'self';", $response->getHeaderLine('Content-Security-Policy'));
            self::assertStringNotContainsString('https://', $response->getHeaderLine('Content-Security-Policy'));
            self::assertFalse($response->hasHeader(SecurityHeadersMiddleware::SCRIPT_ORIGIN_HEADER));
        }
    }

    /**
     * A handler whose response carries the script-origin marker header.
     *
     * @param   string  $origin  Origin the handler asks for.
     *
     * @return  RequestHandlerInterface  Handler returning a marked empty response.
     *
     * @since   2.0.0
     */
    private function handlerRequesting(string $origin): RequestHandlerInterface
    {
        return new class ($origin) implements RequestHandlerInterface {
            /**
             * Hold the requested origin.
             *
             * @param  string  $origin  Origin to place in the marker header.
             *
             * @since  2.0.0
             */
            public function __construct(private readonly string $origin)
            {
            }

            /**
             * Answer with the marker header set.
             *
             * @param   ServerRequestInterface  $request  Ignored request.
             *
             * @return  ResponseInterface  Marked response.
             *
             * @since   2.0.0
             */
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new TextResponse('', 204))
                    ->withHeader(SecurityHeadersMiddleware::SCRIPT_ORIGIN_HEADER, $this->origin);
            }
        };
    }
}
