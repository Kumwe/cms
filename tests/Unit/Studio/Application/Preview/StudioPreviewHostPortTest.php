<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Preview;

use DateTimeImmutable;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Application\Preview\StudioPreviewActivityRecorder;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBindingSource;
use Kumwe\App\Studio\Application\Preview\StudioPreviewDraftSource;
use Kumwe\App\Studio\Application\Preview\StudioPreviewGrantRepository;
use Kumwe\App\Studio\Application\Preview\StudioPreviewHostPort;
use Kumwe\App\Studio\Application\Preview\StudioPreviewRefused;
use Kumwe\App\Studio\Application\Preview\StudioPreviewRenderer;
use Kumwe\App\Studio\Application\Preview\StudioPreviewSequenceClaim;
use Kumwe\App\Studio\Application\Preview\StudioPreviewSequenceRepository;
use Kumwe\App\Studio\Application\Preview\StudioPreviewSequenceWaiter;
use Kumwe\App\Studio\Application\Preview\StudioPreviewTransportGuard;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewGrant;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewRenderedDocument;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewRenderRequest;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewTransport;
use Kumwe\App\Tests\Support\StudioProducerRequest;
use Kumwe\Producer\Error\HostRefusal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use ReflectionClass;
use RuntimeException;
use stdClass;

