<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Composition;

use DateTimeImmutable;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Content\Application\ContentModelRepository;
use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Content\Domain\ContentEntry;
use Kumwe\App\Content\Domain\ContentStatus;
use Kumwe\App\Content\Domain\ContentTypeDefinition;
use Kumwe\App\Content\Domain\JsonSchemaValidator;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Contribution\StudioPreviewRendererContribution;
use Kumwe\App\Extension\Runtime\ActiveExtensionSet;
use Kumwe\App\Extension\Runtime\TrustEnforcingStudioPreviewBlockRenderer;
use Kumwe\App\Presentation\Application\SitePresentation;
use Kumwe\App\Site\Application\SiteSettings;
use Kumwe\App\Studio\Application\Composition\CanonicalStudioPublishedContentRenderer;
use Kumwe\App\Studio\Application\Composition\StudioBuiltInThemeRelease;
use Kumwe\App\Studio\Application\Composition\StudioCompositionThemeMismatch;
use Kumwe\App\Studio\Application\Composition\StudioPublishedBlueprintMismatch;
use Kumwe\App\Studio\Application\Composition\StudioPublishedBlueprintUnavailable;
use Kumwe\App\Studio\Application\Composition\StudioPublishedCompositionGuard;
use Kumwe\App\Studio\Application\Composition\StudioPublishedModelMismatch;
use Kumwe\App\Studio\Application\Composition\StudioPublishedTheme;
use Kumwe\App\Studio\Application\Host\StudioArtifactAdmission;
use Kumwe\App\Studio\Application\Host\StudioArtifactRepository;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBindingResolver;
use Kumwe\App\Studio\Application\Preview\StudioPublishedBlockRendererUnavailable;
use Kumwe\App\Studio\Application\Projection\ContentProjectionBindingRepository;
use Kumwe\App\Studio\Application\Projection\ContentStudioProjector;
use Kumwe\App\Studio\Application\Projection\RecordAuthorizedStudioContentFieldDisclosure;
use Kumwe\App\Studio\Domain\Artifact\StoredStudioArtifact;
use Kumwe\App\Studio\Application\Rendering\StudioBlockRendererRuntime;
use Kumwe\App\Studio\Application\Rendering\StudioContentFieldBlockRenderer;
use Kumwe\Extension\Spi\Contribution\CanonicalCompositionDocument;
use Kumwe\Extension\Spi\Contribution\CanonicalCompositionKind;
use Kumwe\Extension\Spi\Contribution\CompositionHostBinding;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\Producer\Canonical\CanonicalJson;
use Kumwe\Producer\Error\HostRefusal;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBindingResult;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlock;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlockFragment;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlockRenderer;
use Kumwe\Producer\Render\BlockCoordinate;
use Kumwe\Producer\Render\BlockRenderer;
use Kumwe\Producer\Render\BlockRendererRegistry;
use Kumwe\Producer\Render\RenderResult;
use Kumwe\Producer\Render\RenderState;
use Kumwe\Producer\Schema\StudioDocumentSchemaRegistry;
use Kumwe\App\Studio\Domain\Projection\ContentBlueprintBinding;
use Kumwe\App\Tests\Support\TrustFencedStudioPreviewRenderers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use stdClass;

/**
 * Pins the exact fail-closed public Content-to-Blueprint runtime and its deliberate legacy fallback.
 *
 * @since  2.0.0
 */
#[CoversClass(CanonicalStudioPublishedContentRenderer::class)]
#[CoversClass(StudioPublishedCompositionGuard::class)]
#[CoversClass(StudioPublishedBlueprintMismatch::class)]
#[CoversClass(StudioPublishedBlueprintUnavailable::class)]
#[CoversClass(StudioPublishedModelMismatch::class)]
#[CoversClass(StudioPublishedBlockRendererUnavailable::class)]
#[UsesClass(ContentStudioProjector::class)]
#[UsesClass(StudioBlockRendererRuntime::class)]
#[UsesClass(StudioContentFieldBlockRenderer::class)]
#[UsesClass(TrustEnforcingStudioPreviewBlockRenderer::class)]
#[UsesClass(TrustStore::class)]
final class StudioPublishedContentRendererTest extends TestCase
{
    use TrustFencedStudioPreviewRenderers;

