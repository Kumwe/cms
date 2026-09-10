<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Presentation\Preview;

use InvalidArgumentException;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Presentation\ContentPageRenderService;
use Kumwe\App\Studio\Application\Composition\StudioCompositionThemeMismatch;
use Kumwe\App\Studio\Application\Composition\StudioPublishedTheme;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBindingResolver;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBindingValues;
use Kumwe\App\Studio\Application\Preview\StudioPreviewRenderer;
use Kumwe\App\Studio\Application\Preview\StudioPreviewStylesheet;
use Kumwe\App\Studio\Application\Rendering\StudioBlockRendererRuntime;
use Kumwe\App\Studio\Application\Rendering\StudioRenderResultAdmission;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewDraft;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewIdentity;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewRenderedDocument;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewRenderRequest;
use Kumwe\Producer\Render\CompositionRenderer;
use Kumwe\Producer\Render\RenderContext;
use Kumwe\Producer\Render\RenderPolicy;
use stdClass;

/**
 * Preview renderer that uses the canonical public content template and validated site theme path.
 *
 * @since  2.0.0
 */
final readonly class CanonicalStudioPreviewRenderer implements StudioPreviewRenderer
{
    /**
     * Bind preview projection to the same page renderer publication uses.
     *
     * @param  ContentPageRenderService      $pages           Canonical content template/theme service.
     * @param  StudioBlockRendererRuntime    $blocks          Fresh live Producer renderer composition.
     * @param  StudioPreviewBindingResolver  $bindings        Host-owned Content binding evaluator.
     * @param  StudioPublishedTheme          $theme           Live trusted public-theme projection.
     * @param  string                        $siteIdentifier  Site whose canonical theme is configured.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ContentPageRenderService $pages,
        private StudioBlockRendererRuntime $blocks,
        private StudioPreviewBindingResolver $bindings,
        private StudioPublishedTheme $theme,
        private string $siteIdentifier,
    ) {
    }

    /**
     * Render the Blueprint into the canonical `page` layout with exact marker attributes.
     *
     * @param   StudioHostSessionSnapshot   $snapshot  Live trusted Studio authority.
     * @param   StudioPreviewDraft          $draft     Exact canonical unpublished Blueprint.
     * @param   StudioPreviewRenderRequest  $request   Exact render attempt and viewport.
     * @param   StudioPreviewBindingValues  $values    Authorized values for binding resolution.
     *
     * @return  StudioPreviewRenderedDocument  Complete canonical page and marker inventory.
     *
     * @throws  InvalidArgumentException  When the configured site does not own this session.
     * @throws  StudioCompositionThemeMismatch  When the draft's immutable theme lock is no longer live.
     *
     * @since   2.0.0
     */
    public function render(
        StudioHostSessionSnapshot $snapshot,
        StudioPreviewDraft $draft,
        StudioPreviewRenderRequest $request,
        StudioPreviewBindingValues $values,
    ): StudioPreviewRenderedDocument {
        if (!hash_equals($this->siteIdentifier, $snapshot->session->siteId)) {
            throw new InvalidArgumentException('The Studio preview site does not own this renderer.');
        }
        $document = $draft->document();
        $dependencyLock = $document->dependencyLock ?? null;
        $lockedTheme = $dependencyLock instanceof stdClass ? $dependencyLock->theme ?? null : null;
        $currentTheme = $this->theme->reference(SiteContext::fromString($snapshot->session->siteId));
        if (!$currentTheme->matches($lockedTheme)) {
            throw new StudioCompositionThemeMismatch();
        }
        $identity = StudioPreviewIdentity::forDraft($document);
        $labelReference = $document->label ?? null;
        $label = $labelReference instanceof \stdClass ? $labelReference->defaultMessage ?? null : null;
        $title = is_string($label) && $label !== '' ? $label : $draft->artifactId();
        $viewport = in_array($request->viewport, ['compact', 'medium', 'expanded'], true)
            ? $request->viewport
            : 'expanded';
        $result = (new CompositionRenderer($this->blocks->registry($viewport)))->renderDocument(
            $document,
            new RenderContext(
                resolveBinding: fn (stdClass $node, string $port) => $this->bindings->resolve(
                    $node,
                    $port,
                    $values,
                ),
                previewMarkerMap: $identity['markerMap'],
                policy: RenderPolicy::RequireRegistered,
            ),
        );
        StudioRenderResultAdmission::assertSupported($result);
        $path = '/administrator/studio/preview';
        $page = $this->pages->renderPreview(
            'page',
            [
                'title' => $title,
                'data' => [],
                'body_html' => $result->html,
            ],
            $path,
            $path,
            null,
            'core.administrator.content-editor',
            StudioPreviewStylesheet::HREF_PLACEHOLDER,
        );

        return new StudioPreviewRenderedDocument(
            $page['html'],
            $identity['markers'],
            $identity['markerMap'],
            [],
            $result->css . "\n" . $page['themeStylesheet'],
        );
    }
}
