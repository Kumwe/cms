<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessRecord\Application;

use DateTimeImmutable;
use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordPostingPeriodConflict;
use Kumwe\App\BusinessRecord\Application\PostingPeriodRepository;
use Kumwe\App\BusinessRecord\Application\PostingPeriodService;
use Kumwe\App\BusinessRecord\Domain\PostingPeriod;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\InMemoryPostingPeriodRepository;
use Kumwe\App\Tests\Support\ImmediateTransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Pins the administrative rules of the posting-period lock: who may close, and what a close means.
 *
 * The lock's refusal of mutations is proven beside the record service; what is proven here is the
 * administrative half the acceptance names — closing is capability-gated and audited, a declared
 * range is immutable behind its key, and re-opening is a state move rather than a deletion.
 *
 * @since  2.0.0
 */
#[CoversClass(PostingPeriodService::class)]
#[CoversClass(BusinessRecordPostingPeriodConflict::class)]
final class PostingPeriodServiceTest extends TestCase
{
    /**
     * Closing a new key declares the range closed, stores it, and audits the act.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testClosingDeclaresTheRangeAndAuditsTheAct(): void
    {
        $repository = $this->repository();
        $events = [];
        $service = $this->service($repository, $events);

        $closed = $service->close(
            $this->actor(),
            '2026-08',
            new DateTimeImmutable('2026-08-01T00:00:00Z'),
            new DateTimeImmutable('2026-09-01T00:00:00Z'),
        );

        self::assertTrue($closed->isClosed());
        self::assertSame('default', $closed->siteIdentifier);
        self::assertSame(AuthorizationContext::SUBJECT, $closed->closedBy);
        $stored = $repository->find('default', null, '2026-08');
        self::assertInstanceOf(PostingPeriod::class, $stored);
        self::assertTrue($stored->isClosed());
        self::assertCount(1, $events);
        self::assertSame('business.period.close', $events[0]->action());
        self::assertSame('business_posting_period', $events[0]->subjectType());
        self::assertSame('default::2026-08', $events[0]->subjectId());
    }

    /**
     * Re-opening a closed period is audited, and closing it again works over the same range.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReopeningAndReclosingAreStateMovesOverTheDeclaredRange(): void
    {
        $repository = $this->repository();
        $events = [];
        $service = $this->service($repository, $events);
        $actor = $this->actor();
        $starts = new DateTimeImmutable('2026-08-01T00:00:00Z');
        $ends = new DateTimeImmutable('2026-09-01T00:00:00Z');
        $service->close($actor, '2026-08', $starts, $ends);

        $reopened = $service->reopen($actor, '2026-08');
        self::assertFalse($reopened->isClosed());
        self::assertSame('business.period.reopen', $events[1]->action());

        $closedAgain = $service->close($actor, '2026-08', $starts, $ends);
        self::assertTrue($closedAgain->isClosed());
        self::assertCount(3, $events);
        self::assertSame([$closedAgain->key], array_map(
            static fn (PostingPeriod $period): string => $period->key,
            $service->list($actor),
        ));
    }

    /**
     * The declaration behind a key is immutable: contradictions are refused under one stable code.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testContradictingTheStoredDeclarationIsRefused(): void
    {
        $repository = $this->repository();
        $events = [];
        $service = $this->service($repository, $events);
        $actor = $this->actor();
        $starts = new DateTimeImmutable('2026-08-01T00:00:00Z');
        $ends = new DateTimeImmutable('2026-09-01T00:00:00Z');
        $service->close($actor, '2026-08', $starts, $ends);

        $cases = [
            'closing a closed period' => static fn (): PostingPeriod =>
                $service->close($actor, '2026-08', $starts, $ends),
            're-declaring the range' => static fn (): PostingPeriod =>
                $service->close($actor, '2026-08', $starts, new DateTimeImmutable('2026-10-01T00:00:00Z')),
            're-opening an undeclared key' => static fn (): PostingPeriod =>
                $service->reopen($actor, '2026-09'),
        ];
        foreach ($cases as $name => $attempt) {
            try {
                $attempt();
                self::fail(sprintf('The service must refuse %s.', $name));
            } catch (BusinessRecordPostingPeriodConflict $refused) {
                self::assertSame('business_record.posting_period_conflict', $refused->stableCode());
            }
        }
        self::assertCount(1, $events, 'A refused command must record no audit event.');
    }

    /**
     * Every command is capability-gated: an actor without the grant changes and learns nothing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testActorsWithoutTheCapabilityAreRefused(): void
    {
        $repository = $this->repository();
        $events = [];
        $service = $this->service($repository, $events);
        $reader = AuthorizationContext::human([PostingPeriodService::READ]);

        try {
            $service->close(
                $reader,
                '2026-08',
                new DateTimeImmutable('2026-08-01T00:00:00Z'),
                new DateTimeImmutable('2026-09-01T00:00:00Z'),
            );
            self::fail('Closing must demand the manage capability.');
        } catch (AuthorizationDenied) {
            self::assertSame([], $repository->listFor('default', null));
        }
        try {
            $service->list(AuthorizationContext::human([PostingPeriodService::MANAGE]));
            self::fail('Listing must demand the read capability.');
        } catch (AuthorizationDenied) {
            self::assertCount(0, $events);
        }
    }

    /**
     * Mint the managing-and-reading test actor.
     *
     * @return  ExecutionContext  Human context holding both posting-period capabilities.
     *
     * @since   2.0.0
     */
    private function actor(): ExecutionContext
    {
        return AuthorizationContext::human([PostingPeriodService::MANAGE, PostingPeriodService::READ]);
    }

    /**
     * Build the service over the in-memory store, the real gateway, and a capturing audit sink.
     *
     * @param   PostingPeriodRepository  $repository  Store the service writes through.
     * @param   list<AuditEvent>         $events      Sink capturing every recorded event, by reference.
     *
     * @return  PostingPeriodService  The service under test.
     *
     * @since   2.0.0
     */
    private function service(PostingPeriodRepository $repository, array &$events): PostingPeriodService
    {
        $recorder = new class ($events) implements AuditRecorder {
            /**
             * Capture events into the test's own list.
             *
             * @param  list<AuditEvent>  $events  Sink held by reference.
             *
             * @since  2.0.0
             */
            public function __construct(private array &$events)
            {
            }

            /**
             * Append one event to the captured list.
             *
             * @param   AuditEvent  $event  Event the service recorded.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function record(AuditEvent $event): void
            {
                $this->events[] = $event;
            }
        };
        $clock = new class implements ClockInterface {
            /**
             * Answer a fixed instant so bookkeeping assertions are exact.
             *
             * @return  DateTimeImmutable  Always 2026-09-05T08:00:00Z.
             *
             * @since   2.0.0
             */
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-09-05T08:00:00Z');
            }
        };

        return new PostingPeriodService(
            $repository,
            AuthorizationContext::gateway(),
            new ImmediateTransactionManager(),
            $recorder,
            $clock,
        );
    }

    /**
     * Build the in-memory posting-period store the service writes through.
     *
     * @return  InMemoryPostingPeriodRepository  Store holding declarations for the test's lifetime.
     *
     * @since   2.0.0
     */
    private function repository(): InMemoryPostingPeriodRepository
    {
        return new InMemoryPostingPeriodRepository();
    }
}
