<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Http\Mcp;

use Kumwe\App\Delivery\Http\Mcp\McpHttpHandler;
use Kumwe\App\Infrastructure\Mcp\KumweMcpServerFactory;
use Kumwe\App\Infrastructure\Mcp\McpCapabilityCatalog;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\McpHandlersFixture;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;

#[CoversClass(McpHttpHandler::class)]
final class McpHttpHandlerTest extends TestCase
{
    public function testItRejectsANonPositiveBodyLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->handler(0);
    }

    public function testItRunsTheOfficialTransportForOptionsRequests(): void
    {
        $context = AuthorizationContext::human(['content.read']);
        $request = (new ServerRequest())
            ->withMethod('OPTIONS')
            ->withUri(new \Laminas\Diactoros\Uri('https://kumwe.test/mcp'))
            ->withAttribute(
                AuthenticatedPrincipal::REQUEST_ATTRIBUTE,
                $context->principal(),
            )
            ->withAttribute(ExecutionContextAttribute::NAME, $context);

        self::assertSame(204, $this->handler()->handle($request)->getStatusCode());
    }

    /**
     * A browser's preflight carries no credentials, so it is answered without a principal and without a 500.
     *
     * The `OPTIONS` route runs no bearer middleware; before this, the handler refused the request as
     * unauthenticated, which surfaced as an internal error on every cross-origin attempt. The answer
     * mirrors the transport's CORS defaults: the three MCP methods, the headers the transport reads,
     * and the session header it exposes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnauthenticatedPreflightIsAnsweredWithTheTransportAllowances(): void
    {
        $request = (new ServerRequest())
            ->withMethod('OPTIONS')
            ->withUri(new \Laminas\Diactoros\Uri('https://kumwe.test/mcp'))
            ->withHeader('Origin', 'https://elsewhere.test')
            ->withHeader('Access-Control-Request-Method', 'POST');

        $response = $this->handler()->handle($request);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('GET, POST, DELETE', $response->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertStringContainsString('Mcp-Session-Id', $response->getHeaderLine('Access-Control-Allow-Headers'));
        self::assertStringContainsString('Authorization', $response->getHeaderLine('Access-Control-Allow-Headers'));
        self::assertSame('Mcp-Session-Id', $response->getHeaderLine('Access-Control-Expose-Headers'));
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'), 'No origin is configured as allowed.');
    }

    public function testItRejectsMismatchedPrincipalAndExecutionContext(): void
    {
        $context = AuthorizationContext::human(['content.read']);
        $other = AuthorizationContext::human(
            ['content.read'],
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb302',
        );
        $request = (new ServerRequest())
            ->withMethod('POST')
            ->withUri(new \Laminas\Diactoros\Uri('https://kumwe.test/mcp'))
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $other->principal())
            ->withAttribute(ExecutionContextAttribute::NAME, $context);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('identities must match');

        $this->handler()->handle($request);
    }

    private function handler(int $maxBodyBytes = 1_048_576): McpHttpHandler
    {
        $catalog = new McpCapabilityCatalog();

        return new McpHttpHandler(
            new KumweMcpServerFactory($catalog),
            McpHandlersFixture::create($catalog),
            new ResponseFactory(),
            new StreamFactory(),
            new NullLogger(),
            $maxBodyBytes,
            ['kumwe.test'],
        );
    }
}
