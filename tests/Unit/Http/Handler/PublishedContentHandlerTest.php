<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Http\Handler;

use DateTimeImmutable;
use Kumwe\App\Application\Authorization\SiteContext;
use Kumwe\App\Application\Persistence\TransactionManager;
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
use Kumwe\App\Http\Handler\PublishedContentHandler;
use Kumwe\App\Http\Handler\StudioPublishedStylesheetHandler;
use Kumwe\App\Localization\Application\ActiveLocale;
use Kumwe\App\Localization\Application\SupportedLocales;
use Kumwe\App\Localization\Application\Translator;
use Kumwe\App\Localization\Domain\LocaleTag;
use Kumwe\App\Localization\Domain\TextDirection;
use Kumwe\App\Localization\Presentation\TranslationTwigExtension;
use Kumwe\App\Navigation\Application\MenuItemRecord;
use Kumwe\App\Navigation\Application\MenuRecord;
use Kumwe\App\Navigation\Application\NavigationRepository;
use Kumwe\App\Navigation\Application\PublicNavigation;
use Kumwe\App\Presentation\Application\SitePresentation;
use Kumwe\App\Presentation\ContentLayoutCatalog;
use Kumwe\App\Presentation\ContentPageRenderService;
use Kumwe\App\Presentation\ContentPresenter;
use Kumwe\App\Presentation\RichTextFormatter;
use Kumwe\App\Presentation\SiteRenderer;
use Kumwe\App\Presentation\Twig\SiteTwigEnvironment;
use Kumwe\App\Site\Application\PublicPageLocator;
use Kumwe\App\Site\Application\SiteSettings;
use Kumwe\App\Studio\Application\Composition\StudioPublishedBlueprintUnavailable;
use Kumwe\App\Studio\Application\Composition\StudioPublishedContentRenderer;
use Kumwe\App\Studio\Application\Composition\StudioPublishedEnhancementRuntime;
use Kumwe\App\Studio\Application\Composition\StudioPublishedStylesheet;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Http\Middleware\SecurityHeadersMiddleware;
use Kumwe\Producer\Deployment\StudioBrowserAssetLocator;
use Kumwe\Producer\Render\Enhancement;
use Kumwe\Producer\Render\RenderResult;
use Kumwe\App\Workflow\Domain\Workflow;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Twig\Loader\ArrayLoader;

/**
 * Pins the public handler's published-Studio branch without weakening the established site response.
 *
 * The composition fragment replaces only the legacy entry body. Canonical routing, cache and indexing
 * policy, record locale, navigation, language alternates, the menu-bound colour scheme, and the internal
 * public page template still belong to the existing site pipeline. The refusal cases live here too: a
 * configured but incompatible composition must escape as its typed failure instead of being hidden by a
 * legacy fallback.
 *
 * @since  2.0.0
 */
