<?php

declare(strict_types=1);

namespace Kumwe\App\Tools\Governance;

/**
 * Loads and validates every App governance record under `docs/architecture/`.
 *
 * Migration ledger records, change sets, conflict ledger entries, integration trains, evidence attestations,
 * non-roadmap records and Core Growth Records are read from their fixed directories, parsed with StrictYaml,
 * validated against their schemas and cross-checked: a file is named after its identifier, identifiers are
 * unique, a ledger record names a locked package whose installed handoff carries the recorded digest, a
 * change-set and its ledger record share one sequence number, and a Core Growth Record carries its seven
 * narrative sections and a reviewer when approved. Any failure is a `GovernanceViolation`.
 *
 * @since  2.0.0
 */
final readonly class GovernanceRecords
{
    /**
     * Canonical migration states (D-GOV-1).
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const CHANGE_SET_STATES = [
        'enabling-refactor', 'package-implemented', 'package-released', 'release-verified', 'app-pr-ready',
        'core-integrated', 'objective-verified', 'gate-accepted',
    ];

    /**
     * H2 headings every Core Growth Record body carries, in this order.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const CORE_GROWTH_SECTIONS = [
        'Capability required',
        'Why existing package APIs are insufficient',
        'Why extending the owning package is inappropriate',
        'Why a new focused package is inappropriate',
        'App-specific responsibility',
        'Tests proving the boundary',
        'Decision',
    ];

    /**
     * Evidence schema strings and the schema files that validate them.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const EVIDENCE_SCHEMAS = [
        'kumwe-release-attestation/v2' => 'release-attestation.v2.schema.json',
        'kumwe-engine-candidate-attestation/v1' => 'engine-candidate-attestation.v1.schema.json',
        'kumwe-verified-legacy-release/v1' => 'verified-legacy-release.v1.schema.json',
    ];

    /**
     * Keep the validated records.
     *
     * @param  array<string, array{id: string, path: string, record: array<string, mixed>}>                $migrations
     *         Ledger records by migration id.
     * @param  array<string, array{id: string, path: string, record: array<string, mixed>}>                $changeSets
     *         Change sets by id.
     * @param  array<string, array{id: string, path: string, record: array<string, mixed>}>                $conflicts
     *         Conflict ledger entries by id.
     * @param  array<string, array{id: string, path: string, record: array<string, mixed>}>                $trains
     *         Integration trains by id.
     * @param  array<string, array<string, array{path: string, record: array<string, mixed>}>>             $evidence
     *         Evidence records by migration id and file name.
     * @param array<string, array{path: string, record: array<string, mixed>}> $legacyEvidence
     *         Verified legacy release records by repository-relative path.
     * @param  array<string, array{id: string, path: string, record: array<string, mixed>}>                $nonRoadmap
     *         Non-roadmap records by id.
     * @param  array<string, array{id: string, path: string, record: array<string, mixed>, body: string}>  $coreGrowth
     *         Core Growth Records by id.
     *
     * @since  2.0.0
     */
    private function __construct(
        private array $migrations,
        private array $changeSets,
        private array $conflicts,
        private array $trains,
        private array $evidence,
        private array $legacyEvidence,
        private array $nonRoadmap,
        private array $coreGrowth,
    ) {
    }

    /**
     * Load every governance record under a repository root.
     *
     * @param   string        $root             Absolute repository root.
     * @param   string        $schemaDirectory  Absolute governance schema directory.
     * @param   ComposerLock  $lock             The repository's lock, for package cross-checks.
     *
     * @return  self  The validated records; directories that do not exist yield no records.
     *
     * @throws  GovernanceViolation  When any record is malformed, misnamed, duplicated or contradicts another.
     *
     * @since   2.0.0
     */
    public static function load(string $root, string $schemaDirectory, ComposerLock $lock): self
    {
        $validator = new SchemaValidator();
        $architecture = $root . '/docs/architecture';
        $migrations = self::yamlRecords(
            $root,
            $architecture . '/migrations',
            'KUMWE-MIG-',
            'migration_id',
            $schemaDirectory . '/migration-ledger.v1.schema.json',
            $validator,
        );
        $changeSets = self::yamlRecords(
            $root,
            $architecture . '/migrations/change-sets',
            'KUMWE-CS-',
            'change_set',
            $schemaDirectory . '/change-set.v2.schema.json',
            $validator,
        );
        $conflicts = self::yamlRecords(
            $root,
            $architecture . '/migrations/conflicts',
            'KUMWE-CONFLICT-',
            'conflict_id',
            $schemaDirectory . '/conflict-ledger.v1.schema.json',
            $validator,
        );
        $trains = self::yamlRecords(
            $root,
            $architecture . '/migrations/trains',
            'KUMWE-TRAIN-',
            'train',
            $schemaDirectory . '/integration-train.v1.schema.json',
            $validator,
        );
        $nonRoadmap = self::yamlRecords(
            $root,
            $architecture . '/non-roadmap',
            'NRM-',
            'id',
            $schemaDirectory . '/non-roadmap-record.v1.schema.json',
            $validator,
        );
        [$evidence, $legacyEvidence] = self::readEvidence(
            $root,
            $architecture . '/migrations/evidence',
            $migrations,
            $lock,
            $schemaDirectory,
            $validator,
        );
        $coreGrowth = self::readCoreGrowth($root, $architecture . '/core-growth', $schemaDirectory, $validator);

        foreach ($nonRoadmap as $id => $entry) {
            if ($entry['record']['nrm_ref'] !== $id) {
                throw GovernanceViolation::at(
                    $entry['path'],
                    sprintf('nrm_ref "%s" differs from id %s', self::string($entry['record']['nrm_ref']), $id),
                    'record one identifier',
                );
            }
        }
        foreach ($changeSets as $id => $entry) {
            $migrationId = self::string($entry['record']['migration_id']);
            if (self::sequence($migrationId) !== self::sequence($id)) {
                throw GovernanceViolation::at(
                    $entry['path'],
                    sprintf('migration_id %s does not share the sequence of %s (D-GOV-2)', $migrationId, $id),
                    'allocate the same NNN to both records',
                );
            }
        }
        foreach ($conflicts as $entry) {
            /** @var list<string> $named */
            $named = $entry['record']['change_sets'];
            foreach ($named as $changeSet) {
                if (!isset($changeSets[$changeSet])) {
                    throw GovernanceViolation::at(
                        $entry['path'],
                        sprintf('names change set %s, which has no record', $changeSet),
                        'add the change-set record or correct the reference',
                    );
                }
            }
        }
        foreach ($trains as $entry) {
            $ledger = self::string($entry['record']['conflict_ledger']);
            if (!file_exists($root . '/' . $ledger)) {
                throw GovernanceViolation::at(
                    $entry['path'],
                    sprintf('conflict_ledger %s does not exist', $ledger),
                    'point it at the conflicts directory or a conflict record',
                );
            }
        }
        foreach ($migrations as $id => $entry) {
            self::assertMigration($root, $id, $entry, $changeSets, $conflicts, $nonRoadmap, $evidence, $lock);
        }

        return new self(
            $migrations,
            $changeSets,
            $conflicts,
            $trains,
            $evidence,
            $legacyEvidence,
            $nonRoadmap,
            $coreGrowth,
        );
    }

    /**
     * Cross-check one ledger record against the lock, the installed handoff, its change set and its evidence.
     *
     * @param   string                                                                           $root        Root.
     * @param string $id Migration id.
     * @param   array{id: string, path: string, record: array<string, mixed>}                    $entry       Record.
     * @param array<string, array{id: string, path: string, record: array<string, mixed>}> $changeSets Change sets.
     * @param   array<string, array{id: string, path: string, record: array<string, mixed>}>     $conflicts   Conflicts.
     * @param array<string, array{id: string, path: string, record: array<string, mixed>}> $nonRoadmap NRM records.
     * @param   array<string, array<string, array{path: string, record: array<string, mixed>}>>  $evidence    Evidence.
     * @param   ComposerLock                                                                     $lock        Lock.
     *
     * @return  void
     *
     * @throws  GovernanceViolation  When any cross-check fails.
     *
     * @since   2.0.0
     */
    private static function assertMigration(
        string $root,
        string $id,
        array $entry,
        array $changeSets,
        array $conflicts,
        array $nonRoadmap,
        array $evidence,
        ComposerLock $lock,
    ): void {
        $path = $entry['path'];
        $record = $entry['record'];
        self::assertMigrationTestOwnership($root, $path, $record);
        $package = self::string($record['package']);
        $locked = $lock->package($package);
        if ($locked === null) {
            throw GovernanceViolation::at(
                $path,
                sprintf('package %s is not locked in composer.lock', $package),
                'adopt the release with composer require before recording the migration',
            );
        }
        if (self::bareVersion(self::string($record['version'])) !== self::bareVersion($locked['version'])) {
            throw GovernanceViolation::at(
                $path,
                sprintf('version %s differs from the locked %s', self::string($record['version']), $locked['version']),
                'record the exact locked release',
            );
        }
        $short = substr($package, strlen('kumwe/'));
        $expectedHandoff = 'vendor/kumwe/' . $short . '/MIGRATION-HANDOFF.md';
        if ($record['handoff_path'] !== $expectedHandoff) {
            throw GovernanceViolation::at(
                $path,
                sprintf('handoff_path must be %s', $expectedHandoff),
                'name the installed handoff',
            );
        }
        if (is_file($root . '/' . $expectedHandoff)) {
            $actual = hash('sha256', (string) file_get_contents($root . '/' . $expectedHandoff));
            if ($actual !== $record['handoff_sha256']) {
                throw GovernanceViolation::at(
                    $path,
                    sprintf(
                        'handoff_sha256 %s differs from the installed handoff digest %s',
                        self::string($record['handoff_sha256']),
                        $actual,
                    ),
                    'record the digest of the released handoff',
                );
            }
        }
        /** @var list<string> $entries */
        $entries = $record['capability_index_entries'];
        if (!in_array($package, $entries, true)) {
            throw GovernanceViolation::at(
                $path,
                sprintf('capability_index_entries does not list %s', $package),
                'list the adopted package',
            );
        }
        $changeSetId = self::string($record['change_set']);
        if (self::sequence($changeSetId) !== self::sequence($id)) {
            throw GovernanceViolation::at(
                $path,
                sprintf('change_set %s does not share the sequence of %s (D-GOV-2)', $changeSetId, $id),
                'allocate the same NNN to both records',
            );
        }
        $changeSet = $changeSets[$changeSetId] ?? null;
        if ($changeSet === null) {
            throw GovernanceViolation::at(
                $path,
                sprintf('change set %s has no record under docs/architecture/migrations/change-sets', $changeSetId),
                'add the change-set record',
            );
        }
        if ($changeSet['record']['migration_id'] !== $id) {
            throw GovernanceViolation::at(
                $changeSet['path'],
                sprintf(
                    'migration_id %s differs from the ledger record %s that names this change set',
                    self::string($changeSet['record']['migration_id']),
                    $id,
                ),
                'align both records',
            );
        }
        /** @var list<string> $named */
        $named = $record['conflicts'];
        foreach ($named as $conflict) {
            if (!isset($conflicts[$conflict])) {
                throw GovernanceViolation::at(
                    $path,
                    sprintf('names conflict %s, which has no record', $conflict),
                    'add the conflict record or correct the reference',
                );
            }
        }
        /** @var list<string> $refs */
        $refs = $record['non_roadmap_refs'];
        foreach ($refs as $reference) {
            if (!isset($nonRoadmap[$reference])) {
                throw GovernanceViolation::at(
                    $path,
                    sprintf('names non-roadmap record %s, which has no record', $reference),
                    'add the record or correct the reference',
                );
            }
        }
        $attestationPath = self::string($record['release_attestation']);
        $expectedPrefix = 'docs/architecture/migrations/evidence/' . $id . '/';
        if (!str_starts_with($attestationPath, $expectedPrefix) || !isset($evidence[$id][basename($attestationPath)])) {
            throw GovernanceViolation::at(
                $path,
                sprintf('release_attestation %s is not an evidence record under %s', $attestationPath, $expectedPrefix),
                'commit the attestation at the evidence path',
            );
        }
        $attestation = $evidence[$id][basename($attestationPath)]['record'];
        $verifiedRelease = ($attestation['schema'] ?? null) === 'kumwe-release-attestation/v2'
            && ($attestation['status'] ?? null) === 'verified';
        if (!$verifiedRelease) {
            throw GovernanceViolation::at(
                $path,
                sprintf('release_attestation %s is not a verified release attestation', $attestationPath),
                'a migration ledger record needs a passing external attestation',
            );
        }
        foreach (['migration_id' => $id, 'change_set' => $changeSetId] as $field => $expected) {
            if ($attestation[$field] !== $expected) {
                throw GovernanceViolation::at(
                    $attestationPath,
                    sprintf(
                        '%s %s differs from the ledger record %s',
                        $field,
                        self::string($attestation[$field]),
                        $expected,
                    ),
                    'align the attestation and the ledger',
                );
            }
        }
        if (self::bareVersion(self::string($attestation['version'])) !== self::bareVersion($locked['version'])) {
            throw GovernanceViolation::at(
                $attestationPath,
                sprintf(
                    'version %s differs from the locked %s',
                    self::string($attestation['version']),
                    $locked['version'],
                ),
                'adopt exactly the attested release',
            );
        }
        /** @var array{url: string, sha256: string} $archive */
        $archive = $attestation['source_archive'];
        if ($archive['sha256'] !== $record['artifact_digest']) {
            throw GovernanceViolation::at(
                $path,
                sprintf(
                    'artifact_digest %s differs from the attested source archive digest %s',
                    self::string($record['artifact_digest']),
                    $archive['sha256'],
                ),
                'record the attested digest',
            );
        }
    }

    /**
     * Verify that adoption really removes duplicate tests and preserves the named host test files.
     *
     * This checks App's own tree only. Package behavior and conformance suites execute in their owner's CI.
     * A retained test renamed later must be updated in the ledger in the same change.
     *
     * @param   string                $root    Repository root.
     * @param   string                $path    Ledger display path.
     * @param   array<string, mixed>  $record  Schema-validated migration record.
     *
     * @return  void
     *
     * @throws  GovernanceViolation  When a removal is false or a retained test is absent.
     *
     * @since   2.0.0
     */
    private static function assertMigrationTestOwnership(string $root, string $path, array $record): void
    {
        $seen = [];
        foreach (['removed_tests', 'retained_tests'] as $field) {
            /** @var list<string> $tests */
            $tests = $record[$field];
            foreach ($tests as $test) {
                if (
                    !str_starts_with($test, 'tests/')
                    || !str_ends_with($test, '.php')
                    || preg_match('~[\\\\\x00-\x20\x7f]|(?:^|/)\.{1,2}(?:/|$)|//~', $test) === 1
                ) {
                    throw GovernanceViolation::at(
                        $path,
                        sprintf('%s names a non-canonical App test path %s', $field, $test),
                        'name an exact repository-relative PHP file under tests/',
                    );
                }
                if (isset($seen[$test])) {
                    throw GovernanceViolation::at(
                        $path,
                        sprintf('test %s is listed more than once or as both removed and retained', $test),
                        'record each test once with its actual ownership',
                    );
                }
                $seen[$test] = true;
                $absolute = $root . '/' . $test;
                if ($field === 'removed_tests' && (file_exists($absolute) || is_link($absolute))) {
                    throw GovernanceViolation::at(
                        $path,
                        sprintf('removed test %s still exists in App', $test),
                        'move its portable behavior tests to the package and delete the duplicate at adoption',
                    );
                }
                if ($field === 'retained_tests' && (!is_file($absolute) || is_link($absolute))) {
                    throw GovernanceViolation::at(
                        $path,
                        sprintf('retained host test %s is missing or is a symlink', $test),
                        'restore the host test or update its reviewed replacement path in this ledger',
                    );
                }
            }
        }
    }

    /**
     * Read every YAML record of one type from one directory.
     *
     * @param   string           $root       Repository root, for relative paths.
     * @param   string           $directory  Absolute record directory; may not exist.
     * @param   string           $prefix     Identifier prefix such as `KUMWE-MIG-`.
     * @param   string           $idField    Record field that carries the identifier.
     * @param   string           $schema     Absolute schema path.
     * @param   SchemaValidator  $validator  Validator.
     *
     * @return  array<string, array{id: string, path: string, record: array<string, mixed>}>  Records by id, sorted.
     *
     * @throws  GovernanceViolation  When a file is misnamed, malformed, fails its schema or repeats an id.
     *
     * @since   2.0.0
     */
    private static function yamlRecords(
        string $root,
        string $directory,
        string $prefix,
        string $idField,
        string $schema,
        SchemaValidator $validator,
    ): array
    {
        $records = [];
        foreach (self::files($directory, ['yaml', 'yml']) as $file) {
            $relative = substr($file, strlen($root) + 1);
            $name = basename($file);
            if (preg_match('/^' . preg_quote($prefix, '/') . '[0-9]{4}-[0-9]{3}\.yaml$/', $name) !== 1) {
                throw GovernanceViolation::at(
                    $relative,
                    sprintf('the file name is not %sYYYY-NNN.yaml', $prefix),
                    'name the record after its identifier',
                );
            }
            $id = substr($name, 0, -5);
            $record = StrictYaml::parse((string) file_get_contents($file), $relative);
            $violations = $validator->validate($record, $schema);
            if ($violations !== []) {
                throw GovernanceViolation::at(
                    $relative,
                    sprintf('fails %s: %s', basename($schema), implode('; ', $violations)),
                    'repair the listed properties',
                );
            }
            if (($record[$idField] ?? null) !== $id) {
                throw GovernanceViolation::at(
                    $relative,
                    sprintf('%s "%s" differs from the file name', $idField, self::string($record[$idField] ?? null)),
                    'name the file after the identifier',
                );
            }
            if (isset($records[$id])) {
                throw GovernanceViolation::at(
                    $relative,
                    sprintf('identifier %s is already used by %s', $id, $records[$id]['path']),
                    'allocate the next sequence number (D-GOV-3)',
                );
            }
            $records[$id] = ['id' => $id, 'path' => $relative, 'record' => $record];
        }
        ksort($records, SORT_STRING);

        return $records;
    }

    /**
     * Read the evidence attestations under `migrations/evidence/`.
     *
     * @param   string                                                                        $root             Root.
     * @param string $directory Evidence dir.
     * @param   array<string, array{id: string, path: string, record: array<string, mixed>}>  $migrations       Ledger.
     * @param   ComposerLock                                                                  $lock             Lock.
     * @param   string                                                                        $schemaDirectory  Schemas.
     * @param SchemaValidator $validator Validator.
     *
     * @return  array{0: array<string, array<string, array{path: string, record: array<string, mixed>}>>,
     *          1: array<string, array{path: string, record: array<string, mixed>}>}  Migration evidence by id and
     *          file, and legacy evidence by path.
     *
     * @throws  GovernanceViolation  When a directory names no migration or package, or a record is invalid.
     *
     * @since   2.0.0
     */
    private static function readEvidence(
        string $root,
        string $directory,
        array $migrations,
        ComposerLock $lock,
        string $schemaDirectory,
        SchemaValidator $validator,
    ): array
    {
        $evidence = [];
        $legacy = [];
        if (!is_dir($directory)) {
            return [[], []];
        }
        $entries = scandir($directory) ?: [];
        sort($entries, SORT_STRING);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir($directory . '/' . $entry)) {
                continue;
            }
            if ($entry === 'legacy') {
                foreach (scandir($directory . '/legacy') ?: [] as $short) {
                    if ($short === '.' || $short === '..' || !is_dir($directory . '/legacy/' . $short)) {
                        continue;
                    }
                    if ($lock->package('kumwe/' . $short) === null) {
                        throw GovernanceViolation::at(
                            'docs/architecture/migrations/evidence/legacy/' . $short,
                            'names no locked kumwe package',
                            'name the directory after the package short name',
                        );
                    }
                    foreach (self::files($directory . '/legacy/' . $short, ['yaml', 'yml']) as $file) {
                        $relative = substr($file, strlen($root) + 1);
                        $record = self::evidenceRecord($file, $relative, $schemaDirectory, $validator);
                        if ($record['schema'] !== 'kumwe-verified-legacy-release/v1') {
                            throw GovernanceViolation::at(
                                $relative,
                                'only a verified legacy release record belongs under evidence/legacy',
                                'move the record under its migration',
                            );
                        }
                        $legacy[$relative] = ['path' => $relative, 'record' => $record];
                    }
                }
                continue;
            }
            if (!isset($migrations[$entry])) {
                throw GovernanceViolation::at(
                    'docs/architecture/migrations/evidence/' . $entry,
                    'names no migration ledger record',
                    'add the KUMWE-MIG record first or remove the directory',
                );
            }
            foreach (self::files($directory . '/' . $entry, ['yaml', 'yml']) as $file) {
                $relative = substr($file, strlen($root) + 1);
                $record = self::evidenceRecord($file, $relative, $schemaDirectory, $validator);
                $name = basename($file);
                if ($record['schema'] === 'kumwe-verified-legacy-release/v1') {
                    throw GovernanceViolation::at(
                        $relative,
                        'a verified legacy release record cannot stand in for migration evidence',
                        'a migration needs a release attestation (Kumwe-v2-08)',
                    );
                }
                if ($name === 'RELEASE-ATTESTATION.yaml' && ($record['status'] ?? null) !== 'verified') {
                    throw GovernanceViolation::at(
                        $relative,
                        'RELEASE-ATTESTATION.yaml exists only with status verified (D-GOV-4)',
                        'write a failed verification to RELEASE-VERIFICATION-FAILED.yaml',
                    );
                }
                if ($name === 'RELEASE-VERIFICATION-FAILED.yaml') {
                    $gaps = $record['known_gaps'] ?? [];
                    if (($record['status'] ?? null) !== 'failed' || !is_array($gaps) || $gaps === []) {
                        throw GovernanceViolation::at(
                            $relative,
                            'RELEASE-VERIFICATION-FAILED.yaml needs status failed and non-empty known_gaps (D-GOV-4)',
                            'record what failed',
                        );
                    }
                }
                $evidence[$entry][$name] = ['path' => $relative, 'record' => $record];
            }
            if (isset($evidence[$entry])) {
                ksort($evidence[$entry], SORT_STRING);
            }
        }
        ksort($evidence, SORT_STRING);
        ksort($legacy, SORT_STRING);

        return [$evidence, $legacy];
    }

    /**
     * Parse and validate one evidence record by its declared schema.
     *
     * @param   string           $file             Absolute path.
     * @param   string           $relative         Repository-relative path.
     * @param   string           $schemaDirectory  Schema directory.
     * @param   SchemaValidator  $validator        Validator.
     *
     * @return  array<string, mixed>  The record.
     *
     * @throws  GovernanceViolation  When the schema string is unknown or the record fails it.
     *
     * @since   2.0.0
     */
    private static function evidenceRecord(
        string $file,
        string $relative,
        string $schemaDirectory,
        SchemaValidator $validator,
    ): array
    {
        $record = StrictYaml::parse((string) file_get_contents($file), $relative);
        $schema = $record['schema'] ?? null;
        if (!is_string($schema) || !isset(self::EVIDENCE_SCHEMAS[$schema])) {
            throw GovernanceViolation::at(
                $relative,
                sprintf('schema "%s" is not an evidence record type', self::string($schema)),
                'use one of ' . implode(', ', array_keys(self::EVIDENCE_SCHEMAS)),
            );
        }
        $violations = $validator->validate($record, $schemaDirectory . '/' . self::EVIDENCE_SCHEMAS[$schema]);
        if ($violations !== []) {
            throw GovernanceViolation::at(
                $relative,
                sprintf('fails %s: %s', self::EVIDENCE_SCHEMAS[$schema], implode('; ', $violations)),
                'repair the listed properties',
            );
        }

        return $record;
    }

    /**
     * Read the Core Growth Records.
     *
     * @param   string           $root             Root.
     * @param   string           $directory        Absolute `core-growth` directory; may not exist.
     * @param   string           $schemaDirectory  Schema directory.
     * @param   SchemaValidator  $validator        Validator.
     *
     * @return  array<string, array{id: string, path: string, record: array<string, mixed>, body: string}>  By id.
     *
     * @throws GovernanceViolation When a record is misnamed, invalid, lacks a section or reviewer, or repeats a symbol.
     *
     * @since   2.0.0
     */
    private static function readCoreGrowth(
        string $root,
        string $directory,
        string $schemaDirectory,
        SchemaValidator $validator,
    ): array
    {
        $records = [];
        $owners = [];
        foreach (self::files($directory, ['md']) as $file) {
            $name = basename($file);
            if ($name === 'README.md') {
                continue;
            }
            $relative = substr($file, strlen($root) + 1);
            if (preg_match('/^KUMWE-CGR-[0-9]{4}-[0-9]{3}\.md$/', $name) !== 1) {
                throw GovernanceViolation::at(
                    $relative,
                    'the file name is not KUMWE-CGR-YYYY-NNN.md',
                    'name the record after its identifier',
                );
            }
            $id = substr($name, 0, -3);
            $parsed = StrictYaml::parseFrontMatter((string) file_get_contents($file), $relative);
            $record = $parsed['front_matter'];
            $violations = $validator->validate($record, $schemaDirectory . '/core-growth-record.v1.schema.json');
            if ($violations !== []) {
                throw GovernanceViolation::at(
                    $relative,
                    'fails core-growth-record.v1.schema.json: ' . implode('; ', $violations),
                    'repair the listed properties',
                );
            }
            if ($record['id'] !== $id) {
                throw GovernanceViolation::at(
                    $relative,
                    sprintf('id "%s" differs from the file name', self::string($record['id'])),
                    'name the file after the identifier',
                );
            }
            $reviewer = $record['reviewer'];
            if ($record['decision'] === 'approved' && (!is_string($reviewer) || trim($reviewer) === '')) {
                throw GovernanceViolation::at(
                    $relative,
                    'an approved record has no reviewer',
                    'name the architectural reviewer who approved it',
                );
            }
            foreach (self::CORE_GROWTH_SECTIONS as $section) {
                $heading = '/^##\s+(?:[0-9]+\.\s+)?' . preg_quote($section, '/') . '\s*$/m';
                if (preg_match($heading, $parsed['body']) !== 1) {
                    throw GovernanceViolation::at(
                        $relative,
                        sprintf('the section "## %s" is missing', $section),
                        'write the seven H2 sections in order',
                    );
                }
            }
            /** @var list<string> $symbols */
            $symbols = $record['symbols'];
            foreach ($symbols as $symbol) {
                if (isset($owners[$symbol])) {
                    throw GovernanceViolation::at(
                        $relative,
                        sprintf('%s is already named by %s', $symbol, $owners[$symbol]),
                        'one Core Growth Record owns each FQCN',
                    );
                }
                $owners[$symbol] = $id;
            }
            $records[$id] = ['id' => $id, 'path' => $relative, 'record' => $record, 'body' => $parsed['body']];
        }
        ksort($records, SORT_STRING);

        return $records;
    }

    /**
     * List the files of given extensions directly inside a directory, sorted.
     *
     * @param   string        $directory   Absolute directory; may not exist.
     * @param   list<string>  $extensions  Lowercase extensions without dots.
     *
     * @return  list<string>  Absolute paths, sorted.
     *
     * @since   2.0.0
     */
    private static function files(string $directory, array $extensions): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $files = [];
        foreach (scandir($directory) ?: [] as $entry) {
            $path = $directory . '/' . $entry;
            if (!is_file($path)) {
                continue;
            }
            $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (in_array($extension, $extensions, true)) {
                $files[] = $path;
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * The YYYY-NNN tail of a governance identifier.
     *
     * @param   string  $id  Identifier such as `KUMWE-MIG-2026-001`.
     *
     * @return  string  Such as `2026-001`.
     *
     * @since   2.0.0
     */
    private static function sequence(string $id): string
    {
        return preg_match('/([0-9]{4}-[0-9]{3})$/', $id, $match) === 1 ? $match[1] : $id;
    }

    /**
     * A version without its optional `v` prefix.
     *
     * @param   string  $version  Version string.
     *
     * @return  string  The bare version.
     *
     * @since   2.0.0
     */
    private static function bareVersion(string $version): string
    {
        return ltrim($version, 'v');
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
     * Migration ledger records.
     *
     * @return  array<string, array{id: string, path: string, record: array<string, mixed>}>  By migration id.
     *
     * @since   2.0.0
     */
    public function migrations(): array
    {
        return $this->migrations;
    }

    /**
     * The ledger record that adopted a package, if any.
     *
     * @param   string  $package  Package name.
     *
     * @return  array{id: string, path: string, record: array<string, mixed>}|null  The record, or null.
     *
     * @since   2.0.0
     */
    public function migrationForPackage(string $package): ?array
    {
        foreach ($this->migrations as $entry) {
            if ($entry['record']['package'] === $package) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Change-set records.
     *
     * @return  array<string, array{id: string, path: string, record: array<string, mixed>}>  By change-set id.
     *
     * @since   2.0.0
     */
    public function changeSets(): array
    {
        return $this->changeSets;
    }

    /**
     * Conflict ledger records.
     *
     * @return  array<string, array{id: string, path: string, record: array<string, mixed>}>  By conflict id.
     *
     * @since   2.0.0
     */
    public function conflicts(): array
    {
        return $this->conflicts;
    }

    /**
     * Integration train records.
     *
     * @return  array<string, array{id: string, path: string, record: array<string, mixed>}>  By train id.
     *
     * @since   2.0.0
     */
    public function trains(): array
    {
        return $this->trains;
    }

    /**
     * Evidence attestations of each migration.
     *
     * @return  array<string, array<string, array{path: string, record: array<string, mixed>}>>  By migration id
     *          and file name.
     *
     * @since   2.0.0
     */
    public function evidence(): array
    {
        return $this->evidence;
    }

    /**
     * Verified legacy release records.
     *
     * @return  array<string, array{path: string, record: array<string, mixed>}>  By repository-relative path.
     *
     * @since   2.0.0
     */
    public function legacyEvidence(): array
    {
        return $this->legacyEvidence;
    }

    /**
     * Non-roadmap records.
     *
     * @return  array<string, array{id: string, path: string, record: array<string, mixed>}>  By NRM id.
     *
     * @since   2.0.0
     */
    public function nonRoadmap(): array
    {
        return $this->nonRoadmap;
    }

    /**
     * Core Growth Records.
     *
     * @return  array<string, array{id: string, path: string, record: array<string, mixed>, body: string}>  By id.
     *
     * @since   2.0.0
     */
    public function coreGrowth(): array
    {
        return $this->coreGrowth;
    }
}
