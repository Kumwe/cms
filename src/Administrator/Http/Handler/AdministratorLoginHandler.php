<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Http\Handler;

use Kumwe\App\Administrator\Http\AdministratorRequest;
use Kumwe\App\Administrator\Http\Middleware\AdministratorSessionMiddleware;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Identity\Application\Administration\AdministratorSessionStore;
use Kumwe\App\Identity\Application\Administration\AuthenticationThrottled;
use Kumwe\App\Http\Middleware\TrustedProxyMiddleware;
use Kumwe\Localization\Application\Translator;
use Kumwe\App\Http\Middleware\RequestIdMiddleware;
use InvalidArgumentException;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves the administrator sign-in screen and trades a correct credential for a session cookie.
 *
 * This is the one administrator route that runs without a session, so it is where the session is
 * created rather than read: it authenticates the credential, issues the execution context the rest of
 * the administrator authorises against, and hands the browser the opaque token that
 * `AdministratorSessionMiddleware` will exchange for that session on every later request. Failure
 * re-renders the form instead of redirecting — a wrong password as 401, a throttled address as 429 —
 * so the reason and the typed email survive, and neither outcome is ever cached.
 *
 * @since  2.0.0
 */
final readonly class AdministratorLoginHandler implements RequestHandlerInterface
{
    /**
     * Wire the sign-in screen to the identity gateway, the session store and the cookie policy.
     *
     * @param  AdministratorIdentityGateway  $identities       Verifies the credential and applies throttling.
     * @param  AdministratorSessionStore     $sessions         Creates the stored session the cookie points at.
     * @param  AdministratorRenderer         $renderer         Renders the `login` template.
     * @param  Translator                    $translator       Resolves the rejection wording for the locale
     *         in flight.
     * @param  bool                          $secureCookie     Whether the cookie carries `Secure`; true when the
     *         configured base URL is served over HTTPS.
     * @param  int                           $sessionLifetime  Cookie `Max-Age` in seconds, matching the stored
     *         session's own lifetime.
     * @param  ?SiteContext                  $site             Site the session is issued for; null signs the
     *         operator in against the default site.
     *
     * @since  2.0.0
     */
    public function __construct(
        private AdministratorIdentityGateway $identities,
        private AdministratorSessionStore $sessions,
        private AdministratorRenderer $renderer,
        private Translator $translator,
        private bool $secureCookie,
        private int $sessionLifetime,
        private ?SiteContext $site = null,
    ) {
    }

    /**
     * Render the sign-in form, or exchange a posted credential for a session cookie.
     *
     * The address the attempt is throttled against is read from the attribute `TrustedProxyMiddleware`
     * publishes and never from a header here, so a forwarded header cannot be used to escape the
     * throttle; an unusable value degrades to `unknown` rather than skipping the count. A wrong
     * credential and a throttled one both re-render the form, at 401 and 429, and the rejection message
     * deliberately does not say which of the two fields was wrong. A credential that is valid but whose
     * identity may not hold an administrator session re-renders it at 403, because signing in again
     * would not change that decision. Success binds the new session to the pipeline's request identifier
     * — or to a freshly generated one when the pipeline published none — so the sign-in can be
     * correlated in the audit trail.
     *
     * @param   ServerRequestInterface  $request  Sign-in request; `GET` renders the form, `POST` submits it.
     *
     * @return  ResponseInterface  The rendered form, at 200, 401, 403 or 429, or a 303 to
     *          `/administrator` carrying the session cookie.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) === 'GET') {
            return $this->form();
        }

        $form = AdministratorRequest::form($request);
        $remoteAddress = $request->getAttribute(TrustedProxyMiddleware::ATTRIBUTE_CLIENT_ADDRESS, 'unknown');

        if (!is_string($remoteAddress) || $remoteAddress === '') {
            $remoteAddress = 'unknown';
        }

        try {
            $principal = $this->identities->authenticate(
                $form['email'] ?? '',
                $form['password'] ?? '',
                $remoteAddress,
            );
        } catch (InvalidArgumentException) {
            // A submission whose address is not an address at all — empty, or longer than an address
            // can be — is a wrong credential, not a defect: it is answered on the form exactly as a
            // wrong password is, rather than escaping as a 500.
            $principal = null;
        } catch (AuthenticationThrottled) {
            return new HtmlResponse($this->renderer->render('login', [
                'error' => $this->translator->translate('core.security.authentication.throttled'),
                'email' => $form['email'] ?? '',
            ]), 429, ['Cache-Control' => 'no-store', 'Retry-After' => '900']);
        }

        if ($principal === null) {
            return new HtmlResponse($this->renderer->render('login', [
                'error' => $this->translator->translate('core.administrator.login.invalid_credentials'),
                'email' => $form['email'] ?? '',
            ]), 401, ['Cache-Control' => 'no-store']);
        }

        $requestId = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);

        // The store's contract says opening a session may be denied, and this route is exempt from the
        // middleware that renders the themed denial: both the session and the authorization middleware
        // return early for `/administrator/login`. Letting the refusal escape produced a bare
        // `application/problem+json` document, which Firefox will not render as a page at all -- the
        // visitor was handed the browser's own "there's a problem with this site" screen. It is answered
        // here, on the form, exactly as the wrong-credential and throttled arms already are.
        try {
            $created = $this->sessions->create($principal->context(
                $this->site ?? SiteContext::default(),
                AuthenticationStrength::Password,
                is_string($requestId) && $requestId !== '' ? $requestId : 'login-' . bin2hex(random_bytes(16)),
            ), $request->getHeaderLine('User-Agent'));
        } catch (AuthorizationDenied) {
            return new HtmlResponse($this->renderer->render('login', [
                'error' => $this->translator->translate('core.administrator.login.access_denied'),
                'email' => $form['email'] ?? '',
            ]), 403, ['Cache-Control' => 'no-store']);
        }

        return new RedirectResponse('/administrator', 303, [
            'Cache-Control' => 'no-store',
            'Set-Cookie' => $this->cookie($created->token),
        ]);
    }

    /**
     * Render the empty sign-in form for a `GET`.
     *
     * @return  ResponseInterface  The form at 200, marked `no-store` so a shared browser cannot go back to it.
     *
     * @since   2.0.0
     */
    private function form(): ResponseInterface
    {
        return new HtmlResponse(
            $this->renderer->render('login'),
            200,
            ['Cache-Control' => 'no-store'],
        );
    }

    /**
     * Build the `Set-Cookie` value that carries the new session token to the browser.
     *
     * The cookie is scoped to `/administrator` so it is never sent with a public page request, and it
     * is `HttpOnly` and `SameSite=Strict` because nothing but the administrator pipeline may present
     * it. `AdministratorLogoutHandler` must repeat these attributes for the browser to accept its
     * expiring replacement.
     *
     * @param   string  $token  Opaque session token the browser will send back on each request.
     *
     * @return  string  Header value, gaining `Secure` when the installation is configured for HTTPS.
     *
     * @since   2.0.0
     */
    private function cookie(string $token): string
    {
        return sprintf(
            '%s=%s; Path=/administrator; Max-Age=%d; HttpOnly; SameSite=Strict%s',
            AdministratorSessionMiddleware::COOKIE_NAME,
            $token,
            $this->sessionLifetime,
            $this->secureCookie ? '; Secure' : '',
        );
    }
}
