<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Localization\Http;

use Kumwe\App\Application\Authorization\ExecutionContextAttribute;
use Kumwe\App\Localization\Application\ActiveLocale;
use Kumwe\App\Localization\Application\SupportedLocales;
use Kumwe\App\Localization\Application\TranslationScope;
use Kumwe\App\Localization\Domain\LocaleTag;
use Kumwe\App\Localization\Http\Middleware\TranslationScopeMiddleware;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Pins the point where authenticated organization membership enters translation resolution.
 *
 * @since  2.0.0
 */
#[CoversClass(TranslationScopeMiddleware::class)]
final class TranslationScopeMiddlewareTest extends TestCase
{
    /**
     * The authenticated context enriches only the scope and cannot replace the negotiated locale.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAuthenticatedMembershipReachesTheActiveTranslationScope(): void
    {
        $active = new ActiveLocale(new SupportedLocales());
        $active->begin(LocaleTag::fromString('de'), new TranslationScope('public-site'));
        $seen = null;
        $context = AuthorizationContext::human(
            [],
            site: 'customer-site',
            membership: AuthorizationContext::membership('acme'),
        );

        (new TranslationScopeMiddleware($active))->process(
            (new ServerRequestFactory())->createServerRequest('GET', 'https://kumwe.test/')
                ->withAttribute(ExecutionContextAttribute::NAME, $context),
            $this->handler(static function () use ($active, &$seen): void {
                $seen = [
                    'locale' => $active->locale()->toString(),
                    'site' => $active->scope()->site,
                    'organization' => $active->scope()->organization,
                ];
            }),
        );

        self::assertSame([
            'locale' => 'de',
            'site' => 'customer-site',
            'organization' => 'acme',
        ], $seen);
    }

    /**
     * Build a downstream handler that observes the active scope.
     *
     * @param   callable(): void  $inspect  Observation to run when dispatch is reached.
     *
     * @return  RequestHandlerInterface  Handler returning an empty response after observing.
     *
     * @since   2.0.0
     */
    private function handler(callable $inspect): RequestHandlerInterface
    {
        return new class ($inspect) implements RequestHandlerInterface {
            /** @var callable(): void @since 2.0.0 */
            private $inspect;

            /**
             * @param  callable(): void  $inspect  Observation to run at dispatch.
             *
             * @since  2.0.0
             */
            public function __construct(callable $inspect)
            {
                $this->inspect = $inspect;
            }

            /**
             * Observe scope and return an empty response.
             *
             * @param   ServerRequestInterface  $request  Request passed through unchanged.
             *
             * @return  ResponseInterface  Empty response.
             *
             * @since   2.0.0
             */
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ($this->inspect)();

                return new Response();
            }
        };
    }
}
