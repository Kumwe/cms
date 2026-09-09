<?php

/**
 * Verify exact, reproducible coordinates for every extracted first-party dependency.
 *
 * App consumes Conversion, Extension SDK, and Producer as exact Composer releases and the coordinated
 * Studio family as exact npm releases or digest-verified local tarballs. A manifest-only check is not
 * enough: a stale or hand-edited lock can still resolve a branch, an alias, or a foreign repository.
 * This gate therefore binds both manifests to both locks, requires immutable Composer commit references,
 * accepts only the official Kumwe GitHub coordinates, and keeps every Studio package on an exact version.
 * The one admitted Composer alias form is `X.Y.Z as A.B.C` with two exact releases: the real release stays
 * immutable while a sibling library whose constraint predates that release keeps resolving, and the lock
 * must record exactly that alias. It then invokes the Producer/Studio alignment verifier so the exact
 * Producer commit cannot implement a different Studio release than App vendors.
 *
 * Usage:
 *
 *   php tools/verify-studio-dependencies.php [--composer=PATH] [--composer-lock=PATH]
 *       [--package=PATH] [--package-lock=PATH] [--app-studio-pin=PATH]
 *       [--app-studio-release=PATH] [--producer-studio-pin=PATH]
 *       [--producer-studio-release=PATH]
 *
 * @since  2.0.0
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
    'composer' => $root . '/composer.json',
    'composer-lock' => $root . '/composer.lock',
    'package' => $root . '/package.json',
    'package-lock' => $root . '/package-lock.json',
    'app-studio-pin' => $root . '/resources/studio-contract/PIN.json',
    'app-studio-release' => $root . '/resources/studio-contract/studio-release.json',
    'producer-studio-pin' => $root . '/vendor/kumwe/producer/resources/studio-contract/PIN.json',
    'producer-studio-release' => $root
        . '/vendor/kumwe/producer/resources/studio-contract/studio-release.json',
];

foreach (array_slice($argv, 1) as $argument) {
    $matched = false;
    foreach (array_keys($paths) as $name) {
        $prefix = '--' . $name . '=';
        if (!str_starts_with($argument, $prefix)) {
            continue;
        }
        $paths[$name] = substr($argument, strlen($prefix));
        $matched = true;
        break;
    }
    if ($matched) {
        continue;
    }

    fwrite(STDERR, sprintf("Unknown argument: %s\n", $argument));
    exit(2);
}

$composerRepositories = [
    'kumwe/conversion' => 'conversion',
    'kumwe/extension-sdk' => 'extension-sdk',
    'kumwe/producer' => 'producer',
];
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
$errors = [];

$composer = dependencyManifest($paths['composer'], $errors);
$composerAliases = [];
$composerPins = $composer === null
    ? []
    : verifyComposerManifest($composer, $composerRepositories, $errors, $composerAliases);
$composerLock = dependencyManifest($paths['composer-lock'], $errors);
if ($composerLock !== null) {
    verifyComposerLock($composerLock, $composerPins, $composerRepositories, $composerAliases, $errors);
}

$package = dependencyManifest($paths['package'], $errors);
$npmPins = $package === null ? [] : verifyNpmManifest($package, $studioPackages, $errors);
$packageLock = dependencyManifest($paths['package-lock'], $errors);
if ($packageLock !== null) {
    verifyNpmLock($packageLock, $npmPins, $studioPackages, $errors);
}

if ($errors !== []) {
    reportDependencyFailure($errors);
}

printf(
    "Kumwe first-party dependencies verified: %d Composer pin(s), %d Studio pin(s), immutable official locks.\n",
    count($composerPins),
    count($npmPins),
);

require_once __DIR__ . '/verify-producer-studio-alignment.php';

exit(verifyProducerStudioAlignment([
    'app_pin' => $paths['app-studio-pin'],
    'app_release' => $paths['app-studio-release'],
    'producer_pin' => $paths['producer-studio-pin'],
    'producer_release' => $paths['producer-studio-release'],
]));

/**
 * Decode one JSON manifest or record why it cannot be used as evidence.
 *
 * @param   string        $path    Manifest path.
 * @param   list<string>  $errors  Accumulated failures.
 *
 * @return  array<string, mixed>|null  Decoded object, or null after recording the failure.
 *
 * @since   2.0.0
 */
