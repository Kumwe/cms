<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Infrastructure\Security;

use InvalidArgumentException;
use Kumwe\App\BusinessRecord\Domain\SecretKeyPurpose;
use Kumwe\Secret\Value\KeyMaterial;
use Kumwe\Secret\Value\KeyRing;
use SensitiveParameter;

/**
 * Derives the per-purpose key rings a deployment's configured secrets stand for.
 *
 * This is where the record-encryption key stops being the application secret. Until now one key was
 * derived from `APP_SECRET` and hard-coded as `application-secret-v1`, which meant rotating the session
 * and token secret after a compromise stranded every stored envelope — the two had to be rotated
 * together, so in practice neither was. A deployment now supplies dedicated record key material instead,
 * and `APP_SECRET` rotates on its own schedule.
 *
 * Backward compatibility is not optional and is not a migration step. The `application-secret-v1` key is
 * always derivable and is always placed in the record ring: as the active key when no dedicated material
 * is configured, so an existing installation upgrades with no configuration change and no re-encryption,
 * and as a retired key once dedicated material is configured, so envelopes already written keep opening
 * while `business:rekey-secrets` works through them. Its derivation is reproduced here byte for byte —
 * `hash_hmac('sha256', 'kumwe:business-record:encryption:v1', $secret, true)` — because those bytes are
 * already in production databases and are not ours to change. `$legacySecret` exists so that derivation
 * can be pinned to the *old* `APP_SECRET` after that secret is rotated, which is what finally lets the
 * two rotate independently.
 *
 * Mutation-plan tokens get a ring of their own, derived from `APP_SECRET` under a different label with a
 * different identifier. They live for minutes, so they neither need nor want the record ring's
 * retired-key baggage, and a record-key rotation no longer touches them.
 *
 * @since  2.0.0
 */
