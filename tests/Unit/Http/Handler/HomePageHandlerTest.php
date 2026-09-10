<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Http\Handler;

use DateTimeImmutable;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Content\Application\ContentModelRepository;
use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Content\Application\SiteScopedContentRepository;
use Kumwe\App\Content\Application\TranslationGroupRepository;
use Kumwe\App\Content\Domain\ContentEntry;
use Kumwe\App\Content\Domain\ContentStatus;
use Kumwe\App\Content\Domain\PublicationWindow;
use Kumwe\App\Content\Domain\TranslationGroup;
use Kumwe\App\Content\Domain\TranslationGroupMember;
use Kumwe\App\Content\Presentation\TranslationGroupPresenter;
use Kumwe\App\Http\Handler\HomePageHandler;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Localization\Application\ActiveLocale;
use Kumwe\App\Localization\Application\SupportedLocales;
use Kumwe\App\Localization\Domain\LocaleTag;
use Kumwe\App\Navigation\Application\NavigationRepository;
use Kumwe\App\Navigation\Application\PublicNavigation;
use Kumwe\App\Presentation\ContentLayoutCatalog;
use Kumwe\App\Presentation\ContentPageRenderService;
use Kumwe\App\Presentation\ContentPresenter;
use Kumwe\App\Presentation\RichTextFormatter;
use Kumwe\App\Presentation\SiteRenderer;
use Kumwe\App\Presentation\Twig\SiteTwigEnvironment;
use Kumwe\App\Site\Application\PublicPageLocator;
use Kumwe\App\Site\Application\SiteSettings;
use Kumwe\App\Http\Middleware\SecurityHeadersMiddleware;
use Kumwe\App\Studio\Application\Composition\StudioPublishedContentRenderer;
use Kumwe\App\Studio\Application\Composition\StudioPublishedEnhancementRuntime;
use Kumwe\Producer\Deployment\StudioBrowserAssetLocator;
use Kumwe\Producer\Render\Enhancement;
use Kumwe\Producer\Render\RenderResult;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Workflow\Domain\Workflow;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Twig\Loader\ArrayLoader;

/**
 * Pins what the site root serves, which is the one public entry point that names no language.
 *
 * Every other public path carries its locale in the URL, so the root is the only place a reader's
 * negotiated language — rather than the address they typed — decides which locale of the nominated
 * page they are given. That decision, and the alternate-language links that go with it, is what this
 * class holds; the fallback to the standalone template when no page is nominated is held beside it,
 * because a freshly installed site has to serve a usable root before any content exists.
 *
 * @since  2.0.0
 */
#[CoversClass(HomePageHandler::class)]
#[CoversClass(ContentPageRenderService::class)]
final class HomePageHandlerTest extends TestCase
{
    /**
     * Identifier of the logical item the front page publishes.
     *
     * @var    string
     * @since  2.0.0
     */
    private const GROUP = '018f22e2-7c8b-7ab0-8f3a-88e8026bb960';

    /**
     * Identifier of the English entry, which is the one nominated as the homepage.
     *
     * @var    string
     * @since  2.0.0
     */
    private const ENGLISH = '018f22e2-7c8b-7ab0-8f3a-88e8026bb961';

    /**
     * Identifier of the German entry.
     *
     * @var    string
     * @since  2.0.0
     */
    private const GERMAN = '018f22e2-7c8b-7ab0-8f3a-88e8026bb962';

