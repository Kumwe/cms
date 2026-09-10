<?php

declare(strict_types=1);

namespace Kumwe\App\Portal\Application;

use Kumwe\Context\Value\ExecutionContext;

/**
 * Provenance-owning adapter that mints portal-surface execution contexts from resolved sessions.
 *
 * @since  2.0.0
 */
interface PortalExecutionContextFactory
{
    /**
     * Mint an `AuthenticatedSurface::Portal` context carrying the live membership snapshot.
     *
     * @param   PortalSession  $session    Resolved portal session.
     * @param   string         $requestId  Current request identifier.
     *
     * @return  ExecutionContext  Trusted portal execution context.
     *
     * @since   2.0.0
     */
    public function create(PortalSession $session, string $requestId): ExecutionContext;
}
