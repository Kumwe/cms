<?php

declare(strict_types=1);

namespace Kumwe\App\Portal\Application;

use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Default factory that stamps resolved browser sessions as the portal delivery surface.
 *
 * @since  2.0.0
 */
final readonly class DefaultPortalExecutionContextFactory implements PortalExecutionContextFactory
{
    /**
     * Mint a password-strength portal context with exact session and live membership bindings.
     *
     * @param   PortalSession  $session    Resolved portal session.
     * @param   string         $requestId  Current request identifier.
     *
     * @return  ExecutionContext  Portal-surface context trusted by the principal's provenance.
     *
     * @since   2.0.0
     */
    public function create(PortalSession $session, string $requestId): ExecutionContext
    {
        return $session->identity->principal->context(
            $session->identity->context->site,
            AuthenticationStrength::Password,
            $requestId,
            surface: AuthenticatedSurface::Portal,
            membership: $session->identity->context->membership,
            sessionId: $session->id,
        );
    }
}