    /**
     * Prove the root serves the reader's negotiated locale of the nominated page, and advertises it.
     *
     * The nomination names one entry, so without negotiation a German reader would be handed the
     * English page at a URL that could not tell them otherwise. The alternates travel with the
     * response, so the layout can emit `hreflang` links and a selector for exactly the locales the
     * item publishes. Their hrefs are asserted too: each has to carry the explicit choice that stops a
     * visit to the language-neutral root from negotiating back to the reader's previous locale.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheRootServesTheNegotiatedLocaleOfTheNominatedPageAndAdvertisesTheRest(): void
    {
        $response = $this->handle('de');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            '<!doctype html><head></head>page|Ueber uns|de:true:/?locale=de en-GB:false:/?locale=en-GB '
                . '|/?locale=de|Kumwe|'
                . '<p>Ueber uns</p>',
            (string) $response->getBody(),
        );
        self::assertSame('public, max-age=60, stale-while-revalidate=300', $response->getHeaderLine('Cache-Control'));
        self::assertSame('', $response->getHeaderLine('X-Robots-Tag'));

        $studio = $this->handle(
            'de',
            studioBody: '<section class="studio-preview-section"><p>Studio home</p></section>',
        );
        $studioHtml = (string) $studio->getBody();
        self::assertStringContainsString(
            'page|Ueber uns|de:true:/?locale=de en-GB:false:/?locale=en-GB |/?locale=de|Kumwe|'
                . '<section class="studio-preview-section"><p>Studio home</p></section>',
            $studioHtml,
        );
        self::assertStringContainsString(
            '<link rel="stylesheet" href="/studio/styles/'
                . hash('sha256', '[data-studio-block]{display:block}')
                . '.css?page=%2F&amp;entry=' . self::GERMAN . '&amp;locale=de" data-studio-composition>',
            $studioHtml,
        );
        self::assertSame('', $studio->getHeaderLine(SecurityHeadersMiddleware::SCRIPT_ORIGIN_HEADER));
    }

    /**
     * A homepage composition that needs the pinned enhancement runtime names that runtime's origin for the
     * security headers, so `script-src` widens by exactly one origin on exactly that response.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnEnhancedHomepageNamesTheRuntimeOriginForTheSecurityHeaders(): void
    {
        $response = $this->handle(
            'de',
            studioBody: '<section data-studio-enhancement="tabs"><p>Studio home</p></section>',
            enhanced: true,
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            'https://cdn.jsdelivr.net',
            $response->getHeaderLine(SecurityHeadersMiddleware::SCRIPT_ORIGIN_HEADER),
        );
        self::assertStringContainsString(
            '<section data-studio-enhancement="tabs"><p>Studio home</p></section>',
            (string) $response->getBody(),
        );
    }

    /**
     * Prove a reader whose language the item does not publish is served the declared fallback.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAReaderWhoseLanguageIsNotPublishedIsServedTheDeclaredFallback(): void
    {
        $active = null;
        $response = $this->handle('af', active: $active);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('|About us|', (string) $response->getBody());
        self::assertInstanceOf(ActiveLocale::class, $active);
        self::assertSame('en-GB', $active->locale()->toString());
    }

    /**
     * Prove a site nominating no homepage renders the standalone template with no alternates at all.
     *
     * Nothing is nominated on a freshly installed site, so the root has to answer with the `home`
     * template rather than a 404 — and with an empty language view model, because there is no item
     * whose locales could be advertised. The indexing refusal is asserted here too, since that is
     * what keeps a staging deployment out of search results.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testASiteNominatingNoHomepageRendersTheStandaloneTemplateWithoutAlternates(): void
    {
        $response = $this->handle('de', nominated: false, indexing: false);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('home|0|none|Kumwe', (string) $response->getBody());
        self::assertSame('noindex, nofollow, noarchive', $response->getHeaderLine('X-Robots-Tag'));
    }

    /**
     * Answer one request for the site root under a stated reader locale and site configuration.
     *
     * @param   string  $locale     Locale the request negotiated.
     * @param   bool    $nominated  Whether the site nominates a homepage entry at all.
     * @param   bool    $indexing   Whether the site allows search engines to index it.
     * @param   ?ActiveLocale  $active  Shared locale holder, returned for assertions when requested.
     * @param   ?string  $studioBody  Safe published Studio fragment, or null for legacy rendering.
     * @param   bool    $enhanced   Whether the Studio fragment needs the pinned enhancement runtime.
     *
     * @return  ResponseInterface  The handler's response.
     *
     * @since   2.0.0
     */
    private function handle(
        string $locale,
        bool $nominated = true,
        bool $indexing = true,
        ?ActiveLocale &$active = null,
        ?string $studioBody = null,
        bool $enhanced = false,
    ): ResponseInterface {
        $settings = $this->settings($nominated, $indexing);
        $content = $this->content();
        $locator = new PublicPageLocator($content, $settings, $this->navigation(), SiteContext::default());
        $active = new ActiveLocale(new SupportedLocales());
        $active->begin(LocaleTag::fromString($locale));
        $handler = new HomePageHandler(
            $locator,
            new ContentPageRenderService($settings, new SiteRenderer(new SiteTwigEnvironment(new ArrayLoader([
                'page.twig' => '<!doctype html><head></head>page|{{ entry.title }}|'
                    . '{% for a in languages.alternates %}{{ a.locale }}:{{ a.current ? "true" : "false" }}:'
                    . '{{ a.href }} {% endfor %}|{{ canonical_url }}|{{ site_name }}|'
                    . '{{ entry.body_html|default("")|raw }}',
                'home.twig' => 'home|{{ languages.alternates|length }}|'
                    . '{{ languages.default_href is null ? "none" : "some" }}|{{ site_name }}',
            ])))),
            new ContentPresenter(new RichTextFormatter()),
            new ContentLayoutCatalog($this->createStub(ContentModelRepository::class), SiteContext::DEFAULT),
            $this->languages($content, $locator, $active),
            $active,
            $this->studio($studioBody, $enhanced),
            $enhanced
                ? new StudioPublishedEnhancementRuntime(
                    StudioBrowserAssetLocator::npmPackages('https://cdn.jsdelivr.net/npm'),
                )
                : null,
        );

        return $handler->handle(
            (new ServerRequestFactory())->createServerRequest('GET', 'https://kumwe.test/'),
        );
    }

