<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Application;

use InvalidArgumentException;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Identity\Application\StepUp\AuthorizationStepUpProofAdapter;
use Kumwe\App\Identity\Application\StepUp\StepUpProvider;
use Kumwe\App\Identity\Domain\StepUp\StepUpIntent;
use Kumwe\App\Identity\Domain\StepUp\StepUpVerification;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Coordinates fresh generated-action proof issuance and execution inside one application transaction.
 *
 * The caller supplies a purpose resolved from policy-visible action metadata. Actor, session, site,
 * organization, workspace, and security epoch always come from the authenticated execution context;
 * none can be replaced by submitted form data. Verification, session rotation, proof adaptation, approval
 * consumption, and the action mutation share one outer transaction owned by this application service.
 *
 * @since  2.0.0
 */
final readonly class GeneratedBusinessActionStepUp
{
    /**
     * Bind verification output and application work to the shared transaction boundary.
     *
     * @param  AuthorizationStepUpProofAdapter  $proofs        Trusted proof adapter.
     * @param  TransactionManager               $transactions  Atomic proof and action boundary.
     *
     * @since  2.0.0
     */
    public function __construct(
        private AuthorizationStepUpProofAdapter $proofs,
        private TransactionManager $transactions,
    ) {
    }

    /**
     * Verify, elevate, and execute one high-impact action as one atomic application operation.
     *
     * @template T
     *
     * @param   ExecutionContext               $context    Current password-authenticated browser context.
     * @param   string                         $purpose    Exact action purpose resolved from business metadata.
     * @param   array<string, mixed>           $form       Submitted confirmation controls.
     * @param   string                         $source     Trusted-proxy-resolved attempt source.
     * @param   StepUpProvider                 $provider   Surface-specific session-rotating provider.
     * @param   callable(ExecutionContext): T  $operation  Canonical action work using the elevated context.
     *
     * @return  array{0: T, 1: StepUpVerification}  Action result and committed rotated proof.
     *
     * @throws  GeneratedBusinessStepUpInputRejected  When verification controls are incomplete or invalid.
     *
     * @since   2.0.0
     */
    public function execute(
        ExecutionContext $context,
        string $purpose,
        array $form,
        string $source,
        StepUpProvider $provider,
        callable $operation,
    ): array {
        return $this->transactions->transactional(function () use (
            $context,
            $purpose,
            $form,
            $source,
            $provider,
            $operation,
        ): array {
            try {
                $verification = $this->verify($context, $purpose, $form, $source, $provider);
            } catch (InvalidArgumentException $exception) {
                throw new GeneratedBusinessStepUpInputRejected(
                    $exception->getMessage(),
                    previous: $exception,
                );
            }
            $elevated = $this->elevate($context, $verification);

            return [$operation($elevated), $verification];
        });
    }

    /**
     * Verify one authenticator or recovery credential for the exact server-selected purpose.
     *
     * @param   ExecutionContext      $context   Current password-authenticated browser context.
     * @param   string                $purpose   Exact action purpose resolved from business metadata.
     * @param   array<string, mixed>  $form      Submitted confirmation controls.
     * @param   string                $source    Trusted-proxy-resolved attempt source.
     * @param   StepUpProvider        $provider  Surface-specific session-rotating provider.
     *
     * @return  StepUpVerification  Fresh proof paired with a rotated browser session.
     *
     * @throws  InvalidArgumentException  When the verification method or credential is missing.
     *
     * @since   2.0.0
     */
    private function verify(
        ExecutionContext $context,
        string $purpose,
        array $form,
        string $source,
        StepUpProvider $provider,
    ): StepUpVerification {
        $method = $form['verification_method'] ?? null;
        $credential = $form['verification'] ?? null;
        if (!is_string($credential) || trim($credential) === '') {
            throw new InvalidArgumentException('Enter a current authenticator or recovery code.');
        }
        $intent = $this->intent($context, $purpose);

        return match ($method) {
            'totp' => $provider->challenge($intent, $credential, $source),
            'recovery' => $provider->recover($intent, $credential, $source),
            default => throw new InvalidArgumentException('Choose an authenticator or recovery code.'),
        };
    }

    /**
     * Move the request authority onto the provider's exact rotated session and fresh proof.
     *
     * @param   ExecutionContext    $context       Original browser execution context.
     * @param   StepUpVerification  $verification  Successful surface-specific provider result.
     *
     * @return  ExecutionContext  Multi-factor context accepted by approval proof consumption.
     *
     * @throws  InvalidArgumentException  When a non-human or sessionless context reaches the browser flow.
     *
     * @since   2.0.0
     */
    private function elevate(
        ExecutionContext $context,
        StepUpVerification $verification,
    ): ExecutionContext {
        $principal = AuthenticatedPrincipal::of($context)
            ?? throw new InvalidArgumentException('Generated business step-up requires a human actor.');

        return $principal->context(
            $context->site(),
            AuthenticationStrength::MultiFactor,
            $context->requestId(),
            $context->correlationId(),
            $context->surface(),
            $context->membership(),
            $verification->rotatedSession->sessionId,
            $this->proofs->adapt($verification),
        );
    }

    /**
     * Build an intent exclusively from authenticated server-owned context coordinates.
     *
     * @param   ExecutionContext  $context  Current authenticated browser context.
     * @param   string            $purpose  Policy-resolved generated action purpose.
     *
     * @return  StepUpIntent  Actor, old session, scope, purpose, and security epoch binding.
     *
     * @throws  InvalidArgumentException  When the context is non-human or has no live browser session.
     *
     * @since   2.0.0
     */
    private function intent(ExecutionContext $context, string $purpose): StepUpIntent
    {
        $principal = $context->principal()
            ?? throw new InvalidArgumentException('Generated business step-up requires a human actor.');

        return new StepUpIntent(
            $principal->subject(),
            $context->sessionId()
                ?? throw new InvalidArgumentException('Generated business step-up requires a live session.'),
            $context->site()->identifier(),
            $context->organization()?->identifier(),
            $context->workspace()?->identifier(),
            $purpose,
            $principal->securityEpoch(),
        );
    }
}
