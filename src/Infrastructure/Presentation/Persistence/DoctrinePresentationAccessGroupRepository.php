<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Presentation\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\App\Application\Presentation\Preference\PresentationAccessGroup;
use Kumwe\App\Application\Presentation\Preference\PresentationAccessGroupCatalog;
use Kumwe\App\Application\Presentation\Preference\PresentationAccessGroupRepository;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Context\Value\ExecutionContext;
use RuntimeException;

/**
 * DBAL projection of canonical direct-user and current-membership roles into presentation access groups.
 *
 * No presentation-owned membership rows are introduced: group existence comes from `roles`, while effective
 * assignment comes from `user_roles` plus only the execution context's exact `membership_roles` row set.
 * Exact existence locks append `FOR UPDATE` where supported and never open or commit a transaction.
 *
 * @since  2.0.0
 */
final readonly class DoctrinePresentationAccessGroupRepository implements PresentationAccessGroupRepository
{
    /**
     * Largest portable deterministic offset accepted from bounded application paging.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAXIMUM_CATALOG_OFFSET = 10_000;

    /**
     * Bind the projection to the canonical application connection and prefixed table resolver.
     *
     * @param  Connection  $database  DBAL connection carrying caller-owned transactions.
     * @param  TableNames  $tables    Resolver for the installation's physical table prefix.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * Project direct roles and roles from only the actor's current server-resolved membership.
     *
     * @param   ExecutionContext  $context  Authenticated actor and optional exact membership selection.
     * @param   int               $limit    Maximum effective groups returned, from one through 250.
     *
     * @return  PresentationAccessGroupCatalog  Bounded effective groups and explicit overflow evidence.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the read.
     * @throws  InvalidArgumentException  When a system context reaches the human dashboard projection.
     * @throws  RuntimeException  When a stored role row does not contain strings.
     * @throws  \InvalidArgumentException  When stored role fields violate their canonical schema.
     *
     * @since   2.0.0
     */
    public function listForContext(ExecutionContext $context, int $limit): PresentationAccessGroupCatalog
    {
        self::assertLimit($limit);
        $principal = $context->principal();
        if ($principal === null) {
            throw new InvalidArgumentException('Presentation access groups require an authenticated human actor.');
        }
        $membershipId = $context->membership()?->membershipId();
        $membershipPredicate = '';
        $parameters = [$principal->subject()];
        $types = [Types::GUID];
        if ($membershipId !== null) {
            $membershipPredicate = sprintf(
                ' OR EXISTS (SELECT 1 FROM %s mr WHERE mr.role_id = r.id AND mr.membership_id = ?)',
                $this->tables->quoted('membership_roles'),
            );
            $parameters[] = $membershipId;
            $types[] = Types::GUID;
        }
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT r.id, r.code, r.name FROM %s r WHERE '
            . 'EXISTS (SELECT 1 FROM %s ur WHERE ur.role_id = r.id AND ur.user_id = ?)%s '
            . 'ORDER BY r.code, r.id LIMIT %d',
            $this->tables->quoted('roles'),
            $this->tables->quoted('user_roles'),
            $membershipPredicate,
            $limit + 1,
        ), $parameters, $types);
        $groups = $this->groups($rows);

        return new PresentationAccessGroupCatalog(
            array_slice($groups, 0, $limit),
            count($groups) > $limit ? $groups[$limit] : null,
        );
    }

    /**
     * Project a bounded page of canonical roles and inspect one extra row for forward navigation.
     *
     * @param   int     $limit   Maximum returned groups, from one through 250.
     * @param   int     $offset  Zero-based deterministic role offset.
     * @param   string  $search  Optional normalized literal search across role code and name.
     *
     * @return  PresentationAccessGroupCatalog  Canonically ordered page and whether another role exists.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the read.
     * @throws  InvalidArgumentException  When the requested bound is outside the contract.
     * @throws  RuntimeException  When a stored role row does not contain strings.
     * @throws  \InvalidArgumentException  When stored role fields violate their canonical schema.
     *
     * @since   2.0.0
     */
    public function catalog(int $limit, int $offset = 0, string $search = ''): PresentationAccessGroupCatalog
    {
        self::assertLimit($limit);
        if ($offset < 0 || $offset > self::MAXIMUM_CATALOG_OFFSET) {
            throw new InvalidArgumentException('A presentation access-group catalogue offset is out of range.');
        }
        if (
            !mb_check_encoding($search, 'UTF-8')
            || mb_strlen($search, 'UTF-8') > 64
            || trim($search) !== $search
            || preg_match('/[\x00-\x1f\x7f]/u', $search) === 1
            || preg_match('/\s{2,}/u', $search) === 1
        ) {
            throw new InvalidArgumentException('A presentation access-group catalogue search must be normalized.');
        }
        $where = '';
        $parameters = [];
        $types = [];
        if ($search !== '') {
            $where = " WHERE (LOWER(code) LIKE ? ESCAPE '!' OR LOWER(name) LIKE ? ESCAPE '!')";
            $literal = '%' . str_replace(
                ['!', '%', '_'],
                ['!!', '!%', '!_'],
                mb_strtolower($search, 'UTF-8'),
            ) . '%';
            $parameters = [$literal, $literal];
            $types = [Types::STRING, Types::STRING];
        }
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT id, code, name FROM %s%s ORDER BY code, id LIMIT %d OFFSET %d',
            $this->tables->quoted('roles'),
            $where,
            $limit + 1,
            $offset,
        ), $parameters, $types);
        $groups = $this->groups($rows);
        $lookahead = count($groups) > $limit ? $groups[$limit] : null;

        return new PresentationAccessGroupCatalog(array_slice($groups, 0, $limit), $lookahead);
    }

    /**
     * Check whether one stable prefixed identity maps to a current role row.
     *
     * @param   string  $identifier  Candidate `role:<uuid>` presentation identity.
     * @param   bool    $lock        Whether supported databases should hold the matching role row.
     *
     * @return  bool  True only when the identifier is canonical and its role exists.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the read.
     *
     * @since   2.0.0
     */
    public function exists(string $identifier, bool $lock = false): bool
    {
        $roleId = PresentationAccessGroup::roleIdFromIdentifier($identifier);
        if ($roleId === null) {
            return false;
        }
        $stored = $this->database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE id = ?%s',
            $this->tables->quoted('roles'),
            $this->lockClause($lock),
        ), [$roleId], [Types::GUID]);

        return is_string($stored) && $stored === $roleId;
    }

    /**
     * Convert driver rows into validated application projections.
     *
     * @param   list<array<string, mixed>>  $rows  Raw role rows returned by DBAL.
     *
     * @return  list<PresentationAccessGroup>  Validated groups preserving query order.
     *
     * @throws  RuntimeException  When a required stored value is not a string.
     * @throws  \InvalidArgumentException  When stored role fields violate their canonical schema.
     *
     * @since   2.0.0
     */
    private function groups(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $code = $row['code'] ?? null;
            $name = $row['name'] ?? null;
            if (!is_string($id) || !is_string($code) || !is_string($name)) {
                throw new RuntimeException('A stored presentation access-group role is invalid.');
            }
            $groups[] = PresentationAccessGroup::fromRole($id, $code, $name);
        }

        return $groups;
    }

    /**
     * Require one portable access-group catalogue bound.
     *
     * The 250-row ceiling leaves room for site, administrator, current-workspace, and user keys in the
     * preference repository's 256-key batch contract.
     *
     * @param   int  $limit  Candidate row bound.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the bound is outside one through 250.
     *
     * @since   2.0.0
     */
    private static function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 250) {
            throw new InvalidArgumentException('A presentation access-group catalogue limit must be 1 to 250.');
        }
    }

    /**
     * Return the portable optional pessimistic-lock suffix.
     *
     * @param   bool  $lock  Whether the caller requested a pessimistic read.
     *
     * @return  string  Empty for unlocked reads and SQLite, otherwise `FOR UPDATE` with a leading space.
     *
     * @since   2.0.0
     */
    private function lockClause(bool $lock): string
    {
        return $lock && !($this->database->getDatabasePlatform() instanceof SQLitePlatform)
            ? ' FOR UPDATE'
            : '';
    }
}
