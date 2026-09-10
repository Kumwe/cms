<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSecurity\Application\Approval;

use DateTimeImmutable;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Relational store and role-membership predicates for generic maker-checker approval.
 *
 * @since  2.0.0
 */
interface ApprovalRepository
{
    /**
     * Resolve the single active rule matching an exact action binding.
     *
     * @param   ApprovalBinding  $binding  Site, organization, action and resource binding.
     *
     * @return  ?ApprovalRule  Active matching rule, or null when the action requires no approval.
     *
     * @since   2.0.0
     */
    public function rule(ApprovalBinding $binding): ?ApprovalRule;

    /**
     * Check the requester's live role eligibility for a selected rule.
     *
     * @param   ApprovalRule      $rule     Selected active rule.
     * @param   ExecutionContext  $context  Requesting actor and exact membership context.
     *
     * @return  bool  Whether the requester satisfies the rule's optional role restriction.
     *
     * @since   2.0.0
     */
    public function requesterEligible(ApprovalRule $rule, ExecutionContext $context): bool;

    /**
     * Check one prospective approver against the frozen rule and live membership.
     *
     * @param   ApprovalRequest   $request  Locked pending request.
     * @param   ExecutionContext  $context  Prospective approver and exact membership context.
     *
     * @return  bool  Whether the approver satisfies live membership and optional role restriction.
     *
     * @since   2.0.0
     */
    public function approverEligible(ApprovalRequest $request, ExecutionContext $context): bool;

    /**
     * Persist one immutable pending approval request.
     *
     * @param   string             $id         New request UUID.
     * @param   ApprovalRule       $rule       Rule snapshot to freeze.
     * @param   ApprovalBinding    $binding    Non-transferable operation binding.
     * @param   DateTimeImmutable  $expiresAt  Exclusive request expiry.
     * @param   DateTimeImmutable  $createdAt  Creation instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function insert(
        string $id,
        ApprovalRule $rule,
        ApprovalBinding $binding,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $createdAt,
    ): void;

    /**
     * Lock and reconstitute one approval request.
     *
     * @param   string  $id  Request UUID.
     *
     * @return  ?ApprovalRequest  Locked request, or null without enumeration detail.
     *
     * @since   2.0.0
     */
    public function lock(string $id): ?ApprovalRequest;

    /**
     * Insert one immutable actor decision, refusing duplicate actors.
     *
     * @param   string             $id                  Vote UUID.
     * @param   string             $requestId           Parent request UUID.
     * @param   string             $approverId          Deciding actor UUID.
     * @param   string             $decision            Closed approve or reject value.
     * @param   ?string            $reason              Optional bounded operator note.
     * @param   string             $contextFingerprint  Actor authority fingerprint.
     * @param   ?string            $stepUpProofId       Persisted consumed proof UUID.
     * @param   DateTimeImmutable  $at                  Decision instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function vote(
        string $id,
        string $requestId,
        string $approverId,
        string $decision,
        ?string $reason,
        string $contextFingerprint,
        ?string $stepUpProofId,
        DateTimeImmutable $at,
    ): void;

    /**
     * Count distinct approving actors for quorum evaluation.
     *
     * @param   string  $requestId  Request UUID.
     *
     * @return  int  Number of distinct approve votes.
     *
     * @since   2.0.0
     */
    public function approvalCount(string $requestId): int;

    /**
     * Apply one exact optimistic lifecycle transition.
     *
     * @param   string             $requestId        Request UUID.
     * @param   ApprovalStatus     $from             Required current state.
     * @param   ApprovalStatus     $to               Terminal or approved target state.
     * @param   int                $expectedVersion  Required optimistic version.
     * @param   DateTimeImmutable  $at               Transition instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function transition(
        string $requestId,
        ApprovalStatus $from,
        ApprovalStatus $to,
        int $expectedVersion,
        DateTimeImmutable $at,
    ): void;
}
