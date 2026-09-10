<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessRecord;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Kumwe\App\Kernel\Container;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessRecord\Application\BusinessRecordDefinitionResolver;
use Kumwe\App\BusinessRecord\Application\BusinessRecordRelationView;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\DeleteRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\DocumentLineInput;
use Kumwe\App\BusinessRecord\Application\Command\DocumentWriteIntent;
use Kumwe\App\BusinessRecord\Application\Command\RelateRecordsCommand;
use Kumwe\App\BusinessRecord\Application\Command\UpdateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\WriteDocumentCommand;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordUniqueConflict;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordValidationFailed;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordVersionConflict;
use Kumwe\App\BusinessRecord\Application\OwnedLineWrite;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\App\BusinessRecord\Application\Query\RecordHistoryQuery;
use Kumwe\App\BusinessRecord\Application\RecordValueCodec;
use Kumwe\App\BusinessRecord\Application\ValidationViolation;
use Kumwe\App\BusinessRecord\Domain\BusinessRecord;
use Kumwe\Conversion\Decimal\ExactDecimal;
use Kumwe\App\BusinessRecord\Domain\RecordScope;
use Kumwe\App\BusinessDefinition\Domain\ScopeMode;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordWriteRepository;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaInstallationRepository;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\BusinessQueryCounter;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Proves the atomic document command against a real engine, where atomicity can actually be observed.
 *
 * A header and its owned lines are one thing or they are a defect that reached the customer. What is
 * pinned here is that they are one thing on the way in and on the way out: one version, one revision, one
 * audit action, one idempotent outcome; a refusal anywhere in the collection leaving no row at all; a rule
 * that spans the whole document refusing the whole document; and a statement count that does not grow with
 * the collection.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessRecordService::class)]
