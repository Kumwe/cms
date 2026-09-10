<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Extension;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Extension\Application\ExtensionManager;
use Kumwe\App\Extension\Application\Migration\ScopedExtensionTableNames;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\Extension\Manifest\ExtensionIdentifier;
use Kumwe\Extension\Package\PackageChecksum;
use Kumwe\Extension\Package\PackageSignatureMessage;
use Kumwe\App\Extension\Runtime\ActiveExtensionSet;
use Kumwe\App\Extension\Infrastructure\DoctrineExtensionManager;
use Kumwe\App\Extension\Infrastructure\RedisLockedExtensionManager;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\ContainerFactory;
use Kumwe\App\Presentation\Twig\RecoveryAdministratorTwigEnvironment;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Twig\Loader\FilesystemLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use ZipArchive;
use Kumwe\App\Extension\Contribution\CanonicalManifestActivator;
use Kumwe\App\Extension\Contribution\OwnedExtensionBindingRegistrar;

#[CoversClass(OwnedExtensionBindingRegistrar::class)]
#[CoversClass(CanonicalManifestActivator::class)]
#[CoversClass(DoctrineExtensionManager::class)]
#[CoversClass(RedisLockedExtensionManager::class)]
#[UsesClass(ActiveExtensionSet::class)]
#[UsesClass(ExtensionContributionRegistrySet::class)]
final class ExtensionContributionLifecycleIntegrationTest extends TestCase
{
    public function testSignedContributionLifecycleIsPermissionAwareTrustBoundAndDataPreserving(): void
    {
        $environment = Environment::fromGlobals();
        $container = TestKernelFactory::create($environment);
        $manager = $container->get(ExtensionManager::class);
        $trust = $container->get(TrustStore::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(ExtensionManager::class, $manager);
        self::assertInstanceOf(TrustStore::class, $trust);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $context = TestKernelFactory::administratorContext($container);
        // The tail of a UUIDv7 is its random half; the head is a timestamp whose first eight hex
        // characters only move every 65 seconds, which two suite runs on one database can share.
        $marker = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -8));
        $identifier = 'integration/contributions-' . $marker;
        $namespace = str_replace('/', '.', $identifier);
        $capability = $namespace . '.manage';
        $keyId = 'integration.contributions.' . $marker;
        $archive = $this->examplePackage($identifier);
        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $publicKey = sodium_crypto_sign_publickey($keyPair);
        $bytes = file_get_contents($archive);
        if (!is_string($bytes)) {
            throw new RuntimeException('The contribution fixture package cannot be read.');
        }
        $checksum = PackageChecksum::calculate($bytes);
        $signature = base64_encode(sodium_crypto_sign_detached(
            PackageSignatureMessage::forChecksum($checksum),
            $secretKey,
        ));
        $extensionTables = new ScopedExtensionTableNames(
            $tables->raw(...),
            static fn (string $part): string => $database->getDatabasePlatform()->quoteSingleIdentifier($part),
            ExtensionIdentifier::fromString($identifier),
        );
        $dataTable = $extensionTables->raw('announcements');
        $installed = false;

