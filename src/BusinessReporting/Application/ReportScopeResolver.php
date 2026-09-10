<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Application;

use Kumwe\App\BusinessReporting\Domain\ReportDefinition;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Resolves the record-organization dimension independently from the actor's membership context.
 *
 * @since  2.0.0
 */
interface ReportScopeResolver
{
    /**
     * Resolve the organization identifier the report's source definition actually stores.
     *
     * @param   ExecutionContext  $context               Authenticated actor and optional membership context.
     * @param   ReportDefinition  $report                Active report whose source scope governs the query.
     * @param   ?string           $assertedOrganization  Optional caller assertion to compare with membership.
     *
     * @return  ?string  Authenticated organization for organization-scoped sources, otherwise null.
     *
     * @since   2.0.0
     */
    public function resolve(
        ExecutionContext $context,
        ReportDefinition $report,
        ?string $assertedOrganization,
    ): ?string;
}
