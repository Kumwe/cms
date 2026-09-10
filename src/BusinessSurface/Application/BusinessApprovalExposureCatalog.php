<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Application;

use Kumwe\Context\Value\ExecutionContext;

/**
 * Resolves whether stored business-action approvals remain exposed on one authenticated surface.
 *
 * Approval reviewers are intentionally not required to hold the action execution capability. Their
 * visibility comes from the approval application service; this port adds only the active definition,
 * explicit surface, and action-declaration ceiling needed by generated approval adapters.
 *
 * @since  2.0.0
 */
interface BusinessApprovalExposureCatalog
{
    /**
     * Return request identities whose active definition/action tuple is exposed on the exact surface.
     *
     * @param ExecutionContext $context Authenticated actor, site, and membership.
     * @param BusinessSurface $surface Generated adapter requesting approval presentation.
     * @param   list<array{request_id: string, definition_id: string, action: string}>  $requests
     *          Canonical business-record approval bindings, bounded to one hundred entries.
     *
     * @return  array<string, true>  Exposed request UUIDs keyed to true.
     *
     * @since   2.0.0
     */
    public function approvalActions(
        ExecutionContext $context,
        BusinessSurface $surface,
        array $requests,
    ): array;
}
