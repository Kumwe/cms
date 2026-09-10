<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessRecord;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Kumwe\App\Kernel\Container;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Application\Automation\IdempotencyKey;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\Sequence\Contract\NumberSequenceAllocator;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\UpdateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable;
use Kumwe\Sequence\Exception\NumberSequenceUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordValidationFailed;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\App\BusinessRecord\Application\ValidationViolation;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessNumberSequenceAllocator;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RuntimeException;

#[CoversClass(DoctrineBusinessNumberSequenceAllocator::class)]
#[CoversClass(BusinessRecordService::class)]
#[CoversClass(BusinessRecordValidationFailed::class)]
final class BusinessNumberSequenceContentionIntegrationTest extends TestCase
{
    private const FIELD = 'document_number';

    public function testTheCounterRowIsHeldExclusivelyUntilTheAllocatingTransactionEnds(): void
    {
        $environment = Environment::fromGlobals();
        $primary = TestKernelFactory::create($environment);
        $database = $this->connection($primary);
        if ($database->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped(
                'SQLite has no row-level FOR UPDATE, so the blocking-allocator property is not expressible '
                . 'there; its contiguity rests on single-writer serialization and the compare-and-set instead.',
            );
        }

        $secondary = TestKernelFactory::create($environment);
        $concurrent = $this->connection($secondary);
        $allocator = $this->allocator($primary);
        $rival = $this->allocator($secondary);
        $counter = Uuid::uuid7()->toString();
        $now = new DateTimeImmutable('2026-08-14T09:00:00', new DateTimeZone('UTC'));
        $this->boundLockWait($concurrent);

        try {
            $database->beginTransaction();
            self::assertSame(1, $allocator->allocate('default', $counter, self::FIELD, '-', '2026', $now));
            $database->commit();

            $database->beginTransaction();
            self::assertSame(2, $allocator->allocate('default', $counter, self::FIELD, '-', '2026', $now));

            $concurrent->beginTransaction();
            try {
                $rival->allocate('default', $counter, self::FIELD, '-', '2026', $now);
                self::fail('A second allocator must not reach the counter while the first still holds it.');
            } catch (NumberSequenceUnavailable $refusal) {
                self::assertTrue($concurrent->isTransactionActive());
                self::assertInstanceOf(
                    DbalException::class,
                    $refusal->getPrevious(),
                    'The adapter raises the package refusal with the driver failure chained.',
                );
            }
            $concurrent->rollBack();
            $database->rollBack();

            self::assertSame(
                1,
                $this->counter($primary, $counter, '2026'),
                'A rolled-back allocation gives its number back rather than burning it.',
            );
            $concurrent->beginTransaction();
            self::assertSame(
                2,
                $rival->allocate('default', $counter, self::FIELD, '-', '2026', $now),
                'The number the rolled-back holder released is handed to the next allocator, leaving no hole.',
            );
            $concurrent->commit();
        } finally {
            if ($database->isTransactionActive()) {
                $database->rollBack();
            }
            if ($concurrent->isTransactionActive()) {
                $concurrent->rollBack();
            }
            $concurrent->close();
        }
    }

