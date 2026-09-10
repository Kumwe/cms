<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Demo\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use FilesystemIterator;
use Kumwe\App\Application\Authorization\SystemPrincipal;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionService;
use Kumwe\App\BusinessDefinition\Domain\DefinitionOwnerType;
use Kumwe\App\Delivery\Console\Command\MigrateCommand;
use Kumwe\App\Demo\Application\DemoBusinessTemplateProjector;
use Kumwe\App\Demo\Application\DemoProfileLedger;
use Kumwe\App\Demo\Application\VdmBusinessManifestProjector;
use Kumwe\App\Demo\Infrastructure\DemoBusinessProfileExporter;
use Kumwe\App\Demo\Infrastructure\DemoContentProfileInstaller;
use Kumwe\App\Demo\Infrastructure\DemoProfileExporter;
use Kumwe\App\Demo\Infrastructure\DemoProfileInstaller;
use Kumwe\App\Demo\Infrastructure\FilesystemDemoManifestCatalog;
use Kumwe\App\Demo\Infrastructure\VdmBusinessDemoInstaller;
use Kumwe\App\Extension\Runtime\ExtensionRuntimeMapCompiler;
use Kumwe\App\Infrastructure\Persistence\Migration\MigrationRunner;
use Kumwe\App\Kernel\Configuration\ApplicationConfiguration;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Kernel\ContainerFactory;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionProperty;
use SplFileInfo;

/**
 * Proves an installed business dataset exports under a new profile name and installs into a fresh installation.
 *
 * This is the acceptance proof for the export contract `docs/demo-profiles.md` promises: a running
 * installation — the documentation site with the released VDM business demo — is exported under a profile
 * name that did not exist before, the package is re-validated through the catalog exactly as
 * `demo:export-profile` does, and a second, empty installation on the same database (a different table
 * prefix) selects that profile and reconciles it from the exported root. Every count the released profile
 * pins — 12 definitions, 80 records, 130 relations, 65 actions, 2 archives — arrives on the target, and a
 * second reconciliation changes nothing, which is the re-migrate that used to die once the package had
 * installed once.
 *
 * @since  2.0.0
 */
#[CoversClass(DemoBusinessProfileExporter::class)]
#[CoversClass(DemoBusinessTemplateProjector::class)]
#[CoversClass(VdmBusinessManifestProjector::class)]
#[CoversClass(VdmBusinessDemoInstaller::class)]
#[CoversClass(DemoProfileInstaller::class)]
#[CoversClass(FilesystemDemoManifestCatalog::class)]
#[UsesClass(DemoProfileExporter::class)]
final class DemoBusinessProfileExportInstallIntegrationTest extends TestCase
{
    /**
     * Counts the released VDM profile pins, by ledger resource type, that the target must reproduce.
     *
     * @var    array<string, int>
     * @since  2.0.0
     */
    private const array EXPECTED_DATASET = [
        'business_action' => 65,
        'business_archive' => 2,
        'business_definition' => 12,
        'business_record' => 80,
        'business_relation' => 130,
    ];

