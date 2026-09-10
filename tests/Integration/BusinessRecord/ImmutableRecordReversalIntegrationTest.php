<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessRecord;

use Kumwe\App\Kernel\Container;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionValidator;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\WorkflowBinding;
use Kumwe\App\BusinessRecord\Application\BusinessRecordRelationView;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\ArchiveRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\DeleteRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\DocumentLineInput;
use Kumwe\App\BusinessRecord\Application\Command\DocumentWriteIntent;
use Kumwe\App\BusinessRecord\Application\Command\ExecuteRecordActionCommand;
use Kumwe\App\BusinessRecord\Application\Command\RelateRecordsCommand;
use Kumwe\App\BusinessRecord\Application\Command\ReorderRecordLinesCommand;
use Kumwe\App\BusinessRecord\Application\Command\RestoreRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\UnrelateRecordsCommand;
use Kumwe\App\BusinessRecord\Application\Command\UpdateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\WriteDocumentCommand;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordImmutable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordReferenceConflict;
use Kumwe\App\BusinessRecord\Application\Query\BrowseRecordsQuery;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\App\BusinessRecord\Application\Query\RecordHistoryQuery;
use Kumwe\Extension\Spi\BusinessRecord\Query\ComparisonFilter;
use Kumwe\Extension\Spi\BusinessRecord\Query\ComparisonOperator;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordProjection;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\Extension\Spi\BusinessRecord\Query\RelationFilter;
use Kumwe\Extension\Spi\BusinessRecord\Query\RelationQuantifier;
use Kumwe\App\BusinessSchema\Infrastructure\Schema\CanonicalDefinitionPhysicalSchemaCompiler;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

#[CoversClass(BusinessRecordService::class)]
#[CoversClass(WorkflowBinding::class)]
#[CoversClass(BusinessRecordImmutable::class)]
#[CoversClass(BusinessDefinitionValidator::class)]
#[CoversClass(CanonicalDefinitionPhysicalSchemaCompiler::class)]
/**
 * Proves the immutable-correction rule of ADR 0003 end to end against a real engine.
 *
 * A definition declares that entering `approved` closes the document. What is pinned here is the whole
 * contract: every content mutation — header update, owned-line add, remove and reorder, document amend,
 * archive and restore — refuses on the closed record with the stable `business_record.immutable` error
 * while its version and lines stay exactly as approved; the state machine itself stays open, which is
 * how the approved document still becomes a delivered one; correction succeeds as a new document of the
 * same definition through the ordinary aggregate command, carrying a declared `reversal` link that is
 * queryable from both directions while the original is never re-versioned and never suppressed; and a
 * definition that declares nothing is completely unaffected.
 *
 * @since  2.0.0
 */
