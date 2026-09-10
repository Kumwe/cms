<?php

declare(strict_types=1);

namespace Kumwe\App\Presentation\Application;

use Kumwe\App\Extension\Domain\ThemeSurface;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Port deciding whether an actor may change which theme is bound to a presentation surface.
 *
 * Theme management is deliberately split per surface — `themes.site.manage` and
 * `themes.administrator.manage` are separate capabilities — so an editor trusted with the public look of
 * a site cannot reach the back-office chrome. `DoctrineExtensionManager` consults this on every template
 * install, activation, and removal, and an implementation is expected to re-read the grant from durable
 * storage rather than trust the caller's token, so a revocation takes effect immediately.
 *
 * @since  2.0.0
 */
interface ThemeMutationAuthorizer
{
    /**
     * Assert that the actor holds the theme-management capability for one surface.
     *
     * @param   ExecutionContext  $context  Actor and site the mutation runs as.
     * @param   ThemeSurface      $surface  Surface whose theme binding is about to change.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses the actor.
     * @throws  \Kumwe\App\Identity\Application\Authorization\InsufficientCapability  When no grant backs it.
     *
     * @since   2.0.0
     */
    public function assertSurface(ExecutionContext $context, ThemeSurface $surface): void;
}
