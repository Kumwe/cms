<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Site\Application;

use DateTimeImmutable;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Content\Application\ContentModelRepository;
use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Content\Application\SiteScopedContentRepository;
use Kumwe\App\Content\Application\TranslationGroupRepository;
use Kumwe\App\Content\Domain\ContentEntry;
use Kumwe\App\Content\Domain\ContentStatus;
use Kumwe\App\Content\Domain\ExpectedVersion;
use Kumwe\App\Content\Presentation\TranslationGroupPresenter;
use Kumwe\App\Localization\Application\ActiveLocale;
use Kumwe\App\Localization\Application\SupportedLocales;
use Kumwe\App\Localization\Domain\LocaleTag;
use Kumwe\App\Http\Handler\PublishedContentHandler;
use Kumwe\App\Navigation\Application\MenuItemRecord;
use Kumwe\App\Navigation\Application\MenuRecord;
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
use Kumwe\App\Studio\Application\Composition\StudioPublishedContentRenderer;
use Kumwe\Producer\Render\RenderResult;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Workflow\Domain\Workflow;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Twig\Loader\ArrayLoader;

#[CoversClass(PublicPageLocator::class)]
#[CoversClass(PublishedContentHandler::class)]
#[CoversClass(ContentPresenter::class)]
#[UsesClass(ContentLayoutCatalog::class)]
#[UsesClass(ContentRecord::class)]
#[UsesClass(ContentService::class)]
#[UsesClass(PublicNavigation::class)]
final class PublicPageLocatorTest extends TestCase
{
    private const HOME = '018f22e2-7c8b-7ab0-8f3a-88e8026bb701';
    private const ABOUT = '018f22e2-7c8b-7ab0-8f3a-88e8026bb702';
    private const TEAM = '018f22e2-7c8b-7ab0-8f3a-88e8026bb703';
    private const MENU = '018f22e2-7c8b-7ab0-8f3a-88e8026bb704';
    private const ABOUT_ITEM = '018f22e2-7c8b-7ab0-8f3a-88e8026bb705';
    private const TEAM_ITEM = '018f22e2-7c8b-7ab0-8f3a-88e8026bb706';

    public function testResolvesHomepageByStableContentIdentifier(): void
    {
        $home = $this->record(self::HOME, 'Home', 'home');
        $locator = $this->locator([self::HOME => $home]);

        self::assertSame($home, $locator->homepage());
        self::assertSame('/', $locator->pathFor($home));
        self::assertSame($home, $locator->byPath('/'));
    }

    public function testResolvesNestedMenuPathAndRedirectSourceToOneCanonicalPage(): void
    {
        $records = [
            self::HOME => $this->record(self::HOME, 'Home', 'home'),
            self::ABOUT => $this->record(self::ABOUT, 'About', 'about'),
            self::TEAM => $this->record(self::TEAM, 'Team', 'team'),
        ];
        $locator = $this->locator($records);

        self::assertSame($records[self::TEAM], $locator->byPath('/about/team'));
        self::assertSame($records[self::TEAM], $locator->byPath('/pages/team'));
        self::assertSame('/about/team', $locator->pathFor($records[self::TEAM]));

        $navigation = $locator->navigation();
        self::assertSame('/', $navigation[0]['href']);
        self::assertSame('/about/team', $navigation[1]['children'][0]['href']);
    }

    public function testFallsBackToRootSlugForPublishedContentWithoutAMenuItem(): void
    {
        $news = $this->record('018f22e2-7c8b-7ab0-8f3a-88e8026bb707', 'News', 'news');
        $reserved = $this->record('018f22e2-7c8b-7ab0-8f3a-88e8026bb709', 'API', 'api');
        $locator = $this->locator([
            self::HOME => $this->record(self::HOME, 'Home', 'home'),
            $reserved->entry->id() => $reserved,
        ], ['news' => $news]);

        self::assertSame($news, $locator->byPath('/news'));
        self::assertSame('/news', $locator->pathFor($news));
        self::assertNull($locator->byPath('/about/../news'));
        self::assertNull($locator->byPath('/about%2Fteam'));
        self::assertNull($locator->byPath('/api'));
        self::assertNull($locator->publicPathFor($reserved));
    }

