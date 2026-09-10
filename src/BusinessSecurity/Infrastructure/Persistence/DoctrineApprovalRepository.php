<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSecurity\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalBinding;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalRepository;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalRequest;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalRule;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalStatus;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Context\Value\ExecutionContext;
use RuntimeException;

/**
 * Doctrine implementation of the relational maker-checker and separation-of-duty store.
 *
 * @since  2.0.0
 */
final readonly class DoctrineApprovalRepository implements ApprovalRepository
{
    /**
     * Bind the approval adapter to the installation database.
     *
     * @param  Connection  $database  Installation connection.
     * @param  TableNames  $tables    Portable table-name compiler.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * Resolve the single active site and organization-specific rule for a binding.
     *
     * @param   ApprovalBinding  $binding  Exact protected action and scope.
     *
     * @return  ?ApprovalRule  Matching active rule, or null when approval is not configured.
     *
     * @since   2.0.0
     */
    public function rule(ApprovalBinding $binding): ?ApprovalRule
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT r.id, r.organization_id, r.rule_code, r.approval_action, r.quorum, r.distinct_actors, '
            . 'r.version, r.approver_role_id FROM %s r '
            . 'LEFT JOIN %s o ON o.id = r.organization_id '
            . "WHERE r.status = 'active' AND r.site_identifier = ? "
            . 'AND r.resource_type = ? AND r.request_action = ? '
            . 'AND (r.organization_id IS NULL OR (o.identifier = ? AND o.site_identifier = ?)) '
            . 'ORDER BY CASE WHEN r.organization_id IS NULL THEN 1 ELSE 0 END, r.rule_code',
            $this->tables->quoted('separation_duty_rules'),
            $this->tables->quoted('organizations'),
        ), [
            $binding->siteIdentifier(),
            $binding->resourceType(),
            $binding->action(),
            $binding->organization() ?? '__no_organization__',
            $binding->siteIdentifier(),
        ]);
        if ($rows === []) {
            return null;
        }
        $specific = array_values(array_filter(
            $rows,
            static fn (array $candidate): bool => ($candidate['organization_id'] ?? null) !== null,
        ));
        $candidates = $specific === [] ? $rows : $specific;
        if (count($candidates) !== 1) {
            throw new RuntimeException('Multiple active approval rules match the same protected action.');
        }
        $row = $candidates[0];

        return new ApprovalRule(
            $this->string($row, 'id'),
            $this->string($row, 'rule_code'),
            $this->string($row, 'approval_action'),
            $this->positiveInteger($row['quorum'] ?? null),
            $this->boolean($row['distinct_actors'] ?? null),
            $this->positiveInteger($row['version'] ?? null),
            $this->nullableString($row['approver_role_id'] ?? null),
        );
    }

    /**
     * Check the requester's optional role against current assignments.
     *
     * @param   ApprovalRule      $rule     Selected active rule.
     * @param   ExecutionContext  $context  Requesting actor and exact membership.
     *
     * @return  bool  Whether the requester is eligible.
     *
     * @since   2.0.0
     */
    public function requesterEligible(ApprovalRule $rule, ExecutionContext $context): bool
    {
        return $this->roleEligible($rule->id, 'requester_role_id', $context);
    }

    /**
     * Check one approver against the frozen rule, scope, actor and live role state.
     *
     * @param   ApprovalRequest   $request  Locked pending request.
     * @param   ExecutionContext  $context  Prospective approver and exact membership.
     *
     * @return  bool  Whether the actor may cast one vote.
     *
     * @since   2.0.0
     */
    public function approverEligible(ApprovalRequest $request, ExecutionContext $context): bool
    {
        if (
            $request->binding->siteIdentifier() !== $context->site()->identifier()
            || $request->binding->organization() !== $context->organization()?->identifier()
            || $request->binding->workspace() !== $context->workspace()?->identifier()
        ) {
            return false;
        }
        $liveVersion = $this->database->fetchOne(sprintf(
            "SELECT version FROM %s WHERE id = ? AND status = 'active'",
            $this->tables->quoted('separation_duty_rules'),
        ), [$request->ruleId]);
        if ($this->nullablePositiveInteger($liveVersion) !== $request->ruleVersion) {
            return false;
        }
        if ($request->distinctActors && $request->binding->requesterId() === $context->actorId()) {
            return false;
        }
        if (
            $this->database->fetchOne(sprintf(
                'SELECT 1 FROM %s WHERE request_id = ? AND approver_id = ?',
                $this->tables->quoted('approval_votes'),
            ), [$request->id, $context->actorId()]) !== false
        ) {
            return false;
        }

        return $this->roleIdEligible($request->approverRoleId, $context);
    }

    /**
     * Insert one immutable pending request with frozen rule and scope data.
     *
     * @param   string             $id         Request UUID.
     * @param   ApprovalRule       $rule       Selected rule snapshot.
     * @param   ApprovalBinding    $binding    Non-transferable action binding.
     * @param   DateTimeImmutable  $expiresAt  Exclusive expiry.
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
    ): void {
        $organizationId = $this->scopeId(
            'organizations',
            $binding->organization(),
            $binding->siteIdentifier(),
        );
        $workspaceId = $this->workspaceId($organizationId, $binding->workspace());
        $this->database->insert($this->tables->raw('approval_requests'), [
            'id' => $id,
            'rule_id' => $rule->id,
            'rule_version' => $rule->version,
            'approval_action' => $rule->approvalAction,
            'approver_role_id' => $rule->approverRoleId,
            'distinct_actors' => $rule->distinctActors,
            'site_identifier' => $binding->siteIdentifier(),
            'organization_id' => $organizationId,
            'workspace_id' => $workspaceId,
            'requester_id' => $binding->requesterId(),
            'action' => $binding->action(),
            'resource_type' => $binding->resourceType(),
            'resource_id' => $binding->resourceId(),
            'resource_version' => $binding->resourceVersion(),
            'context_fingerprint' => $binding->contextFingerprint(),
            'payload_digest' => $binding->payloadDigest(),
            'required_quorum' => $rule->quorum,
            'status' => ApprovalStatus::Pending->value,
            'expires_at' => $expiresAt,
            'created_at' => $createdAt,
            'resolved_at' => null,
            'consumed_at' => null,
            'revoked_at' => null,
            'version' => 1,
        ], [
            'expires_at' => Types::DATETIME_IMMUTABLE,
            'created_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * Lock and reconstitute one request with its human-readable scope identifiers.
     *
     * @param   string  $id  Request UUID.
     *
     * @return  ?ApprovalRequest  Locked request, or null when absent.
     *
     * @since   2.0.0
     */
    public function lock(string $id): ?ApprovalRequest
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT a.*, o.identifier AS organization_identifier, w.identifier AS workspace_identifier '
            . 'FROM %s a LEFT JOIN %s o ON o.id = a.organization_id '
            . 'LEFT JOIN %s w ON w.id = a.workspace_id WHERE a.id = ?%s',
            $this->tables->quoted('approval_requests'),
            $this->tables->quoted('organizations'),
            $this->tables->quoted('workspaces'),
            $this->lockClause(),
        ), [$id]);
        if ($row === false) {
            return null;
        }

        $organization = $row['organization_identifier'] ?? null;
        $workspace = $row['workspace_identifier'] ?? null;
        if (($organization !== null && !is_string($organization)) || ($workspace !== null && !is_string($workspace))) {
            throw new RuntimeException('Stored approval scope is invalid.');
        }
        $binding = new ApprovalBinding(
            $this->string($row, 'requester_id'),
            $this->string($row, 'action'),
            $this->string($row, 'resource_type'),
            $this->string($row, 'resource_id'),
            $this->positiveInteger($row['resource_version'] ?? null),
            $this->string($row, 'site_identifier'),
            $organization,
            $workspace,
            $this->string($row, 'context_fingerprint'),
            $this->string($row, 'payload_digest'),
        );

        return new ApprovalRequest(
            $this->string($row, 'id'),
            $this->string($row, 'rule_id'),
            $this->positiveInteger($row['rule_version'] ?? null),
            $this->string($row, 'approval_action'),
            $this->nullableString($row['approver_role_id'] ?? null),
            $this->boolean($row['distinct_actors'] ?? null),
            $binding,
            $this->positiveInteger($row['required_quorum'] ?? null),
            ApprovalStatus::from($this->string($row, 'status')),
            $this->date($row['expires_at'] ?? null),
            $this->positiveInteger($row['version'] ?? null),
        );
    }

    /**
     * Persist one immutable approve or reject vote.
     *
     * @param   string             $id                  Vote UUID.
     * @param   string             $requestId           Request UUID.
     * @param   string             $approverId          Deciding actor UUID.
     * @param   string             $decision            Closed approve or reject value.
     * @param   ?string            $reason              Optional bounded operator note.
     * @param   string             $contextFingerprint  Actor authority fingerprint.
     * @param   ?string            $stepUpProofId       Consumed proof UUID.
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
    ): void {
        if (!in_array($decision, ['approve', 'reject'], true)) {
            throw new InvalidArgumentException('An approval vote decision is invalid.');
        }
        $this->database->insert($this->tables->raw('approval_votes'), [
            'id' => $id,
            'request_id' => $requestId,
            'approver_id' => $approverId,
            'decision' => $decision,
            'reason' => $reason,
            'context_fingerprint' => $contextFingerprint,
            'step_up_proof_id' => $stepUpProofId,
            'decided_at' => $at,
        ], ['decided_at' => Types::DATETIME_IMMUTABLE]);
    }

    /**
     * Count the request's distinct approving actors.
     *
     * @param   string  $requestId  Request UUID.
     *
     * @return  int  Distinct approval count.
     *
     * @since   2.0.0
     */
    public function approvalCount(string $requestId): int
    {
        $count = $this->database->fetchOne(sprintf(
            "SELECT COUNT(DISTINCT approver_id) FROM %s WHERE request_id = ? AND decision = 'approve'",
            $this->tables->quoted('approval_votes'),
        ), [$requestId]);

        return $this->nonNegativeInteger($count);
    }

    /**
     * Apply one optimistic request lifecycle transition and its terminal timestamp.
     *
     * @param   string             $requestId        Request UUID.
     * @param   ApprovalStatus     $from             Required current state.
     * @param   ApprovalStatus     $to               Target state.
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
    ): void {
        $timeColumn = match ($to) {
            ApprovalStatus::Consumed => 'consumed_at',
            ApprovalStatus::Revoked => 'revoked_at',
            ApprovalStatus::Approved,
            ApprovalStatus::Rejected,
            ApprovalStatus::Cancelled => 'resolved_at',
            ApprovalStatus::Pending => throw new InvalidArgumentException(
                'Approval requests cannot return to pending.',
            ),
        };
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET status = ?, %s = ?, version = version + 1 '
            . 'WHERE id = ? AND status = ? AND version = ?',
            $this->tables->quoted('approval_requests'),
            $this->database->quoteSingleIdentifier($timeColumn),
        ), [$to->value, $at, $requestId, $from->value, $expectedVersion], [
            Types::STRING,
            Types::DATETIME_IMMUTABLE,
            Types::GUID,
            Types::STRING,
            Types::INTEGER,
        ]);
        if ($affected !== 1) {
            throw new RuntimeException('The approval request changed before its transition could be applied.');
        }
    }

    /**
     * Check an optional rule role against live global or organization membership assignments.
     *
     * @param   string            $ruleId   Rule UUID.
     * @param   string            $column   Trusted rule role column.
     * @param   ExecutionContext  $context  Actor and active membership.
     *
     * @return  bool  True when no role is required or the actor holds it in the exact scope.
     *
     * @since   2.0.0
     */
    private function roleEligible(string $ruleId, string $column, ExecutionContext $context): bool
    {
        if (!in_array($column, ['requester_role_id', 'approver_role_id'], true)) {
            throw new \LogicException('An unsupported approval role column was requested.');
        }
        $roleId = $this->database->fetchOne(sprintf(
            'SELECT %s FROM %s WHERE id = ? AND status = ?',
            $this->database->quoteSingleIdentifier($column),
            $this->tables->quoted('separation_duty_rules'),
        ), [$ruleId, 'active']);
        if ($roleId === false) {
            return false;
        }
        if ($roleId !== null && !is_string($roleId)) {
            throw new RuntimeException('A stored approval role is invalid.');
        }

        return $this->roleIdEligible($roleId, $context);
    }

    /**
     * Check whether the actor currently holds one exact frozen role.
     *
     * @param   ?string           $roleId   Optional frozen role UUID.
     * @param   ExecutionContext  $context  Actor and exact membership scope.
     *
     * @return  bool  Whether no role is required or the actor holds it globally or in this membership.
     *
     * @since   2.0.0
     */
    private function roleIdEligible(?string $roleId, ExecutionContext $context): bool
    {
        if ($roleId === null) {
            return true;
        }
        if (
            $this->database->fetchOne(sprintf(
                'SELECT 1 FROM %s WHERE user_id = ? AND role_id = ?',
                $this->tables->quoted('user_roles'),
            ), [$context->actorId(), $roleId]) !== false
        ) {
            return true;
        }
        $membership = $context->membership();
        if ($membership === null) {
            return false;
        }

        return $this->database->fetchOne(sprintf(
            'SELECT 1 FROM %s mr INNER JOIN %s m ON m.id = mr.membership_id '
            . 'INNER JOIN %s o ON o.id = m.organization_id '
            . 'WHERE mr.membership_id = ? AND mr.role_id = ? AND m.user_id = ? AND m.version = ? '
            . "AND o.policy_generation = ? AND m.status = 'active' AND o.status = 'active'",
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
     * Validate an optional string read from the approval store.
     *
     * @param   mixed  $value  Driver value.
     *
     * @return  ?string  Valid optional string.
     *
     * @since   2.0.0
     */
    private function nullableString(mixed $value): ?string
    {
        if ($value !== null && !is_string($value)) {
            throw new RuntimeException('A stored optional approval string is invalid.');
        }

        return $value;
    }

    /**
     * Validate an optional positive integer read from the approval store.
     *
     * @param   mixed  $value  Driver value or absent result.
     *
     * @return  ?int  Valid positive integer, or null.
     *
     * @since   2.0.0
     */
    private function nullablePositiveInteger(mixed $value): ?int
    {
        if ($value === false || $value === null) {
            return null;
        }

        return $this->positiveInteger($value);
    }

    /**
     * Resolve an optional organization identifier to its exact site-owned row.
     *
     * @param   string   $table           Trusted logical table name.
     * @param   ?string  $identifier      Optional organization identifier.
     * @param   string   $siteIdentifier  Exact site owner.
     *
     * @return  ?string  Organization UUID.
     *
     * @throws  RuntimeException  When a requested organization is absent.
     *
     * @since   2.0.0
     */
    private function scopeId(string $table, ?string $identifier, string $siteIdentifier): ?string
    {
        if ($identifier === null) {
            return null;
        }
        $id = $this->database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE identifier = ? AND site_identifier = ? AND status = ?',
            $this->tables->quoted($table),
        ), [$identifier, $siteIdentifier, 'active']);
        if (!is_string($id)) {
            throw new RuntimeException('The approval organization scope is not active.');
        }

        return $id;
    }

    /**
     * Resolve an optional workspace inside the already-resolved organization.
     *
     * @param   ?string  $organizationId  Organization UUID.
     * @param   ?string  $identifier      Workspace identifier.
     *
     * @return  ?string  Workspace UUID.
     *
     * @throws  RuntimeException  When workspace and organization do not match.
     *
     * @since   2.0.0
     */
    private function workspaceId(?string $organizationId, ?string $identifier): ?string
    {
        if ($identifier === null) {
            return null;
        }
        if ($organizationId === null) {
            throw new RuntimeException('An approval workspace requires an organization.');
        }
        $id = $this->database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE organization_id = ? AND identifier = ? AND status = ?',
            $this->tables->quoted('workspaces'),
        ), [$organizationId, $identifier, 'active']);
        if (!is_string($id)) {
            throw new RuntimeException('The approval workspace scope is not active.');
        }

        return $id;
    }

    /** Return the platform's row-lock suffix. @return string SQL lock suffix where supported. @since 2.0.0 */
    private function lockClause(): string
    {
        $platform = $this->database->getDatabasePlatform();
        if ($platform instanceof SQLitePlatform) {
            return '';
        }

        return $platform instanceof PostgreSQLPlatform ? ' FOR UPDATE OF a' : ' FOR UPDATE';
    }

    /**
     * Read one required string column.
     *
     * @param   array<string, mixed>  $row     Stored row.
     * @param   string                $column  Required column name.
     *
     * @return  string  Required string value.
     *
     * @since   2.0.0
     */
    private function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException(sprintf('Stored approval column %s is invalid.', $column));
        }

        return $value;
    }

    /**
     * Normalize one positive driver integer.
     *
     * @param   mixed  $value  Driver value.
     *
     * @return  int  Positive integer.
     *
     * @since   2.0.0
     */
    private function positiveInteger(mixed $value): int
    {
        $integer = $this->nonNegativeInteger($value);
        if ($integer < 1) {
            throw new RuntimeException('Stored approval integer must be positive.');
        }

        return $integer;
    }

    /**
     * Normalize one non-negative driver integer.
     *
     * @param   mixed  $value  Driver value.
     *
     * @return  int  Non-negative integer.
     *
     * @since   2.0.0
     */
    private function nonNegativeInteger(mixed $value): int
    {
        if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) {
            throw new RuntimeException('Stored approval integer is invalid.');
        }

        return (int) $value;
    }

    /**
     * Normalize one portable database boolean.
     *
     * @param   mixed  $value  Driver value.
     *
     * @return  bool  Boolean value.
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

        throw new RuntimeException('Stored approval boolean is invalid.');
    }

    /**
     * Normalize one portable immutable datetime.
     *
     * @param   mixed  $value  Driver value.
     *
     * @return  DateTimeImmutable  Parsed immutable instant.
     *
     * @since   2.0.0
     */
    private function date(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if (!is_string($value)) {
            throw new RuntimeException('Stored approval time is invalid.');
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception $exception) {
            throw new RuntimeException('Stored approval time is invalid.', 0, $exception);
        }
    }
}
