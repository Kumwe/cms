<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Console\Command;

use Kumwe\App\Tests\Support\TranslatesConsoleOutput;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\Application\Security\HighImpactCredentialGuard;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\BusinessSecurity\Application\Administration\BusinessSecurityAdministrationRepository;
use Kumwe\App\Delivery\Console\Command\DemoInstallCommand;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Demo\Infrastructure\DemoAccessProvisioner;
use Kumwe\App\Demo\Infrastructure\DemoExampleExtensionInstaller;
use Kumwe\App\Demo\Infrastructure\FilesystemDemoManifestCatalog;
use Kumwe\App\Extension\Application\ExtensionManager;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Identity\Application\Administration\AccessControlRepository;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Identity\Application\Administration\AdministratorSessionStore;
use Kumwe\App\Identity\Application\Security\PasswordHasher;
use Kumwe\App\Identity\Application\StepUp\StepUpCredentialStore;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Configuration\ApplicationConfiguration;
use Kumwe\App\Kernel\Configuration\ConfigurationFactory;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use ReflectionClass;
use RuntimeException;

/**
 * Proves the one-step demonstration command's option, gating, and credentials-file decisions.
 *
 * The full end-to-end path — real cast provisioning and signed example installation against a
 * database — is exercised by the live installation checks; what belongs here is the command's own
 * surface: the option grammar, the protected password-file gate, the `none`-profile skip, the rule
 * that the credentials file is only created when this run actually generated a password, that its
 * path is reported before any example installs, and that a failing example is named.
 *
 * @since  2.0.0
 */
#[CoversClass(DemoInstallCommand::class)]
final class DemoInstallCommandTest extends TestCase
{
    private const string ROLE_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bb310';
    private const string USER_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bb311';

    private string $fixtures;
    private string $passwordFile;
    private string $credentialsFile;

    protected function setUp(): void
    {
        $fixtures = sys_get_temp_dir() . '/kumwe-demo-install-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($fixtures . '/resources/demo/business/vdm', 0o700, true));
        $this->fixtures = $fixtures;
        self::assertNotFalse(file_put_contents($fixtures . '/resources/demo/business/vdm/profile.json', '{}'));
        $manifest = [
            'format' => 'kumwe.demo-access/v1',
            'profile' => 'vdm',
            'version' => 1,
            'roles' => [[
                'handle' => 'vdm-clerk',
                'label' => 'Clerk',
                'area' => 'administrator',
                'capabilities' => ['content.read'],
            ]],
            'staff' => [[
                'email' => 'clerk@vdm.example',
                'display_name' => 'Demo Clerk',
                'role' => 'vdm-clerk',
            ]],
            'organizations' => [],
        ];
        self::assertNotFalse(file_put_contents(
            $fixtures . '/resources/demo/business/vdm/access.json',
            json_encode($manifest, JSON_THROW_ON_ERROR),
        ));
        $this->passwordFile = $fixtures . '/admin-password';
        self::assertNotFalse(file_put_contents($this->passwordFile, "correct-horse-battery\n"));
        self::assertTrue(chmod($this->passwordFile, 0o600));
        $this->credentialsFile = $fixtures . '/demo-access-credentials.json';
    }

    protected function tearDown(): void
    {
        $paths = [
            $this->passwordFile,
            $this->credentialsFile,
            $this->fixtures . '/resources/demo/business/vdm/profile.json',
            $this->fixtures . '/resources/demo/business/vdm/access.json',
        ];
        foreach ($paths as $path) {
            @unlink($path);
        }
        @rmdir($this->fixtures . '/resources/demo/business/vdm');
        @rmdir($this->fixtures . '/resources/demo/business');
        @rmdir($this->fixtures . '/resources/demo');
        @rmdir($this->fixtures . '/resources');
        @rmdir($this->fixtures);
    }

    public function testRejectsAnArgumentWithoutOptionSyntax(): void
    {
        $output = new DemoInstallCommandOutput();

        self::assertSame(1, $this->command('none')->execute(['--admin-email'], $output));
        self::assertSame(['Options must use --name=value syntax.'], $output->errors);
    }

    public function testRequiresTheCredentialsFileOption(): void
    {
        $output = new DemoInstallCommandOutput();

        self::assertSame(1, $this->command('none')->execute([
            '--admin-email=owner@example.com',
            '--admin-password-file=' . $this->passwordFile,
        ], $output));
        self::assertSame(['The --credentials-file option is required.'], $output->errors);
    }

    public function testRejectsARelativeCredentialsPath(): void
    {
        $output = new DemoInstallCommandOutput();

        self::assertSame(1, $this->command('none')->execute([
            '--admin-email=owner@example.com',
            '--admin-password-file=' . $this->passwordFile,
            '--credentials-file=demo-access-credentials.json',
        ], $output));
        self::assertSame(['The credentials file must be an absolute path.'], $output->errors);
    }

