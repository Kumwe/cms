<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use Doctrine\DBAL\Connection;
use Kumwe\App\Kernel\Container;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Authorization\SystemPrincipal;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaInstallationRepository;
use Kumwe\App\Delivery\Console\Command\CreateAdministratorCommand;
use Kumwe\App\Delivery\Console\Command\MigrateCommand;
use Kumwe\App\Delivery\Console\Command\QueueWorkCommand;
use Kumwe\App\Delivery\Console\Command\ScheduleRunCommand;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Extension\Runtime\ExtensionRuntimeMapCompiler;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\ContainerFactory;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Throwable;

/** Boots the real kernel and obtains a context only through production authentication. */
final class TestKernelFactory
{
    /**
     * Process-wide scope that retires definitions created on the shared integration database.
     *
     * @var    TransientBusinessDefinitionFixtureScope|null
     * @since  2.0.0
     */
    private static ?TransientBusinessDefinitionFixtureScope $definitionFixtures = null;

    /**
     * Address the bootstrapped administrator is created under, for tests that authenticate by hand.
     *
     * @var    string
     * @since  2.0.0
     */
    public const ADMINISTRATOR_EMAIL = 'integration-administrator@example.test';

    private const EMAIL = self::ADMINISTRATOR_EMAIL;
    /**
     * Password the bootstrapped administrator is created with, for tests that authenticate by hand.
     *
     * @var    string
     * @since  2.0.0
     */
    public const ADMINISTRATOR_PASSWORD = 'integration administrator password';

    private const PASSWORD = self::ADMINISTRATOR_PASSWORD;

    public static function create(Environment $environment): Container
    {
        // Repair and materialize through recovery composition first: discarding after a full boot would leave
        // that container's immutable execution gates pinned to the replica state that was just removed.
        $recovery = (new ContainerFactory())->createRecovery($environment);
        try {
            self::discardReplicaLocalRuntime($recovery);
            $migrate = $recovery->get(MigrateCommand::class);
            if (!$migrate instanceof MigrateCommand || $migrate->execute([], self::output()) !== 0) {
                throw new RuntimeException('The integration database could not be migrated.');
            }
        } finally {
            $database = $recovery->get(Connection::class);
            if ($database instanceof Connection) {
                $database->close();
            }
        }
        $container = (new ContainerFactory())->create($environment);
        self::trackTransientBusinessDefinitions($container, $environment);

        return $container;
    }

    /**
     * Snapshot fixture definitions once for the shared suite database and retire additions at shutdown.
     *
     * Tests that exercise prefix isolation deliberately boot temporary sibling installations and tear their
     * tables down themselves. Comparing the supplied database identity with the process-global test identity
     * keeps those installations out of this process scope while every ordinary kernel boot shares one
     * baseline. Shutdown is used rather than per-test teardown because several integration classes reuse a
     * definition across their methods; the lifecycle boundary is the complete PHPUnit process.
     *
     * @param   Container    $container    Migrated application container whose production stores do the work.
     * @param   Environment  $environment  Configuration used to create that container.
     *
     * @return  void
     *
     * @throws  RuntimeException  When one of the required lifecycle services is unavailable.
     *
     * @since  2.0.0
     */
    private static function trackTransientBusinessDefinitions(Container $container, Environment $environment): void
    {
        if (
            self::$definitionFixtures !== null
            || self::databaseIdentity($environment) !== self::databaseIdentity(Environment::fromGlobals())
        ) {
            return;
        }

        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        $definitions = $container->get(BusinessDefinitionRepository::class);
        $installations = $container->get(BusinessSchemaInstallationRepository::class);
        $transactions = $container->get(TransactionManager::class);
        $clock = $container->get(ClockInterface::class);
        if (
            !$database instanceof Connection
            || !$tables instanceof TableNames
            || !$definitions instanceof BusinessDefinitionRepository
            || !$installations instanceof BusinessSchemaInstallationRepository
            || !$transactions instanceof TransactionManager
            || !$clock instanceof ClockInterface
        ) {
            throw new RuntimeException('The integration definition fixture lifecycle is unavailable.');
        }

        $scope = new TransientBusinessDefinitionFixtureScope(
            $database,
            $tables,
            $definitions,
            $installations,
            $transactions,
            $clock,
        );
        self::$definitionFixtures = $scope;
        $shutdown = ProcessOwnedShutdown::capture(static function () use ($scope): void {
            try {
                $scope->withdraw();
            } catch (Throwable $failure) {
                fwrite(STDERR, sprintf(
                    "\nIntegration definition fixture withdrawal failed: %s\n",
                    $failure->getMessage(),
                ));
                exit(1);
            }
        });
        register_shutdown_function($shutdown);
    }

    /**
     * Identify the database and prefix whose fixture lifecycle one process may govern.
     *
     * Passwords are deliberately absent: identity is about the addressed database, not how a connection
     * authenticated to it. Raw optional port values are retained so a test explicitly aimed at another
     * listener does not get mistaken for the shared integration database.
     *
     * @param   Environment  $environment  Configuration whose database identity is wanted.
     *
     * @return  array{string, string, ?string, string, string, string}  Driver, host, port, database, user,
     *          and table prefix.
     *
     * @since  2.0.0
     */
    private static function databaseIdentity(Environment $environment): array
    {
        return [
            strtolower($environment->string('DB_DRIVER', 'mariadb')),
            $environment->string('DB_HOST'),
            $environment->optionalString('DB_PORT'),
            $environment->string('DB_NAME'),
            $environment->string('DB_USER'),
            $environment->string('DB_TABLE_PREFIX', 'kumwe_'),
        ];
    }

