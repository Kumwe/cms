<?php

declare(strict_types=1);

namespace Kumwe\App\Presentation\Application\Preference;

use InvalidArgumentException;
use Kumwe\App\InterfaceStandard\CustomizationScope;
use Kumwe\App\InterfaceStandard\CustomizationSlot;
use Kumwe\App\InterfaceStandard\PresentationPreferenceKey;
use Kumwe\App\InterfaceStandard\SurfaceArea;
use Kumwe\App\InterfaceStandard\SurfaceId;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;

/**
 * Server-resolved hierarchy identities used to resolve preferences for one rendered surface.
 *
 * Administrator rendering considers site, administrator, active role/workspace, then user layers.
 * Portal rendering omits administrator configuration; public rendering considers site and an optional
 * authenticated user. The template area is rejected because templates implement surfaces rather than
 * forming an actor preference context of their own.
 *
 * @since  2.0.0
 */
final readonly class PresentationPreferenceContext
{
    /**
     * Validate the rendered area and its server-selected site, role/workspace, and actor identities.
     *
     * @param   SurfaceArea  $area             Rendered actor-facing area.
     * @param   string       $siteId           Current site identity, always present.
     * @param   ?string      $roleWorkspaceId  Active role or workspace identity, when applicable.
     * @param   ?string      $userId           Authenticated actor identity, when applicable.
     *
     * @throws  InvalidArgumentException  When the area is a template or an identity violates the schema.
     *
     * @since   2.0.0
     */
    private function __construct(
        public SurfaceArea $area,
        public string $siteId,
        public ?string $roleWorkspaceId = null,
        public ?string $userId = null,
    ) {
        if ($area === SurfaceArea::Template) {
            throw new InvalidArgumentException('A KIS template is not a presentation preference runtime context.');
        }
        PresentationPreferenceKey::assertScopeId($siteId);
        PresentationPreferenceKey::assertScopeId($roleWorkspaceId);
        PresentationPreferenceKey::assertScopeId($userId);
    }

    /**
     * Build an authenticated context only from the execution envelope's server-resolved identities.
     *
     * @param   SurfaceArea       $area     Administrator, portal, or authenticated public area being rendered.
     * @param   ExecutionContext  $context  Trusted site, principal, and optional validated workspace selection.
     *
     * @return  self  Resolution context whose identities did not come from request preference fields.
     *
     * @throws  InvalidArgumentException  When the area is a template.
     *
     * @since   2.0.0
     */
    public static function fromExecutionContext(SurfaceArea $area, ExecutionContext $context): self
    {
        return new self(
            $area,
            $context->site()->identifier(),
            $context->workspace()?->identifier(),
            $context->principal()?->subject(),
        );
    }

    /**
     * Build the only unauthenticated resolution context, containing the current public site alone.
     *
     * @param   SiteContext  $site  Server-selected public site.
     *
     * @return  self  Public context with no role/workspace or user layer.
     *
     * @since   2.0.0
     */
    public static function anonymousPublic(SiteContext $site): self
    {
        return new self(SurfaceArea::Public, $site->identifier());
    }

    /**
     * Produce low-to-high precedence keys for one surface and slot.
     *
     * @param   SurfaceId          $surface  Semantic surface being rendered.
     * @param   CustomizationSlot  $slot     Presentation choice being resolved.
     *
     * @return  list<PresentationPreferenceKey>  Applicable layers in deterministic override order.
     *
     * @since   2.0.0
     */
    public function layers(SurfaceId $surface, CustomizationSlot $slot): array
    {
        $layers = [new PresentationPreferenceKey($surface, $slot, CustomizationScope::Site, $this->siteId)];
        if ($this->area === SurfaceArea::Administrator) {
            $layers[] = new PresentationPreferenceKey(
                $surface,
                $slot,
                CustomizationScope::Administrator,
                null,
            );
        }
        if ($this->area !== SurfaceArea::Public && $this->roleWorkspaceId !== null) {
            $layers[] = new PresentationPreferenceKey(
                $surface,
                $slot,
                CustomizationScope::RoleWorkspace,
                $this->roleWorkspaceId,
            );
        }
        if ($this->userId !== null) {
            $layers[] = new PresentationPreferenceKey(
                $surface,
                $slot,
                CustomizationScope::User,
                $this->userId,
            );
        }

        return $layers;
    }
}