    /**
     * Build an optional published Studio rendering seam for the homepage path.
     *
     * @param   ?string  $body      Safe Studio body or null to preserve the legacy Content presenter.
     * @param   bool     $enhanced  Whether the body carries one node the enhancement runtime must serve.
     *
     * @return  ?StudioPublishedContentRenderer  Configured renderer, or null without a Studio composition.
     *
     * @since   2.0.0
     */
    private function studio(?string $body, bool $enhanced = false): ?StudioPublishedContentRenderer
    {
        if ($body === null) {
            return null;
        }
        $studio = $this->createStub(StudioPublishedContentRenderer::class);
        $studio->method('render')->willReturn(new RenderResult(
            $body,
            '[data-studio-block]{display:block}',
            $enhanced ? [new Enhancement('tabs', 'node-one', 's6e6f64652d6f6e65')] : [],
        ));

        return $studio;
    }

    /**
     * Build the language presenter over the item the front page publishes in two locales.
     *
     * @param   ContentService     $content  Publication-aware reader each sibling is loaded through.
     * @param   PublicPageLocator  $pages    Two-way path map each locale's link is built through.
     * @param   ActiveLocale       $active   Shared holder carrying the request's negotiated locale.
     *
     * @return  TranslationGroupPresenter  Presenter wired the way the composition root wires it.
     *
     * @since   2.0.0
     */
    private function languages(
        ContentService $content,
        PublicPageLocator $pages,
        ActiveLocale $active,
    ): TranslationGroupPresenter {
        $groups = $this->createStub(TranslationGroupRepository::class);
        $groups->method('forContent')->willReturn(new TranslationGroup(
            self::GROUP,
            LocaleTag::fromString('en-GB'),
            [
                new TranslationGroupMember(
                    LocaleTag::fromString('en-GB'),
                    self::ENGLISH,
                    'about-us',
                    ContentStatus::Published->value,
                    true,
                    PublicationWindow::unbounded(),
                ),
                new TranslationGroupMember(
                    LocaleTag::fromString('de'),
                    self::GERMAN,
                    'ueber-uns',
                    ContentStatus::Published->value,
                    true,
                    PublicationWindow::unbounded(),
                ),
            ],
        ));
        return new TranslationGroupPresenter(
            $groups,
            $content,
            $pages,
            $active,
            $this->clock(),
            SiteContext::default(),
        );
    }