        try {
            $trust->add(
                $context,
                $keyId,
                base64_encode($publicKey),
                'integration',
                '*',
                new DateTimeImmutable('+1 year'),
            );
            $result = $manager->install($archive, $context, $keyId, $signature);
            $installed = true;
            self::assertSame('disabled', $result['status']);
            self::assertTrue($database->createSchemaManager()->tablesExist([$dataTable]));
            $diagnostic = $this->installed($manager, $context, $identifier);
            self::assertSame(4, $diagnostic['manifest_schema']);
            self::assertFalse($diagnostic['contributions']['active']);
            self::assertFalse($diagnostic['contributions']['capabilities'][0]['active']);
            self::assertFalse($diagnostic['contributions']['resource_policies'][0]['active']);
            self::assertSame(
                ['global', 'site'],
                $diagnostic['contributions']['capabilities'][0]['allowed_scopes'],
            );
            self::assertSame('active', $diagnostic['contributions']['capabilities'][0]['lifecycle']);
            self::assertSame(1, $diagnostic['contributions']['capabilities'][0]['version']);
            $capabilityRow = $database->fetchAssociative(sprintf(
                'SELECT owner_kind, owner_identifier, allowed_scopes, delegable, high_impact, '
                . 'lifecycle_state, definition_version, definition_checksum FROM %s WHERE code = ?',
                $tables->quoted('capabilities'),
            ), [$capability]);
            self::assertIsArray($capabilityRow);
            self::assertSame('extension', $capabilityRow['owner_kind']);
            self::assertSame($identifier, $capabilityRow['owner_identifier']);
            self::assertSame(['global', 'site'], json_decode(
                (string) $capabilityRow['allowed_scopes'],
                true,
                flags: JSON_THROW_ON_ERROR,
            ));
            self::assertTrue((bool) $capabilityRow['delegable']);
            self::assertFalse((bool) $capabilityRow['high_impact']);
            self::assertSame('active', $capabilityRow['lifecycle_state']);
            self::assertSame('1', (string) $capabilityRow['definition_version']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', (string) $capabilityRow['definition_checksum']);
            self::assertSame('1', (string) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s WHERE extension_id = ? AND policy_code = ? '
                . 'AND capability_code = ? AND lifecycle_state = ?',
                $tables->quoted('extension_contribution_resource_policies'),
            ), [
                $this->extensionId($database, $tables, $identifier),
                $namespace . '.administrator',
                $capability,
                'active',
            ]));
            self::assertFalse($diagnostic['contributions']['business']['field_types'][0]['active']);
            self::assertFalse($diagnostic['contributions']['business']['field_presentations'][0]['active']);
            self::assertSame(2, count($diagnostic['contributions']['business']['definitions']));
            self::assertSame(2, $this->definitionCount($database, $tables, $identifier));
            self::assertSame(2, $this->versionCount($database, $tables, $identifier));
            self::assertSame(0, $this->activeDefinitionCount($database, $tables, $identifier));
            self::assertSame(2, $this->schemaPlanCount($database, $tables, $identifier));
            $inactiveRegistries = $container->get(ExtensionContributionRegistrySet::class);
            self::assertInstanceOf(ExtensionContributionRegistrySet::class, $inactiveRegistries);
            self::assertFalse($inactiveRegistries->fieldTypes()->has($namespace . '.severity'));

            $manager->activate($identifier, $context);
            self::assertSame(2, $this->activeDefinitionCount($database, $tables, $identifier));
            $trust->synchronizeRuntimeMaterialization();
            $runtime = TestKernelFactory::create($environment);
            $active = $runtime->get(ActiveExtensionSet::class);
            $registries = $runtime->get(ExtensionContributionRegistrySet::class);
            self::assertInstanceOf(ActiveExtensionSet::class, $active);
            self::assertInstanceOf(ExtensionContributionRegistrySet::class, $registries);
            self::assertSame($capability, $active->contributionInventory($identifier)['capabilities'][0]['id']);
            self::assertSame(
                $namespace . '.administrator',
                $active->contributionInventory($identifier)['resource_policies'][0]['id'],
            );
            self::assertSame(
                [
                    'field_type' => $namespace . '.severity',
                    'contexts' => ['create', 'detail', 'filter', 'history', 'list', 'relation', 'update'],
                ],
                $active->contributionInventory($identifier)['business']['field_presentations'][0],
            );
            self::assertSame([], $registries->navigation()->visible([]));
            $visible = $registries->navigation()->visible([$capability => true]);
            self::assertSame($namespace . '.navigation', $visible[0]['id']);

            $recovery = (new ContainerFactory())->createRecovery($environment);
            $recoveryActive = $recovery->get(ActiveExtensionSet::class);
            $recoveryRegistries = $recovery->get(ExtensionContributionRegistrySet::class);
            self::assertInstanceOf(ActiveExtensionSet::class, $recoveryActive);
            self::assertInstanceOf(ExtensionContributionRegistrySet::class, $recoveryRegistries);
            self::assertSame(0, $recoveryActive->count());
            self::assertSame(
                [],
                $recoveryRegistries->inventory(ContributionOwner::extension($identifier))['capabilities'],
            );