    public function testRejectsAGroupReadablePasswordFile(): void
    {
        self::assertTrue(chmod($this->passwordFile, 0o640));
        $output = new DemoInstallCommandOutput();

        self::assertSame(1, $this->command('none')->execute($this->arguments(), $output));
        self::assertSame(
            ['The password file must not be readable or writable by group or others.'],
            $output->errors,
        );
    }

    public function testRejectsAnUnshippedExampleSelection(): void
    {
        $output = new DemoInstallCommandOutput();

        self::assertSame(1, $this->command('none')->execute(
            [...$this->arguments(), '--extensions=ecommerce'],
            $output,
        ));
        self::assertStringContainsString('The ecommerce example is not shipped', $output->errors[0]);
    }

    public function testSkipsTheCastCleanlyWhenNoBusinessProfileIsSelected(): void
    {
        $output = new DemoInstallCommandOutput();

        self::assertSame(0, $this->command('none')->execute($this->arguments(), $output));
        self::assertStringContainsString('skipping the demonstration cast', $output->lines[0]);
        self::assertSame('Confirmed kumwe/announcements-example (announcements).', $output->lines[1]);
        self::assertCount(4, array_filter(
            $output->lines,
            static fn (string $line): bool => str_starts_with($line, 'Confirmed kumwe/'),
        ));
        self::assertContains(
            'Staff sign in at /administrator; portal organization members sign in at /portal.',
            $output->lines,
        );
        self::assertContains(
            'The selected site content and business dataset were already installed by database:migrate.',
            $output->lines,
        );
        self::assertFileDoesNotExist($this->credentialsFile);
    }

    public function testConfirmsTheExistingCastWithoutCreatingACredentialsFile(): void
    {
        $repository = $this->createStub(AccessControlRepository::class);
        $repository->method('roles')->willReturn([['code' => 'vdm-clerk', 'id' => self::ROLE_ID]]);
        $repository->method('userIdByEmail')->willReturn(self::USER_ID);
        $output = new DemoInstallCommandOutput();

        self::assertSame(0, $this->command('vdm', $repository)->execute($this->arguments(), $output));
        self::assertSame('Confirmed clerk@vdm.example as vdm-clerk (administrator)', $output->lines[0]);
        self::assertContains('No new credentials were generated; existing sign-ins remain valid.', $output->lines);
        self::assertFileDoesNotExist($this->credentialsFile);
    }

    public function testWritesOwnerOnlyCredentialsWhenAPasswordWasGenerated(): void
    {
        $output = new DemoInstallCommandOutput();

        self::assertSame(0, $this->command('vdm', $this->emptyRepository())->execute(
            $this->arguments(),
            $output,
        ));
        self::assertStringStartsWith('Provisioned clerk@vdm.example as vdm-clerk', $output->lines[0]);
        self::assertSame(
            sprintf('Wrote the demonstration credentials file %s.', $this->credentialsFile),
            $output->lines[1],
        );
        self::assertStringStartsWith('Confirmed kumwe/announcements-example', $output->lines[2]);
        self::assertFileExists($this->credentialsFile);
        self::assertSame(0o600, fileperms($this->credentialsFile) & 0o777);
        $document = file_get_contents($this->credentialsFile);
        self::assertIsString($document);
        self::assertStringContainsString('kumwe.demo-access-credentials/v1', $document);
        self::assertStringContainsString('clerk@vdm.example', $document);
    }

    public function testRefusesToOverwriteAnExistingCredentialsFile(): void
    {
        self::assertNotFalse(file_put_contents($this->credentialsFile, '{}'));
        $output = new DemoInstallCommandOutput();

        self::assertSame(1, $this->command('vdm', $this->emptyRepository())->execute(
            $this->arguments(),
            $output,
        ));
        self::assertSame(
            ['The credentials file already exists; move it away before provisioning again.'],
            $output->errors,
        );
        self::assertSame('{}', file_get_contents($this->credentialsFile));
    }

    public function testNarrowsTheExampleSelectionToTheRequestedSubset(): void
    {
        $manager = $this->createMock(ExtensionManager::class);
        $manager->method('installed')->willReturn($this->activeExamples());
        $manager->expects(self::never())->method('install');
        $output = new DemoInstallCommandOutput();

        self::assertSame(0, $this->command('none', null, $manager)->execute(
            [...$this->arguments(), '--extensions=announcements'],
            $output,
        ));
        self::assertSame(1, count(array_filter(
            $output->lines,
            static fn (string $line): bool => str_starts_with($line, 'Confirmed kumwe/'),
        )));
    }

