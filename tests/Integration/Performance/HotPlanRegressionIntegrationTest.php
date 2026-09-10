<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Performance;

use Doctrine\DBAL\Connection;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\DocumentLineInput;
use Kumwe\App\BusinessRecord\Application\Command\DocumentWriteIntent;
use Kumwe\App\BusinessRecord\Application\Command\WriteDocumentCommand;
use Kumwe\App\BusinessRecord\Application\Query\BrowseRecordsQuery;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaInstallationRepository;
use Kumwe\App\BusinessSchema\Infrastructure\Schema\CanonicalDefinitionPhysicalSchemaCompiler;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\ContainerConnectionCounter;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Captures the declared hot plans from the running system and refuses one with no indexed path.
 *
 * The capacity contract forbids a full scan, sort or spill on "a declared hot plan", and until
 * `docs/quality/hot-plans.json` existed nothing declared one, so the rule enforced nothing. This suite is
 * the enforcement the declaration makes possible: it runs the real operation, takes the SQL the runtime
 * actually compiled — never a hand-written imitation — records the engine's own plan for it under
 * `build/perf/`, and asserts the driving table is reachable through an index the planner can see.
 *
 * The assertion is deliberately about the access *path*, not the optimizer's choice. On a CI-sized
 * dataset every planner is entitled to prefer a scan, so asserting its choice would be flaky where it
 * held at all; what regresses deterministically is the path itself — a dropped index, or a predicate
 * rewritten so no index applies — and that is what fails here, on every engine the matrix runs.
 *
 * @since  2.0.0
 */
#[CoversClass(CanonicalDefinitionPhysicalSchemaCompiler::class)]
final class HotPlanRegressionIntegrationTest extends TestCase
{
    /**
     * Every declared hot plan is captured from a live operation and has an indexed access path.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryDeclaredHotPlanKeepsAnIndexedAccessPath(): void
    {
        $declared = $this->declaredPlans();
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $this->records($container);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $header = $this->install($container, $context, $suffix);
        $documentId = Uuid::uuid7()->toString();
        $created = $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            ['title' => 'Plan capture document', 'total' => '50.00'],
            $this->lines(50, $suffix),
            NeutralBusinessFixture::idempotencyKey('plan-doc-' . $suffix),
            recordId: $documentId,
        ));

        [$recordTable] = $this->physical($container, $header, 'record');
        [$lineTable] = $this->physical($container, $header, 'line:lines');
        $tables = $this->service($container, TableNames::class);
        self::assertInstanceOf(TableNames::class, $tables);
        $idempotencyTable = $tables->raw('business_command_idempotency');

        $counter = ContainerConnectionCounter::wrap($container);
        $captured = [];

        $counter->reset();
        $records->read(new ReadRecordQuery($context, $header->handle, $documentId));
        $captured['record_identity_lookup'] = $this->firstSelectOn($counter->statements(), $recordTable);

        $counter->reset();
        $records->browse(new BrowseRecordsQuery($context, $header->handle, new RecordQuerySpecification()));
        $captured['policy_filtered_page'] = $this->firstSelectOn($counter->statements(), $recordTable);

        $counter->reset();
        $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            ['title' => 'Plan capture document', 'total' => '50.00'],
            $this->lines(50, $suffix),
            NeutralBusinessFixture::idempotencyKey('plan-doc-amend-' . $suffix),
            DocumentWriteIntent::Amend,
            $created->version,
            $documentId,
        ));
        $captured['owned_lines_for_document'] = $this->firstSelectOn($counter->statements(), $lineTable);
        $captured['idempotency_claim_lookup'] = $this->firstSelectOn($counter->statements(), $idempotencyTable);

        $drivingTables = [
            'record_identity_lookup' => $recordTable,
            'policy_filtered_page' => $recordTable,
            'owned_lines_for_document' => $lineTable,
            'idempotency_claim_lookup' => $idempotencyTable,
        ];
        $connection = $this->connection($container);
        $report = [];
        foreach ($declared as $plan) {
            $name = $plan['name'];
            self::assertArrayHasKey($name, $captured, sprintf('Declared hot plan %s was never captured.', $name));
            $statement = $captured[$name];
            self::assertNotNull($statement, sprintf('No live statement was captured for hot plan %s.', $name));
            $explained = $this->explain($connection, $statement['sql'], $statement['params']);
            $report[$name] = [
                'operation_class' => $plan['operation_class'],
                'sql' => $statement['sql'],
                'plan' => $explained,
            ];
            $this->assertIndexedPath($connection, $explained, $drivingTables[$name], $name);
        }

        if (!is_dir('build/perf')) {
            mkdir('build/perf', 0775, true);
        }
        file_put_contents(
            sprintf('build/perf/plans-%s.json', $this->engineLabel($connection)),
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }

    /**
     * Read and validate the declared hot-plan registry.
     *
     * @return  list<array{name: string, operation_class: string, driving_table: string}>  Declared plans.
     *
     * @since   2.0.0
     */
    private function declaredPlans(): array
    {
        $document = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/docs/quality/hot-plans.json'),
            true,
        );
        self::assertIsArray($document);
        self::assertArrayHasKey('plans', $document);
        self::assertIsArray($document['plans']);
        self::assertNotSame([], $document['plans'], 'A hot-plan registry declaring nothing enforces nothing.');
        $plans = [];
        foreach ($document['plans'] as $plan) {
            self::assertIsArray($plan);
            self::assertIsString($plan['name'] ?? null);
            self::assertIsString($plan['operation_class'] ?? null);
            self::assertIsString($plan['driving_table'] ?? null);
            $plans[] = [
                'name' => $plan['name'],
                'operation_class' => $plan['operation_class'],
                'driving_table' => $plan['driving_table'],
            ];
        }

