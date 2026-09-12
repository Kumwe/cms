<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use Kumwe\App\Infrastructure\Persistence\Migration\BusinessTransactionalRuntimeMigration;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ProductionArtifactsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testRuntimeImageIsNonRootAndWebImageReceivesOnlyPublicFiles(): void
    {
        $dockerfile = $this->contents('docker/php/Dockerfile');

        self::assertStringContainsString('FROM php-base AS runtime', $dockerfile);
        self::assertStringContainsString('pdo_mysql pdo_pgsql', $dockerfile);
        self::assertStringContainsString('pecl install redis-6.3.0', $dockerfile);
        self::assertStringContainsString('apcu-5.1.28', $dockerfile);
        self::assertStringContainsString('mbstring pcntl pdo_mysql', $dockerfile);
        self::assertStringContainsString('USER www-data', $dockerfile);
        self::assertStringContainsString(
            'COPY --from=vendor --chown=nginx:nginx /var/www/kumwe/public /var/www/kumwe/public',
            $dockerfile,
        );
        self::assertStringNotContainsString('COPY --from=vendor /var/www/kumwe /var/www/kumwe/public', $dockerfile);
    }

    public function testOnlyTheDedicatedPublicDirectoryContainsWebEntrypoints(): void
    {
        foreach (['index.php', '.htaccess', 'robots.txt.dist', 'web.config.txt'] as $legacyRootFile) {
            self::assertFileDoesNotExist(
                $this->root . '/' . $legacyRootFile,
                sprintf('Legacy web-root artifact %s must not be shipped.', $legacyRootFile),
            );
        }

        self::assertFileExists($this->root . '/public/index.php');
        self::assertFileDoesNotExist($this->root . '/public/robots.txt');

        $nginx = $this->contents('docker/nginx/default.conf');
        self::assertStringNotContainsString('location = /robots.txt', $nginx);
        self::assertStringContainsString('try_files $uri $uri/ /index.php?$query_string;', $nginx);

        $container = $this->contents('src/Kernel/ContainerFactory.php');
        self::assertStringContainsString("get('/robots.txt', RobotsHandler::class", $container);
    }

    public function testProductionTopologyKeepsDataServicesInternalAndSecretsFileBacked(): void
    {
        $compose = $this->contents('compose.production.yaml');

        foreach (['web:', 'app:', 'worker:', 'scheduler:', 'migrate:', 'database:', 'redis:'] as $service) {
            self::assertStringContainsString($service, $compose);
        }

        self::assertStringContainsString('internal: true', $compose);
        self::assertStringContainsString('ghcr.io/kumwe/app/app:latest', $compose);
        self::assertStringContainsString('ghcr.io/kumwe/app/web:latest', $compose);
        self::assertStringContainsString('KUMWE_DATABASE_IMAGE:-mariadb:lts', $compose);
        self::assertStringContainsString('KUMWE_REDIS_IMAGE:-redis:8-alpine', $compose);
        self::assertStringContainsString('APP_SECRET_FILE: /run/secrets/app_secret', $compose);
        self::assertStringContainsString(
            'EXTENSION_RUNTIME_SIGNING_KEY_FILE: /run/secrets/runtime_signing_key',
            $compose,
        );
        self::assertStringContainsString('DB_PASSWORD_FILE: /run/secrets/db_password', $compose);
        self::assertStringContainsString(
            'extension-assets-data:/var/www/kumwe/public/assets/extensions',
            $compose,
        );
        self::assertStringContainsString('private-data:/var/www/kumwe/storage/private', $compose);
        self::assertStringContainsString('profiles: [automation]', $compose);
    }

    public function testReleaseAndSecurityActionsAreCommitPinned(): void
    {
        foreach (['.github/workflows/security.yml', '.github/workflows/release.yml'] as $workflow) {
            $contents = $this->contents($workflow);
            self::assertDoesNotMatchRegularExpression(
                '/uses:\s+[^\s@]+@(?![a-f0-9]{40}(?:\s|$))[^\s]+/i',
                $contents,
                sprintf('Workflow actions must be pinned in %s.', $workflow),
            );
        }
    }

    public function testBackupToolsAreFailClosedAndRefuseNonV2Data(): void
    {
        $backup = $this->contents('tools/backup.sh');
        $restore = $this->contents('tools/restore.sh');
        $verify = $this->contents('tools/restore-verify.sh');

        self::assertStringContainsString('set -Eeuo pipefail', $backup);
        self::assertStringContainsString('KUMWE_BACKUP_CONSISTENCY', $backup);
        self::assertStringContainsString(BusinessTransactionalRuntimeMigration::ID, $backup);
        self::assertStringContainsString(BusinessTransactionalRuntimeMigration::ID, $restore);
        self::assertStringContainsString('mariadb|mysql|pgsql', $backup);
        self::assertStringContainsString('--no-tablespaces', $backup);
        self::assertStringContainsString('--set-gtid-purged=OFF', $backup);
        self::assertStringNotContainsString('--routines', $backup);
        self::assertStringNotContainsString('--events', $backup);
        self::assertStringContainsString('${#table_prefix} -le 28', $backup);
        self::assertStringContainsString('^[a-z][a-z0-9]*(_[a-z0-9]+)*_$', $backup);
        self::assertStringContainsString('${#table_prefix} -le 28', $restore);
        self::assertStringContainsString('database_table_prefix | length <= 28', $verify);
        self::assertStringContainsString('product_major: 2', $backup);
        self::assertStringContainsString('extension-assets.tar.gz', $backup);
        self::assertStringContainsString('KUMWE_PRIVATE_DIR', $backup);
        self::assertStringContainsString('private.tar.gz', $backup);
        self::assertStringContainsString('KUMWE_RESTORE_PRIVATE_DIR', $restore);
        self::assertStringContainsString('private.tar.gz', $restore);
        self::assertStringContainsString('private.tar.gz', $verify);
        self::assertStringContainsString('set -Eeuo pipefail', $verify);
        self::assertStringContainsString('Kumwe 1.x and unknown formats are refused', $verify);
    }

    /**
     * Require CI to execute the signed backup path and every fail-closed refusal the tooling claims.
     *
     * The signing and signature-verification branches are conditional on a key being configured, and
     * the refusals are conditional on something being wrong, so both are dead code in a drill that
     * only ever hands the tooling a good, unsigned backup. This asserts the drill supplies a keypair
     * and runs the tamper cases, and that the tamper drill still covers each named refusal — a case
     * quietly deleted from it would otherwise reduce the gate without failing anything.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCiExercisesTheSignedBackupPathAndEveryFailClosedRefusal(): void
    {
        $ci = $this->contents('.github/workflows/ci.yml');
        $drill = $this->contents('tests/Support/backup-tamper-drill.sh');

        self::assertStringContainsString('minisign -G -f -W', $ci);
        self::assertStringContainsString('KUMWE_BACKUP_SIGNING_SECRET_KEY_FILE="$signing_root', $ci);
        self::assertStringContainsString('KUMWE_BACKUP_SIGNING_PUBLIC_KEY_FILE="$signing_root', $ci);
        self::assertStringContainsString('test -f "$backup_path/checksums.sha256.minisig"', $ci);
        self::assertStringContainsString('export KUMWE_EXPECTED_RELEASE=2.0.0', $ci);
        self::assertStringContainsString('bash tests/Support/backup-tamper-drill.sh "$backup_path"', $ci);
        self::assertStringContainsString('KUMWE_TAMPER_DRILL_OCCUPIED_DB=kumwe_restore_test', $ci);
        self::assertStringContainsString('KUMWE_DRILL_BACKUP_MANIFEST_CHECKSUM=', $ci);
        self::assertStringContainsString('KUMWE_DRILL_RESTORE_SECONDS=', $ci);

        self::assertStringContainsString('set -Eeuo pipefail', $drill);
        foreach (
            [
                'corrupted-database-dump',
                'corrupted-media-archive',
                'edited-manifest-without-reseal',
                'missing-payload',
                'narrowed-checksum-manifest',
                'old-manifest-version',
                'traversal-archive',
                'symlink-archive',
                'symlink-in-backup-directory',
                'unexpected-release',
                'resealed-after-tamper',
                'missing-signature',
                'signed-without-public-key',
                'driver-mismatch',
                'existing-filesystem-target',
                'non-empty-target-database',
            ] as $case
        ) {
            self::assertStringContainsString($case, $drill, sprintf('The %s tamper case must survive.', $case));
        }
    }

    /**
     * Require the restore drill to prove recoverability by using restored data, not by hashing it.
     *
     * A restore booted with the wrong application secret reproduces every digest the acceptance
     * manifest compares, so the proofs that separate "the bytes came back" from "the system works"
     * are the ones that decrypt, authenticate, elevate and execute. Each is asserted here by the
     * production collaborator it must go through, because a drill that stopped calling one of them
     * would still pass its own comparison.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRestoreDrillProvesRecoverabilityByExecutingRatherThanComparing(): void
    {
        $acceptance = $this->contents('tests/Support/BusinessRuntimeBackupAcceptance.php');
        $security = $this->contents('tests/Support/RestoreSecurityAcceptance.php');
        $work = $this->contents('tests/Support/RestoredWork.php');

        self::assertStringContainsString('EnvelopeCipher::class', $acceptance);
        self::assertStringContainsString('$cipher->decrypt(', $acceptance);
        self::assertStringContainsString('SecretAssociatedData::for(', $acceptance);
        self::assertStringContainsString('recovered_plaintext_digest', $acceptance);
        self::assertStringContainsString('SchemaRecoveryEvidence(', $acceptance);
        self::assertStringContainsString('backup_quiesce_seconds', $acceptance);
        self::assertStringContainsString('restore_seconds', $acceptance);

        self::assertStringContainsString('AdministratorIdentityGateway', $security);
        self::assertStringContainsString('StepUpSecretCipher', $security);
        self::assertStringContainsString('->decrypt(', $security);
        self::assertStringContainsString('catch (StepUpRejected)', $security);
        self::assertStringContainsString('accepted a replayed TOTP code', $security);
        self::assertStringContainsString('accepted a spent recovery code', $security);
        self::assertStringContainsString('accepted a session that had expired', $security);
        self::assertStringContainsString('let a read-only operator write a record', $security);

        self::assertStringContainsString("'extension:runtime:materialize'", $work);
        self::assertStringContainsString("'schedule:run'", $work);
        self::assertStringContainsString("'queue:work', '--once'", $work);
        self::assertStringContainsString('/bin/kumwe', $work);
    }

    /**
     * Require the deployment drills to resolve their own classes inside the production image.
     *
     * The image installs with `--no-dev` and an authoritative classmap, so no class under
     * `Kumwe\App\Tests\` is autoloadable there even though the drill's directory is mounted into it.
     * The entry points once compensated with a list of `require` lines naming each collaborator, and
     * a drill that gained a class without gaining a line still passed every cheaper job — those run
     * under the dev autoloader — before dying in the deployed image with "class not found". Holding
     * the entry points to the shared loader, and refusing a list that grows back, keeps the failure
     * mode retired rather than merely repaired.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDeploymentDrillsResolveTheirClassesWithoutTheDevelopmentAutoloader(): void
    {
        $loader = $this->contents('tests/Support/deployment-drill-autoload.php');

        self::assertStringContainsString("require dirname(__DIR__, 2) . '/vendor/autoload.php';", $loader);
        self::assertStringContainsString('spl_autoload_register(', $loader);
        self::assertStringContainsString('\'Kumwe\\\\App\\\\Tests\\\\\'', $loader);

        self::assertStringContainsString(
            '/tests/Support:/var/www/kumwe/tests/Support:ro',
            $this->contents('.github/workflows/deployment-acceptance.yml'),
        );

        foreach (
            [
                'tests/Support/business-runtime-backup-acceptance.php',
                'tests/Support/asset-inspection-deployment-acceptance.php',
            ] as $entryPoint
        ) {
            $contents = $this->contents($entryPoint);
            self::assertStringContainsString(
                "require __DIR__ . '/deployment-drill-autoload.php';",
                $contents,
                sprintf('%s must reach its classes through the shared drill loader.', $entryPoint),
            );
            self::assertDoesNotMatchRegularExpression(
                '#require __DIR__ \. \'/[A-Z][A-Za-z]*\.php\';#',
                $contents,
                sprintf('%s must not name collaborators by hand; the loader resolves them.', $entryPoint),
            );
        }
    }

    /**
     * Proves CI deploys every supported database and both released distribution formats.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCiDeploysEverySupportedDatabaseAndComposerDistribution(): void
    {
        $ci = $this->contents('.github/workflows/ci.yml');
        $acceptance = $this->contents('.github/workflows/deployment-acceptance.yml');

        foreach (['mariadb:lts', 'mysql:8.4', 'postgres:17-alpine'] as $databaseImage) {
            self::assertStringContainsString($databaseImage, $ci);
            self::assertStringContainsString($databaseImage, $acceptance);
        }

        self::assertStringContainsString("php-version: '8.5'", $ci);
        self::assertStringContainsString('php bin/kumwe database:migrate', $acceptance);
        self::assertStringContainsString('php bin/kumwe user:create-admin', $acceptance);
        self::assertStringContainsString('Composer and ZIP installation', $acceptance);
        self::assertStringContainsString(
            'CREATE DATABASE kumwe_distribution_zip CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
            $acceptance,
        );
        self::assertStringContainsString("COUNT(collation_name), ':', COUNT(DISTINCT collation_name)", $acceptance);
        self::assertStringContainsString("test \"\$zip_collation_contract\" = '3:3:1'", $acceptance);
        self::assertStringContainsString("table_name = 'kumwe_content_entries'", $acceptance);
        self::assertStringContainsString("table_name = 'kumwe_workflow_definition_versions'", $acceptance);
        self::assertStringContainsString('bash tools/deployment-probe.sh', $acceptance);
        self::assertStringContainsString('Restore a production backup into a clean database', $acceptance);

        $probe = $this->contents('tools/deployment-probe.sh');
        self::assertStringContainsString('user without administrator.access', $probe);
        self::assertStringContainsString('Idempotency-Replayed: true', $probe);
        self::assertStringContainsString('kumwe_content_list', $probe);
        self::assertStringContainsString('kumwe_content_create', $probe);
    }

    /**
     * Require the production database matrix to execute the signed proof package and clean restore.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDeploymentAcceptanceExercisesTheCompleteSignedExtensionLifecycle(): void
    {
        $acceptance = $this->contents('.github/workflows/deployment-acceptance.yml');
        $driver = $this->contents('tools/asset-inspection-deployment-acceptance.sh');
        $support = $this->contents('tests/Support/AssetInspectionDeploymentAcceptance.php');

        self::assertStringContainsString('tools/asset-inspection-deployment-acceptance.sh package', $acceptance);
        self::assertStringContainsString('tools/asset-inspection-deployment-acceptance.sh grant', $acceptance);
        self::assertStringContainsString('tools/asset-inspection-deployment-acceptance.sh exercise', $acceptance);
        self::assertStringContainsString('asset-inspection-deployment-acceptance.php', $acceptance);
        self::assertStringContainsString('extension:runtime:materialize', $acceptance);
        self::assertStringContainsString('KUMWE_ACCEPTANCE_ASSET_MANIFEST', $acceptance);
        self::assertStringContainsString('KUMWE_ACCEPTANCE_ASSET_STATE', $acceptance);
        self::assertStringContainsString('$source_private/report-exports/objects', $acceptance);
        self::assertStringContainsString('app web worker scheduler', $acceptance);
        self::assertStringContainsString(
            'exec -T app /usr/local/bin/kumwe-entrypoint sh -euc',
            str_replace("\\\n            ", '', $acceptance),
        );
        self::assertStringNotContainsString('exec -T app sh -euc', $acceptance);
        self::assertStringContainsString('stat -c %u "$media_fixture"', $acceptance);
        self::assertStringContainsString('stat -c %a "$media_fixture"', $acceptance);

        foreach (['extension:build', 'extension:inspect', 'extension:conformance', 'extension:sign'] as $command) {
            self::assertStringContainsString($command, $driver);
        }
        foreach (['extension:install', 'extension:activate', 'extension:disable'] as $command) {
            self::assertStringContainsString($command, $driver);
        }
        foreach (['business-record create', 'business-record relate', 'integration:work --once'] as $command) {
            self::assertStringContainsString($command, $driver);
        }
        self::assertStringContainsString('Idempotency-Replayed', $driver);
        self::assertStringContainsString("del(.replayed)", $driver);
        self::assertStringContainsString('ordered_record_ids', $driver);
        self::assertStringContainsString('/order', $driver);
        $schemaLoopStart = strpos($driver, 'for definition in');
        if ($schemaLoopStart === false) {
            self::fail('The deployment schema-install loop is unavailable.');
        }
        $schemaLoopEnd = strpos($driver, 'install_schema "$definition"', $schemaLoopStart);
        if ($schemaLoopEnd === false) {
            self::fail('The deployment schema-install call is unavailable.');
        }
        $schemaLoop = substr($driver, $schemaLoopStart, $schemaLoopEnd - $schemaLoopStart);
        $schemaCursor = 0;
        foreach (
            [
            '019bc200-0000-7000-8000-000000000001',
            '019bc200-0000-7000-8000-000000000002',
            '019bc200-0000-7000-8000-000000000004',
            '019bc200-0000-7000-8000-000000000005',
            '019bc200-0000-7000-8000-000000000003',
            ] as $definitionId
        ) {
            $definitionPosition = strpos($schemaLoop, $definitionId, $schemaCursor);
            if ($definitionPosition === false) {
                self::fail('The deployment schema-install order is not dependency-safe.');
            }
            $schemaCursor = $definitionPosition + strlen($definitionId);
        }
        self::assertStringContainsString('access grant', $driver);
        self::assertStringContainsString('integration:manage projection-rebuild', $driver);
        self::assertStringContainsString('integration:manage projections', $driver);
        self::assertStringContainsString('integration:manage outbox --limit=1000', $driver);
        self::assertStringContainsString("drain_current_outbox 'pre-existing integration work'", $driver);
        self::assertStringContainsString("drain_current_outbox 'asset-inspection integration work'", $driver);
        self::assertStringContainsString('--max-items="$outstanding"', $driver);
        self::assertStringContainsString('maximum_runtime="$((outstanding * 2 + 30))"', $driver);
        self::assertStringNotContainsString('seq 1 100', $driver);
        self::assertStringNotContainsString('one hundred bounded passes', $driver);
        self::assertStringNotContainsString('KUMWE_BUSINESS_DEMO=false', $acceptance);
        self::assertStringContainsString('.status == "dispatched"', $driver);
        self::assertStringContainsString('.status == "dead"', $driver);
        self::assertStringContainsString('kumwe.asset-inspection-example.integration', $driver);
        self::assertStringContainsString('business-report export', $driver);
        self::assertStringContainsString('kumwe_business_report_execute', $driver);
        self::assertStringContainsString('--force-recreate app web worker scheduler', $driver);
        self::assertStringContainsString('$database->quoteSingleIdentifier($table->physicalName)', $support);
        self::assertStringNotContainsString('$tables->quoted($table->physicalName)', $support);

        $boundedDrainStart = strpos($driver, "\ndrain_current_outbox() {");
        $boundedDrainEnd = strpos(
            $driver,
            "\ndrain_existing_integrations() {",
            $boundedDrainStart === false ? 0 : $boundedDrainStart,
        );
        if ($boundedDrainStart === false || $boundedDrainEnd === false) {
            self::fail('The bounded deployment outbox drain is unavailable.');
        }
        $boundedDrain = substr($driver, $boundedDrainStart, $boundedDrainEnd - $boundedDrainStart);
        self::assertStringContainsString('--stream=outbox', $boundedDrain);
        self::assertStringContainsString('.status == "pending" or .status == "reserved"', $boundedDrain);
        self::assertStringContainsString('.status == "dead"', $boundedDrain);
        self::assertStringNotContainsString('purge', $boundedDrain);
        self::assertStringNotContainsString('sleep ', $boundedDrain);

        $initialDrain = strpos($driver, "\ndrain_integrations\n");
        $baselineStop = strpos($driver, "\n    compose --profile automation stop worker scheduler\n");
        $baselineDrain = strpos($driver, "\n    drain_existing_integrations\n");
        $packageBuild = strpos($driver, 'app php bin/kumwe extension:build');
        $packageRestart = strpos($driver, '--force-recreate app web worker scheduler', $packageBuild);
        $exerciseStop = strpos(
            $driver,
            "\ncompose --profile automation stop worker scheduler\n",
            $packageRestart === false ? 0 : $packageRestart,
        );
        $firstExerciseToken = strpos(
            $driver,
            'issue_token "$cli_token_file"',
            $exerciseStop === false ? 0 : $exerciseStop,
        );
        $replay = strpos($driver, 'acceptance_php replay');
        $lifecycleRefresh = strrpos(
            $driver,
            "refresh_management_token 'kumwe.asset-inspection-example.manage,kumwe.asset-inspection-example.view'",
        );
        $lifecycleStop = strpos(
            $driver,
            "\ncompose --profile automation stop worker scheduler\n",
            $lifecycleRefresh === false ? 0 : $lifecycleRefresh,
        );
        $finalDrain = strpos(
            $driver,
            "\ndrain_integrations\n",
            $initialDrain === false ? 0 : $initialDrain + 1,
        );
        $snapshot = strpos($driver, 'asset-inspection-deployment-acceptance.php snapshot');
        if (
            $baselineStop === false
            || $baselineDrain === false
            || $packageBuild === false
            || $packageRestart === false
            || $exerciseStop === false
            || $firstExerciseToken === false
            || $initialDrain === false
            || $replay === false
            || $lifecycleRefresh === false
            || $lifecycleStop === false
            || $finalDrain === false
            || $snapshot === false
            || $baselineStop >= $baselineDrain
            || $baselineDrain >= $packageBuild
            || $packageBuild >= $packageRestart
            || $packageRestart >= $exerciseStop
            || $exerciseStop >= $firstExerciseToken
            || $firstExerciseToken >= $initialDrain
            || $initialDrain >= $replay
            || $replay >= $lifecycleRefresh
            || $lifecycleRefresh >= $lifecycleStop
            || $lifecycleStop >= $finalDrain
            || $finalDrain >= $snapshot
        ) {
            self::fail('The integration drain does not fence both event replay and the source snapshot.');
        }

        $persistenceToken = strpos($acceptance, '--name=deployment-persistence');
        $persistenceTokenEnd = strpos(
            $acceptance,
            '--password-file="$password_file"',
            $persistenceToken === false ? 0 : $persistenceToken,
        );
        $preRestartAuthorization = strpos(
            $acceptance,
            'Authorization: Bearer $persistence_api_token',
            $persistenceToken === false ? 0 : $persistenceToken,
        );
        $persistenceMask = strpos(
            $acceptance,
            'echo "::add-mask::$persistence_api_token"',
            $persistenceToken === false ? 0 : $persistenceToken,
        );
        $restart = strpos(
            $acceptance,
            'restart app web worker scheduler',
            $preRestartAuthorization === false ? 0 : $preRestartAuthorization,
        );
        $postRestartAuthorization = strpos(
            $acceptance,
            'Authorization: Bearer $persistence_api_token',
            $preRestartAuthorization === false ? 0 : $preRestartAuthorization + 1,
        );
        if (
            $persistenceToken === false
            || $persistenceTokenEnd === false
            || $persistenceMask === false
            || $preRestartAuthorization === false
            || $restart === false
            || $postRestartAuthorization === false
            || $persistenceToken >= $preRestartAuthorization
            || $persistenceTokenEnd >= $persistenceMask
            || $persistenceMask >= $preRestartAuthorization
            || $preRestartAuthorization >= $restart
            || $restart >= $postRestartAuthorization
        ) {
            self::fail('The restart proof does not reuse one freshly issued persistence token.');
        }
        $persistenceIssue = substr($acceptance, $persistenceToken, $persistenceTokenEnd - $persistenceToken);
        self::assertStringContainsString('--capabilities=content.read', $persistenceIssue);
        self::assertStringContainsString('--audience=kumwe-http', $persistenceIssue);
        self::assertStringContainsString('--purpose=api', $persistenceIssue);
        self::assertStringNotContainsString('--organization=', $persistenceIssue);
        self::assertStringNotContainsString('KUMWE_ACCEPTANCE_API_TOKEN', $acceptance);
    }

    /**
     * Keep deployment bootstrap credentials unscoped until an exact live membership exists.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDeploymentAcceptanceBindsSensitiveTokensAfterMembershipBootstrap(): void
    {
        $acceptance = $this->contents('.github/workflows/deployment-acceptance.yml');
        $driver = $this->contents('tools/asset-inspection-deployment-acceptance.sh');
        $support = $this->contents('tests/Support/AssetInspectionDeploymentAcceptance.php');
        $bootstrapStart = strpos($acceptance, '--name=deployment-cli-management');
        if ($bootstrapStart === false) {
            self::fail('The deployment management-token bootstrap is missing.');
        }
        $bootstrapEnd = strpos($acceptance, '--password-file="$password_file"', $bootstrapStart);
        if ($bootstrapEnd === false) {
            self::fail('The deployment management-token bootstrap is incomplete.');
        }
        $bootstrap = substr($acceptance, $bootstrapStart, $bootstrapEnd - $bootstrapStart);
        if (preg_match('/--capabilities=([^\s\\\\]+)/D', $bootstrap, $workflowCapabilities) !== 1) {
            self::fail('The workflow bootstrap capability set is unavailable.');
        }
        if (preg_match("/bootstrap_capabilities='([^']+)'/D", $driver, $driverCapabilities) !== 1) {
            self::fail('The driver bootstrap capability set is unavailable.');
        }

        self::assertStringContainsString('business.schema.plan', $bootstrap);
        self::assertSame($driverCapabilities[1], $workflowCapabilities[1]);
        foreach (['business.record.', 'business.security.manage', 'business.step_up.manage'] as $sensitive) {
            self::assertStringNotContainsString($sensitive, $bootstrap);
        }
        self::assertStringNotContainsString('--organization=', $bootstrap);
        self::assertStringContainsString('--organization="$KUMWE_ACCEPTANCE_ORGANIZATION"', $driver);
        self::assertStringContainsString("delegated_capabilities=\"\${delegated_capabilities", $driver);
        self::assertStringContainsString("        refresh_bootstrap_token\n", $driver);
        self::assertStringContainsString("apply_policy_profile\n    refresh_management_token", $driver);
        self::assertStringContainsString('acceptance_php apply-seed-policy', $driver);
        self::assertStringContainsString('asset-inspection-seed-policy-operator@kumwe.test', $driver);
        self::assertStringContainsString('(.policy_ids | length == 10)', $driver);
        self::assertStringContainsString('(.policy_ids | length == 8)', $driver);
        self::assertStringContainsString('$security->createOrganization(', $support);
        self::assertStringContainsString('$security->createMembership(', $support);
        self::assertStringContainsString('private static function seedPolicyRequests(', $support);
        self::assertStringContainsString("'business.record.create'", $support);
        self::assertStringContainsString("'business.record.relate'", $support);
        self::assertStringContainsString('use DateTimeImmutable;', $support);
        self::assertStringContainsString('private static function requiredRowInteger(', $support);
        self::assertStringContainsString('asset_manifest_for_restore', $acceptance);
        self::assertStringContainsString('sudo chown 82:82 "$asset_manifest_for_restore"', $acceptance);
        self::assertStringContainsString("sudo bash -euo pipefail -c '\n              cd \"\$1\"", $acceptance);
    }

    public function testNativeInstallerPersistsIndependentRuntimeTrustAndStableIdentity(): void
    {
        $installer = $this->contents('bin/kumwe-install');

        self::assertStringContainsString(
            "'EXTENSION_RUNTIME_SIGNING_KEY' => base64_encode(random_bytes(48))",
            $installer,
        );
        self::assertStringContainsString("'KUMWE_DEPLOYMENT_ID' =>", $installer);
        self::assertStringContainsString('strlen($databasePrefix) > 28', $installer);
        self::assertStringContainsString("'KUMWE_REPLICA_ID' => 'primary-replica'", $installer);
        self::assertStringContainsString("'KUMWE_PROCESS_ID' => 'application-runtime'", $installer);
        self::assertStringContainsString("'KUMWE_INSTANCE_ID' => 'primary-instance'", $installer);
    }

    public function testObservabilityContractIsPrivateByDefault(): void
    {
        /**
         * @var    array{
         *             logging: array{destination: string},
         *             health: array{expose_details: bool},
         *             metrics: array{enabled: bool, public: bool}
         *         } $configuration
         */
        $configuration = require $this->root . '/config/observability.php';

        self::assertFalse($configuration['metrics']['enabled']);
        self::assertFalse($configuration['metrics']['public']);
        self::assertFalse($configuration['health']['expose_details']);
        self::assertSame('php://stderr', $configuration['logging']['destination']);
    }

    public function testTheDeclaredObservabilityContractIsTheOneTheKernelActuallyWires(): void
    {
        // The contract used to be read only by this test. If it stops being loaded by the composition
        // root, it goes back to being a statement of intent that the running process ignores.
        $kernel = $this->contents('src/Kernel/ContainerFactory.php');

        self::assertStringContainsString('ObservabilityContract::load($root)', $kernel);
        self::assertStringContainsString('new JsonFormatter(', $kernel);
        foreach (['LogRedactionProcessor', 'LogContextProcessor'] as $processor) {
            self::assertStringContainsString(
                sprintf('pushProcessor(self::service($container, %s::class))', $processor),
                $kernel,
            );
        }
        self::assertStringNotContainsString('$configuration->debug ? Level::Debug : Level::Info', $kernel);
    }

    public function testAlertRulesShipForEveryDocumentedSignalAndNameTheirRunbook(): void
    {
        $rules = $this->contents('deploy/observability/alerts.yaml');

        foreach (
            [
                'kumwe_ready',
                'kumwe_http_requests_total',
                'kumwe_http_request_duration_seconds_bucket',
                'kumwe_jobs_oldest_due_age_seconds',
                'kumwe_jobs_dead_lettered',
                'kumwe_worker_heartbeat_age_seconds',
                'kumwe_scheduler_lag_seconds',
                'kumwe_outbox_oldest_pending_age_seconds',
                'kumwe_inbox_poison',
                'kumwe_process_work_oldest_overdue_age_seconds',
                'kumwe_export_queue_depth',
                'kumwe_metrics_collection_failed',
            ] as $signal
        ) {
            self::assertStringContainsString($signal, $rules, sprintf('%s has no alert rule.', $signal));
        }

        // An alert nobody can act on is worse than none, so every rule states its runbook and the
        // concrete failure it would have caught.
        $alerts = substr_count($rules, '- alert: ');
        self::assertGreaterThan(10, $alerts);
        self::assertSame($alerts, substr_count($rules, 'runbook: '));
        self::assertSame($alerts, substr_count($rules, 'caught: '));
        self::assertStringContainsString('deploy/observability/alerts.yaml', $this->contents(
            'docs/operations/monitoring.md',
        ));
    }

    public function testNoAlertRuleReferencesARunbookThatDoesNotExist(): void
    {
        $rules = $this->contents('deploy/observability/alerts.yaml');
        preg_match_all('/runbook: (\S+)/', $rules, $matches);
        self::assertNotSame([], $matches[1]);

        foreach (array_unique($matches[1]) as $reference) {
            [$path, $anchor] = array_pad(explode('#', $reference, 2), 2, null);
            self::assertFileExists($this->root . '/' . $path);
            if ($anchor === null) {
                continue;
            }
            $headings = [];
            preg_match_all('/^#{2,4} (.+)$/m', $this->contents($path), $found);
            foreach ($found[1] as $heading) {
                $headings[] = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', trim($heading)));
            }
            self::assertContains($anchor, $headings, sprintf('%s names a missing section.', $reference));
        }
    }

    public function testNoMetricNameOrLabelInTheCatalogueIsUnbounded(): void
    {
        $catalog = $this->contents('src/Infrastructure/Observability/MetricCatalog.php');

        // These are the label names that would turn one series into one per row or per account.
        foreach (['path', 'route', 'user', 'record', 'tenant', 'site', 'email', 'session'] as $unbounded) {
            self::assertStringNotContainsString(
                sprintf("'%s' => ", $unbounded),
                $catalog,
                sprintf('The %s label would be unbounded.', $unbounded),
            );
        }
    }

    private function contents(string $path): string
    {
        $contents = file_get_contents($this->root . '/' . $path);
        self::assertIsString($contents, sprintf('Unable to read %s.', $path));

        return $contents;
    }
}