final readonly class ConfiguredSecretKeyRings
{
    /**
     * Identifier of the key derived from the application secret, as shipped before dedicated material existed.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string LEGACY_KEY_ID = 'application-secret-v1';

    /**
     * Frozen derivation label of that legacy key; changing it would strand every envelope it sealed.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string LEGACY_LABEL = 'kumwe:business-record:encryption:v1';

    /**
     * Capture the secrets this deployment supplies, without deriving anything yet.
     *
     * @param   string                 $applicationSecret  `APP_SECRET`, still the source of the
     *          mutation-plan key and, unless `$legacySecret` overrides it, of the legacy record key.
     * @param   ?string                $legacySecret       Previous `APP_SECRET`, supplied while envelopes
     *          sealed under it still exist so `APP_SECRET` can be rotated ahead of re-encryption; null to
     *          derive the legacy key from `$applicationSecret` as before.
     * @param   ?string                $activeKeyId        Identifier for the dedicated record key; null
     *          takes `SecretKeyPurpose::Record`'s default. Ignored when `$activeKey` is null.
     * @param   ?string                $activeKey          Dedicated record-encryption secret of at least
     *          32 bytes; null keeps the legacy key active, which is the no-configuration upgrade path.
     * @param   array<string, string>  $previousKeys       Retired dedicated secrets by identifier, kept so
     *          envelopes from earlier rotations still open.
     *
     * @throws  InvalidArgumentException  When a supplied secret is shorter than 32 bytes, the active
     *          record secret repeats `APP_SECRET`, or an identifier is malformed, repeated, or claims the
     *          reserved legacy name. No message quotes secret material.
     *
     * @since   2.0.0
     */
    public function __construct(
        #[SensitiveParameter] private string $applicationSecret,
        #[SensitiveParameter] private ?string $legacySecret = null,
        private ?string $activeKeyId = null,
        #[SensitiveParameter] private ?string $activeKey = null,
        #[SensitiveParameter] private array $previousKeys = [],
    ) {
        if (strlen($applicationSecret) < 32) {
            throw new InvalidArgumentException('The application secret must contain at least 32 bytes.');
        }
        if ($legacySecret !== null && strlen($legacySecret) < 32) {
            throw new InvalidArgumentException('RECORD_ENCRYPTION_LEGACY_SECRET must contain at least 32 bytes.');
        }
        if ($activeKey !== null) {
            $this->assertIdentifier($activeKeyId ?? SecretKeyPurpose::Record->defaultKeyId());
            if (strlen($activeKey) < 32) {
                throw new InvalidArgumentException('RECORD_ENCRYPTION_KEY must contain at least 32 bytes.');
            }
            if (hash_equals($applicationSecret, $activeKey)) {
                throw new InvalidArgumentException('RECORD_ENCRYPTION_KEY must be independent from APP_SECRET.');
            }
        } elseif ($activeKeyId !== null) {
            throw new InvalidArgumentException('RECORD_ENCRYPTION_KEY_ID requires RECORD_ENCRYPTION_KEY.');
        }
        foreach ($previousKeys as $keyId => $key) {
            $this->assertIdentifier($keyId);
            if (strlen($key) < 32) {
                throw new InvalidArgumentException('RECORD_ENCRYPTION_PREVIOUS_KEYS holds a secret that is too short.');
            }
            if ($activeKey !== null && hash_equals($activeKeyId ?? SecretKeyPurpose::Record->defaultKeyId(), $keyId)) {
                throw new InvalidArgumentException('RECORD_ENCRYPTION_PREVIOUS_KEYS repeats the active identifier.');
            }
        }
    }

    /**
     * Build the ring durable record envelopes are sealed and opened with.
     *
     * The legacy key is present in every ring this returns — active when no dedicated material is
     * configured, retired otherwise — which is the whole of the backward-compatibility guarantee.
     *
     * @return  KeyRing  Active record key plus the legacy key and every configured retired key.
     *
     * @throws  InvalidArgumentException  When two configured identifiers collide inside the ring.
     *
     * @since   2.0.0
     */
    public function records(): KeyRing
    {
        $legacy = new KeyMaterial(
            self::LEGACY_KEY_ID,
            hash_hmac('sha256', self::LEGACY_LABEL, $this->legacySecret ?? $this->applicationSecret, true),
        );
        if ($this->activeKey === null) {
            return new KeyRing($legacy, $this->retired());
        }

        return new KeyRing(
            new KeyMaterial(
                $this->activeKeyId ?? SecretKeyPurpose::Record->defaultKeyId(),
                $this->derive($this->activeKey),
            ),
            [$legacy, ...$this->retired()],
        );
    }

    /**
     * Build the ring short-lived mutation-plan tokens are sealed and opened with.
     *
     * One key, its own label, its own identifier, and no retired keys: a plan token expires within
     * minutes, so a rotation only has to outlive the tokens already issued, and holding retired plan keys
     * would keep expired tokens openable for no benefit. Because the ring is separate, a record-key
     * rotation neither invalidates a plan token nor is delayed by one.
     *
     * @return  KeyRing  Single-key ring for `SecretKeyPurpose::MutationPlan`.
     *
     * @since   2.0.0
     */
    public function mutationPlans(): KeyRing
    {
        return new KeyRing(new KeyMaterial(
            SecretKeyPurpose::MutationPlan->defaultKeyId(),
            hash_hkdf(
                'sha256',
                $this->applicationSecret,
                SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
                SecretKeyPurpose::MutationPlan->derivationLabel(),
            ),
        ));
    }

    /**
     * Derive the retired dedicated record keys, in configuration order.
     *
     * @return  list<KeyMaterial>  Retired keys; empty when the deployment configures none.
     *
     * @throws  InvalidArgumentException  When a retired identifier is malformed.
     *
     * @since   2.0.0
     */
    private function retired(): array
    {
        $keys = [];
        foreach ($this->previousKeys as $keyId => $key) {
            $keys[] = new KeyMaterial($keyId, $this->derive($key));
        }

        return $keys;
    }

    /**
     * Stretch one configured secret into raw AEAD key bytes under the record purpose's label.
     *
     * HKDF rather than the configured bytes directly, so an operator who supplies a long passphrase or a
     * base64 blob still gets a uniform key of the right length, and so record key material is unrelated
     * to the same secret used for any other purpose.
     *
     * @param   string  $secret  Configured record-encryption secret, at least 32 bytes.
     *
     * @return  string  Raw XChaCha20-Poly1305 key bytes.
     *
     * @since   2.0.0
     */
    private function derive(#[SensitiveParameter] string $secret): string
    {
        return hash_hkdf(
            'sha256',
            $secret,
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            SecretKeyPurpose::Record->derivationLabel(),
        );
    }

    /**
     * Insist a configured key identifier is well formed and does not claim the reserved legacy name.
     *
     * @param   string  $keyId  Identifier as configured.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the shape is wrong or the reserved identifier is claimed.
     *
     * @since   2.0.0
     */
    private function assertIdentifier(string $keyId): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,126}$/D', $keyId) !== 1) {
            throw new InvalidArgumentException('A record-encryption key identifier is invalid.');
        }
        if (hash_equals(self::LEGACY_KEY_ID, $keyId)) {
            throw new InvalidArgumentException('The identifier "application-secret-v1" is reserved.');
        }
    }
}
