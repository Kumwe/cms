<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessRecord;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Application\Automation\IdempotencyKey;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\ArchiveRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
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
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordNotFound;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordPostingPeriodClosed;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\App\BusinessRecord\Application\Query\RecordHistoryQuery;
use Kumwe\App\BusinessRecord\Application\PostingPeriodCalendar;
use Kumwe\App\BusinessRecord\Application\PostingPeriodLock;
use Kumwe\App\BusinessRecord\Application\PostingPeriodService;
use Kumwe\App\BusinessRecord\Domain\PostingPeriod;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrinePostingPeriodRepository;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\App\Kernel\Container;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Proves the posting-period acceptance whole, against the real container, database and policies.
 *
 * Business records have no delivery adapter of their own — REST, console, MCP, portal and the
 * administrator screens all arrive at `BusinessRecordService` — so refusing each mutation path at
 * that one boundary is what refuses the mutation on every surface. What is proven here: a mutation
 * dated inside a closed period is refused on every mutation path, before the fence, under the one
 * stable code; closing is capability-gated and audited; a record dated outside the range is entirely
 * unaffected; a pure workflow transition is deliberately admitted; and re-opening restores posting.
 *
 * @since  2.0.0
 */
#[CoversClass(PostingPeriodLock::class)]
#[CoversClass(PostingPeriodService::class)]
#[CoversClass(DoctrinePostingPeriodRepository::class)]
#[CoversClass(BusinessRecordService::class)]
#[CoversClass(BusinessRecordPostingPeriodClosed::class)]
final class PostingPeriodLockIntegrationTest extends TestCase
{
    /**
     * Stable per-process suffix keeping this suite's definitions apart from every other run.
     *
     * @var    ?string
     * @since  2.0.0
     */
    private static ?string $suffix = null;

