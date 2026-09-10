<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use Kumwe\App\Tests\Unit\Governance\GovernanceFixture;
use Kumwe\App\Tools\Governance\CapabilityIndexBuilder;
use Kumwe\App\Tools\Governance\CapabilityIndexWriter;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Proves the capability index gate holds for this repository and is registered in every lane that must run it.
 *
 * The committed `docs/architecture/capability-index.md` matches what the installed Kumwe packages generate, the
 * generator is deterministic, a stale digest is refused, the two remaining pre-Version-2 packages appear only as
 * approved legacy-unmanifested entries that cannot satisfy a release gate while `kumwe/canonical-json` and
 * `kumwe/producer` are indexed from their Version 2 manifests and ledger records, and the check is wired into
 * `composer qa`, the quality contract, both CI steps and the coverage contract.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class CapabilityIndexGateTest extends TestCase
{
    /**
     * Repository root.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $root;

    /**
     * Load the governance classes once.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/tools/Governance/bootstrap.php';
        require_once dirname(__DIR__) . '/Unit/Governance/GovernanceFixture.php';
    }

    /**
     * Resolve the repository root.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    /**
     * The committed index is current for the installed packages and its digest is the one the tool computes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheCommittedIndexIsCurrent(): void
    {
        $check = GovernanceFixture::run(['--check']);

        self::assertSame(0, $check['status'], $check['output']);
        self::assertStringContainsString('Capability index verified (4 packages; digest sha256:', $check['output']);

        $digest = GovernanceFixture::run(['--digest']);
        self::assertSame(0, $digest['status'], $digest['output']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $digest['output']);
        self::assertStringContainsString('digest sha256:' . $digest['output'], $check['output']);
        $markdown = file_get_contents($this->root . '/docs/architecture/capability-index.md');
        self::assertIsString($markdown);
        self::assertSame($digest['output'], CapabilityIndexWriter::embeddedDigest($markdown));
        self::assertSame($digest['output'], GovernanceFixture::run(['--digest'])['output'], 'The digest is stable.');
    }

    /**
     * Writing twice produces identical bytes, and the fixture root passes its own check afterwards.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testWritingTwiceProducesIdenticalBytes(): void
    {
        $root = GovernanceFixture::copy();
        try {
            $first = GovernanceFixture::run(['--write', '--root=' . $root]);
            self::assertSame(0, $first['status'], $first['output']);
            self::assertStringContainsString('Capability index written (2 packages; digest sha256:', $first['output']);
            $json = GovernanceFixture::read($root, CapabilityIndexWriter::JSON_PATH);
            $digest = GovernanceFixture::read($root, CapabilityIndexWriter::DIGEST_PATH);
            $markdown = GovernanceFixture::read($root, CapabilityIndexWriter::MARKDOWN_PATH);

            $second = GovernanceFixture::run(['--write', '--root=' . $root]);
            self::assertSame(0, $second['status'], $second['output']);
            self::assertSame($json, GovernanceFixture::read($root, CapabilityIndexWriter::JSON_PATH));
            self::assertSame($digest, GovernanceFixture::read($root, CapabilityIndexWriter::DIGEST_PATH));
            self::assertSame($markdown, GovernanceFixture::read($root, CapabilityIndexWriter::MARKDOWN_PATH));
            self::assertSame(hash('sha256', $json) . "  v1.json\n", $digest);
            self::assertSame(
                file_get_contents(GovernanceFixture::cleanRoot() . '/docs/architecture/capability-index.md'),
                $markdown,
                'The committed fixture markdown is what the tool generates.',
            );
            self::assertStringNotContainsString($root, $json, 'No absolute path leaks into the generated index.');

            $check = GovernanceFixture::run(['--check', '--root=' . $root]);
            self::assertSame(0, $check['status'], $check['output']);
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * A stale embedded digest and a hand-edited document are refused, naming the file and the regenerate command.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAStaleDigestIsRefused(): void
    {
        $root = GovernanceFixture::copy();
        try {
            $markdown = GovernanceFixture::read($root, CapabilityIndexWriter::MARKDOWN_PATH);
            $digest = CapabilityIndexWriter::embeddedDigest($markdown);
            self::assertNotNull($digest);
            GovernanceFixture::replace($root, CapabilityIndexWriter::MARKDOWN_PATH, $digest, strrev($digest));

            $stale = GovernanceFixture::run(['--check', '--root=' . $root]);
            self::assertSame(1, $stale['status'], $stale['output']);
            self::assertStringContainsString(
                'Capability index: docs/architecture/capability-index.md embeds the stale digest',
                $stale['output'],
            );
            self::assertStringContainsString('composer kumwe:capability-index', $stale['output']);

            GovernanceFixture::write($root, CapabilityIndexWriter::MARKDOWN_PATH, $markdown . "\nHand edit.\n");
            $edited = GovernanceFixture::run(['--check', '--root=' . $root]);
            self::assertSame(1, $edited['status'], $edited['output']);
            self::assertStringContainsString('differs from the regenerated index', $edited['output']);

            GovernanceFixture::replace($root, 'composer.lock', '"version": "v0.9.0"', '"version": "v0.9.1"');
            $unapproved = GovernanceFixture::run(['--check', '--root=' . $root]);
            self::assertSame(1, $unapproved['status'], $unapproved['output']);
            self::assertStringContainsString(
                'Capability index: docs/architecture/governance/legacy-packages.json',
                $unapproved['output'],
            );
            self::assertStringContainsString('approved at v0.9.0 but v0.9.1 is locked', $unapproved['output']);
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * The tool refuses unknown arguments and an absent mode.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheToolRefusesUnknownArguments(): void
    {
        $unknown = GovernanceFixture::run(['--check', '--verbose']);
        self::assertSame(1, $unknown['status']);
        self::assertStringContainsString('Capability index: Unknown argument --verbose', $unknown['output']);

        $none = GovernanceFixture::run([]);
        self::assertSame(1, $none['status']);
        self::assertStringContainsString('No mode given', $none['output']);

        $both = GovernanceFixture::run(['--check', '--write']);
        self::assertSame(1, $both['status']);
        self::assertStringContainsString('exactly one mode', $both['output']);
    }

    /**
     * Two pre-Version-2 packages remain legacy-unmanifested transitional entries that cannot satisfy a release
     * gate, while `kumwe/canonical-json` and `kumwe/producer` are indexed from their Version 2 manifests and the
     * ledger records adopting their handoffs.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheInstalledPackagesAreLegacyEntriesOrVersionTwoAdoptions(): void
    {
        $document = (new CapabilityIndexBuilder($this->root))->build();
        /** @var list<array<string, mixed>> $packages */
        $packages = $document['packages'];

        self::assertSame(
            ['kumwe/canonical-json', 'kumwe/conversion', 'kumwe/extension-sdk', 'kumwe/producer'],
            array_column($packages, 'package'),
        );
        foreach ([$packages[1], $packages[2]] as $package) {
            self::assertSame('legacy-unmanifested', $package['manifest_status'], (string) $package['package']);
            self::assertFalse($package['release_gate_eligible'], (string) $package['package']);
            self::assertIsArray($package['legacy']);
            self::assertSame('eWɘyn', $package['legacy']['approved_by']);
            self::assertSame('2026-09-02', $package['legacy']['approved_on']);
            self::assertNull($package['handoff']);
            self::assertNotEmpty($package['public_symbols']);
        }
        $canonical = $packages[0];
        self::assertSame('v2-manifested', $canonical['manifest_status']);
        self::assertTrue($canonical['release_gate_eligible']);
        self::assertNull($canonical['legacy']);
        self::assertIsArray($canonical['handoff']);
        self::assertSame('KUMWE-MIG-2026-007', $canonical['handoff']['migration_id']);
        self::assertSame('KUMWE-CS-2026-007', $canonical['handoff']['change_set']);
        self::assertSame('vendor/kumwe/canonical-json/MIGRATION-HANDOFF.md', $canonical['handoff']['path']);
        self::assertContains('Kumwe\\CanonicalJson\\Profile', $canonical['public_symbols']);
        $producer = $packages[3];
        self::assertSame('v2-manifested', $producer['manifest_status']);
        self::assertTrue($producer['release_gate_eligible']);
        self::assertNull($producer['legacy']);
        self::assertIsArray($producer['handoff']);
        self::assertSame('KUMWE-MIG-2026-032', $producer['handoff']['migration_id']);
        self::assertSame('KUMWE-CS-2026-032', $producer['handoff']['change_set']);
        self::assertSame('vendor/kumwe/producer/MIGRATION-HANDOFF.md', $producer['handoff']['path']);
        self::assertContains('Kumwe\\Producer\\Deployment\\StudioDeploymentEmitter', $producer['public_symbols']);
        $sources = array_column($packages, 'public_symbols_source', 'package');
        self::assertSame('manifest:resources/public-api/v1.json', $sources['kumwe/canonical-json']);
        self::assertSame('manifest:resources/public-api/v1.json', $sources['kumwe/conversion']);
        self::assertSame('source-scan', $sources['kumwe/extension-sdk']);
        self::assertSame('manifest:resources/public-api/v1.json', $sources['kumwe/producer']);
        self::assertSame(['v0.1.1', 'v0.1.2', 'v0.2.4', 'v0.3.0'], array_column($packages, 'installed_version'));
        self::assertSame([], $document['extracted_namespaces']);
        self::assertSame([], $document['removed_symbols']);
        self::assertContains('Kumwe\\App\\BusinessRecord\\Query\\', $packages[2]['legacy']['retired_app_namespaces']);
    }

    /**
     * The check is a `composer qa` member after the Studio pins, a quality-contract check, two CI steps and a
     * reasoned coverage path.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheGateIsRegisteredInEveryLane(): void
    {
        $composer = $this->document($this->root . '/composer.json');
        self::assertSame(
            'php tools/generate-capability-index.php --write',
            $composer['scripts']['kumwe:capability-index'] ?? null,
        );
        self::assertSame(
            'php tools/generate-capability-index.php --check',
            $composer['scripts']['kumwe:capability-index-check'] ?? null,
        );
        /** @var list<string> $qa */
        $qa = $composer['scripts']['qa'];
        $studio = array_search('@studio:dependencies', $qa, true);
        self::assertIsInt($studio);
        self::assertSame('@kumwe:capability-index-check', $qa[$studio + 1] ?? null);

        $contract = $this->document($this->root . '/docs/quality/contract.json');
        $checks = array_values(array_filter(
            $contract['checks'] ?? [],
            static fn (mixed $check): bool => is_array($check) && ($check['id'] ?? null) === 'capability-index',
        ));
        self::assertCount(1, $checks);
        self::assertSame('kumwe:capability-index-check', $checks[0]['composer_script'] ?? null);
        self::assertSame('platform-architecture', $checks[0]['owner'] ?? null);
        self::assertSame('docs/architecture/capability-index.md', $checks[0]['artifact'] ?? null);
        self::assertTrue($checks[0]['in_qa'] ?? false);
        self::assertSame(['local', 'ci', 'nightly', 'release'], $checks[0]['cadence'] ?? null);
        self::assertSame('quality', $checks[0]['workflows']['ci']['job'] ?? null);

        $workflow = file_get_contents($this->root . '/.github/workflows/ci.yml');
        self::assertIsString($workflow);
        self::assertSame(2, substr_count($workflow, "          composer kumwe:capability-index-check\n"));
        self::assertStringContainsString(
            'Verify the pinned Studio contract corpus and the capability index',
            $workflow,
        );
        self::assertStringContainsString(
            "          composer studio:dependencies\n          composer kumwe:capability-index-check\n",
            $workflow,
        );

        $coverage = $this->document($this->root . '/docs/quality/coverage-contract.json');
        $paths = array_column($coverage['attribution']['reasoned'] ?? [], 'path');
        self::assertContains('tests/Unit/Governance/', $paths);
    }

    /**
     * Decode one repository JSON object.
     *
     * @param   string  $path  Document path.
     *
     * @return  array<string, mixed>  Decoded object.
     *
     * @since   2.0.0
     */
    private function document(string $path): array
    {
        $bytes = file_get_contents($path);
        self::assertIsString($bytes, $path);
        $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, $path);

        return $decoded;
    }
}
