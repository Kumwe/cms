<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSecurity\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use JsonException;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\App\BusinessSecurity\Application\Administration\BusinessSecurityAdministrationRepository;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Context\Value\SiteContext;
use RuntimeException;

/**
 * DBAL adapter for the canonical administrator Business Security read and write model.
 *
 * This adapter operates exclusively on the identity and business-security tables used by authentication,
 * record policy, approval, step-up and token verification. It does not mirror any of that state. Every
 * item created here receives a resource-site ownership row in the same transaction so subsequent gateway
 * decisions can authorize the exact item rather than trusting a request-supplied site.
 *
 * @since  2.0.0
 */
final readonly class DoctrineBusinessSecurityAdministrationRepository implements
    BusinessSecurityAdministrationRepository
{
    /**
     * Configure the relational Business Security administration adapter.
     *
     * @param  Connection                   $database   Installation connection.
     * @param  TableNames                   $tables     Portable table-name compiler.
     * @param  ResourceSiteOwnershipWriter  $ownership  Canonical item ownership writer.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private ResourceSiteOwnershipWriter $ownership,
    ) {
    }

    /**
     * Assemble the bounded administrator security read model from canonical relational tables.
     *
     * @param   string  $siteIdentifier  Site whose security state may be disclosed.
     *
     * @return  array<string, list<array<string, mixed>>>  Structured security administration rows.
     *
     * @since   2.0.0
     */
    public function overview(string $siteIdentifier): array
    {
        $organizations = $this->database->fetchAllAssociative(sprintf(
            'SELECT id, identifier, name, status, policy_generation, version, created_at, updated_at '
            . 'FROM %s WHERE site_identifier = ? ORDER BY name, identifier LIMIT 500',
            $this->tables->quoted('organizations'),
        ), [$siteIdentifier]);
        $workspaces = $this->database->fetchAllAssociative(sprintf(
            'SELECT w.id, w.organization_id, w.identifier, w.name, w.status, w.version, '
            . 'w.created_at, w.updated_at, o.identifier AS organization_identifier '
            . 'FROM %s w INNER JOIN %s o ON o.id = w.organization_id '
            . 'WHERE o.site_identifier = ? ORDER BY o.identifier, w.name, w.identifier LIMIT 500',
            $this->tables->quoted('workspaces'),
            $this->tables->quoted('organizations'),
        ), [$siteIdentifier]);
        $users = $this->database->fetchAllAssociative(sprintf(
            'SELECT id, email, display_name, status, version, security_epoch FROM %s '
            . 'ORDER BY display_name, email, id LIMIT 500',
            $this->tables->quoted('users'),
        ));
        $roles = $this->roles();
        $memberships = $this->memberships($siteIdentifier, $roles, $workspaces);
        $policies = $this->resourcePolicies($siteIdentifier);
        $rules = $this->separationRules($siteIdentifier);
        [$approvals, $votes] = $this->approvals($siteIdentifier);
        $stepUp = $this->stepUpStatus($siteIdentifier);
        $tokens = $this->tokens($siteIdentifier);
        $definitions = $this->definitions($siteIdentifier);
        $capabilities = $this->capabilities();

        return [
            'organizations' => $organizations,
            'workspaces' => $workspaces,
            'users' => $users,
            'roles' => $roles,
            'memberships' => $memberships,
            'resource_policies' => $policies,
            'separation_rules' => $rules,
            'approvals' => $approvals,
            'approval_votes' => $votes,
            'step_up_credentials' => $stepUp,
            'tokens' => $tokens,
            'definitions' => $definitions,
            'capabilities' => $capabilities,
        ];
    }

    /**
     * Resolve and optionally lock the exact actor and organization behind one site membership.
     *
     * @param   string  $membershipId    Membership row to resolve.
     * @param   string  $siteIdentifier  Site that must own the membership.
     * @param   bool    $lock            Whether to append the platform lock clause.
     *
     * @return  ?array{user_id: string, organization_id: string, organization_identifier: string}  Authority row.
     *
     * @since   2.0.0
     */
    public function membershipAuthority(
        string $membershipId,
        string $siteIdentifier,
        bool $lock = false,
    ): ?array {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT m.user_id, m.organization_id, o.identifier AS organization_identifier '
            . 'FROM %s m INNER JOIN %s o ON o.id = m.organization_id '
            . 'WHERE m.id = ? AND o.site_identifier = ?%s',
            $this->tables->quoted('organization_memberships'),
            $this->tables->quoted('organizations'),
            $lock ? $this->lockClause() : '',
        ), [$membershipId, $siteIdentifier]);
        if ($row === false) {
            return null;
        }

        return [
            'user_id' => $this->string($row, 'user_id'),
            'organization_id' => $this->string($row, 'organization_id'),
            'organization_identifier' => $this->string($row, 'organization_identifier'),
        ];
    }

    /**
     * Resolve an organization's public identifier within one exact site.
     *
     * @param   string  $organizationId  Organization row to resolve.
     * @param   string  $siteIdentifier  Site that must own the organization.
     * @param   bool    $lock            Whether to append the platform lock clause.
     *
     * @return  ?string  Public identifier, or null when the organization is unavailable.
     *
     * @since   2.0.0
     */
    public function organizationIdentifier(
        string $organizationId,
        string $siteIdentifier,
        bool $lock = false,
    ): ?string {
        $identifier = $this->database->fetchOne(sprintf(
            'SELECT identifier FROM %s WHERE id = ? AND site_identifier = ?%s',
            $this->tables->quoted('organizations'),
            $lock ? $this->lockClause() : '',
        ), [$organizationId, $siteIdentifier]);

        return is_string($identifier) ? $identifier : null;
    }

    /**
     * Resolve and optionally lock one workspace and its exact parent organization.
     *
     * @param   string  $workspaceId     Workspace row to resolve.
     * @param   string  $siteIdentifier  Site that must own the parent organization.
     * @param   bool    $lock            Whether to append the platform lock clause.
     *
     * @return  ?array{identifier: string, organization_id: string, organization_identifier: string}  Authority row.
     *
     * @since   2.0.0
     */
    public function workspaceAuthority(
        string $workspaceId,
        string $siteIdentifier,
        bool $lock = false,
    ): ?array {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT w.identifier, w.organization_id, o.identifier AS organization_identifier '
            . 'FROM %s w INNER JOIN %s o ON o.id = w.organization_id '
            . 'WHERE w.id = ? AND o.site_identifier = ?%s',
            $this->tables->quoted('workspaces'),
            $this->tables->quoted('organizations'),
            $lock ? $this->lockClause() : '',
        ), [$workspaceId, $siteIdentifier]);
        if ($row === false) {
            return null;
        }

        return [
            'identifier' => $this->string($row, 'identifier'),
            'organization_id' => $this->string($row, 'organization_id'),
            'organization_identifier' => $this->string($row, 'organization_identifier'),
        ];
    }

    /**
     * Load the canonical capability grants currently attached to one role.
     *
     * @param   string  $roleId  Role whose delegation ceiling is being inspected.
     *
     * @return  list<array{capability: string, scope_type: string, scope_identifier: ?string}>  Grant rows.
     *
     * @since   2.0.0
     */
    public function roleGrants(string $roleId): array
    {
        /** @var list<array{capability: string, scope_type: string, scope_identifier: ?string}> $rows */
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT capability_code AS capability, scope_type, scope_identifier FROM %s '
            . 'WHERE role_id = ? ORDER BY capability_code, scope_type, scope_identifier',
            $this->tables->quoted('role_capability_grants'),
        ), [$roleId]);

        return $rows;
    }

    /**
     * Decode policy-compatible field types from one published site definition.
     *
     * @param   string  $definitionId    Definition row to inspect.
     * @param   string  $siteIdentifier  Site that must own the definition.
     *
     * @return  array<string, string>  Field handles keyed to policy value type.
     *
     * @since   2.0.0
     */
    public function definitionFieldTypes(string $definitionId, string $siteIdentifier): array
    {
        $definition = $this->definition($definitionId, $siteIdentifier);

        return $definition['field_types'] ?? [];
    }

    /**
     * Decode published action handles from one exact site definition.
     *
     * @param   string  $definitionId    Definition row to inspect.
     * @param   string  $siteIdentifier  Site that must own the definition.
     *
     * @return  list<string>  Published action handles.
     *
     * @since   2.0.0
     */
    public function definitionActions(string $definitionId, string $siteIdentifier): array
    {
        $definition = $this->definition($definitionId, $siteIdentifier);

        return $definition['actions'] ?? [];
    }

    /**
     * Resolve and optionally lock authority-bearing fields from one site-owned record policy.
     *
     * @param   string  $policyId        Policy row whose activation is being evaluated.
     * @param   string  $siteIdentifier  Site that must own the policy definition and organization.
     * @param   bool    $lock            Whether to append the platform lock clause.
     *
     * @return  ?array{effect: string, capability: string, organization_id: ?string,
     *          organization_identifier: ?string}  Policy authority, or null when unavailable in the site.
     *
     * @since   2.0.0
     */
    public function resourcePolicyAuthority(
        string $policyId,
        string $siteIdentifier,
        bool $lock = false,
    ): ?array {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT p.effect, p.capability_code, p.organization_id FROM %s p '
            . 'INNER JOIN %s d ON d.id = p.entity_definition_id '
            . 'WHERE p.id = ? AND d.site_identifier = ?%s',
            $this->tables->quoted('resource_policies'),
            $this->tables->quoted('business_definitions'),
            $lock ? $this->lockClause() : '',
        ), [$policyId, $siteIdentifier]);
        if ($row === false) {
            return null;
        }
        $organizationId = $this->nullableString($row['organization_id'] ?? null);
        $organizationIdentifier = $organizationId === null
            ? null
            : $this->organizationIdentifier($organizationId, $siteIdentifier, $lock);
        if ($organizationId !== null && $organizationIdentifier === null) {
            return null;
        }

        return [
            'effect' => $this->string($row, 'effect'),
            'capability' => $this->string($row, 'capability_code'),
            'organization_id' => $organizationId,
            'organization_identifier' => $organizationIdentifier,
        ];
    }

    /**
     * Resolve and optionally lock one site-owned separation rule's organization scope.
     *
     * @param   string  $ruleId          Separation rule to resolve.
     * @param   string  $siteIdentifier  Site that must own the rule and optional organization.
     * @param   bool    $lock            Whether to append the platform lock clause.
     *
     * @return  ?array{organization_id: ?string, organization_identifier: ?string}  Authority row,
     *          or null when unavailable.
     *
     * @since   2.0.0
     */
    public function separationRuleAuthority(
        string $ruleId,
        string $siteIdentifier,
        bool $lock = false,
    ): ?array {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT organization_id FROM %s WHERE id = ? AND site_identifier = ?%s',
            $this->tables->quoted('separation_duty_rules'),
            $lock ? $this->lockClause() : '',
        ), [$ruleId, $siteIdentifier]);
        if ($row === false) {
            return null;
        }
        $organizationId = $this->nullableString($row['organization_id'] ?? null);
        $organizationIdentifier = $organizationId === null
            ? null
            : $this->organizationIdentifier($organizationId, $siteIdentifier, $lock);
        if ($organizationId !== null && $organizationIdentifier === null) {
            return null;
        }

        return [
            'organization_id' => $organizationId,
            'organization_identifier' => $organizationIdentifier,
        ];
    }

    /**
     * Insert an active site organization and its canonical ownership row.
     *
     * @param   string             $id              Application-assigned UUID.
     * @param   string             $siteIdentifier  Site owning the organization.
     * @param   string             $identifier      Stable organization identifier.
     * @param   string             $name            Human-readable organization name.
     * @param   DateTimeImmutable  $at              Trusted creation instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function insertOrganization(
        string $id,
        string $siteIdentifier,
        string $identifier,
        string $name,
        DateTimeImmutable $at,
    ): void {
        $this->database->insert($this->tables->raw('organizations'), [
            'id' => $id,
            'site_identifier' => $siteIdentifier,
            'identifier' => $identifier,
            'name' => $name,
            'status' => 'active',
            'policy_generation' => 1,
            'version' => 1,
            'created_at' => $at,
            'updated_at' => $at,
        ], $this->timeTypes('created_at', 'updated_at'));
        $this->recordOwnership('organization', $id, $siteIdentifier);
    }

    /**
     * Insert an active workspace beneath a locked site organization.
     *
     * @param   string             $id              Application-assigned UUID.
     * @param   string             $organizationId  Parent organization row.
     * @param   string             $siteIdentifier  Site that must own the organization.
     * @param   string             $identifier      Stable workspace identifier.
     * @param   string             $name            Human-readable workspace name.
     * @param   DateTimeImmutable  $at              Trusted creation instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function insertWorkspace(
        string $id,
        string $organizationId,
        string $siteIdentifier,
        string $identifier,
        string $name,
        DateTimeImmutable $at,
    ): void {
        $this->assertOrganization($organizationId, $siteIdentifier, true);
        $this->database->insert($this->tables->raw('workspaces'), [
            'id' => $id,
            'organization_id' => $organizationId,
            'identifier' => $identifier,
            'name' => $name,
            'status' => 'active',
            'version' => 1,
            'created_at' => $at,
            'updated_at' => $at,
        ], $this->timeTypes('created_at', 'updated_at'));
        $this->recordOwnership('workspace', $id, $siteIdentifier);
        $this->bumpPolicyGeneration($organizationId, $at);
    }

    /**
     * Insert an active membership and invalidate affected authorization snapshots.
     *
     * @param   string              $id              Application-assigned UUID.
     * @param   string              $organizationId  Organization receiving the member.
     * @param   string              $siteIdentifier  Site owning the organization and membership.
     * @param   string              $userId          User who owns the membership.
     * @param   DateTimeImmutable   $validFrom       First valid instant.
     * @param   ?DateTimeImmutable  $validUntil      Optional exclusive expiry.
     * @param   string              $actorId         Administrator creating the row.
     * @param   DateTimeImmutable   $at              Trusted creation instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function insertMembership(
        string $id,
        string $organizationId,
        string $siteIdentifier,
        string $userId,
        DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validUntil,
        string $actorId,
        DateTimeImmutable $at,
    ): void {
        $this->assertOrganization($organizationId, $siteIdentifier, true);
        $this->assertUser($userId);
        $this->database->insert($this->tables->raw('organization_memberships'), [
            'id' => $id,
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'status' => 'active',
            'version' => 1,
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            'created_by' => $actorId,
            'created_at' => $at,
            'updated_at' => $at,
        ], $this->timeTypes('valid_from', 'valid_until', 'created_at', 'updated_at'));
        $this->recordOwnership('organization_membership', $id, $siteIdentifier);
        $this->bumpUserEpoch($userId);
        $this->bumpPolicyGeneration($organizationId, $at);
    }

    /**
     * Optimistically change a membership and invalidate its effective authority.
     *
     * @param   string             $membershipId     Membership row to update.
     * @param   string             $siteIdentifier   Site that must own the membership.
     * @param   string             $status           Validated target state.
     * @param   int                $expectedVersion  Version observed by the operator.
     * @param   string             $actorId          Administrator applying the change.
     * @param   DateTimeImmutable  $at               Trusted transition instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function updateMembershipStatus(
        string $membershipId,
        string $siteIdentifier,
        string $status,
        int $expectedVersion,
        string $actorId,
        DateTimeImmutable $at,
    ): void {
        $membership = $this->membership($membershipId, $siteIdentifier, true);
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET status = ?, version = version + 1, updated_at = ? '
            . 'WHERE id = ? AND version = ?',
            $this->tables->quoted('organization_memberships'),
        ), [$status, $at, $membershipId, $expectedVersion], [
            Types::STRING,
            Types::DATETIME_IMMUTABLE,
            Types::GUID,
            Types::INTEGER,
        ]);
        $this->assertChanged($affected, 'The membership changed before its status could be saved.');
        $this->bumpUserEpoch($membership['user_id']);
        $this->bumpPolicyGeneration($membership['organization_id'], $at);
    }

    /**
     * Insert a workspace assignment after verifying the exact membership organization.
     *
     * @param   string             $membershipId    Membership receiving the workspace.
     * @param   string             $workspaceId     Active workspace to assign.
     * @param   string             $siteIdentifier  Site that must own both rows.
     * @param   string             $actorId         Administrator assigning the workspace.
     * @param   DateTimeImmutable  $at              Trusted assignment instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function assignMembershipWorkspace(
        string $membershipId,
        string $workspaceId,
        string $siteIdentifier,
        string $actorId,
        DateTimeImmutable $at,
    ): void {
        $membership = $this->membership($membershipId, $siteIdentifier, true);
        $matches = $this->database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE id = ? AND organization_id = ? AND status = ?',
            $this->tables->quoted('workspaces'),
        ), [$workspaceId, $membership['organization_id'], 'active']);
        if ($matches === false) {
            throw new InvalidArgumentException('The workspace is not active in the membership organization.');
        }
        $this->database->insert($this->tables->raw('membership_workspaces'), [
            'membership_id' => $membershipId,
            'workspace_id' => $workspaceId,
            'assigned_by' => $actorId,
            'assigned_at' => $at,
        ], ['assigned_at' => Types::DATETIME_IMMUTABLE]);
        $this->invalidateMembership($membership, $at);
    }

    /**
     * Delete an exact workspace assignment and invalidate the membership snapshot.
     *
     * @param   string             $membershipId    Membership losing the workspace.
     * @param   string             $workspaceId     Workspace assignment to delete.
     * @param   string             $siteIdentifier  Site that must own the membership.
     * @param   DateTimeImmutable  $at              Trusted revocation instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function revokeMembershipWorkspace(
        string $membershipId,
        string $workspaceId,
        string $siteIdentifier,
        DateTimeImmutable $at,
    ): void {
        $membership = $this->membership($membershipId, $siteIdentifier, true);
        $affected = $this->database->delete($this->tables->raw('membership_workspaces'), [
            'membership_id' => $membershipId,
            'workspace_id' => $workspaceId,
        ]);
        $this->assertChanged($affected, 'The membership workspace assignment does not exist.');
        $this->invalidateMembership($membership, $at);
    }

    /**
     * Insert a membership role assignment and invalidate the membership snapshot.
     *
     * @param   string             $membershipId    Membership receiving the role.
     * @param   string             $roleId          Existing role to assign.
     * @param   string             $siteIdentifier  Site that must own the membership.
     * @param   string             $actorId         Administrator assigning the role.
     * @param   DateTimeImmutable  $at              Trusted assignment instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function assignMembershipRole(
        string $membershipId,
        string $roleId,
        string $siteIdentifier,
        string $actorId,
        DateTimeImmutable $at,
    ): void {
        $membership = $this->membership($membershipId, $siteIdentifier, true);
        $this->assertRole($roleId);
        $this->database->insert($this->tables->raw('membership_roles'), [
            'membership_id' => $membershipId,
            'role_id' => $roleId,
            'assigned_by' => $actorId,
            'assigned_at' => $at,
        ], ['assigned_at' => Types::DATETIME_IMMUTABLE]);
        $this->invalidateMembership($membership, $at);
    }

    /**
     * Delete an exact membership role and invalidate the membership snapshot.
     *
     * @param   string             $membershipId    Membership losing the role.
     * @param   string             $roleId          Role assignment to delete.
     * @param   string             $siteIdentifier  Site that must own the membership.
     * @param   DateTimeImmutable  $at              Trusted revocation instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function revokeMembershipRole(
        string $membershipId,
        string $roleId,
        string $siteIdentifier,
        DateTimeImmutable $at,
    ): void {
        $membership = $this->membership($membershipId, $siteIdentifier, true);
        $affected = $this->database->delete($this->tables->raw('membership_roles'), [
            'membership_id' => $membershipId,
            'role_id' => $roleId,
        ]);
        $this->assertChanged($affected, 'The membership role assignment does not exist.');
        $this->invalidateMembership($membership, $at);
    }

    /**
     * Insert a canonical business-record policy and its site ownership row.
     *
     * @param   string                       $id                  Application-assigned UUID.
     * @param   string                       $policyCode          Stable policy code.
     * @param   string                       $capability          Capability governed by the policy.
     * @param   string                       $action              Operation token evaluated by policy compilation.
     * @param   string                       $effect              Closed allow-or-deny effect.
     * @param   ?string                      $organizationId      Optional organization restriction.
     * @param   ?string                      $entityDefinitionId  Required published definition restriction.
     * @param   array<string, mixed>         $predicate           Canonical closed policy AST.
     * @param   array<string, list<string>>  $fields              Explicit field and action disclosures.
     * @param   string                       $checksum            Digest of the canonical document.
     * @param   int                          $priority            Stable policy ordering priority.
     * @param   string                       $actorId             Administrator creating the policy.
     * @param   string                       $siteIdentifier      Site owning the policy.
     * @param   DateTimeImmutable            $at                  Trusted creation instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function insertResourcePolicy(
        string $id,
        string $policyCode,
        string $capability,
        string $action,
        string $effect,
        ?string $organizationId,
        ?string $entityDefinitionId,
        array $predicate,
        array $fields,
        string $checksum,
        int $priority,
        string $actorId,
        string $siteIdentifier,
        DateTimeImmutable $at,
    ): void {
        if ($organizationId !== null) {
            $this->assertOrganization($organizationId, $siteIdentifier, true);
        }
        if ($entityDefinitionId === null || $this->definition($entityDefinitionId, $siteIdentifier) === null) {
            throw new InvalidArgumentException('The resource policy definition is unavailable in this site.');
        }
        $this->database->insert($this->tables->raw('resource_policies'), [
            'id' => $id,
            'policy_code' => $policyCode,
            'owner_kind' => 'core',
            'owner_identifier' => 'core',
            'capability_code' => $capability,
            'resource_type' => 'business_record',
            'action' => $action,
            'effect' => $effect,
            'scope_type' => $organizationId === null ? 'site' : 'organization',
            'organization_id' => $organizationId,
            'entity_definition_id' => $entityDefinitionId,
            'canonical_ast' => $predicate,
            'field_rules' => $fields,
            'ast_checksum' => $checksum,
            'policy_version' => 1,
            'priority' => $priority,
            'status' => 'active',
            'created_by' => $actorId,
            'created_at' => $at,
            'updated_at' => $at,
        ], [
            'canonical_ast' => Types::JSON,
            'field_rules' => Types::JSON,
            ...$this->timeTypes('created_at', 'updated_at'),
        ]);
        $this->recordOwnership('resource_policy', $id, $siteIdentifier);
        $this->bumpPolicies($siteIdentifier, $organizationId, $at);
    }

    /**
     * Optimistically change a resource policy and invalidate affected policy generations.
     *
     * @param   string             $id               Policy row to update.
     * @param   string             $siteIdentifier   Site that must own the policy.
     * @param   string             $status           Validated target state.
     * @param   int                $expectedVersion  Version observed by the operator.
     * @param   DateTimeImmutable  $at               Trusted transition instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function updateResourcePolicyStatus(
        string $id,
        string $siteIdentifier,
        string $status,
        int $expectedVersion,
        DateTimeImmutable $at,
    ): void {
        $row = $this->policyScope($id, $siteIdentifier, true);
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET status = ?, policy_version = policy_version + 1, updated_at = ? '
            . 'WHERE id = ? AND policy_version = ?',
            $this->tables->quoted('resource_policies'),
        ), [$status, $at, $id, $expectedVersion], [
            Types::STRING,
            Types::DATETIME_IMMUTABLE,
            Types::GUID,
            Types::INTEGER,
        ]);
        $this->assertChanged($affected, 'The resource policy changed before its status could be saved.');
        $this->bumpPolicies($siteIdentifier, $row['organization_id'], $at);
    }

    /**
     * Insert a site-bounded separation rule and its canonical ownership row.
     *
     * @param   string             $id               Application-assigned UUID.
     * @param   ?string            $organizationId   Optional organization restriction.
     * @param   string             $ruleCode         Stable rule code.
     * @param   string             $resourceType     Resource type governed by the rule.
     * @param   string             $requestAction    Exact operation token triggering approval.
     * @param   string             $approvalAction   Capability required on the approval request.
     * @param   ?string            $requesterRoleId  Optional requester role restriction.
     * @param   ?string            $approverRoleId   Optional approver role restriction.
     * @param   int                $quorum           Required number of approvals.
     * @param   bool               $distinctActors   Whether votes must use distinct actors.
     * @param   string             $actorId          Administrator creating the rule.
     * @param   string             $siteIdentifier   Site owning the rule.
     * @param   DateTimeImmutable  $at               Trusted creation instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function insertSeparationRule(
        string $id,
        ?string $organizationId,
        string $ruleCode,
        string $resourceType,
        string $requestAction,
        string $approvalAction,
        ?string $requesterRoleId,
        ?string $approverRoleId,
        int $quorum,
        bool $distinctActors,
        string $actorId,
        string $siteIdentifier,
        DateTimeImmutable $at,
    ): void {
        if ($organizationId !== null) {
            $this->assertOrganization($organizationId, $siteIdentifier, true);
        }
        foreach (
            array_filter(
                [$requesterRoleId, $approverRoleId],
                static fn (?string $roleId): bool => $roleId !== null,
            ) as $roleId
        ) {
            $this->assertRole($roleId);
        }
        $this->database->insert($this->tables->raw('separation_duty_rules'), [
            'id' => $id,
            'site_identifier' => $siteIdentifier,
            'organization_id' => $organizationId,
            'scope_key' => $organizationId ?? 'site',
            'rule_code' => $ruleCode,
            'resource_type' => $resourceType,
            'request_action' => $requestAction,
            'approval_action' => $approvalAction,
            'requester_role_id' => $requesterRoleId,
            'approver_role_id' => $approverRoleId,
            'quorum' => $quorum,
            'distinct_actors' => $distinctActors,
            'status' => 'active',
            'version' => 1,
            'created_by' => $actorId,
            'created_at' => $at,
            'updated_at' => $at,
        ], [
            'distinct_actors' => Types::BOOLEAN,
            ...$this->timeTypes('created_at', 'updated_at'),
        ]);
        $this->recordOwnership('separation_duty_rule', $id, $siteIdentifier);
        $this->bumpPolicies($siteIdentifier, $organizationId, $at);
    }

    /**
     * Optimistically change a separation rule and invalidate affected policy generations.
     *
     * @param   string             $id               Separation rule to update.
     * @param   string             $siteIdentifier   Site that must own the rule.
     * @param   string             $status           Validated target state.
     * @param   int                $expectedVersion  Version observed by the operator.
     * @param   DateTimeImmutable  $at               Trusted transition instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function updateSeparationRuleStatus(
        string $id,
        string $siteIdentifier,
        string $status,
        int $expectedVersion,
        DateTimeImmutable $at,
    ): void {
        $row = $this->ruleScope($id, $siteIdentifier, true);
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET status = ?, version = version + 1, updated_at = ? '
            . 'WHERE id = ? AND version = ?',
            $this->tables->quoted('separation_duty_rules'),
        ), [$status, $at, $id, $expectedVersion], [
            Types::STRING,
            Types::DATETIME_IMMUTABLE,
            Types::GUID,
            Types::INTEGER,
        ]);
        $this->assertChanged($affected, 'The separation rule changed before its status could be saved.');
        $this->bumpPolicies($siteIdentifier, $row['organization_id'], $at);
    }

    /**
     * Load roles with their canonical capability grant rows nested for presentation.
     *
     * @return  list<array<string, mixed>>  Bounded role inventory.
     *
     * @since   2.0.0
     */
    private function roles(): array
    {
        $roles = $this->database->fetchAllAssociative(sprintf(
            'SELECT id, code, name, created_at FROM %s ORDER BY name, code, id LIMIT 500',
            $this->tables->quoted('roles'),
        ));
        foreach ($roles as &$role) {
            $id = $role['id'] ?? null;
            $role['grants'] = is_string($id) ? $this->roleGrants($id) : [];
        }
        unset($role);

        return $roles;
    }

    /**
     * Attach canonical role and workspace rows to each site membership.
     *
     * @param   string                      $siteIdentifier  Site whose memberships may be disclosed.
     * @param   list<array<string, mixed>>  $roles           Role rows available for nesting.
     * @param   list<array<string, mixed>>  $workspaces      Workspace rows available for nesting.
     *
     * @return  list<array<string, mixed>>  Memberships with nested roles, workspaces, and expiry state.
     *
     * @since   2.0.0
     */
    private function memberships(string $siteIdentifier, array $roles, array $workspaces): array
    {
        $memberships = $this->database->fetchAllAssociative(sprintf(
            'SELECT m.id, m.organization_id, m.user_id, m.status, m.version, m.valid_from, m.valid_until, '
            . 'm.created_by, m.created_at, m.updated_at, o.identifier AS organization_identifier, '
            . 'u.email, u.display_name FROM %s m INNER JOIN %s o ON o.id = m.organization_id '
            . 'INNER JOIN %s u ON u.id = m.user_id WHERE o.site_identifier = ? '
            . 'ORDER BY o.identifier, u.display_name, u.email, m.id LIMIT 500',
            $this->tables->quoted('organization_memberships'),
            $this->tables->quoted('organizations'),
            $this->tables->quoted('users'),
        ), [$siteIdentifier]);
        $rolesById = $this->index($roles);
        $workspacesById = $this->index($workspaces);
        $now = new DateTimeImmutable('now');
        foreach ($memberships as &$membership) {
            $id = $this->string($membership, 'id');
            $membership['roles'] = [];
            foreach (
                $this->database->fetchFirstColumn(sprintf(
                    'SELECT role_id FROM %s WHERE membership_id = ? ORDER BY role_id',
                    $this->tables->quoted('membership_roles'),
                ), [$id]) as $roleId
            ) {
                if (is_string($roleId) && isset($rolesById[$roleId])) {
                    $membership['roles'][] = $rolesById[$roleId];
                }
            }
            $membership['workspaces'] = [];
            foreach (
                $this->database->fetchFirstColumn(sprintf(
                    'SELECT workspace_id FROM %s WHERE membership_id = ? ORDER BY workspace_id',
                    $this->tables->quoted('membership_workspaces'),
                ), [$id]) as $workspaceId
            ) {
                if (is_string($workspaceId) && isset($workspacesById[$workspaceId])) {
                    $membership['workspaces'][] = $workspacesById[$workspaceId];
                }
            }
            $validUntil = $membership['valid_until'] ?? null;
            $membership['expired'] = $validUntil !== null && $this->date($validUntil) <= $now;
        }
        unset($membership);

        return $memberships;
    }

    /**
     * Load and decode the structured ABAC policy inventory visible to one site.
     *
     * @param   string  $siteIdentifier  Site whose scoped policies may be disclosed.
     *
     * @return  list<array<string, mixed>>  Policy rows with decoded predicate and field rules.
     *
     * @since   2.0.0
     */
    private function resourcePolicies(string $siteIdentifier): array
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT p.id, p.policy_code, p.owner_kind, p.owner_identifier, p.capability_code, '
            . 'p.resource_type, p.action, p.effect, p.scope_type, p.organization_id, '
            . 'p.entity_definition_id, p.canonical_ast, p.field_rules, p.ast_checksum, '
            . 'p.policy_version, p.priority, p.status, p.created_by, p.created_at, p.updated_at, '
            . 'o.identifier AS organization_identifier, d.handle AS definition_handle '
            . 'FROM %s p LEFT JOIN %s o ON o.id = p.organization_id '
            . 'LEFT JOIN %s d ON d.id = p.entity_definition_id '
            . 'WHERE (p.organization_id IS NULL OR o.site_identifier = ?) '
            . 'AND (d.id IS NULL OR d.site_identifier = ?) ORDER BY p.priority DESC, p.policy_code LIMIT 500',
            $this->tables->quoted('resource_policies'),
            $this->tables->quoted('organizations'),
            $this->tables->quoted('business_definitions'),
        ), [$siteIdentifier, $siteIdentifier]);
        foreach ($rows as &$row) {
            $row['predicate'] = $this->document($row['canonical_ast'] ?? null);
            $row['field_rules'] = $this->document($row['field_rules'] ?? null);
            unset($row['canonical_ast']);
        }
        unset($row);

        return $rows;
    }

    /**
     * Load the structured maker-checker rule inventory for one site.
     *
     * @param   string  $siteIdentifier  Site that must own every returned rule.
     *
     * @return  list<array<string, mixed>>  Site-wide and organization-scoped rules.
     *
     * @since   2.0.0
     */
    private function separationRules(string $siteIdentifier): array
    {
        return $this->database->fetchAllAssociative(sprintf(
            'SELECT r.id, r.site_identifier, r.organization_id, r.rule_code, r.resource_type, r.request_action, '
            . 'r.approval_action, r.requester_role_id, r.approver_role_id, r.quorum, '
            . 'r.distinct_actors, r.status, r.version, r.created_by, r.created_at, r.updated_at, '
            . 'o.identifier AS organization_identifier, rr.code AS requester_role_code, '
            . 'ar.code AS approver_role_code FROM %s r LEFT JOIN %s o ON o.id = r.organization_id '
            . 'LEFT JOIN %s rr ON rr.id = r.requester_role_id LEFT JOIN %s ar ON ar.id = r.approver_role_id '
            . 'WHERE r.site_identifier = ? AND (r.organization_id IS NULL OR o.site_identifier = ?) '
            . 'ORDER BY r.rule_code, r.id LIMIT 500',
            $this->tables->quoted('separation_duty_rules'),
            $this->tables->quoted('organizations'),
            $this->tables->quoted('roles'),
            $this->tables->quoted('roles'),
        ), [$siteIdentifier, $siteIdentifier]);
    }

    /**
     * Load bounded approval requests and their votes for one exact site.
     *
     * @param   string  $siteIdentifier  Site that must own every returned request.
     *
     * @return  array{0: list<array<string,mixed>>, 1: list<array<string,mixed>>}  Requests and votes.
     *
     * @since   2.0.0
     */
    private function approvals(string $siteIdentifier): array
    {
        $requests = $this->database->fetchAllAssociative(sprintf(
            'SELECT a.id, a.rule_id, a.rule_version, a.approval_action, a.approver_role_id, '
            . 'a.distinct_actors, a.requester_id, a.action, a.resource_type, a.resource_id, '
            . 'a.resource_version, a.required_quorum, a.status, a.expires_at, a.created_at, '
            . 'a.resolved_at, a.consumed_at, a.revoked_at, a.version, '
            . 'o.identifier AS organization_identifier, w.identifier AS workspace_identifier, '
            . 'r.rule_code FROM %s a INNER JOIN %s r ON r.id = a.rule_id '
            . 'LEFT JOIN %s o ON o.id = a.organization_id LEFT JOIN %s w ON w.id = a.workspace_id '
            . 'WHERE a.site_identifier = ? ORDER BY a.created_at DESC, a.id DESC LIMIT 200',
            $this->tables->quoted('approval_requests'),
            $this->tables->quoted('separation_duty_rules'),
            $this->tables->quoted('organizations'),
            $this->tables->quoted('workspaces'),
        ), [$siteIdentifier]);
        $ids = [];
        foreach ($requests as $request) {
            $id = $request['id'] ?? null;
            if (is_string($id)) {
                $ids[$id] = true;
            }
        }
        $votes = [];
        if ($ids !== []) {
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $votes = $this->database->fetchAllAssociative(sprintf(
                'SELECT request_id, approver_id, decision, reason, step_up_proof_id, decided_at '
                . 'FROM %s WHERE request_id IN (%s) ORDER BY decided_at, id',
                $this->tables->quoted('approval_votes'),
                $placeholders,
            ), array_keys($ids));
        }

        return [$requests, $votes];
    }

    /**
     * Load non-secret step-up enrollment and recovery status for site members.
     *
     * @param   string  $siteIdentifier  Site whose member credentials may be disclosed.
     *
     * @return  list<array<string,mixed>>  Credential lifecycle and remaining recovery-code counts.
     *
     * @since   2.0.0
     */
    private function stepUpStatus(string $siteIdentifier): array
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT c.id, c.subject_id, c.status, c.created_at, c.enrollment_expires_at, '
            . 'c.confirmed_at, c.last_verified_at, c.disabled_at, c.version, u.email, u.display_name '
            . 'FROM %s c INNER JOIN %s u ON u.id = c.subject_id '
            . 'WHERE EXISTS (SELECT 1 FROM %s m INNER JOIN %s o ON o.id = m.organization_id '
            . 'WHERE m.user_id = c.subject_id AND o.site_identifier = ?) '
            . 'ORDER BY u.display_name, u.email, c.created_at DESC LIMIT 500',
            $this->tables->quoted('step_up_credentials'),
            $this->tables->quoted('users'),
            $this->tables->quoted('organization_memberships'),
            $this->tables->quoted('organizations'),
        ), [$siteIdentifier]);
        foreach ($rows as &$row) {
            $id = $this->string($row, 'id');
            $remaining = $this->database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s WHERE credential_id = ? AND consumed_at IS NULL',
                $this->tables->quoted('step_up_recovery_codes'),
            ), [$id]);
            $row['recovery_codes_remaining'] = $this->integer($remaining);
        }
        unset($row);

        return $rows;
    }

    /**
     * Build scope and delegation diagnostics without disclosing access-token secrets.
     *
     * @param   string  $siteIdentifier  Exact site scope of the token inventory.
     *
     * @return  list<array<string,mixed>>  Token metadata with family and attenuation diagnostics.
     *
     * @since   2.0.0
     */
    private function tokens(string $siteIdentifier): array
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT t.id, t.subject_id, t.name, t.capabilities, t.security_epoch, t.audience, '
            . 't.purpose, t.site_identifier, t.organization_identifier, t.workspace_identifier, '
            . 't.membership_id, t.membership_version, t.policy_generation, t.family_id, '
            . 't.parent_token_id, t.rotated_from, t.delegation_depth, t.owner_identifier, t.expires_at, '
            . 't.revoked_at, t.revocation_reason, t.created_at, t.last_used_at, '
            . 'u.email, u.display_name FROM %s t INNER JOIN %s u ON u.id = t.subject_id '
            . 'WHERE t.site_identifier = ? ORDER BY t.created_at DESC, t.id DESC LIMIT 500',
            $this->tables->quoted('api_tokens'),
            $this->tables->quoted('users'),
        ), [$siteIdentifier]);
        $byId = [];
        foreach ($rows as &$row) {
            $row['capabilities'] = $this->stringList($row['capabilities'] ?? null, 'token capabilities');
            $id = $this->string($row, 'id');
            $byId[$id] = &$row;
        }
        unset($row);
        foreach ($rows as &$row) {
            $reasons = [];
            $capabilities = $this->stringList($row['capabilities'] ?? null, 'token capabilities');
            $parentId = $row['parent_token_id'] ?? null;
            if ($parentId !== null) {
                $parent = is_string($parentId) ? ($byId[$parentId] ?? null) : null;
                if (!is_array($parent)) {
                    $reasons[] = 'parent_unavailable_in_site';
                } else {
                    $parentCapabilities = $this->stringList(
                        $parent['capabilities'] ?? null,
                        'parent token capabilities',
                    );
                    if (($row['family_id'] ?? null) !== ($parent['family_id'] ?? null)) {
                        $reasons[] = 'family_mismatch';
                    }
                    $rotation = ($row['rotated_from'] ?? null) === $parentId;
                    $expectedDepth = $this->integer($parent['delegation_depth'] ?? null) + ($rotation ? 0 : 1);
                    if ($this->integer($row['delegation_depth'] ?? null) !== $expectedDepth) {
                        $reasons[] = 'depth_mismatch';
                    }
                    if (array_diff($capabilities, $parentCapabilities) !== []) {
                        $reasons[] = 'capability_broadening';
                    } elseif ($rotation && array_diff($parentCapabilities, $capabilities) !== []) {
                        $reasons[] = 'rotation_capability_mismatch';
                    }
                    foreach (
                        [
                        'site_identifier',
                        'organization_identifier',
                        'workspace_identifier',
                        'membership_id',
                        ] as $scope
                    ) {
                        if (($row[$scope] ?? null) !== ($parent[$scope] ?? null)) {
                            $reasons[] = $scope . '_mismatch';
                        }
                    }
                    foreach (['membership_version', 'policy_generation'] as $generation) {
                        if (
                            $this->nullableInteger($row[$generation] ?? null)
                            !== $this->nullableInteger($parent[$generation] ?? null)
                        ) {
                            $reasons[] = $generation . '_mismatch';
                        }
                    }
                }
            }
            $row['delegation_valid'] = $reasons === [];
            $row['delegation_diagnostics'] = $reasons === [] ? ['bounded_to_issuer'] : $reasons;
            $row['scope_summary'] = array_values(array_filter([
                'site:' . $siteIdentifier,
                is_string($row['organization_identifier'] ?? null)
                    ? 'organization:' . $row['organization_identifier']
                    : null,
                is_string($row['workspace_identifier'] ?? null)
                    ? 'workspace:' . $row['workspace_identifier']
                    : null,
                is_string($row['membership_id'] ?? null) ? 'membership:' . $row['membership_id'] : null,
            ], static fn (?string $scope): bool => $scope !== null));
        }
        unset($row);

        return $rows;
    }

    /**
     * Decode published site definitions into structured field and action choices.
     *
     * @param   string  $siteIdentifier  Site that must own every returned definition.
     *
     * @return  list<array<string,mixed>>  Published definitions with structured fields and actions.
     *
     * @since   2.0.0
     */
    private function definitions(string $siteIdentifier): array
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT d.id, d.handle, d.published_version, v.canonical_payload FROM %s d '
            . 'INNER JOIN %s v ON v.definition_id = d.id AND v.version = d.published_version '
            . 'WHERE d.site_identifier = ? AND d.publication_state = ? AND d.owner_active = ? '
            . 'ORDER BY d.handle LIMIT 500',
            $this->tables->quoted('business_definitions'),
            $this->tables->quoted('business_definition_versions'),
        ), [$siteIdentifier, 'published', true], [Types::STRING, Types::STRING, Types::BOOLEAN]);
        $result = [];
        foreach ($rows as $row) {
            $document = $this->document($row['canonical_payload'] ?? null);
            $result[] = [
                'id' => $this->string($row, 'id'),
                'handle' => $this->string($row, 'handle'),
                'version' => $this->integer($row['published_version'] ?? null),
                'fields' => $this->definitionFields($document),
                'actions' => $this->definitionActionRows($document),
            ];
        }

        return $result;
    }

    /**
     * Load owner-aware live capability metadata for administrator diagnostics.
     *
     * @return  list<array<string,mixed>>  Capability lifecycle, scope, delegation, and impact metadata.
     *
     * @since   2.0.0
     */
    private function capabilities(): array
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT code, description, owner_kind, owner_identifier, allowed_scopes, delegable, '
            . 'high_impact, definition_version, definition_checksum, lifecycle_state '
            . 'FROM %s ORDER BY code LIMIT 1000',
            $this->tables->quoted('capabilities'),
        ));
        foreach ($rows as &$row) {
            $row['allowed_scopes'] = $this->stringList($row['allowed_scopes'] ?? null, 'capability scopes');
            $row['delegable'] = $this->boolean($row['delegable'] ?? null);
            $row['high_impact'] = $this->boolean($row['high_impact'] ?? null);
        }
        unset($row);

        return $rows;
    }

    /**
     * Load policy-compatible metadata from one published site definition.
     *
     * @param   string  $definitionId    Definition row to load.
     * @param   string  $siteIdentifier  Site that must own the definition.
     *
     * @return  ?array{field_types: array<string,string>, actions: list<string>}  Metadata or null when absent.
     *
     * @since   2.0.0
     */
    private function definition(string $definitionId, string $siteIdentifier): ?array
    {
        $value = $this->database->fetchOne(sprintf(
            'SELECT v.canonical_payload FROM %s d INNER JOIN %s v '
            . 'ON v.definition_id = d.id AND v.version = d.published_version '
            . 'WHERE d.id = ? AND d.site_identifier = ? AND d.publication_state = ? AND d.owner_active = ?',
            $this->tables->quoted('business_definitions'),
            $this->tables->quoted('business_definition_versions'),
        ), [$definitionId, $siteIdentifier, 'published', true], [
            Types::GUID,
            Types::STRING,
            Types::STRING,
            Types::BOOLEAN,
        ]);
        if ($value === false) {
            return null;
        }
        $document = $this->document($value);
        $fields = [];
        foreach ($this->definitionFields($document) as $field) {
            if (is_string($field['policy_type'] ?? null)) {
                $fields[$field['handle']] = $field['policy_type'];
            }
        }

        return [
            'field_types' => $fields,
            'actions' => array_column($this->definitionActionRows($document), 'handle'),
        ];
    }

    /**
     * Extract trusted field choices from a canonical business-definition document.
     *
     * @param   array<string, mixed>  $document  Decoded canonical definition payload.
     *
     * @return  list<array{handle: string, type: string, policy_type: ?string}>  Definition fields.
     *
     * @since   2.0.0
     */
    private function definitionFields(array $document): array
    {
        $fields = $document['fields'] ?? [];
        if (!is_array($fields) || !array_is_list($fields)) {
            throw new RuntimeException('Stored business-definition fields must be a list.');
        }
        $result = [];
        foreach ($fields as $field) {
            if (!is_array($field) || !is_string($field['handle'] ?? null) || !is_string($field['type'] ?? null)) {
                throw new RuntimeException('A stored business-definition field is invalid.');
            }
            $result[] = [
                'handle' => $field['handle'],
                'type' => $field['type'],
                'policy_type' => $this->policyType($field['type']),
            ];
        }

        return $result;
    }

    /**
     * Extract trusted action choices from a canonical business-definition document.
     *
     * @param   array<string, mixed>  $document  Decoded canonical definition payload.
     *
     * @return  list<array{handle: string, label: string}>  Action handles and display labels.
     *
     * @since   2.0.0
     */
    private function definitionActionRows(array $document): array
    {
        $actions = $document['actions'] ?? [];
        if (!is_array($actions) || !array_is_list($actions)) {
            throw new RuntimeException('Stored business-definition actions must be a list.');
        }
        $result = [];
        foreach ($actions as $action) {
            if (!is_array($action) || !is_string($action['handle'] ?? null)) {
                throw new RuntimeException('A stored business-definition action is invalid.');
            }
            $result[] = [
                'handle' => $action['handle'],
                'label' => is_string($action['label'] ?? null) ? $action['label'] : $action['handle'],
            ];
        }

        return $result;
    }

    /**
     * Map a business-definition field type to its comparable record-policy scalar type.
     *
     * @param   string  $type  Registered business field type code.
     *
     * @return  ?string  Comparable policy type, or null for unsupported compound values.
     *
     * @since   2.0.0
     */
    private function policyType(string $type): ?string
    {
        return match ($type) {
            'core.boolean' => 'boolean',
            'core.integer' => 'integer',
            'core.decimal' => 'decimal',
            'core.date', 'core.local_time', 'core.instant' => 'temporal',
            'core.string', 'core.text', 'core.rich_text', 'core.email', 'core.url', 'core.phone',
            'core.uuid', 'core.media_reference', 'core.reference_identity', 'core.enum',
            'core.entity_reference' => 'string',
            default => null,
        };
    }

    /**
     * Load an exact site membership and optionally lock it for mutation.
     *
     * @param   string  $id              Membership row to load.
     * @param   string  $siteIdentifier  Site that must own the membership.
     * @param   bool    $lock            Whether to append the platform lock clause.
     *
     * @return  array{id: string, user_id: string, organization_id: string}  Membership scope identifiers.
     *
     * @since   2.0.0
     */
    private function membership(string $id, string $siteIdentifier, bool $lock): array
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT m.id, m.user_id, m.organization_id FROM %s m INNER JOIN %s o ON o.id = m.organization_id '
            . 'WHERE m.id = ? AND o.site_identifier = ?%s',
            $this->tables->quoted('organization_memberships'),
            $this->tables->quoted('organizations'),
            $lock ? $this->lockClause() : '',
        ), [$id, $siteIdentifier]);
        if ($row === false) {
            throw new InvalidArgumentException('The organization membership is unavailable in this site.');
        }

        return [
            'id' => $this->string($row, 'id'),
            'user_id' => $this->string($row, 'user_id'),
            'organization_id' => $this->string($row, 'organization_id'),
        ];
    }

    /**
     * Load a resource policy's site-confined organization scope.
     *
     * @param   string  $id              Policy row to load.
     * @param   string  $siteIdentifier  Site that must own its organization or definition scope.
     * @param   bool    $lock            Whether to append the platform lock clause.
     *
     * @return  array{organization_id: ?string}  Optional organization restriction.
     *
     * @since   2.0.0
     */
    private function policyScope(string $id, string $siteIdentifier, bool $lock): array
    {
        $row = $this->resourcePolicyAuthority($id, $siteIdentifier, $lock)
            ?? throw new InvalidArgumentException('The resource policy is unavailable in this site.');

        return ['organization_id' => $row['organization_id']];
    }

    /**
     * Load a separation rule's site-confined organization scope.
     *
     * @param   string  $id              Separation rule to load.
     * @param   string  $siteIdentifier  Site that must own the rule.
     * @param   bool    $lock            Whether to append the platform lock clause.
     *
     * @return  array{organization_id: ?string}  Optional organization restriction.
     *
     * @since   2.0.0
     */
    private function ruleScope(string $id, string $siteIdentifier, bool $lock): array
    {
        return $this->separationRuleAuthority($id, $siteIdentifier, $lock)
            ?? throw new InvalidArgumentException('The separation rule is unavailable in this site.');
    }

    /**
     * Require one site-owned organization, optionally restricted to active state.
     *
     * @param   string  $id              Organization row to require.
     * @param   string  $siteIdentifier  Site that must own the row.
     * @param   bool    $active          Whether the organization must currently be active.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertOrganization(string $id, string $siteIdentifier, bool $active): void
    {
        $exists = $this->database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE id = ? AND site_identifier = ?%s',
            $this->tables->quoted('organizations'),
            $active ? ' AND status = ?' : '',
        ), $active ? [$id, $siteIdentifier, 'active'] : [$id, $siteIdentifier]);
        if ($exists === false) {
            throw new InvalidArgumentException('The organization is unavailable in this site.');
        }
    }

    /**
     * Require one existing user before creating a membership.
     *
     * @param   string  $id  User row that must exist.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertUser(string $id): void
    {
        if (
            $this->database->fetchOne(sprintf(
                'SELECT id FROM %s WHERE id = ?',
                $this->tables->quoted('users'),
            ), [$id]) === false
        ) {
            throw new InvalidArgumentException('The membership user is unavailable.');
        }
    }

    /**
     * Require one existing role before changing a membership assignment.
     *
     * @param   string  $id  Role row that must exist.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertRole(string $id): void
    {
        if (
            $this->database->fetchOne(sprintf(
                'SELECT id FROM %s WHERE id = ?',
                $this->tables->quoted('roles'),
            ), [$id]) === false
        ) {
            throw new InvalidArgumentException('The membership role is unavailable.');
        }
    }

    /**
     * Invalidate a membership, its principal tokens, and organization policy snapshots.
     *
     * @param   array{id: string, user_id: string, organization_id: string}  $membership  Locked membership scope.
     * @param   DateTimeImmutable                                            $at          Trusted invalidation instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function invalidateMembership(array $membership, DateTimeImmutable $at): void
    {
        $this->database->executeStatement(sprintf(
            'UPDATE %s SET version = version + 1, updated_at = ? WHERE id = ?',
            $this->tables->quoted('organization_memberships'),
        ), [$at, $membership['id']], [
            Types::DATETIME_IMMUTABLE,
            Types::GUID,
        ]);
        $this->bumpUserEpoch($membership['user_id']);
        $this->bumpPolicyGeneration($membership['organization_id'], $at);
    }

    /**
     * Bump the exact user's security epoch to invalidate existing credentials.
     *
     * @param   string  $userId  User whose credential snapshots become stale.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function bumpUserEpoch(string $userId): void
    {
        $this->database->executeStatement(sprintf(
            'UPDATE %s SET security_epoch = security_epoch + 1 WHERE id = ?',
            $this->tables->quoted('users'),
        ), [$userId]);
    }

    /**
     * Bump one organization's policy generation and optimistic version.
     *
     * @param   string             $organizationId  Organization whose policy snapshot becomes stale.
     * @param   DateTimeImmutable  $at              Trusted invalidation instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function bumpPolicyGeneration(string $organizationId, DateTimeImmutable $at): void
    {
        $this->database->executeStatement(sprintf(
            'UPDATE %s SET policy_generation = policy_generation + 1, version = version + 1, updated_at = ? '
            . 'WHERE id = ?',
            $this->tables->quoted('organizations'),
        ), [$at, $organizationId], [Types::DATETIME_IMMUTABLE, Types::GUID]);
    }

    /**
     * Bump affected organization generations for a site- or organization-wide policy.
     *
     * @param   string             $siteIdentifier  Site whose organizations may become stale.
     * @param   ?string            $organizationId  Exact organization, or null for every site organization.
     * @param   DateTimeImmutable  $at              Trusted invalidation instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function bumpPolicies(
        string $siteIdentifier,
        ?string $organizationId,
        DateTimeImmutable $at,
    ): void {
        $changed = $this->database->executeStatement(sprintf(
            'UPDATE %s SET policy_generation = policy_generation + 1 WHERE identifier = ?',
            $this->tables->quoted('sites'),
        ), [$siteIdentifier]);
        if ($changed !== 1) {
            throw new RuntimeException('The site policy generation could not be invalidated.');
        }
        if ($organizationId !== null) {
            $this->bumpPolicyGeneration($organizationId, $at);
            return;
        }
        $this->database->executeStatement(sprintf(
            'UPDATE %s SET policy_generation = policy_generation + 1, version = version + 1, updated_at = ? '
            . 'WHERE site_identifier = ?',
            $this->tables->quoted('organizations'),
        ), [$at, $siteIdentifier], [Types::DATETIME_IMMUTABLE, Types::STRING]);
    }

    /**
     * Record item ownership through the shared authorization ownership registry.
     *
     * @param   string  $type            Canonical authorization resource type.
     * @param   string  $id              Stable item identifier.
     * @param   string  $siteIdentifier  Site that owns the item.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function recordOwnership(string $type, string $id, string $siteIdentifier): void
    {
        $this->ownership->record(
            AuthorizationResource::item($type, $id),
            SiteContext::fromString($siteIdentifier),
        );
    }

    /**
     * Index structured rows by their required string identifier.
     *
     * @param   list<array<string, mixed>>  $rows  Rows available for nested projection lookup.
     *
     * @return  array<string,array<string,mixed>>  Rows keyed by identifier.
     *
     * @since   2.0.0
     */
    private function index(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if (is_string($id)) {
                $result[$id] = $row;
            }
        }

        return $result;
    }

    /**
     * Decode a stored JSON object without accepting a list or scalar value.
     *
     * @param   mixed  $value  Driver JSON value or an already-decoded object shape.
     *
     * @return  array<string,mixed>  Decoded object document.
     *
     * @since   2.0.0
     */
    private function document(mixed $value): array
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Stored Business Security JSON is invalid.', 0, $exception);
            }
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new RuntimeException('Stored Business Security JSON must be an object.');
        }
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                throw new RuntimeException('Stored Business Security JSON object keys must be strings.');
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Decode and validate one stored JSON string list.
     *
     * @param   mixed   $value  Driver JSON value or an already-decoded list.
     * @param   string  $name   Operator-facing noun used in corruption errors.
     *
     * @return  list<string>  Decoded string values in stored order.
     *
     * @since   2.0.0
     */
    private function stringList(mixed $value, string $name): array
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException(sprintf('Stored %s are invalid.', $name), 0, $exception);
            }
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException(sprintf('Stored %s must be a list.', $name));
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new RuntimeException(sprintf('Stored %s contain an invalid value.', $name));
            }
        }

        /** @var list<string> $value */
        return $value;
    }

    /**
     * Read a required non-empty string from a relational row.
     *
     * @param   array<string, mixed>  $row  Driver row containing the value.
     * @param   string                $key  Column key to require.
     *
     * @return  string  Valid non-empty column value.
     *
     * @since   2.0.0
     */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Stored Business Security %s is invalid.', $key));
        }

        return $value;
    }

    /**
     * Normalize a nullable driver string while rejecting empty corrupt values.
     *
     * @param   mixed  $value  Nullable driver column value.
     *
     * @return  ?string  Non-empty string or null.
     *
     * @since   2.0.0
     */
    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('A stored Business Security scope is invalid.');
        }

        return $value;
    }

    /**
     * Normalize a non-negative integer returned by a portable database driver.
     *
     * @param   mixed  $value  Integer or decimal digit string from the driver.
     *
     * @return  int  Non-negative normalized integer.
     *
     * @since   2.0.0
     */
    private function integer(mixed $value): int
    {
        if ((is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value >= 0) {
            return (int) $value;
        }

        throw new RuntimeException('A stored Business Security integer is invalid.');
    }

    /**
     * Normalize an optional non-negative integer returned by a portable database driver.
     *
     * @param   mixed  $value  Nullable integer or decimal digit string from the driver.
     *
     * @return  ?int  Null when absent, otherwise the normalized non-negative integer.
     *
     * @since   2.0.0
     */
    private function nullableInteger(mixed $value): ?int
    {
        return $value === null ? null : $this->integer($value);
    }

    /**
     * Normalize a strict Boolean returned by a supported database driver.
     *
     * @param   mixed  $value  Native Boolean or canonical zero-or-one driver value.
     *
     * @return  bool  Normalized Boolean.
     *
     * @since   2.0.0
     */
    private function boolean(mixed $value): bool
    {
        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }
        if ($value === false || $value === 0 || $value === '0') {
            return false;
        }

        throw new RuntimeException('A stored Business Security boolean is invalid.');
    }

    /**
     * Normalize a strict stored date-time from a supported database driver.
     *
     * @param   mixed  $value  Immutable date or non-empty driver date-time string.
     *
     * @return  DateTimeImmutable  Normalized immutable date-time.
     *
     * @since   2.0.0
     */
    private function date(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            return new DateTimeImmutable($value);
        }

        throw new RuntimeException('A stored Business Security date is invalid.');
    }

    /**
     * Build DBAL immutable date-time type hints keyed by column name.
     *
     * @param   string  $columns  Column names requiring immutable date-time conversion.
     *
     * @return  array<string,string>  DBAL type map keyed by column.
     *
     * @since   2.0.0
     */
    private function timeTypes(string ...$columns): array
    {
        return array_fill_keys($columns, Types::DATETIME_IMMUTABLE);
    }

    /**
     * Require exactly one changed row from an optimistic mutation.
     *
     * @param   int|string  $affected  Portable DBAL affected-row count.
     * @param   string      $message   Stable operator-facing conflict message.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertChanged(int|string $affected, string $message): void
    {
        if ((string) $affected !== '1') {
            throw new InvalidArgumentException($message);
        }
    }

    /**
     * Return the portable row-lock suffix supported by the active database platform.
     *
     * @return  string  Empty for SQLite and `FOR UPDATE` elsewhere.
     *
     * @since   2.0.0
     */
    private function lockClause(): string
    {
        return $this->database->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE';
    }
}
