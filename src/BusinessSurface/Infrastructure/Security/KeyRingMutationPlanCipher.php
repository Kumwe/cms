<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Infrastructure\Security;

use Kumwe\App\BusinessSurface\Application\MutationPlanCipher;
use Kumwe\Secret\Cipher\KeyRingEnvelopeCipher;
use Kumwe\Secret\Contract\KeyProvider;
use Kumwe\Secret\Exception\KeyUnavailable;
use Kumwe\Secret\Value\EncryptedEnvelope;

/**
 * `MutationPlanCipher` over a key ring of the mutation-plan purpose alone.
 *
 * The sealing itself is the same authenticated encryption record secrets use, so this holds a
 * `KeyRingEnvelopeCipher` rather than repeating it; what the class contributes is the type. Binding the
 * plan service to `MutationPlanCipher` and this implementation to the plan-purpose ring is what makes the
 * separation structural: there is no wiring in which the plan service ends up holding record key
 * material, and no rotation of one ring reaches the other.
 *
 * @since  2.0.0
 */
final readonly class KeyRingMutationPlanCipher implements MutationPlanCipher
{
    /**
     * Cipher bound to the plan-purpose ring, doing the actual sealing.
     *
     * @var    KeyRingEnvelopeCipher
     * @since  2.0.0
     */
    private KeyRingEnvelopeCipher $cipher;

    /**
     * Bind the cipher to the provider holding mutation-plan key material.
     *
     * @param  KeyProvider  $keys  Provider for the plan purpose, never the record purpose.
     *
     * @since  2.0.0
     */
    public function __construct(KeyProvider $keys)
    {
        $this->cipher = new KeyRingEnvelopeCipher($keys);
    }

    /**
     * Seal one plan document under the active mutation-plan key.
     *
     * @param   string  $plaintext       Scalar plan document, already JSON-encoded by the caller.
     * @param   string  $associatedData  The plan domain's binding, authenticated but not stored.
     *
     * @return  EncryptedEnvelope  Ciphertext, nonce and the mutation-plan key identifier.
     *
     * @throws  \InvalidArgumentException  When either input exceeds its bound.
     * @throws  \RuntimeException  When libsodium refuses the encryption.
     *
     * @since   2.0.0
     */
    public function encrypt(string $plaintext, string $associatedData): EncryptedEnvelope
    {
        return $this->cipher->encrypt($plaintext, $associatedData);
    }

    /**
     * Open one plan token under the key its envelope names.
     *
     * A token minted before this deployment moved to a plan-specific key names an identifier this ring
     * does not hold and is refused as unavailable. That is the intended outcome: plan tokens live for
     * minutes, so the only tokens affected are the handful in flight across the upgrade, and the caller
     * already reports every decode failure as one indistinguishable invalid-plan answer.
     *
     * @param   EncryptedEnvelope  $envelope        Envelope decoded from the presented token.
     * @param   string             $associatedData  The plan domain's binding, as used to seal it.
     *
     * @return  string  The original plan document.
     *
     * @throws  \RuntimeException  When the envelope names an unsupported construction or fails
     *          authentication.
     * @throws  KeyUnavailable  When the token names a key this
     *          ring does not hold.
     *
     * @since   2.0.0
     */
    public function decrypt(EncryptedEnvelope $envelope, string $associatedData): string
    {
        return $this->cipher->decrypt($envelope, $associatedData);
    }
}