final class ImmutableRecordReversalIntegrationTest extends TestCase
{
    /**
     * The whole immutable-correction journey: freeze, refusals, transition, reversal, and both queries.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAClosedDocumentRefusesMutationAndIsCorrectedByALinkedReversal(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $this->records($container);
        $suffix = self::suffix();
        $header = $this->install($container, $context, $suffix, true);

        $originalId = Uuid::uuid7()->toString();
        $created = $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            ['title' => 'Original document', 'total' => '3.00'],
            $this->lines(3, $suffix . 'a'),
            NeutralBusinessFixture::idempotencyKey('immrev-create-' . $suffix),
            recordId: $originalId,
        ));
        self::assertSame(1, $created->version);
        self::assertSame('draft', $created->workflowState);

        $drafted = $records->update(new UpdateRecordCommand(
            $context,
            $header->handle,
            $originalId,
            1,
            ['title' => 'Original document, still a draft'],
            NeutralBusinessFixture::idempotencyKey('immrev-draft-edit-' . $suffix),
        ));
        self::assertSame(2, $drafted->version, 'A draft stays fully mutable before the closing transition.');

        $approved = $records->action(new ExecuteRecordActionCommand(
            $context,
            $header->handle,
            $originalId,
            2,
            'approve',
            NeutralBusinessFixture::idempotencyKey('immrev-approve-' . $suffix),
        ));
        self::assertSame('approved', $approved->workflowState);
        $frozenVersion = $approved->version;
        $storedLines = $this->readLines($records, $context, $header->handle, $originalId);
        self::assertCount(3, $storedLines);

        $this->assertRefusedImmutable('update', static fn () => $records->update(new UpdateRecordCommand(
            $context,
            $header->handle,
            $originalId,
            $frozenVersion,
            ['title' => 'Rewritten after approval'],
            NeutralBusinessFixture::idempotencyKey('immrev-update-' . $suffix),
        )));
        $this->assertRefusedImmutable('amend', static fn () => $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            ['total' => '1.00'],
            [new DocumentLineInput([
                'code' => 'line-' . $suffix . 'b-1',
                'description' => 'Replacement line',
                'amount' => '1.00',
            ])],
            NeutralBusinessFixture::idempotencyKey('immrev-amend-' . $suffix),
            DocumentWriteIntent::Amend,
            $frozenVersion,
            $originalId,
        )));
        $this->assertRefusedImmutable('relate', static fn () => $records->relate(new RelateRecordsCommand(
            $context,
            $header->handle,
            $originalId,
            $frozenVersion,
            'lines',
            Uuid::uuid7()->toString(),
            NeutralBusinessFixture::idempotencyKey('immrev-add-line-' . $suffix),
            3,
            targetValues: [
                'code' => 'line-' . $suffix . 'b-2',
                'description' => 'Smuggled line',
                'amount' => '0.00',
            ],
        )));
        $this->assertRefusedImmutable('unrelate', static fn () => $records->unrelate(new UnrelateRecordsCommand(
            $context,
            $header->handle,
            $originalId,
            $frozenVersion,
            'lines',
            $storedLines[0]->recordId,
            NeutralBusinessFixture::idempotencyKey('immrev-drop-line-' . $suffix),
        )));
        $this->assertRefusedImmutable('reorder', static fn () => $records->reorder(new ReorderRecordLinesCommand(
            $context,
            $header->handle,
            $originalId,
            $frozenVersion,
            'lines',
            array_reverse(array_map(
                static fn (BusinessRecordRelationView $line): string => $line->recordId,
                $storedLines,
            )),
            NeutralBusinessFixture::idempotencyKey('immrev-reorder-' . $suffix),
        )));
        $this->assertRefusedImmutable('archive', static fn () => $records->archive(new ArchiveRecordCommand(
            $context,
            $header->handle,
            $originalId,
            $frozenVersion,
            NeutralBusinessFixture::idempotencyKey('immrev-archive-' . $suffix),
        )));
        $this->assertRefusedImmutable('restore', static fn () => $records->restore(new RestoreRecordCommand(
            $context,
            $header->handle,
            $originalId,
            $frozenVersion,
            NeutralBusinessFixture::idempotencyKey('immrev-restore-' . $suffix),
        )));

        $unchanged = $records->read(new ReadRecordQuery($context, $header->handle, $originalId));
        self::assertSame($frozenVersion, $unchanged->version, 'Seven refusals must not move the record.');
        self::assertSame('Original document, still a draft', $unchanged->values['title']);
        self::assertCount(3, $this->readLines($records, $context, $header->handle, $originalId));

        $delivered = $records->action(new ExecuteRecordActionCommand(
            $context,
            $header->handle,
            $originalId,
            $frozenVersion,
            'deliver',
            NeutralBusinessFixture::idempotencyKey('immrev-deliver-' . $suffix),
        ));
        self::assertSame(
            'delivered',
            $delivered->workflowState,
            'Immutability freezes content, not the state machine.',
        );
        $deliveredVersion = $delivered->version;
        $this->assertRefusedImmutable('delivered update', static fn () => $records->update(new UpdateRecordCommand(
            $context,
            $header->handle,
            $originalId,
            $deliveredVersion,
            ['title' => 'Rewritten after delivery'],
            NeutralBusinessFixture::idempotencyKey('immrev-update-b-' . $suffix),
        )));

        $correctionId = Uuid::uuid7()->toString();
        $correction = $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            ['title' => 'Reversal document', 'total' => '-3.00'],
            $this->lines(3, $suffix . 'r', '-1.00'),
            NeutralBusinessFixture::idempotencyKey('immrev-reverse-' . $suffix),
            recordId: $correctionId,
        ));
        self::assertSame(1, $correction->version, 'The reversal commits through the ordinary aggregate command.');

        $linked = $records->relate(new RelateRecordsCommand(
            $context,
            $header->handle,
            $correctionId,
            1,
            'reverses',
            $originalId,
            NeutralBusinessFixture::idempotencyKey('immrev-link-' . $suffix),
        ));
        self::assertSame(2, $linked->version);
        self::assertSame(
            $deliveredVersion,
            $records->read(new ReadRecordQuery($context, $header->handle, $originalId))->version,
            'Naming a closed document as the reversal target must never re-version the closed document.',
        );

        $correctionApproved = $records->action(new ExecuteRecordActionCommand(
            $context,
            $header->handle,
            $correctionId,
            2,
            'approve',
            NeutralBusinessFixture::idempotencyKey('immrev-approve-b-' . $suffix),
        ));
        self::assertSame('approved', $correctionApproved->workflowState, 'The correction has its own approval path.');
        $correctionVersion = $correctionApproved->version;
        $this->assertRefusedImmutable('correction update', static fn () => $records->update(new UpdateRecordCommand(
            $context,
            $header->handle,
            $correctionId,
            $correctionVersion,
            ['title' => 'Rewritten correction'],
            NeutralBusinessFixture::idempotencyKey('immrev-update-c-' . $suffix),
        )));
        $this->assertRefusedImmutable('unpair', static fn () => $records->unrelate(new UnrelateRecordsCommand(
            $context,
            $header->handle,
            $correctionId,
            $correctionVersion,
            'reverses',
            $originalId,
            NeutralBusinessFixture::idempotencyKey('immrev-unpair-' . $suffix),
        )));

        $corrections = $records->browse(new BrowseRecordsQuery(
            $context,
            $header->handle,
            new RecordQuerySpecification(
                new RelationFilter(
                    'reverses',
                    RelationQuantifier::Any,
                    new ComparisonFilter('title', ComparisonOperator::Equal, 'Original document, still a draft'),
                ),
                projection: new RecordProjection(['title'], ['reverses']),
            ),
        ));
        self::assertCount(1, $corrections->records, 'What did this correct is a declared query.');
        self::assertSame($correctionId, $corrections->records[0]->recordId);
        self::assertSame($originalId, $corrections->records[0]->includes['reverses'][0]->recordId);

        $originals = $records->browse(new BrowseRecordsQuery(
            $context,
            $header->handle,
            new RecordQuerySpecification(
                new RelationFilter(
                    'reversed_by',
                    RelationQuantifier::Any,
                    new ComparisonFilter('title', ComparisonOperator::Equal, 'Reversal document'),
                ),
                projection: new RecordProjection(['title'], ['reversed_by']),
            ),
        ));
        self::assertCount(1, $originals->records, 'What corrected this is a declared query.');
        self::assertSame($originalId, $originals->records[0]->recordId);
        self::assertSame($correctionId, $originals->records[0]->includes['reversed_by'][0]->recordId);

        self::assertNotEmpty(
            $records->history(new RecordHistoryQuery($context, $header->handle, $originalId))->revisions,
            'The original stays in history beside its correction.',
        );

        // A link whose storage sits on a closed record's own row can be neither made nor broken: the
        // `annotations` collection stores on its many-to-one inverse, so linking rewrites the target.
        $annotatorId = Uuid::uuid7()->toString();
        $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            ['title' => 'Annotator document', 'total' => '1.00'],
            $this->lines(1, $suffix . 'n'),
            NeutralBusinessFixture::idempotencyKey('immrev-annotator-' . $suffix),
            recordId: $annotatorId,
        ));
        $annotatedId = Uuid::uuid7()->toString();
        $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            ['title' => 'Annotated document', 'total' => '1.00'],
            $this->lines(1, $suffix . 'm'),
            NeutralBusinessFixture::idempotencyKey('immrev-annotated-' . $suffix),
            recordId: $annotatedId,
        ));
        $records->relate(new RelateRecordsCommand(
            $context,
            $header->handle,
            $annotatorId,
            1,
            'annotations',
            $annotatedId,
            NeutralBusinessFixture::idempotencyKey('immrev-annotate-' . $suffix),
        ));
        $records->action(new ExecuteRecordActionCommand(
            $context,
            $header->handle,
            $annotatedId,
            2,
            'approve',
            NeutralBusinessFixture::idempotencyKey('immrev-approve-c-' . $suffix),
        ));
        $this->assertRefusedImmutable('inverse-stored unrelate', static fn () => $records->unrelate(
            new UnrelateRecordsCommand(
                $context,
                $header->handle,
                $annotatorId,
                2,
                'annotations',
                $annotatedId,
                NeutralBusinessFixture::idempotencyKey('immrev-unannotate-' . $suffix),
            ),
        ));
        $this->assertRefusedImmutable('inverse-stored relate', static fn () => $records->relate(
            new RelateRecordsCommand(
                $context,
                $header->handle,
                $annotatorId,
                2,
                'annotations',
                $originalId,
                NeutralBusinessFixture::idempotencyKey('immrev-annotate-b-' . $suffix),
            ),
        ));

        // Hard deletion stays open, but never by rewriting a closed document: clearing the set-null
        // reference a closed record holds would mutate its fields, so the sweep refuses by name.
        $anchorId = Uuid::uuid7()->toString();
        $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            ['title' => 'Anchor document', 'total' => '1.00'],
            $this->lines(1, $suffix . 'p'),
            NeutralBusinessFixture::idempotencyKey('immrev-anchor-' . $suffix),
            recordId: $anchorId,
        ));
        $referrerId = Uuid::uuid7()->toString();
        $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            ['title' => 'Referrer document', 'total' => '1.00'],
            $this->lines(1, $suffix . 'q'),
            NeutralBusinessFixture::idempotencyKey('immrev-referrer-' . $suffix),
            recordId: $referrerId,
        ));
        $records->relate(new RelateRecordsCommand(
            $context,
            $header->handle,
            $referrerId,
            1,
            'refers_to',
            $anchorId,
            NeutralBusinessFixture::idempotencyKey('immrev-refer-' . $suffix),
        ));
        $records->action(new ExecuteRecordActionCommand(
            $context,
            $header->handle,
            $referrerId,
            2,
            'approve',
            NeutralBusinessFixture::idempotencyKey('immrev-approve-d-' . $suffix),
        ));
        $this->assertRefusedImmutable('set-null sweep', static fn () => $records->delete(new DeleteRecordCommand(
            $context,
            $header->handle,
            $anchorId,
            1,
            NeutralBusinessFixture::idempotencyKey('immrev-delete-c-' . $suffix),
        )));

        try {
            $records->delete(new DeleteRecordCommand(
                $context,
                $header->handle,
                $originalId,
                $deliveredVersion,
                NeutralBusinessFixture::idempotencyKey('immrev-delete-' . $suffix),
            ));
            self::fail('A reversed document must not be hard-deleted out from under its correction.');
        } catch (BusinessRecordReferenceConflict) {
            self::assertTrue(true);
        }

        $deleted = $records->delete(new DeleteRecordCommand(
            $context,
            $header->handle,
            $correctionId,
            $correctionVersion,
            NeutralBusinessFixture::idempotencyKey('immrev-delete-b-' . $suffix),
        ));
        self::assertTrue(
            $deleted->deleted,
            'The audited delete lifecycle stays open on a closed record, exactly as the ADR leaves it.',
        );
    }

    /**
     * A definition that declares no immutable state keeps every mutation it had, before and after approval.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testADefinitionWithoutTheDeclarationIsUnaffected(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $this->records($container);
        $suffix = self::suffix();
        $header = $this->install($container, $context, $suffix, false);

        $documentId = Uuid::uuid7()->toString();
        $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            ['title' => 'Open document', 'total' => '2.00'],
            $this->lines(2, $suffix . 'o'),
            NeutralBusinessFixture::idempotencyKey('immrev-open-create-' . $suffix),
            recordId: $documentId,
        ));
        $approved = $records->action(new ExecuteRecordActionCommand(
            $context,
            $header->handle,
            $documentId,
            1,
            'approve',
            NeutralBusinessFixture::idempotencyKey('immrev-open-approve-' . $suffix),
        ));
        self::assertSame('approved', $approved->workflowState);

        $updated = $records->update(new UpdateRecordCommand(
            $context,
            $header->handle,
            $documentId,
            $approved->version,
            ['title' => 'Open document, edited after approval'],
            NeutralBusinessFixture::idempotencyKey('immrev-open-update-' . $suffix),
        ));
        self::assertSame($approved->version + 1, $updated->version, 'No declaration, no freeze: nothing regresses.');

        $archived = $records->archive(new ArchiveRecordCommand(
            $context,
            $header->handle,
            $documentId,
            $updated->version,
            NeutralBusinessFixture::idempotencyKey('immrev-open-archive-' . $suffix),
        ));
        self::assertSame($updated->version + 1, $archived->version);
    }

    /**
     * Run one refused mutation and hold the refusal to the stable named error, never a policy denial.
     *
     * @param   string      $operation  Label naming the refused path in the failure message.
     * @param   callable(): mixed  $mutation  The mutation attempt to run.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertRefusedImmutable(string $operation, callable $mutation): void
    {
        try {
            $mutation();
            self::fail('The ' . $operation . ' path mutated an immutable record.');
        } catch (BusinessRecordImmutable $exception) {
            self::assertSame(
                'business_record.immutable',
                $exception->stableCode(),
                'The ' . $operation . ' refusal must carry the stable code callers branch on.',
            );
            self::assertNotSame('', $exception->workflowState);
        }
    }

    /**
     * Install the line type and one document header, with or without the immutability declaration.
     *
     * Both variants share one shape — a workflow moving draft to approved to delivered, and the
     * reciprocal `reverses`/`reversed_by` pair — and differ in exactly one line of the workflow
     * document, so the control case isolates the declaration itself.
     *
     * @param   Container         $container  Live integration container.
     * @param   ExecutionContext  $context    Trusted test actor.
     * @param   string            $suffix     Unique per-run suffix for handles and identities.
     * @param   bool              $immutable  Whether the workflow declares its closing states.
     *
     * @return  EntityTypeDefinition  The installed header definition.
     *
     * @since   2.0.0
     */
    private function install(
        Container $container,
        ExecutionContext $context,
        string $suffix,
        bool $immutable,
    ): EntityTypeDefinition {
        $lineDocument = NeutralBusinessFixture::documentLineDocument($suffix, Uuid::uuid7()->toString());
        $lineHandle = $lineDocument['handle'];
        self::assertIsString($lineHandle);
        $document = NeutralBusinessFixture::documentHeaderDocument(
            $suffix,
            Uuid::uuid7()->toString(),
            $lineHandle,
        );
        $handle = $document['handle'];
        self::assertIsString($handle);
        $document['workflow'] = [
            'initial_state' => 'draft',
            'states' => ['draft', 'approved', 'delivered'],
            'transitions' => [
                [
                    'handle' => 'approve',
                    'from' => 'draft',
                    'to' => 'approved',
                    'capability' => 'business.record.action',
                ],
                [
                    'handle' => 'deliver',
                    'from' => 'approved',
                    'to' => 'delivered',
                    'capability' => 'business.record.action',
                ],
            ],
        ];
        if ($immutable) {
            $document['workflow']['immutable_states'] = ['approved', 'delivered'];
        }
        $document['actions'] = [
            [
                'handle' => 'approve',
                'label' => 'Approve',
                'capability' => 'business.record.action',
                'administrator' => true,
                'portal' => false,
                'public' => false,
                'transition' => 'approve',
            ],
            [
                'handle' => 'deliver',
                'label' => 'Deliver',
                'capability' => 'business.record.action',
                'administrator' => true,
                'portal' => false,
                'public' => false,
                'transition' => 'deliver',
            ],
        ];
        self::assertIsArray($document['relationships']);
        $document['relationships'][] = [
            'handle' => 'reverses',
            'label' => 'Reverses',
            'kind' => 'reversal',
            'target' => $handle,
            'inverse' => 'reversed_by',
            'on_delete' => 'restrict',
        ];
        $document['relationships'][] = [
            'handle' => 'reversed_by',
            'label' => 'Reversed by',
            'kind' => 'one_to_many',
            'target' => $handle,
            'inverse' => 'reverses',
            'on_delete' => 'restrict',
        ];
        $document['relationships'][] = [
            'handle' => 'annotations',
            'label' => 'Annotations',
            'kind' => 'one_to_many',
            'target' => $handle,
            'inverse' => 'annotated_in',
            'on_delete' => 'restrict',
        ];
        $document['relationships'][] = [
            'handle' => 'annotated_in',
            'label' => 'Annotated in',
            'kind' => 'many_to_one',
            'target' => $handle,
            'inverse' => 'annotations',
            'on_delete' => 'restrict',
        ];
        $document['relationships'][] = [
            'handle' => 'refers_to',
            'label' => 'Refers to',
            'kind' => 'many_to_one',
            'target' => $handle,
            'on_delete' => 'set_null',
        ];
        NeutralBusinessFixture::install($container, $context, $lineDocument);

        return NeutralBusinessFixture::install($container, $context, $document);
    }

