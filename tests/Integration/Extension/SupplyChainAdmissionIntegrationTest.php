<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Extension;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use FilesystemIterator;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Extension\Application\ExtensionManager;
use Kumwe\App\Extension\Application\Package\NonConformingPackage;
use Kumwe\Extension\Package\PackageBillOfMaterials;
use Kumwe\Extension\Package\PackageProvenance;
use Kumwe\App\Extension\Application\Trust\RevocationFeedSource;
use Kumwe\App\Extension\Application\Trust\RevocationFeedSynchronizer;
use Kumwe\App\Extension\Application\Trust\RevocationList;
use Kumwe\App\Extension\Application\Trust\RevocationListRefused;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\Extension\Toolchain\ComponentScaffolder;
use Kumwe\Extension\Toolchain\DeterministicPackageBuilder;
use Kumwe\Extension\Toolchain\PackageInspector;
use Kumwe\Extension\Toolchain\ScaffoldRequest;
use Kumwe\Extension\Package\PackageChecksum;
use Kumwe\Extension\Package\PackageSignatureMessage;
use Kumwe\App\Extension\Infrastructure\Trust\DoctrineRevocationFeedStateStore;
use Kumwe\App\Extension\Infrastructure\Trust\SodiumRevocationListVerifier;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Configuration\RevocationFeedConfiguration;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Kumwe\App\Audit\Application\AuditRecorder;
use Ramsey\Uuid\Uuid;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use ZipArchive;

#[CoversClass(RevocationFeedSynchronizer::class)]
#[CoversClass(DoctrineRevocationFeedStateStore::class)]
#[UsesClass(RevocationList::class)]
/**
 * Drives the supply-chain controls end to end against a real database and a real install.
 *
 * Two things are proven here that no unit test can. A package built by the shipped SDK installs through
 * the real registry and leaves a stored admission record an operator screen can read, while a package
 * edited after its build is refused before a single byte is unpacked. And the revocation feed drives the
 * real trust store: a signed list withdraws a live key, a replayed older list is refused, and an
 * unreachable origin leaves the applied revocation in force instead of failing the run.
 *
 * @since  2.0.0
 */
final class SupplyChainAdmissionIntegrationTest extends TestCase
{
    /**
     * Canonical private root allocated for one test invocation.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $temporary;

    /**
     * Allocate a private test root with a canonical absolute path.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->temporary = sys_get_temp_dir() . '/kumwe-supply-chain-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($this->temporary, 0700));
    }

    /**
     * Remove only the private root allocated by this test.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        if (isset($this->temporary) && is_dir($this->temporary) && !is_link($this->temporary)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->temporary, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $entry) {
                if (!$entry instanceof SplFileInfo) {
                    continue;
                }
                if ($entry->isDir() && !$entry->isLink()) {
                    rmdir($entry->getPathname());
                } else {
                    unlink($entry->getPathname());
                }
            }
            rmdir($this->temporary);
        }
        parent::tearDown();
    }

    /**
     * A signed, SDK-built package installs and records a verified inventory; an edited one is refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAdmissionRecordsAVerifiedInventoryAndRefusesAnEditedPackage(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $manager = $container->get(ExtensionManager::class);
        $trust = $container->get(TrustStore::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(ExtensionManager::class, $manager);
        self::assertInstanceOf(TrustStore::class, $trust);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $context = TestKernelFactory::administratorContext($container);
        $marker = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), 0, 8));
        $identifier = 'integration/supply-' . $marker;
        $keyId = 'integration.supply.' . $marker;
        $keyPair = sodium_crypto_sign_keypair();
        $trust->add(
            $context,
            $keyId,
            base64_encode(sodium_crypto_sign_publickey($keyPair)),
            'integration',
            '*',
            new DateTimeImmutable('+1 year'),
        );
        $archive = $this->buildPackage($identifier, 'clean');
        $signature = $this->sign($archive, sodium_crypto_sign_secretkey($keyPair));
        $installed = false;

        try {
            $manager->install($archive, $context, $keyId, $signature);
            $installed = true;
            $row = $this->findRow($manager, $context, $identifier);
            self::assertSame('verified', $row['supply_chain']['sbom']);
            self::assertSame('verified', $row['supply_chain']['provenance']);
            self::assertSame('passed', $row['supply_chain']['conformance']);
            self::assertGreaterThan(5, $row['supply_chain']['components']);
            self::assertSame(
                PackageProvenance::BUILDER_NAME . '@' . PackageProvenance::BUILDER_VERSION,
                $row['supply_chain']['builder'],
            );
            $stored = $database->fetchAssociative(sprintf(
                'SELECT a.sbom_state, a.sbom_components, a.conformance_state, a.conformance_mode '
                . 'FROM %s a INNER JOIN %s r ON r.id = a.release_id INNER JOIN %s e ON e.id = r.extension_id '
                . 'WHERE e.identifier = ?',
                $tables->quoted('extension_release_attestations'),
                $tables->quoted('extension_releases'),
                $tables->quoted('extensions'),
            ), [$identifier]);
            self::assertIsArray($stored);
            self::assertSame('verified', $stored['sbom_state']);
            self::assertSame('scan', $stored['conformance_mode']);

            $edited = $this->buildPackage($identifier . '-edited', 'edited');
            $this->rewriteEntry($edited, 'README.md', "# Rewritten after the inventory was recorded\n");
            $editedSignature = $this->sign($edited, sodium_crypto_sign_secretkey($keyPair));

            $this->expectException(NonConformingPackage::class);
            $manager->install($edited, $context, $keyId, $editedSignature);
        } finally {
            if ($installed) {
                try {
                    $manager->uninstall($identifier, $context);
                } catch (Throwable) {
                    // The assertion under test owns the failure; cleanup is best effort.
                }
            }
        }
    }

    /**
     * A signed list revokes a live key, a replay is refused, and an unreachable origin does not.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRevocationFeedAppliesRefusesRollbackAndSurvivesAnUnreachableOrigin(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $trust = $container->get(TrustStore::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        $clock = $container->get(ClockInterface::class);
        $audit = $container->get(AuditRecorder::class);
        self::assertInstanceOf(TrustStore::class, $trust);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        self::assertInstanceOf(ClockInterface::class, $clock);
        self::assertInstanceOf(AuditRecorder::class, $audit);
        $context = TestKernelFactory::administratorContext($container);
        $marker = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), 0, 8));
        $keyId = 'integration.feed.' . $marker;
        $trust->add(
            $context,
            $keyId,
            base64_encode(str_repeat("\x02", SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)),
            'integration',
            '*',
            new DateTimeImmutable('+1 year'),
        );
        $origin = $this->temporary . '/revocations-' . $marker . '.json';
        $seed = substr(str_pad('kumwe-integration-feed-seed-', 32, '.'), 0, 32);
        $issuerKeys = sodium_crypto_sign_seed_keypair($seed);
        $configuration = new RevocationFeedConfiguration(
            $origin,
            base64_encode(sodium_crypto_sign_publickey($issuerKeys)),
            3_600,
        );
        $source = new class ($origin) implements RevocationFeedSource {
            /**
             * Bind the stub source to the mirrored file it reads.
             *
             * @param  string  $path  Absolute path of the mirrored envelope.
             *
             * @since  2.0.0
             */
            public function __construct(private string $path)
            {
            }