    /**
     * Export the installed VDM dataset under a new name, install it elsewhere, and reconcile it twice.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExportedDatasetInstallsUnderANewProfileNameAndReconcilesIdempotently(): void
    {
        $sourcePrefix = $this->prefix();
        $targetPrefix = $this->prefix();
        $profile = 'fork-' . substr(str_replace('-', '', Uuid::uuid7()->toString()), -8);
        $exportRoot = sys_get_temp_dir() . '/kumwe-export-install-' . substr($profile, 5);
        $database = null;
        try {
            $source = TestKernelFactory::create($this->environment($sourcePrefix, 'documentation', 'vdm'));
            $database = $this->connection($source);
            $sourceCounts = $this->datasetCounts($source);
            foreach (self::EXPECTED_DATASET as $type => $count) {
                self::assertSame($count, $sourceCounts[$type] ?? null, $type);
            }

            $this->exportPackage($source, $profile, $exportRoot);
            $catalog = new FilesystemDemoManifestCatalog($exportRoot);
            $verified = $catalog->business($profile);
            self::assertSame(12, $verified['manifest']['expected']['definition_count'] ?? null);

            $targetEnvironment = $this->environment($targetPrefix, 'blank', $profile);
            $target = $this->migratedInstallation($targetEnvironment);
            $reconciler = $this->reconcilerOver($target, $exportRoot);

            $messages = $reconciler->reconcile();
            self::assertContains('Reconciled 80 VDM business records and their example workflows.', $messages);
            self::assertSame($sourceCounts, $this->datasetCounts($target));
            $this->assertTemplateDefinitionsPublished($target, $profile);

            $assets = $this->assetIndex($target);
            self::assertSame([], $reconciler->reconcile(), 'A second reconciliation must find nothing to do.');
            self::assertSame($assets, $this->assetIndex($target));
        } finally {
            if ($database instanceof Connection) {
                $this->dropInstallation($database, $sourcePrefix);
                $this->dropInstallation($database, $targetPrefix);
            }
            $this->removeTree($exportRoot);
        }
    }

    /**
     * Write the source installation's package under the profile name, the way `demo:export-profile` does.
     *
     * The released `blank` and `placeholder` content manifests are copied beside the package, which is what
     * copying `<output>/resources/demo` over an installation's `resources/demo` leaves in place: the target
     * selects `blank` for its site content and this profile for its business dataset, both from one root.
     *
     * @param   Container  $source      Installed source kernel.
     * @param   string     $profile     New profile name the package is exported as.
     * @param   string     $exportRoot  Absolute directory the package is written below.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function exportPackage(Container $source, string $profile, string $exportRoot): void
    {
        $context = TestKernelFactory::administratorContext($source);
        $exporter = $source->get(DemoBusinessProfileExporter::class);
        $writer = $source->get(DemoProfileExporter::class);
        self::assertInstanceOf(DemoBusinessProfileExporter::class, $exporter);
        self::assertInstanceOf(DemoProfileExporter::class, $writer);

        $business = $exporter->documents($context, $profile);
        self::assertNotSame([], $business['profile']);
        $documents = [sprintf('content/%s.json', $profile) => $writer->contentManifest($context, $profile)];
        $documents[sprintf('business/%s/profile.json', $profile)] = $business['profile'];
        foreach ($business['definitions'] as $relative => $document) {
            $documents[sprintf('business/%s/%s', $profile, $relative)] = $document;
        }
        $documents[sprintf('business/%s/records.json', $profile)] = $business['records'];
        if ($business['access'] !== []) {
            $documents[sprintf('business/%s/access.json', $profile)] = $business['access'];
        }
        $writer->writePackage($exportRoot, $profile, $documents);

        $released = dirname(__DIR__, 4) . '/resources/demo/content';
        foreach (['blank', 'placeholder'] as $content) {
            self::assertTrue(copy(
                sprintf('%s/%s.json', $released, $content),
                sprintf('%s/resources/demo/content/%s.json', $exportRoot, $content),
            ));
        }
    }

    /**
     * Migrate one fresh installation's schema without reconciling demo profiles, then boot it fully.
     *
     * `MigrateCommand` reconciles the selected profiles through the repository catalog, which does not know
     * the exported profile; the migration runner and the runtime materialization it wraps are driven
     * directly instead, leaving the demo ledger untouched for the reconciler composed over the export root.
     *
     * @param   Environment  $environment  Configuration of the target installation.
     *
     * @return  Container  Fully booted target kernel with a migrated, demo-free schema.
     *
     * @since   2.0.0
     */
    private function migratedInstallation(Environment $environment): Container
    {
        $recovery = (new ContainerFactory())->createRecovery($environment);
        try {
            $compiler = $recovery->get(ExtensionRuntimeMapCompiler::class);
            $migrate = $recovery->get(MigrateCommand::class);
            $runner = $recovery->get(MigrationRunner::class);
            self::assertInstanceOf(ExtensionRuntimeMapCompiler::class, $compiler);
            self::assertInstanceOf(MigrateCommand::class, $migrate);
            self::assertInstanceOf(MigrationRunner::class, $runner);
            $compiler->discardLocal();
            $system = (new ReflectionProperty(MigrateCommand::class, 'system'))->getValue($migrate);
            self::assertInstanceOf(SystemPrincipal::class, $system);
            $runner->migrate($system->context(
                SiteContext::default(),
                'integration-export-install-' . bin2hex(random_bytes(8)),
            ));
            $compiler->reconcileAndMaterialize(true);
        } finally {
            $connection = $recovery->get(Connection::class);
            if ($connection instanceof Connection) {
                $connection->close();
            }
        }

        return (new ContainerFactory())->create($environment);
    }

