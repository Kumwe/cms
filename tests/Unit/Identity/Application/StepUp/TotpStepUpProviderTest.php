<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Identity\Application\StepUp;

use DateTimeImmutable;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Identity\Application\StepUp\StepUpAttemptThrottle;
use Kumwe\App\Identity\Application\StepUp\StepUpCredentialStore;
use Kumwe\App\Identity\Application\StepUp\StepUpRandomSource;
use Kumwe\App\Identity\Application\StepUp\StepUpRejected;
use Kumwe\App\Identity\Application\StepUp\StepUpProofStore;
use Kumwe\App\Identity\Application\StepUp\StepUpSessionRotator;
use Kumwe\App\Identity\Application\StepUp\TotpAlgorithm;
use Kumwe\App\Identity\Application\StepUp\TotpStepUpProvider;
use Kumwe\App\Identity\Domain\StepUp\RotatedStepUpSession;
use Kumwe\App\Identity\Domain\StepUp\StepUpIntent;
use Kumwe\App\Identity\Domain\StepUp\StepUpMethod;
use Kumwe\App\Identity\Domain\StepUp\TotpCredential;
use Kumwe\App\Identity\Infrastructure\StepUp\SodiumStepUpRecoveryCodeHasher;
use Kumwe\App\Identity\Infrastructure\StepUp\SodiumStepUpSecretCipher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(TotpStepUpProvider::class)]
final class TotpStepUpProviderTest extends TestCase
{
    private const SUBJECT = '018f0000-0000-7000-8000-000000000001';
    private const SESSION = '018f0000-0000-7000-8000-000000000002';

    public function testEnrollmentReplayFenceRecoveryConsumptionAndSessionBinding(): void
    {
        $clock = new MutableStepUpClock(new DateTimeImmutable('2026-08-09T10:00:00+00:00'));
        $store = new MemoryStepUpCredentialStore();
        $throttle = new RecordingStepUpThrottle();
        $sessions = new RecordingStepUpSessionRotator();
        $audit = new RecordingStepUpAudit();
        $provider = new TotpStepUpProvider(
            $store,
            new SodiumStepUpSecretCipher(str_repeat('e', 32)),
            new SodiumStepUpRecoveryCodeHasher(str_repeat('h', 32)),
            new DeterministicStepUpRandom(),
            new TimeStepTestAlgorithm(),
            $throttle,
            $sessions,
            new RecordingStepUpProofStore(),
            new ImmediateStepUpTransactions(),
            $audit,
            $clock,
        );

        $setup = $provider->beginEnrollment(self::SUBJECT, 'Kumwe', 'user@example.test');
        self::assertStringStartsWith('otpauth://totp/', $setup->provisioningUri);
        self::assertStringStartsWith('v1.', $store->credential?->encryptedSecret ?? '');
        self::assertStringNotContainsString($setup->secret, $store->credential?->encryptedSecret ?? '');

        $intent = $this->intent(self::SESSION);
        $completed = $provider->confirmEnrollment($intent, $setup->enrollmentId, '123456', '192.0.2.5');
        self::assertCount(10, $completed->recoveryCodes);
        self::assertCount(10, array_unique($completed->recoveryCodes));
        self::assertTrue($completed->verification->isFreshFor(
            $clock->now(),
            $completed->verification->rotatedSession->sessionId,
            'records.approve',
            'site-a',
            'org-a',
            'workspace-a',
            7,
        ));
        self::assertFalse($completed->verification->isFreshFor(
            $clock->now(),
            self::SESSION,
            'records.approve',
            'site-a',
            'org-a',
            'workspace-a',
            7,
        ));

        try {
            $provider->challenge(
                $this->intent($completed->verification->rotatedSession->sessionId),
                '123456',
                '192.0.2.5',
            );
            self::fail('The enrollment time-step must not be accepted twice.');
        } catch (StepUpRejected) {
            self::assertFalse($throttle->results[array_key_last($throttle->results)]);
        }

        $clock->advance('+30 seconds');
        $challenged = $provider->challenge(
            $this->intent($completed->verification->rotatedSession->sessionId),
            '123456',
            '192.0.2.5',
        );
        self::assertSame(StepUpMethod::Totp, $challenged->method);

        $recovered = $provider->recover(
            $this->intent($challenged->rotatedSession->sessionId),
            $completed->recoveryCodes[0],
            '192.0.2.5',
        );
        self::assertSame(StepUpMethod::RecoveryCode, $recovered->method);

        $this->expectException(StepUpRejected::class);
        try {
            $provider->recover(
                $this->intent($recovered->rotatedSession->sessionId),
                $completed->recoveryCodes[0],
                '192.0.2.5',
            );
        } finally {
            self::assertCount(4, $audit->events);
            self::assertSame([true, false, true, true, false], $throttle->results);
        }
    }

