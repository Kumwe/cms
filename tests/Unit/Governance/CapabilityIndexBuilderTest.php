<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Governance;

use Kumwe\App\Tools\Governance\CapabilityIndexBuilder;
use Kumwe\App\Tools\Governance\CapabilityIndexWriter;
use Kumwe\App\Tools\Governance\GovernanceViolation;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Holds `tools/Governance/CapabilityIndexBuilder.php` and `CapabilityIndexWriter.php` to the index rules.
 *
 * The clean fixture builds deterministically and independently of lock order; every fail-closed rule of the
 * specification refuses a mutated copy of the fixture with a message naming the file, the rule and the fix.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class CapabilityIndexBuilderTest extends TestCase
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
     * The clean fixture builds the documented shape: one Version 2 entry, one legacy entry, ledger-derived lists.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheCleanFixtureBuildsTheDocumentedShape(): void
    {
        $document = (new CapabilityIndexBuilder(GovernanceFixture::cleanRoot()))->build();

        self::assertSame('kumwe-capability-index/v1', $document['schema']);
        self::assertSame('tools/generate-capability-index.php', $document['generator']);
        self::assertSame(
            GovernanceFixture::digest(GovernanceFixture::cleanRoot(), 'composer.lock'),
            $document['composer_lock_sha256'],
        );
        self::assertSame(['kumwe/example-legacy', 'kumwe/example-v2'], array_column($document['packages'], 'package'));

        [$legacy, $version2] = $document['packages'];
        self::assertSame('legacy-unmanifested', $legacy['manifest_status']);
        self::assertFalse($legacy['release_gate_eligible']);
        self::assertSame('eWɘyn', $legacy['legacy']['approved_by']);
        self::assertSame(['Kumwe\\App\\Example\\Formatting\\'], $legacy['legacy']['retired_app_namespaces']);
        self::assertSame('source-scan', $legacy['public_symbols_source']);
        self::assertNull($legacy['dependency_injection']['config_provider']);
        self::assertStringContainsString(
            'legacy-unmanifested',
            (string) $legacy['dependency_injection']['provider_absence_reason'],
        );
        self::assertNull($legacy['handoff']);
        self::assertSame('https://github.com/kumwe/example-legacy', $legacy['repository']);

        self::assertSame('v2-manifested', $version2['manifest_status']);
        self::assertTrue($version2['release_gate_eligible']);
        self::assertNull($version2['legacy']);
        self::assertSame(['Kumwe\\Example\\'], $version2['canonical_namespaces']);
        self::assertSame('example.describe', $version2['capabilities'][0]['id']);
        self::assertSame(['vendor/kumwe/example-v2/docs/public-api.md'], $version2['capabilities'][0]['documentation']);
        self::assertSame('Kumwe\\Example\\ConfigProvider', $version2['dependency_injection']['config_provider']);
        self::assertSame('shared', $version2['dependency_injection']['factories'][0]['lifetime']);
        self::assertSame(
            ['Kumwe\\Example\\Contract\\ExampleServiceInterface' => 'Kumwe\\Example\\ExampleService'],
            $version2['dependency_injection']['aliases'],
        );
        self::assertSame('kumwe.example.prefix', $version2['dependency_injection']['configuration_keys'][0]['key']);
        self::assertSame('KUMWE-MIG-2026-001', $version2['handoff']['migration_id']);
        self::assertSame(
            GovernanceFixture::digest(GovernanceFixture::cleanRoot(), 'vendor/kumwe/example-v2/MIGRATION-HANDOFF.md'),
            $version2['handoff']['sha256'],
        );
        self::assertSame('vendor/kumwe/example-v2/docs/public-api.md', $version2['documentation']['public_api']);

        self::assertSame(
            [
                [
                    'old_namespace' => 'Kumwe\\App\\Example\\Describing\\',
                    'package' => 'kumwe/example-v2',
                    'migration_id' => 'KUMWE-MIG-2026-001',
                ],
            ],
            $document['extracted_namespaces'],
        );
        self::assertSame('Kumwe\\App\\Example\\Describing\\Describer', $document['removed_symbols'][0]['old_fqcn']);
        self::assertSame('kumwe/example-v2', $document['ownership']['Kumwe\\Example\\ExampleService']);
        self::assertSame('kumwe/example-legacy', $document['ownership']['Kumwe\\ExampleLegacy\\LegacyFormatter']);
        self::assertArrayNotHasKey('Kumwe\\Example\\Internal\\Helper', $document['ownership']);
    }

    /**
     * A production record changes only the record coordinate within the existing index handoff field.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProductionRecordsKeepTheExistingIndexShape(): void
    {
        $root = GovernanceFixture::copy();
        try {
            $before = (new CapabilityIndexBuilder($root))->build();
            GovernanceFixture::useProductionRecord($root, true);
            $after = (new CapabilityIndexBuilder($root))->build();
            foreach ($after['packages'] as $offset => $package) {
                if ($package['package'] === 'kumwe/example-v2') {
                    self::assertSame('vendor/kumwe/example-v2/docs/release-record.md', $package['handoff']['path']);
                    self::assertSame(
                        GovernanceFixture::digest($root, 'vendor/kumwe/example-v2/docs/release-record.md'),
                        $package['handoff']['sha256'],
                    );
                    $before['packages'][$offset]['handoff'] = $package['handoff'];
                }
            }
            self::assertSame($before, $after);
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * Two builds of one tree produce identical bytes, and the lock's package order does not change them.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheIndexIsDeterministicAndOrderIndependent(): void
    {
        $first = CapabilityIndexWriter::json((new CapabilityIndexBuilder(GovernanceFixture::cleanRoot()))->build());
        $second = CapabilityIndexWriter::json((new CapabilityIndexBuilder(GovernanceFixture::cleanRoot()))->build());
        self::assertSame($first, $second);
        self::assertStringEndsWith("\n", $first);
        self::assertStringNotContainsString(sys_get_temp_dir(), $first);

        $root = GovernanceFixture::copy();
        try {
            /** @var array{packages: list<array<string, mixed>>} $lock */
            $lock = json_decode(GovernanceFixture::read($root, 'composer.lock'), true, 512, JSON_THROW_ON_ERROR);
            $lock['packages'] = array_reverse($lock['packages']);
            $reordered = json_encode($lock, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            GovernanceFixture::write($root, 'composer.lock', $reordered);

            $document = (new CapabilityIndexBuilder($root))->build();
            $expected = json_decode($first, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($expected);
            $expected['composer_lock_sha256'] = hash('sha256', $reordered);
            self::assertSame(
                json_encode($expected, JSON_THROW_ON_ERROR),
                json_encode(
                    json_decode(CapabilityIndexWriter::json($document), true, 512, JSON_THROW_ON_ERROR),
                    JSON_THROW_ON_ERROR,
                ),
            );
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * The writer's markdown embeds the digest, stays under 120 columns and round-trips through `check()`.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheWriterEmbedsTheDigestAndChecksItsOwnOutput(): void
    {
        $root = GovernanceFixture::copy();
        try {
            $document = (new CapabilityIndexBuilder($root))->build();
            $written = CapabilityIndexWriter::write($root, $document);

            self::assertSame(hash('sha256', $written['json']), $written['digest']);
            self::assertSame(
                $written['digest'] . "  v1.json\n",
                GovernanceFixture::read($root, CapabilityIndexWriter::DIGEST_PATH),
            );
            self::assertSame($written['json'], GovernanceFixture::read($root, CapabilityIndexWriter::JSON_PATH));
            self::assertSame(
                $written['markdown'],
                GovernanceFixture::read($root, CapabilityIndexWriter::MARKDOWN_PATH),
            );
            self::assertSame($written['digest'], CapabilityIndexWriter::embeddedDigest($written['markdown']));
            foreach (explode("\n", $written['markdown']) as $line) {
                self::assertLessThanOrEqual(120, strlen($line), $line);
            }
            self::assertSame([], CapabilityIndexWriter::check($root, $document)['problems']);

            GovernanceFixture::replace(
                $root,
                CapabilityIndexWriter::MARKDOWN_PATH,
                '- Index digest: sha256:' . substr($written['digest'], 0, 8),
                '- Index digest: sha256:00000000',
            );
            $problems = CapabilityIndexWriter::check($root, $document)['problems'];
            self::assertCount(1, $problems);
            self::assertStringContainsString('stale digest', $problems[0]);

            GovernanceFixture::write(
                $root,
                CapabilityIndexWriter::MARKDOWN_PATH,
                str_replace('## kumwe/example-v2', '## kumwe/example-v2 (edited)', $written['markdown']),
            );
            self::assertStringContainsString(
                'differs from the regenerated index',
                CapabilityIndexWriter::check($root, $document)['problems'][0],
            );

            GovernanceFixture::delete($root, CapabilityIndexWriter::MARKDOWN_PATH);
            self::assertStringContainsString(
                'is missing',
                CapabilityIndexWriter::check($root, $document)['problems'][0],
            );
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * Every fail-closed rule refuses a mutated copy of the fixture with the file, the rule and the fix.
     *
     * @param   list<array{0: string, 1: string, 2: string}>  $mutations  File, search and replacement.
     * @param   string                                        $rule       Fragment of the expected message.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('failClosedRules')]
    public function testEveryFailClosedRuleRefusesTheMutatedFixture(array $mutations, string $rule): void
    {
        $root = GovernanceFixture::copy();
        try {
            foreach ($mutations as [$relative, $search, $replace]) {
                if ($search === 'DELETE') {
                    GovernanceFixture::delete($root, $relative);
                } elseif ($search === '') {
                    GovernanceFixture::write($root, $relative, $replace);
                } else {
                    GovernanceFixture::replace($root, $relative, $search, $replace);
                }
            }
            try {
                (new CapabilityIndexBuilder($root))->build();
                self::fail('The index must be refused: ' . $rule);
            } catch (GovernanceViolation $violation) {
                self::assertStringContainsString($rule, $violation->getMessage());
                self::assertStringContainsString('Fix:', $violation->getMessage());
            }
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * Assert that building a root is refused with a message carrying the rule and a fix.
     *
     * @param   string  $root  Scratch root.
     * @param   string  $rule  Fragment of the expected message.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertRefused(string $root, string $rule): void
    {
        try {
            (new CapabilityIndexBuilder($root))->build();
            self::fail('The index must be refused: ' . $rule);
        } catch (GovernanceViolation $violation) {
            self::assertStringContainsString($rule, $violation->getMessage());
            self::assertStringContainsString('Fix:', $violation->getMessage());
        }
    }

    /**
     * Mutations that break an index rule of specification section 3.2.
     *
     * @return  iterable<string, array{list<array{0: string, 1: string, 2: string}>, string}>  Mutations and rule.
     *
     * @since   2.0.0
     */
    public static function failClosedRules(): iterable
    {
        $registry = 'docs/architecture/governance/legacy-packages.json';
        $ledger = 'docs/architecture/migrations/KUMWE-MIG-2026-001.yaml';
        $attestation = 'docs/architecture/migrations/evidence/KUMWE-MIG-2026-001/RELEASE-ATTESTATION.yaml';

        yield 'locked package not installed' => [
            [['vendor/kumwe/example-legacy', 'DELETE', '']],
            'install locked dependencies first',
        ];
        yield 'missing manifest' => [
            [['vendor/kumwe/example-v2/resources/capabilities/v1.json', 'DELETE', '']],
            'ships no resources/capabilities/v1.json',
        ];
        yield 'unapproved legacy package' => [
            [[$registry, '"kumwe/example-legacy": {', '"kumwe/example-legacy-other": {']],
            'not an approved legacy package',
        ];
        yield 'wrong legacy version' => [
            [[$registry, '"installed_version": "v0.9.0"', '"installed_version": "v0.9.1"']],
            'approved at v0.9.1 but v0.9.0 is locked',
        ];
        yield 'legacy namespaces differ from the lock' => [
            [[$registry, '"Kumwe\\\\ExampleLegacy\\\\"', '"Kumwe\\\\Legacy\\\\"']],
            'differ from the locked PSR-4 roots',
        ];
        yield 'stale registry entry' => [
            [
                [
                    $registry,
                    '"kumwe/example-legacy": {',
                    '"kumwe/example-gone": ' . json_encode([
                        'installed_version' => 'v0.9.0',
                        'reason' => 'gone',
                        'responsibility' => 'gone',
                        'non_responsibilities' => [],
                        'canonical_namespaces' => ['Kumwe\\Gone\\'],
                        'retired_app_namespaces' => [],
                        'verified_legacy_release' => null,
                        'approved_by' => 'x',
                        'approved_on' => '2026-09-02',
                    ], JSON_THROW_ON_ERROR) . ', "kumwe/example-legacy": {',
                ],
            ],
            'is not locked',
        ];
        yield 'ledger naming a legacy package' => [
            [
                [$ledger, 'package: kumwe/example-v2', 'package: kumwe/example-legacy'],
                [$ledger, 'version: 0.1.0', 'version: 0.9.0'],
                [
                    $ledger,
                    'handoff_path: vendor/kumwe/example-v2/MIGRATION-HANDOFF.md',
                    'handoff_path: vendor/kumwe/example-legacy/MIGRATION-HANDOFF.md',
                ],
                [$ledger, '  - kumwe/example-v2', '  - kumwe/example-legacy'],
                [$attestation, 'version: 0.1.0', 'version: 0.9.0'],
            ],
            'cannot satisfy a migration release gate',
        ];
        yield 'version 2 package without a ledger record' => [
            [[$ledger, 'DELETE', ''], ['docs/architecture/migrations/evidence/KUMWE-MIG-2026-001', 'DELETE', '']],
            'no ledger record adopts it',
        ];
        $handoffDigest = GovernanceFixture::digest(
            GovernanceFixture::cleanRoot(),
            'vendor/kumwe/example-v2/MIGRATION-HANDOFF.md',
        );
        yield 'handoff digest mismatch' => [
            [[$ledger, $handoffDigest, strrev($handoffDigest)]],
            'differs from the installed handoff digest',
        ];
        yield 'provider without factories' => [
            [
                [
                    'vendor/kumwe/example-v2/resources/service-map/v1.json',
                    GovernanceFixture::FACTORY_BLOCK,
                    '"factories": []',
                ],
            ],
            'without any factory',
        ];
        yield 'service registered twice inside a package' => [
            [
                [
                    'vendor/kumwe/example-v2/resources/service-map/v1.json',
                    '"Kumwe\\\\Example\\\\Contract\\\\ExampleServiceInterface": "Kumwe\\\\Example\\\\ExampleService"',
                    '"Kumwe\\\\Example\\\\ExampleService": "Kumwe\\\\Example\\\\ExampleService"',
                ],
            ],
            'both a factory-built service and an alias',
        ];
        yield 'verified legacy release missing' => [
            [
                [
                    $registry,
                    '"verified_legacy_release": null',
                    '"verified_legacy_release": "docs/architecture/migrations/evidence/legacy/example-legacy/'
                    . 'VERIFIED-LEGACY-RELEASE.yaml"',
                ],
            ],
            'is not a verified legacy release record',
        ];
        yield 'registry fails its schema' => [
            [[$registry, '"approved_on": "2026-09-02"', '"approved_on": "today"']],
            'fails legacy-packages.v1.schema.json',
        ];
        yield 'registry missing' => [[[$registry, 'DELETE', '']], 'registry is missing'];
    }

    /**
     * A Version 2 package whose manifests describe another release than the locked one is refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAVersion2PackageMustDescribeTheLockedRelease(): void
    {
        $root = GovernanceFixture::copy();
        try {
            foreach (['public-api', 'capabilities', 'service-map'] as $manifest) {
                GovernanceFixture::replace(
                    $root,
                    'vendor/kumwe/example-v2/resources/' . $manifest . '/v1.json',
                    '"release": "0.1.0"',
                    '"release": "0.2.0"',
                );
            }
            GovernanceFixture::reseal($root, 'example-v2', 'KUMWE-MIG-2026-001');
            $this->assertRefused($root, 'describe release 0.2.0');
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * A ledger record whose change set differs from the released handoff's is refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheLedgerMustNameTheHandoffChangeSet(): void
    {
        $root = GovernanceFixture::copy();
        try {
            GovernanceFixture::replace(
                $root,
                'vendor/kumwe/example-v2/MIGRATION-HANDOFF.md',
                'change_set: KUMWE-CS-2026-001',
                'change_set: KUMWE-CS-2026-002',
            );
            GovernanceFixture::reseal($root, 'example-v2', 'KUMWE-MIG-2026-001');
            $this->assertRefused($root, 'differs from the handoff change set');
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * A distribution install that omits a legacy package's charter, README, docs and tests yields identical bytes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheIndexDoesNotDependOnLegacyFilesAReleaseArchiveMayOmit(): void
    {
        $expected = CapabilityIndexWriter::json((new CapabilityIndexBuilder(GovernanceFixture::cleanRoot()))->build());
        $root = GovernanceFixture::copy();
        try {
            foreach (['CHARTER.md', 'README.md', 'docs', 'tests'] as $relative) {
                GovernanceFixture::delete($root, 'vendor/kumwe/example-legacy/' . $relative);
            }
            self::assertFileDoesNotExist($root . '/vendor/kumwe/example-legacy/CHARTER.md');
            self::assertFileDoesNotExist($root . '/vendor/kumwe/example-legacy/README.md');

            $document = (new CapabilityIndexBuilder($root))->build();

            self::assertSame($expected, CapabilityIndexWriter::json($document));
            self::assertSame(
                ['charter' => null, 'readme' => null, 'public_api' => null],
                $document['packages'][0]['documentation'],
            );
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * A Version 2 archive that omits its charter fails closed, naming what a release archive must ship.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAVersion2ArchiveMustShipItsDocumentation(): void
    {
        $root = GovernanceFixture::copy();
        try {
            GovernanceFixture::delete($root, 'vendor/kumwe/example-v2/CHARTER.md');
            $this->assertRefused(
                $root,
                'release archive must ship CHARTER.md, README.md, docs/, resources/ and MIGRATION-HANDOFF.md',
            );
        } finally {
            GovernanceFixture::remove($root);
        }
    }

    /**
     * Two packages exporting one FQCN, or declaring one capability id, are refused as duplicate owners.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDuplicateOwnersAcrossPackagesAreRefused(): void
    {
        $root = GovernanceFixture::copy();
        try {
            GovernanceFixture::cloneVersion2Package($root);
            try {
                (new CapabilityIndexBuilder($root))->build();
                self::fail('Two packages declaring one capability id must be refused.');
            } catch (GovernanceViolation $violation) {
                self::assertStringContainsString(
                    'duplicate capability owner: example.describe',
                    $violation->getMessage(),
                );
            }

            GovernanceFixture::replace(
                $root,
                'vendor/kumwe/example-v3/resources/capabilities/v1.json',
                '"id": "example.describe"',
                '"id": "example.describe-again"',
            );
            GovernanceFixture::reseal($root, 'example-v3', 'KUMWE-MIG-2026-002');
            self::assertSame(
                ['kumwe/example-legacy', 'kumwe/example-v2', 'kumwe/example-v3'],
                array_column((new CapabilityIndexBuilder($root))->build()['packages'], 'package'),
                'With distinct capability ids the clone is a clean second Version 2 package.',
            );

            GovernanceFixture::replace(
                $root,
                'vendor/kumwe/example-legacy/composer.json',
                '"Kumwe\\\\ExampleLegacy\\\\": "src/"',
                '"Kumwe\\\\Example\\\\": "src/"',
            );
            GovernanceFixture::replace(
                $root,
                'composer.lock',
                '"Kumwe\\\\ExampleLegacy\\\\": "src/"',
                '"Kumwe\\\\Example\\\\": "src/"',
            );
            GovernanceFixture::replace(
                $root,
                'docs/architecture/governance/legacy-packages.json',
                '"Kumwe\\\\ExampleLegacy\\\\"',
                '"Kumwe\\\\Example\\\\"',
            );
            GovernanceFixture::delete($root, 'vendor/kumwe/example-legacy/src');
            GovernanceFixture::write(
                $root,
                'vendor/kumwe/example-legacy/src/ExampleService.php',
                "<?php\n\ndeclare(strict_types=1);\n\nnamespace Kumwe\\Example;\n\nfinal class ExampleService\n{\n}\n",
            );
            try {
                (new CapabilityIndexBuilder($root))->build();
                self::fail('Two packages exporting one FQCN must be refused.');
            } catch (GovernanceViolation $violation) {
                self::assertStringContainsString(
                    'duplicate FQCN owner: Kumwe\\Example\\ExampleService',
                    $violation->getMessage(),
                );
            }
        } finally {
            GovernanceFixture::remove($root);
        }
    }
}
