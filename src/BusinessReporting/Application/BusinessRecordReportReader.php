<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Application;

use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordQueryPurpose;
use Kumwe\App\BusinessRecord\Application\RecordBrowseResult;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordQuerySpecification;

/**
 * Policy-aware record-page seam used by reporting and export execution.
 *
 * @since  2.0.0
 */
interface BusinessRecordReportReader
{
    /**
     * Read one page through the canonical business-record service.
     *
     * @param   ExecutionContext            $context                 Authenticated actor and scope.
     * @param   string                      $definitionIdentifier    Source business entity handle.
     * @param   RecordQuerySpecification    $specification           Bounded typed query AST.
     * @param   ?string                     $organizationIdentifier  Requested organization scope.
     * @param   BusinessRecordQueryPurpose  $purpose                 Report or export disclosure usage.
     *
     * @return  RecordBrowseResult  Policy-filtered and field-narrowed page.
     *
     * @since   2.0.0
     */
    public function browse(
        ExecutionContext $context,
        string $definitionIdentifier,
        RecordQuerySpecification $specification,
        ?string $organizationIdentifier,
        BusinessRecordQueryPurpose $purpose,
    ): RecordBrowseResult;
}