function dependencyManifest(string $path, array &$errors): ?array
{
    if (!is_file($path)) {
        $errors[] = sprintf('Missing manifest or lock: %s', $path);

        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        $errors[] = sprintf('Unreadable manifest or lock: %s', $path);

        return null;
    }
    /** @var mixed $decoded */
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $errors[] = sprintf('Malformed JSON manifest or lock: %s (%s)', $path, json_last_error_msg());

        return null;
    }

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

/**
 * Decide whether a dependency specifier is one exact semantic version.
 *
 * @param   string  $specifier  Manifest or lock version.
 *
 * @return  bool  True for a release coordinate without range, branch, alias, or URL semantics.
 *
 * @since   2.0.0
 */
function exactDependencyVersion(string $specifier): bool
{
    return preg_match(
        '/^v?\d+\.\d+\.\d+(-[0-9A-Za-z]+(\.[0-9A-Za-z]+)*)?(\+[0-9A-Za-z]+(\.[0-9A-Za-z]+)*)?$/D',
        $specifier,
    ) === 1;
}

/**
 * Read a Composer inline alias that binds one exact release to one exact alias version.
 *
 * Only the `X.Y.Z as A.B.C` form with two plain releases is admitted: the real release stays an immutable
 * coordinate, and the alias only lets a sibling library whose constraint predates that release resolve.
 * Branches, ranges and pre-release suffixes on either side are refused.
 *
 * @param   string  $specifier  Manifest version.
 *
 * @return  array{version: string, alias: string}|null  The exact release and its alias, or null.
 *
 * @since   2.0.0
 */
function exactDependencyAlias(string $specifier): ?array
{
    if (preg_match('/^v?(\d+\.\d+\.\d+) as (\d+\.\d+\.\d+)$/D', $specifier, $matches) !== 1) {
        return null;
    }

    return ['version' => $matches[1], 'alias' => $matches[2]];
}

/**
 * Compare exact versions without treating Composer's conventional leading `v` as a different release.
 *
 * @param   string  $version  Exact version.
 *
 * @return  string  Normalized exact coordinate.
 *
 * @since   2.0.0
 */
function normalizedDependencyVersion(string $version): string
{
    return str_starts_with($version, 'v') ? substr($version, 1) : $version;
}

/**
 * Read and validate the three direct first-party Composer pins.
 *
 * @param   array<string, mixed>   $manifest      Decoded composer.json.
 * @param   array<string, string>  $repositories  Package names mapped to official GitHub repositories.
 * @param   list<string>           $errors        Accumulated failures.
 * @param   array<string, string>  $aliases       Exact alias versions declared beside a pin, by package name.
 *
 * @return  array<string, string>  Exact versions by package name.
 *
 * @since   2.0.0
 */
