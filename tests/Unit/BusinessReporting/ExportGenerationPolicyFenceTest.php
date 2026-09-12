<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessReporting;

use DateTimeImmutable;
use Kumwe\App\Application\Authorization\AuthorizationDecision;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordQueryPurpose;
use Kumwe\App\BusinessRecord\Application\RecordBrowseResult;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\App\BusinessReporting\Application\BusinessRecordReportReader;
use Kumwe\App\BusinessReporting\Application\ExportArtifactRepository;
use Kumwe\App\BusinessReporting\Application\ExportArtifactStorage;
use Kumwe\App\BusinessReporting\Application\ExportExecutionContextResolver;
use Kumwe\App\BusinessReporting\Application\ExportGenerationRejected;
use Kumwe\App\BusinessReporting\Application\ExportGenerationService;
use Kumwe\App\BusinessReporting\Application\ExportJobDispatcher;
use Kumwe\App\BusinessReporting\Application\ExportPolicySnapshotProvider;
use Kumwe\App\BusinessReporting\Application\ExportService;
use Kumwe\App\BusinessReporting\Application\ReportCsvEncoder;
use Kumwe\App\BusinessReporting\Application\ReportDefinitionRegistry;
use Kumwe\App\BusinessReporting\Application\ReportScopeResolver;
use Kumwe\App\BusinessReporting\Application\ReportService;
use Kumwe\App\BusinessReporting\Application\StoredExportArtifact;
use Kumwe\App\BusinessReporting\Domain\ExportArtifact;
use Kumwe\App\BusinessReporting\Domain\ExportArtifactStatus;
use Kumwe\App\BusinessReporting\Domain\ReportColumnDefinition;
use Kumwe\App\BusinessReporting\Domain\ReportDefinition;
use Kumwe\Extension\Spi\BusinessReporting\Domain\ReportValueType;
use Kumwe\App\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;