    public function testAFirstUseRaceIsArbitratedByTheCounterIdentityIndex(): void
    {
        $environment = Environment::fromGlobals();
        $primary = TestKernelFactory::create($environment);
        $database = $this->connection($primary);
        if ($database->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped(
                'SQLite serializes writers at the database rather than the row, so two sessions cannot be '
                . 'held inside a first-use insert at once to be arbitrated.',
            );
        }

        $secondary = TestKernelFactory::create($environment);
        $concurrent = $this->connection($secondary);
        $counter = Uuid::uuid7()->toString();
        $now = new DateTimeImmutable('2026-08-14T09:00:00', new DateTimeZone('UTC'));
        $this->boundLockWait($concurrent);

        try {
            $database->beginTransaction();
            self::assertSame(
                1,
                $this->allocator($primary)->allocate('default', $counter, self::FIELD, '-', '2027', $now),
                'The very first allocation of a period creates its counter row.',
            );

            $concurrent->beginTransaction();
            try {
                $this->allocator($secondary)->allocate('default', $counter, self::FIELD, '-', '2027', $now);
                self::fail('Two sessions cannot both create the same counter.');
            } catch (NumberSequenceUnavailable $refusal) {
                self::assertTrue($concurrent->isTransactionActive());
                self::assertInstanceOf(
                    DbalException::class,
                    $refusal->getPrevious(),
                    'A first-use race is reported as the package refusal over the driver failure that settled it.',
                );
            }
            $concurrent->rollBack();
            $database->commit();

            $concurrent->beginTransaction();
            self::assertSame(
                2,
                $this->allocator($secondary)->allocate('default', $counter, self::FIELD, '-', '2027', $now),
                'The loser of the first-use race replays onto the winner\'s counter, never onto a second one.',
            );
            $concurrent->rollBack();
            self::assertSame(1, $this->rowCount($primary, $counter, '2027'));
        } finally {
            if ($database->isTransactionActive()) {
                $database->rollBack();
            }
            if ($concurrent->isTransactionActive()) {
                $concurrent->rollBack();
            }
            $concurrent->close();
        }
    }

