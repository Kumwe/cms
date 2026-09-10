<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Application;

use InvalidArgumentException;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalQueryService;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalRequestView;
use Kumwe\Context\Value\ExecutionContext;
use Ramsey\Uuid\Uuid;

/**
 * Narrows generic approval visibility to live generated-business surface contracts.
 *
 * The approval query service remains the authority for requester, approver, manager, membership, and
 * scope visibility. This service adds the missing generated-surface ceiling: business endpoints receive
 * only business-record requests, and portal presentation additionally requires the definition's exact
 * approval opt-in and action exposure. Non-business approvals continue through the generic portal inbox.
 *
 * @since  2.0.0
 */
final readonly class BusinessApprovalSurfaceService
{
    /**
     * Most generic approval candidates inspected to fill one filtered page.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int CANDIDATE_LIMIT = 100;

    /**
     * Prefix used by the record service for exact action approval bindings.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string ACTION_PREFIX = 'business.record.action:';

    /**
     * Bind generic scoped approval queries to generated definition/action exposure.
     *
     * @param  ApprovalQueryService             $approvals  Canonical scoped approval query boundary.
     * @param  BusinessApprovalExposureCatalog  $exposure   Active definition and surface exposure ceiling.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ApprovalQueryService $approvals,
        private BusinessApprovalExposureCatalog $exposure,
    ) {
    }

    /**
     * List only live business-record action approvals for one generated machine adapter.
     *
     * @param   ExecutionContext  $context  Authenticated actor and exact scope.
     * @param   BusinessSurface   $surface  API, CLI, or another provenance-matching generated adapter.
     * @param   int               $limit    Maximum returned rows, from one through one hundred.
     *
     * @return  list<ApprovalRequestView>  Bounded live business-record approvals.
     *
     * @since   2.0.0
     */
    public function businessInbox(
        ExecutionContext $context,
        BusinessSurface $surface,
        int $limit = 50,
    ): array {
        return $this->inbox($context, $surface, $limit, true);
    }

    /**
     * Read one live business-record action approval without enumerating another resource family.
     *
     * @param   ExecutionContext  $context    Authenticated actor and exact scope.
     * @param   BusinessSurface   $surface    Provenance-matching generated adapter.
     * @param   string            $requestId  Exact approval UUID.
     *
     * @return  ApprovalRequestView|null  Visible live business approval, or null for every denial.
     *
     * @since   2.0.0
     */
    public function businessDetail(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $requestId,
    ): ?ApprovalRequestView {
        return $this->detail($context, $surface, $requestId, true);
    }

    /**
     * List the generic portal inbox while omitting business approvals not explicitly portal-exposed.
     *
     * @param   ExecutionContext  $context  Authenticated portal actor and exact scope.
     * @param   int               $limit    Maximum returned rows, from one through one hundred.
     *
     * @return  list<ApprovalRequestView>  Non-business requests plus live portal-enabled business requests.
     *
     * @since   2.0.0
     */
    public function portalInbox(ExecutionContext $context, int $limit = 50): array
    {
        return $this->inbox($context, BusinessSurface::Portal, $limit, false);
    }

    /**
     * Read one generic portal approval with the generated-business exposure ceiling applied when relevant.
     *
     * @param   ExecutionContext  $context    Authenticated portal actor and exact scope.
     * @param   string            $requestId  Exact approval UUID.
     *
     * @return  ApprovalRequestView|null  Visible non-business or portal-exposed business request.
     *
     * @since   2.0.0
     */
    public function portalDetail(ExecutionContext $context, string $requestId): ?ApprovalRequestView
    {
        return $this->detail($context, BusinessSurface::Portal, $requestId, false);
    }

    /**
     * Filter one bounded generic inbox through business classification and batch exposure.
     *
     * @param   ExecutionContext  $context       Authenticated actor and exact scope.
     * @param   BusinessSurface   $surface       Provenance-matching generated adapter.
     * @param   int               $limit         Maximum returned rows.
     * @param   bool              $businessOnly  Whether unrelated approval resource families are omitted.
     *
     * @return  list<ApprovalRequestView>  Filtered approval page in repository order.
     *
     * @since   2.0.0
     */
    private function inbox(
        ExecutionContext $context,
        BusinessSurface $surface,
        int $limit,
        bool $businessOnly,
    ): array {
        self::limit($limit);
        if (BusinessSurface::fromAuthenticated($context->surface()) !== $surface) {
            return [];
        }
        $candidates = $this->approvals->inbox($context, self::CANDIDATE_LIMIT);
        $bindings = [];
        foreach ($candidates as $candidate) {
            $binding = self::binding($candidate);
            if ($binding !== null) {
                $bindings[] = $binding;
            }
        }
        $exposed = $this->exposure->approvalActions($context, $surface, $bindings);
        $results = [];
        foreach ($candidates as $candidate) {
            $binding = self::binding($candidate);
            if ($binding === null) {
                if ($businessOnly || $candidate->resourceType === 'business_record') {
                    continue;
                }
            } elseif (!isset($exposed[$candidate->id])) {
                continue;
            }
            $results[] = $candidate;
            if (count($results) === $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * Filter one exact generic approval without distinguishing malformed, stale, or unexposed bindings.
     *
     * @param   ExecutionContext  $context       Authenticated actor and exact scope.
     * @param   BusinessSurface   $surface       Provenance-matching generated adapter.
     * @param   string            $requestId     Exact approval UUID.
     * @param   bool              $businessOnly  Whether unrelated approval resource families are omitted.
     *
     * @return  ApprovalRequestView|null  Surviving request, or null for every unavailable identity.
     *
     * @since   2.0.0
     */
    private function detail(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $requestId,
        bool $businessOnly,
    ): ?ApprovalRequestView {
        if (BusinessSurface::fromAuthenticated($context->surface()) !== $surface) {
            return null;
        }
        $candidate = $this->approvals->detail($context, $requestId);
        if (!$candidate instanceof ApprovalRequestView) {
            return null;
        }
        $binding = self::binding($candidate);
        if ($binding === null) {
            return !$businessOnly && $candidate->resourceType !== 'business_record' ? $candidate : null;
        }
        $exposed = $this->exposure->approvalActions($context, $surface, [$binding]);

        return isset($exposed[$candidate->id]) ? $candidate : null;
    }

    /**
     * Parse the exact internal binding produced for a business-record action approval.
     *
     * @param   ApprovalRequestView  $request  Scoped generic approval projection.
     *
     * @return  array{request_id: string, definition_id: string, action: string}|null  Canonical binding or null.
     *
     * @since   2.0.0
     */
    private static function binding(ApprovalRequestView $request): ?array
    {
        if ($request->resourceType !== 'business_record' || !str_starts_with($request->action, self::ACTION_PREFIX)) {
            return null;
        }
        $resource = explode(':', $request->resourceId, 2);
        $action = substr($request->action, strlen(self::ACTION_PREFIX));
        if (
            count($resource) !== 2
            || !Uuid::isValid($request->id)
            || !Uuid::isValid($resource[0])
            || !Uuid::isValid($resource[1])
            || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $action) !== 1
        ) {
            return null;
        }

        return [
            'request_id' => $request->id,
            'definition_id' => $resource[0],
            'action' => $action,
        ];
    }

    /**
     * Validate a caller-facing approval page bound.
     *
     * @param   int  $limit  Requested maximum rows.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the limit falls outside one through one hundred.
     *
     * @since   2.0.0
     */
    private static function limit(int $limit): void
    {
        if ($limit < 1 || $limit > self::CANDIDATE_LIMIT) {
            throw new InvalidArgumentException('An approval inbox limit must be between one and one hundred.');
        }
    }
}
