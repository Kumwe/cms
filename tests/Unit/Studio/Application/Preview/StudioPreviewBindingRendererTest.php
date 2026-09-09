<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Preview;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\SiteContext;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Runtime\ActiveExtensionSet;
use Kumwe\App\Presentation\ContentPageRenderService;
use Kumwe\App\Presentation\SiteRenderer;
use Kumwe\App\Presentation\Twig\SiteTwigEnvironment;
use Kumwe\App\Site\Application\SiteSettings;
use Kumwe\App\Studio\Application\Composition\StudioBuiltInThemeRelease;
use Kumwe\App\Studio\Application\Composition\StudioCompositionThemeMismatch;
use Kumwe\App\Studio\Application\Composition\StudioPublishedTheme;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBindingResolver;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBindingValues;
use Kumwe\App\Studio\Application\Preview\StudioPreviewStylesheet;
use Kumwe\App\Studio\Application\Rendering\StudioBlockRendererRuntime;
use Kumwe\App\Studio\Application\Rendering\StudioContentFieldBlockRenderer;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewDraft;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewIdentity;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewRenderRequest;
use Kumwe\App\Studio\Presentation\Preview\CanonicalStudioPreviewRenderer;
use Kumwe\Producer\Render\CompositionRenderer;
use Kumwe\Producer\Render\RenderContext;
use Kumwe\Producer\Render\RenderException;
use Kumwe\Producer\Render\RenderPolicy;
use Kumwe\Producer\Render\RenderState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Twig\Loader\ArrayLoader;

#[CoversClass(CanonicalStudioPreviewRenderer::class)]
#[CoversClass(StudioPreviewBindingResolver::class)]
#[CoversClass(StudioPreviewBindingValues::class)]
#[CoversClass(StudioPreviewStylesheet::class)]
#[UsesClass(StudioBlockRendererRuntime::class)]
#[UsesClass(StudioContentFieldBlockRenderer::class)]
/**
 * Proves preview binding resolution, canonical preview output and stylesheet activation fail closed.
 *
 * @since  2.0.0
 */
final class StudioPreviewBindingRendererTest extends TestCase
{
    /**
     * Provide each core field block type with a raw bound value and its expected safe rendering.
     *
     * @return iterable<string, array{string, mixed, string}>
     *
     * @since   2.0.0
     */
    public static function fieldBlocks(): iterable
    {
        yield 'text' => ['core/field-text', '<script>alert(1)</script>', '&lt;script&gt;alert(1)&lt;/script&gt;'];
        yield 'rich text' => [
            'core/field-rich-text',
            (object) ['type' => 'doc', 'content' => [(object) ['type' => 'text', 'text' => 'Rich value']]],
            'Rich value',
        ];
        yield 'integer' => ['core/field-integer', 42, '42'];
        yield 'decimal' => ['core/field-decimal', '19.9500', '19.9500'];
        yield 'boolean' => ['core/field-boolean', false, 'false'];
        yield 'date' => ['core/field-date', '2026-08-24', '2026-08-24'];
        yield 'date time' => ['core/field-date-time', '2026-08-24T12:30:00Z', '2026-08-24T12:30:00Z'];
        yield 'media' => [
            'core/field-media',
            (object) ['id' => 'media:hero', 'url' => 'javascript:alert(1)'],
            'media:hero',
        ];
        yield 'resource' => ['core/field-resource', (object) ['label' => 'Referenced page'], 'Referenced page'];
    }

