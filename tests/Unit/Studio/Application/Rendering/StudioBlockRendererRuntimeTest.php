<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Rendering;

use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Runtime\TrustEnforcingStudioPreviewBlockRenderer;
use Kumwe\App\Studio\Application\Rendering\FragmentStudioPreviewBlockRenderer;
use Kumwe\App\Studio\Application\Rendering\StudioBlockRendererRuntime;
use Kumwe\App\Studio\Application\Rendering\StudioContentFieldBlockRenderer;
use Kumwe\App\Studio\Application\Rendering\StudioRenderResultAdmission;
use Kumwe\App\Extension\Contribution\StudioPreviewRendererContribution;
use Kumwe\Extension\Spi\Contribution\CanonicalCompositionDocument;
use Kumwe\Extension\Spi\Contribution\CanonicalCompositionKind;
use Kumwe\Extension\Spi\Contribution\CompositionHostBinding;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBindingResult;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlock;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlockFragment;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlockRenderer;
use Kumwe\Producer\Canonical\CanonicalJson;
use Kumwe\Producer\Render\BindingResolution;
use Kumwe\Producer\Render\BlockRenderer;
use Kumwe\Producer\Render\BlockCoordinate;
use Kumwe\Producer\Render\CompositionRenderer;
use Kumwe\Producer\Render\Enhancement;
use Kumwe\Producer\Render\RenderContext;
use Kumwe\Producer\Render\RenderPolicy;
use Kumwe\Producer\Render\RenderException;
use Kumwe\Producer\Render\RenderResult;
use Kumwe\Producer\Render\RenderState;
use Kumwe\Producer\Schema\StudioContractResources;
use Kumwe\App\Tests\Support\TrustFencedStudioPreviewRenderers;
use Kumwe\Extension\Spi\Contribution\ContributionDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Closure;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use stdClass;

#[CoversClass(StudioBlockRendererRuntime::class)]
#[CoversClass(StudioContentFieldBlockRenderer::class)]
#[CoversClass(StudioRenderResultAdmission::class)]
#[UsesClass(ExtensionContributionRegistrySet::class)]
#[UsesClass(TrustEnforcingStudioPreviewBlockRenderer::class)]
#[UsesClass(TrustStore::class)]
/**
 * Proves registry composition binds core coordinates directly and fences extension implementations.
 *
 * @since  2.0.0
 */
final class StudioBlockRendererRuntimeTest extends TestCase
{
    use TrustFencedStudioPreviewRenderers;

