<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Governance;

use Kumwe\App\Tools\Governance\CoreGrowthGate;
use Kumwe\App\Tools\Governance\CoreGrowthInventory;
use Kumwe\App\Tools\Governance\GovernanceViolation;
use Kumwe\App\Tools\Governance\LayerClassifier;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Holds `tools/Governance/CoreGrowthGate.php` and `tools/verify-core-growth.php` to the check rules of the gate.
 *
 * Every rule is exercised against a mutated scratch copy of the clean fixture: a stale baseline, unrecorded
 * portable and host growth, a pending and an approved Core Growth Record, a duplicate FQCN owner, a reintroduced
 * namespace or removed symbol, a likely duplicate responsibility, a duplicate service owner, a reference to a
 * retired namespace inside and outside an architecture test, a stale capability index, broken records and
 * citations, the bootstrap snapshot and a malformed baseline. `--record` refuses on every hard finding and writes
 * nothing, and writes identical bytes twice otherwise.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class CoreGrowthGateTest extends TestCase
{
    /**
     * Repository-relative path of the example Core Growth Record the tests derive records from.
     *
     * @var    string
     * @since  2.0.0
     */
    private const EXAMPLE_RECORD = 'docs/architecture/governance/examples/core-growth-record.v1.example.md';

    /**
     * Repository-relative path of the fixture ledger record.
     *
     * @var    string
     * @since  2.0.0
     */
    private const LEDGER = 'docs/architecture/migrations/KUMWE-MIG-2026-001.yaml';

    /**
     * A domain-layer FQCN the fixture does not declare.
     *
     * @var    string
     * @since  2.0.0
     */
    private const NEW_DOMAIN_CLASS = 'Kumwe\\App\\Example\\Domain\\Other';

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
     * The clean fixture passes through the class and the tool, and recording it twice is byte-identical to the
     * committed fixture baseline.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheCleanFixturePassesAndRecordsDeterministically(): void
    {
        self::assertVerified(GovernanceFixture::cleanRoot(), 4, 0);
        $check = self::runGate(['--root=' . GovernanceFixture::cleanRoot()]);
        self::assertSame(0, $check['status'], $check['output']);
        self::assertSame(
            'Core growth verified (4 production symbols; 0 recorded growth entries; no duplicate owners).',
            $check['output'],
        );

        $root = GovernanceFixture::copy();
        try {
            $committed = GovernanceFixture::read($root, CoreGrowthGate::BASELINE_PATH);
            $first = self::runGate(['--record', '--root=' . $root]);
            self::assertSame(0, $first['status'], $first['output']);
            self::assertStringContainsString(
                'Core growth baseline recorded (4 production symbols; 0 recorded growth entries; '
                . '0 added, 0 removed, 0 expanded, 0 renamed).',
                $first['output'],
            );
            self::assertSame($committed, GovernanceFixture::read($root, CoreGrowthGate::BASELINE_PATH));
            $second = self::runGate(['--record', '--root=' . $root]);
            self::assertSame(0, $second['status'], $second['output']);
            self::assertSame($committed, GovernanceFixture::read($root, CoreGrowthGate::BASELINE_PATH));
            self::assertStringNotContainsString($root, $committed, 'No absolute path leaks into the baseline.');
            self::assertSame($committed, (new CoreGrowthGate($root))->preview()['json']);
            self::assertFalse(is_dir($root . '/build'), 'Recording writes nothing under build/.');
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * A symbol removed from `src/` leaves the baseline stale until it is re-recorded; its consumers keep their surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testARemovedSymbolMakesTheBaselineStaleUntilRecorded(): void
    {
        $root = GovernanceFixture::copy();
        try {
            GovernanceFixture::delete($root, 'src/Example/Domain/ExampleSubject.php');
            $failures = self::assertRefused($root, 'baseline stale: Kumwe\\App\\Example\\Domain\\ExampleSubject');
            self::assertStringContainsString('re-record with composer kumwe:core-growth-record', $failures[0]);

            $result = (new CoreGrowthGate($root))->record();
            self::assertSame([], $result['failures']);
            self::assertTrue($result['written']);
            self::assertSame(['Kumwe\\App\\Example\\Domain\\ExampleSubject'], $result['removed']);
            self::assertSame([], $result['expanded']);
            self::assertSame(3, $result['symbols']);
            self::assertVerified($root, 3, 0);
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * A new portable FQCN fails naming both ways forward, `--record` refuses and writes nothing, a pending record
     * still fails, and an approved record passes once recorded with `growth.record`.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNewPortableGrowthNeedsAnApprovedRecord(): void
    {
        $root = GovernanceFixture::copy();
        try {
            $committed = GovernanceFixture::read($root, CoreGrowthGate::BASELINE_PATH);
            GovernanceFixture::write(
                $root,
                'src/Example/Domain/Other.php',
                self::classSource(
                    'Kumwe\\App\\Example\\Domain',
                    'Other',
                    "    public function name(): string\n    {\n        return 'x';\n    }\n",
                ),
            );
            $failures = self::assertRefused(
                $root,
                'src/Example/Domain/Other.php: ' . self::NEW_DOMAIN_CLASS
                . ' is new portable growth in the domain layer without an approved Core Growth Record',
            );
            self::assertCount(1, $failures);
            self::assertStringContainsString('docs/architecture/core-growth/KUMWE-CGR-YYYY-NNN.md', $failures[0]);
            self::assertStringContainsString('docs/architecture/core-growth/README.md', $failures[0]);
            self::assertStringContainsString('or move the behaviour to the package that owns it', $failures[0]);

            $refused = self::runGate(['--record', '--root=' . $root]);
            self::assertSame(1, $refused['status'], $refused['output']);
            self::assertStringContainsString('Core growth: src/Example/Domain/Other.php: ', $refused['output']);
            self::assertStringContainsString(
                'Core growth: ' . CoreGrowthGate::BASELINE_PATH . ' was not written',
                $refused['output'],
            );
            self::assertSame($committed, GovernanceFixture::read($root, CoreGrowthGate::BASELINE_PATH));
            self::assertFalse((new CoreGrowthGate($root))->record()['written']);

            GovernanceFixture::write(
                $root,
                'docs/architecture/core-growth/KUMWE-CGR-2026-001.md',
                self::growthRecord(self::NEW_DOMAIN_CLASS, 'domain', 'pending'),
            );
            $failures = self::assertRefused($root, 'KUMWE-CGR-2026-001 names it but its decision is pending');
            self::assertCount(1, $failures);

            GovernanceFixture::write(
                $root,
                'docs/architecture/core-growth/KUMWE-CGR-2026-001.md',
                self::growthRecord(self::NEW_DOMAIN_CLASS, 'domain'),
            );
            $failures = self::assertRefused(
                $root,
                're-record: ' . self::NEW_DOMAIN_CLASS . ' is new portable growth approved by KUMWE-CGR-2026-001',
            );
            self::assertCount(1, $failures);

            $recorded = self::runGate(['--record', '--root=' . $root]);
            self::assertSame(0, $recorded['status'], $recorded['output']);
            self::assertStringContainsString('  added ' . self::NEW_DOMAIN_CLASS, $recorded['output']);
            self::assertStringContainsString(
                '5 production symbols; 1 recorded growth entries; 1 added',
                $recorded['output'],
            );
            $baseline = (new CoreGrowthGate($root))->readBaseline();
            self::assertNotNull($baseline);
            self::assertSame(
                ['record' => 'KUMWE-CGR-2026-001'],
                $baseline['symbols'][self::NEW_DOMAIN_CLASS]['growth'],
            );
            self::assertVerified($root, 5, 1);
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * A widened public surface in a portable layer is growth; a private change is not.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAWidenedPortableSurfaceIsGrowthWhileAPrivateChangeIsNot(): void
    {
        $root = GovernanceFixture::copy();
        try {
            $file = 'src/Example/Application/DescribeSubject.php';
            $anchor = "    public function describe(ExampleSubject \$subject): string\n";
            GovernanceFixture::replace(
                $root,
                $file,
                $anchor,
                "    private function trim(string \$text): string\n    {\n        return trim(\$text);\n    }\n\n"
                . $anchor,
            );
            self::assertVerified($root, 4, 0);

            GovernanceFixture::replace(
                $root,
                $file,
                $anchor,
                "    public function shout(string \$text): string\n    {\n"
                . "        return strtoupper(\$text);\n    }\n\n" . $anchor,
            );
            $failures = self::assertRefused(
                $root,
                'Kumwe\\App\\Example\\Application\\DescribeSubject is changed portable growth in the application layer',
            );
            self::assertCount(1, $failures);
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * A public signature that now spells a name a migration ledger retired as the package symbol replacing it is
     * a rename: the check asks for a re-record, the record reports it as renamed and keeps the entry's growth
     * evidence, and any further widening of the same surface is still refused as growth.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testASurfaceChangedOnlyByALedgerRenameIsReRecordedNotGrowth(): void
    {
        $root = GovernanceFixture::copy();
        try {
            $fqcn = 'Kumwe\\App\\Example\\Application\\DescribeSubject';
            $retiredName = 'Kumwe\\App\\Example\\Describing\\DescriberInterface';
            $packageName = 'Kumwe\\Example\\Contract\\ExampleServiceInterface';
            $symbol = CoreGrowthInventory::scan(
                $root,
                LayerClassifier::fromFile($root . '/' . CoreGrowthGate::LAYER_GRAPH),
            )->symbol($fqcn);
            self::assertNotNull($symbol);
            self::assertStringContainsString($packageName, $symbol['canonical']);
            $retiredSurface = CoreGrowthInventory::digest(
                str_replace($packageName, $retiredName, $symbol['canonical']),
            );
            self::recordSurface($root, $fqcn, $retiredSurface);

            $failures = self::assertRefused(
                $root,
                sprintf(
                    're-record: %s differs from the baseline only by the names KUMWE-MIG-2026-001 retired (%s -> %s)',
                    $fqcn,
                    $retiredName,
                    $packageName,
                ),
            );
            self::assertCount(1, $failures);

            $recorded = self::runGate(['--record', '--root=' . $root]);
            self::assertSame(0, $recorded['status'], $recorded['output']);
            self::assertStringContainsString('renamed ' . $fqcn, $recorded['output']);
            self::assertStringContainsString('0 added, 0 removed, 0 expanded, 1 renamed', $recorded['output']);
            self::assertVerified($root, 4, 0);

            self::recordSurface($root, $fqcn, $retiredSurface);
            $anchor = "    public function describe(ExampleSubject \$subject): string\n";
            GovernanceFixture::replace(
                $root,
                'src/Example/Application/DescribeSubject.php',
                $anchor,
                "    public function shout(string \$text): string\n    {\n"
                . "        return strtoupper(\$text);\n    }\n\n" . $anchor,
            );
            $failures = self::assertRefused(
                $root,
                $fqcn . ' is changed portable growth in the application layer',
            );
            self::assertCount(1, $failures);
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * Host-layer growth fails until recorded, is recorded as `implements`/`extends` evidence, and fails again when
     * those facts change.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testHostGrowthIsRecordedAsAdapterEvidence(): void
    {
        $root = GovernanceFixture::copy();
        try {
            $fqcn = 'Kumwe\\App\\Example\\Infrastructure\\ShoutingExampleService';
            $file = 'src/Example/Infrastructure/ShoutingExampleService.php';
            $adapter = static fn (string $implements): string => self::classSource(
                'Kumwe\\App\\Example\\Infrastructure',
                'ShoutingExampleService' . $implements,
                "    public function describe(string \$subject): string\n    {\n"
                . "        return strtoupper(\$subject);\n    }\n",
                'use Kumwe\\Example\\Contract\\ExampleServiceInterface;',
            );
            GovernanceFixture::write($root, $file, $adapter(' implements ExampleServiceInterface'));
            $failures = self::assertRefused(
                $root,
                're-record: ' . $fqcn . ' is new host growth (host-infrastructure; implements '
                . 'Kumwe\\Example\\Contract\\ExampleServiceInterface; extends nothing)',
            );
            self::assertCount(1, $failures);

            $result = (new CoreGrowthGate($root))->record();
            self::assertTrue($result['written']);
            self::assertSame([$fqcn], $result['added']);
            $baseline = (new CoreGrowthGate($root))->readBaseline();
            self::assertNotNull($baseline);
            self::assertSame(
                [
                    'classification' => 'host-infrastructure',
                    'implements' => ['Kumwe\\Example\\Contract\\ExampleServiceInterface'],
                    'extends' => null,
                ],
                $baseline['symbols'][$fqcn]['growth'],
            );
            self::assertVerified($root, 5, 1);

            GovernanceFixture::write($root, $file, $adapter(''));
            $failures = self::assertRefused(
                $root,
                're-record: ' . $fqcn
                . ' is changed host growth (host-infrastructure; implements nothing; extends nothing)',
            );
            self::assertCount(1, $failures);

            GovernanceFixture::replace(
                $root,
                'src/Kernel/ContainerFactory.php',
                "    public function factories(): array\n",
                "    public function aliases(): array\n    {\n        return [];\n    }\n\n"
                . "    public function factories(): array\n",
            );
            self::assertContains(
                'src/Kernel/ContainerFactory.php: re-record: Kumwe\\App\\Kernel\\ContainerFactory is changed host '
                . 'growth '
                . '(host-kernel; implements nothing; extends nothing) that the baseline does not record. '
                . 'Fix: run composer kumwe:core-growth-record and commit the baseline.',
                (new CoreGrowthGate($root))->check()['failures'],
            );
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * An FQCN an installed package exports is refused as a duplicate owner, and `--record` refuses it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testADuplicateFqcnOwnerIsRefused(): void
    {
        $root = GovernanceFixture::copy();
        try {
            GovernanceFixture::write(
                $root,
                'src/Example/ExampleService.php',
                self::classSource('Kumwe\\Example', 'ExampleService'),
            );
            $failures = self::assertRefused(
                $root,
                'src/Example/ExampleService.php: duplicate FQCN owner: Kumwe\\Example\\ExampleService is new in src/ '
                . 'but kumwe/example-v2 exports it',
            );
            self::assertCount(1, $failures);
            self::assertStringContainsString('App must not declare an FQCN an installed package owns', $failures[0]);
            self::assertFalse((new CoreGrowthGate($root))->record()['written']);
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * A declaration under an extracted namespace root, or under a namespace a legacy package retired, is refused once.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAReintroducedNamespaceIsRefused(): void
    {
        $root = GovernanceFixture::copy();
        try {
            GovernanceFixture::write(
                $root,
                'src/Example/Describing/NewDescriber.php',
                self::classSource('Kumwe\\App\\Example\\Describing', 'NewDescriber'),
            );
            $failures = self::assertRefused(
                $root,
                'src/Example/Describing/NewDescriber.php: reintroduces the extracted namespace '
                . 'Kumwe\\App\\Example\\Describing\\ (retired by KUMWE-MIG-2026-001 for kumwe/example-v2) with '
                . 'Kumwe\\App\\Example\\Describing\\NewDescriber',
            );
            self::assertCount(1, $failures, 'The reference rule does not report the declaration a second time.');
            self::assertStringContainsString('no alias, remap, shadow or fallback', $failures[0]);
            GovernanceFixture::delete($root, 'src/Example/Describing');

            GovernanceFixture::write(
                $root,
                'src/Example/Formatting/Formatter.php',
                self::classSource('Kumwe\\App\\Example\\Formatting', 'Formatter'),
            );
            $failures = self::assertRefused(
                $root,
                'reintroduces the extracted namespace Kumwe\\App\\Example\\Formatting\\ '
                . '(retired for kumwe/example-legacy)',
            );
            self::assertCount(1, $failures);
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * A declaration equal to a removed symbol is refused with its replacement named, after the capability index is
     * regenerated for the ledger change that recorded the removal.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAReintroducedRemovedSymbolNamesItsReplacement(): void
    {
        $root = GovernanceFixture::copy();
        try {
            GovernanceFixture::replace(
                $root,
                self::LEDGER,
                "symbols:\n",
                "symbols:\n  - old_fqcn: Kumwe\\App\\Example\\Domain\\LegacyDescriber\n"
                . "    new_fqcn: Kumwe\\Example\\ExampleService\n",
            );
            self::assertRefused($root, 'stale capability index digest — run composer kumwe:capability-index');
            $written = GovernanceFixture::run(['--write', '--root=' . $root]);
            self::assertSame(0, $written['status'], $written['output']);
            self::assertVerified($root, 4, 0);

            GovernanceFixture::write(
                $root,
                'src/Example/Domain/LegacyDescriber.php',
                self::classSource('Kumwe\\App\\Example\\Domain', 'LegacyDescriber'),
            );
            $failures = self::assertRefused(
                $root,
                'src/Example/Domain/LegacyDescriber.php: reintroduces the removed symbol '
                . 'Kumwe\\App\\Example\\Domain\\LegacyDescriber (KUMWE-MIG-2026-001); '
                . 'use Kumwe\\Example\\ExampleService',
            );
            self::assertCount(1, $failures);
            self::assertStringContainsString(
                'Fix: consume Kumwe\\Example\\ExampleService from kumwe/example-v2 and delete the App declaration',
                $failures[0],
            );
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * A same-named, same-kind class sharing half its public method names with a package symbol is refused until an
     * approved record lists that symbol under `overlap_reviewed`; a disjoint or single-method class is not flagged.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testALikelyDuplicateResponsibilityIsRefusedUntilReviewed(): void
    {
        $root = GovernanceFixture::copy();
        try {
            $fqcn = 'Kumwe\\App\\Example\\Infrastructure\\ExampleService';
            $file = 'src/Example/Infrastructure/ExampleService.php';
            $twin = static fn (string $first, string $second): string => self::classSource(
                'Kumwe\\App\\Example\\Infrastructure',
                'ExampleService',
                "    public function {$first}(string \$prefix): void\n    {\n    }\n\n"
                . "    public function {$second}(string \$subject): string\n    {\n        return \$subject;\n    }\n",
            );
            GovernanceFixture::write($root, $file, $twin('__construct', 'describe'));
            $failures = self::assertRefused(
                $root,
                $file . ': likely duplicate responsibility of Kumwe\\Example\\ExampleService (kumwe/example-v2): '
                . $fqcn . ' has the same short name, the same kind and shares 2 of its 2 public method names '
                . '(__construct, describe)',
            );
            self::assertCount(1, $failures);
            self::assertStringContainsString(
                'lists Kumwe\\Example\\ExampleService under overlap_reviewed',
                $failures[0],
            );
            self::assertFalse((new CoreGrowthGate($root))->record()['written']);

            GovernanceFixture::write(
                $root,
                'docs/architecture/core-growth/KUMWE-CGR-2026-001.md',
                self::growthRecord($fqcn, 'infrastructure', 'approved', ['Kumwe\\Example\\ExampleService']),
            );
            $failures = self::assertRefused($root, 're-record: ' . $fqcn . ' is new host growth');
            self::assertCount(1, $failures);
            self::assertTrue((new CoreGrowthGate($root))->record()['written']);
            self::assertVerified($root, 5, 1);

            GovernanceFixture::delete($root, 'docs/architecture/core-growth/KUMWE-CGR-2026-001.md');
            GovernanceFixture::write($root, $file, $twin('run', 'stop'));
            $failures = self::assertRefused($root, 're-record: ' . $fqcn . ' is changed host growth');
            self::assertCount(1, $failures, 'Disjoint method names are not an overlap.');

            GovernanceFixture::write(
                $root,
                $file,
                self::classSource(
                    'Kumwe\\App\\Example\\Infrastructure',
                    'ExampleService',
                    "    public function describe(string \$subject): string\n    {\n        return \$subject;\n    }\n",
                ),
            );
            $failures = self::assertRefused($root, 're-record: ' . $fqcn . ' is changed host growth');
            self::assertCount(1, $failures, 'Fewer than two public methods is never an overlap.');
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * A `src/Kernel/` array key naming a package-owned service or alias is refused unless the migration ledger
     * records the host binding under `di_changes`.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testADuplicateServiceOwnerNeedsALedgerEntry(): void
    {
        $root = GovernanceFixture::copy();
        try {
            $ledger = GovernanceFixture::read($root, self::LEDGER);
            $stripped = (string) preg_replace('/di_changes:\n(?:  - .*\n|    .*\n)+/', "di_changes: []\n", $ledger);
            self::assertNotSame($ledger, $stripped);
            GovernanceFixture::write($root, self::LEDGER, $stripped);
            $failures = self::assertRefused(
                $root,
                'src/Kernel/ContainerFactory.php:27: duplicate service owner: registers '
                . 'Kumwe\\Example\\Contract\\ExampleServiceInterface, which kumwe/example-v2 owns in its service map',
            );
            self::assertCount(1, $failures);
            self::assertStringContainsString(
                'record the intentional host binding under di_changes of the migration ledger record that adopted '
                . 'kumwe/example-v2',
                $failures[0],
            );
            GovernanceFixture::write($root, self::LEDGER, $ledger);
            self::assertVerified($root, 4, 0);

            GovernanceFixture::write(
                $root,
                'src/Kernel/ServiceFactories.php',
                self::classSource(
                    'Kumwe\\App\\Kernel',
                    'ServiceFactories',
                    "    public function map(): array\n    {\n        return [\n"
                    . "            'Kumwe\\\\Example\\\\ExampleService' => ExampleService::class,\n"
                    . "            [ExampleService::class],\n        ];\n    }\n",
                    'use Kumwe\\Example\\ExampleService;',
                ),
            );
            $failures = (new CoreGrowthGate($root))->check()['failures'];
            self::assertCount(2, $failures);
            self::assertStringContainsString('re-record: Kumwe\\App\\Kernel\\ServiceFactories', $failures[0]);
            self::assertStringContainsString(
                'src/Kernel/ServiceFactories.php:14: duplicate service owner: registers Kumwe\\Example\\ExampleService',
                $failures[1],
            );
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * An import, inline name or `::class` naming a retired root fails everywhere; a string literal fails except in
     * a test file under `tests/Architecture/`, where it is an absence assertion.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReferencesToRetiredNamespacesAreRefusedExceptArchitectureAssertions(): void
    {
        $root = GovernanceFixture::copy();
        try {
            $kernel = 'src/Kernel/ContainerFactory.php';
            $import = "use Kumwe\\Example\\Contract\\ExampleServiceInterface;\n";
            GovernanceFixture::replace(
                $root,
                $kernel,
                $import,
                "use Kumwe\\App\\Example\\Describing\\Describer;\n" . $import,
            );
            $failures = self::assertRefused(
                $root,
                'src/Kernel/ContainerFactory.php:8: references Kumwe\\App\\Example\\Describing\\Describer under the '
                . 'retired namespace Kumwe\\App\\Example\\Describing\\ '
                . '(retired by KUMWE-MIG-2026-001 for kumwe/example-v2)',
            );
            self::assertCount(1, $failures);
            GovernanceFixture::replace($root, $kernel, "use Kumwe\\App\\Example\\Describing\\Describer;\n", '');
            self::assertVerified($root, 4, 0);

            $assertion = "<?php\n\nnamespace Kumwe\\App\\Tests\\Architecture;\n\nfinal class AbsenceTest\n{\n"
                . "    public function testAbsent(): void\n    {\n"
                . "        \$single = 'Kumwe\\\\App\\\\Example\\\\Describing\\\\';\n"
                . "        \$double = \"Kumwe\\\\App\\\\Example\\\\Formatting\\\\\";\n"
                . "        \$json = '{\"class\": \"Kumwe\\\\\\\\App\\\\\\\\Example\\\\\\\\Describing\\\\\\\\X\"}';\n"
                . "        \$bare = 'Kumwe\\App\\Example\\Describing';\n    }\n}\n";
            GovernanceFixture::write($root, 'tests/Architecture/AbsenceTest.php', $assertion);
            self::assertVerified($root, 4, 0);

            GovernanceFixture::write($root, 'tests/Unit/AbsenceTest.php', $assertion);
            $failures = self::assertRefused(
                $root,
                'tests/Unit/AbsenceTest.php:9: a string literal names the retired namespace '
                . 'Kumwe\\App\\Example\\Describing\\',
            );
            self::assertCount(4, $failures);
            self::assertStringContainsString('tests/Unit/AbsenceTest.php:10: a string literal', $failures[1]);
            self::assertStringContainsString('Kumwe\\App\\Example\\Formatting\\', $failures[1]);
            self::assertStringContainsString('tests/Unit/AbsenceTest.php:11: a string literal', $failures[2]);
            self::assertStringContainsString('tests/Unit/AbsenceTest.php:12: a string literal', $failures[3]);
            self::assertStringContainsString(
                'an assertion that the namespace is absent belongs in a test under tests/Architecture',
                $failures[0],
            );
            GovernanceFixture::delete($root, 'tests/Unit');

            GovernanceFixture::write($root, 'tests/Architecture/Support.php', $assertion);
            $failures = self::assertRefused($root, 'tests/Architecture/Support.php:9: a string literal');
            self::assertCount(4, $failures, 'Only *Test.php files carry the allowance.');
            GovernanceFixture::delete($root, 'tests/Architecture/Support.php');

            GovernanceFixture::write(
                $root,
                'tests/Architecture/ClassRefTest.php',
                "<?php\n\nnamespace Kumwe\\App\\Tests\\Architecture;\n\n"
                . "use Kumwe\\App\\Example\\Describing\\Describer;\n\n"
                . "final class ClassRefTest\n{\n    public function testRef(): string\n    {\n"
                . "        return Describer::class;\n    }\n}\n",
            );
            $failures = self::assertRefused($root, 'tests/Architecture/ClassRefTest.php:5: references');
            self::assertCount(2, $failures);
            self::assertStringContainsString('tests/Architecture/ClassRefTest.php:11: references', $failures[1]);
            GovernanceFixture::delete($root, 'tests/Architecture/ClassRefTest.php');

            GovernanceFixture::write(
                $root,
                'examples/describe.php',
                "<?php\n\n\$service = 'Kumwe\\\\App\\\\Example\\\\Describing\\\\Describer';\n",
            );
            $failures = self::assertRefused($root, 'examples/describe.php:3: a string literal');
            self::assertCount(1, $failures);
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * A stale capability index is the only finding reported, before any growth rule runs.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAStaleCapabilityIndexBlocksTheCheckFirst(): void
    {
        $root = GovernanceFixture::copy();
        try {
            GovernanceFixture::replace(
                $root,
                self::LEDGER,
                "old_namespace_roots:\n",
                "old_namespace_roots:\n  - Kumwe\\App\\Example\\Legacy\\\n",
            );
            GovernanceFixture::write(
                $root,
                'src/Example/Domain/Other.php',
                self::classSource('Kumwe\\App\\Example\\Domain', 'Other'),
            );
            try {
                (new CoreGrowthGate($root))->check();
                self::fail('A stale index must be refused.');
            } catch (GovernanceViolation $violation) {
                self::assertStringStartsWith(
                    'docs/architecture/capability-index.md: stale capability index digest — run '
                    . 'composer kumwe:capability-index.',
                    $violation->getMessage(),
                );
            }
            $stale = self::runGate(['--root=' . $root]);
            self::assertSame(1, $stale['status']);
            self::assertSame(
                'Core growth: docs/architecture/capability-index.md: stale capability index digest — run '
                . 'composer kumwe:capability-index. Fix: regenerate and commit the capability index, then re-run the '
                . 'core growth check.',
                $stale['output'],
            );
            $refused = self::runGate(['--record', '--root=' . $root]);
            self::assertSame(1, $refused['status']);

            $written = GovernanceFixture::run(['--write', '--root=' . $root]);
            self::assertSame(0, $written['status'], $written['output']);
            $failures = self::assertRefused($root, self::NEW_DOMAIN_CLASS . ' is new portable growth');
            self::assertCount(1, $failures);
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * Two records naming one FQCN, a record naming an undeclared FQCN or the wrong layer, and a baseline citing a
     * pending, unknown or non-naming record are refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBrokenRecordsAndCitationsAreRefused(): void
    {
        $root = GovernanceFixture::copy();
        $subject = 'Kumwe\\App\\Example\\Application\\DescribeSubject';
        $first = 'docs/architecture/core-growth/KUMWE-CGR-2026-001.md';
        $second = 'docs/architecture/core-growth/KUMWE-CGR-2026-002.md';
        try {
            GovernanceFixture::write($root, $first, self::growthRecord($subject, 'application'));
            self::assertVerified($root, 4, 0);
            GovernanceFixture::write(
                $root,
                $second,
                self::growthRecord($subject, 'application', 'approved', [], 'KUMWE-CGR-2026-002'),
            );
            self::assertRefused($root, $subject . ' is already named by KUMWE-CGR-2026-001');
            GovernanceFixture::delete($root, $second);

            GovernanceFixture::write($root, $first, self::growthRecord('Kumwe\\App\\Example\\Domain\\Gone', 'domain'));
            $failures = self::assertRefused(
                $root,
                $first . ': names Kumwe\\App\\Example\\Domain\\Gone, which src/ does not declare',
            );
            self::assertCount(1, $failures);

            GovernanceFixture::write($root, $first, self::growthRecord($subject, 'domain'));
            $failures = self::assertRefused(
                $root,
                $first . ': records layer domain for ' . $subject . ', which docs/architecture/layers.json classifies '
                . 'as application',
            );
            self::assertCount(1, $failures);
            self::assertStringContainsString('Fix: set layer to application', $failures[0]);

            GovernanceFixture::write($root, $first, self::growthRecord($subject, 'application', 'pending'));
            self::cite($root, $subject, 'KUMWE-CGR-2026-001');
            $failures = self::assertRefused(
                $root,
                CoreGrowthGate::BASELINE_PATH . ': ' . $subject
                . ' cites KUMWE-CGR-2026-001, whose decision is pending',
            );
            self::assertCount(1, $failures);
            self::assertFalse((new CoreGrowthGate($root))->record()['written']);

            GovernanceFixture::write($root, $first, self::growthRecord($subject, 'application'));
            self::assertVerified($root, 4, 1);

            GovernanceFixture::write(
                $root,
                $first,
                self::growthRecord('Kumwe\\App\\Example\\Domain\\ExampleSubject', 'domain'),
            );
            $failures = self::assertRefused($root, $subject . ' cites KUMWE-CGR-2026-001, which does not name it');
            self::assertCount(1, $failures);

            GovernanceFixture::delete($root, $first);
            $failures = self::assertRefused(
                $root,
                $subject . ' cites KUMWE-CGR-2026-001, which has no record under docs/architecture/core-growth',
            );
            self::assertCount(1, $failures);
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * Without a baseline, the check reports only that it is missing, `--record` writes the bootstrap snapshot with
     * `growth` null for every symbol, and a reintroduction is still refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMissingBaselineIsRecordedAsTheBootstrapSnapshot(): void
    {
        $root = GovernanceFixture::copy();
        try {
            GovernanceFixture::delete($root, CoreGrowthGate::BASELINE_PATH);
            GovernanceFixture::write(
                $root,
                'src/Example/Domain/Other.php',
                self::classSource('Kumwe\\App\\Example\\Domain', 'Other'),
            );
            $failures = self::assertRefused($root, CoreGrowthGate::BASELINE_PATH . ': the baseline is missing');
            self::assertCount(1, $failures, 'Portable growth is not judged before the bootstrap snapshot exists.');
            self::assertStringContainsString(
                'record the bootstrap snapshot with composer kumwe:core-growth-record',
                $failures[0],
            );

            $result = (new CoreGrowthGate($root))->record();
            self::assertTrue($result['written']);
            self::assertSame(5, $result['symbols']);
            self::assertSame(0, $result['recorded']);
            self::assertCount(5, $result['added']);
            $baseline = (new CoreGrowthGate($root))->readBaseline();
            self::assertNotNull($baseline);
            self::assertSame(CoreGrowthGate::SCHEMA, $baseline['schema']);
            self::assertSame(CoreGrowthGate::NOTE, $baseline['note']);
            self::assertSame([null], array_values(array_unique(array_column($baseline['symbols'], 'growth'))));
            self::assertVerified($root, 5, 0);

            GovernanceFixture::delete($root, CoreGrowthGate::BASELINE_PATH);
            GovernanceFixture::write(
                $root,
                'src/Example/Describing/NewDescriber.php',
                self::classSource('Kumwe\\App\\Example\\Describing', 'NewDescriber'),
            );
            $result = (new CoreGrowthGate($root))->record();
            self::assertFalse($result['written']);
            self::assertCount(1, $result['failures']);
            self::assertStringContainsString('reintroduces the extracted namespace', $result['failures'][0]);
            self::assertFileDoesNotExist($root . '/' . CoreGrowthGate::BASELINE_PATH);
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * A hand-edited baseline that fails its schema or is not JSON is refused with the re-record fix.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMalformedBaselineIsRefused(): void
    {
        $root = GovernanceFixture::copy();
        try {
            $baseline = (new CoreGrowthGate($root))->readBaseline();
            self::assertNotNull($baseline);
            $baseline['symbols']['Kumwe\\App\\Kernel\\ContainerFactory']['surface'] = 'not-a-digest';
            GovernanceFixture::write($root, CoreGrowthGate::BASELINE_PATH, CoreGrowthGate::json($baseline));
            try {
                (new CoreGrowthGate($root))->check();
                self::fail('A baseline failing its schema must be refused.');
            } catch (GovernanceViolation $violation) {
                self::assertStringContainsString('fails core-growth-baseline.v1.schema.json', $violation->getMessage());
                self::assertStringContainsString('never hand-edited', $violation->getMessage());
            }
            $tool = self::runGate(['--root=' . $root]);
            self::assertSame(1, $tool['status']);
            self::assertStringStartsWith('Core growth: ' . CoreGrowthGate::BASELINE_PATH . ': fails', $tool['output']);

            GovernanceFixture::write($root, CoreGrowthGate::BASELINE_PATH, "{\n");
            try {
                (new CoreGrowthGate($root))->check();
                self::fail('A baseline that is not JSON must be refused.');
            } catch (GovernanceViolation $violation) {
                self::assertStringContainsString('not well-formed JSON', $violation->getMessage());
            }
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * The tool refuses unknown and repeated arguments.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheToolRefusesUnknownArguments(): void
    {
        $unknown = self::runGate(['--verbose']);
        self::assertSame(1, $unknown['status']);
        self::assertStringContainsString('Core growth: Unknown argument --verbose', $unknown['output']);
        self::assertStringContainsString(
            'Usage: php tools/verify-core-growth.php [--record] [--root=PATH]',
            $unknown['output'],
        );

        $twice = self::runGate(['--record', '--record', '--root=' . GovernanceFixture::cleanRoot()]);
        self::assertSame(1, $twice['status']);
        self::assertStringContainsString('--record is given twice', $twice['output']);
    }

    /**
     * Assert that a root passes the check with the given counts.
     *
     * @param   string  $root      Root to check.
     * @param   int     $symbols   Expected production symbol count.
     * @param   int     $recorded  Expected number of entries carrying growth evidence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertVerified(string $root, int $symbols, int $recorded): void
    {
        $check = (new CoreGrowthGate($root))->check();

        self::assertSame([], $check['failures']);
        self::assertSame($symbols, $check['symbols']);
        self::assertSame($recorded, $check['recorded']);
    }

    /**
     * Assert that a root is refused with a message carrying the fragment and a fix, and return the findings.
     *
     * @param   string  $root      Root to check.
     * @param   string  $fragment  Text one finding must contain.
     *
     * @return  list<string>  Every finding, or the single violation message when the check could not run.
     *
     * @since   2.0.0
     */
    private static function assertRefused(string $root, string $fragment): array
    {
        try {
            $failures = (new CoreGrowthGate($root))->check()['failures'];
        } catch (GovernanceViolation $violation) {
            $failures = [$violation->getMessage()];
        }
        self::assertNotSame([], $failures, 'The check must refuse: ' . $fragment);
        $matching = array_filter($failures, static fn (string $failure): bool => str_contains($failure, $fragment));
        self::assertNotSame([], $matching, implode("\n", $failures));
        foreach ($failures as $failure) {
            self::assertStringContainsString('Fix:', $failure);
        }

        return $failures;
    }

    /**
     * Make the baseline of a scratch root cite a Core Growth Record for one symbol.
     *
     * @param   string  $root    Scratch root.
     * @param   string  $fqcn    Symbol whose entry cites the record.
     * @param   string  $record  Record id.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function cite(string $root, string $fqcn, string $record): void
    {
        $baseline = (new CoreGrowthGate($root))->readBaseline();
        self::assertNotNull($baseline);
        $baseline['symbols'][$fqcn]['growth'] = ['record' => $record];
        GovernanceFixture::write($root, CoreGrowthGate::BASELINE_PATH, CoreGrowthGate::json($baseline));
    }

    /**
     * Rewrite the surface digest one baseline entry records, leaving its growth evidence untouched.
     *
     * @param   string  $root     Fixture root.
     * @param   string  $fqcn     Baseline entry to rewrite.
     * @param   string  $surface  Digest to record.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function recordSurface(string $root, string $fqcn, string $surface): void
    {
        $baseline = (new CoreGrowthGate($root))->readBaseline();
        self::assertNotNull($baseline);
        $baseline['symbols'][$fqcn]['surface'] = $surface;
        GovernanceFixture::write($root, CoreGrowthGate::BASELINE_PATH, CoreGrowthGate::json($baseline));
    }

    /**
     * Run `tools/verify-core-growth.php` with the given arguments.
     *
     * @param   list<string>  $arguments  Arguments such as `--record` and `--root=PATH`.
     *
     * @return  array{status: int, output: string}  Exit status and combined output.
     *
     * @since   2.0.0
     */
    private static function runGate(array $arguments): array
    {
        $command = sprintf(
            '%s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(GovernanceFixture::repositoryRoot() . '/tools/verify-core-growth.php'),
        );
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }
        $lines = [];
        $status = 0;
        exec($command . ' 2>&1', $lines, $status);

        return ['status' => $status, 'output' => implode("\n", $lines)];
    }

    /**
     * The source of one class-like declaration.
     *
     * @param   string  $namespace  Namespace.
     * @param   string  $name       Short name, optionally followed by an `implements` clause.
     * @param   string  $body       Member declarations, indented.
     * @param   string  $prologue   Imports placed after the namespace.
     *
     * @return  string  Complete PHP source.
     *
     * @since   2.0.0
     */
    private static function classSource(
        string $namespace,
        string $name,
        string $body = '',
        string $prologue = '',
    ): string {
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\n"
            . ($prologue === '' ? '' : $prologue . "\n\n")
            . "final class {$name}\n{\n{$body}}\n";
    }

    /**
     * A Core Growth Record derived from the committed example.
     *
     * @param   string        $fqcn             The one symbol the record names.
     * @param   string        $layer            Its layer.
     * @param   string        $decision         The decision; the reviewer is kept only when it is approved.
     * @param   list<string>  $overlapReviewed  Package symbols listed under `overlap_reviewed`.
     * @param   string        $id               Record identifier.
     *
     * @return  string  Complete markdown with front matter.
     *
     * @since   2.0.0
     */
    private static function growthRecord(
        string $fqcn,
        string $layer,
        string $decision = 'approved',
        array $overlapReviewed = [],
        string $id = 'KUMWE-CGR-2026-001',
    ): string {
        $example = GovernanceFixture::read(GovernanceFixture::repositoryRoot(), self::EXAMPLE_RECORD);
        $overlap = $overlapReviewed === []
            ? 'overlap_reviewed: []'
            : "overlap_reviewed:\n" . implode(
                "\n",
                array_map(static fn (string $symbol): string => '  - ' . $symbol, $overlapReviewed),
            );
        $record = str_replace(
            [
                'id: KUMWE-CGR-2026-001',
                'Kumwe\\App\\Example\\Application\\DescribeSubject',
                'layer: application',
                "overlap_reviewed:\n  - Kumwe\\Example\\ExampleService",
                'decision: approved',
            ],
            ['id: ' . $id, $fqcn, 'layer: ' . $layer, $overlap, 'decision: ' . $decision],
            $example,
        );
        self::assertStringContainsString($fqcn, $record);
        if ($decision !== 'approved') {
            $record = str_replace('reviewer: "Platform architecture"', 'reviewer: null', $record);
        }

        return $record;
    }
}
