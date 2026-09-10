<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Extension;

use DateTimeImmutable;
use FilesystemIterator;
use Kumwe\App\Administrator\Http\Handler\AdministratorExtensionsHandler;
use Kumwe\App\Application\Automation\JobHandlerRegistry;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Identity\Application\Administration\AdministratorSession;
use Kumwe\App\Extension\Application\ExtensionManager;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Laminas\Diactoros\ServerRequestFactory;
use Kumwe\Extension\Package\PackageChecksum;
use Kumwe\Extension\Package\PackageSignatureMessage;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Kumwe\App\Extension\Contribution\CanonicalManifestActivator;
use Kumwe\App\Extension\Contribution\CanonicalManifestInterpreter;
use Kumwe\App\Extension\Contribution\OwnedExtensionBindingRegistrar;
use Kumwe\App\Extension\Runtime\ActiveExtensionSet;
use Kumwe\App\Extension\Runtime\ExtensionRuntimeLoader;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use ZipArchive;
use Kumwe\App\Extension\Infrastructure\DoctrineExtensionManager;
use Kumwe\App\Extension\Infrastructure\RedisLockedExtensionManager;
use Kumwe\App\Extension\Contribution\CoreContributionRegistrar;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;

#[CoversClass(CoreContributionRegistrar::class)]
#[CoversClass(AdministratorExtensionsHandler::class)]
#[CoversClass(DoctrineExtensionManager::class)]
#[CoversClass(RedisLockedExtensionManager::class)]
#[CoversClass(CanonicalManifestActivator::class)]
#[CoversClass(CanonicalManifestInterpreter::class)]
#[CoversClass(OwnedExtensionBindingRegistrar::class)]
#[UsesClass(ActiveExtensionSet::class)]
#[UsesClass(ExtensionContributionRegistrySet::class)]
#[UsesClass(ExtensionRuntimeLoader::class)]
/**
 * Drives the signed SDK compatibility package of every promised manifest generation through the host.
 *
 * The SDK vendors one committed package per generation, from the schema-1 plugin through the schema-5
 * composition paraphrases; the schema-6 canonical Studio package has its own dedicated lifecycle test.
 * Each package is re-owned, signed, installed and activated against the real database, and the loaded
 * runtime's contribution registries are compared against the exact surface that generation promises —
 * so a change to what a generation gives a package fails here rather than in an author's build.
 *
 * @since  2.0.0
 */
