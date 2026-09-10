<?php

declare(strict_types=1);

namespace Kumwe\App\Presentation\Application;

use Kumwe\App\Extension\Domain\ThemeSurface;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Port for the extra proof of presence a theme activation must clear before it is applied.
 *
 * Capability checks answer "may this role manage themes"; this port answers "is that human here right
 * now", which matters because the administrator theme renders the very console an operator would use to
 * undo a bad activation. `DoctrineExtensionManager` consults it after authorization and before the
 * candidate package is compiled, so a refused step-up leaves the extension registry untouched.
 *
 * @since  2.0.0
 */
interface ThemeActivationGuard
{
    /**
     * Assert that the actor may activate a theme on the given surface.
     *
     * Implementations are expected to let the site surface through unchallenged, to demand a step-up on
     * the administrator surface, and to rate limit failures so the credential cannot be guessed by
     * repeated activation attempts.
     *
     * @param   ThemeSurface      $surface           Presentation surface whose theme is about to change.
     * @param   ExecutionContext  $context           Actor and site the activation runs as.
     * @param   string|null       $stepUpCredential  Password re-entered now, or null when none was given.
     *
     * @return  void
     *
     * @throws  StepUpAuthenticationRequired  When the surface demands a step-up the actor did not satisfy.
     *
     * @since   2.0.0
     */
    public function assertAllowed(
        ThemeSurface $surface,
        ExecutionContext $context,
        #[\SensitiveParameter] ?string $stepUpCredential,
    ): void;
}