#[CoversClass(PublishedContentHandler::class)]
#[CoversClass(StudioPublishedStylesheetHandler::class)]
#[CoversClass(StudioPublishedStylesheet::class)]
#[UsesClass(ContentLayoutCatalog::class)]
#[UsesClass(ContentPageRenderService::class)]
#[UsesClass(ContentPresenter::class)]
#[UsesClass(PublicPageLocator::class)]
final class PublishedContentHandlerTest extends TestCase
{
    /**
     * Arabic record rendered by the handler.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string ARABIC = '018f22e2-7c8b-7ab0-8f3a-88e8026bd101';

    /**
     * English sibling used to prove language alternates survive composition rendering.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string ENGLISH = '018f22e2-7c8b-7ab0-8f3a-88e8026bd102';

    /**
     * Logical translation-group identifier shared by the two records.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string GROUP = '018f22e2-7c8b-7ab0-8f3a-88e8026bd103';

    /**
     * Public navigation menu used by every handler fixture.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string MENU = '018f22e2-7c8b-7ab0-8f3a-88e8026bd104';

    /**
     * Prove composed markup enters the ordinary public theme pipeline with every surrounding decision intact.
     *
     * The menu deliberately binds the legacy `article` template and the Ocean scheme. A published
     * composition must choose the closed internal `page` template while retaining that scheme, as well as
     * the canonical URL, public navigation, alternate languages, record direction, cache policy, and indexing
     * refusal. An empty `entry.data` proves the legacy payload cannot leak beside the composed fragment.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPublishedStudioMarkupKeepsTheCanonicalSiteEnvelopeIntact(): void
    {
        $studio = $this->createMock(StudioPublishedContentRenderer::class);
        $studio->expects(self::once())
            ->method('render')
            ->with(self::callback(static fn (ContentRecord $record): bool =>
                $record->entry->id() === self::ARABIC))
            ->willReturn(new RenderResult(
                '<section data-studio-published><p>Composed roadmap</p></section>',
                '[data-studio-block]{display:block}',
                [],
            ));
        $active = null;
        $handler = $this->handler($studio, $active);

        $response = $handler->handle($this->request('/insights/roadmap'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(
            'page|ar|rtl|/insights/roadmap|https://public.kumwe.test/insights/roadmap|core.public.page|'
                . 'Arabic roadmap|0|<section data-studio-published><p>Composed roadmap</p></section>|'
                . 'ocean|#0777af|Roadmap=/insights/roadmap;Roadmap in English=/insights/roadmap-en;'
                . 'Documentation=/documentation;|ar=/insights/roadmap:rtl:current;'
                . 'en-GB=/insights/roadmap-en:ltr:other;|/insights/roadmap-en',
            (string) $response->getBody(),
        );
        self::assertStringContainsString(
            '<link rel="stylesheet" href="/studio/styles/'
                . hash('sha256', '[data-studio-block]{display:block}')
                . '.css?page=%2Finsights%2Froadmap&amp;entry=' . self::ARABIC
                . '&amp;locale=ar" data-studio-composition>',
            (string) $response->getBody(),
        );
        self::assertSame(
            'public, max-age=60, stale-while-revalidate=300',
            $response->getHeaderLine('Cache-Control'),
        );
        self::assertSame('noindex, nofollow, noarchive', $response->getHeaderLine('X-Robots-Tag'));
        self::assertSame('', $response->getHeaderLine(SecurityHeadersMiddleware::SCRIPT_ORIGIN_HEADER));
        self::assertInstanceOf(ActiveLocale::class, $active);
        self::assertSame('ar', $active->locale()->toString());
        self::assertSame(TextDirection::RightToLeft, $active->locale()->direction());
    }

    /**
     * Prove an absent published composition preserves the established presenter and bound layout exactly.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNoPublishedCompositionPreservesTheLegacyLayoutAndPresenter(): void
    {
        $studio = $this->createMock(StudioPublishedContentRenderer::class);
        $studio->expects(self::once())->method('render')->willReturn(null);
        $active = null;

        $response = $this->handler($studio, $active)->handle($this->request('/insights/roadmap'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            'article|/insights/roadmap|https://public.kumwe.test/insights/roadmap|Arabic roadmap|'
                . '<p>Legacy <strong>body</strong>.</p>|ocean|#0777af',
            (string) $response->getBody(),
        );
        self::assertInstanceOf(ActiveLocale::class, $active);
        self::assertSame('ar', $active->locale()->toString());
    }

    /**
     * Prove a configured composition failure propagates instead of silently exposing legacy content.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testConfiguredCompositionFailureRemainsFailClosed(): void
    {
        $studio = $this->createMock(StudioPublishedContentRenderer::class);
        $studio->expects(self::once())
            ->method('render')
            ->willThrowException(new StudioPublishedBlueprintUnavailable());

        $this->expectException(StudioPublishedBlueprintUnavailable::class);
        $this->expectExceptionMessage('The configured published Studio Blueprint is unavailable.');

        $active = null;
        $this->handler($studio, $active)->handle($this->request('/insights/roadmap'));
    }

    /**
     * Prove canonical redirects happen before the optional composition renderer is consulted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCanonicalRedirectPreservesQueryLocaleAndCacheWithoutRendering(): void
    {
        $studio = $this->createMock(StudioPublishedContentRenderer::class);
        $studio->expects(self::never())->method('render');
        $active = null;

        $response = $this->handler($studio, $active)->handle(
            $this->request('/pages/roadmap-ar?campaign=studio'),
        );

        self::assertSame(308, $response->getStatusCode());
        self::assertSame('/insights/roadmap?campaign=studio', $response->getHeaderLine('Location'));
        self::assertSame('public, max-age=300', $response->getHeaderLine('Cache-Control'));
        self::assertInstanceOf(ActiveLocale::class, $active);
        self::assertSame('ar', $active->locale()->toString());
    }

    /**
     * Prove a public miss remains indistinguishable and cannot invoke the composition boundary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMissingPageRemainsAnUncacheableCompositionFreeMiss(): void
    {
        $studio = $this->createMock(StudioPublishedContentRenderer::class);
        $studio->expects(self::never())->method('render');
        $active = null;

        $response = $this->handler($studio, $active)->handle($this->request('/missing-page'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('en-GB', $response->getHeaderLine('Content-Language'));
        self::assertStringNotContainsString('Legacy', (string) $response->getBody());
        self::assertInstanceOf(ActiveLocale::class, $active);
        self::assertSame('en-GB', $active->locale()->toString());
    }

    /**
     * Prove the stylesheet endpoint re-renders live output per request, then serves exact bytes with a
     * digest ETag under must-revalidate caching, or an empty 304 on a matching If-None-Match.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPublishedStylesheetRevalidatesLiveOutputBeforeBytesOrNotModified(): void
    {
        $css = '[data-studio-block]{display:block}';
        $result = new RenderResult('<p>Published</p>', $css, []);
        $studio = $this->createMock(StudioPublishedContentRenderer::class);
        $studio->expects(self::exactly(2))->method('render')->willReturn($result);
        $handler = $this->stylesheetHandler($studio);
        $digest = StudioPublishedStylesheet::digest($css);
        $request = $this->stylesheetRequest($digest);

        $response = $handler->handle($request);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($css, (string) $response->getBody());
        self::assertSame('"' . $digest . '"', $response->getHeaderLine('ETag'));
        self::assertSame('public, no-cache, must-revalidate', $response->getHeaderLine('Cache-Control'));
        self::assertSame('same-origin', $response->getHeaderLine('Cross-Origin-Resource-Policy'));

        $notModified = $handler->handle($request->withHeader('If-None-Match', '"' . $digest . '"'));
        self::assertSame(304, $notModified->getStatusCode());
        self::assertSame('', (string) $notModified->getBody());
    }

    /**
     * Prove a withdrawn composition or drifted locale coordinate yields an uncacheable 404, and the
     * mismatched coordinate never reaches the rendering boundary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPublishedStylesheetFailsClosedAfterWithdrawalOrCoordinateDrift(): void
    {
        $studio = $this->createMock(StudioPublishedContentRenderer::class);
        $studio->expects(self::once())->method('render')->willReturn(null);
        $handler = $this->stylesheetHandler($studio);
        $digest = StudioPublishedStylesheet::digest('[data-studio-block]{display:block}');

        $withdrawn = $handler->handle($this->stylesheetRequest($digest));
        self::assertSame(404, $withdrawn->getStatusCode());
        self::assertSame('private, no-store', $withdrawn->getHeaderLine('Cache-Control'));

        $wrongLocale = $handler->handle($this->stylesheetRequest($digest)->withQueryParams([
            'page' => '/insights/roadmap',
            'entry' => self::ARABIC,
            'locale' => 'en-GB',
        ]));
        self::assertSame(404, $wrongLocale->getStatusCode());
        self::assertSame('private, no-store', $wrongLocale->getHeaderLine('Cache-Control'));
    }

    /**
     * A composition that needs the pinned enhancement runtime names that runtime's origin for the security
     * headers, so `script-src` widens by exactly one origin on exactly that response.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnEnhancedCompositionNamesTheRuntimeOriginForTheSecurityHeaders(): void
    {
        $studio = $this->createStub(StudioPublishedContentRenderer::class);
        $studio->method('render')->willReturn(new RenderResult(
            '<section data-studio-enhancement="tabs"><p>Composed roadmap</p></section>',
            '[data-studio-block]{display:block}',
            [new Enhancement('tabs', 'node-one', 's6e6f64652d6f6e65')],
        ));
        $active = null;
        $handler = $this->handler(
            $studio,
            $active,
            new StudioPublishedEnhancementRuntime(
                StudioBrowserAssetLocator::npmPackages('https://cdn.jsdelivr.net/npm'),
            ),
        );

        $response = $handler->handle($this->request('/insights/roadmap'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            'https://cdn.jsdelivr.net',
            $response->getHeaderLine(SecurityHeadersMiddleware::SCRIPT_ORIGIN_HEADER),
        );
        self::assertStringContainsString(
            '<section data-studio-enhancement="tabs"><p>Composed roadmap</p></section>',
            (string) $response->getBody(),
        );
    }

    /**
     * Build the real public collaborators around a controllable Studio rendering boundary.
     *
     * @param   StudioPublishedContentRenderer     $studio        Published composition boundary under test.
     * @param   ?ActiveLocale                      $active        Shared locale holder returned for assertions.
     * @param   ?StudioPublishedEnhancementRuntime  $enhancements  Optional pinned enhancement runtime locator.
     *
     * @return  PublishedContentHandler  Fully composed handler over deterministic in-memory stores.
     *
     * @since   2.0.0
     */
    private function handler(
        StudioPublishedContentRenderer $studio,
        ?ActiveLocale &$active,
        ?StudioPublishedEnhancementRuntime $enhancements = null,
    ): PublishedContentHandler {
        $settings = $this->settings();
        $content = $this->content();
        $pages = new PublicPageLocator(
            $content,
            $settings,
            $this->navigation(),
            SiteContext::default(),
        );
        $active = new ActiveLocale(new SupportedLocales());
        $active->begin(LocaleTag::fromString('en-GB'));
        $models = $this->createStub(ContentModelRepository::class);
        $twig = new SiteTwigEnvironment(new ArrayLoader([
            'page.twig' => '<!doctype html><head></head>page|{{ locale_tag() }}|{{ text_direction() }}|'
                . '{{ current_path }}|{{ canonical_url }}|{{ surface_id }}|'
                . '{{ entry.title }}|{{ entry.data|length }}|{{ entry.body_html|raw }}|'
                . '{{ presentation.active_scheme }}|'
                . '{{ presentation.css_variables[\'--site-accent\'] }}|'
                . '{% for item in navigation %}{{ item.title }}={{ item.href }};{% endfor %}|'
                . '{% for alternate in languages.alternates %}{{ alternate.locale }}='
                . '{{ alternate.href }}:{{ alternate.direction }}:'
                . '{{ alternate.current ? "current" : "other" }};{% endfor %}|'
                . '{{ languages.default_href }}',
            'article.twig' => 'article|{{ current_path }}|{{ canonical_url }}|{{ entry.title }}|'
                . '{{ entry.body_html|raw }}|{{ presentation.active_scheme }}|'
                . '{{ presentation.css_variables[\'--site-accent\'] }}',
        ]));
        $twig->addExtension(new TranslationTwigExtension($this->createStub(Translator::class), $active));

        return new PublishedContentHandler(
            $pages,
            new ContentPageRenderService(
                $settings,
                new SiteRenderer(
                    $twig,
                    baseUrl: 'https://public.kumwe.test',
                ),
            ),
            new ContentPresenter(new RichTextFormatter()),
            new ContentLayoutCatalog($models, SiteContext::DEFAULT),
            $this->languages($content, $pages, $active),
            $active,
            $studio,
            $enhancements,
        );
    }

