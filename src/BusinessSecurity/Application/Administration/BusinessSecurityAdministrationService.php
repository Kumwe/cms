<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSecurity\Application\Administration;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationPolicyRegistry;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\BusinessSecurity\Application\Approval\StepUpProofConsumer;
use Kumwe\Extension\Spi\BusinessSecurity\Application\FieldAccessUsage;
use Kumwe\App\BusinessSecurity\Application\MembershipDirectory;
use Kumwe\Extension\Spi\BusinessSecurity\Policy\RecordPolicyComparison;
use Kumwe\Extension\Spi\BusinessSecurity\Policy\RecordPolicyComparisonOperator;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicyConstant;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicyNullCheck;
use Kumwe\Extension\Spi\BusinessSecurity\Policy\RecordPolicyPredicate;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicySchema;
use Kumwe\Extension\Spi\BusinessSecurity\Policy\RecordPolicyValueType;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;

/**
 * Guarded runtime for the structured administrator Business Security workspace.
 *
 * Every mutation is authorized through the canonical gateway, executed in one transaction with a
 * purpose-bound single-use step-up proof, and recorded in the audit trail. Membership writes refuse the
 * actor's own membership, while role assignment additionally applies the gateway's delegation ceiling to
 * every grant carried by that role. Policy documents are built from closed form fields into the existing
 * typed AST; this service never accepts JSON, SQL, callbacks, or another executable policy representation.
 *
 * @since  2.0.0
 */