    /**
     * Prove core coordinates resolve directly and the full Producer output stays escaped and complete.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testItBindsCoreCoordinatesDirectlyAndReturnsCompleteProducerOutput(): void
    {
        $registries = new ExtensionContributionRegistrySet();
        $runtime = new StudioBlockRendererRuntime($registries, new StudioContentFieldBlockRenderer());
        $registry = $runtime->registry();
        self::assertTrue($registry->supports(new BlockCoordinate(
            'studio.core/section',
            '1.0.0',
            'layout-section-r1',
        )));
        self::assertTrue($registry->supports(new BlockCoordinate(
            'core/field-text',
            '1.0.0',
            'core-block-r1',
        )));

        $document = (object) [
            'dependencyLock' => (object) ['blocks' => [
                (object) [
                    'type' => 'studio.core/section',
                    'version' => '1.0.0',
                    'revision' => 'layout-section-r1',
                ],
                (object) [
                    'type' => 'core/field-text',
                    'version' => '1.0.0',
                    'revision' => 'core-block-r1',
                ],
            ]],
            'roots' => [(object) [
                'id' => 'root',
                'type' => 'studio.core/section',
                'version' => '1.0.0',
                'properties' => new stdClass(),
                'bindings' => new stdClass(),
                'slots' => (object) ['content' => [(object) [
                    'id' => 'field',
                    'type' => 'core/field-text',
                    'version' => '1.0.0',
                    'properties' => new stdClass(),
                    'bindings' => new stdClass(),
                    'slots' => new stdClass(),
                ]]],
            ]],
        ];
        $result = (new CompositionRenderer($registry))->renderDocument(
            $document,
            new RenderContext(
                resolveBinding: static fn (stdClass $node, string $port): BindingResolution =>
                    $node->id === 'field' && $port === 'value'
                        ? BindingResolution::available('<Current>')
                        : BindingResolution::unavailable(),
                policy: RenderPolicy::RequireRegistered,
            ),
        );

        self::assertStringContainsString('data-studio-block="section"', $result->html);
        self::assertStringContainsString('class="studio-preview-section"', $result->html);
        self::assertStringContainsString('&lt;Current&gt;', $result->html);
        self::assertStringNotContainsString('<Current>', $result->html);
        self::assertNotSame('', $result->css);
        self::assertSame([], $result->enhancements);
    }

    /**
     * Prove every registry decision re-reads live owner authority instead of reusing a snapshot.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEachRegistryReflectsCurrentOwnerAuthorityWithoutSnapshotReuse(): void
    {
        $registries = new ExtensionContributionRegistrySet();
        $runtime = new StudioBlockRendererRuntime($registries, new StudioContentFieldBlockRenderer());
        $coordinate = new BlockCoordinate('core/field-text', '1.0.0', 'core-block-r1');

        self::assertTrue($runtime->registry()->supports($coordinate));
        $registries->canonicalCompositionDocuments()->remove(ContributionOwner::core());
        self::assertFalse($runtime->registry()->supports($coordinate));
    }

    /**
     * Prove a hidden binding suppresses the wrapper without leaking the bound value into markup.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testHiddenBindingIsRetainedOnlyAsProducerWrapperState(): void
    {
        $runtime = new StudioBlockRendererRuntime(
            new ExtensionContributionRegistrySet(),
            new StudioContentFieldBlockRenderer(),
        );
        $document = (object) [
            'dependencyLock' => (object) ['blocks' => [(object) [
                'type' => 'core/field-text',
                'version' => '1.0.0',
                'revision' => 'core-block-r1',
            ]]],
            'roots' => [(object) [
                'id' => 'hidden-field',
                'type' => 'core/field-text',
                'version' => '1.0.0',
                'properties' => new stdClass(),
                'bindings' => new stdClass(),
                'slots' => new stdClass(),
            ]],
        ];
        $result = (new CompositionRenderer($runtime->registry()))->renderDocument(
            $document,
            new RenderContext(
                resolveBinding: static fn (): BindingResolution => BindingResolution::hidden(),
                policy: RenderPolicy::RequireRegistered,
            ),
        );

        self::assertStringContainsString('data-studio-node="hidden-field"', $result->html);
        self::assertStringContainsString(' hidden>', $result->html);
        self::assertStringNotContainsString('hidden-field</', $result->html);
    }

    /**
     * Prove the App admits only the enhancement families the pinned Studio runtime publishes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAppAdmitsPublishedEnhancementFamiliesAndRefusesTheRest(): void
    {
        StudioRenderResultAdmission::assertSupported(new RenderResult(
            '<div>Safe baseline</div>',
            '[data-studio-block]{display:block}',
            [new Enhancement('tabs', 'node-one', 's6e6f64652d6f6e65')],
        ));

        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('The pinned Studio enhancement runtime does not implement motion.');
        StudioRenderResultAdmission::assertSupported(new RenderResult(
            '<div>Safe baseline</div>',
            '[data-studio-block]{display:block}',
            [new Enhancement('motion', 'node-one', 's6e6f64652d6f6e65')],
        ));
    }

    /**
     * Prove a trusted SDK implementation resolves at its exact coordinate through the fragment adapter.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTrustedSdkImplementationProjectsThroughTheFragmentAdapter(): void
    {
        [$registries, $coordinate] = self::extensionRuntime();
        $runtime = new StudioBlockRendererRuntime($registries, new StudioContentFieldBlockRenderer());

        self::assertTrue($runtime->registry()->supports($coordinate));
        self::assertInstanceOf(
            FragmentStudioPreviewBlockRenderer::class,
            $runtime->registry('compact')->rendererFor($coordinate),
        );
    }

    /**
     * Prove an implementation outside the frozen SDK fragment SPI cannot even register.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAProducerEngineImplementationCannotRegisterAsAPreviewRenderer(): void
    {
        $producerRenderer = new class implements BlockRenderer {
            /**
             * Emit fixed markup so registration alone decides whether this executes.
             *
             * @param   stdClass     $node   The decoded Blueprint node to render.
             * @param   string       $scope  The node's CSS-safe scope token.
             * @param   RenderState  $state  Per-render accumulation and engine services.
             *
             * @return  string  Fixed inner markup.
             *
             * @since   2.0.0
             */
            public function render(stdClass $node, string $scope, RenderState $state): string
            {
                unset($node, $scope, $state);

                return '<p>Exact extension renderer</p>';
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires an implementation of');
        self::extensionRuntime($producerRenderer);
    }

