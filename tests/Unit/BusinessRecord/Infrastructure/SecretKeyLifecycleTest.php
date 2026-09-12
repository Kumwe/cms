<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessRecord\Infrastructure;

use InvalidArgumentException;
use Kumwe\App\BusinessRecord\Domain\SecretKeyPurpose;
use Kumwe\App\BusinessRecord\Infrastructure\Security\ConfiguredSecretKeyRings;
use Kumwe\Secret\Cipher\KeyRingEnvelopeCipher;
use Kumwe\Secret\Exception\KeyUnavailable;
use Kumwe\Secret\Provider\KeyRingKeyProvider;
use Kumwe\Secret\Value\EncryptedEnvelope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(SecretKeyPurpose::class)]
#[CoversClass(ConfiguredSecretKeyRings::class)]
/**
 * Proves the record key ring rotates without stranding anything it has already sealed.
 *
 * @since  2.0.0
 */
final class SecretKeyLifecycleTest extends TestCase
{
    /**
     * Build one deterministic fixture secret of usable length from a readable stem.
     *
     * The values are assembled rather than written out so no line of this file resembles a credential to a
     * secret scanner, and so a reader can see at a glance that nothing here was ever real key material.
     *
     * @param   string  $stem  Readable stem repeated until the result clears the 32-byte minimum.
     *
     * @return  string  Deterministic fixture secret of at least 32 bytes.
     *
     * @since   2.0.0
     */
    private static function fixtureSecret(string $stem): string
    {
        return str_repeat($stem . '-', (int) ceil(33 / (strlen($stem) + 1)));
    }

    /**
     * Application secret an existing installation is assumed to be running on.
     *
     * @return  string  Deterministic fixture value.
     *
     * @since   2.0.0
     */
    private static function applicationSecret(): string
    {
        return self::fixtureSecret('installation-fixture');
    }

    /**
     * Dedicated record-encryption secret such an installation adopts during the upgrade.
     *
     * @return  string  Deterministic fixture value.
     *
     * @since   2.0.0
     */
    private static function recordSecret(): string
    {
        return self::fixtureSecret('record-fixture');
    }

