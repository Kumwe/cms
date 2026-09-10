<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessRecord;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Kumwe\App\Kernel\Container;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Application\Automation\IdempotencyKey;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordUniqueConflict;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessNumberSequenceAllocator;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordIdempotencyRepository;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordWriteRepository;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Pins the ADR 0008 offline-numbering contract: sync-time allocation guarded by a client reference.
 *
 * A disconnected terminal carries only its own client reference; the human document number is allocated
 * by the receiving create command at synchronisation time, by the unchanged gapless allocator. The
 * reference is a declared field whose uniqueness scope is the definition's own tenancy — the unique index
 * the schema compiler leads with the scope columns — so a re-submitted sync cannot double-create even
 * after the idempotency ledger's bounded replay window has passed or when a different operator replays
 * it. The two mechanisms compose: the idempotency key replays the stored outcome in-window, and the
 * reference refuses a duplicate durably, with the refused command handing its allocated number straight
 * back so the run keeps no hole. A create that carries no reference is untouched by any of this.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineBusinessNumberSequenceAllocator::class)]
#[CoversClass(DoctrineBusinessRecordIdempotencyRepository::class)]
#[CoversClass(DoctrineBusinessRecordWriteRepository::class)]
final class BusinessRecordClientReferenceIntegrationTest extends TestCase
{
    /**
     * A repeated sync under its own idempotency key replays the stored outcome and allocates nothing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testARepeatedSyncUnderItsIdempotencyKeyReplaysInsteadOfCreatingASecondRecord(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $definition = $this->install($container, $context);
        $records = $this->records($container);
        $recordId = Uuid::uuid7()->toString();
        $command = static fn (): CreateRecordCommand => new CreateRecordCommand(
            $context,
            $definition->id,
            [
                ...NeutralBusinessFixture::recordValues('Replayed sync original'),
                'client_reference' => 'terminal-ref-' . $recordId,
            ],
            IdempotencyKey::fromString('client-reference-replay-' . $recordId),
            recordId: $recordId,
        );

        $first = $records->create($command());
        $second = $records->create($command());

        self::assertFalse($first->replayed);
        self::assertTrue($second->replayed, 'The same key in its window replays the stored outcome.');
        self::assertSame($first->recordId, $second->recordId);
        self::assertSame($first->version, $second->version);
        self::assertSame(
            1,
            $this->counter($container, $definition->id, $this->period()),
            'A replay allocates nothing; the counter still stands where the original create left it.',
        );
    }

    /**
     * A re-submitted sync under a fresh key cannot double-create, and its refused number returns unburned.
     *
     * A fresh key is what a sync looks like once the idempotency window has passed or another operator
     * replays the terminal's batch: the ledger no longer answers, so the declared reference must. The
     * duplicate is refused by the unique index inside the write transaction, exactly one record carries
     * the reference, and the number the refused command had been handed rolls back with it — the next
     * distinct sync takes it, leaving the run contiguous.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAReSubmittedSyncUnderAFreshKeyCannotDoubleCreateAndBurnsNoNumber(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $definition = $this->install($container, $context);
        $records = $this->records($container);
        $reference = 'terminal-ref-' . Uuid::uuid7()->toString();

        $firstId = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $definition->id,
            [...NeutralBusinessFixture::recordValues('Offline capture original'), 'client_reference' => $reference],
            IdempotencyKey::fromString('client-reference-first-' . Uuid::uuid7()->toString()),
            recordId: $firstId,
        ));
        $view = $records->read(new ReadRecordQuery($context, $definition->id, $firstId));
        self::assertSame(sprintf('SEQ-%s-0001', $this->period()), $view->values['document_number'] ?? null);
        self::assertSame($reference, $view->values['client_reference'] ?? null);

        try {
            $records->create(new CreateRecordCommand(
                $context,
                $definition->id,
                [
                    ...NeutralBusinessFixture::recordValues('Offline capture duplicate'),
                    'client_reference' => $reference,
                ],
                IdempotencyKey::fromString('client-reference-second-' . Uuid::uuid7()->toString()),
                recordId: Uuid::uuid7()->toString(),
            ));
            self::fail('A second create carrying the same client reference must be refused.');
        } catch (BusinessRecordUniqueConflict $conflict) {
            self::assertNull($conflict->field, 'A record-table unique violation names no relationship handle.');
        }
        self::assertSame(
            1,
            $this->counter($container, $definition->id, $this->period()),
            'The refused duplicate rolled its allocation back with the rest of its transaction.',
        );

        $thirdId = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $definition->id,
            [
                ...NeutralBusinessFixture::recordValues('Offline capture successor'),
                'client_reference' => 'terminal-ref-' . Uuid::uuid7()->toString(),
            ],
            IdempotencyKey::fromString('client-reference-third-' . Uuid::uuid7()->toString()),
            recordId: $thirdId,
        ));
        $successor = $records->read(new ReadRecordQuery($context, $definition->id, $thirdId));
        self::assertSame(
            sprintf('SEQ-%s-0002', $this->period()),
            $successor->values['document_number'] ?? null,
            'The next distinct sync takes the number the refused duplicate handed back: no hole in the run.',
        );
    }

    /**
     * A create carrying no client reference behaves exactly as before the field existed.
     *
     * Two referenceless creates coexist under the unique index — absence is not a value two rows contend
     * for — and each is numbered at creation time exactly as today.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACreateWithoutAClientReferenceBehavesExactlyAsToday(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $definition = $this->install($container, $context);
        $records = $this->records($container);

        foreach ([1, 2] as $index) {
            $recordId = Uuid::uuid7()->toString();
            $records->create(new CreateRecordCommand(
                $context,
                $definition->id,
                NeutralBusinessFixture::recordValues(sprintf('Connected capture %d', $index)),
                IdempotencyKey::fromString('client-reference-none-' . Uuid::uuid7()->toString()),
                recordId: $recordId,
            ));
            $view = $records->read(new ReadRecordQuery($context, $definition->id, $recordId));
            self::assertSame(sprintf('SEQ-%s-%04d', $this->period(), $index), $view->values['document_number'] ?? null);
            self::assertNull($view->values['client_reference'] ?? null);
        }
    }

    /**
     * Install the fixture definition carrying an allocated number and a declared client reference.
     *
     * The reference field is the ADR 0008 declaration verbatim: an ordinary caller-writable field,
     * optional because a connected create has no reference, unique within the definition's tenancy
     * scope, and immutable after create so the dedupe key can never be edited out from under a later
     * re-submission.
     *
     * @param   Container         $container  Integration container.
     * @param   ExecutionContext  $context    Trusted administrator context.
     *
     * @return  EntityTypeDefinition  The published, installed fixture definition.
     *
     * @since   2.0.0
     */
    private function install(Container $container, ExecutionContext $context): EntityTypeDefinition
    {
        $document = NeutralBusinessFixture::document($this->suffix(), Uuid::uuid7()->toString());
        $document['fields'][] = [
            'handle' => 'document_number',
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
        $document['fields'][] = [
            'handle' => 'client_reference',
            'label' => 'Client reference',
            'type' => 'core.text',
            'required' => false,
            'nullable' => true,
            'length' => 64,
            'unique' => true,
            'indexed' => true,
            'immutable_after_create' => true,
            'filterable' => true,
        ];

        return NeutralBusinessFixture::install($container, $context, $document);
    }

    /**
     * Read the committed counter value, or zero when the counter has no row yet.
     *
     * @param   Container  $container     Integration container.
     * @param   string     $definitionId  Definition whose sequence counter is read.
     * @param   string     $period        Period key of the counter being read.
     *
     * @return  int  The stored `current_value`, or zero before the first allocation.
     *
     * @since   2.0.0
     */
    private function counter(Container $container, string $definitionId, string $period): int
    {
        $stored = $this->connection($container)->fetchOne(sprintf(
            'SELECT current_value FROM %s WHERE definition_id = ? AND field_handle = ? AND period_key = ?',
            $this->tables($container)->quoted('business_number_sequences'),
        ), [$definitionId, 'document_number', $period]);

        return $stored === false ? 0 : (int) $stored;
    }

    /**
     * Name the calendar period the yearly fixture sequence allocates under right now.
     *
     * @return  string  The current UTC year.
     *
     * @since   2.0.0
     */
    private function period(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y');
    }

    /**
     * Mint a fresh fixture suffix so each run installs its own definition and its own empty counter.
     *
     * @return  string  Twelve lowercase characters unique to this run.
     *
     * @since   2.0.0
     */
    private function suffix(): string
    {
        return strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
    }

    /**
     * Resolve the record service from the integration container.
     *
     * @param   Container  $container  Integration container.
     *
     * @return  BusinessRecordService  The installed record service.
     *
     * @since   2.0.0
     */
    private function records(Container $container): BusinessRecordService
    {
        $records = $container->get(BusinessRecordService::class);
        if (!$records instanceof BusinessRecordService) {
            throw new RuntimeException('The business record service is unavailable.');
        }

        return $records;
    }

    /**
     * Resolve the live integration connection from the container.
     *
     * @param   Container  $container  Integration container.
     *
     * @return  Connection  The installation's connection.
     *
     * @since   2.0.0
     */
    private function connection(Container $container): Connection
    {
        $connection = $container->get(Connection::class);
        if (!$connection instanceof Connection) {
            throw new RuntimeException('The integration connection is unavailable.');
        }

        return $connection;
    }

    /**
     * Resolve the prefixed table-name map from the container.
     *
     * @param   Container  $container  Integration container.
     *
     * @return  TableNames  The installation's table map.
     *
     * @since   2.0.0
     */
    private function tables(Container $container): TableNames
    {
        $tables = $container->get(TableNames::class);
        if (!$tables instanceof TableNames) {
            throw new RuntimeException('The integration table map is unavailable.');
        }

        return $tables;
    }
}
