<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessRecord;

use DateTimeImmutable;
use Kumwe\App\Kernel\Container;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Application\Automation\IdempotencyKey;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\WorkflowBinding;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\DocumentLineInput;
use Kumwe\App\BusinessRecord\Application\Command\ExecuteRecordActionCommand;
use Kumwe\App\BusinessRecord\Application\Command\RelateRecordsCommand;
use Kumwe\App\BusinessRecord\Application\Command\UpdateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\WriteDocumentCommand;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordImmutable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordPostingPeriodClosed;
use Kumwe\App\BusinessRecord\Application\PostingPeriodLock;
use Kumwe\App\BusinessRecord\Application\PostingPeriodService;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Proves the immutable-correction and posting-period primitives compose without special-casing each other.
 *
 * A document is approved into an immutable state inside period P, and P is then closed. From that moment
 * the original refuses every content mutation under one deterministic error: the posting-period gate is
 * evaluated before the mutation fence is ever taken, and the immutability guard runs inside the fenced
 * transaction, so `business_record.posting_period_closed` answers first and `business_record.immutable`
 * is never reached for a record dated in a closed period — while a closed document dated in an open
 * period still answers `business_record.immutable`, which is the same order read from the other side.
 * The correction is an ordinary aggregate document dated in open period Q carrying the declared
 * `reversal` link: it commits, draws its own gapless number, and never re-versions the original. And a
 * plain create backdated into closed P refuses by name, which is the proof the correction succeeded
 * because of its posting date and not because corrections are exempt from the period lock.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessRecordService::class)]
#[CoversClass(PostingPeriodLock::class)]
#[CoversClass(PostingPeriodService::class)]
#[CoversClass(WorkflowBinding::class)]
#[CoversClass(BusinessRecordImmutable::class)]
#[CoversClass(BusinessRecordPostingPeriodClosed::class)]
final class CorrectionAfterPeriodCloseIntegrationTest extends TestCase
{
    /**
     * Stable per-process suffix keeping this suite's definitions and periods apart from other runs.
     *
     * @var    ?string
     * @since  2.0.0
     */
    private static ?string $suffix = null;

