<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Application;

use Kumwe\Context\Value\AuthenticatedSurface;

/**
 * Delivery boundary requesting generated business metadata or operations.
 *
 * @since  2.0.0
 */
enum BusinessSurface: string
{
    /** Administrator browser workspace. @since 2.0.0 */
    case Administrator = 'administrator';

    /** Explicitly enabled ordinary-user portal workspace. @since 2.0.0 */
    case Portal = 'portal';

    /** Authenticated generic REST API. @since 2.0.0 */
    case Api = 'api';

    /** Host-authorized command-line client. @since 2.0.0 */
    case Cli = 'cli';

    /** Bounded Model Context Protocol client. @since 2.0.0 */
    case Mcp = 'mcp';

    /**
     * Map an authenticated execution context onto its generated surface.
     *
     * @param   AuthenticatedSurface  $surface  Delivery provenance recorded by authentication.
     *
     * @return  self|null  Generated surface, or null for background and recovery contexts.
     *
     * @since   2.0.0
     */
    public static function fromAuthenticated(AuthenticatedSurface $surface): ?self
    {
        return match ($surface) {
            AuthenticatedSurface::Administrator => self::Administrator,
            AuthenticatedSurface::Portal => self::Portal,
            AuthenticatedSurface::Api => self::Api,
            AuthenticatedSurface::Cli => self::Cli,
            AuthenticatedSurface::Mcp => self::Mcp,
            AuthenticatedSurface::Background, AuthenticatedSurface::Recovery => null,
        };
    }
}
