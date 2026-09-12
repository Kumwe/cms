<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Http\Handler;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Administrator\Http\AdministratorRequest;
use Kumwe\App\Administrator\Http\Middleware\AdministratorSessionMiddleware;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\Localization\Application\Translator;
use Kumwe\App\Administrator\Presentation\SecurityWorkspaceState;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\BusinessSecurity\Application\Administration\BusinessSecurityAdministrationService;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalService;
use Kumwe\Extension\Spi\BusinessSecurity\Application\FieldAccessUsage;
use Kumwe\App\Http\Middleware\TrustedProxyMiddleware;
use Kumwe\App\Identity\Application\StepUp\AdministratorStepUpProvider;
use Kumwe\App\Identity\Application\StepUp\AuthorizationStepUpProofAdapter;
use Kumwe\App\Identity\Domain\StepUp\StepUpIntent;
use Kumwe\App\Identity\Domain\StepUp\StepUpVerification;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;

/**
 * Structured administrator UI for organization security, maker-checker and credential diagnostics.
 *
 * The handler accepts only flat form fields and maps each named action to one application-service method.
 * It never accepts policy JSON. Every protected security write first completes a purpose-bound TOTP or
 * recovery challenge and moves the request onto the rotated administrator session before the service
 * atomically consumes that proof. Approval decisions use the exact purposes consumed by ApprovalService;
 * cancellation deliberately does not ask for step-up because it can only cancel the maker's own request.
 *
 * @since  2.0.0
 */
