<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Presentation;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Audit\Infrastructure\Persistence\DoctrineAuditRecorder;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Extension\Runtime\ExtensionRuntimeMapCompiler;
use Kumwe\App\Extension\Runtime\RuntimeArtifactDigester;
use Kumwe\App\Extension\Runtime\RuntimeIdentity;
use Kumwe\App\Extension\Runtime\RuntimeMaterializationState;
use Kumwe\App\Extension\Runtime\RuntimePublicationKeyRing;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Infrastructure\Trust\FilesystemExtensionArtifactVerifier;
use Kumwe\App\Identity\Application\Administration\AuthenticationRateLimiter;
use Kumwe\App\Identity\Application\Administration\AccessTokenQuotaPolicy;
use Kumwe\App\Identity\Application\Administration\TokenDelegationPreauthorizer;
use Kumwe\App\Identity\Application\Administration\TokenRotationPreauthorizer;
use Kumwe\App\Identity\Application\Authorization\InsufficientCapability;
use Kumwe\App\Identity\Application\Security\PasswordHasher;
use Kumwe\App\Identity\Infrastructure\Administration\DoctrineAdministratorIdentityGateway;
use Kumwe\App\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\App\Infrastructure\Persistence\Migration\AuditTamperEvidenceMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\CoreSchemaMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\InstallationGlobalAutomationMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\ApplicationAuthorizationMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\BusinessDefinitionCatalogMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\BusinessSecurityPortalMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\BusinessTransactionalRuntimeMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\IsolateThemeSurfacesMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\TokenAndTrustLifecycleMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Presentation\Infrastructure\DoctrineThemeMutationAuthorizer;
use Kumwe\App\Extension\Domain\ThemeSurface;
use Kumwe\App\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Throwable;

#[CoversClass(IsolateThemeSurfacesMigration::class)]
#[CoversClass(ExtensionRuntimeMapCompiler::class)]
#[CoversClass(RuntimeArtifactDigester::class)]
#[CoversClass(RuntimeMaterializationState::class)]
final class ThemePersistenceIntegrationTest extends TestCase
{
    public function testRuntimeIdentityIsStableAndExplicit(): void
    {
        $first = new RuntimeIdentity('deployment-a', 'replica-a', 'worker-process', 'instance-a');
        $second = new RuntimeIdentity('deployment-a', 'replica-a', 'worker-process', 'instance-a');
        $scaled = new RuntimeIdentity('deployment-a', 'replica-a', 'worker-process', 'instance-b');

        self::assertSame($first->leaseId, $second->leaseId);
        self::assertNotSame($first->leaseId, $scaled->leaseId);
        self::assertSame('replica-a', $first->replicaId);
    }

    public function testRuntimeArtifactDigestRejectsSymlinkedExecutableBytes(): void
    {
        $directory = sys_get_temp_dir() . '/kumwe-artifact-symlink-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700, true));
        file_put_contents($directory . '/provider.php', '<?php');
        self::assertTrue(symlink($directory . '/provider.php', $directory . '/alias.php'));
        $this->expectException(\RuntimeException::class);

        try {
            (new RuntimeArtifactDigester())->digest($directory);
        } finally {
            unlink($directory . '/alias.php');
            unlink($directory . '/provider.php');
            rmdir($directory);
        }
    }

    public function testFreshInitialAdministratorReceivesBothThemeCapabilities(): void
    {
        [$database, $tables] = $this->schema();
        $gateway = $this->identityGateway($database, $tables);
        $userId = $gateway->createInitialAdministrator(
            AuthorizationContext::system(SystemIdentity::Bootstrap)->context(
                \Kumwe\Context\Value\SiteContext::default(),
                'theme-bootstrap-test',
            ),
            'admin@example.test',
            'Administrator',
            'password',
        );
        $capabilities = $database->fetchFirstColumn(sprintf(
            'SELECT g.capability_code FROM %s ur INNER JOIN %s g ON g.role_id = ur.role_id '
            . 'WHERE ur.user_id = ? ORDER BY g.capability_code',
            $tables->quoted('user_roles'),
            $tables->quoted('role_capability_grants'),
        ), [$userId]);

        self::assertContains('themes.site.manage', $capabilities);
        self::assertContains('themes.administrator.manage', $capabilities);
    }

