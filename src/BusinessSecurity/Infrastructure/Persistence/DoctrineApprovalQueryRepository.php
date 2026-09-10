<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSecurity\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalBinding;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalQueryRepository;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalRequestView;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalStatus;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalVoteView;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Context\Value\ExecutionContext;
use RuntimeException;

/**
 * Doctrine-backed, scope-filtered approval inbox and immutable detail projections.
 *
 * @since  2.0.0
 */
final readonly class DoctrineApprovalQueryRepository implements ApprovalQueryRepository
{
    /**
     * Bind projection queries to one database and installation table namespace.
     *
     * @param  Connection  $database  Shared DBAL connection.
     * @param  TableNames  $tables    Logical-to-physical table name mapper.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * List scope-filtered requests visible through the pre-authorized actor modes.
     *
     * @param   ExecutionContext   $context          Current actor and exact scope.
     * @param   bool               $includeOwn       Whether requester-owned rows are visible.
     * @param   bool               $includeEligible  Whether currently eligible rows are visible.
     * @param   bool               $includeManaged   Whether approval managers may see all scoped rows.
     * @param   DateTimeImmutable  $at               Trusted visibility and expiry instant.
     * @param   int                $limit            Maximum number of rows to return.
     *
     * @return  list<ApprovalRequestView>  Validated visible projections, newest first.
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
    ): array {
        return array_map(
            fn (array $row): ApprovalRequestView => $this->view(
                $row,
                $context,
                $includeOwn,
                $includeEligible,
                $includeManaged,
                $at,
            ),
            $this->rows(
                $context,
                $includeOwn,
                $includeEligible,
                $includeManaged,
                $at,
                $limit,
                null,
            ),
        );
    }

    /**
     * Find one exact scope-filtered request without distinguishing absence from denial.
     *
     * @param   ExecutionContext   $context          Current actor and exact scope.
     * @param   string             $requestId        Exact approval request UUID.
     * @param   bool               $includeOwn       Whether requester-owned rows are visible.
     * @param   bool               $includeEligible  Whether currently eligible rows are visible.
     * @param   bool               $includeManaged   Whether approval managers may see all scoped rows.
     * @param   DateTimeImmutable  $at               Trusted visibility and expiry instant.
     *
     * @return  ?ApprovalRequestView  Validated visible projection or null.
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
    ): ?ApprovalRequestView {
        $rows = $this->rows(
            $context,
            $includeOwn,
            $includeEligible,
            $includeManaged,
            $at,
            1,
            $requestId,
        );
        if ($rows === []) {
            return null;
        }

        return $this->view($rows[0], $context, $includeOwn, $includeEligible, $includeManaged, $at);
    }

    /**
     * Apply exact scope and requester/eligible-approver/manager visibility before limiting the result.
     *
     * @param   ExecutionContext   $context          Current actor and exact scope.
     * @param   bool               $includeOwn       Whether requester-owned rows are visible.
     * @param   bool               $includeEligible  Whether currently eligible rows are visible.
     * @param   bool               $includeManaged   Whether approval managers may see all scoped rows.
     * @param   DateTimeImmutable  $at               Trusted visibility and expiry instant.
     * @param   int                $limit            Maximum number of rows to return.
     * @param   ?string            $requestId        Optional exact approval request UUID.
     *
     * @return  list<array<string, mixed>>  Bounded stored rows.
     *
     * @since   2.0.0
     */
    private function rows(
        ExecutionContext $context,
        bool $includeOwn,
        bool $includeEligible,
        bool $includeManaged,
        DateTimeImmutable $at,
        int $limit,
        ?string $requestId,
    ): array {
        if (!$includeOwn && !$includeEligible && !$includeManaged) {
            return [];
        }
        $approvalVotes = $this->tables->quoted('approval_votes');
        $query = $this->database->createQueryBuilder();
        $query->select(
            'a.*',
            'r.rule_code',
            'r.version AS live_rule_version',
            'r.status AS rule_status',
            'o.identifier AS organization_identifier',
            'w.identifier AS workspace_identifier',
            sprintf(
                "(SELECT COUNT(DISTINCT vc.approver_id) FROM %s vc "
                . "WHERE vc.request_id = a.id AND vc.decision = 'approve') AS approval_count",
                $approvalVotes,
            ),
        )
            ->from($this->tables->quoted('approval_requests'), 'a')
            ->innerJoin('a', $this->tables->quoted('separation_duty_rules'), 'r', 'r.id = a.rule_id')
            ->leftJoin('a', $this->tables->quoted('organizations'), 'o', 'o.id = a.organization_id')
            ->leftJoin('a', $this->tables->quoted('workspaces'), 'w', 'w.id = a.workspace_id')
            ->where('a.site_identifier = :site')
            ->setParameter('site', $context->site()->identifier())
            ->setMaxResults($limit)
            ->orderBy('a.created_at', 'DESC')
            ->addOrderBy('a.id', 'ASC');
        $membership = $context->membership();
        if ($membership === null) {
            $query->andWhere('a.organization_id IS NULL')->andWhere('a.workspace_id IS NULL');
        } else {
            $query->andWhere('o.identifier = :organization')
                ->setParameter('organization', $membership->organization()->identifier());
            if ($membership->workspace() === null) {
                $query->andWhere('a.workspace_id IS NULL');
            } else {
                $query->andWhere('w.identifier = :workspace')
                    ->setParameter('workspace', $membership->workspace()->identifier());
            }
        }
        if ($requestId !== null) {
            $query->andWhere('a.id = :request_id')->setParameter('request_id', $requestId, Types::GUID);
        }

        $visible = [];
        if ($includeManaged) {
            $visible[] = '1 = 1';
        }
        if ($includeOwn) {
            $visible[] = 'a.requester_id = :actor';
        }
        if ($includeEligible) {
            $role = $membership === null
                ? sprintf(
                    'EXISTS (SELECT 1 FROM %s ur WHERE ur.user_id = :actor '
                    . 'AND ur.role_id = a.approver_role_id)',
                    $this->tables->quoted('user_roles'),
                )
                : sprintf(
                    'EXISTS (SELECT 1 FROM %s mr INNER JOIN %s lm ON lm.id = mr.membership_id '
                    . 'INNER JOIN %s lo ON lo.id = lm.organization_id '
                    . 'WHERE mr.membership_id = :membership_id AND mr.role_id = a.approver_role_id '
                    . 'AND lm.user_id = :actor AND lm.version = :membership_version '
                    . "AND lo.policy_generation = :policy_generation "
                    . "AND lm.status = 'active' AND lo.status = 'active')",
                    $this->tables->quoted('membership_roles'),
                    $this->tables->quoted('organization_memberships'),
                    $this->tables->quoted('organizations'),
                );
            $visible[] = sprintf(
                "(a.status = 'pending' AND a.expires_at > :now AND r.status = 'active' "
                . 'AND r.version = a.rule_version '
                . 'AND (a.distinct_actors = :not_distinct OR a.requester_id <> :actor) '
                . 'AND (a.approver_role_id IS NULL OR %s) '
                . 'AND NOT EXISTS (SELECT 1 FROM %s sv WHERE sv.request_id = a.id '
                . 'AND sv.approver_id = :actor))',
                $role,
                $approvalVotes,
            );
            if ($requestId !== null) {
                $visible[] = sprintf(
                    "(a.status <> 'pending' AND EXISTS (SELECT 1 FROM %s hv WHERE hv.request_id = a.id "
                    . 'AND hv.approver_id = :actor))',
                    $approvalVotes,
                );
            }
            $query->setParameter('now', $at, Types::DATETIME_IMMUTABLE)
                ->setParameter('not_distinct', false, Types::BOOLEAN);
            if ($membership !== null) {
                $query->setParameter('membership_id', $membership->membershipId(), Types::GUID)
                    ->setParameter('membership_version', $membership->membershipVersion(), Types::INTEGER)
                    ->setParameter('policy_generation', $membership->policyGeneration(), Types::INTEGER);
            }
        }
        $query->setParameter('actor', $context->actorId());
        $query->andWhere('(' . implode(' OR ', $visible) . ')');

        return $query->executeQuery()->fetchAllAssociative();
    }

    /**
     * Reconstitute one validated application projection and its actor-specific controls.
     *
     * @param   array<string, mixed>  $row              Stored joined approval row.
     * @param   ExecutionContext      $context          Current actor and exact scope.
     * @param   bool                  $includeOwn       Whether requester authority was granted.
     * @param   bool                  $includeEligible  Whether approver authority was granted.
     * @param   bool                  $includeManaged   Whether manager authority was granted.
     * @param   DateTimeImmutable     $at               Trusted eligibility and expiry instant.
     *
     * @return  ApprovalRequestView  Validated application projection.
     *
     * @since   2.0.0
     */
    private function view(
        array $row,
        ExecutionContext $context,
        bool $includeOwn,
        bool $includeEligible,
        bool $includeManaged,
        DateTimeImmutable $at,
    ): ApprovalRequestView {
        $organization = $this->nullableString($row['organization_identifier'] ?? null);
        $workspace = $this->nullableString($row['workspace_identifier'] ?? null);
        $binding = new ApprovalBinding(
            $this->string($row, 'requester_id'),
            $this->string($row, 'action'),
            $this->string($row, 'resource_type'),
            $this->string($row, 'resource_id'),
            $this->positive($row['resource_version'] ?? null),
            $this->string($row, 'site_identifier'),
            $organization,
            $workspace,
            $this->string($row, 'context_fingerprint'),
            $this->string($row, 'payload_digest'),
        );
        $status = ApprovalStatus::from($this->string($row, 'status'));
        $votes = $this->votes($this->string($row, 'id'));
        $alreadyVoted = array_any(
            $votes,
            static fn (ApprovalVoteView $vote): bool => $vote->approverId === $context->actorId(),
        );
        $canApprove = $includeEligible
            && $status === ApprovalStatus::Pending
            && $this->date($row['expires_at'] ?? null) > $at
            && !$alreadyVoted
            && $this->eligible($row, $context);

        return new ApprovalRequestView(
            $this->string($row, 'id'),
            $this->string($row, 'rule_code'),
            $this->positive($row['rule_version'] ?? null),
            $this->string($row, 'approval_action'),
            $this->nullableString($row['approver_role_id'] ?? null),
            $this->boolean($row['distinct_actors'] ?? null),
            $binding->requesterId(),
            $binding->action(),
            $binding->resourceType(),
            $binding->resourceId(),
            $binding->resourceVersion(),
            $binding->siteIdentifier(),
            $binding->organization(),
            $binding->workspace(),
            $binding->payloadDigest(),
            $binding->digest(),
            $this->positive($row['required_quorum'] ?? null),
            $this->nonNegative($row['approval_count'] ?? null),
            $status,
            $this->date($row['created_at'] ?? null),
            $this->date($row['expires_at'] ?? null),
            $this->positive($row['version'] ?? null),
            $canApprove,
            $includeOwn && $binding->requesterId() === $context->actorId()
                && $status === ApprovalStatus::Pending,
            $includeManaged && in_array($status, [ApprovalStatus::Pending, ApprovalStatus::Approved], true),
            $votes,
        );
    }

    /**
     * Recheck the frozen rule version, maker-checker constraint, and exact approver role.
     *
     * @param   array<string, mixed>  $row      Stored joined approval row.
     * @param   ExecutionContext      $context  Current actor and exact membership.
     *
     * @return  bool  Whether the actor satisfies the live exact-version rule.
     *
     * @since   2.0.0
     */
    private function eligible(array $row, ExecutionContext $context): bool
    {
        if (
            $this->string($row, 'rule_status') !== 'active'
            || $this->positive($row['live_rule_version'] ?? null)
                !== $this->positive($row['rule_version'] ?? null)
        ) {
            return false;
        }
        if (
            $this->boolean($row['distinct_actors'] ?? null)
            && $this->string($row, 'requester_id') === $context->actorId()
        ) {
            return false;
        }
        $roleId = $this->nullableString($row['approver_role_id'] ?? null);
        if ($roleId === null) {
            return true;
        }
        $membership = $context->membership();
        if ($membership === null) {
            return $this->database->fetchOne(sprintf(
                'SELECT 1 FROM %s WHERE user_id = ? AND role_id = ?',
                $this->tables->quoted('user_roles'),
            ), [$context->actorId(), $roleId]) !== false;
        }

        return $this->database->fetchOne(sprintf(
            'SELECT 1 FROM %s mr INNER JOIN %s m ON m.id = mr.membership_id '
            . 'INNER JOIN %s o ON o.id = m.organization_id '
            . 'WHERE mr.membership_id = ? AND mr.role_id = ? AND m.user_id = ? '
            . "AND m.version = ? AND o.policy_generation = ? "
            . "AND m.status = 'active' AND o.status = 'active'",
            $this->tables->quoted('membership_roles'),
            $this->tables->quoted('organization_memberships'),
            $this->tables->quoted('organizations'),
        ), [
            $membership->membershipId(),
            $roleId,
            $context->actorId(),
            $membership->membershipVersion(),
            $membership->policyGeneration(),
        ]) !== false;
    }

    /**
     * Load redacted immutable decisions for one authorized request projection.
     *
     * @param   string  $requestId  Approval request UUID.
     *
     * @return  list<ApprovalVoteView>  Redacted immutable request votes.
     *
     * @since   2.0.0
     */
    private function votes(string $requestId): array
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT id, approver_id, decision, reason, decided_at FROM %s '
            . 'WHERE request_id = ? ORDER BY decided_at, id',
            $this->tables->quoted('approval_votes'),
        ), [$requestId]);

        return array_map(fn (array $row): ApprovalVoteView => new ApprovalVoteView(
            $this->string($row, 'id'),
            $this->string($row, 'approver_id'),
            $this->string($row, 'decision'),
            $this->nullableString($row['reason'] ?? null),
            $this->date($row['decided_at'] ?? null),
        ), $rows);
    }

    /**
     * Read a required string from a database row.
     *
     * @param   array<string, mixed>  $row     Stored row.
     * @param   string                $column  Column to read.
     *
     * @return  string  Required stored string.
     *
     * @since   2.0.0
     */
    private function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException(sprintf('Stored approval projection column %s is invalid.', $column));
        }

        return $value;
    }

    /**
     * Normalize a nullable string returned by any supported database driver.
     *
     * @param   mixed  $value  Driver-returned value.
     *
     * @return  ?string  Optional stored string.
     *
     * @since   2.0.0
     */
    private function nullableString(mixed $value): ?string
    {
        if ($value !== null && !is_string($value)) {
            throw new RuntimeException('A stored approval projection string is invalid.');
        }

        return $value;
    }

    /**
     * Normalize a positive integer returned by any supported database driver.
     *
     * @param   mixed  $value  Driver-returned value.
     *
     * @return  int  Positive portable integer.
     *
     * @since   2.0.0
     */
    private function positive(mixed $value): int
    {
        $integer = $this->nonNegative($value);
        if ($integer < 1) {
            throw new RuntimeException('A stored approval projection integer must be positive.');
        }

        return $integer;
    }

    /**
     * Normalize a non-negative integer returned by any supported database driver.
     *
     * @param   mixed  $value  Driver-returned value.
     *
     * @return  int  Non-negative portable integer.
     *
     * @since   2.0.0
     */
    private function nonNegative(mixed $value): int
    {
        if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) {
            throw new RuntimeException('A stored approval projection integer is invalid.');
        }

        return (int) $value;
    }

    /**
     * Normalize a database boolean without accepting truthy strings.
     *
     * @param   mixed  $value  Driver-returned value.
     *
     * @return  bool  Portable database boolean.
     *
     * @since   2.0.0
     */
    private function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1') {
            return true;
        }
        if ($value === 0 || $value === '0') {
            return false;
        }

        throw new RuntimeException('A stored approval projection boolean is invalid.');
    }

    /**
     * Normalize an immutable timestamp returned by any supported database driver.
     *
     * @param   mixed  $value  Driver-returned value.
     *
     * @return  DateTimeImmutable  Portable immutable datetime.
     *
     * @since   2.0.0
     */
    private function date(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if (!is_string($value)) {
            throw new RuntimeException('A stored approval projection time is invalid.');
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception $exception) {
            throw new RuntimeException('A stored approval projection time is invalid.', 0, $exception);
        }
    }
}
