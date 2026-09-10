<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Application;

use Kumwe\App\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\Context\Value\SiteContext;

/**
 * Locking re-reads of the facts a schema execution started from, taken again before it commits.
 *
 * The executor decides what to apply from a definition's ownership and its installation status, then
 * spends however long the DDL takes actually applying it. Both facts can move in that window: an operator
 * disables the owning extension, another plan finalizes, a purge removes the installation. This port lets
 * the executor take those rows again and hold them still for the rest of its transaction, so a change that
 * arrived mid-run is refused rather than overwritten — the alternative is an installation row that claims
 * a lifecycle nobody asked for. Both calls are locking reads, so an implementation may require the
 * caller's transaction to already be open and must keep the rows locked until it commits.
 *
 * @since  2.0.0
 */
interface BusinessSchemaExecutionStateGuard
{
    /**
     * Lock the definition row and confirm it still belongs to the site and owner execution began under.
     *
     * Ownership is reported rather than merely asserted because a plan may legitimately finalize while its
     * owning extension is disabled — recovering an interrupted upgrade, for one — and the caller uses the
     * answer to decide which installation states it will then accept. Pass `$activeRequired` only where an
     * inactive owner should end the run outright.
     *
     * @param   SiteContext  $site             Site the execution was authorized for.
     * @param   string       $definitionId     Definition whose row is locked, as a UUID.
     * @param   string       $ownerIdentifier  Owner the plan was built and approved for.
     * @param   bool         $activeRequired   Whether an inactive owner should be refused outright.
     *
     * @return  bool  Whether the owning extension is currently marked active.
     *
     * @throws  BusinessSchemaConflict  When the implementation's transaction requirement is unmet, the row
     *          cannot be locked, the definition has gone, its site or owner is no longer the one execution
     *          began under, or an active owner was required and the owner is not active.
     *
     * @since   2.0.0
     */
    public function lockOwner(
        SiteContext $site,
        string $definitionId,
        string $ownerIdentifier,
        bool $activeRequired,
    ): bool;

    /**
     * Lock this definition's installation row and read the lifecycle status it currently holds.
     *
     * @param   string  $definitionId  Definition whose installation row is locked, as a UUID.
     *
     * @return  ?SchemaInstallationStatus  The locked status, or null when the definition has no
     *          installation row at all, which is the normal shape of a first install.
     *
     * @throws  BusinessSchemaConflict  When the implementation's transaction requirement is unmet, the row
     *          cannot be locked, or it holds a status this build cannot interpret — reported rather than
     *          folded into the null case, since finalizing over it would discard the state it recorded.
     *
     * @since   2.0.0
     */
    public function lockInstallationStatus(string $definitionId): ?SchemaInstallationStatus;
}
