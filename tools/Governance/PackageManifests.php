<?php

declare(strict_types=1);

namespace Kumwe\App\Tools\Governance;

/**
 * Reads one installed Kumwe package and decides whether it is Version 2 manifested or a legacy release.
 *
 * A package claims Version 2 governance as soon as it ships any of `resources/capabilities/v1.json`,
 * `resources/service-map/v1.json`, `docs/release-record.md`, `MIGRATION-HANDOFF.md`, or a public API carrying the
 * Version 2 schema string. A claim is all-or-nothing: every manifest and the handoff must then exist, validate
 * against its schema and agree with the others, or the package is refused rather than downgraded to legacy.
 * A package that ships none of them is `legacy-unmanifested`; its public symbols come from the pre-Version-2
 * manifest it does ship (`resources/public-api.json` schema 2, or a schema-1 `resources/public-api/v1.json`)
 * and otherwise from a PSR-4 source scan that excludes `@internal` declarations.
 *
 * Only inputs a release archive is guaranteed to ship are read for a legacy package: `composer.json`,
 * `resources/` and `src/`. CHARTER.md, README.md and `docs/` are never probed, so the index digest is the same
 * whether the package was installed from a distribution archive that export-ignores them or from source. A
 * Version 2 package must ship every document its handoff names, and the gate fails closed when it does not.
 *
 * @since  2.0.0
 */
