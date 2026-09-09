<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Holds first-party manifests and lockfiles to exact, official, immutable coordinates.
 *
 * The extracted PHP libraries and Studio packages cross a trust boundary before any of their classes run.
 * These tests exercise the dependency-free gate in both directions: the committed records pass, including
 * the one admitted exact Producer alias that the lock must record verbatim, while ranges, branches, mutable
 * Composer aliases, unrecorded or divergent exact aliases, npm aliases, mutable lock references, foreign URLs,
 * a runtime library declared only under require-dev, and a lock whose dist reference diverges from its source
 * all fail.
 * Synthetic passing evidence supplies an aligned Producer release so a pin failure cannot be confused with
 * the separate three-way Studio alignment decision.
 *
 * @since   2.0.0
 */
#[CoversNothing]
final class StudioDependencyPinGateTest extends TestCase
{
    /**
     * Repository root, resolved once per test.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $root;

    /**
     * Synthetic JSON records written by a test.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $temporary = [];

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
     * Remove every synthetic record a test wrote.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }
        $this->temporary = [];
    }

    /**
     * The committed manifests, locks, and installed Producer form one reproducible dependency chain.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheCommittedDependencySetSatisfiesTheGate(): void
    {
        $result = $this->execute([]);

        self::assertSame(0, $result['status'], $result['output']);
        self::assertStringContainsString('Kumwe first-party dependencies verified', $result['output']);
        self::assertStringContainsString('Producer/Studio alignment verified', $result['output']);
    }

    /**
     * Exact manifests backed by official commit locks and an aligned Producer pass together.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExactOfficialSyntheticRecordsPass(): void
    {
        $result = $this->executeFixture($this->fixture());

        self::assertSame(0, $result['status'], $result['output']);
        self::assertStringContainsString('3 Composer pin(s), 8 Studio pin(s)', $result['output']);
    }

    /**
     * A Composer range for Conversion fails before it can move the extracted value contract silently.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAComposerRangeForConversionFails(): void
    {
        $fixture = $this->fixture();
        $fixture['composer']['require']['kumwe/conversion'] = '^0.1';
        $result = $this->executeFixture($fixture);

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('kumwe/conversion as "^0.1"', $result['output']);
    }

    /**
     * A Composer development branch for Extension SDK fails the exact-release rule.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAComposerBranchForExtensionSdkFails(): void
    {
        $fixture = $this->fixture();
        $fixture['composer']['require']['kumwe/extension-sdk'] = 'dev-main';
        $result = $this->executeFixture($fixture);

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('kumwe/extension-sdk as "dev-main"', $result['output']);
    }

    /**
     * A Composer branch alias for Producer remains mutable and therefore fails.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAComposerAliasForProducerFails(): void
    {
        $fixture = $this->fixture();
        $fixture['composer']['require']['kumwe/producer'] = 'dev-main as 0.1.0';
        $result = $this->executeFixture($fixture);

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('kumwe/producer as "dev-main as 0.1.0"', $result['output']);
    }

    /**
     * An exact Producer alias the manifest declares must be recorded by the regenerated lock.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnExactComposerAliasWithoutItsLockRecordFails(): void
    {
        $fixture = $this->fixture();
        $fixture['composer']['require']['kumwe/producer'] = '0.3.0 as 0.2.99';
        $fixture['composer_lock']['aliases'] = [];
        $result = $this->executeFixture($fixture);

        self::assertSame(1, $result['status']);
        self::assertStringContainsString(
            'composer.json declares kumwe/producer as 0.2.99 but composer.lock records no such alias',
            $result['output'],
        );
    }

    /**
     * A lock alias the manifest does not declare, or one that disagrees with the pin, fails.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testALockAliasTheManifestDoesNotDeclareOrMatchFails(): void
    {
        $undeclared = $this->fixture();
        $undeclared['composer']['require']['kumwe/producer'] = '0.3.0';
        $undeclared['composer_lock']['aliases'] = [[
            'package' => 'kumwe/producer',
            'version' => '0.3.0.0',
            'alias' => '0.2.99',
            'alias_normalized' => '0.2.99.0',
        ]];
        $result = $this->executeFixture($undeclared);

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('composer.json declares no exact alias', $result['output']);

        $divergent = $this->fixture();
        $divergent['composer']['require']['kumwe/producer'] = '0.3.0 as 0.2.99';
        $divergent['composer_lock']['aliases'] = [[
            'package' => 'kumwe/producer',
            'version' => '0.2.2.0',
            'alias' => '0.2.99',
            'alias_normalized' => '0.2.99.0',
        ]];
        $result = $this->executeFixture($divergent);

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('but composer.json declares 0.3.0 as 0.2.99', $result['output']);
    }

    /**
     * A foreign Composer URL cannot replace a first-party package coordinate.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAForeignComposerSpecifierFails(): void
    {
        $fixture = $this->fixture();
        $fixture['composer']['require']['kumwe/producer'] = 'https://packages.invalid/producer.zip';
        $result = $this->executeFixture($fixture);

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('packages.invalid/producer.zip', $result['output']);
    }

    /**
     * An exact manifest cannot hide a branch-shaped reference in composer.lock.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMutableComposerLockReferenceFails(): void
    {
        $fixture = $this->fixture();
        $this->setComposerLockValue($fixture, 'kumwe/conversion', ['source', 'reference'], 'main');
        $result = $this->executeFixture($fixture);

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('immutable 40-character commit', $result['output']);
        self::assertStringContainsString('"main"', $result['output']);
    }

    /**
     * An exact manifest cannot redirect Extension SDK's lock to a foreign source repository.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAForeignComposerLockSourceFails(): void
    {
        $fixture = $this->fixture();
        $this->setComposerLockValue(
            $fixture,
            'kumwe/extension-sdk',
            ['source', 'url'],
            'https://github.com/foreign/extension-sdk.git',
        );
        $result = $this->executeFixture($fixture);

        self::assertSame(1, $result['status']);
        self::assertStringContainsString(
            'source URL must be https://github.com/kumwe/extension-sdk.git',
            $result['output'],
        );
    }

    /**
     * An extracted runtime library declared only under require-dev is not a runtime pin and fails.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnExtractedPackageOnlyInRequireDevFails(): void
    {
        $fixture = $this->fixture();
        $fixture['composer']['require-dev']['kumwe/producer'] = $fixture['composer']['require']['kumwe/producer'];
        unset($fixture['composer']['require']['kumwe/producer']);
        $result = $this->executeFixture($fixture);

        self::assertSame(1, $result['status']);
        self::assertStringContainsString(
            'composer.json require must directly pin extracted runtime library kumwe/producer.',
            $result['output'],
        );
    }

    /**
     * composer.lock cannot download one commit while naming another as its source.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testADivergentComposerLockDistReferenceFails(): void
    {
        $fixture = $this->fixture();
        $this->setComposerLockValue($fixture, 'kumwe/producer', ['dist', 'reference'], str_repeat('0', 40));
        $result = $this->executeFixture($fixture);

        self::assertSame(1, $result['status']);
        self::assertStringContainsString(
            'composer.lock kumwe/producer source and dist references differ.',
            $result['output'],
        );
    }

    /**
     * An npm range for a Studio package fails in any dependency section.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnNpmRangeForStudioFails(): void
    {
        $fixture = $this->fixture();
        $fixture['package']['dependencies']['@kumwe/studio-core'] = '~0.1.0';
        $result = $this->executeFixture($fixture);

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('@kumwe/studio-core as "~0.1.0"', $result['output']);
    }

    /**
     * An npm alias can redirect a trusted package name and therefore fails.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnNpmAliasForStudioFails(): void
    {
        $fixture = $this->fixture();
        $fixture['package']['dependencies']['@kumwe/studio-core'] = 'npm:@foreign/studio-core@0.1.0';
        $result = $this->executeFixture($fixture);

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('npm:@foreign/studio-core@0.1.0', $result['output']);
    }

    /**
     * A file reference outside the digest-verified Studio package directory fails.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAForeignStudioFileReferenceFails(): void
    {
        $fixture = $this->fixture();
        $fixture['package']['dependencies']['@kumwe/studio'] = 'file:../studio/kumwe-studio-0.1.0.tgz';
        $result = $this->executeFixture($fixture);

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('file:../studio/kumwe-studio-0.1.0.tgz', $result['output']);
    }

    /**
     * package-lock cannot resolve an exact Studio declaration from a foreign URL.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAForeignStudioLockTargetFails(): void
    {
        $fixture = $this->fixture();
        $fixture['package_lock']['packages']['node_modules/@kumwe/studio']['resolved']
            = 'https://packages.invalid/kumwe-studio.tgz';
        $result = $this->executeFixture($fixture);

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('@kumwe/studio resolved target must be', $result['output']);
        self::assertStringContainsString('packages.invalid/kumwe-studio.tgz', $result['output']);
    }

    /**
     * composer qa reaches both coordinate verification and Producer alignment through the existing gate.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheGateRemainsWiredIntoTheLocalLane(): void
    {
        $composer = $this->decode($this->root . '/composer.json');
        $scripts = $composer['scripts'] ?? null;
        self::assertIsArray($scripts);
        self::assertSame('php tools/verify-studio-dependencies.php', $scripts['studio:dependencies'] ?? null);
        self::assertContains('@studio:dependencies', $scripts['qa'] ?? []);

        $tool = (string) file_get_contents($this->root . '/tools/verify-studio-dependencies.php');
        self::assertStringContainsString("require_once __DIR__ . '/verify-producer-studio-alignment.php'", $tool);
    }

    /**
     * Decode the committed manifests and locks into one independently mutable fixture.
     *
     * @return  array{composer: array<string, mixed>, composer_lock: array<string, mixed>,
     *          package: array<string, mixed>, package_lock: array<string, mixed>}
     *
     * @since   2.0.0
     */
    private function fixture(): array
    {
        return [
            'composer' => $this->decode($this->root . '/composer.json'),
            'composer_lock' => $this->decode($this->root . '/composer.lock'),
            'package' => $this->decode($this->root . '/package.json'),
            'package_lock' => $this->decode($this->root . '/package-lock.json'),
        ];
    }

