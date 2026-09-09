<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Infrastructure\Release;

use InvalidArgumentException;

/**
 * App-owned qualification of one exact Studio deployment for the contextual PHP adapter.
 *
 * Filesystem records are evidence, not their own trust root. This value is created in reviewed App
 * wiring only when one coordinated Studio release, its pin record, the materialized first-party
 * catalog, the pinned browser module and the PHP authoring host have passed qualification together.
 * Producer independently owns and verifies the schema corpus and the browser-asset manifest; the
 * qualification names the exact module integrity so a Producer re-pin that moves the module cannot
 * keep an unreviewed App qualification alive.
 *
 * @since  2.0.0
 */
final readonly class StudioContextualAuthoringQualification
{
    /**
     * Bind the adapter to immutable release and evidence digests selected by App.
     *
     * @param   string  $release                 Exact coordinated semantic version.
     * @param   string  $releaseRecordSha256     Hex SHA-256 of `studio-release.json`.
     * @param   string  $pinRecordSha256         Hex SHA-256 of App's complete `PIN.json`.
     * @param   string  $coreCatalogSha256       Hex SHA-256 of the materialized `core-catalog.json`.
     * @param   string  $browserModuleIntegrity  Subresource-integrity value of the pinned browser module.
     *
     * @throws  InvalidArgumentException  When a coordinate cannot identify exact immutable evidence.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $release,
        public string $releaseRecordSha256,
        public string $pinRecordSha256,
        public string $coreCatalogSha256,
        public string $browserModuleIntegrity,
    ) {
        if (
            preg_match(
                '/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?$/D',
                $release,
            ) !== 1
        ) {
            throw new InvalidArgumentException('Studio qualification requires an exact semantic release.');
        }
        foreach ([$releaseRecordSha256, $pinRecordSha256, $coreCatalogSha256] as $digest) {
            if (preg_match('/^[0-9a-f]{64}$/D', $digest) !== 1) {
                throw new InvalidArgumentException('Studio qualification requires exact SHA-256 evidence.');
            }
        }
        if (preg_match('#^sha256-[A-Za-z0-9+/]{43}=$#D', $browserModuleIntegrity) !== 1) {
            throw new InvalidArgumentException('Studio qualification requires the exact browser module integrity.');
        }
    }
}
