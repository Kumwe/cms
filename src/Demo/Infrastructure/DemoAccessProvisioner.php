<?php

declare(strict_types=1);

namespace Kumwe\App\Demo\Infrastructure;

use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\BusinessSecurity\Application\Administration\BusinessSecurityAdministrationRepository;
use Kumwe\App\Identity\Application\Administration\AccessControlRepository;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Provisions the demonstration cast an access manifest declares under a real administrator's authority.
 *
 * Roles, users, role assignments, and capability grants go through the canonical access-control service,
 * so the ordinary authorization, delegation-ceiling, audit, and ownership rules apply — which is why the
 * caller must supply an authenticated administrator context rather than a system identity. Organization,
 * workspace, and membership rows are written through the Business Security administration repository the
 * same way the trusted browser fixtures provision them: the public service correctly demands a human
 * step-up proof per mutation, so this host-boundary path writes the rows directly inside one transaction
 * and records an audit event for each provisioned resource. Every password is generated here at
 * provisioning time and returned once to the caller; nothing secret is read from or written to any
 * manifest, and existing accounts are never modified — an already-provisioned identity is reported
 * without a password instead of being overwritten. Roles, by contrast, are reconciled additively on
 * every run: a capability a later manifest version adds to a declared role is granted to the existing
 * role, while grants an operator added by hand are left untouched, which is how re-running the command
 * after a manifest version bump converges a live demonstration on the new cast.
 *
 * @since  2.0.0
 */
