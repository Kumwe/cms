<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Governance;

use Kumwe\App\Tools\Governance\GovernanceViolation;
use Kumwe\App\Tools\Governance\PackageManifests;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Holds `tools/Governance/PackageManifests.php` to the Version 2 manifest rules and the legacy fallbacks.
 *
 * A package that claims Version 2 must ship every manifest and the handoff, all valid and consistent; a package
 * that ships none of them is legacy and its symbols come from the pre-Version-2 manifest it does ship or from a
 * source scan that excludes `@internal` declarations.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class PackageManifestsTest extends TestCase
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
     * The fixture's Version 2 package reads as manifested with its four exported symbols and validated handoff.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAVersion2PackageReadsAsManifested(): void
    {
        $manifests = self::read(GovernanceFixture::cleanRoot(), 'example-v2');

        self::assertSame('v2-manifested', $manifests->manifestStatus());
        self::assertTrue($manifests->isVersion2());
        self::assertSame('kumwe/example-v2', $manifests->name());
        self::assertSame(
            [
                'Kumwe\\Example\\ConfigProvider',
                'Kumwe\\Example\\Container\\ExampleServiceFactory',
                'Kumwe\\Example\\Contract\\ExampleServiceInterface',
                'Kumwe\\Example\\ExampleService',
            ],
            $manifests->publicSymbols(),
        );
        self::assertSame('manifest:resources/public-api/v1.json', $manifests->publicSymbolsSource());
        self::assertSame(
            'sha256:' . GovernanceFixture::digest(
                GovernanceFixture::cleanRoot(),
                'vendor/kumwe/example-v2/resources/public-api/v1.json',
            ),
            $manifests->publicApiDigest(),
        );
        $handoff = $manifests->handoff();
        self::assertNotNull($handoff);
        self::assertSame('vendor/kumwe/example-v2/MIGRATION-HANDOFF.md', $handoff['path']);
        self::assertSame('KUMWE-MIG-2026-001', $handoff['front_matter']['migration_id']);
        self::assertSame('vendor/kumwe/example-v2/CHARTER.md', $manifests->charterPath());
        self::assertStringStartsWith('**Kumwe Example** describes a subject', (string) $manifests->charterSummary());
        self::assertSame('Kumwe\\Example\\ConfigProvider', $manifests->serviceMap()['config_provider'] ?? null);
    }

    /**
     * Current YAML and JSON records preserve package identity, strict schema and manifest digest checks.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProductionReleaseRecordsPreserveAllPackageChecks(): void
    {
        foreach ([false, true] as $json) {
            $root = GovernanceFixture::copy();
            try {
                GovernanceFixture::useProductionRecord($root, $json);
                $manifests = self::read($root, 'example-v2');
                self::assertSame('v2-manifested', $manifests->manifestStatus());
                $record = $manifests->handoff();
                self::assertNotNull($record);
                self::assertSame('vendor/kumwe/example-v2/docs/release-record.md', $record['path']);
                self::assertSame('kumwe-package-release-record/v1', $record['front_matter']['schema']);
                self::assertArrayHasKey('consumer_contract', $record['front_matter']);
                self::assertArrayNotHasKey('next_task', $record['front_matter']);
                GovernanceFixture::replace(
                    $root,
                    'vendor/kumwe/example-v2/resources/service-map/v1.json',
                    '"description": "Marker prepended to every description."',
                    '"description": "Marker prepended to each description."',
                );
                try {
                    self::read($root, 'example-v2');
                    self::fail('A new record must still bind the complete manifest bytes.');
                } catch (GovernanceViolation $violation) {
                    self::assertStringContainsString('has digest', $violation->getMessage());
                }
            } finally {
                GovernanceFixture::remove($root);
            }
        }
    }

    /**
     * Production records cannot use a legacy schema, omit required sections or shadow another record.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProductionRecordAmbiguityAndSchemaDriftAreRefused(): void
    {
        $cases = [
            ['kumwe-package-release-record/v1', 'kumwe-migration-handoff/v2', 'fails package-release-record'],
            ['## Consumer contract', '## Missing section', 'narrative section'],
            ['consumer_contract:', 'next_task:', 'fails package-release-record'],
        ];
        foreach ($cases as [$search, $replace, $rule]) {
            $root = GovernanceFixture::copy();
            try {
                GovernanceFixture::useProductionRecord($root);
                GovernanceFixture::replace($root, 'vendor/kumwe/example-v2/docs/release-record.md', $search, $replace);
                try {
                    self::read($root, 'example-v2');
                    self::fail('A current record must be complete and use its assigned schema.');
                } catch (GovernanceViolation $violation) {
                    self::assertStringContainsString($rule, $violation->getMessage());
                }
            } finally {
                GovernanceFixture::remove($root);
            }
        }
        $root = GovernanceFixture::copy();
        try {
            GovernanceFixture::useProductionRecord($root);
            GovernanceFixture::write($root, 'vendor/kumwe/example-v2/MIGRATION-HANDOFF.md', 'second record');
            $this->expectException(GovernanceViolation::class);
            $this->expectExceptionMessage('ships both');
            self::read($root, 'example-v2');
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * JSON front matter refuses duplicate keys, including escaped names and objects nested inside arrays.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProductionJsonCannotOverwriteObjectMembers(): void
    {
        $path = 'vendor/kumwe/example-v2/docs/release-record.md';
        foreach ([
            '{"key":1,"key":2}',
            '{"key":1,"\u006bey":2}',
            '{"items":[{"key":1,"key":2}]}',
            '{"key":{"nested":1},"key":null}',
            '{"key": invalid}',
        ] as $json) {
            try {
                PackageManifests::parseReleaseRecord("---\n" . $json . "\n---\n", $path);
                self::fail('Malformed or duplicated JSON must be refused.');
            } catch (GovernanceViolation $violation) {
                self::assertStringContainsString('Fix:', $violation->getMessage());
            }
        }
        $accepted = json_encode(
            ['key' => 'quoted "key": remains value text', 'items' => [['key' => 1], ['key' => 2]]],
            JSON_THROW_ON_ERROR,
        );
        $parsed = PackageManifests::parseReleaseRecord("---\n" . $accepted . "\n---\nBody", $path);
        self::assertSame(json_decode($accepted, true, flags: JSON_THROW_ON_ERROR), $parsed['front_matter']);
        self::assertSame('Body', $parsed['body']);
    }

    /**
     * A legacy package without any manifest is scanned, and `@internal` declarations are not exported.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testALegacyPackageWithoutManifestsIsSourceScanned(): void
    {
        $manifests = self::read(GovernanceFixture::cleanRoot(), 'example-legacy');

        self::assertSame('legacy-unmanifested', $manifests->manifestStatus());
        self::assertSame(
            ['Kumwe\\ExampleLegacy\\LegacyFormat', 'Kumwe\\ExampleLegacy\\LegacyFormatter'],
            $manifests->publicSymbols(),
        );
        self::assertSame('source-scan', $manifests->publicSymbolsSource());
        self::assertSame(
            'sha256:' . hash('sha256', "Kumwe\\ExampleLegacy\\LegacyFormat\nKumwe\\ExampleLegacy\\LegacyFormatter\n"),
            $manifests->publicApiDigest(),
        );
        self::assertNull($manifests->publicApiPath());
        self::assertNull($manifests->charterPath());
        self::assertCount(3, $manifests->declarations());
        self::assertNull($manifests->handoff());
    }

    /**
     * The installed Version 2 manifests of Producer and the pre-Version-2 manifests of Conversion supply symbols.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInstalledManifestsSupplyPublicSymbolsAcrossSchemas(): void
    {
        $producer = self::read(GovernanceFixture::repositoryRoot(), 'producer');
        self::assertSame('v2-manifested', $producer->manifestStatus());
        self::assertSame('manifest:resources/public-api/v1.json', $producer->publicSymbolsSource());
        self::assertContains('Kumwe\\Producer\\Canonical\\CanonicalJson', $producer->publicSymbols());
        self::assertContains('Kumwe\\Producer\\Deployment\\StudioDeploymentEmitter', $producer->publicSymbols());
        self::assertNotNull($producer->handoff());

        $conversion = self::read(GovernanceFixture::repositoryRoot(), 'conversion');
        self::assertSame('manifest:resources/public-api/v1.json', $conversion->publicSymbolsSource());
        self::assertCount(23, $conversion->publicSymbols());

        $sdk = self::read(GovernanceFixture::repositoryRoot(), 'extension-sdk');
        self::assertSame('source-scan', $sdk->publicSymbolsSource());
        self::assertNotContains('Kumwe\\Extension\\Manifest\\ExtensionManifestGrammar', $sdk->publicSymbols());
        self::assertContains('Kumwe\\Extension\\Manifest\\ExtensionManifest', $sdk->publicSymbols());
    }

    /**
     * Each way a Version 2 claim can be incomplete or self-contradictory is refused with the file and the rule.
     *
     * @param   string  $relative  Package-relative file to mutate.
     * @param   string  $search    Text to replace, or empty to delete the file.
     * @param   string  $replace   Replacement.
     * @param   string  $rule      Fragment of the expected message.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('brokenVersion2Packages')]
    public function testIncompleteOrContradictoryVersion2ClaimsAreRefused(
        string $relative,
        string $search,
        string $replace,
        string $rule,
    ): void {
        $root = GovernanceFixture::copy();
        $package = 'vendor/kumwe/example-v2/';
        try {
            if ($search === '') {
                GovernanceFixture::delete($root, $package . $relative);
            } else {
                GovernanceFixture::replace($root, $package . $relative, $search, $replace);
            }
            try {
                self::read($root, 'example-v2');
                self::fail('The package must be refused: ' . $rule);
            } catch (GovernanceViolation $violation) {
                self::assertStringContainsString($rule, $violation->getMessage());
                self::assertStringContainsString('Fix:', $violation->getMessage());
            }
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * Mutations that break a Version 2 package.
     *
     * @return  iterable<string, array{string, string, string, string}>  File, search, replacement, rule fragment.
     *
     * @since   2.0.0
     */
    public static function brokenVersion2Packages(): iterable
    {
        yield 'missing capabilities manifest' => [
            'resources/capabilities/v1.json',
            '',
            '',
            'ships no resources/capabilities/v1.json',
        ];
        yield 'missing service map' => [
            'resources/service-map/v1.json',
            '',
            '',
            'ships no resources/service-map/v1.json',
        ];
        yield 'missing handoff' => ['MIGRATION-HANDOFF.md', '', '', 'ships no MIGRATION-HANDOFF.md'];
        yield 'manifest fails its schema' => [
            'resources/capabilities/v1.json',
            '"native_requirements": null',
            '"native_requirements": "none"',
            'fails package-capabilities.v1.schema.json',
        ];
        yield 'manifest names another package' => [
            'resources/service-map/v1.json',
            '"package": "kumwe/example-v2"',
            '"package": "kumwe/other"',
            'names package "kumwe/other"',
        ];
        yield 'manifests disagree on the release' => [
            'resources/service-map/v1.json',
            '"release": "0.1.0"',
            '"release": "0.2.0"',
            'disagree on the release',
        ];
        yield 'capability names an unexported symbol' => [
            'resources/capabilities/v1.json',
            '"Kumwe\\\\Example\\\\ExampleService"',
            '"Kumwe\\\\Example\\\\Internal\\\\Helper"',
            'does not export',
        ];
        yield 'capability links a missing document' => [
            'resources/capabilities/v1.json',
            '"docs/public-api.md"',
            '"docs/absent.md"',
            'does not ship',
        ];
        yield 'provider without factories' => [
            'resources/service-map/v1.json',
            GovernanceFixture::FACTORY_BLOCK,
            '"factories": []',
            'without any factory',
        ];
        yield 'null provider without a reason' => [
            'resources/service-map/v1.json',
            '"config_provider": "Kumwe\\\\Example\\\\ConfigProvider"',
            '"config_provider": null',
            'without a provider_absence_reason',
        ];
        yield 'alias to an unexported symbol' => [
            'resources/service-map/v1.json',
            '"Kumwe\\\\Example\\\\Contract\\\\ExampleServiceInterface": "Kumwe\\\\Example\\\\ExampleService"',
            '"Kumwe\\\\Example\\\\Contract\\\\ExampleServiceInterface": "Kumwe\\\\Example\\\\Internal\\\\Helper"',
            'is not exported',
        ];
        yield 'handoff manifest digest mismatch' => [
            'resources/service-map/v1.json',
            '"description": "Marker prepended to every description."',
            '"description": "Marker prepended to each description."',
            'has digest',
        ];
        yield 'handoff names another package' => [
            'MIGRATION-HANDOFF.md',
            'composer_package: kumwe/example-v2',
            'composer_package: kumwe/example-v9',
            'is not the installed package',
        ];
        yield 'handoff canonical namespace mismatch' => [
            'MIGRATION-HANDOFF.md',
            'canonical_namespace: Kumwe\\Example',
            'canonical_namespace: Kumwe\\Elsewhere',
            'is not a PSR-4 root',
        ];
        yield 'handoff missing narrative section' => [
            'MIGRATION-HANDOFF.md',
            '## 7. Drift check',
            '## 7. Drift',
            'narrative section "## Drift check" is missing',
        ];
        yield 'handoff outside the YAML subset' => [
            'MIGRATION-HANDOFF.md',
            'decisions:',
            'decisions: &anchor',
            'anchors',
        ];
        yield 'handoff for a native artifact' => [
            'MIGRATION-HANDOFF.md',
            'artifact_kind: framework_php',
            'artifact_kind: native_cpp',
            'must match exactly one alternative',
        ];
        yield 'symbol outside the namespace' => [
            'resources/public-api/v1.json',
            '"Kumwe\\\\Example\\\\ConfigProvider": {',
            '"Kumwe\\\\Other\\\\ConfigProvider": {',
            'outside the package namespace',
        ];
    }

    /**
     * A pre-Version-2 manifest that names another package or lacks a types object is refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMalformedPreVersion2ManifestIsRefused(): void
    {
        $root = GovernanceFixture::copy();
        try {
            GovernanceFixture::write(
                $root,
                'vendor/kumwe/example-legacy/resources/public-api.json',
                '{"schema": 2, "package": "kumwe/example-legacy"}',
            );
            try {
                self::read($root, 'example-legacy');
                self::fail('A manifest without types must be refused.');
            } catch (GovernanceViolation $violation) {
                self::assertStringContainsString('no "types" object', $violation->getMessage());
            }
            GovernanceFixture::write(
                $root,
                'vendor/kumwe/example-legacy/resources/public-api.json',
                '{"schema": 2, "package": "kumwe/example-legacy", '
                . '"types": {"Kumwe\\\\ExampleLegacy\\\\LegacyFormatter": {}}}',
            );
            $manifests = self::read($root, 'example-legacy');
            self::assertSame(['Kumwe\\ExampleLegacy\\LegacyFormatter'], $manifests->publicSymbols());
            self::assertSame('manifest:resources/public-api.json', $manifests->publicSymbolsSource());
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * Read one installed package of a root.
     *
     * @param   string  $root   Repository or fixture root.
     * @param   string  $short  Package short name.
     *
     * @return  PackageManifests  The package.
     *
     * @since   2.0.0
     */
    private static function read(string $root, string $short): PackageManifests
    {
        return PackageManifests::read(
            $root . '/vendor/kumwe/' . $short,
            'vendor/kumwe/' . $short,
            GovernanceFixture::schemaDirectory(),
        );
    }
}