    /**
     * Build the stylesheet handler over real locators and an Arabic active locale.
     *
     * @param   StudioPublishedContentRenderer  $studio  Published composition boundary under test.
     *
     * @return  StudioPublishedStylesheetHandler  Handler composed over deterministic in-memory stores.
     *
     * @since   2.0.0
     */
    private function stylesheetHandler(
        StudioPublishedContentRenderer $studio,
    ): StudioPublishedStylesheetHandler {
        $content = $this->content();
        $pages = new PublicPageLocator(
            $content,
            $this->settings(),
            $this->navigation(),
            SiteContext::default(),
        );
        $active = new ActiveLocale(new SupportedLocales());
        $active->begin(LocaleTag::fromString('ar'));

        return new StudioPublishedStylesheetHandler(
            $pages,
            $this->languages($content, $pages, $active),
            $studio,
            SiteContext::default(),
        );
    }

    /**
     * Build one stylesheet request for the Arabic roadmap page at the given digest coordinate.
     *
     * @param   string  $digest  Stylesheet content digest addressed by the request path.
     *
     * @return  \Psr\Http\Message\ServerRequestInterface  Request carrying digest, page, entry and locale.
     *
     * @since   2.0.0
     */
    private function stylesheetRequest(string $digest): \Psr\Http\Message\ServerRequestInterface
    {
        return $this->request('/studio/styles/' . $digest . '.css')
            ->withAttribute('digest', $digest)
            ->withQueryParams([
                'page' => '/insights/roadmap',
                'entry' => self::ARABIC,
                'locale' => 'ar',
            ]);
    }