function verifyComposerManifest(array $manifest, array $repositories, array &$errors, array &$aliases = []): array
{
    $pins = [];
    foreach (['require', 'require-dev'] as $section) {
        /** @var mixed $dependencies */
        $dependencies = $manifest[$section] ?? [];
        if (!is_array($dependencies)) {
            $errors[] = sprintf('composer.json declares %s but it is not an object.', $section);
            continue;
        }
        foreach ($dependencies as $name => $specifier) {
            if (!is_string($name) || !isset($repositories[$name])) {
                continue;
            }
            if (isset($pins[$name])) {
                $errors[] = sprintf('composer.json declares %s in more than one dependency section.', $name);
                continue;
            }
            $alias = is_string($specifier) ? exactDependencyAlias($specifier) : null;
            if ($alias !== null) {
                $pins[$name] = $alias['version'];
                $aliases[$name] = $alias['alias'];
                continue;
            }
            if (!is_string($specifier) || !exactDependencyVersion($specifier)) {
                $errors[] = sprintf(
                    'composer.json %s declares %s as %s; extracted libraries require a bare exact version.',
                    $section,
                    $name,
                    dependencyPrintable($specifier),
                );
                continue;
            }
            $pins[$name] = normalizedDependencyVersion($specifier);
        }
    }

    /** @var mixed $runtime */
    $runtime = $manifest['require'] ?? null;
    foreach (array_keys($repositories) as $name) {
        if (!is_array($runtime) || !array_key_exists($name, $runtime)) {
            $errors[] = sprintf('composer.json require must directly pin extracted runtime library %s.', $name);
        }
    }

    return $pins;
}

/**
 * Bind each first-party Composer release to one official immutable GitHub commit in composer.lock.
 *
 * @param   array<string, mixed>   $lock          Decoded composer.lock.
 * @param   array<string, string>  $pins          Direct exact manifest versions.
 * @param   array<string, string>  $repositories  Package names mapped to official GitHub repositories.
 * @param   array<string, string>  $aliases       Exact alias versions composer.json declares, by package name.
 * @param   list<string>           $errors        Accumulated failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function verifyComposerLock(array $lock, array $pins, array $repositories, array $aliases, array &$errors): void
{
    verifyComposerLockAliases($lock, $pins, $repositories, $aliases, $errors);
    $entries = [];
    foreach (['packages', 'packages-dev'] as $section) {
        /** @var mixed $packages */
        $packages = $lock[$section] ?? [];
        if (!is_array($packages) || !array_is_list($packages)) {
            $errors[] = sprintf('composer.lock %s must be an array.', $section);
            continue;
        }
        foreach ($packages as $index => $package) {
            if (!is_array($package)) {
                $errors[] = sprintf('composer.lock %s entry %d is not an object.', $section, $index);
                continue;
            }
            $name = $package['name'] ?? null;
            if (!is_string($name) || !isset($repositories[$name])) {
                continue;
            }
            if (isset($entries[$name])) {
                $errors[] = sprintf('composer.lock records %s more than once.', $name);
                continue;
            }
            $entries[$name] = $package;
        }
    }

    foreach ($repositories as $name => $repository) {
        $entry = $entries[$name] ?? null;
        if (!is_array($entry)) {
            $errors[] = sprintf('composer.lock does not contain the required first-party package %s.', $name);
            continue;
        }
        $version = $entry['version'] ?? null;
        if (!is_string($version) || !exactDependencyVersion($version)) {
            $errors[] = sprintf(
                'composer.lock records %s with non-exact version %s.',
                $name,
                dependencyPrintable($version),
            );
        } elseif (isset($pins[$name]) && normalizedDependencyVersion($version) !== $pins[$name]) {
            $errors[] = sprintf(
                'composer.lock records %s at %s but composer.json pins %s.',
                $name,
                $version,
                $pins[$name],
            );
        }

        $source = $entry['source'] ?? null;
        $officialSource = sprintf('https://github.com/kumwe/%s.git', $repository);
        if (!is_array($source)) {
            $errors[] = sprintf('composer.lock %s has no source record.', $name);
            continue;
        }
        if (($source['type'] ?? null) !== 'git') {
            $errors[] = sprintf('composer.lock %s source type must be git.', $name);
        }
        if (($source['url'] ?? null) !== $officialSource) {
            $errors[] = sprintf(
                'composer.lock %s source URL must be %s, got %s.',
                $name,
                $officialSource,
                dependencyPrintable($source['url'] ?? null),
            );
        }
        $reference = $source['reference'] ?? null;
        if (!is_string($reference) || preg_match('/^[0-9a-f]{40}$/D', $reference) !== 1) {
            $errors[] = sprintf(
                'composer.lock %s source reference must be one immutable 40-character commit, got %s.',
                $name,
                dependencyPrintable($reference),
            );
            continue;
        }

        $dist = $entry['dist'] ?? null;
        $officialDist = sprintf('https://api.github.com/repos/kumwe/%s/zipball/%s', $repository, $reference);
        if (!is_array($dist)) {
            $errors[] = sprintf('composer.lock %s has no dist record.', $name);
            continue;
        }
        if (($dist['type'] ?? null) !== 'zip') {
            $errors[] = sprintf('composer.lock %s dist type must be zip.', $name);
        }
        if (($dist['reference'] ?? null) !== $reference) {
            $errors[] = sprintf('composer.lock %s source and dist references differ.', $name);
        }
        if (($dist['url'] ?? null) !== $officialDist) {
            $errors[] = sprintf(
                'composer.lock %s dist URL must be %s, got %s.',
                $name,
                $officialDist,
                dependencyPrintable($dist['url'] ?? null),
            );
        }
    }
}

