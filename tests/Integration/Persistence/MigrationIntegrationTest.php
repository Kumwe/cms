<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Persistence;

use Kumwe\App\Tests\Support\TranslatesConsoleOutput;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Application\Automation\Job\PurgeStudioContentAuthoringContextsHandler;
use Kumwe\App\Application\Automation\JobExecutionClass;
use Kumwe\App\Application\Automation\JobHandlerRegistry;
use Kumwe\App\Delivery\Console\Command\MigrateCommand;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\Content\Infrastructure\Persistence\DoctrineContentModelRepository;
use Kumwe\App\Infrastructure\Persistence\Migration\ApplicationAuthorizationMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\AuthorizationRecoveryIntegrationMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\CoreSchemaMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\ContentModelIdentifierCollationMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\ContentModelRuntimeMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\DatabaseDrivenPresentationMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\DemoProfileProvenanceMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\DynamicSiteContentMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\ExtensionContributionCatalogMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\BusinessDefinitionCatalogMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\BusinessSecurityPortalMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\DoctrineMigrationLock;
use Kumwe\App\Infrastructure\Persistence\Migration\DoctrineMigrationRepository;
use Kumwe\App\Infrastructure\Persistence\Migration\IdempotencyLeaseNullabilityMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\JobRecoveryMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\IsolateThemeSurfacesMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\InstallationGlobalAutomationMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\MigrationRunner;
use Kumwe\App\Infrastructure\Persistence\Migration\ResourceOwnershipScopeMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\SiteAutomationContextMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\StudioContentAuthoringContextRetentionMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\TokenAndTrustLifecycleMigration;
use Kumwe\App\Infrastructure\Persistence\ReadinessProbe;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\ContainerFactory;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use ReflectionMethod;
use RuntimeException;

