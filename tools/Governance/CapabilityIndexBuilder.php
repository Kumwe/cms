<?php

declare(strict_types=1);

namespace Kumwe\App\Tools\Governance;

/**
 * Builds the capability index document from the lock, the installed packages, the legacy registry and the ledger.
 *
 * The builder is a pure function of the repository tree: the same lock, vendor manifests, registry and records
 * always produce the same document, with every map and list sorted. Every rule of the index is enforced here
 * and fails closed: a locked package without its vendor directory, a legacy release the registry has not
 * approved at that exact version, two packages exporting one FQCN, one capability id or one service, a
 * provider without factories, a Version 2 package whose manifests disagree with its lock entry or ledger
 * record. The finished document is validated against `capability-index.v1.schema.json` before it is returned.
 *
 * @since  2.0.0
 */
final readonly class CapabilityIndexBuilder
{
    /**
     * Schema identity of the document.
     *
     * @var    string
     * @since  2.0.0
     */
    public const SCHEMA = 'kumwe-capability-index/v1';

    /**
     * Generator recorded in the document.
     *
     * @var    string
     * @since  2.0.0
     */
    public const GENERATOR = 'tools/generate-capability-index.php';

    /**
     * Repository-relative path of the legacy registry.
     *
     * @var    string
     * @since  2.0.0
     */
    public const LEGACY_REGISTRY = 'docs/architecture/governance/legacy-packages.json';

    /**
     * Bind the builder to a repository root.
     *
     * @param  string       $root             Absolute repository root, or a fixture root passed as `--root`.
     * @param  string|null  $schemaDirectory  Governance schema directory; defaults to this repository's.
     *
     * @since  2.0.0
     */
    public function __construct(
        private string $root,
        private ?string $schemaDirectory = null,
    ) {
    }

    /**
     * The schema directory shipped with the tools, independent of the root being indexed.
     *
     * @return  string  Absolute path of `docs/architecture/governance/schemas`.
     *
     * @since   2.0.0
     */
    public static function defaultSchemaDirectory(): string
    {
        return dirname(__DIR__, 2) . '/docs/architecture/governance/schemas';
    }

    /**
     * The schema directory this builder validates against.
     *
     * @return  string  Absolute path.
     *
     * @since   2.0.0
     */
    public function schemaDirectory(): string
    {
        return $this->schemaDirectory ?? self::defaultSchemaDirectory();
    }

    /**
     * The repository root being indexed.
     *
     * @return  string  As given to the constructor.
     *
     * @since   2.0.0
     */
    public function root(): string
    {
        return $this->root;
    }

    /**
     * Build the index document.
     *
     * @return  array<string, mixed>  The document of section 3.2, ready for `CapabilityIndexWriter`.
     *
     * @throws  GovernanceViolation  When any input is missing, malformed or breaks an index rule.
     *
     * @since   2.0.0
     */
    public function build(): array
    {
        $schemas = $this->schemaDirectory();
        $lock = ComposerLock::read($this->root . '/composer.lock');
        $registry = LegacyPackageRegistry::read($this->root . '/' . self::LEGACY_REGISTRY, $schemas);
        $records = GovernanceRecords::load($this->root, $schemas, $lock);

        $packages = [];
        $ownership = [];
        $capabilityOwners = [];
        $serviceOwners = [];
        foreach ($lock->packages() as $name => $locked) {
            $display = 'vendor/kumwe/' . substr($name, strlen('kumwe/'));
            if (!is_dir($this->root . '/' . $display)) {
                throw GovernanceViolation::at(
                    $display,
                    sprintf('%s %s is locked but not installed', $name, $locked['version']),
                    'install locked dependencies first (composer install)',
                );
            }
            $manifests = PackageManifests::read($this->root . '/' . $display, $display, $schemas);
            if ($manifests->name() !== $name) {
                throw GovernanceViolation::at(
                    $display . '/composer.json',
                    sprintf('the installed package is %s but the lock expects %s', $manifests->name(), $name),
                    'reinstall from the lock',
                );
            }
            if (array_keys($manifests->psr4()) !== array_keys($locked['psr4'])) {
                throw GovernanceViolation::at(
                    $display . '/composer.json',
                    sprintf(
                        'PSR-4 roots (%s) differ from the lock (%s)',
                        implode(', ', array_keys($manifests->psr4())),
                        implode(', ', array_keys($locked['psr4'])),
                    ),
                    'reinstall from the lock',
                );
            }
            $entry = $manifests->isVersion2()
                ? $this->version2Entry($locked, $manifests, $records)
                : $this->legacyEntry($locked, $manifests, $registry, $records);

            foreach ($entry['public_symbols'] as $symbol) {
                if (isset($ownership[$symbol])) {
                    throw GovernanceViolation::at(
                        $display,
                        sprintf(
                            'duplicate FQCN owner: %s is exported by both %s and %s',
                            $symbol,
                            $ownership[$symbol],
                            $name,
                        ),
                        'one package owns each public symbol; resolve ownership before indexing',
                    );
                }
                $ownership[$symbol] = $name;
            }
            foreach ($entry['capabilities'] as $capability) {
                if (isset($capabilityOwners[$capability['id']])) {
                    throw GovernanceViolation::at(
                        $display . '/' . PackageManifests::MANIFEST_PATHS['capabilities'],
                        sprintf(
                            'duplicate capability owner: %s is declared by both %s and %s',
                            $capability['id'],
                            $capabilityOwners[$capability['id']],
                            $name,
                        ),
                        'one package owns each capability id',
                    );
                }
                $capabilityOwners[$capability['id']] = $name;
            }
            $services = array_column($entry['dependency_injection']['factories'], 'service');
            foreach (array_merge($services, array_keys($entry['dependency_injection']['aliases'])) as $service) {
                if (isset($serviceOwners[$service])) {
                    throw GovernanceViolation::at(
                        $display . '/' . PackageManifests::MANIFEST_PATHS['service_map'],
                        sprintf(
                            'duplicate service owner: %s is registered by both %s and %s',
                            $service,
                            $serviceOwners[$service],
                            $name,
                        ),
                        'one package registers each service identifier',
                    );
                }
                $serviceOwners[$service] = $name;
            }
            $packages[] = $entry;
        }

        foreach ($registry->entries() as $name => $approved) {
            if ($lock->package($name) === null) {
                throw GovernanceViolation::at(
                    self::LEGACY_REGISTRY,
                    sprintf(
                        '%s %s is approved as a legacy entry but is not locked',
                        $name,
                        $approved['installed_version'],
                    ),
                    'remove the stale entry',
                );
            }
        }

        $extracted = [];
        $removed = [];
        foreach ($records->migrations() as $id => $entry) {
            /** @var array{package: string, old_namespace_roots: list<string>,
             *   symbols: list<array{old_fqcn: string, new_fqcn: string}>} $record */
            $record = $entry['record'];
            foreach ($record['old_namespace_roots'] as $namespace) {
                $extracted[] = ['old_namespace' => $namespace, 'package' => $record['package'], 'migration_id' => $id];
            }
            foreach ($record['symbols'] as $symbol) {
                $removed[] = [
                    'old_fqcn' => $symbol['old_fqcn'],
                    'new_fqcn' => $symbol['new_fqcn'],
                    'package' => $record['package'],
                    'migration_id' => $id,
                ];
            }
        }
        usort($extracted, static fn (array $left, array $right): int => [$left['old_namespace'], $left['migration_id']]
            <=> [$right['old_namespace'], $right['migration_id']]);
        usort($removed, static fn (array $left, array $right): int => [$left['old_fqcn'], $left['migration_id']]
            <=> [$right['old_fqcn'], $right['migration_id']]);
        ksort($ownership, SORT_STRING);

        $document = [
            'schema' => self::SCHEMA,
            'generator' => self::GENERATOR,
            'composer_lock_sha256' => $lock->sha256(),
            'packages' => $packages,
            'extracted_namespaces' => $extracted,
            'removed_symbols' => $removed,
            'ownership' => $ownership,
        ];
        $violations = (new SchemaValidator())->validate($document, $schemas . '/capability-index.v1.schema.json');
        if ($violations !== []) {
            throw GovernanceViolation::at(
                self::GENERATOR,
                'the generated index fails capability-index.v1.schema.json: ' . implode('; ', $violations),
                'this is a generator defect; report it with the inputs that produced it',
            );
        }

        return $document;
    }

    /**
     * Build the entry of a Version 2 manifested package.
     *
     * @param   array{name: string, version: string, source: array{type: string, url: string, reference: string},
     *          dist: array{type: string, url: string, reference: string}, license: list<string>,
     *          psr4: array<string, list<string>>, extra: array<string, mixed>}  $locked     Lock entry.
     * @param   PackageManifests   $manifests  Installed package.
     * @param   GovernanceRecords  $records    Governance records.
     *
     * @return  array<string, mixed>  The package entry.
     *
     * @throws  GovernanceViolation  When the manifests, handoff and ledger disagree with each other or the lock.
     *
     * @since   2.0.0
     */
    private function version2Entry(array $locked, PackageManifests $manifests, GovernanceRecords $records): array
    {
        $name = $locked['name'];
        $display = $manifests->display();
        /** @var array<string, mixed> $capabilities */
        $capabilities = $manifests->capabilities();
        /** @var array<string, mixed> $serviceMap */
        $serviceMap = $manifests->serviceMap();
        /** @var array{path: string, sha256: string, front_matter: array<string, mixed>, body: string} $handoff */
        $handoff = $manifests->handoff();

        /** @var string $release */
        $release = $capabilities['release'];
        if (ltrim($release, 'v') !== ltrim($locked['version'], 'v')) {
            throw GovernanceViolation::at(
                $display . '/resources',
                sprintf('the manifests describe release %s but %s is locked', $release, $locked['version']),
                'install the release the manifests were generated for',
            );
        }
        /** @var string $migrationId */
        $migrationId = $handoff['front_matter']['migration_id'];
        /** @var string $changeSet */
        $changeSet = $handoff['front_matter']['change_set'];
        $ledger = $records->migrationForPackage($name);
        if ($ledger === null || $ledger['id'] !== $migrationId) {
            throw GovernanceViolation::at(
                'docs/architecture/migrations/' . $migrationId . '.yaml',
                sprintf(
                    '%s is installed with a Version 2 handoff for %s but no ledger record adopts it',
                    $name,
                    $migrationId,
                ),
                'record the adoption in the migration ledger (D-GOV-2) before indexing the package',
            );
        }
        if ($ledger['record']['change_set'] !== $changeSet) {
            throw GovernanceViolation::at(
                $ledger['path'],
                sprintf(
                    'change_set %s differs from the handoff change set %s',
                    self::string($ledger['record']['change_set']),
                    $changeSet,
                ),
                'align the ledger record with the released handoff',
            );
        }
        $documents = ['CHARTER.md' => $manifests->charterPath(), 'README.md' => $manifests->readmePath()];
        foreach ($documents as $file => $path) {
            if ($path === null) {
                throw GovernanceViolation::at(
                    $display . '/' . $file,
                    sprintf('%s ships no %s', $name, $file),
                    'a Version 2 release archive must ship CHARTER.md, README.md, docs/, resources/ and '
                    . basename($handoff['path'])
                    . ' (no export-ignore); a release that omits them fails this gate at adoption',
                );
            }
        }

        $entries = [];
        /** @var list<array{id: string, title: string, symbols: list<string>, documentation: list<string>}> $declared */
        $declared = $capabilities['capabilities'];
        foreach ($declared as $capability) {
            $symbols = $capability['symbols'];
            sort($symbols, SORT_STRING);
            $documentation = array_map(
                static fn (string $path): string => $display . '/' . $path,
                $capability['documentation'],
            );
            sort($documentation, SORT_STRING);
            $entries[] = [
                'id' => $capability['id'],
                'title' => $capability['title'],
                'symbols' => $symbols,
                'documentation' => $documentation,
            ];
        }
        usort($entries, static fn (array $left, array $right): int => strcmp($left['id'], $right['id']));

        /** @var list<array{service: string, factory: string, lifetime: string}> $factories */
        $factories = $serviceMap['factories'];
        usort($factories, static fn (array $left, array $right): int => strcmp($left['service'], $right['service']));
        /** @var array<string, string> $aliases */
        $aliases = $serviceMap['aliases'];
        ksort($aliases, SORT_STRING);
        /** @var list<array{key: string, default: mixed, description: string}> $keys */
        $keys = $serviceMap['configuration_keys'];
        usort($keys, static fn (array $left, array $right): int => strcmp($left['key'], $right['key']));
        /** @var list<array{symbol: string, since: string, replacement: string|null}> $deprecations */
        $deprecations = $capabilities['deprecations'];
        usort($deprecations, static fn (array $left, array $right): int => strcmp($left['symbol'], $right['symbol']));
        /** @var array<string, mixed> $documentation */
        $documentation = $handoff['front_matter']['documentation'];

        return $this->entry($locked, $manifests, [
            'manifest_status' => 'v2-manifested',
            'legacy' => null,
            'release_gate_eligible' => true,
            'responsibility' => $capabilities['responsibility'],
            'non_responsibilities' => $capabilities['non_responsibilities'],
            'capabilities' => $entries,
            'documentation' => [
                'charter' => $manifests->charterPath(),
                'readme' => $manifests->readmePath(),
                'public_api' => $display . '/' . self::string($documentation['public_api']),
            ],
            'dependency_injection' => [
                'config_provider' => $serviceMap['config_provider'],
                'provider_absence_reason' => $serviceMap['provider_absence_reason'],
                'factories' => $factories,
                'aliases' => $aliases,
                'configuration_keys' => $keys,
            ],
            'native_requirements' => $capabilities['native_requirements'],
            'deprecations' => $deprecations,
            'handoff' => [
                'path' => $handoff['path'],
                'sha256' => $handoff['sha256'],
                'migration_id' => $migrationId,
                'change_set' => $changeSet,
            ],
        ]);
    }

    /**
     * Build the entry of an approved legacy-unmanifested package.
     *
     * @param   array{name: string, version: string, source: array{type: string, url: string, reference: string},
     *          dist: array{type: string, url: string, reference: string}, license: list<string>,
     *          psr4: array<string, list<string>>, extra: array<string, mixed>}  $locked     Lock entry.
     * @param   PackageManifests       $manifests  Installed package.
     * @param   LegacyPackageRegistry  $registry   Approved entries.
     * @param   GovernanceRecords      $records    Governance records.
     *
     * @return  array<string, mixed>  The package entry.
     *
     * @throws  GovernanceViolation  When the package is unapproved, approved at another version, named by a ledger
     *          record, or its registry entry contradicts the lock or its verified release.
     *
     * @since   2.0.0
     */
    private function legacyEntry(
        array $locked,
        PackageManifests $manifests,
        LegacyPackageRegistry $registry,
        GovernanceRecords $records,
    ): array
    {
        $name = $locked['name'];
        $display = $manifests->display();
        $approved = $registry->entry($name);
        if ($approved === null) {
            throw GovernanceViolation::at(
                $display,
                sprintf('%s is installed without Version 2 manifests and is not an approved legacy package', $name),
                'adopt Version 2 manifests in the package, or approve it in ' . self::LEGACY_REGISTRY,
            );
        }
        if ($approved['installed_version'] !== $locked['version']) {
            throw GovernanceViolation::at(
                self::LEGACY_REGISTRY,
                sprintf(
                    '%s is approved at %s but %s is locked',
                    $name,
                    $approved['installed_version'],
                    $locked['version'],
                ),
                'a re-pin re-approves the entry: record the locked version with a new approval',
            );
        }
        $ledger = $records->migrationForPackage($name);
        if ($ledger !== null) {
            throw GovernanceViolation::at(
                $ledger['path'],
                sprintf('%s is a legacy-unmanifested entry and cannot satisfy a migration release gate', $name),
                'a migration ledger record needs a Version 2 release with its handoff',
            );
        }
        $namespaces = $approved['canonical_namespaces'];
        sort($namespaces, SORT_STRING);
        if ($namespaces !== array_keys($locked['psr4'])) {
            throw GovernanceViolation::at(
                self::LEGACY_REGISTRY,
                sprintf(
                    '%s canonical_namespaces (%s) differ from the locked PSR-4 roots (%s)',
                    $name,
                    implode(', ', $namespaces),
                    implode(', ', array_keys($locked['psr4'])),
                ),
                'record exactly the autoload.psr-4 roots of the locked release',
            );
        }
        $verified = $approved['verified_legacy_release'];
        if ($verified !== null) {
            $expectedPrefix = 'docs/architecture/migrations/evidence/legacy/' . substr($name, strlen('kumwe/')) . '/';
            $evidence = $records->legacyEvidence()[$verified] ?? null;
            if (!str_starts_with($verified, $expectedPrefix) || $evidence === null) {
                throw GovernanceViolation::at(
                    self::LEGACY_REGISTRY,
                    sprintf(
                        '%s verified_legacy_release %s is not a verified legacy release record under %s',
                        $name,
                        $verified,
                        $expectedPrefix,
                    ),
                    'commit the VERIFIED-LEGACY-RELEASE.yaml at that path or set the field to null',
                );
            }
            $record = $evidence['record'];
            $recordVersion = ltrim(self::string($record['version'] ?? null), 'v');
            if (($record['status'] ?? null) !== 'verified' || $recordVersion !== ltrim($locked['version'], 'v')) {
                throw GovernanceViolation::at(
                    $verified,
                    sprintf('the record is not a verified release of %s %s', $name, $locked['version']),
                    'verify the locked release or set verified_legacy_release to null',
                );
            }
        }
        $retired = $approved['retired_app_namespaces'];
        sort($retired, SORT_STRING);

        return $this->entry($locked, $manifests, [
            'manifest_status' => 'legacy-unmanifested',
            'legacy' => [
                'reason' => $approved['reason'],
                'approved_by' => $approved['approved_by'],
                'approved_on' => $approved['approved_on'],
                'verified_legacy_release' => $verified,
                'retired_app_namespaces' => $retired,
            ],
            'release_gate_eligible' => false,
            'responsibility' => $approved['responsibility'],
            'non_responsibilities' => $approved['non_responsibilities'],
            'capabilities' => [],
            'documentation' => [
                'charter' => $manifests->charterPath(),
                'readme' => $manifests->readmePath(),
                'public_api' => $manifests->publicApiPath(),
            ],
            'dependency_injection' => [
                'config_provider' => null,
                'provider_absence_reason' => sprintf(
                    '%s %s ships no Version 2 service map; it is an approved legacy-unmanifested entry '
                    . 'and App composes its services explicitly.',
                    $name,
                    $locked['version'],
                ),
                'factories' => [],
                'aliases' => [],
                'configuration_keys' => [],
            ],
            'native_requirements' => null,
            'deprecations' => [],
            'handoff' => null,
        ]);
    }

    /**
     * Assemble the fields every entry shares, in the documented key order.
     *
     * @param   array{name: string, version: string, source: array{type: string, url: string, reference: string},
     *          dist: array{type: string, url: string, reference: string}, license: list<string>,
     *          psr4: array<string, list<string>>, extra: array<string, mixed>}  $locked     Lock entry.
     * @param   PackageManifests      $manifests  Installed package.
     * @param   array<string, mixed>  $specific   Status-specific fields.
     *
     * @return  array<string, mixed>  The complete entry.
     *
     * @throws  GovernanceViolation  When the lock's source URL is not an https repository.
     *
     * @since   2.0.0
     */
    private function entry(array $locked, PackageManifests $manifests, array $specific): array
    {
        $repository = $locked['source']['url'];
        if (str_ends_with($repository, '.git')) {
            $repository = substr($repository, 0, -4);
        }
        if (preg_match('#^https?://#', $repository) !== 1) {
            throw GovernanceViolation::at(
                'composer.lock',
                sprintf('%s source url "%s" is not an https repository', $locked['name'], $locked['source']['url']),
                'lock the package from its public repository',
            );
        }
        $namespaces = array_keys($locked['psr4']);
        sort($namespaces, SORT_STRING);

        return [
            'package' => $locked['name'],
            'repository' => $repository,
            'installed_version' => $locked['version'],
            'source_reference' => $locked['source']['reference'],
            'dist_reference' => $locked['dist']['reference'],
            'license' => $locked['license'],
            'manifest_status' => $specific['manifest_status'],
            'legacy' => $specific['legacy'],
            'release_gate_eligible' => $specific['release_gate_eligible'],
            'canonical_namespaces' => $namespaces,
            'responsibility' => $specific['responsibility'],
            'non_responsibilities' => $specific['non_responsibilities'],
            'capabilities' => $specific['capabilities'],
            'public_symbols' => $manifests->publicSymbols(),
            'public_symbols_source' => $manifests->publicSymbolsSource(),
            'public_api_digest' => $manifests->publicApiDigest(),
            'documentation' => $specific['documentation'],
            'dependency_injection' => $specific['dependency_injection'],
            'native_requirements' => $specific['native_requirements'],
            'deprecations' => $specific['deprecations'],
            'handoff' => $specific['handoff'],
        ];
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
}
