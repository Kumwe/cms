<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

use Kumwe\Secret\Exception\InvalidInput;
use Kumwe\Secret\Value\AssociatedData;

/**
 * Builds the associated data that binds an encrypted secret field to the one cell it belongs in.
 *
 * `RecordValueCodec` passes this string to `EnvelopeCipher` on every secret-field encryption. Because the
 * AEAD construction authenticates it without storing it, a ciphertext lifted out of one row and pasted
 * into another site, definition, record, or field no longer authenticates and cannot be decrypted — the
 * database alone is not enough to move a secret around. The leading version marker lets a future binding
 * change be introduced without silently accepting envelopes written under the old one.
 *
 * @since  2.0.0
 */
final class SecretAssociatedData
{
    /**
     * Compose the binding for one secret field of one record.
     *
     * The arguments are the coordinates of the cell, so the same four values must be supplied again at
     * decryption time or authentication fails.
     *
     * @param   string  $siteIdentifier  Site the record belongs to.
     * @param   string  $definitionId    UUID of the business definition the record is an instance of.
     * @param   string  $recordId        Caller-facing identity of the record holding the secret.
     * @param   string  $field           Handle of the secret field within that record.
     *
     * @return  string  Newline-joined binding, opening with the `business-record-secret-v1` marker; opaque
     *          to callers and never stored.
     *
     * @throws  InvalidInput  When a coordinate contains the binding separator.
     *
     * @since   2.0.0
     */
    public static function for(
        string $siteIdentifier,
        string $definitionId,
        string $recordId,
        string $field,
    ): string {
        return AssociatedData::for('business-record-secret-v1', $siteIdentifier, $definitionId, $recordId, $field);
    }

    /**
     * Prevent instantiation; the type exists only to namespace the binding rule.
     *
     * @since  2.0.0
     */
    private function __construct()
    {
    }
}
