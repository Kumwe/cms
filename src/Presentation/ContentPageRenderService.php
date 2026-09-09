<?php

declare(strict_types=1);

namespace Kumwe\App\Presentation;

use Kumwe\App\Presentation\Application\SitePresentation;
use Kumwe\App\Site\Application\SiteSettings;
use Kumwe\Producer\Deployment\StudioBrowserAssetLocation;

/**
 * Canonical site-template and theme path shared by published and unpublished content rendering.
 *
 * @since  2.0.0
 */
final readonly class ContentPageRenderService
{
    /**
     * Bind the canonical page path to validated settings and the isolated site Twig renderer.
     *
     * @param  SiteSettings  $settings  Site presentation document.
     * @param  SiteRenderer  $renderer  Isolated site template/theme renderer.
     *
     * @since  2.0.0
     */
    public function __construct(private SiteSettings $settings, private SiteRenderer $renderer)
    {
    }

    /**
     * Render one content page through the same validated site presentation pipeline.
     *
     * @param   string                       $template               Site layout name without `.twig`.
     * @param   array<string, mixed>|null    $entry                  Already presented safe entry, or null for a home
     *          template with no selected record.
     * @param   string                       $currentPath            Current application path.
     * @param   string                       $canonicalUrl           Canonical path or absolute URL.
     * @param   string|null                  $schemeOverride         Optional menu-bound colour scheme.
     * @param   string                       $surfaceId              Stable interface surface identity.
     * @param   list<array<string, mixed>>   $navigation             Presented site navigation.
     * @param   array<string, mixed>         $languages              Presented language alternates.
     * @param   bool                         $includeThemeVariables  Whether validated CSS variables may be emitted as
     *          the existing public theme attribute; preview documents set false under their stricter CSP.
     * @param   string|null                  $studioStylesheetHref   Exact same-origin Producer stylesheet URL.
     * @param   ?StudioBrowserAssetLocation  $studioEnhancement      Pinned enhancement runtime the rendered blocks
     *          need, deferred with its manifest integrity, or null for a script-free page.
     *
     * @return  string  Complete themed HTML document.
     *
     * @since   2.0.0
     */
    public function render(
        string $template,
        ?array $entry,
        string $currentPath,
        string $canonicalUrl,
        ?string $schemeOverride,
        string $surfaceId,
        array $navigation = [],
        array $languages = [],
        bool $includeThemeVariables = true,
        ?string $studioStylesheetHref = null,
        ?StudioBrowserAssetLocation $studioEnhancement = null,
    ): string {
        $settings = $this->settings->current();
        $presentation = SitePresentation::from(
            $settings['presentation'] ?? SitePresentation::defaults(),
        )->withSchemeOverride($schemeOverride)->toView();
        if (!$includeThemeVariables) {
            $presentation['css_variables'] = [];
        }

        $variables = [
            'site_name' => $settings['site_name'],
            'navigation' => $navigation,
            'current_path' => $currentPath,
            'canonical_url' => $canonicalUrl,
            'site_logo' => $presentation['logo'],
            'presentation' => $presentation,
            'surface_id' => $surfaceId,
            'languages' => $languages,
        ];
        if ($entry !== null) {
            $variables['entry'] = $entry;
        }

        $html = $this->renderer->render($template, $variables);
        if ($studioStylesheetHref === null) {
            return $html;
        }
        if (
            preg_match(
                '/^\/studio\/styles\/[a-f0-9]{64}\.css\?[A-Za-z0-9._~%=&-]{1,4096}$/D',
                $studioStylesheetHref,
            ) !== 1
            || str_contains($studioStylesheetHref, '..')
        ) {
            throw new \InvalidArgumentException('The published Studio stylesheet URL is invalid.');
        }
        $offset = strripos($html, '</head>');
        if ($offset === false) {
            throw new \InvalidArgumentException('A themed content document must contain a closing head tag.');
        }
        $link = sprintf(
            '<link rel="stylesheet" href="%s" data-studio-composition>',
            htmlspecialchars($studioStylesheetHref, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'),
        );
        if ($studioEnhancement !== null) {
            $link .= sprintf(
                '<script defer src="%s" integrity="%s" crossorigin="anonymous" data-studio-enhancements></script>',
                htmlspecialchars($studioEnhancement->url(), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'),
                htmlspecialchars($studioEnhancement->integrity(), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'),
            );
        }

        return substr_replace($html, $link, $offset, 0);
    }

    /**
     * Render an exact preview document whose validated theme travels through a same-origin stylesheet.
     *
     * @param   string                     $template        Site layout name without `.twig`.
     * @param   array<string, mixed>|null  $entry           Already presented safe entry.
     * @param   string                     $currentPath     Current application path.
     * @param   string                     $canonicalUrl    Canonical path or absolute URL.
     * @param   string|null                $schemeOverride  Optional menu-bound colour scheme.
     * @param   string                     $surfaceId       Stable interface surface identity.
     * @param   string                     $stylesheetHref  Trusted combined stylesheet sentinel.
     *
     * @return  array{html: string, themeStylesheet: string}  Complete HTML and its closed theme stylesheet.
     *
     * @since   2.0.0
     */
    public function renderPreview(
        string $template,
        ?array $entry,
        string $currentPath,
        string $canonicalUrl,
        ?string $schemeOverride,
        string $surfaceId,
        string $stylesheetHref,
    ): array {
        if (
            preg_match('/^\/[A-Za-z0-9._\/-]{1,255}$/D', $stylesheetHref) !== 1
            || str_contains($stylesheetHref, '..')
        ) {
            throw new \InvalidArgumentException('The preview stylesheet path is invalid.');
        }
        $settings = $this->settings->current();
        $presentation = SitePresentation::from(
            $settings['presentation'] ?? SitePresentation::defaults(),
        )->withSchemeOverride($schemeOverride)->toView();
        $themeVariables = $presentation['css_variables'];
        if (!is_array($themeVariables) || array_is_list($themeVariables)) {
            throw new \InvalidArgumentException('The generated theme style variables are invalid.');
        }
        $presentation['css_variables'] = [];
        $variables = [
            'site_name' => $settings['site_name'],
            'navigation' => [],
            'current_path' => $currentPath,
            'canonical_url' => $canonicalUrl,
            'site_logo' => $presentation['logo'],
            'presentation' => $presentation,
            'surface_id' => $surfaceId,
            'languages' => [],
        ];
        if ($entry !== null) {
            $variables['entry'] = $entry;
        }
        $html = $this->renderer->render($template, $variables);
        $offset = strripos($html, '</head>');
        if ($offset === false) {
            throw new \InvalidArgumentException('A themed content document must contain a closing head tag.');
        }
        $link = sprintf('<link rel="stylesheet" href="%s" data-studio-composition>', $stylesheetHref);
        $html = substr_replace($html, $link, $offset, 0);
        $declarations = '';
        foreach ($themeVariables as $property => $value) {
            if (
                !is_string($property)
                || preg_match('/^--[a-z0-9-]+$/D', $property) !== 1
                || !is_string($value)
                || preg_match('/^#[a-f0-9]{6}$/D', $value) !== 1
            ) {
                throw new \InvalidArgumentException('A generated theme style variable is invalid.');
            }
            $declarations .= $property . ':' . $value . ';';
        }

        return ['html' => $html, 'themeStylesheet' => 'body{' . $declarations . '}'];
    }

    /**
     * Report whether published pages may be indexed, from the same effective settings snapshot.
     *
     * @return  bool  True only for an explicit enabled setting.
     *
     * @since   2.0.0
     */
    public function searchIndexingEnabled(): bool
    {
        return $this->settings->current()['search_indexing_enabled'] === true;
    }
}