#[CoversClass(DoctrineBusinessRecordWriteRepository::class)]
final class AggregateDocumentIntegrationTest extends TestCase
{
    /**
     * Proves a hundred-line and a thousand-line document each commit as one versioned, audited change.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAHundredAndAThousandLineDocumentEachCommitAsOneChange(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $this->records($container);
        $suffix = self::suffix();
        $header = $this->install($container, $context, $suffix);

        foreach ([100, 1000] as $size) {
            $documentId = Uuid::uuid7()->toString();
            $result = $records->writeDocument(new WriteDocumentCommand(
                $context,
                $header->handle,
                'lines',
                ['title' => 'Document of ' . $size, 'total' => number_format($size, 2, '.', '')],
                $this->lines($size, $suffix . 'a' . $size),
                NeutralBusinessFixture::idempotencyKey('doc-' . $size . '-' . $suffix),
                recordId: $documentId,
            ));

            self::assertSame(1, $result->version, 'A whole document is one version, however many lines it has.');
            self::assertFalse($result->replayed);
            self::assertSame('document.create', $result->operation);
            self::assertSame($size, $this->lineCount($container, $header, $documentId));
            self::assertSame(
                1,
                $this->revisionCount($container, $header, $documentId),
                'A thousand lines do not produce a thousand revisions.',
            );
            self::assertSame(
                1,
                $this->auditCount($container, 'business.record.document.create', $documentId),
                'A thousand lines do not produce a thousand audit entries.',
            );

            $history = $records->history(new RecordHistoryQuery($context, $header->handle, $documentId));
            self::assertCount(1, $history->revisions);
            self::assertSame('document.create', $history->revisions[0]->operation);

            // A document of this size is deliberately not left behind: the integration suite shares one
            // database, and a thousand persisted lines would sit inside every later include budget.
            $records->delete(new DeleteRecordCommand(
                $context,
                $header->handle,
                $documentId,
                $result->version,
                NeutralBusinessFixture::idempotencyKey('doc-' . $size . '-drop-' . $suffix),
            ));
            self::assertSame(0, $this->lineCount($container, $header, $documentId));
        }
    }

    /**
     * Proves a thousand-line collection is stored in a bounded number of statements, not one per line.
     *
     * The budget is asserted as a count rather than as a duration, so a regression to per-line writes
     * fails the build on the number it broke instead of on a timing threshold that would drift. The write
     * side is exercised on its own instrumented connection and rolled back, because what is being measured
     * is the cost of storing the collection and nothing around it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAThousandLineCollectionIsStoredInBoundedStatements(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $this->records($container);
        $suffix = self::suffix();
        $header = $this->install($container, $context, $suffix);
        $documentId = Uuid::uuid7()->toString();
        $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            ['title' => 'Budget document', 'total' => '0.00'],
            [],
            NeutralBusinessFixture::idempotencyKey('doc-budget-' . $suffix),
            recordId: $documentId,
        ));

        $counter = new BusinessQueryCounter();
        $connection = $this->countedConnection($this->connection($container), $counter);
        $writes = new DoctrineBusinessRecordWriteRepository(
            $connection,
            $this->service($container, RecordValueCodec::class),
        );
        $resolver = $this->service($container, BusinessRecordDefinitionResolver::class);
        $resolved = $resolver->forCreate($context, $header->handle);
        $owner = $records->read(new ReadRecordQuery($context, $header->handle, $documentId));
        $relationship = $resolved->definition->runtimeRelationship('lines');
        self::assertNotNull($relationship);
        $lineResolved = $resolver->pinned(
            $context,
            $relationship->target,
            $this->pinnedLineVersion($container, $header),
        );

        $prepared = [];
        for ($index = 0; $index < 1000; ++$index) {
            $prepared[] = new OwnedLineWrite(
                Uuid::uuid7()->toString(),
                Uuid::uuid7()->toString(),
                $index,
                [
                    'code' => 'budget-' . $suffix . '-' . $index,
                    'description' => 'Budget line ' . $index,
                    'amount' => ExactDecimal::fromString('1.00', 18, 2),
                ],
            );
        }
        foreach ($prepared as $position => $line) {
            $prepared[$position] = new OwnedLineWrite(
                $line->recordKey,
                $line->recordKey,
                $line->position,
                ['id' => $line->recordKey, ...$line->values],
            );
        }

        $connection->beginTransaction();
        $counter->reset();
        try {
            $writes->writeOwnedLines(
                $resolved,
                $this->ownerRecord($container, $context, $header, $documentId),
                $relationship,
                $lineResolved->definition,
                $prepared,
                [],
                false,
                $context->actorId(),
                $this->now($container),
            );
            $statements = $counter->queries();
        } finally {
            $connection->rollBack();
            $connection->close();
        }

        self::assertGreaterThan(0, $statements);
        self::assertLessThanOrEqual(
            12,
            $statements,
            sprintf(
                'A thousand lines must be written in bounded batches; this write issued %d statements.',
                $statements,
            ),
        );
        self::assertSame(
            0,
            $this->lineCount($container, $header, $documentId),
            'The measured write was rolled back and left nothing behind.',
        );
        unset($owner);
    }

    /**
     * Proves reordering a thousand-line document renumbers it set-based rather than row by row.
     *
     * Reordering marks every surviving line as having to be written even though each differs from its
     * stored row in one server-derived integer. Rewriting them one at a time is the per-line reorder
     * update: one drag on a thousand-line document issued a thousand statements, each carrying a full
     * column list. The whole collection is moved here and the statements are counted, so a regression to
     * the row-at-a-time path fails on the number it broke.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReorderingAThousandLineDocumentIsRenumberedSetBased(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $this->records($container);
        $suffix = self::suffix();
        $header = $this->install($container, $context, $suffix);
        $documentId = Uuid::uuid7()->toString();
        $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            ['title' => 'Reorder document', 'total' => '1000.00'],
            $this->lines(1000, $suffix),
            NeutralBusinessFixture::idempotencyKey('doc-reorder-' . $suffix),
            recordId: $documentId,
        ));

        [$table, $ownerColumn] = $this->physical($container, $header, 'line:lines', 'owner_id');
        [, $lineColumn] = $this->physical($container, $header, 'line:lines', 'line_id');
        [, $versionColumn] = $this->physical($container, $header, 'line:lines', 'version');
        [, $positionColumn] = $this->physical($container, $header, 'line:lines', 'position');
        $owner = $this->ownerRecord($container, $context, $header, $documentId);
        $stored = $this->connection($container)->fetchAllAssociative(sprintf(
            'SELECT %s AS line_key, %s AS line_version FROM %s WHERE %s = ? ORDER BY %s',
            $lineColumn,
            $versionColumn,
            $table,
            $ownerColumn,
            $positionColumn,
        ), [$owner->recordKey]);
        self::assertCount(1000, $stored);

        $moved = [];
        foreach (array_reverse($stored) as $position => $row) {
            $moved[] = new OwnedLineWrite(
                (string) $row['line_key'],
                (string) $row['line_key'],
                $position,
                [],
                (int) $row['line_version'],
                true,
                false,
            );
        }

        $counter = new BusinessQueryCounter();
        $connection = $this->countedConnection($this->connection($container), $counter);
        $writes = new DoctrineBusinessRecordWriteRepository(
            $connection,
            $this->service($container, RecordValueCodec::class),
        );
        $resolver = $this->service($container, BusinessRecordDefinitionResolver::class);
        $resolved = $resolver->forCreate($context, $header->handle);
        $relationship = $resolved->definition->runtimeRelationship('lines');
        self::assertNotNull($relationship);
        $lineResolved = $resolver->pinned(
            $context,
            $relationship->target,
            $this->pinnedLineVersion($container, $header),
        );

        $connection->beginTransaction();
        $counter->reset();
        try {
            $writes->writeOwnedLines(
                $resolved,
                $owner,
                $relationship,
                $lineResolved->definition,
                $moved,
                [],
                true,
                $context->actorId(),
                $this->now($container),
            );
            $statements = $counter->queries();
        } finally {
            $connection->rollBack();
            $connection->close();
        }

        self::assertGreaterThan(0, $statements);
        self::assertLessThanOrEqual(
            15,
            $statements,
            sprintf(
                'A thousand moved lines must be renumbered set-based; this reorder issued %d statements.',
                $statements,
            ),
        );

        $records->delete(new DeleteRecordCommand(
            $context,
            $header->handle,
            $documentId,
            $records->read(new ReadRecordQuery($context, $header->handle, $documentId))->version,
            NeutralBusinessFixture::idempotencyKey('doc-reorder-drop-' . $suffix),
        ));
        self::assertSame(0, $this->lineCount($container, $header, $documentId));
    }

    /**
     * Proves a document refused partway through its collection leaves neither a header nor a line behind.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testADocumentRefusedMidWriteLeavesNothingBehind(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $this->records($container);
        $suffix = self::suffix();
        $header = $this->install($container, $context, $suffix);

        $lines = $this->lines(400, $suffix . 'c');
        $lines[399] = new DocumentLineInput([
            'code' => 'line-' . $suffix . 'c-1',
            'description' => 'Colliding line',
            'amount' => '1.00',
        ]);
        $documentId = Uuid::uuid7()->toString();

        $caught = null;
        try {
            $records->writeDocument(new WriteDocumentCommand(
                $context,
                $header->handle,
                'lines',
                ['title' => 'Refused document', 'total' => '400.00'],
                $lines,
                NeutralBusinessFixture::idempotencyKey('doc-collide-' . $suffix),
                recordId: $documentId,
            ));
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(BusinessRecordUniqueConflict::class, $caught);
        self::assertSame(0, $this->lineCount($container, $header, $documentId));
        self::assertSame(0, $this->headerCount($container, $header, $documentId));
        self::assertSame(0, $this->revisionCount($container, $header, $documentId));
        self::assertSame(0, $this->auditCount($container, 'business.record.document.create', $documentId));
        self::assertSame(
            0,
            $this->idempotencyCount($container, 'neutral-fixture:doc-collide-' . $suffix),
            'A refused document rolls its idempotency claim back with the work the claim guarded.',
        );
    }

    /**
     * Proves a document contradicting its own lines is refused whole, with the invariant named.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAThousandLineDocumentBreakingItsTotalIsRefusedAtomically(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $this->records($container);
        $suffix = self::suffix();
        $header = $this->install($container, $context, $suffix);
        $documentId = Uuid::uuid7()->toString();

        try {
            $records->writeDocument(new WriteDocumentCommand(
                $context,
                $header->handle,
                'lines',
                ['title' => 'Inconsistent document', 'total' => '999.99'],
                $this->lines(1000, $suffix . 'd'),
                NeutralBusinessFixture::idempotencyKey('doc-inconsistent-' . $suffix),
                recordId: $documentId,
            ));
            self::fail('A document whose total contradicts its lines was accepted.');
        } catch (BusinessRecordValidationFailed $exception) {
            self::assertSame(
                ['total_agrees_with_lines'],
                array_map(
                    static fn (ValidationViolation $violation): string => $violation->field,
                    $exception->violations,
                ),
                'The refusal names the rule the document broke, not a row.',
            );
        }

        self::assertSame(0, $this->headerCount($container, $header, $documentId));
        self::assertSame(0, $this->lineCount($container, $header, $documentId));
    }

    /**
     * Proves an amendment replaces the collection whole and moves the aggregate exactly one version.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnAmendmentReplacesTheCollectionAndMovesOneVersion(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $this->records($container);
        $suffix = self::suffix();
        $header = $this->install($container, $context, $suffix);
        $documentId = Uuid::uuid7()->toString();

        $created = $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            ['title' => 'Amendable document', 'total' => '3.00'],
            $this->lines(3, $suffix . 'e'),
            NeutralBusinessFixture::idempotencyKey('doc-amend-create-' . $suffix),
            recordId: $documentId,
        ));
        $stored = $this->readLines($records, $context, $header, $documentId);
        self::assertCount(3, $stored);

        $amended = $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            ['total' => '12.00'],
            [
                new DocumentLineInput(['amount' => '9.00'], $stored[2]->recordId),
                new DocumentLineInput(['amount' => '2.00'], $stored[1]->recordId),
                new DocumentLineInput([
                    'code' => 'line-' . $suffix . 'e-added',
                    'description' => 'Added line',
                    'amount' => '1.00',
                ]),
            ],
            NeutralBusinessFixture::idempotencyKey('doc-amend-' . $suffix),
            DocumentWriteIntent::Amend,
            $created->version,
            $documentId,
        ));

        self::assertSame($created->version + 1, $amended->version);
        $after = $this->readLines($records, $context, $header, $documentId);
        self::assertCount(3, $after);
        self::assertSame(
            [0, 1, 2],
            array_map(static fn (BusinessRecordRelationView $line): ?int => $line->position, $after),
            'Positions stay dense and follow the submitted order.',
        );
        self::assertSame($stored[2]->recordId, $after[0]->recordId, 'A reordered line keeps its identity.');
        self::assertSame($stored[1]->recordId, $after[1]->recordId);
        self::assertNotContains(
            $stored[0]->recordId,
            array_map(static fn (BusinessRecordRelationView $line): string => $line->recordId, $after),
            'A line the amendment did not name is removed with the command that dropped it.',
        );
        self::assertSame(3, $this->lineCount($container, $header, $documentId));
    }

    /**
     * Proves a stale amendment against a document that has moved on is refused rather than applied.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAStaleAmendmentIsRefused(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $this->records($container);
        $suffix = self::suffix();
        $header = $this->install($container, $context, $suffix);
        $documentId = Uuid::uuid7()->toString();

        $created = $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            ['title' => 'Contended document', 'total' => '2.00'],
            $this->lines(2, $suffix . 'f'),
            NeutralBusinessFixture::idempotencyKey('doc-stale-create-' . $suffix),
            recordId: $documentId,
        ));
        $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            ['total' => '1.00'],
            [new DocumentLineInput([
                'code' => 'line-' . $suffix . 'f-winner',
                'description' => 'Winning line',
                'amount' => '1.00',
            ])],
            NeutralBusinessFixture::idempotencyKey('doc-stale-first-' . $suffix),
            DocumentWriteIntent::Amend,
            $created->version,
            $documentId,
        ));

        $this->expectException(BusinessRecordVersionConflict::class);
        $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            ['total' => '5.00'],
            [new DocumentLineInput([
                'code' => 'line-' . $suffix . 'f-loser',
                'description' => 'Losing line',
                'amount' => '5.00',
            ])],
            NeutralBusinessFixture::idempotencyKey('doc-stale-second-' . $suffix),
            DocumentWriteIntent::Amend,
            $created->version,
            $documentId,
        ));
    }

    /**
     * Proves an exact replay returns the first outcome without rewriting a single line or trail entry.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnExactReplayRewritesNothing(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $this->records($container);
        $suffix = self::suffix();
        $header = $this->install($container, $context, $suffix);
        $documentId = Uuid::uuid7()->toString();
        $command = fn (): WriteDocumentCommand => new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            ['title' => 'Replayed document', 'total' => '5.00'],
            [new DocumentLineInput([
                'code' => 'line-' . $suffix . 'g-1',
                'description' => 'Replayed line',
                'amount' => '5.00',
            ])],
            NeutralBusinessFixture::idempotencyKey('doc-replay-' . $suffix),
            recordId: $documentId,
        );

        $first = $records->writeDocument($command());
        $replay = $records->writeDocument($command());

        self::assertFalse($first->replayed);
        self::assertTrue($replay->replayed);
        self::assertSame($first->version, $replay->version);
        self::assertSame(1, $this->lineCount($container, $header, $documentId));
        self::assertSame(1, $this->revisionCount($container, $header, $documentId));
        self::assertSame(1, $this->auditCount($container, 'business.record.document.create', $documentId));
    }

    /**
     * Proves the single-line relation command survives and is now held to the document's own rule.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheSingleLineRelationCommandSurvivesAndHonoursTheDocumentRule(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $this->records($container);
        $suffix = self::suffix();
        $header = $this->install($container, $context, $suffix);
        $documentId = Uuid::uuid7()->toString();

        $created = $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            ['title' => 'Relate document', 'total' => '1.00'],
            $this->lines(1, $suffix . 'h'),
            NeutralBusinessFixture::idempotencyKey('doc-relate-create-' . $suffix),
            recordId: $documentId,
        ));

        $relate = static fn (string $key, string $amount): RelateRecordsCommand => new RelateRecordsCommand(
            $context,
            $header->handle,
            $documentId,
            $created->version,
            'lines',
            Uuid::uuid7()->toString(),
            NeutralBusinessFixture::idempotencyKey($key),
            1,
            targetValues: [
                'code' => 'line-' . $suffix . 'h-' . $key,
                'description' => 'Linked line',
                'amount' => $amount,
            ],
        );

        try {
            $records->relate($relate('doc-relate-breaks-' . $suffix, '2.00'));
            self::fail('A single-line link left the document contradicting its own total.');
        } catch (BusinessRecordValidationFailed $exception) {
            self::assertSame(
                ['total_agrees_with_lines'],
                array_map(
                    static fn (ValidationViolation $violation): string => $violation->field,
                    $exception->violations,
                ),
            );
        }
        self::assertSame(1, $this->lineCount($container, $header, $documentId), 'The refused link wrote nothing.');

        try {
            $records->update(new UpdateRecordCommand(
                $context,
                $header->handle,
                $documentId,
                $created->version,
                ['total' => '3.00'],
                NeutralBusinessFixture::idempotencyKey('doc-relate-total-' . $suffix),
            ));
            self::fail('A header edit left the document contradicting its own lines.');
        } catch (BusinessRecordValidationFailed $exception) {
            self::assertSame(
                ['total_agrees_with_lines'],
                array_map(
                    static fn (ValidationViolation $violation): string => $violation->field,
                    $exception->violations,
                ),
            );
        }

        $amended = $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            ['total' => '3.00'],
            [
                ...$this->lines(1, $suffix . 'h'),
                new DocumentLineInput([
                    'code' => 'line-' . $suffix . 'h-second',
                    'description' => 'Second line',
                    'amount' => '2.00',
                ]),
            ],
            NeutralBusinessFixture::idempotencyKey('doc-relate-amend-' . $suffix),
            DocumentWriteIntent::Amend,
            $created->version,
            $documentId,
        ));
        self::assertSame($created->version + 1, $amended->version);
        self::assertSame(2, $this->lineCount($container, $header, $documentId));
    }

    /**
     * Proves an extension may declare a document rule that core has never heard of, and have it enforced.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnExtensionDeclaresTheDocumentRuleAndCoreEnforcesIt(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $this->records($container);
        $suffix = self::suffix();
        $header = $this->install($container, $context, $suffix, 'testing/documents_' . $suffix);
        $documentId = Uuid::uuid7()->toString();

        try {
            $records->writeDocument(new WriteDocumentCommand(
                $context,
                $header->handle,
                'lines',
                ['title' => 'Extension document', 'total' => '10.00'],
                $this->lines(3, $suffix . 'i'),
                NeutralBusinessFixture::idempotencyKey('doc-extension-' . $suffix),
                recordId: $documentId,
            ));
            self::fail('A rule an extension declared was not enforced by core.');
        } catch (BusinessRecordValidationFailed $exception) {
            self::assertSame(
                ['total_agrees_with_lines'],
                array_map(
                    static fn (ValidationViolation $violation): string => $violation->field,
                    $exception->violations,
                ),
            );
        }
        self::assertSame(0, $this->headerCount($container, $header, $documentId));

        $accepted = $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            ['title' => 'Extension document', 'total' => '3.00'],
            $this->lines(3, $suffix . 'j'),
            NeutralBusinessFixture::idempotencyKey('doc-extension-ok-' . $suffix),
            recordId: $documentId,
        ));
        self::assertSame(1, $accepted->version);
    }

    /**
     * Proves lines are owned rather than independent: deleting the header takes the whole collection.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDeletingTheHeaderRemovesItsLinesWithIt(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $this->records($container);
        $suffix = self::suffix();
        $header = $this->install($container, $context, $suffix);
        $documentId = Uuid::uuid7()->toString();

        $created = $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            ['title' => 'Deleted document', 'total' => '4.00'],
            $this->lines(4, $suffix . 'k'),
            NeutralBusinessFixture::idempotencyKey('doc-delete-create-' . $suffix),
            recordId: $documentId,
        ));
        self::assertSame(4, $this->lineCount($container, $header, $documentId));

        $records->delete(new DeleteRecordCommand(
            $context,
            $header->handle,
            $documentId,
            $created->version,
            NeutralBusinessFixture::idempotencyKey('doc-delete-' . $suffix),
        ));

        self::assertSame(0, $this->headerCount($container, $header, $documentId));
        self::assertSame(0, $this->lineCount($container, $header, $documentId));
    }

    /**
     * Install the neutral document header and line definitions, owned by the site or by an extension.
     *
     * The site-owned pair is installed under one stable suffix and reused by every case here, because a
     * definition is a schema plan and installing twenty of the same shape would cost the suite twenty
     * schema executions and twenty entries against the site's bounded definition count for no more
     * evidence than one. Records stay isolated by identity rather than by definition. The extension-owned
     * pair keeps its own per-run suffix, because what that case proves is contribution itself.
     *
     * @param   Container         $container  Live integration container.
     * @param   ExecutionContext  $context    Trusted test actor.
     * @param   string            $suffix     Unique suffix for the extension-owned pair.
     * @param   ?string           $extension  Extension identifier declaring them, or null for the site.
     *
     * @return  EntityTypeDefinition  The installed header definition.
     *
     * @since   2.0.0
     */
    private function install(
        Container $container,
        ExecutionContext $context,
        string $suffix,
        ?string $extension = null,
    ): EntityTypeDefinition {
        $lineDocument = NeutralBusinessFixture::documentLineDocument(
            $extension === null ? NeutralBusinessFixture::DOCUMENT_SUFFIX : $suffix,
            Uuid::uuid7()->toString(),
            $extension,
        );
        $lineHandle = $lineDocument['handle'];
        self::assertIsString($lineHandle);
        $headerDocument = NeutralBusinessFixture::documentHeaderDocument(
            $extension === null ? NeutralBusinessFixture::DOCUMENT_SUFFIX : $suffix,
            Uuid::uuid7()->toString(),
            $lineHandle,
            $extension,
        );
        if ($extension === null) {
            NeutralBusinessFixture::install($container, $context, $lineDocument);

            return NeutralBusinessFixture::install($container, $context, $headerDocument);
        }

        return NeutralBusinessFixture::installContributed(
            $container,
            $context,
            $extension,
            [$lineDocument, $headerDocument],
        )[1];
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
                'code' => 'line-' . $suffix . '-' . $index,
                'description' => 'Line ' . $index,
                'amount' => '1.00',
            ]);
        }

        return $lines;
    }

    /**
     * Read the document's lines back through the ordinary read path, in stored order.
     *
     * @param   BusinessRecordService  $records     Live record service.
     * @param   ExecutionContext       $context     Trusted test actor.
     * @param   EntityTypeDefinition   $header      Installed header definition.
     * @param   string                 $documentId  Identity of the document to read.
     *
     * @return  list<BusinessRecordRelationView>  The collection as a reader sees it.
     *
     * @since   2.0.0
     */
    private function readLines(
        BusinessRecordService $records,
        ExecutionContext $context,
        EntityTypeDefinition $header,
        string $documentId,
    ): array {
        $view = $records->read(new ReadRecordQuery(
            $context,
            $header->handle,
            $documentId,
            includes: ['lines'],
        ));

        return $view->includes['lines'] ?? [];
    }

    /**
     * Count the line rows one document currently owns, read straight off the installed table.
     *
     * @param   Container             $container   Live integration container.
     * @param   EntityTypeDefinition  $header      Installed header definition.
     * @param   string                $documentId  Identity of the document being counted.
     *
     * @return  int  How many line rows the document owns.
     *
     * @since   2.0.0
     */
    private function lineCount(Container $container, EntityTypeDefinition $header, string $documentId): int
    {
        [$table, $column] = $this->physical($container, $header, 'line:lines', 'owner_id');
        $database = $this->connection($container);

        return (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE %s = ?',
            $database->getDatabasePlatform()->quoteSingleIdentifier($table),
            $database->getDatabasePlatform()->quoteSingleIdentifier($column),
        ), [$documentId]);
    }

    /**
     * Count the header rows one document identity currently has.
     *
     * @param   Container             $container   Live integration container.
     * @param   EntityTypeDefinition  $header      Installed header definition.
     * @param   string                $documentId  Identity of the document being counted.
     *
     * @return  int  Zero or one.
     *
     * @since   2.0.0
     */
    private function headerCount(Container $container, EntityTypeDefinition $header, string $documentId): int
    {
        [$table, $column] = $this->physical($container, $header, 'record', 'record_id');
        $database = $this->connection($container);

        return (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE %s = ?',
            $database->getDatabasePlatform()->quoteSingleIdentifier($table),
            $database->getDatabasePlatform()->quoteSingleIdentifier($column),
        ), [$documentId]);
    }

    /**
     * Count the revision rows written for one document.
     *
     * @param   Container             $container   Live integration container.
     * @param   EntityTypeDefinition  $header      Installed header definition.
     * @param   string                $documentId  Identity of the document being counted.
     *
     * @return  int  How many revisions the document's history holds.
     *
     * @since   2.0.0
     */
    private function revisionCount(Container $container, EntityTypeDefinition $header, string $documentId): int
    {
        $database = $this->connection($container);

        return (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE definition_id = ? AND record_id = ?',
            $this->service($container, TableNames::class)->quoted('business_record_revisions'),
        ), [$header->id, $documentId]);
    }

    /**
     * Count the audit entries written under one action for one document.
     *
     * @param   Container  $container   Live integration container.
     * @param   string     $action      Audit action to count.
     * @param   string     $documentId  Subject identity to count under.
     *
     * @return  int  How many audit entries the trail holds for that pair.
     *
     * @since   2.0.0
     */
    private function auditCount(Container $container, string $action, string $documentId): int
    {
        $database = $this->connection($container);

        return (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE action = ? AND subject_id = ?',
            $this->service($container, TableNames::class)->quoted('audit_events'),
        ), [$action, $documentId]);
    }

    /**
     * Count the idempotency claims outstanding under one caller-supplied operation identifier.
     *
     * @param   Container  $container  Live integration container.
     * @param   string     $key        Operation identifier the command was claimed under.
     *
     * @return  int  How many ledger rows the key still holds.
     *
     * @since   2.0.0
     */
    private function idempotencyCount(Container $container, string $key): int
    {
        $database = $this->connection($container);

        return (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE operation_id = ?',
            $this->service($container, TableNames::class)->quoted('business_command_idempotency'),
        ), [$key]);
    }

    /**
     * Resolve one installed physical table and column name pair from a definition's blueprint.
     *
     * @param   Container             $container  Live integration container.
     * @param   EntityTypeDefinition  $header     Installed header definition.
     * @param   string                $logical    Logical table name, such as `record` or `line:lines`.
     * @param   string                $column     Logical column name within that table.
     *
     * @return  array{string, string}  The physical table name and the physical column name.
     *
     * @since   2.0.0
     */
    private function physical(
        Container $container,
        EntityTypeDefinition $header,
        string $logical,
        string $column,
    ): array {
        $installation = $this->service($container, BusinessSchemaInstallationRepository::class)->find($header->id);
        self::assertNotNull($installation);
        $table = $installation->blueprint->table($logical);
        self::assertNotNull($table);
        $blueprint = $table->column($column);
        self::assertNotNull($blueprint);

        return [$table->physicalName, $blueprint->physicalName];
    }

    /**
     * Read the definition version the header's line table is pinned to.
     *
     * @param   Container             $container  Live integration container.
     * @param   EntityTypeDefinition  $header     Installed header definition.
     *
     * @return  int  The pinned line definition version.
     *
     * @since   2.0.0
     */
    private function pinnedLineVersion(Container $container, EntityTypeDefinition $header): int
    {
        $installation = $this->service($container, BusinessSchemaInstallationRepository::class)->find($header->id);
        self::assertNotNull($installation);
        $table = $installation->blueprint->table('line:lines');
        self::assertNotNull($table);
        $version = $table->options['target_definition_version'] ?? null;
        self::assertIsInt($version);

        return $version;
    }

    /**
     * Load the document's header as the write side needs it, keyed and scoped.
     *
     * @param   Container             $container   Live integration container.
     * @param   ExecutionContext      $context     Trusted test actor.
     * @param   EntityTypeDefinition  $header      Installed header definition.
     * @param   string                $documentId  Identity of the document.
     *
     * @return  \Kumwe\App\BusinessRecord\Domain\BusinessRecord  The stored header record.
     *
     * @since   2.0.0
     */
    private function ownerRecord(
        Container $container,
        ExecutionContext $context,
        EntityTypeDefinition $header,
        string $documentId,
    ): BusinessRecord {
        return new BusinessRecord(
            $header->id,
            $this->service($container, BusinessRecordDefinitionResolver::class)
                ->forCreate($context, $header->handle)->definition->definitionVersion,
            $documentId,
            $documentId,
            RecordScope::reconstitute(ScopeMode::Site, 'default', null),
            1,
            null,
            [],
            $context->actorId(),
            $this->now($container),
            $context->actorId(),
            $this->now($container),
        );
    }

    /**
     * Open an independently instrumented connection to the same integration database.
     *
     * @param   Connection            $source   Composed connection supplying the exact parameters.
     * @param   BusinessQueryCounter  $counter  Driver-middleware statement counter.
     *
     * @return  Connection  A real connection whose executed statements are counted.
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
     * Read the one instant the runtime stamps mutations with.
     *
     * @param   Container  $container  Live integration container.
     *
     * @return  \DateTimeImmutable  The clock's current instant.
     *
     * @since   2.0.0
     */
    private function now(Container $container): \DateTimeImmutable
    {
        $clock = $container->get(\Psr\Clock\ClockInterface::class);
        self::assertInstanceOf(\Psr\Clock\ClockInterface::class, $clock);

        return \DateTimeImmutable::createFromInterface($clock->now());
    }

    /**
     * Resolve one wired service, failing the test rather than returning something unusable.
     *
     * @template T of object
     *
     * @param   Container         $container  Live integration container.
     * @param   class-string<T>   $service    Service identifier to resolve.
     *
     * @return  T  The wired instance.
     *
     * @since   2.0.0
     */
    private function service(Container $container, string $service): object
    {
        $resolved = $container->get($service);
        self::assertInstanceOf($service, $resolved);

        return $resolved;
    }

    /**
     * Resolve the record service every case here drives.
     *
     * @param   Container  $container  Live integration container.
     *
     * @return  BusinessRecordService  The wired transactional record service.
     *
     * @since   2.0.0
     */
    private function records(Container $container): BusinessRecordService
    {
        return $this->service($container, BusinessRecordService::class);
    }

    /**
     * Resolve the composed database connection.
     *
     * @param   Container  $container  Live integration container.
     *
     * @return  Connection  The wired DBAL connection.
     *
     * @since   2.0.0
     */
    private function connection(Container $container): Connection
    {
        return $this->service($container, Connection::class);
    }

    /**
     * Mint a short unique stem so parallel runs never collide on a definition handle or a line code.
     *
     * @return  string  Ten lowercase alphanumeric characters.
     *
     * @since   2.0.0
     */
    private static function suffix(): string
    {
        return strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
    }
}
