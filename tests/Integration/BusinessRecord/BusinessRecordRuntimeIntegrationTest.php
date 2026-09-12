<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessRecord;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\App\BusinessDefinition\Application\PackageDefinitionSynchronizer;
use Kumwe\App\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\App\BusinessDefinition\Domain\FieldTypeDefinition;
use Kumwe\App\BusinessRecord\Application\BusinessRecordMutationFence;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordMutationFence;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordReadRepository;
use Kumwe\App\BusinessRecord\Application\Command\ArchiveRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\DeleteRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\ExecuteRecordActionCommand;
use Kumwe\App\BusinessRecord\Application\Command\RestoreRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\UpdateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordIdempotencyConflict;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordNotFound;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordUniqueConflict;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordValidationFailed;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordVersionConflict;
use Kumwe\App\BusinessRecord\Application\Exception\InvalidBusinessRecordQuery;
use Kumwe\App\BusinessRecord\Application\Query\BrowseRecordsQuery;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\App\BusinessRecord\Application\Query\RecordHistoryQuery;
use Kumwe\App\BusinessRecord\Application\BusinessRecordRevisionView;
use Kumwe\App\BusinessRecord\Application\ValidationViolation;
use Kumwe\Conversion\Decimal\ExactDecimal;
use Kumwe\Conversion\Value\MoneyValue;
use Kumwe\Conversion\Value\QuantityValue;
use Kumwe\Extension\Spi\BusinessRecord\Value\ZonedDateTimeValue;
use Kumwe\Extension\Spi\BusinessRecord\Query\AggregateFunction;
use Kumwe\Extension\Spi\BusinessRecord\Query\ComparisonFilter;
use Kumwe\Extension\Spi\BusinessRecord\Query\ComparisonOperator;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordAggregate;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordCursor;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordProjection;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordSearch;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordSort;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

