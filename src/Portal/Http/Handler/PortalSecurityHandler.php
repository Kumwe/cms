<?php

declare(strict_types=1);

namespace Kumwe\App\Portal\Http\Handler;

use Kumwe\App\Http\Middleware\TrustedProxyMiddleware;
use Kumwe\App\Identity\Application\Administration\AuthenticationThrottled;
use Kumwe\App\Identity\Application\StepUp\StepUpProvider;
use Kumwe\App\Identity\Application\StepUp\StepUpRejected;
use Kumwe\App\Identity\Domain\StepUp\RotatedStepUpSession;
use Kumwe\App\Identity\Domain\StepUp\StepUpIntent;
use Kumwe\App\Portal\Application\PortalSession;
use Kumwe\App\Portal\Http\Middleware\PortalSessionMiddleware;
use Kumwe\Localization\Application\Translator;
use Kumwe\App\Portal\Http\PortalRequest;
use Kumwe\App\Portal\Presentation\PortalRenderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Accessible portal delivery for TOTP enrollment, challenge, and single-use recovery.
 *
 * Every challenge purpose is selected from the matched server route. Scope, actor, epoch, and session
 * bindings come only from the resolved portal session, and every successful verification replaces the
 * cookie before another request can use the proof.
 *
 * @since  2.0.0
 */
