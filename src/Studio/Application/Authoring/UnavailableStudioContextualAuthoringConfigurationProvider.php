<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

use Kumwe\Context\Value\ExecutionContext;

/**
 * Keeps contextual Studio unavailable until its canonical mount configuration is published.
 *
 * Production binds this fail-closed provider independently of release/runtime qualification. A later
 * reviewed adapter can replace it without teaching the Content handler a provisional wire shape.
 *
 * @since  2.0.0
 */
final readonly class UnavailableStudioContextualAuthoringConfigurationProvider implements
    StudioContextualAuthoringConfigurationProvider
{
    /**
     * Refuse every mount because no canonical configuration can currently be supplied.
     *
     * @param   ExecutionContext              $context    Authenticated actor and site resolved by PHP.
     * @param   ContentStudioAuthoringTarget  $target     Trusted Content coordinates for this mount.
     * @param   string                        $csrfToken  Administrator session's current CSRF value.
     *
     * @return  null  Configuration remains unavailable.
     *
     * @since   2.0.0
     */
    public function forMount(
        ExecutionContext $context,
        ContentStudioAuthoringTarget $target,
        string $csrfToken,
    ): ?StudioContextualAuthoringConfiguration {
        return null;
    }
}
