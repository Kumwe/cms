<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Http\Handler;

use InvalidArgumentException;
use Kumwe\App\Administrator\Http\AdministratorRequest;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\Localization\Application\MessageFormattingFailed;
use Kumwe\Localization\Application\MessageOverrideRecord;
use Kumwe\App\Localization\Application\MessageOverrideService;
use Kumwe\Localization\Application\SupportedLocales;
use Kumwe\Localization\Domain\InvalidMessageIdentifier;
use Kumwe\Localization\Domain\MessageCatalogueLayer;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves the screen an operator changes interface wording on, without a file edit or a deployment.
 *
 * This is the administered face of the override chain's two upper layers. It answers three questions
 * an operator actually has: what does this installation currently say, which of those words has this
 * site or organization already changed, and what does the changed wording read as. Searching the
 * shipped catalogue is part of the screen rather than a separate tool, because an operator relabelling
 * "Client" as "Patient" has to find the message before they can change it, and hundreds of identifiers
 * is not something anybody browses.
 *
 * The screen is one path carrying both a read and two writes, so the handler dispatches on an `action`
 * field rather than splitting into endpoints. A write answers 303 back to the screen, so a refresh
 * cannot repost it; a refused value re-renders at 422 with the message, so the operator keeps their
 * place and learns what was wrong.
 *
 * @since  2.0.0
 */
