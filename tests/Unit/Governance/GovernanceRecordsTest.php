<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Governance;

use Kumwe\App\Tools\Governance\ComposerLock;
use Kumwe\App\Tools\Governance\GovernanceRecords;
use Kumwe\App\Tools\Governance\GovernanceViolation;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Holds `tools/Governance/GovernanceRecords.php` to the record invariants of the governance guide.
 *
 * Every record type is loaded from its directory, validated against its schema and cross-checked with the lock,
 * the installed handoff and its sibling records; a Core Growth Record must carry its seven sections and a
 * reviewer once approved.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class GovernanceRecordsTest extends TestCase
{
    /**
     * Load the governance classes once.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/tools/Governance/bootstrap.php';
    }

    /**
     * The clean fixture loads its ledger record, change set and attestation, and finds the record of a package.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheCleanFixtureLoads(): void
    {
        $records = self::load(GovernanceFixture::cleanRoot());

        self::assertSame(['KUMWE-MIG-2026-001'], array_keys($records->migrations()));
        self::assertSame(['KUMWE-CS-2026-001'], array_keys($records->changeSets()));
        self::assertSame(['RELEASE-ATTESTATION.yaml'], array_keys($records->evidence()['KUMWE-MIG-2026-001']));
        self::assertSame([], $records->conflicts());
        self::assertSame([], $records->trains());
        self::assertSame([], $records->nonRoadmap());
        self::assertSame([], $records->coreGrowth());
        self::assertSame([], $records->legacyEvidence());
        self::assertSame('KUMWE-MIG-2026-001', $records->migrationForPackage('kumwe/example-v2')['id'] ?? null);
        self::assertNull($records->migrationForPackage('kumwe/example-legacy'));
    }

    /**
     * A retained host test cannot resolve through a symlink to evidence outside its recorded path.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRetainedHostEvidenceCannotUseASymlinkedParent(): void
    {
        $root = GovernanceFixture::copy();
        $directory = $root . '/tests/Integration/Example';
        $moved = $root . '/tests/Relocated';
        try {
            self::assertTrue(rename($directory, $moved));
            self::assertTrue(symlink($moved, $directory));
            try {
                self::load($root);
                self::fail('A symlinked parent must not count as local host evidence.');
            } catch (GovernanceViolation $violation) {
                self::assertStringContainsString('resolves through a symlink', $violation->getMessage());
            }
        } finally {
            if (is_link($directory)) {
                unlink($directory);
            }
            GovernanceFixture::remove($root);
        }
    }

    /**
     * Conflict, train, non-roadmap, legacy evidence and approved Core Growth Records load when well-formed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testWellFormedRecordsOfEveryTypeLoad(): void
    {
        $root = GovernanceFixture::copy();
        $examples = GovernanceFixture::repositoryRoot() . '/docs/architecture/governance/examples/';
        try {
            $conflict = (string) file_get_contents($examples . 'conflict-ledger.v1.example.yaml');
            GovernanceFixture::write(
                $root,
                'docs/architecture/migrations/conflicts/KUMWE-CONFLICT-2026-001.yaml',
                $conflict,
            );
            $train = (string) file_get_contents($examples . 'integration-train.v1.example.yaml');
            GovernanceFixture::write($root, 'docs/architecture/migrations/trains/KUMWE-TRAIN-2026-001.yaml', $train);
            $nrm = str_replace(
                'NRM-2026-099',
                'NRM-2026-001',
                (string) file_get_contents($examples . 'non-roadmap-record.v1.example.yaml'),
            );
            GovernanceFixture::write($root, 'docs/architecture/non-roadmap/NRM-2026-001.yaml', $nrm);
            $legacy = (string) file_get_contents($examples . 'verified-legacy-release.v1.example.yaml');
            GovernanceFixture::write(
                $root,
                'docs/architecture/migrations/evidence/legacy/example-legacy/VERIFIED-LEGACY-RELEASE.yaml',
                $legacy,
            );
            $growth = (string) file_get_contents($examples . 'core-growth-record.v1.example.md');
            GovernanceFixture::write($root, 'docs/architecture/core-growth/KUMWE-CGR-2026-001.md', $growth);
            GovernanceFixture::write($root, 'docs/architecture/core-growth/README.md', "# Core Growth Records\n");
            GovernanceFixture::write($root, 'docs/architecture/migrations/README.md', "# Migrations\n");

            $records = self::load($root);

            self::assertSame(['KUMWE-CONFLICT-2026-001'], array_keys($records->conflicts()));
            self::assertSame(['KUMWE-TRAIN-2026-001'], array_keys($records->trains()));
            self::assertSame(['NRM-2026-001'], array_keys($records->nonRoadmap()));
            self::assertSame(
                ['docs/architecture/migrations/evidence/legacy/example-legacy/VERIFIED-LEGACY-RELEASE.yaml'],
                array_keys($records->legacyEvidence()),
            );
            self::assertSame(['KUMWE-CGR-2026-001'], array_keys($records->coreGrowth()));
            self::assertSame('approved', $records->coreGrowth()['KUMWE-CGR-2026-001']['record']['decision']);
            self::assertStringContainsString('## Decision', $records->coreGrowth()['KUMWE-CGR-2026-001']['body']);
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * A pending Core Growth Record without a reviewer loads; the reviewer is required only once approved.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAPendingRecordNeedsNoReviewer(): void
    {
        $root = GovernanceFixture::copy();
        $example = self::example('core-growth-record.v1.example.md');
        try {
            $pending = str_replace(
                ['decision: approved', 'reviewer: "Platform architecture"'],
                ['decision: pending', 'reviewer: null'],
                $example,
            );
            GovernanceFixture::write($root, 'docs/architecture/core-growth/KUMWE-CGR-2026-001.md', $pending);

            self::assertSame('pending', self::load($root)->coreGrowth()['KUMWE-CGR-2026-001']['record']['decision']);
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * Each record invariant refuses a mutated tree with the file and the rule.
     *
     * @param   list<array{0: string, 1: string, 2: string}>  $mutations  File, search (empty to write), replacement.
     * @param   string                                        $rule       Fragment of the expected message.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('brokenRecords')]
    public function testBrokenRecordsAreRefused(array $mutations, string $rule): void
    {
        $root = GovernanceFixture::copy();
        try {
            foreach ($mutations as [$relative, $search, $replace]) {
                if ($search === '') {
                    GovernanceFixture::write($root, $relative, $replace);
                } else {
                    GovernanceFixture::replace($root, $relative, $search, $replace);
                }
            }
            try {
                self::load($root);
                self::fail('The records must be refused: ' . $rule);
            } catch (GovernanceViolation $violation) {
                self::assertStringContainsString($rule, $violation->getMessage());
                self::assertStringContainsString('Fix:', $violation->getMessage());
            }
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * Mutations that break a governance record.
     *
     * @return  iterable<string, array{list<array{0: string, 1: string, 2: string}>, string}>  Mutations and rule.
     *
     * @since   2.0.0
     */
    public static function brokenRecords(): iterable
    {
        $ledger = 'docs/architecture/migrations/KUMWE-MIG-2026-001.yaml';
        $changeSet = 'docs/architecture/migrations/change-sets/KUMWE-CS-2026-001.yaml';
        $attestation = 'docs/architecture/migrations/evidence/KUMWE-MIG-2026-001/RELEASE-ATTESTATION.yaml';
        $example = self::example('core-growth-record.v1.example.md');

        yield 'duplicate package test still present after adoption' => [
            [['tests/Unit/Example/Describing/DescriberTest.php', '', "<?php\n// Restored duplicate.\n"]],
            'still exists in App',
        ];
        yield 'released test removal cannot be omitted from the ledger' => [
            [[$ledger, "removed_tests:\n  - tests/Unit/Example/Describing/DescriberTest.php", 'removed_tests: []'],
                ['tests/Unit/Example/Describing/DescriberTest.php', '', "<?php\n// Restored duplicate.\n"]],
            'released handoff test removal tests/Unit/Example/Describing/DescriberTest.php is missing',
        ];
        yield 'retained host test absent' => [
            [[$ledger, 'DescribeSubjectIntegrationTest.php', 'MissingIntegrationTest.php']],
            'retained host test tests/Integration/Example/MissingIntegrationTest.php is missing',
        ];
        yield 'same test removed and retained' => [
            [[$ledger, 'tests/Integration/Example/DescribeSubjectIntegrationTest.php',
                'tests/Unit/Example/Describing/DescriberTest.php']],
            'both removed and retained',
        ];
        yield 'test ownership path traversal' => [
            [[$ledger, 'tests/Unit/Example/Describing/DescriberTest.php', 'tests/../outside.php']],
            'non-canonical App test path',
        ];
        yield 'test ownership does not accept directory claims' => [
            [[$ledger, 'tests/Unit/Example/Describing/DescriberTest.php', 'tests/Unit/Example/']],
            'non-canonical App test path',
        ];

        yield 'ledger id differs from file name' => [
            [[$ledger, 'migration_id: KUMWE-MIG-2026-001', 'migration_id: KUMWE-MIG-2026-002']],
            'differs from the file name',
        ];
        yield 'ledger file misnamed' => [
            [['docs/architecture/migrations/KUMWE-MIG-1.yaml', '', "schema: kumwe-migration-ledger/v1\n"]],
            'not KUMWE-MIG-YYYY-NNN.yaml',
        ];
        yield 'ledger fails its schema' => [
            [[$ledger, 'app_pull_request: "https://github.com/kumwe/app/pull/1234"', 'app_pull_request: 1234']],
            'fails migration-ledger.v1.schema.json',
        ];
        yield 'ledger names an unlocked package' => [
            [[$ledger, 'package: kumwe/example-v2', 'package: kumwe/example-v4']],
            'not locked',
        ];
        yield 'ledger version differs from the lock' => [
            [[$ledger, 'version: 0.1.0', 'version: 0.1.1']],
            'differs from the locked',
        ];
        $handoffDigest = GovernanceFixture::digest(
            GovernanceFixture::cleanRoot(),
            'vendor/kumwe/example-v2/MIGRATION-HANDOFF.md',
        );
        yield 'ledger handoff digest mismatch' => [
            [[$ledger, $handoffDigest, strrev($handoffDigest)]],
            'differs from the installed handoff digest',
        ];
        yield 'ledger handoff path elsewhere' => [
            [[$ledger, 'handoff_path: vendor/kumwe/example-v2/MIGRATION-HANDOFF.md', 'handoff_path: docs/HANDOFF.md']],
            'handoff_path must be',
        ];
        yield 'ledger without its change set' => [
            [
                [$ledger, 'change_set: KUMWE-CS-2026-001', 'change_set: KUMWE-CS-2026-001'],
                [$changeSet, 'change_set: KUMWE-CS-2026-001', 'change_set: KUMWE-CS-2026-002'],
            ],
            'differs from the file name',
        ];
        yield 'change set sequence mismatch' => [
            [[$changeSet, 'migration_id: KUMWE-MIG-2026-001', 'migration_id: KUMWE-MIG-2026-002']],
            'D-GOV-2',
        ];
        yield 'change set outside the canonical states' => [
            [[$changeSet, 'state: core-integrated', 'state: app-integrated']],
            'must be one of',
        ];
        yield 'ledger without its attestation' => [
            [
                [
                    $ledger,
                    'release_attestation: ' . $attestation,
                    'release_attestation: ' . dirname($attestation) . '/MISSING.yaml',
                ],
            ],
            'is not an evidence record',
        ];
        yield 'attestation failed under the verified name' => [
            [[$attestation, 'status: verified', 'status: failed']],
            'exists only with status verified',
        ];
        yield 'failed verification without gaps' => [
            [
                [
                    'docs/architecture/migrations/evidence/KUMWE-MIG-2026-001/RELEASE-VERIFICATION-FAILED.yaml',
                    '',
                    str_replace(
                        'status: verified',
                        'status: failed',
                        (string) file_get_contents(GovernanceFixture::cleanRoot() . '/' . $attestation),
                    ),
                ],
            ],
            'non-empty known_gaps',
        ];
        $archiveDigest = hash('sha256', 'kumwe/example-v2 0.1.0 source archive');
        yield 'attestation artifact digest differs' => [
            [[$attestation, $archiveDigest, strrev($archiveDigest)]],
            'differs from the attested source archive digest',
        ];
        yield 'attestation version differs' => [
            [[$attestation, 'version: 0.1.0', 'version: 0.1.1']],
            'differs from the locked',
        ];
        yield 'evidence of an unknown migration' => [
            [
                [
                    'docs/architecture/migrations/evidence/KUMWE-MIG-2026-009/RELEASE-ATTESTATION.yaml',
                    '',
                    "schema: kumwe-release-attestation/v2\n",
                ],
            ],
            'names no migration ledger record',
        ];
        yield 'evidence with an unknown schema' => [
            [['docs/architecture/migrations/evidence/KUMWE-MIG-2026-001/OTHER.yaml', '', "schema: kumwe-other/v1\n"]],
            'not an evidence record type',
        ];
        yield 'non-roadmap nrm_ref mismatch' => [
            [
                [
                    'docs/architecture/non-roadmap/NRM-2026-001.yaml',
                    '',
                    str_replace(
                        ['id: NRM-2026-099', 'nrm_ref: NRM-2026-099'],
                        ['id: NRM-2026-001', 'nrm_ref: NRM-2026-002'],
                        self::example('non-roadmap-record.v1.example.yaml'),
                    ),
                ],
            ],
            'differs from id',
        ];
        yield 'approved growth record without a reviewer' => [
            [
                [
                    'docs/architecture/core-growth/KUMWE-CGR-2026-001.md',
                    '',
                    str_replace('reviewer: "Platform architecture"', 'reviewer: null', $example),
                ],
            ],
            'has no reviewer',
        ];
        yield 'growth record missing a section' => [
            [
                [
                    'docs/architecture/core-growth/KUMWE-CGR-2026-001.md',
                    '',
                    str_replace('## Tests proving the boundary', '## Tests', $example),
                ],
            ],
            'section "## Tests proving the boundary" is missing',
        ];
        yield 'growth record with an unknown decision' => [
            [
                [
                    'docs/architecture/core-growth/KUMWE-CGR-2026-001.md',
                    '',
                    str_replace('decision: approved', 'decision: maybe', $example),
                ],
            ],
            'must be one of',
        ];
        yield 'growth record misnamed' => [
            [['docs/architecture/core-growth/CGR-1.md', '', $example]],
            'not KUMWE-CGR-YYYY-NNN.md',
        ];
        yield 'two growth records naming one symbol' => [
            [
                ['docs/architecture/core-growth/KUMWE-CGR-2026-001.md', '', $example],
                [
                    'docs/architecture/core-growth/KUMWE-CGR-2026-002.md',
                    '',
                    str_replace('id: KUMWE-CGR-2026-001', 'id: KUMWE-CGR-2026-002', $example),
                ],
            ],
            'is already named by',
        ];
        yield 'conflict naming an unknown change set' => [
            [
                [
                    'docs/architecture/migrations/conflicts/KUMWE-CONFLICT-2026-001.yaml',
                    '',
                    str_replace(
                        'KUMWE-CS-2026-001',
                        'KUMWE-CS-2026-007',
                        self::example('conflict-ledger.v1.example.yaml'),
                    ),
                ],
            ],
            'has no record',
        ];
        yield 'train naming a missing ledger path' => [
            [
                [
                    'docs/architecture/migrations/trains/KUMWE-TRAIN-2026-001.yaml',
                    '',
                    str_replace(
                        'conflict_ledger: docs/architecture/migrations/conflicts',
                        'conflict_ledger: docs/absent',
                        self::example('integration-train.v1.example.yaml'),
                    ),
                ],
            ],
            'does not exist',
        ];
    }

    /**
     * Read one documented example.
     *
     * @param   string  $name  File name under docs/architecture/governance/examples.
     *
     * @return  string  File bytes.
     *
     * @since   2.0.0
     */
    private static function example(string $name): string
    {
        return (string) file_get_contents(
            GovernanceFixture::repositoryRoot() . '/docs/architecture/governance/examples/' . $name,
        );
    }

    /**
     * Load the records of a root with its own lock.
     *
     * @param   string  $root  Repository or fixture root.
     *
     * @return  GovernanceRecords  The records.
     *
     * @since   2.0.0
     */
    private static function load(string $root): GovernanceRecords
    {
        return GovernanceRecords::load(
            $root,
            GovernanceFixture::schemaDirectory(),
            ComposerLock::read($root . '/composer.lock'),
        );
    }
}