    /**
     * Prove an extension executable outside the live trust fence refuses the decision before it can render.
     *
     * The registrar always installs `TrustEnforcingStudioPreviewBlockRenderer`, so a bare SDK
     * implementation under an extension owner can only mean the fence was bypassed. The runtime must
     * not skip such an entry quietly, and must not consult it: the whole registry decision fails and the
     * implementation is never invoked.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnfencedExtensionImplementationIsRefusedBeforeItCanRender(): void
    {
        $unfenced = new class implements StudioPreviewBlockRenderer {
            /**
             * Number of times the host asked this implementation to render.
             *
             * @var    int
             * @since  2.0.0
             */
            public int $renders = 0;

            /**
             * Count the invocation so the test can prove the runtime never reached it.
             *
             * @param   StudioPreviewBlock          $block     Immutable copied contributed grid input.
             * @param   StudioPreviewBindingResult  $binding   Authorized binding projection.
             * @param   string                      $viewport  Active semantic viewport.
             *
             * @return  StudioPreviewBlockFragment  Constant fixture fragment.
             *
             * @since   2.0.0
             */
            public function render(
                StudioPreviewBlock $block,
                StudioPreviewBindingResult $binding,
                string $viewport,
            ): StudioPreviewBlockFragment {
                unset($block, $binding, $viewport);
                $this->renders++;

                return new StudioPreviewBlockFragment('div', 'acme-shop-grid', '');
            }
        };
        [$registries, $coordinate] = self::extensionRuntime($unfenced, fenced: false);
        $runtime = new StudioBlockRendererRuntime($registries, new StudioContentFieldBlockRenderer());

        try {
            $runtime->registry();
            self::fail('An extension implementation outside the trust fence must refuse the registry decision.');
        } catch (RenderException $refusal) {
            self::assertSame(
                'An extension preview renderer is not fenced by live host trust.',
                $refusal->getMessage(),
            );
        }
        try {
            $runtime->registry('compact')->supports($coordinate);
            self::fail('A viewport-specific registry decision must refuse the same unfenced implementation.');
        } catch (RenderException) {
        }

        self::assertSame(0, $unfenced->renders);
    }

    /**
     * Prove the fenced implementation is refused only for extension owners, never for core-owned entries.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCoreOwnedEntriesKeepTheirDirectPathWithoutTheExtensionFence(): void
    {
        $registries = new ExtensionContributionRegistrySet();
        $runtime = new StudioBlockRendererRuntime($registries, new StudioContentFieldBlockRenderer());
        $registry = $runtime->registry();

        self::assertTrue($registry->supports(new BlockCoordinate('core/field-text', '1.0.0', 'core-block-r1')));
        self::assertTrue($registry->supports(new BlockCoordinate(
            'studio.core/section',
            '1.0.0',
            'layout-section-r1',
        )));
        self::assertNotInstanceOf(
            FragmentStudioPreviewBlockRenderer::class,
            $registry->rendererFor(new BlockCoordinate('core/field-text', '1.0.0', 'core-block-r1')),
        );
    }

    /**
     * Prove one ambiguous trusted extension coordinate refuses the whole registry decision.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDuplicateTrustedExtensionCoordinateFailsClosed(): void
    {
        [$registries] = self::extensionRuntime();
        $surface = $registries->canonicalCompositionDocuments();
        $entriesProperty = new ReflectionProperty($surface, 'entries');
        $entries = $entriesProperty->getValue($surface);
        self::assertIsArray($entries);
        $first = reset($entries);
        self::assertIsArray($first);
        $entries['hostile duplicate key'] = $first;
        $entriesProperty->setValue($surface, $entries);

        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('A trusted extension block coordinate is ambiguous.');
        (new StudioBlockRendererRuntime(
            $registries,
            new StudioContentFieldBlockRenderer(),
        ))->registry();
    }

    /**
     * Prove a canonical block whose signed host binding was withdrawn cannot register its coordinate.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACanonicalBlockWithoutItsSignedHostBindingCannotRegister(): void
    {
        [$registries, $coordinate] = self::extensionRuntime();
        $registries->compositionHostBindings()->remove(ContributionOwner::extension('acme/shop'));
        $runtime = new StudioBlockRendererRuntime($registries, new StudioContentFieldBlockRenderer());

        self::assertFalse($runtime->registry()->supports($coordinate));
    }

    /**
     * Prove forged canonical state without exact executable coordinates is skipped, never registered.
     *
     * A genuine canonical document cannot lose its coordinate members, so corrupted registry state is
     * reproduced by initializing the readonly value without its constructor. One forged document drops
     * the revision member entirely and the other carries a type outside the Producer coordinate
     * grammar; both must leave the registry decision without any registered coordinate.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testForgedCanonicalStateWithoutExactCoordinatesCannotRegister(): void
    {
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $missingOwner = ContributionOwner::extension('acme/forge');
        $registries->canonicalCompositionDocuments()->register($missingOwner, self::forgedDocument(
            '{"kind":"block-definition","type":"acme.forge/block","version":"1.0.0"}',
            'acme.forge/block',
        ));
        $registries->compositionHostBindings()->register($missingOwner, new CompositionHostBinding(
            CanonicalCompositionKind::BlockDefinition,
            'acme.forge/block',
            'acme.forge/renderer',
        ));
        $mangledOwner = ContributionOwner::extension('acme/mangle');
        $registries->canonicalCompositionDocuments()->register($mangledOwner, self::forgedDocument(
            '{"kind":"block-definition","revision":"forged-r1","type":"Not A Qualified Name",'
                . '"version":"1.0.0"}',
            'acme.mangle/block',
        ));
        $registries->compositionHostBindings()->register($mangledOwner, new CompositionHostBinding(
            CanonicalCompositionKind::BlockDefinition,
            'acme.mangle/block',
            'acme.mangle/renderer',
        ));
        $registry = (new StudioBlockRendererRuntime(
            $registries,
            new StudioContentFieldBlockRenderer(),
        ))->registry();

        self::assertFalse($registry->supports(new BlockCoordinate('acme.forge/block', '1.0.0', 'forged-r1')));
        self::assertFalse($registry->supports(new BlockCoordinate('acme.mangle/block', '1.0.0', 'forged-r1')));
    }

    /**
     * Prove an implementation declared by a foreign definition shape never reaches the registry.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAForeignDefinitionShapeCannotProjectATrustedPreviewImplementation(): void
    {
        [$registries, $coordinate, $renderer] = self::extensionRuntime();
        $owner = ContributionOwner::extension('acme/shop');
        $registries->studioPreviewRenderers()->remove($owner);
        $foreign = new class implements ContributionDefinition {
            /**
             * Claim an identifier inside the owner namespace without being a renderer contribution.
             *
             * @return  string  Owner-scoped identifier.
             *
             * @since   2.0.0
             */
            public function identifier(): string
            {
                return 'acme.shop/foreign-preview';
            }

            /**
             * Export the empty declaration body.
             *
             * @return  array<string, mixed>  Empty manifest structure.
             *
             * @since   2.0.0
             */
            public function toArray(): array
            {
                return [];
            }
        };
        $registries->studioPreviewRenderers()->register($owner, $foreign, $renderer);
        $runtime = new StudioBlockRendererRuntime($registries, new StudioContentFieldBlockRenderer());

        self::assertFalse($runtime->registry()->supports($coordinate));
    }

    /**
     * Prove the admission policy is a closed static surface whose constructor holds no state.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheAdmissionPolicyIsAClosedStaticSurface(): void
    {
        $constructor = new ReflectionMethod(StudioRenderResultAdmission::class, '__construct');
        self::assertTrue($constructor->isPrivate());

        $instance = (new ReflectionClass(StudioRenderResultAdmission::class))->newInstanceWithoutConstructor();
        $constructor->invoke($instance);
        self::assertSame([], get_object_vars($instance));
    }

    /**
     * Forge one canonical composition document value outside its validating constructor.
     *
     * @param   string  $canonical  Raw canonical JSON bytes the forged value retains.
     * @param   string  $identity   Identity member the forged value reports.
     *
     * @return  CanonicalCompositionDocument  Forged block-definition document value.
     *
     * @since   2.0.0
     */
    private static function forgedDocument(string $canonical, string $identity): CanonicalCompositionDocument
    {
        $forged = (new ReflectionClass(CanonicalCompositionDocument::class))->newInstanceWithoutConstructor();
        Closure::bind(function (string $bytes, string $id): void {
            $this->kind = CanonicalCompositionKind::BlockDefinition;
            $this->canonical = $bytes;
            $this->identity = $id;
        }, $forged, CanonicalCompositionDocument::class)($canonical, $identity);

        return $forged;
    }

    /**
     * Register one signed extension block with a contributed executable for its preview renderer.
     *
     * An SDK implementation is registered behind the exact production trust fence unless a test asks
     * for the bare implementation to prove the runtime refuses it; any other object is registered as-is
     * so registration itself can be proven to refuse it.
     *
     * @param   ?object  $renderer  Implementation to register; defaults to a bounded SDK fixture.
     * @param   bool     $fenced    Whether an SDK implementation is wrapped in live trust enforcement.
     *
     * @return  array{ExtensionContributionRegistrySet, BlockCoordinate, object}  Registries, the
     *          exact block coordinate, and the registered implementation.
     *
     * @since   2.0.0
     */
    private static function extensionRuntime(?object $renderer = null, bool $fenced = true): array
    {
        $document = json_decode(
            StudioContractResources::testkitBytes('fixtures/block.grid.example.json'),
            false,
            64,
            JSON_THROW_ON_ERROR,
        );
        self::assertInstanceOf(stdClass::class, $document);
        $document->type = 'acme.shop/grid';
        $document->owner = (object) ['id' => 'acme.shop/blocks', 'version' => '1.0.0'];
        $document->rendererRequirements = [
            (object) [
                'surface' => 'web',
                'capability' => 'acme.shop/web-grid',
                'versions' => '^1.0.0',
            ],
            (object) [
                'surface' => 'preview',
                'capability' => 'acme.shop/preview-grid',
                'versions' => '^1.0.0',
            ],
        ];
        $canonical = new CanonicalCompositionDocument(
            CanonicalCompositionKind::BlockDefinition,
            CanonicalJson::stringify($document),
        );
        $binding = new CompositionHostBinding(
            CanonicalCompositionKind::BlockDefinition,
            'acme.shop/grid',
            'acme.shop/grid-preview',
        );
        $owner = ContributionOwner::extension('acme/shop');
        $renderer ??= new class implements StudioPreviewBlockRenderer {
            /**
             * Emit one bounded fixture fragment regardless of block, binding or viewport.
             *
             * @param   StudioPreviewBlock          $block     Immutable copied contributed grid input.
             * @param   StudioPreviewBindingResult  $binding   Authorized binding projection.
             * @param   string                      $viewport  Active semantic viewport.
             *
             * @return  StudioPreviewBlockFragment  Constant fixture fragment.
             *
             * @since   2.0.0
             */
            public function render(
                StudioPreviewBlock $block,
                StudioPreviewBindingResult $binding,
                string $viewport,
            ): StudioPreviewBlockFragment {
                unset($block, $binding, $viewport);

                return new StudioPreviewBlockFragment('div', 'acme-shop-grid', '');
            }
        };
        if ($fenced && $renderer instanceof StudioPreviewBlockRenderer) {
            $renderer = self::trustFencedPreviewRenderer($renderer, 'acme/shop');
        }
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $registries->canonicalCompositionDocuments()->register($owner, $canonical);
        $registries->compositionHostBindings()->register($owner, $binding);
        $registries->studioPreviewRenderers()->register(
            $owner,
            new StudioPreviewRendererContribution($owner, '1.0.0', $canonical, $binding),
            $renderer,
        );

        return [
            $registries,
            new BlockCoordinate('acme.shop/grid', '1.0.0', 'grid-block-r1'),
            $renderer,
        ];
    }
}