    /**
     * Compose the production reconciler over the exported root instead of the repository catalog.
     *
     * Every collaborator but the catalog is the target kernel's own, including the purpose-bound
     * profile-installer authority, so the reconciliation runs exactly as `database:migrate` would with the
     * package copied over `resources/demo`.
     *
     * @param   Container  $target      Booted target kernel.
     * @param   string     $exportRoot  Absolute root holding the exported `resources/demo`.
     *
     * @return  DemoProfileInstaller  Reconciler reading manifests from the export.
     *
     * @since   2.0.0
     */
    private function reconcilerOver(Container $target, string $exportRoot): DemoProfileInstaller
    {
        $installer = $target->get(DemoProfileInstaller::class);
        $configuration = $target->get(ApplicationConfiguration::class);
        $content = $target->get(DemoContentProfileInstaller::class);
        $business = $target->get(VdmBusinessDemoInstaller::class);
        $ledger = $target->get(DemoProfileLedger::class);
        self::assertInstanceOf(DemoProfileInstaller::class, $installer);
        self::assertInstanceOf(ApplicationConfiguration::class, $configuration);
        self::assertInstanceOf(DemoContentProfileInstaller::class, $content);
        self::assertInstanceOf(VdmBusinessDemoInstaller::class, $business);
        self::assertInstanceOf(DemoProfileLedger::class, $ledger);
        $system = (new ReflectionProperty(DemoProfileInstaller::class, 'system'))->getValue($installer);
        self::assertInstanceOf(SystemPrincipal::class, $system);

        return new DemoProfileInstaller(
            $configuration,
            new FilesystemDemoManifestCatalog($exportRoot),
            $content,
            $business,
            $ledger,
            $system,
        );
    }

    /**
     * Require the target to publish exactly the twelve definitions under the new profile's namespace.
     *
     * @param   Container  $target   Booted target kernel.
     * @param   string     $profile  Profile name the definitions were exported as.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertTemplateDefinitionsPublished(Container $target, string $profile): void
    {
        $definitions = $target->get(BusinessDefinitionService::class);
        self::assertInstanceOf(BusinessDefinitionService::class, $definitions);
        $context = TestKernelFactory::administratorContext($target);
        $handles = [];
        foreach ($definitions->catalog($context) as $entry) {
            if ($entry->owner->type !== DefinitionOwnerType::Site) {
                continue;
            }
            self::assertStringStartsWith('site.default.' . $profile . '_', $entry->handle);
            self::assertSame(1, $entry->publishedVersion, $entry->handle);
            $handles[] = $entry->handle;
        }
        sort($handles);
        self::assertCount(12, $handles);
        self::assertContains('site.default.' . $profile . '_invoice_line', $handles);
        self::assertContains('site.default.' . $profile . '_product', $handles);
    }

    /**
     * Count the business-demo ledger assets of one installation by resource type.
     *
     * @param   Container  $container  Booted kernel of the installation.
     *
     * @return  array<string, int>  Asset counts keyed by resource type, sorted by type.
     *
     * @since   2.0.0
     */
    private function datasetCounts(Container $container): array
    {
        $counts = [];
        foreach ($this->assets($container) as $asset) {
            $type = $asset['resource_type'] ?? null;
            self::assertIsString($type);
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }
        ksort($counts, SORT_STRING);

        return $counts;
    }

    /**
     * Index the business-demo ledger of one installation by fixture key, for a change-nothing comparison.
     *
     * @param   Container  $container  Booted kernel of the installation.
     *
     * @return  array<string, array{string, string, int, string}>  Resource type, resource identity, applied
     *          version, and applied checksum by fixture key.
     *
     * @since   2.0.0
     */
    private function assetIndex(Container $container): array
    {
        $index = [];
        foreach ($this->assets($container) as $asset) {
            $fixture = $asset['fixture_key'] ?? null;
            $type = $asset['resource_type'] ?? null;
            $resource = $asset['resource_id'] ?? null;
            $version = $asset['last_applied_version'] ?? null;
            $checksum = $asset['last_applied_checksum'] ?? null;
            self::assertIsString($fixture);
            self::assertIsString($type);
            self::assertIsString($resource);
            self::assertIsInt($version);
            self::assertIsString($checksum);
            $index[$fixture] = [$type, $resource, $version, $checksum];
        }
        ksort($index, SORT_STRING);

        return $index;
    }

