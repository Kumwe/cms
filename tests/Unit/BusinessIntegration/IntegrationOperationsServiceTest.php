<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessIntegration;

use DateTimeImmutable;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\BusinessIntegration\Application\EventContractRegistry;
use Kumwe\App\BusinessIntegration\Application\InboxStore;
use Kumwe\App\BusinessIntegration\Application\IntegrationOperationsService;
use Kumwe\App\BusinessIntegration\Application\OutboxStore;
use Kumwe\App\BusinessIntegration\Application\ProcessManagerService;
use Kumwe\App\BusinessIntegration\Application\ProcessManagerStore;
use Kumwe\App\BusinessReporting\Application\ProjectionRebuildResult;
use Kumwe\App\BusinessReporting\Application\ProjectionRuntime;
use Kumwe\App\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(IntegrationOperationsService::class)]
#[UsesClass(AuditEvent::class)]
final class IntegrationOperationsServiceTest extends TestCase
{
    private const EVENT_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bb501';

    public function testReplayRequiresAuthorityAndCommitsAuditWithMutation(): void
    {
        $outbox = $this->createMock(OutboxStore::class);
        $outbox->expects(self::once())->method('replay')->with(
            self::EVENT_ID,
            AuthorizationContext::SUBJECT,
            self::isInstanceOf(DateTimeImmutable::class),
        );
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::once())->method('record')->with(self::callback(
            static fn (AuditEvent $event): bool => $event->action() === 'integration.event.replay'
                && $event->subjectType() === 'integration_event'
                && $event->subjectId() === self::EVENT_ID
                && $event->actorId() === AuthorizationContext::SUBJECT,
        ));

        $result = $this->service($outbox, $audit)->replay(
            AuthorizationContext::human(['automation.manage']),
            self::EVENT_ID,
        );

        self::assertSame(['event_id' => self::EVENT_ID, 'status' => 'pending'], $result);
    }

    public function testListingWithoutAutomationAuthorityFailsBeforeStoreAccess(): void
    {
        $outbox = $this->createMock(OutboxStore::class);
        $outbox->expects(self::never())->method('recent');

        $this->expectException(\Kumwe\App\Application\Authorization\AuthorizationDenied::class);
        $this->service($outbox)->outbox(AuthorizationContext::human([]));
    }

    public function testPurgeIsBoundedAndAuditsExactRemovalCount(): void
    {
        $outbox = $this->createMock(OutboxStore::class);
        $outbox->expects(self::once())->method('purgeExpired')->with(
            self::isInstanceOf(DateTimeImmutable::class),
            25,
        )->willReturn(7);
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::once())->method('record')->with(self::callback(
            static fn (AuditEvent $event): bool => $event->action() === 'integration.retention.purge'
                && $event->metadata() === ['limit' => 25, 'purged' => 7],
        ));

        $result = $this->service($outbox, $audit)->purge(
            AuthorizationContext::human(['automation.manage']),
            25,
        );

        self::assertSame(['purged' => 7, 'limit' => 25], $result);
    }

    public function testProjectionRebuildRequiresAuthorityAndAuditsActivatedChecksums(): void
    {
        $projection = $this->createMock(ProjectionRuntime::class);
        $projection->expects(self::once())->method('rebuild')->with('acme.activity')->willReturn(
            new ProjectionRebuildResult(12, 4, str_repeat('a', 64), str_repeat('b', 64)),
        );
        $projection->expects(self::once())->method('inventory')->willReturn([[
            'projection_id' => 'acme.activity',
            'active_generation' => [
                'generation_id' => self::EVENT_ID,
                'last_sequence' => 12,
                'source_checksum' => str_repeat('a', 64),
                'projection_checksum' => str_repeat('b', 64),
            ],
        ]]);
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::once())->method('record')->with(self::callback(
            static fn (AuditEvent $event): bool => $event->action() === 'integration.projection.rebuild'
                && $event->subjectType() === 'business_projection'
                && $event->subjectId() === 'acme.activity'
                && $event->metadata()['projection_checksum'] === str_repeat('b', 64),
        ));

        $result = $this->service(
            $this->createStub(OutboxStore::class),
            $audit,
            $projection,
        )->rebuildProjection(AuthorizationContext::human(['automation.manage']), 'acme.activity');

        self::assertSame(self::EVENT_ID, $result['generation_id']);
        self::assertSame(12, $result['last_sequence']);
        self::assertSame(str_repeat('b', 64), $result['projection_checksum']);
    }

    private function service(
        OutboxStore $outbox,
        ?AuditRecorder $audit = null,
        ?ProjectionRuntime $projections = null,
    ): IntegrationOperationsService {
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-10T12:00:00+00:00'));
        $processes = $this->createStub(ProcessManagerStore::class);

        return new IntegrationOperationsService(
            $outbox,
            $this->createStub(InboxStore::class),
            $processes,
            new ProcessManagerService($processes, new EventContractRegistry([], []), $clock),
            AuthorizationContext::gateway(),
            $transactions,
            $audit ?? $this->createStub(AuditRecorder::class),
            $clock,
            $projections,
        );
    }
}
