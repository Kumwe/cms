<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\Transaction\Contract\TransactionState;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Studio\Application\Media\StudioMediaOperations;
use Kumwe\App\Studio\Domain\Host\StudioMutationReplayRecord;
use Kumwe\Producer\Canonical\CanonicalJson;
use Kumwe\Producer\Error\HostError;
use Kumwe\Producer\Wire\HostResult;
use Kumwe\Producer\Wire\MutationOutcome;
use Kumwe\Producer\Wire\Operation;
use Kumwe\Producer\Wire\Port\MutationBoundaryInterface;
use Kumwe\Producer\Wire\RequestEnvelope;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use stdClass;

/**
 * App-owned atomic transaction, audit and protected replay boundary for every Producer mutation.
 *
 * The boundary assumes it is the outermost transaction of its request, and asserts it. Replay-race
 * recovery relies on the statement that lost a unique-index race having been rolled back before
 * `findReplay()` re-reads the winning claim; inside an enclosing transaction PostgreSQL instead leaves the
 * whole transaction aborted and the recovery lookup fails. `execute()` therefore refuses to start while any
 * transaction is already active rather than joining it the way ordinary `TransactionManager` nesting would.
 *
 * @since  2.0.0
 */
final readonly class StudioProducerMutationBoundary implements MutationBoundaryInterface
{
    /**
     * Compose one request-scoped boundary from App transaction, persistence and authority services.
     *
     * @param  TransactionManager              $transactions      Authoritative nesting transaction manager.
     * @param  TransactionState                $transactionState  Live connection state proving no scope is open.
     * @param  StudioMutationReplayRepository  $replays           Durable keyed mutation claim store.
     * @param  StudioMutationOutcomeCodec      $outcomes          Authenticated logical-outcome protection.
     * @param  AuditRecorder                   $audit             Transactional disclosure-safe audit sink.
     * @param  ClockInterface                  $clock             Trusted audit-event clock.
     * @param  StudioMediaOperations           $media             Upload-grant redaction rehydration authority.
     * @param  StudioProducerRequestAuthority  $authority         Trusted evidence for this exact dispatch.
     *
     * @since  2.0.0
     */
    public function __construct(
        private TransactionManager $transactions,
        private TransactionState $transactionState,
        private StudioMutationReplayRepository $replays,
        private StudioMutationOutcomeCodec $outcomes,
        private AuditRecorder $audit,
        private ClockInterface $clock,
        private StudioMediaOperations $media,
        private StudioProducerRequestAuthority $authority,
    ) {
    }

    /**
     * Commit one mutation and its audit, adding protected at-most-once replay only when Producer supplies a key.
     *
     * A call made while a transaction is already open is refused before any authority snapshot, lookup
     * or mutation runs: nesting would silently disable replay-race recovery, so it is a host composition
     * error rather than a scope to join.
     *
     * @param   Operation                           $operation     Closed Producer registry row.
     * @param   RequestEnvelope                     $request       Validated canonical request.
     * @param   string|null                         $scopeKey      Producer's canonical replay scope, or null.
     * @param   string|null                         $intentDigest  Producer's canonical intent digest, or null.
     * @param   callable(): (HostResult|HostError)  $mutation      Validated port mutation body.
     *
     * @return  MutationOutcome  Fresh committed outcome or exact logical replay.
     *
     * @since   2.0.0
     */
    public function execute(
        Operation $operation,
        RequestEnvelope $request,
        ?string $scopeKey,
        ?string $intentDigest,
        callable $mutation,
    ): MutationOutcome {
        if ($this->transactionState->isActive()) {
            StudioProducerError::refuse('internal', 'studio.host/mutation-boundary-nested');
        }
        if (($scopeKey === null) !== ($intentDigest === null)) {
            StudioProducerError::refuse('internal', 'studio.host/mutation-coordinate-invalid');
        }
        $snapshot = $this->authority->snapshot();
        if ($scopeKey === null || $intentDigest === null) {
            $outcome = $this->transactions->transactional(
                fn (): HostResult|HostError => $this->perform($operation, $request, $snapshot, $mutation),
            );

            return new MutationOutcome(null, $outcome);
        }

        $scopeDigest = self::scopeDigest($scopeKey, $snapshot);
        $prior = $this->replays->findReplay($scopeDigest);
        if ($prior !== null) {
            return $this->replay($operation, $snapshot, $prior, $intentDigest);
        }

        try {
            return $this->transactions->transactional(function () use (
                $operation,
                $request,
                $snapshot,
                $mutation,
                $scopeDigest,
                $intentDigest,
            ): MutationOutcome {
                $prior = $this->replays->findReplay($scopeDigest);
                if ($prior !== null) {
                    return $this->replay($operation, $snapshot, $prior, $intentDigest);
                }
                $this->replays->beginReplay(
                    new StudioMutationReplayRecord($scopeDigest, $intentDigest, null),
                    $snapshot,
                    $request->context(),
                );
                $outcome = $this->perform($operation, $request, $snapshot, $mutation);
                $stored = $this->storedOutcome($operation, $outcome);
                $this->replays->completeReplay(
                    $scopeDigest,
                    $this->outcomes->protect($stored, $scopeDigest, $intentDigest),
                );

                return new MutationOutcome($intentDigest, $outcome);
            });
        } catch (StudioMutationReplayRace) {
            $winner = $this->replays->findReplay($scopeDigest);
            if ($winner === null) {
                StudioProducerError::refuse(
                    'unavailable',
                    'studio.host/idempotency-in-progress',
                    retryable: true,
                );
            }

            return $this->replay($operation, $snapshot, $winner, $intentDigest);
        }
    }

    /**
     * Invoke and audit one logical outcome inside the authoritative App transaction.
     *
     * @param   Operation                           $operation  Closed Producer registry row.
     * @param   RequestEnvelope                     $request    Validated canonical request.
     * @param   StudioHostSessionSnapshot           $snapshot   Trusted live App authority snapshot.
     * @param   callable(): (HostResult|HostError)  $mutation   Validated port mutation body.
     *
     * @return  HostResult|HostError  Fresh success or explicitly committed refusal.
     *
     * @since   2.0.0
     */
    private function perform(
        Operation $operation,
        RequestEnvelope $request,
        StudioHostSessionSnapshot $snapshot,
        callable $mutation,
    ): HostResult|HostError {
        $outcome = $mutation();
        $this->audit($operation, $request, $snapshot, $outcome);

        return $outcome;
    }

    /**
     * Prove intent equality, authenticate the stored outcome and rehydrate any ephemeral capability.
     *
     * @param   Operation                   $operation     Closed Producer registry row.
     * @param   StudioHostSessionSnapshot   $snapshot      Current trusted App authority snapshot.
     * @param   StudioMutationReplayRecord  $prior         Existing durable replay claim.
     * @param   string                      $intentDigest  Current Producer intent digest.
     *
     * @return  MutationOutcome  Exact logical replay under the stored intent coordinate.
     *
     * @since   2.0.0
     */
    private function replay(
        Operation $operation,
        StudioHostSessionSnapshot $snapshot,
        StudioMutationReplayRecord $prior,
        string $intentDigest,
    ): MutationOutcome {
        if (!hash_equals($prior->intentDigest, $intentDigest)) {
            StudioProducerError::refuse('invalid-request', 'studio.host/idempotency-intent-changed');
        }
        if ($prior->protectedOutcome === null) {
            StudioProducerError::refuse(
                'unavailable',
                'studio.host/idempotency-in-progress',
                retryable: true,
            );
        }
        try {
            $outcome = $this->outcomes->recover(
                $prior->protectedOutcome,
                $prior->scopeDigest,
                $prior->intentDigest,
            );
            $outcome = $this->rehydratedOutcome($operation, $snapshot, $outcome);
        } catch (StudioMutationOutcomeRejected) {
            StudioProducerError::refuse('internal', 'studio.host/idempotency-corrupt');
        }

        return new MutationOutcome($prior->intentDigest, $outcome);
    }

    /**
     * Remove the live upload capability from the authorize-upload result before durable storage.
     *
     * @param   Operation             $operation  Closed Producer registry row.
     * @param   HostResult|HostError  $outcome    Fresh logical mutation outcome.
     *
     * @return  HostResult|HostError  Storage-safe logical projection.
     *
     * @since   2.0.0
     */
    private function storedOutcome(Operation $operation, HostResult|HostError $outcome): HostResult|HostError
    {
        if (
            $operation->capability !== 'studio.operation/media.authorize-upload'
            || !$outcome instanceof HostResult
        ) {
            return $outcome;
        }
        $headers = $outcome->value instanceof stdClass ? ($outcome->value->headers ?? null) : null;
        if (
            !$outcome->value instanceof stdClass || !$headers instanceof stdClass
            || !is_string($headers->{'X-Studio-Upload-Token'} ?? null)
        ) {
            StudioProducerError::refuse('internal', 'studio.media/idempotency-corrupt');
        }
        $stored = clone $outcome->value;
        $stored->headers = clone $headers;
        unset($stored->headers->{'X-Studio-Upload-Token'});

        return new HostResult($stored, $outcome->revision);
    }

    /**
     * Restore only a verified authorize-upload capability after the protected result authenticates.
     *
     * @param   Operation                  $operation  Closed Producer registry row.
     * @param   StudioHostSessionSnapshot  $snapshot   Current trusted App authority snapshot.
     * @param   HostResult|HostError       $outcome    Authenticated stored logical outcome.
     *
     * @return  HostResult|HostError  Exact replayable outcome with any ephemeral capability restored.
     *
     * @since   2.0.0
     */
    private function rehydratedOutcome(
        Operation $operation,
        StudioHostSessionSnapshot $snapshot,
        HostResult|HostError $outcome,
    ): HostResult|HostError {
        if (
            $operation->capability !== 'studio.operation/media.authorize-upload'
            || !$outcome instanceof HostResult
        ) {
            return $outcome;
        }
        if (!$outcome->value instanceof stdClass) {
            throw new StudioMutationOutcomeRejected('The stored Studio upload grant is invalid.');
        }
        try {
            $restored = $this->media->replayUploadGrant(
                $this->authority->context(),
                $snapshot,
                $outcome->value,
            );
        } catch (\Throwable) {
            throw new StudioMutationOutcomeRejected('The stored Studio upload grant cannot be restored.');
        }

        return new HostResult($restored, $outcome->revision);
    }

    /**
     * Add trusted actor and browser-session scope to Producer's deterministic replay coordinate.
     *
     * @param   string                     $scopeKey  Producer's canonical SRI SHA-256 replay scope.
     * @param   StudioHostSessionSnapshot  $snapshot  Trusted live App authority snapshot.
     *
     * @return  string  Lowercase SHA-256 digest fitting the existing durable scope column.
     *
     * @since   2.0.0
     */
    private static function scopeDigest(string $scopeKey, StudioHostSessionSnapshot $snapshot): string
    {
        return hash('sha256', CanonicalJson::stringify((object) [
            'actorId' => $snapshot->session->actorId,
            'producerScopeKey' => $scopeKey,
            'sessionBinding' => $snapshot->session->sessionBinding,
            'siteId' => $snapshot->session->siteId,
        ]));
    }

    /**
     * Record one disclosure-safe generic mutation event inside the same authoritative transaction.
     *
     * @param   Operation                  $operation  Closed Producer registry row.
     * @param   RequestEnvelope            $request    Current validated request.
     * @param   StudioHostSessionSnapshot  $snapshot   Trusted live App authority snapshot.
     * @param   HostResult|HostError       $outcome    Fresh success or committed refusal.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function audit(
        Operation $operation,
        RequestEnvelope $request,
        StudioHostSessionSnapshot $snapshot,
        HostResult|HostError $outcome,
    ): void {
        $session = $snapshot->session;
        $context = $request->context();
        $resourceDigest = hash('sha256', CanonicalJson::stringify((object) [
            'resourceId' => $session->resourceId,
            'siteId' => $session->siteId,
        ]));
        $metadata = [
            'idempotent' => $context->idempotencyKey !== null,
            'mode' => $session->mode->value,
            'operation_id' => $operation->capability,
            'resource_identity_digest' => $resourceDigest,
            'resource_kind' => $session->resourceKind->value,
            'site_identifier' => $session->siteId,
        ];
        if ($outcome instanceof HostResult && $outcome->revision !== null) {
            $metadata['revision'] = $outcome->revision;
        }
        if ($outcome instanceof HostError) {
            $metadata['refusal_category'] = $outcome->category();
        }
        if ($context->idempotencyKey !== null) {
            $metadata['idempotency_key_digest'] = hash('sha256', $context->idempotencyKey);
        }
        $this->audit->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $this->clock->now(),
            $session->actorId,
            str_replace('studio.operation/', 'studio.', $operation->capability),
            'studio_' . $operation->port,
            $resourceDigest,
            $outcome instanceof HostResult ? 'success' : 'refused',
            $metadata,
        ));
    }
}