    public function testThemeAuthorizationRechecksTheCurrentGrantSource(): void
    {
        [$database, $tables] = $this->schema();
        $gateway = $this->identityGateway($database, $tables);
        $userId = $gateway->createInitialAdministrator(
            AuthorizationContext::system(SystemIdentity::Bootstrap)->context(
                \Kumwe\Context\Value\SiteContext::default(),
                'theme-authority-bootstrap-test',
            ),
            'authority@example.test',
            'Authority',
            'password',
        );
        $context = AuthorizationContext::human(['themes.site.manage'], $userId);
        $authorization = new DoctrineThemeMutationAuthorizer(
            $database,
            $tables,
            AuthorizationContext::gateway(),
        );
        $authorization->assertSurface($context, ThemeSurface::Site);
        $database->delete($tables->raw('role_capability_grants'), [
            'capability_code' => 'themes.site.manage',
        ]);
        $this->expectException(InsufficientCapability::class);

        $authorization->assertSurface($context, ThemeSurface::Site);
    }

    public function testLegacyActiveTemplateBecomesExplicitSiteTheme(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tables = new TableNames($database, 'kumwe_');
        (new CoreSchemaMigration($tables))->up($database);
        $database->insert($tables->raw('extensions'), [
            'id' => '00000000-0000-7000-8000-000000000901',
            'identifier' => 'legacy/site',
            'extension_type' => 'template',
            'installed_version' => '1.0.0',
            'status' => 'active',
            'service_provider' => 'Legacy\\Site\\Provider',
            'runtime_path' => 'legacy/site/1.0.0',
            'registry_version' => 1,
            'installed_at' => '2026-08-05 00:00:00',
            'updated_at' => '2026-08-05 00:00:00',
        ]);
        (new IsolateThemeSurfacesMigration($tables))->up($database);

        self::assertSame(
            '00000000-0000-7000-8000-000000000901',
            $database->fetchOne(sprintf(
                'SELECT extension_id FROM %s WHERE site_identifier = ?',
                $tables->quoted('site_theme_activations'),
            ), ['default']),
        );
        self::assertNull($database->fetchOne(sprintf(
            "SELECT extension_id FROM %s WHERE surface = 'administrator'",
            $tables->quoted('theme_activations'),
        )));
    }

