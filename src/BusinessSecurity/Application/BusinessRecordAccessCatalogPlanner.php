<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSecurity\Application;

use Kumwe\App\BusinessRecord\Application\ResolvedBusinessDefinition;
use Kumwe\App\BusinessRecord\Domain\RecordScope;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Optional bulk policy-planning port for generated definition catalogs.
 *
 * Record operations continue to request one plan through `BusinessRecordAccessController`. Discovery
 * already holds a bounded active-definition snapshot, so this port lets a persistence adapter lock the
 * policy generation and fetch all applicable policies once instead of repeating both statements for
 * every definition. Related plans may use only the active definitions in the supplied snapshot; a
 * missing or unusable target is omitted and therefore remains unavailable in generated metadata.
 *
 * @since  2.0.0
 */
interface BusinessRecordAccessCatalogPlanner
{
    /**
     * Resolve plans for one bounded active-definition snapshot under one operation.
     *
     * @param ExecutionContext $context Actor and exact authenticated scope.
     * @param string $operation Dotted business-record operation identifier.
     * @param   list<array{resolved: ResolvedBusinessDefinition, scope: RecordScope, requested: bool}>  $resources
     *          Active definitions, scopes, and whether each needs a top-level catalog plan.
     *
     * @return  array<string, BusinessRecordAccessPlan>  Plans keyed by definition UUID.
     *
     * @since   2.0.0
     */
    public function catalogPlans(
        ExecutionContext $context,
        string $operation,
        array $resources,
    ): array;
}
