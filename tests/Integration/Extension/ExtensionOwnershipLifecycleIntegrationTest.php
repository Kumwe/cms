<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Extension;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Authorization\AuthorizationResourceOwnershipUnknown;
use Kumwe\App\Application\Authorization\ResourceSiteOwnership;
use Kumwe\App\Application\Authorization\ResourceSiteOwnershipConflict;
use Kumwe\App\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\App\Extension\Application\ExtensionManager;
use Kumwe\App\Extension\Infrastructure\DoctrineExtensionManager;
use Kumwe\App\Extension\Infrastructure\RedisLockedExtensionManager;
use Kumwe\App\Infrastructure\Authorization\DoctrineResourceSiteOwnershipWriter;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use ZipArchive;

#[CoversClass(DoctrineExtensionManager::class)]
#[CoversClass(RedisLockedExtensionManager::class)]
#[CoversClass(DoctrineResourceSiteOwnershipWriter::class)]
#[CoversClass(ResourceSiteOwnershipConflict::class)]
final class ExtensionOwnershipLifecycleIntegrationTest extends TestCase
{
    public function testUninstallRemovesOwnershipAndAllowsSameIdentifierToBeReinstalled(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $extensions = $container->get(ExtensionManager::class);
        $ownership = $container->get(ResourceSiteOwnership::class);
        self::assertInstanceOf(ExtensionManager::class, $extensions);
        self::assertInstanceOf(ResourceSiteOwnership::class, $ownership);
        $context = TestKernelFactory::administratorContext($container);
        $identifier = 'integration/ownership-' . Uuid::uuid7()->toString();
        $archive = $this->package($identifier);
        $installed = false;

        try {
            $first = $extensions->install($archive, $context);
            $installed = true;
            self::assertSame($identifier, $first['identifier']);
            self::assertSame(SiteContext::DEFAULT, $ownership->scopeFor(
                AuthorizationResource::item('extension', $identifier),
            )->identifier);

            $extensions->uninstall($identifier, $context);
            $installed = false;
            $this->assertOwnershipIsAbsent($ownership, AuthorizationResource::item('extension', $identifier));

            $second = $extensions->install($archive, $context);
            $installed = true;
            self::assertSame($identifier, $second['identifier']);
            self::assertSame('1.0.0', $second['installed_version']);
            self::assertSame(SiteContext::DEFAULT, $ownership->scopeFor(
                AuthorizationResource::item('extension', $identifier),
            )->identifier);

            $extensions->uninstall($identifier, $context);
            $installed = false;
            $this->assertOwnershipIsAbsent($ownership, AuthorizationResource::item('extension', $identifier));
        } finally {
            if ($installed) {
                $extensions->uninstall($identifier, $context);
            }
            if (is_file($archive)) {
                unlink($archive);
            }
        }
    }

    public function testOwnershipRemovalCannotCrossSiteBoundary(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        $writer = $container->get(ResourceSiteOwnershipWriter::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        self::assertInstanceOf(ResourceSiteOwnershipWriter::class, $writer);
        $site = SiteContext::fromString('integration-' . Uuid::uuid7()->toString());
        $resource = AuthorizationResource::item('content', Uuid::uuid7()->toString());
        $database->insert($tables->raw('sites'), [
            'identifier' => $site->identifier(),
            'name' => 'Integration ownership boundary',
            'created_at' => new DateTimeImmutable('now'),
        ], ['created_at' => Types::DATETIME_IMMUTABLE]);
        $writer->record($resource, $site);

        try {
            try {
                $writer->remove($resource, SiteContext::default());
                self::fail('A caller cannot remove a resource ownership record belonging to another site.');
            } catch (ResourceSiteOwnershipConflict) {
                self::addToAssertionCount(1);
            }
            self::assertSame($site->identifier(), $database->fetchOne(sprintf(
                'SELECT site_identifier FROM %s WHERE resource_type = ? AND resource_id = ?',
                $tables->quoted('resource_site_ownership'),
            ), [$resource->type(), $resource->identifier()]));
        } finally {
            $database->delete($tables->raw('resource_site_ownership'), [
                'resource_type' => $resource->type(),
                'resource_id' => $resource->identifier(),
                'site_identifier' => $site->identifier(),
            ]);
            $database->delete($tables->raw('sites'), ['identifier' => $site->identifier()]);
        }
    }

    private function assertOwnershipIsAbsent(
        ResourceSiteOwnership $ownership,
        AuthorizationResource $resource,
    ): void {
        try {
            $ownership->scopeFor($resource);
            self::fail('A physically deleted resource cannot leave an authorization ownership tombstone.');
        } catch (AuthorizationResourceOwnershipUnknown) {
            self::addToAssertionCount(1);
        }
    }

    private function package(string $identifier): string
    {
        $archive = tempnam(sys_get_temp_dir(), 'kumwe-ownership-extension-');
        if (!is_string($archive)) {
            throw new RuntimeException('The integration extension archive could not be allocated.');
        }
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The integration extension archive could not be opened.');
        }

        try {
            $manifest = json_encode([
                'schema' => 1,
                'name' => $identifier,
                'type' => 'plugin',
                'version' => '1.0.0',
                'provider' => 'KumweIntegration\\OwnershipLifecycle\\Provider',
                'autoload' => [
                    'psr-4' => ['KumweIntegration\\OwnershipLifecycle\\' => 'src/'],
                ],
                'requires' => ['kumwe' => '^2.0.0', 'php' => '^8.5.0'],
                'dependencies' => [],
                'migrations' => [],
                'configuration' => [],
                'permissions' => [],
                'routes' => [],
                'events' => [],
                'assets' => [],
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            if (!$zip->addFromString('kumwe.json', $manifest)) {
                throw new RuntimeException('The integration extension manifest could not be written.');
            }
            if (
                !$zip->addFromString('src/Provider.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace KumweIntegration\OwnershipLifecycle;

use Kumwe\Extension\Spi\Runtime\ExtensionContainer;
use Kumwe\Extension\Spi\Runtime\BootableExtension;

final class Provider implements BootableExtension
{
    public function register(ExtensionContainer $container): void
    {
    }

    public function boot(ExtensionContainer $container): void
    {
    }
}
PHP)
            ) {
                throw new RuntimeException('The integration extension provider could not be written.');
            }
        } finally {
            $zip->close();
        }

        return $archive;
    }
}
