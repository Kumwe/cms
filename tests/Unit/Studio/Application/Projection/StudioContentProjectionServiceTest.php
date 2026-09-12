<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Projection;

use DateTimeImmutable;
use Kumwe\App\Administrator\Http\Handler\AdministratorStudioCompositionHandler;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\App\Administrator\Presentation\RecoveryAdministratorRenderer;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Content\Application\ContentModelRepository;
use Kumwe\App\Content\Application\ContentModelService;
use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Application\ContentRepository;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Content\Domain\ContentEntry;
use Kumwe\App\Content\Domain\ContentTypeDefinition;
use Kumwe\App\Content\Domain\JsonSchemaValidator;
use Kumwe\App\Content\Domain\PublicationWindow;
use Kumwe\App\Content\Domain\SchemaCompatibilityChecker;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Contribution\StudioPreviewRendererContribution;
use Kumwe\App\Extension\Runtime\ActiveExtensionSet;
use Kumwe\App\Extension\Runtime\TrustEnforcingStudioPreviewBlockRenderer;
use Kumwe\App\Presentation\Application\SitePresentation;
use Kumwe\App\Site\Application\SiteSettings;
use Kumwe\App\Studio\Application\Composition\ContentBlueprintBindingStore;
use Kumwe\App\Studio\Application\Composition\StudioBuiltInThemeRelease;
use Kumwe\App\Studio\Application\Composition\StudioCompositionLockMismatch;
use Kumwe\App\Studio\Application\Composition\StudioCompositionModelMismatch;
use Kumwe\App\Studio\Application\Composition\StudioContentCompositionService;
use Kumwe\App\Studio\Application\Composition\StudioCompositionContributionCatalog;
use Kumwe\App\Studio\Application\Composition\StudioPublishedTheme;
use Kumwe\App\Studio\Application\Host\StudioArtifactAdmission;
use Kumwe\App\Studio\Application\Host\StudioArtifactRepository;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Application\Host\StudioModelHostPort;
use Kumwe\App\Studio\Application\Host\StudioPersistenceRace;
use Kumwe\App\Studio\Application\Projection\ContentProjectionBindingRepository;
use Kumwe\App\Studio\Application\Projection\ContentStudioProjector;
use Kumwe\App\Studio\Application\Projection\RecordAuthorizedStudioContentFieldDisclosure;
use Kumwe\App\Studio\Application\Projection\StudioContentProjectionService;
use Kumwe\App\Studio\Application\Projection\StudioProjectionRejected;
use Kumwe\App\Studio\Application\Preview\ContentStudioPreviewBindingSource;
use Kumwe\App\Studio\Application\Rendering\StudioBlockRendererRuntime;
use Kumwe\App\Studio\Application\Rendering\StudioContentFieldBlockRenderer;
use Kumwe\App\Studio\Application\Release\StudioReleaseRecord;
use Kumwe\Extension\Spi\Contribution\CanonicalCompositionDocument;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBindingResult;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlock;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlockFragment;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlockRenderer;
use Kumwe\Extension\Spi\Contribution\CanonicalCompositionKind;
use Kumwe\Extension\Spi\Contribution\CompositionHostBinding;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\Producer\Canonical\CanonicalJson;
use Kumwe\Producer\Schema\StudioContractResources;
use Kumwe\Producer\Schema\StudioDocumentSchemaRegistry;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewDraft;
use Kumwe\App\Studio\Domain\Projection\ContentBlueprintBinding;
use Kumwe\App\Studio\Domain\Projection\EntryCompositionOverrides;
use Kumwe\App\Studio\Domain\Projection\StudioProjectionRejection;
use Kumwe\Producer\Error\HostRefusal;
use Kumwe\Producer\Wire\HostResult;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Transaction\Testing\ImmediateTransactionManager;
use Kumwe\App\Tests\Support\StudioProducerRequest;
use Kumwe\App\Tests\Support\TrustFencedStudioPreviewRenderers;
use Kumwe\App\Identity\Application\Administration\AdministratorSession;
use Kumwe\Localization\Application\ActiveLocale;
use Kumwe\Localization\Application\SupportedLocales;
use Kumwe\App\Presentation\Twig\AdministratorTwigEnvironment;
use Kumwe\App\Presentation\Twig\RecoveryAdministratorTwigEnvironment;
use Kumwe\App\Workflow\Domain\Workflow;
use Kumwe\App\Workflow\Domain\WorkflowDefinition;
use Kumwe\App\Workflow\Domain\WorkflowStateDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Laminas\Diactoros\ServerRequestFactory;
use Twig\Loader\ArrayLoader;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;

