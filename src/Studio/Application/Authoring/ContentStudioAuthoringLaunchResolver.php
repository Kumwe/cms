<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

use Kumwe\Context\Value\ExecutionContext;

/**
 * Combines release/runtime evidence with one canonical per-mount configuration atomically.
 *
 * Runtime qualification is necessary but insufficient. The resolver preserves an earlier runtime
 * refusal without consulting configuration and converts a runtime-ready result to the stable
 * configuration fallback unless the provider returns the exact mount value in the same call.
 *
 * @since  2.0.0
 */
final readonly class ContentStudioAuthoringLaunchResolver
{
    /**
     * Bind the independent runtime and per-mount configuration gates.
     *
     * @param  StudioContextualAuthoringAvailability           $availability    Pinned runtime evidence.
     * @param  StudioContextualAuthoringConfigurationProvider  $configurations  Canonical mount source.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioContextualAuthoringAvailability $availability,
        private StudioContextualAuthoringConfigurationProvider $configurations,
    ) {
    }

    /**
     * Resolve one atomic launch decision from PHP-authoritative request state.
     *
     * @param   ExecutionContext              $context    Authenticated actor and site resolved by PHP.
     * @param   ContentStudioAuthoringTarget  $target     Trusted Content coordinates for this mount.
     * @param   string                        $csrfToken  Administrator session's current CSRF value.
     *
     * @return  ContentStudioAuthoringLaunch  Configured launch or structured fallback.
     *
     * @since   2.0.0
     */
    public function resolve(
        ExecutionContext $context,
        ContentStudioAuthoringTarget $target,
        string $csrfToken,
    ): ContentStudioAuthoringLaunch {
        $runtime = $this->availability->current();
        if (!$runtime->available) {
            return new ContentStudioAuthoringLaunch($target, $runtime, null);
        }

        $configuration = $this->configurations->forMount($context, $target, $csrfToken);
        if ($configuration === null) {
            return new ContentStudioAuthoringLaunch(
                $target,
                StudioContextualAuthoringReadiness::fallback(
                    StudioContextualAuthoringFallbackReason::ConfigurationUnavailable,
                ),
                null,
            );
        }

        return new ContentStudioAuthoringLaunch($target, $runtime, $configuration);
    }
}
