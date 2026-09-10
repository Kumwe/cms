<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessRecord;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Kernel\Container;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\Sequence\Contract\NumberSequenceAllocator;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\DeleteRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\DocumentLineInput;
use Kumwe\App\BusinessRecord\Application\Command\WriteDocumentCommand;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessNumberSequenceAllocator;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\BusinessQueryCounter;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Proves the hot-counter properties package P4-C demands on the real create paths.
 *
 * The contention suite proves the counter's exclusivity, first-use arbitration and refusal reflow.
 * What it does not pin is the counter under production-shaped heat: how long the row is held while a
 * thousand-line posting commits, whether a sustained run on one hot counter stays contiguous through
 * the real command, and whether one legal entity's held counter can ever delay another entity's
 * commit. Durations are pinned the way this suite pins every budget — as bounded statement counts
 * and provable release points, never as wall-clock assertions that flake under CI scheduling. The
 * transition model these tests bind to is recorded in ADR 0011: the create command is the numbering
 * transition, and gapless is the single declared policy.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineBusinessNumberSequenceAllocator::class)]
#[CoversClass(BusinessRecordService::class)]
final class BusinessNumberSequenceHotPathIntegrationTest extends TestCase
{
    /**
     * Handle of the allocated sequence field every fixture definition carries.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string FIELD = 'document_number';

    /**
     * A thousand-line sequenced posting holds its counter for a bounded statement span, then lets go.
     *
     * The counter row is taken after the whole collection is validated and prepared, and is released
     * with the commit. The span between those points is pinned twice: the allocation itself costs at
     * most three statements on a warm counter, and the thousand-line collection writes inside the
     * twelve-statement budget `AggregateDocumentIntegrationTest` enforces — so the hold window is a
     * constant-plus-batched span, never a per-line one. Release is proven by a rival allocator with
     * a bounded lock wait taking the very next number immediately after the commit.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAThousandLineSequencedPostingHoldsItsCounterForABoundedSpan(): void
    {
        $environment = Environment::fromGlobals();
        $primary = TestKernelFactory::create($environment);
        $context = TestKernelFactory::administratorContext($primary);
        $records = $this->records($primary);
        $suffix = $this->suffix();
        $header = $this->installSequencedDocument($primary, $context, $suffix);
        $period = $this->period();

        $warmId = Uuid::uuid7()->toString();
        $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            ['title' => 'Warm-up posting', 'total' => '2.00'],
            $this->lines(2, $suffix . 'w'),
            NeutralBusinessFixture::idempotencyKey('seq-hot-warm-' . $suffix),
            recordId: $warmId,
        ));
        self::assertSame(
            sprintf('DOC-%s-0001', $period),
            $this->number($records, $context, $header, $warmId),
        );

        $documentId = Uuid::uuid7()->toString();
        $posted = $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            ['title' => 'Thousand-line posting', 'total' => '1000.00'],
            $this->lines(1000, $suffix . 'k'),
            NeutralBusinessFixture::idempotencyKey('seq-hot-thousand-' . $suffix),
            recordId: $documentId,
        ));
        self::assertSame(1, $posted->version, 'A sequenced thousand-line posting is still one version.');
        self::assertSame(
            sprintf('DOC-%s-0002', $period),
            $this->number($records, $context, $header, $documentId),
            'The thousand-line posting takes the next number in the same run as the warm-up.',
        );

        $secondary = TestKernelFactory::create($environment);
        $concurrent = $this->connection($secondary);
        $database = $this->connection($primary);
        if (!$database->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->boundLockWait($concurrent);
            try {
                $concurrent->beginTransaction();
                self::assertSame(
                    3,
                    $this->allocator($secondary)
                        ->allocate('default', $header->id, self::FIELD, '-', $period, $this->now()),
                    'The counter is free the moment the posting commits: a bounded-wait rival takes '
                        . 'the next number without ever being refused.',
                );
                $concurrent->rollBack();
            } finally {
                if ($concurrent->isTransactionActive()) {
                    $concurrent->rollBack();
                }
                $concurrent->close();
            }
        }

        $counter = new BusinessQueryCounter();
        $counted = $this->countedConnection($database, $counter);
        try {
            $allocator = new DoctrineBusinessNumberSequenceAllocator($counted, $this->tables($primary));
            $counted->beginTransaction();
            $counter->reset();
            self::assertSame(
                3,
                $allocator->allocate('default', $header->id, self::FIELD, '-', $period, $this->now()),
                'A warm counter allocates onto the run the committed postings established.',
            );
            $statements = $counter->queries();
            $counted->rollBack();
        } finally {
            if ($counted->isTransactionActive()) {
                $counted->rollBack();
            }
            $counted->close();
        }
        self::assertGreaterThan(0, $statements);
        self::assertLessThanOrEqual(
            3,
            $statements,
            sprintf(
                'A warm allocation must cost a constant statement span; this one issued %d statements.',
                $statements,
            ),
        );

        // A document of this size is deliberately not left behind: the integration suite shares one
        // database, and a thousand persisted lines would sit inside every later include budget.
        $records->delete(new DeleteRecordCommand(
            $context,
            $header->handle,
            $documentId,
            $posted->version,
            NeutralBusinessFixture::idempotencyKey('seq-hot-thousand-drop-' . $suffix),
        ));
    }

    /**
     * A single hot counter sustains a contiguous run through the real command across two kernels.
     *
     * This is the deterministic core of the hot-sequence worst case: forty creates through the full
     * command path, alternating two kernels onto one counter, must produce the numbers one to forty
     * with no duplicate, no hole and no reordering — the sustained-load latency profile of the same
     * shape lives in the capacity harness as the `hot_sequence_commit` operation class.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAHotCounterSustainsAContiguousRunAcrossInterleavedSessions(): void
    {
        $environment = Environment::fromGlobals();
        $primary = TestKernelFactory::create($environment);
        $secondary = TestKernelFactory::create($environment);
        $context = TestKernelFactory::administratorContext($primary);
        $rivalContext = TestKernelFactory::administratorContext($secondary);
        $definition = $this->installSequencedRecord($primary, $context, 'default');
        $records = $this->records($primary);
        $rivalRecords = $this->records($secondary);
        $period = $this->period();

        $numbers = [];
        for ($index = 0; $index < 40; ++$index) {
            $service = $index % 2 === 0 ? $records : $rivalRecords;
            $actor = $index % 2 === 0 ? $context : $rivalContext;
            $numbers[] = $this->create($service, $actor, $definition, sprintf('Hot %d', $index));
        }

        self::assertSame($numbers, array_values(array_unique($numbers)), 'A hot counter never duplicates.');
        foreach ($numbers as $position => $number) {
            self::assertSame(
                sprintf('SEQ-%s-%04d', $period, $position + 1),
                $number,
                'A sustained interleaved run on one hot counter stays contiguous and ordered.',
            );
        }
        self::assertSame(40, $this->counter($primary, $definition->id, $period));
    }

    /**
     * Two legal-entity sites number independently, and one held counter never delays the other.
     *
     * Each site is its own counter coordinate, so each entity's run starts at one. The isolation is
     * then proven under contention: while one session holds site A's counter open mid-transaction,
     * site B's full create command — on a bounded-lock-wait connection that would refuse rather than
     * queue — commits immediately. A blocked cross-entity commit would surface here as the refusal
     * the contention suite pins for same-counter rivals.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTwoLegalEntitySitesNumberIndependentlyEndToEnd(): void
    {
        $environment = Environment::fromGlobals();
        $primary = TestKernelFactory::create($environment);
        $administrator = TestKernelFactory::administratorContext($primary);
        $principal = $administrator->principal();
        self::assertNotNull($principal);
        $suffix = $this->suffix();
        $siteA = 'seqenta' . $suffix;
        $siteB = 'seqentb' . $suffix;
        $this->createSite($primary, $siteA);
        $this->createSite($primary, $siteB);
        $contextA = $principal->context(
            SiteContext::fromString($siteA),
            AuthenticationStrength::Password,
            'integration-sequence-site-a-' . $suffix,
        );
        $contextB = $principal->context(
            SiteContext::fromString($siteB),
            AuthenticationStrength::Password,
            'integration-sequence-site-b-' . $suffix,
        );
        $definitionA = $this->installSequencedRecord($primary, $contextA, $siteA);
        $definitionB = $this->installSequencedRecord($primary, $contextB, $siteB);
        $records = $this->records($primary);
        $period = $this->period();

        self::assertSame(
            sprintf('SEQ-%s-0001', $period),
            $this->create($records, $contextA, $definitionA, 'Entity A first'),
        );
        self::assertSame(
            sprintf('SEQ-%s-0002', $period),
            $this->create($records, $contextA, $definitionA, 'Entity A second'),
        );
        self::assertSame(
            sprintf('SEQ-%s-0001', $period),
            $this->create($records, $contextB, $definitionB, 'Entity B first'),
            'The second legal entity starts its own run at one, untouched by the first.',
        );

        $database = $this->connection($primary);
        if ($database->getDatabasePlatform() instanceof SQLitePlatform) {
            return;
        }
        $holder = TestKernelFactory::create($environment);
        $holding = $this->connection($holder);
        $secondary = TestKernelFactory::create($environment);
        $rivalRecords = $this->records($secondary);
        $rivalPrincipal = TestKernelFactory::administratorContext($secondary)->principal();
        self::assertNotNull($rivalPrincipal);
        $rivalContextB = $rivalPrincipal->context(
            SiteContext::fromString($siteB),
            AuthenticationStrength::Password,
            'integration-sequence-site-b-rival-' . $suffix,
        );
        $this->boundLockWait($this->connection($secondary));
        try {
            $holding->beginTransaction();
            self::assertSame(
                3,
                $this->allocator($holder)
                    ->allocate($siteA, $definitionA->id, self::FIELD, '-', $period, $this->now()),
                'The holder takes entity A\'s counter and keeps it open across the rival commit.',
            );
            self::assertSame(
                sprintf('SEQ-%s-0002', $period),
                $this->create($rivalRecords, $rivalContextB, $definitionB, 'Entity B under A contention'),
                'Entity B commits instantly while entity A\'s counter is held: the partitions share nothing.',
            );
            $holding->rollBack();
        } finally {
            if ($holding->isTransactionActive()) {
                $holding->rollBack();
            }
            $holding->close();
        }
    }

    /**
     * Install a plain sequenced record definition owned by the given site.
     *
     * @param   Container         $container  Booted kernel container.
     * @param   ExecutionContext  $context    Actor authorized on the owning site.
     * @param   string            $site       Site identifier the definition is owned by.
     *
     * @return  EntityTypeDefinition  The installed active definition.
     *
     * @since   2.0.0
     */
    private function installSequencedRecord(
        Container $container,
        ExecutionContext $context,
        string $site,
    ): EntityTypeDefinition {
        $suffix = $this->suffix();
        $document = NeutralBusinessFixture::document($suffix, Uuid::uuid7()->toString());
        if ($site !== 'default') {
            $handle = $document['handle'];
            self::assertIsString($handle);
            $document['site'] = $site;
            $document['owner'] = ['type' => 'site', 'identifier' => $site];
            $document['handle'] = 'site.' . $site . '.' . $handle;
        }
        $document['fields'][] = $this->sequenceField('SEQ-');

        return NeutralBusinessFixture::install($container, $context, $document);
    }