            /**
             * Read the mirror, raising the transport failure an unreachable origin produces.
             *
             * @param   string  $origin  Configured origin; ignored, the stub reads its own path.
             *
             * @return  string  Raw envelope bytes.
             *
             * @throws  RuntimeException  When the mirror has been removed.
             *
             * @since   2.0.0
             */
            public function fetch(string $origin): string
            {
                $payload = is_file($this->path) ? file_get_contents($this->path) : false;
                if (!is_string($payload)) {
                    throw new RuntimeException('The revocation feed origin could not be reached.');
                }

                return $payload;
            }
        };
        $synchronizer = new RevocationFeedSynchronizer(
            $configuration,
            $source,
            new SodiumRevocationListVerifier(),
            new DoctrineRevocationFeedStateStore($database, $tables),
            $trust,
            $audit,
            $clock,
            new Logger('supply-chain-test', [new NullHandler()]),
        );

        $this->publishList($origin, $issuerKeys, 4, [$keyId]);
        self::assertSame([$keyId], $synchronizer->synchronize($context));
        self::assertSame(4, $synchronizer->state()->appliedSequence);
        self::assertNotNull($synchronizer->state()->lastSuccessAt);
        self::assertFalse($synchronizer->state()->isStale($clock->now()));
        self::assertNotNull($this->revokedAt($database, $tables, $keyId));

        $this->publishList($origin, $issuerKeys, 3, []);
        try {
            $synchronizer->synchronize($context);
            self::fail('A list older than the applied sequence must be refused.');
        } catch (RevocationListRefused $refusal) {
            self::assertStringContainsString('older than the applied sequence', $refusal->getMessage());
        }
        self::assertSame(4, $synchronizer->state()->appliedSequence);

        self::assertTrue(unlink($origin));
        self::assertSame([], $synchronizer->synchronize($context));
        $state = $synchronizer->state();
        self::assertSame(4, $state->appliedSequence);
        self::assertGreaterThan(0, $state->consecutiveFailures);
        self::assertStringContainsString('unreachable', (string) $state->lastFailureReason);
        self::assertNotNull($this->revokedAt($database, $tables, $keyId));
    }

    /**
     * Write a signed revocation list to the mirrored origin.
     *
     * @param   string        $path       Absolute path the envelope is written to.
     * @param   string        $keyPair    Issuer key pair the statement is signed with.
     * @param   int           $sequence   List sequence number.
     * @param   list<string>  $revoked    Key identifiers the list withdraws.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function publishList(string $path, string $keyPair, int $sequence, array $revoked): void
    {
        $entries = [];
        foreach ($revoked as $keyId) {
            $entries[] = ['key_id' => $keyId, 'reason' => 'Withdrawn by the integration issuer.'];
        }
        $statement = json_encode([
            'format' => RevocationList::FORMAT,
            'issuer' => 'integration-issuer',
            'sequence' => $sequence,
            'issued_at' => (new DateTimeImmutable('-1 hour'))->format(DATE_ATOM),
            'valid_until' => (new DateTimeImmutable('+30 days'))->format(DATE_ATOM),
            'revoked_keys' => $entries,
        ], JSON_THROW_ON_ERROR);
        $envelope = json_encode([
            'format' => RevocationList::ENVELOPE_FORMAT,
            'algorithm' => 'ed25519',
            'key_id' => 'integration-issuer-2026',
            'document' => $statement,
            'signature' => base64_encode(
                sodium_crypto_sign_detached($statement, sodium_crypto_sign_secretkey($keyPair)),
            ),
        ], JSON_THROW_ON_ERROR);
        self::assertSame(strlen($envelope), file_put_contents($path, $envelope, LOCK_EX));
    }

    /**
     * Read the revocation instant recorded on one trust key.
     *
     * @param   Connection  $database  Connection the read runs on.
     * @param   TableNames  $tables    Prefix-aware resolver for the trust key table.
     * @param   string      $keyId     Key identifier to read.
     *
     * @return  ?string  The stored instant as the driver returned it, or null when still active.
     *
     * @since   2.0.0
     */
    private function revokedAt(Connection $database, TableNames $tables, string $keyId): ?string
    {
        $value = $database->fetchOne(sprintf(
            'SELECT revoked_at FROM %s WHERE key_id = ?',
            $tables->quoted('extension_trust_keys'),
        ), [$keyId]);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Find the listed registry row for one installed extension.
     *
     * @param   ExtensionManager  $manager     Registry the listing is read from.
     * @param   ExecutionContext  $context     Actor the listing is authorized for.
     * @param   string            $identifier  `vendor/name` identifier to find.
     *
     * @return  array<string, mixed>  The matching row.
     *
     * @since   2.0.0
     */
    private function findRow(ExtensionManager $manager, ExecutionContext $context, string $identifier): array
    {
        foreach ($manager->installed($context) as $row) {
            if (($row['identifier'] ?? null) === $identifier) {
                return $row;
            }
        }

        self::fail(sprintf('The extension %s is not listed as installed.', $identifier));
    }

    /**
     * Scaffold and build one package with the shipped SDK builder.
     *
     * @param   string  $identifier  `vendor/name` identifier for the scaffolded component.
     * @param   string  $label       Directory label distinguishing this build from its siblings.
     *
     * @return  string  Absolute path of the published archive.
     *
     * @since   2.0.0
     */
    private function buildPackage(string $identifier, string $label): string
    {
        $source = $this->temporary . '/' . $label;
        (new ComponentScaffolder())->scaffold(new ScaffoldRequest(
            $identifier,
            'Integration\\Supply' . ucfirst($label),
            $source,
            'Supply Chain Fixture',
        ));

        return (new DeterministicPackageBuilder(
            new PackageInspector(),
        ))->build($source, $this->temporary . '/' . $label . '.zip')->archive;
    }

    /**
     * Replace one entry of an already-published package, simulating a post-build edit.
     *
     * @param   string  $archive   Absolute package path.
     * @param   string  $path      Package path to rewrite.
     * @param   string  $contents  Replacement bytes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function rewriteEntry(string $archive, string $path, string $contents): void
    {
        self::assertTrue(chmod($archive, 0600));
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive) === true);
        self::assertTrue($zip->addFromString($path, $contents));
        self::assertTrue($zip->close());
        self::assertContains($path, [$path]);
        self::assertNotSame(PackageBillOfMaterials::PATH, $path);
    }

    /**
     * Sign one package's digest with the fixture publisher key.
     *
     * @param   string  $archive    Absolute package path.
     * @param   string  $secretKey  Publisher secret key.
     *
     * @return  string  Standard base64 detached signature over the SDK package message.
     *
     * @since   2.0.0
     */
    private function sign(string $archive, string $secretKey): string
    {
        $bytes = file_get_contents($archive);
        self::assertIsString($bytes);

        return base64_encode(
            sodium_crypto_sign_detached(
                PackageSignatureMessage::forChecksum(PackageChecksum::calculate($bytes)),
                $secretKey,
            ),
        );
    }
}
