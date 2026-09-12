<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Http\Middleware;

use Kumwe\App\Administrator\Http\AdministratorRequest;
use Kumwe\Localization\Application\ActiveLocale;
use Kumwe\Localization\Application\Translator;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Refuses an administrator submission that does not echo the CSRF token minted for its session.
 *
 * Every state-changing administrator route is piped through this before its handler, so no handler
 * checks the token itself and a newly added screen cannot ship without the guard. The token is taken
 * from an `X-CSRF-Token` header for the shell's scripted posts and from a `_csrf` field for plain form
 * posts, and is compared against the value `AdministratorSession` carries using a timing-safe
 * comparison. On success the flattened form replaces the parsed body, so the handler downstream reads
 * exactly the fields this middleware validated rather than parsing the body a second time.
 *
 * @since  2.0.0
 */
final class AdministratorCsrfMiddleware implements MiddlewareInterface
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
     * Legacy administrator handlers continue to receive the flattened parsed body, while handlers
     * with schema-authorized nested controls can explicitly consume and revalidate this exact object.
     *
     * @var    string
     * @since  2.0.0
     */
    public const ATTRIBUTE_PARSED_BODY = self::class . '.parsed_body';

    /**
     * Forward the request only when it presents the session's CSRF token, and answer 403 otherwise.
     *
     * A refusal renders a self-contained HTML page rather than a problem document, because the
     * submission came from a browser form and the operator needs a link back into the administrator.
     * An empty candidate is rejected before the comparison runs, so a session whose token was somehow
     * blank cannot be satisfied by omitting the field altogether.
     *
     * @param   ServerRequestInterface   $request  Administrator submission carrying the token in a header or field.
     * @param   RequestHandlerInterface  $handler  Next handler, reached with the flattened form as parsed body.
     *
     * @return  ResponseInterface  The handler's response, or a 403 `no-store` HTML page when the token is wrong.
     *
     * @throws  \InvalidArgumentException  When the route was mounted without the administrator session middleware.
     *
     * @since   2.0.0
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = AdministratorRequest::session($request);
        $parsed = AdministratorRequest::parsedBody($request);
        $form = AdministratorRequest::form($request);
        $provided = $request->getHeaderLine('X-CSRF-Token');

        if ($provided === '') {
            $provided = $form['_csrf'] ?? '';
        }

        if ($provided === '' || !hash_equals($session->csrfToken, $provided)) {
            $tag = $this->locale->locale()->toString();
            $title = $this->translator->translate('core.administrator.csrf.forbidden');

            return new HtmlResponse(
                sprintf(
                    '<!doctype html><html lang="%s" dir="%s"><head><meta charset="utf-8"><title>%s</title>'
                        . '</head><body><main><h1>%s</h1><p>%s</p><p><a href="/administrator">%s</a></p>'
                        . '</main></body></html>',
                    htmlspecialchars($tag, ENT_QUOTES),
                    $this->locale->locale()->direction()->value,
                    htmlspecialchars($title, ENT_QUOTES),
                    htmlspecialchars($title, ENT_QUOTES),
                    htmlspecialchars(
                        $this->translator->translate('core.administrator.csrf.token_invalid_or_expired'),
                        ENT_QUOTES,
                    ),
                    htmlspecialchars($this->translator->translate('core.administrator.csrf.return_link'), ENT_QUOTES),
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