    /**
     * Stable Content type used by every exact binding fixture.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string TYPE_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026be810';

    /**
     * Stable Content entry rendered by every public fixture.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string ENTRY_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026be811';

    /**
     * A published exact Blueprint renders complete safe values without preview markers; inactive states fall back.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPublishedCompositionIsMarkerFreeWhileAbsentDraftAndRetiredBindingsUseLegacyRendering(): void
    {
        $theme = $this->theme();
        $document = $this->blueprint($theme);
        $this->guard($theme)->assertPublishable(SiteContext::default(), $document);
        self::addToAssertionCount(1);
        $admission = self::admission();
        $published = $admission->admit(SiteContext::DEFAULT, $document);
        $draft = $admission->revise($published, 'blueprint-draft', 'draft');
        $retired = $admission->revise($published, 'blueprint-retired', 'retired');

        self::assertNull($this->renderer(null, null, $theme)->render($this->record()));
        self::assertNull($this->renderer($this->binding(), $draft, $theme)->render($this->record()));
        self::assertNull($this->renderer($this->binding(), $retired, $theme)->render($this->record()));

        $result = $this->renderer($this->binding(), $published, $theme)->render($this->record());
        self::assertInstanceOf(RenderResult::class, $result);
        self::assertStringContainsString('data-studio-block="field-text"', $result->html);
        self::assertStringContainsString(
            '<p class="studio-preview-field-text" data-studio-part="value">'
            . '&lt;strong&gt;Exact &amp; safe&lt;/strong&gt;</p>',
            $result->html,
        );
        self::assertStringNotContainsString('data-studio-preview-marker', $result->html);
        self::assertNotSame('', $result->css);
        self::assertSame([], $result->enhancements);
        self::assertSame('content-type-v4', ContentStudioProjector::modelRevision(4));
    }

    /**
     * Once configured, absent, wrong-kind, and structurally incompatible artifacts fail with typed refusals.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testConfiguredArtifactAvailabilityKindAndCompatibilityFailuresAreTyped(): void
    {
        $theme = $this->theme();
        $binding = $this->binding();
        $wrongBinding = new ContentBlueprintBinding(
            SiteContext::default(),
            '018f22e2-7c8b-7ab0-8f3a-88e8026be899',
            4,
            $binding->blueprintId,
            $binding->blueprintVersion,
            null,
            1,
        );
        $this->assertThrows(
            StudioPublishedBlueprintMismatch::class,
            fn () => $this->renderer($wrongBinding, null, $theme)->render($this->record()),
        );
        $this->assertThrows(
            StudioPublishedBlueprintUnavailable::class,
            fn () => $this->renderer($binding, null, $theme)->render($this->record()),
        );

        $wrongKindDocument = (object) [
            'kind' => 'content-model',
            'id' => $binding->blueprintId,
            'revision' => 'wrong-kind-r1',
            'status' => 'published',
        ];
        $wrongKind = new StoredStudioArtifact(
            SiteContext::DEFAULT,
            $binding->blueprintId,
            $binding->blueprintVersion,
            'content-model',
            'wrong-kind-r1',
            'published',
            CanonicalJson::stringify($wrongKindDocument),
            '[]',
        );
        $this->assertThrows(
            StudioPublishedBlueprintMismatch::class,
            fn () => $this->renderer($binding, $wrongKind, $theme)->render($this->record()),
        );

        $incompatibleDocument = (object) [
            'kind' => 'blueprint',
            'id' => $binding->blueprintId,
            'version' => $binding->blueprintVersion,
            'revision' => 'incompatible-r1',
            'status' => 'published',
        ];
        $incompatible = new StoredStudioArtifact(
            SiteContext::DEFAULT,
            $binding->blueprintId,
            $binding->blueprintVersion,
            'blueprint',
            'incompatible-r1',
            'published',
            CanonicalJson::stringify($incompatibleDocument),
            '[]',
        );
        $this->assertThrows(
            StudioPublishedBlueprintMismatch::class,
            fn () => $this->renderer($binding, $incompatible, $theme)->render($this->record()),
        );
        $this->assertPublicationDiagnostic(
            $this->guard($theme),
            $incompatibleDocument,
            'studio.artifact/blueprint-incompatible',
        );

        $invalidStatusDocument = self::copy($this->blueprint($theme));
        $invalidStatusDocument->revision = 'invalid-status-r1';
        $invalidStatusDocument->status = 'archived';
        $invalidStatus = new StoredStudioArtifact(
            SiteContext::DEFAULT,
            $binding->blueprintId,
            $binding->blueprintVersion,
            'blueprint',
            'invalid-status-r1',
            'archived',
            CanonicalJson::stringify($invalidStatusDocument),
            '[]',
        );
        $this->assertThrows(
            StudioPublishedBlueprintMismatch::class,
            fn () => $this->renderer($binding, $invalidStatus, $theme)->render($this->record()),
        );

        $wrongDocumentIdentity = self::copy($this->blueprint($theme));
        $wrongDocumentIdentity->version = '2.0.0';
        $wrongDocumentIdentityArtifact = new StoredStudioArtifact(
            SiteContext::DEFAULT,
            $binding->blueprintId,
            $binding->blueprintVersion,
            'blueprint',
            $wrongDocumentIdentity->revision,
            'published',
            CanonicalJson::stringify($wrongDocumentIdentity),
            '[]',
        );
        $this->assertThrows(
            StudioPublishedBlueprintMismatch::class,
            fn () => $this->renderer(
                $binding,
                $wrongDocumentIdentityArtifact,
                $theme,
            )->render($this->record()),
        );
    }

    /**
     * Pinned model, published theme, and live block renderer drift each stop public output explicitly.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testModelThemeAndLiveRendererDriftFailClosed(): void
    {
        $theme = $this->theme();
        $modelDrift = self::copy($this->blueprint($theme));
        $modelDrift->model->revision = 'content-type-v3';
        $this->assertThrows(
            StudioPublishedModelMismatch::class,
            fn () => $this->renderer(
                $this->binding(),
                self::admission()->admit(SiteContext::DEFAULT, $modelDrift),
                $theme,
            )->render($this->record()),
        );
        $this->assertPublicationDiagnostic(
            $this->guard($theme),
            $modelDrift,
            'studio.artifact/model-lock-mismatch',
        );

        $modelWithIntegrity = self::copy($this->blueprint($theme));
        $modelWithIntegrity->model->integrity = 'sha256-' . base64_encode(str_repeat("\0", 32));
        $this->assertPublicationDiagnostic(
            $this->guard($theme),
            $modelWithIntegrity,
            'studio.artifact/model-lock-mismatch',
        );

        $missingModels = $this->createStub(ContentModelRepository::class);
        $missingModels->method('contentType')->willReturn(null);
        $this->assertPublicationDiagnostic(
            $this->guard($theme, models: $missingModels),
            $this->blueprint($theme),
            'studio.artifact/model-lock-mismatch',
        );

        $themeDrift = self::copy($this->blueprint($theme));
        $themeDrift->dependencyLock->theme->revision = 'published-stale';
        $this->assertThrows(
            StudioCompositionThemeMismatch::class,
            fn () => $this->renderer(
                $this->binding(),
                self::admission()->admit(SiteContext::DEFAULT, $themeDrift),
                $theme,
            )->render($this->record()),
        );
        $this->assertPublicationDiagnostic(
            $this->guard($theme),
            $themeDrift,
            'studio.artifact/theme-lock-mismatch',
        );

        $ownerDrift = self::copy($this->blueprint($theme));
        $ownerDrift->owner->id = 'acme.extension/content';
        $this->assertPublicationDiagnostic(
            $this->guard($theme),
            $ownerDrift,
            'studio.artifact/blueprint-incompatible',
        );

        $rendererDrift = self::copy($this->blueprint($theme));
        $rendererDrift->dependencyLock->blocks[0]->type = 'example.extension/missing';
        $rendererDrift->roots[0]->type = 'example.extension/missing';
        $this->assertThrows(
            StudioPublishedBlockRendererUnavailable::class,
            fn () => $this->renderer(
                $this->binding(),
                self::admission()->admit(SiteContext::DEFAULT, $rendererDrift),
                $theme,
            )->render($this->record()),
        );
        $this->assertPublicationDiagnostic(
            $this->guard($theme),
            $rendererDrift,
            'studio.artifact/block-renderer-unavailable',
        );

        $nodeLockDrift = self::copy($this->blueprint($theme));
        $nodeLockDrift->roots[0]->type = 'example.extension/missing';
        $this->assertPublicationDiagnostic(
            $this->guard($theme),
            $nodeLockDrift,
            'studio.artifact/block-renderer-unavailable',
        );

        $projectionRejected = self::admission()->admit(SiteContext::DEFAULT, $this->blueprint($theme));
        $this->assertThrows(
            StudioPublishedModelMismatch::class,
            fn () => $this->renderer(
                $this->binding(),
                $projectionRejected,
                $theme,
            )->render($this->record(42)),
        );
    }

    /**
     * Publication validates properties, responsive overrides, slots, node identity, and Content bindings.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPublicationEnforcesEveryLiveCoreBlockSemantic(): void
    {
        $theme = $this->theme();
        $guard = $this->guard($theme);
        $valid = $this->coreGridBlueprint($theme, 1);
        $guard->assertPublishable(SiteContext::default(), $valid);
        self::addToAssertionCount(1);

        $invalidProperty = self::copy($valid);
        $invalidProperty->roots[0]->properties->columns = 0;
        $this->assertPublicationDiagnostic(
            $guard,
            $invalidProperty,
            'studio.artifact/blueprint-incompatible',
        );

        $invalidResponsiveProperty = self::copy($valid);
        $invalidResponsiveProperty->roots[0]->responsive = (object) [
            'columns' => (object) ['compact' => 0],
        ];
        $this->assertPublicationDiagnostic(
            $guard,
            $invalidResponsiveProperty,
            'studio.artifact/blueprint-incompatible',
        );

        $unknownSlot = self::copy($valid);
        $unknownSlot->roots[0]->slots->unknown = [];
        $this->assertPublicationDiagnostic($guard, $unknownSlot, 'studio.artifact/blueprint-incompatible');

        $overMaximum = $this->coreGridBlueprint($theme, 101);
        $this->assertPublicationDiagnostic($guard, $overMaximum, 'studio.artifact/blueprint-incompatible');

        $duplicateIdentity = self::copy($valid);
        $duplicateIdentity->roots[] = self::copy($duplicateIdentity->roots[0]);
        $this->assertPublicationDiagnostic(
            $guard,
            $duplicateIdentity,
            'studio.artifact/blueprint-incompatible',
        );

        $unknownField = self::copy($valid);
        $unknownField->roots[0]->slots->items[0]->bindings->value->source->fieldPath = ['data_missing'];
        $this->assertPublicationDiagnostic($guard, $unknownField, 'studio.artifact/blueprint-incompatible');
    }

    /**
     * A signed extension block keeps its required property, child-type, integrity, and withdrawal contract.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPublicationEnforcesSignedExtensionBlockSemanticsAndWithdrawal(): void
    {
        [$registries, $owner] = self::manifestSixRegistries();
        $theme = $this->theme();
        $guard = $this->guard($theme, registries: $registries);
        $valid = $this->extensionGridBlueprint($theme);
        $guard->assertPublishable(SiteContext::default(), $valid);
        self::addToAssertionCount(1);

        $missingRequiredProperties = self::copy($valid);
        $missingRequiredProperties->roots[0]->properties = new stdClass();
        $this->assertPublicationDiagnostic(
            $guard,
            $missingRequiredProperties,
            'studio.artifact/blueprint-incompatible',
        );

        $rejectedChild = self::copy($valid);
        $rejectedChild->roots[0]->slots->items[] = $this->blueprint($theme)->roots[0];
        $this->assertPublicationDiagnostic(
            $guard,
            $rejectedChild,
            'studio.artifact/blueprint-incompatible',
        );

        $badIntegrity = self::copy($valid);
        $badIntegrity->dependencyLock->blocks[0]->integrity = 'sha256-' . base64_encode(str_repeat("\0", 32));
        $this->assertPublicationDiagnostic(
            $guard,
            $badIntegrity,
            'studio.artifact/block-renderer-unavailable',
        );

        [$minimumRegistries] = self::manifestSixRegistries(
            static function (stdClass $definition): void {
                $definition->slots[0]->minimum = 1;
            },
        );
        $this->assertPublicationDiagnostic(
            $this->guard($theme, registries: $minimumRegistries),
            $valid,
            'studio.artifact/blueprint-incompatible',
        );

        [$duplicateSlotRegistries] = self::manifestSixRegistries(
            static function (stdClass $definition): void {
                $slot = json_decode(
                    CanonicalJson::stringify($definition->slots[0]),
                    false,
                    16,
                    JSON_THROW_ON_ERROR,
                );
                self::assertInstanceOf(stdClass::class, $slot);
                $definition->slots[] = $slot;
            },
        );
        $this->assertPublicationDiagnostic(
            $this->guard($theme, registries: $duplicateSlotRegistries),
            $valid,
            'studio.artifact/blueprint-incompatible',
        );

        $registries->remove($owner);
        $this->assertPublicationDiagnostic(
            $guard,
            $valid,
            'studio.artifact/block-renderer-unavailable',
        );
    }

    /**
     * Prove a contributed implementation outside the frozen SDK fragment SPI cannot even register.
     *
     * A raw Producer renderer is the only shape that could request engine enhancements, so refusing it
     * at the registration boundary keeps published output free of unserviced enhancement requests
     * while the App has no canonical enhancement runtime.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAProducerEngineImplementationCannotRegisterAsAPreviewRenderer(): void
    {
        $enhancingRenderer = new class implements BlockRenderer {
            /**
             * Attempt the Producer engine surface the frozen SDK preview SPI deliberately withholds.
             *
             * @param   stdClass     $node   Composition node to render.
             * @param   string       $scope  Rendering scope identifier.
             * @param   RenderState  $state  Shared rendering state receiving the enhancement request.
             *
             * @return  string  Safe baseline markup.
             *
             * @since   2.0.0
             */
            public function render(stdClass $node, string $scope, RenderState $state): string
            {
                $state->enhance('motion', $node, $scope);

                return '<p>Safe non-JavaScript baseline</p>';
            }
        };
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires an implementation of');
        self::manifestSixRegistries(renderer: $enhancingRenderer);
    }

    /**
     * A trusted renderer that throws mid-render withholds the whole public composition, never a page.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testATrustedRendererThrowingMidRenderWithholdsPublicOutputCompletely(): void
    {
        $throwing = new class implements StudioPreviewBlockRenderer {
            /**
             * Fail exactly like a live extension renderer whose runtime collapses mid-render.
             *
             * @param   StudioPreviewBlock          $block     Immutable copied contributed grid input.
             * @param   StudioPreviewBindingResult  $binding   Authorized binding projection.
             * @param   string                      $viewport  Active semantic viewport.
             *
             * @return  StudioPreviewBlockFragment  Never returned: this double always collapses.
             *
             * @since   2.0.0
             */
            public function render(
                StudioPreviewBlock $block,
                StudioPreviewBindingResult $binding,
                string $viewport,
            ): StudioPreviewBlockFragment {
                unset($block, $binding, $viewport);
                throw new \RuntimeException('The live grid renderer collapsed mid-render.');
            }
        };
        [$registries] = self::manifestSixRegistries(renderer: $throwing);
        $theme = $this->theme();
        $artifact = self::admission()->admit(SiteContext::DEFAULT, $this->extensionGridBlueprint($theme));

        try {
            $this->renderer($this->binding(), $artifact, $theme, $registries)->render($this->record());
            self::fail('A collapsing live renderer must withhold the published composition.');
        } catch (StudioPublishedBlockRendererUnavailable $refused) {
            self::assertSame('unavailable', $refused->type);
            self::assertSame('unknown', $refused->version);
            self::assertNull($refused->revision);
        }
    }

    /**
     * A requested engine enhancement withholds public output while no canonical runtime services it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testARequestedEngineEnhancementWithholdsPublicOutputCompletely(): void
    {
        [$registries] = self::manifestSixRegistries(static function (stdClass $definition): void {
            $definition->propertySchema->properties->design = (object) ['type' => 'object'];
        });
        $theme = $this->theme();
        $blueprint = $this->extensionGridBlueprint($theme);
        $blueprint->roots[0]->properties->design = (object) ['animation' => 'fade'];
        $artifact = self::admission()->admit(SiteContext::DEFAULT, $blueprint);

        try {
            $this->renderer($this->binding(), $artifact, $theme, $registries)->render($this->record());
            self::fail('A requested Producer enhancement must withhold the published composition.');
        } catch (StudioPublishedBlockRendererUnavailable $refused) {
            self::assertSame('enhancement', $refused->type);
            self::assertSame('unknown', $refused->version);
            self::assertNull($refused->revision);
        }
    }

    /**
     * A registry composition failure refuses publication instead of publishing a partial dependency set.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testARegistryCompositionFailureRefusesPublication(): void
    {
        [$registries] = self::manifestSixRegistries();
        $surface = $registries->canonicalCompositionDocuments();
        $entriesProperty = new ReflectionProperty($surface, 'entries');
        $entries = $entriesProperty->getValue($surface);
        self::assertIsArray($entries);
        $first = reset($entries);
        self::assertIsArray($first);
        $entries['hostile duplicate key'] = $first;
        $entriesProperty->setValue($surface, $entries);
        $theme = $this->theme();

        $this->assertPublicationDiagnostic(
            $this->guard($theme, registries: $registries),
            $this->extensionGridBlueprint($theme),
            'studio.artifact/block-renderer-unavailable',
        );
    }

    /**
     * A lock coordinate outside the Producer grammar is an incompatible Blueprint, not a crash.
     *
     * The schema-admitted public path pins lock members to the same grammar, so the guard's own
     * coordinate fence is proved directly against the lock index it protects.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testALockCoordinateOutsideTheProducerGrammarIsIncompatible(): void
    {
        $liveLocks = new ReflectionMethod(StudioPublishedCompositionGuard::class, 'liveLocks');

        $this->expectException(StudioPublishedBlueprintMismatch::class);
        $liveLocks->invoke(
            $this->guard($this->theme()),
            (object) ['blocks' => [(object) [
                'type' => 'core/field-text',
                'version' => 'not-a-semantic-version',
                'revision' => 'core-block-r1',
            ]]],
            [],
            BlockRendererRegistry::withCoreCatalog(),
        );
    }

    /**
     * A node whose exact lock loses its live definition names only the immutable failed coordinate.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testANodeWhoseLockLosesItsLiveDefinitionNamesTheFailedCoordinate(): void
    {
        $assertNode = new ReflectionMethod(StudioPublishedCompositionGuard::class, 'assertNode');
        $identifiers = [];
        $validators = [];
        $node = (object) [
            'id' => 'orphaned-node',
            'type' => 'core/field-text',
            'version' => '1.0.0',
            'properties' => new stdClass(),
            'bindings' => new stdClass(),
            'slots' => new stdClass(),
        ];
        $locks = ["core/field-text\x001.0.0" => new BlockCoordinate('core/field-text', '1.0.0', 'core-block-r1')];

        try {
            $assertNode->invokeArgs(
                $this->guard($this->theme()),
                [$node, $locks, [], [], &$identifiers, &$validators],
            );
            self::fail('A node without a live definition must fail closed.');
        } catch (StudioPublishedBlockRendererUnavailable $refused) {
            self::assertSame('core/field-text', $refused->type);
            self::assertSame('1.0.0', $refused->version);
            self::assertSame('core-block-r1', $refused->revision);
        }
    }

    /**
     * A live definition whose property schema leaves the closed profile refuses publication.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnInadmissiblePropertySchemaRefusesPublication(): void
    {
        [$registries] = self::manifestSixRegistries(static function (stdClass $definition): void {
            $definition->propertySchema = (object) ['type' => 'object'];
        });
        $theme = $this->theme();

        $this->assertPublicationDiagnostic(
            $this->guard($theme, registries: $registries),
            $this->extensionGridBlueprint($theme),
            'studio.artifact/blueprint-incompatible',
        );
    }

    /**
     * Build a schema-valid nested core Grid with a requested number of accepted field children.
     *
     * @param   StudioPublishedTheme  $theme     Live theme whose immutable coordinate is locked.
     * @param   int                   $children  Number of uniquely identified field nodes to place.
     *
     * @return  stdClass  Published Blueprint using exact current core Grid and field locks.
     *
     * @since   2.0.0
     */
    private function coreGridBlueprint(StudioPublishedTheme $theme, int $children): stdClass
    {
        $document = $this->blueprint($theme);
        $field = $document->roots[0];
        $items = [];
        for ($index = 0; $index < $children; $index++) {
            $member = self::copy($field);
            $member->id = 'body-field-' . $index;
            $items[] = $member;
        }
        array_unshift($document->dependencyLock->blocks, (object) [
            'type' => 'studio.core/grid',
            'version' => '1.0.0',
            'revision' => 'layout-grid-r1',
        ]);
        $document->roots = [(object) [
            'id' => 'layout-grid',
            'type' => 'studio.core/grid',
            'version' => '1.0.0',
            'properties' => (object) ['columns' => 4, 'collapse' => 'stack'],
            'bindings' => new stdClass(),
            'slots' => (object) ['items' => $items],
            'authoring' => (object) ['mode' => 'structural'],
        ]];

        return $document;
    }

    /**
     * Build one valid Blueprint locked to the signed manifest-6 Grid definition.
     *
     * @param   StudioPublishedTheme  $theme  Live theme whose immutable coordinate is locked.
     *
     * @return  stdClass  Published extension-backed Blueprint with every required property.
     *
     * @since   2.0.0
     */
    private function extensionGridBlueprint(StudioPublishedTheme $theme): stdClass
    {
        $document = $this->blueprint($theme);
        $document->dependencyLock->blocks = [(object) [
            'type' => 'kumwe.contract-manifest-six/grid',
            'version' => '1.0.0',
            'revision' => 'grid-block-r1',
        ]];
        $document->roots = [(object) [
            'id' => 'extension-grid',
            'type' => 'kumwe.contract-manifest-six/grid',
            'version' => '1.0.0',
            'properties' => (object) ['columns' => 3, 'collapse' => 'stack'],
            'bindings' => new stdClass(),
            'slots' => (object) ['items' => []],
            'authoring' => (object) ['mode' => 'structural'],
        ]];

        return $document;
    }

    /**
     * Activate only the signed manifest-6 Grid document and its owner-bound host metadata.
     *
     * @param   (callable(stdClass): void)|null  $mutate    Optional schema-valid definition mutation.
     * @param   BlockRenderer|null               $renderer  Optional preview renderer replacing the default.
     *
     * @return  array{ExtensionContributionRegistrySet, ContributionOwner}  Mutable live registries and their
     *          extension owner for withdrawal proof.
     *
     * @since   2.0.0
     */
    private static function manifestSixRegistries(
        ?callable $mutate = null,
        ?object $renderer = null,
    ): array {
        $path = dirname(__DIR__, 5)
            . '/vendor/kumwe/extension-sdk/resources/fixtures/generations/manifest-6/kumwe.json';
        $manifest = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        $canonical = $manifest['contributions']['composition']['documents'][0]['canonical'] ?? null;
        self::assertIsString($canonical);
        $definition = json_decode($canonical, false, 64, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $definition);
        if ($mutate !== null) {
            $mutate($definition);
            self::assertTrue(
                StudioDocumentSchemaRegistry::fromVendoredCorpus()->validate('block-definition', $definition)->valid(),
            );
            $canonical = CanonicalJson::stringify($definition);
        }

        $owner = ContributionOwner::extension('kumwe/contract-manifest-six');
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $document = new CanonicalCompositionDocument(
            CanonicalCompositionKind::BlockDefinition,
            $canonical,
        );
        $binding = new CompositionHostBinding(
            CanonicalCompositionKind::BlockDefinition,
            'kumwe.contract-manifest-six/grid',
            'kumwe.contract-manifest-six/grid-preview',
        );
        $registries->canonicalCompositionDocuments()->register($owner, $document);
        $registries->compositionHostBindings()->register($owner, $binding);
        $renderer ??= new class implements StudioPreviewBlockRenderer {
            /**
             * Name the copied column count inside one bounded safe fragment.
             *
             * @param   StudioPreviewBlock          $block     Immutable copied contributed grid input.
             * @param   StudioPreviewBindingResult  $binding   Authorized binding projection.
             * @param   string                      $viewport  Active semantic viewport.
             *
             * @return  StudioPreviewBlockFragment  Safe fixture grid fragment.
             *
             * @since   2.0.0
             */
            public function render(
                StudioPreviewBlock $block,
                StudioPreviewBindingResult $binding,
                string $viewport,
            ): StudioPreviewBlockFragment {
                unset($viewport);
                $columns = $block->property('columns');

                return new StudioPreviewBlockFragment(
                    'div',
                    'fixture-grid',
                    'Columns ' . (is_int($columns) ? $columns : 0),
                    $binding->hidden,
                );
            }
        };
        $registries->studioPreviewRenderers()->register(
            $owner,
            new StudioPreviewRendererContribution($owner, '1.0.0', $document, $binding),
            $renderer instanceof StudioPreviewBlockRenderer
                ? self::trustFencedPreviewRenderer($renderer, 'kumwe/contract-manifest-six')
                : $renderer,
        );

        return [$registries, $owner];
    }

    /**
     * Compose the production renderer around deterministic in-memory repository doubles.
     *
     * @param   ?ContentBlueprintBinding           $binding     Exact binding or no configured composition.
     * @param   ?StoredStudioArtifact              $artifact    Selected current artifact or an unavailable
     *          coordinate.
     * @param   StudioPublishedTheme               $theme       Deterministic live public theme.
     * @param   ?ExtensionContributionRegistrySet  $registries  Live contribution registries override.
     *
     * @return  CanonicalStudioPublishedContentRenderer  Renderer under test.
     *
     * @since   2.0.0
     */
    private function renderer(
        ?ContentBlueprintBinding $binding,
        ?StoredStudioArtifact $artifact,
        StudioPublishedTheme $theme,
        ?ExtensionContributionRegistrySet $registries = null,
    ): CanonicalStudioPublishedContentRenderer {
        $bindings = $this->createStub(ContentProjectionBindingRepository::class);
        $bindings->method('blueprint')->willReturn($binding);
        $artifacts = $this->createStub(StudioArtifactRepository::class);
        $artifacts->method('current')->willReturn($artifact);
        $artifacts->method('revision')->willReturn($artifact);
        $registries ??= new ExtensionContributionRegistrySet();
        $runtime = new StudioBlockRendererRuntime($registries, new StudioContentFieldBlockRenderer());
        $resolver = new StudioPreviewBindingResolver();

        return new CanonicalStudioPublishedContentRenderer(
            $bindings,
            $artifacts,
            $this->guard($theme, $runtime, $registries),
            $this->projector(),
            $runtime,
            $resolver,
        );
    }

    /**
     * Build the exact reusable compatibility guard used by publication and public rendering.
     *
     * @param   StudioPublishedTheme                     $theme       Deterministic live public theme.
     * @param   StudioBlockRendererRuntime|null           $blocks      Live exact renderer runtime override.
     * @param   ExtensionContributionRegistrySet|null    $registries  Live canonical contribution override.
     * @param   ContentModelRepository|null              $models      Content model authority override.
     *
     * @return  StudioPublishedCompositionGuard  Production guard around deterministic repositories.
     *
     * @since   2.0.0
     */
    private function guard(
        StudioPublishedTheme $theme,
        ?StudioBlockRendererRuntime $blocks = null,
        ?ExtensionContributionRegistrySet $registries = null,
        ?ContentModelRepository $models = null,
    ): StudioPublishedCompositionGuard {
        if ($models === null) {
            $models = $this->createStub(ContentModelRepository::class);
            $models->method('contentType')->willReturn($this->definition());
        }

        $registries ??= new ExtensionContributionRegistrySet();

        return new StudioPublishedCompositionGuard(
            self::admission(),
            $models,
            $theme,
            $blocks ?? new StudioBlockRendererRuntime($registries, new StudioContentFieldBlockRenderer()),
            $registries,
        );
    }

    /**
     * Require one typed runtime drift to become its stable artifact-publication diagnostic.
     *
     * @param   StudioPublishedCompositionGuard  $guard       Production compatibility guard.
     * @param   stdClass                         $blueprint   Candidate Blueprint.
     * @param   string                           $diagnostic  Expected closed host diagnostic.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertPublicationDiagnostic(
        StudioPublishedCompositionGuard $guard,
        stdClass $blueprint,
        string $diagnostic,
    ): void {
        try {
            $guard->assertPublishable(SiteContext::default(), $blueprint);
            self::fail(sprintf('Publication must refuse %s.', $diagnostic));
        } catch (HostRefusal $refused) {
            self::assertSame('conflict', $refused->error()->category());
            self::assertSame($diagnostic, $refused->error()->diagnostics()[0]->code());
            self::assertNull($refused->error()->revision());
        }
    }

    /**
     * Build the production lossless Content projection used by the public runtime.
     *
     * @return  ContentStudioProjector  Schema-governed projector.
     *
     * @since   2.0.0
     */
    private function projector(): ContentStudioProjector
    {
        return new ContentStudioProjector(
            StudioDocumentSchemaRegistry::fromVendoredCorpus(),
            new RecordAuthorizedStudioContentFieldDisclosure(),
            new JsonSchemaValidator(),
        );
    }

    /**
     * Build one schema-valid App-owned Blueprint with an exact core field renderer lock.
     *
     * @param   StudioPublishedTheme  $theme  Live theme whose immutable coordinate is locked.
     *
     * @return  stdClass  Canonical published Blueprint document.
     *
     * @since   2.0.0
     */
    private function blueprint(StudioPublishedTheme $theme): stdClass
    {
        return (object) [
            'contractVersion' => '0.1-draft',
            'kind' => 'blueprint',
            'id' => $this->binding()->blueprintId,
            'version' => $this->binding()->blueprintVersion,
            'revision' => 'blueprint-published-r1',
            'owner' => (object) ['id' => 'kumwe.app/content', 'version' => '2.0.0'],
            'status' => 'published',
            'label' => (object) [
                'key' => 'kumwe.app/public-content',
                'defaultMessage' => 'Published content',
            ],
            'model' => (object) [
                'id' => ContentStudioProjector::modelId(self::TYPE_ID),
                'version' => ContentStudioProjector::modelVersion(4),
                'revision' => ContentStudioProjector::modelRevision(4),
            ],
            'dependencyLock' => (object) [
                'theme' => $theme->reference(SiteContext::default())->document(),
                'blocks' => [(object) [
                    'type' => 'core/field-text',
                    'version' => '1.0.0',
                    'revision' => 'core-block-r1',
                ]],
            ],
            'roots' => [(object) [
                'id' => 'body-field',
                'type' => 'core/field-text',
                'version' => '1.0.0',
                'properties' => new stdClass(),
                'bindings' => (object) ['value' => (object) [
                    'source' => (object) ['kind' => 'entry-field', 'fieldPath' => ['data_body']],
                    'transforms' => [],
                    'onNull' => 'error',
                    'onError' => 'error',
                ]],
                'slots' => new stdClass(),
                'authoring' => (object) ['mode' => 'content'],
            ]],
        ];
    }

    /**
     * Build the exact immutable binding selected for definition version four.
     *
     * @return  ContentBlueprintBinding  Host-owned public composition coordinate.
     *
     * @since   2.0.0
     */
    private function binding(): ContentBlueprintBinding
    {
        return new ContentBlueprintBinding(
            SiteContext::default(),
            self::TYPE_ID,
            4,
            'content-blueprint:' . self::TYPE_ID . ':v4',
            '1.0.0',
            null,
            1,
        );
    }

    /**
     * Build the exact published definition pinned by the record and Blueprint.
     *
     * @return  ContentTypeDefinition  Closed body-only Content schema.
     *
     * @since   2.0.0
     */
    private function definition(): ContentTypeDefinition
    {
        return new ContentTypeDefinition(
            self::TYPE_ID,
            SiteContext::default(),
            'article',
            'Article',
            ContentService::CORE_WORKFLOW_ID,
            1,
            [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => ['body' => ['type' => 'string']],
                'required' => ['body'],
            ],
            4,
            self::now(),
            self::now(),
        );
    }

    /**
     * Build one published record containing HTML-shaped text that must remain escaped.
     *
     * @param   mixed  $body  Body-field value to retain without schema-level validation.
     *
     * @return  ContentRecord  Public record pinned to definition version four.
     *
     * @since   2.0.0
     */
    private function record(mixed $body = '<strong>Exact & safe</strong>'): ContentRecord
    {
        return new ContentRecord(
            ContentEntry::create(
                self::ENTRY_ID,
                'Exact title',
                'exact-title',
                ['body' => $body],
                ContentStatus::Published,
            ),
            self::TYPE_ID,
            ContentService::CORE_WORKFLOW_ID,
            self::now(),
            self::now(),
            contentTypeVersion: 4,
        );
    }

    /**
     * Build the deterministic live built-in theme used by exact lock comparisons.
     *
     * @return  StudioPublishedTheme  Live theme authority.
     *
     * @since   2.0.0
     */
    private function theme(): StudioPublishedTheme
    {
        $settings = $this->createStub(SiteSettings::class);
        $settings->method('current')->willReturn(['presentation' => SitePresentation::defaults()]);

        return new StudioPublishedTheme(
            $settings,
            new ActiveExtensionSet(new ExtensionContributionRegistrySet(withCore: false)),
            new StudioBuiltInThemeRelease(str_repeat('a', 64)),
        );
    }

    /**
     * Build the pinned Studio schema and safety admission boundary.
     *
     * @return  StudioArtifactAdmission  Canonical admission service.
     *
     * @since   2.0.0
     */
    private static function admission(): StudioArtifactAdmission
    {
        return new StudioArtifactAdmission(StudioDocumentSchemaRegistry::fromVendoredCorpus());
    }

    /**
     * Copy a canonical JSON object without retaining mutable fixture identity.
     *
     * @param   stdClass  $document  Source document.
     *
     * @return  stdClass  Deep canonical copy.
     *
     * @since   2.0.0
     */
    private static function copy(stdClass $document): stdClass
    {
        $copy = json_decode(CanonicalJson::stringify($document), false, 64, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $copy);

        return $copy;
    }

    /**
     * Assert a public rendering operation stops with one exact typed refusal.
     *
     * @param   class-string<\Throwable>  $expected   Expected refusal type.
     * @param   callable(): mixed         $operation  Operation that must fail closed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertThrows(string $expected, callable $operation): void
    {
        try {
            $operation();
            self::fail('The incompatible public Studio composition did not fail closed.');
        } catch (\Throwable $failure) {
            self::assertInstanceOf($expected, $failure);
        }
    }

    /**
     * Return the deterministic instant used by Content fixtures.
     *
     * @return  DateTimeImmutable  Fixed publication time.
     *
     * @since   2.0.0
     */
    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-24T12:00:00+00:00');
    }
}
