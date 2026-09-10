<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Identity\Application\Authorization\InsufficientCapability;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Presentation\Application\ThemeMutationAuthorizer;
use Kumwe\App\Extension\Domain\ThemeSurface;

/**
 * An in-memory theme authorizer that decides purely from the principal's capabilities.
 *
 * Production composes DoctrineThemeMutationAuthorizer, which also consults persisted
 * surface ownership. This double exists so theme-manager integration tests can vary the
 * caller's capabilities without provisioning ownership rows.
 */
final readonly class CapabilityThemeAuthorizer implements ThemeMutationAuthorizer
{
    public function assertSurface(ExecutionContext $context, ThemeSurface $surface): void
    {
        $principal = $context->principal();
        if ($principal === null) {
            return;
        }
        $capability = 'themes.' . $surface->value . '.manage';
        if (!$principal->hasCapability(Capability::fromString($capability))) {
            throw new InsufficientCapability($capability);
        }
    }
}
