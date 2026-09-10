<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Application\Trust;

use InvalidArgumentException;
use JsonException;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Kernel\Configuration\RevocationFeedConfiguration;
use Kumwe\Context\Value\ExecutionContext;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Consumes an upstream revocation list and turns it into local emergency revocations.
 *
 * Until this existed, revocation was local-only: a key known compromised upstream stayed trusted on
 * every installation until each operator noticed and acted. An operator now pins an issuer's Ed25519
 * public key and an origin, and a scheduled job brings the two together — the list is fetched, verified
 * against the pinned key, checked for freshness and for a sequence strictly newer than the one already
 * applied, and every key it withdraws that is still active here is passed to
 * `TrustStore::emergencyRevoke()`, which quarantines the extensions that depend on it.
 *
 * The two failure modes are deliberately not the same failure.
 *
 * **Integrity fails closed.** A document that is served but does not verify — wrong signature, wrong
 * format, expired freshness window, a sequence at or below the one already applied — is refused
 * outright, recorded with its reason, audited, and reported to the caller as a hard failure. That is
 * the case an attacker actually controls, and treating it as an incident is the only defensible
 * reading.
 *
 * **Availability fails open, loudly.** An origin that cannot be reached leaves the last applied list in
 * force and is recorded as a failure that does not stop the run. Fail-closed here would mean any vendor
 * outage, network partition or dropped packet could take unrelated installations offline, which hands a
 * remote kill switch to whoever can interrupt a connection — a strictly worse exposure than the one the
 * feed mitigates, given the local trust store is already authoritative and already fails closed. The
 * price is that staleness has to be visible, so it is logged as a warning on every run and rendered on
 * the Extensions screen once the configured budget is exceeded.
 *
 * @since  2.0.0
 */