    /**
     * A create whose counter another transaction holds is refused by the record service as temporarily
     * unavailable: the adapter's package refusal is translated at the service seam, so callers keep seeing one
     * transient vocabulary, nothing is committed on either side, and the run starts at one once the holder lets go.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheRecordServiceTranslatesAHeldCounterIntoATransientRefusal(): void
    {
        $environment = Environment::fromGlobals();
        $primary = TestKernelFactory::create($environment);
        $database = $this->connection($primary);
        if ($database->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped(
                'SQLite has no row-level FOR UPDATE, so one session cannot hold the counter against another\'s create.',
            );
        }

        $context = TestKernelFactory::administratorContext($primary);
        $definition = $this->install($primary, $context);
        $secondary = TestKernelFactory::create($environment);
        $rivalContext = TestKernelFactory::administratorContext($secondary);
        $concurrent = $this->connection($secondary);
        $this->boundLockWait($concurrent);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        try {
            $database->beginTransaction();
            self::assertSame(1, $this->allocator($primary)->allocate(
                $definition->siteIdentifier,
                $definition->id,
                self::FIELD,
                '-',
                $this->period(),
                $now,
            ));
            try {
                $this->create($this->records($secondary), $rivalContext, $definition, 'Blocked create');
                self::fail('A create whose counter another transaction holds must be refused as transient.');
            } catch (BusinessRecordTemporarilyUnavailable $refusal) {
                self::assertSame(
                    'business_record.temporarily_unavailable',
                    $refusal->stableCode(),
                    'The record vocabulary reports the held counter as replayable rather than as a 409 conflict.',
                );
            }
            self::assertFalse($concurrent->isTransactionActive(), 'The refused create rolled its transaction back.');
        } finally {
            if ($database->isTransactionActive()) {
                $database->rollBack();
            }
            if ($concurrent->isTransactionActive()) {
                $concurrent->rollBack();
            }
            $concurrent->close();
        }

        self::assertSame(
            0,
            $this->counter($primary, $definition->id, $this->period()),
            'Neither the rolled-back holder nor the refused create committed a number.',
        );
        self::assertSame(
            sprintf('SEQ-%s-0001', $this->period()),
            $this->create($this->records($primary), $context, $definition, 'After release'),
            'Once the holder releases the counter, the run starts at one.',
        );
    }

    public function testInterleavedCreatesAcrossTwoKernelsProduceOneContiguousRun(): void
    {
        $environment = Environment::fromGlobals();
        $primary = TestKernelFactory::create($environment);
        $secondary = TestKernelFactory::create($environment);
        $context = TestKernelFactory::administratorContext($primary);
        $definition = $this->install($primary, $context);
        $rivalContext = TestKernelFactory::administratorContext($secondary);
        $records = $this->records($primary);
        $rivalRecords = $this->records($secondary);

        $numbers = [];
        for ($index = 0; $index < 6; ++$index) {
            $service = $index % 2 === 0 ? $records : $rivalRecords;
            $actor = $index % 2 === 0 ? $context : $rivalContext;
            $numbers[] = $this->create($service, $actor, $definition, sprintf('Interleaved %d', $index));
        }

        self::assertSame($numbers, array_values(array_unique($numbers)));
        foreach ($numbers as $position => $number) {
            self::assertSame(
                sprintf('SEQ-%s-%04d', $this->period(), $position + 1),
                $number,
                'Numbers allocated across two kernels form one contiguous run.',
            );
        }
        self::assertSame(6, $this->counter($primary, $definition->id, $this->period()));
    }

    public function testARefusedCreateReturnsItsNumberAndTheNextCreateTakesIt(): void
    {
        $environment = Environment::fromGlobals();
        $primary = TestKernelFactory::create($environment);
        $context = TestKernelFactory::administratorContext($primary);
        $definition = $this->install($primary, $context);
        $records = $this->records($primary);

        $first = $this->create($records, $context, $definition, 'Refusal baseline');
        self::assertSame(sprintf('SEQ-%s-0001', $this->period()), $first);

        try {
            $records->create(new CreateRecordCommand(
                $context,
                $definition->id,
                [...NeutralBusinessFixture::recordValues('X'), 'name' => 'X'],
                IdempotencyKey::fromString('sequence-refused-' . Uuid::uuid7()->toString()),
                recordId: Uuid::uuid7()->toString(),
            ));
            self::fail('A name below its minimum length must be refused.');
        } catch (BusinessRecordValidationFailed $exception) {
            self::assertNotSame([], $exception->violations);
        }
        self::assertSame(
            1,
            $this->counter($primary, $definition->id, $this->period()),
            'The refused command rolled its allocation back with the rest of its transaction.',
        );
        self::assertSame(
            sprintf('SEQ-%s-0002', $this->period()),
            $this->create($records, $context, $definition, 'Refusal successor'),
            'The next create takes the number the refused one gave back, leaving no hole.',
        );
    }

    public function testAnAllocatedNumberIsRefusedFromEveryCallerFacingWritePath(): void
    {
        $environment = Environment::fromGlobals();
        $primary = TestKernelFactory::create($environment);
        $context = TestKernelFactory::administratorContext($primary);
        $definition = $this->install($primary, $context);
        $records = $this->records($primary);

        try {
            $records->create(new CreateRecordCommand(
                $context,
                $definition->id,
                [
                    ...NeutralBusinessFixture::recordValues('Caller supplied number'),
                    self::FIELD => 'SEQ-1999-0001',
                ],
                IdempotencyKey::fromString('sequence-supplied-' . Uuid::uuid7()->toString()),
                recordId: Uuid::uuid7()->toString(),
            ));
            self::fail('A caller cannot supply an allocated number.');
        } catch (BusinessRecordValidationFailed $exception) {
            self::assertSame(['record'], array_map(
                static fn (ValidationViolation $violation): string => $violation->field,
                $exception->violations,
            ), 'The field is not create-writable at all, so the refusal never confirms the handle exists.');
        }
        self::assertSame(
            0,
            $this->counter($primary, $definition->id, $this->period()),
            'A refusal before the allocator runs does not touch the counter.',
        );

        $recordId = Uuid::uuid7()->toString();
        $created = $records->create(new CreateRecordCommand(
            $context,
            $definition->id,
            NeutralBusinessFixture::recordValues('Immutable number holder'),
            IdempotencyKey::fromString('sequence-immutable-' . Uuid::uuid7()->toString()),
            recordId: $recordId,
        ));
        try {
            $records->update(new UpdateRecordCommand(
                $context,
                $definition->id,
                $recordId,
                $created->version,
                [self::FIELD => 'SEQ-1999-0002'],
                IdempotencyKey::fromString('sequence-immutable-update-' . Uuid::uuid7()->toString()),
            ));
            self::fail('An allocated number cannot be changed after creation.');
        } catch (BusinessRecordValidationFailed $exception) {
            self::assertNotSame([], $exception->violations);
        }
    }

    /**
     * Install the sequence-bearing fixture definition once per kernel and hand back its published form.
     */
    private function install(Container $container, ExecutionContext $context): EntityTypeDefinition
    {
        $document = NeutralBusinessFixture::document($this->suffix(), Uuid::uuid7()->toString());
        $document['fields'][] = [
            'handle' => self::FIELD,
            'label' => 'Document number',
            'type' => 'core.sequence',
            'configuration' => [
                'scope' => 'site',
                'reset' => 'yearly',
                'prefix' => 'SEQ-',
                'padding' => 4,
                'timezone' => 'UTC',
            ],
            'required' => true,
            'nullable' => false,
            'length' => 36,
            'unique' => true,
            'indexed' => true,
            'immutable_after_create' => true,
            'server_only' => true,
            'read_only' => true,
            'create_visible' => false,
            'update_visible' => false,
            'sortable' => true,
            'filterable' => true,
        ];

        return NeutralBusinessFixture::install($container, $context, $document);
    }

