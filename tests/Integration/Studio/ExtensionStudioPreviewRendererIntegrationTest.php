<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Studio;

use DateTimeImmutable;
use FilesystemIterator;
use Kumwe\App\Administrator\Http\Handler\AdministratorExtensionsHandler;
use Kumwe\App\Identity\Application\Administration\AdministratorSession;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Extension\Application\ExtensionManager;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Contribution\CanonicalManifestActivator;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Contribution\OwnedExtensionBindingRegistrar;
use Kumwe\App\Extension\Contribution\StudioPreviewRendererContribution;
use Kumwe\Extension\Package\PackageChecksum;
use Kumwe\Extension\Package\PackageSignatureMessage;
use Kumwe\App\Extension\Infrastructure\DoctrineExtensionManager;
use Kumwe\App\Extension\Runtime\ActiveExtensionSet;
use Kumwe\App\Extension\Runtime\ExtensionRuntimeMapCompiler;
use Kumwe\App\Extension\Runtime\ExtensionRuntimeLoader;
use Kumwe\App\Extension\Runtime\RuntimeMaterializationState;
use Kumwe\App\Extension\Runtime\TrustEnforcingStudioPreviewBlockRenderer;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Studio\Application\Rendering\FragmentStudioPreviewBlockRenderer;
use Kumwe\App\Studio\Application\Rendering\StudioBlockRendererRuntime;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Laminas\Diactoros\ServerRequestFactory;
use Kumwe\Producer\Render\BlockCoordinate;
use Kumwe\Producer\Render\CompositionRenderer;
use Kumwe\Producer\Render\RenderContext;
use Kumwe\Producer\Render\RenderException;
use Kumwe\Producer\Render\RenderPolicy;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use stdClass;
use Throwable;
use ZipArchive;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;

#[CoversClass(StudioBlockRendererRuntime::class)]
#[CoversClass(AdministratorExtensionsHandler::class)]
#[CoversClass(StudioPreviewRendererContribution::class)]
#[CoversClass(TrustEnforcingStudioPreviewBlockRenderer::class)]
#[CoversClass(ActiveExtensionSet::class)]
#[UsesClass(DoctrineExtensionManager::class)]
#[CoversClass(OwnedExtensionBindingRegistrar::class)]
#[UsesClass(CanonicalManifestActivator::class)]
#[CoversClass(ExtensionRuntimeLoader::class)]
/**
 * Proves schema-six preview code crosses the real signed extension lifecycle without manifest execution.
 *
 * @since  2.0.0
 */
final class ExtensionStudioPreviewRendererIntegrationTest extends TestCase
{
    /**
     * Contract-grammar preview marker for the fixture's one grid node (sha256 of `grid-node`).
     *
     * @var    string
     * @since  2.0.0
     */
    private const string GRID_MARKER =
        'studio.preview/node/d78336768c8cfc767454d3e2bafc54f62029c9d31aa281805075032763e0342b/0';