final readonly class RevocationFeedSynchronizer
{
    /**
     * Wire the synchronizer to its feed, its verifier, the trust store it drives and its records.
     *
     * @param  RevocationFeedConfiguration  $configuration  Pinned origin, verification key and staleness
     *         budget; a disabled feed makes every synchronization a no-op.
     * @param  RevocationFeedSource         $source         Transport reading the configured origin.
     * @param  RevocationListVerifier       $verifier       Ed25519 check against the pinned key.
     * @param  RevocationFeedStateStore     $state          Durable sequence and outcome per origin.
     * @param  TrustStore                   $trust          Store the withdrawals are applied through.
     * @param  AuditRecorder                $audit          Sink each applied list and each refusal is
     *         recorded to.
     * @param  ClockInterface               $clock          Clock freshness and staleness are judged by.
     * @param  LoggerInterface              $logger         Sink the stale-feed warning is written to.
     *
     * @since  2.0.0
     */
    public function __construct(
        private RevocationFeedConfiguration $configuration,
        private RevocationFeedSource $source,
        private RevocationListVerifier $verifier,
        private RevocationFeedStateStore $state,
        private TrustStore $trust,
        private AuditRecorder $audit,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Fetch, verify and apply the upstream list once.
     *
     * @param   ExecutionContext  $context  Trusted context the revocations are authorized and audited
     *          under; the scheduled job supplies the extension-materializer identity.
     *
     * @return  list<string>  Key identifiers withdrawn by this run; empty when nothing was newly
     *          revoked, when the list was already applied, or when the origin was unreachable.
     *
     * @throws  RevocationListRefused  When a served document fails verification, freshness or the
     *          sequence check; an unreachable origin deliberately does not raise.
     *
     * @since   2.0.0
     */
    public function synchronize(ExecutionContext $context): array
    {
        $origin = $this->configuration->origin;
        $publicKey = $this->configuration->publicKeyBase64;
        if ($origin === null || $publicKey === null) {
            return [];
        }
        $now = $this->clock->now();
        try {
            $payload = $this->source->fetch($origin);
        } catch (Throwable $failure) {
            $this->state->recordFailure($origin, $now, 'unreachable: ' . $failure->getMessage());
            $this->warnAboutStaleness($origin, $failure->getMessage());

            return [];
        }

        try {
            $list = RevocationList::fromEnvelope($payload);
        } catch (InvalidArgumentException | JsonException $failure) {
            throw $this->refuse($origin, $context, $now, 'malformed: ' . $failure->getMessage());
        }
        if (!$this->verifier->verify($publicKey, $list->signedBytes, $list->signatureBase64)) {
            throw $this->refuse($origin, $context, $now, 'signature did not verify against the pinned feed key');
        }
        if (!$list->isCurrentAt($now)) {
            throw $this->refuse($origin, $context, $now, 'the list passed its valid_until instant');
        }
        $recorded = $this->state->read($origin, $this->configuration->maxStaleSeconds);
        if ($list->sequence < $recorded->appliedSequence) {
            throw $this->refuse($origin, $context, $now, sprintf(
                'sequence %d is older than the applied sequence %d',
                $list->sequence,
                $recorded->appliedSequence,
            ));
        }
        if ($list->sequence === $recorded->appliedSequence) {
            $this->state->recordSuccess($origin, $list, $now, $recorded->revokedKeyCount);

            return [];
        }

        $withdrawn = $this->apply($list, $context);
        $this->state->recordSuccess($origin, $list, $now, count($list->revokedKeyIds()));
        $this->record($context, 'extension.trust_key.revocation_feed.apply', [
            'origin' => $origin,
            'issuer' => $list->issuer,
            'sequence' => $list->sequence,
            'document_sha256' => $list->documentSha256,
            'withdrawn_keys' => $withdrawn,
        ], 'applied');

        return $withdrawn;
    }

    /**
     * Report the feed's current position and freshness for the operator surface.
     *
     * @return  RevocationFeedState  The recorded state, or an unconfigured one when no feed is set up.
     *
     * @since   2.0.0
     */
    public function state(): RevocationFeedState
    {
        $origin = $this->configuration->origin;
        if ($origin === null) {
            return RevocationFeedState::unconfigured();
        }

        return $this->state->read($origin, $this->configuration->maxStaleSeconds);
    }

    /**
     * Withdraw every key the list names that this installation still trusts.
     *
     * Keys the installation never held, or already revoked, are skipped rather than treated as an
     * error: an upstream list describes the issuer's whole population, not this installation's, and a
     * run that failed because someone else's key was named would stop the ones that matter from landing.
     *
     * @param   RevocationList    $list     The verified, newer list being applied.
     * @param   ExecutionContext  $context  Context the revocations are authorized and audited under.
     *
     * @return  list<string>  Identifiers actually withdrawn by this run.
     *
     * @since   2.0.0
     */
    private function apply(RevocationList $list, ExecutionContext $context): array
    {
        $active = [];
        foreach ($this->trust->keys($context) as $key) {
            $keyId = $key['key_id'] ?? null;
            $revokedAt = $key['revoked_at'] ?? null;
            if (is_string($keyId) && $revokedAt === null) {
                $active[$keyId] = true;
            }
        }
        $withdrawn = [];
        foreach ($list->revokedKeyIds() as $keyId) {
            if (!isset($active[$keyId])) {
                continue;
            }
            $reason = $list->reasonFor($keyId) ?? 'Withdrawn by the upstream revocation feed.';
            $this->trust->emergencyRevoke($context, $keyId, sprintf(
                'Upstream revocation %s#%d: %s',
                $list->issuer,
                $list->sequence,
                $reason,
            ));
            $withdrawn[] = $keyId;
        }

        return $withdrawn;
    }

    /**
     * Record and raise the refusal of a served document that failed an integrity check.
     *
     * @param   string              $origin   Configured feed origin.
     * @param   ExecutionContext    $context  Context the refusal is audited under.
     * @param   \DateTimeImmutable  $now      Instant recorded on the failure.
     * @param   string              $reason   Why the document was refused.
     *
     * @return  RevocationListRefused  The exception the caller must throw.
     *
     * @since   2.0.0
     */
    private function refuse(
        string $origin,
        ExecutionContext $context,
        \DateTimeImmutable $now,
        string $reason,
    ): RevocationListRefused {
        $this->state->recordFailure($origin, $now, 'refused: ' . $reason);
        $this->record($context, 'extension.trust_key.revocation_feed.refuse', [
            'origin' => $origin,
            'reason' => substr($reason, 0, 500),
        ], 'rejected');

        return new RevocationListRefused(sprintf('The upstream revocation list was refused: %s.', $reason));
    }

    /**
     * Log that the feed could not be reached, at the severity its staleness has earned.
     *
     * Below the budget this is an information line, because a single missed fetch is ordinary. Past it
     * the same condition becomes a warning naming how long the installation has been running without a
     * confirmed upstream list, which is the signal an operator is expected to act on.
     *
     * @param   string  $origin  Configured feed origin.
     * @param   string  $reason  Transport failure as reported by the source.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function warnAboutStaleness(string $origin, string $reason): void
    {
        $now = $this->clock->now();
        $state = $this->state->read($origin, $this->configuration->maxStaleSeconds);
        $message = 'The extension revocation feed could not be reached; the last applied list stays in force.';
        $fields = [
            'origin' => $origin,
            'reason' => $reason,
            'applied_sequence' => $state->appliedSequence,
            'last_success_at' => $state->lastSuccessAt?->format(DATE_ATOM),
            'consecutive_failures' => $state->consecutiveFailures,
        ];
        if ($state->isStale($now)) {
            $this->logger->warning($message . ' It is now stale.', $fields);

            return;
        }
        $this->logger->info($message, $fields);
    }

    /**
     * Write one audit record for a feed outcome, never letting the sink fail the run it describes.
     *
     * @param   ExecutionContext      $context   Actor and provenance the record is written under.
     * @param   string                $action    Machine action token.
     * @param   array<string, mixed>  $metadata  Fields recorded alongside the action.
     * @param   string                $outcome   Outcome token stored on the record.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function record(ExecutionContext $context, string $action, array $metadata, string $outcome): void
    {
        try {
            $this->audit->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $this->clock->now(),
                $context->actorId(),
                $action,
                'extension_trust_key',
                'revocation-feed',
                $outcome,
                $metadata,
            ));
        } catch (Throwable $failure) {
            $this->logger->warning('The revocation feed outcome could not be audited.', [
                'action' => $action,
                'reason' => $failure->getMessage(),
            ]);
        }
    }
}
