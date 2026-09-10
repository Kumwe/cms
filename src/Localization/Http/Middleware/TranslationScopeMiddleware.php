<?php

declare(strict_types=1);

namespace Kumwe\App\Localization\Http\Middleware;

use Kumwe\App\Application\Authorization\ExecutionContextAttribute;
use Kumwe\App\Localization\Application\ActiveLocale;
use Kumwe\App\Localization\Application\TranslationScope;
use Kumwe\Context\Value\ExecutionContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Enriches the negotiated translation scope from the authenticated execution context.
 *
 * Locale negotiation runs before the error boundary so even rejected requests render consistently, but
 * organization membership is trustworthy only after the administrator, portal or bearer middleware has
 * authenticated the caller. This middleware sits after those boundaries and changes only the override
 * scope: the locale already negotiated remains the locale downstream templates and formatters read.
 *
 * @since  2.0.0
 */
final readonly class TranslationScopeMiddleware implements MiddlewareInterface
{
    /**
     * Bind scope enrichment to the request-local locale holder.
     *
     * @param  ActiveLocale  $active  Holder whose site and organization scope is enriched.
     *
     * @since  2.0.0
     */
    public function __construct(private ActiveLocale $active)
    {
    }

    /**
     * Apply authenticated site and organization scope, then continue dispatch.
     *
     * Public requests carry no execution context and retain the site scope negotiation opened. An
     * authenticated context is the single authority for both identifiers; no query, cookie or route
     * parameter is consulted here.
     *
     * @param   ServerRequestInterface   $request  Request after authentication middleware has run.
     * @param   RequestHandlerInterface  $handler  Dispatcher or later middleware.
     *
     * @return  ResponseInterface  Downstream response unchanged.
     *
     * @since   2.0.0
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $context = $request->getAttribute(ExecutionContextAttribute::NAME);
        if ($context instanceof ExecutionContext) {
            $this->active->adoptScope(new TranslationScope(
                $context->site()->identifier(),
                $context->organization()?->identifier(),
            ));
        }

        return $handler->handle($request);
    }
}