#[CoversClass(BusinessRecordService::class)]
#[CoversClass(DoctrineBusinessRecordMutationFence::class)]
#[CoversClass(DoctrineBusinessRecordReadRepository::class)]
final class BusinessRecordRuntimeIntegrationTest extends TestCase
{
    /**
     * Proves conditional visibility and editability are enforced on create, update, and read.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testConditionalVisibilityAndEditabilityAreEnforcedAcrossTheRuntimeBoundary(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
        $document = NeutralBusinessFixture::document($suffix, Uuid::uuid7()->toString());
        $document['fields'][] = [
            'handle' => 'conditional_note',
            'label' => 'Conditional note',
            'type' => 'core.text',
            'default' => 'Stored default',
            'visibility_condition' => [
                'op' => 'eq',
                'type' => 'boolean',
                'args' => [
                    ['op' => 'field', 'type' => 'boolean', 'field' => 'enabled'],
                    ['op' => 'literal', 'type' => 'boolean', 'value' => true],
                ],
            ],
            'editability_condition' => [
                'op' => 'eq',
                'type' => 'boolean',
                'args' => [
                    ['op' => 'field', 'type' => 'string', 'field' => 'status'],
                    ['op' => 'literal', 'type' => 'string', 'value' => 'ready'],
                ],
            ],
        ];
        $definition = NeutralBusinessFixture::install($container, $context, $document);

        $hiddenInput = NeutralBusinessFixture::recordValues();
        $hiddenInput['conditional_note'] = 'Hidden create';
        self::assertValidationCode(static fn () => $records->create(new CreateRecordCommand(
            $context,
            $definition->handle,
            $hiddenInput,
            NeutralBusinessFixture::idempotencyKey('conditional-hidden-create-' . $suffix),
            recordId: Uuid::uuid7()->toString(),
        )), 'not_visible');

        $readOnlyInput = NeutralBusinessFixture::recordValues();
        $readOnlyInput['enabled'] = true;
        $readOnlyInput['conditional_note'] = 'Read-only create';
        self::assertValidationCode(static fn () => $records->create(new CreateRecordCommand(
            $context,
            $definition->handle,
            $readOnlyInput,
            NeutralBusinessFixture::idempotencyKey('conditional-read-only-create-' . $suffix),
            recordId: Uuid::uuid7()->toString(),
        )), 'not_editable');

        $recordId = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $definition->handle,
            NeutralBusinessFixture::recordValues(),
            NeutralBusinessFixture::idempotencyKey('conditional-create-' . $suffix),
            recordId: $recordId,
        ));
        self::assertArrayNotHasKey(
            'conditional_note',
            $records->read(new ReadRecordQuery($context, $definition->handle, $recordId))->values,
        );

        self::assertValidationCode(static fn () => $records->update(new UpdateRecordCommand(
            $context,
            $definition->handle,
            $recordId,
            1,
            ['conditional_note' => 'Hidden update'],
            NeutralBusinessFixture::idempotencyKey('conditional-hidden-update-' . $suffix),
        )), 'not_visible');
        $visible = $records->update(new UpdateRecordCommand(
            $context,
            $definition->handle,
            $recordId,
            1,
            ['enabled' => true],
            NeutralBusinessFixture::idempotencyKey('conditional-show-' . $suffix),
        ));
        self::assertSame(2, $visible->version);
        self::assertSame(
            'Stored default',
            $records->read(new ReadRecordQuery($context, $definition->handle, $recordId))
                ->values['conditional_note'],
        );

        self::assertValidationCode(static fn () => $records->update(new UpdateRecordCommand(
            $context,
            $definition->handle,
            $recordId,
            2,
            ['conditional_note' => 'Read-only update'],
            NeutralBusinessFixture::idempotencyKey('conditional-read-only-update-' . $suffix),
        )), 'not_editable');
        $editable = $records->update(new UpdateRecordCommand(
            $context,
            $definition->handle,
            $recordId,
            2,
            ['status' => 'ready'],
            NeutralBusinessFixture::idempotencyKey('conditional-editable-' . $suffix),
        ));
        self::assertSame(3, $editable->version);
        $updated = $records->update(new UpdateRecordCommand(
            $context,
            $definition->handle,
            $recordId,
            3,
            ['conditional_note' => 'Allowed update'],
            NeutralBusinessFixture::idempotencyKey('conditional-update-' . $suffix),
        ));
        self::assertSame(4, $updated->version);
        self::assertSame(
            'Allowed update',
            $records->read(new ReadRecordQuery($context, $definition->handle, $recordId))
                ->values['conditional_note'],
        );
        $projected = $records->browse(new BrowseRecordsQuery(
            $context,
            $definition->handle,
            new RecordQuerySpecification(projection: new RecordProjection(['conditional_note'])),
        ));
        self::assertCount(1, $projected->records);
        self::assertSame(['conditional_note' => 'Allowed update'], $projected->records[0]->values);
    }

    public function testStableStandaloneBackupFixtureSeedsAndReplaysThroughTheRuntime(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);

        $definition = NeutralBusinessFixture::install($container, $context);
        $first = NeutralBusinessFixture::createBackupRecord($container, $context);
        $replayed = NeutralBusinessFixture::createBackupRecord($container, $context);
        self::assertSame(NeutralBusinessFixture::DEFINITION_ID, $definition->id);
        self::assertSame(NeutralBusinessFixture::HANDLE, $definition->handle);
        self::assertSame(NeutralBusinessFixture::RECORD_ID, $first->recordId);
        self::assertSame($first->recordKey, $replayed->recordKey);
        self::assertSame($first->version, $replayed->version);
        self::assertTrue($replayed->replayed);

        $view = $records->read(new ReadRecordQuery(
            $context,
            NeutralBusinessFixture::HANDLE,
            NeutralBusinessFixture::RECORD_ID,
        ));
        self::assertSame('Backup acceptance record', $view->values['name']);
        self::assertFalse($view->values['enabled']);
        self::assertSame(
            '12345678901234567890123456789012345.123456789012345678901234567890',
            $view->values['amount']->value(),
        );
        self::assertSame('13:14:15.123456', $view->values['local_time']->format('H:i:s.u'));
    }

    public function testRecordMutationFenceSerializesSchemaTransitionAcrossDatabaseSessions(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $definition = NeutralBusinessFixture::install($container, $context);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        $transactions = $container->get(TransactionManager::class);
        $fence = $container->get(BusinessRecordMutationFence::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        self::assertInstanceOf(TransactionManager::class, $transactions);
        self::assertInstanceOf(BusinessRecordMutationFence::class, $fence);
        $secondary = DriverManager::getConnection($database->getParams());
        $secondary->executeStatement(
            $database->getDatabasePlatform() instanceof AbstractMySQLPlatform
                ? 'SET innodb_lock_wait_timeout = 1'
                : "SET lock_timeout = '500ms'",
        );

        try {
            $database->beginTransaction();
            $generation = $fence->lock($context, $definition->handle);
            self::assertSame($definition->definitionVersion, $generation->definitionVersion);
            try {
                $secondary->update(
                    $tables->raw('business_schema_installations'),
                    ['status' => SchemaInstallationStatus::Installing->value],
                    ['definition_id' => $definition->id],
                );
                self::fail('A schema transition must wait for the in-flight record mutation transaction.');
            } catch (DbalException) {
                self::assertTrue($database->isTransactionActive());
            } finally {
                $database->rollBack();
            }

            $database->beginTransaction();
            $readGeneration = $fence->shared($context->site(), $definition->handle);
            self::assertSame($generation->schemaChecksum, $readGeneration->schemaChecksum);
            try {
                $secondary->update(
                    $tables->raw('business_schema_installations'),
                    ['status' => SchemaInstallationStatus::Installing->value],
                    ['definition_id' => $definition->id],
                );
                self::fail('A schema transition must wait for an in-flight record read transaction.');
            } catch (DbalException) {
                self::assertTrue($database->isTransactionActive());
            } finally {
                $database->rollBack();
            }

            $secondary->update(
                $tables->raw('business_schema_installations'),
                ['status' => SchemaInstallationStatus::Installing->value],
                ['definition_id' => $definition->id],
            );
            try {
                $transactions->transactional(static fn () => $fence->lock($context, $definition->handle));
                self::fail('A record mutation must fail closed while its schema installation is changing.');
            } catch (BusinessRecordSchemaUnavailable) {
                self::assertTrue(true);
            }
        } finally {
            if ($database->isTransactionActive()) {
                $database->rollBack();
            }
            $secondary->update(
                $tables->raw('business_schema_installations'),
                ['status' => SchemaInstallationStatus::Active->value],
                ['definition_id' => $definition->id],
            );
            $secondary->close();
        }
    }

    public function testContributedTemporalStorageRoundTripsDefaultsAndKeysetCursors(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        $fieldTypes = $container->get(FieldTypeRegistry::class);
        $synchronizer = $container->get(PackageDefinitionSynchronizer::class);
        $transactions = $container->get(TransactionManager::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(FieldTypeRegistry::class, $fieldTypes);
        self::assertInstanceOf(PackageDefinitionSynchronizer::class, $synchronizer);
        self::assertInstanceOf(TransactionManager::class, $transactions);

        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
        $extensionOwner = 'tests/runtime_' . $suffix;
        $fieldType = str_replace('/', '.', $extensionOwner) . '.observed_at';
        $fieldTypeDefinition = new FieldTypeDefinition(
            $fieldType,
            'Observed instant',
            'A contributed string value stored as a portable immutable UTC instant.',
            'string',
            'datetime',
        );
        $fieldTypes->register(
            DefinitionOwner::extension($extensionOwner),
            $fieldTypeDefinition,
        );
        $transactions->transactional(static fn () => $synchronizer->synchronize(
            $extensionOwner,
            '1.0.0',
            $context->site(),
            [$fieldTypeDefinition],
            [],
            true,
            $context->actorId(),
        ));
        $document = NeutralBusinessFixture::relationTargetDocument($suffix, Uuid::uuid7()->toString());
        $document['fields'][] = [
            'handle' => 'observed_at',
            'label' => 'Observed at',
            'type' => $fieldType,
            'required' => true,
            'nullable' => false,
            'default' => '2026-08-08T11:14:15.100000Z',
            'sortable' => true,
        ];
        $document['fields'][] = [
            'handle' => 'restricted_score',
            'label' => 'Restricted score',
            'type' => 'core.integer',
            'required' => true,
            'nullable' => false,
            'default' => 7,
            'reportable' => true,
            'sensitivity' => 'restricted',
        ];
        $document['fields'][] = [
            'handle' => 'boolean_score',
            'label' => 'Boolean score',
            'type' => 'core.boolean',
            'required' => true,
            'nullable' => false,
            'default' => false,
            'reportable' => true,
        ];
        $definition = NeutralBusinessFixture::install($container, $context, $document);
        $firstId = Uuid::uuid7()->toString();
        $secondId = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $definition->handle,
            ['label' => 'Contributed temporal default'],
            NeutralBusinessFixture::idempotencyKey('custom-time-default-' . $suffix),
            recordId: $firstId,
        ));
        $records->create(new CreateRecordCommand(
            $context,
            $definition->handle,
            ['label' => 'Contributed temporal explicit', 'observed_at' => '2026-08-08T11:14:15.654321Z'],
            NeutralBusinessFixture::idempotencyKey('custom-time-explicit-' . $suffix),
            recordId: $secondId,
        ));

        $first = $records->read(new ReadRecordQuery($context, $definition->handle, $firstId));
        self::assertInstanceOf(DateTimeImmutable::class, $first->values['observed_at']);
        self::assertSame(
            '2026-08-08T11:14:15.100000+00:00',
            $first->values['observed_at']->format('Y-m-d\TH:i:s.uP'),
        );
        $page = $records->browse(new BrowseRecordsQuery(
            $context,
            $definition->handle,
            new RecordQuerySpecification(
                sorts: [new RecordSort('observed_at')],
                pageSize: 1,
                projection: new RecordProjection(['label', 'observed_at']),
            ),
        ));
        self::assertCount(1, $page->records);
        self::assertNotNull($page->nextCursor);
        $tail = $records->browse(new BrowseRecordsQuery(
            $context,
            $definition->handle,
            new RecordQuerySpecification(
                sorts: [new RecordSort('observed_at')],
                after: $page->nextCursor,
                pageSize: 1,
                projection: new RecordProjection(['label', 'observed_at']),
            ),
        ));
        self::assertCount(1, $tail->records);
        self::assertNull($tail->nextCursor);
        self::assertNotSame($page->records[0]->recordId, $tail->records[0]->recordId);

        foreach (['restricted_score', 'boolean_score'] as $aggregateField) {
            try {
                $records->browse(new BrowseRecordsQuery(
                    $context,
                    $definition->handle,
                    new RecordQuerySpecification(projection: new RecordProjection(
                        ['label'],
                        aggregates: [new RecordAggregate(
                            'minimum_value',
                            AggregateFunction::Minimum,
                            $aggregateField,
                        )],
                    )),
                ));
                self::fail('A redacted or nonportable aggregate field was accepted.');
            } catch (InvalidBusinessRecordQuery) {
                self::assertTrue(true);
            }
        }
    }

    public function testTransactionalRuntimeRoundTripsQueriesConcurrencyLifecycleAndIntegrity(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        $schemas = $container->get(BusinessSchemaService::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(BusinessSchemaService::class, $schemas);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);

        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
        $definition = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::document($suffix, Uuid::uuid7()->toString()),
        );
        $firstId = Uuid::uuid7()->toString();
        $secondId = Uuid::uuid7()->toString();
        $createKey = NeutralBusinessFixture::idempotencyKey('create-' . $suffix);
        $create = new CreateRecordCommand(
            $context,
            $definition->handle,
            NeutralBusinessFixture::recordValues('Alpha'),
            $createKey,
            recordId: $firstId,
        );

        $created = $records->create($create);
        $replayed = $records->create($create);

        self::assertSame(1, $created->version);
        self::assertFalse($created->replayed);
        self::assertTrue($replayed->replayed);
        self::assertSame($created->toArray(), $replayed->toArray());

        try {
            $different = NeutralBusinessFixture::recordValues('Changed replay');
            $records->create(new CreateRecordCommand(
                $context,
                $definition->handle,
                $different,
                $createKey,
                recordId: $firstId,
            ));
            self::fail('One idempotency key must not authorize a different request.');
        } catch (BusinessRecordIdempotencyConflict $exception) {
            self::assertSame('business_record.idempotency_key_reused', $exception->stableCode());
        }

        $view = $records->read(new ReadRecordQuery($context, $definition->handle, $firstId));
        self::assertSame('Alpha', $view->values['name']);
        self::assertSame('Alpha', $view->values['display_name']);
        self::assertArrayNotHasKey('credential', $view->values);
        self::assertInstanceOf(ExactDecimal::class, $view->values['amount']);
        self::assertSame(
            '12345678901234567890123456789012345.123456789012345678901234567890',
            $view->values['amount']->value(),
        );
        self::assertInstanceOf(MoneyValue::class, $view->values['price']);
        self::assertSame('NAD', $view->values['price']->currency);
        self::assertSame(
            '99999999999999999999999999999999999.999999999999999999999999999999',
            $view->values['price']->amount->value(),
        );
        self::assertInstanceOf(QuantityValue::class, $view->values['quantity']);
        self::assertSame('unit', $view->values['quantity']->unit);
        self::assertSame(
            '12345678901234567890123456789012345.000000000000000000000000000001',
            $view->values['quantity']->amount->value(),
        );
        self::assertInstanceOf(DateTimeImmutable::class, $view->values['service_date']);
        self::assertSame('2026-08-08', $view->values['service_date']->format('Y-m-d'));
        self::assertInstanceOf(DateTimeImmutable::class, $view->values['local_time']);
        self::assertSame('13:14:15.123456', $view->values['local_time']->format('H:i:s.u'));
        self::assertInstanceOf(DateTimeImmutable::class, $view->values['recorded_at']);
        self::assertSame(
            '2026-08-08T11:14:15.123456+00:00',
            $view->values['recorded_at']->format('Y-m-d\TH:i:s.uP'),
        );
        self::assertInstanceOf(ZonedDateTimeValue::class, $view->values['scheduled_for']);
        self::assertSame(
            ['instant' => '2026-08-08T11:14:15.123456Z', 'timezone' => 'Africa/Windhoek'],
            $view->values['scheduled_for']->toArray(),
        );
        self::assertSame('draft', $view->workflowState);

        $secondValues = NeutralBusinessFixture::recordValues('Bravo');
        $secondValues['local_time'] = '13:14:15.100000';
        $records->create(new CreateRecordCommand(
            $context,
            $definition->handle,
            $secondValues,
            NeutralBusinessFixture::idempotencyKey('second-' . $suffix),
            recordId: $secondId,
        ));
        try {
            $records->create(new CreateRecordCommand(
                $context,
                $definition->handle,
                NeutralBusinessFixture::recordValues('Alpha'),
                NeutralBusinessFixture::idempotencyKey('duplicate-' . $suffix),
                recordId: Uuid::uuid7()->toString(),
            ));
            self::fail('A scoped unique field must reject duplicates.');
        } catch (BusinessRecordUniqueConflict) {
            self::assertTrue(true);
        }

        $projection = new RecordProjection(
            ['name', 'amount'],
            aggregates: [new RecordAggregate('row_count', AggregateFunction::Count)],
        );
        $specification = new RecordQuerySpecification(
            new ComparisonFilter('amount', ComparisonOperator::GreaterThanOrEqual, '0.000000'),
            new RecordSearch('a', ['name']),
            [new RecordSort('name')],
            pageSize: 1,
            projection: $projection,
        );
        $firstPage = $records->browse(new BrowseRecordsQuery($context, $definition->handle, $specification));

        self::assertCount(1, $firstPage->records);
        self::assertSame('Alpha', $firstPage->records[0]->values['name']);
        self::assertSame(2, (int) $firstPage->aggregates['row_count']);
        self::assertNotNull($firstPage->nextCursor);

        $secondPageSpecification = new RecordQuerySpecification(
            $specification->filter,
            $specification->search,
            $specification->sorts,
            $firstPage->nextCursor,
            1,
            $projection,
        );
        $secondPage = $records->browse(new BrowseRecordsQuery(
            $context,
            $definition->handle,
            $secondPageSpecification,
        ));
        self::assertCount(1, $secondPage->records);
        self::assertSame('Bravo', $secondPage->records[0]->values['name']);
        self::assertNull($secondPage->nextCursor);

        foreach (['enabled', 'local_time', 'recorded_at'] as $portableSort) {
            $portableFirst = $records->browse(new BrowseRecordsQuery(
                $context,
                $definition->handle,
                new RecordQuerySpecification(
                    sorts: [new RecordSort($portableSort)],
                    pageSize: 1,
                    projection: new RecordProjection(['name']),
                ),
            ));
            self::assertCount(1, $portableFirst->records);
            self::assertNotNull($portableFirst->nextCursor);

            $portableSecond = $records->browse(new BrowseRecordsQuery(
                $context,
                $definition->handle,
                new RecordQuerySpecification(
                    sorts: [new RecordSort($portableSort)],
                    after: $portableFirst->nextCursor,
                    pageSize: 1,
                    projection: new RecordProjection(['name']),
                ),
            ));
            self::assertCount(1, $portableSecond->records);
            self::assertNull($portableSecond->nextCursor);
            self::assertNotSame(
                $portableFirst->records[0]->recordId,
                $portableSecond->records[0]->recordId,
            );
        }

        $cursor = $firstPage->nextCursor?->value() ?? self::fail('The first page cursor is unavailable.');
        $replacement = str_ends_with($cursor, 'A') ? 'B' : 'A';
        $tampered = RecordCursor::fromString(substr($cursor, 0, -1) . $replacement);
        try {
            $records->browse(new BrowseRecordsQuery(
                $context,
                $definition->handle,
                new RecordQuerySpecification(
                    $specification->filter,
                    $specification->search,
                    $specification->sorts,
                    $tampered,
                    1,
                    $projection,
                ),
            ));
            self::fail('A tampered cursor must be rejected before returning rows.');
        } catch (InvalidBusinessRecordQuery) {
            self::assertTrue(true);
        }

        $updated = $records->update(new UpdateRecordCommand(
            $context,
            $definition->handle,
            $firstId,
            1,
            ['name' => 'Alpha updated'],
            NeutralBusinessFixture::idempotencyKey('update-' . $suffix),
        ));
        self::assertSame(2, $updated->version);
        try {
            $records->update(new UpdateRecordCommand(
                $context,
                $definition->handle,
                $firstId,
                1,
                ['name' => 'Stale update'],
                NeutralBusinessFixture::idempotencyKey('stale-' . $suffix),
            ));
            self::fail('A stale expected version must fail closed.');
        } catch (BusinessRecordVersionConflict $exception) {
            self::assertSame(1, $exception->expectedVersion);
            self::assertSame(2, $exception->actualVersion);
        }

        $approved = $records->action(new ExecuteRecordActionCommand(
            $context,
            $definition->handle,
            $firstId,
            2,
            'approve',
            NeutralBusinessFixture::idempotencyKey('approve-' . $suffix),
        ));
        self::assertSame(3, $approved->version);
        self::assertSame('approved', $approved->workflowState);

        $archived = $records->archive(new ArchiveRecordCommand(
            $context,
            $definition->handle,
            $firstId,
            3,
            NeutralBusinessFixture::idempotencyKey('archive-' . $suffix),
        ));
        self::assertSame(4, $archived->version);
        try {
            $records->read(new ReadRecordQuery($context, $definition->handle, $firstId));
            self::fail('Archived records must be excluded by default.');
        } catch (BusinessRecordNotFound) {
            self::assertTrue(true);
        }
        self::assertNotNull($records->read(new ReadRecordQuery(
            $context,
            $definition->handle,
            $firstId,
            includeArchived: true,
        ))->archivedAt);

        $restored = $records->restore(new RestoreRecordCommand(
            $context,
            $definition->handle,
            $firstId,
            4,
            NeutralBusinessFixture::idempotencyKey('restore-' . $suffix),
        ));
        self::assertSame(5, $restored->version);
        self::assertNull($records->read(new ReadRecordQuery($context, $definition->handle, $firstId))->archivedAt);

        $deleted = $records->delete(new DeleteRecordCommand(
            $context,
            $definition->handle,
            $firstId,
            5,
            NeutralBusinessFixture::idempotencyKey('delete-' . $suffix),
        ));
        self::assertSame(6, $deleted->version);
        self::assertTrue($deleted->deleted);
        try {
            $records->read(new ReadRecordQuery($context, $definition->handle, $firstId));
            self::fail('Soft-deleted records must be excluded by default.');
        } catch (BusinessRecordNotFound) {
            self::assertTrue(true);
        }
        $deletedView = $records->read(new ReadRecordQuery(
            $context,
            $definition->handle,
            $firstId,
            includeArchived: true,
            includeDeleted: true,
        ));
        self::assertNotNull($deletedView->deletedAt);

        $history = $records->history(new RecordHistoryQuery($context, $definition->handle, $firstId));
        self::assertCount(6, $history->revisions);
        self::assertSame(
            ['delete', 'restore', 'archive', 'action.approve', 'update', 'create'],
            array_map(
                static fn (BusinessRecordRevisionView $revision): string => $revision->operation,
                $history->revisions,
            ),
        );
        foreach ($history->revisions as $revision) {
            self::assertArrayNotHasKey('credential', $revision->snapshot);
            self::assertNotContains('credential', $revision->changedFields);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $revision->integrityChecksum);
        }

        self::assertSame(6, (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE definition_id = ? AND record_id = ?',
            $tables->quoted('business_record_revisions'),
        ), [$definition->id, $created->recordKey]));
        self::assertSame(6, (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE subject_type = ? AND subject_id = ? AND outcome = ?',
            $tables->quoted('audit_events'),
        ), ['business_record', $created->recordKey, 'success']));
        $auditMetadata = $database->fetchFirstColumn(sprintf(
            'SELECT metadata FROM %s WHERE subject_type = ? AND subject_id = ? AND outcome = ?',
            $tables->quoted('audit_events'),
        ), ['business_record', $created->recordKey, 'success']);
        foreach ($auditMetadata as $metadata) {
            self::assertStringNotContainsString(
                'credential',
                is_string($metadata) ? $metadata : json_encode($metadata, JSON_THROW_ON_ERROR),
            );
        }
        self::assertSame(1, (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE operation = ? AND operation_id = ? AND state = ?',
            $tables->quoted('business_command_idempotency'),
        ), ['business.record.create', $createKey->value(), 'completed']));

        $installation = $schemas->installation($context, $definition->id);
        self::assertNotNull($installation);
        $recordTable = $installation->blueprint->table('record');
        self::assertNotNull($recordTable);
        $ciphertext = $database->fetchOne(sprintf(
            'SELECT %s FROM %s WHERE %s = ?',
            $database->getDatabasePlatform()->quoteIdentifier(
                $recordTable->column('credential.ciphertext')?->physicalName ?? 'missing',
            ),
            $database->getDatabasePlatform()->quoteIdentifier($recordTable->physicalName),
            $database->getDatabasePlatform()->quoteIdentifier(
                $recordTable->column('record_id')?->physicalName ?? 'missing',
            ),
        ), [$created->recordKey]);
        if (is_resource($ciphertext)) {
            $ciphertext = stream_get_contents($ciphertext);
        }
        self::assertIsString($ciphertext);
        self::assertStringNotContainsString('neutral-fixture-secret', $ciphertext);

        $revisionSnapshots = $database->fetchFirstColumn(sprintf(
            'SELECT snapshot FROM %s WHERE definition_id = ? AND record_id = ?',
            $tables->quoted('business_record_revisions'),
        ), [$definition->id, $created->recordKey]);
        foreach ($revisionSnapshots as $snapshot) {
            self::assertStringNotContainsString(
                'neutral-fixture-secret',
                is_string($snapshot) ? $snapshot : json_encode($snapshot, JSON_THROW_ON_ERROR),
            );
        }
    }

    /**
     * Assert that a real application mutation fails with one stable field-condition code.
     *
     * @param   callable(): mixed  $operation     Mutation expected to fail validation.
     * @param   string             $expectedCode  Stable violation code that must be present.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertValidationCode(callable $operation, string $expectedCode): void
    {
        try {
            $operation();
            self::fail('A condition-rejected runtime mutation was accepted.');
        } catch (BusinessRecordValidationFailed $exception) {
            self::assertContains(
                $expectedCode,
                array_map(
                    static fn (ValidationViolation $violation): string => $violation->code,
                    $exception->violations,
                ),
            );
        }
    }
}