    /**
     * Install the line and header definitions of a sequenced aggregate document fixture.
     *
     * @param   Container         $container  Booted kernel container.
     * @param   ExecutionContext  $context    Trusted test actor.
     * @param   string            $suffix     Collision-resistant fixture suffix.
     *
     * @return  EntityTypeDefinition  The installed header definition carrying the sequence field.
     *
     * @since   2.0.0
     */
    private function installSequencedDocument(
        Container $container,
        ExecutionContext $context,
        string $suffix,
    ): EntityTypeDefinition {
        $lineDocument = NeutralBusinessFixture::documentLineDocument($suffix, Uuid::uuid7()->toString());
        $lineHandle = $lineDocument['handle'];
        self::assertIsString($lineHandle);
        $headerDocument = NeutralBusinessFixture::documentHeaderDocument(
            $suffix,
            Uuid::uuid7()->toString(),
            $lineHandle,
        );
        $headerDocument['fields'][] = $this->sequenceField('DOC-');
        NeutralBusinessFixture::install($container, $context, $lineDocument);

        return NeutralBusinessFixture::install($container, $context, $headerDocument);
    }

    /**
     * The one sequence field shape every fixture here allocates through.
     *
     * @param   string  $prefix  Number prefix distinguishing the record and document fixtures.
     *
     * @return  array<string, mixed>  The field declaration.
     *
     * @since   2.0.0
     */
    private function sequenceField(string $prefix): array
    {
        return [
            'handle' => self::FIELD,
            'label' => 'Document number',
            'type' => 'core.sequence',
            'configuration' => [
                'scope' => 'site',
                'reset' => 'yearly',
                'prefix' => $prefix,
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
    }

    /**
     * Create one record through the real command and read its allocated number back.
     *
     * @param   BusinessRecordService  $records     Live record service.
     * @param   ExecutionContext       $context     Acting principal.
     * @param   EntityTypeDefinition   $definition  Installed sequenced definition.
     * @param   string                 $name        Distinguishing record name.
     *
     * @return  string  The server-allocated number.
     *
     * @since   2.0.0
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
            NeutralBusinessFixture::idempotencyKey('seq-hot-' . Uuid::uuid7()->toString()),
            recordId: $recordId,
        ));
        $view = $records->read(new ReadRecordQuery($context, $definition->id, $recordId));
        $number = $view->values[self::FIELD] ?? null;
        self::assertIsString($number);

        return $number;
    }

    /**
     * Read one document header's allocated number back through the ordinary read path.
     *
     * @param   BusinessRecordService  $records     Live record service.
     * @param   ExecutionContext       $context     Trusted test actor.
     * @param   EntityTypeDefinition   $header      Installed header definition.
     * @param   string                 $documentId  Identity of the posted document.
     *
     * @return  string  The server-allocated number.
     *
     * @since   2.0.0
     */
    private function number(
        BusinessRecordService $records,
        ExecutionContext $context,
        EntityTypeDefinition $header,
        string $documentId,
    ): string {
        $view = $records->read(new ReadRecordQuery($context, $header->handle, $documentId));
        $number = $view->values[self::FIELD] ?? null;
        self::assertIsString($number);

        return $number;
    }

    /**
     * Insert one enabled site row, giving the test a second legal entity to number under.
     *
     * @param   Container  $container  Booted kernel container.
     * @param   string     $site       Site identifier to create.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function createSite(Container $container, string $site): void
    {
        $this->connection($container)->insert($this->tables($container)->raw('sites'), [
            'identifier' => $site,
            'name' => 'Sequence entity ' . $site,
            'created_at' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
            'enabled' => true,
            'policy_generation' => 1,
        ], [
            'created_at' => Types::DATETIME_IMMUTABLE,
            'enabled' => Types::BOOLEAN,
            'policy_generation' => Types::BIGINT,
        ]);
    }

    /**
     * Read the committed counter value, or zero when the counter has no row yet.
     *
     * @param   Container  $container     Booted kernel container.
     * @param   string     $definitionId  Counter's definition coordinate.
     * @param   string     $period        Counter's period key.
     *
     * @return  int  The committed counter value.
     *
     * @since   2.0.0
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
     * Open a second connection to the same database that counts every statement it executes.
     *
     * @param   Connection            $source   Live connection supplying the parameters.
     * @param   BusinessQueryCounter  $counter  Statement sink the new connection reports into.
     *
     * @return  Connection  The counted connection.
     *
     * @since   2.0.0
     */
    private function countedConnection(Connection $source, BusinessQueryCounter $counter): Connection
    {
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($counter)]);

        return DriverManager::getConnection($source->getParams(), $configuration);
    }

