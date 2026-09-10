<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Localization;

use DateTimeImmutable;
use Kumwe\App\Administrator\Http\Handler\AdministratorWordingHandler;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;
use Kumwe\App\Identity\Application\Administration\AdministratorSession;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\Context\Value\ExecutionContext;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Proves malformed hidden wording controls cannot widen a write by falling back to defaults.
 *
 * @since  2.0.0
 */
#[CoversClass(AdministratorWordingHandler::class)]
final class AdministratorWordingValidationIntegrationTest extends TestCase
{
    /**
     * Unsupported locale and layer values both re-render as refused submissions.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMalformedPostedScopeControlsAreRefusedInsteadOfDefaulted(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $handler = $container->get(AdministratorWordingHandler::class);
        self::assertInstanceOf(AdministratorWordingHandler::class, $handler);
        $context = TestKernelFactory::administratorContext($container);

        $locale = $handler->handle($this->request($context, [
            'action' => 'override.save',
            'locale' => 'not-a-carried-locale',
            'layer' => 'site',
            'identifier' => 'core.administrator.wording.layer_site',
            'pattern' => 'Must not be stored',
        ]));
        self::assertSame(422, $locale->getStatusCode());
        self::assertStringContainsString('does not carry that locale', (string) $locale->getBody());

        $layer = $handler->handle($this->request($context, [
            'action' => 'override.save',
            'locale' => 'en-GB',
            'layer' => 'core',
            'identifier' => 'core.administrator.wording.layer_site',
            'pattern' => 'Must not be stored',
        ]));
        self::assertSame(422, $layer->getStatusCode());
        self::assertStringContainsString('administered wording layer is required', (string) $layer->getBody());

        $pattern = $handler->handle($this->request($context, [
            'action' => 'override.save',
            'locale' => 'en-GB',
            'layer' => 'site',
            'identifier' => 'core.administrator.wording.layer_site',
            'pattern' => '{count, plural, one {One} other {Many}',
        ]));
        self::assertSame(422, $pattern->getStatusCode());
        self::assertStringContainsString('cannot be formatted for locale en-GB', (string) $pattern->getBody());
    }

    /**
     * Build an authenticated administrator POST around the supplied flat form.
     *
     * @param   ExecutionContext      $context  Real administrator context composed by the test kernel.
     * @param   array<string, string> $form     Wording form to submit directly to the handler.
     *
     * @return  ServerRequestInterface  Request carrying the trusted session and execution context.
     *
     * @since   2.0.0
     */
    private function request(ExecutionContext $context, array $form): ServerRequestInterface
    {
        $principal = $context->principal();
        self::assertInstanceOf(AuthenticatedPrincipal::class, $principal);

        return (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/administrator/wording?locale=en-GB&layer=site')
            ->withQueryParams(['locale' => 'en-GB', 'layer' => 'site'])
            ->withParsedBody($form)
            ->withAttribute(ExecutionContextAttribute::NAME, $context)
            ->withAttribute(AdministratorSession::REQUEST_ATTRIBUTE, new AdministratorSession(
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb399',
                $principal,
                'wording-validation-csrf',
                new DateTimeImmutable('+1 hour'),
            ));
    }
}
