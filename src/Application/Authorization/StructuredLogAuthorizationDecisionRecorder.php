<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Authorization;

use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Psr\Log\LoggerInterface;

/**
 * Writes each authorization decision to the PSR-3 log as one structured record.
 *
 * This is the recorder the composition root wires into `DenyByDefaultAuthorizationGateway`, and so the
 * running trail of who was allowed or refused what — every decision the gateway reaches passes through
 * here, including the ones it goes on to turn into an `AuthorizationDenied`. Refusals are logged at
 * warning and grants at info, so an operator can alert on refusals without switching the application to
 * debug logging. Failing to write matters: the gateway converts an exception raised here into
 * `AuthorizationAuditUnavailable` whenever the decision was an allow, so a log sink that cannot accept
 * the record stops the grant instead of letting unrecorded access through.
 *
 * @since  2.0.0
 */
final readonly class StructuredLogAuthorizationDecisionRecorder implements AuthorizationDecisionRecorder
{
    /**
     * Bind the recorder to the log the decision trail is written to.
     *
     * @param  LoggerInterface  $logger  Sink every decision record is emitted to, at info or warning.
     *
     * @since  2.0.0
     */
    public function __construct(private LoggerInterface $logger)
    {
    }

    /**
     * Log one decision together with the actor, action, resource and request identity behind it.
     *
     * The record holds identifiers, the policy name and its reason token only — never a credential, a
     * grant listing or a request body — because it lands in the ordinary application log rather than in
     * the tamper-evident audit store. `request_id` and `correlation_id` are carried so that the
     * decisions taken across one request, or one chain of dispatched work, can be gathered afterwards.
     *
     * @param   ExecutionContext       $context   Actor, site and request identity the decision was made for.
     * @param   Capability             $action    Capability that was checked, such as `content.publish`.
     * @param   AuthorizationResource  $resource  Type and identifier of the thing the action was aimed at.
     * @param   AuthorizationDecision  $decision  Outcome, with the policy and reason token that produced it.
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
    ): void {
        $record = [
            'subject' => $context->actorId(),
            'action' => $action->value(),
            'resource_type' => $resource->type(),
            'resource_id' => $resource->identifier(),
            'site' => $context->site()->identifier(),
            'authentication_strength' => $context->authenticationStrength()->value,
            'request_id' => $context->requestId(),
            'correlation_id' => $context->correlationId(),
            'policy' => $decision->policy,
            'reason' => $decision->reason,
            'allowed' => $decision->allowed,
        ];

        if ($decision->allowed) {
            $this->logger->info('Authorization decision.', $record);
        } else {
            $this->logger->warning('Authorization decision.', $record);
        }
    }
}
