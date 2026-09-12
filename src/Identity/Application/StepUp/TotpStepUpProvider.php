<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Application\StepUp;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Identity\Domain\StepUp\RotatedStepUpSession;
use Kumwe\App\Identity\Domain\StepUp\StepUpEnrollmentCompletion;
use Kumwe\App\Identity\Domain\StepUp\StepUpEnrollmentSetup;
use Kumwe\App\Identity\Domain\StepUp\StepUpIntent;
use Kumwe\App\Identity\Domain\StepUp\StepUpMethod;
use Kumwe\App\Identity\Domain\StepUp\StepUpVerification;
use Kumwe\App\Identity\Domain\StepUp\TotpCredential;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * Production TOTP provider with encrypted enrollment, replay fences, recovery, throttling, and rotation.
 *
 * The provider never persists a plaintext authenticator secret or recovery code. A TOTP counter is
 * accepted only by a versioned monotonic update, and recovery succeeds only by consuming one keyed
 * digest. Both paths rotate the browser session in the same transaction before returning a five-minute,
 * context-bound verification result.
 *
 * @since  2.0.0
 */
final readonly class TotpStepUpProvider implements StepUpProvider, AdministratorStepUpProvider
{
    /**
     * Number of 128-bit recovery codes generated at confirmation.
     *
     * @var    int
     * @since  2.0.0
     */
    private const RECOVERY_CODE_COUNT = 10;

    /**
     * Bind the provider to its cryptographic, persistence, transaction, audit, and session boundaries.
     *
     * @param  StepUpCredentialStore     $credentials   Encrypted credential and digest store.
     * @param  StepUpSecretCipher        $cipher        Authenticated encryption for TOTP secrets.
     * @param  StepUpRecoveryCodeHasher  $recovery      Keyed recovery-code digest implementation.
     * @param  StepUpRandomSource        $random        CSPRNG and UUID source.
     * @param  TotpAlgorithm             $totp          RFC 6238 implementation.
     * @param  StepUpAttemptThrottle     $throttle      Distributed, fail-closed attempt budget.
     * @param  StepUpSessionRotator      $sessions      Transaction-participating browser session rotator.
     * @param  StepUpProofStore          $proofs        Durable single-use proof replay fence.
     * @param  TransactionManager        $transactions  Shared transaction coordinator.
     * @param  AuditRecorder             $audit         Durable security event sink.
     * @param  ClockInterface            $clock         Trusted UTC time source.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StepUpCredentialStore $credentials,
        private StepUpSecretCipher $cipher,
        private StepUpRecoveryCodeHasher $recovery,
        private StepUpRandomSource $random,
        private TotpAlgorithm $totp,
        private StepUpAttemptThrottle $throttle,
        private StepUpSessionRotator $sessions,
        private StepUpProofStore $proofs,
        private TransactionManager $transactions,
        private AuditRecorder $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Create a ten-minute pending enrollment and return the plaintext provisioning secret once.
     *
     * @param   string  $subjectId     Authenticated actor UUID.
     * @param   string  $issuer        Authenticator issuer label, 1 through 80 printable characters.
     * @param   string  $accountLabel  Account label, 1 through 191 printable characters.
     *
     * @return  StepUpEnrollmentSetup  Pending UUID, Base32 secret, provisioning URI, and expiry.
     *
     * @throws  InvalidArgumentException  When labels or subject are invalid or an active credential exists.
     *
     * @since   2.0.0
     */
    public function beginEnrollment(string $subjectId, string $issuer, string $accountLabel): StepUpEnrollmentSetup
    {
        self::assertSubject($subjectId);
        $issuer = self::label($issuer, 80, 'issuer');
        $accountLabel = self::label($accountLabel, 191, 'account');
        if ($this->credentials->active($subjectId) !== null) {
            throw new InvalidArgumentException('The actor already has an active TOTP credential.');
        }

        $now = $this->clock->now();
        $expiresAt = $now->modify('+10 minutes');
        $credentialId = $this->random->uuid();
        $secret = $this->random->bytes(20);
        $encoded = $this->totp->encodeSecret($secret);
        $credential = new TotpCredential(
            $credentialId,
            $subjectId,
            $this->cipher->encrypt($secret, self::associatedData($credentialId, $subjectId)),
            false,
            $now,
            $expiresAt,
            null,
            null,
            1,
        );

        $this->transactions->transactional(function () use ($credential, $now): void {
            $this->credentials->replacePending($credential);
            $this->audit->record(new AuditEvent(
                $this->random->uuid(),
                $now,
                $credential->subjectId,
                'identity.step_up.enrollment.begin',
                'step_up_credential',
                $credential->id,
                'success',
                ['expires_at' => $credential->enrollmentExpiresAt?->format(DATE_ATOM)],
            ));
        });

        $uri = 'otpauth://totp/' . rawurlencode($issuer . ':' . $accountLabel) . '?'
            . http_build_query([
                'secret' => $encoded,
                'issuer' => $issuer,
                'algorithm' => 'SHA1',
                'digits' => '6',
                'period' => '30',
            ], '', '&', PHP_QUERY_RFC3986);

        return new StepUpEnrollmentSetup($credentialId, $encoded, $uri, $expiresAt);
    }

    /**
     * Confirm a pending secret with a live TOTP and install ten recovery-code digests atomically.
     *
     * @param   StepUpIntent  $intent        Server-resolved context to bind the proof to.
     * @param   string        $enrollmentId  Pending credential UUID.
     * @param   string        $code          Authenticator TOTP candidate.
     * @param   string        $source        Trusted-proxy-resolved source.
     *
     * @return  StepUpEnrollmentCompletion  Fresh verification and plaintext recovery codes shown once.
     *
     * @throws  StepUpRejected  When any credential, expiry, code, or compare-and-swap check fails.
     *
     * @since   2.0.0
     */
    public function confirmEnrollment(
        StepUpIntent $intent,
        string $enrollmentId,
        string $code,
        string $source,
    ): StepUpEnrollmentCompletion {
        return $this->attempt($intent, $source, 'totp-enrollment', function () use (
            $intent,
            $enrollmentId,
            $code,
        ): StepUpEnrollmentCompletion {
            $now = $this->clock->now();
            $credential = $this->credentials->pending($enrollmentId, $intent->subjectId);
            if (!$credential instanceof TotpCredential || !$credential->mayConfirmAt($now)) {
                throw new StepUpRejected();
            }
            $timeStep = $this->totp->verify($this->secret($credential), $code, $now);
            if ($timeStep === null) {
                throw new StepUpRejected();
            }
            [$plainCodes, $digests] = $this->recoveryCodes();

            $verification = $this->transactions->transactional(function () use (
                $credential,
                $intent,
                $timeStep,
                $digests,
                $now,
            ): StepUpVerification {
                if (
                    !$this->credentials->activate(
                        $credential->id,
                        $intent->subjectId,
                        $credential->version,
                        $timeStep,
                        $digests,
                        $now,
                    )
                ) {
                    throw new StepUpRejected();
                }
                $rotated = $this->sessions->rotate($intent, $now);
                $verification = $this->verification(
                    $intent,
                    $credential->id,
                    StepUpMethod::Totp,
                    $now,
                    $rotated,
                );
                $this->proofs->issue($verification);
                $this->recordSuccess($intent, $credential->id, StepUpMethod::Totp, $now, 'enrollment.confirm');

                return $verification;
            });

            return new StepUpEnrollmentCompletion($verification, $plainCodes);
        });
    }

    /**
     * Accept a never-before-used TOTP counter and rotate the challenged session atomically.
     *
     * @param   StepUpIntent  $intent  Server-resolved context to bind the proof to.
     * @param   string        $code    Authenticator TOTP candidate.
     * @param   string        $source  Trusted-proxy-resolved source.
     *
     * @return  StepUpVerification  Fresh context-bound result.
     *
     * @throws  StepUpRejected  When the credential or code is absent, invalid, or replayed.
     *
     * @since   2.0.0
     */
    public function challenge(StepUpIntent $intent, string $code, string $source): StepUpVerification
    {
        return $this->attempt($intent, $source, $this->throttlePurpose($intent->purpose), function () use (
            $intent,
            $code,
        ): StepUpVerification {
            $now = $this->clock->now();
            $credential = $this->active($intent->subjectId);
            $timeStep = $this->totp->verify($this->secret($credential), $code, $now);
            if ($timeStep === null) {
                throw new StepUpRejected();
            }

            return $this->transactions->transactional(function () use (
                $credential,
                $intent,
                $timeStep,
                $now,
            ): StepUpVerification {
                if (
                    !$this->credentials->acceptTimeStep(
                        $credential->id,
                        $credential->version,
                        $timeStep,
                        $now,
                    )
                ) {
                    throw new StepUpRejected();
                }
                $rotated = $this->sessions->rotate($intent, $now);
                $verification = $this->verification(
                    $intent,
                    $credential->id,
                    StepUpMethod::Totp,
                    $now,
                    $rotated,
                );
                $this->proofs->issue($verification);
                $this->recordSuccess($intent, $credential->id, StepUpMethod::Totp, $now, 'challenge');

                return $verification;
            });
        });
    }

    /**
     * Consume one keyed recovery digest and rotate the challenged session atomically.
     *
     * @param   StepUpIntent  $intent        Server-resolved context to bind the proof to.
     * @param   string        $recoveryCode  One-time code as displayed during enrollment.
     * @param   string        $source        Trusted-proxy-resolved source.
     *
     * @return  StepUpVerification  Fresh context-bound recovery result.
     *
     * @throws  StepUpRejected  When the credential or code is absent, malformed, spent, or raced.
     *
     * @since   2.0.0
     */
    public function recover(StepUpIntent $intent, string $recoveryCode, string $source): StepUpVerification
    {
        $throttlePurpose = $this->throttlePurpose($intent->purpose) . ':recovery';
        return $this->attempt($intent, $source, $throttlePurpose, function () use (
            $intent,
            $recoveryCode,
        ): StepUpVerification {
            $now = $this->clock->now();
            $credential = $this->active($intent->subjectId);
            $normalized = strtolower(str_replace('-', '', trim($recoveryCode)));
            if (preg_match('/^[0-9a-f]{32}$/D', $normalized) !== 1) {
                throw new StepUpRejected();
            }
            $digest = $this->recovery->digest($normalized);

            return $this->transactions->transactional(function () use (
                $credential,
                $intent,
                $digest,
                $now,
            ): StepUpVerification {
                if (
                    !$this->credentials->consumeRecoveryCode(
                        $credential->id,
                        $credential->version,
                        $digest,
                        $now,
                    )
                ) {
                    throw new StepUpRejected();
                }
                $rotated = $this->sessions->rotate($intent, $now);
                $verification = $this->verification(
                    $intent,
                    $credential->id,
                    StepUpMethod::RecoveryCode,
                    $now,
                    $rotated,
                );
                $this->proofs->issue($verification);
                $this->recordSuccess($intent, $credential->id, StepUpMethod::RecoveryCode, $now, 'recovery');

                return $verification;
            });
        });
    }

    /**
     * Replace the subject's whole recovery-code set and disclose the new codes exactly once.
     *
     * No credential is presented here and none is checked, because the check has already happened: the
     * administrator surface reaches this only inside the transaction of a successful, payload-bound
     * TOTP challenge whose proof it consumed, so possession of the authenticator is already proven and
     * asking for it twice would only add a second oracle. The version the active credential was read at
     * fences the write, so a challenge landing concurrently makes this fail rather than resurrect codes
     * the other request's consumption just spent.
     *
     * Only the digests reach storage; the plaintext exists in this method's return value and nowhere
     * else, and is never audited, logged or stored.
     *
     * @param   string  $subjectId  Authenticated actor whose active credential is reissued against.
     *
     * @return  list<string>  Plaintext recovery codes, shown once and unrecoverable afterwards.
     *
     * @throws  StepUpRejected  When the subject has no active credential, or the credential advanced
     *          under a concurrent challenge before the replacement could land.
     * @throws  \RuntimeException  When a broken random source cannot produce ten unique codes.
     *
     * @since   2.0.0
     */
    public function reissueRecoveryCodes(string $subjectId): array
    {
        self::assertSubject($subjectId);
        $credential = $this->active($subjectId);
        [$plainCodes, $digests] = $this->recoveryCodes();
        $now = $this->clock->now();

        return $this->transactions->transactional(function () use (
            $credential,
            $subjectId,
            $digests,
            $plainCodes,
            $now,
        ): array {
            if (
                !$this->credentials->replaceRecoveryCodes(
                    $credential->id,
                    $subjectId,
                    $credential->version,
                    $digests,
                    $now,
                )
            ) {
                throw new StepUpRejected();
            }
            $this->audit->record(new AuditEvent(
                $this->random->uuid(),
                $now,
                $subjectId,
                'identity.step_up.recovery.reissue',
                'step_up_credential',
                $credential->id,
                'success',
                ['issued_codes' => count($plainCodes)],
            ));

            return $plainCodes;
        });
    }

    /**
     * Apply the shared failure budget and make its successful reset part of the outer write transaction.
     *
     * @template T
     *
     * @param   StepUpIntent   $intent     Attempted actor and context.
     * @param   string         $source     Trusted origin.
     * @param   string         $purpose    Budget partition.
     * @param   callable(): T  $operation  Credential work to attempt.
     *
     * @return  T  Operation result.
     *
     * @throws  Throwable  Whatever the operation or throttle raises.
     *
     * @since   2.0.0
     */
    private function attempt(
        StepUpIntent $intent,
        string $source,
        string $purpose,
        callable $operation,
    ): mixed {
        $this->throttle->assertAllowed($intent->subjectId, $source, $purpose);

        return $this->transactions->transactional(function () use (
            $intent,
            $source,
            $purpose,
            $operation,
        ): mixed {
            try {
                $result = $operation();
            } catch (Throwable $exception) {
                $this->throttle->record($intent->subjectId, $source, $purpose, false);
                throw $exception;
            }
            $this->throttle->record($intent->subjectId, $source, $purpose, true);

            return $result;
        });
    }

    /**
     * Keep payload-bound proofs inside one action-level failure budget.
     *
     * Access-control purposes end in a canonical SHA-256 payload binding. Removing only that controlled
     * suffix for throttling prevents an attacker varying harmless form fields to obtain a fresh attempt
     * budget, while the issued proof retains the full exact-purpose binding.
     *
     * @param   string  $purpose  Exact proof purpose, optionally ending in `.payload.` and a SHA-256 digest.
     *
     * @return  string  Stable action-level throttle partition.
     *
     * @since   2.0.0
     */
    private function throttlePurpose(string $purpose): string
    {
        return preg_replace('/\.payload\.[a-f0-9]{64}$/D', '', $purpose) ?? $purpose;
    }

    /**
     * Load an active credential without revealing whether setup ever occurred.
     *
     * @param   string  $subjectId  Authenticated actor UUID.
     *
     * @return  TotpCredential  Active encrypted credential.
     *
     * @throws  StepUpRejected  When no active credential exists.
     *
     * @since   2.0.0
     */
    private function active(string $subjectId): TotpCredential
    {
        $credential = $this->credentials->active($subjectId);
        if (!$credential instanceof TotpCredential) {
            throw new StepUpRejected();
        }

        return $credential;
    }

    /**
     * Decrypt one credential using its immutable identity binding.
     *
     * @param   TotpCredential  $credential  Stored credential.
     *
     * @return  string  Raw TOTP secret held only for the calculation.
     *
     * @since   2.0.0
     */
    private function secret(TotpCredential $credential): string
    {
        return $this->cipher->decrypt(
            $credential->encryptedSecret,
            self::associatedData($credential->id, $credential->subjectId),
        );
    }

    /**
     * Generate ten unique 128-bit recovery codes and only their keyed stored digests.
     *
     * @return  array{list<string>, list<string>}  Display forms followed by stored digests.
     *
     * @throws  \RuntimeException  When a broken random source cannot produce ten unique values.
     *
     * @since   2.0.0
     */
    private function recoveryCodes(): array
    {
        $plain = [];
        $digests = [];
        for ($attempt = 0; count($plain) < self::RECOVERY_CODE_COUNT && $attempt < 100; ++$attempt) {
            $normalized = bin2hex($this->random->bytes(16));
            $digest = $this->recovery->digest($normalized);
            if (isset($digests[$digest])) {
                continue;
            }
            $digests[$digest] = true;
            $plain[] = implode('-', str_split($normalized, 4));
        }
        if (count($plain) !== self::RECOVERY_CODE_COUNT) {
            throw new \RuntimeException('The random source did not produce unique recovery codes.');
        }

        return [$plain, array_keys($digests)];
    }

    /**
     * Create a five-minute result bound to the replacement session and original intent.
     *
     * @param   StepUpIntent          $intent        Challenged context.
     * @param   string                $credentialId  Credential UUID.
     * @param   StepUpMethod          $method        Accepted credential kind.
     * @param   DateTimeImmutable     $now           Verification instant.
     * @param   RotatedStepUpSession  $rotated       Replacement session.
     *
     * @return  StepUpVerification  Fresh proof adapter input.
     *
     * @since   2.0.0
     */
    private function verification(
        StepUpIntent $intent,
        string $credentialId,
        StepUpMethod $method,
        DateTimeImmutable $now,
        RotatedStepUpSession $rotated,
    ): StepUpVerification {
        $nonce = rtrim(strtr(base64_encode($this->random->bytes(32)), '+/', '-_'), '=');

        return new StepUpVerification(
            $intent,
            $credentialId,
            $method,
            $now,
            $now->modify('+5 minutes'),
            $nonce,
            $rotated,
        );
    }

    /**
     * Record a successful enrollment or challenge without credential material.
     *
     * @param   StepUpIntent       $intent        Actor and resolved scope.
     * @param   string             $credentialId  Credential UUID.
     * @param   StepUpMethod       $method        Accepted method.
     * @param   DateTimeImmutable  $now           Verification instant.
     * @param   string             $action        Event suffix.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function recordSuccess(
        StepUpIntent $intent,
        string $credentialId,
        StepUpMethod $method,
        DateTimeImmutable $now,
        string $action,
    ): void {
        $this->audit->record(new AuditEvent(
            $this->random->uuid(),
            $now,
            $intent->subjectId,
            'identity.step_up.' . $action,
            'step_up_credential',
            $credentialId,
            'success',
            [
                'method' => $method->value,
                'site' => $intent->siteIdentifier,
                'organization' => $intent->organizationIdentifier,
                'workspace' => $intent->workspaceIdentifier,
                'purpose' => $intent->purpose,
                'security_epoch' => $intent->securityEpoch,
            ],
        ));
    }

    /**
     * Build associated data that prevents moving ciphertext between actors or credential rows.
     *
     * @param   string  $credentialId  Credential UUID.
     * @param   string  $subjectId     Actor UUID.
     *
     * @return  string  Stable versioned binding.
     *
     * @since   2.0.0
     */
    private static function associatedData(string $credentialId, string $subjectId): string
    {
        return "kumwe-step-up-v1\0" . strtolower($credentialId) . "\0" . strtolower($subjectId);
    }

    /**
     * Validate an actor UUID before touching storage.
     *
     * @param   string  $subjectId  Candidate actor UUID.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the identifier is not canonical.
     *
     * @since   2.0.0
     */
    private static function assertSubject(string $subjectId): void
    {
        $uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}'
            . '-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di';
        if (preg_match($uuid, $subjectId) !== 1) {
            throw new InvalidArgumentException('A step-up actor identifier must be a canonical UUID.');
        }
    }

    /**
     * Normalize a printable authenticator label within its bound.
     *
     * @param   string  $value    Candidate label.
     * @param   int     $maximum  Maximum Unicode characters.
     * @param   string  $field    Field named in a rejection.
     *
     * @return  string  Trimmed label.
     *
     * @throws  InvalidArgumentException  When empty, oversized, or control-bearing.
     *
     * @since   2.0.0
     */
    private static function label(string $value, int $maximum, string $field): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $maximum || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException(sprintf('The TOTP %s label is invalid.', $field));
        }

        return $value;
    }
}
