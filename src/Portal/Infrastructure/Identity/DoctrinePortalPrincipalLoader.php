<?php

declare(strict_types=1);

namespace Kumwe\App\Portal\Infrastructure\Identity;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Portal\Application\PortalPasswordIdentity;
use Kumwe\App\Portal\Application\PortalPrincipalLoader;
use Kumwe\Context\Value\MembershipContext;

/**
 * DBAL loader that rebuilds portal principals from current active users, roles, grants, and epochs.
 *
 * @since  2.0.0
 */
final readonly class DoctrinePortalPrincipalLoader implements PortalPrincipalLoader
{
    /**
     * Bind live identity reads to the database and the kernel provenance authority.
     *
     * @param  Connection  $database    Shared DBAL connection.
     * @param  TableNames  $tables      Installation table-prefix mapper.
     * @param  object      $provenance  Exact authority trusted by the authorization gateway.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private object $provenance,
    ) {
    }

    /**
     * Rebuild one active user's whole scoped grant set and current security epoch.
     *
     * @param   string              $subjectId     User UUID.
     * @param   string              $credentialId  Bounded portal credential identity.
     * @param   ?MembershipContext  $membership    Exact live membership whose roles may add grants.
     *
     * @return  ?PortalPasswordIdentity  Live principal and epoch or null for an inactive or unknown user.
     *
     * @throws  InvalidArgumentException  When stored identity state is corrupt.
     *
     * @since   2.0.0
     */
    public function load(
        string $subjectId,
        string $credentialId,
        ?MembershipContext $membership = null,
    ): ?PortalPasswordIdentity {
        $epoch = $this->database->fetchOne(sprintf(
            "SELECT security_epoch FROM %s WHERE id = ? AND status = 'active'",
            $this->tables->quoted('users'),
        ), [$subjectId]);
        if ($epoch === false) {
            return null;
        }
        $securityEpoch = $this->positiveInteger($epoch);
        $grants = $membership === null
            ? $this->globalGrants($subjectId)
            : $this->membershipGrants($subjectId, $securityEpoch, $membership);
        if ($grants === null) {
            return null;
        }

        return new PortalPasswordIdentity(
            AuthenticatedPrincipal::issueFromGrantRows(
                $this->provenance,
                $subjectId,
                $grants,
                $credentialId,
                $securityEpoch,
            ),
            $securityEpoch,
        );
    }

    /**
     * Load only installation-wide roles while password login is identifying a portal subject.
     *
     * @param   string  $subjectId  Active user UUID.
     *
     * @return  list<array{capability: string, scope_type: string, scope_identifier: ?string}>
     *          Deduplicated global-role grants.
     *
     * @since   2.0.0
     */
    private function globalGrants(string $subjectId): array
    {
        /** @var list<array{capability: string, scope_type: string, scope_identifier: ?string}> $grants */
        $grants = $this->database->fetchAllAssociative(sprintf(
            'SELECT DISTINCT g.capability_code AS capability, g.scope_type, g.scope_identifier '
            . 'FROM %s ur INNER JOIN %s g ON g.role_id = ur.role_id WHERE ur.user_id = ? '
            . 'ORDER BY g.capability_code, g.scope_type, g.scope_identifier',
            $this->tables->quoted('user_roles'),
            $this->tables->quoted('role_capability_grants'),
        ), [$subjectId]);

        return $grants;
    }

    /**
     * Union global roles with only one exact, still-current membership's role grants.
     *
     * The membership row anchors the statement, so an inactive, expired, version-stale, foreign-user,
     * policy-stale, or inactive-workspace selection returns no sentinel row and fails closed. The left
     * join deliberately retains one sentinel when the valid actor has no grants.
     *
     * @param   string             $subjectId      Active user UUID.
     * @param   int                $securityEpoch  User epoch observed immediately before this statement.
     * @param   MembershipContext  $membership     Server-resolved membership snapshot to revalidate.
     *
     * @return  ?list<array{capability: string, scope_type: string, scope_identifier: ?string}>
     *          Deduplicated global and selected-membership grants, or null when the selection is stale.
     *
     * @throws  InvalidArgumentException  When a returned grant or membership sentinel is malformed.
     *
     * @since   2.0.0
     */
    private function membershipGrants(
        string $subjectId,
        int $securityEpoch,
        MembershipContext $membership,
    ): ?array {
        $workspace = $membership->workspace()?->identifier();
        $workspaceGuard = '';
        $parameters = [
            $membership->membershipId(),
            $subjectId,
            $securityEpoch,
            $membership->membershipVersion(),
            $membership->organization()->identifier(),
            $membership->policyGeneration(),
        ];
        if ($workspace !== null) {
            $workspaceGuard = sprintf(
                ' AND EXISTS (SELECT 1 FROM %s mw INNER JOIN %s w ON w.id = mw.workspace_id '
                . 'WHERE mw.membership_id = m.id AND w.organization_id = o.id '
                . "AND w.identifier = ? AND w.status = 'active')",
                $this->tables->quoted('membership_workspaces'),
                $this->tables->quoted('workspaces'),
            );
            $parameters[] = $workspace;
        }
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT DISTINCT g.capability_code AS capability, g.scope_type, g.scope_identifier, '
            . 'm.id AS validated_membership_id FROM %s m INNER JOIN %s o ON o.id = m.organization_id '
            . "INNER JOIN %s u ON u.id = m.user_id AND u.status = 'active' "
            . 'LEFT JOIN %s g ON g.role_id IN ('
            . 'SELECT ur.role_id FROM %s ur WHERE ur.user_id = m.user_id UNION '
            . 'SELECT mr.role_id FROM %s mr WHERE mr.membership_id = m.id) '
            . 'WHERE m.id = ? AND m.user_id = ? AND u.security_epoch = ? AND m.version = ? '
            . "AND m.status = 'active' AND m.valid_from <= CURRENT_TIMESTAMP "
            . 'AND (m.valid_until IS NULL OR m.valid_until > CURRENT_TIMESTAMP) '
            . "AND o.identifier = ? AND o.policy_generation = ? AND o.status = 'active'%s "
            . 'ORDER BY g.capability_code, g.scope_type, g.scope_identifier',
            $this->tables->quoted('organization_memberships'),
            $this->tables->quoted('organizations'),
            $this->tables->quoted('users'),
            $this->tables->quoted('role_capability_grants'),
            $this->tables->quoted('user_roles'),
            $this->tables->quoted('membership_roles'),
            $workspaceGuard,
        ), $parameters);
        if ($rows === []) {
            return null;
        }

        $grants = [];
        foreach ($rows as $row) {
            if (($row['validated_membership_id'] ?? null) !== $membership->membershipId()) {
                throw new InvalidArgumentException('A stored portal membership identity is invalid.');
            }
            $capability = $row['capability'] ?? null;
            $scopeType = $row['scope_type'] ?? null;
            $scopeIdentifier = $row['scope_identifier'] ?? null;
            if ($capability === null && $scopeType === null && $scopeIdentifier === null) {
                continue;
            }
            if (
                !is_string($capability)
                || !is_string($scopeType)
                || ($scopeIdentifier !== null && !is_string($scopeIdentifier))
            ) {
                throw new InvalidArgumentException('A stored portal role grant is invalid.');
            }
            $grants[] = [
                'capability' => $capability,
                'scope_type' => $scopeType,
                'scope_identifier' => $scopeIdentifier,
            ];
        }

        return $grants;
    }

    /**
     * Normalize a positive integer returned by any supported DBAL driver.
     *
     * @param   mixed  $value  Driver-returned security epoch.
     *
     * @return  int  Positive epoch.
     *
     * @throws  InvalidArgumentException  When malformed.
     *
     * @since   2.0.0
     */
    private function positiveInteger(mixed $value): int
    {
        if (!is_int($value) && (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1)) {
            throw new InvalidArgumentException('A stored portal security epoch is invalid.');
        }
        $integer = (int) $value;
        if ($integer < 1) {
            throw new InvalidArgumentException('A stored portal security epoch is invalid.');
        }

        return $integer;
    }
}
