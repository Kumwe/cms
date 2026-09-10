<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessRecord;

use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\DeleteRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\DocumentLineInput;
use Kumwe\App\BusinessRecord\Application\Command\DocumentWriteIntent;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\App\BusinessRecord\Application\Command\WriteDocumentCommand;
use Kumwe\App\BusinessRecord\Application\DocumentCommitTimingRecorder;
use Kumwe\App\BusinessRecord\Application\DocumentCommitTimings;
use Kumwe\App\BusinessRecord\Application\DocumentWriteBudget;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\ContainerConnectionCounter;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Holds the whole document command — not one repository — to P4-B's cost and disclosure promises.
 *
 * The repository-level budget proves the collection write is bounded; this suite counts the entire
 * `writeDocument` call on the connection every collaborator shares, so a per-line round trip hiding in
 * reference resolution, the idempotency ledger or the publication is caught wherever it lives. It also
 * proves the commit exposes its phase durations, because a budget nobody can measure is a promise
 * nobody can check. It runs on all three engines through the ordinary integration matrix.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessRecordService::class)]
#[CoversClass(DocumentCommitTimingRecorder::class)]
#[CoversClass(DocumentCommitTimings::class)]
#[CoversClass(DocumentWriteBudget::class)]
final class DocumentCommitInstrumentationIntegrationTest extends TestCase
{
    /**
     * A thousand-line create through the whole service issues a bounded, sublinear statement count.
     *
     * The bound is asserted twice: absolutely, far below one statement per line, and relatively, so the
     * step from one hundred lines to a thousand costs only the extra bounded insert batches rather than
     * anything proportional to the collection. One application transaction, not a thousand, is implied
     * by the absolute bound — a thousand transactions cannot fit in under a hundred statements.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAThousandLineCommandCostsABoundedSublinearStatementCount(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $this->records($container);
        $suffix = self::suffix();
        $header = $this->install($container, $context, $suffix);
        $counter = ContainerConnectionCounter::wrap($container);

        $smallId = Uuid::uuid7()->toString();
        $counter->reset();
        $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            ['title' => 'Instrumented small document', 'total' => '100.00'],
            $this->lines(100, $suffix . 's'),
            NeutralBusinessFixture::idempotencyKey('instr-small-' . $suffix),
            recordId: $smallId,
        ));
        $smallStatements = $counter->queries();

        $largeId = Uuid::uuid7()->toString();
        $counter->reset();
        $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            ['title' => 'Instrumented large document', 'total' => '1000.00'],
            $this->lines(1000, $suffix . 'l'),
            NeutralBusinessFixture::idempotencyKey('instr-large-' . $suffix),
            recordId: $largeId,
        ));
        $largeStatements = $counter->queries();

        self::assertGreaterThan(0, $smallStatements);
        self::assertLessThanOrEqual(
            100,
            $largeStatements,
            sprintf(
                'A thousand-line command must stay far below one statement per line; it issued %d.',
                $largeStatements,
            ),
        );
        self::assertLessThanOrEqual(
            $smallStatements + 30,
            $largeStatements,
            sprintf(
                'Ten times the lines may only cost the extra bounded batches; %d grew to %d.',
                $smallStatements,
                $largeStatements,
            ),
        );

        $this->drop($records, $context, $header, $smallId, 'instr-small-drop-' . $suffix);
        $this->drop($records, $context, $header, $largeId, 'instr-large-drop-' . $suffix);
    }

    /**
     * An amendment touching three lines of a thousand costs statements proportional to three, not a thousand.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAmendingThreeLinesOfAThousandCostsThree(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $this->records($container);
        $suffix = self::suffix();
        $header = $this->install($container, $context, $suffix);
        $documentId = Uuid::uuid7()->toString();
        $lines = $this->lines(1000, $suffix . 'a');
        $created = $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            ['title' => 'Amendment base', 'total' => '1000.00'],
            $lines,
            NeutralBusinessFixture::idempotencyKey('instr-amend-base-' . $suffix),
            recordId: $documentId,
        ));

        foreach ([10, 500, 990] as $index) {
            $lines[$index] = new DocumentLineInput([
                'code' => 'line-' . $suffix . 'a-' . ($index + 1),
                'description' => 'Amended line ' . ($index + 1),
                'amount' => '1.00',
            ]);
        }
        $counter = ContainerConnectionCounter::wrap($container);
        $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            ['title' => 'Amendment base', 'total' => '1000.00'],
            $lines,
            NeutralBusinessFixture::idempotencyKey('instr-amend-' . $suffix),
            DocumentWriteIntent::Amend,
            $created->version,
            $documentId,
        ));
        $amendStatements = $counter->queries();

        self::assertLessThanOrEqual(
            80,
            $amendStatements,
            sprintf(
                'Amending three lines of a thousand must not re-touch the collection; it issued %d statements.',
                $amendStatements,
            ),
        );

        $this->drop($records, $context, $header, $documentId, 'instr-amend-drop-' . $suffix);
    }

    /**
     * A committed document exposes its validation, lock-wait, write and publication durations.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACommittedDocumentExposesItsPhaseDurations(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $this->records($container);
        $recorder = $container->get(DocumentCommitTimingRecorder::class);
        self::assertInstanceOf(DocumentCommitTimingRecorder::class, $recorder);
        $suffix = self::suffix();
        $header = $this->install($container, $context, $suffix);
        $documentId = Uuid::uuid7()->toString();

        $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            ['title' => 'Timed document', 'total' => '100.00'],
            $this->lines(100, $suffix . 't'),
            NeutralBusinessFixture::idempotencyKey('instr-timed-' . $suffix),
            recordId: $documentId,
        ));

        $timings = $recorder->latest();
        self::assertNotNull($timings, 'A committed document must leave its timings behind.');
        self::assertGreaterThan(0.0, $timings->validationMs);
        self::assertGreaterThan(0.0, $timings->lockWaitMs);
        self::assertGreaterThan(0.0, $timings->writeMs);
        self::assertGreaterThan(0.0, $timings->revisionMs);
        self::assertGreaterThan(0.0, $timings->auditMs);
        self::assertGreaterThan(0.0, $timings->eventMs);
        self::assertGreaterThanOrEqual(
            $timings->validationMs + $timings->lockWaitMs + $timings->writeMs
                + $timings->revisionMs + $timings->auditMs + $timings->eventMs,
            $timings->totalMs,
            'The named phases are components of the total, never more than it.',
        );

        $this->drop($records, $context, $header, $documentId, 'instr-timed-drop-' . $suffix);
    }

    /**
     * A thousand-line command stays inside the declared memory and payload budgets, measured, not assumed.
     *
     * The capacity contract's per-change budgets require bounded memory and encoded payload size at a
     * thousand lines. `DocumentWriteBudget` declares the ceilings and the command refuses past them; this
     * is the other half of the budget — proof that the real thousand-line command actually fits inside
     * what was declared, so the ceiling constrains the implementation rather than flattering it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAThousandLineCommandStaysInsideItsDeclaredBudgets(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $this->records($container);
        $suffix = self::suffix();
        $header = $this->install($container, $context, $suffix);
        $documentId = Uuid::uuid7()->toString();
        $lines = $this->lines(1000, $suffix . 'b');

        $payloadBytes = strlen((string) json_encode(array_map(
            static fn (DocumentLineInput $line): array => $line->values,
            $lines,
        )));
        self::assertLessThanOrEqual(
            DocumentWriteBudget::MAXIMUM_PAYLOAD_BYTES,
            $payloadBytes,
            'A thousand contract-shaped lines must encode inside the declared payload ceiling.',
        );

        gc_collect_cycles();
        $memoryBefore = memory_get_usage();
        $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            ['title' => 'Budgeted document', 'total' => '1000.00'],
            $lines,
            NeutralBusinessFixture::idempotencyKey('instr-budget-' . $suffix),
            recordId: $documentId,
        ));
        $memoryDelta = memory_get_usage() - $memoryBefore;

        self::assertLessThanOrEqual(
            DocumentWriteBudget::MAXIMUM_MEMORY_DELTA_BYTES,
            $memoryDelta,
            sprintf('A thousand-line command grew memory by %d bytes, past its declared budget.', $memoryDelta),
        );

        $this->drop($records, $context, $header, $documentId, 'instr-budget-drop-' . $suffix);
    }

    /**
     * Install the neutral header and line documents this suite writes against.
     *
     * @param   Container         $container  Live integration container.
     * @param   ExecutionContext  $context    Administrator context the installation runs as.
     * @param   string            $suffix     Unused uniqueness stem, kept for signature parity.
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
            NeutralBusinessFixture::DOCUMENT_SUFFIX,
            Uuid::uuid7()->toString(),
        );
        $lineHandle = $lineDocument['handle'];
        self::assertIsString($lineHandle);
        $headerDocument = NeutralBusinessFixture::documentHeaderDocument(
            NeutralBusinessFixture::DOCUMENT_SUFFIX,
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
                'code' => 'line-' . $suffix . '-' . $index,
                'description' => 'Line ' . $index,
                'amount' => '1.00',
            ]);
        }

        return $lines;
    }

    /**
     * Delete a measured document so its thousand lines never sit inside a later test's budget.
     *
     * @param   BusinessRecordService  $records     Live record service.
     * @param   ExecutionContext       $context     Administrator context the delete runs as.
     * @param   EntityTypeDefinition   $header      Installed header definition.
     * @param   string                 $documentId  Identity of the document to remove.
     * @param   string                 $key         Idempotency stem for the delete.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function drop(
        BusinessRecordService $records,
        ExecutionContext $context,
        EntityTypeDefinition $header,
        string $documentId,
        string $key,
    ): void {
        $records->delete(new DeleteRecordCommand(
            $context,
            $header->handle,
            $documentId,
            $records->read(new ReadRecordQuery($context, $header->handle, $documentId))->version,
            NeutralBusinessFixture::idempotencyKey($key),
        ));
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
     * A short unique stem for codes and idempotency keys.
     *
     * @return  string  Ten lowercase characters unique to this call.
     *
     * @since   2.0.0
     */
    private static function suffix(): string
    {
        return strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
    }
}
