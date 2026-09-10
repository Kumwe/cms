<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Preview;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Infrastructure\Persistence\Migration\StudioPreviewGrantMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Studio\Application\Composition\StudioCompositionThemeMismatch;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Application\Preview\StudioPreviewActivityRecorder;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBindingSource;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBindingValues;
use Kumwe\App\Studio\Application\Preview\StudioPreviewDraftSource;
use Kumwe\App\Studio\Application\Preview\StudioPreviewGrantRepository;
use Kumwe\App\Studio\Application\Preview\StudioPreviewHostPort;
use Kumwe\App\Studio\Application\Preview\StudioPreviewRenderer;
use Kumwe\App\Studio\Application\Preview\StudioPreviewTransportGuard;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewDraft;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewIdentity;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewRenderedDocument;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewRenderRequest;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewTransport;
use Kumwe\App\Studio\Infrastructure\Persistence\DoctrineStudioPreviewRepository;
use Kumwe\App\Studio\Infrastructure\Transport\NativeStudioPreviewSequenceWaiter;
use Kumwe\App\Tests\Support\StudioProducerRequest;
use Kumwe\Producer\Error\HostRefusal;
use Kumwe\Producer\Wire\HostResult;
use Kumwe\Producer\Wire\OperationRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use ReflectionClass;
use RuntimeException;
use stdClass;

