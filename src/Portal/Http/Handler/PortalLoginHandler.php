<?php

declare(strict_types=1);

namespace Kumwe\App\Portal\Http\Handler;

use Kumwe\App\Http\Middleware\TrustedProxyMiddleware;
use Kumwe\App\Identity\Application\Administration\AuthenticationThrottled;
use Kumwe\Localization\Application\Translator;
use Kumwe\App\Portal\Application\PortalAuthenticator;
use Kumwe\App\Portal\Application\PortalContextResolver;
use Kumwe\App\Portal\Application\PortalPasswordIdentity;
use Kumwe\App\Portal\Application\PortalSessionStore;
use Kumwe\App\Portal\Application\PortalContext;
use Kumwe\App\Portal\Http\Middleware\PortalSessionMiddleware;
use Kumwe\App\Portal\Http\PortalRequest;
use Kumwe\App\Portal\Presentation\PortalRenderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Portal-only password sign-in and membership-selection handler.
 *
 * @since  2.0.0
 */
final readonly class PortalLoginHandler implements RequestHandlerInterface
{
    /**
     * Message identifier of the non-enumerating response shared by credentials and unavailable
     * membership selections.
     *
     * @var    string
     * @since  2.0.0
     */
    private const SIGN_IN_REJECTED = 'core.portal.login.sign_in_rejected';

    /**
     * Host-only double-submit cookie used before a portal session exists.
     *
     * @var    string
     * @since  2.0.0
     */
    public const LOGIN_CSRF_COOKIE_NAME = 'kumwe_portal_login_csrf';

    /**
     * Short lifetime of one pre-authentication login token.
     *
     * @var    int
     * @since  2.0.0
     */
    private const LOGIN_CSRF_SECONDS = 600;

    /**
     * Bind login to shared identities, server membership resolution, portal storage, and cookie policy.
     *
     * @param   PortalAuthenticator    $authenticator    Password authentication and throttling port.
     * @param   PortalContextResolver  $contexts         Server-owned membership selection authority.
     * @param   PortalSessionStore     $sessions         Dedicated portal session store.
     * @param   PortalRenderer         $renderer         Distinct portal shell renderer.
     * @param   Translator             $translator       Resolves rejection wording for the locale in flight.
     * @param   bool                   $secureCookie     Whether HTTPS requires the `Secure` attribute.
     * @param   int                    $sessionLifetime  Cookie `Max-Age`, matching stored lifetime.
     *
     * @throws  \InvalidArgumentException  When cookie lifetime is outside 5 minutes through 7 days.
     *
     * @since   2.0.0
     */
    public function __construct(
        private PortalAuthenticator $authenticator,
        private PortalContextResolver $contexts,
        private PortalSessionStore $sessions,
        private PortalRenderer $renderer,
        private Translator $translator,
        private bool $secureCookie,
        private int $sessionLifetime,
    ) {
        if ($sessionLifetime < 300 || $sessionLifetime > 604_800) {
            throw new \InvalidArgumentException('The portal cookie lifetime is invalid.');
        }
    }

    /**
     * Render login or exchange a valid password and live membership for `kumwe_portal` only.
     *
     * @param   ServerRequestInterface  $request  GET or submitted login request.
     *
     * @return  ResponseInterface  No-store form/error or 303 portal redirect carrying its scoped cookie.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) === 'GET') {
            return $this->form();
        }
        $form = PortalRequest::form($request);
        if (!$this->validLoginCsrf($request, $form)) {
            return $this->error(
                $this->translator->translate('core.portal.login.security_token_invalid'),
                $form['email'] ?? '',
                403,
            );
        }
        $source = $request->getAttribute(TrustedProxyMiddleware::ATTRIBUTE_CLIENT_ADDRESS, 'unknown');
        if (!is_string($source) || $source === '') {
            $source = 'unknown';
        }
        try {
            $identity = $this->authenticator->authenticate(
                $form['email'] ?? '',
                $form['password'] ?? '',
                $source,
            );
        } catch (AuthenticationThrottled) {
            return $this->error(
                $this->translator->translate('core.security.authentication.throttled'),
                $form['email'] ?? '',
                429,
                ['Retry-After' => '900'],
            );
        }
        if (!$identity instanceof PortalPasswordIdentity) {
            return $this->error($this->translator->translate(self::SIGN_IN_REJECTED), $form['email'] ?? '', 401);
        }
        $hint = trim($form['workspace'] ?? '');
        if ($hint !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D', $hint) !== 1) {
            return $this->error($this->translator->translate(self::SIGN_IN_REJECTED), $form['email'] ?? '', 401);
        }
        $context = $this->contexts->resolve($identity->principal, $hint === '' ? null : $hint);
        if (!$context instanceof PortalContext) {
            return $this->error($this->translator->translate(self::SIGN_IN_REJECTED), $form['email'] ?? '', 401);
        }
        $created = $this->sessions->create($identity, $context, $request->getHeaderLine('User-Agent'));

        return new RedirectResponse('/portal', 303, [
            'Cache-Control' => 'no-store',
            'Set-Cookie' => [
                $this->cookie($created->cookieToken),
                $this->clearLoginCsrfCookie(),
            ],
        ]);
    }

    /**
     * Render the empty no-store sign-in form.
     *
     * @return  ResponseInterface  Portal login response.
     *
     * @since   2.0.0
     */
    private function form(): ResponseInterface
    {
        $csrf = self::token(32);
        return new HtmlResponse($this->renderer->render('login', [
            'error' => '',
            'email' => '',
            'login_csrf' => $csrf,
        ]), 200, [
            'Cache-Control' => 'no-store',
            'Set-Cookie' => $this->loginCsrfCookie($csrf),
        ]);
    }

    /**
     * Render a safe sign-in error while preserving only the submitted email.
     *
     * @param   string                                         $message  Operator-facing safe error.
     * @param   string                                         $email    Submitted email.
     * @param   int                                            $status   HTTP status.
     * @param   array<non-empty-string, array<string>|string>  $headers  Additional headers.
     *
     * @return  ResponseInterface  No-store error form.
     *
     * @since   2.0.0
     */
    private function error(string $message, string $email, int $status, array $headers = []): ResponseInterface
    {
        $csrf = self::token(32);
        return new HtmlResponse(
            $this->renderer->render('login', [
                'error' => $message,
                'email' => $email,
                'login_csrf' => $csrf,
            ]),
            $status,
            ['Cache-Control' => 'no-store', 'Set-Cookie' => $this->loginCsrfCookie($csrf)] + $headers,
        );
    }

    /**
     * Compare the submitted login token with its host-only pre-authentication cookie.
     *
     * @param   ServerRequestInterface  $request  Login request carrying the double-submit cookie.
     * @param   array<string, string>   $form     Parsed login form carrying the reflected token.
     *
     * @return  bool  True only for two well-formed, byte-identical tokens.
     *
     * @since   2.0.0
     */
    private function validLoginCsrf(ServerRequestInterface $request, array $form): bool
    {
        $cookie = $request->getCookieParams()[self::LOGIN_CSRF_COOKIE_NAME] ?? null;
        $submitted = $form['_csrf'] ?? null;
        return is_string($cookie)
            && is_string($submitted)
            && preg_match('/^[A-Za-z0-9_-]{43}$/D', $cookie) === 1
            && preg_match('/^[A-Za-z0-9_-]{43}$/D', $submitted) === 1
            && hash_equals($cookie, $submitted);
    }

    /**
     * Serialize one short-lived host-only login CSRF cookie.
     *
     * @param   string  $token  CSPRNG-backed double-submit token.
     *
     * @return  string  Cookie restricted to the portal login path.
     *
     * @since   2.0.0
     */
    private function loginCsrfCookie(string $token): string
    {
        return sprintf(
            '%s=%s; Path=/portal/login; Max-Age=%d; HttpOnly; SameSite=Strict%s',
            self::LOGIN_CSRF_COOKIE_NAME,
            $token,
            self::LOGIN_CSRF_SECONDS,
            $this->secureCookie ? '; Secure' : '',
        );
    }

    /**
     * Expire the pre-authentication token after a successful login exchange.
     *
     * @return  string  Clearing cookie with the exact issuance path and protections.
     *
     * @since   2.0.0
     */
    private function clearLoginCsrfCookie(): string
    {
        return sprintf(
            '%s=; Path=/portal/login; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT; '
                . 'HttpOnly; SameSite=Strict%s',
            self::LOGIN_CSRF_COOKIE_NAME,
            $this->secureCookie ? '; Secure' : '',
        );
    }

    /**
     * Build a portal-scoped strict browser cookie.
     *
     * @param   string  $token  Opaque one-time session token.
     *
     * @return  string  `Set-Cookie` value never sent to `/administrator`.
     *
     * @since   2.0.0
     */
    private function cookie(string $token): string
    {
        return sprintf(
            '%s=%s; Path=/portal; Max-Age=%d; HttpOnly; SameSite=Strict%s',
            PortalSessionMiddleware::COOKIE_NAME,
            $token,
            $this->sessionLifetime,
            $this->secureCookie ? '; Secure' : '',
        );
    }

    /**
     * Generate an unpadded URL-safe token with the requested entropy.
     *
     * @param   positive-int  $bytes  Random byte count.
     *
     * @return  string  URL-safe opaque token.
     *
     * @since   2.0.0
     */
    private static function token(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
