<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessRecord;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\BusinessRecord\Application\BusinessRecordDefinitionResolver;
use Kumwe\App\BusinessRecord\Application\ResolvedBusinessDefinition;
use Kumwe\App\BusinessRecord\Domain\RecordScope;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordQueryCompiler;
use Kumwe\Extension\Spi\BusinessRecord\Query\AggregateFunction;
use Kumwe\Extension\Spi\BusinessRecord\Query\ComparisonFilter;
use Kumwe\Extension\Spi\BusinessRecord\Query\ComparisonOperator;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordAggregate;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordProjection;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessPlan;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessController;
use Kumwe\Extension\Spi\BusinessSecurity\Application\FieldAccessUsage;
use Kumwe\App\BusinessSecurity\Infrastructure\Persistence\DoctrineBusinessRecordAccessController;
use Kumwe\Extension\Spi\BusinessSecurity\Application\FieldDisclosurePlan;
use Kumwe\Extension\Spi\BusinessSecurity\Policy\RecordPolicyComparison;
use Kumwe\Extension\Spi\BusinessSecurity\Policy\RecordPolicyComparisonOperator;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicyConstant;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicySchema;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicySet;
use Kumwe\Extension\Spi\BusinessSecurity\Policy\RecordPolicyValueType;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RuntimeException;

#[CoversClass(DoctrineBusinessRecordQueryCompiler::class)]
#[CoversClass(DoctrineBusinessRecordAccessController::class)]
final class BusinessRecordPolicyCompilerIntegrationTest extends TestCase
{
    public function testRowPolicyPrecedesPagingAndSharesBindingsWithAggregatesAndEvaluator(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $id = Uuid::uuid7()->toString();
        $suffix = 'policy' . substr(str_replace('-', '', $id), 0, 12);
        $definition = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::document($suffix, $id),
        );
        $resolver = $container->get(BusinessRecordDefinitionResolver::class);
        $compiler = $container->get(DoctrineBusinessRecordQueryCompiler::class);
        self::assertInstanceOf(BusinessRecordDefinitionResolver::class, $resolver);
        self::assertInstanceOf(DoctrineBusinessRecordQueryCompiler::class, $compiler);
        $resolved = $resolver->forCreate($context, $definition->handle);
        $scope = \Kumwe\App\BusinessRecord\Domain\RecordScope::forDefinition(
            $definition->scope,
            $context->site(),
            null,
        );
        $schema = new RecordPolicySchema(['status' => RecordPolicyValueType::String]);
        $records = new RecordPolicySet(
            $schema,
            [new RecordPolicyConstant(true)],
            [new RecordPolicyComparison(
                'status',
                RecordPolicyComparisonOperator::Equal,
                RecordPolicyValueType::String,
                'draft',
            )],
        );
        $fields = new FieldDisclosurePlan([
            'list' => ['name'],
            'filter' => ['status'],
        ]);
        $access = new BusinessRecordAccessPlan(
            $definition->id,
            'business.record.browse',
            $records,
            $fields,
            str_repeat('a', 64),
        );
        $query = new RecordQuerySpecification(
            new ComparisonFilter('status', ComparisonOperator::Equal, 'ready'),
            pageSize: 17,
            projection: new RecordProjection(
                ['name'],
                aggregates: [new RecordAggregate('row_count', AggregateFunction::Count)],
            ),
        );

        $compiled = $compiler->compile($resolved, $scope, $query, $access);

        self::assertStringContainsString('AND NOT', $compiled->sql);
        self::assertStringContainsString('CASE WHEN', $compiled->sql);
        self::assertStringContainsString('LIMIT 18', $compiled->sql);
        $policyPosition = strpos($compiled->sql, 'CASE WHEN');
        $pagePosition = strpos($compiled->sql, 'LIMIT 18');
        self::assertIsInt($policyPosition);
        self::assertIsInt($pagePosition);
        self::assertLessThan($pagePosition, $policyPosition);
        self::assertNotNull($compiled->aggregateSql);
        self::assertStringContainsString('AND NOT', $compiled->aggregateSql);
        self::assertSame($compiled->aggregateParameters, $compiled->parameters);
        self::assertSame(['draft', 'ready'], array_slice($compiled->parameters, -2));
        self::assertTrue($records->allows(['status' => 'ready']));
        self::assertFalse($records->allows(['status' => 'draft']));

        $changedAccess = new BusinessRecordAccessPlan(
            $definition->id,
            'business.record.browse',
            $records,
            $fields,
            str_repeat('b', 64),
        );
        self::assertNotSame(
            $compiled->cursorDigest,
            $compiler->compile($resolved, $scope, $query, $changedAccess)->cursorDigest,
        );