    /**
     * The whole interaction: freeze in P, close P, deterministic refusal, correction in Q, no exemption.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACorrectionDatedInAnOpenPeriodSucceedsAfterTheOriginalsPeriodCloses(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        $periods = $container->get(PostingPeriodService::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(PostingPeriodService::class, $periods);
        $suffix = $this->suffix();
        $header = $this->install($container, $context, $suffix);

        // Periods persist across runs and are site-wide, so this run's windows sit at a per-run
        // offset in a range no other suite posts into.
        $base = (new DateTimeImmutable('4000-01-01T00:00:00Z'))
            ->modify('+' . ((int) hexdec(substr($suffix, 0, 8)) % 250_000) . ' days');
        $periodP = 'cpp-' . $suffix;
        $periodQ = 'cpq-' . $suffix;
        $periods->close($context, $periodP, $base, $base->modify('+10 days'));
        $periods->reopen($context, $periodP);
        $periods->close($context, $periodQ, $base->modify('+10 days'), $base->modify('+20 days'));
        $periods->reopen($context, $periodQ);

        // 1. A document dated in open period P is created and approved into its immutable state.
        $originalId = Uuid::uuid7()->toString();
        $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            [
                'title' => 'Original document',
                'total' => '2.00',
                'posted_on' => $base->modify('+2 days')->format('Y-m-d'),
            ],
            $this->lines(2, $suffix . 'a'),
            $this->key('create-original'),
            recordId: $originalId,
        ));
        $approved = $records->action(new ExecuteRecordActionCommand(
            $context,
            $header->handle,
            $originalId,
            1,
            'approve',
            $this->key('approve-original'),
        ));
        self::assertSame('approved', $approved->workflowState);
        $frozenVersion = $approved->version;
        $originalNumber = $this->number($records, $context, $header, $originalId);

        // While P is still open, the fence inside the transaction is what answers: immutable.
        try {
            $records->update(new UpdateRecordCommand(
                $context,
                $header->handle,
                $originalId,
                $frozenVersion,
                ['title' => 'Edited before close'],
                $this->key('update-before-close'),
            ));
            self::fail('An approved document must refuse content mutation while its period is open.');
        } catch (BusinessRecordImmutable $refused) {
            self::assertSame('business_record.immutable', $refused->stableCode());
        }

        // 2. Close P, through the capability-gated administrative path.
        $periods->close($context, $periodP, $base, $base->modify('+10 days'));

        // 3. Now the period gate wins, deterministically: it is evaluated before the mutation fence
        // is taken, while the immutability guard runs inside the fenced transaction, so a record
        // dated in a closed period reports the closed period and never reaches the fence. Twice,
        // to pin that the answer is an ordering and not a race.
        foreach (['first', 'second'] as $attempt) {
            try {
                $records->update(new UpdateRecordCommand(
                    $context,
                    $header->handle,
                    $originalId,
                    $frozenVersion,
                    ['title' => 'Edited after close'],
                    $this->key('update-after-close-' . $attempt),
                ));
                self::fail('A closed period must refuse the mutation of a record dated inside it.');
            } catch (BusinessRecordPostingPeriodClosed $refused) {
                self::assertSame('business_record.posting_period_closed', $refused->stableCode(), $attempt);
                self::assertSame($periodP, $refused->periodKey, $attempt);
            }
        }

        // 4. The correction: a new document of the same definition, dated in open period Q, through
        // the ordinary aggregate command, carrying the declared reversal link back to the original.
        $correctionId = Uuid::uuid7()->toString();
        $correction = $records->writeDocument(new WriteDocumentCommand(
            $context,
            $header->handle,
            'lines',
            [
                'title' => 'Correction document',
                'total' => '-2.00',
                'posted_on' => $base->modify('+12 days')->format('Y-m-d'),
            ],
            $this->lines(2, $suffix . 'r', '-1.00'),
            $this->key('create-correction'),
            recordId: $correctionId,
        ));
        self::assertSame(1, $correction->version);
        $linked = $records->relate(new RelateRecordsCommand(
            $context,
            $header->handle,
            $correctionId,
            1,
            'reverses',
            $originalId,
            $this->key('link-correction'),
        ));
        self::assertSame(2, $linked->version);

        // The correction draws its own gapless number, and the original is never re-versioned.
        $correctionNumber = $this->number($records, $context, $header, $correctionId);
        self::assertNotSame($originalNumber, $correctionNumber);
        self::assertStringEndsWith('-0001', $originalNumber);
        self::assertStringEndsWith('-0002', $correctionNumber);
        $original = $records->read(new ReadRecordQuery($context, $header->handle, $originalId));
        self::assertSame($frozenVersion, $original->version);
        self::assertSame('Original document', $original->values['title']);

        // 5. Corrections are not exempt: a plain document creation backdated into closed P refuses
        // with the same stable code. The correction above succeeded because of its date, nothing else.
        try {
            $records->writeDocument(new WriteDocumentCommand(
                $context,
                $header->handle,
                'lines',
                [
                    'title' => 'Backdated document',
                    'total' => '1.00',
                    'posted_on' => $base->modify('+3 days')->format('Y-m-d'),
                ],
                $this->lines(1, $suffix . 'b'),
                $this->key('create-backdated'),
            ));
            self::fail('A creation backdated into the closed period must refuse by name.');
        } catch (BusinessRecordPostingPeriodClosed $refused) {
            self::assertSame($periodP, $refused->periodKey);
        }

        // The order read from the other side: once the correction is approved into the immutable
        // state, its open period admits the mutation attempt and the fence answers immutable.
        $correctionApproved = $records->action(new ExecuteRecordActionCommand(
            $context,
            $header->handle,
            $correctionId,
            2,
            'approve',
            $this->key('approve-correction'),
        ));
        self::assertSame('approved', $correctionApproved->workflowState);
        try {
            $records->update(new UpdateRecordCommand(
                $context,
                $header->handle,
                $correctionId,
                $correctionApproved->version,
                ['title' => 'Edited correction'],
                $this->key('update-correction'),
            ));
            self::fail('An approved correction in an open period must refuse as immutable.');
        } catch (BusinessRecordImmutable $refused) {
            self::assertSame('business_record.immutable', $refused->stableCode());
        }
    }

    /**
     * Install the line type and the posting-dated, numbered, immutable-after-approval document header.
     *
     * The header composes everything both primitives declare: a `posted_on` posting date, a yearly
     * allocated number, the approve workflow whose `approved` state closes the document, and the
     * reciprocal `reverses`/`reversed_by` pair the correction travels on.
     *
     * @param   Container         $container  Real integration container.
     * @param   ExecutionContext  $context    Administrator the installation runs as.
     * @param   string            $suffix     Per-process fixture suffix.
     *
     * @return  EntityTypeDefinition  The installed header definition.
     *
     * @since   2.0.0
     */
    private function install(
        Container $container,
        ExecutionContext $context,
        string $suffix,
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
        $document['fields'][] = [
            'handle' => 'posted_on',
            'label' => 'Posted on',
            'type' => 'core.date',
            'required' => false,
            'nullable' => true,
            'filterable' => true,
            'configuration' => ['posting_date' => true],
        ];
        $document['fields'][] = [
            'handle' => 'doc_number',
            'label' => 'Document number',
            'type' => 'core.sequence',
            'configuration' => [
                'scope' => 'site',
                'reset' => 'yearly',
                'prefix' => 'DOC-',
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
        ];
        $document['workflow'] = [
            'initial_state' => 'draft',
            'states' => ['draft', 'approved'],
            'immutable_states' => ['approved'],
            'transitions' => [[
                'handle' => 'approve',
                'from' => 'draft',
                'to' => 'approved',
                'capability' => 'business.record.action',
            ]],
        ];
        $document['actions'] = [[
            'handle' => 'approve',
            'label' => 'Approve',
            'capability' => 'business.record.action',
            'administrator' => true,
            'portal' => false,
            'public' => false,
            'transition' => 'approve',
        ]];
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
     * Read one document's allocated number back through the ordinary read path.
     *
     * @param   BusinessRecordService  $records   Live record service.
     * @param   ExecutionContext       $context   Administrator the read runs as.
     * @param   EntityTypeDefinition   $header    Installed header definition.
     * @param   string                 $recordId  Identity of the document to read.
     *
     * @return  string  The rendered number the header's sequence field was given.
     *
     * @since   2.0.0
     */
    private function number(
        BusinessRecordService $records,
        ExecutionContext $context,
        EntityTypeDefinition $header,
        string $recordId,
    ): string {
        $number = $records->read(
            new ReadRecordQuery($context, $header->handle, $recordId),
        )->values['doc_number'] ?? null;
        self::assertIsString($number);

        return $number;
    }

    /**
     * Mint one idempotency key unique to this process and operation.
     *
     * @param   string  $operation  Short operation slug.
     *
     * @return  IdempotencyKey  Key under the neutral fixture namespace.
     *
     * @since   2.0.0
     */
    private function key(string $operation): IdempotencyKey
    {
        return NeutralBusinessFixture::idempotencyKey('corrclose-' . $operation . '-' . $this->suffix());
    }

    /**
     * Answer the per-process suffix, minting it on first use.
     *
     * @return  string  Ten lowercase hex characters.
     *
     * @since   2.0.0
     */
    private function suffix(): string
    {
        return self::$suffix ??= strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
    }
}
