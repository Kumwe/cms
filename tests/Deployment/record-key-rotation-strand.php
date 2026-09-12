<?php

/**
 * Reproduce the stranded record-encryption keys, through the artifact's own cipher and key ring.
 *
 * A site-wide rotation covers every installation of the caller's site, so a rotation drill moved every
 * stored envelope in a shared database onto a key whose material existed only inside that one process and
 * was dropped at teardown. Everything afterwards inherited a database whose secrets nothing could open, and
 * nothing noticed, because the backup drill hashed ciphertext and a stranded envelope hashes exactly like a
 * readable one.
 *
 * This case pins the four properties that make the failure visible instead of silent: the ring opens what a
 * retired key sealed, a stranded envelope is byte-identical to the readable one so no digest could ever have
 * caught it, a missing key is a named refusal rather than a corrupt-ciphertext error, and the rotation
 * reverses through the same supported operation — which is also the shape an operator needs to abandon a
 * rotation part way through. It runs against the classes the artifact actually ships, resolved by the
 * production autoloader.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Kumwe\App\BusinessRecord\Application\SecretAssociatedData;
use Kumwe\App\Tests\Deployment\CaseReport;
use Kumwe\Secret\Cipher\KeyRingEnvelopeCipher;
use Kumwe\Secret\Exception\KeyUnavailable;
use Kumwe\Secret\Provider\KeyRingKeyProvider;
use Kumwe\Secret\Value\EncryptedEnvelope;
use Kumwe\Secret\Value\KeyMaterial;
use Kumwe\Secret\Value\KeyRing;

require __DIR__ . '/../Support/deployment-drill-autoload.php';

$case = 'record-key-rotation-strand';
$detail = [];

try {
    // Derived from readable stems so the material is unmistakably synthetic and carries no entropy.
    $outgoing = new KeyMaterial('artifact-lane-outgoing', hash('sha256', 'kumwe-artifact-outgoing', true));
    $incoming = new KeyMaterial('artifact-lane-incoming', hash('sha256', 'kumwe-artifact-incoming', true));

    $binding = SecretAssociatedData::for(
        'artifact-lane-site',
        '00000000-0000-4000-8000-000000000001',
        'artifact-lane-record',
        'passphrase',
    );
    $plaintext = 'the value a rotation must never make unreadable';

    $beforeRotation = new KeyRingEnvelopeCipher(new KeyRingKeyProvider(new KeyRing($outgoing)));
    $sealed = $beforeRotation->encrypt($plaintext, $binding);
    if ($sealed->keyId !== $outgoing->keyId) {
        throw new RuntimeException('The envelope does not name the key that sealed it.');
    }

    // The rotation: a new active key, the old one retired but still held.
    $afterRotation = new KeyRingEnvelopeCipher(
        new KeyRingKeyProvider(new KeyRing($incoming, [$outgoing])),
    );
    if ($afterRotation->decrypt($sealed, $binding) !== $plaintext) {
        throw new RuntimeException(
            'A rotation left an envelope written under the retired key unreadable. The ring exists so that '
            . 're-encryption is a background pass rather than an outage.',
        );
    }
    $detail['retired_key_opens'] = true;

    // The strand: the retired key's material is gone, which is what a drill holding it in-process caused.
    $stranded = new KeyRingEnvelopeCipher(new KeyRingKeyProvider(new KeyRing($incoming)));
    $refusal = null;
    try {
        $stranded->decrypt($sealed, $binding);
    } catch (KeyUnavailable $unavailable) {
        $refusal = $unavailable->getMessage();
    }
    if ($refusal === null) {
        throw new RuntimeException('A ring without the sealing key opened the envelope anyway.');
    }
    $detail['stranded_refusal'] = 'KeyUnavailable';

    // Why nothing noticed: the stranded envelope is byte-identical to the readable one.
    $storedBefore = $sealed->toStorage();
    $storedAfter = EncryptedEnvelope::fromStorage($storedBefore)->toStorage();
    $digestBefore = hash('sha256', (string) json_encode($storedBefore));
    $digestAfter = hash('sha256', (string) json_encode($storedAfter));
    if (!hash_equals($digestBefore, $digestAfter)) {
        throw new RuntimeException('A stored envelope did not round-trip through storage unchanged.');
    }
    $detail['digest_cannot_detect_strand'] = true;

    // The reversal: the same supported operation in the other direction restores what the rotation moved.
    $reEncrypted = $afterRotation->encrypt($afterRotation->decrypt($sealed, $binding), $binding);
    if ($reEncrypted->keyId !== $incoming->keyId) {
        throw new RuntimeException('Re-encryption did not move the envelope onto the active key.');
    }
    $rolledBack = new KeyRingEnvelopeCipher(
        new KeyRingKeyProvider(new KeyRing($outgoing, [$incoming])),
    );
    if ($rolledBack->decrypt($reEncrypted, $binding) !== $plaintext) {
        throw new RuntimeException(
            'The rotation could not be rolled back through the same operation in the other direction, which '
            . 'is how a shared database is handed on readable and how an operator abandons a rotation.',
        );
    }
    $detail['rotation_reverses'] = true;
} catch (Throwable $failure) {
    CaseReport::fail($case, $failure->getMessage(), $detail);
}

CaseReport::pass($case, $detail);