/**
 * Bind the lock's alias list to exactly the exact aliases composer.json declares for first-party packages.
 *
 * Composer writes one `aliases` record per root alias, carrying the normalized real version and the alias.
 * A first-party alias the manifest does not declare, a declared alias the lock does not record, or a record
 * whose real version differs from the pin all fail, so the alias can never widen beyond one exact release.
 *
 * @param   array<string, mixed>   $lock          Decoded composer.lock.
 * @param   array<string, string>  $pins          Direct exact manifest versions.
 * @param   array<string, string>  $repositories  Package names mapped to official GitHub repositories.
 * @param   array<string, string>  $aliases       Exact alias versions composer.json declares, by package name.
 * @param   list<string>           $errors        Accumulated failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function verifyComposerLockAliases(
    array $lock,
    array $pins,
    array $repositories,
    array $aliases,
    array &$errors,
): void {
    /** @var mixed $records */
    $records = $lock['aliases'] ?? [];
    if (!is_array($records) || !array_is_list($records)) {
        $errors[] = 'composer.lock aliases must be an array.';

        return;
    }
    $recorded = [];
    foreach ($records as $index => $record) {
        $name = is_array($record) ? ($record['package'] ?? null) : null;
        if (!is_string($name) || !isset($repositories[$name])) {
            continue;
        }
        if (!is_array($record) || isset($recorded[$name])) {
            $errors[] = sprintf('composer.lock aliases entry %d repeats or malforms %s.', $index, $name);
            continue;
        }
        $declared = $aliases[$name] ?? null;
        if ($declared === null) {
            $errors[] = sprintf(
                'composer.lock aliases %s as %s but composer.json declares no exact alias.',
                $name,
                dependencyPrintable($record['alias'] ?? null),
            );
            continue;
        }
        $expectedVersion = isset($pins[$name]) ? $pins[$name] . '.0' : null;
        if (
            ($record['alias'] ?? null) !== $declared
            || ($record['alias_normalized'] ?? null) !== $declared . '.0'
            || ($record['version'] ?? null) !== $expectedVersion
        ) {
            $errors[] = sprintf(
                'composer.lock aliases %s as %s from %s but composer.json declares %s as %s.',
                $name,
                dependencyPrintable($record['alias'] ?? null),
                dependencyPrintable($record['version'] ?? null),
                $pins[$name] ?? 'nothing',
                $declared,
            );
        }
        $recorded[$name] = true;
    }
    foreach ($aliases as $name => $alias) {
        if (!isset($recorded[$name])) {
            $errors[] = sprintf(
                'composer.json declares %s as %s but composer.lock records no such alias; regenerate the lock.',
                $name,
                $alias,
            );
        }
    }
}

/**
 * Read and validate every coordinated Studio package specifier in package.json.
 *
 * @param   array<string, mixed>  $manifest  Decoded package.json.
 * @param   list<string>          $required  Required coordinated Studio packages.
 * @param   list<string>          $errors    Accumulated failures.
 *
 * @return  array<string, array{specifier: string, version: string}>  Exact Studio pins by package name.
 *
 * @since   2.0.0
 */
