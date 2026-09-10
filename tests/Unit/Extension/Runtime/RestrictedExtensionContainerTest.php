<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Runtime;

use InvalidArgumentException;
use Kumwe\App\Content\Application\ContentRepository;
use Kumwe\App\Extension\Application\ExtensionManager;
use Kumwe\App\Extension\Application\Migration\ExtensionMigrationRunner;
use Kumwe\App\Extension\Runtime\RestrictedExtensionContainer;
use Kumwe\Extension\Spi\Runtime\ExtensionContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

#[CoversClass(RestrictedExtensionContainer::class)]
final class RestrictedExtensionContainerTest extends TestCase
{
    public function testResolvesOnlyExplicitlyAllowlistedApplicationPort(): void
    {
        $service = new stdClass();
        $container = new RestrictedExtensionContainer('kumwe/example', [
            'application.port' => $service,
        ]);

        self::assertSame($service, $container->get('application.port'));
    }

    /** @return iterable<string, array{string}> */
    public static function privilegedServiceIds(): iterable
    {
        yield 'execution context' => [\Kumwe\Context\Value\ExecutionContext::class];
        yield 'authorization gateway' => [\Kumwe\App\Application\Authorization\AuthorizationGateway::class];
        yield 'site ownership registry' => [\Kumwe\App\Application\Authorization\ResourceSiteOwnership::class];
        yield 'site ownership writer' => [\Kumwe\App\Application\Authorization\ResourceSiteOwnershipWriter::class];
        yield 'root container' => [\Kumwe\App\Kernel\Container::class];
        yield 'database connection' => [\Doctrine\DBAL\Connection::class];
        yield 'raw event manager' => [\Laminas\EventManager\EventManagerInterface::class];
        yield 'raw repository' => [ContentRepository::class];
        yield 'extension mutation manager' => [ExtensionManager::class];
        yield 'migration runner' => [ExtensionMigrationRunner::class];
    }

    #[DataProvider('privilegedServiceIds')]
    public function testRejectsPrivilegedInfrastructureAndMutationPorts(string $serviceId): void
    {
        $container = new RestrictedExtensionContainer('kumwe/example', [
            'application.port' => new stdClass(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not allowlisted');

        $container->get($serviceId);
    }

    public function testExtensionCanRegisterOnlyInsideItsOwnServiceNamespace(): void
    {
        $container = new RestrictedExtensionContainer('kumwe/example', []);
        $container->share(
            'extension.kumwe.example.local',
            static fn (ExtensionContainer $container): stdClass => new stdClass(),
        );

        self::assertInstanceOf(stdClass::class, $container->get('extension.kumwe.example.local'));

        $this->expectException(InvalidArgumentException::class);
        $container->share(
            'extension.another.plugin.local',
            static fn (ExtensionContainer $container): stdClass => new stdClass(),
        );
    }
}
