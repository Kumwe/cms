<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Infrastructure;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Application\Authorization\SystemPrincipal;
use Kumwe\App\BusinessReporting\Application\ExportQueueProducerContextProvider;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Issues narrow Worker system contexts for internal export queue production.
 *
 * @since  2.0.0
 */
final readonly class SystemExportQueueProducerContextProvider implements ExportQueueProducerContextProvider
{
    /**
     * Accept only the kernel-issued Worker principal.
     *
     * @param   SystemPrincipal  $worker  Principal issued with kernel provenance for `SystemIdentity::Worker`.
     *
     * @throws  InvalidArgumentException  When another system identity is supplied.
     *
     * @since   2.0.0
     */
    public function __construct(private SystemPrincipal $worker)
    {
        if ($worker->identity() !== SystemIdentity::Worker) {
            throw new InvalidArgumentException('Export queue production requires the Worker system identity.');
        }
    }

    /**
     * Issue a fresh site-scoped producer context tied to the original request trace.
     *
     * @param   ExecutionContext  $requestContext  Original human request context.
     *
     * @return  ExecutionContext  Kernel-provenance Worker context.
     *
     * @since   2.0.0
     */
    public function forRequest(ExecutionContext $requestContext): ExecutionContext
    {
        return $this->worker->context(
            $requestContext->site(),
            'report-export-dispatch-' . bin2hex(random_bytes(12)),
            $requestContext->correlationId(),
        );
    }
}
