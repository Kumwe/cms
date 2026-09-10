<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Console\Command;

use Kumwe\App\Tests\Support\TranslatesConsoleOutput;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Delivery\Console\Command\ActivateExtensionCommand;
use Kumwe\App\Delivery\Console\Command\ConsoleAuthorizer;
use Kumwe\App\Delivery\Console\Command\RecoverAdministratorThemeCommand;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Extension\Application\ExtensionManager;
use Kumwe\App\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\App\Presentation\Application\AdministratorThemeRecovery;
use Kumwe\App\Extension\Domain\ThemeSurface;
use Kumwe\App\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActivateExtensionCommand::class)]
#[CoversClass(RecoverAdministratorThemeCommand::class)]
final class ThemeCommandTest extends TestCase
{
    private const ACTOR = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';
    private string $tokenFile;

    protected function setUp(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'kumwe-theme-token-');
        self::assertIsString($file);
        $this->tokenFile = $file;
        self::assertTrue(chmod($this->tokenFile, 0600));
        self::assertNotFalse(file_put_contents($this->tokenFile, 'verified-token'));
    }

    protected function tearDown(): void
    {
        unlink($this->tokenFile);
    }

    public function testSiteThemeActivationCarriesTheExplicitSurface(): void
    {
        $extensions = $this->createMock(ExtensionManager::class);
        $extensions->expects(self::once())->method('activate')->with(
            'acme/corporate',
            self::isInstanceOf(ExecutionContext::class),
            ThemeSurface::Site,
        )->willReturn(['identifier' => 'acme/corporate']);
        $output = new ThemeCommandOutput();

        self::assertSame(0, $this->command($extensions)->execute([
            'acme/corporate',
            '--surface=site',
            '--site=default',
            '--token-file=' . $this->tokenFile,
        ], $output));
        self::assertSame(['Activated acme/corporate.'], $output->lines);
    }

    public function testCliCannotBypassAdministratorStepUp(): void
    {
        $extensions = $this->createMock(ExtensionManager::class);
        $extensions->expects(self::never())->method('activate');
        $output = new ThemeCommandOutput();

        self::assertSame(1, $this->command($extensions)->execute([
            'acme/corporate',
            '--surface=administrator',
            '--site=default',
            '--token-file=' . $this->tokenFile,
        ], $output));
        self::assertStringContainsString('step-up authentication', $output->errors[0]);
    }

    public function testRecoveryRequiresExactConfirmationAndRestoresCore(): void
    {
        $recovery = $this->createMock(AdministratorThemeRecovery::class);
        $recovery->expects(self::once())->method('recover');
        $output = new ThemeCommandOutput();
        $command = new RecoverAdministratorThemeCommand($recovery);

        self::assertSame(1, $command->execute([], $output));
        self::assertSame(0, $command->execute(['--confirm=restore-core-administrator'], $output));
        self::assertStringContainsString('protected built-in administrator theme', $output->lines[0]);
    }

    private function command(ExtensionManager $extensions): ActivateExtensionCommand
    {
        $tokens = $this->createStub(AccessTokenVerifier::class);
        $tokens->method('verify')->willReturn(AuthorizationContext::principal(
            ['extensions.manage', 'themes.site.manage'],
            self::ACTOR,
        ));

        return new ActivateExtensionCommand($extensions, new ConsoleAuthorizer($tokens));
    }
}

final class ThemeCommandOutput implements Output
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
