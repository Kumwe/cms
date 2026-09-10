<?php

declare(strict_types=1);

namespace Kumwe\App\Http\Handler;

use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Presentation\TranslationGroupPresenter;
use Kumwe\App\Site\Application\PublicPageLocator;
use Kumwe\App\Studio\Application\Composition\StudioPublishedContentRenderer;
use Kumwe\App\Studio\Application\Composition\StudioPublishedStylesheet;
use Kumwe\Context\Value\SiteContext;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\TextResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Revalidates and serves the exact Producer CSS of one currently published composition.
 *
 * Digest URLs are cache identities, never bearer capabilities. Every request repeats public record,
 * site, locale, canonical-path, artifact and live renderer checks before bytes or a 304 can leave the
 * process, so withdrawal and distrust fail closed even when a browser holds an older URL.
 *
 * @since  2.0.0
 */
final readonly class StudioPublishedStylesheetHandler implements RequestHandlerInterface
{
    /**
     * Bind stylesheet revalidation to the same public authorities the page handler uses.
     *
     * @param  PublicPageLocator               $pages      Canonical public page and homepage locator.
     * @param  TranslationGroupPresenter       $languages  Locale negotiation for the language-neutral root.
     * @param  StudioPublishedContentRenderer  $studio     Live published-composition renderer.
     * @param  SiteContext                     $site       Site whose published stylesheets may be served.
     *
     * @since  2.0.0
     */
    public function __construct(
        private PublicPageLocator $pages,
        private TranslationGroupPresenter $languages,
        private StudioPublishedContentRenderer $studio,
        private SiteContext $site,
    ) {
    }

    /**
     * Serve one published composition's CSS only after every publication check repeats.
     *
     * @param   ServerRequestInterface  $request  Public stylesheet request carrying digest and coordinates.
     *
     * @return  ResponseInterface  Exact CSS bytes, a 304 revalidation, or one uncacheable miss.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $digest = $request->getAttribute('digest');
        $query = $request->getQueryParams();
        $keys = array_keys($query);
        sort($keys, SORT_STRING);
        if (
            !is_string($digest)
            || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
            || $keys !== ['entry', 'locale', 'page']
            || !is_string($query['entry'] ?? null)
            || !is_string($query['locale'] ?? null)
            || !is_string($query['page'] ?? null)
        ) {
            return self::unavailable();
        }
        $entryId = $query['entry'];
        $locale = $query['locale'];
        $pagePath = $query['page'];
        if (
            $entryId === ''
            || strlen($entryId) > 240
            || $locale === ''
            || strlen($locale) > 63
            || $pagePath === ''
            || strlen($pagePath) > 2048
            || $pagePath[0] !== '/'
            || str_contains($pagePath, "\0")
            || str_contains($pagePath, '?')
            || str_contains($pagePath, '#')
        ) {
            return self::unavailable();
        }

        try {
            $record = $pagePath === '/'
                ? $this->homepage()
                : $this->pages->byPath($pagePath);
            if (!$record instanceof ContentRecord || !$this->matches($record, $pagePath, $entryId, $locale)) {
                return self::unavailable();
            }
            $result = $this->studio->render($record);
            if ($result === null || !hash_equals($digest, StudioPublishedStylesheet::digest($result->css))) {
                return self::unavailable();
            }
        } catch (Throwable) {
            return self::unavailable();
        }

        $headers = [
            'Cache-Control' => 'public, no-cache, must-revalidate',
            'Content-Type' => 'text/css; charset=utf-8',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'ETag' => '"' . $digest . '"',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if (hash_equals('"' . $digest . '"', $request->getHeaderLine('If-None-Match'))) {
            return new EmptyResponse(304, $headers);
        }

        return new TextResponse($result->css, 200, $headers);
    }

    /**
     * Resolve the language-neutral root through the same locale selection as the page handler.
     *
     * @return  ?ContentRecord  Negotiated homepage record, or null when none is published.
     *
     * @since   2.0.0
     */
    private function homepage(): ?ContentRecord
    {
        $record = $this->pages->homepage();

        return $record === null ? null : $this->languages->negotiate($record);
    }

    /**
     * Require exact current route, entry, site and locale coordinates.
     *
     * @param   ContentRecord  $record    Public record resolved for the requested path.
     * @param   string         $pagePath  Requested canonical page path.
     * @param   string         $entryId   Requested Content entry identity.
     * @param   string         $locale    Requested entry locale, `und` for language-neutral.
     *
     * @return  bool  True only when every requested coordinate matches the live record.
     *
     * @since   2.0.0
     */
    private function matches(ContentRecord $record, string $pagePath, string $entryId, string $locale): bool
    {
        return hash_equals($this->site->identifier(), $record->siteIdentifier)
            && hash_equals($entryId, $record->entry->id())
            && hash_equals($locale, $record->entry->locale()?->toString() ?? 'und')
            && ($pagePath === '/' || hash_equals($pagePath, $this->pages->pathFor($record)));
    }

    /**
     * Collapse malformed, withdrawn and unauthorized stylesheets into one uncacheable miss.
     *
     * @return  EmptyResponse  Uncacheable not-found response.
     *
     * @since   2.0.0
     */
    private static function unavailable(): EmptyResponse
    {
        return new EmptyResponse(404, ['Cache-Control' => 'private, no-store']);
    }
}