/**
 * Proves the App preview implementation consumes Producer's direct port and pinned vector contract.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioPreviewHostPort::class)]
#[CoversClass(StudioPreviewRenderRequest::class)]
final class StudioPreviewProducerPortTest extends TestCase
{
    /**
     * Render and cancel accept only the canonical Producer wrappers under same-origin transport evidence.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testDirectRenderAndCancelUseCanonicalProducerArguments(): void
    {
        $vector = self::vector();
        self::assertInstanceOf(stdClass::class, $vector->draft);
        self::assertInstanceOf(stdClass::class, $vector->render);
        $draft = new StudioPreviewDraft('default', $vector->draft);
        [$port, $guard] = self::runtime($draft);
        $render = self::request(
            $guard,
            'studio.operation/preview.render',
            (object) ['payload' => $vector->render],
            0,
        );

        $result = $port->forRequest($render->authority)->render($render->arguments(), $render->context());

        self::assertInstanceOf(HostResult::class, $result);
        self::assertInstanceOf(stdClass::class, $result->value);
        self::assertSame($vector->render->draftDigest, $result->value->draftDigest);
        self::assertSame($vector->render->requestId, $result->value->requestId);
        self::assertIsArray($result->value->markers);
        self::assertInstanceOf(stdClass::class, $result->value->markerMap);

        [$cancelPort, $cancelGuard] = self::runtime($draft);
        $cancel = self::request(
            $cancelGuard,
            'studio.operation/preview.cancel',
            (object) ['draftDigest' => $vector->render->draftDigest],
            0,
        );
        $cancelled = $cancelPort->forRequest($cancel->authority)->cancel(
            $cancel->arguments(),
            $cancel->context(),
        );
        self::assertNull($cancelled->value);

        $invalid = self::request(
            $cancelGuard,
            'studio.operation/preview.cancel',
            (object) ['draftDigest' => 'not-a-digest'],
            1,
        );
        self::assertRefused('studio.preview/invalid-cancel-payload', static fn () => $cancelPort
            ->forRequest($invalid->authority)
            ->cancel($invalid->arguments(), $invalid->context()));
    }

    /**
     * A typed live-theme mismatch is a canonical Producer conflict and abandons the pending grant.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testThemeMismatchMapsToProducerConflictAndAbandonsTheGrant(): void
    {
        $vector = self::vector();
        self::assertInstanceOf(stdClass::class, $vector->draft);
        self::assertInstanceOf(stdClass::class, $vector->render);
        $draft = new StudioPreviewDraft('default', $vector->draft);
        $renderer = new class implements StudioPreviewRenderer {
            /**
             * {@inheritDoc}
             *
             * @param   StudioHostSessionSnapshot   $snapshot  Authorized host session snapshot.
             * @param   StudioPreviewDraft          $draft     Draft selected for rendering.
             * @param   StudioPreviewRenderRequest  $request   Validated preview render request.
             * @param   StudioPreviewBindingValues  $values    Resolved preview binding values.
             *
             * @return  StudioPreviewRenderedDocument  Never returned: this double always raises a theme mismatch.
             *
             * @since   2.0.0
             */
            public function render(
                StudioHostSessionSnapshot $snapshot,
                StudioPreviewDraft $draft,
                StudioPreviewRenderRequest $request,
                StudioPreviewBindingValues $values,
            ): StudioPreviewRenderedDocument {
                unset($snapshot, $draft, $request, $values);
                throw new StudioCompositionThemeMismatch();
            }
        };
        [$port, $guard, $database] = self::runtime($draft, $renderer);
        $request = self::request(
            $guard,
            'studio.operation/preview.render',
            (object) ['payload' => $vector->render],
            0,
        );

        self::assertRefused('studio.preview/theme-lock-mismatch', static fn () => $port
            ->forRequest($request->authority)
            ->render($request->arguments(), $request->context()), 'conflict');
        self::assertSame('failed', $database->fetchOne(
            'SELECT state FROM kumwe_studio_preview_grants WHERE resource_context_key = ? AND request_id = ?',
            [$request->snapshot->session->resourceContextKey, $vector->render->requestId],
        ));
    }

    /**
     * Assemble the production preview port around real grant persistence and deterministic edges.
     *
     * @param   StudioPreviewDraft             $draft     Immutable preview draft.
     * @param   StudioPreviewRenderer|null     $renderer  Optional renderer refusal behavior.
     * @param   StudioPreviewGrantRepository|null $grants Optional durable grant behavior.
     *
     * @return  array{StudioPreviewHostPort, StudioPreviewTransportGuard, Connection}
     *
     * @since  2.0.0
     */
    private static function runtime(
        StudioPreviewDraft $draft,
        ?StudioPreviewRenderer $renderer = null,
        ?StudioPreviewGrantRepository $grants = null,
    ): array {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tables = new TableNames($database, 'kumwe_');
        (new StudioPreviewGrantMigration($tables))->up($database);
        $repository = new DoctrineStudioPreviewRepository($database, $tables);
        $guard = new StudioPreviewTransportGuard(
            'https://kumwe.test',
            $repository,
            new NativeStudioPreviewSequenceWaiter(),
        );
        $source = new class ($draft) implements StudioPreviewDraftSource {
            /**
             * Retain the deterministic draft.
             *
             * @param   StudioPreviewDraft  $draft  Sole draft this source ever serves.
             *
             * @since   2.0.0
             */
            public function __construct(private readonly StudioPreviewDraft $draft)
            {
            }

            /**
             * {@inheritDoc}
             *
             * @param   StudioHostSessionSnapshot   $snapshot  Authorized host session snapshot.
             * @param   StudioPreviewRenderRequest  $request   Validated preview render request.
             *
             * @return  ?StudioPreviewDraft  The retained draft when the artifact identifier matches, null otherwise.
             *
             * @since   2.0.0
             */
            public function find(
                StudioHostSessionSnapshot $snapshot,
                StudioPreviewRenderRequest $request,
            ): ?StudioPreviewDraft {
                unset($snapshot);
                return hash_equals($request->artifactId, $this->draft->artifactId()) ? $this->draft : null;
            }
        };
        $bindings = new class implements StudioPreviewBindingSource {
            /**
             * {@inheritDoc}
             *
             * @param   ExecutionContext           $context   Caller execution context.
             * @param   StudioHostSessionSnapshot  $snapshot  Authorized host session snapshot.
             * @param   StudioPreviewDraft         $draft     Draft the bindings would feed.
             *
             * @return  StudioPreviewBindingValues  Empty binding values regardless of input.
             *
             * @since   2.0.0
             */
            public function resolve(
                ExecutionContext $context,
                StudioHostSessionSnapshot $snapshot,
                StudioPreviewDraft $draft,
            ): StudioPreviewBindingValues {
                unset($context, $snapshot, $draft);
                return new StudioPreviewBindingValues(new stdClass(), new stdClass());
            }
        };
        $renderer ??= new class implements StudioPreviewRenderer {
            /**
             * {@inheritDoc}
             *
             * @param   StudioHostSessionSnapshot   $snapshot  Authorized host session snapshot.
             * @param   StudioPreviewDraft          $draft     Draft selected for rendering.
             * @param   StudioPreviewRenderRequest  $request   Validated preview render request.
             * @param   StudioPreviewBindingValues  $values    Resolved preview binding values.
             *
             * @return  StudioPreviewRenderedDocument  Minimal document carrying the draft's identity markers.
             *
             * @since   2.0.0
             */
            public function render(
                StudioHostSessionSnapshot $snapshot,
                StudioPreviewDraft $draft,
                StudioPreviewRenderRequest $request,
                StudioPreviewBindingValues $values,
            ): StudioPreviewRenderedDocument {
                unset($snapshot, $request, $values);
                $identity = StudioPreviewIdentity::forDraft($draft->document());

                return new StudioPreviewRenderedDocument(
                    '<!doctype html><title>Preview</title>',
                    $identity['markers'],
                    $identity['markerMap'],
                );
            }
        };
        $activity = new class implements StudioPreviewActivityRecorder {
            /**
             * {@inheritDoc}
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
                unset($context, $snapshot, $action, $outcome, $reason);
            }
        };
        $clock = new class implements ClockInterface {
            /**
             * {@inheritDoc}
             *
             * @return  DateTimeImmutable  The fixed instant this deterministic clock always reports.
             *
             * @since   2.0.0
             */
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-08-24T12:00:00+00:00');
            }
        };

        return [
            new StudioPreviewHostPort(
                $source,
                $bindings,
                $renderer,
                $grants ?? $repository,
                $guard,
                $activity,
                $clock,
            ),
            $guard,
            $database,
        ];
    }

    /**
     * Open one direct Producer preview request with same-origin transport evidence.
     *
     * @param   StudioPreviewTransportGuard  $guard       Production preview transport guard.
     * @param   string                       $capability  Closed Producer operation capability.
     * @param   mixed                        $arguments   Candidate operation arguments.
     * @param   int                          $sequence    Monotonic transport sequence.
     *
     * @return  StudioProducerRequest  Authorized direct request scope.
     *
     * @since  2.0.0
     */
    private static function request(
        StudioPreviewTransportGuard $guard,
        string $capability,
        mixed $arguments,
        int $sequence,
    ): StudioProducerRequest {
        return StudioProducerRequest::authorized(
            $capability,
            $arguments,
            previewTransport: static fn (StudioHostSessionSnapshot $snapshot): StudioPreviewTransport =>
                new StudioPreviewTransport(
                    'https://kumwe.test',
                    $guard->channelId($snapshot->session),
                    $guard->sourceId($snapshot->session),
                    $sequence,
                ),
        );
    }

    /**
     * Assert one direct Producer refusal retains its stable App diagnostic.
     *
     * @param   string    $code      Expected delivery-safe diagnostic code.
     * @param   callable  $callback  Port invocation expected to fail.
     * @param   string    $category  Expected Producer refusal category.
     *
     * @return  void
     *
     * @since  2.0.0
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

    /**
     * Load the exact canonical-preorder preview vector from Producer's package.
     *
     * @return  stdClass  Decoded vector.
     *
     * @since  2.0.0
     */
    private static function vector(): stdClass
    {
        $producerRoot = dirname((string) (new ReflectionClass(OperationRegistry::class))->getFileName(), 3);
        $path = $producerRoot . '/resources/studio-contract/testkit/vectors/preview/canonical-preorder.json';
        $vector = json_decode((string) file_get_contents($path), false, 64, JSON_THROW_ON_ERROR);
        if (!$vector instanceof stdClass) {
            throw new RuntimeException('The Producer preview vector is invalid.');
        }

        return $vector;
    }
}
