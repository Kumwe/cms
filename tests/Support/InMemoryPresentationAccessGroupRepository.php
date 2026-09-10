<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use InvalidArgumentException;
use Kumwe\App\Application\Presentation\Preference\PresentationAccessGroup;
use Kumwe\App\Application\Presentation\Preference\PresentationAccessGroupCatalog;
use Kumwe\App\Application\Presentation\Preference\PresentationAccessGroupRepository;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Deterministic in-memory presentation access-group projection for application tests.
 *
 * Tests seed the same typed groups production projects from identity roles and identify each user's
 * assignments by stable group ID. Requested locks are recorded as semantic strings so a test can prove
 * an authorization path asked for a locked read without depending on Doctrine SQL.
 *
 * @since  2.0.0
 */
final class InMemoryPresentationAccessGroupRepository implements PresentationAccessGroupRepository
{
    /**
     * Groups keyed by stable presentation identity.
     *
     * @var    array<string, PresentationAccessGroup>
     * @since  2.0.0
     */
    private array $groups = [];

    /**
     * Stable group identities assigned directly to each user.
     *
     * @var    array<string, list<string>>
     * @since  2.0.0
     */
    private array $directAssignments;

    /**
     * Stable group identities assigned to each organization membership.
     *
     * @var    array<string, list<string>>
     * @since  2.0.0
     */
    private array $membershipAssignments;

    /**
     * Semantic descriptions of requested pessimistic reads, in call order.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $locks = [];

    /**
     * Bounded catalogue queries observed by the test store.
     *
     * @var    list<array{limit: int, offset: int, search: string}>
     * @since  2.0.0
     */
    private array $catalogQueries = [];

    /**
     * Number of bounded effective-context projections observed by the test store.
     *
     * @var    int
     * @since  2.0.0
     */
    private int $contextQueries = 0;

    /**
     * Seed typed access groups and direct user assignments.
     *
     * @param  list<PresentationAccessGroup>   $groups                 Available role projections.
     * @param  array<string, list<string>>      $directAssignments      User UUID to direct group identifiers.
     * @param  array<string, list<string>>      $membershipAssignments  Membership UUID to group identifiers.
     *
     * @throws  InvalidArgumentException  When a group is duplicated or an assignment names an absent group.
     *
     * @since  2.0.0
     */
    public function __construct(
        array $groups = [],
        array $directAssignments = [],
        array $membershipAssignments = [],
    ) {
        foreach ($groups as $group) {
            if (isset($this->groups[$group->id])) {
                throw new InvalidArgumentException('A presentation access group is duplicated in the test store.');
            }
            $this->groups[$group->id] = $group;
        }
        foreach ([...array_values($directAssignments), ...array_values($membershipAssignments)] as $identifiers) {
            foreach ($identifiers as $identifier) {
                if (!isset($this->groups[$identifier])) {
                    throw new InvalidArgumentException('A test presentation access-group assignment is stale.');
                }
            }
        }
        $this->directAssignments = $directAssignments;
        $this->membershipAssignments = $membershipAssignments;
    }

    /**
     * Return direct and exact-current-membership groups in the same order as the Doctrine adapter.
     *
     * @param   ExecutionContext  $context  Authenticated actor and optional current membership.
     * @param   int               $limit    Maximum effective groups returned, from one through 250.
     *
     * @return  PresentationAccessGroupCatalog  Bounded effective groups and overflow evidence.
     *
     * @throws  InvalidArgumentException  When a system context reaches this human projection.
     *
     * @since   2.0.0
     */
    public function listForContext(ExecutionContext $context, int $limit): PresentationAccessGroupCatalog
    {
        if ($limit < 1 || $limit > 250) {
            throw new InvalidArgumentException('A presentation access-group catalogue limit must be 1 to 250.');
        }
        $principal = $context->principal();
        if ($principal === null) {
            throw new InvalidArgumentException('Presentation access groups require an authenticated human actor.');
        }
        $this->contextQueries++;
        $identifiers = $this->directAssignments[$principal->subject()] ?? [];
        $membershipId = $context->membership()?->membershipId();
        if ($membershipId !== null) {
            $identifiers = [
                ...$identifiers,
                ...($this->membershipAssignments[$membershipId] ?? []),
            ];
        }
        $groups = [];
        foreach (array_values(array_unique($identifiers)) as $identifier) {
            if (isset($this->groups[$identifier])) {
                $groups[] = $this->groups[$identifier];
            }
        }

        $groups = self::ordered($groups);

        return new PresentationAccessGroupCatalog(
            array_slice($groups, 0, $limit),
            count($groups) > $limit ? $groups[$limit] : null,
        );
    }