    public function testLegacyPagesRouteRedirectsPermanentlyToNestedCanonicalPath(): void
    {
        $team = $this->record(self::TEAM, 'Team', 'team');
        $team = $team->withEntry(
            $team->entry->translate(
                new ExpectedVersion(1),
                LocaleTag::fromString('de'),
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb960',
            ),
            new DateTimeImmutable('2026-08-07T10:01:00+00:00'),
        );
        $records = [
            self::HOME => $this->record(self::HOME, 'Home', 'home'),
            self::ABOUT => $this->record(self::ABOUT, 'About', 'about'),
            self::TEAM => $team,
        ];
        $settings = $this->settings();
        $locator = $this->locator($records, settings: $settings);
        $active = new ActiveLocale(new SupportedLocales());
        $handler = new PublishedContentHandler(
            $locator,
            new ContentPageRenderService($settings, new SiteRenderer(new SiteTwigEnvironment(new ArrayLoader()))),
            new ContentPresenter(new RichTextFormatter()),
            $this->layouts(),
            $this->languages($locator),
            $active,
        );
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'https://kumwe.test/pages/team?preview=0',
        );

        $response = $handler->handle($request);

        self::assertSame(308, $response->getStatusCode());
        self::assertSame('/about/team?preview=0', $response->getHeaderLine('Location'));
        self::assertSame('de', $active->locale()->toString());
    }

    public function testCanonicalNestedPathRendersThePublishedTarget(): void
    {
        $records = [
            self::HOME => $this->record(self::HOME, 'Home', 'home'),
            self::ABOUT => $this->record(self::ABOUT, 'About', 'about'),
            self::TEAM => $this->record(self::TEAM, 'Team', 'team'),
        ];
        $settings = $this->settings();
        $locator = $this->locator($records, settings: $settings);
        $handler = new PublishedContentHandler(
            $locator,
            new ContentPageRenderService($settings, new SiteRenderer(new SiteTwigEnvironment(new ArrayLoader([
                'page.twig' => '{{ current_path }}|{{ entry.title }}|{{ entry.body_html|raw }}',
            ])))),
            new ContentPresenter(new RichTextFormatter()),
            $this->layouts(),
            $this->languages($locator),
            new ActiveLocale(new SupportedLocales()),
        );
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'https://kumwe.test/about/team',
        );

        $response = $handler->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('/about/team|Team|<p>Team</p>', (string) $response->getBody());

        $studio = $this->createMock(StudioPublishedContentRenderer::class);
        $studio->expects(self::once())->method('render')->with($records[self::TEAM])->willReturn(new RenderResult(
            '<section class="studio-preview-section"><p>Studio team</p></section>',
            '[data-studio-block]{display:block}',
            [],
        ));
        $studioHandler = new PublishedContentHandler(
            $locator,
            new ContentPageRenderService($settings, new SiteRenderer(new SiteTwigEnvironment(new ArrayLoader([
                'page.twig' => '<head></head>{{ current_path }}|{{ entry.title }}|{{ entry.body_html|raw }}|'
                    . '{{ entry.data|length }}',
            ])))),
            new ContentPresenter(new RichTextFormatter()),
            $this->layouts(),
            $this->languages($locator),
            new ActiveLocale(new SupportedLocales()),
            $studio,
        );
        $studioResponse = $studioHandler->handle($request);

        self::assertSame(200, $studioResponse->getStatusCode());
        $studioBody = (string) $studioResponse->getBody();
        self::assertStringContainsString(
            '/about/team|Team|<section class="studio-preview-section"><p>Studio team</p></section>|0',
            $studioBody,
        );
        self::assertMatchesRegularExpression(
            '#<head><link rel="stylesheet" href="/studio/styles/[a-f0-9]{64}\.css\?[^"]+"'
            . ' data-studio-composition></head>#',
            $studioBody,
        );
    }

    public function testPresenterRendersNestedStructuredBodiesWithoutLosingTopLevelCompatibility(): void
    {
        $at = new DateTimeImmutable('2026-08-07T10:00:00+00:00');
        $record = new ContentRecord(
            ContentEntry::create(
                self::HOME,
                'Home',
                'home',
                [
                    'body' => 'Welcome **home**.',
                    'capabilities' => ['body' => 'Build **well**.'],
                    'sections' => [['body' => 'First'], ['body' => 'Second']],
                ],
                ContentStatus::Published,
            ),
            ContentService::CORE_PAGE_TYPE_ID,
            ContentService::CORE_WORKFLOW_ID,
            $at,
            $at,
        );

        $entry = (new ContentPresenter(new RichTextFormatter()))->present($record);

        self::assertSame('<p>Welcome <strong>home</strong>.</p>', $entry['body_html']);
        self::assertSame(
            '<p>Build <strong>well</strong>.</p>',
            $entry['data']['capabilities']['body_html'],
        );
        self::assertSame('<p>First</p>', $entry['data']['sections'][0]['body_html']);
        self::assertSame('<p>Second</p>', $entry['data']['sections'][1]['body_html']);
    }

    /**
     * @param array<string, ContentRecord> $byId
     * @param array<string, ContentRecord> $additionalBySlug
     */
    private function locator(
        array $byId,
        array $additionalBySlug = [],
        ?SiteSettings $settings = null,
    ): PublicPageLocator {
        $bySlug = $additionalBySlug;
        foreach ($byId as $record) {
            $bySlug[$record->entry->slug()] = $record;
        }
        $repository = $this->createStub(SiteScopedContentRepository::class);
        $repository->method('findPublishedByIdForSite')->willReturnCallback(
            static fn (SiteContext $site, string $id): ?ContentRecord => $byId[$id] ?? null,
        );
        $repository->method('findPublishedBySlugForSite')->willReturnCallback(
            static fn (SiteContext $site, string $slug): ?ContentRecord => $bySlug[$slug] ?? null,
        );
        return new PublicPageLocator(
            $this->content($repository),
            $settings ?? $this->settings(),
            $this->navigation(),
            SiteContext::default(),
        );
    }

    /**
     * Build the language presenter an untranslated site hands the public handlers.
     *
     * Every record in this class stands alone, so the group store answers null and the presenter
     * contributes no alternates — which is exactly the shape a single-language site renders in.
     *
     * @param   PublicPageLocator  $locator  Locator the presenter would build sibling links through.
     *
     * @return  TranslationGroupPresenter  Presenter over a store that carries no translation group.
     *
     * @since   2.0.0
     */
    private function languages(PublicPageLocator $locator): TranslationGroupPresenter
    {
        $groups = $this->createStub(TranslationGroupRepository::class);
        $groups->method('forContent')->willReturn(null);
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-07T10:00:00+00:00'));

        return new TranslationGroupPresenter(
            $groups,
            $this->content($this->createStub(SiteScopedContentRepository::class)),
            $locator,
            new ActiveLocale(new SupportedLocales()),
            $clock,
            SiteContext::default(),
        );
    }

    private function settings(): SiteSettings
    {
        $settings = $this->createStub(SiteSettings::class);
        $settings->method('current')->willReturn([
            'site_name' => 'Kumwe',
            'homepage_content_id' => self::HOME,
            'homepage_slug' => 'home',
            'search_indexing_enabled' => true,
        ]);

        return $settings;
    }

    private function content(SiteScopedContentRepository $repository): ContentService
    {
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-07T10:00:00+00:00'));

        return new ContentService(
            $repository,
            $this->createStub(AuditRecorder::class),
            $transactions,
            $clock,
            new Workflow(),
            AuthorizationContext::gateway(),
            AuthorizationContext::ownershipWriter(),
        );
    }

    private function navigation(): PublicNavigation
    {
        $at = new DateTimeImmutable('2026-08-07T10:00:00+00:00');
        $repository = $this->createStub(NavigationRepository::class);
        $repository->method('menus')->willReturn([
            new MenuRecord(self::MENU, 'main', 'Main menu', 1, $at, $at),
        ]);
        $repository->method('items')->willReturn([
            new MenuItemRecord(
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb708',
                self::MENU,
                null,
                'Home',
                'home',
                '/home',
                0,
                1,
                $at,
                $at,
                contentId: self::HOME,
            ),
            new MenuItemRecord(
                self::ABOUT_ITEM,
                self::MENU,
                null,
                'About',
                'about',
                '/about',
                1,
                1,
                $at,
                $at,
                contentId: self::ABOUT,
            ),
            new MenuItemRecord(
                self::TEAM_ITEM,
                self::MENU,
                self::ABOUT_ITEM,
                'Team',
                'team',
                '/about/team',
                0,
                1,
                $at,
                $at,
                contentId: self::TEAM,
            ),
        ]);

        return new PublicNavigation($repository);
    }

    private function record(string $id, string $title, string $slug): ContentRecord
    {
        $at = new DateTimeImmutable('2026-08-07T10:00:00+00:00');

        return new ContentRecord(
            ContentEntry::create($id, $title, $slug, ['body' => $title], ContentStatus::Published),
            ContentService::CORE_PAGE_TYPE_ID,
            ContentService::CORE_WORKFLOW_ID,
            $at,
            $at,
        );
    }

    private function layouts(): ContentLayoutCatalog
    {
        return new ContentLayoutCatalog($this->createStub(ContentModelRepository::class), 'default');
    }
}