    /**
     * Create one record and read the number the server allocated for it back off the stored row.
     */
    private function create(
        BusinessRecordService $records,
        ExecutionContext $context,
        EntityTypeDefinition $definition,
        string $name,
    ): string {
        $recordId = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $definition->id,
            NeutralBusinessFixture::recordValues($name),
            IdempotencyKey::fromString('sequence-' . Uuid::uuid7()->toString()),
            recordId: $recordId,
        ));
        $view = $records->read(new ReadRecordQuery($context, $definition->id, $recordId));
        $number = $view->values[self::FIELD] ?? null;
        self::assertIsString($number);

        return $number;
    }

    /**
     * Read the committed counter value, or zero when the counter has no row yet.
     */
    private function counter(Container $container, string $definitionId, string $period): int
    {
        $stored = $this->connection($container)->fetchOne(sprintf(
            'SELECT current_value FROM %s WHERE definition_id = ? AND field_handle = ? AND period_key = ?',
            $this->tables($container)->quoted('business_number_sequences'),
        ), [$definitionId, self::FIELD, $period]);

        return $stored === false ? 0 : (int) $stored;
    }

    /**
     * Count the counter rows a period holds, proving a first-use race created exactly one.
     */
    private function rowCount(Container $container, string $definitionId, string $period): int
    {
        return (int) $this->connection($container)->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE definition_id = ? AND field_handle = ? AND period_key = ?',
            $this->tables($container)->quoted('business_number_sequences'),
        ), [$definitionId, self::FIELD, $period]);
    }

    /**
     * Make a blocked session fail quickly instead of waiting out the server default.
     *
     * This is what turns a race into a deterministic interleaving: the rival session provably reaches the
     * counter row, provably cannot have it, and says so within a second rather than at the mercy of
     * scheduling.
     */
    private function boundLockWait(Connection $connection): void
    {
        $connection->executeStatement(
            $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
                ? 'SET innodb_lock_wait_timeout = 1'
                : "SET lock_timeout = '500ms'",
        );
    }

    private function period(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y');
    }

    /**
     * Mint a fresh fixture suffix so each run installs its own definition and its own empty counter.
     */
    private function suffix(): string
    {
        return strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
    }

    private function allocator(Container $container): NumberSequenceAllocator
    {
        $allocator = $container->get(NumberSequenceAllocator::class);
        if (!$allocator instanceof NumberSequenceAllocator) {
            throw new RuntimeException('The business number sequence allocator is unavailable.');
        }

        return $allocator;
    }

    private function records(Container $container): BusinessRecordService
    {
        $records = $container->get(BusinessRecordService::class);
        if (!$records instanceof BusinessRecordService) {
            throw new RuntimeException('The business record service is unavailable.');
        }

        return $records;
    }

    private function connection(Container $container): Connection
    {
        $connection = $container->get(Connection::class);
        if (!$connection instanceof Connection) {
            throw new RuntimeException('The integration connection is unavailable.');
        }

        return $connection;
    }

    private function tables(Container $container): TableNames
    {
        $tables = $container->get(TableNames::class);
        if (!$tables instanceof TableNames) {
            throw new RuntimeException('The integration table map is unavailable.');
        }

        return $tables;
    }
}