            $manager->disable($identifier, $context);
            self::assertSame(0, $this->activeDefinitionCount($database, $tables, $identifier));
            self::assertSame([], $registries->navigation()->visible([$capability => true]));

            $manager->activate($identifier, $context);
            self::assertSame($namespace . '.navigation', $registries->navigation()->visible([
                $capability => true,
            ])[0]['id']);

            self::assertSame(
                [$identifier],
                $trust->emergencyRevoke($context, $keyId, 'Contribution lifecycle trust exercise.'),
            );
            self::assertSame(0, $this->activeDefinitionCount($database, $tables, $identifier));
            self::assertSame([], $registries->navigation()->visible([$capability => true]));
            $quarantined = TestKernelFactory::create($environment)->get(ActiveExtensionSet::class);
            self::assertInstanceOf(ActiveExtensionSet::class, $quarantined);
            self::assertSame(0, $quarantined->count());

            $manager->uninstall($identifier, $context);
            $installed = false;
            self::assertSame('0', (string) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s WHERE policy_code = ?',
                $tables->quoted('extension_contribution_resource_policies'),
            ), [$namespace . '.administrator']));
            self::assertFalse($database->fetchOne(sprintf(
                'SELECT code FROM %s WHERE code = ?',
                $tables->quoted('capabilities'),
            ), [$capability]));
            self::assertTrue($database->createSchemaManager()->tablesExist([$dataTable]));
            self::assertSame(2, $this->definitionCount($database, $tables, $identifier));
            self::assertSame(2, $this->versionCount($database, $tables, $identifier));
            self::assertSame(0, $this->activeDefinitionCount($database, $tables, $identifier));
        } finally {
            if ($installed) {
                try {
                    $manager->uninstall($identifier, $context);
                } catch (Throwable) {
                }
            }
            if ($database->createSchemaManager()->tablesExist([$dataTable])) {
                $database->createSchemaManager()->dropTable($dataTable);
            }
            if (is_file($archive)) {
                unlink($archive);
            }
        }
    }

    private function definitionCount(Connection $database, TableNames $tables, string $owner): int
    {
        return (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE owner_identifier = ?',
            $tables->quoted('business_definitions'),
        ), [$owner]);
    }

    private function activeDefinitionCount(Connection $database, TableNames $tables, string $owner): int
    {
        return (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE owner_identifier = ? AND owner_active = ?',
            $tables->quoted('business_definitions'),
        ), [$owner, true], [\Doctrine\DBAL\Types\Types::STRING, \Doctrine\DBAL\Types\Types::BOOLEAN]);
    }

    private function versionCount(Connection $database, TableNames $tables, string $owner): int
    {
        return (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s v INNER JOIN %s d ON d.id = v.definition_id WHERE d.owner_identifier = ?',
            $tables->quoted('business_definition_versions'),
            $tables->quoted('business_definitions'),
        ), [$owner]);
    }

    private function schemaPlanCount(Connection $database, TableNames $tables, string $owner): int
    {
        return (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s p INNER JOIN %s d ON d.id = p.definition_id WHERE d.owner_identifier = ?',
            $tables->quoted('business_schema_plans'),
            $tables->quoted('business_definitions'),
        ), [$owner]);
    }

    private function extensionId(Connection $database, TableNames $tables, string $identifier): string
    {
        $id = $database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE identifier = ?',
            $tables->quoted('extensions'),
        ), [$identifier]);
        if (!is_string($id) || $id === '') {
            throw new RuntimeException('The installed contribution extension identifier is unavailable.');
        }

        return $id;
    }

    /**
     * Proves recovery composition offers no route to an extension template or asset.
     *
     * The lifecycle case above already shows recovery runs no extension PHP: it builds a recovery
     * container while a package is installed, active and trusted, and finds the active set and the
     * contribution inventory both empty, which a provider that had executed would have filled. This is the
     * other half — that recovery cannot *read* an extension's templates either — and it is a property of
     * the loader chain rather than of installed state, because `recoveryAdministrator()` composes from the
     * core template root alone and never consults the registry.
     *
     * It is deliberately its own case with its own container. Resolving the recovery environment inside
     * the lifecycle test perturbed the contribution registries that test then asserts the ordering of, so
     * keeping the two apart is what stops a proof about templates from silently changing what a proof
     * about navigation is measuring.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRecoveryCompositionExposesNoExtensionTemplateNamespace(): void
    {
        $recovery = (new ContainerFactory())->createRecovery(Environment::fromGlobals());
        $templates = $recovery->get(RecoveryAdministratorTwigEnvironment::class);
        self::assertInstanceOf(RecoveryAdministratorTwigEnvironment::class, $templates);

        $loader = $templates->getLoader();
        self::assertInstanceOf(FilesystemLoader::class, $loader);
        self::assertSame(
            [FilesystemLoader::MAIN_NAMESPACE, 'core-admin', 'kis'],
            $loader->getNamespaces(),
        );
        foreach ($loader->getNamespaces() as $namespace) {
            foreach ($loader->getPaths($namespace) as $path) {
                self::assertStringStartsWith(dirname(__DIR__, 3) . '/templates', $path);
            }
        }
    }

    /** @return array<string, mixed> */
    private function installed(ExtensionManager $manager, ExecutionContext $context, string $identifier): array
    {
        foreach ($manager->installed($context) as $extension) {
            if (($extension['identifier'] ?? null) === $identifier) {
                return $extension;
            }
        }
        throw new RuntimeException('The installed contribution fixture is unavailable.');
    }

    /**
     * Package the announcements example under a wholly per-run identity.
     *
     * The identifier, the namespaced handles, and the two business-definition UUIDs are all rewritten,
     * because uninstalling an extension deliberately preserves its published definitions — the data
     * preservation this test itself asserts. A repeat of the package under the example's fixed
     * definition UUIDs would therefore meet the previous run's published revision and be refused as a
     * stale draft, so each run has to admit definitions it alone identifies.
     *
     * @param   string  $identifier  Per-run `vendor/name` identifier the package is rewritten to.
     *
     * @return  string  Absolute path of the packaged archive.
     *
     * @throws  RuntimeException  When the fixture archive cannot be assembled.
     *
     * @since   2.0.0
     */
    private function examplePackage(string $identifier): string
    {
        $archive = tempnam(sys_get_temp_dir(), 'kumwe-contribution-extension-');
        if (!is_string($archive)) {
            throw new RuntimeException('The contribution fixture archive cannot be allocated.');
        }
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The contribution fixture archive cannot be opened.');
        }
        $root = dirname(__DIR__, 3) . '/examples/extensions/announcements';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        // Minted once so every packaged file agrees on which definition carries which identity.
        $definitionIds = [Uuid::uuid7()->toString(), Uuid::uuid7()->toString()];
        try {
            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $contents = file_get_contents($file->getPathname());
                if (!is_string($contents)) {
                    throw new RuntimeException('An announcements fixture file cannot be read.');
                }
                $contents = str_replace(
                    [
                        'kumwe/announcements-example',
                        'kumwe.announcements-example',
                        '01912f8a-8c4b-7eb1-8f7d-c256efd39801',
                        '01912f8a-8c4b-7eb1-8f7d-c256efd39802',
                    ],
                    [
                        $identifier,
                        str_replace('/', '.', $identifier),
                        $definitionIds[0],
                        $definitionIds[1],
                    ],
                    $contents,
                );
                $relative = substr($file->getPathname(), strlen($root) + 1);
                if (!$zip->addFromString($relative, $contents)) {
                    throw new RuntimeException('An announcements fixture file cannot be packaged.');
                }
            }
        } finally {
            $zip->close();
        }
        return $archive;
    }
}