    /**
     * Closing a period is capability-gated, audited, and read back through the calendar seam.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testClosingAPeriodIsCapabilityGatedAndAudited(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $periods = $container->get(PostingPeriodService::class);
        self::assertInstanceOf(PostingPeriodService::class, $periods);
        $key = 'gate-' . $this->suffix();
        // The database outlives test processes, so the declared range is unique per run — a repeated
        // range would let an earlier run's declaration answer the containment assertions below.
        $offset = (int) hexdec(substr($this->suffix(), 0, 8)) % 500_000;
        $starts = (new DateTimeImmutable('3000-01-01T00:00:00Z'))->modify('+' . $offset . ' minutes');
        $ends = $starts->modify('+1 hour');

        $reader = TestKernelFactory::contextFromGrantRows($container, [
            ['capability' => PostingPeriodService::READ, 'scope_type' => 'global', 'scope_identifier' => null],
        ]);
        try {
            $periods->close($reader, $key, $starts, $ends);
            self::fail('Closing a posting period must demand the manage capability.');
        } catch (AuthorizationDenied) {
            self::assertTrue(true);
        }

        $closed = $periods->close($context, $key, $starts, $ends);
        self::assertTrue($closed->isClosed());
        self::assertContains($key, array_map(
            static fn (PostingPeriod $period): string => $period->key,
            $periods->list($context),
        ));

        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        self::assertSame('1', (string) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE action = ? AND subject_type = ? AND subject_id = ?',
            $tables->quoted('audit_events'),
        ), ['business.period.close', 'business_posting_period', 'default::' . $key]));

        // The calendar seam answers with the declared period's stable key, open or closed alike.
        $calendar = $container->get(PostingPeriodCalendar::class);
        self::assertInstanceOf(PostingPeriodCalendar::class, $calendar);
        $resolved = $calendar->periodContaining('default', null, $starts->modify('+30 minutes'));
        self::assertSame($key, $resolved?->key);
        self::assertNull($calendar->periodContaining('default', null, $ends->modify('+30 minutes')));
    }

    /**
     * Every mutation path refuses a posting date inside the closed range, and only those.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMutationDatedInsideAClosedPeriodIsRefusedOnEveryMutationPath(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        $periods = $container->get(PostingPeriodService::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(PostingPeriodService::class, $periods);
        $suffix = $this->suffix();
        [$owner, $target] = $this->installGraph($container, $context, $suffix);

        $targetOne = Uuid::uuid7()->toString();
        $targetTwo = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $target,
            ['label' => 'Posting target one ' . $suffix],
            $this->key('target-one'),
            recordId: $targetOne,
        ));
        $records->create(new CreateRecordCommand(
            $context,
            $target,
            ['label' => 'Posting target two ' . $suffix],
            $this->key('target-two'),
            recordId: $targetTwo,
        ));

        $august = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $owner,
            ['title' => 'August owner', 'posted_on' => '2026-08-08'],
            $this->key('owner-august'),
            recordId: $august,
        ));
        $version = $records->relate(new RelateRecordsCommand(
            $context,
            $owner,
            $august,
            1,
            'tags',
            $targetOne,
            $this->key('owner-tag-one'),
            0,
        ))->version;
        $version = $records->relate(new RelateRecordsCommand(
            $context,
            $owner,
            $august,
            $version,
            'tags',
            $targetTwo,
            $this->key('owner-tag-two'),
            1,
        ))->version;

        $september = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $owner,
            ['title' => 'September owner', 'posted_on' => '2026-09-02'],
            $this->key('owner-september'),
            recordId: $september,
        ));
        $archived = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $owner,
            ['title' => 'August archived', 'posted_on' => '2026-08-09'],
            $this->key('owner-archived'),
            recordId: $archived,
        ));
        $records->archive(new ArchiveRecordCommand($context, $owner, $archived, 1, $this->key('archive-open')));
        $document = $records->writeDocument(new WriteDocumentCommand(
            $context,
            $owner,
            'lines',
            ['title' => 'August document', 'posted_on' => '2026-08-05'],
            [new DocumentLineInput(['description' => 'August line', 'units' => '1.000'])],
            $this->key('document-open'),
        ));

        $periods->close(
            $context,
            'aug-' . $suffix,
            new DateTimeImmutable('2026-08-01T00:00:00Z'),
            new DateTimeImmutable('2026-09-01T00:00:00Z'),
        );

        $refusals = [
            'a backdated creation' => fn () => $records->create(new CreateRecordCommand(
                $context,
                $owner,
                ['title' => 'Backdated', 'posted_on' => '2026-08-15'],
                $this->key('refused-create'),
            )),
            'an update of a closed-period record' => fn () => $records->update(new UpdateRecordCommand(
                $context,
                $owner,
                $august,
                $version,
                ['title' => 'Renamed'],
                $this->key('refused-update'),
            )),
            'a date moved into the closed period' => fn () => $records->update(new UpdateRecordCommand(
                $context,
                $owner,
                $september,
                1,
                ['posted_on' => '2026-08-20'],
                $this->key('refused-move'),
            )),
            'an archive' => fn () => $records->archive(new ArchiveRecordCommand(
                $context,
                $owner,
                $august,
                $version,
                $this->key('refused-archive'),
            )),
            'a delete' => fn () => $records->delete(new DeleteRecordCommand(
                $context,
                $owner,
                $august,
                $version,
                $this->key('refused-delete'),
            )),
            'a restore' => fn () => $records->restore(new RestoreRecordCommand(
                $context,
                $owner,
                $archived,
                2,
                $this->key('refused-restore'),
            )),
            'an owned-line creation' => fn () => $records->relate(new RelateRecordsCommand(
                $context,
                $owner,
                $august,
                $version,
                'lines',
                Uuid::uuid7()->toString(),
                $this->key('refused-line'),
                0,
                targetValues: ['description' => 'Refused line', 'units' => '1.000'],
            )),
            'an unrelate' => fn () => $records->unrelate(new UnrelateRecordsCommand(
                $context,
                $owner,
                $august,
                $version,
                'tags',
                $targetOne,
                $this->key('refused-unrelate'),
            )),
            'a reorder' => fn () => $records->reorder(new ReorderRecordLinesCommand(
                $context,
                $owner,
                $august,
                $version,
                'tags',
                [$targetTwo, $targetOne],
                $this->key('refused-reorder'),
            )),
            'a document amendment' => fn () => $records->writeDocument(new WriteDocumentCommand(
                $context,
                $owner,
                'lines',
                ['title' => 'Amended document', 'posted_on' => '2026-08-05'],
                [],
                $this->key('refused-amend'),
                DocumentWriteIntent::Amend,
                $document->version,
                $document->recordId,
            )),
            'a backdated document creation' => fn () => $records->writeDocument(new WriteDocumentCommand(
                $context,
                $owner,
                'lines',
                ['title' => 'Backdated document', 'posted_on' => '2026-08-06'],
                [new DocumentLineInput(['description' => 'Refused line', 'units' => '2.000'])],
                $this->key('refused-doc-create'),
            )),
            'a custom action attempt' => fn () => $records->guardCustomActionPostingPeriod(
                new ExecuteRecordActionCommand(
                    $context,
                    $owner,
                    $august,
                    $version,
                    'approve',
                    $this->key('refused-custom'),
                ),
            ),
        ];
        foreach ($refusals as $name => $attempt) {
            try {
                $attempt();
                self::fail(sprintf('The closed period must refuse %s.', $name));
            } catch (BusinessRecordPostingPeriodClosed $refused) {
                self::assertSame('business_record.posting_period_closed', $refused->stableCode(), $name);
                self::assertSame('aug-' . $suffix, $refused->periodKey, $name);
            }
        }

        // Absence stays the mutation's own verdict: a missing record is reported as not found even
        // while a period is closed, so the gate opens no existence side channel and replay of a
        // completed delete stays reachable.
        try {
            $records->update(new UpdateRecordCommand(
                $context,
                $owner,
                Uuid::uuid7()->toString(),
                1,
                ['title' => 'Ghost'],
                $this->key('missing-update'),
            ));
            self::fail('A missing record must answer not-found, not a period refusal.');
        } catch (BusinessRecordNotFound) {
            self::assertTrue(true);
        }

        // A record dated outside the range is unaffected, in both value and date terms.
        $septemberVersion = $records->update(new UpdateRecordCommand(
            $context,
            $owner,
            $september,
            1,
            ['title' => 'September owner, still open'],
            $this->key('open-update'),
        ))->version;
        self::assertSame(2, $septemberVersion);
        $records->create(new CreateRecordCommand(
            $context,
            $owner,
            ['title' => 'September fresh', 'posted_on' => '2026-09-03'],
            $this->key('open-create'),
        ));
        $records->create(new CreateRecordCommand(
            $context,
            $owner,
            ['title' => 'Undated owner'],
            $this->key('open-undated'),
        ));

        // A pure workflow transition writes no posting-dated content, so the closed period admits it.
        $transitioned = $records->action(new ExecuteRecordActionCommand(
            $context,
            $owner,
            $august,
            $version,
            'approve',
            $this->key('open-transition'),
        ));
        self::assertSame('approved', $transitioned->workflowState);

        // Re-opening is the administrative undo: the refused update now applies.
        $periods->reopen($context, 'aug-' . $suffix);
        $reopened = $records->update(new UpdateRecordCommand(
            $context,
            $owner,
            $august,
            $transitioned->version,
            ['title' => 'After reopen'],
            $this->key('reopen-update'),
        ));
        self::assertSame($transitioned->version + 1, $reopened->version);
    }

    /**
     * A hard-delete set-null sweep refuses a closed source and rolls back every induced write atomically.
     *
     * Two referrers are given storage keys that sort the open-period row first. The sweep therefore
     * rewrites that source before it discovers the closed-period source. The refusal must still leave the
     * target present, both references attached, every version and revision unchanged, and no audit entry
     * from the attempted sweep. Reopening and replaying the exact idempotency key then succeeds, proving
     * the refused transaction did not strand its claim either.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAClosedSetNullReferrerRollsBackTheWholeHardDeleteSweep(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        $periods = $container->get(PostingPeriodService::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(PostingPeriodService::class, $periods);
        [$owner, $target] = $this->installGraph($container, $context, $this->suffix());

        $openSourceId = '10000000-0000-4000-8000-000000000001';
        $closedSourceId = '20000000-0000-4000-8000-000000000002';
        $targetId = '30000000-0000-4000-8000-000000000003';
        $records->create(new CreateRecordCommand(
            $context,
            $target,
            ['label' => 'Atomic set-null target ' . $this->suffix()],
            $this->key('atomic-target'),
            recordId: $targetId,
        ));
        foreach (
            [
                [$openSourceId, 'Open source', '2410-02-05', 'atomic-open'],
                [$closedSourceId, 'Closed source', '2410-01-15', 'atomic-closed'],
            ] as [$recordId, $title, $postedOn, $key]
        ) {
            $records->create(new CreateRecordCommand(
                $context,
                $owner,
                ['title' => $title, 'posted_on' => $postedOn],
                $this->key($key),
                recordId: $recordId,
            ));
            $related = $records->relate(new RelateRecordsCommand(
                $context,
                $owner,
                $recordId,
                1,
                'nullable_target',
                $targetId,
                $this->key($key . '-relate'),
            ));
            self::assertSame(2, $related->version);
        }

        $sourceBefore = [];
        foreach ([$openSourceId, $closedSourceId] as $recordId) {
            $view = $records->read(new ReadRecordQuery(
                $context,
                $owner,
                $recordId,
                includes: ['nullable_target'],
            ));
            self::assertSame($recordId, $view->recordKey);
            self::assertCount(1, $view->includes['nullable_target']);
            $sourceBefore[$recordId] = [
                'record_key' => $view->recordKey,
                'version' => $view->version,
                'history' => count($records->history(new RecordHistoryQuery(
                    $context,
                    $owner,
                    $recordId,
                ))->revisions),
                'audit' => $this->recordAuditCount($container, $view->recordKey),
            ];
        }
        self::assertLessThan(
            0,
            strcmp(
                $sourceBefore[$openSourceId]['record_key'],
                $sourceBefore[$closedSourceId]['record_key'],
            ),
            'The open-period source must sort before the closed-period source.',
        );
        $targetBefore = $records->read(new ReadRecordQuery($context, $target, $targetId));
        $targetHistory = count($records->history(new RecordHistoryQuery(
            $context,
            $target,
            $targetId,
        ))->revisions);
        $targetAudit = $this->recordAuditCount($container, $targetBefore->recordKey);

        $periodKey = 'atomic-set-null-' . $this->suffix();
        $periods->close(
            $context,
            $periodKey,
            new DateTimeImmutable('2410-01-01T00:00:00Z'),
            new DateTimeImmutable('2410-02-01T00:00:00Z'),
        );
        try {
            $deleteKey = $this->key('atomic-delete');
            $deleteIdempotencyBefore = $this->idempotencyCount($container, $deleteKey->value());
            try {
                $records->delete(new DeleteRecordCommand(
                    $context,
                    $target,
                    $targetId,
                    1,
                    $deleteKey,
                ));
                self::fail('A closed-period set-null source must refuse the target hard delete.');
            } catch (BusinessRecordPostingPeriodClosed $refused) {
                self::assertSame('business_record.posting_period_closed', $refused->stableCode());
                self::assertSame($periodKey, $refused->periodKey);
            }

            $targetAfter = $records->read(new ReadRecordQuery($context, $target, $targetId));
            self::assertSame($targetBefore->version, $targetAfter->version);
            self::assertSame($targetHistory, count($records->history(new RecordHistoryQuery(
                $context,
                $target,
                $targetId,
            ))->revisions));
            self::assertSame($targetAudit, $this->recordAuditCount($container, $targetAfter->recordKey));
            self::assertSame(
                $deleteIdempotencyBefore,
                $this->idempotencyCount($container, $deleteKey->value()),
                'The refused delete must leave no idempotency claim behind.',
            );
            foreach ([$openSourceId, $closedSourceId] as $recordId) {
                $view = $records->read(new ReadRecordQuery(
                    $context,
                    $owner,
                    $recordId,
                    includes: ['nullable_target'],
                ));
                self::assertSame($sourceBefore[$recordId]['version'], $view->version);
                self::assertCount(1, $view->includes['nullable_target']);
                self::assertSame($targetId, $view->includes['nullable_target'][0]->recordId);
                self::assertSame($sourceBefore[$recordId]['history'], count($records->history(
                    new RecordHistoryQuery($context, $owner, $recordId),
                )->revisions));
                self::assertSame(
                    $sourceBefore[$recordId]['audit'],
                    $this->recordAuditCount($container, $view->recordKey),
                );
            }
        } finally {
            $periods->reopen($context, $periodKey);
        }

        $deleted = $records->delete(new DeleteRecordCommand(
            $context,
            $target,
            $targetId,
            1,
            $deleteKey,
        ));
        self::assertTrue($deleted->deleted, 'The refused idempotency claim rolled back with the delete.');
        self::assertSame(
            $deleteIdempotencyBefore + 1,
            $this->idempotencyCount($container, $deleteKey->value()),
        );
        foreach ([$openSourceId, $closedSourceId] as $recordId) {
            $view = $records->read(new ReadRecordQuery(
                $context,
                $owner,
                $recordId,
                includes: ['nullable_target'],
            ));
            self::assertSame(3, $view->version);
            self::assertSame([], $view->includes['nullable_target']);
        }
        $this->expectException(BusinessRecordNotFound::class);
        $records->read(new ReadRecordQuery($context, $target, $targetId));
    }

    /**
     * Install the posting-dated owner graph once per process and answer its handles.
     *
     * The owner is the relationship fixture widened by the whole primitive under test: a nullable
     * `posted_on` date declared as the posting date, an approve workflow that proves the transition
     * exemption, and a many-to-one set-null relationship used by the atomic hard-delete proof.
     *
     * @param   Container         $container  Real integration container.
     * @param   ExecutionContext  $context    Administrator the installation runs as.
     * @param   string            $suffix     Per-process fixture suffix.
     *
     * @return  array{string, string}  Owner handle and relation-target handle.
     *
     * @since   2.0.0
     */
    private function installGraph(Container $container, ExecutionContext $context, string $suffix): array
    {
        $target = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::relationTargetDocument($suffix, Uuid::uuid5(
                Uuid::NAMESPACE_URL,
                'kumwe:test:posting-target:' . $suffix,
            )->toString()),
        );
        $line = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::ownedLineDocument($suffix, Uuid::uuid5(
                Uuid::NAMESPACE_URL,
                'kumwe:test:posting-line:' . $suffix,
            )->toString()),
        );
        $document = NeutralBusinessFixture::relationshipOwnerDocument(
            $suffix,
            Uuid::uuid5(Uuid::NAMESPACE_URL, 'kumwe:test:posting-owner:' . $suffix)->toString(),
            $target->handle,
            $line->handle,
        );
        $document['fields'][] = [
            'handle' => 'posted_on',
            'label' => 'Posted on',
            'type' => 'core.date',
            'required' => false,
            'nullable' => true,
            'filterable' => true,
            'sortable' => true,
            'configuration' => ['posting_date' => true],
        ];
        $document['relationships'][] = [
            'handle' => 'nullable_target',
            'label' => 'Nullable target',
            'kind' => 'many_to_one',
            'target' => $target->handle,
            'on_delete' => 'set_null',
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
        $document['workflow'] = [
            'initial_state' => 'draft',
            'states' => ['draft', 'approved'],
            'transitions' => [[
                'handle' => 'approve',
                'from' => 'draft',
                'to' => 'approved',
                'capability' => 'business.record.action',
            ]],
        ];
        $owner = NeutralBusinessFixture::install($container, $context, $document);

        return [$owner->handle, $target->handle];
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
        return NeutralBusinessFixture::idempotencyKey('period-' . $operation . '-' . $this->suffix());
    }

    /**
     * Count committed audit entries for one internal business-record key.
     *
     * @param   Container  $container  Integration container holding the audit table and table map.
     * @param   string     $recordKey  Internal record key used as the audit subject identifier.
     *
     * @return  int  Number of committed entries for this record.
     *
     * @since   2.0.0
     */
    private function recordAuditCount(Container $container, string $recordKey): int
    {
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);

        return (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE subject_type = ? AND subject_id = ?',
            $tables->quoted('audit_events'),
        ), ['business_record', $recordKey]);
    }

    /**
     * Count durable business-command claims under one caller-minted operation identifier.
     *
     * @param   Container  $container    Integration container holding the command ledger and table map.
     * @param   string     $operationId  Caller-minted idempotency key stored as `operation_id`.
     *
     * @return  int  Number of durable claims for the operation identifier.
     *
     * @since   2.0.0
     */
    private function idempotencyCount(Container $container, string $operationId): int
    {
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);

        return (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE operation_id = ?',
            $tables->quoted('business_command_idempotency'),
        ), [$operationId]);
    }

    /**
     * Answer the per-process suffix, minting it on first use.
     *
     * @return  string  Twelve lowercase hex characters.
     *
     * @since   2.0.0
     */
    private function suffix(): string
    {
        return self::$suffix ??= strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
    }
}
