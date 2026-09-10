<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSecurity\Application\Approval;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\BusinessSecurity\Application\MembershipDirectory;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * Non-enumerating application query boundary for approval inbox and request detail.
 *
 * @since  2.0.0
 */
final readonly class ApprovalQueryService
{
    /**
     * Bind queries to scoped persistence, canonical authorization, membership freshness, and time.
     *
     * @param  ApprovalQueryRepository  $repository     Scope-aware projection store.
     * @param  AuthorizationGateway     $authorization  Canonical deny-by-default gateway.
     * @param  MembershipDirectory      $memberships    Live membership freshness authority.
     * @param  ClockInterface           $clock          Trusted visibility and expiry clock.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ApprovalQueryRepository $repository,
        private AuthorizationGateway $authorization,
        private MembershipDirectory $memberships,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Return only requests the current requester, eligible approver, or approval manager may observe.
     *
     * @param   ExecutionContext  $context  Current actor and exact organization/workspace scope.
     * @param   int               $limit    Maximum rows, from one through one hundred.
     *
     * @return  list<ApprovalRequestView>  Bounded current-scope inbox.
     *
     * @since   2.0.0
     */
    public function inbox(ExecutionContext $context, int $limit = 50): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('An approval inbox limit must be between one and one hundred.');
        }
        if (!$this->current($context)) {
            return [];
        }
        [$own, $eligible, $managed] = $this->access($context);
        if (!$own && !$eligible && !$managed) {
            return [];
        }

        return $this->repository->visible(
            $context,
            $own,
            $eligible,
            $managed,
            $this->clock->now(),
            $limit,
        );
    }

    /**
     * Return one exact request only if it belongs to the current scope and visibility set.
     *
     * @param   ExecutionContext  $context    Current actor and exact organization/workspace scope.
     * @param   string            $requestId  Approval request UUID.
     *
     * @return  ?ApprovalRequestView  Visible detail or null for absent and unauthorized identities alike.
     *
     * @since   2.0.0
     */
    public function detail(ExecutionContext $context, string $requestId): ?ApprovalRequestView
    {
        if (!Uuid::isValid($requestId) || !$this->current($context)) {
            return null;
        }
        [$own, $eligible, $managed] = $this->access($context);
        if (!$own && !$eligible && !$managed) {
            return null;
        }

        return $this->repository->findVisible(
            $context,
            $requestId,
            $own,
            $eligible,
            $managed,
            $this->clock->now(),
        );
    }

    /**
     * Resolve requester, eligible-approver, and manager collection authority independently.
     *
     * @param   ExecutionContext  $context  Current actor and exact scope.
     *
     * @return  array{bool, bool, bool}  Requester, approver, and manager collection authority.
     *
     * @since   2.0.0
     */
    private function access(ExecutionContext $context): array
    {
        $resource = AuthorizationResource::collection('approval_request');

        return [
            $this->authorization->decide(
                $context,
                Capability::fromString('business.approval.request'),
                $resource,
            )->allowed,
            $this->authorization->decide(
                $context,
                Capability::fromString('business.approval.approve'),
                $resource,
            )->allowed,
            $this->authorization->decide(
                $context,
                Capability::fromString('business.approval.manage'),
                $resource,
            )->allowed,
        ];
    }

    /**
     * Check that the execution context still carries a current membership snapshot.
     *
     * @param   ExecutionContext  $context  Context whose membership must be revalidated.
     *
     * @return  bool  Whether the carried membership snapshot is still current.
     *
     * @since   2.0.0
     */
    private function current(ExecutionContext $context): bool
    {
        $membership = $context->membership();

        return $membership === null || $this->memberships->current(
            $context->actorId(),
            $context->site(),
            $membership,
            false,
        );
    }
}