    #[DataProvider('fieldBlocks')]
    /**
     * Prove each core field block renders only its escaped bound value, with no script, URL or style leak.
     *
     * @param   string  $type      Core field block type under test.
     * @param   mixed   $value     Raw bound entry-field value.
     * @param   string  $expected  Exact safe fragment the rendered output must contain.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCoreFieldBlocksResolveOnlyAuthorizedValues(
        string $type,
        mixed $value,
        string $expected,
    ): void {
        $document = self::document($type, (object) [
            'source' => (object) ['kind' => 'entry-field', 'fieldPath' => ['field']],
            'transforms' => [],
            'onNull' => 'empty',
            'onError' => 'error',
        ]);
        $html = self::render(
            $document,
            new StudioPreviewBindingValues((object) ['field' => $value], new stdClass()),
        );

        self::assertStringContainsString($expected, $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString(' style=', $html);
    }

    /**
     * Prove an unregistered binding source applies the declared fallback and never leaks other values.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnregisteredBindingOperationsApplyTheDeclaredFallback(): void
    {
        $document = self::document('core/field-text', (object) [
            'source' => (object) ['kind' => 'query-reference'],
            'transforms' => [],
            'onNull' => 'empty',
            'onError' => 'fallback',
            'fallback' => 'Safe fallback',
        ]);
        $html = self::render(
            $document,
            new StudioPreviewBindingValues((object) ['secret' => 'must-not-render'], new stdClass()),
        );

        self::assertStringContainsString('Safe fallback', $html);
        self::assertStringNotContainsString('must-not-render', $html);
    }

    /**
     * Prove the canonical preview emits Producer HTML, markers and theme CSS through one linked
     * stylesheet placeholder, with no inline style element in the page.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCanonicalPreviewConsumesProducerHtmlCssAndMarkersThroughOneStylesheet(): void
    {
        [$renderer, $theme] = $this->canonicalRuntime();
        $document = self::lockTheme(self::document('core/field-text', (object) [
            'source' => (object) ['kind' => 'static-value', 'value' => 'Rendered value'],
            'transforms' => [],
            'onNull' => 'empty',
            'onError' => 'error',
        ]), $theme);
        $draft = new StudioPreviewDraft('default', $document);
        $rendered = $renderer->render(
            self::snapshot($draft),
            $draft,
            new StudioPreviewRenderRequest(
                $draft->artifactId(),
                $draft->digest(),
                $draft->revision(),
                'requests/canonical-renderer',
                'expanded',
            ),
            new StudioPreviewBindingValues(new stdClass(), new stdClass()),
        );

        self::assertStringContainsString('Rendered value', $rendered->html);
        self::assertStringContainsString('data-studio-block="field-text"', $rendered->html);
        self::assertStringContainsString('|0|core.administrator.content-editor', $rendered->html);
        self::assertStringContainsString(
            'href="' . StudioPreviewStylesheet::HREF_PLACEHOLDER . '" data-studio-composition',
            $rendered->html,
        );
        self::assertStringContainsString('[data-studio-block]', $rendered->stylesheet ?? '');
        self::assertStringContainsString('--site-accent:#0c9189;', $rendered->stylesheet ?? '');
        self::assertStringNotContainsString('<style', $rendered->html);
        self::assertCount(1, $rendered->markers);
        self::assertSame('field-node', $rendered->markerMap[$rendered->markers[0]]);
    }

    /**
     * Prove placeholder activation substitutes only a same-origin URL and strips nothing when inactive.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStylesheetActivationRequiresExactSameOriginUrlAndInventory(): void
    {
        $placeholder = sprintf('href="%s"', StudioPreviewStylesheet::HREF_PLACEHOLDER);
        $html = '<link ' . $placeholder . ' data-studio-composition>';
        $href = '/administrator/studio/preview/styles.css?grant=preview-1';

        self::assertSame(
            '<link href="' . $href . '" data-studio-composition>',
            StudioPreviewStylesheet::activate($html, $href, true),
        );
        self::assertSame('<main>Preview</main>', StudioPreviewStylesheet::activate(
            '<main>Preview</main>',
            $href,
            false,
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The Studio preview stylesheet URL is invalid.');
        StudioPreviewStylesheet::activate($html, 'https://example.test/styles.css', true);
    }

    /**
     * Prove rendering refuses a draft whose locked theme no longer matches the live active scheme.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCanonicalRendererRefusesAStalePublishedThemeLock(): void
    {
        $settingsDocument = self::settingsDocument();
        $settings = $this->createStub(SiteSettings::class);
        $settings->method('current')->willReturnCallback(
            static function () use (&$settingsDocument): array {
                return $settingsDocument;
            },
        );
        [$renderer, $theme] = $this->canonicalRuntime($settings);
        $document = self::lockTheme(self::document('core/field-text', (object) [
            'source' => (object) ['kind' => 'static-value', 'value' => 'value'],
            'transforms' => [],
            'onNull' => 'empty',
            'onError' => 'error',
        ]), $theme);
        $draft = new StudioPreviewDraft('default', $document);
        $settingsDocument['presentation']['active_scheme'] = 'ocean';

        $this->expectException(StudioCompositionThemeMismatch::class);
        $renderer->render(
            self::snapshot($draft),
            $draft,
            new StudioPreviewRenderRequest(
                $draft->artifactId(),
                $draft->digest(),
                $draft->revision(),
                'requests/stale-theme-renderer',
                'expanded',
            ),
            new StudioPreviewBindingValues(new stdClass(), new stdClass()),
        );
    }

    /**
     * Prove a draft locked to an unregistered block revision fails closed instead of rendering anything.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCanonicalRendererRefusesAnUnregisteredRevisionWithoutDraftFallback(): void
    {
        [$renderer, $theme] = $this->canonicalRuntime();
        $document = self::lockTheme(self::document('core/field-text', (object) [
            'source' => (object) ['kind' => 'static-value', 'value' => 'must not render'],
            'transforms' => [],
            'onNull' => 'empty',
            'onError' => 'error',
        ]), $theme);
        $document->dependencyLock->blocks[0]->revision = 'unregistered-revision';
        $draft = new StudioPreviewDraft('default', $document);

        $this->expectException(RenderException::class);
        $renderer->render(
            self::snapshot($draft),
            $draft,
            new StudioPreviewRenderRequest(
                $draft->artifactId(),
                $draft->digest(),
                $draft->revision(),
                'requests/unregistered-renderer',
                'expanded',
            ),
            new StudioPreviewBindingValues(new stdClass(), new stdClass()),
        );
    }

    /**
     * Prove preview rendering fails closed when the Producer engine records an enhancement request,
     * because the App has no canonical enhancement runtime yet. Motion intent on a node's design
     * properties is the engine-owned path that requests one; the frozen SDK fragment SPI gives
     * contributed renderers no way to request enhancements at all.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCanonicalPreviewRefusesProducerEnhancementsUntilOneCanonicalRuntimeExists(): void
    {
        [$renderer, $theme] = $this->canonicalRuntime();
        $document = self::lockTheme(self::document('core/field-text', (object) [
            'source' => (object) ['kind' => 'static-value', 'value' => 'Rendered value'],
            'transforms' => [],
            'onNull' => 'empty',
            'onError' => 'error',
        ]), $theme);
        $document->roots[0]->properties = (object) ['design' => (object) ['animation' => 'fade']];
        $draft = new StudioPreviewDraft('default', $document);

        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('The pinned Studio enhancement runtime does not implement motion.');
        $renderer->render(
            self::snapshot($draft),
            $draft,
            new StudioPreviewRenderRequest(
                $draft->artifactId(),
                $draft->digest(),
                $draft->revision(),
                'requests/enhancing-renderer',
                'expanded',
            ),
            new StudioPreviewBindingValues(new stdClass(), new stdClass()),
        );
    }

    /**
     * Prove only the canonical value port with a structured binding object is resolvable at all.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOnlyTheValuePortWithAStructuredBindingResolves(): void
    {
        $resolver = new StudioPreviewBindingResolver();
        $values = new StudioPreviewBindingValues((object) ['field' => 'secret'], new stdClass());
        $node = (object) ['bindings' => (object) ['value' => (object) [
            'source' => (object) ['kind' => 'entry-field', 'fieldPath' => ['field']],
            'transforms' => [],
        ]]];

        $foreignPort = $resolver->resolve($node, 'href', $values);
        self::assertFalse($foreignPort->isAvailable());
        self::assertFalse($foreignPort->isHidden());

        $unstructured = $resolver->resolve(
            (object) ['bindings' => (object) ['value' => 'not-a-binding-object']],
            'value',
            $values,
        );
        self::assertFalse($unstructured->isAvailable());
        self::assertFalse($unstructured->isHidden());
    }

    /**
     * Prove an unresolvable source with the explicit hide policy hides the node without a value leak.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnresolvableSourceWithHidePolicyHidesTheNode(): void
    {
        $resolver = new StudioPreviewBindingResolver();
        $node = (object) ['bindings' => (object) ['value' => (object) [
            'source' => (object) ['kind' => 'query-reference'],
            'transforms' => [],
            'onError' => 'hide',
        ]]];

        self::assertTrue($resolver->resolve(
            $node,
            'value',
            new StudioPreviewBindingValues(new stdClass(), new stdClass()),
        )->isHidden());
    }

    /**
     * Prove each declared null policy resolves exactly as declared and defaults to unresolved.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEachDeclaredNullPolicyResolvesExactlyAsDeclared(): void
    {
        $resolver = new StudioPreviewBindingResolver();
        $values = new StudioPreviewBindingValues(new stdClass(), new stdClass());
        $resolve = static function (array $policy) use ($resolver, $values) {
            $binding = (object) array_merge([
                'source' => (object) ['kind' => 'static-value', 'value' => null],
                'transforms' => [],
            ], $policy);

            return $resolver->resolve((object) ['bindings' => (object) ['value' => $binding]], 'value', $values);
        };

        $empty = $resolve(['onNull' => 'empty']);
        self::assertTrue($empty->isAvailable());
        self::assertSame('', $empty->value());

        $fallback = $resolve(['onNull' => 'fallback', 'fallback' => 'Declared fallback']);
        self::assertTrue($fallback->isAvailable());
        self::assertSame('Declared fallback', $fallback->value());

        $undeclaredFallback = $resolve(['onNull' => 'fallback']);
        self::assertFalse($undeclaredFallback->isAvailable());
        self::assertFalse($undeclaredFallback->isHidden());

        self::assertTrue($resolve(['onNull' => 'hide'])->isHidden());

        $error = $resolve(['onNull' => 'error']);
        self::assertFalse($error->isAvailable());
        self::assertFalse($error->isHidden());
    }

    /**
     * Prove a declared viewport outside the closed semantic set renders as the expanded default.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAViewportOutsideTheClosedSetRendersAsTheExpandedDefault(): void
    {
        [$renderer, $theme] = $this->canonicalRuntime();
        $document = self::lockTheme(self::document('core/field-text', (object) [
            'source' => (object) ['kind' => 'static-value', 'value' => 'Viewport value'],
            'transforms' => [],
            'onNull' => 'empty',
            'onError' => 'error',
        ]), $theme);
        $draft = new StudioPreviewDraft('default', $document);
        $rendered = $renderer->render(
            self::snapshot($draft),
            $draft,
            new StudioPreviewRenderRequest(
                $draft->artifactId(),
                $draft->digest(),
                $draft->revision(),
                'requests/foreign-viewport-renderer',
                'desktop',
            ),
            new StudioPreviewBindingValues(new stdClass(), new stdClass()),
        );

        self::assertStringContainsString('Viewport value', $rendered->html);
        self::assertCount(1, $rendered->markers);
    }

    /**
     * Build a one-node Blueprint document binding the value port of a single field block.
     *
     * @param   string    $type     Field block type placed at the document root.
     * @param   stdClass  $binding  Binding declaration attached to the node's value port.
     *
     * @return  stdClass  Locked single-node Blueprint document.
     *
     * @since   2.0.0
     */
    private static function document(string $type, stdClass $binding): stdClass
    {
        return (object) [
            'kind' => 'blueprint',
            'id' => 'blueprint:binding-test',
            'version' => '1.0.0',
            'revision' => 'blueprint-r1',
            'dependencyLock' => (object) ['blocks' => [(object) [
                'type' => $type,
                'version' => '1.0.0',
                'revision' => 'core-block-r1',
            ]]],
            'roots' => [(object) [
                'id' => 'field-node',
                'type' => $type,
                'version' => '1.0.0',
                'properties' => new stdClass(),
                'bindings' => (object) ['value' => $binding],
                'slots' => new stdClass(),
            ]],
        ];
    }