function verifyNpmManifest(array $manifest, array $required, array &$errors): array
{
    $pins = [];
    foreach (['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies'] as $section) {
        /** @var mixed $dependencies */
        $dependencies = $manifest[$section] ?? [];
        if (!is_array($dependencies)) {
            $errors[] = sprintf('package.json declares %s but it is not an object.', $section);
            continue;
        }
        foreach ($dependencies as $name => $specifier) {
            if (!is_string($name) || !str_starts_with($name, '@kumwe/studio')) {
                continue;
            }
            if (isset($pins[$name])) {
                $errors[] = sprintf('package.json declares %s in more than one dependency section.', $name);
                continue;
            }
            if (!is_string($specifier)) {
                $errors[] = sprintf('package.json %s pins %s to a non-string specifier.', $section, $name);
                continue;
            }
            $version = npmDependencyVersion($name, $specifier);
            if ($version === null) {
                $errors[] = sprintf(
                    'package.json %s declares %s as "%s"; Studio requires an exact version or its exact '
                    . 'resources/studio-contract/packages tarball.',
                    $section,
                    $name,
                    $specifier,
                );
                continue;
            }
            $pins[$name] = ['specifier' => $specifier, 'version' => $version];
        }
    }

    foreach ($required as $name) {
        if (!isset($pins[$name])) {
            $errors[] = sprintf('package.json must pin coordinated Studio package %s.', $name);
        }
    }

    return $pins;
}

/**
 * Resolve the exact version carried by an npm version or a canonical vendored tarball specifier.
 *
 * @param   string  $name       npm package name.
 * @param   string  $specifier  npm dependency specifier.
 *
 * @return  string|null  Exact normalized version, or null for ranges, branches, aliases, and URLs.
 *
 * @since   2.0.0
 */
function npmDependencyVersion(string $name, string $specifier): ?string
{
    if (exactDependencyVersion($specifier)) {
        return normalizedDependencyVersion($specifier);
    }

    $slug = str_replace(['@', '/'], ['', '-'], $name);
    $pattern = '/^file:resources\/studio-contract\/packages\/' . preg_quote($slug, '/')
        . '-(\d+\.\d+\.\d+(?:-[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?'
        . '(?:\+[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?)\.tgz$/D';
    if (preg_match($pattern, $specifier, $matches) !== 1) {
        return null;
    }

    return $matches[1];
}

