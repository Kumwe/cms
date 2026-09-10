<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Http\Handler;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Administrator\Http\AdministratorRequest;
use Kumwe\App\Administrator\Http\Middleware\AdministratorSessionMiddleware;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\App\Administrator\Presentation\SecurityWorkspaceState;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\BusinessSecurity\Application\Approval\StepUpProofConsumer;
use Kumwe\App\BusinessSecurity\Application\MembershipDirectory;
use Kumwe\App\Http\Middleware\TrustedProxyMiddleware;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Identity\Application\Administration\AdministratorSessionStore;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Identity\Application\StepUp\AdministratorStepUpProvider;
use Kumwe\App\Identity\Application\StepUp\AuthorizationStepUpProofAdapter;
use Kumwe\App\Identity\Domain\StepUp\StepUpEnrollmentCompletion;
use Kumwe\App\Identity\Domain\StepUp\StepUpIntent;
use Kumwe\App\Identity\Domain\StepUp\StepUpVerification;
use Kumwe\App\Identity\Domain\UserStatus;
use Kumwe\App\Shared\Domain\CanonicalJson;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves the administrator access-control screen and applies the identity changes it posts back.
 *
 * Users, roles, capability grants and API tokens are managed from one screen behind one
 * `users.manage` capability, because deciding who may do what means seeing all four together. `GET`
 * renders the current state; `POST` dispatches on the form's `action` field and then redirects, so a
 * refresh cannot replay a change. Token issue and rotation are the deliberate exception: they render
 * instead of redirecting, because the plaintext secret is shown once and is never recoverable.
 *
 * @since  2.0.0
 */
