<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Composition;

use Kumwe\App\Studio\Application\Rendering\StudioRenderResultAdmission;
use Kumwe\Producer\Deployment\StudioBrowserAssetLocation;
use Kumwe\Producer\Deployment\StudioBrowserAssetLocator;
use Kumwe\Producer\Render\RenderResult;

/**
 * Resolves the pinned Studio enhancement runtime a published page defers when its blocks need it.
 *
 * Producer's semantic markup works without JavaScript; the enhancement runtime only upgrades the
 * closed block families (tabs, dialogs, slideshows and their kin) that a rendered page declares. This
 * boundary asks Producer which pinned asset that is, where the deployment serves it from and with
 * which integrity, and returns nothing at all for a page that needs no enhancement, so most public
 * responses stay script-free.
 *
 * @since  2.0.0
 */
final readonly class StudioPublishedEnhancementRuntime
{
    /**
     * Bind the runtime to the configured pinned browser-asset origin.
     *
     * @param  StudioBrowserAssetLocator  $assets  Producer's locator for the configured origin.
     *
     * @since  2.0.0
     */
    public function __construct(private StudioBrowserAssetLocator $assets)
    {
    }

    /**
     * The integrity-bound runtime location for one render result, or null when the page needs none.
     *
     * @param   RenderResult  $result  Producer render result of a published composition.
     *
     * @return  ?StudioBrowserAssetLocation  Deferred runtime location, or null.
     *
     * @throws  \Kumwe\Producer\Render\RenderException  When the result declares an unpublished enhancement.
     * @throws  \Kumwe\Producer\Deployment\DeploymentException  When the pinned runtime cannot be located.
     *
     * @since   2.0.0
     */
    public function locationFor(RenderResult $result): ?StudioBrowserAssetLocation
    {
        StudioRenderResultAdmission::assertSupported($result);
        if ($result->enhancements === []) {
            return null;
        }

        return $this->assets->locate('enhancement-runtime');
    }
}