/**
 * Bind package.json's Studio pins to package-lock versions, local paths, and integrity records.
 *
 * @param   array<string, mixed>                                  $lock      Decoded package-lock.json.
 * @param   array<string, array{specifier: string, version: string}>  $pins   Exact manifest pins.
 * @param   list<string>                                          $required  Required package names.
 * @param   list<string>                                          $errors    Accumulated failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function verifyNpmLock(array $lock, array $pins, array $required, array &$errors): void
{
    /** @var mixed $packages */
    $packages = $lock['packages'] ?? null;
    if (!is_array($packages)) {
        $errors[] = 'package-lock.json must declare a packages object.';

        return;
    }
    /** @var mixed $root */
    $root = $packages[''] ?? null;
    if (!is_array($root)) {
        $errors[] = 'package-lock.json must carry the root package record.';
        $root = [];
    }
    $rootSpecifiers = [];
    foreach (['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies'] as $section) {
        /** @var mixed $dependencies */
        $dependencies = $root[$section] ?? [];
        if (!is_array($dependencies)) {
            $errors[] = sprintf('package-lock.json root %s must be an object.', $section);
            continue;
        }
        foreach ($dependencies as $name => $specifier) {
            if (is_string($name) && str_starts_with($name, '@kumwe/studio') && is_string($specifier)) {
                $rootSpecifiers[$name] = $specifier;
            }
        }
    }

    foreach ($required as $name) {
        $pin = $pins[$name] ?? null;
        if (!is_array($pin)) {
            continue;
        }
        if (($rootSpecifiers[$name] ?? null) !== $pin['specifier']) {
            $errors[] = sprintf(
                'package-lock.json root records %s as %s but package.json pins "%s".',
                $name,
                dependencyPrintable($rootSpecifiers[$name] ?? null),
                $pin['specifier'],
            );
        }
        $entry = $packages['node_modules/' . $name] ?? null;
        if (!is_array($entry)) {
            $errors[] = sprintf('package-lock.json does not contain %s.', $name);
            continue;
        }
        $version = $entry['version'] ?? null;
        if (!is_string($version) || !exactDependencyVersion($version)) {
            $errors[] = sprintf(
                'package-lock.json records %s with non-exact version %s.',
                $name,
                dependencyPrintable($version),
            );
        } elseif (normalizedDependencyVersion($version) !== $pin['version']) {
            $errors[] = sprintf(
                'package-lock.json records %s at %s but package.json pins %s.',
                $name,
                $version,
                $pin['version'],
            );
        }

        $resolved = $entry['resolved'] ?? null;
        $expected = str_starts_with($pin['specifier'], 'file:')
            ? $pin['specifier']
            : officialNpmTarball($name, $pin['version']);
        if ($resolved !== $expected) {
            $errors[] = sprintf(
                'package-lock.json %s resolved target must be %s, got %s.',
                $name,
                $expected,
                dependencyPrintable($resolved),
            );
        }
        $integrity = $entry['integrity'] ?? null;
        if (!is_string($integrity) || preg_match('/^sha512-[A-Za-z0-9+\/=]+$/D', $integrity) !== 1) {
            $errors[] = sprintf('package-lock.json %s must carry a sha512 integrity.', $name);
        }

        /** @var mixed $nested */
        $nested = $entry['dependencies'] ?? [];
        if (!is_array($nested)) {
            $errors[] = sprintf('package-lock.json %s dependencies must be an object.', $name);
            continue;
        }
        foreach ($nested as $dependency => $specifier) {
            if (!is_string($dependency) || !str_starts_with($dependency, '@kumwe/studio')) {
                continue;
            }
            if (!is_string($specifier) || !exactDependencyVersion($specifier)) {
                $errors[] = sprintf(
                    'package-lock.json %s depends on %s through non-exact specifier %s.',
                    $name,
                    $dependency,
                    dependencyPrintable($specifier),
                );
            }
        }
    }
}

/**
 * Build the only accepted remote npm tarball coordinate for a published Studio package.
 *
 * @param   string  $name     npm package name.
 * @param   string  $version  Exact version.
 *
 * @return  string  Official registry tarball URL.
 *
 * @since   2.0.0
 */
function officialNpmTarball(string $name, string $version): string
{
    $leaf = substr($name, strrpos($name, '/') + 1);

    return sprintf('https://registry.npmjs.org/%s/-/%s-%s.tgz', $name, $leaf, $version);
}

/**
 * Render an arbitrary decoded value safely in one-line diagnostics.
 *
 * @param   mixed  $value  Value to render.
 *
 * @return  string  JSON representation, or the value's type if encoding fails.
 *
 * @since   2.0.0
 */
function dependencyPrintable(mixed $value): string
{
    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return is_string($encoded) ? $encoded : get_debug_type($value);
}

/**
 * Print every coordinate failure and stop before Producer alignment can claim success.
 *
 * @param   list<string>  $errors  Accumulated failures.
 *
 * @return  never
 *
 * @since   2.0.0
 */
function reportDependencyFailure(array $errors): never
{
    fwrite(STDERR, "Kumwe first-party dependency verification failed:\n");
    foreach (array_values(array_unique($errors)) as $error) {
        fwrite(STDERR, ' - ' . $error . "\n");
    }
    exit(1);
}
