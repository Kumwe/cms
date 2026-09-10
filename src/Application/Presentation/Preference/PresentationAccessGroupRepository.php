<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Presentation\Preference;

use Kumwe\Context\Value\ExecutionContext;

/**
 * Read-only boundary projecting access-control roles as KIS presentation access groups.
 *
 * Implementations read the canonical role and assignment tables rather than maintaining a parallel
 * registry. Optional pessimistic locks belong to the caller's existing transaction and keep a group
 * selection stable while an authorization-sensitive preference decision is made.
 *
 * @since  2.0.0
 */
interface PresentationAccessGroupRepository
{
    /**
     * List roles effective in one server-resolved actor and membership context.
     *
     * Direct user roles are combined with roles from only the exact current membership carried by the
     * execution context. Implementations must not union assignments from another organization membership.
     *
     * @param   ExecutionContext  $context  Authenticated actor and optional current membership selection.
     * @param   int               $limit    Maximum effective groups returned, from one through 250.
     *
     * @return  PresentationAccessGroupCatalog  Bounded effective groups plus explicit overflow evidence.
     *
     * @throws  \InvalidArgumentException  When the requested bound is outside the contract.
     *
     * @since   2.0.0
     */
    public function listForContext(ExecutionContext $context, int $limit): PresentationAccessGroupCatalog;

    /**
     * Read a bounded deterministic page of roles available for preference administration.
     *
     * One extra row is inspected so the returned value can report forward navigation. Search matches
     * normalized text literally in canonical role code or name; SQL wildcard characters have no special meaning.
     *
     * @param   int     $limit   Maximum groups returned, from one through 250.
     * @param   int     $offset  Zero-based deterministic row offset.
     * @param   string  $search  Optional normalized role-code or role-name search, up to 64 characters.
     *
     * @return  PresentationAccessGroupCatalog  Bounded groups and an explicit forward-page signal.
     *
     * @throws  \InvalidArgumentException  When the requested bound is outside the contract.
     *
     * @since   2.0.0
     */
    public function catalog(int $limit, int $offset = 0, string $search = ''): PresentationAccessGroupCatalog;

    /**
     * Determine whether one stable presentation access-group identity still names a live role.
     *
     * @param   string  $identifier  Candidate `role:<uuid>` presentation identity.
     * @param   bool    $lock        Whether supported databases should hold the matching row for the transaction.
     *
     * @return  bool  True only when the identifier is canonical and its role exists.
     *
     * @since   2.0.0
     */
    public function exists(string $identifier, bool $lock = false): bool;
}
