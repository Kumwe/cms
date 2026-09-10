<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSecurity\Application;

use Kumwe\App\BusinessRecord\Application\ResolvedBusinessDefinition;
use Kumwe\App\BusinessRecord\Domain\RecordScope;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Application port resolving one authoritative access decision for a business-record operation.
 *
 * @since  2.0.0
 */
interface BusinessRecordAccessController
{
    /**
     * Resolve row, field, relation and action access for an exact actor/resource/scope tuple.
     *
     * @param   ExecutionContext            $context    Authenticated actor, site, organization and assurance.
     * @param   string                      $operation  Dotted business-record operation identifier.
     * @param   ResolvedBusinessDefinition  $resolved   Pinned definition and installed physical schema.
     * @param   RecordScope                 $scope      Site and organization every resulting query must bind.
     *
     * @return  BusinessRecordAccessPlan  Immutable decision whose fingerprint covers current policy state.
     *
     * @since   2.0.0
     */
    public function plan(
        ExecutionContext $context,
        string $operation,
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
    ): BusinessRecordAccessPlan;
}
