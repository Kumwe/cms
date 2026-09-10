<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessReporting;

use DateTimeImmutable;
use Kumwe\App\Application\Authorization\AuthorizationDecision;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable;
use Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordQueryPurpose;
use Kumwe\App\BusinessReporting\Application\ExportArtifactRepository;
use Kumwe\App\BusinessReporting\Application\ExportArtifactStorage;
use Kumwe\App\BusinessReporting\Application\ExportArtifactUnavailable;
use Kumwe\App\BusinessReporting\Application\ExportJobDispatcher;
use Kumwe\App\BusinessReporting\Application\ExportPolicySnapshotProvider;
use Kumwe\App\BusinessReporting\Application\ExportService;
use Kumwe\App\BusinessReporting\Application\ReportDefinitionRegistry;
use Kumwe\App\BusinessReporting\Application\ReportScopeResolver;
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
use Throwable;

#[CoversClass(ExportService::class)]
final class ExportServiceTransactionTest extends TestCase
{
    public function testPolicySnapshotsRemainInsideStandaloneAndNestedExportTransactions(): void
    {
        $transactions = new ExportTransactionProbe();
        $policy = str_repeat('a', 64);
        $checksum = str_repeat('c', 64);
        $snapshotDepths = [];
        $auditActions = [];
        $storedArtifact = null;
        $policies = $this->createMock(ExportPolicySnapshotProvider::class);
        $policies->expects(self::exactly(4))
            ->method('snapshot')
            ->willReturnCallback(function (
                ExecutionContext $context,
                ReportDefinition $report,
                ?string $organizationIdentifier,
                BusinessRecordQueryPurpose $purpose,
            ) use (
                $transactions,
                $policy,
                &$snapshotDepths
): string {
                self::assertTrue($transactions->active());
                self::assertSame(BusinessRecordQueryPurpose::Export, $purpose);
                self::assertNull($organizationIdentifier);
                $snapshotDepths[] = $transactions->depth;

                return $policy;
            });
        $artifacts = $this->createMock(ExportArtifactRepository::class);
        $artifacts->expects(self::once())
            ->method('add')
            ->willReturnCallback(function (ExportArtifact $artifact) use (
                $transactions,
                &$storedArtifact,
            ): void {
                self::assertTrue($transactions->active());
                $storedArtifact = $artifact;
            });
        $artifacts->expects(self::exactly(3))
            ->method('find')
            ->willReturnCallback(function (string $artifactId) use (
                $transactions,
                &$storedArtifact,
            ): ?ExportArtifact {
                self::assertTrue($transactions->active());
                self::assertSame($storedArtifact?->id, $artifactId);

                return $storedArtifact;
            });
        $jobs = $this->createMock(ExportJobDispatcher::class);
        $jobs->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(function (
                ExecutionContext $context,
                string $artifactId
            ) use (
                $transactions,
                &$storedArtifact,
            ): void {
                self::assertTrue($transactions->active());
                self::assertSame($storedArtifact?->id, $artifactId);
            });
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::exactly(2))
            ->method('record')
            ->willReturnCallback(function (AuditEvent $event) use (
                $transactions,
                &$auditActions,
            ): void {
                self::assertTrue($transactions->active());
                $auditActions[] = $event->action();
            });
        $authorization = $this->createStub(AuthorizationGateway::class);
        $authorization->method('decide')->willReturn(new AuthorizationDecision(true, 'test.export', 'allowed'));
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-10T12:00:00+00:00'));
        $storage = $this->createMock(ExportArtifactStorage::class);
        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, 'test');
        rewind($stream);
        $storage->expects(self::once())
            ->method('open')
            ->willReturnCallback(function (StoredExportArtifact $artifact) use (
                $transactions,
                $stream,
                $checksum,
            ) {
                self::assertTrue($transactions->active());
                self::assertSame(4, $artifact->size);
                self::assertSame($checksum, $artifact->checksum);

                return $stream;
            });
        $service = new ExportService(
            new ReportDefinitionRegistry([$this->report()]),
            new class implements ReportScopeResolver {
                public function resolve(
                    ExecutionContext $context,
                    ReportDefinition $report,
                    ?string $assertedOrganization,
                ): ?string {
                    return $assertedOrganization;
                }
            },
            $artifacts,
            $storage,
            $jobs,
            $policies,
            $authorization,
            $transactions,
            $audit,
            $clock,
        );
        $context = AuthorizationContext::human(['acme.reports.read', 'business.record.export']);

        $created = $service->request($context, 'acme.open_items');
        self::assertSame([
            [
                'capability' => 'acme.reports.read',
                'scope_type' => 'global',
                'scope_identifier' => null,
            ],
            [
                'capability' => 'business.record.export',
                'scope_type' => 'global',
                'scope_identifier' => null,
            ],
        ], $created->authorityGrantRows);
        self::assertSame($created, $service->status($context, $created->id));
        $nested = $transactions->transactional(
            static fn (): ExportArtifact => $service->status($context, $created->id),
        );
        $storedArtifact = $created
            ->start(new DateTimeImmutable('2026-08-10T12:01:00+00:00'))
            ->complete(
                new DateTimeImmutable('2026-08-10T12:02:00+00:00'),
                $created->id . '.' . str_repeat('b', 32) . '.csv',
                4,
                $checksum,
                1,
                str_repeat('d', 64),
            );
        $download = $service->download($context, $created->id);

        self::assertSame($created, $nested);
        self::assertSame($stream, $download->stream);
        self::assertSame([1, 1, 2, 2], $snapshotDepths);
        self::assertSame(
            ['business.report.export.request', 'business.report.export.download'],
            $auditActions,
        );
        self::assertSame(6, $transactions->calls);
        self::assertSame(2, $transactions->maximumDepth);
        self::assertFalse($transactions->active());
        self::assertIsResource($download->stream);
        fclose($download->stream);
    }

    public function testMalformedArtifactIdentifiersRemainIndistinguishableFromMissingArtifacts(): void
    {
        $transactions = new ExportTransactionProbe();
        $missingId = '019bc210-7a6e-7e81-8000-000000000099';
        $foreignOwner = AuthorizationContext::human(
            ['acme.reports.read', 'business.record.export'],
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb399',
        );
        $foreignArtifact = $this->completedArtifact($foreignOwner);
        $artifacts = $this->createMock(ExportArtifactRepository::class);
        $artifacts->expects(self::exactly(2))
            ->method('find')
            ->willReturnCallback(static function (string $artifactId) use (
                $missingId,
                $foreignArtifact,
            ): ?ExportArtifact {
                if ($artifactId === $missingId) {
                    return null;
                }
                self::assertSame($foreignArtifact->id, $artifactId);

                return $foreignArtifact;
            });
        $service = $this->service($artifacts, $transactions);
        $context = AuthorizationContext::human(['acme.reports.read', 'business.record.export']);

        $malformedStatus = $this->unavailable(
            static fn () => $service->status($context, 'not-a-canonical-uuid'),
        );
        $malformedDownload = $this->unavailable(
            static fn () => $service->download($context, strtoupper($missingId)),
        );
        $missing = $this->unavailable(static fn () => $service->status($context, $missingId));
        $foreign = $this->unavailable(
            static fn () => $service->status($context, $foreignArtifact->id),
        );

        self::assertSame('The export artifact is unavailable.', $malformedStatus->getMessage());
        self::assertSame($malformedStatus->getMessage(), $malformedDownload->getMessage());
        self::assertSame($malformedStatus->getMessage(), $missing->getMessage());
        self::assertSame($malformedStatus->getMessage(), $foreign->getMessage());
        self::assertSame(2, $transactions->calls);
        self::assertSame(2, $transactions->rollbacks);
        self::assertFalse($transactions->active());
    }

    public function testRepositoryIntegrityFailuresRemainOperationalErrors(): void
    {
        $transactions = new ExportTransactionProbe();
        $artifactId = '019bc210-7a6e-7e81-8000-000000000098';
        $failure = new RuntimeException('Export metadata storage integrity failed.');
        $artifacts = $this->createMock(ExportArtifactRepository::class);
        $artifacts->expects(self::once())
            ->method('find')
            ->with($artifactId)
            ->willThrowException($failure);
        $service = $this->service($artifacts, $transactions);
        $context = AuthorizationContext::human(['acme.reports.read', 'business.record.export']);

        try {
            $service->status($context, $artifactId);
            self::fail('Corrupt export metadata must remain an operational failure.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame(1, $transactions->calls);
        self::assertSame(1, $transactions->rollbacks);
        self::assertFalse($transactions->active());
    }

    public function testTransientPolicySnapshotFailuresRemainOperationalErrors(): void
    {
        $transactions = new ExportTransactionProbe();
        $context = AuthorizationContext::human(['acme.reports.read', 'business.record.export']);
        $artifact = $this->completedArtifact($context);
        $artifacts = $this->createMock(ExportArtifactRepository::class);
        $artifacts->expects(self::once())->method('find')->with($artifact->id)->willReturn($artifact);
        $failure = new BusinessRecordTemporarilyUnavailable(
            new RuntimeException('The policy database is temporarily unavailable.'),
        );
        $policies = $this->createMock(ExportPolicySnapshotProvider::class);
        $policies->expects(self::once())->method('snapshot')->willThrowException($failure);
        $service = $this->service($artifacts, $transactions, policies: $policies);

        try {
            $service->status($context, $artifact->id);
            self::fail('An operational policy snapshot failure must remain retryable.');
        } catch (BusinessRecordTemporarilyUnavailable $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame(1, $transactions->calls);
        self::assertSame(1, $transactions->rollbacks);
        self::assertFalse($transactions->active());
    }

    public function testAuditFailureRollsBackAndClosesTheOpenedDownloadStream(): void
    {
        $transactions = new ExportTransactionProbe();
        $context = AuthorizationContext::human(['acme.reports.read', 'business.record.export']);
        $artifact = $this->completedArtifact($context);
        $artifacts = $this->createMock(ExportArtifactRepository::class);
        $artifacts->expects(self::once())->method('find')->with($artifact->id)->willReturn($artifact);
        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, 'test');
        rewind($stream);
        $storage = $this->createMock(ExportArtifactStorage::class);
        $storage->expects(self::once())->method('open')->willReturn($stream);
        $failure = new RuntimeException('The audit event could not be recorded.');
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::once())->method('record')->willThrowException($failure);
        $service = $this->service($artifacts, $transactions, $storage, $audit);

        try {
            $service->download($context, $artifact->id);
            self::fail('An unaudited export download must not succeed.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertFalse(is_resource($stream));
        self::assertSame(2, $transactions->calls);
        self::assertSame(1, $transactions->rollbacks);
        self::assertFalse($transactions->active());
    }

    public function testCommitFailureClosesTheOpenedDownloadStream(): void
    {
        $transactions = new ExportTransactionProbe();
        $failure = new RuntimeException('The export download transaction could not commit.');
        $transactions->outerCommitFailure = $failure;
        $context = AuthorizationContext::human(['acme.reports.read', 'business.record.export']);
        $artifact = $this->completedArtifact($context);
        $artifacts = $this->createMock(ExportArtifactRepository::class);
        $artifacts->expects(self::once())->method('find')->with($artifact->id)->willReturn($artifact);
        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, 'test');
        rewind($stream);
        $storage = $this->createMock(ExportArtifactStorage::class);
        $storage->expects(self::once())->method('open')->willReturn($stream);
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::once())->method('record');
        $service = $this->service($artifacts, $transactions, $storage, $audit);

        try {
            $service->download($context, $artifact->id);
            self::fail('An export download must not survive a failed transaction commit.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertFalse(is_resource($stream));
        self::assertSame(2, $transactions->calls);
        self::assertSame(1, $transactions->rollbacks);
        self::assertFalse($transactions->active());
    }

    private function service(
        ExportArtifactRepository $artifacts,
        TransactionManager $transactions,
        ?ExportArtifactStorage $storage = null,
        ?AuditRecorder $audit = null,
        ?ExportPolicySnapshotProvider $policies = null,
    ): ExportService {
        $authorization = $this->createStub(AuthorizationGateway::class);
        $authorization->method('decide')->willReturn(new AuthorizationDecision(true, 'test.export', 'allowed'));
        if ($policies === null) {
            $policies = $this->createStub(ExportPolicySnapshotProvider::class);
            $policies->method('snapshot')->willReturn(str_repeat('a', 64));
        }
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-10T12:00:00+00:00'));

        return new ExportService(
            new ReportDefinitionRegistry([$this->report()]),
            new class implements ReportScopeResolver {
                public function resolve(
                    ExecutionContext $context,
                    ReportDefinition $report,
                    ?string $assertedOrganization,
                ): ?string {
                    return $assertedOrganization;
                }
            },
            $artifacts,
            $storage ?? $this->createStub(ExportArtifactStorage::class),
            $this->createStub(ExportJobDispatcher::class),
            $policies,
            $authorization,
            $transactions,
            $audit ?? $this->createStub(AuditRecorder::class),
            $clock,
        );
    }

    private function completedArtifact(ExecutionContext $context): ExportArtifact
    {
        $id = '019bc210-7a6e-7e81-8000-000000000097';
        $createdAt = new DateTimeImmutable('2026-08-10T11:00:00+00:00');
        $report = $this->report();
        $artifact = new ExportArtifact(
            $id,
            $report->identifier(),
            $report->version,
            $report->checksum(),
            $context->actorId(),
            $context->site()->identifier(),
            $context->organization()?->identifier(),
            $context->workspace()?->identifier(),
            $context->surface(),
            $context->approvalFingerprint(),
            str_repeat('a', 64),
            [],
            str_repeat('e', 64),
            ExportArtifactStatus::Queued,
            $createdAt,
            $createdAt->modify('+1 day'),
            null,
            null,
            'acme_open_items-20260810-110000.csv',
            null,
            null,
            null,
            null,
            null,
            null,
            1,
        );

        return $artifact
            ->start($createdAt->modify('+1 minute'))
            ->complete(
                $createdAt->modify('+2 minutes'),
                $id . '.' . str_repeat('b', 32) . '.csv',
                4,
                str_repeat('c', 64),
                1,
                str_repeat('d', 64),
            );
    }

    /**
     * @param  callable(): mixed  $operation  Artifact lookup expected to fail without enumeration.
     */
    private function unavailable(callable $operation): ExportArtifactUnavailable
    {
        try {
            $operation();
            self::fail('An unavailable export artifact must not be returned.');
        } catch (ExportArtifactUnavailable $exception) {
            return $exception;
        }
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
}

final class ExportTransactionProbe implements TransactionManager
{
    public int $depth = 0;
    public int $calls = 0;
    public int $maximumDepth = 0;
    public int $rollbacks = 0;
    public ?Throwable $outerCommitFailure = null;

    /**
     * @var    list<array{commit: list<callable(): void>, rollback: list<callable(): void>}>
     */
    private array $frames = [];

    public function transactional(callable $operation): mixed
    {
        ++$this->calls;
        ++$this->depth;
        $this->maximumDepth = max($this->maximumDepth, $this->depth);
        $this->frames[] = ['commit' => [], 'rollback' => []];
        try {
            $result = $operation();
        } catch (Throwable $exception) {
            $frame = array_pop($this->frames);
            --$this->depth;
            if (is_array($frame)) {
                ++$this->rollbacks;
                $this->invoke($frame['rollback']);
            }
            throw $exception;
        }

        $frame = array_pop($this->frames);
        --$this->depth;
        if (!is_array($frame)) {
            throw new RuntimeException('The export transaction test frame was lost.');
        }
        $parent = array_key_last($this->frames);
        if ($parent !== null) {
            array_push($this->frames[$parent]['commit'], ...$frame['commit']);
            array_push($this->frames[$parent]['rollback'], ...$frame['rollback']);
        } elseif ($this->outerCommitFailure !== null) {
            $failure = $this->outerCommitFailure;
            $this->outerCommitFailure = null;
            ++$this->rollbacks;
            $this->invoke($frame['rollback']);
            throw $failure;
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
        return $this->depth > 0;
    }

    /**
     * @param  list<callable(): void>  $operations  Completion callbacks registered in one frame.
     */
    private function invoke(array $operations): void
    {
        foreach ($operations as $operation) {
            $operation();
        }
    }
}