    /**
     * Build the content service over a store holding both locales of the front page.
     *
     * @return  ContentService  Service wired with a fixed clock and the built-in workflow.
     *
     * @since   2.0.0
     */
    private function content(): ContentService
    {
        $records = [
            self::ENGLISH => $this->record(self::ENGLISH, 'About us', 'about-us', 'en-GB'),
            self::GERMAN => $this->record(self::GERMAN, 'Ueber uns', 'ueber-uns', 'de'),
        ];
        $repository = $this->createStub(SiteScopedContentRepository::class);
        $repository->method('findPublishedByIdForSite')->willReturnCallback(
            static fn (SiteContext $site, string $id): ?ContentRecord => $records[$id] ?? null,
        );
        $repository->method('findPublishedBySlugForSite')->willReturn(null);
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );

        return new ContentService(
            $repository,
            $this->createStub(AuditRecorder::class),
            $transactions,
            $this->clock(),
            new Workflow(),
            AuthorizationContext::gateway(),
            AuthorizationContext::ownershipWriter(),
        );
    }

    /**
     * Build one locale's stored record.
     *
     * @param   string  $id      UUID of the entry.
     * @param   string  $title   Title the template renders.
     * @param   string  $slug    Route segment the locale is published under.
     * @param   string  $locale  Language tag the entry is written in.
     *
     * @return  ContentRecord  The stored record, published and unscheduled.
     *
     * @since   2.0.0
     */
    private function record(string $id, string $title, string $slug, string $locale): ContentRecord
    {
        $at = new DateTimeImmutable('2026-08-17T09:00:00+00:00');

        return new ContentRecord(
            ContentEntry::create(
                $id,
                $title,
                $slug,
                ['body' => $title],
                ContentStatus::Published,
                null,
                $locale,
                self::GROUP,
            ),
            ContentService::CORE_PAGE_TYPE_ID,
            ContentService::CORE_WORKFLOW_ID,
            $at,
            $at,
        );
    }

    /**
     * The stored site settings the handler reads.
     *
     * @param   bool  $nominated  Whether a homepage entry is nominated.
     * @param   bool  $indexing   Whether search engines may index the site.
     *
     * @return  SiteSettings  Stubbed settings document.
     *
     * @since   2.0.0
     */
    private function settings(bool $nominated, bool $indexing): SiteSettings
    {
        $settings = $this->createStub(SiteSettings::class);
        $settings->method('current')->willReturn([
            'site_name' => 'Kumwe',
            'homepage_content_id' => $nominated ? self::ENGLISH : null,
            'homepage_slug' => null,
            'search_indexing_enabled' => $indexing,
        ]);

        return $settings;
    }

    /**
     * An empty public navigation, so every link falls back to the record's own slug.
     *
     * @return  PublicNavigation  Navigation over a store with no menu.
     *
     * @since   2.0.0
     */
    private function navigation(): PublicNavigation
    {
        $repository = $this->createStub(NavigationRepository::class);
        $repository->method('menus')->willReturn([]);
        $repository->method('items')->willReturn([]);

        return new PublicNavigation($repository);
    }

    /**
     * The one instant every publication question in this class is asked about.
     *
     * @return  ClockInterface  Clock fixed to a moment inside every open window.
     *
     * @since   2.0.0
     */
    private function clock(): ClockInterface
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-17T10:00:00+00:00'));

        return $clock;
    }
}
