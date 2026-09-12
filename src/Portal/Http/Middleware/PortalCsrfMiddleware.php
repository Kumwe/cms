<?php

declare(strict_types=1);

namespace Kumwe\App\Portal\Http\Middleware;

use Kumwe\Localization\Application\ActiveLocale;
use Kumwe\Localization\Application\Translator;
use Kumwe\App\Portal\Http\PortalRequest;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Independent synchronizer-token guard for portal mutations.
 *
 * @since  2.0.0
 */
final class PortalCsrfMiddleware implements MiddlewareInterface
{
    /**
     * Bind the guard to the translator and locale holder its refusal page renders with.
     *
     * @param  Translator    $translator  Resolves the refusal wording for the locale in flight.
     * @param  ActiveLocale  $locale      Names the language and direction the refusal page declares.
     *
     * @since  2.0.0
     */
    public function __construct(
        private readonly Translator $translator,
        private readonly ActiveLocale $locale,
    ) {
    }
    /**
     * Request attribute carrying the original parsed body after successful CSRF validation.
     *
     * @var    string
     * @since  2.0.0
     */
    public const ATTRIBUTE_PARSED_BODY = self::class . '.parsed_body';

    /**
     * Compare the portal session token in constant time before forwarding a flattened form.
     *
     * @param   ServerRequestInterface   $request  Portal mutation.
     * @param   RequestHandlerInterface  $handler  Downstream handler.
     *
     * @return  ResponseInterface  Downstream response or a no-store 403 page.
     *
     * @since   2.0.0
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = PortalRequest::session($request);
        $parsed = $request->getParsedBody();
        if (!is_array($parsed)) {
            $parsed = [];
            parse_str((string) $request->getBody(), $parsed);
        }
        $form = PortalRequest::form($request);
        $provided = $request->getHeaderLine('X-CSRF-Token');
        if ($provided === '') {
            $provided = $form['_csrf'] ?? '';
        }
        if ($provided === '' || !hash_equals($session->csrfToken, $provided)) {
            $tag = $this->locale->locale()->toString();
            $title = $this->translator->translate('core.portal.csrf.forbidden');

            return new HtmlResponse(
                sprintf(
                    '<!doctype html><html lang="%s" dir="%s"><head><meta charset="utf-8"><title>%s</title>'
                        . '</head><body><main><h1>%s</h1><p>%s</p><p><a href="/portal">%s</a></p>'
                        . '</main></body></html>',
                    htmlspecialchars($tag, ENT_QUOTES),
                    $this->locale->locale()->direction()->value,
                    htmlspecialchars($title, ENT_QUOTES),
                    htmlspecialchars($title, ENT_QUOTES),
                    htmlspecialchars(
                        $this->translator->translate('core.portal.csrf.token_invalid_or_expired'),
                        ENT_QUOTES,
                    ),
                    htmlspecialchars($this->translator->translate('core.portal.csrf.return_link'), ENT_QUOTES),
                ),
                403,
                ['Cache-Control' => 'no-store', 'Content-Language' => $tag],
            );
        }

        return $handler->handle(
            $request
                ->withParsedBody($form)
                ->withAttribute(self::ATTRIBUTE_PARSED_BODY, $parsed),
        );
    }
}
