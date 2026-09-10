<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

use Kumwe\Context\Value\ExecutionContext;

/**
 * Supplies the canonical host configuration for one authorized Content Studio mount.
 *
 * Implementations receive only PHP-authoritative launch facts and the administrator session's CSRF
 * value. Returning null means the published configuration contract cannot be supplied atomically and
 * therefore requires the structured Content editor fallback.
 *
 * @since  2.0.0
 */
interface StudioContextualAuthoringConfigurationProvider
{
    /**
     * Resolve the exact configuration for one actor, session, and Content target.
     *
     * @param   ExecutionContext              $context    Authenticated actor and site resolved by PHP.
     * @param   ContentStudioAuthoringTarget  $target     Trusted Content coordinates for this mount.
     * @param   string                        $csrfToken  Administrator session's current CSRF value.
     *
     * @return  ?StudioContextualAuthoringConfiguration  Canonical configuration, or null while unavailable.
     *
     * @since   2.0.0
     */
    public function forMount(
        ExecutionContext $context,
        ContentStudioAuthoringTarget $target,
        string $csrfToken,
    ): ?StudioContextualAuthoringConfiguration;
}
