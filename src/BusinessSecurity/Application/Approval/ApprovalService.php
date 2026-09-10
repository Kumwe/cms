<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSecurity\Application\Approval;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\BusinessSecurity\Application\MembershipDirectory;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * Canonical maker-checker workflow for every high-impact application service and adapter.
 *
 * @since  2.0.0
 */
final readonly class ApprovalService
{
    /**
     * Wire the relational workflow, authorization, live membership and replay fences.
     *
     * @param  ApprovalRepository           $repository     Approval and SoD store.
     * @param  StepUpProofConsumer          $stepUp         Atomic proof replay fence.
     * @param  MembershipDirectory          $memberships    Live membership/version resolver.
     * @param  TransactionManager           $transactions   Atomic workflow boundary.
     * @param  AuthorizationGateway         $authorization  Canonical authorization gateway.
     * @param  ResourceSiteOwnershipWriter  $ownership      Site ownership for item-level approval decisions.
     * @param  AuditRecorder                $audit          Durable audit sink.
     * @param  ClockInterface               $clock          Trusted current time.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ApprovalRepository $repository,
        private StepUpProofConsumer $stepUp,
        private MembershipDirectory $memberships,
        private TransactionManager $transactions,
        private AuthorizationGateway $authorization,
        private ResourceSiteOwnershipWriter $ownership,
        private AuditRecorder $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Create a request bound to an unchanged mutation and the requester's current authority.
     *
     * @param   ExecutionContext  $context   Requesting actor and exact scope.
     * @param   ApprovalBinding   $binding   Canonical action/resource/version/payload binding.
     * @param   ?DateInterval     $lifetime  Requested lifetime, capped at seven days.
     *
     * @return  ?string  New request UUID, or null when no active rule requires approval.
     *
     * @throws  ApprovalDenied  When scope, membership, role or binding does not match.
     * @throws  InvalidArgumentException  When lifetime is not positive or exceeds seven days.
     *
     * @since   2.0.0
     */
    public function request(
        ExecutionContext $context,
        ApprovalBinding $binding,
        ?DateInterval $lifetime = null,
    ): ?string {
        $this->assertBindingContext($context, $binding);
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('business.approval.request'),
            AuthorizationResource::item($binding->resourceType(), $binding->resourceId()),
        );
        $rule = $this->repository->rule($binding);
        if ($rule === null) {
            return null;
        }
        if (!$this->repository->requesterEligible($rule, $context)) {
            throw new ApprovalDenied();
        }
        $now = $this->clock->now();
        $expiresAt = $now->add($lifetime ?? new DateInterval('P1D'));
        if ($expiresAt <= $now || $expiresAt > $now->add(new DateInterval('P7D'))) {
            throw new InvalidArgumentException('Approval lifetime must be positive and no longer than seven days.');
        }
        $id = Uuid::uuid7()->toString();

        return $this->transactions->transactional(function () use (
            $context,
            $binding,
            $rule,
            $now,
            $expiresAt,
            $id,
        ): string {
            $this->assertCurrentMembership($context, true);
            $this->repository->insert($id, $rule, $binding, $expiresAt, $now);
            $this->ownership->record(
                AuthorizationResource::item('approval_request', $id),
                $context->site(),
            );
            $this->audit($context, 'approval.request', $id, [
                'binding_digest' => $binding->digest(),
                'rule_id' => $rule->id,
                'rule_version' => $rule->version,
                'approval_action' => $rule->approvalAction,
                'approver_role_id' => $rule->approverRoleId,
                'distinct_actors' => $rule->distinctActors,
                'required_quorum' => $rule->quorum,
                'expires_at' => $expiresAt->format(DATE_ATOM),
            ]);

            return $id;
        });
    }

    /**
     * Record one distinct, eligible, step-up-authenticated approval and advance on quorum.
     *
     * @param   ExecutionContext  $context    Approving actor with a fresh proof.
     * @param   string            $requestId  Request UUID.
     * @param   ?string           $reason     Optional bounded operator note.
     *
     * @return  ApprovalStatus  `Approved` when quorum was reached, otherwise `Pending`.
     *
     * @throws  ApprovalDenied  For every absent, stale, repeated, ineligible or mismatched request.
     *
     * @since   2.0.0
     */
    public function approve(ExecutionContext $context, string $requestId, ?string $reason = null): ApprovalStatus
    {
        return $this->decide($context, $requestId, 'approve', $reason);
    }

    /**
     * Reject a request with a fresh, single-use step-up proof.
     *
     * @param   ExecutionContext  $context    Rejecting actor with a fresh proof.
     * @param   string            $requestId  Request UUID.
     * @param   ?string           $reason     Optional bounded operator note.
     *
     * @return  ApprovalStatus  Always `Rejected` on success.
     *
     * @throws  ApprovalDenied  For every absent, stale, repeated, ineligible or mismatched request.
     *
     * @since   2.0.0
     */
    public function reject(ExecutionContext $context, string $requestId, ?string $reason = null): ApprovalStatus
    {
        return $this->decide($context, $requestId, 'reject', $reason);
    }

    /**
     * Cancel the requester's own still-pending request.
     *
     * @param   ExecutionContext  $context    Original requester and exact context.
     * @param   string            $requestId  Request UUID.
     *
     * @return  void
     *
     * @throws  ApprovalDenied  When the request is not the actor's current pending request.
     *
     * @since   2.0.0
     */
    public function cancel(ExecutionContext $context, string $requestId): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('business.approval.request'),
            AuthorizationResource::item('approval_request', $requestId),
        );
        $this->transactions->transactional(function () use ($context, $requestId): void {
            $request = $this->repository->lock($requestId);
            if (
                $request === null
                || $request->status !== ApprovalStatus::Pending
                || $request->binding->requesterId() !== $context->actorId()
                || $request->binding->contextFingerprint() !== $context->approvalFingerprint()
            ) {
                throw new ApprovalDenied();
            }
            $this->assertCurrentMembership($context, true);
            $this->repository->transition(
                $requestId,
                ApprovalStatus::Pending,
                ApprovalStatus::Cancelled,
                $request->version,
                $this->clock->now(),
            );
            $this->audit($context, 'approval.cancel', $requestId);
        });
    }

    /**
     * Revoke a pending or approved request under management authority and fresh step-up.
     *
     * @param   ExecutionContext  $context    Managing actor with a fresh proof.
     * @param   string            $requestId  Request UUID.
     *
     * @return  void
     *
     * @throws  ApprovalDenied  When the request is absent, terminal, stale or the proof cannot be consumed.
     *
     * @since   2.0.0
     */
    public function revoke(ExecutionContext $context, string $requestId): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('business.approval.manage'),
            AuthorizationResource::item('approval_request', $requestId),
        );
        $this->transactions->transactional(function () use ($context, $requestId): void {
            $request = $this->repository->lock($requestId);
            if (
                $request === null
                || !in_array($request->status, [ApprovalStatus::Pending, ApprovalStatus::Approved], true)
                || $request->binding->siteIdentifier() !== $context->site()->identifier()
                || $request->binding->organization() !== $context->organization()?->identifier()
                || $request->binding->workspace() !== $context->workspace()?->identifier()
            ) {
                throw new ApprovalDenied();
            }
            $this->assertCurrentMembership($context, true);
            $now = $this->clock->now();
            $proof = $context->stepUpProof() ?? throw new ApprovalDenied();
            $this->stepUp->consume($proof, $context, 'business.approval.revoke', $now);
            $this->repository->transition(
                $requestId,
                $request->status,
                ApprovalStatus::Revoked,
                $request->version,
                $now,
            );
            $this->audit($context, 'approval.revoke', $requestId);
        });
    }

    /**
     * Atomically consume an approved request for the exact bound mutation.
     *
     * Application services call this inside their own transaction immediately before changing the
     * resource, so a replay, version change, membership change or payload substitution cannot land.
     *
     * @param   ExecutionContext  $context    Original requester and unchanged authority context.
     * @param   string            $requestId  Approved request UUID.
     * @param   ApprovalBinding   $binding    Binding rebuilt from current resource version and payload.
     *
     * @return  void
     *
     * @throws  ApprovalDenied  When any binding, state, expiry or membership check fails.
     *
     * @since   2.0.0
     */
    public function consume(ExecutionContext $context, string $requestId, ApprovalBinding $binding): void
    {
        $this->assertBindingContext($context, $binding);
        $this->transactions->transactional(function () use ($context, $requestId, $binding): void {
            $request = $this->repository->lock($requestId);
            $now = $this->clock->now();
            if (
                $request === null
                || $request->status !== ApprovalStatus::Approved
                || $request->expiresAt <= $now
                || !hash_equals($request->binding->digest(), $binding->digest())
            ) {
                throw new ApprovalDenied();
            }
            $this->assertCurrentMembership($context, true);
            $proof = $context->stepUpProof() ?? throw new ApprovalDenied();
            $this->stepUp->consume($proof, $context, $binding->action(), $now);
            $this->repository->transition(
                $requestId,
                ApprovalStatus::Approved,
                ApprovalStatus::Consumed,
                $request->version,
                $now,
            );
            $this->audit($context, 'approval.consume', $requestId, ['binding_digest' => $binding->digest()]);
        });
    }

    /**
     * Apply an approve or reject decision under identical checks.
     *
     * @param   ExecutionContext  $context    Deciding actor.
     * @param   string            $requestId  Request UUID.
     * @param   string            $decision   `approve` or `reject`.
     * @param   ?string           $reason     Optional bounded note.
     *
     * @return  ApprovalStatus  Resulting request state.
     *
     * @throws  ApprovalDenied  When any decision precondition fails.
     *
     * @since   2.0.0
     */
    private function decide(
        ExecutionContext $context,
        string $requestId,
        string $decision,
        ?string $reason,
    ): ApprovalStatus {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('business.approval.approve'),
            AuthorizationResource::item('approval_request', $requestId),
        );
        $reason = $reason === null ? null : trim($reason);
        if ($reason !== null && ($reason === '' || mb_strlen($reason) > 500)) {
            throw new ApprovalDenied();
        }

        return $this->transactions->transactional(function () use (
            $context,
            $requestId,
            $decision,
            $reason,
        ): ApprovalStatus {
            $request = $this->repository->lock($requestId);
            $now = $this->clock->now();
            if (
                $request === null
                || $request->status !== ApprovalStatus::Pending
                || $request->expiresAt <= $now
                || !$this->repository->approverEligible($request, $context)
            ) {
                throw new ApprovalDenied();
            }
            $this->authorization->assertAllowed(
                $context,
                Capability::fromString($request->approvalAction),
                AuthorizationResource::item('approval_request', $requestId),
            );
            $this->assertCurrentMembership($context, true);
            $proof = $context->stepUpProof() ?? throw new ApprovalDenied();
            $proofId = $this->stepUp->consume($proof, $context, 'business.approval.' . $decision, $now);
            $this->repository->vote(
                Uuid::uuid7()->toString(),
                $requestId,
                $context->actorId(),
                $decision,
                $reason,
                $context->authorizationFingerprint(),
                $proofId,
                $now,
            );

            $status = ApprovalStatus::Pending;
            if ($decision === 'reject') {
                $status = ApprovalStatus::Rejected;
            } elseif ($this->repository->approvalCount($requestId) >= $request->quorum) {
                $status = ApprovalStatus::Approved;
            }
            if ($status !== ApprovalStatus::Pending) {
                $this->repository->transition(
                    $requestId,
                    ApprovalStatus::Pending,
                    $status,
                    $request->version,
                    $now,
                );
            }
            $this->audit($context, 'approval.' . $decision, $requestId, ['status' => $status->value]);

            return $status;
        });
    }

    /**
     * Require an unchanged actor and scope before creating or consuming a binding.
     *
     * @param   ExecutionContext  $context  Current actor and scope.
     * @param   ApprovalBinding   $binding  Expected action binding.
     *
     * @return  void
     *
     * @throws  ApprovalDenied  When any value differs.
     *
     * @since   2.0.0
     */
    private function assertBindingContext(ExecutionContext $context, ApprovalBinding $binding): void
    {
        if (
            $binding->requesterId() !== $context->actorId()
            || $binding->siteIdentifier() !== $context->site()->identifier()
            || $binding->organization() !== $context->organization()?->identifier()
            || $binding->workspace() !== $context->workspace()?->identifier()
            || !hash_equals($binding->contextFingerprint(), $context->approvalFingerprint())
        ) {
            throw new ApprovalDenied();
        }
    }

    /**
     * Require that a context's membership snapshot still matches a locked live row.
     *
     * @param   ExecutionContext  $context  Context being authorized.
     * @param   bool              $lock     Whether the following operation mutates state.
     *
     * @return  void
     *
     * @throws  ApprovalDenied  When an organization context is absent or stale.
     *
     * @since   2.0.0
     */
    private function assertCurrentMembership(ExecutionContext $context, bool $lock): void
    {
        $membership = $context->membership();
        if (
            $membership !== null && !$this->memberships->current(
                $context->actorId(),
                $context->site(),
                $membership,
                $lock,
            )
        ) {
            throw new ApprovalDenied();
        }
    }

    /**
     * Record a workflow transition without raw payload or secret material.
     *
     * @param   ExecutionContext     $context    Accountable actor and scope.
     * @param   string               $action     Audit action.
     * @param   string               $requestId  Approval request UUID.
     * @param   array<string,mixed>  $metadata   Safe additional evidence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function audit(
        ExecutionContext $context,
        string $action,
        string $requestId,
        array $metadata = [],
    ): void {
        $this->audit->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $this->clock->now(),
            $context->actorId(),
            $action,
            'approval_request',
            $requestId,
            'success',
            [
                'site' => $context->site()->identifier(),
                'organization' => $context->organization()?->identifier(),
                'workspace' => $context->workspace()?->identifier(),
                ...$metadata,
            ],
        ));
    }
}
