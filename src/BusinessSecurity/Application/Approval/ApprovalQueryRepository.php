<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSecurity\Application\Approval;

use DateTimeImmutable;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Scope-aware read store for approval inbox and immutable request detail projections.
 *
 * @since  2.0.0
 */
interface ApprovalQueryRepository
{
    /**
     * List requests visible to the actor through one or more pre-authorized visibility modes.
     *
     * @param   ExecutionContext   $context          Current actor and exact scope.
     * @param   bool               $includeOwn       Whether requester-owned rows are visible.
     * @param   bool               $includeEligible  Whether currently eligible rows are visible.
     * @param   bool               $includeManaged   Whether approval managers may see all scoped rows.
     * @param   DateTimeImmutable  $at               Trusted visibility and expiry instant.
     * @param   int                $limit            Maximum number of rows to return.
     *
     * @return  list<ApprovalRequestView>  Visible requests, newest first and bounded by `$limit`.
     *
     * @since   2.0.0
     */
    public function visible(
        ExecutionContext $context,
        bool $includeOwn,
        bool $includeEligible,
        bool $includeManaged,
        DateTimeImmutable $at,
        int $limit,
    ): array;

    /**
     * Find one request only when it is visible through an authorized mode in the exact actor scope.
     *
     * @param   ExecutionContext   $context          Current actor and exact scope.
     * @param   string             $requestId        Exact approval request UUID.
     * @param   bool               $includeOwn       Whether requester-owned rows are visible.
     * @param   bool               $includeEligible  Whether currently eligible rows are visible.
     * @param   bool               $includeManaged   Whether approval managers may see all scoped rows.
     * @param   DateTimeImmutable  $at               Trusted visibility and expiry instant.
     *
     * @return  ?ApprovalRequestView  Exact visible request or null without enumeration detail.
     *
     * @since   2.0.0
     */
    public function findVisible(
        ExecutionContext $context,
        string $requestId,
        bool $includeOwn,
        bool $includeEligible,
        bool $includeManaged,
        DateTimeImmutable $at,
    ): ?ApprovalRequestView;
}
