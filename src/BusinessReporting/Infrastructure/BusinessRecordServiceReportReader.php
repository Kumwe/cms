<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Infrastructure;

use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Query\BrowseRecordsQuery;
use Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordQueryPurpose;
use Kumwe\App\BusinessRecord\Application\RecordBrowseResult;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\App\BusinessReporting\Application\BusinessRecordReportReader;

/**
 * Adapter forcing every reporting read through `BusinessRecordService::browse()`.
 *
 * @since  2.0.0
 */
final readonly class BusinessRecordServiceReportReader implements BusinessRecordReportReader
{
    /**
     * Wire the reporting seam to the canonical policy-enforcing service.
     *
     * @param  BusinessRecordService  $records  Canonical business record use case.
     *
     * @since  2.0.0
     */
    public function __construct(private BusinessRecordService $records)
    {
    }

    /**
     * Read one policy-filtered and disclosure-safe page.
     *
     * @param   ExecutionContext            $context                 Authenticated actor and scope.
     * @param   string                      $definitionIdentifier    Source business entity handle.
     * @param   RecordQuerySpecification    $specification           Bounded typed query AST.
     * @param   ?string                     $organizationIdentifier  Requested organization scope.
     * @param   BusinessRecordQueryPurpose  $purpose                 Report or export usage.
     *
     * @return  RecordBrowseResult  Safe page from the record service.
     *
     * @since   2.0.0
     */
    public function browse(
        ExecutionContext $context,
        string $definitionIdentifier,
        RecordQuerySpecification $specification,
        ?string $organizationIdentifier,
        BusinessRecordQueryPurpose $purpose,
    ): RecordBrowseResult {
        return $this->records->browse(new BrowseRecordsQuery(
            $context,
            $definitionIdentifier,
            $specification,
            $organizationIdentifier,
            $purpose,
        ));
    }
}
