<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Localization\Http;

use Kumwe\App\Localization\Application\ActiveLocale;
use Kumwe\App\Localization\Application\LocaleNegotiator;
use Kumwe\App\Localization\Application\SiteDefaultLocale;
use Kumwe\App\Localization\Application\SupportedLocales;
use Kumwe\App\Localization\Domain\LocaleTag;
use Kumwe\App\Localization\Http\Middleware\LocaleNegotiationMiddleware;
use Kumwe\App\Site\Application\SiteSettings;
use Kumwe\Context\Value\ExecutionContext;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

#[CoversClass(LocaleNegotiationMiddleware::class)]
final class LocaleNegotiationMiddlewareTest extends TestCase
{
    public function testItPublishesTheResolvedLocaleOnTheRequestAndOnTheRequestScopedHolder(): void
    {
        $active = $this->holder();
        $middleware = $this->middleware($active, 'en');
        $seen = null;
        $handler = $this->handler(static function (ServerRequestInterface $request) use ($active, &$seen): void {
            $seen = [
                'attribute' => $request->getAttribute(LocaleNegotiationMiddleware::ATTRIBUTE),
                'holder' => $active->locale()->toString(),
                'scope' => $active->scope()->key(),
            ];
        });

        $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', 'https://kumwe.test/')
                ->withHeader('Accept-Language', 'de'),
            $handler,
        );

        self::assertIsArray($seen);
        self::assertInstanceOf(LocaleTag::class, $seen['attribute']);
        self::assertSame('de', $seen['attribute']->toString());
        self::assertSame('de', $seen['holder']);
        self::assertSame('acme', $seen['scope']);
    }

    public function testAnExplicitQueryChoiceOverridesTheClientPreference(): void
    {
        $active = $this->holder();
        $seen = null;
        $handler = $this->handler(static function (ServerRequestInterface $request) use (&$seen): void {
            $seen = $request->getAttribute(LocaleNegotiationMiddleware::ATTRIBUTE);
        });

        $this->middleware($active, 'en')->process(
            (new ServerRequestFactory())
                ->createServerRequest('GET', 'https://kumwe.test/?locale=he')
                ->withQueryParams(['locale' => 'he'])
                ->withHeader('Accept-Language', 'de'),
            $handler,
        );

        self::assertInstanceOf(LocaleTag::class, $seen);
        self::assertSame('he', $seen->toString());
    }

    public function testTheHolderIsClosedEvenWhenTheRequestEndsByThrowing(): void
    {
        $active = $this->holder();
        $middleware = $this->middleware($active, 'he');
        $handler = $this->handler(static function (): void {
            throw new RuntimeException('The handler failed.');
        });

        try {
            $middleware->process(
                (new ServerRequestFactory())->createServerRequest('GET', 'https://kumwe.test/'),
                $handler,
            );
            self::fail('The handler failure must propagate.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }

        self::assertSame('en-GB', $active->locale()->toString());
    }

    /**
     * Public HTML advertises its resolved language and varies without replacing existing fields.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPublicHtmlCarriesLanguageAndAcceptLanguageVariation(): void
    {
        $response = $this->middleware($this->holder(), 'en')->process(
            (new ServerRequestFactory())->createServerRequest('GET', 'https://kumwe.test/')
                ->withHeader('Accept-Language', 'de'),
            $this->handler(
                static function (): void {
                },
                new HtmlResponse('', 200, [
                    'Cache-Control' => 'public, max-age=60',
                    'Vary' => 'Accept-Encoding',
                ]),
            ),
        );

        self::assertSame('de', $response->getHeaderLine('Content-Language'));
        self::assertSame('Accept-Encoding, Accept-Language', $response->getHeaderLine('Vary'));
    }

    /**
     * A source-language fallback page keeps the language its own document declares.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExplicitFallbackLanguageIsNotOverwrittenByNegotiation(): void
    {
        $body = '<!doctype html><html lang="en-GB"><body>Fallback</body></html>';
        $response = $this->middleware($this->holder(), 'en')->process(
            (new ServerRequestFactory())->createServerRequest('GET', 'https://kumwe.test/missing')
                ->withHeader('Accept-Language', 'de'),
            $this->handler(
                static function (): void {
                },
                new HtmlResponse($body, 404, [
                    'Cache-Control' => 'no-store',
                    'Content-Language' => 'en-GB',
                ]),
            ),
        );

        self::assertSame('en-GB', $response->getHeaderLine('Content-Language'));
        self::assertStringContainsString('<html lang="en-GB">', (string) $response->getBody());
        self::assertFalse($response->hasHeader('Vary'));
    }

    /**
     * A public redirect selected by locale carries the same cache contract as public HTML.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPublicRedirectCarriesLanguageAndAcceptLanguageVariation(): void
    {
        $response = $this->middleware($this->holder(), 'en')->process(
            (new ServerRequestFactory())->createServerRequest('GET', 'https://kumwe.test/')
                ->withHeader('Accept-Language', 'he'),
            $this->handler(
                static function (): void {
                },
                new RedirectResponse('/he', 302, ['Cache-Control' => 'public, max-age=60']),
            ),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('he', $response->getHeaderLine('Content-Language'));
        self::assertSame('Accept-Language', $response->getHeaderLine('Vary'));
    }

    /**
     * A machine response does not claim that negotiation changed its representation language.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPublicMachineResponseCarriesNoLanguageMetadata(): void
    {
        $response = $this->middleware($this->holder(), 'en')->process(
            (new ServerRequestFactory())->createServerRequest('GET', 'https://kumwe.test/api')
                ->withHeader('Accept-Language', 'de'),
            $this->handler(
                static function (): void {
                },
                (new Response())->withHeader('Content-Type', 'application/json')
                    ->withHeader('Cache-Control', 'public, max-age=60'),
            ),
        );

        self::assertFalse($response->hasHeader('Content-Language'));
        self::assertFalse($response->hasHeader('Vary'));
    }

    private function holder(): ActiveLocale
    {
        return new ActiveLocale(new SupportedLocales());
    }

    private function middleware(ActiveLocale $active, string $storedLocale): LocaleNegotiationMiddleware
    {
        $supported = new SupportedLocales();
        $settings = new class ($storedLocale) implements SiteSettings {
            public function __construct(private readonly string $storedLocale)
            {
            }

            public function current(): array
            {
                return ['default_locale' => $this->storedLocale];
            }

            public function managed(ExecutionContext $context): array
            {
                return $this->current();
            }

            public function update(ExecutionContext $context, string $siteName, string $homepageSlug): void
            {
            }

            public function updateAll(ExecutionContext $context, array $settings): void
            {
            }
        };

        return new LocaleNegotiationMiddleware(
            new LocaleNegotiator($supported, new SiteDefaultLocale($settings, $supported)),
            $active,
            'acme',
        );
    }

    private function handler(callable $inspect, ?ResponseInterface $response = null): RequestHandlerInterface
    {
        return new class ($inspect, $response) implements RequestHandlerInterface {
            /** @var callable(ServerRequestInterface): void */
            private $inspect;

            public function __construct(callable $inspect, private readonly ?ResponseInterface $response)
            {
                $this->inspect = $inspect;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ($this->inspect)($request);

                return $this->response ?? new Response();
            }
        };
    }
}
