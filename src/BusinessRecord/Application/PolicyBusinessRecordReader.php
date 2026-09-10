<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\BusinessRecord\Application\Query\BrowseRecordsQuery;
use Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordPage;
use Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordReader;
use Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordReadRequest;
use RuntimeException;
use Kumwe\App\Extension\Runtime\ExtensionExecutionContext;

/**
 * Host implementation of the SDK business-record reader port for in-process extension code.
 *
 * Extensions resolve this port from their restricted container and hand it a request typed against the
 * public SDK interfaces only. Every disclosure still runs through `BusinessRecordService::browse()`, so
 * capability, scope, row and field policy are applied exactly as they are for the host's own delivery
 * paths — the port narrows the surface, it never adds authority. The SDK contract requires proof that
 * the request carries the concrete host-issued execution context, because implementing the public
 * context interface is not authority: a context minted by extension code is refused here before any
 * policy work begins.
 *
 * @since  2.0.0
 */
final readonly class PolicyBusinessRecordReader implements BusinessRecordReader
{
    /**
     * Bind the port to the one policy-enforcing browse service.
     *
     * @param  BusinessRecordService  $records  Host service that authorizes and executes every browse.
     *
     * @since  2.0.0
     */
    public function __construct(private BusinessRecordService $records)
    {
    }

    /**
     * Disclose one policy-admitted page for a host-authorized extension request.
     *
     * @param   BusinessRecordReadRequest  $query  Request naming the definition, scope and page wanted.
     *
     * @return  BusinessRecordPage  Bounded page of disclosure-safe record views.
     *
     * @throws  RuntimeException  When the request does not carry the concrete host-issued execution
     *          context for the active invocation.
     *
     * @since   2.0.0
     */
    public function readPage(BusinessRecordReadRequest $query): BusinessRecordPage
    {
        $context = ExtensionExecutionContext::host($query->context)
            ?? throw new RuntimeException('Business-record disclosure requires the host-issued execution context.');

        return $this->records->browse(new BrowseRecordsQuery(
            $context,
            $query->definitionIdentifier,
            $query->specification,
            $query->organizationIdentifier,
            $query->purpose,
        ));
    }
}
