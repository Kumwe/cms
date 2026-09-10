<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Http\Middleware;

use InvalidArgumentException;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Http\Middleware\BearerAuthenticationMiddleware;
use Kumwe\App\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\ServerRequestFactory;
use LogicException;
use Mezzio\Router\Route;
use Mezzio\Router\RouteResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;

#[CoversClass(BearerAuthenticationMiddleware::class)]
#[UsesClass(AuthenticatedPrincipal::class)]
final class BearerAuthenticationMiddlewareTest extends TestCase
{
    private const TOKEN = 'abcdefghijklmnopqrstuvwxyz0123456789ABCD';
    private const SUBJECT = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';

    public function testBypassesRouteWithoutBearerOption(): void
    {
        $verifier = $this->createMock(AccessTokenVerifier::class);
        $verifier->expects(self::never())->method('verify');

        $response = (new BearerAuthenticationMiddleware($verifier))->process(
            $this->request([]),
            $this->successfulHandler(),
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testMissingTokenReturnsBearerChallengeWithoutErrorCode(): void
    {
        $verifier = $this->createStub(AccessTokenVerifier::class);
        $response = (new BearerAuthenticationMiddleware($verifier))->process(
            $this->protectedRequest(),
            $this->neverHandler(),
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer realm="kumwe-api"', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testMalformedTokenReturnsInvalidRequestWithoutEchoingToken(): void
    {
        $unsafe = 'token containing spaces and secret material';
        $verifier = $this->createMock(AccessTokenVerifier::class);
        $verifier->expects(self::never())->method('verify');
        $response = (new BearerAuthenticationMiddleware($verifier))->process(
            $this->protectedRequest()->withHeader('Authorization', 'Bearer ' . $unsafe),
            $this->neverHandler(),
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('error="invalid_request"', $response->getHeaderLine('WWW-Authenticate'));
        self::assertStringNotContainsString($unsafe, (string) $response->getBody());
    }

    public function testMultipleAuthorizationHeadersAreRejectedBeforeVerification(): void
    {
        $verifier = $this->createMock(AccessTokenVerifier::class);
        $verifier->expects(self::never())->method('verify');
        $response = (new BearerAuthenticationMiddleware($verifier))->process(
            $this->protectedRequest()->withHeader('Authorization', [
                'Bearer ' . self::TOKEN,
                'Bearer ' . str_repeat('x', 40),
            ]),
            $this->neverHandler(),
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('error="invalid_request"', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testUnknownTokenReturnsInvalidTokenWithoutEchoingToken(): void
    {
        $verifier = $this->createMock(AccessTokenVerifier::class);
        $verifier->expects(self::once())->method('verify')->with(
            self::TOKEN,
            'kumwe-http',
            'api',
            'default',
        )->willReturn(null);
        $response = (new BearerAuthenticationMiddleware($verifier))->process(
            $this->protectedRequest()->withHeader('Authorization', 'Bearer ' . self::TOKEN),
            $this->neverHandler(),
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('error="invalid_token"', $response->getHeaderLine('WWW-Authenticate'));
        self::assertStringNotContainsString(self::TOKEN, (string) $response->getBody());
    }

    public function testAttachesPrincipalWhenEveryExactCapabilityIsPresent(): void
    {
        $principal = AuthorizationContext::principal(['content.read', 'content.update'], self::SUBJECT);
        $verifier = $this->createMock(AccessTokenVerifier::class);
        $verifier->expects(self::once())
            ->method('verify')
            ->with(self::TOKEN, 'kumwe-http', 'api', 'default')
            ->willReturn($principal);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static function (ServerRequestInterface $request) use ($principal): bool {
                $context = $request->getAttribute(ExecutionContextAttribute::NAME);

                return $request->getAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE) === $principal
                    && $context instanceof ExecutionContext
                    && $context->principal() === $principal
                    && $context->authenticationStrength() === AuthenticationStrength::BearerToken;
            },
        ))->willReturn(new TextResponse('', 204));
        $response = (new BearerAuthenticationMiddleware($verifier))->process(
            $this->protectedRequest(['content.update', 'content.read'])
                ->withHeader('Authorization', 'Bearer ' . self::TOKEN),
            $handler,
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testMissingExactCapabilityReturnsInsufficientScope(): void
    {
        $principal = AuthorizationContext::principal(['content.read'], self::SUBJECT);
        $verifier = $this->createStub(AccessTokenVerifier::class);
        $verifier->method('verify')->willReturn($principal);
        $response = (new BearerAuthenticationMiddleware($verifier))->process(
            $this->protectedRequest(['content.update', 'content.read'])
                ->withHeader('Authorization', 'Bearer ' . self::TOKEN),
            $this->neverHandler(),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(
            'Bearer realm="kumwe-api", error="insufficient_scope", scope="content.read content.update"',
            $response->getHeaderLine('WWW-Authenticate'),
        );
    }

    public function testWildcardRouteCapabilityIsRejectedAsConfigurationError(): void
    {
        $principal = AuthorizationContext::principal(['content.read'], self::SUBJECT);
        $verifier = $this->createStub(AccessTokenVerifier::class);
        $verifier->method('verify')->willReturn($principal);

        $this->expectException(LogicException::class);

        (new BearerAuthenticationMiddleware($verifier))->process(
            $this->protectedRequest(['content.*'])
                ->withHeader('Authorization', 'Bearer ' . self::TOKEN),
            $this->neverHandler(),
        );
    }

    public function testRejectsUnsafeRealmAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BearerAuthenticationMiddleware(
            $this->createStub(AccessTokenVerifier::class),
            "kumwe\"\r\nX-Evil: injected",
        );
    }

    public function testRequiresOneExplicitSiteHeaderForAuthenticatedRequests(): void
    {
        $verifier = $this->createMock(AccessTokenVerifier::class);
        $verifier->expects(self::never())->method('verify');
        $request = $this->protectedRequest()
            ->withoutHeader(BearerAuthenticationMiddleware::SITE_HEADER)
            ->withHeader('Authorization', 'Bearer ' . self::TOKEN)
            ->withHeader('Host', 'corporate.example.test')
            ->withHeader('X-Forwarded-Host', 'default.example.test');

        $response = (new BearerAuthenticationMiddleware($verifier))->process($request, $this->neverHandler());

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('error="invalid_request"', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testBindsAValidatedNonDefaultSiteToVerificationAndExecutionContext(): void
    {
        $principal = AuthorizationContext::principal(['content.read'], self::SUBJECT);
        $verifier = $this->createMock(AccessTokenVerifier::class);
        $verifier->expects(self::once())->method('verify')->with(
            self::TOKEN,
            'kumwe-mcp',
            'mcp',
            'corporate',
        )->willReturn($principal);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static function (ServerRequestInterface $request): bool {
                $context = $request->getAttribute(ExecutionContextAttribute::NAME);

                return $context instanceof ExecutionContext
                    && $context->site()->identifier() === 'corporate';
            },
        ))->willReturn(new TextResponse('', 204));
        $request = $this->request([
            BearerAuthenticationMiddleware::OPTION_AUTHENTICATION => 'bearer',
            BearerAuthenticationMiddleware::OPTION_REQUIRED_CAPABILITIES => ['content.read'],
            BearerAuthenticationMiddleware::OPTION_TOKEN_AUDIENCE => 'kumwe-mcp',
            BearerAuthenticationMiddleware::OPTION_TOKEN_PURPOSE => 'mcp',
        ])->withHeader('Authorization', 'Bearer ' . self::TOKEN)
            ->withHeader(BearerAuthenticationMiddleware::SITE_HEADER, 'Corporate');

        self::assertSame(204, (new BearerAuthenticationMiddleware($verifier))->process(
            $request,
            $handler,
        )->getStatusCode());
    }

    public function testRejectsAmbiguousOrInvalidSiteHeadersBeforeTokenVerification(): void
    {
        foreach ([['corporate', 'storefront'], ['corporate,storefront'], ['../corporate']] as $values) {
            $verifier = $this->createMock(AccessTokenVerifier::class);
            $verifier->expects(self::never())->method('verify');
            $request = $this->protectedRequest()
                ->withHeader('Authorization', 'Bearer ' . self::TOKEN)
                ->withHeader(BearerAuthenticationMiddleware::SITE_HEADER, $values);

            self::assertSame(401, (new BearerAuthenticationMiddleware($verifier))->process(
                $request,
                $this->neverHandler(),
            )->getStatusCode());
        }
    }

    /** @param list<string> $requiredCapabilities */
    private function protectedRequest(array $requiredCapabilities = []): ServerRequestInterface
    {
        return $this->request([
            BearerAuthenticationMiddleware::OPTION_AUTHENTICATION => 'bearer',
            BearerAuthenticationMiddleware::OPTION_REQUIRED_CAPABILITIES => $requiredCapabilities,
        ])->withHeader(BearerAuthenticationMiddleware::SITE_HEADER, 'default');
    }

    /** @param array<string, mixed> $options */
    private function request(array $options): ServerRequestInterface
    {
        $route = new Route('/protected', $this->createStub(MiddlewareInterface::class), ['GET'], 'protected');
        $route->setOptions($options);

        return (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/protected')
            ->withAttribute(RouteResult::class, RouteResult::fromRoute($route));
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
