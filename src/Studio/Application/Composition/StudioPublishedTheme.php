<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Composition;

use Kumwe\App\Extension\Runtime\ActiveExtensionSet;
use Kumwe\App\Site\Application\SiteSettings;
use Kumwe\Context\Value\SiteContext;
use RuntimeException;

/**
 * Projects the exact trusted public theme that publication and Studio preview must share.
 *
 * The active theme release comes only from the verified runtime publication. The site presentation
 * comes through its validating domain contract. Their deterministic digest is the immutable revision
 * a Blueprint locks and the live host authority re-resolves, so neither a package activation nor a
 * palette change can silently alter an open exact preview.
 *
 * @since  2.0.0
 */
final readonly class StudioPublishedTheme
{
    /**
     * Bind the projection to validated settings and the trusted resident extension graph.
     *
     * @param  SiteSettings               $settings    Effective site presentation source.
     * @param  ActiveExtensionSet         $extensions  Signed active-theme release source.
     * @param  StudioBuiltInThemeRelease  $builtIn     Exact built-in public-theme deployment release.
     *
     * @since  2.0.0
     */
    public function __construct(
        private SiteSettings $settings,
        private ActiveExtensionSet $extensions,
        private StudioBuiltInThemeRelease $builtIn,
    ) {
    }

    /**
     * Resolve one exact theme package and presentation revision for a site.
     *
     * @param   SiteContext  $site  Site whose public publication surface Studio previews.
     *
     * @return  StudioPublishedThemeReference  Deterministic immutable reference.
     *
     * @since   2.0.0
     */
    public function reference(SiteContext $site): StudioPublishedThemeReference
    {
        $release = $this->extensions->siteThemeRelease($site->identifier()) ?? [
            'id' => 'core.theme/site',
            'version' => '1.0.0',
            'revision' => $this->builtIn->revision,
        ];
        $presentation = $this->settings->current()['presentation'] ?? null;
        if (!is_array($presentation) || array_is_list($presentation)) {
            throw new RuntimeException('The validated public presentation projection is unavailable.');
        }
        /** @var array<string, mixed> $presentation */
        $revision = 'published-' . hash('sha256', (string) json_encode(
            [
                'contract' => 'kumwe.app/published-theme-v1',
                'id' => $release['id'],
                'presentation' => $presentation,
                'release_revision' => $release['revision'],
                'version' => $release['version'],
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));

        return new StudioPublishedThemeReference($release['id'], $release['version'], $revision);
    }
}