/**
 * Proves the Studio Content read boundary delegates only through authorized, version-pinned services.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioContentProjectionService::class)]
#[CoversClass(ContentStudioPreviewBindingSource::class)]
#[CoversClass(StudioModelHostPort::class)]
#[CoversClass(StudioContentCompositionService::class)]
#[CoversClass(StudioCompositionModelMismatch::class)]
#[CoversClass(AdministratorStudioCompositionHandler::class)]
#[UsesClass(ContentModelService::class)]
#[UsesClass(ContentService::class)]
#[UsesClass(ContentStudioProjector::class)]
#[UsesClass(RecordAuthorizedStudioContentFieldDisclosure::class)]
#[UsesClass(StudioProjectionRejected::class)]
#[UsesClass(ContentTypeDefinition::class)]
#[UsesClass(ContentEntry::class)]
#[UsesClass(ContentRecord::class)]
#[UsesClass(ContentBlueprintBinding::class)]
#[UsesClass(ExtensionContributionRegistrySet::class)]
#[UsesClass(StudioPreviewRendererContribution::class)]
#[UsesClass(StudioBlockRendererRuntime::class)]
#[UsesClass(StudioContentFieldBlockRenderer::class)]
#[UsesClass(TrustEnforcingStudioPreviewBlockRenderer::class)]
#[UsesClass(TrustStore::class)]
#[UsesClass(EntryCompositionOverrides::class)]
#[UsesClass(JsonSchemaValidator::class)]
#[UsesClass(SchemaCompatibilityChecker::class)]
#[UsesClass(Workflow::class)]
#[UsesClass(WorkflowDefinition::class)]
#[UsesClass(WorkflowStateDefinition::class)]
final class StudioContentProjectionServiceTest extends TestCase
{
    use TrustFencedStudioPreviewRenderers;

    /**
     * Stable definition coordinate used across every service delegation.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string TYPE_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026be710';

    /**
     * Stable entry coordinate used across every service delegation.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string ENTRY_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026be720';

    /**
     * Stable custom workflow coordinate pinned by the definition and entry.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string WORKFLOW_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026be730';

    /**
     * `vector.host-vector.model.list.authorized` preserves authorized deterministic coordinates.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testModelsDelegateToTheAuthorizedCollectionAndExactBindingCoordinate(): void
    {
        $definition = $this->definition();
        $binding = $this->binding();
        $models = $this->createMock(ContentModelRepository::class);
        $models->expects(self::once())
            ->method('contentTypes')
            ->with(self::callback(self::isDefaultSite(...)))
            ->willReturn([$definition]);
        $content = $this->createStub(ContentRepository::class);
        $bindings = $this->createMock(ContentProjectionBindingRepository::class);
        $bindings->expects(self::once())
            ->method('blueprint')
            ->with(self::callback(self::isDefaultSite(...)), self::TYPE_ID, 4)
            ->willReturn($binding);

        $request = StudioProducerRequest::authorized('studio.operation/model.list', new \stdClass());
        $port = (new StudioModelHostPort($this->service($models, $content, $bindings)))
            ->forRequest($request->authority);
        $documents = $port->list($request->arguments(), $request->context())->value;

        self::assertCount(1, $documents);
        self::assertSame('content-model:' . self::TYPE_ID, $documents[0]->id);
        self::assertSame(
            'kumwe.blueprints/article',
            $documents[0]->extensions->{'kumwe.app/blueprint-binding'}->id,
        );
    }

    /**
     * The stored model vector resolves through AP-2 while malformed read requests fail closed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testModelGetStoredVectorUsesTheAuthorizedExactProjection(): void
    {
        $models = $this->createMock(ContentModelRepository::class);
        $models->expects(self::exactly(2))
            ->method('contentType')
            ->with(self::callback(self::isDefaultSite(...)), self::TYPE_ID, 4)
            ->willReturn($this->definition());
        $bindings = $this->createMock(ContentProjectionBindingRepository::class);
        $bindings->expects(self::exactly(2))->method('blueprint')->willReturn($this->binding());
        $port = new StudioModelHostPort($this->service(
            $models,
            $this->createStub(ContentRepository::class),
            $bindings,
        ));
        $request = self::modelRequest((object) ['reference' => (object) [
            'id' => 'content-model:' . self::TYPE_ID,
            'version' => '0.0.4',
            'revision' => 'content-type-v4',
        ]]);
        $result = $port->forRequest($request->authority)->get($request->arguments(), $request->context());

        self::assertSame('content-model:' . self::TYPE_ID, $result->value->id);
        self::assertSame('0.0.4', $result->value->version);
        self::assertSame($result->value->revision, $result->revision);
        $validReference = (object) [
            'id' => 'content-model:' . self::TYPE_ID,
            'version' => '0.0.4',
        ];
        self::assertHostRefusal(
            static fn () => self::modelGet($port, self::modelRequest((object) ['reference' => (object) [
                    'id' => 'not-a-content-model',
                    'version' => '0.0.4',
                ]])),
            'not-found',
            'studio.model/not-found',
        );
        self::assertHostRefusal(
            static fn () => self::modelGet($port, self::modelRequest(null)),
            'invalid-request',
            'studio.host/invalid-arguments',
        );
        self::assertHostRefusal(
            static fn () => self::modelGet($port, self::modelRequest((object) ['reference' => 'not-an-object'])),
            'invalid-request',
            'studio.host/invalid-arguments',
        );
        self::assertHostRefusal(
            static fn () => self::modelGet($port, self::modelRequest((object) ['reference' => (object) [
                    'extra' => true,
                    'id' => 'content-model:' . self::TYPE_ID,
                    'version' => '0.0.4',
                ]])),
            'invalid-request',
            'studio.host/invalid-arguments',
        );
        self::assertHostRefusal(
            static fn () => self::modelGet($port, self::modelRequest(
                (object) ['reference' => (object) ['id' => '', 'version' => '0.0.4']],
            )),
            'invalid-request',
            'studio.host/invalid-arguments',
        );
        self::assertHostRefusal(
            static fn () => self::modelGet($port, self::modelRequest((object) ['reference' => (object) [
                    'id' => $validReference->id,
                    'revision' => 'content-type-v999',
                    'version' => $validReference->version,
                ]])),
            'not-found',
            'studio.model/not-found',
        );
        self::assertHostRefusal(
            static fn () => self::modelList(
                $port,
                StudioProducerRequest::authorized(
                    'studio.operation/model.list',
                    (object) ['unexpected' => true],
                ),
            ),
            'invalid-request',
            'studio.host/invalid-arguments',
        );
        self::assertHostRefusal(
            static fn () => self::modelGet(
                $port,
                self::modelRequest(
                    (object) ['reference' => $validReference],
                    expectedRevision: 'revision/not-allowed',
                ),
            ),
            'invalid-request',
            'studio.host/invalid-context',
        );
    }

    /**
     * Provisioning and the administrator composition surface preserve every exact draft boundary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCompositionProvisioningIsSchemaValidTransactionalAndIdempotent(): void
    {
        $models = $this->createStub(ContentModelRepository::class);
        $models->method('contentType')->willReturn($this->definition());
        $currentBinding = null;
        $bindings = $this->createStub(ContentProjectionBindingRepository::class);
        $bindings->method('blueprint')->willReturnCallback(
            static function () use (&$currentBinding): ?ContentBlueprintBinding {
                return $currentBinding;
            },
        );
        $projection = $this->service($models, $this->createStub(ContentRepository::class), $bindings);
        $bindingStore = $this->createMock(ContentBlueprintBindingStore::class);
        $bindingStore->expects(self::once())->method('add')->willReturnCallback(
            static function (ContentBlueprintBinding $binding) use (&$currentBinding): void {
                $currentBinding = $binding;
            },
        );
        $stored = null;
        $artifacts = $this->createMock(StudioArtifactRepository::class);
        $artifacts->method('current')->willReturnCallback(static function () use (&$stored) {
            return $stored;
        });
        $artifacts->method('revision')->willReturn(null);
        $artifacts->expects(self::once())->method('store')->willReturnCallback(
            static function ($artifact, ?string $expected) use (&$stored): bool {
                self::assertNull($expected);
                $stored = $artifact;
                return true;
            },
        );
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(self::now());
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::once())->method('record');
        $catalog = $this->compositionCatalog();
        $settingsDocument = ['presentation' => SitePresentation::defaults(), 'timezone' => []];
        $settings = $this->createStub(SiteSettings::class);
        $settings->method('current')->willReturnCallback(
            static function () use (&$settingsDocument): array {
                return $settingsDocument;
            },
        );
        $theme = new StudioPublishedTheme(
            $settings,
            new ActiveExtensionSet(new ExtensionContributionRegistrySet(withCore: false)),
            new StudioBuiltInThemeRelease(str_repeat('a', 64)),
        );
        $admission = new StudioArtifactAdmission(StudioDocumentSchemaRegistry::fromVendoredCorpus());
        $service = new StudioContentCompositionService(
            $projection,
            $bindings,
            $bindingStore,
            $admission,
            $artifacts,
            new ImmediateTransactionManager(),
            $audit,
            $clock,
            $catalog,
            $theme,
        );

        $first = $service->provision(
            AuthorizationContext::human(
                ['content.read'],
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb303',
            ),
            self::TYPE_ID,
            4,
            ['acme.shop/grid-preview', 'core.renderer/field', 'core.renderer/layout'],
        );
        $second = $service->provision(
            AuthorizationContext::human(
                ['content.read'],
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb304',
            ),
            self::TYPE_ID,
            4,
            ['acme.shop/grid-preview', 'core.renderer/field', 'core.renderer/layout'],
        );

        self::assertSame($first->binding->blueprintId, $second->binding->blueprintId);
        self::assertSame(1, $first->binding->revision);
        self::assertNull($first->binding->blueprintRevision);
        self::assertSame('draft', $first->blueprint->status);
        self::assertSame([], $first->blueprint->document()->roots);
        self::assertCount(14, $first->blueprint->document()->dependencyLock->blocks);
        $lockedTypes = array_map(
            static fn (\stdClass $lock): string => $lock->type,
            $first->blueprint->document()->dependencyLock->blocks,
        );
        self::assertContains('acme.shop/grid', $lockedTypes);
        $restricted = $catalog->project(
            [],
            ['acme.shop/grid-preview', 'core.renderer/field', 'core.renderer/layout'],
            $first->blueprint->document()->dependencyLock->blocks,
        );
        $privileged = $catalog->project(
            ['acme.shop.catalog.edit' => true],
            ['acme.shop/grid-preview', 'core.renderer/field', 'core.renderer/layout'],
            $first->blueprint->document()->dependencyLock->blocks,
        );
        self::assertNotContains('acme.shop/grid', self::compositionBlockTypes($restricted->documents));
        self::assertContains('acme.shop/grid', self::compositionBlockTypes($privileged->documents));
        self::assertSame('acme.shop/preview-grid', $restricted->blockRenderers['acme.shop/grid']);
        self::assertSame('core.theme/site', $first->blueprint->document()->dependencyLock->theme->id);
        self::assertSame(
            $theme->reference(SiteContext::fromString('default'))->revision,
            $first->blueprint->document()->dependencyLock->theme->revision,
        );

        $activeLocale = new ActiveLocale(new SupportedLocales());
        $renderer = new AdministratorRenderer(
            new AdministratorTwigEnvironment(new ArrayLoader([
                'studio-composition.twig' => '{% if studio_boot_json is not null %}'
                    . '{{ studio_boot_json|raw }}{% elseif theme_mismatch %}theme-mismatch{% endif %}',
            ])),
            new RecoveryAdministratorRenderer(
                new RecoveryAdministratorTwigEnvironment(new ArrayLoader()),
            ),
        );
        $studioReleaseBytes = file_get_contents(
            dirname(__DIR__, 5) . '/resources/studio-contract/studio-release.json',
        );
        self::assertIsString($studioReleaseBytes);
        $studioRelease = StudioReleaseRecord::fromJson($studioReleaseBytes);
        $handler = new AdministratorStudioCompositionHandler(
            $service,
            $renderer,
            $activeLocale,
            $settings,
            $catalog,
            $studioRelease,
        );
        $principal = AuthorizationContext::principal([
            'acme.shop.catalog.edit',
            'content.read',
        ]);
        $handlerContext = $principal->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'studio-composition-handler',
            surface: AuthenticatedSurface::Administrator,
            sessionId: 'administrator-studio-composition-handler',
        );
        $session = new AdministratorSession(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb350',
            $principal,
            'csrf-studio-composition',
            self::now()->modify('+1 hour'),
            SiteContext::default(),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest(
                'GET',
                'https://kumwe.test/administrator/content-models/' . self::TYPE_ID . '/versions/4/composition',
            )
            ->withAttribute('id', self::TYPE_ID)
            ->withAttribute('version', '4')
            ->withAttribute(AdministratorSession::REQUEST_ATTRIBUTE, $session)
            ->withAttribute(ExecutionContextAttribute::NAME, $handlerContext)
            ->withQueryParams(['locale' => 'de-DE']);

        $response = $handler->handle($request);
        $boot = json_decode((string) $response->getBody(), false, 64, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $boot);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame($principal->subject(), $boot->actor->id);
        self::assertSame($first->binding->blueprintId, $boot->artifact->id);
        self::assertSame('csrf-studio-composition', $boot->csrf);
        self::assertSame('de-DE', $boot->locale->requested);
        self::assertSame('en-GB', $boot->locale->resolved);
        self::assertSame('UTC', $boot->locale->timezone);
        self::assertSame('draft', $boot->status);
        self::assertSame($studioRelease->release, $boot->release);
        self::assertIsArray($boot->contributions);

        $fallbackResponse = $handler->handle($request->withQueryParams(['locale' => 'not_locale!']));
        $fallbackBoot = json_decode((string) $fallbackResponse->getBody(), false, 64, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $fallbackBoot);
        self::assertSame('en-GB', $fallbackBoot->locale->requested);

        $post = $handler->handle($request->withMethod('POST'));
        self::assertSame(303, $post->getStatusCode());
        self::assertSame(
            '/administrator/content-models/' . self::TYPE_ID . '/versions/4/composition',
            $post->getHeaderLine('Location'),
        );

        try {
            $handler->handle($request->withAttribute('version', '0'));
            self::fail('The administrator composition handler accepted an invalid route coordinate.');
        } catch (\InvalidArgumentException $invalidCoordinate) {
            self::assertSame(
                'The Content model composition coordinate is invalid.',
                $invalidCoordinate->getMessage(),
            );
        }
        $unavailableBindings = $this->createStub(ContentProjectionBindingRepository::class);
        $unavailableBindings->method('blueprint')->willReturn($first->binding);
        $unavailableArtifacts = $this->createStub(StudioArtifactRepository::class);
        $unavailableArtifacts->method('current')->willReturn(null);
        $unavailable = $this->compositionService(
            $projection,
            $unavailableBindings,
            $this->createStub(ContentBlueprintBindingStore::class),
            $admission,
            $unavailableArtifacts,
            $clock,
            $catalog,
            $theme,
        );
        $this->assertRuntimeFailure(
            fn (): ?\Kumwe\App\Studio\Application\Composition\StudioContentComposition => $unavailable->find(
                $this->allowedContext(),
                self::TYPE_ID,
                4,
            ),
            'The selected Studio Blueprint is unavailable.',
        );

        $bindingRaceReads = 0;
        $bindingRaceBindings = $this->createStub(ContentProjectionBindingRepository::class);
        $bindingRaceBindings->method('blueprint')->willReturnCallback(
            static function () use (&$bindingRaceReads, $first): ?ContentBlueprintBinding {
                $bindingRaceReads++;
                return $bindingRaceReads === 1 ? null : $first->binding;
            },
        );
        $bindingRaceArtifacts = $this->createStub(StudioArtifactRepository::class);
        $bindingRaceArtifacts->method('current')->willReturn($first->blueprint);
        $bindingRace = $this->compositionService(
            $projection,
            $bindingRaceBindings,
            $this->createStub(ContentBlueprintBindingStore::class),
            $admission,
            $bindingRaceArtifacts,
            $clock,
            $catalog,
            $theme,
        )->provision($this->allowedContext(), self::TYPE_ID, 4, []);
        self::assertSame($first->binding->blueprintId, $bindingRace->binding->blueprintId);

        $artifactRaceReads = 0;
        $artifactRaceBindings = $this->createStub(ContentProjectionBindingRepository::class);
        $artifactRaceBindings->method('blueprint')->willReturnCallback(
            static function () use (&$artifactRaceReads, $first): ?ContentBlueprintBinding {
                $artifactRaceReads++;
                return $artifactRaceReads < 3 ? null : $first->binding;
            },
        );
        $artifactRaceArtifacts = $this->createStub(StudioArtifactRepository::class);
        $artifactRaceArtifacts->method('current')->willReturn($first->blueprint);
        $artifactRaceArtifacts->method('store')->willReturn(false);
        $artifactRace = $this->compositionService(
            $projection,
            $artifactRaceBindings,
            $this->createStub(ContentBlueprintBindingStore::class),
            $admission,
            $artifactRaceArtifacts,
            $clock,
            $catalog,
            $theme,
        )->provision($this->allowedContext(), self::TYPE_ID, 4, []);
        self::assertSame($first->binding->blueprintId, $artifactRace->binding->blueprintId);

        $unresolvedBindings = $this->createStub(ContentProjectionBindingRepository::class);
        $unresolvedBindings->method('blueprint')->willReturn(null);
        $unresolvedArtifacts = $this->createStub(StudioArtifactRepository::class);
        $unresolvedArtifacts->method('store')->willReturn(false);
        $unresolved = $this->compositionService(
            $projection,
            $unresolvedBindings,
            $this->createStub(ContentBlueprintBindingStore::class),
            $admission,
            $unresolvedArtifacts,
            $clock,
            $catalog,
            $theme,
        );
        $this->assertRuntimeFailure(
            fn (): \Kumwe\App\Studio\Application\Composition\StudioContentComposition => $unresolved->provision(
                $this->allowedContext(),
                self::TYPE_ID,
                4,
                [],
            ),
            'The concurrent Studio composition could not be resolved.',
        );

        $modelDrift = [
            'id' => 'content-model:018f22e2-7c8b-7ab0-8f3a-88e8026be711',
            'version' => '0.0.5',
            'revision' => 'content-type-v5',
        ];
        $mismatches = 0;
        foreach ($modelDrift as $member => $value) {
            $document = $first->blueprint->document();
            $document->model->{$member} = $value;
            $stored = $admission->admit(SiteContext::DEFAULT, $document);
            try {
                $service->find(
                    AuthorizationContext::human(
                        ['content.read'],
                        '018f22e2-7c8b-7ab0-8f3a-88e8026bb310',
                    ),
                    self::TYPE_ID,
                    4,
                );
            } catch (StudioCompositionModelMismatch $failure) {
                self::assertSame(
                    'The Studio Blueprint Content-model lock requires an explicit migration.',
                    $failure->getMessage(),
                );
                $mismatches++;
            }
        }
        self::assertSame(count($modelDrift), $mismatches);
        $stored = $first->blueprint;

        $settingsDocument['presentation']['active_scheme'] = 'ocean';
        $mismatch = $handler->handle($request);
        self::assertSame(409, $mismatch->getStatusCode());
        self::assertSame('no-store', $mismatch->getHeaderLine('Cache-Control'));
        self::assertSame('theme-mismatch', (string) $mismatch->getBody());
    }

    /**
     * Adopting an authored Blueprint refuses a type version that already binds one, a document without a
     * dependency lock, a lock the admitted coordinates do not match and both persistence races, and otherwise
     * stores the authored document under an `authored-` revision bound to the type version.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAdoptRefusesBoundTypesLockMismatchesAndPersistenceRacesBeforeBinding(): void
    {
        $models = $this->createStub(ContentModelRepository::class);
        $models->method('contentType')->willReturn($this->definition());
        $reads = 0;
        $raceRead = null;
        $raceBinding = null;
        $currentBinding = null;
        $bindings = $this->createStub(ContentProjectionBindingRepository::class);
        $bindings->method('blueprint')->willReturnCallback(
            static function () use (&$reads, &$raceRead, &$raceBinding, &$currentBinding): ?ContentBlueprintBinding {
                $reads++;

                return $reads === $raceRead ? $raceBinding : $currentBinding;
            },
        );
        $projection = $this->service($models, $this->createStub(ContentRepository::class), $bindings);
        $bindingStore = $this->createStub(ContentBlueprintBindingStore::class);
        $bindingStore->method('add')->willReturnCallback(
            static function (ContentBlueprintBinding $binding) use (&$currentBinding): void {
                $currentBinding = $binding;
            },
        );
        $storeAnswers = [];
        $stored = null;
        $artifacts = $this->createStub(StudioArtifactRepository::class);
        $artifacts->method('current')->willReturnCallback(static function () use (&$stored) {
            return $stored;
        });
        $artifacts->method('revision')->willReturn(null);
        $artifacts->method('store')->willReturnCallback(
            static function ($artifact) use (&$stored, &$storeAnswers): bool {
                $accepted = $storeAnswers === [] ? true : (bool) array_shift($storeAnswers);
                if ($accepted) {
                    $stored = $artifact;
                }

                return $accepted;
            },
        );
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(self::now());
        $settings = $this->createStub(SiteSettings::class);
        $settings->method('current')->willReturn(['presentation' => SitePresentation::defaults(), 'timezone' => []]);
        $theme = new StudioPublishedTheme(
            $settings,
            new ActiveExtensionSet(new ExtensionContributionRegistrySet(withCore: false)),
            new StudioBuiltInThemeRelease(str_repeat('a', 64)),
        );
        $service = $this->compositionService(
            $projection,
            $bindings,
            $bindingStore,
            new StudioArtifactAdmission(StudioDocumentSchemaRegistry::fromVendoredCorpus()),
            $artifacts,
            $clock,
            $this->compositionCatalog(),
            $theme,
        );
        $context = AuthorizationContext::human(['content.read'], '018f22e2-7c8b-7ab0-8f3a-88e8026bb305');
        $admitted = ['acme.shop/grid-preview', 'core.renderer/field', 'core.renderer/layout'];

        // Before anything is bound the reference names the initial Blueprint the type version would get.
        $initial = $service->reference($context, self::TYPE_ID, 4, $admitted);
        self::assertStringStartsWith('initial-', $initial->revision);
        self::assertSame('1.0.0', $initial->version);

        // A provisioned draft supplies the one lock the admitted coordinates accept, and binds the version.
        $provisioned = $service->provision($context, self::TYPE_ID, 4, $admitted);
        $locks = $provisioned->blueprint->document()->dependencyLock->blocks;
        self::assertSame($initial->id, $provisioned->binding->blueprintId);
        $bound = $service->reference($context, self::TYPE_ID, 4, $admitted);
        self::assertSame($provisioned->binding->blueprintId, $bound->id);
        self::assertSame($provisioned->binding->blueprintVersion, $bound->version);
        self::assertSame($provisioned->blueprint->revision, $bound->revision);
        $document = json_encode($provisioned->blueprint->document(), JSON_THROW_ON_ERROR);
        $authored = static function (?array $blocks) use ($document): \stdClass {
            $copy = json_decode($document, false, 64, JSON_THROW_ON_ERROR);
            self::assertInstanceOf(\stdClass::class, $copy);
            if ($blocks === null) {
                unset($copy->dependencyLock);
            } else {
                $copy->dependencyLock->blocks = $blocks;
            }

            return $copy;
        };
        try {
            $service->adopt($context, self::TYPE_ID, 4, $authored($locks), $locks, 'draft');
            self::fail('A bound type version must not adopt a second Blueprint.');
        } catch (RuntimeException $bound) {
            self::assertSame('The Content type version already binds a Studio Blueprint.', $bound->getMessage());
        }

        // Once the draft binding is gone the authored document is admitted and bound under its own revision.
        $currentBinding = null;
        $stored = null;
        $readsBefore = $reads;
        // An empty layout is stored as the type's draft composition, exactly as the authoring service asks.
        $adopted = $service->adopt($context, self::TYPE_ID, 4, $authored($locks), $locks, 'draft');
        $readsPerAdoption = $reads - $readsBefore;
        self::assertSame($provisioned->binding->blueprintId, $adopted->binding->blueprintId);
        self::assertSame(1, $adopted->binding->revision);
        self::assertIsString($adopted->binding->blueprintRevision);
        self::assertStringStartsWith('authored-', $adopted->binding->blueprintRevision);
        self::assertSame($adopted->binding->blueprintRevision, $adopted->blueprint->revision);
        self::assertSame('draft', $adopted->blueprint->status);
        self::assertSame($currentBinding, $adopted->binding);
        self::assertSame($stored, $adopted->blueprint);
        self::assertEquals($locks, $adopted->blueprint->document()->dependencyLock->blocks);

        // A binding that appears on the read inside the transaction, or a store that refuses, is a race.
        $currentBinding = null;
        $stored = null;
        $raceBinding = $provisioned->binding;
        $raceRead = $reads + $readsPerAdoption;
        try {
            $service->adopt($context, self::TYPE_ID, 4, $authored($locks), $locks, 'draft');
            self::fail('A concurrently provisioned binding must be refused.');
        } catch (StudioPersistenceRace $race) {
            self::assertSame('A Content composition was concurrently provisioned.', $race->getMessage());
        }
        $raceRead = null;
        $storeAnswers = [false];
        try {
            $service->adopt($context, self::TYPE_ID, 4, $authored($locks), $locks, 'draft');
            self::fail('A concurrently stored Blueprint must be refused.');
        } catch (StudioPersistenceRace $race) {
            self::assertSame('A Studio Blueprint was concurrently provisioned.', $race->getMessage());
        }
        self::assertNull($currentBinding);

        // A document without a lock, or with a lock the admitted coordinates do not match, never reaches storage.
        try {
            $service->adopt($context, self::TYPE_ID, 4, $authored(null), $locks, 'draft');
            self::fail('A document without a dependency lock must be refused.');
        } catch (StudioCompositionLockMismatch $mismatch) {
            self::assertSame('dependencyLock', $mismatch->type);
        }
        $stale = [(object) ['type' => 'acme.shop/grid', 'version' => '9.9.9', 'revision' => 'stale']];
        try {
            $service->adopt($context, self::TYPE_ID, 4, $authored($stale), $locks, 'draft');
            self::fail('A lock the admitted coordinates do not match must be refused.');
        } catch (StudioCompositionLockMismatch $mismatch) {
            self::assertSame('acme.shop/grid', $mismatch->type);
        }
        self::assertNull($currentBinding);
        self::assertNull($stored);
    }

    /**
     * Exact reversible model and entry coordinates drive pinned definition, workflow and metadata reads.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testModelAndEntryLoadExactPinnedDependenciesBeforeProjection(): void
    {
        $definition = $this->definition(self::WORKFLOW_ID, 3);
        $record = $this->record();
        $workflow = $this->workflow();
        $models = $this->createMock(ContentModelRepository::class);
        $models->expects(self::exactly(2))
            ->method('contentType')
            ->with(self::callback(self::isDefaultSite(...)), self::TYPE_ID, 4)
            ->willReturn($definition);
        $models->expects(self::once())
            ->method('workflow')
            ->with(self::callback(self::isDefaultSite(...)), self::WORKFLOW_ID, 3)
            ->willReturn($workflow);
        $content = $this->createMock(ContentRepository::class);
        $content->expects(self::once())
            ->method('find')
            ->with(self::ENTRY_ID, false)
            ->willReturn($record);
        $bindings = $this->createMock(ContentProjectionBindingRepository::class);
        $bindings->expects(self::once())
            ->method('blueprint')
            ->with(self::callback(self::isDefaultSite(...)), self::TYPE_ID, 4)
            ->willReturn($this->binding());
        $bindings->expects(self::once())
            ->method('overrides')
            ->with(self::callback(self::isDefaultSite(...)), self::ENTRY_ID)
            ->willReturn($this->overrides());
        $service = $this->service($models, $content, $bindings);
        $context = $this->allowedContext();

        $model = $service->model($context, 'content-model:' . self::TYPE_ID, '0.0.4');
        $entry = $service->entry($context, 'content-entry:' . self::ENTRY_ID);

        self::assertSame('0.0.4', $model->version);
        self::assertSame('kumwe.blueprints/article', $model->extensions->{'kumwe.app/blueprint-binding'}->id);
        self::assertSame('content-model:' . self::TYPE_ID, $entry->model->id);
        self::assertSame('legal_review', $entry->extensions->{'kumwe.app/content-entry'}->workflowState);
        self::assertSame('quiet', $entry->compositionOverrides->{'hero/main'}->tone);
    }

    /**
     * Invalid Studio coordinates stop before any Content or projection-metadata store is consulted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInvalidCoordinatesAreRejectedBeforeAuthoritativeReads(): void
    {
        $models = $this->createMock(ContentModelRepository::class);
        $models->expects(self::never())->method(self::anything());
        $content = $this->createMock(ContentRepository::class);
        $content->expects(self::never())->method(self::anything());
        $bindings = $this->createMock(ContentProjectionBindingRepository::class);
        $bindings->expects(self::never())->method(self::anything());
        $service = $this->service($models, $content, $bindings);
        $context = $this->allowedContext();

        $this->assertRefusal(
            fn () => $service->model($context, 'blueprint:' . self::TYPE_ID),
            StudioProjectionRejection::InvalidIdentifier,
        );
        $this->assertRefusal(
            fn () => $service->model($context, 'content-model:' . self::TYPE_ID, '4'),
            StudioProjectionRejection::InvalidIdentifier,
        );
        $this->assertRefusal(
            fn () => $service->entry($context, 'entry:' . self::ENTRY_ID),
            StudioProjectionRejection::InvalidIdentifier,
        );
    }

    /**
     * Denial and absent model or entry rows collapse to the identical non-disclosing refusal.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDeniedAndMissingReadsCollapseToUnavailable(): void
    {
        $deniedModels = $this->createMock(ContentModelRepository::class);
        $deniedModels->expects(self::never())->method(self::anything());
        $deniedContent = $this->createMock(ContentRepository::class);
        $deniedContent->expects(self::never())->method(self::anything());
        $deniedBindings = $this->createMock(ContentProjectionBindingRepository::class);
        $deniedBindings->expects(self::never())->method(self::anything());
        $this->assertRefusal(
            fn () => $this->service($deniedModels, $deniedContent, $deniedBindings)
                ->models(AuthorizationContext::human([])),
            StudioProjectionRejection::Unavailable,
        );

        $missingModels = $this->createMock(ContentModelRepository::class);
        $missingModels->expects(self::once())
            ->method('contentType')
            ->with(self::callback(self::isDefaultSite(...)), self::TYPE_ID, 4)
            ->willReturn(null);
        $missingModelBindings = $this->createMock(ContentProjectionBindingRepository::class);
        $missingModelBindings->expects(self::never())->method(self::anything());
        $this->assertRefusal(
            fn () => $this->service(
                $missingModels,
                $this->createStub(ContentRepository::class),
                $missingModelBindings,
            )->model($this->allowedContext(), 'content-model:' . self::TYPE_ID, '0.0.4'),
            StudioProjectionRejection::Unavailable,
        );

        $missingContent = $this->createMock(ContentRepository::class);
        $missingContent->expects(self::once())
            ->method('find')
            ->with(self::ENTRY_ID, false)
            ->willReturn(null);
        $missingEntryBindings = $this->createMock(ContentProjectionBindingRepository::class);
        $missingEntryBindings->expects(self::never())->method(self::anything());
        $this->assertRefusal(
            fn () => $this->service(
                $this->createStub(ContentModelRepository::class),
                $missingContent,
                $missingEntryBindings,
            )->entry($this->allowedContext(), 'content-entry:' . self::ENTRY_ID),
            StudioProjectionRejection::Unavailable,
        );
    }

    /**
     * An entry whose exact custom workflow version is absent is unavailable before overrides are queried.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMissingPinnedWorkflowCollapsesToUnavailableBeforeOverrideLookup(): void
    {
        $definition = $this->definition(self::WORKFLOW_ID, 3);
        $models = $this->createMock(ContentModelRepository::class);
        $models->expects(self::once())
            ->method('contentType')
            ->with(self::callback(self::isDefaultSite(...)), self::TYPE_ID, 4)
            ->willReturn($definition);
        $models->expects(self::once())
            ->method('workflow')
            ->with(self::callback(self::isDefaultSite(...)), self::WORKFLOW_ID, 3)
            ->willReturn(null);
        $content = $this->createMock(ContentRepository::class);
        $content->expects(self::once())
            ->method('find')
            ->with(self::ENTRY_ID, false)
            ->willReturn($this->record());
        $bindings = $this->createMock(ContentProjectionBindingRepository::class);
        $bindings->expects(self::never())->method(self::anything());

        $this->assertRefusal(
            fn () => $this->service($models, $content, $bindings)
                ->entry($this->allowedContext(), 'content-entry:' . self::ENTRY_ID),
            StudioProjectionRejection::Unavailable,
        );
    }

    /**
     * Preview binding reuses the authorized entry projection and proves the exact Blueprint lock.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPreviewBindingReadsOnlyAuthorizedProjectedEntryValues(): void
    {
        $definition = $this->definition(self::WORKFLOW_ID, 3);
        $models = $this->createMock(ContentModelRepository::class);
        $models->expects(self::exactly(3))
            ->method('contentType')
            ->with(self::callback(self::isDefaultSite(...)), self::TYPE_ID, 4)
            ->willReturn($definition);
        $models->expects(self::once())
            ->method('workflow')
            ->with(self::callback(self::isDefaultSite(...)), self::WORKFLOW_ID, 3)
            ->willReturn($this->workflow());
        $content = $this->createMock(ContentRepository::class);
        $content->expects(self::once())
            ->method('find')
            ->with(self::ENTRY_ID, false)
            ->willReturn($this->record());
        $bindings = $this->createMock(ContentProjectionBindingRepository::class);
        $bindings->expects(self::exactly(2))
            ->method('blueprint')
            ->with(self::callback(self::isDefaultSite(...)), self::TYPE_ID, 4)
            ->willReturn($this->binding());
        $bindings->expects(self::once())
            ->method('overrides')
            ->with(self::callback(self::isDefaultSite(...)), self::ENTRY_ID)
            ->willReturn($this->overrides());
        $context = $this->allowedContext();
        $service = $this->service($models, $content, $bindings);
        $model = $service->model($context, 'content-model:' . self::TYPE_ID, '0.0.4');
        $coordinate = $model->extensions->{'kumwe.app/blueprint-binding'};
        $document = (object) [
            'kind' => 'blueprint',
            'id' => $coordinate->id,
            'version' => $coordinate->version,
            'revision' => $coordinate->revision,
            'model' => (object) [
                'id' => $model->id,
                'version' => $model->version,
                'revision' => $model->revision,
            ],
            'roots' => [],
        ];
        $session = new StudioHostSession(
            'contexts/content-preview',
            AuthorizationContext::SUBJECT,
            SiteContext::DEFAULT,
            null,
            null,
            'administrator',
            hash('sha256', 'content-preview-session'),
            StudioSessionMode::Content,
            StudioResourceKind::Content,
            'content-entry:' . self::ENTRY_ID,
            'session-content-preview',
        );

        $values = (new ContentStudioPreviewBindingSource($service))->resolve(
            $context,
            new StudioHostSessionSnapshot(
                $session,
                ['studio.permission/read'],
                $session->sessionGeneration,
                true,
                false,
                false,
            ),
            new StudioPreviewDraft(SiteContext::DEFAULT, $document),
        );

        self::assertSame('Exact body.', $values->entry()->data_body);
        self::assertFalse(property_exists($values->entry(), 'compositionOverrides'));
        self::assertSame([], get_object_vars($values->context()));
    }

    /**
     * Compose the service from the real authorized Content facades and pure projector.
     *
     * @param   ContentModelRepository              $models    Content definition and workflow store double.
     * @param   ContentRepository                   $content   Content entry store double.
     * @param   ContentProjectionBindingRepository  $bindings  Projection metadata store double.
     *
     * @return  StudioContentProjectionService
     *
     * @since   2.0.0
     */
    private function service(
        ContentModelRepository $models,
        ContentRepository $content,
        ContentProjectionBindingRepository $bindings,
    ): StudioContentProjectionService {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(self::now());
        $authorization = AuthorizationContext::gateway();
        $transactions = new ImmediateTransactionManager();
        $audit = $this->createStub(AuditRecorder::class);

        return new StudioContentProjectionService(
            new ContentModelService(
                $models,
                new JsonSchemaValidator(),
                new SchemaCompatibilityChecker(),
                $authorization,
                AuthorizationContext::ownershipWriter(),
                $audit,
                $transactions,
                $clock,
            ),
            new ContentService(
                $content,
                $audit,
                $transactions,
                $clock,
                new Workflow(),
                $authorization,
                AuthorizationContext::ownershipWriter(),
            ),
            $bindings,
            new ContentStudioProjector(
                StudioDocumentSchemaRegistry::fromVendoredCorpus(),
                new RecordAuthorizedStudioContentFieldDisclosure(),
                new JsonSchemaValidator(),
            ),
        );
    }

    /**
     * Compose the Content composition boundary around scenario-specific persistence doubles.
     *
     * @param   StudioContentProjectionService        $projection    Authorized Content projection.
     * @param   ContentProjectionBindingRepository    $bindings      Binding read model double.
     * @param   ContentBlueprintBindingStore          $bindingStore  Binding write model double.
     * @param   StudioArtifactAdmission               $admission     Canonical artifact admission.
     * @param   StudioArtifactRepository              $artifacts     Artifact repository double.
     * @param   ClockInterface                        $clock         Deterministic audit clock.
     * @param   StudioCompositionContributionCatalog  $catalog       Live contribution catalogue.
     * @param   StudioPublishedTheme                  $theme         Exact public theme projection.
     *
     * @return  StudioContentCompositionService  Service under the requested race or failure scenario.
     *
     * @since   2.0.0
     */
    private function compositionService(
        StudioContentProjectionService $projection,
        ContentProjectionBindingRepository $bindings,
        ContentBlueprintBindingStore $bindingStore,
        StudioArtifactAdmission $admission,
        StudioArtifactRepository $artifacts,
        ClockInterface $clock,
        StudioCompositionContributionCatalog $catalog,
        StudioPublishedTheme $theme,
    ): StudioContentCompositionService {
        return new StudioContentCompositionService(
            $projection,
            $bindings,
            $bindingStore,
            $admission,
            $artifacts,
            new ImmediateTransactionManager(),
            $this->createStub(AuditRecorder::class),
            $clock,
            $catalog,
            $theme,
        );
    }

    /**
     * Build the version-four Content definition projected by the service.
     *
     * @param   string  $workflowId       Workflow coordinate pinned by this version.
     * @param   int     $workflowVersion  Exact workflow version pinned by this version.
     *
     * @return  ContentTypeDefinition
     *
     * @since   2.0.0
     */
    private function definition(
        string $workflowId = ContentService::CORE_WORKFLOW_ID,
        int $workflowVersion = 1,
    ): ContentTypeDefinition {
        return new ContentTypeDefinition(
            self::TYPE_ID,
            SiteContext::default(),
            'article',
            'Article',
            $workflowId,
            $workflowVersion,
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
     * Build the custom-workflow record returned by the authoritative Content service.
     *
     * @return  ContentRecord
     *
     * @since   2.0.0
     */
    private function record(): ContentRecord
    {
        return new ContentRecord(
            ContentEntry::reconstitute(
                self::ENTRY_ID,
                'Exact title',
                'exact-entry',
                ['body' => 'Exact body.'],
                'legal_review',
                PublicationWindow::unbounded(),
                5,
            ),
            self::TYPE_ID,
            self::WORKFLOW_ID,
            self::now(),
            self::now(),
            contentTypeVersion: 4,
            workflowVersion: 3,
        );
    }

    /**
     * Build the exact custom workflow version pinned by the record.
     *
     * @return  WorkflowDefinition
     *
     * @since   2.0.0
     */
    private function workflow(): WorkflowDefinition
    {
        return new WorkflowDefinition(
            self::WORKFLOW_ID,
            SiteContext::default(),
            'editorial',
            'Editorial',
            [
                new WorkflowStateDefinition('writing', 'Writing', true, false),
                new WorkflowStateDefinition('legal_review', 'Legal review', false, false),
                new WorkflowStateDefinition('live', 'Live', false, true),
            ],
            [],
            3,
            self::now(),
            self::now(),
        );
    }

    /**
     * Build the exact-version Blueprint coordinate returned for a model.
     *
     * @return  ContentBlueprintBinding
     *
     * @since   2.0.0
     */
    private function binding(): ContentBlueprintBinding
    {
        return new ContentBlueprintBinding(
            SiteContext::default(),
            self::TYPE_ID,
            4,
            'kumwe.blueprints/article',
            '1.5.0',
            'artifact-22',
            2,
        );
    }

    /**
     * Build the per-entry composition overrides returned after every authoritative read succeeds.
     *
     * @return  EntryCompositionOverrides
     *
     * @since   2.0.0
     */
    private function overrides(): EntryCompositionOverrides
    {
        return new EntryCompositionOverrides(
            SiteContext::default(),
            self::ENTRY_ID,
            (object) ['hero/main' => (object) ['tone' => 'quiet']],
            6,
        );
    }

    /**
     * Return an authorized Content reader at the default site.
     *
     * @return  ExecutionContext
     *
     * @since   2.0.0
     */
    private function allowedContext(): ExecutionContext
    {
        return AuthorizationContext::human(['content.read']);
    }

    /**
     * Build one capability-gated, renderer-supported extension contribution for provisioning tests.
     *
     * @return  StudioCompositionContributionCatalog
     *
     * @since   2.0.0
     */
    private function compositionCatalog(): StudioCompositionContributionCatalog
    {
        $owner = ContributionOwner::extension('acme/shop');
        $document = json_decode(
            StudioContractResources::testkitBytes('fixtures/block.grid.example.json'),
            false,
            32,
            JSON_THROW_ON_ERROR,
        );
        self::assertInstanceOf(\stdClass::class, $document);
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
            'acme.shop.catalog.edit',
        );
        $registries = new ExtensionContributionRegistrySet();
        $registries->canonicalCompositionDocuments()->register($owner, $canonical);
        $registries->compositionHostBindings()->register($owner, $binding);
        $preview = new class implements StudioPreviewBlockRenderer {
            /**
             * Emit a fixed grid placeholder fragment regardless of block, binding or viewport.
             *
             * @param   StudioPreviewBlock          $block     Immutable copied contributed grid input.
             * @param   StudioPreviewBindingResult  $binding   Authorized binding projection.
             * @param   string                      $viewport  Active semantic viewport.
             *
             * @return  StudioPreviewBlockFragment  Constant placeholder fragment.
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
        $registries->studioPreviewRenderers()->register(
            $owner,
            new StudioPreviewRendererContribution($owner, '1.0.0', $canonical, $binding),
            self::trustFencedPreviewRenderer($preview, 'acme/shop'),
        );

        return new StudioCompositionContributionCatalog(
            $registries,
            new StudioBlockRendererRuntime($registries, new StudioContentFieldBlockRenderer()),
        );
    }

    /**
     * Return the exact block types from projected canonical contribution documents.
     *
     * @param   list<\stdClass>  $documents  Projected canonical documents.
     *
     * @return  list<string>  Exact projected block types.
     *
     * @since   2.0.0
     */
    private static function compositionBlockTypes(array $documents): array
    {
        return array_values(array_map(
            static fn (\stdClass $document): string => $document->type,
            array_filter(
                $documents,
                static fn (\stdClass $document): bool => ($document->kind ?? null) === 'block-definition',
            ),
        ));
    }

    /**
     * Recognize the exact tenant scope expected by every repository call.
     *
     * @param   SiteContext  $site  Site received by a repository double.
     *
     * @return  bool
     *
     * @since   2.0.0
     */
    private static function isDefaultSite(SiteContext $site): bool
    {
        return $site->identifier() === SiteContext::DEFAULT;
    }

    /**
     * Build one model host request carrying the exact runtime value under test.
     *
     * @param   mixed        $arguments         Candidate model operation arguments.
     * @param   string|null  $expectedRevision  Optional forbidden read revision.
     * @param   string|null  $idempotencyKey    Optional forbidden read idempotency key.
     *
     * @return  StudioProducerRequest  Authorized Producer request scope.
     *
     * @since   2.0.0
     */
    private static function modelRequest(
        mixed $arguments,
        ?string $expectedRevision = null,
        ?string $idempotencyKey = null,
    ): StudioProducerRequest {
        return StudioProducerRequest::authorized(
            'studio.operation/model.get',
            $arguments,
            $expectedRevision,
            $idempotencyKey,
        );
    }

    /**
     * Execute one direct model.get call.
     *
     * @param   StudioModelHostPort    $port     Model host port under test.
     * @param   StudioProducerRequest  $request  Authorized Producer request scope.
     *
     * @return  HostResult  Exact host result of the get operation.
     *
     * @since   2.0.0
     */
    private static function modelGet(StudioModelHostPort $port, StudioProducerRequest $request): HostResult
    {
        return $port->forRequest($request->authority)->get($request->arguments(), $request->context());
    }

    /**
     * Execute one direct model.list call.
     *
     * @param   StudioModelHostPort    $port     Model host port under test.
     * @param   StudioProducerRequest  $request  Authorized Producer request scope.
     *
     * @return  HostResult  Exact host result of the list operation.
     *
     * @since   2.0.0
     */
    private static function modelList(StudioModelHostPort $port, StudioProducerRequest $request): HostResult
    {
        return $port->forRequest($request->authority)->list($request->arguments(), $request->context());
    }

    /**
     * Assert one model host request is refused with its exact non-disclosing diagnostic.
     *
     * @param   callable(): mixed  $operation  Model host operation expected to fail closed.
     * @param   string             $category   Expected canonical refusal category.
     * @param   string             $code       Expected canonical diagnostic code.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertHostRefusal(callable $operation, string $category, string $code): void
    {
        try {
            $operation();
            self::fail('The malformed Studio model host request unexpectedly succeeded.');
        } catch (HostRefusal $refused) {
            self::assertSame($category, $refused->error()->category());
            self::assertSame($code, $refused->error()->diagnostics()[0]->code());
        }
    }

    /**
     * Assert one service read stops with a non-disclosing typed refusal.
     *
     * @param   callable(): mixed          $operation  Read expected to stop.
     * @param   StudioProjectionRejection  $reason     Stable refusal category.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertRefusal(callable $operation, StudioProjectionRejection $reason): void
    {
        try {
            $operation();
            self::fail('The Studio Content service unexpectedly disclosed a document.');
        } catch (StudioProjectionRejected $failure) {
            self::assertSame($reason, $failure->rejection);
            self::assertSame('', $failure->path);
            self::assertSame('The requested content projection is unavailable.', $failure->getMessage());
            $diagnostic = json_encode($failure->diagnostic(), JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString(self::TYPE_ID, $diagnostic);
            self::assertStringNotContainsString(self::ENTRY_ID, $diagnostic);
        }
    }

    /**
     * Assert one composition operation stops with its exact stable runtime diagnostic.
     *
     * @param   callable(): mixed  $operation  Composition operation that must fail closed.
     * @param   string             $message    Exact expected diagnostic.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertRuntimeFailure(callable $operation, string $message): void
    {
        try {
            $operation();
            self::fail('The incompatible Studio Content composition was accepted.');
        } catch (RuntimeException $failure) {
            self::assertSame($message, $failure->getMessage());
        }
    }

    /**
     * Return the deterministic timestamp shared by every test fixture.
     *
     * @return  DateTimeImmutable
     *
     * @since   2.0.0
     */
    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-24T12:00:00+00:00');
    }
}