    /**
     * Build published Arabic and English siblings behind the ordinary Content service boundary.
     *
     * @return  ContentService  Publication-aware Content service over two deterministic records.
     *
     * @since   2.0.0
     */
    private function content(): ContentService
    {
        $records = [
            self::ARABIC => $this->record(self::ARABIC, 'Arabic roadmap', 'roadmap-ar', 'ar'),
            self::ENGLISH => $this->record(self::ENGLISH, 'Roadmap', 'roadmap-en', 'en-GB'),
        ];
        $repository = $this->createStub(SiteScopedContentRepository::class);
        $repository->method('findPublishedByIdForSite')->willReturnCallback(
            static fn (SiteContext $site, string $id): ?ContentRecord => $records[$id] ?? null,
        );
        $repository->method('findPublishedBySlugForSite')->willReturnCallback(
            static function (SiteContext $site, string $slug) use ($records): ?ContentRecord {
                foreach ($records as $record) {
                    if ($record->entry->slug() === $slug) {
                        return $record;
                    }
                }

                return null;
            },
        );
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
     * Build one published record carrying the locale and legacy body the handler must preserve.
     *
     * @param   string  $id      Stable entry identifier.
     * @param   string  $title   Public title.
     * @param   string  $slug    Stable permalink slug.
     * @param   string  $locale  Declared record locale.
     *
     * @return  ContentRecord  Published, unscheduled record pinned to Content-model version three.
     *
     * @since   2.0.0
     */
    private function record(string $id, string $title, string $slug, string $locale): ContentRecord
    {
        $at = new DateTimeImmutable('2026-08-24T10:00:00+00:00');

        return new ContentRecord(
            ContentEntry::create(
                $id,
                $title,
                $slug,
                ['body' => 'Legacy **body**.'],
                ContentStatus::Published,
                null,
                $locale,
                self::GROUP,
            ),
            ContentService::CORE_PAGE_TYPE_ID,
            ContentService::CORE_WORKFLOW_ID,
            $at,
            $at,
            contentTypeVersion: 3,
        );
    }

    /**
     * Build the two-language view model used by the public site template.
     *
     * @param   ContentService     $content  Publication-aware sibling reader.
     * @param   PublicPageLocator  $pages    Canonical path builder for each sibling.
     * @param   ActiveLocale       $active   Locale holder the handler aligns to the record.
     *
     * @return  TranslationGroupPresenter  Presenter over the Arabic and English group.
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
                    LocaleTag::fromString('ar'),
                    self::ARABIC,
                    'roadmap-ar',
                    ContentStatus::Published->value,
                    true,
                    PublicationWindow::unbounded(),
                ),
                new TranslationGroupMember(
                    LocaleTag::fromString('en-GB'),
                    self::ENGLISH,
                    'roadmap-en',
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
     * Build the menu mount, template and colour-scheme bindings the response must retain.
     *
     * @return  PublicNavigation  Navigation with both language siblings and one ordinary URL.
     *
     * @since   2.0.0
     */
    private function navigation(): PublicNavigation
    {
        $at = new DateTimeImmutable('2026-08-24T10:00:00+00:00');
        $repository = $this->createStub(NavigationRepository::class);
        $repository->method('menus')->willReturn([
            new MenuRecord(self::MENU, 'main', 'Main menu', 1, $at, $at),
        ]);
        $repository->method('items')->willReturn([
            new MenuItemRecord(
                '018f22e2-7c8b-7ab0-8f3a-88e8026bd105',
                self::MENU,
                null,
                'Roadmap',
                'roadmap',
                '/insights/roadmap',
                0,
                1,
                $at,
                $at,
                contentId: self::ARABIC,
                template: 'article',
                colorScheme: 'ocean',
            ),
            new MenuItemRecord(
                '018f22e2-7c8b-7ab0-8f3a-88e8026bd106',
                self::MENU,
                null,
                'Roadmap in English',
                'roadmap-en',
                '/insights/roadmap-en',
                1,
                1,
                $at,
                $at,
                contentId: self::ENGLISH,
            ),
            new MenuItemRecord(
                '018f22e2-7c8b-7ab0-8f3a-88e8026bd107',
                self::MENU,
                null,
                'Documentation',
                'documentation',
                '/documentation-link',
                2,
                1,
                $at,
                $at,
                targetType: 'url',
                targetUrl: '/documentation',
            ),
        ]);

        return new PublicNavigation($repository);
    }

    /**
     * Build the site settings whose presentation override is asserted in the rendered response.
     *
     * @return  SiteSettings  Stable settings with indexing disabled and the default validated schemes.
     *
     * @since   2.0.0
     */
    private function settings(): SiteSettings
    {
        $settings = $this->createStub(SiteSettings::class);
        $settings->method('current')->willReturn([
            'site_name' => 'Kumwe Enterprise',
            'homepage_content_id' => null,
            'homepage_slug' => null,
            'search_indexing_enabled' => false,
            'presentation' => SitePresentation::defaults(),
        ]);

        return $settings;
    }

    /**
     * Build the fixed clock shared by publication and translation decisions.
     *
     * @return  ClockInterface  Clock fixed inside every record's unbounded publication window.
     *
     * @since   2.0.0
     */
    private function clock(): ClockInterface
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-24T10:00:00+00:00'));

        return $clock;
    }

    /**
     * Build one GET request whose path and query the handler resolves.
     *
     * @param   string  $path  Root-relative path, optionally carrying a query string.
     *
     * @return  \Psr\Http\Message\ServerRequestInterface  Immutable request targeting the public origin.
     *
     * @since   2.0.0
     */
    private function request(string $path): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest(
            'GET',
            'https://public.kumwe.test' . $path,
        );
    }
}