    /**
     * Render one document through the core runtime with the preview binding resolver.
     *
     * @param   stdClass                    $document  Blueprint document to render.
     * @param   StudioPreviewBindingValues  $values    Authorized binding values offered to the resolver.
     *
     * @return  string  Rendered preview HTML.
     *
     * @since   2.0.0
     */
    private static function render(stdClass $document, StudioPreviewBindingValues $values): string
    {
        $identity = StudioPreviewIdentity::forDraft($document);
        $resolver = new StudioPreviewBindingResolver();

        return (new CompositionRenderer(self::runtime()->registry()))->renderDocument(
            $document,
            new RenderContext(
                resolveBinding: static fn (stdClass $node, string $port) => $resolver->resolve(
                    $node,
                    $port,
                    $values,
                ),
                previewMarkerMap: $identity['markerMap'],
                policy: RenderPolicy::RequireRegistered,
            ),
        )->html;
    }

    /**
     * Build a trusted blueprint-mode session snapshot bound to the draft's artifact.
     *
     * @param   StudioPreviewDraft  $draft  Draft whose artifact id the session is opened for.
     *
     * @return  StudioHostSessionSnapshot  Live session snapshot with read permission.
     *
     * @since   2.0.0
     */
    private static function snapshot(StudioPreviewDraft $draft): StudioHostSessionSnapshot
    {
        $session = new StudioHostSession(
            'contexts/canonical-renderer',
            'actor-renderer',
            'default',
            null,
            null,
            'administrator',
            hash('sha256', 'canonical-renderer-session'),
            StudioSessionMode::Blueprint,
            StudioResourceKind::Blueprint,
            $draft->artifactId(),
            'session-canonical-renderer',
        );

        return new StudioHostSessionSnapshot(
            $session,
            ['studio.permission/read'],
            $session->sessionGeneration,
            true,
            false,
            false,
        );
    }

