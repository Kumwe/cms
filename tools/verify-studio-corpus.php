<?php

/**
 * Prove App's deployment pin agrees with Producer's exact Studio authority.
 *
 * Producer owns and verifies the released PHP schema corpus, testkit resources and browser-asset
 * manifest. App retains only the coordinated release record, the registry pin of the eight npm
 * packages its browser build consumes, and the materialized first-party block catalog. This gate
 * binds those App-owned records to Producer's typed release and package provenance, compiles
 * Producer's closed schema registry, and resolves every manifest-listed testkit member through
 * Producer's safe resource API.
 *
 * Usage:
 *
 *   php tools/verify-studio-corpus.php
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Kumwe\Producer\Schema\StudioContractResources;
use Kumwe\Producer\Schema\StudioDocumentSchemaRegistry;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Read one required ordinary file.
 *
 * @param   string  $path  Absolute deployment-evidence path.
 *
 * @return  string  Exact file bytes.
 *
 * @throws  RuntimeException  When the file is absent or unreadable.
 *
 * @since   2.0.0
 */
$fileBytes = static function (string $path): string {
    $bytes = is_file($path) ? file_get_contents($path) : false;
    if (!is_string($bytes)) {
        throw new RuntimeException(sprintf('Required Studio evidence %s is missing or unreadable.', $path));
    }

    return $bytes;
};

/**
 * Decode exact object-shaped JSON bytes.
 *
 * @param   string  $bytes  JSON document bytes.
 * @param   string  $label  Evidence label for diagnostics.
 *
 * @return  array<string, mixed>  Decoded object members.
 *
 * @throws  RuntimeException  When the bytes are not object-shaped JSON.
 *
 * @since   2.0.0
 */
$object = static function (string $bytes, string $label): array {
    try {
        $decoded = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException($label . ' is not valid JSON.', 0, $error);
    }
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new RuntimeException($label . ' must be a JSON object.');
    }

    return $decoded;
};

/**
 * Admit an ordered string list from deployment evidence.
 *
 * @param   mixed   $value  Candidate list.
 * @param   string  $label  Evidence label for diagnostics.
 *
 * @return  list<string>  Exact ordered values.
 *
 * @throws  RuntimeException  When the value is not an ordered string list.
 *
 * @since   2.0.0
 */
$stringList = static function (mixed $value, string $label): array {
    if (!is_array($value) || !array_is_list($value)) {
        throw new RuntimeException($label . ' must be an ordered list.');
    }
    foreach ($value as $entry) {
        if (!is_string($entry)) {
            throw new RuntimeException($label . ' must contain only strings.');
        }
    }

    return $value;
};

/**
 * Admit an object-shaped string map from deployment evidence.
 *
 * @param   mixed   $value  Candidate map.
 * @param   string  $label  Evidence label for diagnostics.
 *
 * @return  array<string, string>  Exact named string values.
 *
 * @throws  RuntimeException  When the value is not a named string map.
 *
 * @since   2.0.0
 */
$stringMap = static function (mixed $value, string $label): array {
    if (!is_array($value) || array_is_list($value)) {
        throw new RuntimeException($label . ' must be an object.');
    }
    $strings = [];
    foreach ($value as $key => $entry) {
        if (!is_string($key) || !is_string($entry)) {
            throw new RuntimeException($label . ' must contain only named strings.');
        }
        $strings[$key] = $entry;
    }

    return $strings;
};

$root = dirname(__DIR__);
$contractRoot = $root . '/resources/studio-contract';
$errors = [];
$studioPackages = [
    '@kumwe/studio',
    '@kumwe/studio-core',
    '@kumwe/studio-media',
    '@kumwe/studio-preview',
    '@kumwe/studio-protocol',
    '@kumwe/studio-renderer-web',
    '@kumwe/studio-rich-text',
    '@kumwe/studio-testkit',
];
$corpusCount = 0;
$groupCount = 0;

