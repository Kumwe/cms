<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessSchema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\TransactionIsolationLevel;
use Doctrine\DBAL\Types\Types;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\BusinessDefinition\Application\PackageDefinitionSynchronizer;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Infrastructure\Persistence\DoctrinePackageDefinitionSynchronizer;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaConflict;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaExecutionLock;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaExecutionStateGuard;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaInstallationRepository;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaPlanRepository;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\App\BusinessSchema\Application\PhysicalSchemaGateway;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallation;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\App\BusinessSchema\Domain\SchemaPlanStatus;
use Kumwe\App\BusinessSchema\Infrastructure\Execution\DoctrineBusinessSchemaExecutionStateGuard;
use Kumwe\App\BusinessSchema\Infrastructure\Persistence\DoctrineBusinessSchemaInstallationRepository;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

#[CoversClass(BusinessSchemaService::class)]
#[CoversClass(DoctrinePackageDefinitionSynchronizer::class)]
#[CoversClass(DoctrineBusinessSchemaExecutionStateGuard::class)]
#[CoversClass(DoctrineBusinessSchemaInstallationRepository::class)]
#[CoversClass(SchemaInstallation::class)]
final class BusinessSchemaExecutionStateGuardIntegrationTest extends TestCase
{
    public function testDisabledUpgradeCannotReactivateBeforeRecoveryThenRemainsPreserved(): void
    {
        $environment = Environment::fromGlobals();
        $primary = TestKernelFactory::create($environment);
        $context = TestKernelFactory::administratorContext($primary);
        $synchronizer = $primary->get(PackageDefinitionSynchronizer::class);
        $transactions = $primary->get(TransactionManager::class);
        $schemas = $primary->get(BusinessSchemaService::class);
        self::assertInstanceOf(PackageDefinitionSynchronizer::class, $synchronizer);
        self::assertInstanceOf(TransactionManager::class, $transactions);
        self::assertInstanceOf(BusinessSchemaService::class, $schemas);

        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
        $owner = 'testing/lifecycle_' . $suffix;
        $definition = EntityTypeDefinition::fromArray(self::extensionDocument(
            Uuid::uuid7()->toString(),
            $owner,
        ));
        $transactions->transactional(static function () use (
            $synchronizer,
            $owner,
            $context,
            $definition,
        ): void {
            $synchronizer->synchronize(
                $owner,
                '1.0.0',
                $context->site(),
                [],
                [$definition],
                true,
                $context->actorId(),
            );
        });
        $plan = $schemas->createPlan($context, $definition->id);
        if ($plan->status === SchemaPlanStatus::PendingApproval) {
            $confirmation = $plan->risk->requiresHighImpactAuthorization() ? $plan->checksum() : null;
            $plan = $schemas->approve($context, $plan->id, $plan->checksum(), $confirmation, null);
        }
        self::assertSame(SchemaPlanStatus::Approved, $plan->status);
        $schemas->execute($context, $plan->id);

        $installations = $primary->get(BusinessSchemaInstallationRepository::class);
        $physical = $primary->get(PhysicalSchemaGateway::class);
        self::assertInstanceOf(BusinessSchemaInstallationRepository::class, $installations);
        self::assertInstanceOf(PhysicalSchemaGateway::class, $physical);
        $active = $installations->find($definition->id);
        self::assertNotNull($active);
        self::assertSame(SchemaInstallationStatus::Active, $active->status);

        $transactions->transactional(static function () use (
            $synchronizer,
            $owner,
        ): void {
            $synchronizer->setActive($owner, false, 'integration:lifecycle-disable');
        });
        $disabled = $installations->find($definition->id);
        self::assertNotNull($disabled);
        self::assertSame(SchemaInstallationStatus::Disabled, $disabled->status);
        self::assertSame($active->schemaChecksum, $disabled->schemaChecksum);

        $v2 = EntityTypeDefinition::fromArray(self::extensionDocument(
            $definition->id,
            $owner,
            2,
            true,
        ));
        $transactions->transactional(static function () use (
            $synchronizer,
            $owner,
            $context,
            $v2,
        ): void {
            $synchronizer->synchronize(
                $owner,
                '2.0.0',
                $context->site(),
                [],
                [$v2],
                false,
                $context->actorId(),
            );
        });
        $upgrade = $schemas->createPlan($context, $definition->id);
        self::assertSame(SchemaPlanStatus::PendingApproval, $upgrade->status);
        $confirmation = $upgrade->risk->requiresHighImpactAuthorization() ? $upgrade->checksum() : null;
        $approved = $schemas->approve(
            $context,
            $upgrade->id,
            $upgrade->checksum(),
            $confirmation,
            null,
        );
        self::assertSame(SchemaPlanStatus::Approved, $approved->status);
        $plans = $primary->get(BusinessSchemaPlanRepository::class);
        $lock = $primary->get(BusinessSchemaExecutionLock::class);
        $clock = $primary->get(ClockInterface::class);
        self::assertInstanceOf(BusinessSchemaPlanRepository::class, $plans);
        self::assertInstanceOf(BusinessSchemaExecutionLock::class, $lock);
        self::assertInstanceOf(ClockInterface::class, $clock);
        $lock->synchronized($definition->id, function (int $fence) use (
            $plans,
            $context,
            $approved,
            $clock,
        ): void {
            $current = $plans->find($context->site(), $approved->id);
            self::assertNotNull($current);
            $executing = $current->begin($fence, $clock->now());
            $plans->replace($executing, $current->revision);
            $interrupted = $executing->recoveryRequired(
                'before_first_operation',
                ['fence' => $fence],
                $clock->now(),
            );
            $plans->replace($interrupted, $executing->revision, $fence);
        });
        $installations->save($disabled->preserve($clock->now()));
        $v3Document = self::extensionDocument($definition->id, $owner, 3, true);
        $v3Document['fields'][] = [
            'handle' => 'future_note',
            'label' => 'Future note',
            'type' => 'core.text',
            'length' => 120,
        ];
        $v3 = EntityTypeDefinition::fromArray($v3Document);
        $transactions->transactional(static function () use (
            $synchronizer,
            $owner,
            $context,
            $v3,
        ): void {
            $synchronizer->synchronize(
                $owner,
                '3.0.0',
                $context->site(),
                [],
                [$v3],
                false,
                $context->actorId(),
            );
        });
        $latest = $schemas->createPlan($context, $definition->id);
        self::assertSame(SchemaPlanStatus::PendingApproval, $latest->status);
        self::assertSame(3, $latest->toDefinitionVersion);
        try {
            $transactions->transactional(static function () use ($synchronizer, $owner): void {
                $synchronizer->setActive($owner, true, 'integration:premature-reactivate');
            });
            self::fail('An owner cannot reactivate while its schema plan requires recovery.');
        } catch (BusinessSchemaConflict $exception) {
            self::assertStringContainsString('recovery is incomplete', $exception->getMessage());
        }
        self::assertSame(
            SchemaInstallationStatus::Preserved,
            $installations->find($definition->id)?->status,
        );

        $outcome = $schemas->recover($context, $approved->id);
        self::assertTrue($outcome->resumed);

        $preserved = $installations->find($definition->id);
        self::assertNotNull($preserved);
        self::assertSame(SchemaInstallationStatus::Preserved, $preserved->status);
        self::assertSame(2, $preserved->definitionVersion);
        self::assertSame($preserved->schemaChecksum, $physical->inspect($preserved->blueprint)?->checksum());
        self::assertSame(SchemaPlanStatus::Completed, $schemas->plan($context, $approved->id)->status);

        $transactions->transactional(static function () use ($synchronizer, $owner): void {
            $synchronizer->setActive($owner, true, 'integration:lifecycle-reactivate');
        });
        self::assertSame(
            SchemaInstallationStatus::Active,
            $installations->find($definition->id)?->status,
        );
    }

