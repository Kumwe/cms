<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Http\Api\Extension;

use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Delivery\Http\Api\Extension\ExtensionApiHandler;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\Extension\Application\ExtensionManager;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Identity\Application\Administration\AuthenticationThrottled;
use Kumwe\App\Extension\Domain\ThemeSurface;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;

#[CoversClass(ExtensionApiHandler::class)]
final class ExtensionApiHandlerTest extends TestCase
{
    private const ACTOR = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';

    public function testRestActivationPassesAuthorizedSiteSurface(): void
    {
        $extensions = $this->createMock(ExtensionManager::class);
        $extensions->expects(self::once())->method('activate')->with(
            'acme/corporate',
            self::isInstanceOf(ExecutionContext::class),
            ThemeSurface::Site,
            null,
        )->willReturn(['identifier' => 'acme/corporate', 'status' => 'active']);
        $handler = new ExtensionApiHandler(
            $extensions,
            new ProblemDetailsResponseFactory(),
        );
        $response = $handler->handle($this->request(['extensions.manage', 'themes.site.manage']));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testRestAdministratorActivationForwardsCurrentPassword(): void
    {
        $extensions = $this->createMock(ExtensionManager::class);
        $extensions->expects(self::once())->method('activate')->with(
            'acme/corporate',
            self::isInstanceOf(ExecutionContext::class),
            ThemeSurface::Administrator,
            'correct horse battery staple',
        )->willReturn(['identifier' => 'acme/corporate', 'status' => 'active']);
        $handler = new ExtensionApiHandler(
            $extensions,
            new ProblemDetailsResponseFactory(),
        );
        $response = $handler->handle($this->request(
            ['extensions.manage', 'themes.administrator.manage'],
            'administrator',
            'correct horse battery staple',
        ));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testRestActivationRejectsMissingSurfaceCapability(): void
    {
        $extensions = $this->createMock(ExtensionManager::class);
        $extensions->expects(self::once())->method('activate')->willThrowException(
            $this->authorizationDenied('themes.site.manage'),
        );
        $handler = new ExtensionApiHandler(
            $extensions,
            new ProblemDetailsResponseFactory(),
        );
        $response = $handler->handle($this->request(['extensions.manage']));

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('themes.site.manage', (string) $response->getBody());
    }

    public function testRestMapsStepUpThrottlingToControlledProblem(): void
    {
        $extensions = $this->createStub(ExtensionManager::class);
        $extensions->method('activate')->willThrowException(new AuthenticationThrottled());
        $handler = new ExtensionApiHandler(
            $extensions,
            new ProblemDetailsResponseFactory(),
        );
        $response = $handler->handle($this->request(['extensions.manage', 'themes.site.manage']));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('900', $response->getHeaderLine('Retry-After'));
    }

    public function testRestDisableRejectsMissingActiveSiteThemeCapability(): void
    {
        $extensions = $this->createMock(ExtensionManager::class);
        $extensions->expects(self::once())->method('disable')->willThrowException(
            $this->authorizationDenied('themes.site.manage'),
        );
        $handler = new ExtensionApiHandler(
            $extensions,
            new ProblemDetailsResponseFactory(),
        );
        $response = $handler->handle($this->request(['extensions.manage'], action: 'disable'));

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('themes.site.manage', (string) $response->getBody());
    }

    public function testRestUninstallForwardsAdministratorStepUp(): void
    {
        $extensions = $this->createMock(ExtensionManager::class);
        $extensions->method('installed')->willReturn([[
            'identifier' => 'acme/corporate',
            'extension_type' => 'template',
            'theme_surfaces' => ['administrator'],
        ]]);
        $extensions->expects(self::once())->method('uninstall')->with(
            'acme/corporate',
            self::isInstanceOf(ExecutionContext::class),
            'correct horse battery staple',
        );
        $handler = new ExtensionApiHandler(
            $extensions,
            new ProblemDetailsResponseFactory(),
        );
        $response = $handler->handle($this->request(
            ['extensions.manage', 'themes.administrator.manage'],
            currentPassword: 'correct horse battery staple',
            action: 'uninstall',
            method: 'DELETE',
        ));

        self::assertSame(204, $response->getStatusCode());
    }

    /** @param list<string> $capabilities */
    private function request(
        array $capabilities,
        string $surface = 'site',
        ?string $currentPassword = null,
        string $action = 'activate',
        string $method = 'POST',
    ): \Psr\Http\Message\ServerRequestInterface {
        $body = $action === 'activate' ? ['surface' => $surface] : [];
        if ($currentPassword !== null) {
            $body['current_password'] = $currentPassword;
        }

        $principal = AuthorizationContext::principal($capabilities, self::ACTOR);
        $context = $principal->context(
            \Kumwe\Context\Value\SiteContext::default(),
            \Kumwe\Context\Value\AuthenticationStrength::BearerToken,
            'theme-api-test-request',
        );

        return (new ServerRequestFactory())
            ->createServerRequest($method, 'https://kumwe.test/api/v1/extensions/acme/corporate/' . $action)
            ->withAttribute('vendor', 'acme')
            ->withAttribute('name', 'corporate')
            ->withAttribute(
                AuthenticatedPrincipal::REQUEST_ATTRIBUTE,
                $principal,
            )
            ->withAttribute(ExecutionContextAttribute::NAME, $context)
            ->withBody((new StreamFactory())->createStream(json_encode(
                $body === [] ? (object) [] : $body,
                JSON_THROW_ON_ERROR,
            )));
    }

    private function authorizationDenied(string $capability): AuthorizationDenied
    {
        return new AuthorizationDenied(
            self::ACTOR,
            $capability,
            'theme',
            'acme/corporate',
            'default',
            'theme-mutation',
            'missing-capability',
        );
    }
}