final class ManifestGenerationLifecycleIntegrationTest extends TestCase
{
    /**
     * Install, activate and inventory every promised generation, then withdraw each cleanly.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryPromisedGenerationActivatesAndContributesItsExactSurface(): void
    {
        $environment = Environment::fromGlobals();
        $container = TestKernelFactory::create($environment);
        $manager = $container->get(ExtensionManager::class);
        $trust = $container->get(TrustStore::class);
        self::assertInstanceOf(ExtensionManager::class, $manager);
        self::assertInstanceOf(TrustStore::class, $trust);
        $context = TestKernelFactory::administratorContext($container);
        $marker = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $keyId = 'integration.generations.' . $marker;
        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $generations = [
            1 => 'one',
            2 => 'two',
            3 => 'three',
            4 => 'four',
            5 => 'five',
        ];
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
            foreach ($generations as $schema => $word) {
                $identifier = sprintf('integration/gen%d-%s', $schema, $marker);
                $archives[] = $archive = self::fixturePackage($schema, $word, $identifier);
                $manager->install($archive, $context, $keyId, self::signature($archive, $secretKey));
                $installed[] = $identifier;
                $manager->activate($identifier, $context);
            }
            $trust->synchronizeRuntimeMaterialization();

            $runtime = TestKernelFactory::create($environment);
            $registries = $runtime->get(ExtensionContributionRegistrySet::class);
            self::assertInstanceOf(ExtensionContributionRegistrySet::class, $registries);
            $worker = $runtime->get(JobHandlerRegistry::class);
            self::assertInstanceOf(JobHandlerRegistry::class, $worker);
            foreach ($generations as $schema => $word) {
                $identifier = sprintf('integration/gen%d-%s', $schema, $marker);
                $dotted = str_replace('/', '.', $identifier);
                $owner = ContributionOwner::extension($identifier);
                match ($schema) {
                    1 => self::assertSame([], $registries->inventory($owner)['capabilities']),
                    2 => self::assertGeneration2($registries, $owner, $dotted),
                    3 => self::assertGeneration3($registries, $owner, $dotted),
                    4 => self::assertGeneration4($registries, $worker, $owner, $dotted),
                    5 => self::assertGeneration5($registries, $owner),
                };
            }
            self::assertExtensionsScreenRenders($runtime, $installed);
        } finally {
            foreach (array_reverse($installed) as $identifier) {
                try {
                    $manager->disable($identifier, $context);
                } catch (Throwable) {
                }
                try {
                    $manager->uninstall($identifier, $context);
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
     * The schema-2 component contributes its graphical administrator surface.
     *
     * @param   ExtensionContributionRegistrySet  $registries  Loaded runtime contribution registries.
     * @param   ContributionOwner                 $owner       Re-owned package identity.
     * @param   string                            $dotted      Dotted contribution namespace.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertGeneration2(
        ExtensionContributionRegistrySet $registries,
        ContributionOwner $owner,
        string $dotted,
    ): void {
        $inventory = $registries->inventory($owner);
        self::assertSame($dotted . '.manage', $inventory['capabilities'][0]['id'] ?? null);
        self::assertSame($dotted . '.workspace', $inventory['administrator']['workspaces'][0]['id'] ?? null);
        self::assertSame($dotted . '.navigation', $inventory['administrator']['navigation'][0]['id'] ?? null);
        self::assertSame($dotted . '.index', $inventory['administrator']['views'][0]['name'] ?? null);
    }

    /**
     * The schema-3 component adds portal and business declarations with an executable field presenter.
     *
     * @param   ExtensionContributionRegistrySet  $registries  Loaded runtime contribution registries.
     * @param   ContributionOwner                 $owner       Re-owned package identity.
     * @param   string                            $dotted      Dotted contribution namespace.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertGeneration3(
        ExtensionContributionRegistrySet $registries,
        ContributionOwner $owner,
        string $dotted,
    ): void {
        $inventory = $registries->inventory($owner);
        self::assertSame($dotted . '.portal-workspace', $inventory['portal']['workspaces'][0]['id'] ?? null);
        self::assertSame($dotted . '.portal-status', $inventory['portal']['templates'][0]['name'] ?? null);
        self::assertTrue($registries->fieldTypes()->has($dotted . '.grade'));
        self::assertNotSame([], $registries->fieldPresentations()->contexts($dotted . '.grade'));
    }

    /**
     * The schema-4 component binds every integration executable its manifest declares, and the worker
     * registry composes its contributed job under the signed type.
     *
     * @param   ExtensionContributionRegistrySet  $registries  Loaded runtime contribution registries.
     * @param   JobHandlerRegistry                $worker      Worker-facing job registry of the same kernel.
     * @param   ContributionOwner                 $owner       Re-owned package identity.
     * @param   string                            $dotted      Dotted contribution namespace.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertGeneration4(
        ExtensionContributionRegistrySet $registries,
        JobHandlerRegistry $worker,
        ContributionOwner $owner,
        string $dotted,
    ): void {
        self::assertNotNull($registries->domainListeners()->definition($owner, $dotted . '.observe-now'));
        self::assertNotNull($registries->eventConsumers()->definition($owner, $dotted . '.observe-later'));
        self::assertNotNull($registries->jobs()->definition($owner, $dotted . '.summarize'));
        self::assertNotNull($worker->find($dotted . '.summarize'));
        self::assertNotNull($registries->projections()->definition($owner, $dotted . '.activity'));
        self::assertNotNull($registries->reports()->definition($owner, $dotted . '.summary'));
        self::assertNotNull($registries->webhooks()->definition($owner, $dotted . '.observed-webhook'));
    }

    /**
     * The schema-5 component contributes its declarative composition paraphrases.
     *
     * @param   ExtensionContributionRegistrySet  $registries  Loaded runtime contribution registries.
     * @param   ContributionOwner                 $owner       Re-owned package identity.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertGeneration5(
        ExtensionContributionRegistrySet $registries,
        ContributionOwner $owner,
    ): void {
        foreach (
            [
            $registries->compositionBlocks(),
            $registries->compositionPatterns(),
            $registries->compositionFieldControls(),
            $registries->compositionInspectors(),
            $registries->compositionDesignVocabularies(),
            $registries->compositionMigrations(),
            ] as $registry
        ) {
            $owned = array_filter(
                $registry->entries(),
                static fn (array $entry): bool => $entry['owner']->identifier() === $owner->identifier(),
            );
            self::assertNotSame([], $owned);
        }
    }

    /**
     * Build one unique signed package from a vendored SDK generation fixture.
     *
     * @param   int     $schema      Manifest schema the fixture proves.
     * @param   string  $word        Fixture namespace word for the generation.
     * @param   string  $identifier  Per-test extension identifier.
     *
     * @return  string  Absolute archive path.
     *
     * @throws  RuntimeException  When the fixture cannot be packaged.
     *
     * @since   2.0.0
     */
    private static function fixturePackage(int $schema, string $word, string $identifier): string
    {
        // The schema-4 fixture pins one business-definition identity; a run-unique identity keeps a
        // reused database from seeing a second owner claim a catalog row it already recorded.
        $definitionId = Uuid::uuid7()->toString();
        $archive = tempnam(sys_get_temp_dir(), 'kumwe-generation-extension-');
        if (!is_string($archive)) {
            throw new RuntimeException('The generation fixture archive cannot be allocated.');
        }
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The generation fixture archive cannot be opened.');
        }
        $root = dirname(__DIR__, 3)
            . '/vendor/kumwe/extension-sdk/resources/fixtures/generations/manifest-' . $schema;
        $dotted = str_replace('/', '.', $identifier);
        $phpNamespace = 'IntegrationGeneration\\G' . substr(hash('sha256', $identifier), 0, 12);
        $jsonNamespace = str_replace('\\', '\\\\', $phpNamespace);
        $fixtureNamespace = 'KumweContract\\Manifest' . ucfirst($word);
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
                    throw new RuntimeException('A generation fixture file cannot be read.');
                }
                $relative = substr($file->getPathname(), strlen($root) + 1);
                if ($relative === 'src/Definitions.php') {
                    // Canonical constants are source-wrapped across adjacent literals. Join them before
                    // re-owning so a namespace split at a line boundary cannot survive inside signed bytes.
                    $joined = preg_replace("/'\\s*\\.\\s*'/", '', $contents);
                    if (!is_string($joined)) {
                        throw new RuntimeException('The generation fixture definitions cannot be joined.');
                    }
                    $contents = $joined;
                }
                $contents = str_replace(
                    [
                        str_replace('\\', '\\\\', $fixtureNamespace),
                        $fixtureNamespace,
                        'kumwe/contract-manifest-' . $word,
                        'kumwe.contract-manifest-' . $word,
                        '018f22e2-7c8b-7ab0-8f3a-88e8026bc104',
                    ],
                    [$jsonNamespace, $phpNamespace, $identifier, $dotted, $definitionId],
                    $contents,
                );
                if ($relative === 'kumwe.json') {
                    $contents = str_replace('"php": "^8.5.0"', '"php": "^8.3.0"', $contents);
                }
                if (!$zip->addFromString($relative, $contents)) {
                    throw new RuntimeException('A generation fixture file cannot be packaged.');
                }
            }
        } finally {
            $zip->close();
        }

        return $archive;
    }

    /**
     * Sign one archive checksum with the test's ephemeral private key.
     *
     * @param   string  $archive    Absolute archive path.
     * @param   string  $secretKey  Raw Ed25519 secret key bytes.
     *
     * @return  string  Base64 detached signature over the package checksum message.
     *
     * @throws  RuntimeException  When the archive bytes cannot be read.
     *
     * @since   2.0.0
     */
    private static function signature(string $archive, string $secretKey): string
    {
        $bytes = file_get_contents($archive);
        if (!is_string($bytes)) {
            throw new RuntimeException('The generation fixture archive cannot be signed.');
        }

        return base64_encode(sodium_crypto_sign_detached(
            PackageSignatureMessage::forChecksum(PackageChecksum::calculate($bytes)),
            $secretKey,
        ));
    }

    /**
     * Prove the administrator extensions screen renders every installed generation's diagnostics.
     *
     * The canonical SDK graph carries only declared sections; the screen reads a stable shape. Rendering
     * the real handler against the live kernel is the proof that no generation leaves a section missing.
     *
     * @param   \Psr\Container\ContainerInterface  $runtime    Fresh kernel that loaded the generations.
     * @param   list<string>                        $installed  Identifiers every rendered row must cover.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertExtensionsScreenRenders(
        \Psr\Container\ContainerInterface $runtime,
        array $installed,
    ): void {
        $context = TestKernelFactory::administratorContext($runtime);
        $principal = $context->principal();
        self::assertInstanceOf(AuthenticatedPrincipal::class, $principal);
        $manager = $runtime->get(ExtensionManager::class);
        self::assertInstanceOf(ExtensionManager::class, $manager);
        $rows = [];
        foreach ($manager->installed($context) as $row) {
            self::assertIsArray($row);
            $rows[$row['identifier']] = $row;
        }
        foreach ($installed as $identifier) {
            self::assertArrayHasKey($identifier, $rows);
            $contributions = $rows[$identifier]['contributions'];
            self::assertIsArray($contributions);
            self::assertIsArray($contributions['capabilities']);
            foreach (['workspaces', 'navigation', 'routes', 'views'] as $kind) {
                self::assertIsArray($contributions['administrator'][$kind]);
            }
        }
        $handler = $runtime->get(AdministratorExtensionsHandler::class);
        self::assertInstanceOf(AdministratorExtensionsHandler::class, $handler);
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/administrator/extensions')
            ->withAttribute(ExecutionContextAttribute::NAME, $context)
            ->withAttribute(AdministratorSession::REQUEST_ATTRIBUTE, new AdministratorSession(
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb399',
                $principal,
                'generation-lifecycle-csrf',
                new DateTimeImmutable('+1 hour'),
            ));

        $response = $handler->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        foreach ($installed as $identifier) {
            self::assertStringContainsString($identifier, $body);
        }
    }
}