    /**
     * Lock the document's dependency lock to the live published theme coordinate.
     *
     * @param   stdClass              $document  Blueprint document receiving the theme lock.
     * @param   StudioPublishedTheme  $theme     Live theme whose reference is locked.
     *
     * @return  stdClass  The same document with its theme lock applied.
     *
     * @since   2.0.0
     */
    private static function lockTheme(stdClass $document, StudioPublishedTheme $theme): stdClass
    {
        $document->dependencyLock->theme = $theme->reference(SiteContext::default())->document();

        return $document;
    }

    /**
     * Compose the canonical preview renderer around live theme, page rendering and block runtime parts.
     *
     * @param   ?SiteSettings                $settings  Live site settings override.
     * @param   ?StudioBlockRendererRuntime  $runtime   Block renderer runtime override.
     *
     * @return array{CanonicalStudioPreviewRenderer, StudioPublishedTheme}
     *
     * @since   2.0.0
     */
    private function canonicalRuntime(
        ?SiteSettings $settings = null,
        ?StudioBlockRendererRuntime $runtime = null,
    ): array {
        if ($settings === null) {
            $settings = $this->createStub(SiteSettings::class);
            $settings->method('current')->willReturn(self::settingsDocument());
        }
        $theme = new StudioPublishedTheme(
            $settings,
            new ActiveExtensionSet(new ExtensionContributionRegistrySet(withCore: false)),
            new StudioBuiltInThemeRelease(str_repeat('a', 64)),
        );

        return [new CanonicalStudioPreviewRenderer(
            new ContentPageRenderService(
                $settings,
                new SiteRenderer(new SiteTwigEnvironment(new ArrayLoader([
                    'page.twig' => '<!doctype html><html><head><title>{{ entry.title }}</title></head><body>'
                        . '{{ entry.body_html|raw }}|{{ presentation.css_variables|length }}|{{ surface_id }}'
                        . '|{{ presentation.theme_color }}'
                        . '</body></html>',
                ]))),
            ),
            $runtime ?? self::runtime(),
            new StudioPreviewBindingResolver(),
            $theme,
            'default',
        ), $theme];
    }

    /**
     * Build the core-only block renderer runtime over the default contribution registries.
     *
     * @return  StudioBlockRendererRuntime  Runtime resolving only core field block renderers.
     *
     * @since   2.0.0
     */
    private static function runtime(): StudioBlockRendererRuntime
    {
        return new StudioBlockRendererRuntime(
            new ExtensionContributionRegistrySet(),
            new StudioContentFieldBlockRenderer(),
        );
    }

    /**
     * Build a settings document whose default scheme pins a recognizable accent variable.
     *
     * @return array<string, mixed>
     *
     * @since   2.0.0
     */
    private static function settingsDocument(): array
    {
        $presentation = \Kumwe\App\Presentation\Application\SitePresentation::defaults();
        $presentation['schemes'][0]['colors']['accent'] = '#0c9189';

        return [
            'site_name' => 'Kumwe',
            'presentation' => $presentation,
            'search_indexing_enabled' => false,
        ];
    }
}