final readonly class AdministratorAccessControlHandler implements RequestHandlerInterface
{
    /**
     * Submitted fields that carry a secret and must never reach a purpose digest or a redirect.
     *
     * The step-up purpose is a canonical digest of the exact submitted change set, and that digest is
     * stored on the proof row and repeated in the audit event. A password folded into it would leave a
     * durable, offline-guessable commitment to the plaintext in two tables, so every credential-bearing
     * field is stripped before the digest is taken. What remains still binds the proof to the action,
     * the target account and the stated reason, which is what the payload binding is for.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array CREDENTIAL_FIELDS = [
        '_csrf',
        'step_up_method',
        'step_up_code',
        'recovery_code',
        'current_password',
        'new_password',
        'new_password_confirmation',
        'password',
    ];

    /**
     * Wire the screen to the services that read and change administrator identities.
     *
     * @param  AccessControlService             $access           Reads and mutates users, roles, grants and tokens.
     * @param  AdministratorIdentityGateway     $identities       Issues and rotates tokens, the secret-bearing acts.
     * @param  AdministratorRenderer            $renderer         Renders the `access-control` template.
     * @param  AdministratorSessionStore        $sessions         Rotates authenticated organization selections.
     * @param  MembershipDirectory              $memberships      Lists only selections the actor currently holds.
     * @param  AdministratorStepUpProvider      $stepUp           Production administrator second-factor provider.
     * @param  AuthorizationStepUpProofAdapter  $proofs           Adapts provider verification to authorization proof.
     * @param  StepUpProofConsumer              $proofConsumer    Atomically spends every adapted proof once.
     * @param  TransactionManager               $transactions     Joins proof use, session rotation and mutation.
     * @param  ClockInterface                   $clock            Trusted proof-consumption instant.
     * @param  bool                             $secureCookie     Whether the rotated cookie carries `Secure`.
     * @param  int                              $sessionLifetime  Cookie maximum age in seconds.
     *
     * @since  2.0.0
     */
    public function __construct(
        private AccessControlService $access,
        private AdministratorIdentityGateway $identities,
        private AdministratorRenderer $renderer,
        private AdministratorSessionStore $sessions,
        private MembershipDirectory $memberships,
        private AdministratorStepUpProvider $stepUp,
        private AuthorizationStepUpProofAdapter $proofs,
        private StepUpProofConsumer $proofConsumer,
        private TransactionManager $transactions,
        private ClockInterface $clock,
        private bool $secureCookie = true,
        private int $sessionLifetime = 28_800,
    ) {
    }

    /**
     * Render the access-control screen, first applying whatever change a `POST` carries.
     *
     * A change that produces no secret redirects to `?saved=1` so the browser cannot resubmit it.
     * Token issue and rotation fall through to the render instead, because the plaintext token is
     * handed to the operator exactly once. The response is marked `no-store` for that reason as much
     * as for the CSRF token it carries.
     *
     * @param   ServerRequestInterface  $request  Administrator request, already authenticated and CSRF-checked.
     *
     * @return  ResponseInterface  The rendered screen, or a 303 redirect when there is no secret to show.
     *
     * @throws  InvalidArgumentException  When a required field is missing or the action is not supported.
     * @throws  \DateMalformedStringException  When a token expiry field is not a readable date and time.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = AdministratorRequest::session($request);
        $context = AdministratorRequest::context($request);
        $workspace = SecurityWorkspaceState::access($request->getQueryParams());
        $createdToken = null;
        $enrollment = null;
        $recoveryCodes = [];
        $replacementToken = null;
        $csrf = $session->csrfToken;
        if (strtoupper($request->getMethod()) === 'POST') {
            $form = AdministratorRequest::form($request);
            if (($form['action'] ?? null) === 'context.select') {
                $workspaceIdentifier = trim($form['workspace'] ?? '');
                $created = $this->sessions->selectMembership(
                    $context,
                    AdministratorRequest::required($form, 'organization'),
                    $workspaceIdentifier === '' ? null : $workspaceIdentifier,
                    $request->getHeaderLine('User-Agent'),
                );

                return new RedirectResponse($workspace->url(
                    '/administrator/access',
                    ['saved' => '1'],
                ), 303, [
                    'Cache-Control' => 'no-store',
                    'Set-Cookie' => $this->cookie($created->token),
                ]);
            }
            $action = AdministratorRequest::required($form, 'action');
            if ($action === 'step_up.begin') {
                $enrollment = $this->stepUp->beginEnrollment(
                    $context->actorId(),
                    'Kumwe',
                    $context->actorId(),
                );
            } elseif ($action === 'step_up.confirm') {
                $purpose = 'identity.step_up.enrollment';
                $completion = $this->transactions->transactional(function () use (
                    $context,
                    $purpose,
                    $form,
                    $request,
                ): StepUpEnrollmentCompletion {
                    $completion = $this->stepUp->confirmEnrollment(
                        $this->stepUpIntent($context, $purpose),
                        AdministratorRequest::required($form, 'enrollment_id'),
                        AdministratorRequest::required($form, 'step_up_code'),
                        $this->source($request),
                    );
                    $steppedContext = $this->multiFactorContext($context, $completion->verification);
                    $this->proofConsumer->consume(
                        $steppedContext->stepUpProof()
                            ?? throw new InvalidArgumentException('Administrator enrollment proof is unavailable.'),
                        $steppedContext,
                        $purpose,
                        $this->clock->now(),
                    );

                    return $completion;
                });
                $replacementToken = $completion->verification->rotatedSession->cookieToken;
                $csrf = $completion->verification->rotatedSession->csrfToken;
                $recoveryCodes = $completion->recoveryCodes;
            } elseif ($action === 'step_up.recovery.reissue') {
                $purpose = 'identity.step_up.recovery.reissue';
                /** @var array{verification: StepUpVerification, codes: list<string>} $reissued */
                $reissued = $this->transactions->transactional(function () use (
                    $context,
                    $purpose,
                    $form,
                    $request,
                ): array {
                    $verification = $this->stepUp->challenge(
                        $this->stepUpIntent($context, $purpose),
                        AdministratorRequest::required($form, 'step_up_code'),
                        $this->source($request),
                    );
                    $steppedContext = $this->multiFactorContext($context, $verification);
                    $this->proofConsumer->consume(
                        $steppedContext->stepUpProof()
                            ?? throw new InvalidArgumentException('Administrator reissue proof is unavailable.'),
                        $steppedContext,
                        $purpose,
                        $this->clock->now(),
                    );

                    return [
                        'verification' => $verification,
                        'codes' => $this->stepUp->reissueRecoveryCodes($steppedContext->actorId()),
                    ];
                });
                $replacementToken = $reissued['verification']->rotatedSession->cookieToken;
                $csrf = $reissued['verification']->rotatedSession->csrfToken;
                $recoveryCodes = $reissued['codes'];
            } else {
                $purpose = $this->stepUpPurpose($action, $form);
                /**
                 * @var    array{
                 *             verification: StepUpVerification,
                 *             created_token: ?array{token: string, token_id: string}
                 *         } $result
                 */
                $result = $this->transactions->transactional(function () use (
                    $context,
                    $purpose,
                    $form,
                    $request,
                ): array {
                    $verification = ($form['step_up_method'] ?? 'totp') === 'recovery'
                        ? $this->stepUp->recover(
                            $this->stepUpIntent($context, $purpose),
                            AdministratorRequest::required($form, 'recovery_code'),
                            $this->source($request),
                        )
                        : $this->stepUp->challenge(
                            $this->stepUpIntent($context, $purpose),
                            AdministratorRequest::required($form, 'step_up_code'),
                            $this->source($request),
                        );
                    $steppedContext = $this->multiFactorContext($context, $verification);
                    $this->proofConsumer->consume(
                        $steppedContext->stepUpProof()
                            ?? throw new InvalidArgumentException('Administrator step-up proof is unavailable.'),
                        $steppedContext,
                        $purpose,
                        $this->clock->now(),
                    );

                    return [
                        'verification' => $verification,
                        'created_token' => $this->mutate($steppedContext, $form),
                    ];
                });
                $verification = $result['verification'];
                $replacementToken = $verification->rotatedSession->cookieToken;
                $csrf = $verification->rotatedSession->csrfToken;
                $createdToken = $result['created_token'];
                if ($action === 'user.password.change') {
                    return $this->signedOut();
                }
            }
            if ($createdToken === null) {
                if ($enrollment === null && $recoveryCodes === []) {
                    return new RedirectResponse($workspace->url(
                        '/administrator/access',
                        ['saved' => '1'],
                    ), 303, array_filter([
                        'Set-Cookie' => $replacementToken === null ? null : $this->cookie($replacementToken),
                    ], static fn (?string $header): bool => $header !== null));
                }
            }
        }

        $context = AdministratorRequest::context($request);
        $section = $workspace->section;
        return new HtmlResponse($this->renderer->render('access-control', [
            'csrf' => $csrf,
            'capabilities' => AdministratorRequest::capabilityMap($request),
            'users' => in_array($section, ['users', 'assignments'], true)
                ? $this->access->users($context)
                : [],
            'roles' => in_array($section, ['groups', 'grants', 'assignments'], true)
                ? $this->access->roles($context)
                : [],
            'tokens' => $section === 'tokens' ? $this->access->tokens($context) : [],
            'available_capabilities' => in_array($section, ['groups', 'grants', 'tokens'], true)
                ? $this->access->capabilities($context)
                : [],
            'security_events' => $section === 'events' ? $this->access->securityEvents($context) : [],
            'created_token' => $createdToken,
            'saved' => ($request->getQueryParams()['saved'] ?? null) === '1',
            'workspace' => $workspace->toArray(),
            'organization_selections' => $this->memberships->selections(
                $context->actorId(),
                $context->site(),
            ),
            'active_organization' => $context->organization()?->identifier(),
            'active_workspace' => $context->workspace()?->identifier(),
            'active_actor_id' => $context->actorId(),
            'step_up_enrollment' => $enrollment,
            'step_up_recovery_codes' => $recoveryCodes,
        ]), 200, array_filter([
            'Cache-Control' => 'no-store',
            'Set-Cookie' => $replacementToken === null ? null : $this->cookie($replacementToken),
        ], static fn (?string $header): bool => $header !== null));
    }

    /**
     * Apply the single access-control operation named by the form's `action` field.
     *
     * Each branch reads only its own fields, so one action can never pick up a value meant for
     * another. The return value is what tells the caller apart: only the token branches yield
     * something, and everything else goes through `after()` to say "done, redirect".
     *
     * @param   ExecutionContext       $context  Actor and site the change is authorised and audited against.
     * @param   array<string, string>  $form     Flattened form as returned by `AdministratorRequest::form()`.
     *
     * @return  array{token: string, token_id: string}|null  Secret and id, or null when none was issued.
     *
     * @throws  InvalidArgumentException  When a required field is missing or `action` names no known operation.
     * @throws  \DateMalformedStringException  When the rotation expiry field is not a readable date and time.
     *
     * @since   2.0.0
     */
    private function mutate(ExecutionContext $context, array $form): ?array
    {
        $action = AdministratorRequest::required($form, 'action');
        return match ($action) {
            'user.create' => $this->after(function () use ($context, $form): void {
                $this->access->createUser(
                    $context,
                    AdministratorRequest::required($form, 'email'),
                    AdministratorRequest::required($form, 'display_name'),
                    AdministratorRequest::required($form, 'password'),
                    UserStatus::from($form['status'] ?? 'active'),
                );
            }),
            'user.update' => $this->after(function () use ($context, $form): void {
                $this->access->updateUser(
                    $context,
                    AdministratorRequest::required($form, 'id'),
                    AdministratorRequest::required($form, 'email'),
                    AdministratorRequest::required($form, 'display_name'),
                    UserStatus::from(AdministratorRequest::required($form, 'status')),
                    AdministratorRequest::positiveInteger($form, 'version'),
                );
            }),
            'role.create' => $this->after(function () use ($context, $form): void {
                $this->access->createRole(
                    $context,
                    AdministratorRequest::required($form, 'code'),
                    AdministratorRequest::required($form, 'name'),
                );
            }),
            'role.assign' => $this->after(function () use ($context, $form): void {
                $this->access->assignRole(
                    $context,
                    AdministratorRequest::required($form, 'user_id'),
                    AdministratorRequest::required($form, 'role_id'),
                );
            }),
            'role.revoke' => $this->after(function () use ($context, $form): void {
                $this->access->revokeRole(
                    $context,
                    AdministratorRequest::required($form, 'user_id'),
                    AdministratorRequest::required($form, 'role_id'),
                );
            }),
            'grant.create' => $this->after(function () use ($context, $form): void {
                $scopeType = $form['scope_type'] ?? 'global';
                $scopeIdentifier = trim($form['scope_identifier'] ?? '');
                $this->access->grant(
                    $context,
                    AdministratorRequest::required($form, 'role_id'),
                    AdministratorRequest::required($form, 'capability'),
                    $scopeType,
                    $scopeIdentifier === '' ? null : $scopeIdentifier,
                );
            }),
            'grant.revoke' => $this->after(function () use ($context, $form): void {
                $this->access->revokeGrant($context, AdministratorRequest::required($form, 'grant_id'));
            }),
            'grant.synchronize' => $this->after(function () use ($context, $form): void {
                $selected = array_values(array_filter(array_map(
                    'trim',
                    explode(',', $form['selected_capabilities'] ?? ''),
                ), static fn (string $capability): bool => $capability !== ''));
                $this->access->synchronizeGlobalRoleGrants(
                    $context,
                    AdministratorRequest::required($form, 'role_id'),
                    $selected,
                    AdministratorRequest::required($form, 'grant_snapshot'),
                );
            }),
            'token.create' => $this->createToken($form, $context),
            'token.revoke' => $this->after(function () use ($context, $form): void {
                $this->access->revokeToken($context, AdministratorRequest::required($form, 'token_id'));
            }),
            'token.rotate' => $this->identities->rotateAccessToken(
                $context,
                AdministratorRequest::required($form, 'token_id'),
                AdministratorRequest::required($form, 'token_name'),
                trim($form['expires_at'] ?? '') === ''
                    ? null
                    : new DateTimeImmutable($form['expires_at']),
            ),
            'token.emergency_revoke' => $this->after(function () use ($context, $form): void {
                $this->access->emergencyRevokeAllSubjectTokens(
                    $context,
                    AdministratorRequest::required($form, 'user_id'),
                    AdministratorRequest::required($form, 'reason'),
                );
            }),
            'user.password.change' => $this->after(function () use ($context, $form): void {
                $replacement = AdministratorRequest::required($form, 'new_password');
                if (!hash_equals($replacement, AdministratorRequest::required($form, 'new_password_confirmation'))) {
                    throw new InvalidArgumentException('The replacement password and its confirmation differ.');
                }
                $this->access->changeOwnPassword(
                    $context,
                    AdministratorRequest::required($form, 'current_password'),
                    $replacement,
                );
            }),
            'user.password.reset' => $this->after(function () use ($context, $form): void {
                $this->access->resetUserPassword(
                    $context,
                    AdministratorRequest::required($form, 'user_id'),
                    AdministratorRequest::required($form, 'new_password'),
                    AdministratorRequest::required($form, 'reason'),
                );
            }),
            'user.step_up.revoke' => $this->after(function () use ($context, $form): void {
                $this->access->revokeStepUpCredentials(
                    $context,
                    AdministratorRequest::required($form, 'user_id'),
                    AdministratorRequest::required($form, 'reason'),
                );
            }),
            'user.sessions.terminate' => $this->after(function () use ($context, $form): void {
                $this->access->terminateUserSessions(
                    $context,
                    AdministratorRequest::required($form, 'user_id'),
                    AdministratorRequest::required($form, 'reason'),
                );
            }),
            default => throw new InvalidArgumentException('The access-control action is not supported.'),
        };
    }

    /**
     * Run a change that yields nothing and report the absence of a secret to show.
     *
     * The helper exists so that every non-token arm of the `match` above is an expression of the same
     * type; returning `null` rather than `void` is what lets a statement-shaped operation sit there.
     *
     * @param   callable(): void  $operation  The access-control change to perform.
     *
     * @return  null  Always null, telling the caller to redirect rather than render.
     *
     * @since   2.0.0
     */
    private function after(callable $operation): null
    {
        $operation();

        return null;
    }

    /**
     * Issue a new API token for a subject and return the only copy of its secret.
     *
     * Capabilities arrive as one comma-separated field because the form posts a multi-select; blank
     * entries are dropped so a trailing comma cannot request an unnamed capability. Audience and
     * purpose fall back to the HTTP API defaults when the form leaves them out.
     *
     * @param   array<string, string>  $form     Flattened form carrying the token fields.
     * @param   ExecutionContext       $context  Actor and site the issue is authorised and audited against.
     *
     * @return  array{token: string, token_id: string}  Plaintext secret, shown once, and the stored id.
     *
     * @throws  InvalidArgumentException  When a required token field is missing or blank.
     * @throws  \DateMalformedStringException  When the expiry field is not a readable date and time.
     *
     * @since   2.0.0
     */
    private function createToken(array $form, ExecutionContext $context): array
    {
        $capabilities = array_values(array_filter(array_map(
            'trim',
            explode(',', AdministratorRequest::required($form, 'token_capabilities')),
        ), static fn (string $capability): bool => $capability !== ''));
        $expiresAt = trim($form['expires_at'] ?? '');

        return $this->identities->issueAccessToken(
            $context,
            AdministratorRequest::required($form, 'token_email'),
            AdministratorRequest::required($form, 'token_name'),
            $capabilities,
            $expiresAt === '' ? null : new DateTimeImmutable($expiresAt),
            $form['audience'] ?? 'kumwe-http',
            $form['purpose'] ?? 'api',
        );
    }

    /**
     * Build the isolated replacement administrator cookie after a scope rotation.
     *
     * @param   string  $token  One-time opaque session token.
     *
     * @return  string  Strict administrator-only cookie header.
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

    /**
     * Send the operator back to sign-in after they have replaced their own password.
     *
     * Changing a password advances the actor's own security epoch, which is exactly what retires every
     * session they hold — including the one this request rotated a moment earlier. Rather than hand the
     * browser a cookie that will fail to resolve on the next request and leave the operator staring at
     * an unexplained sign-in page, the replacement cookie is expired here and the redirect states the
     * outcome, so being signed out reads as the intended consequence of the change.
     *
     * @return  ResponseInterface  A 303 to the administrator sign-in with the session cookie cleared.
     *
     * @since   2.0.0
     */
    private function signedOut(): ResponseInterface
    {
        return new RedirectResponse('/administrator/login?password_changed=1', 303, [
            'Cache-Control' => 'no-store',
            'Set-Cookie' => sprintf(
                '%s=; Path=/administrator; Max-Age=0; HttpOnly; SameSite=Strict%s',
                AdministratorSessionMiddleware::COOKIE_NAME,
                $this->secureCookie ? '; Secure' : '',
            ),
        ]);
    }

    /**
     * Build an exact second-factor intent only from the authenticated administrator context.
     *
     * @param   ExecutionContext  $context  Authenticated administrator and current session scope.
     * @param   string            $purpose  Exact access-control mutation purpose.
     *
     * @return  StepUpIntent  Actor, old session, scope, purpose, and current security epoch.
     *
     * @since   2.0.0
     */
    private function stepUpIntent(ExecutionContext $context, string $purpose): StepUpIntent
    {
        $principal = $context->principal()
            ?? throw new InvalidArgumentException('Administrator step-up requires a human actor.');

        return new StepUpIntent(
            $principal->subject(),
            $context->sessionId()
                ?? throw new InvalidArgumentException('Administrator step-up requires a live session.'),
            $context->site()->identifier(),
            $context->organization()?->identifier(),
            $context->workspace()?->identifier(),
            $purpose,
            $principal->securityEpoch(),
        );
    }

    /**
     * Move a successful provider verification onto its exact rotated administrator session.
     *
     * @param ExecutionContext $context Pre-challenge administrator context.
     * @param   \Kumwe\App\Identity\Domain\StepUp\StepUpVerification  $verification  Successful challenge.
     *
     * @return  ExecutionContext  Multi-factor context carrying the newly adapted proof.
     *
     * @since   2.0.0
     */
    private function multiFactorContext(
        ExecutionContext $context,
        \Kumwe\App\Identity\Domain\StepUp\StepUpVerification $verification,
    ): ExecutionContext {
        return AuthenticatedPrincipal::of($context)?->context(
            $context->site(),
            AuthenticationStrength::MultiFactor,
            $context->requestId(),
            $context->correlationId(),
            $context->surface(),
            $context->membership(),
            $verification->rotatedSession->sessionId,
            $this->proofs->adapt($verification),
        ) ?? throw new InvalidArgumentException('Administrator step-up requires a human actor.');
    }

    /**
     * Resolve the closed proof purpose for one supported access-control mutation.
     *
     * @param   string                 $action  Mutation action received from the administrator form.
     * @param   array<string, string>  $form    Exact submitted change set, including its resource snapshot.
     *
     * @return  string  Exact purpose to which the proof must be bound.
     *
     * @since   2.0.0
     */
    private function stepUpPurpose(string $action, array $form): string
    {
        $base = match ($action) {
            'user.create',
            'user.update',
            'role.create',
            'role.assign',
            'role.revoke',
            'grant.create',
            'grant.revoke',
            'grant.synchronize',
            'token.create',
            'token.revoke',
            'token.rotate',
            'token.emergency_revoke',
            'user.password.change',
            'user.password.reset',
            'user.step_up.revoke',
            'user.sessions.terminate' => 'identity.access_control.' . str_replace('_', '.', $action),
            default => throw new InvalidArgumentException('The access-control step-up purpose is invalid.'),
        };
        foreach (self::CREDENTIAL_FIELDS as $credentialField) {
            unset($form[$credentialField]);
        }

        return $base . '.payload.' . CanonicalJson::digest($form);
    }

    /**
     * Resolve the trusted-proxy-normalized source of an administrator attempt.
     *
     * @param   ServerRequestInterface  $request  Request carrying trusted proxy middleware attributes.
     *
     * @return  string  Client address or the non-sensitive fallback marker.
     *
     * @since   2.0.0
     */
    private function source(ServerRequestInterface $request): string
    {
        $source = $request->getAttribute(TrustedProxyMiddleware::ATTRIBUTE_CLIENT_ADDRESS, 'unknown');

        return is_string($source) ? $source : 'unknown';
    }
}
