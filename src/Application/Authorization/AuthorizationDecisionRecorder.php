<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Authorization;

use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Identity\Domain\Capability;

/**
 * Port that receives every authorization decision the gateway reaches, allow or deny alike.
 *
 * The gateway calls this before it returns a permit or raises a denial, so the sink an implementation
 * writes to is the authoritative record of what was attempted, not only of what succeeded. Recording is
 * treated as part of the security boundary: an implementation that throws makes the gateway abandon an
 * otherwise permitted action with `AuthorizationAuditUnavailable`, so an implementation should raise
 * only when the record genuinely did not reach its sink. It sits on the hot path of every authorized
 * operation, including read checks, which is the constraint on how much work it may do per call.
 *
 * @since  2.0.0
 */
interface AuthorizationDecisionRecorder
{
    /**
     * Record one evaluated decision against the actor, action, and resource it concerned.
     *
     * @param   ExecutionContext       $context   Actor, site, and request correlation the decision was
     *          made for.
     * @param   Capability             $action    Capability that was being exercised.
     * @param   AuthorizationResource  $resource  Resource the action was aimed at.
     * @param   AuthorizationDecision  $decision  Outcome, with the policy and reason that produced it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function record(
        ExecutionContext $context,
        Capability $action,
        AuthorizationResource $resource,
        AuthorizationDecision $decision,
    ): void;
}