    /**
     * Install, activate and render the signed fixture, then prove every lifecycle fence fails closed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSignedOwnerRendererExecutesOnlyAtItsExactLiveCoordinates(): void
    {
        $environment = Environment::fromGlobals();
        $container = TestKernelFactory::create($environment);
        $manager = self::service($container, ExtensionManager::class);
        $trust = self::service($container, TrustStore::class);
        $context = TestKernelFactory::administratorContext($container);
        $marker = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $identifier = 'integration/studio-preview-' . $marker;
        $missingIdentifier = 'integration/studio-preview-missing-' . $marker;
        $keyId = 'integration.studio-preview.' . $marker;
        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $archives = [];
        $installed = [];

        try {
            $trust->add(
                $context,
                $keyId,
                base64_encode(sodium_crypto_sign_publickey($keyPair)),
                'integration',
                '*',
                new DateTimeImmutable('+1 year'),
            );
            $archives[] = $baseArchive = $this->fixturePackage($identifier, '1.0.0', false);
            $manager->install($baseArchive, $context, $keyId, self::signature($baseArchive, $secretKey));
            $installed[] = $identifier;
            $manager->activate($identifier, $context);
            $trust->synchronizeRuntimeMaterialization();

            $runtime = TestKernelFactory::create($environment);
            $materialization = self::service($runtime, RuntimeMaterializationState::class);
            $compiler = self::service($runtime, ExtensionRuntimeMapCompiler::class);
            self::assertTrue(
                $compiler->matchesAuthority($materialization),
                'The loaded preview publication must still match database authority.',
            );
            self::assertTrue(
                $compiler->isCurrent($materialization),
                'The loaded preview publication must retain its live replica lease.',
            );
            $publication = $materialization->publication;
            self::assertNotNull($publication);
            $runtimeEntry = null;
            foreach ($publication->document['extensions'] ?? [] as $candidate) {
                if (is_array($candidate) && ($candidate['identifier'] ?? null) === $identifier) {
                    $runtimeEntry = $candidate;
                    break;
                }
            }
            self::assertIsArray($runtimeEntry);
            $runtimeTrust = self::service($runtime, TrustStore::class);
            $runtimeTrust->synchronizedLifecycle(
                static function () use ($runtimeTrust, $runtimeEntry): void {
                    $runtimeTrust->enforceRuntimeEntryTrust($runtimeEntry);
                },
            );
            $blocks = self::service($runtime, StudioBlockRendererRuntime::class);
            $registries = self::service($runtime, ExtensionContributionRegistrySet::class);
            self::assertExtensionsScreenRenders($runtime, $identifier);
            $definition = self::rendererDefinition($registries, $identifier);
            $namespace = str_replace('/', '.', $identifier);
            self::assertSame('1.0.0', $definition->runtimeVersion);
            self::assertSame($namespace . '/grid', $definition->blockType);
            self::assertSame('1.0.0', $definition->blockVersion);
            self::assertSame('grid-block-r1', $definition->blockRevision);
            self::assertSame($namespace . '/grid-preview', $definition->renderer);
            self::assertSame($namespace . '/grid', $definition->previewCapability);
            self::assertSame('^1.0.0', $definition->previewCapabilityVersions);
            $exactCoordinate = new BlockCoordinate(
                $namespace . '/grid',
                '1.0.0',
                'grid-block-r1',
            );
            $rendererRegistry = $blocks->registry();
            self::assertTrue($rendererRegistry->supports($exactCoordinate));
            self::assertTrue(self::rendererImplementation($registries, $identifier)->isAvailable());
            self::assertInstanceOf(
                FragmentStudioPreviewBlockRenderer::class,
                $rendererRegistry->rendererFor($exactCoordinate),
            );

            $exact = self::document($namespace, '1.0.0', 'grid-block-r1');
            self::assertStringContainsString(
                'data-studio-preview-marker="' . self::GRID_MARKER . '"',
                self::render($blocks, $exact),
            );
            self::assertStringContainsString(
                '<section class="studio-preview-extension-grid">',
                self::render($blocks, $exact),
            );
            self::assertStringContainsString(
                'Contributed grid: 3 columns',
                self::render($blocks, $exact),
            );

            $wrongRevision = self::document($namespace, '1.0.0', 'grid-block-r2');
            self::assertFalse($rendererRegistry->supports(new BlockCoordinate(
                $namespace . '/grid',
                '1.0.0',
                'grid-block-r2',
            )));
            self::assertRenderRefused($blocks, $wrongRevision);
            $wrongVersion = self::document($namespace, '2.0.0', 'grid-block-r1');
            self::assertRenderRefused($blocks, $wrongVersion);

            $archives[] = $upgradeArchive = $this->fixturePackage($identifier, '1.1.0', false);
            $upgraded = $manager->install(
                $upgradeArchive,
                $context,
                $keyId,
                self::signature($upgradeArchive, $secretKey),
            );
            self::assertSame('1.1.0', $upgraded['installed_version'] ?? null);
            self::assertFalse($blocks->registry()->supports($exactCoordinate));
            self::assertRenderRefused($blocks, $exact);
            if (($upgraded['status'] ?? null) !== 'active') {
                $manager->activate($identifier, $context);
            }
            $trust->synchronizeRuntimeMaterialization();
            $upgradedRuntime = TestKernelFactory::create($environment);
            $upgradedBlocks = self::service($upgradedRuntime, StudioBlockRendererRuntime::class);
            $upgradedRegistries = self::service($upgradedRuntime, ExtensionContributionRegistrySet::class);
            self::assertSame('1.1.0', self::rendererDefinition(
                $upgradedRegistries,
                $identifier,
            )->runtimeVersion);
            self::assertTrue($upgradedBlocks->registry()->supports($exactCoordinate));
            self::assertStringContainsString('studio-preview-extension-grid', self::render(
                $upgradedBlocks,
                $exact,
            ));

            $manager->disable($identifier, $context);
            self::assertFalse($upgradedBlocks->registry()->supports($exactCoordinate));
            self::assertRenderRefused($upgradedBlocks, $exact);

            $archives[] = $missingArchive = $this->fixturePackage($missingIdentifier, '1.0.0', true);
            $manager->install($missingArchive, $context, $keyId, self::signature($missingArchive, $secretKey));
            $installed[] = $missingIdentifier;
            $manager->activate($missingIdentifier, $context);
            $trust->synchronizeRuntimeMaterialization();
            // Binding is eager and mandatory: a provider whose declared renderer service is not the
            // SDK preview contract must fail the runtime load loudly instead of shipping a silent gap.
            try {
                TestKernelFactory::create($environment);
                self::fail('A bound preview service outside the SDK renderer contract must refuse to load.');
            } catch (LogicException $exception) {
                self::assertSame('The manifest-six preview renderer is unavailable.', $exception->getMessage());
            }
        } finally {
            foreach (array_reverse($installed) as $identifierToRemove) {
                try {
                    $manager->disable($identifierToRemove, $context);
                } catch (Throwable) {
                }
                try {
                    $manager->uninstall($identifierToRemove, $context);
                } catch (Throwable) {
                }
            }
            foreach ($archives as $archive) {
                if (is_file($archive)) {
                    unlink($archive);
                }
            }
        }
    }

    /**
     * Build a unique package from the committed manifest-six compatibility fixture.
     *
     * @param   string  $identifier      Per-test extension identifier.
     * @param   string  $version         Exact package runtime version.
     * @param   bool    $missingService  Whether the bound service deliberately resolves outside the
     *          SDK renderer contract.
     *
     * @return  string  Absolute archive path.
     *
     * @throws  RuntimeException  When the fixture cannot be packaged.
     *
     * @since   2.0.0
     */
    private function fixturePackage(string $identifier, string $version, bool $missingService): string
    {
        $archive = tempnam(sys_get_temp_dir(), 'kumwe-studio-preview-extension-');
        if (!is_string($archive)) {
            throw new RuntimeException('The Studio preview fixture archive cannot be allocated.');
        }
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The Studio preview fixture archive cannot be opened.');
        }
        $root = dirname(__DIR__, 3)
            . '/vendor/kumwe/extension-sdk/resources/fixtures/generations/manifest-6';
        $dotted = str_replace('/', '.', $identifier);
        $phpNamespace = 'IntegrationStudioPreview\\R' . substr(hash('sha256', $identifier), 0, 12);
        $jsonNamespace = str_replace('\\', '\\\\', $phpNamespace);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        try {
            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $contents = file_get_contents($file->getPathname());
                if (!is_string($contents)) {
                    throw new RuntimeException('A Studio preview fixture file cannot be read.');
                }
                $relative = substr($file->getPathname(), strlen($root) + 1);
                if ($relative === 'src/Definitions.php') {
                    // Canonical constants are source-wrapped across adjacent literals. Join them before
                    // re-owning so a namespace split at a line boundary cannot survive inside signed bytes.
                    $joined = preg_replace("/'\\s*\\.\\s*'/", '', $contents);
                    if (!is_string($joined)) {
                        throw new RuntimeException('The Studio preview fixture definitions cannot be joined.');
                    }
                    $contents = $joined;
                }
                $contents = str_replace(
                    [
                        'KumweContract\\\\ManifestSix',
                        'KumweContract\\ManifestSix',
                        'kumwe/contract-manifest-six',
                        'kumwe.contract-manifest-six',
                    ],
                    [$jsonNamespace, $phpNamespace, $identifier, $dotted],
                    $contents,
                );
                if ($relative === 'src/Definitions.php') {
                    self::assertStringNotContainsString('kumwe.contract-manifest-six', $contents);
                    self::assertStringNotContainsString('kumwe/contract-manifest-six', $contents);
                }
                if ($relative === 'kumwe.json') {
                    $manifest = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
                    if (!is_array($manifest)) {
                        throw new RuntimeException('The Studio preview fixture manifest is invalid.');
                    }
                    $manifest['version'] = $version;
                    $requirements = $manifest['requires'] ?? null;
                    if (!is_array($requirements)) {
                        throw new RuntimeException('The Studio preview fixture requirements are invalid.');
                    }
                    $requirements['php'] = '^8.3.0';
                    $manifest['requires'] = $requirements;
                    $encoded = json_encode(
                        $manifest,
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                    );
                    $contents = $encoded . "\n";
                }
                if ($missingService && $relative === 'src/Provider.php') {
                    $contents = str_replace(
                        ': GridPreviewRenderer => new GridPreviewRenderer(),',
                        ': object => new \stdClass(),',
                        $contents,
                    );
                }
                if (!$zip->addFromString($relative, $contents)) {
                    throw new RuntimeException('A Studio preview fixture file cannot be packaged.');
                }
            }
        } finally {
            $zip->close();
        }

        return $archive;
    }

    /**
     * Sign one archive checksum with the fixture's private key.
     *
     * @param   string  $archive    Absolute package archive path.
     * @param   string  $secretKey  Sodium Ed25519 secret key bytes.
     *
     * @return  string  Base64 detached signature.
     *
     * @throws  RuntimeException  When the archive cannot be read.
     *
     * @since   2.0.0
     */
    private static function signature(string $archive, string $secretKey): string
    {
        if ($secretKey === '') {
            throw new RuntimeException('The Studio preview fixture signing key is unavailable.');
        }
        $bytes = file_get_contents($archive);
        if (!is_string($bytes)) {
            throw new RuntimeException('The Studio preview fixture archive cannot be signed.');
        }

        return base64_encode(sodium_crypto_sign_detached(
            PackageSignatureMessage::forChecksum(PackageChecksum::calculate($bytes)),
            $secretKey,
        ));
    }

    /**
     * Build one minimal Blueprint locked to a contributed grid definition.
     *
     * @param   string  $namespace  Dotted package namespace.
     * @param   string  $version    Node and dependency-lock version.
     * @param   string  $revision   Immutable dependency-lock revision.
     *
     * @return  stdClass  Minimal canonical preview input.
     *
     * @since   2.0.0
     */
    private static function document(string $namespace, string $version, string $revision): stdClass
    {
        $type = $namespace . '/grid';

        return (object) [
            'kind' => 'blueprint',
            'id' => $namespace . '/preview-proof',
            'revision' => 'blueprint-r1',
            'dependencyLock' => (object) ['blocks' => [(object) [
                'type' => $type,
                'version' => $version,
                'revision' => $revision,
            ]]],
            'roots' => [(object) [
                'id' => 'grid-node',
                'type' => $type,
                'version' => $version,
                'properties' => (object) ['columns' => 3, 'collapse' => 'stack'],
                'bindings' => new stdClass(),
                'slots' => (object) ['items' => []],
            ]],
        ];
    }

    /**
     * Render one-node preview input through the canonical structural composition path.
     *
     * @param   StudioBlockRendererRuntime  $runtime   Live runtime-composed Producer registry.
     * @param   stdClass                   $document  Blueprint input.
     *
     * @return  string  Safe structural markup.
     *
     * @since   2.0.0
     */
    private static function render(StudioBlockRendererRuntime $runtime, stdClass $document): string
    {
        return (new CompositionRenderer($runtime->registry()))->renderDocument(
            $document,
            new RenderContext(
                previewMarkerMap: [self::GRID_MARKER => 'grid-node'],
                policy: RenderPolicy::RequireRegistered,
            ),
        )->html;
    }

    /**
     * Require the canonical composition path to refuse the document at its current coordinates.
     *
     * @param   StudioBlockRendererRuntime  $runtime   Live runtime-composed Producer registry.
     * @param   stdClass                    $document  Blueprint input expected to be unregistered.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertRenderRefused(StudioBlockRendererRuntime $runtime, stdClass $document): void
    {
        try {
            self::render($runtime, $document);
            self::fail('An unregistered exact Producer coordinate must be refused.');
        } catch (RenderException $refused) {
            self::assertInstanceOf(RenderException::class, $refused);
        }
    }

    /**
     * Find the executable renderer definition owned by one active package.
     *
     * @param   ExtensionContributionRegistrySet  $registries  Runtime contribution registry set.
     * @param   string                            $identifier  Expected package owner.
     *
     * @return  StudioPreviewRendererContribution  Exact live renderer definition.
     *
     * @since   2.0.0
     */
    private static function rendererDefinition(
        ExtensionContributionRegistrySet $registries,
        string $identifier,
    ): StudioPreviewRendererContribution {
        $definition = self::optionalRendererDefinition($registries, $identifier);
        self::assertInstanceOf(StudioPreviewRendererContribution::class, $definition);

        return $definition;
    }

    /**
     * Find the trust-enforcing executable registered by one active package.
     *
     * @param   ExtensionContributionRegistrySet  $registries  Runtime contribution registry set.
     * @param   string                            $identifier  Expected package owner.
     *
     * @return  TrustEnforcingStudioPreviewBlockRenderer  Exact live trust-fenced SDK implementation.
     *
     * @since   2.0.0
     */
    private static function rendererImplementation(
        ExtensionContributionRegistrySet $registries,
        string $identifier,
    ): TrustEnforcingStudioPreviewBlockRenderer {
        foreach ($registries->studioPreviewRenderers()->executableEntries() as $entry) {
            if ($entry['owner']->identifier() !== $identifier) {
                continue;
            }
            $implementation = $entry['implementation'];
            self::assertInstanceOf(TrustEnforcingStudioPreviewBlockRenderer::class, $implementation);

            return $implementation;
        }

        self::fail('The exact live trust-fenced renderer implementation is unavailable.');
    }

    /**
     * Find an executable renderer definition without assuming the package activated one.
     *
     * @param   ExtensionContributionRegistrySet  $registries  Runtime contribution registry set.
     * @param   string                            $identifier  Expected package owner.
     *
     * @return  StudioPreviewRendererContribution|null  Definition, or null for an inert binding.
     *
     * @since   2.0.0
     */
    private static function optionalRendererDefinition(
        ExtensionContributionRegistrySet $registries,
        string $identifier,
    ): ?StudioPreviewRendererContribution {
        foreach ($registries->studioPreviewRenderers()->executableEntries() as $entry) {
            if ($entry['owner']->identifier() === $identifier) {
                $definition = $entry['definition'];

                return $definition instanceof StudioPreviewRendererContribution ? $definition : null;
            }
        }

        return null;
    }

    /**
     * Resolve and assert one typed service from the real kernel container.
     *
     * @template T of object
     *
     * @param   Container        $container  Real application container.
     * @param   class-string<T>  $class      Requested service contract.
     *
     * @return  T  Typed service.
     *
     * @since   2.0.0
     */
    private static function service(Container $container, string $class): object
    {
        $service = $container->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    /**
     * Prove the administrator extensions screen renders a composition-only extension's diagnostics.
     *
     * A manifest that declares only a Studio composition contributes no administrator section to the
     * canonical SDK graph; the screen still reads every section, so the live projection must fill it.
     *
     * @param   Container  $runtime     Fresh kernel that loaded the renderer extension.
     * @param   string     $identifier  Installed extension whose row must render.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertExtensionsScreenRenders(Container $runtime, string $identifier): void
    {
        $context = TestKernelFactory::administratorContext($runtime);
        $principal = $context->principal();
        self::assertInstanceOf(AuthenticatedPrincipal::class, $principal);
        $manager = self::service($runtime, ExtensionManager::class);
        $row = null;
        foreach ($manager->installed($context) as $candidate) {
            if (is_array($candidate) && ($candidate['identifier'] ?? null) === $identifier) {
                $row = $candidate;
            }
        }
        self::assertIsArray($row);
        self::assertIsArray($row['contributions']['composition'] ?? null);
        self::assertSame([], $row['contributions']['administrator']['routes']);
        $handler = self::service($runtime, AdministratorExtensionsHandler::class);
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/administrator/extensions')
            ->withAttribute(ExecutionContextAttribute::NAME, $context)
            ->withAttribute(AdministratorSession::REQUEST_ATTRIBUTE, new AdministratorSession(
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb39a',
                $principal,
                'preview-renderer-csrf',
                new DateTimeImmutable('+1 hour'),
            ));

        $response = $handler->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString($identifier, (string) $response->getBody());
    }
}