    /**
     * Makes the test database the sole runtime authority for this process.
     *
     * Integration tests install, disable and uninstall extensions, so a completed run leaves
     * storage/cache at a generation that a later run against a rebuilt database is behind.
     * Materialization refuses to move backwards — correctly, because in production that would
     * be a silent rollback — which made the suite pass only on a pristine working tree. Tests
     * take the same decision an operator takes with `extension:runtime:materialize --repair`.
     */
    private static function discardReplicaLocalRuntime(Container $container): void
    {
        $compiler = $container->get(ExtensionRuntimeMapCompiler::class);
        if (!$compiler instanceof ExtensionRuntimeMapCompiler) {
            throw new RuntimeException('The extension runtime compiler is unavailable.');
        }
        $compiler->discardLocal();
    }

    public static function administratorContext(Container $container): ExecutionContext
    {
        $identities = $container->get(AdministratorIdentityGateway::class);
        if (!$identities instanceof AdministratorIdentityGateway) {
            throw new RuntimeException('The administrator identity gateway is unavailable.');
        }
        $principal = $identities->authenticate(self::EMAIL, self::PASSWORD, 'integration-tests');
        if ($principal === null) {
            self::bootstrapAdministrator($container);
            $principal = $identities->authenticate(self::EMAIL, self::PASSWORD, 'integration-tests');
        }
        if ($principal === null) {
            throw new RuntimeException('The integration administrator could not be authenticated.');
        }

        return $principal->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'integration-' . bin2hex(random_bytes(16)),
        );
    }

    public static function workerContext(Container $container): ExecutionContext
    {
        $command = $container->get(QueueWorkCommand::class);
        if (!$command instanceof QueueWorkCommand) {
            throw new RuntimeException('The worker command is unavailable.');
        }
        $property = new \ReflectionProperty($command, 'system');
        $system = $property->getValue($command);
        if (!$system instanceof SystemPrincipal) {
            throw new RuntimeException('The worker system principal is unavailable.');
        }

        return $system->context(
            SiteContext::default(),
            'integration-worker-' . bin2hex(random_bytes(16)),
        );
    }

    public static function schedulerContext(Container $container): ExecutionContext
    {
        $command = $container->get(ScheduleRunCommand::class);
        if (!$command instanceof ScheduleRunCommand) {
            throw new RuntimeException('The scheduler command is unavailable.');
        }
        $property = new \ReflectionProperty($command, 'system');
        $system = $property->getValue($command);
        if (!$system instanceof SystemPrincipal) {
            throw new RuntimeException('The scheduler system principal is unavailable.');
        }

        return $system->context(
            SiteContext::default(),
            'integration-scheduler-' . bin2hex(random_bytes(16)),
        );
    }

    /**
     * Creates a deliberately narrowed principal for integration-only denial tests.
     * Production callers cannot obtain the authority proof reflected here.
     *
     * @param list<array{capability: string, scope_type: string, scope_identifier: ?string}> $grants
     */
    public static function contextFromGrantRows(
        Container $container,
        array $grants,
        string $site = SiteContext::DEFAULT,
    ): ExecutionContext {
        $administrator = self::administratorContext($container);
        $principal = $administrator->principal();
        if ($principal === null) {
            throw new RuntimeException('The integration administrator principal is unavailable.');
        }
        $property = new \ReflectionProperty(AuthenticatedPrincipal::class, 'provenance');
        $provenance = $property->getValue($principal);
        if (!is_object($provenance)) {
            throw new RuntimeException('The integration authority proof is unavailable.');
        }

        return AuthenticatedPrincipal::issueFromGrantRows(
            $provenance,
            $principal->subject(),
            $grants,
            'integration-scoped:' . $principal->subject(),
        )->context(
            SiteContext::fromString($site),
            AuthenticationStrength::BearerToken,
            'integration-scoped-' . bin2hex(random_bytes(16)),
        );
    }

    private static function bootstrapAdministrator(Container $container): void
    {
        $passwordFile = tempnam(sys_get_temp_dir(), 'kumwe-integration-password-');
        if (!is_string($passwordFile)) {
            throw new RuntimeException('The integration password file could not be created.');
        }

        try {
            if (file_put_contents($passwordFile, self::PASSWORD) === false || !chmod($passwordFile, 0o600)) {
                throw new RuntimeException('The integration password file could not be protected.');
            }
            $command = $container->get(CreateAdministratorCommand::class);
            if (
                !$command instanceof CreateAdministratorCommand || $command->execute([
                '--email=' . self::EMAIL,
                '--name=Integration Administrator',
                '--password-file=' . $passwordFile,
                ], self::output()) !== 0
            ) {
                throw new RuntimeException('The integration administrator could not be bootstrapped.');
            }
        } finally {
            if (is_file($passwordFile)) {
                unlink($passwordFile);
            }
        }
    }

    private static function output(): Output
    {
        return new class implements Output {
            use TranslatesConsoleOutput;

            public function line(string $message): void
            {
            }

            public function error(string $message): void
            {
            }
        };
    }
}
