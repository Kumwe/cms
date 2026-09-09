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
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves published content at whatever public path the site's navigation gives it.
 *
 * This is the catch-all of the public site: it takes the requested path, asks `PublicPageLocator` which
 * published record — if any — lives there, and renders it. Because one record is reachable by several
 * paths (its slug, its position in a menu, `/pages/{slug}`, or `/` when it is the homepage), the handler
 * also owns canonicalisation. A request that arrives on a non-canonical path is answered with a
 * permanent 308 to the canonical one, query string intact, so links, caches, and search results converge
 * on a single URL per page rather than splitting across duplicates.
 *
 * Missing, unpublished, and reserved paths all produce the same minimal HTML 404, which keeps the
 * existence of draft, scheduled, or trashed content out of the public response.
 *
 * @since  2.0.0
 */
final readonly class PublishedContentHandler implements RequestHandlerInterface
{
    /**
     * Bind the public content route to the locator, settings, and rendering collaborators it composes.
     *
     * @param  PublicPageLocator                $pages      Resolver that maps a request path to a published record and
     *         reports that record's canonical path.
     * @param  ContentPageRenderService         $renderer   Canonical site template/theme path shared with preview.
     * @param  ContentPresenter                 $presenter  Presenter that escapes and renders the record's stored
     *         bodies before they reach a template.
     * @param  ContentLayoutCatalog             $layouts    Content-type to site-template layout selection.
     * @param  TranslationGroupPresenter        $languages  Builder of the page's alternate-language links and
     *         the language selector, from the translation group the rendered entry belongs to.
     * @param  ActiveLocale                     $active     Request locale holder aligned to a locale-bearing record
     *         before its template and translated chrome are rendered.
     * @param  ?StudioPublishedContentRenderer  $studio     Optional exact published Blueprint rendering boundary.
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
     * Resolves the request path to a published record and renders it, redirects to it, or refuses it.
     *
     * Canonicalisation is settled before anything is rendered, so a page is only ever built on its
     * canonical path and the `current_path` and `canonical_url` view variables can never disagree.
     * As on the front page, an `X-Robots-Tag` refusal is added whenever site indexing is switched off.
     *
     * @param   ServerRequestInterface  $request  Request whose URI path selects the record, and whose
     *          query string is carried across a canonical redirect.
     *
     * @return  ResponseInterface  A 200 HTML page when the path is already canonical, a 308 redirect to
     *          the canonical path when it is not, or an uncacheable 404 page when nothing is published
     *          there.
     *
     * @throws  \InvalidArgumentException  When the stored presentation settings are not a valid contract.
     * @throws  \RuntimeException  When the asset manifest cannot be read or names no files for the site
     *          entry point.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $requestedPath = $request->getUri()->getPath();
        $record = $this->pages->byPath($requestedPath);

        if ($record === null) {
            return $this->notFound();
        }

        $recordLocale = $record->entry->locale();
        if ($recordLocale !== null) {
            $this->active->adoptLocale($recordLocale);
        }

        $canonicalPath = $this->pages->pathFor($record);
        if ($requestedPath !== $canonicalPath) {
            $query = $request->getUri()->getQuery();

            return new RedirectResponse(
                $canonicalPath . ($query === '' ? '' : '?' . $query),
                308,
                ['Cache-Control' => 'public, max-age=300'],
            );
        }

        $headers = ['Cache-Control' => 'public, max-age=60, stale-while-revalidate=300'];
        if (!$this->renderer->searchIndexingEnabled()) {
            $headers['X-Robots-Tag'] = 'noindex, nofollow, noarchive';
        }
        $binding = $this->pages->presentationBindingFor($record);
        $studioResult = $this->studio?->render($record);
        $studioBody = $studioResult?->html;
        $studioEnhancement = $studioResult === null ? null : $this->enhancements?->locationFor($studioResult);
        if ($studioEnhancement?->origin() !== null) {
            $headers[SecurityHeadersMiddleware::SCRIPT_ORIGIN_HEADER] = $studioEnhancement->origin();
        }
        $template = $studioBody === null
            ? $this->layouts->templateFor($record, $binding['template'])
            : 'page';
        $entry = $studioBody === null
            ? $this->presenter->present($record)
            : [
                'title' => $record->entry->title(),
                'data' => [],
                'body_html' => $studioBody,
            ];

        return new HtmlResponse(
            $this->renderer->render(
                $template,
                $entry,
                $canonicalPath,
                $canonicalPath,
                $binding['color_scheme'],
                'core.public.page',
                $this->pages->navigation(),
                $this->languages->alternates($record, $canonicalPath),
                true,
                $studioResult === null
                    ? null
                    : StudioPublishedStylesheet::href($record, $canonicalPath, $studioResult->css),
                $studioEnhancement,
            ),
            200,
            $headers,
        );
    }

    /**
     * Builds the uncacheable miss page returned for any path that resolves to nothing published.
     *
     * The markup is inline rather than rendered through Twig so that a miss cannot itself fail on
     * template or theme resolution, and it is deliberately identical for missing, unpublished, and
     * reserved paths so the response never distinguishes between them.
     *
     * @return  ResponseInterface  A 404 HTML response marked `no-store`.
     *
     * @since   2.0.0
     */
    private function notFound(): ResponseInterface
    {
        return new HtmlResponse(
            '<!doctype html><html lang="en-GB"><head><meta charset="utf-8"><title>Not found</title></head>'
            . '<body><main><h1>Page not found</h1></main></body></html>',
            404,
            ['Cache-Control' => 'no-store', 'Content-Language' => 'en-GB'],
        );
    }
}