    /**
     * Make a blocked session fail quickly instead of waiting out the server default.
     *
     * @param   Connection  $connection  The rival session to bound.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function boundLockWait(Connection $connection): void
    {
        $connection->executeStatement(
            $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
                ? 'SET innodb_lock_wait_timeout = 1'
                : "SET lock_timeout = '500ms'",
        );
    }

    /**
     * Build a consistent collection of the given size, each line worth one unit.
     *
     * @param   int     $size    How many lines the document carries.
     * @param   string  $suffix  Uniqueness stem for the line codes.
     *
     * @return  list<DocumentLineInput>  The submitted collection, in stored order.
     *
     * @since   2.0.0
     */
    private function lines(int $size, string $suffix): array
    {
        $lines = [];
        for ($index = 1; $index <= $size; ++$index) {
            $lines[] = new DocumentLineInput([
                'code' => 'seq-line-' . $suffix . '-' . $index,
                'description' => 'Line ' . $index,
                'amount' => '1.00',
            ]);
        }

        return $lines;
    }

    /**
     * The one instant every seam-level allocation in this class stamps.
     *
     * @return  DateTimeImmutable  A fixed UTC instant inside the current period.
     *
     * @since   2.0.0
     */
    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * The current UTC year, which is the yearly-reset counters' period key.
     *
     * @return  string  Four-digit period key.
     *
     * @since   2.0.0
     */
    private function period(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y');
    }

    /**
     * Mint a fresh fixture suffix so each install owns its own definition and empty counter.
     *
     * @return  string  Collision-resistant lowercase suffix.
     *
     * @since   2.0.0
     */
    private function suffix(): string
    {
        return strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
    }

    /**
     * Resolve the live allocator from one kernel.
     *
     * @param   Container  $container  Booted kernel container.
     *
     * @return  NumberSequenceAllocator  The wired allocator.
     *
     * @since   2.0.0
     */
    private function allocator(Container $container): NumberSequenceAllocator
    {
        $allocator = $container->get(NumberSequenceAllocator::class);
        if (!$allocator instanceof NumberSequenceAllocator) {
            throw new RuntimeException('The business number sequence allocator is unavailable.');
        }

        return $allocator;
    }

    /**
     * Resolve the live record service from one kernel.
     *
     * @param   Container  $container  Booted kernel container.
     *
     * @return  BusinessRecordService  The wired record service.
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
     * Resolve one kernel's database connection.
     *
     * @param   Container  $container  Booted kernel container.
     *
     * @return  Connection  The live connection.
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
     * Resolve one kernel's table name map.
     *
     * @param   Container  $container  Booted kernel container.
     *
     * @return  TableNames  The live table map.
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