    /**
     * Replace one nested field in a named first-party composer.lock package.
     *
     * @param   array<string, mixed>  $fixture  Mutable fixture.
     * @param   string                $name     Composer package name.
     * @param   array{0: string, 1: string}  $path  Two-level field path.
     * @param   string                $value    Replacement value.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function setComposerLockValue(array &$fixture, string $name, array $path, string $value): void
    {
        $packages = &$fixture['composer_lock']['packages'];
        self::assertIsArray($packages);
        foreach ($packages as &$package) {
            if (is_array($package) && ($package['name'] ?? null) === $name) {
                self::assertIsArray($package[$path[0]] ?? null);
                $package[$path[0]][$path[1]] = $value;

                return;
            }
        }
        self::fail(sprintf('Fixture does not contain %s.', $name));
    }

    /**
     * Write all synthetic records, supply a valid Producer alignment record, and execute the gate.
     *
     * @param   array{composer: array<string, mixed>, composer_lock: array<string, mixed>,
     *          package: array<string, mixed>, package_lock: array<string, mixed>}  $fixture  Records.
     *
     * @return  array{status: int, output: string}  Exit status and combined output.
     *
     * @since   2.0.0
     */
    private function executeFixture(array $fixture): array
    {
        $releasePath = $this->root . '/resources/studio-contract/studio-release.json';

        return $this->execute([
            '--composer=' . $this->write($fixture['composer']),
            '--composer-lock=' . $this->write($fixture['composer_lock']),
            '--package=' . $this->write($fixture['package']),
            '--package-lock=' . $this->write($fixture['package_lock']),
            '--app-studio-pin=' . $this->root . '/resources/studio-contract/PIN.json',
            '--app-studio-release=' . $releasePath,
            '--producer-studio-pin=' . $this->write($this->alignedProducerPin()),
            '--producer-studio-release=' . $releasePath,
        ]);
    }

