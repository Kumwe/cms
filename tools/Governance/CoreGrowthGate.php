<?php

declare(strict_types=1);

namespace Kumwe\App\Tools\Governance;

use stdClass;

/**
 * Evaluates App production growth against the recorded baseline, the capability index and the governance records.
 *
 * `check()` refuses, in this order: a stale capability index (before anything else); a baseline entry `src/` no
 * longer declares; a declaration under an extracted or retired namespace root, judged before classification because
 * a retired root has no layer rule of its own; for every new or changed symbol, a duplicate FQCN owner, a
 * reintroduced removed symbol, a likely duplicate responsibility of a package symbol (same short
 * name, same kind, at least half of the candidate's public method names shared, minimum two), unrecorded portable
 * growth without an approved Core Growth Record, and unrecorded host growth; a `src/Kernel/` array key naming a
 * service a package service map owns; any import, inline name, `::class` or string literal under the reference
 * directories naming a retired namespace root; and a Core Growth Record that names an undeclared FQCN, records
 * the wrong layer, or is cited by the baseline while pending or rejected. Only the "baseline stale" and
 * "re-record" findings are cleared by `record()`, which refuses to write while any other finding remains and
 * otherwise rewrites the baseline deterministically. A symbol whose surface differs from its baseline entry only
 * by the names an adopted migration ledger retired (the ledger's `old_fqcn`, now spelled as its `new_fqcn`) is a
 * rename, not growth: it is reported as a re-record, keeps its recorded growth evidence, and any further
 * difference in the same surface is judged as growth exactly as before.
 *
 * Two allowances are explicit. When no baseline exists, `record()` writes the bootstrap snapshot with `growth`
 * null for every symbol, and the responsibility, portable and host rules are not applied to snapshot symbols;
 * duplicate owners and reintroductions are refused even then. A string literal in a test file under
 * `tests/Architecture/` may name a retired namespace root, because an architecture test asserts the absence of
 * the namespace by naming it; an import or `::class` reference is refused there as everywhere else, and a string
 * literal anywhere else is refused too.
 *
 * @since  2.0.0
 */