    public function testFinalizationLocksOwnerAndInstallationAgainstConcurrentDisable(): void
    {
        $environment = Environment::fromGlobals();
        $primary = TestKernelFactory::create($environment);
        $secondary = TestKernelFactory::create($environment);
        $context = TestKernelFactory::administratorContext($primary);
        $database = $primary->get(Connection::class);
        $concurrent = $secondary->get(Connection::class);
        $tables = $primary->get(TableNames::class);
        $guard = $primary->get(BusinessSchemaExecutionStateGuard::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(Connection::class, $concurrent);
        self::assertInstanceOf(TableNames::class, $tables);
        self::assertInstanceOf(BusinessSchemaExecutionStateGuard::class, $guard);

        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
        $definition = NeutralBusinessFixture::install(
            $primary,
            $context,
            NeutralBusinessFixture::document($suffix, Uuid::uuid7()->toString()),
        );
        $concurrent->executeStatement(
            $database->getDatabasePlatform() instanceof AbstractMySQLPlatform
                ? 'SET innodb_lock_wait_timeout = 1'
                : "SET lock_timeout = '500ms'",
        );

        try {
            $database->beginTransaction();
            $guard->lockOwner($context->site(), $definition->id, 'default', true);
            self::assertSame(
                SchemaInstallationStatus::Active,
                $guard->lockInstallationStatus($definition->id),
            );

            try {
                $concurrent->update(
                    $tables->raw('business_definitions'),
                    ['owner_active' => false],
                    ['id' => $definition->id],
                    ['owner_active' => Types::BOOLEAN],
                );
                self::fail('Concurrent owner disable must wait for schema finalization.');
            } catch (DbalException) {
                self::assertTrue($database->isTransactionActive());
            }

            try {
                $concurrent->update(
                    $tables->raw('business_schema_installations'),
                    ['status' => SchemaInstallationStatus::Disabled->value],
                    ['definition_id' => $definition->id],
                );
                self::fail('Concurrent installation disable must wait for schema finalization.');
            } catch (DbalException) {
                self::assertTrue($database->isTransactionActive());
            }

            self::assertContains(
                $concurrent->fetchOne(sprintf(
                    'SELECT owner_active FROM %s WHERE id = ?',
                    $tables->quoted('business_definitions'),
                ), [$definition->id]),
                [true, 1, '1'],
            );
            self::assertSame(
                SchemaInstallationStatus::Active->value,
                $concurrent->fetchOne(sprintf(
                    'SELECT status FROM %s WHERE definition_id = ?',
                    $tables->quoted('business_schema_installations'),
                ), [$definition->id]),
            );
        } finally {
            if ($database->isTransactionActive()) {
                $database->rollBack();
            }
            $concurrent->close();
        }
    }

    public function testLifecycleDisableReloadsTheCurrentInstallationAfterAStaleSnapshot(): void
    {
        $environment = Environment::fromGlobals();
        $primary = TestKernelFactory::create($environment);
        $context = TestKernelFactory::administratorContext($primary);
        $synchronizer = $primary->get(PackageDefinitionSynchronizer::class);
        $transactions = $primary->get(TransactionManager::class);
        $schemas = $primary->get(BusinessSchemaService::class);
        $installations = $primary->get(BusinessSchemaInstallationRepository::class);
        $database = $primary->get(Connection::class);
        self::assertInstanceOf(PackageDefinitionSynchronizer::class, $synchronizer);
        self::assertInstanceOf(TransactionManager::class, $transactions);
        self::assertInstanceOf(BusinessSchemaService::class, $schemas);
        self::assertInstanceOf(BusinessSchemaInstallationRepository::class, $installations);
        self::assertInstanceOf(Connection::class, $database);

        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
        $owner = 'testing/stale_lifecycle_' . $suffix;
        $definition = EntityTypeDefinition::fromArray(self::extensionDocument(
            Uuid::uuid7()->toString(),
            $owner,
        ));
        $transactions->transactional(static function () use (
            $synchronizer,
            $owner,
            $context,
            $definition,
        ): void {
            $synchronizer->synchronize(
                $owner,
                '1.0.0',
                $context->site(),
                [],
                [$definition],
                true,
                $context->actorId(),
            );
        });
        $plan = $schemas->createPlan($context, $definition->id);
        if ($plan->status === SchemaPlanStatus::PendingApproval) {
            $confirmation = $plan->risk->requiresHighImpactAuthorization() ? $plan->checksum() : null;
            $plan = $schemas->approve($context, $plan->id, $plan->checksum(), $confirmation, null);
        }
        $schemas->execute($context, $plan->id);

        $secondary = TestKernelFactory::create($environment);
        $secondaryContext = TestKernelFactory::administratorContext($secondary);
        $secondarySynchronizer = $secondary->get(PackageDefinitionSynchronizer::class);
        $secondaryTransactions = $secondary->get(TransactionManager::class);
        $secondarySchemas = $secondary->get(BusinessSchemaService::class);
        $secondaryInstallations = $secondary->get(BusinessSchemaInstallationRepository::class);
        $secondaryDatabase = $secondary->get(Connection::class);
        self::assertInstanceOf(PackageDefinitionSynchronizer::class, $secondarySynchronizer);
        self::assertInstanceOf(TransactionManager::class, $secondaryTransactions);
        self::assertInstanceOf(BusinessSchemaService::class, $secondarySchemas);
        self::assertInstanceOf(BusinessSchemaInstallationRepository::class, $secondaryInstallations);
        self::assertInstanceOf(Connection::class, $secondaryDatabase);

        try {
            $database->setTransactionIsolation(TransactionIsolationLevel::READ_COMMITTED);
            $database->beginTransaction();
            self::assertSame(1, $installations->find($definition->id)?->definitionVersion);

            $v2 = EntityTypeDefinition::fromArray(self::extensionDocument(
                $definition->id,
                $owner,
                2,
                true,
            ));
            $secondaryTransactions->transactional(static function () use (
                $secondarySynchronizer,
                $owner,
                $secondaryContext,
                $v2,
            ): void {
                $secondarySynchronizer->synchronize(
                    $owner,
                    '2.0.0',
                    $secondaryContext->site(),
                    [],
                    [$v2],
                    true,
                    $secondaryContext->actorId(),
                );
            });
            $upgrade = $secondarySchemas->createPlan($secondaryContext, $definition->id);
            if ($upgrade->status === SchemaPlanStatus::PendingApproval) {
                $confirmation = $upgrade->risk->requiresHighImpactAuthorization()
                    ? $upgrade->checksum()
                    : null;
                $upgrade = $secondarySchemas->approve(
                    $secondaryContext,
                    $upgrade->id,
                    $upgrade->checksum(),
                    $confirmation,
                    null,
                );
            }
            $secondarySchemas->execute($secondaryContext, $upgrade->id);
            $current = $secondaryInstallations->find($definition->id);
            self::assertNotNull($current);
            self::assertSame(2, $current->definitionVersion);

            $synchronizer->setActive($owner, false, 'integration:stale-snapshot-disable');
            $database->commit();

            $disabled = $secondaryInstallations->find($definition->id);
            self::assertNotNull($disabled);
            self::assertSame(2, $disabled->definitionVersion);
            self::assertSame($current->schemaChecksum, $disabled->schemaChecksum);
            self::assertSame(SchemaInstallationStatus::Disabled, $disabled->status);
        } finally {
            if ($database->isTransactionActive()) {
                $database->rollBack();
            }
            $secondaryDatabase->close();
        }
    }

    /** @return array<string, mixed> */
    private static function extensionDocument(
        string $id,
        string $owner,
        int $version = 1,
        bool $withNote = false,
    ): array {
        $fields = [[
            'handle' => 'id',
            'label' => 'ID',
            'type' => 'core.uuid',
            'required' => true,
            'nullable' => false,
            'unique' => true,
            'indexed' => true,
            'immutable_after_create' => true,
            'server_only' => true,
            'read_only' => true,
        ]];
        if ($withNote) {
            $fields[] = [
                'handle' => 'note',
                'label' => 'Note',
                'type' => 'core.text',
                'length' => 120,
            ];
        }
        $handle = str_replace('/', '.', $owner) . '.lifecycle_fixture';

        return [
            'id' => $id,
            'owner' => ['type' => 'extension', 'identifier' => $owner],
            'site' => 'default',
            'handle' => $handle,
            'singular_label' => 'Lifecycle fixture',
            'plural_label' => 'Lifecycle fixtures',
            'status' => 'published',
            'definition_version' => $version,
            'storage_mode' => 'relational',
            'identity_strategy' => 'uuid',
            'scope' => 'site',
            'audit_enabled' => true,
            'revisions_enabled' => true,
            'fields' => $fields,
            'relationships' => [[
                'handle' => 'peers',
                'label' => 'Peers',
                'kind' => 'many_to_many',
                'target' => $handle,
                'on_delete' => 'restrict',
            ]],
            'views' => [],
            'actions' => [],
            'workflow' => null,
            'compatibility_metadata' => [],
        ];
    }
}