final readonly class PortalSecurityHandler implements RequestHandlerInterface
{
    /**
     * Bind account-security delivery to the portal step-up provider and isolated renderer.
     *
     * @param   StepUpProvider  $stepUp           Portal authenticator and recovery verifier.
     * @param   PortalRenderer  $renderer         Isolated portal template renderer.
     * @param   Translator      $translator       Resolves notice wording for the locale in flight.
     * @param   bool            $secureCookie     Whether portal cookies require HTTPS.
     * @param   int             $sessionLifetime  Portal cookie lifetime in seconds.
     *
     * @throws  \InvalidArgumentException  When the session lifetime falls outside the supported range.
     *
     * @since   2.0.0
     */
    public function __construct(
        private StepUpProvider $stepUp,
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
     * Render account security or execute one server-selected protected operation.
     *
     * @param   ServerRequestInterface  $request  Resolved portal request.
     *
     * @return  ResponseInterface  Account-security page, protected error, or success redirect.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = PortalRequest::session($request);
        $path = $request->getUri()->getPath();
        if ($request->getMethod() === 'GET' && $path === '/portal/security') {
            $query = $request->getQueryParams();
            return $this->page(
                $session,
                isset($query['verified'])
                    ? $this->translator->translate('core.portal.security.verification_succeeded')
                    : '',
            );
        }

        $form = PortalRequest::form($request);
        try {
            return match ($path) {
                '/portal/security/totp/enroll' => $this->enroll($session),
                '/portal/security/totp/confirm' => $this->confirm($session, $form, $request),
                '/portal/security/challenge' => $this->challenge($session, $form, $request, false),
                '/portal/security/recovery' => $this->challenge($session, $form, $request, true),
                default => $this->page(
                    $session,
                    '',
                    $this->translator->translate('core.portal.security.operation_unavailable'),
                    404,
                ),
            };
        } catch (AuthenticationThrottled) {
            return $this->page(
                $session,
                '',
                $this->translator->translate('core.security.authentication.throttled'),
                429,
                ['Retry-After' => '900'],
            );
        } catch (StepUpRejected) {
            return $this->page(
                $session,
                '',
                $this->translator->translate('core.security.step_up.verification_code_rejected'),
                403,
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->page($session, '', $exception->getMessage(), 422);
        }
    }

    /**
     * Begin a short-lived TOTP enrollment for the current portal subject.
     *
     * @param   PortalSession  $session  Resolved portal session.
     *
     * @return  ResponseInterface  Setup page containing the one-time provisioning secret.
     *
     * @since   2.0.0
     */
    private function enroll(PortalSession $session): ResponseInterface
    {
        $setup = $this->stepUp->beginEnrollment(
            $session->identity->principal->subject(),
            'Kumwe ERP',
            $session->identity->principal->subject(),
        );

        return $this->page($session, '', '', 200, [], [
            'enrollment' => $setup,
        ]);
    }

    /**
     * Confirm enrollment with a valid first code and show single-use recovery codes once.
     *
     * @param   PortalSession           $session  Resolved portal session.
     * @param   array<string, string>   $form     Parsed enrollment confirmation form.
     * @param   ServerRequestInterface  $request  Request carrying the trusted source address.
     *
     * @return  ResponseInterface  Completion page bound to the rotated portal session.
     *
     * @since   2.0.0
     */
    private function confirm(
        PortalSession $session,
        array $form,
        ServerRequestInterface $request,
    ): ResponseInterface {
        $completion = $this->stepUp->confirmEnrollment(
            $this->intent($session, 'portal.step_up.enroll'),
            $form['enrollment_id'] ?? '',
            $form['code'] ?? '',
            $this->source($request),
        );
        $rotated = $completion->verification->rotatedSession;

        return $this->page(
            $this->rotatedSession($session, $rotated, $completion->verification->issuedAt),
            $this->translator->translate('core.portal.security.enrollment_complete'),
            '',
            200,
            ['Set-Cookie' => $this->cookie($rotated->cookieToken)],
            ['recovery_codes' => $completion->recoveryCodes],
        );
    }

    /**
     * Verify TOTP or consume one recovery code for the generic portal challenge purpose.
     *
     * @param   PortalSession           $session   Resolved portal session.
     * @param   array<string, string>   $form      Parsed challenge form.
     * @param   ServerRequestInterface  $request   Request carrying the trusted source address.
     * @param   bool                    $recovery  Whether to use the recovery-code path.
     *
     * @return  ResponseInterface  Redirect carrying only the newly rotated portal cookie.
     *
     * @since   2.0.0
     */
    private function challenge(
        PortalSession $session,
        array $form,
        ServerRequestInterface $request,
        bool $recovery,
    ): ResponseInterface {
        $intent = $this->intent($session, 'portal.step_up.challenge');
        $verification = $recovery
            ? $this->stepUp->recover($intent, $form['recovery_code'] ?? '', $this->source($request))
            : $this->stepUp->challenge($intent, $form['code'] ?? '', $this->source($request));

        return new RedirectResponse('/portal/security?verified=1', 303, [
            'Cache-Control' => 'no-store',
            'Set-Cookie' => $this->cookie($verification->rotatedSession->cookieToken),
        ]);
    }

    /**
     * Build a step-up intent entirely from server-owned session context.
     *
     * @param   PortalSession  $session  Resolved portal session and membership scope.
     * @param   string         $purpose  Server-selected proof purpose.
     *
     * @return  StepUpIntent  Intent bound to actor, session, scope, purpose, and security epoch.
     *
     * @since   2.0.0
     */
    private function intent(PortalSession $session, string $purpose): StepUpIntent
    {
        $membership = $session->identity->context->membership;
        return new StepUpIntent(
            $session->identity->principal->subject(),
            $session->id,
            $session->identity->context->site->identifier(),
            $membership?->organization()->identifier(),
            $membership?->workspace()?->identifier(),
            $purpose,
            $session->identity->securityEpoch,
        );
    }

    /**
     * Read the trusted client address used by the verification throttle.
     *
     * @param   ServerRequestInterface  $request  Request carrying the trusted-proxy result.
     *
     * @return  string  Trusted client address or a stable unknown marker.
     *
     * @since   2.0.0
     */
    private function source(ServerRequestInterface $request): string
    {
        $source = $request->getAttribute(TrustedProxyMiddleware::ATTRIBUTE_CLIENT_ADDRESS, 'unknown');
        return is_string($source) && $source !== '' ? $source : 'unknown';
    }

    /**
     * Rebuild presentation state around a newly rotated browser session.
     *
     * @param   PortalSession         $old         Previous resolved portal session.
     * @param   RotatedStepUpSession  $rotated     Fresh opaque session and CSRF values.
     * @param   \DateTimeImmutable    $verifiedAt  Verification instant recorded by the provider.
     *
     * @return  PortalSession  Fresh session state for the current response.
     *
     * @since   2.0.0
     */
    private function rotatedSession(
        PortalSession $old,
        RotatedStepUpSession $rotated,
        \DateTimeImmutable $verifiedAt,
    ): PortalSession {
        return new PortalSession(
            $rotated->sessionId,
            $old->identity,
            $rotated->csrfToken,
            $old->authenticatedAt,
            $verifiedAt,
            $rotated->expiresAt,
        );
    }

    /**
     * Render the non-cacheable account-security page with bounded presentation data.
     *
     * @param   PortalSession                                  $session  Resolved portal session.
     * @param   string                                         $notice   Optional success message.
     * @param   string                                         $error    Optional protected error message.
     * @param   int                                            $status   HTTP status code.
     * @param   array<non-empty-string, array<string>|string>  $headers  Additional response headers.
     * @param   array<string, mixed>                           $data     One-time enrollment or recovery data.
     *
     * @return  ResponseInterface  Non-cacheable account-security response.
     *
     * @since   2.0.0
     */
    private function page(
        PortalSession $session,
        string $notice = '',
        string $error = '',
        int $status = 200,
        array $headers = [],
        array $data = [],
    ): ResponseInterface {
        return new HtmlResponse($this->renderer->render('security', $data + [
            'notice' => $notice,
            'error' => $error,
            'enrollment' => null,
            'recovery_codes' => [],
            'active_navigation' => 'core.portal-security',
        ], $session), $status, ['Cache-Control' => 'no-store'] + $headers);
    }

    /**
     * Serialize a host-only portal session cookie with strict browser protections.
     *
     * @param   string  $token  Opaque rotated portal cookie token.
     *
     * @return  string  Set-Cookie header value constrained to the portal path.
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
}