#[CoversClass(MigrationRunner::class)]
#[CoversClass(DoctrineMigrationLock::class)]
#[CoversClass(DoctrineMigrationRepository::class)]
#[CoversClass(CoreSchemaMigration::class)]
#[CoversClass(ContentModelIdentifierCollationMigration::class)]
#[CoversClass(ResourceOwnershipScopeMigration::class)]
#[CoversClass(ContentModelRuntimeMigration::class)]
#[CoversClass(DynamicSiteContentMigration::class)]
#[CoversClass(DatabaseDrivenPresentationMigration::class)]
#[CoversClass(DemoProfileProvenanceMigration::class)]
#[CoversClass(ExtensionContributionCatalogMigration::class)]
#[CoversClass(BusinessDefinitionCatalogMigration::class)]
#[CoversClass(BusinessSecurityPortalMigration::class)]
#[CoversClass(JobRecoveryMigration::class)]
#[CoversClass(ApplicationAuthorizationMigration::class)]
#[CoversClass(IdempotencyLeaseNullabilityMigration::class)]
#[CoversClass(AuthorizationRecoveryIntegrationMigration::class)]
#[CoversClass(SiteAutomationContextMigration::class)]
#[CoversClass(InstallationGlobalAutomationMigration::class)]
#[CoversClass(StudioContentAuthoringContextRetentionMigration::class)]
#[CoversClass(TokenAndTrustLifecycleMigration::class)]
#[CoversClass(IsolateThemeSurfacesMigration::class)]
final class MigrationIntegrationTest extends TestCase
{
    public function testDatabaseMigrationIsIdempotentAndReady(): void
    {
        $container = (new ContainerFactory())->create(Environment::fromGlobals());
        $command = $container->get(MigrateCommand::class);
        self::assertInstanceOf(MigrateCommand::class, $command);
        self::assertSame(0, $command->execute([], new class implements Output {
            use TranslatesConsoleOutput;

            public function line(string $message): void
            {
            }

            public function error(string $message): void
            {
            }
        }));
        self::assertTrue($container->get(ReadinessProbe::class)->ready());

        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $jobHandlers = $container->get(JobHandlerRegistry::class);
        self::assertInstanceOf(JobHandlerRegistry::class, $jobHandlers);
        self::assertInstanceOf(
            PurgeStudioContentAuthoringContextsHandler::class,
            $jobHandlers->find(PurgeStudioContentAuthoringContextsHandler::JOB_TYPE),
        );
        self::assertSame(
            '40bf9c3fa708f153453cfbd6caf93c9cef806052eabb6a1bb8ad7a4b71e7dddf',
            (new CoreSchemaMigration($tables))->checksum(),
        );
        $schema = $database->createSchemaManager();
        self::assertTrue($schema->introspectTable($tables->raw('users'))->hasColumn('security_epoch'));
        $idempotency = $schema->introspectTable($tables->raw('idempotency'));
        foreach (
            ['authorization_fingerprint', 'lease_owner', 'lease_expires_at', 'owner_token', 'locked_until'] as $column
        ) {
            self::assertTrue($idempotency->hasColumn($column));
        }
        self::assertFalse($idempotency->getColumn('lease_owner')->getNotnull());
        self::assertFalse($idempotency->getColumn('lease_expires_at')->getNotnull());
        $jobs = $schema->introspectTable($tables->raw('jobs'));
        $schedules = $schema->introspectTable($tables->raw('schedules'));
        self::assertTrue($jobs->hasColumn('lease_token'));
        self::assertTrue($jobs->hasColumn('execution_scope'));
        self::assertTrue($schedules->hasColumn('execution_scope'));
        self::assertTrue($schema->tablesExist([
            $tables->raw('sites'),
            $tables->raw('resource_site_ownership'),
        ]));
        self::assertTrue($schema->introspectTable($tables->raw('sites'))->hasColumn('enabled'));
        self::assertTrue($schema->introspectTable($tables->raw('sites'))->hasColumn('policy_generation'));
        self::assertSame('default', $database->fetchOne(sprintf(
            'SELECT site_identifier FROM %s WHERE resource_type = ? AND resource_id = ?',
            $tables->quoted('resource_site_ownership'),
        ), ['schedule', '00000000-0000-7000-8000-000000000801']));
        self::assertSame(ApplicationAuthorizationMigration::ID, $database->fetchOne(sprintf(
            'SELECT version FROM %s WHERE version = ?',
            $tables->quoted('schema_migrations'),
        ), [ApplicationAuthorizationMigration::ID]));
        self::assertSame(JobRecoveryMigration::ID, $database->fetchOne(sprintf(
            'SELECT version FROM %s WHERE version = ?',
            $tables->quoted('schema_migrations'),
        ), [JobRecoveryMigration::ID]));
        self::assertSame(IdempotencyLeaseNullabilityMigration::ID, $database->fetchOne(sprintf(
            'SELECT version FROM %s WHERE version = ?',
            $tables->quoted('schema_migrations'),
        ), [IdempotencyLeaseNullabilityMigration::ID]));
        self::assertSame('default', $database->fetchOne(sprintf(
            'SELECT site_identifier FROM %s WHERE resource_type = ? AND resource_id = ?',
            $tables->quoted('resource_site_ownership'),
        ), ['schedule', '00000000-0000-7000-8000-000000000802']));
        // The epoch claim belongs to the row the parent schema seeded, so it is addressed by its fixed
        // identifier: the harness re-bootstraps an administrator under the same email when mid-suite
        // authentication fails, and that recreated user legitimately starts a fresh epoch count.
        $legacyAdministrator = $database->fetchAssociative(sprintf(
            'SELECT id, security_epoch FROM %s WHERE id = ?',
            $tables->quoted('users'),
        ), ['018f22e2-7c8b-7ab0-8f3a-88e8026bb901']);
        if ($legacyAdministrator !== false) {
            self::assertSame('4', (string) $legacyAdministrator['security_epoch']);
            self::assertSame('2', (string) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s g INNER JOIN %s r ON r.id = g.role_id '
                . "WHERE r.code = 'administrator' AND g.capability_code IN (?, ?)",
                $tables->quoted('role_capability_grants'),
                $tables->quoted('roles'),
            ), ['themes.site.manage', 'themes.administrator.manage']));
        }
        $users = $schema->introspectTable($tables->raw('users'));
        $tokens = $schema->introspectTable($tables->raw('api_tokens'));
        $keys = $schema->introspectTable($tables->raw('extension_trust_keys'));
        $releases = $schema->introspectTable($tables->raw('extension_releases'));
        self::assertTrue($users->hasColumn('security_epoch'));
        foreach (['security_epoch', 'audience', 'purpose', 'site_identifier', 'rotated_from'] as $column) {
            self::assertTrue($tokens->hasColumn($column), sprintf('Token lifecycle column %s is missing.', $column));
        }
        self::assertTrue($tokens->getColumn('expires_at')->getNotnull());
        foreach (['vendor_namespace', 'extension_pattern', 'expires_at', 'revoked_by'] as $column) {
            self::assertTrue($keys->hasColumn($column), sprintf('Trust lifecycle column %s is missing.', $column));
        }
        self::assertTrue($keys->getColumn('expires_at')->getNotnull());
        foreach (['artifact_sha256', 'deployed_tree_sha256', 'trust_state'] as $column) {
            self::assertTrue($releases->hasColumn($column), sprintf('Release digest column %s is missing.', $column));
        }
        self::assertTrue($schema->tablesExist([$tables->raw('extension_trust_generation')]));
        self::assertTrue($schema->tablesExist([$tables->raw('extension_runtime_outbox')]));
        self::assertSame('ready', $database->fetchOne(sprintf(
            'SELECT lifecycle_state FROM %s WHERE singleton_key = 1',
            $tables->quoted('extension_trust_generation'),
        )));
        self::assertSame('1', (string) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s',
            $tables->quoted('extension_trust_generation'),
        )));
        $idempotency = $schema->introspectTable($tables->raw('idempotency'));
        foreach (['owner_token', 'lease_expires_at', 'attempt'] as $column) {
            self::assertTrue($idempotency->hasColumn($column));
        }
        self::assertFalse($idempotency->getColumn('owner_token')->getNotnull());
        self::assertSame(64, $idempotency->getColumn('owner_token')->getLength());
        self::assertFalse($idempotency->getColumn('lease_owner')->getNotnull());
        self::assertFalse($idempotency->getColumn('lease_expires_at')->getNotnull());
        self::assertInstanceOf(BigIntType::class, $users->getColumn('security_epoch')->getType());
        self::assertSame(AuthorizationRecoveryIntegrationMigration::ID, $database->fetchOne(sprintf(
            'SELECT version FROM %s WHERE version = ?',
            $tables->quoted('schema_migrations'),
        ), [AuthorizationRecoveryIntegrationMigration::ID]));
        self::assertSame(SiteAutomationContextMigration::ID, $database->fetchOne(sprintf(
            'SELECT version FROM %s WHERE version = ?',
            $tables->quoted('schema_migrations'),
        ), [SiteAutomationContextMigration::ID]));
        self::assertSame(
            ['administrator', 'site'],
            $database->fetchFirstColumn(sprintf(
                'SELECT surface FROM %s ORDER BY surface',
                $tables->quoted('theme_activations'),
            )),
        );
        self::assertSame(
            ['default'],
            $database->fetchFirstColumn(sprintf(
                'SELECT site_identifier FROM %s ORDER BY site_identifier',
                $tables->quoted('site_theme_activations'),
            )),
        );
        self::assertSame(
            ['themes.administrator.manage', 'themes.site.manage'],
            $database->fetchFirstColumn(sprintf(
                "SELECT code FROM %s WHERE code LIKE 'themes.%%.manage' ORDER BY code",
                $tables->quoted('capabilities'),
            )),
        );
        self::assertTrue(
            $schema->introspectTable($tables->raw('extension_install_operations'))
                ->hasColumn('site_identifier'),
        );
        self::assertSame(InstallationGlobalAutomationMigration::ID, $database->fetchOne(sprintf(
            'SELECT version FROM %s WHERE version = ?',
            $tables->quoted('schema_migrations'),
        ), [InstallationGlobalAutomationMigration::ID]));
        self::assertTrue($schema->tablesExist([
            $tables->raw('content_types'),
            $tables->raw('content_type_definition_versions'),
            $tables->raw('workflows'),
            $tables->raw('workflow_definition_versions'),
        ]));
        $content = $schema->introspectTable($tables->raw('content_entries'));
        $contentColumns = [
            'site_identifier',
            'content_type_id',
            'content_type_version',
            'workflow_id',
            'workflow_version',
        ];
        foreach ($contentColumns as $column) {
            self::assertTrue($content->hasColumn($column), sprintf('Content-model column %s is missing.', $column));
        }
        self::assertSame(ContentModelRuntimeMigration::ID, $database->fetchOne(sprintf(
            'SELECT version FROM %s WHERE version = ?',
            $tables->quoted('schema_migrations'),
        ), [ContentModelRuntimeMigration::ID]));
        self::assertSame(ContentModelIdentifierCollationMigration::ID, $database->fetchOne(sprintf(
            'SELECT version FROM %s WHERE version = ?',
            $tables->quoted('schema_migrations'),
        ), [ContentModelIdentifierCollationMigration::ID]));
        self::assertSame(DynamicSiteContentMigration::ID, $database->fetchOne(sprintf(
            'SELECT version FROM %s WHERE version = ?',
            $tables->quoted('schema_migrations'),
        ), [DynamicSiteContentMigration::ID]));
        self::assertSame(DatabaseDrivenPresentationMigration::ID, $database->fetchOne(sprintf(
            'SELECT version FROM %s WHERE version = ?',
            $tables->quoted('schema_migrations'),
        ), [DatabaseDrivenPresentationMigration::ID]));
        self::assertTrue($schema->tablesExist([
            $tables->raw('demo_profile_installations'),
            $tables->raw('demo_profile_assets'),
        ]));
        self::assertSame('2', (string) $database->fetchOne(sprintf(
            "SELECT COUNT(*) FROM %s WHERE site_identifier = ? AND status = 'complete'",
            $tables->quoted('demo_profile_installations'),
        ), [SiteContext::DEFAULT]));
        self::assertSame(ExtensionContributionCatalogMigration::ID, $database->fetchOne(sprintf(
            'SELECT version FROM %s WHERE version = ?',
            $tables->quoted('schema_migrations'),
        ), [ExtensionContributionCatalogMigration::ID]));
        self::assertTrue($schema->tablesExist([
            $tables->raw('extension_contribution_capabilities'),
            $tables->raw('extension_contribution_resource_policies'),
        ]));
        self::assertSame(BusinessSecurityPortalMigration::ID, $database->fetchOne(sprintf(
            'SELECT version FROM %s WHERE version = ?',
            $tables->quoted('schema_migrations'),
        ), [BusinessSecurityPortalMigration::ID]));
        $capabilityCatalog = $schema->introspectTable($tables->raw('capabilities'));
        foreach (
            [
            'owner_kind',
            'owner_identifier',
            'allowed_scopes',
            'delegable',
            'high_impact',
            'definition_version',
            'definition_checksum',
            'lifecycle_state',
            ] as $column
        ) {
            self::assertTrue($capabilityCatalog->hasColumn($column));
        }
        self::assertSame(
            ['business.record.export', 'business.record.report', 'business.record.transition'],
            $database->fetchFirstColumn(sprintf(
                'SELECT code FROM %s WHERE code IN (?, ?, ?) ORDER BY code',
                $tables->quoted('capabilities'),
            ), ['business.record.transition', 'business.record.export', 'business.record.report']),
        );
        $transition = $database->fetchAssociative(sprintf(
            'SELECT owner_kind, owner_identifier, allowed_scopes, delegable, high_impact, '
            . 'definition_version, definition_checksum, lifecycle_state FROM %s WHERE code = ?',
            $tables->quoted('capabilities'),
        ), ['business.record.transition']);
        self::assertIsArray($transition);
        self::assertSame('core', $transition['owner_kind']);
        self::assertSame('core', $transition['owner_identifier']);
        self::assertContains('business_record', json_decode(
            (string) $transition['allowed_scopes'],
            true,
            flags: JSON_THROW_ON_ERROR,
        ));
        self::assertSame('active', $transition['lifecycle_state']);
        self::assertSame('1', (string) $transition['definition_version']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', (string) $transition['definition_checksum']);
        self::assertTrue($schema->tablesExist([
            $tables->raw('organizations'),
            $tables->raw('workspaces'),
            $tables->raw('organization_memberships'),
            $tables->raw('membership_workspaces'),
            $tables->raw('membership_roles'),
            $tables->raw('resource_policies'),
            $tables->raw('separation_duty_rules'),
            $tables->raw('approval_requests'),
            $tables->raw('approval_votes'),
            $tables->raw('step_up_credentials'),
            $tables->raw('step_up_recovery_codes'),
            $tables->raw('step_up_proofs'),
            $tables->raw('portal_sessions'),
        ]));
        $resourcePolicies = $schema->introspectTable($tables->raw('resource_policies'));
        self::assertTrue(array_any(
            $resourcePolicies->getForeignKeys(),
            static fn (\Doctrine\DBAL\Schema\ForeignKeyConstraint $foreignKey): bool =>
                $foreignKey->getReferencedTableName()->toString() === $tables->raw('business_definitions'),
        ));
        $separationRules = $schema->introspectTable($tables->raw('separation_duty_rules'));
        foreach (
            [
                'site_identifier',
                'organization_id',
                'scope_key',
                'request_action',
                'approval_action',
                'requester_role_id',
                'approver_role_id',
                'distinct_actors',
                'version',
            ] as $column
        ) {
            self::assertTrue($separationRules->hasColumn($column));
        }
        $approvalRequests = $schema->introspectTable($tables->raw('approval_requests'));
        foreach (
            [
                'rule_version',
                'approval_action',
                'approver_role_id',
                'distinct_actors',
                'site_identifier',
                'organization_id',
                'workspace_id',
                'resource_version',
                'context_fingerprint',
                'payload_digest',
                'expires_at',
                'consumed_at',
                'version',
            ] as $column
        ) {
            self::assertTrue($approvalRequests->hasColumn($column));
        }
        $stepUpProofs = $schema->introspectTable($tables->raw('step_up_proofs'));
        foreach (
            [
                'nonce_digest',
                'user_id',
                'session_id',
                'site_identifier',
                'organization_identifier',
                'workspace_identifier',
                'purpose',
                'security_epoch',
                'expires_at',
                'consumed_at',
                'revoked_at',
            ] as $column
        ) {
            self::assertTrue($stepUpProofs->hasColumn($column));
        }
        $portalSessions = $schema->introspectTable($tables->raw('portal_sessions'));
        foreach (
            [
                'token_digest',
                'csrf_token',
                'site_identifier',
                'organization_identifier',
                'workspace_identifier',
                'membership_id',
                'membership_version',
                'policy_generation',
                'security_epoch',
                'user_agent_digest',
                'step_up_at',
                'expires_at',
            ] as $column
        ) {
            self::assertTrue($portalSessions->hasColumn($column));
        }
        self::assertSame(BusinessDefinitionCatalogMigration::ID, $database->fetchOne(sprintf(
            'SELECT version FROM %s WHERE version = ?',
            $tables->quoted('schema_migrations'),
        ), [BusinessDefinitionCatalogMigration::ID]));
        self::assertTrue($schema->tablesExist([
            $tables->raw('business_field_types'),
            $tables->raw('business_definitions'),
            $tables->raw('business_definition_drafts'),
            $tables->raw('business_definition_versions'),
            $tables->raw('business_definition_dependencies'),
        ]));
        $navigationItems = $schema->introspectTable($tables->raw('navigation_items'));
        foreach (['target_type', 'content_id', 'target_url'] as $column) {
            self::assertTrue(
                $navigationItems->hasColumn($column),
                sprintf('Navigation target column %s is missing.', $column),
            );
        }
        $models = new DoctrineContentModelRepository($database, $tables);
        $page = $models->contentType(SiteContext::default(), 'page');
        self::assertNotNull($page);
        self::assertSame(3, $page->version);
        self::assertArrayNotHasKey('brand_logo', $page->schema()['properties']);
        $workflow = $models->workflow(SiteContext::default(), $page->workflowId, $page->workflowVersion);
        self::assertNotNull($workflow);
        self::assertSame('draft', $workflow->initialState());
        self::assertTrue($workflow->isPublic('published'));
        self::assertFalse($database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE site_identifier = ? AND slug = ? AND deleted_at IS NULL',
            $tables->quoted('content_entries'),
        ), [SiteContext::DEFAULT, 'home']));
        $homepageSetting = $database->fetchOne(sprintf(
            'SELECT setting_value FROM %s WHERE setting_key = ?',
            $tables->quoted('site_settings'),
        ), ['site.homepage_content_id']);
        self::assertIsString($homepageSetting);
        self::assertNull(json_decode($homepageSetting, true, flags: JSON_THROW_ON_ERROR));
        $presentationSetting = $database->fetchOne(sprintf(
            'SELECT setting_value FROM %s WHERE setting_key = ?',
            $tables->quoted('site_settings'),
        ), ['site.presentation']);
        self::assertIsString($presentationSetting);
        $presentation = json_decode($presentationSetting, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($presentation);
        self::assertSame('corporate', $presentation['active_scheme']);
        self::assertSame('main', $presentation['primary_menu']);
        self::assertSame(
            '/media/00000000-0000-7000-8000-000000000901/kumwe-symbol.svg',
            $presentation['logo'],
        );
        self::assertSame('main', $database->fetchOne(sprintf(
            'SELECT handle FROM %s WHERE handle = ?',
            $tables->quoted('navigation_menus'),
        ), ['main']));
        self::assertSame('0', (string) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE menu_id = ?',
            $tables->quoted('navigation_items'),
        ), ['00000000-0000-7000-8000-000000001101']));
        self::assertSame('0', (string) $database->fetchOne(sprintf(
            "SELECT COUNT(*) FROM %s WHERE handle LIKE 'site.default.vdm_%%'",
            $tables->quoted('business_definitions'),
        )));

        self::assertSame(ResourceOwnershipScopeMigration::ID, $database->fetchOne(sprintf(
            'SELECT version FROM %s WHERE version = ?',
            $tables->quoted('schema_migrations'),
        ), [ResourceOwnershipScopeMigration::ID]));
        self::assertTrue($schema->tablesExist([
            $tables->raw('site_groups'),
            $tables->raw('site_group_members'),
        ]));
        $ownership = $schema->introspectTable($tables->raw('resource_site_ownership'));
        self::assertTrue($ownership->hasColumn('scope_level'));
        self::assertTrue($ownership->hasColumn('group_identifier'));
        self::assertFalse($ownership->getColumn('site_identifier')->getNotnull());
        self::assertSame('0', (string) $database->fetchOne(sprintf(
            "SELECT COUNT(*) FROM %s WHERE scope_level <> 'site' OR site_identifier IS NULL",
            $tables->quoted('resource_site_ownership'),
        )));
        self::assertSame('site', $database->fetchOne(sprintf(
            'SELECT scope_level FROM %s WHERE resource_type = ? AND resource_id = ?',
            $tables->quoted('resource_site_ownership'),
        ), ['schedule', '00000000-0000-7000-8000-000000000801']));
        self::assertSame(StudioContentAuthoringContextRetentionMigration::ID, $database->fetchOne(sprintf(
            'SELECT version FROM %s WHERE version = ?',
            $tables->quoted('schema_migrations'),
        ), [StudioContentAuthoringContextRetentionMigration::ID]));
        $retentionSchedule = $database->fetchAssociative(sprintf(
            'SELECT id, cron_expression, payload, execution_scope FROM %s WHERE job_type = ?',
            $tables->quoted('schedules'),
        ), [PurgeStudioContentAuthoringContextsHandler::JOB_TYPE]);
        self::assertIsArray($retentionSchedule);
        self::assertSame(StudioContentAuthoringContextRetentionMigration::SCHEDULE_ID, $retentionSchedule['id']);
        self::assertSame('11 * * * *', $retentionSchedule['cron_expression']);
        self::assertSame('installation', $retentionSchedule['execution_scope']);
        self::assertSame(
            ['batch_size' => 1_000, 'maximum_batches' => 10],
            json_decode((string) $retentionSchedule['payload'], true, flags: JSON_THROW_ON_ERROR),
        );
        $retentionJobId = Uuid::uuid7()->toString();
        $unrelatedJobId = Uuid::uuid7()->toString();
        $now = new DateTimeImmutable('2026-08-27T08:00:00+00:00');
        $database->update(
            $tables->raw('schedules'),
            ['execution_scope' => JobExecutionClass::Site->value],
            ['id' => StudioContentAuthoringContextRetentionMigration::SCHEDULE_ID],
        );
        $database->insert($tables->raw('resource_site_ownership'), [
            'resource_type' => 'schedule',
            'resource_id' => StudioContentAuthoringContextRetentionMigration::SCHEDULE_ID,
            'site_identifier' => SiteContext::DEFAULT,
            'scope_level' => 'site',
            'group_identifier' => null,
        ]);
        foreach (
            [
                $retentionJobId => PurgeStudioContentAuthoringContextsHandler::JOB_TYPE,
                $unrelatedJobId => 'test.unrelated',
            ] as $jobId => $jobType
        ) {
            $database->insert($tables->raw('jobs'), [
                'id' => $jobId,
                'queue' => 'default',
                'job_type' => $jobType,
                'execution_scope' => JobExecutionClass::Site->value,
                'schema_version' => 1,
                'payload' => ['batch_size' => 100, 'maximum_batches' => 1],
                'priority' => 0,
                'status' => 'pending',
                'available_at' => $now,
                'attempts' => 0,
                'maximum_attempts' => 5,
                'created_at' => $now,
                'updated_at' => $now,
            ], [
                'id' => Types::GUID,
                'payload' => Types::JSON,
                'available_at' => Types::DATETIME_IMMUTABLE,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'updated_at' => Types::DATETIME_IMMUTABLE,
            ]);
            $database->insert($tables->raw('resource_site_ownership'), [
                'resource_type' => 'job',
                'resource_id' => $jobId,
                'site_identifier' => SiteContext::DEFAULT,
                'scope_level' => 'site',
                'group_identifier' => null,
            ]);
        }
        $retentionMigration = new StudioContentAuthoringContextRetentionMigration($tables);
        $retentionMigration->up($database);
        $retentionMigration->up($database);
        foreach (
            [
                'schedule' => StudioContentAuthoringContextRetentionMigration::SCHEDULE_ID,
                'job' => $retentionJobId,
            ] as $resourceType => $resourceId
        ) {
            $resourceTable = $resourceType === 'job' ? 'jobs' : 'schedules';
            self::assertSame(JobExecutionClass::Installation->value, $database->fetchOne(sprintf(
                'SELECT execution_scope FROM %s WHERE id = ?',
                $tables->quoted($resourceTable),
            ), [$resourceId]));
            self::assertFalse($database->fetchOne(sprintf(
                'SELECT site_identifier FROM %s WHERE resource_type = ? AND resource_id = ?',
                $tables->quoted('resource_site_ownership'),
            ), [$resourceType, $resourceId]));
        }
        self::assertSame(SiteContext::DEFAULT, $database->fetchOne(sprintf(
            'SELECT site_identifier FROM %s WHERE resource_type = ? AND resource_id = ?',
            $tables->quoted('resource_site_ownership'),
        ), ['schedule', '00000000-0000-7000-8000-000000000801']));
        self::assertSame(JobExecutionClass::Site->value, $database->fetchOne(sprintf(
            'SELECT execution_scope FROM %s WHERE id = ?',
            $tables->quoted('jobs'),
        ), [$unrelatedJobId]));
        self::assertSame(SiteContext::DEFAULT, $database->fetchOne(sprintf(
            'SELECT site_identifier FROM %s WHERE resource_type = ? AND resource_id = ?',
            $tables->quoted('resource_site_ownership'),
        ), ['job', $unrelatedJobId]));
        self::assertSame('1', (string) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE job_type = ?',
            $tables->quoted('schedules'),
        ), [PurgeStudioContentAuthoringContextsHandler::JOB_TYPE]));
        $database->delete($tables->raw('jobs'), ['id' => $retentionJobId], ['id' => Types::GUID]);
        self::assertFalse($database->fetchOne(sprintf(
            'SELECT site_identifier FROM %s WHERE resource_type = ? AND resource_id = ?',
            $tables->quoted('resource_site_ownership'),
        ), ['job', $retentionJobId]));
        $database->delete($tables->raw('resource_site_ownership'), [
            'resource_type' => 'job',
            'resource_id' => $unrelatedJobId,
        ]);
        $database->delete($tables->raw('jobs'), ['id' => $unrelatedJobId], ['id' => Types::GUID]);
        foreach (['ownership.scope.manage', 'reports.consolidated.read', 'sites.group.manage'] as $capability) {
            self::assertSame($capability, $database->fetchOne(sprintf(
                'SELECT code FROM %s WHERE code = ?',
                $tables->quoted('capabilities'),
            ), [$capability]));
        }
        if ($database->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $sitesIdentifier = $schema->introspectTable($tables->raw('sites'))->getColumn('identifier');
            $groupIdentifier = $schema->introspectTable($tables->raw('site_groups'))->getColumn('identifier');
            self::assertSame($sitesIdentifier->getCollation(), $groupIdentifier->getCollation());
            self::assertSame(
                $sitesIdentifier->getCollation(),
                $ownership->getColumn('group_identifier')->getCollation(),
            );
        }
    }

    /**
     * A fresh installation receives the global Studio-context retention schedule before replay begins.
     *
     * The shared integration database is migrated before this class starts, so its ordinary replay proof
     * cannot execute the first-install branch. A minimal isolated schema keeps that branch deterministic
     * while still exercising the real DBAL insert, JSON/date conversion, repair queries and postconditions.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStudioContextRetentionSeedsAFreshInstallationBeforeReplay(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tables = new TableNames($database, 'kumwe_');
        $schema = new Schema();
        $schedules = $schema->createTable($tables->raw('schedules'));
        $schedules->addColumn('id', Types::GUID);
        $schedules->addColumn('name', Types::STRING, ['length' => 160]);
        $schedules->addColumn('cron_expression', Types::STRING, ['length' => 120]);
        $schedules->addColumn('timezone', Types::STRING, ['length' => 80]);
        $schedules->addColumn('queue', Types::STRING, ['length' => 64, 'default' => 'default']);
        $schedules->addColumn('job_type', Types::STRING, ['length' => 128]);
        $schedules->addColumn('job_schema_version', Types::INTEGER, ['default' => 1]);
        $schedules->addColumn('payload', Types::JSON);
        $schedules->addColumn('priority', Types::SMALLINT, ['default' => 0]);
        $schedules->addColumn('maximum_attempts', Types::SMALLINT, ['default' => 5]);
        $schedules->addColumn('enabled', Types::BOOLEAN, ['default' => true]);
        $schedules->addColumn('next_run_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $schedules->addColumn('last_run_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $schedules->addColumn('version', Types::INTEGER, ['default' => 1]);
        $schedules->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $schedules->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $schedules->addColumn('execution_scope', Types::STRING, ['length' => 16, 'notnull' => false]);
        $schedules->setPrimaryKey(['id']);
        $schedules->addUniqueIndex(['name'], 'uniq_schedule_name');
        $schedules->addIndex(['enabled', 'next_run_at'], 'idx_schedule_due');
        $jobs = $schema->createTable($tables->raw('jobs'));
        $jobs->addColumn('id', Types::GUID);
        $jobs->addColumn('job_type', Types::STRING, ['length' => 128]);
        $jobs->addColumn('execution_scope', Types::STRING, ['length' => 16, 'notnull' => false]);
        $jobs->setPrimaryKey(['id']);
        $ownership = $schema->createTable($tables->raw('resource_site_ownership'));
        $ownership->addColumn('resource_type', Types::STRING, ['length' => 63]);
        $ownership->addColumn('resource_id', Types::STRING, ['length' => 191]);
        $ownership->addColumn('site_identifier', Types::STRING, ['length' => 191, 'notnull' => false]);
        $ownership->addColumn('scope_level', Types::STRING, ['length' => 16, 'default' => 'site']);
        $ownership->addColumn('group_identifier', Types::STRING, ['length' => 191, 'notnull' => false]);
        $ownership->setPrimaryKey(['resource_type', 'resource_id']);
        foreach ($schema->toSql($database->getDatabasePlatform()) as $statement) {
            $database->executeStatement($statement);
        }

        $migration = new StudioContentAuthoringContextRetentionMigration($tables);
        $migration->up($database);
        $seededSchedule = $database->fetchAssociative(sprintf(
            'SELECT id, cron_expression, payload FROM %s WHERE job_type = ?',
            $tables->quoted('schedules'),
        ), [PurgeStudioContentAuthoringContextsHandler::JOB_TYPE]);
        self::assertIsArray($seededSchedule);
        self::assertSame(StudioContentAuthoringContextRetentionMigration::SCHEDULE_ID, $seededSchedule['id']);
        self::assertSame('11 * * * *', $seededSchedule['cron_expression']);
        self::assertSame(
            ['batch_size' => 1_000, 'maximum_batches' => 10],
            json_decode((string) $seededSchedule['payload'], true, flags: JSON_THROW_ON_ERROR),
        );
        $operatorPayload = ['batch_size' => 250, 'maximum_batches' => 3];
        $database->update(
            $tables->raw('schedules'),
            [
                'cron_expression' => '7 3 * * *',
                'payload' => $operatorPayload,
                'execution_scope' => JobExecutionClass::Site->value,
            ],
            ['id' => StudioContentAuthoringContextRetentionMigration::SCHEDULE_ID],
            ['payload' => Types::JSON],
        );
        $database->insert($tables->raw('resource_site_ownership'), [
            'resource_type' => 'schedule',
            'resource_id' => StudioContentAuthoringContextRetentionMigration::SCHEDULE_ID,
            'site_identifier' => SiteContext::DEFAULT,
            'scope_level' => 'site',
            'group_identifier' => null,
        ]);
        $migration->up($database);

        $schedule = $database->fetchAssociative(sprintf(
            'SELECT id, cron_expression, payload, enabled, next_run_at, created_at, execution_scope '
            . 'FROM %s WHERE job_type = ?',
            $tables->quoted('schedules'),
        ), [PurgeStudioContentAuthoringContextsHandler::JOB_TYPE]);
        self::assertIsArray($schedule);
        self::assertSame(StudioContentAuthoringContextRetentionMigration::SCHEDULE_ID, $schedule['id']);
        self::assertSame('7 3 * * *', $schedule['cron_expression']);
        self::assertSame('1', (string) $schedule['enabled']);
        self::assertSame(JobExecutionClass::Installation->value, $schedule['execution_scope']);
        self::assertSame(
            $operatorPayload,
            json_decode((string) $schedule['payload'], true, flags: JSON_THROW_ON_ERROR),
        );
        self::assertSame(
            3_600,
            (new DateTimeImmutable((string) $schedule['next_run_at']))->getTimestamp()
                - (new DateTimeImmutable((string) $schedule['created_at']))->getTimestamp(),
        );
        self::assertSame('1', (string) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE job_type = ?',
            $tables->quoted('schedules'),
        ), [PurgeStudioContentAuthoringContextsHandler::JOB_TYPE]));
        self::assertSame('0', (string) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE resource_type = ? AND resource_id = ?',
            $tables->quoted('resource_site_ownership'),
        ), ['schedule', StudioContentAuthoringContextRetentionMigration::SCHEDULE_ID]));
    }

    /**
     * The homepage the migration seeds validates against the page schema the same migration publishes.
     *
     * Both halves are written by hand in one file, so nothing but this check keeps them in step: a key
     * added to the seed but not to the schema, or a string grown past a declared bound, would install a
     * homepage the content model itself rejects the moment an editor opens and saves it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheSeededHomepageSatisfiesThePageSchemaTheSameMigrationPublishes(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(TableNames::class, $tables);
        $migration = new DynamicSiteContentMigration($tables);

        /** @var array<string, mixed> $schema */
        $schema = (new ReflectionMethod($migration, 'pageSchema'))->invoke($migration);
        /** @var array<string, mixed> $data */
        $data = (new ReflectionMethod($migration, 'homepageData'))->invoke($migration);

        /** @var array<string, mixed> $properties */
        $properties = $schema['properties'] ?? [];
        /** @var list<string> $required */
        $required = $schema['required'] ?? [];
        self::assertFalse($schema['additionalProperties'] ?? true);
        self::assertSame([], array_diff(array_keys($data), array_keys($properties)));
        self::assertSame([], array_diff($required, array_keys($data)));

        foreach ($properties as $key => $property) {
            if (!is_array($property) || !isset($data[$key], $property['maxLength'])) {
                continue;
            }
            $value = $data[$key];
            self::assertIsString($value);
            self::assertLessThanOrEqual(
                $property['maxLength'],
                mb_strlen($value),
                sprintf('The seeded homepage overflows the declared bound for %s.', $key),
            );
        }
    }

    public function testBusinessSecuritySiteForeignKeyUsesTheExistingMariaDbCollation(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);
        if (!$database->getDatabasePlatform() instanceof MariaDBPlatform) {
            self::markTestSkipped('This regression exercises MariaDB textual foreign-key collation equality.');
        }

        $prefix = 'c' . bin2hex(random_bytes(5)) . '_';
        $tables = new TableNames($database, $prefix);
        $sites = $tables->quoted('sites');
        $organizations = $tables->quoted('organizations');
        $database->executeStatement(sprintf(
            'CREATE TABLE %s (identifier VARCHAR(191) CHARACTER SET utf8mb4 '
            . 'COLLATE utf8mb4_unicode_ci NOT NULL PRIMARY KEY) ENGINE = InnoDB',
            $sites,
        ));

        try {
            $manager = $database->createSchemaManager();
            $migration = new BusinessSecurityPortalMigration($tables);
            $siteIdentifier = $manager->introspectTableByUnquotedName(
                $tables->raw('sites'),
            )->getColumn('identifier');
            /** @var array<string, mixed> $siteIdentifierOptions */
            $siteIdentifierOptions = (new ReflectionMethod($migration, 'siteIdentifierOptions'))
                ->invoke($migration, $siteIdentifier);
            /** @var list<Table> $definitions */
            $definitions = (new ReflectionMethod($migration, 'tables'))
                ->invoke($migration, $siteIdentifierOptions);
            $organizationDefinition = null;
            foreach ($definitions as $definition) {
                if ($definition->getObjectName()->getUnqualifiedName()->getValue() === $tables->raw('organizations')) {
                    $organizationDefinition = $definition;
                    break;
                }
            }
            self::assertInstanceOf(Table::class, $organizationDefinition);

            $manager->createTable($organizationDefinition);

            $created = $manager->introspectTableByUnquotedName($tables->raw('organizations'));
            self::assertSame(
                $siteIdentifier->getCollation(),
                $created->getColumn('site_identifier')->getCollation(),
            );
            self::assertNotEmpty($created->getForeignKeys());
        } finally {
            $database->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $organizations));
            $database->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $sites));
        }
    }

    public function testBusinessSecurityMigrationBackfillsExistingRecordOwnership(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        $records = $container->get(BusinessRecordService::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        $id = Uuid::uuid7()->toString();
        $marker = substr(str_replace('-', '', $id), 0, 10);
        $definition = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::document('migration' . $marker, $id),
        );
        $created = $records->create(new CreateRecordCommand(
            $context,
            $definition->handle,
            NeutralBusinessFixture::recordValues('Migration ownership backfill'),
            NeutralBusinessFixture::idempotencyKey('migration-' . $marker),
            recordId: Uuid::uuid7()->toString(),
        ));
        $resourceId = $definition->id . ':' . $created->recordKey;
        $database->delete($tables->raw('resource_site_ownership'), [
            'resource_type' => 'business_record',
            'resource_id' => $resourceId,
        ]);
        self::assertFalse($database->fetchOne(sprintf(
            'SELECT site_identifier FROM %s WHERE resource_type = ? AND resource_id = ?',
            $tables->quoted('resource_site_ownership'),
        ), ['business_record', $resourceId]));

        (new BusinessSecurityPortalMigration($tables))->up($database);

        self::assertSame(SiteContext::DEFAULT, $database->fetchOne(sprintf(
            'SELECT site_identifier FROM %s WHERE resource_type = ? AND resource_id = ?',
            $tables->quoted('resource_site_ownership'),
        ), ['business_record', $resourceId]));
    }

    public function testMigrationLockSurvivesDdlAndRejectsASecondDatabaseSession(): void
    {
        $container = (new ContainerFactory())->create(Environment::fromGlobals());
        $database = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);
        $secondary = DriverManager::getConnection($database->getParams());
        $secondary->executeStatement(
            $database->getDatabasePlatform() instanceof AbstractMySQLPlatform
                ? "SET time_zone = '+00:00'"
                : "SET TIME ZONE 'UTC'",
        );
        $prefix = 'l' . bin2hex(random_bytes(5)) . '_';
        $primaryTables = new TableNames($database, $prefix);
        $secondaryTables = new TableNames($secondary, $prefix);
        $primaryLock = new DoctrineMigrationLock($database, $primaryTables);
        $secondaryLock = new DoctrineMigrationLock($secondary, $secondaryTables);
        $probeName = $primaryTables->raw('ddl_probe');

        try {
            $primaryLock->synchronized(function () use (
                $database,
                $secondaryLock,
                $probeName,
            ): void {
                $this->assertSecondMigrationSessionIsBlocked($secondaryLock);

                $probe = new Table($probeName);
                $probe->addColumn('id', Types::INTEGER);
                $probe->setPrimaryKey(['id']);
                $database->createSchemaManager()->createTable($probe);

                $this->assertSecondMigrationSessionIsBlocked($secondaryLock);
            });

            self::assertSame('acquired', $secondaryLock->synchronized(static fn (): string => 'acquired'));
        } finally {
            $schema = $database->createSchemaManager();
            foreach ([$probeName, $primaryTables->raw('migration_locks')] as $table) {
                if ($schema->tablesExist([$table])) {
                    $schema->dropTable($table);
                }
            }
            $secondary->close();
        }
    }

    public function testExpiredLegacyMigrationOwnerRecoveryRequiresTheExactToken(): void
    {
        $container = (new ContainerFactory())->create(Environment::fromGlobals());
        $database = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);
        $prefix = 'k' . bin2hex(random_bytes(5)) . '_';
        $tables = new TableNames($database, $prefix);
        $lock = new DoctrineMigrationLock($database, $tables);
        $owner = str_repeat('a', 64);

        try {
            $lock->synchronized(static fn (): null => null);
            $database->insert($tables->raw('migration_locks'), [
                'lock_name' => 'core-migrations',
                'owner_token' => $owner,
                'acquired_at' => new DateTimeImmutable('-1 hour'),
                'expires_at' => new DateTimeImmutable('+1 hour'),
            ], [
                'acquired_at' => Types::DATETIME_IMMUTABLE,
                'expires_at' => Types::DATETIME_IMMUTABLE,
            ]);

            try {
                $lock->synchronized(static fn (): null => null);
                self::fail('A legacy migration owner must block the advisory-lock bridge.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('legacy migration owner is present', $exception->getMessage());
            }
            try {
                $lock->recoverExpiredLegacyOwner($owner);
                self::fail('An active legacy migration owner must not be recoverable.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('has not expired', $exception->getMessage());
            }
            $database->update(
                $tables->raw('migration_locks'),
                ['expires_at' => new DateTimeImmutable('-1 hour')],
                ['lock_name' => 'core-migrations', 'owner_token' => $owner],
                ['expires_at' => Types::DATETIME_IMMUTABLE],
            );
            try {
                $lock->recoverExpiredLegacyOwner(str_repeat('b', 64));
                self::fail('Recovery must compare-and-delete the exact expected owner token.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('owner changed or no longer exists', $exception->getMessage());
            }

            $lock->recoverExpiredLegacyOwner($owner);
            self::assertSame('recovered', $lock->synchronized(static fn (): string => 'recovered'));
        } finally {
            $schema = $database->createSchemaManager();
            $table = $tables->raw('migration_locks');
            if ($schema->tablesExist([$table])) {
                $schema->dropTable($table);
            }
        }
    }

    /**
     * The token lifecycle migration backfills legacy API tokens from the user row, not a column default.
     *
     * A pre-lifecycle `api_tokens` row carries no audience, purpose, site or epoch and may carry no expiry.
     * Running the migration on that exact shape must stamp the `kumwe-http`, `api` and `default` values,
     * copy the subject's non-default `security_epoch`, give an open-ended token a bounded expiry while
     * leaving an explicit one untouched, make `expires_at` mandatory, and stay a no-op when run again. The
     * tables live under a random prefix and are dropped afterwards, so a reused database stays clean.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTokenLifecycleMigrationBackfillsLegacyApiTokensFromTheUserSecurityEpoch(): void
    {
        $container = (new ContainerFactory())->create(Environment::fromGlobals());
        $database = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);
        $prefix = 'u' . bin2hex(random_bytes(4)) . '_';
        $tables = new TableNames($database, $prefix);
        $tokenColumns = 'SELECT security_epoch, audience, purpose, site_identifier, expires_at FROM %s WHERE id = ?';
        try {
            $this->createPreLifecycleTokenSchema($database, $tables);
            $userId = Uuid::uuid7()->toString();
            $openEndedTokenId = Uuid::uuid7()->toString();
            $boundedTokenId = Uuid::uuid7()->toString();
            $explicitExpiry = (new DateTimeImmutable('+400 days'))->setTime(12, 30, 0);
            $database->insert($tables->raw('users'), ['id' => $userId, 'status' => 'active', 'security_epoch' => 7]);
            $database->insert($tables->raw('api_tokens'), [
                'id' => $openEndedTokenId,
                'subject_id' => $userId,
                'expires_at' => null,
                'revoked_at' => null,
            ]);
            $database->insert($tables->raw('api_tokens'), [
                'id' => $boundedTokenId,
                'subject_id' => $userId,
                'expires_at' => $explicitExpiry,
                'revoked_at' => null,
            ], ['expires_at' => Types::DATETIME_IMMUTABLE]);

            (new TokenAndTrustLifecycleMigration($tables))->up($database);

            $openEnded = $database->fetchAssociative(
                sprintf($tokenColumns, $tables->quoted('api_tokens')),
                [$openEndedTokenId],
            );
            self::assertIsArray($openEnded);
            self::assertSame('7', (string) $openEnded['security_epoch']);
            self::assertSame('kumwe-http', $openEnded['audience']);
            self::assertSame('api', $openEnded['purpose']);
            self::assertSame('default', $openEnded['site_identifier']);
            $backfilledExpiry = new DateTimeImmutable((string) $openEnded['expires_at']);
            self::assertGreaterThan(new DateTimeImmutable('+29 days'), $backfilledExpiry);
            self::assertLessThanOrEqual(new DateTimeImmutable('+31 days'), $backfilledExpiry);

            $bounded = $database->fetchAssociative(
                sprintf($tokenColumns, $tables->quoted('api_tokens')),
                [$boundedTokenId],
            );
            self::assertIsArray($bounded);
            self::assertSame('7', (string) $bounded['security_epoch']);
            self::assertSame('kumwe-http', $bounded['audience']);
            self::assertSame(
                $explicitExpiry->format('Y-m-d H:i:s'),
                (new DateTimeImmutable((string) $bounded['expires_at']))->format('Y-m-d H:i:s'),
            );
            self::assertTrue(
                $database->createSchemaManager()
                    ->introspectTableByUnquotedName($tables->raw('api_tokens'))
                    ->getColumn('expires_at')
                    ->getNotnull(),
                'The migration must make api_tokens.expires_at mandatory once every row carries one.',
            );

            (new TokenAndTrustLifecycleMigration($tables))->up($database);

            self::assertSame($openEnded, $database->fetchAssociative(
                sprintf($tokenColumns, $tables->quoted('api_tokens')),
                [$openEndedTokenId],
            ));
            self::assertSame('1', (string) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s',
                $tables->quoted('extension_trust_generation'),
            )));
        } finally {
            $schema = $database->createSchemaManager();
            foreach (
                [
                    'api_tokens',
                    'users',
                    'extension_releases',
                    'extension_trust_keys',
                    'extension_runtime_outbox',
                    'extension_trust_generation',
                    'extension_runtime_generation',
                    'idempotency',
                ] as $table
            ) {
                $name = $tables->raw($table);
                if ($schema->tablesExist([$name])) {
                    $schema->dropTable($name);
                }
            }
        }
    }

    /**
     * Create the pre-lifecycle table shapes the token and trust migration upgrades in place.
     *
     * Only the tables the migration touches are created: `users` already carries `security_epoch` so the
     * backfill must read the row rather than add the column, `api_tokens` has the pre-lifecycle columns
     * alone, and `extension_trust_generation` exists without its lifecycle column or singleton row to
     * mirror interrupted DDL.
     *
     * @param   Connection  $database  Live connection the tables are created on.
     * @param   TableNames  $tables    Prefixed naming for this test's isolated tables.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function createPreLifecycleTokenSchema(Connection $database, TableNames $tables): void
    {
        $schema = new Schema();
        $users = $schema->createTable($tables->raw('users'));
        $users->addColumn('id', Types::GUID);
        $users->addColumn('status', Types::STRING, ['length' => 32]);
        $users->addColumn('security_epoch', Types::BIGINT, ['default' => 1]);
        $users->setPrimaryKey(['id']);
        $tokens = $schema->createTable($tables->raw('api_tokens'));
        $tokens->addColumn('id', Types::GUID);
        $tokens->addColumn('subject_id', Types::GUID);
        $tokens->addColumn('expires_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $tokens->addColumn('revoked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $tokens->setPrimaryKey(['id']);
        $releases = $schema->createTable($tables->raw('extension_releases'));
        $releases->addColumn('id', Types::GUID);
        $releases->addColumn('extension_id', Types::GUID);
        $releases->addColumn('version', Types::STRING, ['length' => 128]);
        $releases->addColumn('manifest', Types::JSON);
        $releases->addColumn('package_sha256', Types::STRING, ['length' => 64]);
        $releases->addColumn('released_at', Types::DATETIME_IMMUTABLE);
        $releases->setPrimaryKey(['id']);
        $keys = $schema->createTable($tables->raw('extension_trust_keys'));
        $keys->addColumn('key_id', Types::STRING, ['length' => 127]);
        $keys->addColumn('algorithm', Types::STRING, ['length' => 32]);
        $keys->addColumn('public_key_base64', Types::STRING, ['length' => 128]);
        $keys->addColumn('enabled', Types::BOOLEAN);
        $keys->addColumn('added_at', Types::DATETIME_IMMUTABLE);
        $keys->addColumn('revoked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $keys->setPrimaryKey(['key_id']);
        $generation = $schema->createTable($tables->raw('extension_runtime_generation'));
        $generation->addColumn('singleton_key', Types::SMALLINT);
        $generation->addColumn('generation', Types::BIGINT);
        $generation->addColumn('rebuilt_at', Types::DATETIME_IMMUTABLE);
        $generation->setPrimaryKey(['singleton_key']);
        $trustGeneration = $schema->createTable($tables->raw('extension_trust_generation'));
        $trustGeneration->addColumn('singleton_key', Types::SMALLINT);
        $trustGeneration->addColumn('generation', Types::BIGINT, ['default' => 0]);
        $trustGeneration->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $trustGeneration->setPrimaryKey(['singleton_key']);
        $idempotency = $schema->createTable($tables->raw('idempotency'));
        $idempotency->addColumn('id', Types::GUID);
        $idempotency->addColumn('idempotency_key', Types::STRING, ['length' => 128]);
        $idempotency->addColumn('subject', Types::STRING, ['length' => 255]);
        $idempotency->addColumn('operation', Types::STRING, ['length' => 160]);
        $idempotency->addColumn('request_digest', Types::STRING, ['length' => 64]);
        $idempotency->addColumn('state', Types::STRING, ['length' => 16]);
        $idempotency->addColumn('result_body', Types::TEXT, ['notnull' => false]);
        $idempotency->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $idempotency->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $idempotency->setPrimaryKey(['id']);
        foreach ($schema->toSql($database->getDatabasePlatform()) as $statement) {
            $database->executeStatement($statement);
        }
        $database->insert($tables->raw('extension_runtime_generation'), [
            'singleton_key' => 1,
            'generation' => 0,
            'rebuilt_at' => new DateTimeImmutable(),
        ], ['rebuilt_at' => Types::DATETIME_IMMUTABLE]);
    }

    private function assertSecondMigrationSessionIsBlocked(DoctrineMigrationLock $lock): void
    {
        try {
            $lock->synchronized(static fn (): null => null);
            self::fail('A concurrent migration database session must not acquire the lock.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('already running database migrations', $exception->getMessage());
        }
    }
}