final readonly class PackageManifests
{
    /**
     * Narrative sections every handoff body carries, as H2 headings, in this order (Kumwe-v2-08).
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const HANDOFF_SECTIONS = [
        'Migration/implementation summary',
        'Public API and responsibility',
        'Capability reuse/semantic input review',
        'Consumer inventory',
        'Test ownership',
        'Next-task execution notes',
        'Drift check',
        'Validation recipe and observed local results',
    ];

    /**
     * Production release-record narrative sections, in order.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const RELEASE_RECORD_SECTIONS = [
        'Package contract',
        'Public API and responsibility',
        'Dependencies and semantic inputs',
        'Consumer contract',
        'Test ownership',
        'Consumer verification',
        'Compatibility and drift',
        'Validation',
    ];

    /**
     * Handoff `framework_php` fields naming the manifests, mapped to the `MANIFEST_PATHS` keys.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const HANDOFF_MANIFEST_FIELDS = [
        'public_api_manifest' => 'public_api',
        'capability_manifest' => 'capabilities',
        'service_map' => 'service_map',
    ];

    /**
     * Standard manifest paths inside a Version 2 package.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    public const MANIFEST_PATHS = [
        'public_api' => 'resources/public-api/v1.json',
        'capabilities' => 'resources/capabilities/v1.json',
        'service_map' => 'resources/service-map/v1.json',
    ];

    /**
     * Keep the findings of one package read.
     *
     * @param string $name Composer package name.
     * @param string $display Repository-relative package root.
     * @param array<string, mixed> $composer Decoded installed composer.json.
     * @param array<string, list<string>> $psr4 PSR-4 roots to directories.
     * @param string|null $charterPath Repository-relative CHARTER.md when present.
     * @param string|null $charterSummary First paragraph after the charter H1.
     * @param string|null $readmePath Repository-relative README.md when present.
     * @param array<string, mixed>|null $publicApi Decoded Version 2 public API manifest.
     * @param array<string, mixed>|null $capabilities Decoded Version 2 capabilities manifest.
     * @param array<string, mixed>|null $serviceMap Decoded Version 2 service map.
     * @param  array{path: string, sha256: string, front_matter: array<string, mixed>, body: string}|null  $handoff
     *         The validated handoff.
     * @param string $manifestStatus `v2-manifested` or `legacy-unmanifested`.
     * @param list<string> $publicSymbols Exported symbols, sorted.
     * @param string $publicSymbolsSource `manifest:<path>` or `source-scan`.
     * @param string $publicApiDigest `sha256:<hex>` of the symbol source.
     * @param string|null $publicApiPath Repository-relative manifest the symbols came from.
     * @param list<array<string, mixed>> $declarations Source-scan declarations, when scanned.
     *
     * @since  2.0.0
     */
    private function __construct(
        private string $name,
        private string $display,
        private array $composer,
        private array $psr4,
        private ?string $charterPath,
        private ?string $charterSummary,
        private ?string $readmePath,
        private ?array $publicApi,
        private ?array $capabilities,
        private ?array $serviceMap,
        private ?array $handoff,
        private string $manifestStatus,
        private array $publicSymbols,
        private string $publicSymbolsSource,
        private string $publicApiDigest,
        private ?string $publicApiPath,
        private array $declarations,
    ) {
    }

    /**
     * Read one installed package root.
     *
     * @param   string  $packageRoot      Absolute path of `vendor/kumwe/<name>`.
     * @param   string  $display          Repository-relative form of the same path, for messages and the index.
     * @param   string  $schemaDirectory  Absolute path of the governance schema directory.
     *
     * @return  self  The package's manifests and status.
     *
     * @throws GovernanceViolation When the package is unreadable, claims Version 2 incompletely or contradicts itself.
     *
     * @since   2.0.0
     */
    public static function read(string $packageRoot, string $display, string $schemaDirectory): self
    {
        if (!is_dir($packageRoot)) {
            throw GovernanceViolation::at(
                $display,
                'the installed package directory is missing',
                'install locked dependencies first',
            );
        }
        $composer = self::json($packageRoot . '/composer.json', $display . '/composer.json');
        $name = $composer['name'] ?? null;
        if (!is_string($name) || preg_match('/^kumwe\/[a-z0-9-]+$/', $name) !== 1) {
            throw GovernanceViolation::at(
                $display . '/composer.json',
                'the package has no kumwe/* name',
                'repair the package',
            );
        }
        $psr4 = [];
        $autoload = is_array($composer['autoload'] ?? null) ? ($composer['autoload']['psr-4'] ?? null) : null;
        foreach (is_array($autoload) ? $autoload : [] as $namespace => $directories) {
            if (is_string($namespace)) {
                $list = is_array($directories) ? array_values($directories) : [$directories];
                $psr4[$namespace] = array_values(array_filter($list, 'is_string'));
            }
        }
        ksort($psr4, SORT_STRING);
        if ($psr4 === []) {
            throw GovernanceViolation::at(
                $display . '/composer.json',
                'no autoload.psr-4 root is declared',
                'declare the namespace',
            );
        }

        $validator = new SchemaValidator();
        $publicApiFile = $packageRoot . '/' . self::MANIFEST_PATHS['public_api'];
        $publicApiRaw = is_file(
            $publicApiFile,
        ) ? self::json($publicApiFile, $display . '/' . self::MANIFEST_PATHS['public_api']) : null;
        $claimsVersion2 = is_file($packageRoot . '/' . self::MANIFEST_PATHS['capabilities'])
            || is_file($packageRoot . '/' . self::MANIFEST_PATHS['service_map'])
            || is_file($packageRoot . '/MIGRATION-HANDOFF.md')
            || is_file($packageRoot . '/docs/release-record.md')
            || ($publicApiRaw !== null && ($publicApiRaw['schema'] ?? null) === 'kumwe-package-public-api/v1');

        if ($claimsVersion2) {
            $charterPath = is_file($packageRoot . '/CHARTER.md') ? $display . '/CHARTER.md' : null;
            $charterSummary = $charterPath === null ? null : self::readCharterSummary($packageRoot . '/CHARTER.md');
            $readmePath = is_file($packageRoot . '/README.md') ? $display . '/README.md' : null;

            return self::version2(
                $packageRoot,
                $display,
                $name,
                $composer,
                $psr4,
                $charterPath,
                $charterSummary,
                $readmePath,
                $validator,
                $schemaDirectory,
            );
        }

        return self::legacy($packageRoot, $display, $name, $composer, $psr4, $publicApiRaw);
    }

    /**
     * Read a package that claims Version 2 governance, requiring the complete manifest set.
     *
     * @param   string                       $packageRoot      Absolute package root.
     * @param   string                       $display          Repository-relative package root.
     * @param   string                       $name             Package name.
     * @param   array<string, mixed>         $composer         Decoded composer.json.
     * @param   array<string, list<string>>  $psr4             PSR-4 roots.
     * @param   string|null                  $charterPath      CHARTER.md path when present.
     * @param   string|null                  $charterSummary   Charter summary when present.
     * @param   string|null                  $readmePath       README.md path when present.
     * @param   SchemaValidator              $validator        Validator.
     * @param   string                       $schemaDirectory  Schema directory.
     *
     * @return  self  A `v2-manifested` package.
     *
     * @throws  GovernanceViolation  When a manifest is missing, invalid or inconsistent with the others.
     *
     * @since   2.0.0
     */
    private static function version2(
        string $packageRoot,
        string $display,
        string $name,
        array $composer,
        array $psr4,
        ?string $charterPath,
        ?string $charterSummary,
        ?string $readmePath,
        SchemaValidator $validator,
        string $schemaDirectory,
    ): self {
        $manifests = [];
        foreach (self::MANIFEST_PATHS as $key => $relative) {
            $path = $packageRoot . '/' . $relative;
            if (!is_file($path)) {
                throw GovernanceViolation::at(
                    $display . '/' . $relative,
                    sprintf('%s claims Version 2 governance but ships no %s', $name, $relative),
                    'ship all three manifests and MIGRATION-HANDOFF.md, or none of them for a legacy release',
                );
            }
            $decoded = self::json($path, $display . '/' . $relative);
            $schema = $schemaDirectory . '/package-' . str_replace('_', '-', $key) . '.v1.schema.json';
            self::assertValid($validator, $decoded, $schema, $display . '/' . $relative);
            if (($decoded['package'] ?? null) !== $name) {
                throw GovernanceViolation::at(
                    $display . '/' . $relative,
                    sprintf(
                        'the manifest names package "%s" but the installed package is %s',
                        self::string($decoded['package'] ?? null),
                        $name,
                    ),
                    'regenerate the manifest for this package',
                );
            }
            $manifests[$key] = $decoded;
        }
        $releases = array_unique(
            array_map(static fn (array $manifest): string => self::string($manifest['release'] ?? null), $manifests),
        );
        if (count($releases) !== 1) {
            throw GovernanceViolation::at(
                $display . '/resources',
                sprintf('the three manifests disagree on the release (%s)', implode(', ', $releases)),
                'regenerate the manifests from one release',
            );
        }

        /** @var array<string, mixed> $publicApi */
        $publicApi = $manifests['public_api'];
        /** @var array<string, mixed> $capabilities */
        $capabilities = $manifests['capabilities'];
        /** @var array<string, mixed> $serviceMap */
        $serviceMap = $manifests['service_map'];
        $roots = array_keys($psr4);
        foreach ([$publicApi, $capabilities] as $manifest) {
            if (!in_array($manifest['namespace'] ?? null, $roots, true)) {
                throw GovernanceViolation::at(
                    $display . '/resources',
                    sprintf(
                        'manifest namespace "%s" is not a PSR-4 root of the package',
                        self::string($manifest['namespace'] ?? null),
                    ),
                    'declare the canonical namespace under autoload.psr-4 and in the manifests identically',
                );
            }
        }

        /** @var array<string, array<string, mixed>> $symbols */
        $symbols = $publicApi['symbols'];
        $publicSymbols = array_keys($symbols);
        sort($publicSymbols, SORT_STRING);
        foreach ($publicSymbols as $symbol) {
            if (!self::underRoots($symbol, $roots)) {
                throw GovernanceViolation::at(
                    $display . '/' . self::MANIFEST_PATHS['public_api'],
                    sprintf('exported symbol %s is outside the package namespace', $symbol),
                    'export only symbols under the package PSR-4 roots',
                );
            }
        }
        self::assertCapabilities($capabilities, $publicSymbols, $packageRoot, $display);
        self::assertServiceMap($serviceMap, $publicSymbols, $display);

        $handoff = self::readHandoff($packageRoot, $display, $name, $roots, $validator, $schemaDirectory);
        $bytes = (string) file_get_contents($packageRoot . '/' . self::MANIFEST_PATHS['public_api']);

        return new self(
            $name,
            $display,
            $composer,
            $psr4,
            $charterPath,
            $charterSummary,
            $readmePath,
            $publicApi,
            $capabilities,
            $serviceMap,
            $handoff,
            'v2-manifested',
            $publicSymbols,
            'manifest:' . self::MANIFEST_PATHS['public_api'],
            'sha256:' . hash('sha256', $bytes),
            $display . '/' . self::MANIFEST_PATHS['public_api'],
            [],
        );
    }

    /**
     * Read a package released before Version 2, deriving its public symbols from what it does ship.
     *
     * @param   string                       $packageRoot  Absolute package root.
     * @param   string                       $display      Repository-relative package root.
     * @param   string                       $name         Package name.
     * @param   array<string, mixed>         $composer     Decoded composer.json.
     * @param   array<string, list<string>>  $psr4         PSR-4 roots.
     * @param   array<string, mixed>|null    $publicApiV1  Decoded pre-Version-2 `resources/public-api/v1.json`.
     *
     * @return  self  A `legacy-unmanifested` package.
     *
     * @throws  GovernanceViolation  When the pre-Version-2 manifest or the source tree contradicts the package.
     *
     * @since   2.0.0
     */
    private static function legacy(
        string $packageRoot,
        string $display,
        string $name,
        array $composer,
        array $psr4,
        ?array $publicApiV1,
    ): self {
        $roots = array_keys($psr4);
        $candidates = ['resources/public-api.json' => null, self::MANIFEST_PATHS['public_api'] => $publicApiV1];
        foreach ($candidates as $relative => $decoded) {
            $path = $packageRoot . '/' . $relative;
            if (!is_file($path)) {
                continue;
            }
            $decoded ??= self::json($path, $display . '/' . $relative);
            $types = $decoded['types'] ?? null;
            if (!is_array($types) || ($types !== [] && array_is_list($types))) {
                throw GovernanceViolation::at(
                    $display . '/' . $relative,
                    'the pre-Version-2 manifest has no "types" object to derive public symbols from',
                    'regenerate the manifest with the package tooling',
                );
            }
            if (($decoded['package'] ?? null) !== $name) {
                throw GovernanceViolation::at(
                    $display . '/' . $relative,
                    sprintf(
                        'the manifest names package "%s" but the installed package is %s',
                        self::string($decoded['package'] ?? null),
                        $name,
                    ),
                    'regenerate the manifest for this package',
                );
            }
            $symbols = [];
            foreach (array_keys($types) as $symbol) {
                $symbol = (string) $symbol;
                if (!self::underRoots($symbol, $roots)) {
                    throw GovernanceViolation::at(
                        $display . '/' . $relative,
                        sprintf('type %s is outside the package namespace', $symbol),
                        'repair it',
                    );
                }
                $symbols[] = $symbol;
            }
            sort($symbols, SORT_STRING);

            return new self(
                $name,
                $display,
                $composer,
                $psr4,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                'legacy-unmanifested',
                $symbols,
                'manifest:' . $relative,
                'sha256:' . hash('sha256', (string) file_get_contents($path)),
                $display . '/' . $relative,
                [],
            );
        }

        $declarations = [];
        $symbols = [];
        foreach ($psr4 as $namespace => $directories) {
            foreach ($directories as $directory) {
                $sourceRoot = $packageRoot . '/' . rtrim($directory, '/');
                $scans = PhpDeclarationScanner::scanTree($sourceRoot, $display . '/' . rtrim($directory, '/'));
                foreach ($scans as $scan) {
                    foreach ($scan['declarations'] as $declaration) {
                        /** @var string $fqcn */
                        $fqcn = $declaration['fqcn'];
                        if (!str_starts_with($fqcn, $namespace)) {
                            throw GovernanceViolation::at(
                                $scan['file'],
                                sprintf('declares %s outside its PSR-4 root %s', $fqcn, $namespace),
                                'move the file under the directory that owns its namespace',
                            );
                        }
                        $declarations[] = $declaration;
                        if ($declaration['internal'] !== true) {
                            $symbols[] = $fqcn;
                        }
                    }
                }
            }
        }
        sort($symbols, SORT_STRING);

        return new self(
            $name,
            $display,
            $composer,
            $psr4,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            'legacy-unmanifested',
            $symbols,
            'source-scan',
            'sha256:' . hash('sha256', implode("\n", $symbols) . "\n"),
            null,
            $declarations,
        );
    }

    /**
     * Read and validate the handoff of a Version 2 package.
     *
     * @param   string           $packageRoot      Absolute package root.
     * @param   string           $display          Repository-relative package root.
     * @param   string           $name             Package name.
     * @param   list<string>     $roots            PSR-4 roots.
     * @param   SchemaValidator  $validator        Validator.
     * @param   string           $schemaDirectory  Schema directory.
     *
     * @return  array{path: string, sha256: string, front_matter: array<string, mixed>, body: string}  The handoff.
     *
     * @throws  GovernanceViolation  When the handoff is missing, invalid, incomplete or contradicts the package.
     *
     * @since   2.0.0
     */
    private static function readHandoff(
        string $packageRoot,
        string $display,
        string $name,
        array $roots,
        SchemaValidator $validator,
        string $schemaDirectory,
    ): array {
        $recordPath = self::releaseRecordPath($packageRoot, $display);
        $path = $packageRoot . '/' . $recordPath;
        $relative = $display . '/' . $recordPath;
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($bytes)) {
            throw GovernanceViolation::at(
                $relative,
                sprintf('%s claims Version 2 governance but ships no MIGRATION-HANDOFF.md', $name),
                'ship the handoff committed by Phase 1 (Kumwe-v2-08)',
            );
        }
        $parsed = self::parseReleaseRecord($bytes, $relative);
        $front = $parsed['front_matter'];
        $schema = $recordPath === 'docs/release-record.md'
            ? 'package-release-record.v1.schema.json' : 'migration-handoff.v2.schema.json';
        self::assertValid($validator, $front, $schemaDirectory . '/' . $schema, $relative);
        if (($front['artifact_kind'] ?? null) !== 'framework_php') {
            throw GovernanceViolation::at(
                $relative,
                sprintf(
                    'artifact_kind "%s" is not framework_php; only Composer-installed PHP packages are indexed',
                    self::string($front['artifact_kind'] ?? null),
                ),
                'index native artifacts through their own provisioning evidence',
            );
        }
        /** @var array<string, mixed> $block */
        $block = $front['framework_php'];
        if (($block['composer_package'] ?? null) !== $name) {
            throw GovernanceViolation::at(
                $relative,
                sprintf(
                    'framework_php.composer_package "%s" is not the installed package %s',
                    self::string($block['composer_package'] ?? null),
                    $name,
                ),
                'repair the handoff',
            );
        }
        $namespace = rtrim(self::string($block['canonical_namespace'] ?? null), '\\') . '\\';
        if (!in_array($namespace, $roots, true)) {
            throw GovernanceViolation::at(
                $relative,
                sprintf('framework_php.canonical_namespace "%s" is not a PSR-4 root of the package', $namespace),
                'declare the canonical namespace identically in composer.json and the handoff',
            );
        }
        foreach (self::HANDOFF_MANIFEST_FIELDS as $field => $key) {
            if (($block[$field] ?? null) !== self::MANIFEST_PATHS[$key]) {
                throw GovernanceViolation::at(
                    $relative,
                    sprintf('framework_php.%s must be %s', $field, self::MANIFEST_PATHS[$key]),
                    'ship the manifests at the standard paths and name them so in the handoff',
                );
            }
        }
        /** @var array<string, mixed> $ownership */
        $ownership = $front['ownership'];
        /** @var list<array{path: string, sha256: string}> $manifests */
        $manifests = $ownership['public_manifests'];
        foreach ($manifests as $manifest) {
            $file = $packageRoot . '/' . $manifest['path'];
            if (!is_file($file)) {
                throw GovernanceViolation::at(
                    $relative,
                    sprintf('public manifest %s does not exist in the package', $manifest['path']),
                    'repair the handoff',
                );
            }
            $actual = hash('sha256', (string) file_get_contents($file));
            if ($actual !== $manifest['sha256']) {
                throw GovernanceViolation::at(
                    $relative,
                    sprintf(
                        'public manifest %s has digest %s but the handoff records %s',
                        $manifest['path'],
                        $actual,
                        $manifest['sha256'],
                    ),
                    'a handoff and its manifests are released together; regenerate the handoff digests',
                );
            }
        }
        /** @var array<string, mixed> $documentation */
        $documentation = $front['documentation'];
        foreach (['charter', 'readme', 'public_api', 'architecture', 'integration_or_consumer'] as $field) {
            $document = self::string($documentation[$field] ?? null);
            if (!is_file($packageRoot . '/' . $document)) {
                throw GovernanceViolation::at(
                    $relative,
                    sprintf('documentation.%s names %s, which the installed package does not ship', $field, $document),
                    'a Version 2 release archive must ship CHARTER.md, README.md, docs/, resources/ and '
                    . 'MIGRATION-HANDOFF.md (no export-ignore); a release that omits them fails this gate at adoption',
                );
            }
        }
        $sections = $recordPath === 'docs/release-record.md'
            ? self::RELEASE_RECORD_SECTIONS : self::HANDOFF_SECTIONS;
        foreach ($sections as $section) {
            if (preg_match('/^##\s+(?:[0-9]+\.\s+)?' . preg_quote($section, '/') . '\s*$/m', $parsed['body']) !== 1) {
                throw GovernanceViolation::at(
                    $relative,
                    sprintf('the narrative section "## %s" is missing', $section),
                    'write the eight narrative sections of Kumwe-v2-08 as H2 headings in that order',
                );
            }
        }

        return [
            'path' => $relative,
            'sha256' => hash('sha256', $bytes),
            'front_matter' => $front,
            'body' => $parsed['body'],
        ];
    }

    /**
     * Select exactly one installed production record or immutable legacy handoff.
     *
     * @param   string  $packageRoot  Absolute installed package root.
     * @param   string  $display      Package path for diagnostics.
     *
     * @return  string  The recognized package-relative record path.
     *
     * @throws  GovernanceViolation  When neither or both records are present.
     *
     * @since   2.0.0
     */
    public static function releaseRecordPath(string $packageRoot, string $display): string
    {
        $paths = array_values(array_filter(
            ['docs/release-record.md', 'MIGRATION-HANDOFF.md'],
            static fn (string $path): bool => is_file($packageRoot . '/' . $path),
        ));
        if (count($paths) !== 1) {
            throw GovernanceViolation::at(
                $display,
                $paths === [] ? 'ships no MIGRATION-HANDOFF.md or docs/release-record.md'
                    : 'ships both a production release record and a legacy handoff',
                'ship exactly one recognized record; do not allow ambiguous package contracts',
            );
        }

        return $paths[0];
    }

    /**
     * Parse a current record as block YAML or JSON-compatible YAML, preserving strict duplicate-key refusal.
     *
     * Legacy records retain the existing StrictYaml subset. JSON is accepted only for the production path.
     * Counting object-member tokens before and after decoding rejects overwritten duplicate keys, including
     * escaped spellings and duplicates inside nested objects. Quoted value contents are consumed as one token.
     *
     * @param   string  $bytes     Complete markdown record.
     * @param   string  $relative  Installed record path for diagnostics and format selection.
     *
     * @return  array{front_matter: array<string, mixed>, body: string}  Parsed contract and prose.
     *
     * @throws  GovernanceViolation  When JSON is malformed, duplicated or not an object.
     *
     * @since   2.0.0
     */
    public static function parseReleaseRecord(string $bytes, string $relative): array
    {
        if (
            !str_ends_with($relative, '/docs/release-record.md')
            || preg_match('/\A---\r?\n(\{[\s\S]*?)\r?\n---(?:\r?\n|\z)/', $bytes, $match) !== 1
        ) {
            return StrictYaml::parseFrontMatter($bytes, $relative);
        }
        try {
            $object = json_decode($match[1], false, 512, JSON_THROW_ON_ERROR);
            if (!$object instanceof \stdClass) {
                throw new \JsonException('the JSON front matter must be an object');
            }
            $encoded = json_encode($object, JSON_THROW_ON_ERROR);
            $pattern = '/"(?:[^"\\\\]|\\\\.)*"\s*(:)?/s';
            preg_match_all($pattern, $match[1], $originalTokens);
            preg_match_all($pattern, $encoded, $decodedTokens);
            if (count(array_filter($originalTokens[1])) !== count(array_filter($decodedTokens[1]))) {
                throw new \JsonException('duplicate object keys in JSON front matter');
            }
            /** @var array<string, mixed> $front */
            $front = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw GovernanceViolation::at($relative, $exception->getMessage(), 'write unambiguous JSON front matter');
        }

        return ['front_matter' => $front, 'body' => substr($bytes, strlen($match[0]))];
    }

    /**
     * Prove the capabilities manifest only names exported symbols and existing documents.
     *
     * @param   array<string, mixed>  $capabilities   Decoded capabilities manifest.
     * @param   list<string>          $publicSymbols  Exported symbols.
     * @param   string                $packageRoot    Absolute package root.
     * @param   string                $display        Repository-relative package root.
     *
     * @return  void
     *
     * @throws  GovernanceViolation  When an id repeats, a symbol is not exported or a document is missing.
     *
     * @since   2.0.0
     */
    private static function assertCapabilities(
        array $capabilities,
        array $publicSymbols,
        string $packageRoot,
        string $display,
    ): void
    {
        $file = $display . '/' . self::MANIFEST_PATHS['capabilities'];
        $seen = [];
        /** @var list<array<string, mixed>> $entries */
        $entries = $capabilities['capabilities'];
        foreach ($entries as $capability) {
            $id = self::string($capability['id'] ?? null);
            if (isset($seen[$id])) {
                throw GovernanceViolation::at(
                    $file,
                    sprintf('capability id %s is declared twice', $id),
                    'give each capability one id',
                );
            }
            $seen[$id] = true;
            /** @var list<string> $symbols */
            $symbols = $capability['symbols'];
            foreach ($symbols as $symbol) {
                if (!in_array($symbol, $publicSymbols, true)) {
                    throw GovernanceViolation::at(
                        $file,
                        sprintf('capability %s names %s, which the public API manifest does not export', $id, $symbol),
                        'export the symbol or remove it from the capability',
                    );
                }
            }
            /** @var list<string> $documents */
            $documents = $capability['documentation'];
            foreach ($documents as $document) {
                if (!is_file($packageRoot . '/' . $document)) {
                    throw GovernanceViolation::at(
                        $file,
                        sprintf('capability %s links %s, which the package does not ship', $id, $document),
                        'repair the link',
                    );
                }
            }
        }
        /** @var list<array<string, mixed>> $deprecations */
        $deprecations = $capabilities['deprecations'];
        foreach ($deprecations as $deprecation) {
            if (!in_array($deprecation['symbol'] ?? null, $publicSymbols, true)) {
                throw GovernanceViolation::at(
                    $file,
                    sprintf(
                        'deprecation of %s names a symbol the public API manifest does not export',
                        self::string($deprecation['symbol'] ?? null),
                    ),
                    'a deprecated symbol stays exported until it is removed',
                );
            }
        }
    }

    /**
     * Prove the service map is internally consistent and only names exported symbols.
     *
     * @param   array<string, mixed>  $serviceMap     Decoded service map.
     * @param   list<string>          $publicSymbols  Exported symbols.
     * @param   string                $display        Repository-relative package root.
     *
     * @return  void
     *
     * @throws  GovernanceViolation  When the provider decision or a factory/alias is not documented as required.
     *
     * @since   2.0.0
     */
    private static function assertServiceMap(array $serviceMap, array $publicSymbols, string $display): void
    {
        $file = $display . '/' . self::MANIFEST_PATHS['service_map'];
        $provider = $serviceMap['config_provider'] ?? null;
        /** @var list<array<string, string>> $factories */
        $factories = $serviceMap['factories'];
        if ($provider === null) {
            $reason = $serviceMap['provider_absence_reason'] ?? null;
            if (!is_string($reason) || trim($reason) === '') {
                throw GovernanceViolation::at(
                    $file,
                    'config_provider is null without a provider_absence_reason',
                    'state why the package ships no provider',
                );
            }
        } else {
            if ($factories === []) {
                throw GovernanceViolation::at(
                    $file,
                    'a config_provider is declared without any factory',
                    'declare the factories the provider registers',
                );
            }
            if (!in_array($provider, $publicSymbols, true)) {
                throw GovernanceViolation::at(
                    $file,
                    sprintf('config_provider %s is not an exported public symbol', self::string($provider)),
                    'export the provider',
                );
            }
        }
        $services = [];
        foreach ($factories as $factory) {
            foreach (['service', 'factory'] as $field) {
                if (!in_array($factory[$field], $publicSymbols, true)) {
                    throw GovernanceViolation::at(
                        $file,
                        sprintf('%s %s is not an exported public symbol', $field, $factory[$field]),
                        'export it',
                    );
                }
            }
            if (isset($services[$factory['service']])) {
                throw GovernanceViolation::at(
                    $file,
                    sprintf('service %s has two factories', $factory['service']),
                    'declare one factory per service',
                );
            }
            $services[$factory['service']] = true;
        }
        /** @var array<string, string> $aliases */
        $aliases = $serviceMap['aliases'];
        foreach ($aliases as $alias => $target) {
            foreach ([$alias, $target] as $symbol) {
                if (!in_array($symbol, $publicSymbols, true)) {
                    throw GovernanceViolation::at(
                        $file,
                        sprintf('alias %s => %s names %s, which is not exported', $alias, $target, $symbol),
                        'export it',
                    );
                }
            }
            if (isset($services[$alias])) {
                throw GovernanceViolation::at(
                    $file,
                    sprintf('%s is both a factory-built service and an alias', $alias),
                    'register each identifier once',
                );
            }
        }
    }

    /**
     * Validate a decoded document and turn violations into one refusal.
     *
     * @param   SchemaValidator           $validator  Validator.
     * @param   array<int|string, mixed>  $document   Decoded document.
     * @param   string                    $schema     Absolute schema path.
     * @param   string                    $display    Document path for the message.
     *
     * @return  void
     *
     * @throws  GovernanceViolation  When the document fails its schema.
     *
     * @since   2.0.0
     */
    private static function assertValid(
        SchemaValidator $validator,
        array $document,
        string $schema,
        string $display,
    ): void
    {
        $violations = $validator->validate($document, $schema);
        if ($violations !== []) {
            throw GovernanceViolation::at(
                $display,
                sprintf('fails %s: %s', basename($schema), implode('; ', $violations)),
                'repair the listed properties',
            );
        }
    }

    /**
     * The first paragraph after the H1 of a charter.
     *
     * @param   string  $path  Absolute CHARTER.md path.
     *
     * @return  string|null  The paragraph joined on single spaces, or null when the charter has none.
     *
     * @since   2.0.0
     */
    private static function readCharterSummary(string $path): ?string
    {
        $lines = preg_split('/\R/', (string) file_get_contents($path)) ?: [];
        $afterHeading = false;
        $paragraph = [];
        foreach ($lines as $line) {
            if (!$afterHeading) {
                $afterHeading = str_starts_with($line, '# ');
                continue;
            }
            if (trim($line) === '') {
                if ($paragraph !== []) {
                    break;
                }
                continue;
            }
            $paragraph[] = trim($line);
        }

        return $paragraph === [] ? null : implode(' ', $paragraph);
    }

    /**
     * Decode one JSON object file.
     *
     * @param   string  $path     Absolute path.
     * @param   string  $display  Repository-relative path for messages.
     *
     * @return  array<string, mixed>  The decoded object.
     *
     * @throws  GovernanceViolation  When the file is unreadable or not a JSON object.
     *
     * @since   2.0.0
     */
    private static function json(string $path, string $display): array
    {
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($bytes)) {
            throw GovernanceViolation::at($display, 'the file is missing or unreadable', 'restore it');
        }
        /** @var mixed $decoded */
        $decoded = json_decode($bytes, true);
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw GovernanceViolation::at($display, 'the file is not a JSON object', 'repair the JSON');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Decide whether a symbol sits under one of the PSR-4 roots.
     *
     * @param   string        $symbol  Fully qualified name.
     * @param   list<string>  $roots   Namespace roots with trailing backslashes.
     *
     * @return  bool  True when a root prefixes the symbol.
     *
     * @since   2.0.0
     */
    private static function underRoots(string $symbol, array $roots): bool
    {
        foreach ($roots as $root) {
            if (str_starts_with($symbol, $root)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Render a decoded value for a message.
     *
     * @param   mixed  $value  The value.
     *
     * @return  string  The string itself, or its JSON encoding otherwise.
     *
     * @since   2.0.0
     */
    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR);
    }

    /**
     * Package name.
     *
     * @return  string  Composer name.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Repository-relative package root.
     *
     * @return  string  Such as `vendor/kumwe/conversion`.
     *
     * @since   2.0.0
     */
    public function display(): string
    {
        return $this->display;
    }

    /**
     * Decoded installed composer.json.
     *
     * @return  array<string, mixed>  The document.
     *
     * @since   2.0.0
     */
    public function composer(): array
    {
        return $this->composer;
    }

    /**
     * PSR-4 roots declared by the installed package.
     *
     * @return  array<string, list<string>>  Namespace root to directories, sorted.
     *
     * @since   2.0.0
     */
    public function psr4(): array
    {
        return $this->psr4;
    }

    /**
     * Whether every Version 2 manifest and the handoff were present and valid.
     *
     * @return  string  `v2-manifested` or `legacy-unmanifested`.
     *
     * @since   2.0.0
     */
    public function manifestStatus(): string
    {
        return $this->manifestStatus;
    }

    /**
     * Whether the package is Version 2 manifested.
     *
     * @return  bool  True for `v2-manifested`.
     *
     * @since   2.0.0
     */
    public function isVersion2(): bool
    {
        return $this->manifestStatus === 'v2-manifested';
    }

    /**
     * Exported public symbols.
     *
     * @return  list<string>  Sorted fully qualified names.
     *
     * @since   2.0.0
     */
    public function publicSymbols(): array
    {
        return $this->publicSymbols;
    }

    /**
     * Where the public symbols came from.
     *
     * @return  string  `manifest:<package-relative path>` or `source-scan`.
     *
     * @since   2.0.0
     */
    public function publicSymbolsSource(): string
    {
        return $this->publicSymbolsSource;
    }

    /**
     * Digest of the public API source.
     *
     * @return  string  `sha256:<hex>` of the manifest bytes, or of the sorted symbol list for a source scan.
     *
     * @since   2.0.0
     */
    public function publicApiDigest(): string
    {
        return $this->publicApiDigest;
    }

    /**
     * Repository-relative path of the manifest the symbols came from.
     *
     * @return  string|null  Null for a source scan.
     *
     * @since   2.0.0
     */
    public function publicApiPath(): ?string
    {
        return $this->publicApiPath;
    }

    /**
     * Decoded Version 2 public API manifest.
     *
     * @return  array<string, mixed>|null  Null for a legacy package.
     *
     * @since   2.0.0
     */
    public function publicApi(): ?array
    {
        return $this->publicApi;
    }

    /**
     * Decoded Version 2 capabilities manifest.
     *
     * @return  array<string, mixed>|null  Null for a legacy package.
     *
     * @since   2.0.0
     */
    public function capabilities(): ?array
    {
        return $this->capabilities;
    }

    /**
     * Decoded Version 2 service map.
     *
     * @return  array<string, mixed>|null  Null for a legacy package.
     *
     * @since   2.0.0
     */
    public function serviceMap(): ?array
    {
        return $this->serviceMap;
    }

    /**
     * The validated handoff.
     *
     * @return  array{path: string, sha256: string, front_matter: array<string, mixed>, body: string}|null
     *          Null for a legacy package.
     *
     * @since   2.0.0
     */
    public function handoff(): ?array
    {
        return $this->handoff;
    }

    /**
     * Repository-relative CHARTER.md path.
     *
     * @return  string|null  Null when the package ships none.
     *
     * @since   2.0.0
     */
    public function charterPath(): ?string
    {
        return $this->charterPath;
    }

    /**
     * First paragraph after the charter H1.
     *
     * @return  string|null  Null when there is no charter or no paragraph.
     *
     * @since   2.0.0
     */
    public function charterSummary(): ?string
    {
        return $this->charterSummary;
    }

    /**
     * Repository-relative README.md path.
     *
     * @return  string|null  Null when the package ships none.
     *
     * @since   2.0.0
     */
    public function readmePath(): ?string
    {
        return $this->readmePath;
    }

    /**
     * Declarations found by the source scan of a legacy package.
     *
     * @return  list<array<string, mixed>>  Empty unless the symbols came from a source scan.
     *
     * @since   2.0.0
     */
    public function declarations(): array
    {
        return $this->declarations;
    }
}
