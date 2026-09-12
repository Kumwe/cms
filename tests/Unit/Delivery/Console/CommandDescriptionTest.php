<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Console;

use Kumwe\App\BusinessReporting\Delivery\Console\ReportCommand;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Command\ActivateExtensionCommand;
use Kumwe\App\Delivery\Console\Command\BuildExtensionCommand;
use Kumwe\App\Delivery\Console\Command\CreateAccessTokenCommand;
use Kumwe\App\Delivery\Console\Command\CreateAdministratorCommand;
use Kumwe\App\Delivery\Console\Command\DemoAccessCommand;
use Kumwe\App\Delivery\Console\Command\DemoExamplesCommand;
use Kumwe\App\Delivery\Console\Command\DemoExportCommand;
use Kumwe\App\Delivery\Console\Command\DemoInstallCommand;
use Kumwe\App\Delivery\Console\Command\DisableExtensionCommand;
use Kumwe\App\Delivery\Console\Command\ExportAuditTrailCommand;
use Kumwe\App\Delivery\Console\Command\HealthCheckCommand;
use Kumwe\App\Delivery\Console\Command\InspectExtensionCommand;
use Kumwe\App\Delivery\Console\Command\InstallExtensionCommand;
use Kumwe\App\Delivery\Console\Command\IntegrationWorkCommand;
use Kumwe\App\Delivery\Console\Command\ListExtensionsCommand;
use Kumwe\App\Delivery\Console\Command\ManageAccessCommand;
use Kumwe\App\Delivery\Console\Command\ManageAutomationCommand;
use Kumwe\App\Delivery\Console\Command\ManageBusinessDefinitionsCommand;
use Kumwe\App\Delivery\Console\Command\ManageBusinessRecordsCommand;
use Kumwe\App\Delivery\Console\Command\ManageBusinessSchemaCommand;
use Kumwe\App\Delivery\Console\Command\ManageContentCommand;
use Kumwe\App\Delivery\Console\Command\ManageContentModelsCommand;
use Kumwe\App\Delivery\Console\Command\ManageIntegrationsCommand;
use Kumwe\App\Delivery\Console\Command\ManageNavigationCommand;
use Kumwe\App\Delivery\Console\Command\ManagePostingPeriodsCommand;
use Kumwe\App\Delivery\Console\Command\ManageSettingsCommand;
use Kumwe\App\Delivery\Console\Command\ManageTrustStoreCommand;
use Kumwe\App\Delivery\Console\Command\MaterializeExtensionRuntimeCommand;
use Kumwe\App\Delivery\Console\Command\McpServeCommand;
use Kumwe\App\Delivery\Console\Command\MigrateCommand;
use Kumwe\App\Delivery\Console\Command\MigrationStatusCommand;
use Kumwe\App\Delivery\Console\Command\QueueWorkCommand;
use Kumwe\App\Delivery\Console\Command\RecoverAdministratorThemeCommand;
use Kumwe\App\Delivery\Console\Command\RecoverCredentialsCommand;
use Kumwe\App\Delivery\Console\Command\RecoverMigrationLockCommand;
use Kumwe\App\Delivery\Console\Command\RotateRecordSecretsCommand;
use Kumwe\App\Delivery\Console\Command\RunExtensionConformanceCommand;
use Kumwe\App\Delivery\Console\Command\ScaffoldExtensionCommand;
use Kumwe\App\Delivery\Console\Command\ScheduleRunCommand;
use Kumwe\App\Delivery\Console\Command\SignExtensionCommand;
use Kumwe\App\Delivery\Console\Command\UninstallExtensionCommand;
use Kumwe\App\Delivery\Console\Command\VerifyAuditTrailCommand;
use Kumwe\App\Delivery\Console\Command\WatchExtensionRuntimeCommand;
use Kumwe\Localization\Domain\MessageIdentifier;
use Kumwe\App\Tests\Support\InterfaceTranslation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins the summary line every command contributes to `bin/kumwe list`.
 *
 * A description is the one piece of wording a command surrenders to the dispatcher: it returns an
 * identifier and the listing resolves it, which is what lets the listing be translated without any
 * command carrying a translator. An identifier the catalogue does not carry would print itself, so
 * this walks the whole command set rather than sampling it.
 *
 * @since  2.0.0
 */
