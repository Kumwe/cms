<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use Kumwe\App\Shared\Domain\CanonicalJson as AppCanonicalJson;
use Kumwe\App\Tools\Governance\CapabilityIndexBuilder;
use Kumwe\App\Tools\Governance\StrictYaml;
use Kumwe\CanonicalJson\CanonicalEncoder;
use Kumwe\CanonicalJson\FindingCode;
use Kumwe\CanonicalJson\Limits;
use Kumwe\CanonicalJson\Profile;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Proves the adopted `kumwe/canonical-json` release is the verified one and that App adopted only its semantics.
 *
 * `KUMWE-MIG-2026-007` is a semantic-only adoption: the installed package's profile, corpus, manifests and handoff
 * are the exact bytes the external release attestation verified, the ledger pins that release and its handoff, the
 * capability index lists the `canonical-json.semantics` capability from the installed manifests with no provider,
 * and the App's generic executor keeps running until Computation's separately gated runtime cutover.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class CanonicalJsonSemanticIdentityGateTest extends TestCase
{
    /**
     * Installed package root, relative to the repository.
     *
     * @var    string
     * @since  2.0.0
     */
    private const PACKAGE = 'vendor/kumwe/canonical-json';

    /**
     * Migration ledger record of the adoption.
     *
     * @var    string
     * @since  2.0.0
     */
    private const LEDGER = 'docs/architecture/migrations/KUMWE-MIG-2026-007.yaml';

    /**
     * External release attestation the ledger cites.
     *
     * @var    string
     * @since  2.0.0
     */
    private const ATTESTATION = 'docs/architecture/migrations/evidence/KUMWE-MIG-2026-007/RELEASE-ATTESTATION.yaml';

    /**
     * The four exported symbols, in manifest order.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const SYMBOLS = [
        'Kumwe\\CanonicalJson\\CanonicalEncoder',
        'Kumwe\\CanonicalJson\\FindingCode',
        'Kumwe\\CanonicalJson\\Limits',
        'Kumwe\\CanonicalJson\\Profile',
    ];

    /**
     * Load the governance tools once.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/tools/Governance/bootstrap.php';
    }

    /**
     * The installed profile, corpus, manifests and handoff are the bytes the attestation verified and the ledger pins,
     * and the App's own generic executor is still in place.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheInstalledSemanticIdentityIsTheVerifiedRelease(): void
    {
        $root = dirname(__DIR__, 2);
        $package = $root . '/' . self::PACKAGE;
        $ledger = $this->record($root . '/' . self::LEDGER);
        $attestation = $this->record($root . '/' . self::ATTESTATION);

        self::assertSame('kumwe/canonical-json', $ledger['package']);
        self::assertSame('0.1.1', $ledger['version']);
        self::assertSame('verified', $attestation['status']);
        self::assertSame('0.1.1', $attestation['version']);
        self::assertSame('v0.1.1', $attestation['tag']);
        self::assertSame($this->lockedReference($root), $attestation['merge_commit']);
        self::assertIsArray($attestation['source_archive']);
        self::assertSame($attestation['source_archive']['sha256'], $ledger['artifact_digest']);
        self::assertSame(hash_file('sha256', $package . '/MIGRATION-HANDOFF.md'), $ledger['handoff_sha256']);

        $capabilities = $this->json($package . '/resources/capabilities/v1.json');
        self::assertSame('kumwe/canonical-json', $capabilities['package']);
        self::assertSame('0.1.1', $capabilities['release']);

        $corpus = $this->json($package . '/resources/corpus/v1.json');
        self::assertSame('kumwe-canonical-json/generic-v1', Profile::GenericV1->value);
        self::assertSame(Profile::GenericV1->value, $corpus['profile']);
        self::assertIsArray($corpus['cases']);
        self::assertCount(79, $corpus['cases']);
        $digest = hash_file('sha256', $package . '/resources/corpus/v1.json');
        self::assertSame($digest, trim((string) file_get_contents($package . '/resources/corpus/v1.sha256')));
        self::assertSame($digest, $this->attested($attestation, 'resources/corpus/v1.json'));
        $manifests = [
            'resources/public-api/v1.json',
            'resources/capabilities/v1.json',
            'resources/service-map/v1.json',
            'MIGRATION-HANDOFF.md',
        ];
        foreach ($manifests as $path) {
            self::assertSame(hash_file('sha256', $package . '/' . $path), $this->attested($attestation, $path), $path);
        }

        self::assertTrue(interface_exists(CanonicalEncoder::class));
        self::assertTrue(enum_exists(FindingCode::class));
        self::assertTrue(class_exists(Limits::class));
        self::assertTrue(method_exists(AppCanonicalJson::class, 'encode'));
    }

    /**
     * The capability index lists the semantic capability and the direct-construction service map from the installed
     * manifests, bound to the handoff the ledger adopts, and retires no App namespace.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheCapabilityIndexRecordsTheSemanticCapabilityFromTheInstalledManifests(): void
    {
        $root = dirname(__DIR__, 2);
        $document = (new CapabilityIndexBuilder($root))->build();
        /** @var list<array<string, mixed>> $packages */
        $packages = $document['packages'];
        $entries = array_column($packages, null, 'package');
        self::assertArrayHasKey('kumwe/canonical-json', $entries);
        $package = $entries['kumwe/canonical-json'];

        self::assertSame('v2-manifested', $package['manifest_status']);
        self::assertTrue($package['release_gate_eligible']);
        self::assertSame('v0.1.1', $package['installed_version']);
        self::assertSame('manifest:resources/public-api/v1.json', $package['public_symbols_source']);
        self::assertSame(self::SYMBOLS, $package['public_symbols']);
        $handoff = $package['handoff'];
        self::assertIsArray($handoff);
        self::assertSame('KUMWE-MIG-2026-007', $handoff['migration_id']);
        self::assertSame('KUMWE-CS-2026-007', $handoff['change_set']);
        $installed = hash_file('sha256', $root . '/' . self::PACKAGE . '/MIGRATION-HANDOFF.md');
        self::assertSame($installed, $handoff['sha256']);
        $capabilities = $package['capabilities'];
        self::assertIsArray($capabilities);
        self::assertSame(['canonical-json.semantics'], array_column($capabilities, 'id'));
        $semantics = $capabilities[0];
        self::assertIsArray($semantics);
        self::assertSame(self::SYMBOLS, $semantics['symbols']);
        $injection = $package['dependency_injection'];
        self::assertIsArray($injection);
        self::assertNull($injection['config_provider']);
        self::assertSame([], $injection['factories']);
        self::assertSame([], $injection['aliases']);
        self::assertIsString($injection['provider_absence_reason']);
        self::assertNotSame('', $injection['provider_absence_reason']);
        /** @var list<array{package: string}> $extracted */
        $extracted = $document['extracted_namespaces'];
        self::assertSame([], array_values(array_filter(
            $extracted,
            static fn (array $entry): bool => $entry['package'] === 'kumwe/canonical-json',
        )));
    }

    /**
     * Parse one strict YAML governance record.
     *
     * @param   string  $path  Absolute record path.
     *
     * @return  array<string, mixed>  Parsed record.
     *
     * @since   2.0.0
     */
    private function record(string $path): array
    {
        self::assertFileExists($path);

        return StrictYaml::parse((string) file_get_contents($path), basename($path));
    }

    /**
     * Decode one JSON document to an associative array.
     *
     * @param   string  $path  Absolute document path.
     *
     * @return  array<string, mixed>  Decoded document.
     *
     * @since   2.0.0
     */
    private function json(string $path): array
    {
        self::assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * The digest the attestation recorded for one shipped path.
     *
     * @param   array<string, mixed>  $attestation  Parsed attestation record.
     * @param   string                $path         Path inside the released archive.
     *
     * @return  string  Recorded SHA-256 digest.
     *
     * @since   2.0.0
     */
    private function attested(array $attestation, string $path): string
    {
        $entries = $attestation['manifests_and_corpora'];
        self::assertIsArray($entries);
        foreach ($entries as $entry) {
            self::assertIsArray($entry);
            if ($entry['path'] === $path) {
                self::assertIsString($entry['sha256']);

                return $entry['sha256'];
            }
        }

        self::fail('The attestation records no digest for ' . $path);
    }

    /**
     * The source reference the lock pins for the package.
     *
     * @param   string  $root  Repository root.
     *
     * @return  string  Locked git reference.
     *
     * @since   2.0.0
     */
    private function lockedReference(string $root): string
    {
        $lock = $this->json($root . '/composer.lock');
        self::assertIsArray($lock['packages']);
        foreach ($lock['packages'] as $locked) {
            self::assertIsArray($locked);
            if ($locked['name'] === 'kumwe/canonical-json') {
                self::assertIsArray($locked['source']);
                self::assertIsString($locked['source']['reference']);

                return $locked['source']['reference'];
            }
        }

        self::fail('composer.lock does not lock kumwe/canonical-json');
    }
}