    /**
     * An example step that throws names the example in the failure line, after the credentials file
     * the run already wrote has been reported, so the passwords on disk are never left unannounced.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNamesTheFailingExampleAfterReportingTheCredentialsFile(): void
    {
        $manager = $this->createMock(ExtensionManager::class);
        $manager->method('installed')
            ->willThrowException(new RuntimeException('The extension registry is unavailable.'));
        $manager->expects(self::never())->method('install');
        $output = new DemoInstallCommandOutput();

        self::assertSame(1, $this->command('vdm', $this->emptyRepository(), $manager)->execute(
            [...$this->arguments(), '--extensions=asset-inspection,announcements'],
            $output,
        ));
        self::assertSame(
            ['Installing the asset-inspection example failed: The extension registry is unavailable.'],
            $output->errors,
        );
        self::assertSame(
            sprintf('Wrote the demonstration credentials file %s.', $this->credentialsFile),
            $output->lines[1],
        );
        self::assertFileExists($this->credentialsFile);
        self::assertNotContains(
            'Staff sign in at /administrator; portal organization members sign in at /portal.',
            $output->lines,
        );
    }

    public function testReportsAFailedAuthenticationWithoutTouchingAnyService(): void
    {
        $identities = $this->createStub(AdministratorIdentityGateway::class);
        $identities->method('authenticate')->willReturn(null);
        $manager = $this->createMock(ExtensionManager::class);
        $manager->expects(self::never())->method('installed');
        $output = new DemoInstallCommandOutput();
        $command = new DemoInstallCommand(
            $this->configuration('vdm'),
            new FilesystemDemoManifestCatalog($this->fixtures),
            $identities,
            $this->reflectedProvisioner(),
            $this->installer($manager),
            $this->clock(),
        );

        self::assertSame(1, $command->execute($this->arguments(), $output));
        self::assertSame(['The administrator could not be authenticated.'], $output->errors);
        self::assertFileDoesNotExist($this->credentialsFile);
    }

    /**
     * Build the command under test around fakes shaped for one scenario.
     *
     * @param   string                    $businessProfile  Configured business profile selector.
     * @param   ?AccessControlRepository  $repository       Identity lookups for a real provisioner, or
     *          null for scenarios that never reach the provisioner.
     * @param   ?ExtensionManager         $manager          Extension pipeline double, or null for one
     *          that confirms every shipped example as active.
     *
     * @return  DemoInstallCommand  Fully wired command under test.
     *
     * @since   2.0.0
     */
    private function command(
        string $businessProfile,
        ?AccessControlRepository $repository = null,
        ?ExtensionManager $manager = null,
    ): DemoInstallCommand {
        $identities = $this->createStub(AdministratorIdentityGateway::class);
        $identities->method('authenticate')->willReturn(AuthorizationContext::principal(['users.manage']));
        if ($manager === null) {
            $manager = $this->createStub(ExtensionManager::class);
            $manager->method('installed')->willReturn($this->activeExamples());
        }

        return new DemoInstallCommand(
            $this->configuration($businessProfile),
            new FilesystemDemoManifestCatalog($this->fixtures),
            $identities,
            $repository === null ? $this->reflectedProvisioner() : $this->provisioner($repository),
            $this->installer($manager),
            $this->clock(),
        );
    }

    /**
     * Compose the required option set every successful scenario starts from.
     *
     * @return  list<string>  Well-formed `--name=value` arguments.
     *
     * @since   2.0.0
     */
    private function arguments(): array
    {
        return [
            '--admin-email=owner@example.com',
            '--admin-password-file=' . $this->passwordFile,
            '--credentials-file=' . $this->credentialsFile,
        ];
    }

    /**
     * Build a validated configuration selecting the given business profile.
     *
     * @param   string  $businessProfile  Profile selector, `vdm` or `none`.
     *
     * @return  ApplicationConfiguration  Process configuration for the command under test.
     *
     * @since   2.0.0
     */
    private function configuration(string $businessProfile): ApplicationConfiguration
    {
        return (new ConfigurationFactory())->create(new Environment([
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_BASE_URL' => 'https://kumwe.test',
            'APP_TRUSTED_HOSTS' => 'kumwe.test',
            'APP_SECRET' => str_repeat('a', 32),
            'EXTENSION_RUNTIME_SIGNING_KEY' => str_repeat('r', 32),
            'KUMWE_DEPLOYMENT_ID' => 'deployment-2026-08-05',
            'KUMWE_REPLICA_ID' => 'replica-one',
            'KUMWE_PROCESS_ID' => 'app-runtime',
            'KUMWE_INSTANCE_ID' => 'instance-one',
            'KUMWE_BUSINESS_PROFILE' => $businessProfile,
            'DB_HOST' => 'database',
            'DB_DRIVER' => 'mariadb',
            'DB_PORT' => '3306',
            'DB_NAME' => 'kumwe',
            'DB_USER' => 'kumwe',
            'DB_PASSWORD' => 'secret',
            'DB_TABLE_PREFIX' => 'kumwe_',
            'DB_SERVER_VERSION' => 'mariadb-12.3.2',
        ]));
    }

