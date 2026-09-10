<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Composition;

use Kumwe\App\Studio\Application\Composition\StudioPublishedEnhancementRuntime;
use Kumwe\App\Studio\Application\Rendering\StudioRenderResultAdmission;
use Kumwe\Producer\Deployment\StudioBrowserAssetLocator;
use Kumwe\Producer\Render\Enhancement;
use Kumwe\Producer\Render\RenderException;
use Kumwe\Producer\Render\RenderResult;
use Kumwe\Producer\Schema\StudioContractResources;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves a published page defers the pinned enhancement runtime only when its blocks need it.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioPublishedEnhancementRuntime::class)]
#[CoversClass(StudioRenderResultAdmission::class)]
final class StudioPublishedEnhancementRuntimeTest extends TestCase
{
    /**
     * A script-free composition yields no runtime; a published family yields the integrity-bound CDN location.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLocatesThePinnedRuntimeOnlyForEnhancedResults(): void
    {
        $runtime = new StudioPublishedEnhancementRuntime(
            StudioBrowserAssetLocator::npmPackages('https://cdn.jsdelivr.net/npm'),
        );

        self::assertNull($runtime->locationFor(new RenderResult('<p>plain</p>', '', [])));

        $location = $runtime->locationFor(new RenderResult(
            '<div data-studio-enhancement="tabs"></div>',
            '',
            [new Enhancement('tabs', 'node-one', 's6e6f64652d6f6e65')],
        ));

        self::assertNotNull($location);
        self::assertSame('enhancement-runtime', $location->role());
        self::assertSame('https://cdn.jsdelivr.net', $location->origin());
        self::assertStringStartsWith(
            'https://cdn.jsdelivr.net/npm/@kumwe/studio-renderer-web@0.1.0-beta.3/dist/browser/assets/'
                . 'studio-enhancements-',
            $location->url(),
        );
        $expectedIntegrity = StudioContractResources::browserAsset('enhancement-runtime')->integrity();
        self::assertSame($expectedIntegrity, $location->integrity());
    }

    /**
     * A family the pinned runtime does not implement is refused before any location is resolved.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRefusesAnUnpublishedFamily(): void
    {
        $runtime = new StudioPublishedEnhancementRuntime(StudioBrowserAssetLocator::npmPackages('/vendor/npm'));

        $this->expectException(RenderException::class);

        $runtime->locationFor(new RenderResult('<div></div>', '', [
            new Enhancement('motion', 'node-one', 's6e6f64652d6f6e65'),
        ]));
    }
}