    /**
     * Read every business-demo ledger asset of one installation's default site.
     *
     * @param   Container  $container  Booted kernel of the installation.
     *
     * @return  list<array<string, mixed>>  Ledger rows.
     *
     * @since   2.0.0
     */
    private function assets(Container $container): array
    {
        $ledger = $container->get(DemoProfileLedger::class);
        self::assertInstanceOf(DemoProfileLedger::class, $ledger);

        return $ledger->assets(SiteContext::DEFAULT, VdmBusinessDemoInstaller::DATASET);
    }

    /**
     * Copy the configured environment for one sibling installation on the same database.
     *
     * @param   string  $prefix    Table prefix the installation is built under.
     * @param   string  $content   Site-content profile the installation selects.
     * @param   string  $business  Business profile the installation selects.
     *
     * @return  Environment  Same connection and credentials, own prefix, cache namespace, and selections.
     *
     * @since   2.0.0
     */
    private function environment(string $prefix, string $content, string $business): Environment
    {
        /** @var array<string, string> $values */
        $values = (new ReflectionProperty(Environment::class, 'values'))
            ->getValue(Environment::fromGlobals());
        $values['DB_TABLE_PREFIX'] = $prefix;
        $values['REDIS_NAMESPACE'] = 'kumwe.' . rtrim($prefix, '_');
        $values['KUMWE_SITE_CONTENT_PROFILE'] = $content;
        $values['KUMWE_BUSINESS_PROFILE'] = $business;
        unset($values['KUMWE_BUSINESS_DEMO']);

        return new Environment($values);
    }

    /**
     * Open the database connection of one booted kernel.
     *
     * @param   Container  $container  Booted kernel.
     *
     * @return  Connection  Connection to the shared database.
     *
     * @since   2.0.0
     */
    private function connection(Container $container): Connection
    {
        $database = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);

        return $database;
    }

    /**
     * Remove every table and routine one installation created, so a rerun starts from nothing.
     *
     * @param   Connection  $database  Connection to the shared database.
     * @param   string      $prefix    Table prefix whose installation is removed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function dropInstallation(Connection $database, string $prefix): void
    {
        $pattern = $database->quote(str_replace('_', '\\_', $prefix) . '%');
        if ($database->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            /** @var list<string> $tables */
            $tables = $database->fetchFirstColumn(
                'SELECT tablename FROM pg_tables WHERE schemaname = current_schema() AND tablename LIKE ' . $pattern,
            );
            foreach ($tables as $table) {
                $database->executeStatement(sprintf(
                    'DROP TABLE IF EXISTS %s CASCADE',
                    $database->quoteSingleIdentifier($table),
                ));
            }
            $database->executeStatement(sprintf(
                'DROP FUNCTION IF EXISTS %s() CASCADE',
                $database->quoteSingleIdentifier($prefix . 'audit_append_only'),
            ));

            return;
        }
        /** @var list<string> $tables */
        $tables = $database->fetchFirstColumn(
            'SELECT table_name FROM information_schema.tables '
                . 'WHERE table_schema = DATABASE() AND table_name LIKE ' . $pattern,
        );
        $database->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $table) {
                $database->executeStatement(sprintf(
                    'DROP TABLE IF EXISTS %s',
                    $database->quoteSingleIdentifier($table),
                ));
            }
        } finally {
            $database->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * Remove the exported package tree.
     *
     * @param   string  $directory  Absolute package root to remove.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }

    /**
     * Mint a table prefix no other installation, run, or case in this suite is using.
     *
     * @return  non-empty-string  Valid table prefix unique to one installation.
     *
     * @since   2.0.0
     */
    private function prefix(): string
    {
        return 'x' . substr(str_replace('-', '', Uuid::uuid7()->toString()), -10) . '_';
    }
}