final readonly class AdministratorBusinessSecurityHandler implements RequestHandlerInterface
{
    /**
     * Bind the structured workspace to its security, approval, rendering, and step-up collaborators.
     *
     * @param  BusinessSecurityAdministrationService  $security         Guarded security runtime.
     * @param  ApprovalService                        $approvals        Canonical maker-checker workflow.
     * @param  AdministratorRenderer                  $renderer         Core administrator renderer.
     * @param  Translator                             $translator       Resolves the policy-step labels.
     * @param  AdministratorStepUpProvider            $stepUp           Administrator MFA provider.
     * @param  AuthorizationStepUpProofAdapter        $proofs           Verification-to-context adapter.
     * @param  TransactionManager                     $transactions     Atomic verification and mutation scope.
     * @param  bool                                   $secureCookie     Whether rotated cookies carry Secure.
     * @param  int                                    $sessionLifetime  Administrator cookie lifetime.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessSecurityAdministrationService $security,
        private ApprovalService $approvals,
        private AdministratorRenderer $renderer,
        private Translator $translator,
        private AdministratorStepUpProvider $stepUp,
        private AuthorizationStepUpProofAdapter $proofs,
        private TransactionManager $transactions,
        private bool $secureCookie = true,
        private int $sessionLifetime = 28_800,
    ) {
    }

    /**
     * Render the Business Security workspace or apply one protected structured form mutation.
     *
     * @param   ServerRequestInterface  $request  Authenticated administrator request.
     *
     * @return  ResponseInterface  No-store workspace response or post/redirect/get response.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $context = AdministratorRequest::context($request);
        $session = AdministratorRequest::session($request);
        $workspace = SecurityWorkspaceState::business($request->getQueryParams());
        $csrf = $session->csrfToken;
        if (strtoupper($request->getMethod()) === 'POST') {
            $form = AdministratorRequest::form($request);
            $action = AdministratorRequest::required($form, 'action');
            $replacementToken = null;
            if ($action === 'approval.cancel') {
                $this->mutate($context, $form);
            } else {
                $verification = $this->transactions->transactional(function () use (
                    $context,
                    $form,
                    $action,
                    $request,
                ): StepUpVerification {
                    $verification = ($form['step_up_method'] ?? 'totp') === 'recovery'
                        ? $this->stepUp->recover(
                            $this->stepUpIntent($context, $this->purpose($action)),
                            AdministratorRequest::required($form, 'recovery_code'),
                            $this->source($request),
                        )
                        : $this->stepUp->challenge(
                            $this->stepUpIntent($context, $this->purpose($action)),
                            AdministratorRequest::required($form, 'step_up_code'),
                            $this->source($request),
                        );
                    $steppedContext = AuthenticatedPrincipal::of($context)?->context(
                        $context->site(),
                        AuthenticationStrength::MultiFactor,
                        $context->requestId(),
                        $context->correlationId(),
                        $context->surface(),
                        $context->membership(),
                        $verification->rotatedSession->sessionId,
                        $this->proofs->adapt($verification),
                    ) ?? throw new InvalidArgumentException(
                        'Business Security step-up requires a human actor.',
                    );
                    $this->mutate($steppedContext, $form);

                    return $verification;
                });
                $replacementToken = $verification->rotatedSession->cookieToken;
                $csrf = $verification->rotatedSession->csrfToken;
            }

            return new RedirectResponse($workspace->url(
                '/administrator/business-security',
                ['saved' => '1'],
            ), 303, array_filter([
                'Cache-Control' => 'no-store',
                'Set-Cookie' => $replacementToken === null ? null : $this->cookie($replacementToken),
            ], static fn (?string $header): bool => $header !== null));
        }

        return new HtmlResponse($this->renderer->render('business-security', [
            'csrf' => $csrf,
            'actor_id' => $context->actorId(),
            'capabilities' => AdministratorRequest::capabilityMap($request),
            'security' => $this->security->overview($context),
            'workspace' => $workspace->toArray(),
            'policy_steps' => array_map(
                fn (array $step): array => [...$step, 'label' => $this->translator->translate($step['label'])],
                $workspace->policySteps('/administrator/business-security'),
            ),
            'active_organization' => $context->organization()?->identifier(),
            'active_workspace' => $context->workspace()?->identifier(),
            'saved' => ($request->getQueryParams()['saved'] ?? null) === '1',
        ]), 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Apply exactly one named structured form mutation.
     *
     * @param   ExecutionContext       $context  Stepped administrator authority context.
     * @param   array<string, string>  $form     Flattened, non-JSON form values.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function mutate(ExecutionContext $context, array $form): void
    {
        $action = AdministratorRequest::required($form, 'action');
        switch ($action) {
            case 'organization.create':
                $this->security->createOrganization(
                    $context,
                    AdministratorRequest::required($form, 'identifier'),
                    AdministratorRequest::required($form, 'name'),
                );
                return;
            case 'workspace.create':
                $this->security->createWorkspace(
                    $context,
                    AdministratorRequest::required($form, 'organization_id'),
                    AdministratorRequest::required($form, 'identifier'),
                    AdministratorRequest::required($form, 'name'),
                );
                return;
            case 'membership.create':
                $this->security->createMembership(
                    $context,
                    AdministratorRequest::required($form, 'organization_id'),
                    AdministratorRequest::required($form, 'user_id'),
                    new DateTimeImmutable(AdministratorRequest::required($form, 'valid_from')),
                    $this->optionalDate($form['valid_until'] ?? null),
                );
                return;
            case 'membership.status':
                $this->security->changeMembershipStatus(
                    $context,
                    AdministratorRequest::required($form, 'membership_id'),
                    AdministratorRequest::required($form, 'status'),
                    AdministratorRequest::positiveInteger($form, 'version'),
                );
                return;
            case 'membership.workspace.assign':
                $this->security->assignWorkspace(
                    $context,
                    AdministratorRequest::required($form, 'membership_id'),
                    AdministratorRequest::required($form, 'workspace_id'),
                );
                return;
            case 'membership.workspace.revoke':
                $this->security->revokeWorkspace(
                    $context,
                    AdministratorRequest::required($form, 'membership_id'),
                    AdministratorRequest::required($form, 'workspace_id'),
                );
                return;
            case 'membership.role.assign':
                $this->security->assignRole(
                    $context,
                    AdministratorRequest::required($form, 'membership_id'),
                    AdministratorRequest::required($form, 'role_id'),
                );
                return;
            case 'membership.role.revoke':
                $this->security->revokeRole(
                    $context,
                    AdministratorRequest::required($form, 'membership_id'),
                    AdministratorRequest::required($form, 'role_id'),
                );
                return;
            case 'resource_policy.create':
                $this->security->createResourcePolicy(
                    $context,
                    AdministratorRequest::required($form, 'policy_code'),
                    AdministratorRequest::required($form, 'operation'),
                    AdministratorRequest::required($form, 'effect'),
                    $this->optional($form['organization_id'] ?? null),
                    AdministratorRequest::required($form, 'definition_id'),
                    AdministratorRequest::required($form, 'predicate_type'),
                    $this->optional($form['predicate_field'] ?? null),
                    $this->optional($form['predicate_operator'] ?? null),
                    $this->optional($form['predicate_value_type'] ?? null),
                    $this->optional($form['predicate_value'] ?? null),
                    $this->fieldRules($form),
                    $this->integer($form['priority'] ?? '0', 'priority'),
                );
                return;
            case 'resource_policy.status':
                $this->security->changeResourcePolicyStatus(
                    $context,
                    AdministratorRequest::required($form, 'id'),
                    AdministratorRequest::required($form, 'status'),
                    AdministratorRequest::positiveInteger($form, 'version'),
                );
                return;
            case 'separation_duty.create':
                $this->security->createSeparationRule(
                    $context,
                    AdministratorRequest::required($form, 'rule_code'),
                    AdministratorRequest::required($form, 'resource_type'),
                    AdministratorRequest::required($form, 'request_action'),
                    AdministratorRequest::required($form, 'approval_action'),
                    $this->optional($form['organization_id'] ?? null),
                    $this->optional($form['requester_role_id'] ?? null),
                    $this->optional($form['approver_role_id'] ?? null),
                    AdministratorRequest::positiveInteger($form, 'quorum'),
                    ($form['distinct_actors'] ?? null) === '1',
                );
                return;
            case 'separation_duty.status':
                $this->security->changeSeparationRuleStatus(
                    $context,
                    AdministratorRequest::required($form, 'id'),
                    AdministratorRequest::required($form, 'status'),
                    AdministratorRequest::positiveInteger($form, 'version'),
                );
                return;
            case 'approval.approve':
                $this->approvals->approve(
                    $context,
                    AdministratorRequest::required($form, 'request_id'),
                    $this->optional($form['reason'] ?? null),
                );
                return;
            case 'approval.reject':
                $this->approvals->reject(
                    $context,
                    AdministratorRequest::required($form, 'request_id'),
                    $this->optional($form['reason'] ?? null),
                );
                return;
            case 'approval.cancel':
                $this->approvals->cancel(
                    $context,
                    AdministratorRequest::required($form, 'request_id'),
                );
                return;
            case 'approval.revoke':
                $this->approvals->revoke(
                    $context,
                    AdministratorRequest::required($form, 'request_id'),
                );
                return;
        }

        throw new InvalidArgumentException('The Business Security action is unsupported.');
    }

    /**
     * Resolve the exact proof purpose consumed by the target service.
     *
     * @param   string  $action  Closed form action identifier.
     *
     * @return  string  Exact purpose consumed by the mutation.
     *
     * @since   2.0.0
     */
    private function purpose(string $action): string
    {
        return match ($action) {
            'approval.approve', 'approval.reject', 'approval.revoke' => 'business.' . $action,
            default => BusinessSecurityAdministrationService::stepUpPurpose($action),
        };
    }

    /**
     * Build the exact actor, session, scope, purpose, and epoch challenge intent.
     *
     * @param   ExecutionContext  $context  Current authenticated administrator context.
     * @param   string            $purpose  Closed mutation purpose.
     *
     * @return  StepUpIntent  Exact intent for the administrator provider.
     *
     * @since   2.0.0
     */
    private function stepUpIntent(ExecutionContext $context, string $purpose): StepUpIntent
    {
        $principal = $context->principal()
            ?? throw new InvalidArgumentException('Business Security step-up requires a human actor.');

        return new StepUpIntent(
            $principal->subject(),
            $context->sessionId()
                ?? throw new InvalidArgumentException('Business Security step-up requires a live session.'),
            $context->site()->identifier(),
            $context->organization()?->identifier(),
            $context->workspace()?->identifier(),
            $purpose,
            $principal->securityEpoch(),
        );
    }

    /**
     * Extract explicit field and action selections by policy usage.
     *
     * @param   array<string, string>  $form  Flattened structured form.
     *
     * @return  array<string, list<string>>  Explicit selections by usage.
     *
     * @since   2.0.0
     */
    private function fieldRules(array $form): array
    {
        $rules = [];
        $usages = array_map(
            static fn (FieldAccessUsage $usage): string => $usage->value,
            FieldAccessUsage::cases(),
        );
        $usages[] = 'actions';
        foreach ($usages as $usage) {
            $rules[$usage] = $this->csv($form['fields_' . $usage] ?? '');
        }

        return $rules;
    }

    /**
     * Decode one bounded comma-separated multi-selection.
     *
     * @param   string  $value  Flattened multi-select value.
     *
     * @return  list<string>  Sorted, unique selected values.
     *
     * @since   2.0.0
     */
    private function csv(string $value): array
    {
        $values = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $item): bool => $item !== '',
        )));
        if (count($values) > 256) {
            throw new InvalidArgumentException('A Business Security multi-select exceeds 256 values.');
        }
        sort($values, SORT_STRING);

        return $values;
    }

    /**
     * Normalize a blank optional form value to null.
     *
     * @param   ?string  $value  Optional form value.
     *
     * @return  ?string  Trimmed value or null.
     *
     * @since   2.0.0
     */
    private function optional(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Parse an optional form date.
     *
     * @param   ?string  $value  Optional datetime value.
     *
     * @return  ?DateTimeImmutable  Parsed date or null.
     *
     * @since   2.0.0
     */
    private function optionalDate(?string $value): ?DateTimeImmutable
    {
        $value = $this->optional($value);

        return $value === null ? null : new DateTimeImmutable($value);
    }

    /**
     * Parse a strict signed decimal form integer.
     *
     * @param   string  $value  Raw decimal text.
     * @param   string  $name   Field name used in rejection messages.
     *
     * @return  int  Parsed integer.
     *
     * @since   2.0.0
     */
    private function integer(string $value, string $name): int
    {
        if (preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('The %s field must be an integer.', $name));
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($integer)) {
            throw new InvalidArgumentException(sprintf('The %s field is outside the supported range.', $name));
        }

        return $integer;
    }

    /**
     * Build the administrator-only rotated cookie header.
     *
     * @param   string  $token  New opaque administrator cookie token.
     *
     * @return  string  Hardened Set-Cookie value.
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
     * Resolve the trusted-proxy-normalized attempt source.
     *
     * @param   ServerRequestInterface  $request  Current administrator request.
     *
     * @return  string  Normalized source or `unknown`.
     *
     * @since   2.0.0
     */
    private function source(ServerRequestInterface $request): string
    {
        $source = $request->getAttribute(TrustedProxyMiddleware::ATTRIBUTE_CLIENT_ADDRESS, 'unknown');

        return is_string($source) ? $source : 'unknown';
    }
}
