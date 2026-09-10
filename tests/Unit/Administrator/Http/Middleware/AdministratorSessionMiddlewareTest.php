<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Administrator\Http\Middleware;

use DateTimeImmutable;
use Kumwe\App\Administrator\Http\Middleware\AdministratorSessionMiddleware;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;
use Kumwe\App\Identity\Application\Administration\AdministratorSession;
use Kumwe\App\Identity\Application\Administration\AdministratorSessionStore;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(AdministratorSessionMiddleware::class)]
final class AdministratorSessionMiddlewareTest extends TestCase
{
    private const SESSION = '018f22e2-7c8b-7ab0-8f3a-88e8026bb311';

    public function testExactSessionGrantCannotBeUsedForAnotherSession(): void
    {
        $principal = AuthorizationContext::principalFromGrantRows([[
            'capability' => 'administrator.access',
            'scope_type' => 'administrator_session',
            'scope_identifier' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb312',
        ]]);
        $sessions = $this->createStub(AdministratorSessionStore::class);
        $sessions->method('find')->willReturn(new AdministratorSession(
            self::SESSION,
            $principal,
            'csrf-token',
            new DateTimeImmutable('+1 hour'),
        ));
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/administrator')
            ->withCookieParams([AdministratorSessionMiddleware::COOKIE_NAME => 'opaque-session-token']);

        $response = (new AdministratorSessionMiddleware(
            $sessions,
            AuthorizationContext::gateway(),
        ))->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testMatchingSessionGrantAttachesTheSameAuthorizedContext(): void
    {
        $principal = AuthorizationContext::principalFromGrantRows([[
            'capability' => 'administrator.access',
            'scope_type' => 'administrator_session',
            'scope_identifier' => self::SESSION,
        ]]);
        $sessions = $this->createStub(AdministratorSessionStore::class);
        $sessions->method('find')->willReturn(new AdministratorSession(
            self::SESSION,
            $principal,
            'csrf-token',
            new DateTimeImmutable('+1 hour'),
        ));
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/administrator')
            ->withCookieParams([AdministratorSessionMiddleware::COOKIE_NAME => 'opaque-session-token']);

        $response = (new AdministratorSessionMiddleware(
            $sessions,
            AuthorizationContext::gateway(),
        ))->process($request, new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new TextResponse('', 204);
            }
        });

        self::assertSame(204, $response->getStatusCode());
    }

    public function testConfiguredPublicSiteIsAttachedToAdministratorRequests(): void
    {
        $principal = AuthorizationContext::principalFromGrantRows([[
            'capability' => 'administrator.access',
            'scope_type' => 'administrator_session',
            'scope_identifier' => self::SESSION,
        ]]);
        $sessions = $this->createStub(AdministratorSessionStore::class);
        $sessions->method('find')->willReturn(new AdministratorSession(
            self::SESSION,
            $principal,
            'csrf-token',
            new DateTimeImmutable('+1 hour'),
        ));
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/administrator')
            ->withCookieParams([AdministratorSessionMiddleware::COOKIE_NAME => 'opaque-session-token']);

        $response = (new AdministratorSessionMiddleware(
            $sessions,
            AuthorizationContext::gateway(ownership: AuthorizationContext::ownership('corporate')),
            SiteContext::fromString('corporate'),
        ))->process($request, new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $context = $request->getAttribute(ExecutionContextAttribute::NAME);

                return new TextResponse(
                    $context instanceof ExecutionContext ? $context->site()->identifier() : 'missing',
                );
            }
        });

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('corporate', (string) $response->getBody());
    }
}