        $temporalAccess = new BusinessRecordAccessPlan(
            $definition->id,
            'business.record.browse',
            new RecordPolicySet(
                new RecordPolicySchema(['service_date' => RecordPolicyValueType::Temporal]),
                [new RecordPolicyComparison(
                    'service_date',
                    RecordPolicyComparisonOperator::GreaterThanOrEqual,
                    RecordPolicyValueType::Temporal,
                    '2026-01-01',
                )],
            ),
            $fields,
            str_repeat('d', 64),
        );
        $temporal = $compiler->compile($resolved, $scope, $query, $temporalAccess);
        $temporalParameters = array_values(array_filter(
            $temporal->parameters,
            static fn (mixed $parameter): bool => $parameter instanceof DateTimeImmutable,
        ));
        self::assertCount(1, $temporalParameters);
        $temporalParameter = $temporalParameters[0];
        self::assertInstanceOf(DateTimeImmutable::class, $temporalParameter);
        self::assertSame('2026-01-01', $temporalParameter->format('Y-m-d'));
    }

    public function testNoAllowCompilesToFalseForPageAndAggregate(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $id = Uuid::uuid7()->toString();
        $suffix = 'deny' . substr(str_replace('-', '', $id), 0, 12);
        $definition = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::document($suffix, $id),
        );
        $resolver = $container->get(BusinessRecordDefinitionResolver::class);
        $compiler = $container->get(DoctrineBusinessRecordQueryCompiler::class);
        self::assertInstanceOf(BusinessRecordDefinitionResolver::class, $resolver);
        self::assertInstanceOf(DoctrineBusinessRecordQueryCompiler::class, $compiler);
        $resolved = $resolver->forCreate($context, $definition->handle);
        $scope = \Kumwe\App\BusinessRecord\Domain\RecordScope::forDefinition(
            $definition->scope,
            $context->site(),
            null,
        );
        $records = new RecordPolicySet(new RecordPolicySchema([]));
        $access = new BusinessRecordAccessPlan(
            $definition->id,
            'business.record.browse',
            $records,
            new FieldDisclosurePlan(),
            str_repeat('c', 64),
        );
        $query = new RecordQuerySpecification(projection: new RecordProjection(
            aggregates: [new RecordAggregate('row_count', AggregateFunction::Count)],
        ));

        $compiled = $compiler->compile($resolved, $scope, $query, $access);

        self::assertStringContainsString('(1 = 0)', $compiled->sql);
        self::assertNotNull($compiled->aggregateSql);
        self::assertStringContainsString('(1 = 0)', $compiled->aggregateSql);
        self::assertFalse($records->allows([]));
    }

    public function testConditionalFieldGrantsIntersectAndMatchingDenyRulesSubtract(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $id = Uuid::uuid7()->toString();
        $suffix = 'access' . substr(str_replace('-', '', $id), 0, 12);
        $definition = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::document($suffix, $id),
        );
        NeutralBusinessFixture::removeRecordAccess($container, $definition->id);
        $resolver = $container->get(BusinessRecordDefinitionResolver::class);
        $accessController = $container->get(BusinessRecordAccessController::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(BusinessRecordDefinitionResolver::class, $resolver);
        self::assertInstanceOf(BusinessRecordAccessController::class, $accessController);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $resolved = $resolver->forCreate($context, $definition->handle);
        $scope = \Kumwe\App\BusinessRecord\Domain\RecordScope::forDefinition(
            $definition->scope,
            $context->site(),
            null,
        );
        $defaultDenied = self::plan(
            $database,
            $accessController,
            $context,
            'business.record.browse',
            $resolved,
            $scope,
        );
        self::assertFalse($defaultDenied->records->allows(['name' => $context->actorId()]));
        self::assertSame([], $defaultDenied->fields->fields(FieldAccessUsage::List));
        self::assertFalse($defaultDenied->allowsAction('approve'));
        $table = $tables->raw('resource_policies');
        $policyCodes = [];
        $insert = static function (
            string $effect,
            array $ast,
            array $fieldRules,
            int $priority,
            string $operation = 'business.record.browse',
        ) use (
            $database,
            $table,
            $definition,
            $context,
            &$policyCodes
): void {
            $policyCode = 'test.business.record.' . Uuid::uuid7()->toString();
            $policyCodes[] = $policyCode;
            $database->insert($table, [
                'id' => Uuid::uuid7()->toString(),
                'policy_code' => $policyCode,
                'owner_kind' => 'core',
                'owner_identifier' => 'core',
                'capability_code' => $operation,
                'resource_type' => 'business_record',
                'action' => $operation,
                'effect' => $effect,
                'scope_type' => 'global',
                'organization_id' => null,
                'entity_definition_id' => $definition->id,
                'canonical_ast' => CanonicalDefinitionJson::encode($ast),
                'field_rules' => CanonicalDefinitionJson::encode($fieldRules),
                'ast_checksum' => CanonicalDefinitionJson::checksum([
                    'ast' => $ast,
                    'fields' => $fieldRules,
                ]),
                'policy_version' => 1,
                'priority' => $priority,
                'status' => 'active',
                'created_by' => $context->actorId(),
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-01 00:00:00',
            ]);
        };

        try {
            $insert('allow', [
                'type' => 'comparison',
                'field' => 'status',
                'operator' => 'equal',
                'value_type' => 'string',
                'value' => 'draft',
            ], ['list' => ['name', 'status']], 20);
            $insert('allow', [
                'type' => 'comparison',
                'field' => 'enabled',
                'operator' => 'equal',
                'value_type' => 'boolean',
                'value' => false,
            ], ['list' => ['name', 'enabled']], 10);
            $insert('allow', [
                'type' => 'attribute_comparison',
                'source' => 'context',
                'attribute' => 'site',
                'operator' => 'equal',
                'value_type' => 'string',
                'value' => 'another-site',
            ], ['list' => ['status']], 5);

            $intersected = self::plan(
                $database,
                $accessController,
                $context,
                'business.record.browse',
                $resolved,
                $scope,
            );
            self::assertSame(['name'], $intersected->fields->fields(FieldAccessUsage::List));
            self::assertTrue($intersected->records->allows(['status' => 'draft', 'enabled' => true]));
            self::assertTrue($intersected->records->allows(['status' => 'ready', 'enabled' => false]));
            self::assertFalse($intersected->records->allows(['status' => 'ready', 'enabled' => true]));

            $insert('deny', [
                'type' => 'constant',
                'value' => true,
            ], ['list' => ['name']], 30);
            $subtracted = self::plan(
                $database,
                $accessController,
                $context,
                'business.record.browse',
                $resolved,
                $scope,
            );
            self::assertSame([], $subtracted->fields->fields(FieldAccessUsage::List));

            $insert('allow', [
                'type' => 'boolean',
                'operator' => 'all',
                'children' => [[
                    'type' => 'attribute_comparison',
                    'source' => 'context',
                    'attribute' => 'today',
                    'operator' => 'greater_than_or_equal',
                    'value_type' => 'temporal',
                    'value' => '2000-01-01',
                ], [
                    'type' => 'field_attribute_comparison',
                    'field' => 'name',
                    'source' => 'principal',
                    'attribute' => 'id',
                    'operator' => 'equal',
                    'value_type' => 'string',
                ]],
            ], ['detail' => ['name']], 10, 'business.record.read');
            $attributed = self::plan(
                $database,
                $accessController,
                $context,
                'business.record.read',
                $resolved,
                $scope,
            );
            self::assertSame(['name'], $attributed->fields->fields(FieldAccessUsage::Detail));
            self::assertTrue($attributed->records->allows(['name' => $context->actorId()]));
            self::assertFalse($attributed->records->allows(['name' => 'another-actor']));

            $insert('allow', ['type' => 'constant', 'value' => true], [
                'report' => ['amount'],
            ], 10, 'business.record.report');
            $insert('allow', ['type' => 'constant', 'value' => true], [
                'export' => ['name'],
            ], 10, 'business.record.export');
            $insert('allow', ['type' => 'constant', 'value' => true], [
                'detail' => ['name'],
                'actions' => ['approve'],
            ], 10, 'business.record.action');
            $report = self::plan(
                $database,
                $accessController,
                $context,
                'business.record.report',
                $resolved,
                $scope,
            );
            $export = self::plan(
                $database,
                $accessController,
                $context,
                'business.record.export',
                $resolved,
                $scope,
            );
            $action = self::plan(
                $database,
                $accessController,
                $context,
                'business.record.action',
                $resolved,
                $scope,
            );
            self::assertSame(['amount'], $report->fields->fields(FieldAccessUsage::Report));
            self::assertSame([], $report->fields->fields(FieldAccessUsage::List));
            self::assertSame(['name'], $export->fields->fields(FieldAccessUsage::Export));
            self::assertTrue($action->allowsAction('approve'));

            $insert('allow', ['type' => 'constant', 'value' => true], [
                'export' => ['credential'],
            ], 50, 'business.record.export');
            try {
                self::plan(
                    $database,
                    $accessController,
                    $context,
                    'business.record.export',
                    $resolved,
                    $scope,
                );
                self::fail('Dynamic policy must not override an immutable secret/export field ceiling.');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        } finally {
            foreach ($policyCodes as $policyCode) {
                $database->delete($table, ['policy_code' => $policyCode]);
            }
        }
    }

    public function testPolicySnapshotSerializesAConcurrentPolicyGenerationChange(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $id = Uuid::uuid7()->toString();
        $suffix = 'policylock' . substr(str_replace('-', '', $id), 0, 8);
        $definition = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::document($suffix, $id),
        );
        $resolver = $container->get(BusinessRecordDefinitionResolver::class);
        $accessController = $container->get(BusinessRecordAccessController::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(BusinessRecordDefinitionResolver::class, $resolver);
        self::assertInstanceOf(BusinessRecordAccessController::class, $accessController);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $resolved = $resolver->forCreate($context, $definition->handle);
        $scope = RecordScope::forDefinition($definition->scope, $context->site(), null);
        $site = $context->site()->identifier();
        $siteTable = $tables->raw('sites');
        $originalGeneration = (int) $database->fetchOne(sprintf(
            'SELECT policy_generation FROM %s WHERE identifier = ?',
            $tables->quoted('sites'),
        ), [$site]);
        self::assertGreaterThan(0, $originalGeneration);
        $secondary = DriverManager::getConnection($database->getParams());
        $secondary->executeStatement(
            $database->getDatabasePlatform() instanceof AbstractMySQLPlatform
                ? 'SET innodb_lock_wait_timeout = 1'
                : "SET lock_timeout = '500ms'",
        );

        try {
            $database->beginTransaction();
            $accessController->plan(
                $context,
                'business.record.browse',
                $resolved,
                $scope,
            );
            try {
                $secondary->executeStatement(sprintf(
                    'UPDATE %s SET policy_generation = policy_generation + 1 WHERE identifier = ?',
                    $tables->quoted('sites'),
                ), [$site]);
                self::fail('A policy change must wait for the in-flight record policy snapshot.');
            } catch (DbalException) {
                self::assertTrue($database->isTransactionActive());
            } finally {
                $database->rollBack();
            }

            self::assertSame(1, $secondary->executeStatement(sprintf(
                'UPDATE %s SET policy_generation = policy_generation + 1 WHERE identifier = ?',
                $tables->quoted('sites'),
            ), [$site]));
        } finally {
            if ($database->isTransactionActive()) {
                $database->rollBack();
            }
            $secondary->update($siteTable, ['policy_generation' => $originalGeneration], ['identifier' => $site]);
            $secondary->close();
        }
    }

    public function testRelatedPlansInheritReportExportAndActionOperationSemantics(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
        $target = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::referenceTargetDocument($suffix, Uuid::uuid7()->toString()),
        );
        $owner = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::entityReferenceOwnerDocument(
                $suffix,
                Uuid::uuid7()->toString(),
                $target->handle,
            ),
        );
        $resolver = $container->get(BusinessRecordDefinitionResolver::class);
        $accessController = $container->get(BusinessRecordAccessController::class);
        $database = $container->get(Connection::class);
        self::assertInstanceOf(BusinessRecordDefinitionResolver::class, $resolver);
        self::assertInstanceOf(BusinessRecordAccessController::class, $accessController);
        self::assertInstanceOf(Connection::class, $database);
        $resolved = $resolver->forCreate($context, $owner->handle);
        $scope = \Kumwe\App\BusinessRecord\Domain\RecordScope::forDefinition(
            $owner->scope,
            $context->site(),
            null,
        );

        foreach (['report', 'export', 'action'] as $purpose) {
            $operation = 'business.record.' . $purpose;
            $related = self::plan(
                $database,
                $accessController,
                $context,
                $operation,
                $resolved,
                $scope,
            )->related('target_ref');
            self::assertNotNull($related);
            self::assertSame($operation, $related->operation);
            self::assertSame($target->id, $related->resourceIdentifier);
        }
    }

    /**
     * Resolve one access plan inside the transaction that retains its shared policy snapshot lock.
     *
     * @param   Connection                      $database    Connection owning the lock transaction.
     * @param   BusinessRecordAccessController  $controller Policy planner under test.
     * @param   ExecutionContext                $context    Actor and authenticated scope.
     * @param   string                          $operation  Business-record operation being planned.
     * @param   ResolvedBusinessDefinition      $resolved   Pinned definition resource.
     * @param   RecordScope                     $scope      Exact record scope.
     *
     * @return  BusinessRecordAccessPlan  Immutable decision resolved under the shared generation lock.
     *
     * @since   2.0.0
     */
    private static function plan(
        Connection $database,
        BusinessRecordAccessController $controller,
        ExecutionContext $context,
        string $operation,
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
    ): BusinessRecordAccessPlan {
        return $database->transactional(
            static fn (): BusinessRecordAccessPlan => $controller->plan(
                $context,
                $operation,
                $resolved,
                $scope,
            ),
        );
    }
}
