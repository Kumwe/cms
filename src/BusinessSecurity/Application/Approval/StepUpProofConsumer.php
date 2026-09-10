<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSecurity\Application\Approval;

use DateTimeImmutable;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\StepUpProof;

/**
 * Atomic replay fence for a fresh step-up proof used by a high-impact decision.
 *
 * @since  2.0.0
 */
interface StepUpProofConsumer
{
    /**
     * Consume an exact proof once, failing closed for stale, revoked, mismatched or replayed proof.
     *
     * @param   StepUpProof        $proof    Proof attached to the current context.
     * @param   ExecutionContext   $context  Actor, rotated session and exact scope.
     * @param   string             $purpose  Exact protected operation purpose.
     * @param   DateTimeImmutable  $at       Trusted current time.
     *
     * @return  string  Persisted proof UUID for audit and approval-vote binding.
     *
     * @since   2.0.0
     */
    public function consume(
        StepUpProof $proof,
        ExecutionContext $context,
        string $purpose,
        DateTimeImmutable $at,
    ): string;
}