#[CoversClass(ActivateExtensionCommand::class)]
#[CoversClass(BuildExtensionCommand::class)]
#[CoversClass(CreateAccessTokenCommand::class)]
#[CoversClass(CreateAdministratorCommand::class)]
#[CoversClass(DemoAccessCommand::class)]
#[CoversClass(DemoExamplesCommand::class)]
#[CoversClass(DemoExportCommand::class)]
#[CoversClass(DemoInstallCommand::class)]
#[CoversClass(DisableExtensionCommand::class)]
#[CoversClass(ExportAuditTrailCommand::class)]
#[CoversClass(HealthCheckCommand::class)]
#[CoversClass(InspectExtensionCommand::class)]
#[CoversClass(InstallExtensionCommand::class)]
#[CoversClass(IntegrationWorkCommand::class)]
#[CoversClass(ListExtensionsCommand::class)]
#[CoversClass(ManageAccessCommand::class)]
#[CoversClass(ManageAutomationCommand::class)]
#[CoversClass(ManageBusinessDefinitionsCommand::class)]
#[CoversClass(ManageBusinessRecordsCommand::class)]
#[CoversClass(ManageBusinessSchemaCommand::class)]
#[CoversClass(ManageContentCommand::class)]
#[CoversClass(ManageContentModelsCommand::class)]
#[CoversClass(ManageIntegrationsCommand::class)]
#[CoversClass(ManageNavigationCommand::class)]
#[CoversClass(ManagePostingPeriodsCommand::class)]
#[CoversClass(ManageSettingsCommand::class)]
#[CoversClass(ManageTrustStoreCommand::class)]
#[CoversClass(MaterializeExtensionRuntimeCommand::class)]
#[CoversClass(McpServeCommand::class)]
#[CoversClass(MigrateCommand::class)]
#[CoversClass(MigrationStatusCommand::class)]
#[CoversClass(QueueWorkCommand::class)]
#[CoversClass(RecoverAdministratorThemeCommand::class)]
#[CoversClass(RecoverCredentialsCommand::class)]
#[CoversClass(RecoverMigrationLockCommand::class)]
#[CoversClass(RotateRecordSecretsCommand::class)]
#[CoversClass(RunExtensionConformanceCommand::class)]
#[CoversClass(ScaffoldExtensionCommand::class)]
#[CoversClass(ScheduleRunCommand::class)]
#[CoversClass(SignExtensionCommand::class)]
#[CoversClass(UninstallExtensionCommand::class)]
#[CoversClass(VerifyAuditTrailCommand::class)]
#[CoversClass(WatchExtensionRuntimeCommand::class)]
#[CoversClass(ReportCommand::class)]
final class CommandDescriptionTest extends TestCase
{
    /**
     * Every command names its summary with a valid identifier the catalogue actually carries.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryCommandDescribesItselfWithACatalogueMessage(): void
    {
        $translator = InterfaceTranslation::translator();
        foreach (self::commands() as $class) {
            $command = (new ReflectionClass($class))->newInstanceWithoutConstructor();
            self::assertInstanceOf(Command::class, $command);
            $identifier = $command->description();

            self::assertTrue(
                MessageIdentifier::isValid($identifier),
                sprintf('%s returns %s, which is not a message identifier.', $class, $identifier),
            );
            self::assertTrue(
                $translator->has($identifier),
                sprintf('%s names %s, which the source catalogue does not carry.', $class, $identifier),
            );
            self::assertNotSame(
                $identifier,
                $translator->translate($identifier),
                sprintf('%s resolves to its own identifier.', $class),
            );
        }
    }

    /**
     * Every command's name stays the stable, colon-separated token an operator types.
     *
     * The name is machinery, not wording: a translated command name would break every script and
     * runbook that invokes it, so it must not have moved into the catalogue with the summary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryCommandKeepsItsNameOutOfTheCatalogue(): void
    {
        $names = [];
        foreach (self::commands() as $class) {
            $command = (new ReflectionClass($class))->newInstanceWithoutConstructor();
            self::assertInstanceOf(Command::class, $command);
            $name = $command->name();

            self::assertMatchesRegularExpression('/^[a-z][a-z0-9:-]*$/D', $name, $class);
            self::assertArrayNotHasKey($name, $names, sprintf('%s repeats the command name %s.', $class, $name));
            $names[$name] = true;
        }

        self::assertCount(count(self::commands()), $names);
    }

    /**
     * The declared command set is every command the source tree actually ships.
     *
     * A hardcoded list checked against a hardcoded count proves nothing: it can only fail when someone
     * edits the list. What matters is that a command added to `src/` cannot escape the checks above by
     * being left out of the list, so the set is derived from the tree and compared.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheDeclaredSetIsEveryCommandTheSourceTreeShips(): void
    {
        $root = dirname(__DIR__, 4);
        $found = [];
        $trees = [
            '/src/Delivery/Console/Command' => 'Kumwe\\App\\Delivery\\Console\\Command\\',
            '/src/BusinessReporting/Delivery/Console' => 'Kumwe\\App\\BusinessReporting\\Delivery\\Console\\',
        ];
        foreach ($trees as $relative => $namespace) {
            $entries = glob($root . $relative . '/*.php');
            self::assertIsArray($entries);
            foreach ($entries as $file) {
                $class = $namespace . basename($file, '.php');
                if (!class_exists($class) || !is_subclass_of($class, Command::class)) {
                    continue;
                }
                $found[] = $class;
            }
        }

        sort($found, SORT_STRING);
        $declared = self::commands();
        sort($declared, SORT_STRING);
        self::assertSame($declared, $found);
    }

    /**
     * Every dispatchable command class, the reporting one outside the command directory included.
     *
     * @return  list<class-string<Command>>  The full registered command set.
     *
     * @since   2.0.0
     */
    private static function commands(): array
    {
        return [
            ActivateExtensionCommand::class,
            BuildExtensionCommand::class,
            CreateAccessTokenCommand::class,
            CreateAdministratorCommand::class,
            DemoAccessCommand::class,
            DemoExamplesCommand::class,
            DemoExportCommand::class,
            DemoInstallCommand::class,
            DisableExtensionCommand::class,
            ExportAuditTrailCommand::class,
            HealthCheckCommand::class,
            InspectExtensionCommand::class,
            InstallExtensionCommand::class,
            IntegrationWorkCommand::class,
            ListExtensionsCommand::class,
            ManageAccessCommand::class,
            ManageAutomationCommand::class,
            ManageBusinessDefinitionsCommand::class,
            ManageBusinessRecordsCommand::class,
            ManageBusinessSchemaCommand::class,
            ManageContentCommand::class,
            ManageContentModelsCommand::class,
            ManageIntegrationsCommand::class,
            ManageNavigationCommand::class,
            ManagePostingPeriodsCommand::class,
            ManageSettingsCommand::class,
            ManageTrustStoreCommand::class,
            MaterializeExtensionRuntimeCommand::class,
            McpServeCommand::class,
            MigrateCommand::class,
            MigrationStatusCommand::class,
            QueueWorkCommand::class,
            RecoverAdministratorThemeCommand::class,
            RecoverCredentialsCommand::class,
            RecoverMigrationLockCommand::class,
            RotateRecordSecretsCommand::class,
            RunExtensionConformanceCommand::class,
            ScaffoldExtensionCommand::class,
            ScheduleRunCommand::class,
            SignExtensionCommand::class,
            UninstallExtensionCommand::class,
            VerifyAuditTrailCommand::class,
            WatchExtensionRuntimeCommand::class,
            ReportCommand::class,
        ];
    }
}