    /**
     * Prove an installation that configures nothing keeps the exact key its envelopes were sealed with.
     *
     * The derivation is reproduced literally rather than by calling the class under test, because those
     * bytes are already in production databases: if this assertion ever needs changing, every stored
     * envelope in every existing installation has become unreadable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnconfiguredInstallationKeepsTheApplicationSecretDerivedKeyActive(): void
    {
        $ring = (new ConfiguredSecretKeyRings(self::applicationSecret()))->records();

        self::assertSame('application-secret-v1', $ring->active->keyId);
        self::assertSame(['application-secret-v1'], $ring->keyIds());
        self::assertSame(
            hash_hmac('sha256', 'kumwe:business-record:encryption:v1', self::applicationSecret(), true),
            $ring->active->material(),
        );
    }

    /**
     * Prove an envelope sealed before the upgrade still opens once dedicated key material is adopted.
     *
     * This is the backward-compatibility guarantee in one assertion: the same associated data, the same
     * stored row, a ring whose active key is now something else entirely, and the secret still comes back.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEnvelopesSealedUnderTheApplicationSecretStillOpenAfterDedicatedKeysAreAdopted(): void
    {
        $before = new KeyRingEnvelopeCipher(new KeyRingKeyProvider(
            (new ConfiguredSecretKeyRings(self::applicationSecret()))->records(),
        ));
        $binding = 'business-record-secret-v1' . "\n" . 'default/definition/record/credential';
        $stored = $before->encrypt('pre-upgrade-secret', $binding)->toStorage();

        self::assertSame('application-secret-v1', $stored['key_id']);

        $after = new KeyRingEnvelopeCipher(new KeyRingKeyProvider(
            (new ConfiguredSecretKeyRings(
                self::applicationSecret(),
                null,
                'record-encryption-v2',
                self::recordSecret(),
            ))->records(),
        ));

        self::assertSame('pre-upgrade-secret', $after->decrypt(EncryptedEnvelope::fromStorage($stored), $binding));
        self::assertSame('record-encryption-v2', $after->encrypt('written-now', $binding)->keyId);
    }

    /**
     * Prove the record key survives an application-secret rotation once the old secret is named.
     *
     * Without this the two secrets are still joined: rotating `APP_SECRET` would change the bytes the
     * `application-secret-v1` identifier derives to, and every envelope still carrying that identifier
     * would fail authentication. Naming the previous application secret is what lets the two rotate on
     * separate schedules, which is the whole point of the decoupling.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testARotatedApplicationSecretDoesNotStrandEnvelopesWhenTheOldOneIsRetained(): void
    {
        $binding = 'business-record-secret-v1' . "\n" . 'default/definition/record/credential';
        $original = new KeyRingEnvelopeCipher(new KeyRingKeyProvider(
            (new ConfiguredSecretKeyRings(self::applicationSecret()))->records(),
        ));
        $stored = $original->encrypt('sealed-before-the-incident', $binding)->toStorage();

        $rotatedSecretOnly = new KeyRingEnvelopeCipher(new KeyRingKeyProvider(
            (new ConfiguredSecretKeyRings(self::fixtureSecret('rotated-fixture')))->records(),
        ));
        try {
            $rotatedSecretOnly->decrypt(EncryptedEnvelope::fromStorage($stored), $binding);
            self::fail('An envelope opened under an unrelated application secret.');
        } catch (RuntimeException $exception) {
            self::assertStringNotContainsString('sealed-before-the-incident', $exception->getMessage());
        }

        $rotatedWithLegacyRetained = new KeyRingEnvelopeCipher(new KeyRingKeyProvider(
            (new ConfiguredSecretKeyRings(
                self::fixtureSecret('rotated-fixture'),
                self::applicationSecret(),
                'record-encryption-v2',
                self::recordSecret(),
            ))->records(),
        ));

        self::assertSame(
            'sealed-before-the-incident',
            $rotatedWithLegacyRetained->decrypt(EncryptedEnvelope::fromStorage($stored), $binding),
        );
    }

    /**
     * Prove a retired dedicated key keeps opening its envelopes while the active key seals new ones.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testARetiredDedicatedKeyStillOpensWhatItSealed(): void
    {
        $binding = 'business-record-secret-v1' . "\n" . 'default/definition/record/credential';
        $first = new KeyRingEnvelopeCipher(new KeyRingKeyProvider(
            (new ConfiguredSecretKeyRings(
                self::applicationSecret(),
                null,
                'record-v1',
                self::recordSecret(),
            ))->records(),
        ));
        $stored = $first->encrypt('first-generation', $binding)->toStorage();

        $ring = (new ConfiguredSecretKeyRings(
            self::applicationSecret(),
            null,
            'record-v2',
            self::fixtureSecret('second-fixture'),
            ['record-v1' => self::recordSecret()],
        ))->records();
        $second = new KeyRingEnvelopeCipher(new KeyRingKeyProvider($ring));

        self::assertSame(
            ['record-v2', 'application-secret-v1', 'record-v1'],
            $ring->keyIds(),
        );
        self::assertSame('first-generation', $second->decrypt(EncryptedEnvelope::fromStorage($stored), $binding));
        self::assertSame('record-v2', $second->encrypt('second-generation', $binding)->keyId);
    }

    /**
     * Prove a key dropped from the ring fails as unavailable and names no material.
     *
     * Dropping a key from configuration is how a revocation is expressed to the shipped provider, so this
     * is also the revoked-key case.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testARevokedOrForeignKeyFailsClosedWithoutDisclosingAnything(): void
    {
        $binding = 'business-record-secret-v1' . "\n" . 'default/definition/record/credential';
        $retired = new KeyRingEnvelopeCipher(new KeyRingKeyProvider(
            (new ConfiguredSecretKeyRings(
                self::applicationSecret(),
                null,
                'record-v1',
                self::recordSecret(),
            ))->records(),
        ));
        $stored = $retired->encrypt('should-stay-sealed', $binding)->toStorage();

        $withoutTheKey = new KeyRingEnvelopeCipher(new KeyRingKeyProvider(
            (new ConfiguredSecretKeyRings(
                self::applicationSecret(),
                null,
                'record-v2',
                self::recordSecret(),
            ))->records(),
        ));

        try {
            $withoutTheKey->decrypt(EncryptedEnvelope::fromStorage($stored), $binding);
            self::fail('An envelope opened under a key the ring does not hold.');
        } catch (KeyUnavailable $exception) {
            self::assertStringContainsString('"record-v1" is unavailable', $exception->getMessage());
            self::assertStringNotContainsString('should-stay-sealed', $exception->getMessage());
            self::assertStringNotContainsString(self::recordSecret(), $exception->getMessage());
            self::assertStringNotContainsString('record-v2', $exception->getMessage());
        }
    }

    /**
     * Prove the two purposes derive different keys and refuse each other's envelopes.
     *
     * Associated data already stopped a plan token being read as a record secret. This proves the keys
     * are separate too, which is what makes a record-key rotation leave plan tokens alone and a plan-key
     * rotation leave stored records alone.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRecordAndMutationPlanPurposesAreKeyedApart(): void
    {
        $rings = new ConfiguredSecretKeyRings(self::applicationSecret());
        $records = $rings->records();
        $plans = $rings->mutationPlans();

        self::assertNotSame($records->active->keyId, $plans->active->keyId);
        self::assertSame('mutation-plan-v1', $plans->active->keyId);
        self::assertNotSame($records->active->material(), $plans->active->material());
        self::assertSame(['mutation-plan-v1'], $plans->keyIds());

        $planCipher = new KeyRingEnvelopeCipher(new KeyRingKeyProvider($plans));
        $recordCipher = new KeyRingEnvelopeCipher(new KeyRingKeyProvider($records));
        $planToken = $planCipher->encrypt('plan-document', 'kumwe:business-mutation-plan:v2');

        $this->expectException(KeyUnavailable::class);
        $recordCipher->decrypt($planToken, 'kumwe:business-mutation-plan:v2');
    }

    /**
     * Prove a record-key rotation does not change the mutation-plan key at all.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRotatingTheRecordKeyLeavesTheMutationPlanKeyUntouched(): void
    {
        $before = (new ConfiguredSecretKeyRings(self::applicationSecret()))->mutationPlans();
        $after = (new ConfiguredSecretKeyRings(
            self::applicationSecret(),
            null,
            'record-v9',
            self::recordSecret(),
            ['record-v8' => self::fixtureSecret('earlier-fixture')],
        ))->mutationPlans();

        self::assertSame($before->active->keyId, $after->active->keyId);
        self::assertSame($before->active->material(), $after->active->material());
    }

    /**
     * Prove a misconfigured ring refuses to boot rather than silently losing a key.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAmbiguousOrReservedConfigurationIsRefusedAtConstruction(): void
    {
        $cases = [
            'reserved identifier' => fn (): ConfiguredSecretKeyRings => new ConfiguredSecretKeyRings(
                self::applicationSecret(),
                null,
                'application-secret-v1',
                self::recordSecret(),
            ),
            'record key repeats the application secret' => fn (): ConfiguredSecretKeyRings =>
                new ConfiguredSecretKeyRings(self::applicationSecret(), null, 'record-v1', self::applicationSecret()),
            'record key is too short' => fn (): ConfiguredSecretKeyRings =>
                new ConfiguredSecretKeyRings(self::applicationSecret(), null, 'record-v1', 'short'),
            'identifier without key' => fn (): ConfiguredSecretKeyRings =>
                new ConfiguredSecretKeyRings(self::applicationSecret(), null, 'record-v1'),
            'retired identifier repeats the active one' => fn (): ConfiguredSecretKeyRings =>
                new ConfiguredSecretKeyRings(self::applicationSecret(), null, 'record-v1', self::recordSecret(), [
                    'record-v1' => self::fixtureSecret('retired-fixture'),
                ]),
        ];
        foreach ($cases as $name => $case) {
            try {
                $case();
                self::fail(sprintf('A ring accepted its "%s" configuration.', $name));
            } catch (InvalidArgumentException $exception) {
                self::assertStringNotContainsString(self::recordSecret(), $exception->getMessage());
                self::assertStringNotContainsString(self::applicationSecret(), $exception->getMessage());
            }
        }
    }
}