    /**
     * Proves a reissue replaces every recovery code, retires the old ones and audits without material.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRecoveryCodeReissueReplacesTheWholeSetAndRetiresTheOldCodes(): void
    {
        $clock = new MutableStepUpClock(new DateTimeImmutable('2026-08-09T10:00:00+00:00'));
        $store = new MemoryStepUpCredentialStore();
        $audit = new RecordingStepUpAudit();
        $provider = new TotpStepUpProvider(
            $store,
            new SodiumStepUpSecretCipher(str_repeat('e', 32)),
            new SodiumStepUpRecoveryCodeHasher(str_repeat('h', 32)),
            new DeterministicStepUpRandom(),
            new TimeStepTestAlgorithm(),
            new RecordingStepUpThrottle(),
            new RecordingStepUpSessionRotator(),
            new RecordingStepUpProofStore(),
            new ImmediateStepUpTransactions(),
            $audit,
            $clock,
        );
        $setup = $provider->beginEnrollment(self::SUBJECT, 'Kumwe', 'user@example.test');
        $completed = $provider->confirmEnrollment($this->intent(self::SESSION), $setup->enrollmentId, '123456', '');
        $originalDigests = $store->recoveryDigests();

        $reissued = $provider->reissueRecoveryCodes(self::SUBJECT);

        self::assertCount(10, $reissued);
        self::assertCount(10, array_unique($reissued));
        self::assertSame([], array_intersect($completed->recoveryCodes, $reissued));
        self::assertSame([], array_intersect($originalDigests, $store->recoveryDigests()));
        $event = $audit->events[array_key_last($audit->events)];
        self::assertSame('identity.step_up.recovery.reissue', $event->action());
        self::assertSame(['issued_codes' => 10], $event->metadata());
        foreach ($reissued as $code) {
            self::assertStringNotContainsString($code, json_encode($event->metadata(), JSON_THROW_ON_ERROR));
        }
    }

    /**
     * Proves a subject with no active credential cannot obtain a fresh recovery-code set.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRecoveryCodeReissueRefusesASubjectWithNoActiveCredential(): void
    {
        $provider = new TotpStepUpProvider(
            new MemoryStepUpCredentialStore(),
            new SodiumStepUpSecretCipher(str_repeat('e', 32)),
            new SodiumStepUpRecoveryCodeHasher(str_repeat('h', 32)),
            new DeterministicStepUpRandom(),
            new TimeStepTestAlgorithm(),
            new RecordingStepUpThrottle(),
            new RecordingStepUpSessionRotator(),
            new RecordingStepUpProofStore(),
            new ImmediateStepUpTransactions(),
            new RecordingStepUpAudit(),
            new MutableStepUpClock(new DateTimeImmutable('2026-08-09T10:00:00+00:00')),
        );

        $this->expectException(StepUpRejected::class);

        $provider->reissueRecoveryCodes(self::SUBJECT);
    }

    /**
     * Keeps payload-specific proofs exact while throttling all payloads under their shared action.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPayloadBoundPurposesShareActionThrottlePartition(): void
    {
        $clock = new MutableStepUpClock(new DateTimeImmutable('2026-08-09T10:00:00+00:00'));
        $throttle = new RecordingStepUpThrottle();
        $provider = new TotpStepUpProvider(
            new MemoryStepUpCredentialStore(),
            new SodiumStepUpSecretCipher(str_repeat('e', 32)),
            new SodiumStepUpRecoveryCodeHasher(str_repeat('h', 32)),
            new DeterministicStepUpRandom(),
            new TimeStepTestAlgorithm(),
            $throttle,
            new RecordingStepUpSessionRotator(),
            new RecordingStepUpProofStore(),
            new ImmediateStepUpTransactions(),
            new RecordingStepUpAudit(),
            $clock,
        );
        $setup = $provider->beginEnrollment(self::SUBJECT, 'Kumwe', 'user@example.test');
        $completed = $provider->confirmEnrollment(
            $this->intent(self::SESSION),
            $setup->enrollmentId,
            '123456',
            '192.0.2.5',
        );
        $base = 'identity.access_control.grant.synchronize';
        $firstPurpose = $base . '.payload.' . str_repeat('a', 64);
        $secondPurpose = $base . '.payload.' . str_repeat('b', 64);

        $clock->advance('+30 seconds');
        $first = $provider->challenge(
            $this->intent($completed->verification->rotatedSession->sessionId, $firstPurpose),
            '123456',
            '192.0.2.5',
        );
        $clock->advance('+30 seconds');
        $second = $provider->challenge(
            $this->intent($first->rotatedSession->sessionId, $secondPurpose),
            '123456',
            '192.0.2.5',
        );

        self::assertSame($firstPurpose, $first->intent->purpose);
        self::assertSame($secondPurpose, $second->intent->purpose);
        self::assertSame([$base, $base], array_slice($throttle->purposes, -2));
    }

    private function intent(string $sessionId, string $purpose = 'records.approve'): StepUpIntent
    {
        return new StepUpIntent(
            self::SUBJECT,
            $sessionId,
            'site-a',
            'org-a',
            'workspace-a',
            $purpose,
            7,
        );
    }
}

final class MutableStepUpClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $value)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }

    public function advance(string $modifier): void
    {
        $this->value = $this->value->modify($modifier);
    }
}

final class MemoryStepUpCredentialStore implements StepUpCredentialStore
{
    public ?TotpCredential $credential = null;

    /** @var array<string, true> */
    private array $recovery = [];

