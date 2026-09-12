<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessSchema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionService;
use Kumwe\App\BusinessDefinition\Application\PackageDefinitionSynchronizer;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaConflict;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaExecutionLock;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaPlanRepository;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\App\BusinessSchema\Application\DefinitionPhysicalSchemaCompiler;
use Kumwe\App\BusinessSchema\Application\PhysicalSchemaGateway;
use Kumwe\App\BusinessSchema\Domain\PhysicalTableBlueprint;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\App\BusinessSchema\Domain\SchemaOperation;
use Kumwe\App\BusinessSchema\Domain\SchemaOperationKind;
use Kumwe\App\BusinessSchema\Domain\SchemaPlan;
use Kumwe\App\BusinessSchema\Domain\SchemaPlanStatus;
use Kumwe\App\BusinessSchema\Domain\SchemaPlanStep;
use Kumwe\App\BusinessSchema\Domain\SchemaStepStatus;
use Kumwe\App\BusinessSchema\Infrastructure\Execution\DoctrineBusinessSchemaExecutionLock;
use Kumwe\App\BusinessSchema\Infrastructure\Persistence\DoctrineBusinessSchemaPlanRepository;
use Kumwe\App\BusinessSchema\Infrastructure\Schema\CanonicalDefinitionPhysicalSchemaCompiler;
use Kumwe\App\BusinessSchema\Infrastructure\Schema\DoctrinePhysicalSchemaGateway;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

