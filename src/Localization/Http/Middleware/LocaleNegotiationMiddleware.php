<?php

declare(strict_types=1);

namespace Kumwe\App\Localization\Http\Middleware;

use Kumwe\Localization\Application\ActiveLocale;
use Kumwe\Localization\Application\LocaleNegotiator;
use Kumwe\Localization\Application\TranslationScope;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Resolves the locale for one request and publishes it for everything downstream to render in.
 *
 * It publishes twice, deliberately, because two kinds of collaborator need it and they cannot both
 * be served the same way. A handler that already has the request reads the `kumwe.locale`
 * attribute, exactly as it reads `kumwe.request_id`. A Twig function or a presenter that receives
 * only its own arguments reads `ActiveLocale`, exactly as a log processor reads
 * `CorrelationContext`. Both are mechanisms this codebase already uses; neither is a new one.
 *
 * The holder is closed in a `finally`, so a long-lived process cannot carry one request's language
 * into the next one's output even when the request ends by throwing. It is piped early, before the
 * body limit and routing, because the error boundary renders text too and a problem document
 * produced for a rejected request should be in the caller's language rather than in the language
 * the last successful request happened to leave behind.
 *
 * @since  2.0.0
 */
final readonly class LocaleNegotiationMiddleware implements MiddlewareInterface
{
    /**
     * Request attribute the resolved locale tag is published under.
     *
     * @var    string
     * @since  2.0.0
     */
    public const ATTRIBUTE = 'kumwe.locale';

    /**
     * Query parameter through which a caller states an explicit locale choice.
     *
     * A parameter rather than a header, because an explicit choice has to survive being copied into
     * a link, shared, and bookmarked; a header does not. An unrecognised value is ignored rather
     * than refused, so a stale bookmark renders in the negotiated language instead of failing.
     *
     * @var    string
     * @since  2.0.0
     */
    public const QUERY_PARAMETER = 'locale';

    /**
     * Bind the middleware to negotiation, the request-scoped holder and the site being served.
     *
     * @param  LocaleNegotiator  $negotiator  Resolver consulted for every request.
     * @param  ActiveLocale      $active      Holder opened for the duration of the request.
     * @param  string            $site        Identifier of the site this kernel serves, which scopes
     *         the administered override layers.
     *
     * @since  2.0.0
     */
    public function __construct(
        private LocaleNegotiator $negotiator,
        private ActiveLocale $active,
        private string $site,
    ) {
    }

    /**
     * Negotiate, publish, delegate, and close the holder however the request ends.
     *
     * @param   ServerRequestInterface   $request  Request whose explicit choice and `Accept-Language`
     *          are consulted.
     * @param   RequestHandlerInterface  $handler  Next handler, called with the locale attribute set.
     *
     * @return  ResponseInterface  Downstream response, with language and cache variation metadata when it
     *          is localized HTML or a redirect; an explicit fallback language is preserved.
     *
     * @since   2.0.0
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $query = $request->getQueryParams();
        $explicit = $query[self::QUERY_PARAMETER] ?? null;
        $locale = $this->negotiator->negotiate(
            is_string($explicit) && $explicit !== '' ? $explicit : null,
            $request->getHeaderLine('Accept-Language'),
        );
        $this->active->begin($locale, new TranslationScope($this->site));

        try {
            $response = $handler->handle($request->withAttribute(self::ATTRIBUTE, $locale));
            if ($this->isLocalizedResponse($response)) {
                if (!$response->hasHeader('Content-Language')) {
                    $response = $response->withHeader('Content-Language', $this->active->locale()->toString());
                }
                if ($this->isPublic($response->getHeaderLine('Cache-Control'))) {
                    $response = $response->withHeader(
                        'Vary',
                        $this->vary($response->getHeaderLine('Vary'), 'Accept-Language'),
                    );
                }
            }

            return $response;
        } finally {
            $this->active->end();
        }
    }

    /**
     * Decide whether this boundary produced localized browser output.
     *
     * Machine JSON, media bytes, metrics and crawler directives do not become language variants merely
     * because locale negotiation runs outside their routes. HTML is localized by the shared renderers,
     * and a redirect carrying `Location` may have been selected by localized content resolution.
     *
     * @param   ResponseInterface  $response  Downstream response to classify.
     *
     * @return  bool  True for HTML or a redirect response, false for machine and binary representations.
     *
     * @since   2.0.0
     */
    private function isLocalizedResponse(ResponseInterface $response): bool
    {
        $mediaType = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'), 2)[0]));
        if ($mediaType === 'text/html') {
            return true;
        }

        $status = $response->getStatusCode();

        return $status >= 300 && $status < 400 && $response->hasHeader('Location');
    }

    /**
     * Add one field name to a response's comma-separated `Vary` value without duplicates.
     *
     * @param   string  $current  Existing header value, possibly empty.
     * @param   string  $name     Header field the representation additionally varies on.
     *
     * @return  string  Normalized list retaining existing field order and appending the new field once.
     *
     * @since   2.0.0
     */
    private function vary(string $current, string $name): string
    {
        $fields = array_values(array_filter(
            array_map(trim(...), explode(',', $current)),
            static fn (string $field): bool => $field !== '',
        ));
        foreach ($fields as $field) {
            if (strcasecmp($field, $name) === 0 || $field === '*') {
                return implode(', ', $fields);
            }
        }
        $fields[] = $name;

        return implode(', ', $fields);
    }

    /**
     * Decide whether `Cache-Control` explicitly carries the `public` directive.
     *
     * @param   string  $cacheControl  Combined response header value.
     *
     * @return  bool  True only for a complete public directive, not text merely containing that word.
     *
     * @since   2.0.0
     */
    private function isPublic(string $cacheControl): bool
    {
        foreach (explode(',', $cacheControl) as $directive) {
            if (strtolower(trim(explode('=', $directive, 2)[0])) === 'public') {
                return true;
            }
        }

        return false;
    }
}