final readonly class DemoAccessProvisioner
{
    /**
     * Bind the canonical identity services and the narrow membership-bootstrap dependencies.
     *
     * @param  AccessControlService                      $access        Canonical user, role, and grant service.
     * @param  AccessControlRepository                   $identities    Read access for idempotent lookups.
     * @param  BusinessSecurityAdministrationRepository  $security      Organization and membership writer.
     * @param  \Doctrine\DBAL\Connection                 $database      Idempotency lookup connection.
     * @param  TableNames                                $tables        Validated physical table names.
     * @param  TransactionManager                        $transactions  Membership transaction boundary.
     * @param  AuditRecorder                             $audit         Durable provisioning audit sink.
     * @param  ClockInterface                            $clock         Trusted timestamp source.
     *
     * @since  2.0.0
     */
    public function __construct(
        private AccessControlService $access,
        private AccessControlRepository $identities,
        private BusinessSecurityAdministrationRepository $security,
        private \Doctrine\DBAL\Connection $database,
        private TableNames $tables,
        private TransactionManager $transactions,
        private AuditRecorder $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Provision every role, identity, organization, and membership the validated manifest declares.
     *
     * @param   ExecutionContext      $context   Authenticated administrator context the work runs under.
     * @param   array<string, mixed>  $manifest  Access manifest already validated by the demo catalog.
     *
     * @return  array{
     *              roles: list<array{handle: string, label: string, created: bool}>,
     *              identities: list<array{
     *                  email: string,
     *                  display_name: string,
     *                  role: string,
     *                  area: string,
     *                  organization: ?string,
     *                  created: bool,
     *                  password: ?string
     *              }>,
     *              organizations: list<array{identifier: string, label: string, created: bool, members: int}>
     *          }  Everything provisioned or confirmed, with generated passwords for new accounts only.
     *
     * @throws  RuntimeException  When a declared resource cannot be provisioned or resolved.
     *
     * @since   2.0.0
     */
    public function provision(ExecutionContext $context, array $manifest): array
    {
        $roleReports = [];
        $identityReports = [];
        $organizationReports = [];
        $roleIds = $this->existingRolesByCode();
        $roleAreas = [];

        foreach ($this->entries($manifest, 'roles') as $role) {
            $handle = $this->text($role, 'handle');
            $roleAreas[$handle] = $this->text($role, 'area');
            $created = !isset($roleIds[$handle]);
            if ($created) {
                $roleIds[$handle] = $this->access->createRole($context, $handle, $this->text($role, 'label'));
            }
            $held = $created ? [] : $this->grantedCapabilities($roleIds[$handle]);
            foreach ($this->entries($role, 'capabilities') as $capability) {
                if (!is_string($capability)) {
                    throw new RuntimeException('A demo role capability is invalid.');
                }
                if (!isset($held[$capability])) {
                    $this->access->grant($context, $roleIds[$handle], $capability);
                }
            }
            $roleReports[] = ['handle' => $handle, 'label' => $this->text($role, 'label'), 'created' => $created];
        }

        foreach ($this->entries($manifest, 'staff') as $person) {
            $identityReports[] = $this->provisionIdentity($context, $person, $roleIds, $roleAreas, null);
        }

        foreach ($this->entries($manifest, 'organizations') as $organization) {
            $identifier = $this->text($organization, 'identifier');
            $label = $this->text($organization, 'label');
            $members = $this->entries($organization, 'members');
            [$organizationId, $workspaceId, $created] = $this->ensureOrganization(
                $context,
                $identifier,
                $label,
                $this->text($organization, 'workspace'),
            );
            foreach ($members as $member) {
                $report = $this->provisionIdentity($context, $member, $roleIds, $roleAreas, $identifier);
                $identityReports[] = $report;
                $this->ensureMembership(
                    $context,
                    $organizationId,
                    $workspaceId,
                    $this->userIdFor($report['email']),
                    $roleIds[$report['role']],
                    $identifier,
                );
            }
            $organizationReports[] = [
                'identifier' => $identifier,
                'label' => $label,
                'created' => $created,
                'members' => count($members),
            ];
        }

        return [
            'roles' => $roleReports,
            'identities' => $identityReports,
            'organizations' => $organizationReports,
        ];
    }

    /**
     * Provision or confirm one declared identity and assign its role to new accounts.
     *
     * @param   ExecutionContext       $context       Authenticated administrator context.
     * @param   mixed                  $person        Declared identity entry from the manifest.
     * @param   array<string, string>  $roleIds       Role identifiers keyed by handle.
     * @param   array<string, string>  $roleAreas     Declared area keyed by role handle.
     * @param   ?string                $organization  Owning organization identifier for portal members.
     *
     * @return  array{
     *              email: string,
     *              display_name: string,
     *              role: string,
     *              area: string,
     *              organization: ?string,
     *              created: bool,
     *              password: ?string
     *          }  Provisioning outcome, carrying a password only for a newly created account.
     *
     * @throws  RuntimeException  When the entry is malformed or its role is undeclared.
     *
     * @since   2.0.0
     */
    private function provisionIdentity(
        ExecutionContext $context,
        mixed $person,
        array $roleIds,
        array $roleAreas,
        ?string $organization,
    ): array {
        if (!is_array($person)) {
            throw new RuntimeException('A demo identity entry is invalid.');
        }
        $email = strtolower($this->text($person, 'email'));
        $name = $this->text($person, 'display_name');
        $role = $this->text($person, 'role');
        $roleId = $roleIds[$role] ?? null;
        $area = $roleAreas[$role] ?? null;
        if ($roleId === null || $area === null) {
            throw new RuntimeException(sprintf('Demo identity %s references an undeclared role.', $email));
        }
        $existing = $this->identities->userIdByEmail($email);
        $password = null;
        if ($existing === null) {
            $password = $this->generatePassword();
            $userId = $this->access->createUser($context, $email, $name, $password);
            $this->access->assignRole($context, $userId, $roleId);
        }

        return [
            'email' => $email,
            'display_name' => $name,
            'role' => $role,
            'area' => $area,
            'organization' => $organization,
            'created' => $existing === null,
            'password' => $password,
        ];
    }

    /**
     * Create one active organization and its workspace unless both already exist.
     *
     * @param   ExecutionContext  $context     Authenticated administrator context.
     * @param   string            $identifier  Stable organization identifier.
     * @param   string            $label       Human-readable organization name.
     * @param   string            $workspace   Stable workspace identifier inside the organization.
     *
     * @return  array{string, string, bool}  Organization id, workspace id, and whether either was created.
     *
     * @since   2.0.0
     */
    private function ensureOrganization(
        ExecutionContext $context,
        string $identifier,
        string $label,
        string $workspace,
    ): array {
        $site = $context->site()->identifier();
        $organizationId = $this->database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE site_identifier = ? AND identifier = ?',
            $this->tables->quoted('organizations'),
        ), [$site, $identifier]);
        $created = false;
        $at = $this->clock->now();
        if (!is_string($organizationId)) {
            $organizationId = Uuid::uuid7()->toString();
            $created = true;
            $this->transactions->transactional(function () use (
                $context,
                $organizationId,
                $site,
                $identifier,
                $label,
                $at,
            ): void {
                $this->security->insertOrganization($organizationId, $site, $identifier, $label, $at);
                $this->recordProvisioning($context, 'organization', $organizationId, $identifier);
            });
        }
        $workspaceId = $this->database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE organization_id = ? AND identifier = ?',
            $this->tables->quoted('workspaces'),
        ), [$organizationId, $workspace]);
        if (!is_string($workspaceId)) {
            $workspaceId = Uuid::uuid7()->toString();
            $created = true;
            $newWorkspaceId = $workspaceId;
            $this->transactions->transactional(function () use (
                $context,
                $newWorkspaceId,
                $organizationId,
                $site,
                $workspace,
                $at,
            ): void {
                $this->security->insertWorkspace(
                    $newWorkspaceId,
                    $organizationId,
                    $site,
                    $workspace,
                    ucfirst($workspace) . ' workspace',
                    $at,
                );
                $this->recordProvisioning($context, 'workspace', $newWorkspaceId, $workspace);
            });
        }

        return [$organizationId, $workspaceId, $created];
    }

    /**
     * Create one live membership binding a user, workspace, and portal role unless it already exists.
     *
     * @param   ExecutionContext  $context         Authenticated administrator context.
     * @param   string            $organizationId  Organization receiving the member.
     * @param   string            $workspaceId     Workspace assigned to the membership.
     * @param   string            $userId          Existing site user owning the membership.
     * @param   string            $roleId          Portal role assigned through the membership.
     * @param   string            $identifier      Organization identifier for the audit trail.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function ensureMembership(
        ExecutionContext $context,
        string $organizationId,
        string $workspaceId,
        string $userId,
        string $roleId,
        string $identifier,
    ): void {
        $existing = $this->database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE organization_id = ? AND user_id = ?',
            $this->tables->quoted('organization_memberships'),
        ), [$organizationId, $userId]);
        if (is_string($existing)) {
            return;
        }
        $membershipId = Uuid::uuid7()->toString();
        $site = $context->site()->identifier();
        $at = $this->clock->now();
        $this->transactions->transactional(function () use (
            $context,
            $membershipId,
            $organizationId,
            $workspaceId,
            $userId,
            $roleId,
            $site,
            $identifier,
            $at,
        ): void {
            $this->security->insertMembership(
                $membershipId,
                $organizationId,
                $site,
                $userId,
                $at->modify('-1 minute'),
                null,
                $context->actorId(),
                $at,
            );
            $this->security->assignMembershipWorkspace($membershipId, $workspaceId, $site, $context->actorId(), $at);
            $this->security->assignMembershipRole($membershipId, $roleId, $site, $context->actorId(), $at);
            $this->recordProvisioning($context, 'organization_membership', $membershipId, $identifier);
        });
    }

    /**
     * Resolve the user identifier a just-provisioned or pre-existing address belongs to.
     *
     * @param   string  $email  Normalized address of the identity.
     *
     * @return  string  Existing user identifier.
     *
     * @throws  RuntimeException  When the address unexpectedly resolves to no account.
     *
     * @since   2.0.0
     */
    private function userIdFor(string $email): string
    {
        $userId = $this->identities->userIdByEmail($email);
        if ($userId === null) {
            throw new RuntimeException(sprintf('Demo identity %s could not be resolved after provisioning.', $email));
        }

        return $userId;
    }

    /**
     * Record one durable audit event for a provisioned demonstration resource.
     *
     * @param   ExecutionContext  $context       Authenticated administrator context.
     * @param   string            $resourceType  Audited resource type.
     * @param   string            $resourceId    Provisioned resource identifier.
     * @param   string            $identifier    Operator-facing identifier for the audit payload.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function recordProvisioning(
        ExecutionContext $context,
        string $resourceType,
        string $resourceId,
        string $identifier,
    ): void {
        $this->audit->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $this->clock->now(),
            $context->actorId(),
            'demo.access.provision',
            $resourceType,
            $resourceId,
            'success',
            [
                'site' => $context->site()->identifier(),
                'identifier' => $identifier,
            ],
        ));
    }

    /**
     * Read the existing role identifiers keyed by their stable role codes.
     *
     * @return  array<string, string>  Role ids keyed by role code.
     *
     * @since   2.0.0
     */
    private function existingRolesByCode(): array
    {
        $roles = [];
        $offset = 0;
        do {
            $page = $this->identities->roles(100, $offset);
            foreach ($page as $role) {
                $code = $role['code'] ?? null;
                $id = $role['id'] ?? null;
                if (is_string($code) && is_string($id)) {
                    $roles[$code] = $id;
                }
            }
            $offset += 100;
        } while (count($page) === 100);

        return $roles;
    }

    /**
     * Read the global capabilities one existing role already confers, as a lookup for reconciliation.
     *
     * Only global grants count as held: the manifest declares its role capabilities globally, so a
     * scoped grant an operator added by hand neither satisfies a declaration nor blocks the global
     * grant the reconciliation writes.
     *
     * @param   string  $roleId  UUID of the declared role being reconciled.
     *
     * @return  array<string, true>  Globally granted capability codes keyed to `true`.
     *
     * @since   2.0.0
     */
    private function grantedCapabilities(string $roleId): array
    {
        $held = [];
        foreach ($this->identities->roleGrants($roleId) as $grant) {
            if ($grant['scope_type'] === 'global') {
                $held[$grant['capability']] = true;
            }
        }

        return $held;
    }

    /**
     * Generate one strong random password for a newly provisioned demonstration account.
     *
     * @return  string  Twenty-four URL-safe characters from cryptographic randomness.
     *
     * @since   2.0.0
     */
    private function generatePassword(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }

    /**
     * Read one required list from a validated manifest object.
     *
     * @param   array<string, mixed>|mixed  $document  Manifest object carrying the list.
     * @param   string                      $key       Required member name.
     *
     * @return  list<mixed>  Declared list entries.
     *
     * @throws  RuntimeException  When the member is absent or not a list.
     *
     * @since   2.0.0
     */
    private function entries(mixed $document, string $key): array
    {
        $value = is_array($document) ? ($document[$key] ?? null) : null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException(sprintf('The demo access manifest member %s is invalid.', $key));
        }

        return $value;
    }

    /**
     * Read one required non-empty string from a manifest entry.
     *
     * @param   array<string, mixed>|mixed  $entry  Manifest entry carrying the value.
     * @param   string                      $key    Required member name.
     *
     * @return  string  Trimmed non-empty value.
     *
     * @throws  RuntimeException  When the member is absent, not a string, or empty.
     *
     * @since   2.0.0
     */
    private function text(mixed $entry, string $key): string
    {
        $value = is_array($entry) ? ($entry[$key] ?? null) : null;
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException(sprintf('The demo access manifest member %s is invalid.', $key));
        }

        return trim($value);
    }
}