#[CoversClass(BusinessSchemaService::class)]
#[CoversClass(DoctrineBusinessSchemaExecutionLock::class)]
#[CoversClass(DoctrineBusinessSchemaPlanRepository::class)]
#[CoversClass(CanonicalDefinitionPhysicalSchemaCompiler::class)]
#[CoversClass(DoctrinePhysicalSchemaGateway::class)]
#[CoversClass(SchemaPlan::class)]
#[CoversClass(SchemaPlanStep::class)]
final class BusinessSchemaRecoveryIntegrationTest extends TestCase
{
    private const EMPTY_SCHEMA_CHECKSUM = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function testCanonicalPlanIsStableAndLedgerChecksumDriftFailsClosed(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $definitions = $container->get(BusinessDefinitionService::class);
        $schemas = $container->get(BusinessSchemaService::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(BusinessDefinitionService::class, $definitions);
        self::assertInstanceOf(BusinessSchemaService::class, $schemas);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $context = TestKernelFactory::administratorContext($container);
        $suffix = self::suffix();
        $draft = $definitions->saveDraft(
            $context,
            EntityTypeDefinition::fromArray(self::siteDocument(
                Uuid::uuid7()->toString(),
                'site.default.schema_stability_' . $suffix,
            )),
        );
        $published = $definitions->publish($context, $draft->definition->id, $draft->revision);

        $first = $schemas->createPlan($context, $published->definition->id);
        $second = $schemas->createPlan($context, $published->definition->id);
        self::assertSame($first->id, $second->id);
        self::assertSame($first->checksum(), $second->checksum());
        self::assertSame(range(1, count($first->operations())), array_map(
            static fn (SchemaOperation $operation): int => $operation->ordinal,
            $first->operations(),
        ));

        $database->update(
            $tables->raw('business_schema_plans'),
            ['plan_checksum' => str_repeat('f', 64)],
            ['id' => $first->id],
        );
        try {
            $schemas->plan($context, $first->id);
            self::fail('A drifted schema-plan ledger checksum must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('plan_checksum', $exception->getMessage());
        } finally {
            $database->update(
                $tables->raw('business_schema_plans'),
                ['plan_checksum' => $first->checksum()],
                ['id' => $first->id],
            );
        }
    }

    public function testGlobalExecutionLockRejectsASecondSessionAndAdvancesItsFence(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        $clock = $container->get(ClockInterface::class);
        $primary = $container->get(BusinessSchemaExecutionLock::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        self::assertInstanceOf(ClockInterface::class, $clock);
        self::assertInstanceOf(BusinessSchemaExecutionLock::class, $primary);
        $secondaryDatabase = DriverManager::getConnection($database->getParams());
        $secondaryDatabase->executeStatement(
            $database->getDatabasePlatform() instanceof AbstractMySQLPlatform
                ? "SET time_zone = '+00:00'"
                : "SET TIME ZONE 'UTC'",
        );
        $secondary = new DoctrineBusinessSchemaExecutionLock($secondaryDatabase, $tables, $clock);
        $definitionId = Uuid::uuid7()->toString();

        try {
            $firstFence = $primary->synchronized($definitionId, function (int $fence) use (
                $secondary,
                $definitionId,
            ): int {
                try {
                    $secondary->synchronized($definitionId, static fn (int $ignored): int => $ignored);
                    self::fail('A second database session must not acquire the global schema-execution lock.');
                } catch (RuntimeException $exception) {
                    self::assertStringContainsString('Another executor', $exception->getMessage());
                }

                return $fence;
            });
            $secondFence = $secondary->synchronized($definitionId, static fn (int $fence): int => $fence);

            self::assertGreaterThan($firstFence, $secondFence);
        } finally {
            $secondaryDatabase->close();
        }
    }

    public function testArchiveColumnsAreUniversalAndDeleteColumnsFollowSoftDeletePolicy(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $compiler = $container->get(DefinitionPhysicalSchemaCompiler::class);
        self::assertInstanceOf(DefinitionPhysicalSchemaCompiler::class, $compiler);
        $site = TestKernelFactory::administratorContext($container)->site();
        $regularDocument = self::siteDocument(
            Uuid::uuid7()->toString(),
            'site.default.schema_lifecycle_regular_' . self::suffix(),
        );
        $regularDocument['status'] = 'published';
        $regularDocument['definition_version'] = 1;
        $softDeleteDocument = self::siteDocument(
            Uuid::uuid7()->toString(),
            'site.default.schema_lifecycle_soft_' . self::suffix(),
        );
        $softDeleteDocument['status'] = 'published';
        $softDeleteDocument['definition_version'] = 1;
        $softDeleteDocument['soft_delete_enabled'] = true;
        $regular = $compiler->compile(EntityTypeDefinition::fromArray($regularDocument), $site)->table('record');
        $softDelete = $compiler->compile(
            EntityTypeDefinition::fromArray($softDeleteDocument),
            $site,
        )->table('record');

        self::assertNotNull($regular);
        self::assertNotNull($regular->column('archived_by'));
        self::assertNotNull($regular->column('archived_at'));
        self::assertNull($regular->column('deleted_by'));
        self::assertNull($regular->column('deleted_at'));
        self::assertNotNull($softDelete);
        self::assertNotNull($softDelete->column('archived_by'));
        self::assertNotNull($softDelete->column('archived_at'));
        self::assertNotNull($softDelete->column('deleted_by'));
        self::assertNotNull($softDelete->column('deleted_at'));
    }

    public function testRecoveryResumesACompletedDurableStepUnderANewerFence(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $definitions = $container->get(BusinessDefinitionService::class);
        $schemas = $container->get(BusinessSchemaService::class);
        $plans = $container->get(BusinessSchemaPlanRepository::class);
        $compiler = $container->get(DefinitionPhysicalSchemaCompiler::class);
        $physical = $container->get(PhysicalSchemaGateway::class);
        $lock = $container->get(BusinessSchemaExecutionLock::class);
        self::assertInstanceOf(BusinessDefinitionService::class, $definitions);
        self::assertInstanceOf(BusinessSchemaService::class, $schemas);
        self::assertInstanceOf(BusinessSchemaPlanRepository::class, $plans);
        self::assertInstanceOf(DefinitionPhysicalSchemaCompiler::class, $compiler);
        self::assertInstanceOf(PhysicalSchemaGateway::class, $physical);
        self::assertInstanceOf(BusinessSchemaExecutionLock::class, $lock);
        $context = TestKernelFactory::administratorContext($container);
        $draft = $definitions->saveDraft(
            $context,
            EntityTypeDefinition::fromArray(self::siteDocument(
                Uuid::uuid7()->toString(),
                'site.default.schema_recovery_' . self::suffix(),
            )),
        );
        $published = $definitions->publish($context, $draft->definition->id, $draft->revision);
        $pending = $schemas->createPlan($context, $published->definition->id);
        $approved = $schemas->approve($context, $pending->id, $pending->checksum(), null, null);
        self::assertCount(1, $approved->operations());
        self::assertSame(SchemaOperationKind::CreateTable, $approved->operations()[0]->kind);
        $target = $compiler->compile($published->definition, $context->site());

        $interruptedFence = $lock->synchronized($published->definition->id, function (int $fence) use (
            $plans,
            $physical,
            $target,
            $context,
            $approved,
        ): int {
            $current = $plans->find($context->site(), $approved->id);
            self::assertNotNull($current);
            $executing = $current->begin($fence, $current->updatedAt);
            $plans->replace($executing, $current->revision);
            $steps = $plans->steps($executing->id);
            self::assertCount(1, $steps);
            $operation = $executing->operations()[0];
            $running = $steps[0]->start($fence, self::EMPTY_SCHEMA_CHECKSUM, $executing->updatedAt);
            $plans->replaceStep($running, null);
            try {
                $plans->replaceStep(
                    $running->checkpoint(['stale_executor_probe' => true], $executing->updatedAt),
                    $fence + 1,
                );
                self::fail('A stale execution fence must not overwrite a running journal step.');
            } catch (BusinessSchemaConflict $exception) {
                self::assertStringContainsString('fence changed concurrently', $exception->getMessage());
            }
            $physical->execute($operation, $target);
            self::assertTrue($physical->operationSatisfied($operation, $target));
            $chain = hash('sha256', implode("\0", [
                self::EMPTY_SCHEMA_CHECKSUM,
                $operation->checksum(),
                (string) $fence,
                'applied',
            ]));
            $completed = $running->complete($chain, [
                'already_satisfied' => false,
                'processed_rows' => 0,
                'fence' => $fence,
                'simulated_process_crash' => true,
            ], $executing->updatedAt);
            $plans->replaceStep($completed, $fence);
            $interrupted = $executing->recoveryRequired(
                'process_crash',
                ['fence' => $fence, 'durable_steps' => 1],
                $executing->updatedAt,
            );
            $plans->replace($interrupted, $executing->revision, $fence);
            try {
                $plans->replace(
                    $interrupted->resume($fence + 1, $interrupted->updatedAt),
                    $interrupted->revision,
                    $fence + 1,
                );
                self::fail('A stale execution fence must not resume an interrupted plan.');
            } catch (BusinessSchemaConflict $exception) {
                self::assertStringContainsString('changed concurrently', $exception->getMessage());
            }

            return $fence;
        });

        $outcome = $schemas->recover($context, $approved->id);
        $completed = $schemas->plan($context, $approved->id);
        $steps = $schemas->steps($context, $approved->id);
        $installation = $schemas->installation($context, $published->definition->id);

        self::assertTrue($outcome->resumed);
        self::assertGreaterThan($interruptedFence, $outcome->fence);
        self::assertSame(0, $outcome->completedSteps);
        self::assertSame(1, $outcome->skippedSteps);
        self::assertSame(SchemaPlanStatus::Completed, $completed->status);
        self::assertSame(SchemaStepStatus::Completed, $steps[0]->state);
        self::assertNotNull($installation);
        self::assertSame(SchemaInstallationStatus::Active, $installation->status);
        self::assertSame($target->checksum(), $installation->schemaChecksum);
        self::assertSame($target->checksum(), $physical->inspect($target)?->checksum());
    }

    public function testInitialMultiForeignKeyGraphPausesExecutesItsPeerAndRecovers(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $synchronizer = $container->get(PackageDefinitionSynchronizer::class);
        $transactions = $container->get(TransactionManager::class);
        $schemas = $container->get(BusinessSchemaService::class);
        $physical = $container->get(PhysicalSchemaGateway::class);
        $database = $container->get(Connection::class);
        self::assertInstanceOf(PackageDefinitionSynchronizer::class, $synchronizer);
        self::assertInstanceOf(TransactionManager::class, $transactions);
        self::assertInstanceOf(BusinessSchemaService::class, $schemas);
        self::assertInstanceOf(PhysicalSchemaGateway::class, $physical);
        self::assertInstanceOf(Connection::class, $database);
        $context = TestKernelFactory::administratorContext($container);
        $suffix = self::suffix();
        $owner = 'testing/runtime_' . $suffix;
        $namespace = str_replace('/', '.', $owner);
        $leftHandle = $namespace . '.alpha';
        $rightHandle = $namespace . '.beta';
        $left = EntityTypeDefinition::fromArray(self::extensionDocument(
            Uuid::uuid7()->toString(),
            $owner,
            $leftHandle,
            [[
                'handle' => 'betas',
                'label' => 'Betas',
                'kind' => 'many_to_many',
                'target' => $rightHandle,
                'inverse' => 'alphas',
                'required' => false,
                'unique' => false,
                'ordered' => true,
                'on_delete' => 'restrict',
            ]],
        ));
        $right = EntityTypeDefinition::fromArray(self::extensionDocument(
            Uuid::uuid7()->toString(),
            $owner,
            $rightHandle,
            [[
                'handle' => 'alphas',
                'label' => 'Alphas',
                'kind' => 'many_to_many',
                'target' => $leftHandle,
                'inverse' => 'betas',
                'required' => false,
                'unique' => false,
                'ordered' => true,
                'on_delete' => 'restrict',
            ]],
        ));
        $transactions->transactional(static function () use (
            $synchronizer,
            $owner,
            $context,
            $left,
            $right,
        ): void {
            $synchronizer->synchronize(
                $owner,
                '1.0.0',
                $context->site(),
                [],
                [$right, $left],
                true,
                $context->actorId(),
            );
        });

        $definitionIds = [$left->id, $right->id];
        $graphPlans = array_values(array_filter(
            $schemas->plans($context),
            static fn (SchemaPlan $plan): bool => in_array($plan->definitionId, $definitionIds, true),
        ));
        self::assertCount(2, $graphPlans);
        $root = array_values(array_filter(
            $graphPlans,
            static fn (SchemaPlan $plan): bool => count(array_filter(
                $plan->operations(),
                static fn (SchemaOperation $operation): bool =>
                    $operation->kind === SchemaOperationKind::AddForeignKey,
            )) >= 2,
        ))[0] ?? null;
        self::assertNotNull($root, 'The materialized graph side must own both junction foreign keys.');
        $firstForeignKey = null;
        foreach ($root->operations() as $offset => $operation) {
            if ($operation->kind === SchemaOperationKind::AddForeignKey) {
                $firstForeignKey ??= $offset;
                continue;
            }
            if ($firstForeignKey !== null) {
                self::fail('Initial graph plans must create every table before adding any foreign key.');
            }
        }

        try {
            $schemas->approve($context, $root->id, $root->checksum(), str_repeat('0', 64), null);
            self::fail('High-impact graph approval must require the exact inspected checksum.');
        } catch (BusinessSchemaConflict $exception) {
            self::assertStringContainsString('exact current plan checksum', $exception->getMessage());
        }
        foreach ($graphPlans as $plan) {
            $schemas->approve(
                $context,
                $plan->id,
                $plan->checksum(),
                $plan->risk->requiresHighImpactAuthorization() ? $plan->checksum() : null,
                null,
            );
        }

        $executeOnly = TestKernelFactory::contextFromGrantRows($container, [[
            'capability' => 'business.schema.execute',
            'scope_type' => 'site',
            'scope_identifier' => 'default',
        ]]);
        $outcome = $schemas->execute($executeOnly, $root->id);
        self::assertTrue($outcome->resumed);
        foreach ($graphPlans as $planned) {
            $completed = $schemas->plan($context, $planned->id);
            $installation = $schemas->installation($context, $planned->definitionId);
            self::assertSame(SchemaPlanStatus::Completed, $completed->status);
            self::assertNotNull($installation);
            self::assertSame(SchemaInstallationStatus::Active, $installation->status);
            self::assertSame(
                $installation->schemaChecksum,
                $physical->inspect($installation->blueprint)?->checksum(),
            );
            foreach ($schemas->steps($context, $completed->id) as $step) {
                self::assertSame(SchemaStepStatus::Completed, $step->state);
            }
        }
        $rootInstallation = $schemas->installation($context, $root->definitionId);
        self::assertNotNull($rootInstallation);
        $junction = array_values(array_filter(
            $rootInstallation->blueprint->tables(),
            static fn (PhysicalTableBlueprint $table): bool => count($table->foreignKeys()) >= 2,
        ))[0] ?? null;
        self::assertNotNull($junction);
        self::assertCount(
            count($junction->foreignKeys()),
            $database->createSchemaManager()
                ->introspectTableByUnquotedName($junction->physicalName)
                ->getForeignKeys(),
        );
        self::assertSame(0, (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE subject_type = ? AND subject_id = ? AND action = ?',
            $container->get(TableNames::class)->quoted('audit_events'),
        ), ['business_schema_plan', $root->id, 'business.schema.recover']));
        self::assertSame(1, (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE subject_type = ? AND subject_id = ? AND action = ? AND outcome = ?',
            $container->get(TableNames::class)->quoted('audit_events'),
        ), ['business_schema_plan', $root->id, 'business.schema.execute', 'success']));
    }

    /** @return array<string, mixed> */
    private static function siteDocument(string $id, string $handle): array
    {
        return [
            'id' => $id,
            'owner' => ['type' => 'site', 'identifier' => 'default'],
            'site' => 'default',
            'handle' => $handle,
            'singular_label' => 'Schema fixture',
            'plural_label' => 'Schema fixtures',
            'status' => 'draft',
            'definition_version' => 0,
            'storage_mode' => 'relational',
            'identity_strategy' => 'uuid',
            'scope' => 'site',
            'audit_enabled' => true,
            'revisions_enabled' => true,
            'fields' => [self::identityField()],
            'relationships' => [],
            'views' => [],
            'actions' => [],
            'workflow' => null,
            'compatibility_metadata' => [],
        ];
    }

    /**
     * @param list<array<string, mixed>> $relationships
     * @return array<string, mixed>
     */
    private static function extensionDocument(
        string $id,
        string $owner,
        string $handle,
        array $relationships,
    ): array {
        return [
            'id' => $id,
            'owner' => ['type' => 'extension', 'identifier' => $owner],
            'site' => 'default',
            'handle' => $handle,
            'singular_label' => 'Graph fixture',
            'plural_label' => 'Graph fixtures',
            'status' => 'published',
            'definition_version' => 1,
            'storage_mode' => 'relational',
            'identity_strategy' => 'uuid',
            'scope' => 'site',
            'audit_enabled' => true,
            'revisions_enabled' => true,
            'fields' => [self::identityField()],
            'relationships' => $relationships,
            'views' => [],
            'actions' => [],
            'workflow' => null,
            'compatibility_metadata' => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function identityField(): array
    {
        return [
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
        ];
    }

    private static function suffix(): string
    {
        return strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
    }
}