    /**
     * Build Producer's provenance-backed release PIN from App's independently verified release evidence.
     *
     * @return  array<string, mixed>  Aligned Producer PIN.
     *
     * @since   2.0.0
     */
    private function alignedProducerPin(): array
    {
        $appPin = $this->decode($this->root . '/resources/studio-contract/PIN.json');
        $releasePath = $this->root . '/resources/studio-contract/studio-release.json';
        $release = $this->decode($releasePath);
        $bytes = file_get_contents($releasePath);
        self::assertIsString($bytes);
        $pinned = $appPin['pinned'] ?? null;
        self::assertIsArray($pinned);
        $provenance = [];
        foreach ($pinned as $name => $record) {
            self::assertIsArray($record);
            $provenance[] = [
                'name' => $name,
                'version' => $record['version'] ?? null,
                'sha256' => $record['npm_tarball_sha256'] ?? null,
            ];
        }

        return [
            'pin' => 'kumwe-producer-studio-contract',
            'source' => [
                'repository' => 'https://github.com/kumwe/studio',
                'kind' => 'provenance-backed-npm-release',
                'release' => $release['release'],
                'commit' => str_repeat('ab', 20),
            ],
            'release_record' => $appPin['release_record'],
            'protocol_version' => $release['protocolVersion'],
            'corpus_manifest_digest' => $release['corpusManifestDigest'],
            'claimed_profiles' => $release['claimedProfiles'],
            'packages' => $release['packages'],
            'package_provenance' => $provenance,
            'files' => [
                ['file' => 'studio-release.json', 'sha256' => hash('sha256', $bytes)],
            ],
        ];
    }

    /**
     * Run the dependency gate and capture its verdict.
     *
     * @param   list<string>  $arguments  Tool arguments.
     *
     * @return  array{status: int, output: string}  Exit status and combined output.
     *
     * @since   2.0.0
     */
    private function execute(array $arguments): array
    {
        $command = sprintf(
            '%s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->root . '/tools/verify-studio-dependencies.php'),
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
     * Write one synthetic JSON record for the gate to scan.
     *
     * @param   array<string, mixed>  $document  JSON document.
     *
     * @return  string  Absolute path of the written record.
     *
     * @since   2.0.0
     */
    private function write(array $document): string
    {
        $path = sys_get_temp_dir() . '/kumwe-dependency-gate-' . bin2hex(random_bytes(8)) . '.json';
        $encoded = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertIsString($encoded);
        self::assertNotFalse(file_put_contents($path, $encoded . "\n"));
        $this->temporary[] = $path;

        return $path;
    }

    /**
     * Decode one JSON record.
     *
     * @param   string  $path  Absolute path.
     *
     * @return  array<string, mixed>  Decoded object.
     *
     * @since   2.0.0
     */
    private function decode(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($decoded, sprintf('%s is not well-formed JSON.', $path));

        return $decoded;
    }
}
