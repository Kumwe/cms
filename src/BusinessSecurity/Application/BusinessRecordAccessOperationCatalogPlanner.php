<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSecurity\Application;

use Kumwe\App\BusinessRecord\Application\ResolvedBusinessDefinition;
use Kumwe\App\BusinessRecord\Domain\RecordScope;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Optional multi-operation policy-planning port for generated business screens.
 *
 * A detail screen gates a closed set of controls at once. Implementations use this port to retain one
 * definition, membership, and policy snapshot while compiling each distinct capability exactly, avoiding
 * one database snapshot read per button without treating one capability as evidence for another.
 *
 * @since  2.0.0
 */
interface BusinessRecordAccessOperationCatalogPlanner extends BusinessRecordAccessCatalogPlanner
{
    /**
     * Resolve plans for several capabilities from one bounded active-definition snapshot.
     *
     * @param ExecutionContext $context Actor and exact authenticated scope.
     * @param list<string> $operations Unique dotted business-record capabilities, capped at 32.
     * @param   list<array{resolved: ResolvedBusinessDefinition, scope: RecordScope, requested: bool}>  $resources
     *          Active definitions, scopes, and whether each needs a top-level catalog plan.
     *
     * @return  array<string, array<string, BusinessRecordAccessPlan>>  Plans by capability and definition UUID.
     *
     * @since   2.0.0
     */
    public function catalogOperationPlans(
        ExecutionContext $context,
        array $operations,
        array $resources,
    ): array;
}