    public function testForwardMigrationResumesWithoutDuplicatingSchemaOrSeedState(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tables = new TableNames($database, 'kumwe_');
        (new CoreSchemaMigration($tables))->up($database);
        $migration = new IsolateThemeSurfacesMigration($tables);
        $migration->up($database);
        $migration->up($database);

        self::assertTrue(
            $database->createSchemaManager()->introspectTable($tables->raw('extension_migrations'))
                ->hasColumn('migration_sha256'),
        );

        self::assertSame(2, (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s',
            $tables->quoted('theme_activations'),
        )));
        self::assertSame(1, (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE site_identifier = ?',
            $tables->quoted('site_theme_activations'),
        ), ['default']));
        self::assertSame(2, (int) $database->fetchOne(sprintf(
            "SELECT COUNT(*) FROM %s WHERE code LIKE 'themes.%%.manage'",
            $tables->quoted('capabilities'),
        )));
    }

    public function testFailedAuthoritativeGenerationLeavesPreviousMaterializationUntouched(): void
    {
        [$database, $tables] = $this->schema();
        $initialGeneration = (int) $database->fetchOne(sprintf(
            'SELECT generation FROM %s WHERE singleton_key = 1',
            $tables->quoted('extension_runtime_generation'),
        ));
        $directory = sys_get_temp_dir() . '/kumwe-map-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700, true));
        $map = $directory . '/extensions.json';
        file_put_contents($map, '{"generation":0,"extensions":[]}');
        $database->executeStatement(sprintf(
            'CREATE TRIGGER fail_generation BEFORE UPDATE ON %s BEGIN SELECT RAISE(FAIL, \'generation fail\'); END',
            $tables->quoted('extension_runtime_generation'),
        ));

        try {
            (new ExtensionRuntimeMapCompiler(
                $database,
                $tables,
                $map,
                $directory . '/extensions',
                $directory . '/assets',
                new IntegrationClock(),
                self::identity('replica-generation-failure'),
                self::keys(),
                new RuntimeArtifactDigester(),
            ))->rebuild();
            self::fail('Generation failure was not propagated.');
        } catch (Throwable) {
            self::assertSame('{"generation":0,"extensions":[]}', file_get_contents($map));
            self::assertSame($initialGeneration, (int) $database->fetchOne(sprintf(
                'SELECT generation FROM %s WHERE singleton_key = 1',
                $tables->quoted('extension_runtime_generation'),
            )));
            self::assertSame(0, (int) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s',
                $tables->quoted('extension_runtime_publications'),
            )));
        } finally {
            unlink($map);
            rmdir($directory);
        }
    }

    public function testEveryReplicaMustMaterializeTheAuthoritativeGeneration(): void
    {
        [$database, $tables] = $this->schema();
        $directory = sys_get_temp_dir() . '/kumwe-replicas-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory . '/extensions', 0700, true));
        self::assertTrue(mkdir($directory . '/assets', 0700, true));
        $first = new ExtensionRuntimeMapCompiler(
            $database,
            $tables,
            $directory . '/replica-a.json',
            $directory . '/extensions',
            $directory . '/assets',
            new IntegrationClock(),
            self::identity('replica-a'),
            self::keys(),
            new RuntimeArtifactDigester(),
        );
        $second = new ExtensionRuntimeMapCompiler(
            $database,
            $tables,
            $directory . '/replica-b.json',
            $directory . '/extensions',
            $directory . '/assets',
            new IntegrationClock(),
            self::identity('replica-b'),
            self::keys(),
            new RuntimeArtifactDigester(),
        );

        try {
            $firstState = $first->reconcileAndMaterialize();
            self::assertTrue($first->isCurrent($firstState));
            $database->transactional(static fn (): int => $first->stage('test.registry-mutation'));
            $secondState = $second->reconcileAndMaterialize();

            self::assertFalse($first->isCurrent($firstState));
            self::assertTrue($second->isCurrent($secondState));
            self::assertSame($firstState->generation + 1, $secondState->generation);
        } finally {
            foreach (['replica-a.json', 'replica-b.json'] as $file) {
                if (is_file($directory . '/' . $file)) {
                    unlink($directory . '/' . $file);
                }
                if (is_file($directory . '/' . $file . '.lock')) {
                    unlink($directory . '/' . $file . '.lock');
                }
                if (is_file($directory . '/' . $file . '.verified')) {
                    unlink($directory . '/' . $file . '.verified');
                }
                if (is_file($directory . '/' . $file . '.ready')) {
                    unlink($directory . '/' . $file . '.ready');
                }
            }
            rmdir($directory . '/assets');
            rmdir($directory . '/extensions');
            rmdir($directory);
        }
    }

    public function testLiveStaleGenerationLeaseProtectsRetiredRuntimeUntilProcessDrains(): void
    {
        [$database, $tables] = $this->schema();
        $directory = sys_get_temp_dir() . '/kumwe-runtime-lease-' . bin2hex(random_bytes(8));
        $runtime = $directory . '/extensions/acme/plugin/1.0.0';
        $assets = $directory . '/assets/acme/plugin/1.0.0';
        self::assertTrue(mkdir($runtime, 0700, true));
        self::assertTrue(mkdir($assets, 0700, true));
        file_put_contents($runtime . '/provider.php', '<?php');
        file_put_contents($assets . '/plugin.css', 'body{}');
        $extensionId = '00000000-0000-7000-8000-000000000911';
        $database->insert($tables->raw('extensions'), [
            'id' => $extensionId,
            'identifier' => 'acme/plugin',
            'extension_type' => 'plugin',
            'installed_version' => '1.0.0',
            'status' => 'active',
            'service_provider' => 'Acme\\Plugin\\Provider',
            'registry_version' => 1,
            'runtime_path' => 'acme/plugin/1.0.0',
            'installed_at' => '2026-08-05 12:00:00',
            'updated_at' => '2026-08-05 12:00:00',
        ]);
        $database->insert($tables->raw('extension_releases'), [
            'id' => '00000000-0000-7000-8000-000000000912',
            'extension_id' => $extensionId,
            'version' => '1.0.0',
            'manifest' => json_encode([
                'schema' => 1,
                'name' => 'acme/plugin',
                'type' => 'plugin',
                'version' => '1.0.0',
                'provider' => 'Acme\\Plugin\\Provider',
                'autoload' => ['psr-4' => ['Acme\\Plugin\\' => 'src/']],
                'requires' => ['kumwe' => '^2.0.0', 'php' => '^8.5.0'],
            ], JSON_THROW_ON_ERROR),
            'package_sha256' => str_repeat('0', 64),
            'artifact_sha256' => str_repeat('0', 64),
            'deployed_tree_sha256' => FilesystemExtensionArtifactVerifier::treeDigest($runtime),
            'trust_state' => 'verified',
            'signature_algorithm' => null,
            'signing_key_id' => null,
            'signature_base64' => null,
            'released_at' => '2026-08-05 12:00:00',
            'installed_at' => '2026-08-05 12:00:00',
        ]);
        $clock = new MutableIntegrationClock();
        $old = new ExtensionRuntimeMapCompiler(
            $database,
            $tables,
            $directory . '/old.json',
            $directory . '/extensions',
            $directory . '/assets',
            $clock,
            self::identity('replica-old-generation'),
            self::keys(),
            new RuntimeArtifactDigester(),
            1,
            300,
        );
        $new = new ExtensionRuntimeMapCompiler(
            $database,
            $tables,
            $directory . '/new.json',
            $directory . '/extensions',
            $directory . '/assets',
            $clock,
            self::identity('replica-new-generation'),
            self::keys(),
            new RuntimeArtifactDigester(),
            1,
            300,
        );

        try {
            $oldState = $old->reconcileAndMaterialize();
            self::assertTrue($old->isCurrent($oldState));
            $database->transactional(function () use ($database, $tables, $old): void {
                $database->delete($tables->raw('extensions'), ['identifier' => 'acme/plugin']);
                $generation = $old->stage('test.retire-runtime');
                $old->scheduleRetirement('acme/plugin/1.0.0', $generation);
            });
            self::assertFalse($old->isCurrent($oldState));
            $clock->advance(2);
            $new->materializeLatest(true);
            self::assertDirectoryExists($runtime);

            $database->update($tables->raw('extension_runtime_retirements'), [
                'claim_token' => str_repeat('a', 64),
                'claim_until' => $clock->now()->add(new DateInterval('PT10S')),
            ], ['runtime_path' => 'acme/plugin/1.0.0'], ['claim_until' => Types::DATETIME_IMMUTABLE]);
            $clock->advance(5);
            $new->materializeLatest(true);
            self::assertDirectoryExists($runtime);

            $clock->advance(296);
            $new->materializeLatest(true);
            self::assertDirectoryDoesNotExist($runtime);
            self::assertDirectoryDoesNotExist($assets);

            self::assertTrue(mkdir($runtime, 0700, true));
            self::assertTrue(mkdir($assets, 0700, true));
            $new->materializeLatest(true);
            self::assertDirectoryDoesNotExist($runtime);
            self::assertDirectoryDoesNotExist($assets);
        } finally {
            $this->removeRuntimeTestTree($directory);
        }
    }

    public function testTamperedLocalMaterializationIsNeverReady(): void
    {
        [$database, $tables] = $this->schema();
        $directory = sys_get_temp_dir() . '/kumwe-trust-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory . '/extensions', 0700, true));
        self::assertTrue(mkdir($directory . '/assets', 0700, true));
        $map = $directory . '/extensions.json';
        $compiler = new ExtensionRuntimeMapCompiler(
            $database,
            $tables,
            $map,
            $directory . '/extensions',
            $directory . '/assets',
            new IntegrationClock(),
            self::identity('replica-tamper'),
            self::keys(),
            new RuntimeArtifactDigester(),
        );

        try {
            $state = $compiler->reconcileAndMaterialize();
            $payload = file_get_contents($map);
            self::assertIsString($payload);
            file_put_contents($map, str_replace('runtime.reconcile', 'runtime.corrupted', $payload));

            self::assertFalse($compiler->isCurrent($state));
        } finally {
            foreach ([$map, $map . '.lock', $map . '.verified', $map . '.ready'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory . '/assets');
            rmdir($directory . '/extensions');
            rmdir($directory);
        }
    }

    public function testMaterializerNeverReplacesAValidNewerLocalGeneration(): void
    {
        [$database, $tables] = $this->schema();
        $directory = sys_get_temp_dir() . '/kumwe-monotonic-map-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory . '/extensions', 0700, true));
        self::assertTrue(mkdir($directory . '/assets', 0700, true));
        $map = $directory . '/extensions.json';
        $compiler = new ExtensionRuntimeMapCompiler(
            $database,
            $tables,
            $map,
            $directory . '/extensions',
            $directory . '/assets',
            new IntegrationClock(),
            self::identity('replica-monotonic'),
            self::keys(),
            new RuntimeArtifactDigester(),
        );

        try {
            $initialState = $compiler->reconcileAndMaterialize();
            $database->transactional(static fn (): int => $compiler->stage('test.generation-two'));
            self::assertSame($initialState->generation + 1, $compiler->materializeLatest()->generation);
            $database->executeStatement(sprintf(
                'UPDATE %s SET generation = ? WHERE singleton_key = 1',
                $tables->quoted('extension_runtime_generation'),
            ), [$initialState->generation]);
            $this->expectException(\RuntimeException::class);

            $compiler->materializeLatest();
        } finally {
            if (is_file($map)) {
                unlink($map);
            }
            if (is_file($map . '.lock')) {
                unlink($map . '.lock');
            }
            if (is_file($map . '.verified')) {
                unlink($map . '.verified');
            }
            if (is_file($map . '.ready')) {
                unlink($map . '.ready');
            }
            rmdir($directory . '/assets');
            rmdir($directory . '/extensions');
            rmdir($directory);
        }
    }

    public function testReconciliationRetriesMissingLocalArtifactWithoutGenerationBump(): void
    {
        [$database, $tables] = $this->schema();
        $directory = sys_get_temp_dir() . '/kumwe-reconcile-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory . '/extensions', 0700, true));
        self::assertTrue(mkdir($directory . '/assets', 0700, true));
        $map = $directory . '/extensions.json';
        $compiler = new ExtensionRuntimeMapCompiler(
            $database,
            $tables,
            $map,
            $directory . '/extensions',
            $directory . '/assets',
            new IntegrationClock(),
            self::identity('replica-reconcile'),
            self::keys(),
            new RuntimeArtifactDigester(),
        );

        try {
            $first = $compiler->reconcileAndMaterialize();
            unlink($map);
            $second = $compiler->reconcileAndMaterialize();

            self::assertFileExists($map);
            self::assertSame($first->generation, $second->generation);
            self::assertTrue($compiler->isCurrent($second));
        } finally {
            foreach ([$map, $map . '.lock', $map . '.verified', $map . '.ready'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory . '/assets');
            rmdir($directory . '/extensions');
            rmdir($directory);
        }
    }

    public function testSigningKeyRotationRequiresExplicitPreviousKeyOverlap(): void
    {
        [$database, $tables] = $this->schema();
        $directory = sys_get_temp_dir() . '/kumwe-key-rotation-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory . '/extensions', 0700, true));
        self::assertTrue(mkdir($directory . '/assets', 0700, true));
        $map = $directory . '/extensions.json';
        $identity = self::identity('replica-key-rotation');
        $oldKey = str_repeat('old-runtime-key-', 3);
        $newKey = str_repeat('new-runtime-key-', 3);
        $old = new ExtensionRuntimeMapCompiler(
            $database,
            $tables,
            $map,
            $directory . '/extensions',
            $directory . '/assets',
            new IntegrationClock(),
            $identity,
            new RuntimePublicationKeyRing('runtime-v1', $oldKey),
            new RuntimeArtifactDigester(),
        );
        $first = $old->reconcileAndMaterialize();
        $withoutOverlap = new ExtensionRuntimeMapCompiler(
            $database,
            $tables,
            $map,
            $directory . '/extensions',
            $directory . '/assets',
            new IntegrationClock(),
            $identity,
            new RuntimePublicationKeyRing('runtime-v2', $newKey),
            new RuntimeArtifactDigester(),
        );
        self::assertFalse($withoutOverlap->inspectLocal()->trusted);
        try {
            $withoutOverlap->reconcileAndMaterialize();
            self::fail('Signature drift was silently restaged.');
        } catch (\RuntimeException) {
            self::assertSame($first->generation, (int) $database->fetchOne(sprintf(
                'SELECT generation FROM %s WHERE singleton_key = 1',
                $tables->quoted('extension_runtime_generation'),
            )));
        }
        $rotated = new ExtensionRuntimeMapCompiler(
            $database,
            $tables,
            $map,
            $directory . '/extensions',
            $directory . '/assets',
            new IntegrationClock(),
            $identity,
            new RuntimePublicationKeyRing('runtime-v2', $newKey, ['runtime-v1' => $oldKey]),
            new RuntimeArtifactDigester(),
        );

        try {
            self::assertTrue($rotated->inspectLocal()->trusted);
            $rotatedState = $rotated->reconcileAndMaterialize();
            self::assertSame($first->generation + 1, $rotatedState->generation);
            $newKeyOnly = new ExtensionRuntimeMapCompiler(
                $database,
                $tables,
                $map,
                $directory . '/extensions',
                $directory . '/assets',
                new IntegrationClock(),
                $identity,
                new RuntimePublicationKeyRing('runtime-v2', $newKey),
                new RuntimeArtifactDigester(),
            );
            self::assertTrue($newKeyOnly->inspectLocal()->trusted);
            self::assertSame($rotatedState->generation, $newKeyOnly->reconcileAndMaterialize()->generation);
        } finally {
            unlink($map);
            unlink($map . '.lock');
            unlink($map . '.verified');
            unlink($map . '.ready');
            rmdir($directory . '/assets');
            rmdir($directory . '/extensions');
            rmdir($directory);
        }
    }

    /** @return array{Connection, TableNames} */
    private function schema(): array
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tables = new TableNames($database, 'kumwe_');
        (new CoreSchemaMigration($tables))->up($database);
        (new ApplicationAuthorizationMigration($tables))->up($database);
        (new TokenAndTrustLifecycleMigration($tables))->up($database);
        (new IsolateThemeSurfacesMigration($tables))->up($database);
        (new BusinessDefinitionCatalogMigration($tables))->up($database);
        (new BusinessTransactionalRuntimeMigration($tables))->up($database);
        (new BusinessSecurityPortalMigration($tables))->up($database);
        (new InstallationGlobalAutomationMigration($tables))->up($database);
        (new AuditTamperEvidenceMigration($tables))->up($database);

        return [$database, $tables];
    }

    private function identityGateway(
        Connection $database,
        TableNames $tables,
    ): DoctrineAdministratorIdentityGateway {
        return new DoctrineAdministratorIdentityGateway(
            $database,
            $tables,
            new IntegrationPasswordHasher(),
            new DoctrineTransactionManager($database),
            new IntegrationClock(),
            new IntegrationRateLimiter(),
            new DoctrineAuditRecorder($database, $tables),
            $this->createStub(AccessTokenQuotaPolicy::class),
            str_repeat('s', 32),
            AuthorizationContext::gateway(),
            (new ExtensionContributionRegistrySet())->authorizationPolicies(),
            (new \ReflectionClass(TokenDelegationPreauthorizer::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(TokenRotationPreauthorizer::class))->newInstanceWithoutConstructor(),
            AuthorizationContext::ownershipWriter(),
            AuthorizationContext::provenance(),
        );
    }

    private static function identity(string $replica): RuntimeIdentity
    {
        return new RuntimeIdentity('testing-deployment', $replica, 'test-process');
    }

    private static function keys(): RuntimePublicationKeyRing
    {
        return new RuntimePublicationKeyRing('runtime-v1', str_repeat('runtime-secret', 4));
    }

    private function removeRuntimeTestTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}

final readonly class IntegrationPasswordHasher implements PasswordHasher
{
    public function hash(#[\SensitiveParameter] string $plainTextPassword): string
    {
        return 'hash:' . $plainTextPassword;
    }

    public function verify(#[\SensitiveParameter] string $plainTextPassword, string $passwordHash): bool
    {
        return $passwordHash === $this->hash($plainTextPassword);
    }

    public function needsRehash(string $passwordHash): bool
    {
        return false;
    }
}

final readonly class IntegrationRateLimiter implements AuthenticationRateLimiter
{
    public function assertAllowed(string $subjectDigest, string $sourceDigest): void
    {
    }

    public function record(string $subjectDigest, string $sourceDigest, bool $succeeded): void
    {
    }
}

final readonly class IntegrationClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-05T12:00:00+00:00');
    }
}

final class MutableIntegrationClock implements ClockInterface
{
    private DateTimeImmutable $time;

    public function __construct()
    {
        $this->time = new DateTimeImmutable('2026-08-05T12:00:00+00:00');
    }

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }

    public function advance(int $seconds): void
    {
        $this->time = $this->time->add(new DateInterval('PT' . $seconds . 'S'));
    }
}
