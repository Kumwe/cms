<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Host;

use DateTimeImmutable;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Application\Persistence\TransactionState;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Application\Host\StudioHostSessionRepository;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Application\Host\StudioMutationReplayRace;
use Kumwe\App\Studio\Application\Host\StudioMutationReplayRepository;
use Kumwe\App\Studio\Application\Host\StudioProducerError;
use Kumwe\App\Studio\Application\Host\StudioProducerMutationBoundary;
use Kumwe\App\Studio\Application\Host\StudioProducerRequestAuthority;
use Kumwe\App\Studio\Application\Host\StudioResourceContextKeyFactory;
use Kumwe\App\Studio\Application\Media\StudioMediaOperations;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioMutationReplayRecord;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\App\Studio\Infrastructure\Host\SodiumStudioMutationOutcomeCodec;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Producer\Canonical\CanonicalJson;
use Kumwe\Producer\Error\Diagnostic;
use Kumwe\Producer\Error\HostError;
use Kumwe\Producer\Error\HostRefusal;
use Kumwe\Producer\Wire\HostResult;
use Kumwe\Producer\Wire\Operation;
use Kumwe\Producer\Wire\OperationRegistry;
use Kumwe\Producer\Wire\RequestEnvelope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;
use stdClass;

/**
 * Proves App mutation, audit, replay protection, and secret rehydration form one Producer boundary.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioProducerMutationBoundary::class)]
final class StudioProducerMutationBoundaryTest extends TestCase
{
    /**
     * An unkeyed mutation still runs with the same authoritative transaction and audit guarantee.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testUnkeyedMutationAlwaysUsesTheTransactionAndAuditBoundary(): void
    {
        [$operation, $request, $authority] = $this->authorizedRequest(
            'studio.operation/telemetry.emit',
            (object) ['event' => (object) ['name' => 'kumwe.app/test']],
        );
        [$boundary, $transactions, $replays, $audit] = $this->boundary($authority);
        $calls = 0;

        $result = $boundary->execute(
            $operation,
            $request,
            null,
            null,
            static function () use (&$calls): HostResult {
                $calls++;

                return new HostResult(null);
            },
        );

        self::assertNull($result->intentDigest);
        self::assertInstanceOf(HostResult::class, $result->outcome());
        self::assertSame(1, $calls);
        self::assertSame(1, $transactions->calls);
        self::assertCount(1, $audit->events);
        self::assertSame([], $replays->records);
    }

    /**
     * A committed HostError is sealed and replayed without invoking the mutation a second time.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testCommittedStateFailureIsProtectedAndReplayedExactlyOnce(): void
    {
        [$operation, $request, $authority] = $this->authorizedRequest(
            'studio.operation/recovery.store',
            (object) ['envelope' => (object) ['draft' => 'bounded']],
            'idempotency/committed-failure',
        );
        [$boundary, $transactions, $replays, $audit] = $this->boundary($authority);
        $scope = CanonicalJson::digest((object) ['scope' => 'committed-failure']);
        $intent = CanonicalJson::digest((object) ['intent' => 'committed-failure']);
        $expected = StudioProducerError::error(
            'conflict',
            'studio.media/terminal-upload-failure',
            'revision-current',
        );
        $calls = 0;
        $mutation = static function () use (&$calls, $expected): HostError {
            $calls++;

            return $expected;
        };

        $first = $boundary->execute($operation, $request, $scope, $intent, $mutation);
        $second = $boundary->execute($operation, $request, $scope, $intent, $mutation);

        self::assertInstanceOf(HostError::class, $first->outcome());
        self::assertInstanceOf(HostError::class, $second->outcome());
        self::assertSame($expected->toCanonicalJson(), $first->outcome()->toCanonicalJson());
        self::assertSame($expected->toCanonicalJson(), $second->outcome()->toCanonicalJson());
        self::assertSame(1, $calls);
        self::assertSame(1, $transactions->calls);
        self::assertCount(1, $audit->events);
        self::assertCount(1, $replays->records);
    }

    /**
     * Upload capabilities never enter replay storage and are restored only from verified current authority.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testAuthorizeUploadReplayRedactsAndRehydratesTheLiveToken(): void
    {
        [$operation, $request, $authority] = $this->authorizedRequest(
            'studio.operation/media.authorize-upload',
            (object) ['request' => (object) ['name' => 'asset.png']],
            'idempotency/upload-grant',
            ['content.update', 'studio.mode.content'],
        );
        $media = $this->createMock(StudioMediaOperations::class);
        $media->expects(self::once())
            ->method('replayUploadGrant')
            ->willReturnCallback(static function (
                ExecutionContext $context,
                StudioHostSessionSnapshot $snapshot,
                stdClass $stored,
            ): stdClass {
                unset($context, $snapshot);
                self::assertInstanceOf(stdClass::class, $stored->headers ?? null);
                self::assertFalse(property_exists($stored->headers, 'X-Studio-Upload-Token'));
                $restored = clone $stored;
                $restored->headers = clone $stored->headers;
                $restored->headers->{'X-Studio-Upload-Token'} = 'restored-live-token';

                return $restored;
            });
        [$boundary, , $replays, $audit, $codec] = $this->boundary($authority, $media);
        $scope = CanonicalJson::digest((object) ['scope' => 'upload-grant']);
        $intent = CanonicalJson::digest((object) ['intent' => 'upload-grant']);
        $grant = (object) [
            'headers' => (object) [
                'Content-Type' => 'image/png',
                'X-Studio-Upload-Token' => 'fresh-live-token',
            ],
            'method' => 'PUT',
            'uploadId' => 'uploads/producer-test',
            'url' => '/administrator/studio/media/uploads/producer-test',
        ];
        $calls = 0;
        $mutation = static function () use (&$calls, $grant): HostResult {
            $calls++;

            return new HostResult($grant);
        };

        $fresh = $boundary->execute($operation, $request, $scope, $intent, $mutation)->outcome();
        self::assertInstanceOf(HostResult::class, $fresh);
        self::assertSame('fresh-live-token', $fresh->value->headers->{'X-Studio-Upload-Token'});
        $record = $replays->onlyRecord();
        self::assertNotNull($record->protectedOutcome);
        $stored = $codec->recover($record->protectedOutcome, $record->scopeDigest, $record->intentDigest);
        self::assertInstanceOf(HostResult::class, $stored);
        self::assertInstanceOf(stdClass::class, $stored->value->headers ?? null);
        self::assertFalse(property_exists($stored->value->headers, 'X-Studio-Upload-Token'));

        $replayed = $boundary->execute($operation, $request, $scope, $intent, $mutation)->outcome();
        self::assertInstanceOf(HostResult::class, $replayed);
        self::assertSame('restored-live-token', $replayed->value->headers->{'X-Studio-Upload-Token'});
        self::assertSame(1, $calls);
        self::assertCount(1, $audit->events);
    }

    /**
     * A stored upload grant that is not an object never rehydrates a live token.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testANonObjectUploadGrantIsRejectedAtRehydration(): void
    {
        [$operation, $request, $authority] = $this->authorizedRequest(
            'studio.operation/media.authorize-upload',
            (object) ['request' => (object) ['name' => 'asset.png']],
            'idempotency/upload-grant-invalid',
            ['content.update', 'studio.mode.content'],
        );
        [$boundary] = $this->boundary($authority, self::createStub(StudioMediaOperations::class));
        $scope = CanonicalJson::digest((object) ['scope' => 'upload-grant-invalid']);
        $intent = CanonicalJson::digest((object) ['intent' => 'upload-grant-invalid']);

        $this->expectException(HostRefusal::class);
        $this->expectExceptionMessage('could not be completed');

        $boundary->execute($operation, $request, $scope, $intent, static fn (): HostResult
            => new HostResult('not-an-object'));
    }

    /**
     * A stored upload grant the media authority cannot restore is rejected rather than served.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnrestorableUploadGrantIsRejectedAtReplay(): void
    {
        [$operation, $request, $authority] = $this->authorizedRequest(
            'studio.operation/media.authorize-upload',
            (object) ['request' => (object) ['name' => 'asset.png']],
            'idempotency/upload-grant-unrestorable',
            ['content.update', 'studio.mode.content'],
        );
        $media = self::createStub(StudioMediaOperations::class);
        $media->method('replayUploadGrant')
            ->willThrowException(new \RuntimeException('The signing key rotated.'));
        [$boundary] = $this->boundary($authority, $media);
        $scope = CanonicalJson::digest((object) ['scope' => 'upload-grant-unrestorable']);
        $intent = CanonicalJson::digest((object) ['intent' => 'upload-grant-unrestorable']);
        $grant = (object) [
            'headers' => (object) ['Content-Type' => 'image/png', 'X-Studio-Upload-Token' => 'fresh-live-token'],
            'method' => 'PUT',
            'uploadId' => 'uploads/producer-unrestorable',
            'url' => '/administrator/studio/media/uploads/producer-unrestorable',
        ];
        $fresh = $boundary->execute($operation, $request, $scope, $intent, static fn (): HostResult
            => new HostResult($grant))->outcome();
        self::assertInstanceOf(HostResult::class, $fresh);

        $this->expectException(HostRefusal::class);
        $this->expectExceptionMessage('could not be completed');

        $boundary->execute($operation, $request, $scope, $intent, static fn (): HostResult
            => new HostResult($grant));
    }

    /**
     * An audit sink failure inside an unkeyed save propagates, rolls the transaction back and commits nothing.
     *
     * The audit write runs inside the same authoritative transaction as the mutation, so a recorder that
     * throws must abort the scope rather than leave a mutated head without its audit trail.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAFailedAuditWriteRollsBackAnUnkeyedSave(): void
    {
        [$operation, $request, $authority] = $this->authorizedRequest(
            'studio.operation/artifact.save',
            (object) ['document' => (object) ['kind' => 'entry'], 'expectedRevision' => 'entry-r1'],
        );
        $transactions = $this->observingTransactions();
        $audit = $this->failingAudit();
        [$boundary, , $replays] = $this->boundary($authority, transactions: $transactions, audit: $audit);
        $calls = 0;

        try {
            $boundary->execute(
                $operation,
                $request,
                null,
                null,
                static function () use (&$calls): HostResult {
                    $calls++;

                    return new HostResult(null, 'entry-r2');
                },
            );
            self::fail('A failed Studio audit write must abort the artifact mutation.');
        } catch (RuntimeException $failure) {
            self::assertSame('audit unavailable', $failure->getMessage());
        }

        self::assertSame(1, $calls);
        self::assertSame(1, $audit->attempts);
        self::assertSame(0, $transactions->commits);
        self::assertSame(1, $transactions->rollbacks);
        self::assertSame([], $replays->records);
    }

    /**
     * An audit sink failure inside a keyed save rolls back before any replay claim is completed.
     *
     * A later retry with the same key must find no protected outcome to replay, because the claim
     * was opened inside the very transaction the failed audit write discarded.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAFailedAuditWriteCompletesNoReplayClaimForAKeyedSave(): void
    {
        [$operation, $request, $authority] = $this->authorizedRequest(
            'studio.operation/artifact.save',
            (object) ['document' => (object) ['kind' => 'entry'], 'expectedRevision' => 'entry-r1'],
            'idempotency/audit-unavailable',
        );
        $transactions = $this->observingTransactions();
        [$boundary, , $replays] = $this->boundary(
            $authority,
            transactions: $transactions,
            audit: $this->failingAudit(),
        );
        $scope = CanonicalJson::digest((object) ['scope' => 'audit-unavailable']);
        $intent = CanonicalJson::digest((object) ['intent' => 'audit-unavailable']);

        try {
            $boundary->execute($operation, $request, $scope, $intent, static fn (): HostResult
                => new HostResult(null, 'entry-r2'));
            self::fail('A failed Studio audit write must abort the keyed artifact mutation.');
        } catch (RuntimeException $failure) {
            self::assertSame('audit unavailable', $failure->getMessage());
        }

        self::assertSame(0, $transactions->commits);
        self::assertSame(1, $transactions->rollbacks);
        self::assertLessThanOrEqual(1, count($replays->records));
        foreach ($replays->records as $record) {
            self::assertNull($record->protectedOutcome, 'A rolled-back mutation must not complete its replay claim.');
        }
    }

    /**
     * Build one transaction manager that commits on return and rolls back when the operation throws.
     *
     * @return  TransactionManager  Observable double exposing `commits` and `rollbacks` counters and
     *          running the registered hooks for whichever way the scope ends.
     *
     * @since   2.0.0
     */
    private function observingTransactions(): TransactionManager
    {
        return new class implements TransactionManager {
            /**
             * Number of scopes that ended in a commit.
             *
             * @var    int
             * @since  2.0.0
             */
            public int $commits = 0;

            /**
             * Number of scopes that ended in a rollback.
             *
             * @var    int
             * @since  2.0.0
             */
            public int $rollbacks = 0;

            /**
             * Hooks queued for the next commit.
             *
             * @var    list<callable(): void>
             * @since  2.0.0
             */
            private array $commitHooks = [];

            /**
             * Hooks queued for the next rollback.
             *
             * @var    list<callable(): void>
             * @since  2.0.0
             */
            private array $rollbackHooks = [];

            /**
             * Run the operation, committing on return and rolling back when it throws.
             *
             * @param   callable  $operation  Work to perform inside the simulated transaction.
             *
             * @return  mixed  Whatever the operation returned, passed straight back.
             *
             * @throws  \Throwable  Whatever the operation threw, after the rollback hooks ran.
             *
             * @since   2.0.0
             */
            public function transactional(callable $operation): mixed
            {
                try {
                    $result = $operation();
                } catch (\Throwable $failure) {
                    $this->rollbacks++;
                    $hooks = $this->rollbackHooks;
                    $this->commitHooks = [];
                    $this->rollbackHooks = [];
                    foreach ($hooks as $hook) {
                        $hook();
                    }

                    throw $failure;
                }
                $this->commits++;
                $hooks = $this->commitHooks;
                $this->commitHooks = [];
                $this->rollbackHooks = [];
                foreach ($hooks as $hook) {
                    $hook();
                }

                return $result;
            }

            /**
             * Queue a hook for the moment the current scope commits.
             *
             * @param   callable  $operation  Side effect to perform once the work is durable.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function afterCommit(callable $operation): void
            {
                $this->commitHooks[] = $operation;
            }

            /**
             * Queue a hook for the moment the current scope is discarded.
             *
             * @param   callable  $operation  Compensating action to perform on rollback.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function afterRollback(callable $operation): void
            {
                $this->rollbackHooks[] = $operation;
            }
        };
    }

    /**
     * Build one audit sink that refuses every write, to prove the surrounding mutation rolls back.
     *
     * @return  AuditRecorder  Double counting refused writes in `attempts`.
     *
     * @since   2.0.0
     */
    private function failingAudit(): AuditRecorder
    {
        return new class implements AuditRecorder {
            /**
             * Number of audit writes refused.
             *
             * @var    int
             * @since  2.0.0
             */
            public int $attempts = 0;

            /**
             * Refuse the audit write.
             *
             * @param   AuditEvent  $event  Event whose write is refused.
             *
             * @return  void
             *
             * @throws  RuntimeException  Always, carrying the message the boundary must propagate.
             *
             * @since   2.0.0
             */
            public function record(AuditEvent $event): void
            {
                unset($event);
                $this->attempts++;

                throw new RuntimeException('audit unavailable');
            }
        };
    }

    /**
     * Compose the boundary around observable deterministic in-memory services.
     *
     * @param   StudioProducerRequestAuthority  $authority         Successfully authorized request authority.
     * @param   StudioMediaOperations|null      $media             Optional upload rehydration authority.
     * @param   TransactionState|null           $transactionState  Optional live transaction state; defaults
     *          to no open transaction.
     * @param   TransactionManager|null         $transactions      Optional transaction manager double; defaults
     *          to one that counts scopes and commits implicitly on return.
     * @param   AuditRecorder|null              $audit             Optional audit sink double; defaults to one
     *          that records every event in memory.
     *
     * @return  array{StudioProducerMutationBoundary, object, object, object, SodiumStudioMutationOutcomeCodec}
     *
     * @since  2.0.0
     */
    private function boundary(
        StudioProducerRequestAuthority $authority,
        ?StudioMediaOperations $media = null,
        ?TransactionState $transactionState = null,
        ?TransactionManager $transactions = null,
        ?AuditRecorder $audit = null,
    ): array {
        $transactions ??= new class implements TransactionManager {
            /**
             * Number of transaction scopes opened.
             *
             * @var    int
             * @since  2.0.0
             */
            public int $calls = 0;

            /**
             * Count the scope and run the operation directly, committing implicitly on return.
             *
             * @param   callable  $operation  Work to perform inside the simulated transaction.
             *
             * @return  mixed  Whatever the operation returned, passed straight back.
             *
             * @since   2.0.0
             */
            public function transactional(callable $operation): mixed
            {
                $this->calls++;

                return $operation();
            }

            /**
             * Run a commit hook immediately, as if the outermost transaction had just committed.
             *
             * @param   callable  $operation  Side effect to perform once the work is durable.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function afterCommit(callable $operation): void
            {
                $operation();
            }

            /**
             * Discard a rollback hook, because this in-memory manager never rolls back.
             *
             * @param   callable  $operation  Compensating action that is deliberately dropped.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function afterRollback(callable $operation): void
            {
                unset($operation);
            }
        };
        $replays = new class implements StudioMutationReplayRepository {
            /**
             * Pending and completed claims keyed by trusted scope digest.
             *
             * @var    array<string, StudioMutationReplayRecord>
             * @since  2.0.0
             */
            public array $records = [];

            /**
             * Find one claim by the complete trusted replay scope digest.
             *
             * @param   string  $scopeDigest  App-namespaced lowercase SHA-256 scope digest.
             *
             * @return  ?StudioMutationReplayRecord  Existing claim, or null when the scope is unclaimed.
             *
             * @since   2.0.0
             */
            public function findReplay(string $scopeDigest): ?StudioMutationReplayRecord
            {
                return $this->records[$scopeDigest] ?? null;
            }

            /**
             * Claim one scope in memory, refusing a duplicate claim as a replay race.
             *
             * @param   StudioMutationReplayRecord           $record    New pending claim.
             * @param   StudioHostSessionSnapshot            $snapshot  Trusted live App host session.
             * @param   \Kumwe\Producer\Wire\RequestContext  $request   Validated Producer request context.
             *
             * @return  void
             *
             * @throws  StudioMutationReplayRace  When the scope digest is already claimed.
             *
             * @since   2.0.0
             */
            public function beginReplay(
                StudioMutationReplayRecord $record,
                StudioHostSessionSnapshot $snapshot,
                \Kumwe\Producer\Wire\RequestContext $request,
            ): void {
                unset($snapshot, $request);
                if (isset($this->records[$record->scopeDigest])) {
                    throw new StudioMutationReplayRace('Duplicate in-memory replay claim.');
                }
                $this->records[$record->scopeDigest] = $record;
            }

            /**
             * Replace one pending claim with its completed protected outcome.
             *
             * @param   string  $scopeDigest       Existing claimed scope digest.
             * @param   string  $protectedOutcome  Authenticated completed outcome envelope.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function completeReplay(string $scopeDigest, string $protectedOutcome): void
            {
                $record = $this->records[$scopeDigest] ?? null;
                if ($record === null) {
                    throw new \LogicException('Missing in-memory replay claim.');
                }
                $this->records[$scopeDigest] = new StudioMutationReplayRecord(
                    $record->scopeDigest,
                    $record->intentDigest,
                    $protectedOutcome,
                );
            }

            /**
             * Return the only completed test record.
             *
             * @return  StudioMutationReplayRecord  The single stored replay record.
             *
             * @since   2.0.0
             */
            public function onlyRecord(): StudioMutationReplayRecord
            {
                if (count($this->records) !== 1) {
                    throw new \LogicException('Expected exactly one in-memory replay record.');
                }

                return array_values($this->records)[0];
            }
        };
        $audit ??= new class implements AuditRecorder {
            /**
             * Every audit event recorded, in order of arrival.
             *
             * @var    list<AuditEvent>
             * @since  2.0.0
             */
            public array $events = [];

            /**
             * Append one audit event to the observable in-memory trail.
             *
             * @param   AuditEvent  $event  Validated record of the audited mutation.
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
             * Return the fixed deterministic test instant.
             *
             * @return  DateTimeImmutable  Constant timestamp keeping audit records reproducible.
             *
             * @since   2.0.0
             */
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-08-29T12:00:00+00:00');
            }
        };
        $codec = new SodiumStudioMutationOutcomeCodec(str_repeat('k', 32));

        return [
            new StudioProducerMutationBoundary(
                $transactions,
                $transactionState ?? self::createStub(TransactionState::class),
                $replays,
                $codec,
                $audit,
                $clock,
                $media ?? $this->createStub(StudioMediaOperations::class),
                $authority,
            ),
            $transactions,
            $replays,
            $audit,
            $codec,
        ];
    }

    /**
     * Open and authorize one exact Producer mutation request.
     *
     * @param   string        $capability      Closed Producer operation capability.
     * @param   mixed         $arguments       Operation argument value.
     * @param   string|null   $idempotencyKey  Optional replay key.
     * @param   list<string>  $capabilities    Effective App grants.
     *
     * @return  array{Operation, RequestEnvelope, StudioProducerRequestAuthority}
     *
     * @since  2.0.0
     */
    private function authorizedRequest(
        string $capability,
        mixed $arguments,
        ?string $idempotencyKey = null,
        array $capabilities = ['studio.mode.content'],
    ): array {
        [$sessions, $context, $snapshot] = $this->session($capabilities);
        $operation = OperationRegistry::byCapability($capability);
        $requestContext = (object) [
            'operationId' => $capability,
            'protocolVersion' => RequestEnvelope::WIRE_PROTOCOL_VERSION,
            'requestId' => 'requests/mutation-boundary-test',
            'resourceContextKey' => $snapshot->session->resourceContextKey,
            'sessionGeneration' => $snapshot->generation,
        ];
        if ($idempotencyKey !== null) {
            $requestContext->idempotencyKey = $idempotencyKey;
        }
        $request = RequestEnvelope::parse(CanonicalJson::stringify((object) [
            'arguments' => $arguments,
            'context' => $requestContext,
        ]));
        $authority = new StudioProducerRequestAuthority($context, $sessions);
        self::assertNull($authority->authorize($operation, $request));

        return [$operation, $request, $authority];
    }

    /**
     * Assemble one deterministic open Studio session.
     *
     * @param   list<string>  $capabilities  Effective App grants.
     *
     * @return  array{StudioHostSessionAuthority, ExecutionContext, StudioHostSessionSnapshot}
     *
     * @since  2.0.0
     */
    private function session(array $capabilities): array
    {
        $repository = new class implements StudioHostSessionRepository {
            /**
             * Stored bindings keyed by opaque resource-context key.
             *
             * @var    array<string, StudioHostSession>
             * @since  2.0.0
             */
            private array $sessions = [];

            /**
             * Persist an opened binding under its resource-context key.
             *
             * @param   StudioHostSession  $session  Fully verified immutable binding.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function add(StudioHostSession $session): void
            {
                $this->sessions[$session->resourceContextKey] = $session;
            }

            /**
             * Resolve an opaque context key against the in-memory store.
             *
             * @param   string  $resourceContextKey  Canonical host-envelope key.
             *
             * @return  ?StudioHostSession  Stored binding, or null when unknown.
             *
             * @since   2.0.0
             */
            public function find(string $resourceContextKey): ?StudioHostSession
            {
                return $this->sessions[$resourceContextKey] ?? null;
            }
        };
        $keys = new class implements StudioResourceContextKeyFactory {
            /**
             * Mint the fixed deterministic context key for this test.
             *
             * @return  string  Stable opaque resource-context identifier.
             *
             * @since   2.0.0
             */
            public function create(): string
            {
                return 'contexts/producer-mutation-test';
            }
        };
        $sessions = new StudioHostSessionAuthority(AuthorizationContext::gateway(), $repository, $keys);
        $context = AuthenticatedPrincipal::issueFromStrings(
            AuthorizationContext::provenance(),
            AuthorizationContext::SUBJECT,
            $capabilities,
        )->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'studio-producer-mutation-test',
            surface: AuthenticatedSurface::Administrator,
            sessionId: 'administrator-session-test',
        );
        $snapshot = $sessions->open(
            $context,
            StudioSessionMode::Content,
            StudioResourceKind::Content,
            'content-producer-mutation',
        );

        return [$sessions, $context, $snapshot];
    }

    /**
     * Half a replay coordinate is an internal contract breach and never reaches the transaction.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMismatchedReplayCoordinatesAreRefusedBeforeAnyWork(): void
    {
        [$operation, $request, $authority] = $this->authorizedRequest(
            'studio.operation/recovery.store',
            (object) ['envelope' => (object) ['draft' => 'coordinates']],
            'idempotency/coordinate-mismatch',
        );
        [$boundary, $transactions, $replays, $audit] = $this->boundary($authority);
        $calls = 0;

        try {
            $boundary->execute(
                $operation,
                $request,
                'scope/coordinate-mismatch',
                null,
                static function () use (&$calls): HostResult {
                    $calls++;

                    return new HostResult(null);
                },
            );
            self::fail('A scope key without an intent digest must be refused.');
        } catch (HostRefusal $refused) {
            self::assertSame('internal', $refused->error()->category());
        }

        self::assertSame(0, $calls);
        self::assertSame(0, $transactions->calls);
        self::assertSame([], $replays->records);
        self::assertCount(0, $audit->events);
    }

    /**
     * A boundary entered while a transaction is already open is refused before any work or lookup runs.
     *
     * Replay-race recovery depends on observing the losing statement's rollback, which an enclosing
     * transaction would turn into an aborted transaction on PostgreSQL, so nesting is a host composition
     * error rather than a scope to join: no authority snapshot, replay lookup, mutation or audit may run.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testANestedTransactionIsRefusedBeforeAnyWork(): void
    {
        [$operation, $request, $authority] = $this->authorizedRequest(
            'studio.operation/recovery.store',
            (object) ['envelope' => (object) ['draft' => 'nested']],
            'idempotency/nested',
        );
        $state = self::createStub(TransactionState::class);
        $state->method('isActive')->willReturn(true);
        [$boundary, $transactions, $replays, $audit] = $this->boundary($authority, transactionState: $state);
        $scope = CanonicalJson::digest((object) ['scope' => 'nested']);
        $intent = CanonicalJson::digest((object) ['intent' => 'nested']);
        $calls = 0;

        try {
            $boundary->execute(
                $operation,
                $request,
                $scope,
                $intent,
                static function () use (&$calls): HostResult {
                    $calls++;

                    return new HostResult(null);
                },
            );
            self::fail('A mutation boundary entered inside an open transaction must be refused.');
        } catch (HostRefusal $refused) {
            self::assertSame('internal', $refused->error()->category());
            self::assertSame(
                ['studio.host/mutation-boundary-nested'],
                array_map(
                    static fn (Diagnostic $diagnostic): string => $diagnostic->code(),
                    $refused->error()->diagnostics(),
                ),
            );
        }

        self::assertSame(0, $calls);
        self::assertSame(0, $transactions->calls);
        self::assertSame([], $replays->records);
        self::assertCount(0, $audit->events);
    }

    /**
     * A committed mutation that advances a revision records that revision in its audit metadata.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnAuditedMutationRecordsTheAdvancedRevision(): void
    {
        [$operation, $request, $authority] = $this->authorizedRequest(
            'studio.operation/telemetry.emit',
            (object) ['event' => (object) ['name' => 'kumwe.app/revision']],
        );
        [$boundary, , , $audit] = $this->boundary($authority);

        $result = $boundary->execute(
            $operation,
            $request,
            null,
            null,
            static fn (): HostResult => new HostResult(null, 'entry-r2'),
        );

        $outcome = $result->outcome();
        self::assertInstanceOf(HostResult::class, $outcome);
        self::assertSame('entry-r2', $outcome->revision);
        self::assertCount(1, $audit->events);
        self::assertSame('success', $audit->events[0]->outcome());
        $metadata = $audit->events[0]->metadata();
        self::assertSame('entry-r2', $metadata['revision']);
        self::assertFalse($metadata['idempotent']);
    }

    /**
     * A scope claimed between the fast lookup and the transaction is served from the store, not re-run.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAReplayClaimedBetweenLookupAndTransactionIsServedFromTheStore(): void
    {
        [$operation, $request, $authority] = $this->authorizedRequest(
            'studio.operation/recovery.store',
            (object) ['envelope' => (object) ['draft' => 'raced']],
            'idempotency/claimed-in-between',
        );
        $intent = CanonicalJson::digest((object) ['intent' => 'claimed-in-between']);
        $record = $this->protectedRecord(
            'scope/claimed-in-between',
            $intent,
            new HostResult((object) ['replayed' => true]),
        );
        $replays = $this->scriptedReplays([null, $record]);
        [$boundary, $transactions] = $this->scriptedBoundary($authority, $replays);
        $calls = 0;

        $result = $boundary->execute(
            $operation,
            $request,
            'scope/raced',
            $intent,
            static function () use (&$calls): HostResult {
                $calls++;

                return new HostResult(null);
            },
        );

        self::assertSame($intent, $result->intentDigest);
        $outcome = $result->outcome();
        self::assertInstanceOf(HostResult::class, $outcome);
        self::assertTrue($outcome->value->replayed);
        self::assertSame(0, $calls);
        self::assertSame(0, $replays->beginCalls);
        self::assertSame(1, $transactions->calls);
    }

    /**
     * A lost replay race whose winner is not yet visible is refused as retryably in progress.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testALostReplayRaceWithoutAWinnerIsRefusedAsInProgress(): void
    {
        [$operation, $request, $authority] = $this->authorizedRequest(
            'studio.operation/recovery.store',
            (object) ['envelope' => (object) ['draft' => 'no-winner']],
            'idempotency/no-winner',
        );
        $intent = CanonicalJson::digest((object) ['intent' => 'no-winner']);
        $replays = $this->scriptedReplays([null, null, null], true);
        [$boundary] = $this->scriptedBoundary($authority, $replays);
        $calls = 0;

        try {
            $boundary->execute(
                $operation,
                $request,
                'scope/no-winner',
                $intent,
                static function () use (&$calls): HostResult {
                    $calls++;

                    return new HostResult(null);
                },
            );
            self::fail('A lost claim race without a visible winner must be refused.');
        } catch (HostRefusal $refused) {
            self::assertSame('unavailable', $refused->error()->category());
        }

        self::assertSame(0, $calls);
        self::assertSame(1, $replays->beginCalls);
    }

    /**
     * A lost replay race serves the winner's protected outcome without re-running the mutation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testALostReplayRaceServesTheWinningClaim(): void
    {
        [$operation, $request, $authority] = $this->authorizedRequest(
            'studio.operation/recovery.store',
            (object) ['envelope' => (object) ['draft' => 'winner']],
            'idempotency/winner',
        );
        $intent = CanonicalJson::digest((object) ['intent' => 'winner']);
        $record = $this->protectedRecord('scope/winner', $intent, new HostResult((object) ['winner' => true]));
        $replays = $this->scriptedReplays([null, null, $record], true);
        [$boundary] = $this->scriptedBoundary($authority, $replays);
        $calls = 0;

        $result = $boundary->execute(
            $operation,
            $request,
            'scope/winner',
            $intent,
            static function () use (&$calls): HostResult {
                $calls++;

                return new HostResult(null);
            },
        );

        self::assertSame($intent, $result->intentDigest);
        $outcome = $result->outcome();
        self::assertInstanceOf(HostResult::class, $outcome);
        self::assertTrue($outcome->value->winner);
        self::assertSame(0, $calls);
        self::assertSame(1, $replays->beginCalls);
    }

    /**
     * A replay whose current intent differs from the stored claim is refused, never served.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAReplayWithChangedIntentIsRefused(): void
    {
        [$operation, $request, $authority] = $this->authorizedRequest(
            'studio.operation/recovery.store',
            (object) ['envelope' => (object) ['draft' => 'changed']],
            'idempotency/changed-intent',
        );
        $storedIntent = CanonicalJson::digest((object) ['intent' => 'original']);
        $record = $this->protectedRecord('scope/changed-intent', $storedIntent, new HostResult(null));
        $replays = $this->scriptedReplays([$record]);
        [$boundary] = $this->scriptedBoundary($authority, $replays);
        $calls = 0;

        try {
            $boundary->execute(
                $operation,
                $request,
                'scope/changed-intent',
                CanonicalJson::digest((object) ['intent' => 'changed']),
                static function () use (&$calls): HostResult {
                    $calls++;

                    return new HostResult(null);
                },
            );
            self::fail('A replay under a changed intent digest must be refused.');
        } catch (HostRefusal $refused) {
            self::assertSame('invalid-request', $refused->error()->category());
        }

        self::assertSame(0, $calls);
    }

    /**
     * A claim still awaiting its protected outcome is refused as retryably in progress.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAPendingReplayClaimIsRefusedAsInProgress(): void
    {
        [$operation, $request, $authority] = $this->authorizedRequest(
            'studio.operation/recovery.store',
            (object) ['envelope' => (object) ['draft' => 'pending']],
            'idempotency/pending-claim',
        );
        $intent = CanonicalJson::digest((object) ['intent' => 'pending-claim']);
        $record = new StudioMutationReplayRecord(hash('sha256', 'scope/pending-claim'), $intent, null);
        $replays = $this->scriptedReplays([$record]);
        [$boundary] = $this->scriptedBoundary($authority, $replays);
        $calls = 0;

        try {
            $boundary->execute(
                $operation,
                $request,
                'scope/pending-claim',
                $intent,
                static function () use (&$calls): HostResult {
                    $calls++;

                    return new HostResult(null);
                },
            );
            self::fail('A pending replay claim must be refused as in progress.');
        } catch (HostRefusal $refused) {
            self::assertSame('unavailable', $refused->error()->category());
        }

        self::assertSame(0, $calls);
    }

    /**
     * A stored upload grant that authenticates but is not an object is refused at rehydration.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAStoredNonObjectUploadGrantIsRefusedWhenReplayed(): void
    {
        [$operation, $request, $authority] = $this->authorizedRequest(
            'studio.operation/media.authorize-upload',
            (object) ['request' => (object) ['name' => 'asset.png']],
            'idempotency/stored-grant-invalid',
            ['content.update', 'studio.mode.content'],
        );
        $intent = CanonicalJson::digest((object) ['intent' => 'stored-grant-invalid']);
        $record = $this->protectedRecord('scope/stored-grant-invalid', $intent, new HostResult('not-an-object'));
        $replays = $this->scriptedReplays([$record]);
        [$boundary] = $this->scriptedBoundary($authority, $replays);
        $calls = 0;

        try {
            $boundary->execute(
                $operation,
                $request,
                'scope/stored-grant-invalid',
                $intent,
                static function () use (&$calls): HostResult {
                    $calls++;

                    return new HostResult(null);
                },
            );
            self::fail('A stored non-object upload grant must be refused at replay.');
        } catch (HostRefusal $refused) {
            self::assertSame('internal', $refused->error()->category());
        }

        self::assertSame(0, $calls);
    }

    /**
     * Build one durable claim completed under the shared deterministic test codec key.
     *
     * @param   string                $scopeSeed     Seed hashed into the record's trusted scope digest.
     * @param   string                $intentDigest  Canonical SRI SHA-256 intent digest.
     * @param   HostResult|HostError  $outcome       Logical outcome to protect for replay.
     *
     * @return  StudioMutationReplayRecord  Completed claim recoverable by the boundary's codec.
     *
     * @since   2.0.0
     */
    private function protectedRecord(
        string $scopeSeed,
        string $intentDigest,
        HostResult|HostError $outcome,
    ): StudioMutationReplayRecord {
        $scopeDigest = hash('sha256', $scopeSeed);
        $codec = new SodiumStudioMutationOutcomeCodec(str_repeat('k', 32));

        return new StudioMutationReplayRecord(
            $scopeDigest,
            $intentDigest,
            $codec->protect($outcome, $scopeDigest, $intentDigest),
        );
    }

    /**
     * Build one scripted replay repository whose lookups answer from a fixed queue.
     *
     * @param   list<StudioMutationReplayRecord|null>  $finds        Queued findReplay answers, in order.
     * @param   bool                                   $raceOnBegin  Whether claiming loses the replay race.
     *
     * @return  StudioMutationReplayRepository  Observable scripted repository double.
     *
     * @since   2.0.0
     */
    private function scriptedReplays(array $finds, bool $raceOnBegin = false): StudioMutationReplayRepository
    {
        return new class ($finds, $raceOnBegin) implements StudioMutationReplayRepository {
            /**
             * Number of claim attempts observed.
             *
             * @var    int
             * @since  2.0.0
             */
            public int $beginCalls = 0;

            /**
             * Completed claims, as scope digest and protected outcome pairs.
             *
             * @var    list<array{string, string}>
             * @since  2.0.0
             */
            public array $completed = [];

            /**
             * Retain the scripted lookup queue and race behaviour.
             *
             * @param   array  $finds        Queued findReplay answers, in order.
             * @param   bool   $raceOnBegin  Whether claiming loses the replay race.
             *
             * @since   2.0.0
             */
            public function __construct(private array $finds, private bool $raceOnBegin)
            {
            }

            /**
             * Answer the next scripted lookup regardless of the requested scope.
             *
             * @param   string  $scopeDigest  App-namespaced lowercase SHA-256 scope digest.
             *
             * @return  ?StudioMutationReplayRecord  The next queued record, or null.
             *
             * @since   2.0.0
             */
            public function findReplay(string $scopeDigest): ?StudioMutationReplayRecord
            {
                unset($scopeDigest);
                $next = array_shift($this->finds);

                return $next instanceof StudioMutationReplayRecord ? $next : null;
            }

            /**
             * Count one claim attempt, losing the scripted race when configured.
             *
             * @param   StudioMutationReplayRecord           $record    New pending claim.
             * @param   StudioHostSessionSnapshot            $snapshot  Trusted live App host session.
             * @param   \Kumwe\Producer\Wire\RequestContext  $request   Validated Producer request context.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function beginReplay(
                StudioMutationReplayRecord $record,
                StudioHostSessionSnapshot $snapshot,
                \Kumwe\Producer\Wire\RequestContext $request,
            ): void {
                unset($record, $snapshot, $request);
                $this->beginCalls++;
                if ($this->raceOnBegin) {
                    throw new StudioMutationReplayRace('Scripted concurrent replay claim.');
                }
            }

            /**
             * Record one completed claim for later inspection.
             *
             * @param   string  $scopeDigest       Existing claimed scope digest.
             * @param   string  $protectedOutcome  Authenticated completed outcome envelope.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function completeReplay(string $scopeDigest, string $protectedOutcome): void
            {
                $this->completed[] = [$scopeDigest, $protectedOutcome];
            }
        };
    }

    /**
     * Compose the boundary around a caller-scripted replay repository.
     *
     * @param   StudioProducerRequestAuthority  $authority  Successfully authorized request authority.
     * @param   StudioMutationReplayRepository  $replays    Scripted replay repository double.
     *
     * @return  array{StudioProducerMutationBoundary, object, object}
     *
     * @since   2.0.0
     */
    private function scriptedBoundary(
        StudioProducerRequestAuthority $authority,
        StudioMutationReplayRepository $replays,
    ): array {
        $transactions = new class implements TransactionManager {
            /**
             * Number of transaction scopes opened.
             *
             * @var    int
             * @since  2.0.0
             */
            public int $calls = 0;

            /**
             * Count the scope and run the operation directly, committing implicitly on return.
             *
             * @param   callable  $operation  Work to perform inside the simulated transaction.
             *
             * @return  mixed  Whatever the operation returned, passed straight back.
             *
             * @since   2.0.0
             */
            public function transactional(callable $operation): mixed
            {
                $this->calls++;

                return $operation();
            }

            /**
             * Run a commit hook immediately, as if the outermost transaction had just committed.
             *
             * @param   callable  $operation  Side effect to perform once the work is durable.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function afterCommit(callable $operation): void
            {
                $operation();
            }

            /**
             * Discard a rollback hook, because this in-memory manager never rolls back.
             *
             * @param   callable  $operation  Compensating action that is deliberately dropped.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function afterRollback(callable $operation): void
            {
                unset($operation);
            }
        };
        $audit = new class implements AuditRecorder {
            /**
             * Every audit event recorded, in order of arrival.
             *
             * @var    list<AuditEvent>
             * @since  2.0.0
             */
            public array $events = [];

            /**
             * Append one audit event to the observable in-memory trail.
             *
             * @param   AuditEvent  $event  Validated record of the audited mutation.
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
             * Return the fixed deterministic test instant.
             *
             * @return  DateTimeImmutable  Constant timestamp keeping audit records reproducible.
             *
             * @since   2.0.0
             */
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-08-29T12:00:00+00:00');
            }
        };

        return [
            new StudioProducerMutationBoundary(
                $transactions,
                self::createStub(TransactionState::class),
                $replays,
                new SodiumStudioMutationOutcomeCodec(str_repeat('k', 32)),
                $audit,
                $clock,
                self::createStub(StudioMediaOperations::class),
                $authority,
            ),
            $transactions,
            $audit,
        ];
    }
}