    /**
     * Build a real provisioner whose canonical services are backed by test doubles.
     *
     * @param   AccessControlRepository  $repository  Scenario-shaped identity and role lookups.
     *
     * @return  DemoAccessProvisioner  Provisioner exercising the real report and password logic.
     *
     * @since   2.0.0
     */
    private function provisioner(AccessControlRepository $repository): DemoAccessProvisioner
    {
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $passwords = $this->createStub(PasswordHasher::class);
        $passwords->method('hash')->willReturn('argon2id-test-hash');
        $access = new AccessControlService(
            $repository,
            $passwords,
            $transactions,
            $this->createStub(AuditRecorder::class),
            $this->clock(),
            $this->createStub(AuthorizationGateway::class),
            AuthorizationContext::ownershipWriter(),
            $this->createStub(HighImpactCredentialGuard::class),
            $this->createStub(StepUpCredentialStore::class),
            $this->createStub(AdministratorSessionStore::class),
        );
        $connection = $this->createStub(Connection::class);

        return new DemoAccessProvisioner(
            $access,
            $repository,
            $this->createStub(BusinessSecurityAdministrationRepository::class),
            $connection,
            new TableNames($connection, 'kumwe_'),
            $transactions,
            $this->createStub(AuditRecorder::class),
            $this->clock(),
        );
    }

    /**
     * Produce a provisioner instance for scenarios that must never reach it.
     *
     * @return  DemoAccessProvisioner  Uninitialized instance; any call would fail the test loudly.
     *
     * @since   2.0.0
     */
    private function reflectedProvisioner(): DemoAccessProvisioner
    {
        return (new ReflectionClass(DemoAccessProvisioner::class))->newInstanceWithoutConstructor();
    }

    /**
     * Build a repository whose role exists but whose staff identity does not yet.
     *
     * @return  AccessControlRepository  Lookup double that makes the provisioner create one account.
     *
     * @since   2.0.0
     */
    private function emptyRepository(): AccessControlRepository
    {
        $repository = $this->createStub(AccessControlRepository::class);
        $repository->method('roles')->willReturn([['code' => 'vdm-clerk', 'id' => self::ROLE_ID]]);
        $repository->method('userIdByEmail')->willReturnOnConsecutiveCalls(null, self::USER_ID);
        $repository->method('roleGrants')->willReturn([]);

        return $repository;
    }

    /**
     * Build the example installer over the real repository examples with a stubbed pipeline.
     *
     * @param   ExtensionManager  $manager  Stubbed or mocked canonical extension pipeline.
     *
     * @return  DemoExampleExtensionInstaller  Installer wired to the shipped example directories.
     *
     * @since   2.0.0
     */
    private function installer(ExtensionManager $manager): DemoExampleExtensionInstaller
    {
        $trust = (new ReflectionClass(TrustStore::class))->newInstanceWithoutConstructor();

        return new DemoExampleExtensionInstaller(dirname(__DIR__, 5), $manager, $trust, $this->clock());
    }

    /**
     * Describe every shipped default example as already active in the registry.
     *
     * @return  list<array{identifier: string, status: string}>  Registry rows for the default set.
     *
     * @since   2.0.0
     */
    private function activeExamples(): array
    {
        return [
            ['identifier' => 'kumwe/announcements-example', 'status' => 'active'],
            ['identifier' => 'kumwe/asset-inspection-example', 'status' => 'active'],
            ['identifier' => 'kumwe/audit-listener-example', 'status' => 'active'],
            ['identifier' => 'kumwe/horizon-theme-example', 'status' => 'active'],
        ];
    }

    /**
     * Build the fixed clock every collaborator shares.
     *
     * @return  ClockInterface  Clock pinned to one instant.
     *
     * @since   2.0.0
     */
    private function clock(): ClockInterface
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-12T00:00:00+00:00'));

        return $clock;
    }
}

/**
 * Output double that records every ordinary and failure line for assertions.
 *
 * @since  2.0.0
 */
final class DemoInstallCommandOutput implements Output
{
    use TranslatesConsoleOutput;

    /** @var list<string> */
    public array $lines = [];

    /** @var list<string> */
    public array $errors = [];

    public function line(string $message): void
    {
        $this->lines[] = $message;
    }

    public function error(string $message): void
    {
        $this->errors[] = $message;
    }
}