    public function replacePending(TotpCredential $credential): void
    {
        if ($this->credential?->active === true) {
            throw new \InvalidArgumentException('active');
        }
        $this->credential = $credential;
    }

    public function pending(string $credentialId, string $subjectId): ?TotpCredential
    {
        return $this->credential?->id === $credentialId
            && $this->credential->subjectId === $subjectId
            && !$this->credential->active ? $this->credential : null;
    }

    public function active(string $subjectId): ?TotpCredential
    {
        return $this->credential?->subjectId === $subjectId && $this->credential->active
            ? $this->credential : null;
    }

    public function activate(
        string $credentialId,
        string $subjectId,
        int $expectedVersion,
        int $acceptedTimeStep,
        array $recoveryDigests,
        DateTimeImmutable $confirmedAt,
    ): bool {
        $current = $this->pending($credentialId, $subjectId);
        if (!$current instanceof TotpCredential || $current->version !== $expectedVersion) {
            return false;
        }
        $this->credential = new TotpCredential(
            $current->id,
            $current->subjectId,
            $current->encryptedSecret,
            true,
            $current->createdAt,
            null,
            $confirmedAt,
            $acceptedTimeStep,
            $current->version + 1,
        );
        $this->recovery = array_fill_keys($recoveryDigests, true);
        return true;
    }

    public function acceptTimeStep(
        string $credentialId,
        int $expectedVersion,
        int $timeStep,
        DateTimeImmutable $acceptedAt,
    ): bool {
        $current = $this->credential;
        if (
            !$current instanceof TotpCredential
            || !$current->active
            || $current->id !== $credentialId
            || $current->version !== $expectedVersion
            || ($current->lastAcceptedTimeStep !== null && $timeStep <= $current->lastAcceptedTimeStep)
        ) {
            return false;
        }
        $this->credential = new TotpCredential(
            $current->id,
            $current->subjectId,
            $current->encryptedSecret,
            true,
            $current->createdAt,
            null,
            $current->confirmedAt,
            $timeStep,
            $current->version + 1,
        );
        return true;
    }