    /**
     * Build a consistent collection of the given size, each line worth the given amount.
     *
     * @param   int     $size    How many lines the document carries.
     * @param   string  $suffix  Uniqueness stem for the line codes.
     * @param   string  $amount  Exact amount every line carries.
     *
     * @return  list<DocumentLineInput>  The submitted collection, in the order it is to be stored.
     *
     * @since   2.0.0
     */
    private function lines(int $size, string $suffix, string $amount = '1.00'): array
    {
        $lines = [];
        for ($index = 1; $index <= $size; ++$index) {
            $lines[] = new DocumentLineInput([
                'code' => 'line-' . $suffix . '-' . $index,
                'description' => 'Line ' . $index,
                'amount' => $amount,
            ]);
        }

        return $lines;
    }

    /**
     * Read the document's lines back through the ordinary read path, in stored order.
     *
     * @param   BusinessRecordService  $records     Live record service.
     * @param   ExecutionContext       $context     Trusted test actor.
     * @param   string                 $handle      Installed header definition handle.
     * @param   string                 $documentId  Identity of the document to read.
     *
     * @return  list<BusinessRecordRelationView>  The collection as a reader sees it.
     *
     * @since   2.0.0
     */
    private function readLines(
        BusinessRecordService $records,
        ExecutionContext $context,
        string $handle,
        string $documentId,
    ): array {
        $view = $records->read(new ReadRecordQuery($context, $handle, $documentId, includes: ['lines']));

        return $view->includes['lines'] ?? [];
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
        $records = $container->get(BusinessRecordService::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);

        return $records;
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
