<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Infrastructure;

use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Domain\ScopeMode;
use Kumwe\App\BusinessRecord\Application\BusinessRecordDefinitionResolver;
use Kumwe\App\BusinessReporting\Application\ReportScopeResolver;
use Kumwe\App\BusinessReporting\Domain\ReportDefinition;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Derives report record scope from the installed source definition and live membership.
 *
 * A portal actor can carry an organization while reading a site-scoped definition. That organization
 * remains part of the accountable execution context, but it must not be passed as a record partition.
 * Organization-scoped definitions instead use only the organization revalidated in the context.
 *
 * @since  2.0.0
 */
final readonly class BusinessRecordReportScopeResolver implements ReportScopeResolver
{
    /**
     * Bind report scope resolution to the active installed business-definition catalog.
     *
     * @param  BusinessRecordDefinitionResolver  $definitions  Installed source-definition resolver.
     *
     * @since  2.0.0
     */
    public function __construct(private BusinessRecordDefinitionResolver $definitions)
    {
    }

    /**
     * Resolve and validate the report source's effective organization partition.
     *
     * @param   ExecutionContext  $context               Authenticated actor and optional membership context.
     * @param   ReportDefinition  $report                Active report whose source scope governs the query.
     * @param   ?string           $assertedOrganization  Optional caller assertion to compare with membership.
     *
     * @return  ?string  Live organization for organization-scoped sources, otherwise null.
     *
     * @throws  InvalidArgumentException  When the assertion differs from live membership or membership is absent.
     *
     * @since   2.0.0
     */
    public function resolve(
        ExecutionContext $context,
        ReportDefinition $report,
        ?string $assertedOrganization,
    ): ?string {
        $resolved = $this->definitions->forCreate($context, $report->sourceDefinition);
        $organizationScoped = in_array(
            $resolved->definition->scope,
            [ScopeMode::Organization, ScopeMode::SiteOrganization],
            true,
        );
        $authenticatedOrganization = $context->organization()?->identifier();
        if (
            ($assertedOrganization !== null && $assertedOrganization !== $authenticatedOrganization)
            || ($organizationScoped && $authenticatedOrganization === null)
        ) {
            throw new InvalidArgumentException('The report scope does not match authenticated membership.');
        }

        return $organizationScoped ? $authenticatedOrganization : null;
    }
}