    /**
     * Return a deterministic bounded page and explicit forward-page signal.
     *
     * @param   int     $limit   Maximum groups returned, from one through 250.
     * @param   int     $offset  Zero-based deterministic role offset.
     * @param   string  $search  Optional normalized literal code or name search.
     *
     * @return  PresentationAccessGroupCatalog  Bounded groups and completeness signal.
     *
     * @throws  InvalidArgumentException  When the requested bound is outside the contract.
     *
     * @since   2.0.0
     */
    public function catalog(int $limit, int $offset = 0, string $search = ''): PresentationAccessGroupCatalog
    {
        if ($limit < 1 || $limit > 250) {
            throw new InvalidArgumentException('A presentation access-group catalogue limit must be 1 to 250.');
        }
        if ($offset < 0 || $offset > 10_000) {
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
        $this->catalogQueries[] = ['limit' => $limit, 'offset' => $offset, 'search' => $search];
        $groups = self::ordered(array_values($this->groups));
        if ($search !== '') {
            $groups = array_values(array_filter(
                $groups,
                static fn (PresentationAccessGroup $group): bool => mb_stripos(
                    $group->code,
                    $search,
                    0,
                    'UTF-8',
                ) !== false || mb_stripos($group->name, $search, 0, 'UTF-8') !== false,
            ));
        }

        $page = array_slice($groups, $offset, $limit + 1);

        return new PresentationAccessGroupCatalog(
            array_slice($page, 0, $limit),
            count($page) > $limit ? $page[$limit] : null,
        );
    }

    /**
     * Check one stable identity against the seeded live groups.
     *
     * @param   string  $identifier  Candidate stable group identity.
     * @param   bool    $lock        Whether to record an identity-scoped pessimistic read.
     *
     * @return  bool  True when the group is present.
     *
     * @since   2.0.0
     */
    public function exists(string $identifier, bool $lock = false): bool
    {
        if (PresentationAccessGroup::roleIdFromIdentifier($identifier) === null) {
            return false;
        }
        if ($lock) {
            $this->locks[] = 'group:' . $identifier;
        }

        return isset($this->groups[$identifier]);
    }

    /**
     * Return the pessimistic reads requested since construction.
     *
     * @return  list<string>  Semantic lock observations in call order.
     *
     * @since   2.0.0
     */
    public function locks(): array
    {
        return $this->locks;
    }

    /**
     * Return bounded catalogue reads observed since construction.
     *
     * @return  list<array{limit: int, offset: int, search: string}>  Query observations in call order.
     *
     * @since  2.0.0
     */
    public function catalogQueries(): array
    {
        return $this->catalogQueries;
    }

    /**
     * Report the bounded effective-context projection count for query-budget assertions.
     *
     * @return  int  Logical effective-context reads.
     *
     * @since   2.0.0
     */
    public function contextQueryCount(): int
    {
        return $this->contextQueries;
    }

    /**
     * Sort projections exactly as the portable production role queries do.
     *
     * @param   list<PresentationAccessGroup>  $groups  Groups to put into display order.
     *
     * @return  list<PresentationAccessGroup>  Groups ordered by canonical code then role UUID.
     *
     * @since   2.0.0
     */
    private static function ordered(array $groups): array
    {
        usort(
            $groups,
            static fn (PresentationAccessGroup $left, PresentationAccessGroup $right): int => [
                $left->code,
                $left->roleId,
            ] <=> [
                $right->code,
                $right->roleId,
            ],
        );

        return $groups;
    }
}