#[CoversClass(ExportGenerationService::class)]
final class ExportGenerationPolicyFenceTest extends TestCase
{
    public function testPolicyFenceSpansPublicationAndOuterRollbackDeletesBytesButKeepsFailureAudit(): void
    {
        $transactions = new GenerationFenceTransactions();
        $clock = $this->createStub(ClockInterface::class);
        $now = new DateTimeImmutable('2026-08-10T12:00:00+00:00');
        $clock->method('now')->willReturn($now);
        $context = AuthorizationContext::human(['acme.reports.read', 'business.record.export']);
        $report = $this->report();
        $artifact = $this->artifact($context, $report, $now);
        $artifacts = new GenerationFenceArtifacts($transactions, $artifact);
        $storage = new GenerationFenceStorage($transactions);
        $audit = new GenerationFenceAudit($transactions);
        $authorization = $this->createStub(AuthorizationGateway::class);
        $authorization->method('decide')->willReturn(new AuthorizationDecision(true, 'test', 'allowed'));
        $scope = new GenerationFenceScope($transactions);
        $reports = new ReportDefinitionRegistry([$report]);
        $exports = new ExportService(
            $reports,
            $scope,
            $artifacts,
            $storage,
            $this->createStub(ExportJobDispatcher::class),
            new GenerationFencePolicy($transactions, str_repeat('a', 64)),
            $authorization,
            $transactions,
            $audit,
            $clock,
        );
        $reader = new GenerationFenceReader($transactions);
        $service = new ExportGenerationService(
            $artifacts,
            new GenerationFenceContext($transactions, $context),
            $exports,
            new ReportService($reports, $reader, $authorization, $scope),
            new ReportCsvEncoder(),
            $storage,
            $transactions,
            $audit,
            $clock,
        );

        $failure = null;
        try {
            $service->generate($artifact->id, $context);
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertNotNull($failure);
        self::assertSame('The policy-fenced outer commit failed.', $failure->getMessage());
        self::assertSame(ExportArtifactStatus::Queued, $artifacts->current()->status);
        self::assertSame([], $storage->objects);
        self::assertSame([1], $storage->storeDepths);
        self::assertSame([0], $storage->deleteDepths);
        self::assertSame(1, $reader->calls);
        self::assertSame([2, 1, 1], $scope->depths);
        self::assertSame(['business.report.export.attempt'], $audit->actions());
        self::assertSame(4, $transactions->calls);
        self::assertSame(2, $transactions->maximumDepth);
        self::assertFalse($transactions->active());
    }

    public function testContextResolverOperationalFailureRemainsRetryableWithoutRejection(): void
    {
        $transactions = new GenerationFenceTransactions(false);
        $clock = $this->clock();
        $now = $clock->now();
        $context = AuthorizationContext::human(['acme.reports.read', 'business.record.export']);
        $report = $this->report();
        $artifact = $this->artifact($context, $report, $now);
        $failure = new RuntimeException('The identity database is temporarily unavailable.');
        $artifacts = $this->createMock(ExportArtifactRepository::class);
        $artifacts->expects(self::once())->method('find')->with($artifact->id)->willReturn($artifact);
        $artifacts->expects(self::never())->method('save');
        $contexts = $this->createMock(ExportExecutionContextResolver::class);
        $contexts->expects(self::once())->method('resolve')->willThrowException($failure);
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::never())->method('record');
        $storage = $this->createMock(ExportArtifactStorage::class);
        $storage->expects(self::never())->method('store');
        $service = $this->service(
            $artifacts,
            $contexts,
            $transactions,
            $audit,
            $storage,
            $clock,
            $report,
        );

        try {
            $service->generate($artifact->id, $context);
            self::fail('An operational context failure must remain retryable.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame(1, $transactions->calls);
        self::assertSame(1, $transactions->maximumDepth);
        self::assertFalse($transactions->active());
    }

    public function testContextResolverSemanticRejectionDurablyFailsTheArtifact(): void
    {
        $transactions = new GenerationFenceTransactions(false);
        $clock = $this->clock();
        $now = $clock->now();
        $context = AuthorizationContext::human(['acme.reports.read', 'business.record.export']);
        $report = $this->report();
        $artifact = $this->artifact($context, $report, $now);
        $artifacts = new GenerationFenceArtifacts($transactions, $artifact);
        $audit = new GenerationFenceAudit($transactions);
        $storage = new GenerationFenceStorage($transactions);
        $failure = new ExportGenerationRejected('The export actor authority changed.');
        $contexts = $this->createMock(ExportExecutionContextResolver::class);
        $contexts->expects(self::once())->method('resolve')->willThrowException($failure);
        $service = $this->service(
            $artifacts,
            $contexts,
            $transactions,
            $audit,
            $storage,
            $clock,
            $report,
        );

        try {
            $service->generate($artifact->id, $context);
            self::fail('A semantic authority rejection must permanently fail the export.');
        } catch (ExportGenerationRejected $exception) {
            self::assertSame('The export authority or policy changed.', $exception->getMessage());
            self::assertSame($failure, $exception->getPrevious());
        }

        self::assertSame(ExportArtifactStatus::Failed, $artifacts->current()->status);
        self::assertSame('authorization_changed', $artifacts->current()->failureCode);
        self::assertSame(2, $artifacts->current()->version);
        self::assertSame(['business.report.export.generate'], $audit->actions());
        self::assertSame([], $storage->objects);
        self::assertSame(2, $transactions->calls);
        self::assertSame(1, $transactions->maximumDepth);
        self::assertFalse($transactions->active());
    }

    public function testStatusRepositoryOperationalFailureRemainsRetryableWithoutRejection(): void
    {
        $transactions = new GenerationFenceTransactions(false);
        $clock = $this->clock();
        $now = $clock->now();
        $context = AuthorizationContext::human(['acme.reports.read', 'business.record.export']);
        $report = $this->report();
        $artifact = $this->artifact($context, $report, $now);
        $failure = new RuntimeException('The export metadata database is temporarily unavailable.');
        $finds = 0;
        $artifacts = $this->createMock(ExportArtifactRepository::class);
        $artifacts->expects(self::exactly(2))
            ->method('find')
            ->with($artifact->id)
            ->willReturnCallback(static function () use ($artifact, $failure, &$finds): ExportArtifact {
                ++$finds;
                if ($finds === 1) {
                    return $artifact;
                }
                throw $failure;
            });
        $artifacts->expects(self::never())->method('save');
        $contexts = $this->createStub(ExportExecutionContextResolver::class);
        $contexts->method('resolve')->willReturn($context);
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::never())->method('record');
        $storage = $this->createMock(ExportArtifactStorage::class);
        $storage->expects(self::never())->method('store');
        $service = $this->service(
            $artifacts,
            $contexts,
            $transactions,
            $audit,
            $storage,
            $clock,
            $report,
        );

        try {
            $service->generate($artifact->id, $context);
            self::fail('An operational status failure must remain retryable.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame(2, $transactions->calls);
        self::assertSame(2, $transactions->maximumDepth);
        self::assertFalse($transactions->active());
    }

    private function service(
        ExportArtifactRepository $artifacts,
        ExportExecutionContextResolver $contexts,
        GenerationFenceTransactions $transactions,
        AuditRecorder $audit,
        ExportArtifactStorage $storage,
        ClockInterface $clock,
        ReportDefinition $report,
    ): ExportGenerationService {
        $authorization = $this->createStub(AuthorizationGateway::class);
        $authorization->method('decide')->willReturn(new AuthorizationDecision(true, 'test', 'allowed'));
        $scope = $this->createStub(ReportScopeResolver::class);
        $policies = $this->createStub(ExportPolicySnapshotProvider::class);
        $policies->method('snapshot')->willReturn(str_repeat('a', 64));
        $reports = new ReportDefinitionRegistry([$report]);
        $exports = new ExportService(
            $reports,
            $scope,
            $artifacts,
            $storage,
            $this->createStub(ExportJobDispatcher::class),
            $policies,
            $authorization,
            $transactions,
            $audit,
            $clock,
        );

        return new ExportGenerationService(
            $artifacts,
            $contexts,
            $exports,
            new ReportService(
                $reports,
                $this->createStub(BusinessRecordReportReader::class),
                $authorization,
                $scope,
            ),
            new ReportCsvEncoder(),
            $storage,
            $transactions,
            $audit,
            $clock,
        );
    }

    private function clock(): ClockInterface
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-10T12:00:00+00:00'));

        return $clock;
    }

    private function report(): ReportDefinition
    {
        return new ReportDefinition(
            'acme.open_items',
            1,
            'Open items',
            'acme.item',
            'acme.reports.read',
            [],
            [],
            [new ReportColumnDefinition('reference', 'Reference', 'reference', ReportValueType::String)],
        );
    }

    private function artifact(
        ExecutionContext $context,
        ReportDefinition $report,
        DateTimeImmutable $now,
    ): ExportArtifact {
        return new ExportArtifact(
            '019fecc6-8b97-7079-98e9-dc666b067437',
            $report->identifier(),
            $report->version,
            $report->checksum(),
            $context->actorId(),
            $context->site()->identifier(),
            null,
            null,
            $context->surface(),
            $context->approvalFingerprint(),
            str_repeat('a', 64),
            [],
            str_repeat('b', 64),
            ExportArtifactStatus::Queued,
            $now,
            $now->modify('+1 hour'),
            null,
            null,
            'acme_open_items-20260810-120000.csv',
            null,
            null,
            null,
            null,
            null,
            null,
            1,
        );
    }
}

final class GenerationFenceTransactions implements TransactionManager
{
    /** @var list<array{commit: list<callable(): void>, rollback: list<callable(): void>}> */
    private array $frames = [];
    private bool $failNextOuterCommit;
    public int $calls = 0;
    public int $maximumDepth = 0;

    public function __construct(bool $failNextOuterCommit = true)
    {
        $this->failNextOuterCommit = $failNextOuterCommit;
    }

    public function transactional(callable $operation): mixed
    {
        ++$this->calls;
        $this->frames[] = ['commit' => [], 'rollback' => []];
        $this->maximumDepth = max($this->maximumDepth, count($this->frames));
        try {
            $result = $operation();
        } catch (\Throwable $exception) {
            $frame = array_pop($this->frames);
            $this->invoke(is_array($frame) ? $frame['rollback'] : []);
            throw $exception;
        }
        $frame = array_pop($this->frames);
        if (!is_array($frame)) {
            throw new \LogicException('The generation transaction frame was lost.');
        }
        $parent = array_key_last($this->frames);
        if ($parent !== null) {
            array_push($this->frames[$parent]['commit'], ...$frame['commit']);
            array_push($this->frames[$parent]['rollback'], ...$frame['rollback']);
        } elseif ($this->failNextOuterCommit) {
            $this->failNextOuterCommit = false;
            $this->invoke($frame['rollback']);
            throw new RuntimeException('The policy-fenced outer commit failed.');
        } else {
            $this->invoke($frame['commit']);
        }

        return $result;
    }

    public function afterCommit(callable $operation): void
    {
        $frame = array_key_last($this->frames);
        if ($frame === null) {
            $operation();
            return;
        }
        $this->frames[$frame]['commit'][] = $operation;
    }

    public function afterRollback(callable $operation): void
    {
        $frame = array_key_last($this->frames);
        if ($frame !== null) {
            $this->frames[$frame]['rollback'][] = $operation;
        }
    }

    public function active(): bool
    {
        return $this->frames !== [];
    }

    public function depth(): int
    {
        return count($this->frames);
    }

    /** @param list<callable(): void> $operations */
    private function invoke(array $operations): void
    {
        foreach ($operations as $operation) {
            $operation();
        }
    }
}

final class GenerationFenceArtifacts implements ExportArtifactRepository
{
    /** @var array<int, ExportArtifact> */
    private array $versions;

    public function __construct(
        private readonly GenerationFenceTransactions $transactions,
        ExportArtifact $artifact,
    ) {
        $this->versions = [$artifact->version => $artifact];
    }

    public function add(ExportArtifact $artifact): void
    {
        throw new \LogicException('The generation fixture is already seeded.');
    }

    public function find(string $id): ?ExportArtifact
    {
        $current = $this->current();

        return $current->id === $id ? $current : null;
    }

    public function save(ExportArtifact $artifact, int $expectedVersion): void
    {
        if (!$this->transactions->active() || $this->current()->version !== $expectedVersion) {
            throw new RuntimeException('The generation fixture CAS failed.');
        }
        $version = $artifact->version;
        $this->versions[$version] = $artifact;
        $this->transactions->afterRollback(function () use ($version): void {
            unset($this->versions[$version]);
        });
    }

    public function current(): ExportArtifact
    {
        $versions = $this->versions;
        krsort($versions, SORT_NUMERIC);
        $current = reset($versions);

        return $current instanceof ExportArtifact
            ? $current
            : throw new \LogicException('The generation fixture lost its artifact.');
    }
}

final class GenerationFenceStorage implements ExportArtifactStorage
{
    /** @var array<string, string> */
    public array $objects = [];
    /** @var list<int> */
    public array $storeDepths = [];
    /** @var list<int> */
    public array $deleteDepths = [];

    public function __construct(private readonly GenerationFenceTransactions $transactions)
    {
    }

    public function store(string $artifactId, iterable $chunks): StoredExportArtifact
    {
        if (!$this->transactions->active()) {
            throw new RuntimeException('Export bytes escaped the policy fence.');
        }
        $this->storeDepths[] = $this->transactions->depth();
        $bytes = '';
        foreach ($chunks as $chunk) {
            $bytes .= $chunk;
        }
        $key = $artifactId . '.' . str_repeat('c', 32) . '.csv';
        $this->objects[$key] = $bytes;

        return new StoredExportArtifact($key, strlen($bytes), hash('sha256', $bytes));
    }

    public function open(StoredExportArtifact $artifact): mixed
    {
        throw new \LogicException('The generation fixture does not download exports.');
    }

    public function delete(string $key): void
    {
        $this->deleteDepths[] = $this->transactions->depth();
        unset($this->objects[$key]);
    }
}

final class GenerationFenceAudit implements AuditRecorder
{
    /** @var array<string, AuditEvent> */
    private array $events = [];

    public function __construct(private readonly GenerationFenceTransactions $transactions)
    {
    }

    public function record(AuditEvent $event): void
    {
        if (!$this->transactions->active()) {
            throw new RuntimeException('Generation audit evidence escaped its transaction.');
        }
        $id = $event->id();
        $this->events[$id] = $event;
        $this->transactions->afterRollback(function () use ($id): void {
            unset($this->events[$id]);
        });
    }

    /** @return list<string> */
    public function actions(): array
    {
        return array_values(array_map(static fn (AuditEvent $event): string => $event->action(), $this->events));
    }
}

final readonly class GenerationFenceContext implements ExportExecutionContextResolver
{
    public function __construct(
        private GenerationFenceTransactions $transactions,
        private ExecutionContext $context,
    ) {
    }

    public function resolve(ExportArtifact $artifact, ExecutionContext $workerContext): ExecutionContext
    {
        if (!$this->transactions->active()) {
            throw new RuntimeException('Context resolution escaped the policy fence.');
        }

        return $this->context;
    }
}

final readonly class GenerationFencePolicy implements ExportPolicySnapshotProvider
{
    public function __construct(
        private GenerationFenceTransactions $transactions,
        private string $snapshot,
    ) {
    }

    public function snapshot(
        ExecutionContext $context,
        ReportDefinition $report,
        ?string $organizationIdentifier,
        BusinessRecordQueryPurpose $purpose,
    ): string {
        if (!$this->transactions->active() || $purpose !== BusinessRecordQueryPurpose::Export) {
            throw new RuntimeException('Policy snapshot escaped the generation transaction.');
        }

        return $this->snapshot;
    }
}

final class GenerationFenceScope implements ReportScopeResolver
{
    /** @var list<int> */
    public array $depths = [];

    public function __construct(private readonly GenerationFenceTransactions $transactions)
    {
    }

    public function resolve(
        ExecutionContext $context,
        ReportDefinition $report,
        ?string $assertedOrganization,
    ): ?string {
        if (!$this->transactions->active()) {
            throw new RuntimeException('Report scope resolution escaped the policy fence.');
        }
        $this->depths[] = $this->transactions->depth();

        return $assertedOrganization;
    }
}

final class GenerationFenceReader implements BusinessRecordReportReader
{
    public int $calls = 0;

    public function __construct(private readonly GenerationFenceTransactions $transactions)
    {
    }

    public function browse(
        ExecutionContext $context,
        string $definitionIdentifier,
        RecordQuerySpecification $specification,
        ?string $organizationIdentifier,
        BusinessRecordQueryPurpose $purpose,
    ): RecordBrowseResult {
        if (!$this->transactions->active() || $purpose !== BusinessRecordQueryPurpose::Export) {
            throw new RuntimeException('Report materialization escaped the policy fence.');
        }
        ++$this->calls;

        return new RecordBrowseResult([]);
    }
}