try {
    $installed = StudioContractResources::releaseRecord();
    StudioDocumentSchemaRegistry::fromVendoredCorpus();
    $manifestBytes = StudioContractResources::testkitManifestBytes();

    $releaseBytes = $fileBytes($contractRoot . '/studio-release.json');
    $release = $object($releaseBytes, 'App Studio release record');
    $packages = $stringMap($release['packages'] ?? null, 'App Studio release packages');
    $profiles = $stringList($release['claimedProfiles'] ?? null, 'App Studio release profiles');
    $installedPackages = $installed->packages();
    ksort($packages);
    ksort($installedPackages);
    if (
        ($release['kind'] ?? null) !== 'studio-release'
        || ($release['contractVersion'] ?? null) !== $installed->contractVersion()
        || ($release['release'] ?? null) !== $installed->release()
        || ($release['protocolVersion'] ?? null) !== $installed->protocolVersion()
        || ($release['corpusManifestDigest'] ?? null) !== $installed->corpusManifestDigest()
        || $profiles !== $installed->claimedProfiles()
        || $packages !== $installedPackages
        || hash('sha256', $releaseBytes) !== $installed->recordSha256()
    ) {
        throw new RuntimeException('App studio-release.json differs from Producer\'s coordinated release.');
    }

    $pin = $object($fileBytes($contractRoot . '/PIN.json'), 'App Studio PIN');
    $releasePin = $pin['release_record'] ?? null;
    $registry = $pin['registry'] ?? null;
    $pinned = $pin['pinned'] ?? null;
    if (
        !is_array($releasePin)
        || ($releasePin['file'] ?? null) !== 'studio-release.json'
        || ($releasePin['release'] ?? null) !== $installed->release()
        || ($releasePin['sha256'] ?? null) !== $installed->recordSha256()
        || !is_string($registry)
        || preg_match('#^https://[a-z0-9.-]+$#D', $registry) !== 1
        || !is_array($pinned)
        || array_is_list($pinned)
    ) {
        throw new RuntimeException('App PIN.json does not bind Producer\'s exact release record and registry.');
    }

    $expectedNames = $studioPackages;
    sort($expectedNames);
    $pinnedNames = array_keys($pinned);
    sort($pinnedNames);
    $installedNames = array_keys($installedPackages);
    sort($installedNames);
    if ($pinnedNames !== $expectedNames || $installedNames !== $expectedNames) {
        throw new RuntimeException('The Studio release must coordinate exactly the eight public packages.');
    }

    $integrities = $installed->packageIntegrities();
    foreach ($studioPackages as $package) {
        $packagePin = $pinned[$package] ?? null;
        $version = $installedPackages[$package] ?? null;
        $tarball = is_array($packagePin) ? ($packagePin['tarball'] ?? null) : null;
        $digest = is_array($packagePin) ? ($packagePin['npm_tarball_sha256'] ?? null) : null;
        $integrity = is_array($packagePin) ? ($packagePin['integrity'] ?? null) : null;
        $unscoped = substr($package, strlen('@kumwe/'));
        if (
            !is_array($packagePin)
            || !is_string($version)
            || ($packagePin['version'] ?? null) !== $version
            || $tarball !== sprintf('%s/%s/-/%s-%s.tgz', $registry, $package, $unscoped, $version)
            || !is_string($digest)
            || preg_match('/^[0-9a-f]{64}$/D', $digest) !== 1
            || !is_string($integrity)
            || preg_match('#^sha512-[A-Za-z0-9+/]{86}==$#D', $integrity) !== 1
        ) {
            throw new RuntimeException(sprintf('App has a malformed registry pin for %s.', $package));
        }
        if (!is_string($integrities[$package] ?? null) || !hash_equals($integrities[$package], $integrity)) {
            throw new RuntimeException(sprintf(
                'The pinned npm integrity for %s differs from Producer\'s package provenance.',
                $package,
            ));
        }
    }
    if (file_exists($contractRoot . '/packages')) {
        throw new RuntimeException('App must not vendor Studio package tarballs; packages resolve from the registry.');
    }

    $catalog = $object($fileBytes($contractRoot . '/core-catalog.json'), 'App Studio core catalog');
    $blocks = $catalog['blocks'] ?? null;
    if (
        ($catalog['kind'] ?? null) !== 'studio-core-catalog'
        || ($catalog['release'] ?? null) !== $installed->release()
        || !is_array($blocks)
        || !array_is_list($blocks)
        || $blocks === []
    ) {
        throw new RuntimeException('App core-catalog.json does not describe the pinned Studio release.');
    }

    $manifest = $object($manifestBytes, 'Producer Studio testkit manifest');
    $groups = $manifest['groups'] ?? null;
    if (!is_array($groups) || !array_is_list($groups)) {
        throw new RuntimeException('Producer\'s Studio testkit manifest has no ordered groups list.');
    }
    foreach ($groups as $group) {
        $path = is_array($group) ? ($group['path'] ?? null) : null;
        $files = is_array($group) ? ($group['files'] ?? null) : null;
        if (!is_string($path) || !is_array($files) || !array_is_list($files)) {
            throw new RuntimeException('Producer\'s Studio testkit manifest contains a malformed group.');
        }
        $groupCount++;
        foreach ($files as $entry) {
            $file = is_array($entry) ? ($entry['file'] ?? null) : null;
            if (!is_string($file)) {
                throw new RuntimeException('Producer\'s Studio testkit manifest contains a malformed member.');
            }
            StudioContractResources::testkitBytes($path . '/' . $file);
            $corpusCount++;
        }
    }
} catch (Throwable $error) {
    $errors[] = $error->getMessage();
}

if ($errors !== []) {
    fwrite(STDERR, "Studio dependency integration does not match the coordinated release:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, ' - ' . $error . "\n");
    }
    exit(1);
}

printf(
    "Kumwe Studio dependencies verified: %d registry-pinned npm packages, %d Producer document kinds, "
        . "%d testkit files in %d groups.\n",
    count($studioPackages),
    count(StudioDocumentSchemaRegistry::DOCUMENT_KINDS),
    $corpusCount,
    $groupCount,
);