        return $plans;
    }

    /**
     * The first captured SELECT that touches one physical table.
     *
     * @param   list<array{sql: string, params: array<int|string, mixed>}>  $statements  Captured statements.
     * @param   string                                                      $table       Physical table name.
     *
     * @return  array{sql: string, params: array<int|string, mixed>}|null  The statement, or null.
     *
     * @since   2.0.0
     */
    private function firstSelectOn(array $statements, string $table): ?array
    {
        foreach ($statements as $statement) {
            if (
                str_starts_with(ltrim($statement['sql']), 'SELECT')
                && str_contains($statement['sql'], $table)
            ) {
                return $statement;
            }
        }

        return null;
    }

    /**
     * Ask the current engine for its plan of one captured statement, parameters substituted as literals.
     *
     * @param   Connection                    $connection  Live connection of the engine under test.
     * @param   string                        $sql         Captured SQL with positional placeholders.
     * @param   array<int|string, mixed>      $params      Bound parameters, in placeholder order.
     *
     * @return  array<int|string, mixed>  Decoded plan rows as the engine reported them.
     *
     * @since   2.0.0
     */
    private function explain(Connection $connection, string $sql, array $params): array
    {
        $literal = $sql;
        foreach ($params as $value) {
            $replacement = match (true) {
                $value === null => 'NULL',
                is_int($value), is_float($value) => (string) $value,
                is_bool($value) => $value ? 'TRUE' : 'FALSE',
                $value instanceof \DateTimeInterface => $connection->quote($value->format('Y-m-d H:i:s')),
                is_array($value) => implode(', ', array_map(
                    static fn (mixed $item): string => $connection->quote((string) $item),
                    $value,
                )),
                default => $connection->quote((string) $value),
            };
            $position = strpos($literal, '?');
            self::assertNotFalse($position, 'More parameters were bound than the statement has placeholders.');
            $literal = substr_replace($literal, $replacement, $position, 1);
        }

        if ($this->postgres($connection)) {
            $connection->beginTransaction();
            try {
                $connection->executeStatement('SET LOCAL enable_seqscan = off');
                $rows = $connection->fetchAllAssociative('EXPLAIN (FORMAT JSON) ' . $literal);
            } finally {
                $connection->rollBack();
            }

            return $rows;
        }

        return $connection->fetchAllAssociative('EXPLAIN FORMAT=JSON ' . $literal);
    }

    /**
     * Refuse a plan whose driving table has no indexed access path the planner can see.
     *
     * On PostgreSQL sequential scans were disabled for the capture, so an index-capable plan shows an
     * index node for the table; a remaining Seq Scan means no index applies to the predicate at all. On
     * MariaDB and MySQL the JSON plan is refused only when the table is read with access type ALL while
     * the planner lists no usable key — the deterministic signature of a missing or inapplicable index,
     * with the optimizer's volume-dependent preference left out of the verdict.
     *
     * @param   Connection                $connection  Live connection, deciding the dialect.
     * @param   array<int|string, mixed>  $rows        Plan rows as the engine reported them.
     * @param   string                    $table       Physical name of the driving table.
     * @param   string                    $name        Declared plan name, for the failure message.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertIndexedPath(Connection $connection, array $rows, string $table, string $name): void
    {
        $plan = strtolower((string) json_encode($rows));
        $needle = strtolower($table);
        if ($this->postgres($connection)) {
            $indexed = str_contains($plan, 'index scan') || str_contains($plan, 'index only scan')
                || str_contains($plan, 'bitmap index scan');
            self::assertTrue(
                $indexed && !str_contains($plan, sprintf('"seq scan","relation name":"%s"', $needle)),
                sprintf('Hot plan %s reads %s with no indexed access path.', $name, $table),
            );

            return;
        }
        $decoded = json_decode((string) ($rows[0]['EXPLAIN'] ?? json_encode($rows)), true);
        $flat = strtolower((string) json_encode($decoded ?? $rows));
        $fullScan = str_contains($flat, '"access_type":"all"');
        $keyless = !str_contains($flat, '"key":') && !str_contains($flat, '"possible_keys":');
        self::assertFalse(
            $fullScan && $keyless,
            sprintf('Hot plan %s reads %s by full scan with no usable key.', $name, $table),
        );
    }

    /**
     * Name the engine for the capture artifact from the connection itself, never from the environment.
     *
     * @param   Connection  $connection  Live connection of the engine under test.
     *
     * @return  string  Engine label for the plan artifact's file name.
     *
     * @since   2.0.0
     */
    private function engineLabel(Connection $connection): string
    {
        $platform = strtolower($connection->getDatabasePlatform()::class);

        return match (true) {
            str_contains($platform, 'postgres') => 'pgsql',
            str_contains($platform, 'mariadb') => 'mariadb',
            default => 'mysql',
        };
    }

    /**
     * Whether the connection speaks the PostgreSQL dialect.
     *
     * @param   Connection  $connection  Live connection of the engine under test.
     *
     * @return  bool  True on PostgreSQL.
     *
     * @since   2.0.0
     */
    private function postgres(Connection $connection): bool
    {
        return str_contains(strtolower($connection->getDatabasePlatform()::class), 'postgres');
    }

    /**
     * Install a fresh header and line document pair this run alone captures plans against.
     *
     * The shared fixture handles are deliberately not reused: an already-installed definition keeps the
     * table its own compiler generation produced, and this suite exists to hold the *current* compiler's
     * output to the indexed-path rule.
     *
     * @param   Container         $container  Live integration container.
     * @param   ExecutionContext  $context    Administrator context the installation runs as.
     * @param   string            $suffix     Uniqueness stem giving this run its own definitions.
     *
     * @return  EntityTypeDefinition  Installed header definition.
     *
     * @since   2.0.0
     */
    private function install(
        Container $container,
        ExecutionContext $context,
        string $suffix,
    ): EntityTypeDefinition {
        $lineDocument = NeutralBusinessFixture::documentLineDocument(
            $suffix,
            Uuid::uuid7()->toString(),
        );
        $lineHandle = $lineDocument['handle'];
        self::assertIsString($lineHandle);
        $headerDocument = NeutralBusinessFixture::documentHeaderDocument(
            $suffix,
            Uuid::uuid7()->toString(),
            $lineHandle,
        );
        NeutralBusinessFixture::install($container, $context, $lineDocument);

        return NeutralBusinessFixture::install($container, $context, $headerDocument);
    }

    /**
     * Build a consistent collection of the given size, each line worth one unit.
     *
     * @param   int     $size    How many lines the document carries.
     * @param   string  $suffix  Uniqueness stem for the line codes.
     *
     * @return  list<DocumentLineInput>  The submitted collection, in the order it is to be stored.
     *
     * @since   2.0.0
     */
    private function lines(int $size, string $suffix): array
    {
        $lines = [];
        for ($index = 1; $index <= $size; ++$index) {
            $lines[] = new DocumentLineInput([
                'code' => 'plan-' . $suffix . '-' . $index,
                'description' => 'Plan line ' . $index,
                'amount' => '1.00',
            ]);
        }

        return $lines;
    }

    /**
     * Resolve one logical table of the installed header definition to its physical name.
     *
     * @param   Container             $container  Live integration container.
     * @param   EntityTypeDefinition  $header     Installed header definition.
     * @param   string                $logical    Logical table name in the installed blueprint.
     *
     * @return  array{string}  The physical table name.
     *
     * @since   2.0.0
     */
    private function physical(Container $container, EntityTypeDefinition $header, string $logical): array
    {
        $repository = $this->service($container, BusinessSchemaInstallationRepository::class);
        self::assertInstanceOf(BusinessSchemaInstallationRepository::class, $repository);
        $installation = $repository->find($header->id);
        self::assertNotNull($installation);
        $table = $installation->blueprint->table($logical);
        self::assertNotNull($table);

        return [$table->physicalName];
    }

    /**
     * Resolve a shared service out of the container with an instance assertion at the seam.
     *
     * @param   Container  $container  Live integration container.
     * @param   string     $service    Service identifier to resolve.
     *
     * @return  object  The shared instance.
     *
     * @since   2.0.0
     */
    private function service(Container $container, string $service): object
    {
        $resolved = $container->get($service);
        self::assertIsObject($resolved);

        return $resolved;
    }

    /**
     * Resolve the live record service out of the container.
     *
     * @param   Container  $container  Live integration container.
     *
     * @return  BusinessRecordService  The shared service instance.
     *
     * @since   2.0.0
     */
    private function records(Container $container): BusinessRecordService
    {
        $records = $container->get(BusinessRecordService::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);

        return $records;
    }

    /**
     * Resolve the shared connection out of the container.
     *
     * @param   Container  $container  Live integration container.
     *
     * @return  Connection  The shared connection.
     *
     * @since   2.0.0
     */
    private function connection(Container $container): Connection
    {
        $connection = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