final readonly class BusinessSecurityAdministrationService
{
    /**
     * Closed membership lifecycle states accepted from the administration form.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const MEMBERSHIP_STATUSES = ['active', 'suspended', 'revoked'];

    /**
     * Closed policy lifecycle states accepted from the administration form.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const POLICY_STATUSES = ['active', 'inactive'];

    /**
     * Configure the guarded Business Security administration runtime.
     *
     * @param  BusinessSecurityAdministrationRepository  $repository     Canonical relational state.
     * @param  AuthorizationGateway                      $authorization  Shared deny-by-default gateway.
     * @param  AuthorizationPolicyRegistry               $policies       Live typed capability/resource catalog.
     * @param  MembershipDirectory                       $memberships    Live membership freshness and lock gate.
     * @param  StepUpProofConsumer                       $stepUp         Atomic proof replay fence.
     * @param  TransactionManager                        $transactions   Mutation boundary.
     * @param  AuditRecorder                             $audit          Durable audit sink.
     * @param  ClockInterface                            $clock          Trusted current instant.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessSecurityAdministrationRepository $repository,
        private AuthorizationGateway $authorization,
        private AuthorizationPolicyRegistry $policies,
        private MembershipDirectory $memberships,
        private StepUpProofConsumer $stepUp,
        private TransactionManager $transactions,
        private AuditRecorder $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Read the site-bounded security workspace and attach explainable effective membership access.
     *
     * @param   ExecutionContext  $context  Authenticated administrator and exact site scope.
     *
     * @return  array<string, list<array<string, mixed>>>  Structured, presentation-safe read model.
     *
     * @since   2.0.0
     */
    public function overview(ExecutionContext $context): array
    {
        $this->authorize($context, AuthorizationResource::collection('organization'));
        $overview = $this->scopeOverview(
            $context,
            $this->repository->overview($context->site()->identifier()),
        );
        $capabilities = [];
        foreach ($overview['capabilities'] ?? [] as $definition) {
            $code = $definition['code'] ?? null;
            if (is_string($code)) {
                $capabilities[$code] = $definition;
            }
        }
        $memberships = $overview['memberships'] ?? [];
        foreach ($memberships as &$membership) {
            $membership['effective_access'] = $this->explainMembership($membership, $capabilities);
        }
        unset($membership);
        $overview['memberships'] = $memberships;

        $approvalResource = AuthorizationResource::collection('approval_request');
        $overview['approval_capabilities'] = array_values(array_filter(
            $overview['capabilities'] ?? [],
            function (array $definition) use ($approvalResource): bool {
                $code = $definition['code'] ?? null;

                return is_string($code) && $this->policies->supports(
                    Capability::fromString($code),
                    $approvalResource,
                );
            },
        ));
        $businessRecordResource = AuthorizationResource::collection('business_record');
        $overview['business_record_capabilities'] = array_values(array_filter(
            $overview['capabilities'] ?? [],
            function (array $definition) use ($businessRecordResource): bool {
                $code = $definition['code'] ?? null;

                return is_string($code) && $this->policies->supports(
                    Capability::fromString($code),
                    $businessRecordResource,
                );
            },
        ));
        $mayApprove = $this->authorization->decide(
            $context,
            Capability::fromString('business.approval.approve'),
            $approvalResource,
        )->allowed;
        $mayManageApprovals = $this->authorization->decide(
            $context,
            Capability::fromString('business.approval.manage'),
            $approvalResource,
        )->allowed;
        $mayRequest = $this->authorization->decide(
            $context,
            Capability::fromString('business.approval.request'),
            $approvalResource,
        )->allowed;
        if (!$mayApprove && !$mayManageApprovals) {
            $overview['approvals'] = $mayRequest ? array_values(array_filter(
                $overview['approvals'] ?? [],
                static fn (array $request): bool => ($request['requester_id'] ?? null) === $context->actorId(),
            )) : [];
            $requestIds = [];
            foreach ($overview['approvals'] as $request) {
                $requestId = $request['id'] ?? null;
                if (is_string($requestId)) {
                    $requestIds[$requestId] = true;
                }
            }
            $overview['approval_votes'] = array_values(array_filter(
                $overview['approval_votes'] ?? [],
                static function (array $vote) use ($requestIds): bool {
                    $requestId = $vote['request_id'] ?? null;

                    return is_string($requestId) && isset($requestIds[$requestId]);
                },
            ));
        }
        if (
            !$this->authorization->decide(
                $context,
                Capability::fromString('business.step_up.manage'),
                AuthorizationResource::collection('step_up_credential'),
            )->allowed
        ) {
            $overview['step_up_credentials'] = [];
        }
        if (
            !$this->authorization->decide(
                $context,
                Capability::fromString('users.manage'),
                AuthorizationResource::collection('api_token'),
            )->allowed
        ) {
            $overview['tokens'] = [];
        }

        return $overview;
    }

    /**
     * Create one organization owned by the current site.
     *
     * @param   ExecutionContext  $context     Authenticated administrator and exact site scope.
     * @param   string            $identifier  Stable organization identifier.
     * @param   string            $name        Human-readable organization name.
     *
     * @return  string  UUID of the new organization.
     *
     * @since   2.0.0
     */
    public function createOrganization(ExecutionContext $context, string $identifier, string $name): string
    {
        $this->assertSiteAuthorityContext($context);
        $identifier = $this->scopeIdentifier($identifier, 'organization');
        $name = $this->name($name, 'organization');
        $id = Uuid::uuid7()->toString();

        return $this->mutate(
            $context,
            'organization.create',
            'organization',
            $id,
            AuthorizationResource::collection('organization'),
            function (DateTimeImmutable $at) use ($context, $id, $identifier, $name): void {
                $this->repository->insertOrganization(
                    $id,
                    $context->site()->identifier(),
                    $identifier,
                    $name,
                    $at,
                );
            },
        );
    }

    /**
     * Create one workspace inside an active organization owned by the current site.
     *
     * @param   ExecutionContext  $context         Authenticated administrator and exact site scope.
     * @param   string            $organizationId  Parent organization row.
     * @param   string            $identifier      Stable workspace identifier.
     * @param   string            $name            Human-readable workspace name.
     *
     * @return  string  UUID of the new workspace.
     *
     * @since   2.0.0
     */
    public function createWorkspace(
        ExecutionContext $context,
        string $organizationId,
        string $identifier,
        string $name,
    ): string {
        $this->assertOrganizationWideMutation($context);
        $organizationId = $this->opaque($organizationId, 'organization');
        $this->organizationTarget($context, $organizationId);
        $identifier = $this->scopeIdentifier($identifier, 'workspace');
        $name = $this->name($name, 'workspace');
        $id = Uuid::uuid7()->toString();

        return $this->mutate(
            $context,
            'workspace.create',
            'workspace',
            $id,
            AuthorizationResource::collection('workspace'),
            function (DateTimeImmutable $at) use (
                $context,
                $id,
                $organizationId,
                $identifier,
                $name,
            ): void {
                $this->organizationTarget($context, $organizationId, true);
                $this->repository->insertWorkspace(
                    $id,
                    $organizationId,
                    $context->site()->identifier(),
                    $identifier,
                    $name,
                    $at,
                );
            },
        );
    }

    /**
     * Create one versioned organization membership for another site user.
     *
     * @param   ExecutionContext    $context         Authenticated administrator and exact site scope.
     * @param   string              $organizationId  Organization receiving the member.
     * @param   string              $userId          User who will own the membership.
     * @param   DateTimeImmutable   $validFrom       First instant at which the membership is valid.
     * @param   ?DateTimeImmutable  $validUntil      Optional exclusive membership expiry.
     *
     * @return  string  UUID of the new membership.
     *
     * @since   2.0.0
     */
    public function createMembership(
        ExecutionContext $context,
        string $organizationId,
        string $userId,
        DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validUntil,
    ): string {
        $this->assertOrganizationWideMutation($context);
        $organizationId = $this->opaque($organizationId, 'organization');
        $this->organizationTarget($context, $organizationId);
        $userId = $this->opaque($userId, 'user');
        if ($userId === $context->actorId()) {
            throw new SelfEscalationDenied();
        }
        if ($validUntil !== null && $validUntil <= $validFrom) {
            throw new InvalidArgumentException('Membership validity must end after it begins.');
        }
        $id = Uuid::uuid7()->toString();

        return $this->mutate(
            $context,
            'membership.create',
            'organization_membership',
            $id,
            AuthorizationResource::collection('organization_membership'),
            function (DateTimeImmutable $at) use (
                $context,
                $id,
                $organizationId,
                $userId,
                $validFrom,
                $validUntil,
            ): void {
                $this->organizationTarget($context, $organizationId, true);
                $this->repository->insertMembership(
                    $id,
                    $organizationId,
                    $context->site()->identifier(),
                    $userId,
                    $validFrom,
                    $validUntil,
                    $context->actorId(),
                    $at,
                );
            },
        );
    }

    /**
     * Change another user's membership status using optimistic concurrency.
     *
     * @param   ExecutionContext  $context          Authenticated administrator and exact site scope.
     * @param   string            $membershipId     Membership row to change.
     * @param   string            $status           Validated target lifecycle state.
     * @param   int               $expectedVersion  Version the operator observed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function changeMembershipStatus(
        ExecutionContext $context,
        string $membershipId,
        string $status,
        int $expectedVersion,
    ): void {
        $this->assertOrganizationWideMutation($context);
        $membershipId = $this->opaque($membershipId, 'membership');
        $this->membershipTarget($context, $membershipId);
        $this->status($status, self::MEMBERSHIP_STATUSES, 'membership');
        $this->positive($expectedVersion, 'membership version');
        $this->mutate(
            $context,
            'membership.status',
            'organization_membership',
            $membershipId,
            AuthorizationResource::item('organization_membership', $membershipId),
            function (DateTimeImmutable $at) use (
                $context,
                $membershipId,
                $status,
                $expectedVersion,
            ): void {
                $this->membershipTarget($context, $membershipId, true);
                $this->repository->updateMembershipStatus(
                    $membershipId,
                    $context->site()->identifier(),
                    $status,
                    $expectedVersion,
                    $context->actorId(),
                    $at,
                );
            },
        );
    }

    /**
     * Assign a workspace to another user's exact organization membership.
     *
     * @param   ExecutionContext  $context       Authenticated administrator and exact site scope.
     * @param   string            $membershipId  Membership receiving the workspace.
     * @param   string            $workspaceId   Workspace to assign.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function assignWorkspace(
        ExecutionContext $context,
        string $membershipId,
        string $workspaceId,
    ): void {
        $this->membershipAssignment($context, $membershipId, $workspaceId, true, false);
    }

    /**
     * Revoke a workspace from another user's exact organization membership.
     *
     * @param   ExecutionContext  $context       Authenticated administrator and exact site scope.
     * @param   string            $membershipId  Membership losing the workspace.
     * @param   string            $workspaceId   Workspace to revoke.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function revokeWorkspace(
        ExecutionContext $context,
        string $membershipId,
        string $workspaceId,
    ): void {
        $this->membershipAssignment($context, $membershipId, $workspaceId, false, false);
    }

    /**
     * Assign a role within another user's membership after checking every delegated grant.
     *
     * @param   ExecutionContext  $context       Authenticated administrator and exact site scope.
     * @param   string            $membershipId  Membership receiving the role.
     * @param   string            $roleId        Role whose grants will be delegated.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function assignRole(ExecutionContext $context, string $membershipId, string $roleId): void
    {
        $this->assertOrganizationWideMutation($context);
        $this->membershipAssignment($context, $membershipId, $roleId, true, true);
    }

    /**
     * Revoke a role from another user's exact organization membership.
     *
     * @param   ExecutionContext  $context       Authenticated administrator and exact site scope.
     * @param   string            $membershipId  Membership losing the role.
     * @param   string            $roleId        Role to revoke.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function revokeRole(ExecutionContext $context, string $membershipId, string $roleId): void
    {
        $this->assertOrganizationWideMutation($context);
        $this->membershipAssignment($context, $membershipId, $roleId, false, true);
    }

    /**
     * Create one structured business-record row and field policy.
     *
     * @param   ExecutionContext             $context         Authenticated administrator and exact site scope.
     * @param   string                       $policyCode      Stable operator-facing policy code.
     * @param   string                       $operation       Business-record capability controlled by the policy.
     * @param   string                       $effect          Closed allow-or-deny policy effect.
     * @param   ?string                      $organizationId  Optional organization restriction.
     * @param   string                       $definitionId    Published definition restricted by the policy.
     * @param   string                       $predicateType   Closed predicate form selected by the operator.
     * @param   ?string                      $field           Definition field referenced by the predicate.
     * @param   ?string                      $operator        Typed comparison operator, when applicable.
     * @param   ?string                      $valueType       Declared comparison value type, when applicable.
     * @param   ?string                      $value           Structured predicate literal supplied by the operator.
     * @param   array<string, list<string>>  $fieldRules      Explicit fields per usage and declared actions.
     * @param   int                          $priority        Stable policy ordering priority.
     *
     * @return  string  UUID of the policy row.
     *
     * @since   2.0.0
     */
    public function createResourcePolicy(
        ExecutionContext $context,
        string $policyCode,
        string $operation,
        string $effect,
        ?string $organizationId,
        string $definitionId,
        string $predicateType,
        ?string $field,
        ?string $operator,
        ?string $valueType,
        ?string $value,
        array $fieldRules,
        int $priority = 0,
    ): string {
        $this->assertOrganizationWideMutation($context);
        $policyCode = $this->policyCode($policyCode);
        $operationCapability = Capability::fromString($operation);
        $operation = $operationCapability->value();
        if (
            !$this->policies->supports(
                $operationCapability,
                AuthorizationResource::collection('business_record'),
            )
        ) {
            throw new InvalidArgumentException(
                'A row policy must use a live capability bound to business records.',
            );
        }
        if (!in_array($effect, ['allow', 'deny'], true)) {
            throw new InvalidArgumentException('A row policy effect must be allow or deny.');
        }
        $organizationId = $organizationId === null ? null : $this->opaque($organizationId, 'organization');
        $this->optionalOrganizationTarget($context, $organizationId);
        $definitionId = $this->opaque($definitionId, 'definition');
        $types = $this->repository->definitionFieldTypes($definitionId, $context->site()->identifier());
        if ($types === []) {
            throw new InvalidArgumentException('The policy definition is not a published definition in this site.');
        }
        $schema = $this->schema($types);
        $predicate = $this->predicate($predicateType, $field, $operator, $valueType, $value);
        $schema->assertPredicate($predicate);
        $rules = $this->fieldRules(
            $fieldRules,
            $types,
            $this->repository->definitionActions($definitionId, $context->site()->identifier()),
        );
        if ($priority < -100_000 || $priority > 100_000) {
            throw new InvalidArgumentException('A policy priority must be between -100000 and 100000.');
        }
        if ($effect === 'allow' && $this->couldAffectActor($context, $operation, $organizationId)) {
            throw new SelfEscalationDenied();
        }
        $document = $predicate->toArray();
        $checksum = CanonicalDefinitionJson::checksum(['ast' => $document, 'fields' => $rules]);
        $id = Uuid::uuid7()->toString();

        return $this->mutate(
            $context,
            'resource_policy.create',
            'resource_policy',
            $id,
            AuthorizationResource::collection('resource_policy'),
            function (DateTimeImmutable $at) use (
                $context,
                $id,
                $policyCode,
                $operation,
                $effect,
                $organizationId,
                $definitionId,
                $document,
                $rules,
                $checksum,
                $priority,
            ): void {
                $this->optionalOrganizationTarget($context, $organizationId, true);
                $this->repository->insertResourcePolicy(
                    $id,
                    $policyCode,
                    $operation,
                    $operation,
                    $effect,
                    $organizationId,
                    $definitionId,
                    $document,
                    $rules,
                    $checksum,
                    $priority,
                    $context->actorId(),
                    $context->site()->identifier(),
                    $at,
                );
            },
        );
    }

    /**
     * Activate or deactivate one existing row-and-field policy using optimistic concurrency.
     *
     * @param   ExecutionContext  $context          Authenticated administrator and exact site scope.
     * @param   string            $id               Policy row to change.
     * @param   string            $status           Validated target lifecycle state.
     * @param   int               $expectedVersion  Version the operator observed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function changeResourcePolicyStatus(
        ExecutionContext $context,
        string $id,
        string $status,
        int $expectedVersion,
    ): void {
        $this->assertOrganizationWideMutation($context);
        $id = $this->opaque($id, 'resource policy');
        $this->resourcePolicyTarget($context, $id);
        $this->status($status, self::POLICY_STATUSES, 'resource policy');
        $this->positive($expectedVersion, 'policy version');
        $this->mutate(
            $context,
            'resource_policy.status',
            'resource_policy',
            $id,
            AuthorizationResource::item('resource_policy', $id),
            function (DateTimeImmutable $at) use ($context, $id, $status, $expectedVersion): void {
                $policy = $this->resourcePolicyTarget($context, $id, true);
                if (
                    $status === 'active'
                    && $policy['effect'] === 'allow'
                    && $this->couldAffectActor(
                        $context,
                        $policy['capability'],
                        $policy['organization_id'],
                    )
                ) {
                    throw new SelfEscalationDenied();
                }
                $this->repository->updateResourcePolicyStatus(
                    $id,
                    $context->site()->identifier(),
                    $status,
                    $expectedVersion,
                    $at,
                );
            },
        );
    }

    /**
     * Create one maker-checker rule whose approval action is live for approval requests.
     *
     * @param   ExecutionContext  $context          Authenticated administrator and exact site scope.
     * @param   string            $ruleCode         Stable operator-facing rule code.
     * @param   string            $resourceType     Resource type governed by the rule.
     * @param   string            $requestAction    Exact operation token that triggers approval.
     * @param   string            $approvalAction   Capability required on the frozen approval request.
     * @param   ?string           $organizationId   Optional organization restriction.
     * @param   ?string           $requesterRoleId  Optional role required of requesters.
     * @param   ?string           $approverRoleId   Optional role required of approvers.
     * @param   int               $quorum           Number of approvals required.
     * @param   bool              $distinctActors   Whether votes must come from distinct actors.
     *
     * @return  string  UUID of the new maker-checker rule.
     *
     * @since   2.0.0
     */
    public function createSeparationRule(
        ExecutionContext $context,
        string $ruleCode,
        string $resourceType,
        string $requestAction,
        string $approvalAction,
        ?string $organizationId,
        ?string $requesterRoleId,
        ?string $approverRoleId,
        int $quorum,
        bool $distinctActors,
    ): string {
        $this->assertOrganizationWideMutation($context);
        $ruleCode = $this->policyCode($ruleCode);
        $resourceType = $this->machineIdentifier($resourceType, 'resource type', 63);
        $requestAction = $this->operationToken($requestAction, 'request action', 127);
        $approvalAction = $this->operationToken($approvalAction, 'approval action', 191);
        if (
            !$this->policies->supports(
                Capability::fromString($approvalAction),
                AuthorizationResource::collection('approval_request'),
            )
        ) {
            throw new InvalidArgumentException(
                'The approval action must be a live capability bound to approval requests.',
            );
        }
        if ($requestAction === $approvalAction) {
            throw new InvalidArgumentException('Request and approval actions must be distinct.');
        }
        $organizationId = $organizationId === null ? null : $this->opaque($organizationId, 'organization');
        $this->optionalOrganizationTarget($context, $organizationId);
        $requesterRoleId = $this->optionalOpaque($requesterRoleId, 'requester role');
        $approverRoleId = $this->optionalOpaque($approverRoleId, 'approver role');
        if ($requesterRoleId !== null && $requesterRoleId === $approverRoleId) {
            throw new InvalidArgumentException('Requester and approver roles must be distinct.');
        }
        if ($quorum < 1 || $quorum > 32) {
            throw new InvalidArgumentException('Approval quorum must be between one and 32.');
        }
        $id = Uuid::uuid7()->toString();

        return $this->mutate(
            $context,
            'separation_duty.create',
            'separation_duty_rule',
            $id,
            AuthorizationResource::collection('separation_duty_rule'),
            function (DateTimeImmutable $at) use (
                $context,
                $id,
                $organizationId,
                $ruleCode,
                $resourceType,
                $requestAction,
                $approvalAction,
                $requesterRoleId,
                $approverRoleId,
                $quorum,
                $distinctActors,
            ): void {
                $this->optionalOrganizationTarget($context, $organizationId, true);
                $this->repository->insertSeparationRule(
                    $id,
                    $organizationId,
                    $ruleCode,
                    $resourceType,
                    $requestAction,
                    $approvalAction,
                    $requesterRoleId,
                    $approverRoleId,
                    $quorum,
                    $distinctActors,
                    $context->actorId(),
                    $context->site()->identifier(),
                    $at,
                );
            },
        );
    }

    /**
     * Activate or deactivate one separation-of-duty rule using optimistic concurrency.
     *
     * @param   ExecutionContext  $context          Authenticated administrator and exact site scope.
     * @param   string            $id               Separation rule to change.
     * @param   string            $status           Validated target lifecycle state.
     * @param   int               $expectedVersion  Version the operator observed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function changeSeparationRuleStatus(
        ExecutionContext $context,
        string $id,
        string $status,
        int $expectedVersion,
    ): void {
        $this->assertOrganizationWideMutation($context);
        $id = $this->opaque($id, 'separation rule');
        $this->separationRuleTarget($context, $id);
        $this->status($status, self::POLICY_STATUSES, 'separation rule');
        $this->positive($expectedVersion, 'rule version');
        $this->mutate(
            $context,
            'separation_duty.status',
            'separation_duty_rule',
            $id,
            AuthorizationResource::item('separation_duty_rule', $id),
            function (DateTimeImmutable $at) use ($context, $id, $status, $expectedVersion): void {
                $this->separationRuleTarget($context, $id, true);
                $this->repository->updateSeparationRuleStatus(
                    $id,
                    $context->site()->identifier(),
                    $status,
                    $expectedVersion,
                    $at,
                );
            },
        );
    }

    /**
     * Return the single closed proof purpose for a supported handler action.
     *
     * @param   string  $action  Closed mutation action received from the administrator handler.
     *
     * @return  string  Exact purpose to which a fresh proof must be bound.
     *
     * @throws  InvalidArgumentException  When an unrecognised form action is supplied.
     *
     * @since   2.0.0
     */
    public static function stepUpPurpose(string $action): string
    {
        $purpose = match ($action) {
            'organization.create',
            'workspace.create',
            'membership.create',
            'membership.status',
            'membership.workspace.assign',
            'membership.workspace.revoke',
            'membership.role.assign',
            'membership.role.revoke',
            'resource_policy.create',
            'resource_policy.status',
            'separation_duty.create',
            'separation_duty.status' => 'business.security.' . $action,
            default => null,
        };

        return $purpose ?? throw new InvalidArgumentException('The Business Security action is unsupported.');
    }

    /**
     * Apply one workspace or role membership assignment without permitting self-escalation.
     *
     * @param   ExecutionContext  $context       Authenticated administrator and exact site scope.
     * @param   string            $membershipId  Membership being changed.
     * @param   string            $subjectId     Workspace or role row being assigned or revoked.
     * @param   bool              $assign        Whether to assign rather than revoke the subject.
     * @param   bool              $role          Whether the subject is a role rather than a workspace.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function membershipAssignment(
        ExecutionContext $context,
        string $membershipId,
        string $subjectId,
        bool $assign,
        bool $role,
    ): void {
        $membershipId = $this->opaque($membershipId, 'membership');
        $subjectId = $this->opaque($subjectId, $role ? 'role' : 'workspace');
        $membership = $this->membershipTarget($context, $membershipId);
        if (!$role) {
            $this->workspaceTarget($context, $subjectId, $membership['organization_id']);
        }
        if ($role && $assign) {
            $this->assertRoleDelegatable($context, $subjectId);
        }
        $noun = $role ? 'role' : 'workspace';
        $verb = $assign ? 'assign' : 'revoke';
        $this->mutate(
            $context,
            'membership.' . $noun . '.' . $verb,
            'organization_membership',
            $membershipId,
            AuthorizationResource::item('organization_membership', $membershipId),
            function (DateTimeImmutable $at) use (
                $context,
                $membershipId,
                $subjectId,
                $assign,
                $role,
            ): void {
                $membership = $this->membershipTarget($context, $membershipId, true);
                if (!$role) {
                    $this->workspaceTarget($context, $subjectId, $membership['organization_id'], true);
                }
                if ($role && $assign) {
                    $this->assertRoleDelegatable($context, $subjectId);
                    $this->repository->assignMembershipRole(
                        $membershipId,
                        $subjectId,
                        $context->site()->identifier(),
                        $context->actorId(),
                        $at,
                    );

                    return;
                }
                if ($role) {
                    $this->repository->revokeMembershipRole(
                        $membershipId,
                        $subjectId,
                        $context->site()->identifier(),
                        $at,
                    );

                    return;
                }
                if ($assign) {
                    $this->repository->assignMembershipWorkspace(
                        $membershipId,
                        $subjectId,
                        $context->site()->identifier(),
                        $context->actorId(),
                        $at,
                    );

                    return;
                }
                $this->repository->revokeMembershipWorkspace(
                    $membershipId,
                    $subjectId,
                    $context->site()->identifier(),
                    $at,
                );
            },
            ['assigned_subject' => $subjectId],
        );
    }

    /**
     * Assert that every current grant on a role remains within the actor's delegation ceiling.
     *
     * @param   ExecutionContext  $context  Authenticated administrator and exact site scope.
     * @param   string            $roleId   Role whose complete live grant set must remain delegable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertRoleDelegatable(ExecutionContext $context, string $roleId): void
    {
        foreach ($this->repository->roleGrants($roleId) as $grant) {
            $scope = $grant['scope_type'] === 'global'
                ? \Kumwe\App\Identity\Domain\GrantScope::global()
                : \Kumwe\App\Identity\Domain\GrantScope::named(
                    $grant['scope_type'],
                    $grant['scope_identifier'] ?? '',
                );
            $this->authorization->assertCanDelegate(
                $context,
                Capability::fromString($grant['capability']),
                $scope,
            );
        }
    }

    /**
     * Refuse installation or organization creation from a selected membership context.
     *
     * @param   ExecutionContext  $context  Authenticated administrator scope.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertSiteAuthorityContext(ExecutionContext $context): void
    {
        if ($context->organization() !== null || $context->workspace() !== null) {
            throw new BusinessSecurityScopeDenied();
        }
    }

    /**
     * Refuse organization-wide changes from a narrower selected workspace.
     *
     * @param   ExecutionContext  $context  Authenticated administrator scope.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertOrganizationWideMutation(ExecutionContext $context): void
    {
        if ($context->workspace() !== null) {
            throw new BusinessSecurityScopeDenied();
        }
    }

    /**
     * Resolve one site-owned organization and confine it to the selected membership organization.
     *
     * @param   ExecutionContext  $context         Authenticated administrator scope.
     * @param   string            $organizationId  Organization row supplied by the form or stored target.
     * @param   bool              $lock            Whether to lock the organization for a following write.
     *
     * @return  string  Trusted public organization identifier.
     *
     * @since   2.0.0
     */
    private function organizationTarget(
        ExecutionContext $context,
        string $organizationId,
        bool $lock = false,
    ): string {
        $identifier = $this->repository->organizationIdentifier(
            $organizationId,
            $context->site()->identifier(),
            $lock,
        ) ?? throw new InvalidArgumentException('The organization is unavailable in this site.');
        $this->assertStoredOrganization($context, $identifier);

        return $identifier;
    }

    /**
     * Validate an optional organization target, refusing site-wide rows from an organization context.
     *
     * @param   ExecutionContext  $context         Authenticated administrator scope.
     * @param   ?string           $organizationId  Optional organization row.
     * @param   bool              $lock            Whether to lock a non-null organization.
     *
     * @return  ?string  Trusted public organization identifier, or null for a site-wide administrator.
     *
     * @since   2.0.0
     */
    private function optionalOrganizationTarget(
        ExecutionContext $context,
        ?string $organizationId,
        bool $lock = false,
    ): ?string {
        if ($organizationId === null) {
            $this->assertStoredOrganization($context, null);

            return null;
        }

        return $this->organizationTarget($context, $organizationId, $lock);
    }

    /**
     * Compare trusted stored organization scope with the server-resolved execution context.
     *
     * @param   ExecutionContext  $context                 Authenticated administrator scope.
     * @param   ?string           $organizationIdentifier  Trusted stored organization identifier.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertStoredOrganization(
        ExecutionContext $context,
        ?string $organizationIdentifier,
    ): void {
        $selected = $context->organization()?->identifier();
        if ($selected !== null && $organizationIdentifier !== $selected) {
            throw new BusinessSecurityScopeDenied();
        }
    }

    /**
     * Resolve a target membership while refusing self-change and foreign organization scope.
     *
     * @param   ExecutionContext  $context       Authenticated administrator and exact membership scope.
     * @param   string            $membershipId  Membership whose authority must be resolved.
     * @param   bool              $lock          Whether the repository must lock it for mutation.
     *
     * @return  array{user_id: string, organization_id: string, organization_identifier: string}  Target authority.
     *
     * @since   2.0.0
     */
    private function membershipTarget(
        ExecutionContext $context,
        string $membershipId,
        bool $lock = false,
    ): array {
        $authority = $this->repository->membershipAuthority(
            $membershipId,
            $context->site()->identifier(),
            $lock,
        ) ?? throw new InvalidArgumentException('The organization membership is unavailable in this site.');
        $this->assertStoredOrganization($context, $authority['organization_identifier']);
        if ($authority['user_id'] === $context->actorId()) {
            throw new SelfEscalationDenied();
        }

        return $authority;
    }

    /**
     * Resolve a workspace inside the target membership organization and selected workspace context.
     *
     * @param   ExecutionContext  $context         Authenticated administrator scope.
     * @param   string            $workspaceId     Workspace being assigned or revoked.
     * @param   string            $organizationId  Parent organization UUID from the target membership.
     * @param   bool              $lock            Whether to lock the workspace for mutation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function workspaceTarget(
        ExecutionContext $context,
        string $workspaceId,
        string $organizationId,
        bool $lock = false,
    ): void {
        $authority = $this->repository->workspaceAuthority(
            $workspaceId,
            $context->site()->identifier(),
            $lock,
        ) ?? throw new InvalidArgumentException('The workspace is unavailable in this site.');
        $this->assertStoredOrganization($context, $authority['organization_identifier']);
        if (
            $authority['organization_id'] !== $organizationId
            || ($context->workspace() !== null
                && $authority['identifier'] !== $context->workspace()->identifier())
        ) {
            throw new BusinessSecurityScopeDenied();
        }
    }

    /**
     * Resolve a resource policy and confine its stored scope to the selected organization.
     *
     * @param   ExecutionContext  $context   Authenticated administrator scope.
     * @param   string            $policyId  Resource policy row.
     * @param   bool              $lock      Whether to lock the policy and organization.
     *
     * @return  array{effect: string, capability: string, organization_id: ?string,
     *          organization_identifier: ?string}  Trusted policy authority.
     *
     * @since   2.0.0
     */
    private function resourcePolicyTarget(
        ExecutionContext $context,
        string $policyId,
        bool $lock = false,
    ): array {
        $authority = $this->repository->resourcePolicyAuthority(
            $policyId,
            $context->site()->identifier(),
            $lock,
        ) ?? throw new InvalidArgumentException('The resource policy is unavailable in this site.');
        $this->assertStoredOrganization($context, $authority['organization_identifier']);

        return $authority;
    }

    /**
     * Resolve a separation rule and confine its stored scope to the selected organization.
     *
     * @param   ExecutionContext  $context  Authenticated administrator scope.
     * @param   string            $ruleId   Separation rule row.
     * @param   bool              $lock     Whether to lock the rule and organization.
     *
     * @return  array{organization_id: ?string, organization_identifier: ?string}  Trusted rule authority.
     *
     * @since   2.0.0
     */
    private function separationRuleTarget(
        ExecutionContext $context,
        string $ruleId,
        bool $lock = false,
    ): array {
        $authority = $this->repository->separationRuleAuthority(
            $ruleId,
            $context->site()->identifier(),
            $lock,
        ) ?? throw new InvalidArgumentException('The separation rule is unavailable in this site.');
        $this->assertStoredOrganization($context, $authority['organization_identifier']);

        return $authority;
    }

    /**
     * Run one authorized, step-up-bound mutation and audit it before commit.
     *
     * @param   ExecutionContext                   $context      Authenticated administrator and exact site scope.
     * @param string $action Closed mutation action used for proof and audit binding.
     * @param   string                             $subjectType  Audit subject type owned by the mutation.
     * @param   string                             $subjectId    Stable identifier of the mutated subject.
     * @param   AuthorizationResource              $resource     Exact resource authorized before the transaction.
     * @param   callable(DateTimeImmutable): void  $operation    Narrow repository write.
     * @param   array<string, mixed>               $metadata     Non-secret audit evidence.
     *
     * @return  string  Subject identifier, convenient for create methods.
     *
     * @since   2.0.0
     */
    private function mutate(
        ExecutionContext $context,
        string $action,
        string $subjectType,
        string $subjectId,
        AuthorizationResource $resource,
        callable $operation,
        array $metadata = [],
    ): string {
        $this->authorize($context, $resource);
        $purpose = self::stepUpPurpose($action);
        $this->transactions->transactional(function () use (
            $context,
            $action,
            $subjectType,
            $subjectId,
            $purpose,
            $operation,
            $metadata,
        ): void {
            $at = $this->clock->now();
            $membership = $context->membership();
            if (
                $membership !== null && !$this->memberships->current(
                    $context->actorId(),
                    $context->site(),
                    $membership,
                    true,
                )
            ) {
                throw new BusinessSecurityScopeDenied();
            }
            $proof = $context->stepUpProof()
                ?? throw new InvalidArgumentException('A fresh step-up proof is required.');
            $proofId = $this->stepUp->consume($proof, $context, $purpose, $at);
            $operation($at);
            $this->audit->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $at,
                $context->actorId(),
                'business.security.' . $action,
                $subjectType,
                $subjectId,
                'success',
                [
                    'site' => $context->site()->identifier(),
                    'organization' => $context->organization()?->identifier(),
                    'workspace' => $context->workspace()?->identifier(),
                    'step_up_proof_id' => $proofId,
                    ...$metadata,
                ],
            ));
        });

        return $subjectId;
    }

    /**
     * Demand the Business Security management capability on the exact target resource.
     *
     * @param   ExecutionContext       $context   Authenticated administrator and exact site scope.
     * @param   AuthorizationResource  $resource  Collection or item being managed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function authorize(ExecutionContext $context, AuthorizationResource $resource): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('business.security.manage'),
            $resource,
        );
    }

    /**
     * Determine whether an allow policy could enlarge the current actor's own effective access.
     *
     * @param   ExecutionContext  $context         Authenticated administrator and exact site scope.
     * @param   string            $capability      Capability an allow policy would grant.
     * @param   ?string           $organizationId  Optional organization row restricted by the policy.
     *
     * @return  bool  Whether activation would affect the actor's current scope.
     *
     * @since   2.0.0
     */
    private function couldAffectActor(
        ExecutionContext $context,
        string $capability,
        ?string $organizationId,
    ): bool {
        if (AuthenticatedPrincipal::of($context)?->hasCapability(Capability::fromString($capability)) !== true) {
            return false;
        }
        if ($organizationId === null) {
            return true;
        }

        if ($context->organization() === null) {
            return true;
        }

        return $this->repository->organizationIdentifier(
            $organizationId,
            $context->site()->identifier(),
        ) === $context->organization()->identifier();
    }

    /**
     * Reconstruct the closed policy schema from published field metadata.
     *
     * @param   array<string, string>  $types  Field handles keyed to their declared policy value type.
     *
     * @return  RecordPolicySchema  Schema suitable for validating a submitted predicate.
     *
     * @since   2.0.0
     */
    private function schema(array $types): RecordPolicySchema
    {
        $fields = [];
        foreach ($types as $handle => $type) {
            if (!is_string($handle) || !is_string($type)) {
                throw new InvalidArgumentException('The published policy field schema is invalid.');
            }
            $fields[$handle] = RecordPolicyValueType::from($type);
        }

        return new RecordPolicySchema($fields);
    }

    /**
     * Build one validated predicate leaf exclusively from structured form values.
     *
     * @param   string   $type       Closed predicate form selected by the operator.
     * @param   ?string  $field      Definition field referenced by the predicate.
     * @param   ?string  $operator   Typed comparison operator, when applicable.
     * @param   ?string  $valueType  Declared comparison value type, when applicable.
     * @param   ?string  $value      Literal or Boolean marker supplied by the operator.
     *
     * @return  RecordPolicyPredicate  Typed predicate ready for schema validation.
     *
     * @since   2.0.0
     */
    private function predicate(
        string $type,
        ?string $field,
        ?string $operator,
        ?string $valueType,
        ?string $value,
    ): RecordPolicyPredicate {
        if ($type === 'constant') {
            if (!in_array($value, ['true', 'false'], true)) {
                throw new InvalidArgumentException('A constant policy value must be true or false.');
            }

            return new RecordPolicyConstant($value === 'true');
        }
        $field = $field === null ? '' : trim($field);
        if ($type === 'null') {
            if (!in_array($value, ['true', 'false'], true)) {
                throw new InvalidArgumentException('A null-check policy value must be true or false.');
            }

            return new RecordPolicyNullCheck($field, $value === 'true');
        }
        if ($type !== 'comparison' || $operator === null || $valueType === null || $value === null) {
            throw new InvalidArgumentException('A comparison policy requires field, operator, type and value.');
        }
        $policyType = RecordPolicyValueType::from($valueType);

        return new RecordPolicyComparison(
            $field,
            RecordPolicyComparisonOperator::from($operator),
            $policyType,
            $this->literal($policyType, $value),
        );
    }

    /**
     * Parse a comparison literal without permitting ambiguous coercion.
     *
     * @param   RecordPolicyValueType  $type   Declared value type of the comparison.
     * @param   string                 $value  Raw structured form value.
     *
     * @return  string|int|bool  Exactly typed comparison literal.
     *
     * @since   2.0.0
     */
    private function literal(RecordPolicyValueType $type, string $value): string|int|bool
    {
        return match ($type) {
            RecordPolicyValueType::Integer => $this->integerLiteral($value),
            RecordPolicyValueType::Boolean => match ($value) {
                'true' => true,
                'false' => false,
                default => throw new InvalidArgumentException('A boolean policy literal must be true or false.'),
            },
            default => $value,
        };
    }

    /**
     * Parse a strict bounded base-10 integer without alternate notation.
     *
     * @param   string  $value  Candidate canonical integer literal.
     *
     * @return  int  Parsed integer in the platform-supported range.
     *
     * @since   2.0.0
     */
    private function integerLiteral(string $value): int
    {
        if (preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            throw new InvalidArgumentException('An integer policy literal must use canonical base-10 notation.');
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($integer)) {
            throw new InvalidArgumentException('An integer policy literal is outside the supported range.');
        }

        return $integer;
    }

    /**
     * Validate explicit field and action disclosure lists against published vocabularies.
     *
     * @param   array<string, mixed>   $submitted  Structured disclosure lists keyed by usage.
     * @param   array<string, string>  $types      Published field handles keyed to value types.
     * @param   list<string>           $actions    Published definition action handles.
     *
     * @return  array<string, list<string>>  Canonically sorted field and action rules.
     *
     * @since   2.0.0
     */
    private function fieldRules(array $submitted, array $types, array $actions): array
    {
        $allowedKeys = ['actions' => true];
        $rules = [];
        foreach (FieldAccessUsage::cases() as $usage) {
            $allowedKeys[$usage->value] = true;
            $rules[$usage->value] = $this->knownList(
                $submitted[$usage->value] ?? [],
                array_keys($types),
                'policy field',
            );
        }
        if (array_diff_key($submitted, $allowedKeys) !== []) {
            throw new InvalidArgumentException('A field policy contains an unknown usage.');
        }
        $rules['actions'] = $this->knownList($submitted['actions'] ?? [], $actions, 'policy action');

        return $rules;
    }

    /**
     * Restrict a submitted list to a trusted vocabulary and canonical ordering.
     *
     * @param   mixed         $values  Candidate form value expected to be a bounded string list.
     * @param   list<string>  $known   Trusted vocabulary accepted for this list.
     * @param   string        $name    Operator-facing noun used in validation failures.
     *
     * @return  list<string>  Sorted unique submitted values.
     *
     * @since   2.0.0
     */
    private function knownList(mixed $values, array $known, string $name): array
    {
        if (!is_array($values) || !array_is_list($values) || count($values) > 256) {
            throw new InvalidArgumentException(sprintf('A %s list is invalid.', $name));
        }
        $index = array_fill_keys($known, true);
        $result = [];
        foreach ($values as $value) {
            if (!is_string($value) || !isset($index[$value])) {
                throw new InvalidArgumentException(sprintf('A %s is unavailable.', $name));
            }
            $result[$value] = true;
        }
        ksort($result, SORT_STRING);

        return array_keys($result);
    }

    /**
     * Remove every site row outside the server-resolved organization and workspace selection.
     *
     * Site/global administrators retain the full site projection. A selected organization receives only
     * its own memberships, policies, rules, approvals, credentials and tokens; a selected workspace narrows
     * workspace-bound rows again. Global role and capability definitions remain visible because assignment
     * still passes through the delegation ceiling and they carry no organization-owned business data.
     *
     * @param   ExecutionContext                           $context   Authenticated administrator scope.
     * @param   array<string, list<array<string, mixed>>>  $overview  Complete site read model.
     *
     * @return  array<string, list<array<string, mixed>>>  Scope-confined presentation model.
     *
     * @since   2.0.0
     */
    private function scopeOverview(ExecutionContext $context, array $overview): array
    {
        $organization = $context->organization()?->identifier();
        if ($organization === null) {
            return $overview;
        }
        $workspace = $context->workspace()?->identifier();
        $overview['organizations'] = array_values(array_filter(
            $overview['organizations'] ?? [],
            static fn (array $row): bool => ($row['identifier'] ?? null) === $organization,
        ));
        $overview['workspaces'] = array_values(array_filter(
            $overview['workspaces'] ?? [],
            static fn (array $row): bool => ($row['organization_identifier'] ?? null) === $organization
                && ($workspace === null || ($row['identifier'] ?? null) === $workspace),
        ));
        $overview['memberships'] = array_values(array_filter(
            $overview['memberships'] ?? [],
            static fn (array $row): bool => ($row['organization_identifier'] ?? null) === $organization
                && ($workspace === null || array_any(
                    is_array($row['workspaces'] ?? null) ? $row['workspaces'] : [],
                    static fn (mixed $assigned): bool => is_array($assigned)
                        && ($assigned['identifier'] ?? null) === $workspace,
                )),
        ));
        $memberIds = [];
        foreach ($overview['memberships'] as $membership) {
            $userId = $membership['user_id'] ?? null;
            if (is_string($userId)) {
                $memberIds[$userId] = true;
            }
        }
        $overview['users'] = array_values(array_filter(
            $overview['users'] ?? [],
            static fn (array $row): bool => is_string($row['id'] ?? null) && isset($memberIds[$row['id']]),
        ));
        $overview['resource_policies'] = array_values(array_filter(
            $overview['resource_policies'] ?? [],
            static fn (array $row): bool => ($row['organization_identifier'] ?? null) === $organization,
        ));
        $overview['separation_rules'] = array_values(array_filter(
            $overview['separation_rules'] ?? [],
            static fn (array $row): bool => ($row['organization_identifier'] ?? null) === $organization,
        ));
        $overview['approvals'] = array_values(array_filter(
            $overview['approvals'] ?? [],
            static fn (array $row): bool => ($row['organization_identifier'] ?? null) === $organization
                && ($workspace === null || ($row['workspace_identifier'] ?? null) === $workspace),
        ));
        $approvalIds = [];
        foreach ($overview['approvals'] as $approval) {
            $requestId = $approval['id'] ?? null;
            if (is_string($requestId)) {
                $approvalIds[$requestId] = true;
            }
        }
        $overview['approval_votes'] = array_values(array_filter(
            $overview['approval_votes'] ?? [],
            static fn (array $row): bool => is_string($row['request_id'] ?? null)
                && isset($approvalIds[$row['request_id']]),
        ));
        $overview['step_up_credentials'] = array_values(array_filter(
            $overview['step_up_credentials'] ?? [],
            static fn (array $row): bool => is_string($row['subject_id'] ?? null)
                && isset($memberIds[$row['subject_id']]),
        ));
        $overview['tokens'] = array_values(array_filter(
            $overview['tokens'] ?? [],
            static fn (array $row): bool => ($row['organization_identifier'] ?? null) === $organization
                && ($workspace === null || ($row['workspace_identifier'] ?? null) === $workspace),
        ));

        return $overview;
    }

    /**
     * Explain why each membership grant is or is not currently effective.
     *
     * @param   array<string, mixed>                 $membership    Structured membership projection.
     * @param   array<string, array<string, mixed>>  $capabilities  Live capability metadata keyed by code.
     *
     * @return  list<array<string, mixed>>  Explainable effective-access rows for presentation.
     *
     * @since   2.0.0
     */
    private function explainMembership(array $membership, array $capabilities): array
    {
        $effective = [];
        $active = ($membership['status'] ?? null) === 'active' && ($membership['expired'] ?? false) !== true;
        $roles = $membership['roles'] ?? [];
        if (!is_array($roles)) {
            return [];
        }
        foreach ($roles as $role) {
            if (!is_array($role)) {
                continue;
            }
            $grants = $role['grants'] ?? [];
            if (!is_array($grants)) {
                continue;
            }
            foreach ($grants as $grant) {
                if (!is_array($grant) || !is_string($grant['capability'] ?? null)) {
                    continue;
                }
                $code = $grant['capability'];
                $definition = $capabilities[$code] ?? [];
                $lifecycle = $definition['lifecycle_state'] ?? 'unknown';
                $effective[] = [
                    'capability' => $code,
                    'role' => $role['code'] ?? $role['id'] ?? 'unknown',
                    'scope_type' => $grant['scope_type'] ?? 'unknown',
                    'scope_identifier' => $grant['scope_identifier'] ?? null,
                    'owner' => $definition['owner_identifier'] ?? 'unknown',
                    'delegable' => (bool) ($definition['delegable'] ?? false),
                    'high_impact' => (bool) ($definition['high_impact'] ?? false),
                    'effective' => $active && in_array($lifecycle, ['active', 'deprecated'], true),
                    'reason' => !$active ? 'membership_inactive_or_expired'
                        : (in_array($lifecycle, ['active', 'deprecated'], true)
                            ? 'live_membership_role_grant'
                            : 'capability_owner_inactive'),
                ];
            }
        }
        usort($effective, static fn (array $left, array $right): int => [
            $left['capability'],
            $left['role'],
            $left['scope_type'],
            $left['scope_identifier'] ?? '',
        ] <=> [
            $right['capability'],
            $right['role'],
            $right['scope_type'],
            $right['scope_identifier'] ?? '',
        ]);

        return $effective;
    }

    /**
     * Normalize and validate an organization or workspace identifier.
     *
     * @param   string  $value  Candidate identifier supplied by the operator.
     * @param   string  $name   Entity noun used in validation failures.
     *
     * @return  string  Validated lowercase identifier.
     *
     * @since   2.0.0
     */
    private function scopeIdentifier(string $value, string $name): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[a-z0-9][a-z0-9._:-]{0,190}$/D', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('The %s identifier is invalid.', $name));
        }

        return $value;
    }

    /**
     * Validate a bounded operator-facing display name.
     *
     * @param   string  $value  Candidate name supplied by the operator.
     * @param   string  $name   Entity noun used in validation failures.
     *
     * @return  string  Trimmed display name containing one to 191 characters.
     *
     * @since   2.0.0
     */
    private function name(string $value, string $name): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 191) {
            throw new InvalidArgumentException(sprintf('The %s name must contain 1 to 191 characters.', $name));
        }

        return $value;
    }

    /**
     * Validate a stable machine token used to identify a policy or rule.
     *
     * @param   string  $value  Candidate policy code supplied by the operator.
     *
     * @return  string  Validated lowercase policy token.
     *
     * @since   2.0.0
     */
    private function policyCode(string $value): string
    {
        return $this->machineIdentifier($value, 'policy code', 191);
    }

    /**
     * Validate a bounded operation token, including definition-specific capability-and-handle forms.
     *
     * @param   string  $value    Candidate capability or operation token.
     * @param   string  $name     Field noun used in validation failures.
     * @param   int     $maximum  Maximum accepted byte length.
     *
     * @return  string  Canonical operation token.
     *
     * @since   2.0.0
     */
    private function operationToken(string $value, string $name, int $maximum): string
    {
        $value = Capability::fromString($value)->value();
        if (strlen($value) > $maximum) {
            throw new InvalidArgumentException(sprintf('The %s exceeds %d characters.', $name, $maximum));
        }

        return $value;
    }

    /**
     * Validate a lowercase dotted machine identifier within an explicit bound.
     *
     * @param   string  $value    Candidate machine identifier.
     * @param   string  $name     Field noun used in validation failures.
     * @param   int     $maximum  Maximum accepted byte length.
     *
     * @return  string  Validated machine identifier.
     *
     * @since   2.0.0
     */
    private function machineIdentifier(string $value, string $name, int $maximum): string
    {
        $value = trim($value);
        if (strlen($value) > $maximum || preg_match('/^[a-z][a-z0-9._-]*$/D', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('The %s is invalid.', $name));
        }

        return $value;
    }

    /**
     * Validate a non-empty bounded opaque database identity.
     *
     * @param   string  $value  Candidate identifier received from a structured form.
     * @param   string  $name   Entity noun used in validation failures.
     *
     * @return  string  Trimmed opaque identifier without control characters.
     *
     * @since   2.0.0
     */
    private function opaque(string $value, string $name): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 191 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException(sprintf('The %s identity is invalid.', $name));
        }

        return $value;
    }

    /**
     * Normalize an optional opaque identity from a blank structured form value.
     *
     * @param   ?string  $value  Candidate identifier or a blank optional value.
     * @param   string   $name   Entity noun used in validation failures.
     *
     * @return  ?string  Valid identifier, or null when the form value is blank.
     *
     * @since   2.0.0
     */
    private function optionalOpaque(?string $value, string $name): ?string
    {
        return $value === null || trim($value) === '' ? null : $this->opaque($value, $name);
    }

    /**
     * Validate a lifecycle status against a closed vocabulary.
     *
     * @param   string        $status   Candidate lifecycle state.
     * @param   list<string>  $allowed  Closed states accepted for this entity.
     * @param   string        $name     Entity noun used in validation failures.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function status(string $status, array $allowed, string $name): void
    {
        if (!in_array($status, $allowed, true)) {
            throw new InvalidArgumentException(sprintf('The %s status is invalid.', $name));
        }
    }

    /**
     * Require a positive optimistic version supplied by the administrator form.
     *
     * @param   int     $value  Version the operator observed.
     * @param   string  $name   Field noun used in validation failures.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function positive(int $value, string $name): void
    {
        if ($value < 1) {
            throw new InvalidArgumentException(sprintf('The %s must be positive.', $name));
        }
    }
}