final readonly class AdministratorWordingHandler implements RequestHandlerInterface
{
    /**
     * Wire the screen to the wording service and to the locales this installation carries.
     *
     * @param  MessageOverrideService  $overrides  Validates, authorizes and records every wording change.
     * @param  SupportedLocales        $supported  Supplies the locales the language picker offers.
     * @param  AdministratorRenderer   $renderer   Renders the `wording` template.
     *
     * @since  2.0.0
     */
    public function __construct(
        private MessageOverrideService $overrides,
        private SupportedLocales $supported,
        private AdministratorRenderer $renderer,
    ) {
    }

    /**
     * Render the wording screen, or apply one posted change and redirect back to it.
     *
     * Form-shape, identifier and ICU-pattern refusals are caught. A refused authorization is deliberately
     * left to propagate, because an actor without `localization.overrides.manage` is not making a form
     * mistake and must not be shown one.
     *
     * @param   ServerRequestInterface  $request  Administrator request; the method decides render or write.
     *
     * @return  ResponseInterface  The rendered screen, the same screen at 422 when a value was refused, or a
     *          303 redirect back to it after a successful change.
     *
     * @throws  InvalidArgumentException  When the request carries no administrator session or execution
     *          context, or the posted action is unknown.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the capability is refused.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return $this->render($request);
        }

        $form = AdministratorRequest::form($request);
        try {
            $locale = $this->locale($form['locale'] ?? null, true);
            $layer = $this->layer($form['layer'] ?? null, true);
            match (AdministratorRequest::required($form, 'action')) {
                'override.save' => $this->overrides->override(
                    AdministratorRequest::context($request),
                    $layer,
                    $locale,
                    AdministratorRequest::required($form, 'identifier'),
                    AdministratorRequest::required($form, 'pattern'),
                ),
                'override.withdraw' => $this->overrides->withdraw(
                    AdministratorRequest::context($request),
                    $layer,
                    $locale,
                    AdministratorRequest::required($form, 'identifier'),
                ),
                default => throw new InvalidArgumentException('The wording action is not supported.'),
            };
        } catch (InvalidMessageIdentifier | InvalidArgumentException | MessageFormattingFailed $refused) {
            return $this->render($request, 422, $refused->getMessage());
        }

        return new RedirectResponse(sprintf(
            '/administrator/wording?locale=%s&layer=%s&saved=1',
            rawurlencode($locale),
            rawurlencode($layer->value),
        ), 303);
    }

    /**
     * Build the screen for one locale and one administered layer.
     *
     * The catalogue search is bounded and is only run when the operator asked for one, so opening the
     * screen costs one override read rather than a walk of every shipped message.
     *
     * @param   ServerRequestInterface  $request  Request carrying the session and the execution context.
     * @param   int                     $status   Status to answer with; 422 when re-rendering a refusal.
     * @param   ?string                 $error    Message to show above the form, or null on a clean render.
     *
     * @return  ResponseInterface  The rendered screen, marked `no-store` because it carries the CSRF token.
     *
     * @throws  InvalidArgumentException  When the request carries no administrator session or execution
     *          context, or the requested locale is not carried.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the capability is refused.
     *
     * @since   2.0.0
     */
    private function render(
        ServerRequestInterface $request,
        int $status = 200,
        ?string $error = null,
    ): ResponseInterface {
        $session = AdministratorRequest::session($request);
        $context = AdministratorRequest::context($request);
        $query = $request->getQueryParams();
        $locale = $this->locale(is_string($query['locale'] ?? null) ? $query['locale'] : null);
        $layer = $this->layer(is_string($query['layer'] ?? null) ? $query['layer'] : null);
        $term = is_string($query['q'] ?? null) ? $query['q'] : '';

        return new HtmlResponse($this->renderer->render('wording', [
            'csrf' => $session->csrfToken,
            'capabilities' => AdministratorRequest::capabilityMap($request),
            'locale' => $locale,
            'layer' => $layer->value,
            'locales' => $this->supported->tags(),
            'organization' => $context->organization()?->identifier(),
            'overrides' => array_map(
                static fn (MessageOverrideRecord $record): array => $record->toArray(),
                $this->overrides->overrides($context, $layer, $locale),
            ),
            'search_term' => $term,
            'matches' => $term === '' ? [] : $this->overrides->searchCatalogue($context, $locale, $term),
            'saved' => ($query['saved'] ?? null) === '1',
            'error' => $error,
        ]), $status, ['Cache-Control' => 'no-store']);
    }

    /**
     * Resolve the locale the screen is working in, defaulting to the source language.
     *
     * @param   mixed  $value   Locale tag as the query or the form spelled it.
     * @param   bool   $strict  Whether an absent or unsupported value is a refused form submission.
     *
     * @return  string  A canonical tag this installation carries.
     *
     * @throws  InvalidArgumentException  When strict parsing receives no carried locale.
     *
     * @since   2.0.0
     */
    private function locale(mixed $value, bool $strict = false): string
    {
        if (!is_string($value) || $value === '') {
            if ($strict) {
                throw new InvalidArgumentException('A carried locale is required.');
            }

            return $this->supported->source()->toString();
        }
        foreach ($this->supported->all() as $carried) {
            if (strcasecmp($carried->toString(), str_replace('_', '-', trim($value))) === 0) {
                return $carried->toString();
            }
        }

        if ($strict) {
            throw new InvalidArgumentException('This installation does not carry that locale.');
        }

        return $this->supported->source()->toString();
    }

    /**
     * Resolve which administered layer the screen is working in, defaulting to the site.
     *
     * @param   mixed  $value   Layer as the query or the form spelled it.
     * @param   bool   $strict  Whether an absent or unsupported value is a refused form submission.
     *
     * @return  MessageCatalogueLayer  `Site` or `Organization`; a non-strict invalid value falls back to `Site`.
     *
     * @throws  InvalidArgumentException  When strict parsing receives no administered layer.
     *
     * @since   2.0.0
     */
    private function layer(mixed $value, bool $strict = false): MessageCatalogueLayer
    {
        $layer = is_string($value) ? MessageCatalogueLayer::tryFrom($value) : null;
        if ($layer === MessageCatalogueLayer::Site || $layer === MessageCatalogueLayer::Organization) {
            return $layer;
        }
        if ($strict) {
            throw new InvalidArgumentException('An administered wording layer is required.');
        }

        return MessageCatalogueLayer::Site;
    }
}
