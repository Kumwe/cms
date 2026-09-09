<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Pins the fail-closed production composition of contextual Content Studio launches.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class StudioContextualAuthoringLaunchBoundaryTest extends TestCase
{
    /**
     * Production supplies the Producer-proven hosted configuration provider behind the atomic resolver.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProductionRequiresTheAtomicLaunchResolverAndHostedConfigurationProvider(): void
    {
        $container = $this->contents('src/Kernel/ContainerFactory.php');
        $handler = $this->contents('src/Administrator/Http/Handler/AdministratorContentEditorHandler.php');

        self::assertStringContainsString(
            'StudioContextualAuthoringConfigurationProvider::class,' . "\n"
                . '            static fn (Container $container): StudioContextualAuthoringConfigurationProvider =>'
                . "\n"
                . '                new HostedContentStudioAuthoringConfigurationProvider(',
            $container,
        );
        self::assertStringNotContainsString('UnavailableStudioContextualAuthoringConfigurationProvider', $container);
        self::assertStringContainsString('self::studioContextualAuthoringQualification()', $container);
        self::assertStringContainsString('StudioDeploymentEmitter::class', $container);
        self::assertStringContainsString(
            'StudioBrowserAssetLocator::npmPackages($configuration->studioBrowserBaseUrl)',
            $container,
        );
        self::assertStringContainsString('ContentStudioAuthoringLaunchResolver::class', $container);
        self::assertStringContainsString(
            'self::service($container, StudioContextualAuthoringConfigurationProvider::class)',
            $container,
        );
        self::assertStringContainsString(
            'self::service($container, ContentStudioAuthoringLaunchResolver::class)',
            $container,
        );
        self::assertStringContainsString('private ContentStudioAuthoringLaunchResolver $studioLaunches', $handler);
        self::assertStringNotContainsString('private StudioContextualAuthoringAvailability', $handler);
        self::assertStringContainsString('$this->studioLaunches->resolve(', $handler);
        self::assertStringContainsString('$session->csrfToken', $handler);
    }

    /**
     * App retains the future configuration as an opaque object and does not define Studio's wire shape.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testConfigurationSeamDoesNotInventOrSerializeAStudioContract(): void
    {
        $configuration = $this->contents(
            'src/Studio/Application/Authoring/StudioContextualAuthoringConfiguration.php',
        );
        $launch = $this->contents('src/Studio/Application/Authoring/ContentStudioAuthoringLaunch.php');
        $target = $this->contents('src/Studio/Application/Authoring/ContentStudioAuthoringTarget.php');

        self::assertStringNotContainsString('function toArray', $configuration);
        self::assertStringNotContainsString('json_encode', $configuration);
        self::assertStringNotContainsString('json_encode', $launch);
        self::assertStringContainsString("'configuration' => \$this->configuration", $launch);
        self::assertStringNotContainsString('Configuration', $target);
    }

    /**
     * Context authority remains an App-only persistence seam with no route, wire, or old-host coupling.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testContentContextAuthorityIsPersistedWithoutActivatingAnUnpublishedBrowserContract(): void
    {
        $container = $this->contents('src/Kernel/ContainerFactory.php');
        $authority = $this->contents(
            'src/Studio/Application/Authoring/ContentStudioAuthoringContextAuthority.php',
        );
        $handler = $this->contents(
            'src/Administrator/Http/Handler/AdministratorContentEditorHandler.php',
        );
        $hostAuthority = $this->contents('src/Studio/Application/Host/StudioHostSessionAuthority.php');
        $migration = $this->contents(
            'src/Infrastructure/Persistence/Migration/StudioContentAuthoringContextMigration.php',
        );
        $retention = $this->contents(
            'src/Studio/Infrastructure/Persistence/DoctrineContentStudioAuthoringContextPurger.php',
        );
        $retentionMigration = $this->contents(
            'src/Infrastructure/Persistence/Migration/StudioContentAuthoringContextRetentionMigration.php',
        );

        self::assertStringContainsString('ContentStudioAuthoringContextRepository::class', $container);
        self::assertStringContainsString('DoctrineContentStudioAuthoringContextRepository(', $container);
        self::assertStringContainsString('ContentStudioAuthoringContextAuthority::class', $container);
        self::assertStringContainsString('StudioContentAuthoringContextMigration(', $container);
        self::assertStringContainsString('ContentStudioAuthoringContextPurger::class', $container);
        self::assertStringContainsString('DoctrineContentStudioAuthoringContextPurger(', $container);
        self::assertStringContainsString('PurgeStudioContentAuthoringContextsHandler::class', $container);
        self::assertStringContainsString('StudioContentAuthoringContextRetentionMigration(', $container);
        self::assertStringContainsString('StudioResourceContextKeyFactory::class', $container);
        self::assertStringContainsString('ContentModelService::class', $container);
        self::assertStringContainsString('ContentService::class', $container);
        self::assertStringContainsString('ClockInterface::class', $container);
        self::assertStringContainsString('$configuration->administratorSessionSeconds', $container);
        self::assertStringContainsString("'expires_at'", $migration);
        self::assertStringContainsString('idx_studio_content_authoring_context_expiry', $migration);
        self::assertStringNotContainsString('StudioHostSessionAuthority', $authority);
        self::assertStringNotContainsString('StudioContextualAuthoringConfiguration', $authority);
        self::assertStringNotContainsString('ServerRequestInterface', $authority);
        self::assertStringNotContainsString('json_encode', $authority);
        self::assertStringNotContainsString('csrf', strtolower($authority));
        self::assertStringContainsString('ContentStudioAuthoringTargetMismatch', $authority);
        self::assertStringNotContainsString('| LogicException', $authority);
        self::assertStringNotContainsString('ContentStudioAuthoringContextAuthority', $handler);
        self::assertStringNotContainsString('ContentStudioAuthoringContextAuthority', $hostAuthority);
        self::assertStringContainsString('expires_at <= ?', $retention);
        self::assertStringContainsString('ORDER BY expires_at, context_key', $retention);
        self::assertStringNotContainsString('StudioHostSessionAuthority', $retention);
        self::assertStringNotContainsString('StudioContextualAuthoringConfiguration', $retention);
        self::assertStringNotContainsString('ServerRequestInterface', $retention);
        self::assertStringNotContainsString('json_encode', $retention);
        self::assertStringContainsString(
            'PurgeStudioContentAuthoringContextsHandler::JOB_TYPE',
            $retentionMigration,
        );
        self::assertStringContainsString("'job' => 'jobs'", $retentionMigration);
        self::assertStringContainsString("'schedule' => 'schedules'", $retentionMigration);
        self::assertStringContainsString("quoted('resource_site_ownership')", $retentionMigration);
        self::assertStringNotContainsString('PostgreSQLPlatform', $retentionMigration);
        self::assertStringContainsString("array_fill(0, count(\$resourceIds), '?')", $retentionMigration);
        self::assertStringNotContainsString('endpoint', strtolower($retentionMigration));
        self::assertStringNotContainsString('configuration', strtolower($retentionMigration));
    }

    /**
     * Read one repository file or fail with its relative path.
     *
     * @param   string  $path  Path below the application root.
     *
     * @return  string  Exact file contents.
     *
     * @since   2.0.0
     */
    private function contents(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($contents, $path);

        return $contents;
    }
}
