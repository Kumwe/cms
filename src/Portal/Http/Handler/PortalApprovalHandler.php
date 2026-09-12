<?php

declare(strict_types=1);

namespace Kumwe\App\Portal\Http\Handler;

use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalDenied;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalRequestView;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalService;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalVoteView;
use Kumwe\App\BusinessSurface\Application\BusinessApprovalSurfaceService;
use Kumwe\App\Http\Middleware\TrustedProxyMiddleware;
use Kumwe\App\Identity\Application\Administration\AuthenticationThrottled;
use Kumwe\App\Identity\Application\StepUp\AuthorizationStepUpProofAdapter;
use Kumwe\App\Identity\Application\StepUp\StepUpProvider;
use Kumwe\App\Identity\Application\StepUp\StepUpRejected;
use Kumwe\App\Identity\Domain\StepUp\StepUpIntent;
use Kumwe\App\Identity\Domain\StepUp\StepUpVerification;
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
 * Scoped portal approval inbox, non-enumerating detail, and exact-purpose step-up decisions.
 *
 * @since  2.0.0
 */
final readonly class PortalApprovalHandler implements RequestHandlerInterface
{
    /**
     * Bind the approval delivery surface to scoped queries, mutations, and portal step-up.
     *
     * @param   BusinessApprovalSurfaceService   $queries          Portal-safe live exposure gate.
     * @param   ApprovalService                  $approvals        Protected approval mutations.
     * @param   StepUpProvider                   $stepUp           Portal authenticator and recovery verifier.
     * @param   AuthorizationStepUpProofAdapter  $proofs           Proof adapter for authorization contexts.
     * @param   TransactionManager               $transactions     Atomic verification and decision scope.
     * @param   PortalRenderer                   $renderer         Isolated portal template renderer.
     * @param   Translator                       $translator       Resolves notice wording for the locale
     *          in flight.
     * @param   bool                             $secureCookie     Whether portal cookies require HTTPS.
     * @param   int                              $sessionLifetime  Portal cookie lifetime in seconds.
     *
     * @throws  \InvalidArgumentException  When the session lifetime falls outside the supported range.
     *
     * @since   2.0.0
     */
    public function __construct(
        private BusinessApprovalSurfaceService $queries,
        private ApprovalService $approvals,
        private StepUpProvider $stepUp,
        private AuthorizationStepUpProofAdapter $proofs,
        private TransactionManager $transactions,
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
     * Render a scoped approval projection or execute one exact-purpose protected decision.
     *
     * @param   ServerRequestInterface  $request  Resolved portal request.
     *
     * @return  ResponseInterface  Inbox, detail, error, or post-decision redirect response.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = PortalRequest::session($request);
        $context = PortalRequest::context($request);
        if ($request->getMethod() === 'GET' && $request->getUri()->getPath() === '/portal/approvals') {
            $query = $request->getQueryParams();
            $notice = is_string($query['updated'] ?? null)
                ? $this->translator->translate('core.portal.approvals.request_updated')
                : '';
            return $this->inbox($session, $this->queries->portalInbox($context), $notice);
        }

        $requestId = $request->getAttribute('id');
        if (!is_string($requestId)) {
            return $this->notFound($session);
        }
        $detail = $this->queries->portalDetail($context, $requestId);
        if (!$detail instanceof ApprovalRequestView) {
            return $this->notFound($session);
        }
        if ($request->getMethod() === 'GET') {
            $query = $request->getQueryParams();
            $notice = is_string($query['updated'] ?? null)
                ? $this->translator->translate('core.portal.approvals.request_updated')
                : '';
            $view = ($query['view'] ?? null) === 'history' ? 'history' : 'review';

            return $this->detail($session, $detail, $notice, view: $view);
        }

        $decision = $this->decision($request->getUri()->getPath());
        if ($decision === null || !$this->allowed($detail, $decision)) {
            return $this->notFound($session);
        }
        $form = PortalRequest::form($request);
        try {
            $verification = $this->transactions->transactional(function () use (
                $session,
                $context,
                $decision,
                $form,
                $request,
                $requestId,
            ): StepUpVerification {
                $fresh = $this->queries->portalDetail($context, $requestId);
                if (!$fresh instanceof ApprovalRequestView || !$this->allowed($fresh, $decision)) {
                    throw new ApprovalDenied();
                }
                $verification = $this->verify($session, $decision, $form, $request);
                $mfa = $session->identity->principal->context(
                    $session->identity->context->site,
                    AuthenticationStrength::MultiFactor,
                    $context->requestId(),
                    $context->correlationId(),
                    AuthenticatedSurface::Portal,
                    $session->identity->context->membership,
                    $verification->rotatedSession->sessionId,
                    $this->proofs->adapt($verification),
                );
                $fresh = $this->queries->portalDetail($context, $requestId);
                if (!$fresh instanceof ApprovalRequestView || !$this->allowed($fresh, $decision)) {
                    throw new ApprovalDenied();
                }
                if ($decision === 'approve') {
                    $this->approvals->approve($mfa, $requestId, $this->reason($form));
                } elseif ($decision === 'reject') {
                    $this->approvals->reject($mfa, $requestId, $this->reason($form));
                } else {
                    $this->approvals->revoke($mfa, $requestId);
                }

                return $verification;
            });
        } catch (AuthenticationThrottled) {
            return $this->detail(
                $session,
                $detail,
                '',
                $this->translator->translate('core.security.authentication.throttled'),
                429,
                ['Retry-After' => '900'],
            );
        } catch (StepUpRejected) {
            return $this->detail(
                $session,
                $detail,
                '',
                $this->translator->translate('core.security.step_up.verification_code_rejected'),
                403,
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->detail($session, $detail, '', $exception->getMessage(), 422);
        } catch (ApprovalDenied | AuthorizationDenied) {
            $fresh = $this->queries->portalDetail($context, $requestId);
            return $fresh instanceof ApprovalRequestView
                ? $this->detail(
                    $session,
                    $fresh,
                    '',
                    $this->translator->translate('core.portal.approvals.decision_no_longer_permitted'),
                    409,
                )
                : $this->notFound($session);
        }

        return new RedirectResponse('/portal/approvals/' . rawurlencode($requestId) . '?updated=1', 303, [
            'Cache-Control' => 'no-store',
            'Set-Cookie' => $this->cookie($verification->rotatedSession->cookieToken),
        ]);
    }

    /**
     * Render the actor's scoped approval inbox without exposing unavailable requests.
     *
     * @param   PortalSession              $session    Resolved portal session.
     * @param   list<ApprovalRequestView>  $approvals  Scoped approval projections.
     * @param   string                     $notice     Optional status message.
     *
     * @return  ResponseInterface  Non-cacheable approval inbox response.
     *
     * @since   2.0.0
     */
    private function inbox(PortalSession $session, array $approvals, string $notice = ''): ResponseInterface
    {
        return new HtmlResponse($this->renderer->render('approvals', [
            'approvals' => array_map(self::summary(...), $approvals),
            'notice' => $notice,
            'active_navigation' => 'core.portal-approvals',
        ], $session), 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Render one scoped approval request and its currently permitted controls.
     *
     * @param   PortalSession                                  $session   Resolved portal session.
     * @param   ApprovalRequestView                            $approval  Scoped approval projection.
     * @param   string                                         $notice    Optional success message.
     * @param   string                                         $error     Optional protected error message.
     * @param   int                                            $status    HTTP status code.
     * @param   array<non-empty-string, array<string>|string>  $headers   Additional response headers.
     * @param   string                                         $view      Active review or decision-history concern.
     *
     * @return  ResponseInterface  Non-cacheable approval detail response.
     *
     * @since   2.0.0
     */
    private function detail(
        PortalSession $session,
        ApprovalRequestView $approval,
        string $notice = '',
        string $error = '',
        int $status = 200,
        array $headers = [],
        string $view = 'review',
    ): ResponseInterface {
        return new HtmlResponse($this->renderer->render('approval-detail', [
            'approval' => self::detailProjection($approval),
            'notice' => $notice,
            'error' => $error,
            'view' => $view === 'history' ? 'history' : 'review',
            'active_navigation' => 'core.portal-approvals',
        ], $session), $status, ['Cache-Control' => 'no-store'] + $headers);
    }

    /**
     * Project one approval for the portal without internal record, actor, digest, or rule evidence.
     *
     * @param   ApprovalRequestView  $approval  Scoped approval application projection.
     *
     * @return  array<string, mixed>  Minimal portal inbox document.
     *
     * @since   2.0.0
     */
    private static function summary(ApprovalRequestView $approval): array
    {
        return [
            'id' => $approval->id,
            'status' => $approval->status->value,
            'action' => $approval->action,
            'resourceType' => $approval->resourceType,
            'resourceVersion' => $approval->resourceVersion,
            'requiredQuorum' => $approval->requiredQuorum,
            'approvalCount' => $approval->approvalCount,
            'createdAt' => $approval->createdAt,
            'expiresAt' => $approval->expiresAt,
            'canApprove' => $approval->canApprove,
            'canRevoke' => $approval->canRevoke,
        ];
    }

    /**
     * Add redacted decisions to the minimal portal approval document.
     *
     * Vote and approver identities are deliberately omitted; the decision, optional human reason, and
     * timestamp are sufficient for a checker to understand current quorum without learning account IDs.
     *
     * @param   ApprovalRequestView  $approval  Scoped approval application projection.
     *
     * @return  array<string, mixed>  Minimal portal detail document.
     *
     * @since   2.0.0
     */
    private static function detailProjection(ApprovalRequestView $approval): array
    {
        return self::summary($approval) + [
            'votes' => array_map(
                static fn (ApprovalVoteView $vote): array => [
                    'decision' => $vote->decision,
                    'reason' => $vote->reason,
                    'decidedAt' => $vote->decidedAt,
                ],
                $approval->votes,
            ),
        ];
    }

    /**
     * Return the same not-found response for absent and unauthorized approval requests.
     *
     * @param   PortalSession                                  $session  Resolved portal session.
     * @param   array<non-empty-string, array<string>|string>  $headers  Additional response headers.
     *
     * @return  ResponseInterface  Non-enumerating not-found response.
     *
     * @since   2.0.0
     */
    private function notFound(PortalSession $session, array $headers = []): ResponseInterface
    {
        return new HtmlResponse($this->renderer->render('approval-not-found', [
            'active_navigation' => 'core.portal-approvals',
        ], $session), 404, ['Cache-Control' => 'no-store'] + $headers);
    }

    /**
     * Resolve the protected decision from the server-owned route path.
     *
     * @param   string  $path  Matched portal request path.
     *
     * @return  ?string  Decision suffix, or null for an unsupported path.
     *
     * @since   2.0.0
     */
    private function decision(string $path): ?string
    {
        foreach (['approve', 'reject', 'revoke'] as $decision) {
            if (str_ends_with($path, '/' . $decision)) {
                return $decision;
            }
        }
        return null;
    }

    /**
     * Check the projection's live decision eligibility before requesting step-up.
     *
     * @param   ApprovalRequestView  $detail    Scoped approval projection.
     * @param   string               $decision  Server-resolved decision suffix.
     *
     * @return  bool  Whether the current actor may perform the decision.
     *
     * @since   2.0.0
     */
    private function allowed(ApprovalRequestView $detail, string $decision): bool
    {
        return $decision === 'revoke' ? $detail->canRevoke : $detail->canApprove;
    }

    /**
     * Verify the server-selected decision purpose through TOTP or one recovery code.
     *
     * @param   PortalSession           $session   Resolved portal session.
     * @param   string                  $decision  Server-resolved decision suffix.
     * @param   array<string, string>   $form      Parsed decision form.
     * @param   ServerRequestInterface  $request   Request carrying the trusted source address.
     *
     * @return  StepUpVerification  Fresh proof and rotated portal session.
     *
     * @since   2.0.0
     */
    private function verify(
        PortalSession $session,
        string $decision,
        array $form,
        ServerRequestInterface $request,
    ): StepUpVerification {
        $intent = $this->intent($session, 'business.approval.' . $decision);
        return match ($form['verification_method'] ?? '') {
            'totp' => $this->stepUp->challenge($intent, $form['verification'] ?? '', $this->source($request)),
            'recovery' => $this->stepUp->recover(
                $intent,
                $form['verification'] ?? '',
                $this->source($request),
            ),
            default => throw new \InvalidArgumentException('Choose an authenticator or recovery code.'),
        };
    }

    /**
     * Build an exact portal-session-bound step-up intent.
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
     * Normalize an optional human-readable decision reason.
     *
     * @param   array<string, string>  $form  Parsed decision form.
     *
     * @return  ?string  Trimmed reason, or null when omitted.
     *
     * @since   2.0.0
     */
    private function reason(array $form): ?string
    {
        $reason = trim($form['reason'] ?? '');
        return $reason === '' ? null : $reason;
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
