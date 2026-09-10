<?php

declare(strict_types=1);

namespace Kumwe\App\Portal\Presentation;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;
use Kumwe\App\Portal\Application\PortalSession;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Binding\Http\PortalRouteRenderer;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Object-capability renderer fixed to one contributed route's owner and declared portal template.
 *
 * Extension factories receive this wrapper rather than `PortalRenderer`, so they can supply view data
 * but cannot name another extension, another template, or a core portal template.
 *
 * @since  2.0.0
 */
final readonly class PortalContributionRenderer implements PortalRouteRenderer
{
    /**
     * Bind a rendering capability to one owner and one template declaration.
     *
     * @param  PortalRenderer  $renderer          Shared isolated portal renderer, retained privately.
     * @param  string          $extension         Canonical owner identifier chosen by the registry.
     * @param  string          $template          Owned template identifier chosen by the route declaration.
     * @param  string          $activeNavigation  Signed route identifier highlighted by the host shell.
     * @param  object          $provenance        Private composition-root authority for trusted request contexts.
     *
     * @since  2.0.0
     */
    public function __construct(
        private PortalRenderer $renderer,
        private string $extension,
        private string $template,
        private string $activeNavigation,
        private object $provenance,
    ) {
    }

    /**
     * Render the one template this capability was created for.
     *
     * @param   array<string, mixed>    $model    Extension view data.
     * @param   ServerRequestInterface  $request  Active portal request.
     *
     * @return  string  Rendered portal document.
     *
     * @since   2.0.0
     */
    public function render(array $model, ServerRequestInterface $request): string
    {
        $session = $request->getAttribute(PortalSession::REQUEST_ATTRIBUTE);
        $context = $request->getAttribute(ExecutionContextAttribute::NAME);
        if (
            !$session instanceof PortalSession
            || !$context instanceof ExecutionContext
            || !$context->hasProvenance($this->provenance)
            || $context->principal() !== $session->identity->principal
            || $context->sessionId() !== $session->id
            || $context->surface() !== AuthenticatedSurface::Portal
        ) {
            throw new InvalidArgumentException(
                'An active host-issued portal session and execution context are required.',
            );
        }

        $capabilities = [];
        foreach ($session->identity->principal->capabilities() as $capability) {
            $capabilities[$capability->value()] = true;
        }

        $model['capabilities'] = $capabilities;
        $model['active_navigation'] = $this->activeNavigation;

        return $this->renderer->renderExtension($this->extension, $this->template, $model, $session);
    }
}