    public function consumeRecoveryCode(
        string $credentialId,
        int $expectedVersion,
        string $digest,
        DateTimeImmutable $consumedAt,
    ): bool {
        $current = $this->credential;
        if (
            !$current instanceof TotpCredential
            || $current->id !== $credentialId
            || $current->version !== $expectedVersion
            || !isset($this->recovery[$digest])
        ) {
            return false;
        }
        unset($this->recovery[$digest]);
        $this->credential = new TotpCredential(
            $current->id,
            $current->subjectId,
            $current->encryptedSecret,
            true,
            $current->createdAt,
            null,
            $current->confirmedAt,
            $current->lastAcceptedTimeStep,
            $current->version + 1,
        );
        return true;
    }

    public function revokeForSubject(string $subjectId, DateTimeImmutable $revokedAt, string $reason): int
    {
        if ($this->credential?->subjectId !== $subjectId) {
            return 0;
        }
        $this->credential = null;
        $this->recovery = [];

        return 1;
    }

    public function replaceRecoveryCodes(
        string $credentialId,
        string $subjectId,
        int $expectedVersion,
        array $digests,
        DateTimeImmutable $reissuedAt,
    ): bool {
        $current = $this->credential;
        if (
            !$current instanceof TotpCredential
            || !$current->active
            || $current->id !== $credentialId
            || $current->subjectId !== $subjectId
            || $current->version !== $expectedVersion
        ) {
            return false;
        }
        $this->recovery = array_fill_keys($digests, true);
        $this->credential = new TotpCredential(
            $current->id,
            $current->subjectId,
            $current->encryptedSecret,
            true,
            $current->createdAt,
            null,
            $current->confirmedAt,
            $current->lastAcceptedTimeStep,
            $current->version + 1,
        );

        return true;
    }

    /** @return list<string> */
    public function recoveryDigests(): array
    {
        return array_keys($this->recovery);
    }
}

final class DeterministicStepUpRandom implements StepUpRandomSource
{
    private int $counter = 0;

    public function bytes(int $length): string
    {
        ++$this->counter;
        return substr(hash('sha512', 'step-up-' . $this->counter, true), 0, $length);
    }

    public function uuid(): string
    {
        ++$this->counter;
        return sprintf('018f0000-0000-7000-8000-%012x', $this->counter);
    }
}

final readonly class TimeStepTestAlgorithm implements TotpAlgorithm
{
    public function encodeSecret(string $secret): string
    {
        return 'TESTBASE32SECRET';
    }

    public function verify(string $secret, string $code, DateTimeImmutable $now): ?int
    {
        return $code === '123456' ? intdiv($now->getTimestamp(), 30) : null;
    }
}

final class RecordingStepUpThrottle implements StepUpAttemptThrottle
{
    /** @var list<bool> */
    public array $results = [];

    /**
     * Attempt partitions presented before credential work begins.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $purposes = [];

    public function assertAllowed(string $subjectId, string $source, string $purpose): void
    {
        $this->purposes[] = $purpose;
    }

    public function record(string $subjectId, string $source, string $purpose, bool $succeeded): void
    {
        $this->results[] = $succeeded;
    }
}

final class RecordingStepUpSessionRotator implements StepUpSessionRotator
{
    private int $counter = 10;

    public function rotate(StepUpIntent $intent, DateTimeImmutable $verifiedAt): RotatedStepUpSession
    {
        ++$this->counter;
        return new RotatedStepUpSession(
            sprintf('018f0000-0000-7000-8000-%012x', $this->counter),
            str_repeat('t', 64),
            str_repeat('c', 43),
            $verifiedAt->modify('+1 hour'),
        );
    }
}

final class RecordingStepUpProofStore implements StepUpProofStore
{
    public function issue(\Kumwe\App\Identity\Domain\StepUp\StepUpVerification $verification): void
    {
    }
}

final readonly class ImmediateStepUpTransactions implements TransactionManager
{
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }

    public function afterCommit(callable $operation): void
    {
        $operation();
    }

    public function afterRollback(callable $operation): void
    {
    }
}

final class RecordingStepUpAudit implements AuditRecorder
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function record(AuditEvent $event): void
    {
        $this->events[] = $event;
    }
}