final readonly class CoreGrowthGate
{
    /**
     * Repository-relative path of the committed baseline.
     *
     * @var    string
     * @since  2.0.0
     */
    public const BASELINE_PATH = 'docs/architecture/governance/core-growth-baseline.json';

    /**
     * Schema identity of the baseline document.
     *
     * @var    string
     * @since  2.0.0
     */
    public const SCHEMA = 'kumwe-core-growth-baseline/v1';

    /**
     * File name of the baseline schema inside the governance schema directory.
     *
     * @var    string
     * @since  2.0.0
     */
    public const SCHEMA_FILE = 'core-growth-baseline.v1.schema.json';

    /**
     * Repository-relative path of the layer graph.
     *
     * @var    string
     * @since  2.0.0
     */
    public const LAYER_GRAPH = 'docs/architecture/layers.json';

    /**
     * Repository-relative composition root whose array keys are checked for package-owned services.
     *
     * @var    string
     * @since  2.0.0
     */
    public const KERNEL_DIRECTORY = 'src/Kernel';

    /**
     * Repository-relative directories scanned for references to retired namespace roots.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const REFERENCE_DIRECTORIES = ['src', 'tests', 'config', 'bootstrap', 'resources', 'examples'];

    /**
     * Directory whose `*Test.php` files may name a retired namespace root inside a string literal.
     *
     * An architecture test proves a namespace is gone by asserting that no source names it, and it has to spell the
     * namespace to do so. The allowance covers string literals only, in test files under this directory only.
     *
     * @var    string
     * @since  2.0.0
     */
    public const ASSERTION_TEST_DIRECTORY = 'tests/Architecture';

    /**
     * The command that rewrites the baseline.
     *
     * @var    string
     * @since  2.0.0
     */
    public const RECORD_COMMAND = 'composer kumwe:core-growth-record';

    /**
     * The command that regenerates the capability index.
     *
     * @var    string
     * @since  2.0.0
     */
    public const INDEX_COMMAND = 'composer kumwe:capability-index';

    /**
     * Repository-relative directory of the Core Growth Records.
     *
     * @var    string
     * @since  2.0.0
     */
    public const RECORD_DIRECTORY = 'docs/architecture/core-growth';

    /**
     * The `note` every baseline carries: what the file is, how it is re-recorded and what the gate refuses.
     *
     * @var    string
     * @since  2.0.0
     */
    public const NOTE = 'The recorded public surface of every class-like declared under src/ (production only), '
        . 'keyed by fully qualified name: its kind, its layer from docs/architecture/layers.json, the first 24 '
        . 'hexadecimal characters of the SHA-256 of its canonical public surface (kind, modifiers, parent, '
        . 'interfaces, public constants, public properties and public method signatures), and its growth evidence. '
        . 'Written only by composer kumwe:core-growth-record and never by hand; checked by composer '
        . 'kumwe:core-growth-check in composer qa and CI. growth is null for every symbol present at the bootstrap '
        . 'snapshot; portable-layer growth (shared, domain, application) recorded later cites its approved Core '
        . 'Growth Record as {"record": "KUMWE-CGR-YYYY-NNN"}, and host-layer growth (infrastructure, presentation, '
        . 'delivery, kernel) carries its adapter or composition evidence as {"classification": "host-<layer>", '
        . '"implements": [...], "extends": ...}. Re-record after adding, removing or widening a symbol and commit '
        . 'the result with the change. The record command refuses while a duplicate FQCN or service owner, a '
        . 'reintroduced extracted namespace or removed symbol, a likely duplicate responsibility of a package '
        . 'symbol, a reference to a retired namespace, or a missing, pending or rejected Core Growth Record '
        . 'remains; only "baseline stale" and "re-record" findings are cleared by recording.';

    /**
     * Bind the gate to a repository root.
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
     * The schema directory this gate validates against.
     *
     * @return  string  Absolute path.
     *
     * @since   2.0.0
     */
    public function schemaDirectory(): string
    {
        return $this->schemaDirectory ?? CapabilityIndexBuilder::defaultSchemaDirectory();
    }

    /**
     * Run the check without writing anything.
     *
     * @return  array{failures: list<string>, symbols: int, recorded: int}  Every finding in evaluation order, each
     *          naming the file, the rule and the fix; the production symbol count; and the number of baseline
     *          entries carrying growth evidence.
     *
     * @throws  GovernanceViolation  When the capability index is stale, a governance record or the baseline is
     *          malformed, or a production name cannot be classified.
     *
     * @since   2.0.0
     */
    public function check(): array
    {
        $evaluation = $this->evaluate();

        return [
            'failures' => array_column($evaluation['findings'], 'message'),
            'symbols' => $evaluation['inventory']->count(),
            'recorded' => self::recorded($evaluation['baseline']['symbols'] ?? []),
        ];
    }

    /**
     * Compute the baseline `record()` would write, without writing it.
     *
     * @return  array{failures: list<string>, written: bool, json: string, symbols: int, recorded: int,
     *          added: list<string>, removed: list<string>, expanded: list<string>, renamed: list<string>}  The
     *          findings that refuse the record (empty when it may proceed), the baseline bytes, the counts and the
     *          FQCNs that changed.
     *
     * @throws  GovernanceViolation  When the capability index is stale, a governance record or the baseline is
     *          malformed, or a production name cannot be classified.
     *
     * @since   2.0.0
     */
    public function preview(): array
    {
        return $this->assemble();
    }

    /**
     * Re-run the check and rewrite the baseline when nothing but a stale or unrecorded entry stands in the way.
     *
     * @return  array{failures: list<string>, written: bool, json: string, symbols: int, recorded: int,
     *          added: list<string>, removed: list<string>, expanded: list<string>, renamed: list<string>}  The
     *          findings that refused the record (the file is untouched when non-empty), the bytes written, the
     *          counts and the FQCNs added, removed, expanded and renamed relative to the previous baseline.
     *
     * @throws  GovernanceViolation  When the check cannot run, or the baseline cannot be written.
     *
     * @since   2.0.0
     */
    public function record(): array
    {
        $result = $this->assemble();
        if ($result['failures'] !== []) {
            return $result;
        }
        $path = $this->root . '/' . self::BASELINE_PATH;
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw GovernanceViolation::at(self::BASELINE_PATH, 'the directory cannot be created', 'check permissions');
        }
        if (file_put_contents($path, $result['json']) !== strlen($result['json'])) {
            throw GovernanceViolation::at(self::BASELINE_PATH, 'the file cannot be written', 'check permissions');
        }
        $result['written'] = true;

        return $result;
    }

    /**
     * Read and validate the committed baseline.
     *
     * @return  array{schema: string, note: string, symbols: array<string, array{kind: string, layer: string,
     *          surface: string, growth: array<string, mixed>|null}>}|null  The baseline with its symbols sorted, or
     *          null when no baseline has been recorded yet.
     *
     * @throws  GovernanceViolation  When the file exists but is unreadable, not JSON or fails its schema.
     *
     * @since   2.0.0
     */
    public function readBaseline(): ?array
    {
        $path = $this->root . '/' . self::BASELINE_PATH;
        if (!is_file($path)) {
            return null;
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            throw GovernanceViolation::at(self::BASELINE_PATH, 'the file cannot be read', 'restore it or re-record');
        }
        /** @var mixed $decoded */
        $decoded = json_decode($bytes, true);
        if (!is_array($decoded)) {
            throw GovernanceViolation::at(
                self::BASELINE_PATH,
                'the baseline is not well-formed JSON',
                're-record it with ' . self::RECORD_COMMAND . '; the file is generated and never hand-edited',
            );
        }
        $violations = (new SchemaValidator())->validate($decoded, $this->schemaDirectory() . '/' . self::SCHEMA_FILE);
        if ($violations !== []) {
            throw GovernanceViolation::at(
                self::BASELINE_PATH,
                sprintf('fails %s: %s', self::SCHEMA_FILE, implode('; ', $violations)),
                're-record it with ' . self::RECORD_COMMAND . '; the file is generated and never hand-edited',
            );
        }
        /** @var array{schema: string, note: string, symbols: array<string, array{kind: string, layer: string,
         *   surface: string, growth: array<string, mixed>|null}>} $decoded */
        $symbols = $decoded['symbols'];
        ksort($symbols, SORT_STRING);
        $decoded['symbols'] = $symbols;

        return $decoded;
    }

    /**
     * Encode a baseline as the canonical bytes.
     *
     * @param   array{schema: string, note: string, symbols: array<string, array{kind: string, layer: string,
     *          surface: string, growth: array<string, mixed>|null}>}  $baseline  The document.
     *
     * @return  string  Pretty-printed JSON with unescaped slashes and Unicode and one trailing newline.
     *
     * @since   2.0.0
     */
    public static function json(array $baseline): string
    {
        $encodable = $baseline;
        if ($encodable['symbols'] === []) {
            $encodable['symbols'] = new stdClass();
        }

        return json_encode(
            $encodable,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    /**
     * Evaluate the check and assemble the baseline it permits.
     *
     * @return  array{failures: list<string>, written: bool, json: string, symbols: int, recorded: int,
     *          added: list<string>, removed: list<string>, expanded: list<string>}  The result of `preview()`.
     *
     * @throws  GovernanceViolation  When the check cannot run, or the assembled baseline fails its own schema.
     *
     * @since   2.0.0
     */
    private function assemble(): array
    {
        $evaluation = $this->evaluate();
        $blocking = [];
        foreach ($evaluation['findings'] as $finding) {
            if (!$finding['recordable']) {
                $blocking[] = $finding['message'];
            }
        }
        $inventory = $evaluation['inventory'];
        $previous = $evaluation['baseline']['symbols'] ?? [];
        if ($blocking !== []) {
            return [
                'failures' => $blocking,
                'written' => false,
                'json' => '',
                'symbols' => $inventory->count(),
                'recorded' => self::recorded($previous),
                'added' => [],
                'removed' => [],
                'expanded' => [],
                'renamed' => [],
            ];
        }

        $snapshot = $evaluation['baseline'] === null;
        $renames = $evaluation['renames'];
        $entries = [];
        $added = [];
        $expanded = [];
        $renamed = [];
        foreach ($inventory->symbols() as $fqcn => $symbol) {
            $entry = $previous[$fqcn] ?? null;
            $rename = self::renamedOnly($symbol, $entry, $renames);
            if ($entry !== null && (!self::isCandidate($symbol, $entry) || $rename !== null)) {
                $growth = $entry['growth'];
            } elseif ($snapshot) {
                $growth = null;
            } elseif (LayerClassifier::isPortable($symbol['layer'])) {
                $record = $evaluation['approved'][$fqcn] ?? null;
                if ($record === null) {
                    throw GovernanceViolation::at(
                        $symbol['file'],
                        sprintf('%s reached the record step without an approved Core Growth Record', $fqcn),
                        'this is a gate defect; report it with the tree that produced it',
                    );
                }
                $growth = ['record' => $record];
            } else {
                $growth = [
                    'classification' => 'host-' . $symbol['layer'],
                    'implements' => $symbol['implements'],
                    'extends' => $symbol['extends'],
                ];
            }
            if ($entry === null) {
                $added[] = $fqcn;
            } elseif ($rename !== null) {
                $renamed[] = $fqcn;
            } elseif (self::isCandidate($symbol, $entry)) {
                $expanded[] = $fqcn;
            }
            $entries[$fqcn] = [
                'kind' => $symbol['kind'],
                'layer' => $symbol['layer'],
                'surface' => $symbol['surface'],
                'growth' => $growth,
            ];
        }
        $removed = array_values(array_diff(array_keys($previous), array_keys($entries)));
        sort($removed, SORT_STRING);

        $baseline = ['schema' => self::SCHEMA, 'note' => self::NOTE, 'symbols' => $entries];
        $violations = (new SchemaValidator())->validate(
            $baseline,
            $this->schemaDirectory() . '/' . self::SCHEMA_FILE,
        );
        if ($violations !== []) {
            throw GovernanceViolation::at(
                self::BASELINE_PATH,
                sprintf('the assembled baseline fails %s: %s', self::SCHEMA_FILE, implode('; ', $violations)),
                'this is a gate defect; report it with the tree that produced it',
            );
        }

        return [
            'failures' => [],
            'written' => false,
            'json' => self::json($baseline),
            'symbols' => count($entries),
            'recorded' => self::recorded($entries),
            'added' => $added,
            'removed' => $removed,
            'expanded' => $expanded,
            'renamed' => $renamed,
        ];
    }

    /**
     * Run every rule and collect the findings.
     *
     * @return  array{findings: list<array{message: string, recordable: bool}>, inventory: CoreGrowthInventory,
     *          baseline: array{schema: string, note: string, symbols: array<string, array{kind: string,
     *          layer: string, surface: string, growth: array<string, mixed>|null}>}|null,
     *          approved: array<string, string>, renames: array<string, array{old_fqcn: string,
     *          migration_id: string}>}  Findings in rule order, the inventory, the baseline (null before the
     *          bootstrap snapshot), the approved Core Growth Record of each FQCN one names and the names the
     *          adopted migration ledgers retired, by the name that replaced each.
     *
     * @throws  GovernanceViolation  When the capability index is stale, a governance record or the baseline is
     *          malformed, or a production name cannot be classified.
     *
     * @since   2.0.0
     */
    private function evaluate(): array
    {
        $schemas = $this->schemaDirectory();
        $document = (new CapabilityIndexBuilder($this->root, $schemas))->build();
        $current = CapabilityIndexWriter::check($this->root, $document);
        if ($current['problems'] !== []) {
            throw GovernanceViolation::at(
                CapabilityIndexWriter::MARKDOWN_PATH,
                'stale capability index digest — run ' . self::INDEX_COMMAND,
                'regenerate and commit the capability index, then re-run the core growth check',
            );
        }
        $lock = ComposerLock::read($this->root . '/composer.lock');
        $records = GovernanceRecords::load($this->root, $schemas, $lock);
        $classifier = LayerClassifier::fromFile($this->root . '/' . self::LAYER_GRAPH);
        $sourceScans = PhpDeclarationScanner::scanTree(
            $this->root . '/' . CoreGrowthInventory::SOURCE_DIRECTORY,
            CoreGrowthInventory::SOURCE_DIRECTORY,
        );
        $retired = self::retiredRoots($document);
        $reintroductions = self::reintroductions($sourceScans, $retired);
        $sourceScans = $reintroductions['scans'];
        $inventory = CoreGrowthInventory::fromScans($sourceScans, $classifier);
        $baseline = $this->readBaseline();
        $approved = self::approvedRecords($records);
        $renames = self::renames($document);

        $findings = [];
        if ($baseline === null) {
            $findings[] = self::finding(
                self::BASELINE_PATH,
                'the baseline is missing',
                'record the bootstrap snapshot with ' . self::RECORD_COMMAND . ' and commit it',
                true,
            );
        }
        foreach (array_keys($baseline['symbols'] ?? []) as $fqcn) {
            if ($inventory->symbol($fqcn) === null) {
                $findings[] = self::finding(
                    self::BASELINE_PATH,
                    sprintf('baseline stale: %s is recorded but src/ no longer declares it', $fqcn),
                    're-record with ' . self::RECORD_COMMAND,
                    true,
                );
            }
        }
        array_push($findings, ...$reintroductions['findings']);
        array_push(
            $findings,
            ...$this->growthFindings($inventory, $baseline, $document, $records, $lock, $approved, $renames),
        );
        array_push($findings, ...$this->serviceFindings($document, $records, $sourceScans));
        array_push($findings, ...$this->referenceFindings($retired, $sourceScans));
        array_push($findings, ...self::recordFindings($records, $inventory, $baseline));

        return [
            'findings' => $findings,
            'inventory' => $inventory,
            'baseline' => $baseline,
            'approved' => $approved,
            'renames' => $renames,
        ];
    }

    /**
     * Rule 2: evaluate every new or changed production symbol.
     *
     * @param   CoreGrowthInventory    $inventory  Production symbols.
     * @param   array{schema: string, note: string, symbols: array<string, array{kind: string, layer: string,
     *          surface: string, growth: array<string, mixed>|null}>}|null  $baseline   The baseline, or null
     *          before the bootstrap snapshot.
     * @param   array<string, mixed>   $document   Capability index document.
     * @param   GovernanceRecords      $records    Governance records.
     * @param   ComposerLock           $lock       The lock, for package surfaces.
     * @param   array<string, string>  $approved   Approved Core Growth Record id by FQCN.
     * @param   array<string, array{old_fqcn: string, migration_id: string}>  $renames  Retired names by the name
     *          that replaced each, from the adopted migration ledgers.
     *
     * @return  list<array{message: string, recordable: bool}>  One finding per refused candidate.
     *
     * @throws  GovernanceViolation  When an installed package cannot be read for its public surface.
     *
     * @since   2.0.0
     */
    private function growthFindings(
        CoreGrowthInventory $inventory,
        ?array $baseline,
        array $document,
        GovernanceRecords $records,
        ComposerLock $lock,
        array $approved,
        array $renames,
    ): array {
        /** @var array<string, string> $ownership */
        $ownership = $document['ownership'];
        /** @var list<array{old_fqcn: string, new_fqcn: string, package: string, migration_id: string}> $removed */
        $removed = $document['removed_symbols'];
        $removedByFqcn = array_column($removed, null, 'old_fqcn');
        $snapshot = $baseline === null;
        $previous = $baseline['symbols'] ?? [];
        $surfaces = null;
        $findings = [];
        foreach ($inventory->symbols() as $fqcn => $symbol) {
            $entry = $previous[$fqcn] ?? null;
            if ($entry !== null && !self::isCandidate($symbol, $entry)) {
                continue;
            }
            $state = $entry === null ? 'new' : 'changed';
            $file = $symbol['file'];
            if (isset($ownership[$fqcn])) {
                $findings[] = self::finding(
                    $file,
                    sprintf(
                        'duplicate FQCN owner: %s is %s in src/ but %s exports it',
                        $fqcn,
                        $state,
                        $ownership[$fqcn],
                    ),
                    'consume the package symbol; App must not declare an FQCN an installed package owns',
                    false,
                );
                continue;
            }
            if (isset($removedByFqcn[$fqcn])) {
                $replacement = $removedByFqcn[$fqcn];
                $findings[] = self::finding(
                    $file,
                    sprintf(
                        'reintroduces the removed symbol %s (%s); use %s',
                        $fqcn,
                        $replacement['migration_id'],
                        $replacement['new_fqcn'],
                    ),
                    sprintf(
                        'consume %s from %s and delete the App declaration',
                        $replacement['new_fqcn'],
                        $replacement['package'],
                    ),
                    false,
                );
                continue;
            }
            $rename = self::renamedOnly($symbol, $entry, $renames);
            if ($rename !== null) {
                $findings[] = self::finding(
                    $file,
                    sprintf(
                        're-record: %s differs from the baseline only by the names %s retired (%s)',
                        $fqcn,
                        implode(', ', $rename['migrations']),
                        implode(', ', $rename['names']),
                    ),
                    'run ' . self::RECORD_COMMAND . ' and commit the baseline',
                    true,
                );
                continue;
            }
            if ($snapshot) {
                continue;
            }
            $surfaces ??= $this->packageSurfaces($lock);
            $overlap = self::overlap($symbol, $surfaces);
            if ($overlap !== null) {
                $record = $approved[$fqcn] ?? null;
                /** @var list<string> $reviewed */
                $reviewed = $record === null ? [] : $records->coreGrowth()[$record]['record']['overlap_reviewed'];
                if (!in_array($overlap['symbol'], $reviewed, true)) {
                    $findings[] = self::finding(
                        $file,
                        sprintf(
                            'likely duplicate responsibility of %s (%s): %s has the same short name, the same kind '
                            . 'and shares %d of its %d public method names (%s)',
                            $overlap['symbol'],
                            $overlap['package'],
                            $fqcn,
                            count($overlap['shared']),
                            count($symbol['methods']),
                            implode(', ', $overlap['shared']),
                        ),
                        sprintf(
                            'reuse the package symbol, or name %s in an approved Core Growth Record that lists %s '
                            . 'under overlap_reviewed',
                            $fqcn,
                            $overlap['symbol'],
                        ),
                        false,
                    );
                    continue;
                }
            }
            if (LayerClassifier::isPortable($symbol['layer'])) {
                $record = $approved[$fqcn] ?? null;
                if ($record === null) {
                    $named = self::recordNaming($records, $fqcn);
                    $findings[] = self::finding(
                        $file,
                        sprintf(
                            '%s is %s portable growth in the %s layer without an approved Core Growth Record%s',
                            $fqcn,
                            $state,
                            $symbol['layer'],
                            $named === null ? '' : sprintf('; %s names it but its decision is %s', ...$named),
                        ),
                        sprintf(
                            'either write %s/KUMWE-CGR-YYYY-NNN.md naming the FQCN (see %s/README.md), have it '
                            . 'approved and run %s, or move the behaviour to the package that owns it and consume '
                            . 'its public API',
                            self::RECORD_DIRECTORY,
                            self::RECORD_DIRECTORY,
                            self::RECORD_COMMAND,
                        ),
                        false,
                    );
                    continue;
                }
                $findings[] = self::finding(
                    $file,
                    sprintf(
                        're-record: %s is %s portable growth approved by %s that the baseline does not record',
                        $fqcn,
                        $state,
                        $record,
                    ),
                    'run ' . self::RECORD_COMMAND . ' and commit the baseline',
                    true,
                );
                continue;
            }
            $findings[] = self::finding(
                $file,
                sprintf(
                    're-record: %s is %s host growth (host-%s; implements %s; extends %s) that the baseline does '
                    . 'not record',
                    $fqcn,
                    $state,
                    $symbol['layer'],
                    $symbol['implements'] === [] ? 'nothing' : implode(', ', $symbol['implements']),
                    $symbol['extends'] ?? 'nothing',
                ),
                'run ' . self::RECORD_COMMAND . ' and commit the baseline',
                true,
            );
        }

        return $findings;
    }

    /**
     * Rule 2b, judged before classification: no production declaration sits under a retired namespace root.
     *
     * A retired root left with its extraction and has no layer rule of its own, so the classifier would refuse such a
     * declaration as unclassifiable before any growth rule could name the reintroduction. Judging it here names the
     * root and its owner, and withholds the declaration from the inventory so the rest of the check still runs.
     *
     * @param   list<array{file: string, namespace: string, imports: array<string, string>,
     *          declarations: list<array<string, mixed>>, references: list<array{name: string, line: int}>,
     *          strings: list<array{value: string, line: int}>}>  $scans    Scans of `src/`.
     * @param   array<string, string>  $retired  Retired roots to their owner description.
     *
     * @return  array{findings: list<array{message: string, recordable: bool}>, scans: list<array{file: string,
     *          namespace: string, imports: array<string, string>, declarations: list<array<string, mixed>>,
     *          references: list<array{name: string, line: int}>, strings: list<array{value: string, line: int}>}>}
     *          One finding per reintroduced declaration, and the scans without those declarations.
     *
     * @since   2.0.0
     */
    private static function reintroductions(array $scans, array $retired): array
    {
        $findings = [];
        foreach ($scans as $index => $scan) {
            $kept = [];
            foreach ($scan['declarations'] as $declaration) {
                /** @var string $fqcn */
                $fqcn = $declaration['fqcn'];
                $root = self::retiredRootOf($fqcn, $retired);
                if ($root === null) {
                    $kept[] = $declaration;
                    continue;
                }
                $findings[] = self::finding(
                    $scan['file'],
                    sprintf('reintroduces the extracted namespace %s (%s) with %s', $root, $retired[$root], $fqcn),
                    'use the package symbol the capability index names; no alias, remap, shadow or fallback',
                    false,
                );
            }
            $scans[$index]['declarations'] = $kept;
        }

        return ['findings' => $findings, 'scans' => $scans];
    }

    /**
     * Rule 3: a `src/Kernel/` array key must not name a service a package service map owns.
     *
     * @param   array<string, mixed>  $document  Capability index document.
     * @param   GovernanceRecords     $records   Governance records, for the intentional host bindings.
     * @param   list<array{file: string, namespace: string, imports: array<string, string>,
     *          declarations: list<array<string, mixed>>, references: list<array{name: string, line: int}>,
     *          strings: list<array{value: string, line: int}>}>  $sourceScans  Scans of `src/`.
     *
     * @return  list<array{message: string, recordable: bool}>  One finding per unrecorded registration.
     *
     * @throws  GovernanceViolation  When a kernel file cannot be read.
     *
     * @since   2.0.0
     */
    private function serviceFindings(array $document, GovernanceRecords $records, array $sourceScans): array
    {
        $services = [];
        /** @var list<array<string, mixed>> $packages */
        $packages = $document['packages'];
        foreach ($packages as $package) {
            /** @var array{factories: list<array{service: string, factory: string, lifetime: string}>,
             *   aliases: array<string, string>} $injection */
            $injection = $package['dependency_injection'];
            /** @var string $name */
            $name = $package['package'];
            foreach ($injection['factories'] as $factory) {
                $services[$factory['service']] = $name;
            }
            foreach (array_keys($injection['aliases']) as $alias) {
                $services[$alias] = $name;
            }
        }
        if ($services === []) {
            return [];
        }
        $intentional = [];
        foreach ($records->migrations() as $id => $migration) {
            /** @var list<array{service: string, factory: string, note: string}> $changes */
            $changes = $migration['record']['di_changes'];
            foreach ($changes as $change) {
                $intentional[$change['service']] = $id;
            }
        }
        $findings = [];
        foreach ($sourceScans as $scan) {
            if (!str_starts_with($scan['file'], self::KERNEL_DIRECTORY . '/')) {
                continue;
            }
            $mentioned = false;
            foreach ($scan['references'] as $reference) {
                if (isset($services[$reference['name']])) {
                    $mentioned = true;
                    break;
                }
            }
            foreach ($scan['strings'] as $string) {
                if (isset($services[ltrim(str_replace('\\\\', '\\', $string['value']), '\\')])) {
                    $mentioned = true;
                    break;
                }
            }
            if (!$mentioned) {
                continue;
            }
            $source = file_get_contents($this->root . '/' . $scan['file']);
            if (!is_string($source)) {
                throw GovernanceViolation::at($scan['file'], 'the kernel file cannot be read', 'restore the file');
            }
            foreach (self::arrayKeys($source, $scan['namespace'], $scan['imports']) as $key) {
                $owner = $services[$key['name']] ?? null;
                if ($owner === null || isset($intentional[$key['name']])) {
                    continue;
                }
                $findings[] = self::finding(
                    $scan['file'] . ':' . $key['line'],
                    sprintf(
                        'duplicate service owner: registers %s, which %s owns in its service map',
                        $key['name'],
                        $owner,
                    ),
                    sprintf(
                        'consume the package provider, or record the intentional host binding under di_changes of the '
                        . 'migration ledger record that adopted %s',
                        $owner,
                    ),
                    false,
                );
            }
        }

        return $findings;
    }

    /**
     * Rule 4: nothing under the reference directories names a retired namespace root.
     *
     * @param   array<string, string>  $retired  Retired roots to their owner description.
     * @param   list<array{file: string, namespace: string, imports: array<string, string>,
     *          declarations: list<array<string, mixed>>, references: list<array{name: string, line: int}>,
     *          strings: list<array{value: string, line: int}>}>  $sourceScans  Scans of `src/`, reused.
     *
     * @return  list<array{message: string, recordable: bool}>  One finding per file and line.
     *
     * @throws  GovernanceViolation  When a reference directory cannot be scanned.
     *
     * @since   2.0.0
     */
    private function referenceFindings(array $retired, array $sourceScans): array
    {
        if ($retired === []) {
            return [];
        }
        $fix = 'use the package symbol the capability index names; no alias, remap, shadow or fallback';
        $findings = [];
        foreach (self::REFERENCE_DIRECTORIES as $directory) {
            if ($directory === CoreGrowthInventory::SOURCE_DIRECTORY) {
                $scans = $sourceScans;
            } elseif (is_dir($this->root . '/' . $directory)) {
                $scans = PhpDeclarationScanner::scanTree($this->root . '/' . $directory, $directory);
            } else {
                continue;
            }
            foreach ($scans as $scan) {
                foreach ($scan['declarations'] as $declaration) {
                    /** @var string $fqcn */
                    $fqcn = $declaration['fqcn'];
                    /** @var int $line */
                    $line = $declaration['line'];
                    $root = self::retiredRootOf($fqcn, $retired);
                    if ($root === null) {
                        continue;
                    }
                    $findings[] = self::finding(
                        $scan['file'] . ':' . $line,
                        sprintf('declares %s under the retired namespace %s (%s)', $fqcn, $root, $retired[$root]),
                        $fix,
                        false,
                    );
                }
                foreach ($scan['references'] as $reference) {
                    $root = self::retiredRootOf($reference['name'], $retired);
                    if ($root === null) {
                        continue;
                    }
                    $findings[] = self::finding(
                        $scan['file'] . ':' . $reference['line'],
                        sprintf(
                            'references %s under the retired namespace %s (%s)',
                            $reference['name'],
                            $root,
                            $retired[$root],
                        ),
                        $fix,
                        false,
                    );
                }
                if (self::isAssertionTest($scan['file'])) {
                    continue;
                }
                foreach ($scan['strings'] as $string) {
                    $root = self::retiredRootInString($string['value'], $retired);
                    if ($root === null) {
                        continue;
                    }
                    $findings[] = self::finding(
                        $scan['file'] . ':' . $string['line'],
                        sprintf('a string literal names the retired namespace %s (%s)', $root, $retired[$root]),
                        $fix . '; an assertion that the namespace is absent belongs in a test under '
                        . self::ASSERTION_TEST_DIRECTORY,
                        false,
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * Rule 5: every Core Growth Record names declared FQCNs in their layer, and the baseline cites only approved ones.
     *
     * @param   GovernanceRecords    $records    Governance records.
     * @param   CoreGrowthInventory  $inventory  Production symbols.
     * @param   array{schema: string, note: string, symbols: array<string, array{kind: string, layer: string,
     *          surface: string, growth: array<string, mixed>|null}>}|null  $baseline   The baseline, or null.
     *
     * @return  list<array{message: string, recordable: bool}>  One finding per broken record or citation.
     *
     * @since   2.0.0
     */
    private static function recordFindings(
        GovernanceRecords $records,
        CoreGrowthInventory $inventory,
        ?array $baseline,
    ): array {
        $findings = [];
        $growthRecords = $records->coreGrowth();
        foreach ($growthRecords as $growthRecord) {
            /** @var string $layer */
            $layer = $growthRecord['record']['layer'];
            /** @var list<string> $symbols */
            $symbols = $growthRecord['record']['symbols'];
            foreach ($symbols as $fqcn) {
                $symbol = $inventory->symbol($fqcn);
                if ($symbol === null) {
                    $findings[] = self::finding(
                        $growthRecord['path'],
                        sprintf('names %s, which src/ does not declare', $fqcn),
                        'name exactly the FQCNs the record approves, or remove the record',
                        false,
                    );
                    continue;
                }
                if ($symbol['layer'] !== $layer) {
                    $findings[] = self::finding(
                        $growthRecord['path'],
                        sprintf(
                            'records layer %s for %s, which %s classifies as %s',
                            $layer,
                            $fqcn,
                            self::LAYER_GRAPH,
                            $symbol['layer'],
                        ),
                        'set layer to ' . $symbol['layer'],
                        false,
                    );
                }
            }
        }
        foreach ($baseline['symbols'] ?? [] as $fqcn => $entry) {
            $growth = $entry['growth'];
            if (!is_array($growth) || !isset($growth['record'])) {
                continue;
            }
            $id = self::string($growth['record']);
            $growthRecord = $growthRecords[$id] ?? null;
            if ($growthRecord === null) {
                $findings[] = self::finding(
                    self::BASELINE_PATH,
                    sprintf('%s cites %s, which has no record under %s', $fqcn, $id, self::RECORD_DIRECTORY),
                    'restore the record, or re-record after approval of a replacement',
                    false,
                );
                continue;
            }
            $decision = self::string($growthRecord['record']['decision']);
            if ($decision !== 'approved') {
                $findings[] = self::finding(
                    self::BASELINE_PATH,
                    sprintf('%s cites %s, whose decision is %s', $fqcn, $id, $decision),
                    'have the record approved by a reviewer, or move the behaviour upstream and re-record',
                    false,
                );
                continue;
            }
            /** @var list<string> $named */
            $named = $growthRecord['record']['symbols'];
            if (!in_array($fqcn, $named, true)) {
                $findings[] = self::finding(
                    self::BASELINE_PATH,
                    sprintf('%s cites %s, which does not name it', $fqcn, $id),
                    'name the FQCN in the record and re-record',
                    false,
                );
            }
        }

        return $findings;
    }

    /**
     * The public surface of every exported package symbol, for the responsibility-overlap rule.
     *
     * Version 2 packages are read from their public API manifest, source-scanned legacy packages from the
     * declarations the scan produced, and legacy packages shipping a pre-Version-2 manifest from that manifest's
     * `types` map. Nothing under `vendor/` is parsed a second time.
     *
     * @param   ComposerLock  $lock  The repository lock.
     *
     * @return  array<string, array{package: string, kind: string, methods: list<string>}>  By exported FQCN, sorted.
     *
     * @throws  GovernanceViolation  When an installed package cannot be read.
     *
     * @since   2.0.0
     */
    private function packageSurfaces(ComposerLock $lock): array
    {
        $surfaces = [];
        foreach (array_keys($lock->packages()) as $name) {
            $display = 'vendor/kumwe/' . substr($name, strlen('kumwe/'));
            $manifests = PackageManifests::read($this->root . '/' . $display, $display, $this->schemaDirectory());
            $exported = array_fill_keys($manifests->publicSymbols(), true);
            $publicApi = $manifests->publicApi();
            if ($publicApi !== null) {
                /** @var array<string, array<string, mixed>> $symbols */
                $symbols = $publicApi['symbols'];
                foreach ($symbols as $fqcn => $symbol) {
                    $surfaces[$fqcn] = [
                        'package' => $name,
                        'kind' => self::string($symbol['kind'] ?? 'class'),
                        'methods' => self::publicMethodNames($symbol['methods'] ?? []),
                    ];
                }
                continue;
            }
            $declarations = $manifests->declarations();
            if ($declarations !== []) {
                foreach ($declarations as $declaration) {
                    /** @var string $fqcn */
                    $fqcn = $declaration['fqcn'];
                    if (!isset($exported[$fqcn])) {
                        continue;
                    }
                    /** @var array<string, mixed> $methods */
                    $methods = $declaration['methods'];
                    $surfaces[$fqcn] = [
                        'package' => $name,
                        'kind' => self::string($declaration['kind']),
                        'methods' => self::publicMethodNames($methods),
                    ];
                }
                continue;
            }
            $path = $manifests->publicApiPath();
            if ($path === null) {
                continue;
            }
            $bytes = file_get_contents($this->root . '/' . $path);
            /** @var mixed $decoded */
            $decoded = is_string($bytes) ? json_decode($bytes, true) : null;
            $types = is_array($decoded) ? ($decoded['types'] ?? null) : null;
            if (!is_array($types)) {
                throw GovernanceViolation::at($path, 'the manifest has no "types" object', 'regenerate the manifest');
            }
            foreach ($types as $fqcn => $type) {
                if (!is_string($fqcn) || !isset($exported[$fqcn]) || !is_array($type)) {
                    continue;
                }
                $surfaces[$fqcn] = [
                    'package' => $name,
                    'kind' => self::string($type['kind'] ?? 'class'),
                    'methods' => self::publicMethodNames($type['methods'] ?? []),
                ];
            }
        }
        ksort($surfaces, SORT_STRING);

        return $surfaces;
    }

    /**
     * The public method names of a manifest or scan `methods` map.
     *
     * @param   mixed  $methods  A map of method name to facts (with an optional `visibility`), or a list of facts
     *          each carrying a `name`.
     *
     * @return  list<string>  Sorted, without repeats.
     *
     * @since   2.0.0
     */
    private static function publicMethodNames(mixed $methods): array
    {
        if (!is_array($methods)) {
            return [];
        }
        $names = [];
        foreach ($methods as $name => $method) {
            if (is_int($name)) {
                $name = is_array($method) ? ($method['name'] ?? null) : null;
            }
            if (!is_string($name)) {
                continue;
            }
            $visibility = is_array($method) ? ($method['visibility'] ?? 'public') : 'public';
            if ($visibility === 'public') {
                $names[$name] = true;
            }
        }
        $names = array_keys($names);
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * The package symbol a candidate most likely duplicates, if any.
     *
     * @param   array{fqcn: string, short_name: string, kind: string, methods: list<string>}  $symbol    Candidate.
     * @param   array<string, array{package: string, kind: string, methods: list<string>}>    $surfaces  Package
     *          symbols.
     *
     * @return  array{symbol: string, package: string, shared: list<string>}|null  The first package symbol with
     *          the same short name, the same kind and at least half of the candidate's public method names, when
     *          the candidate has at least two; null otherwise.
     *
     * @since   2.0.0
     */
    private static function overlap(array $symbol, array $surfaces): ?array
    {
        $methods = $symbol['methods'];
        if (count($methods) < 2) {
            return null;
        }
        foreach ($surfaces as $fqcn => $surface) {
            if (self::shortName($fqcn) !== $symbol['short_name'] || $surface['kind'] !== $symbol['kind']) {
                continue;
            }
            $shared = array_values(array_intersect($methods, $surface['methods']));
            if (count($shared) * 2 >= count($methods)) {
                return ['symbol' => $fqcn, 'package' => $surface['package'], 'shared' => $shared];
            }
        }

        return null;
    }

    /**
     * The class names used as array keys in one PHP source, as `Foo::class =>` or `'Foo\Bar' =>`.
     *
     * @param   string                 $source     Complete PHP source.
     * @param   string                 $namespace  Namespace of the file, for resolution.
     * @param   array<string, string>  $imports    Imports of the file, alias to fully qualified name.
     *
     * @return  list<array{name: string, line: int}>  Resolved names without a leading backslash, in source order.
     *
     * @since   2.0.0
     */
    private static function arrayKeys(string $source, string $namespace, array $imports): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $significant = static function (int $from) use ($tokens, $count): ?int {
            for ($cursor = $from + 1; $cursor < $count; $cursor++) {
                $token = $tokens[$cursor];
                if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                return $cursor;
            }

            return null;
        };
        $is = static fn (?int $index, int $id): bool => $index !== null
            && is_array($tokens[$index])
            && $tokens[$index][0] === $id;
        $keys = [];
        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                if ($is($significant($index), T_DOUBLE_ARROW)) {
                    $keys[] = ['name' => ltrim(self::unquote($token[1]), '\\'), 'line' => $token[2]];
                }
                continue;
            }
            if (!in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                continue;
            }
            $scope = $significant($index);
            if (!$is($scope, T_DOUBLE_COLON)) {
                continue;
            }
            $class = $significant((int) $scope);
            if (!$is($class, T_CLASS) || !$is($significant((int) $class), T_DOUBLE_ARROW)) {
                continue;
            }
            $keys[] = [
                'name' => self::resolveName($token[0], $token[1], $namespace, $imports),
                'line' => $token[2],
            ];
        }

        return $keys;
    }

    /**
     * Resolve a name token the way `PhpDeclarationScanner` does.
     *
     * @param   int                    $id         Token identifier.
     * @param   string                 $text       Token text.
     * @param   string                 $namespace  Namespace of the file.
     * @param   array<string, string>  $imports    Imports of the file.
     *
     * @return  string  Fully qualified name without a leading backslash.
     *
     * @since   2.0.0
     */
    private static function resolveName(int $id, string $text, string $namespace, array $imports): string
    {
        if ($id === T_NAME_FULLY_QUALIFIED) {
            return ltrim($text, '\\');
        }
        if ($id === T_NAME_RELATIVE) {
            $rest = substr($text, strlen('namespace\\'));

            return $namespace === '' ? $rest : $namespace . '\\' . $rest;
        }
        $segments = explode('\\', $text);
        if (isset($imports[$segments[0]])) {
            $segments[0] = $imports[$segments[0]];

            return implode('\\', $segments);
        }

        return $namespace === '' ? $text : $namespace . '\\' . $text;
    }

    /**
     * The value of a quoted string literal token.
     *
     * @param   string  $literal  Token text including its quotes.
     *
     * @return  string  The unescaped value.
     *
     * @since   2.0.0
     */
    private static function unquote(string $literal): string
    {
        $body = substr($literal, 1, -1);
        if (str_starts_with($literal, '"')) {
            return stripcslashes($body);
        }

        return str_replace(['\\\\', "\\'"], ['\\', "'"], $body);
    }

    /**
     * The retired namespace roots the index knows, each with the owner that retired it.
     *
     * @param   array<string, mixed>  $document  Capability index document.
     *
     * @return  array<string, string>  Root with its trailing backslash to a description such as
     *          `retired by KUMWE-MIG-2026-001 for kumwe/example-v2`, sorted.
     *
     * @since   2.0.0
     */
    private static function retiredRoots(array $document): array
    {
        $retired = [];
        /** @var list<array{old_namespace: string, package: string, migration_id: string}> $extracted */
        $extracted = $document['extracted_namespaces'];
        foreach ($extracted as $entry) {
            $retired[$entry['old_namespace']] = sprintf(
                'retired by %s for %s',
                $entry['migration_id'],
                $entry['package'],
            );
        }
        /** @var list<array<string, mixed>> $packages */
        $packages = $document['packages'];
        foreach ($packages as $package) {
            /** @var array{retired_app_namespaces: list<string>}|null $legacy */
            $legacy = $package['legacy'];
            if ($legacy === null) {
                continue;
            }
            foreach ($legacy['retired_app_namespaces'] as $root) {
                $retired[$root] ??= sprintf('retired for %s', self::string($package['package']));
            }
        }
        ksort($retired, SORT_STRING);

        return $retired;
    }

    /**
     * The retired root a fully qualified name or namespace sits under, if any.
     *
     * @param   string                 $name     Name without a leading backslash.
     * @param   array<string, string>  $retired  Retired roots.
     *
     * @return  string|null  The root, or null.
     *
     * @since   2.0.0
     */
    private static function retiredRootOf(string $name, array $retired): ?string
    {
        foreach (array_keys($retired) as $root) {
            if (str_starts_with($name . '\\', $root)) {
                return $root;
            }
        }

        return null;
    }

    /**
     * The retired root a string literal names, if any, however many backslashes spell each separator.
     *
     * @param   string                 $value    Raw literal text between its quotes.
     * @param   array<string, string>  $retired  Retired roots.
     *
     * @return  string|null  The root, or null.
     *
     * @since   2.0.0
     */
    private static function retiredRootInString(string $value, array $retired): ?string
    {
        $normalized = (string) preg_replace('/\\\\+/', '\\', $value);
        foreach (array_keys($retired) as $root) {
            if (str_contains($normalized, $root) || str_ends_with($normalized, rtrim($root, '\\'))) {
                return $root;
            }
        }

        return null;
    }

    /**
     * Decide whether a file is an architecture test allowed to name a retired root inside a string literal.
     *
     * @param   string  $file  Repository-relative path.
     *
     * @return  bool  True for `tests/Architecture/**\/*Test.php`.
     *
     * @since   2.0.0
     */
    private static function isAssertionTest(string $file): bool
    {
        return str_starts_with($file, self::ASSERTION_TEST_DIRECTORY . '/') && str_ends_with($file, 'Test.php');
    }

    /**
     * The approved Core Growth Record of every FQCN one names.
     *
     * @param   GovernanceRecords  $records  Governance records.
     *
     * @return  array<string, string>  FQCN to record id.
     *
     * @since   2.0.0
     */
    private static function approvedRecords(GovernanceRecords $records): array
    {
        $approved = [];
        foreach ($records->coreGrowth() as $id => $growthRecord) {
            if ($growthRecord['record']['decision'] !== 'approved') {
                continue;
            }
            /** @var list<string> $symbols */
            $symbols = $growthRecord['record']['symbols'];
            foreach ($symbols as $fqcn) {
                $approved[$fqcn] = $id;
            }
        }

        return $approved;
    }

    /**
     * The Core Growth Record naming an FQCN, whatever its decision.
     *
     * @param   GovernanceRecords  $records  Governance records.
     * @param   string             $fqcn     Fully qualified name.
     *
     * @return  array{0: string, 1: string}|null  Record id and decision, or null when no record names it.
     *
     * @since   2.0.0
     */
    private static function recordNaming(GovernanceRecords $records, string $fqcn): ?array
    {
        foreach ($records->coreGrowth() as $id => $growthRecord) {
            /** @var list<string> $symbols */
            $symbols = $growthRecord['record']['symbols'];
            if (in_array($fqcn, $symbols, true)) {
                return [$id, self::string($growthRecord['record']['decision'])];
            }
        }

        return null;
    }

    /**
     * The names every adopted migration ledger retired, keyed by the package symbol that replaced each.
     *
     * @param   array<string, mixed>  $document  Capability index document.
     *
     * @return  array<string, array{old_fqcn: string, migration_id: string}>  By `new_fqcn`; a mapping whose two
     *          names are equal contributes nothing, and the first ledger to name a replacement keeps it.
     *
     * @since   2.0.0
     */
    private static function renames(array $document): array
    {
        /** @var list<array{old_fqcn: string, new_fqcn: string, package: string, migration_id: string}> $removed */
        $removed = $document['removed_symbols'];
        $renames = [];
        foreach ($removed as $symbol) {
            if ($symbol['old_fqcn'] === $symbol['new_fqcn'] || isset($renames[$symbol['new_fqcn']])) {
                continue;
            }
            $renames[$symbol['new_fqcn']] = [
                'old_fqcn' => $symbol['old_fqcn'],
                'migration_id' => $symbol['migration_id'],
            ];
        }

        return $renames;
    }

    /**
     * Whether a changed symbol's surface differs from its baseline entry only by retired names.
     *
     * The baseline recorded the surface spelled with App names a later migration ledger retired; spelling those
     * names as the package symbols the ledger maps them to moves the digest without changing the surface. The
     * comparison rewrites the current canonical surface back to the retired names and matches the result against
     * the recorded digest, so any other difference in the surface still counts as growth.
     *
     * @param   array{kind: string, layer: string, surface: string, canonical: string}  $symbol   The symbol.
     * @param   array{kind: string, layer: string, surface: string, growth: array<string, mixed>|null}|null  $entry
     *          Its baseline entry, or null for a new symbol.
     * @param   array<string, array{old_fqcn: string, migration_id: string}>  $renames  Retired names by the name
     *          that replaced each.
     *
     * @return  array{names: list<string>, migrations: list<string>}|null  The `old -> new` renames the surface
     *          carries and the ledgers that retired them, or null when the change is not a pure rename.
     *
     * @since   2.0.0
     */
    private static function renamedOnly(array $symbol, ?array $entry, array $renames): ?array
    {
        if (
            $entry === null
            || $renames === []
            || $entry['surface'] === $symbol['surface']
            || $entry['kind'] !== $symbol['kind']
            || $entry['layer'] !== $symbol['layer']
        ) {
            return null;
        }
        $canonical = $symbol['canonical'];
        $names = [];
        $migrations = [];
        foreach ($renames as $new => $rename) {
            $pattern = '/(?<![\\\\\w])' . preg_quote($new, '/') . '(?![\\\\\w])/';
            $rewritten = preg_replace($pattern, $rename['old_fqcn'], $canonical);
            if (!is_string($rewritten) || $rewritten === $canonical) {
                continue;
            }
            $canonical = $rewritten;
            $names[] = $rename['old_fqcn'] . ' -> ' . $new;
            $migrations[] = $rename['migration_id'];
        }
        if ($names === [] || CoreGrowthInventory::digest($canonical) !== $entry['surface']) {
            return null;
        }
        sort($names, SORT_STRING);

        return ['names' => $names, 'migrations' => array_values(array_unique($migrations))];
    }

    /**
     * Decide whether a production symbol is new or changed relative to its baseline entry.
     *
     * @param array{kind: string, layer: string, surface: string} $symbol Symbol.
     * @param   array{kind: string, layer: string, surface: string, growth: array<string, mixed>|null}|null  $entry
     *          Baseline entry, or null when the baseline does not know the symbol.
     *
     * @return  bool  True when the entry is absent or its kind, layer or surface differs.
     *
     * @since   2.0.0
     */
    private static function isCandidate(array $symbol, ?array $entry): bool
    {
        return $entry === null
            || $entry['surface'] !== $symbol['surface']
            || $entry['kind'] !== $symbol['kind']
            || $entry['layer'] !== $symbol['layer'];
    }

    /**
     * Count the baseline entries carrying growth evidence.
     *
     * @param   array<string, array{kind: string, layer: string, surface: string, growth: array<string, mixed>|null}>
     *          $symbols  Baseline symbols.
     *
     * @return  int  Entries whose `growth` is not null.
     *
     * @since   2.0.0
     */
    private static function recorded(array $symbols): int
    {
        $count = 0;
        foreach ($symbols as $entry) {
            if ($entry['growth'] !== null) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Build one finding.
     *
     * @param   string  $file        Repository-relative path, with `:line` where known.
     * @param   string  $rule        What was expected and what was found instead.
     * @param   string  $fix         The action that clears it.
     * @param   bool    $recordable  Whether `record()` clears it by rewriting the baseline.
     *
     * @return  array{message: string, recordable: bool}  The finding.
     *
     * @since   2.0.0
     */
    private static function finding(string $file, string $rule, string $fix, bool $recordable): array
    {
        return ['message' => GovernanceViolation::at($file, $rule, $fix)->getMessage(), 'recordable' => $recordable];
    }

    /**
     * The last segment of a qualified name.
     *
     * @param   string  $name  Fully qualified name.
     *
     * @return  string  The short name.
     *
     * @since   2.0.0
     */
    private static function shortName(string $name): string
    {
        $position = strrpos($name, '\\');

        return $position === false ? $name : substr($name, $position + 1);
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
