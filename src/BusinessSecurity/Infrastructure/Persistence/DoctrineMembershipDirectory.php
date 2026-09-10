<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSecurity\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use InvalidArgumentException;
use Kumwe\App\BusinessSecurity\Application\MembershipDirectory;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Context\Value\MembershipContext;
use Kumwe\Context\Value\OrganizationContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Context\Value\WorkspaceContext;

/**
 * Doctrine membership resolver that checks status, validity and policy generation in SQL.
 *
 * @since  2.0.0
 */
final readonly class DoctrineMembershipDirectory implements MembershipDirectory
{
    /**
     * Bind the resolver to the canonical identity and organization tables.
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
     * Resolve an exact live membership selection from canonical organization tables.
     *
     * @param   string       $subjectId               User expected to hold the membership.
     * @param   SiteContext  $site                    Exact authenticated site.
     * @param   string       $organizationIdentifier  Organization selected by a trusted credential.
     * @param   ?string      $workspaceIdentifier     Optional workspace selected by that credential.
     * @param   bool         $lock                    Whether to append a platform row lock for mutation.
     *
     * @return  ?MembershipContext  Live versioned membership, or null when any authority check fails.
     *
     * @since   2.0.0
     */
    public function resolve(
        string $subjectId,
        SiteContext $site,
        string $organizationIdentifier,
        ?string $workspaceIdentifier = null,
        bool $lock = false,
    ): ?MembershipContext {
        try {
            $organizationIdentifier = OrganizationContext::fromString($organizationIdentifier)->identifier();
            $workspaceIdentifier = $workspaceIdentifier === null
                ? null
                : WorkspaceContext::fromString($workspaceIdentifier)->identifier();
        } catch (InvalidArgumentException) {
            return null;
        }

        $workspaceJoin = $workspaceIdentifier === null
            ? ''
            : sprintf(
                ' INNER JOIN %s mw ON mw.membership_id = m.id INNER JOIN %s w ON w.id = mw.workspace_id '
                . "AND w.organization_id = o.id AND w.identifier = ? AND w.status = 'active'",
                $this->tables->quoted('membership_workspaces'),
                $this->tables->quoted('workspaces'),
            );
        $parameters = $workspaceIdentifier === null ? [] : [$workspaceIdentifier];
        array_push($parameters, $subjectId, $site->identifier(), $organizationIdentifier);
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT m.id, m.version AS membership_version, o.identifier AS organization_identifier, '
            . 'o.policy_generation%s FROM %s m INNER JOIN %s o ON o.id = m.organization_id%s '
            . "WHERE m.user_id = ? AND o.site_identifier = ? AND o.identifier = ? AND o.status = 'active' "
            . "AND m.status = 'active' AND m.valid_from <= CURRENT_TIMESTAMP "
            . 'AND (m.valid_until IS NULL OR m.valid_until > CURRENT_TIMESTAMP)%s',
            $workspaceIdentifier === null ? '' : ', w.identifier AS workspace_identifier',
            $this->tables->quoted('organization_memberships'),
            $this->tables->quoted('organizations'),
            $workspaceJoin,
            $this->lockClause($lock),
        ), $parameters);

        return $row === false ? null : $this->context($row);
    }

    /**
     * Compare a stored membership snapshot with the exact live relational state.
     *
     * @param   string             $subjectId   Actor expected to hold the membership.
     * @param   SiteContext        $site        Exact authenticated site.
     * @param   MembershipContext  $membership  Versioned snapshot to verify.
     * @param   bool               $lock        Whether to lock the membership for a following mutation.
     *
     * @return  bool  Whether status, validity, workspace assignment, and both generations still match.
     *
     * @since   2.0.0
     */
    public function current(
        string $subjectId,
        SiteContext $site,
        MembershipContext $membership,
        bool $lock = false,
    ): bool {
        $resolved = $this->resolve(
            $subjectId,
            $site,
            $membership->organization()->identifier(),
            $membership->workspace()?->identifier(),
            $lock,
        );

        return $resolved !== null
            && $resolved->membershipId() === $membership->membershipId()
            && $resolved->membershipVersion() === $membership->membershipVersion()
            && $resolved->policyGeneration() === $membership->policyGeneration();
    }

    /**
     * List active organization and workspace selections available to one site user.
     *
     * @param   string       $subjectId  User whose live selections are requested.
     * @param   SiteContext  $site       Exact authenticated site.
     *
     * @return  list<array{organization: string, workspace: ?string, membership_id: string,
     *          membership_version: int, policy_generation: int}>  Server-derived membership selections.
     *
     * @since   2.0.0
     */
    public function selections(string $subjectId, SiteContext $site): array
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT o.identifier AS organization_identifier, w.identifier AS workspace_identifier, '
            . 'm.id AS membership_id, m.version AS membership_version, o.policy_generation '
            . 'FROM %s m INNER JOIN %s o ON o.id = m.organization_id '
            . 'LEFT JOIN %s mw ON mw.membership_id = m.id '
            . "LEFT JOIN %s w ON w.id = mw.workspace_id AND w.status = 'active' "
            . "WHERE m.user_id = ? AND o.site_identifier = ? AND o.status = 'active' "
            . "AND m.status = 'active' AND m.valid_from <= CURRENT_TIMESTAMP "
            . 'AND (m.valid_until IS NULL OR m.valid_until > CURRENT_TIMESTAMP) '
            . 'ORDER BY o.identifier, w.identifier',
            $this->tables->quoted('organization_memberships'),
            $this->tables->quoted('organizations'),
            $this->tables->quoted('membership_workspaces'),
            $this->tables->quoted('workspaces'),
        ), [$subjectId, $site->identifier()]);

        $selections = [];
        foreach ($rows as $row) {
            try {
                $context = $this->context($row);
            } catch (InvalidArgumentException) {
                continue;
            }
            $selections[] = [
                'organization' => $context->organization()->identifier(),
                'workspace' => $context->workspace()?->identifier(),
                'membership_id' => $context->membershipId(),
                'membership_version' => $context->membershipVersion(),
                'policy_generation' => $context->policyGeneration(),
            ];
        }

        return $selections;
    }

    /**
     * Turn a validated database row into an immutable context.
     *
     * @param   array<string, mixed>  $row  Joined membership row.
     *
     * @return  MembershipContext  Validated membership snapshot.
     *
     * @throws  InvalidArgumentException  When stored state is malformed.
     *
     * @since   2.0.0
     */
    private function context(array $row): MembershipContext
    {
        $id = $row['id'] ?? $row['membership_id'] ?? null;
        $organization = $row['organization_identifier'] ?? null;
        $workspace = $row['workspace_identifier'] ?? null;
        if (!is_string($id) || !is_string($organization) || ($workspace !== null && !is_string($workspace))) {
            throw new InvalidArgumentException('Stored membership identity is invalid.');
        }

        return new MembershipContext(
            $id,
            OrganizationContext::fromString($organization),
            $workspace === null ? null : WorkspaceContext::fromString($workspace),
            $this->positiveInteger($row['membership_version'] ?? null),
            $this->positiveInteger($row['policy_generation'] ?? null),
        );
    }

    /**
     * Decode a portable positive integer returned by a database driver.
     *
     * @param   mixed  $value  Raw integer column value.
     *
     * @return  int  Positive integer.
     *
     * @throws  InvalidArgumentException  When the column is malformed or outside integer range.
     *
     * @since   2.0.0
     */
    private function positiveInteger(mixed $value): int
    {
        if (!is_int($value) && (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1)) {
            throw new InvalidArgumentException('Stored membership generation is invalid.');
        }
        $integer = (int) $value;
        if ($integer < 1) {
            throw new InvalidArgumentException('Stored membership generation is invalid.');
        }

        return $integer;
    }

    /**
     * Add a pessimistic lock where the active database supports it.
     *
     * @param   bool  $lock  Whether the caller is beginning a mutation.
     *
     * @return  string  SQL suffix.
     *
     * @since   2.0.0
     */
    private function lockClause(bool $lock): string
    {
        return $lock && !$this->database->getDatabasePlatform() instanceof SQLitePlatform ? ' FOR UPDATE' : '';
    }
}
