<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use Kumwe\App\Application\Automation\JobQueue;
use Kumwe\App\Application\Automation\Job\ScheduleRepository;
use Kumwe\App\Application\Automation\QueueRuntimeOperations;
use Kumwe\App\Application\Automation\Scheduler;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\Infrastructure\Automation\DoctrineJobQueue;
use Kumwe\App\Infrastructure\Automation\DoctrineQueueRuntimeOperations;
use Kumwe\App\Infrastructure\Automation\DoctrineScheduler;
use Kumwe\App\Infrastructure\Persistence\DoctrineTransactionManager;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use SplFileInfo;

/**
 * Pins the seams the aggregate document command composes over, by type rather than by grep.
 *
 * The transaction boundary is a use-case decision, so the transaction package declares the contract and
 * Infrastructure supplies the driver. These checks read the actual reflected signatures, which is what
 * makes them survive a rename: a Doctrine type reaching an application constructor fails here even when
 * it arrives through an alias, a subclass or a port that was quietly widened.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class TransactionSeamBoundaryTest extends TestCase
{
    /**
     * Files admitted to import a driver, because the extension contract publishes one.
     *
     * A contributed migration is handed the connection it runs its own DDL on, and the prefix helper
     * that keeps it inside its own table namespace. That is a released part of the extension SPI rather
     * than a layering slip, so it is named exactly and nothing else inherits the allowance.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array PUBLISHED_MIGRATION_CONTRACT = [
        'Kumwe\\Extension\\Spi\\Migration\\ExtensionMigration',
        'Kumwe\\App\\Extension\\Application\\Migration\\ExtensionMigrationRunner',
        'Kumwe\\App\\Extension\\Application\\Migration\\ScopedExtensionTableNames',
        'Kumwe\\Extension\\Spi\\Migration\\ExtensionTableNames',
    ];

    /**
     * The host adapter implements the canonical package transaction port.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheTransactionPackagePortIsAdaptedByHostInfrastructure(): void
    {
        $port = new ReflectionClass(TransactionManager::class);
        $adapter = new ReflectionClass(DoctrineTransactionManager::class);

        self::assertTrue($port->isInterface(), 'The transaction boundary must be a contract, not a class.');
        self::assertSame('Kumwe\\Transaction\\Contract\\TransactionManager', $port->getName());
        self::assertStringStartsWith('Kumwe\\App\\Infrastructure\\', $adapter->getName());
        self::assertTrue($adapter->implementsInterface(TransactionManager::class));
        self::assertContains(
            dirname((string) $port->getFileName()),
            [dirname(__DIR__, 2) . '/vendor/kumwe/transaction/src/Contract'],
            'The port must resolve from the installed package.',
        );
    }

    /**
     * No application class takes a Doctrine connection, platform or query builder in any signature.
     *
     * This is the half of the seam a namespace move alone would not prove: the layer is clean only when
     * the types crossing its boundary are its own, whichever directory the class happens to live in.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNoApplicationClassAcceptsADriverType(): void
    {
        $offenders = [];
        foreach ($this->applicationClasses() as $name) {
            $class = new ReflectionClass($name);
            foreach ($class->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $name) {
                    continue;
                }
                foreach ($method->getParameters() as $parameter) {
                    foreach ($this->driverTypes($parameter->getType()) as $type) {
                        $offenders[] = sprintf(
                            '%s::%s($%s): %s',
                            $name,
                            $method->getName(),
                            $parameter->getName(),
                            $type,
                        );
                    }
                }
                foreach ($this->driverTypes($method->getReturnType()) as $type) {
                    $offenders[] = sprintf('%s::%s(): %s', $name, $method->getName(), $type);
                }
            }
        }

        self::assertSame([], $offenders, 'Application signatures must name application types only.');
    }

    /**
     * No application file names a driver or an Infrastructure type anywhere in its executable code.
     *
     * The imports a file declares are the easy half. This reads the token stream instead, so a fully
     * qualified `\Doctrine\DBAL\Connection` written inline — the shape that carries no `use` line for a
     * gate to find — fails here too, while the same name in a documentation block does not.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNoApplicationFileNamesADriverOutsideItsDocumentation(): void
    {
        $offenders = [];
        foreach ($this->applicationFiles() as $relative => $path) {
            $source = file_get_contents($path);
            self::assertIsString($source, sprintf('Could not read %s.', $relative));
            foreach (token_get_all($source) as $token) {
                if (!is_array($token)) {
                    continue;
                }
                if (!in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    continue;
                }
                $name = ltrim($token[1], '\\');
                if (str_starts_with($name, 'Doctrine\\') || str_starts_with($name, 'Kumwe\\App\\Infrastructure\\')) {
                    $offenders[] = sprintf('%s:%d %s', $relative, $token[2], $name);
                }
            }
        }

        self::assertSame([], $offenders, 'Application code must reach Infrastructure only through its own ports.');
    }

    /**
     * The automation adapters sit in Infrastructure and answer application-owned contracts.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAutomationAdaptersSitInInfrastructureBehindApplicationPorts(): void
    {
        $bindings = [
            DoctrineJobQueue::class => [JobQueue::class],
            DoctrineQueueRuntimeOperations::class => [QueueRuntimeOperations::class],
            DoctrineScheduler::class => [Scheduler::class, ScheduleRepository::class],
        ];

        foreach ($bindings as $adapter => $ports) {
            $class = new ReflectionClass($adapter);
            self::assertStringStartsWith('Kumwe\\App\\Infrastructure\\', $adapter);
            self::assertStringStartsWith(
                dirname(__DIR__, 2) . '/src/Infrastructure/',
                (string) $class->getFileName(),
                sprintf('%s must live under src/Infrastructure.', $adapter),
            );
            foreach ($ports as $port) {
                self::assertTrue($class->implementsInterface($port), sprintf('%s must answer %s.', $adapter, $port));
                self::assertStringStartsWith('Kumwe\\App\\Application\\', $port);
            }
        }
    }

    /**
     * Names every driver type a declaration mentions, unwrapping unions and intersections.
     *
     * @param   ?ReflectionType  $type  Declared type of a parameter or a return, or null when untyped.
     *
     * @return  list<string>  Fully qualified driver type names, empty when the declaration is clean.
     *
     * @since   2.0.0
     */
    private function driverTypes(?ReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            return str_starts_with($type->getName(), 'Doctrine\\') ? [$type->getName()] : [];
        }
        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            $names = [];
            foreach ($type->getTypes() as $member) {
                foreach ($this->driverTypes($member) as $name) {
                    $names[] = $name;
                }
            }

            return $names;
        }

        return [];
    }

    /**
     * Lists every class-like declaration that sits inside an application layer anywhere in the tree.
     *
     * @return  list<class-string>  Fully qualified names, each already loadable through the autoloader.
     *
     * @since   2.0.0
     */
    private function applicationClasses(): array
    {
        $names = [];
        foreach ($this->applicationFiles() as $relative => $path) {
            $name = 'Kumwe\\App\\' . str_replace('/', '\\', substr($relative, 0, -4));
            if (class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Lists every PHP file inside an application layer, excluding the published migration contract.
     *
     * Both the shared `src/Application` root and each module's own `src/<Module>/Application` count,
     * because the layer is defined by the directory a file lives in rather than by the module above it.
     *
     * @return  array<string, string>  Repository-relative path under `src/` mapped to its absolute path.
     *
     * @since   2.0.0
     */
    private function applicationFiles(): array
    {
        $root = dirname(__DIR__, 2) . '/src';
        $exempt = array_map(
            static fn (string $name): string => str_replace('\\', '/', substr($name, strlen('Kumwe\\App\\'))) . '.php',
            self::PUBLISHED_MIGRATION_CONTRACT,
        );

        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (!str_contains('/' . $relative, '/Application/') || in_array($relative, $exempt, true)) {
                continue;
            }
            $files[$relative] = $file->getPathname();
        }
        ksort($files);

        self::assertNotSame([], $files, 'The application layer could not be enumerated.');

        return $files;
    }
}