/**
 * Proves the preview port refuses malformed dispatch evidence before any preview work can start.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioPreviewHostPort::class)]
#[CoversClass(StudioPreviewTransportGuard::class)]
final class StudioPreviewHostPortTest extends TestCase
{
    /**
     * Fixed deterministic instant every port composition observes.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string NOW = '2026-08-24T12:00:00+00:00';

    /**
     * Prove a dispatch without browser transport evidence is refused before the guard or ledger runs.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMissingPreviewTransportIsRefusedBeforeAnyPreviewWork(): void
    {
        $request = StudioProducerRequest::authorized(
            'studio.operation/preview.render',
            (object) ['payload' => new stdClass()],
        );
        $port = $this->port();

        self::assertRefused('studio.preview/invalid-transport', static fn (): mixed => $port
            ->forRequest($request->authority)
            ->render($request->arguments(), $request->context()));
    }

    /**
     * Prove mutation-only context members are refused on both read-only preview operations.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMutationContextIsRefusedOnReadOnlyPreviewOperations(): void
    {
        $port = $this->port();
        $render = StudioProducerRequest::authorized(
            'studio.operation/preview.render',
            (object) ['payload' => new stdClass()],
            expectedRevision: '3',
        );
        self::assertRefused('studio.host/invalid-context', static fn (): mixed => $port
            ->forRequest($render->authority)
            ->render($render->arguments(), $render->context()));

        $cancel = StudioProducerRequest::authorized(
            'studio.operation/preview.cancel',
            (object) ['draftDigest' => str_repeat('a', 64)],
            idempotencyKey: 'keys/replayed-cancel',
        );
        self::assertRefused('studio.host/invalid-context', static fn (): mixed => $port
            ->forRequest($cancel->authority)
            ->cancel($cancel->arguments(), $cancel->context()));
    }

    /**
     * Prove a failing security trail replaces the refusal it could not record, after acceptance passed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnActivityFailureWhileRecordingARefusalBecomesTheRefusal(): void
    {
        $guard = self::guard();
        $activity = self::recorder('refused');
        $request = StudioProducerRequest::authorized(
            'studio.operation/preview.render',
            (object) ['bogus' => true],
            previewTransport: static fn (StudioHostSessionSnapshot $snapshot): StudioPreviewTransport =>
                self::transport($guard, $snapshot),
        );
        $port = $this->port(guard: $guard, activity: $activity);

        self::assertRefused(
            'studio.preview/activity-record-unavailable',
            static fn (): mixed => $port
                ->forRequest($request->authority)
                ->render($request->arguments(), $request->context()),
            'unavailable',
        );
        self::assertSame(['accepted'], $activity->outcomes);
    }

    /**
     * Prove an absent or expired grant reads as an unavailable stylesheet without failing the request.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnAbsentGrantReadsAsAnUnavailableStylesheet(): void
    {
        $guard = self::guard();
        $activity = self::recorder();
        $request = StudioProducerRequest::authorized(
            'studio.operation/preview.render',
            (object) ['payload' => new stdClass()],
        );
        $port = $this->port(guard: $guard, activity: $activity);

        self::assertNull($port->stylesheet(
            $request->authority->context(),
            $request->snapshot,
            'requests/producer-port-test',
            self::transport($guard, $request->snapshot),
        ));
        self::assertSame(['accepted', 'refused'], $activity->outcomes);
        self::assertSame('studio.preview/stylesheet-unavailable', $activity->reasons[1]);
    }

    /**
     * Prove a claimed live grant serves its exact combined stylesheet bytes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAClaimedLiveGrantServesItsExactStylesheet(): void
    {
        $guard = self::guard();
        $activity = self::recorder();
        $request = StudioProducerRequest::authorized(
            'studio.operation/preview.render',
            (object) ['payload' => new stdClass()],
        );
        $transport = self::transport($guard, $request->snapshot);
        $grants = self::createStub(StudioPreviewGrantRepository::class);
        $grants->method('claimed')->willReturn(self::grant($transport));
        $port = $this->port(guard: $guard, activity: $activity, grants: $grants);

        self::assertSame('[data-studio-block]{display:block}', $port->stylesheet(
            $request->authority->context(),
            $request->snapshot,
            'requests/producer-port-test',
            $transport,
        ));
        self::assertSame(['accepted', 'completed'], $activity->outcomes);
        self::assertSame('studio.preview/stylesheet-completed', $activity->reasons[1]);
    }

    /**
     * Prove a foreign-origin stylesheet read is recorded and rethrown as the exact identity refusal.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAForeignOriginStylesheetReadIsRecordedAndRefused(): void
    {
        $guard = self::guard();
        $activity = self::recorder();
        $request = StudioProducerRequest::authorized(
            'studio.operation/preview.render',
            (object) ['payload' => new stdClass()],
        );
        $port = $this->port(guard: $guard, activity: $activity);

        try {
            $port->stylesheet(
                $request->authority->context(),
                $request->snapshot,
                'requests/producer-port-test',
                self::transport($guard, $request->snapshot, origin: 'https://foreign.test'),
            );
            self::fail('A foreign-origin stylesheet read must be refused.');
        } catch (StudioPreviewRefused $refused) {
            self::assertSame('forbidden', $refused->category);
            self::assertSame('studio.preview/foreign-origin', $refused->diagnosticCode);
        }
        self::assertSame(['refused'], $activity->outcomes);
        self::assertSame('studio.preview/foreign-origin', $activity->reasons[0]);
    }

    /**
     * Compose the preview port around deterministic doubles without any persistence dependency.
     *
     * @param   StudioPreviewTransportGuard|null   $guard     Transport fence, uninitialized when unused.
     * @param   StudioPreviewActivityRecorder|null $activity  Activity trail double.
     * @param   StudioPreviewGrantRepository|null  $grants    Grant ledger double.
     *
     * @return  StudioPreviewHostPort  Unbound preview port ready for one request scope.
     *
     * @since   2.0.0
     */
    private function port(
        ?StudioPreviewTransportGuard $guard = null,
        ?StudioPreviewActivityRecorder $activity = null,
        ?StudioPreviewGrantRepository $grants = null,
    ): StudioPreviewHostPort {
        $clock = new class implements ClockInterface {
            /**
             * Report the fixed instant used by every grant-expiry decision in this test.
             *
             * @return  DateTimeImmutable  Deterministic trusted instant.
             *
             * @since   2.0.0
             */
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-08-24T12:00:00+00:00');
            }
        };

        return new StudioPreviewHostPort(
            self::createStub(StudioPreviewDraftSource::class),
            self::createStub(StudioPreviewBindingSource::class),
            self::createStub(StudioPreviewRenderer::class),
            $grants ?? self::createStub(StudioPreviewGrantRepository::class),
            $guard ?? (new ReflectionClass(StudioPreviewTransportGuard::class))->newInstanceWithoutConstructor(),
            $activity ?? self::recorder(),
            $clock,
        );
    }

    /**
     * Build the production transport guard over an always-accepting portable sequence ledger.
     *
     * @return  StudioPreviewTransportGuard  Same-origin guard for the deterministic test origin.
     *
     * @since   2.0.0
     */
    private static function guard(): StudioPreviewTransportGuard
    {
        $sequences = new class implements StudioPreviewSequenceRepository {
            /**
             * Accept every sequence claim so identity refusals stay the only guard outcome under test.
             *
             * @param   string  $resourceContextKey  Opaque trusted host context.
             * @param   string  $lane                Closed port or document direction.
             * @param   int     $sequence            Candidate transport sequence.
             *
             * @return  StudioPreviewSequenceClaim  Always the accepted claim.
             *
             * @since   2.0.0
             */
            public function advance(
                string $resourceContextKey,
                string $lane,
                int $sequence,
            ): StudioPreviewSequenceClaim {
                unset($resourceContextKey, $lane, $sequence);

                return StudioPreviewSequenceClaim::Accepted;
            }
        };
        $waiter = new class implements StudioPreviewSequenceWaiter {
            /**
             * Yield instantly; the accepting ledger never leaves a predecessor pending.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function pause(): void
            {
            }
        };

        return new StudioPreviewTransportGuard('https://kumwe.test', $sequences, $waiter);
    }

    /**
     * Build one recording activity trail that can fail deterministically on a chosen outcome.
     *
     * @param   string|null  $failOn  Outcome whose recording raises a sink failure, or null for none.
     *
     * @return  StudioPreviewActivityRecorder&object{outcomes: list<string>, reasons: list<string>}
     *          Recording trail double.
     *
     * @since   2.0.0
     */
    private static function recorder(?string $failOn = null): StudioPreviewActivityRecorder
    {
        return new class ($failOn) implements StudioPreviewActivityRecorder {
            /**
             * Outcomes recorded so far, in call order.
             *
             * @var    list<string>
             * @since  2.0.0
             */
            public array $outcomes = [];

            /**
             * Reasons recorded so far, in call order.
             *
             * @var    list<string>
             * @since  2.0.0
             */
            public array $reasons = [];

            /**
             * Retain the outcome whose recording must fail.
             *
             * @param   string|null  $failOn  Outcome whose recording raises a sink failure.
             *
             * @since   2.0.0
             */
            public function __construct(private readonly ?string $failOn)
            {
            }

            /**
             * Record one bounded activity row or fail exactly like an unavailable sink.
             *
             * @param   ExecutionContext           $context   Caller execution context.
             * @param   StudioHostSessionSnapshot  $snapshot  Authorized host session snapshot.
             * @param   string                     $action    Recorded preview action name.
             * @param   string                     $outcome   Recorded action outcome.
             * @param   string                     $reason    Recorded outcome reason.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function record(
                ExecutionContext $context,
                StudioHostSessionSnapshot $snapshot,
                string $action,
                string $outcome,
                string $reason,
            ): void {
                unset($context, $snapshot, $action);
                if ($outcome === $this->failOn) {
                    throw new RuntimeException('The Studio preview activity sink is unavailable.');
                }
                $this->outcomes[] = $outcome;
                $this->reasons[] = $reason;
            }
        };
    }

    /**
     * Build browser transport evidence bound to the guard's channel and source for one session.
     *
     * @param   StudioPreviewTransportGuard  $guard     Production transport guard.
     * @param   StudioHostSessionSnapshot    $snapshot  Opened trusted session snapshot.
     * @param   int                          $sequence  Monotonic transport sequence.
     * @param   string                       $origin    Browser-supplied absolute origin.
     *
     * @return  StudioPreviewTransport  Transport evidence for one preview call.
     *
     * @since   2.0.0
     */
    private static function transport(
        StudioPreviewTransportGuard $guard,
        StudioHostSessionSnapshot $snapshot,
        int $sequence = 0,
        string $origin = 'https://kumwe.test',
    ): StudioPreviewTransport {
        return new StudioPreviewTransport(
            $origin,
            $guard->channelId($snapshot->session),
            $guard->sourceId($snapshot->session),
            $sequence,
        );
    }

    /**
     * Build one complete claimed grant whose rendered document carries an exact stylesheet.
     *
     * @param   StudioPreviewTransport  $transport  Accepted transport evidence the grant is bound to.
     *
     * @return  StudioPreviewGrant  Live single-use grant fixture.
     *
     * @since   2.0.0
     */
    private static function grant(StudioPreviewTransport $transport): StudioPreviewGrant
    {
        return new StudioPreviewGrant(
            'contexts/producer-port-test',
            'actor-producer-port-test',
            'default',
            null,
            null,
            hash('sha256', 'producer-port-test-session'),
            'generation-producer-port-test',
            $transport->origin,
            $transport->channelId,
            $transport->sourceId,
            new StudioPreviewRenderRequest(
                'content-producer-port-test',
                str_repeat('a', 64),
                'draft-r1',
                'requests/producer-port-test',
                'expanded',
            ),
            new StudioPreviewRenderedDocument(
                '<!doctype html><title>Preview</title>',
                [],
                [],
                [],
                '[data-studio-block]{display:block}',
            ),
            new DateTimeImmutable(self::NOW)->add(new \DateInterval('PT60S')),
        );
    }

    /**
     * Assert one direct Producer refusal retains its stable App diagnostic and category.
     *
     * @param   string    $code      Expected delivery-safe diagnostic code.
     * @param   callable  $callback  Port invocation expected to fail.
     * @param   string    $category  Expected Producer refusal category.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertRefused(
        string $code,
        callable $callback,
        string $category = 'invalid-request',
    ): void {
        try {
            $callback();
            self::fail('The malformed Studio preview request unexpectedly succeeded.');
        } catch (HostRefusal $refusal) {
            self::assertSame($category, $refusal->error()->category());
            self::assertSame($code, $refusal->error()->diagnostics()[0]->code());
        }
    }
}
