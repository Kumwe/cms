<?php

declare(strict_types=1);

namespace Kumwe\App\Http\Handler;

use Kumwe\App\Content\Presentation\TranslationGroupPresenter;
use Kumwe\App\Localization\Application\ActiveLocale;
use Kumwe\App\Presentation\ContentPageRenderService;
use Kumwe\App\Presentation\ContentLayoutCatalog;
use Kumwe\App\Presentation\ContentPresenter;
use Kumwe\App\Site\Application\PublicPageLocator;
use Kumwe\App\Http\Middleware\SecurityHeadersMiddleware;
use Kumwe\App\Studio\Application\Composition\StudioPublishedContentRenderer;
use Kumwe\App\Studio\Application\Composition\StudioPublishedEnhancementRuntime;
use Kumwe\App\Studio\Application\Composition\StudioPublishedStylesheet;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Renders the public front page at `/`, whether or not an operator has nominated a homepage.
 *
 * The front page is a setting rather than a fixed record, so this handler asks `PublicPageLocator` which
 * published entry the site currently points at. When nothing is nominated it falls back to the standalone
 * `home` template instead of returning a 404, which is what lets a freshly installed site serve a usable
 * page before any content exists. Everything else in the response — navigation, branding, the caching
 * and indexing headers — matches `PublishedContentHandler`, so a visitor cannot tell from the response
 * whether the front page is a nominated entry or the fallback.
 *
 * @since  2.0.0
 */
final readonly class HomePageHandler implements RequestHandlerInterface
{
    /**
     * Bind the front page to the locator, settings, and rendering collaborators it composes.
     *
     * @param PublicPageLocator $pages Resolver for the nominated homepage record and the site's
     *         public navigation tree.
     * @param ContentPageRenderService $renderer Canonical site template/theme path shared with preview.
     * @param  ContentPresenter                    $presenter     Presenter that escapes and renders the record's stored
     *         bodies before they reach a template.
     * @param  ContentLayoutCatalog                $layouts       Content-type to site-template layout selection.
     * @param  TranslationGroupPresenter           $languages     Chooser of which locale of the nominated homepage the
     *         reader is served, and builder of the alternate-language links and the language selector.
     * @param  ActiveLocale                        $active        Request locale holder aligned to the resolved homepage
     *         before its template and translated chrome are rendered.
     * @param  ?StudioPublishedContentRenderer     $studio        Optional exact published Blueprint rendering boundary.
     * @param  ?StudioPublishedEnhancementRuntime  $enhancements  Optional pinned enhancement runtime the page
     *         defers when its rendered blocks need it; null serves every composition script-free.
     *
     * @since  2.0.0
     */
    public function __construct(
        private PublicPageLocator $pages,
        private ContentPageRenderService $renderer,
        private ContentPresenter $presenter,
        private ContentLayoutCatalog $layouts,
        private TranslationGroupPresenter $languages,
        private ActiveLocale $active,
        private ?StudioPublishedContentRenderer $studio = null,
        private ?StudioPublishedEnhancementRuntime $enhancements = null,
    ) {
    }

    /**
     * Builds the front page for a request to `/`.
     *
     * The page is cached publicly for a minute with a five-minute stale-while-revalidate window, and
     * carries an `X-Robots-Tag` refusal whenever the site has search indexing switched off — which is
     * how a staging deployment stays out of search results without a separate configuration path.
     *
     * @param   ServerRequestInterface  $request  Incoming request; the front page takes no input from it.
     *
     * @return  ResponseInterface  A 200 HTML response rendered from the `page` template when a homepage
     *          entry is nominated, and from the `home` template otherwise.
     *
     * @throws  \InvalidArgumentException  When the stored presentation settings are not a valid contract.
     * @throws  \RuntimeException  When the asset manifest cannot be read or names no files for the site
     *          entry point.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $record = $this->pages->homepage();
        // The root is the one public entry point that names no language, so it is the one place a
        // reader's negotiated locale — not the URL — decides which locale of the item is served.
        $record = $record === null ? null : $this->languages->negotiate($record);
        $recordLocale = $record?->entry->locale();
        if ($recordLocale !== null) {
            $this->active->adoptLocale($recordLocale);
        }
        $binding = $record === null
            ? ['template' => null, 'color_scheme' => null]
            : $this->pages->presentationBindingFor($record);
        $studioResult = $record === null ? null : $this->studio?->render($record);
        $studioBody = $studioResult?->html;
        $studioEnhancement = $studioResult === null ? null : $this->enhancements?->locationFor($studioResult);
        $template = $record === null
            ? 'home'
            : ($studioBody === null ? $this->layouts->templateFor($record, $binding['template']) : 'page');
        $entry = $record === null
            ? null
            : ($studioBody === null
                ? $this->presenter->present($record)
                : [
                    'title' => $record->entry->title(),
                    'data' => [],
                    'body_html' => $studioBody,
                ]);
        $languages = $record === null
            ? ['alternates' => [], 'default_href' => null]
            : $this->languages->alternates($record, '/');

        $headers = [
            'Cache-Control' => 'public, max-age=60, stale-while-revalidate=300',
        ];
        if (!$this->renderer->searchIndexingEnabled()) {
            $headers['X-Robots-Tag'] = 'noindex, nofollow, noarchive';
        }
        if ($studioEnhancement?->origin() !== null) {
            $headers[SecurityHeadersMiddleware::SCRIPT_ORIGIN_HEADER] = $studioEnhancement->origin();
        }

        return new HtmlResponse($this->renderer->render(
            $template,
            $entry,
            '/',
            $this->canonicalUrl($languages),
            $binding['color_scheme'],
            'core.public.home',
            $this->pages->navigation(),
            $languages,
            true,
            $record === null || $studioResult === null
                ? null
                : StudioPublishedStylesheet::href($record, '/', $studioResult->css),
            $studioEnhancement,
        ), 200, $headers);
    }

    /**
     * Name the rendered locale as canonical when a translated item is served at the neutral root.
     *
     * The root itself cannot distinguish two language variants. Once the language view supplies distinct
     * explicit-choice URLs, the current one is also the canonical URL; otherwise every `hreflang` target
     * would declare `/` canonical and invite crawlers to consolidate all languages back into one page.
     * An untranslated or unconfigured homepage keeps `/` as its one canonical address.
     *
     * @param   array{
     *              alternates: list<array{
     *                  locale: string, label: string, href: string, direction: string, current: bool
     *              }>,
     *              default_href: ?string
     *          }  $languages  Language view built for the rendered homepage.
     *
     * @return  string  Explicit URL of the current locale, or `/` when there is no language choice.
     *
     * @since   2.0.0
     */
    private function canonicalUrl(array $languages): string
    {
        foreach ($languages['alternates'] as $alternate) {
            if ($alternate['current']) {
                return $alternate['href'];
            }
        }

        return '/';
    }
}
