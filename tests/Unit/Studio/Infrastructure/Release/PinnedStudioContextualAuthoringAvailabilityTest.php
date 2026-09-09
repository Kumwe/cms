<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Infrastructure\Release;

use Closure;
use InvalidArgumentException;
use Kumwe\App\Studio\Application\Authoring\StudioContextualAuthoringFallbackReason;
use Kumwe\App\Studio\Application\Authoring\StudioContextualAuthoringReadiness;
use Kumwe\App\Studio\Infrastructure\Release\PinnedStudioContextualAuthoringAvailability;
use Kumwe\App\Studio\Infrastructure\Release\StudioContextualAuthoringQualification;
use Kumwe\Producer\Deployment\StudioBrowserAssetLocator;
use Kumwe\Producer\Schema\StudioContractResources;
use Kumwe\Producer\Schema\StudioDocumentSchemaRegistry;
use Kumwe\Producer\Wire\OperationRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Proves contextual Studio is enabled only by Producer and exact App deployment evidence.
 *
 * @since  2.0.0
 */
#[CoversClass(PinnedStudioContextualAuthoringAvailability::class)]
#[CoversClass(StudioContextualAuthoringQualification::class)]
#[CoversClass(StudioContextualAuthoringReadiness::class)]
#[CoversClass(StudioContextualAuthoringFallbackReason::class)]
final class PinnedStudioContextualAuthoringAvailabilityTest extends TestCase
{
    /**
     * Packaged JavaScript file the temporary deployments publish for the start module.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string LAUNCH_MODULE = 'studio-launch-Test0001.js';

    /**
     * Temporary App deployment roots built by the running test and removed after it.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $deployments = [];

    /**
     * Remove every temporary deployment the test registered.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        foreach ($this->deployments as $root) {
            self::removeTree($root);
        }
        $this->deployments = [];
    }

    /**
     * The repository's own pin passes the protocol boundary and stops closed without a configured origin.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheRepositoryPinPassesTheProtocolBoundaryAndStopsWithoutABrowserOrigin(): void
    {
        $root = dirname(__DIR__, 5);
        self::assertContains('authoring-target', StudioDocumentSchemaRegistry::CONTEXTUAL_DOCUMENT_KINDS);
        self::assertTrue(OperationRegistry::isCapability('studio.operation/authoring.resolve-target'));

        $readiness = (new PinnedStudioContextualAuthoringAvailability($root, null, null))->current();

        self::assertFalse($readiness->available);
        self::assertSame(StudioContextualAuthoringFallbackReason::BrowserRuntimeUnavailable, $readiness->reason);
        self::assertSame([
            'available' => false,
            'fallback' => 'structured-form',
            'reason' => 'browser-runtime-unavailable',
        ], $readiness->toArray());
    }

    /**
     * Missing App-owned release evidence cannot fall through to browser or host qualification.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMissingAppDeploymentEvidenceFailsAtProtocolBoundary(): void
    {
        $root = sys_get_temp_dir() . '/kumwe-studio-missing-' . bin2hex(random_bytes(8));

        $readiness = (new PinnedStudioContextualAuthoringAvailability($root, null, self::locator()))->current();

        self::assertFalse($readiness->available);
        self::assertSame(StudioContextualAuthoringFallbackReason::ProtocolUnavailable, $readiness->reason);
    }

    /**
     * A qualification carries only App-owned immutable deployment coordinates.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testQualificationCarriesExactAppEvidence(): void
    {
        $qualification = new StudioContextualAuthoringQualification(
            '0.1.0-rc.1',
            str_repeat('a', 64),
            str_repeat('b', 64),
            str_repeat('c', 64),
            'sha256-bQLLnuYhQprCbuB1LX5Yb09/n+KVS6xB+M7Mdh2/D34=',
        );

        self::assertSame('0.1.0-rc.1', $qualification->release);
        self::assertSame(str_repeat('a', 64), $qualification->releaseRecordSha256);
        self::assertSame(str_repeat('b', 64), $qualification->pinRecordSha256);
        self::assertSame(str_repeat('c', 64), $qualification->coreCatalogSha256);
        self::assertSame(
            'sha256-bQLLnuYhQprCbuB1LX5Yb09/n+KVS6xB+M7Mdh2/D34=',
            $qualification->browserModuleIntegrity,
        );
    }

    /**
     * A qualification cannot carry a moving release label, a non-SHA-256 digest or a malformed integrity.
     *
     * @param   string  $release    Candidate exact release.
     * @param   string  $digest     Candidate release-record digest.
     * @param   string  $integrity  Candidate browser-module integrity.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('invalidQualifications')]
    public function testQualificationRequiresExactImmutableCoordinates(
        string $release,
        string $digest,
        string $integrity,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        new StudioContextualAuthoringQualification(
            $release,
            $digest,
            str_repeat('a', 64),
            str_repeat('b', 64),
            $integrity,
        );
    }

    /**
     * Supply one invalid coordinate per qualification rule.
     *
     * @return  iterable<string, array{string, string, string}>  Named refusal cases.
     *
     * @since   2.0.0
     */
    public static function invalidQualifications(): iterable
    {
        $integrity = 'sha256-bQLLnuYhQprCbuB1LX5Yb09/n+KVS6xB+M7Mdh2/D34=';

        yield 'moving release label' => ['latest', str_repeat('a', 64), $integrity];
        yield 'non SHA-256 digest' => ['0.1.0-test.1', 'sha256-not-hex', $integrity];
        yield 'SHA-512 integrity' => ['0.1.0-test.1', str_repeat('a', 64), 'sha512-' . str_repeat('A', 86) . '=='];
        yield 'hexadecimal integrity' => ['0.1.0-test.1', str_repeat('a', 64), str_repeat('a', 64)];
    }

    /**
     * One exact deployment whose release, pin, catalog, origin and host evidence all match enables authoring.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAQualifiedExactDeploymentEnablesContextualAuthoring(): void
    {
        $root = $this->deployment();

        $readiness = (new PinnedStudioContextualAuthoringAvailability(
            $root,
            self::qualification($root),
            self::locator(),
        ))->current();

        self::assertTrue($readiness->available);
        self::assertNull($readiness->reason);
        self::assertSame([
            'available' => true,
            'fallback' => 'structured-form',
            'reason' => null,
        ], $readiness->toArray());
    }

    /**
     * A site-absolute mirror keeping the npm package layout qualifies exactly like the public CDN.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testASameOriginPackageMirrorEnablesContextualAuthoring(): void
    {
        $root = $this->deployment();

        $readiness = (new PinnedStudioContextualAuthoringAvailability(
            $root,
            self::qualification($root),
            StudioBrowserAssetLocator::npmPackages('/vendor/npm'),
        ))->current();

        self::assertTrue($readiness->available);
    }

    /**
     * A packaged start module without App-owned qualification stops closed at the host adapter.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAPackagedStartModuleWithoutQualificationStopsAtTheHostAdapter(): void
    {
        $root = $this->deployment();

        $readiness = (new PinnedStudioContextualAuthoringAvailability($root, null, self::locator()))->current();

        self::assertFalse($readiness->available);
        self::assertSame(StudioContextualAuthoringFallbackReason::HostAdapterUnavailable, $readiness->reason);
        self::assertSame('host-adapter-unavailable', $readiness->toArray()['reason']);
    }

    /**
     * A qualification naming any other release or evidence digest cannot open the host adapter.
     *
     * @param   array<string, string>  $drift  Qualification coordinates replaced with non-matching values.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('driftedQualifications')]
    public function testQualificationMustMatchEveryDeployedEvidenceByte(array $drift): void
    {
        $root = $this->deployment();

        $readiness = (new PinnedStudioContextualAuthoringAvailability(
            $root,
            self::qualification($root, $drift),
            self::locator(),
        ))->current();

        self::assertFalse($readiness->available);
        self::assertSame(StudioContextualAuthoringFallbackReason::HostAdapterUnavailable, $readiness->reason);
    }

    /**
     * Supply one drifted coordinate per qualification member.
     *
     * @return  iterable<string, array{array<string, string>}>  Named qualification drifts.
     *
     * @since   2.0.0
     */
    public static function driftedQualifications(): iterable
    {
        yield 'another exact release' => [['release' => '0.1.0-beta.2']];
        yield 'release-record digest' => [['releaseRecordSha256' => str_repeat('0', 64)]];
        yield 'pin-record digest' => [['pinRecordSha256' => str_repeat('0', 64)]];
        yield 'core-catalog digest' => [['coreCatalogSha256' => str_repeat('0', 64)]];
        yield 'browser-module integrity' => [[
            'browserModuleIntegrity' => 'sha256-' . str_repeat('A', 43) . '=',
        ]];
    }

    /**
     * Removing or corrupting the built start module closes the browser boundary again.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMissingStartModuleClosesTheBrowserBoundary(): void
    {
        $root = $this->deployment();
        $qualification = self::qualification($root);
        self::assertTrue(unlink($root . '/public/assets/build/js/' . self::LAUNCH_MODULE));

        $gate = new PinnedStudioContextualAuthoringAvailability($root, $qualification, self::locator());

        $readiness = $gate->current();

        self::assertFalse($readiness->available);
        self::assertSame(StudioContextualAuthoringFallbackReason::BrowserRuntimeUnavailable, $readiness->reason);
    }

    /**
     * Drifted App release, pin, or catalog evidence fails at the protocol boundary even when qualified.
     *
     * @param   Closure  $drift  Mutation applied to one otherwise fully qualified deployment root.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('driftedDeployments')]
    public function testDriftedAppDeploymentEvidenceFailsAtTheProtocolBoundary(Closure $drift): void
    {
        $root = $this->deployment();
        $drift($root);

        $readiness = (new PinnedStudioContextualAuthoringAvailability(
            $root,
            self::qualification($root),
            self::locator(),
        ))->current();

        self::assertFalse($readiness->available);
        self::assertSame(StudioContextualAuthoringFallbackReason::ProtocolUnavailable, $readiness->reason);
    }

    /**
     * Supply one deployment mutation per App pin refusal.
     *
     * @return  iterable<string, array{Closure}>  Named deployment drifts.
     *
     * @since   2.0.0
     */
    public static function driftedDeployments(): iterable
    {
        $release = static fn (Closure $mutate): Closure => static function (string $root) use ($mutate): void {
            self::rewriteJson($root . '/resources/studio-contract/studio-release.json', $mutate);
        };
        $pin = static fn (Closure $mutate): Closure => static function (string $root) use ($mutate): void {
            self::rewriteJson($root . '/resources/studio-contract/PIN.json', $mutate);
        };
        $catalog = static fn (Closure $mutate): Closure => static function (string $root) use ($mutate): void {
            self::rewriteJson($root . '/resources/studio-contract/core-catalog.json', $mutate);
        };

        yield 'release record not JSON' => [static function (string $root): void {
            file_put_contents($root . '/resources/studio-contract/studio-release.json', '{"kind": "studio-release",');
        }];
        yield 'release record list-shaped' => [static function (string $root): void {
            file_put_contents($root . '/resources/studio-contract/studio-release.json', '["studio-release"]');
        }];
        yield 'pin record keyed by a number' => [static function (string $root): void {
            file_put_contents($root . '/resources/studio-contract/PIN.json', '{"1": "kumwe-studio"}');
        }];
        yield 'release packages listed instead of keyed' => [$release(static function (array $record): array {
            $record['packages'] = array_values($record['packages']);

            return $record;
        })];
        yield 'release profiles keyed instead of listed' => [$release(static function (array $record): array {
            $record['claimedProfiles'] = ['contextual' => 'kumwe/contextual-authoring'];

            return $record;
        })];
        yield 'release package version not a string' => [$release(static function (array $record): array {
            $record['packages']['@kumwe/studio'] = 1;

            return $record;
        })];
        yield 'release contract version drifted' => [$release(static function (array $record): array {
            $record['contractVersion'] = '0.2-draft';

            return $record;
        })];
        yield 'release bytes reformatted' => [static function (string $root): void {
            $path = $root . '/resources/studio-contract/studio-release.json';
            $before = (string) file_get_contents($path);
            self::rewriteJson($path, static fn (array $record): array => $record);
            self::assertNotSame($before, file_get_contents($path));
        }];
        yield 'pin release-record digest drifted' => [$pin(static function (array $record): array {
            $record['release_record']['sha256'] = str_repeat('0', 64);

            return $record;
        })];
        yield 'pin binding another record file' => [$pin(static function (array $record): array {
            $record['release_record']['file'] = 'PIN.json';

            return $record;
        })];
        yield 'pin registry over plain HTTP' => [$pin(static function (array $record): array {
            $record['registry'] = 'http://registry.npmjs.org';

            return $record;
        })];
        yield 'pin registry missing' => [$pin(static function (array $record): array {
            unset($record['registry']);

            return $record;
        })];
        yield 'pin package family incomplete' => [$pin(static function (array $record): array {
            unset($record['pinned']['@kumwe/studio-testkit']);

            return $record;
        })];
        yield 'pin package family expanded' => [$pin(static function (array $record): array {
            $record['pinned']['@kumwe/studio-extra'] = $record['pinned']['@kumwe/studio'];

            return $record;
        })];
        yield 'pin package version drifted' => [$pin(static function (array $record): array {
            $record['pinned']['@kumwe/studio']['version'] = '0.1.0-beta.2';

            return $record;
        })];
        yield 'pin tarball from another registry' => [$pin(static function (array $record): array {
            $record['pinned']['@kumwe/studio']['tarball']
                = 'https://mirror.example/@kumwe/studio/-/studio-0.1.0-beta.3.tgz';

            return $record;
        })];
        yield 'pin tarball digest uppercased' => [$pin(static function (array $record): array {
            $digest = $record['pinned']['@kumwe/studio']['npm_tarball_sha256'];
            $record['pinned']['@kumwe/studio']['npm_tarball_sha256'] = strtoupper($digest);

            return $record;
        })];
        yield 'pin integrity differing from Producer provenance' => [$pin(static function (array $record): array {
            $record['pinned']['@kumwe/studio-core']['integrity'] = 'sha512-' . str_repeat('A', 86) . '==';

            return $record;
        })];
        yield 'pin integrity missing' => [$pin(static function (array $record): array {
            unset($record['pinned']['@kumwe/studio-core']['integrity']);

            return $record;
        })];
        yield 'vendored package directory present' => [static function (string $root): void {
            self::assertTrue(mkdir($root . '/resources/studio-contract/packages'));
        }];
        yield 'vendored tarball present' => [static function (string $root): void {
            self::assertTrue(mkdir($root . '/resources/studio-contract/packages'));
            file_put_contents($root . '/resources/studio-contract/packages/kumwe-studio-0.1.0-beta.3.tgz', 'bytes');
        }];
        yield 'core catalog missing' => [static function (string $root): void {
            self::assertTrue(unlink($root . '/resources/studio-contract/core-catalog.json'));
        }];
        yield 'core catalog for another release' => [$catalog(static function (array $record): array {
            $record['release'] = '0.1.0-beta.4';

            return $record;
        })];
        yield 'core catalog without blocks' => [$catalog(static function (array $record): array {
            $record['blocks'] = [];

            return $record;
        })];
    }

    /**
     * Build one temporary App deployment mirroring the real pin, catalog and a built start module.
     *
     * @return  string  Absolute temporary deployment root.
     *
     * @since   2.0.0
     */
    private function deployment(): string
    {
        $app = dirname(__DIR__, 5);
        $root = sys_get_temp_dir() . '/kumwe-studio-deployment-' . bin2hex(random_bytes(8));
        $this->deployments[] = $root;
        $contract = $root . '/resources/studio-contract';
        $build = $root . '/public/assets/build';
        foreach ([$contract, $build . '/.vite', $build . '/js'] as $directory) {
            self::assertTrue(mkdir($directory, 0755, true));
        }
        foreach (['studio-release.json', 'PIN.json', 'core-catalog.json'] as $record) {
            self::assertTrue(copy($app . '/resources/studio-contract/' . $record, $contract . '/' . $record));
        }
        self::writeJson($build . '/.vite/manifest.json', [
            PinnedStudioContextualAuthoringAvailability::LAUNCH_MODULE_ENTRY => [
                'file' => 'js/' . self::LAUNCH_MODULE,
                'name' => 'studio-launch',
                'src' => PinnedStudioContextualAuthoringAvailability::LAUNCH_MODULE_ENTRY,
                'isDynamicEntry' => true,
            ],
        ]);
        file_put_contents($build . '/js/' . self::LAUNCH_MODULE, "export const setupStudioLaunch = () => 'launch';\n");

        return $root;
    }

    /**
     * Qualify one temporary deployment from its exact current evidence bytes, optionally drifting members.
     *
     * @param   string                 $root   Temporary deployment root.
     * @param   array<string, string>  $drift  Qualification members replaced after digesting the deployment.
     *
     * @return  StudioContextualAuthoringQualification  App-owned qualification of that deployment.
     *
     * @since   2.0.0
     */
    private static function qualification(string $root, array $drift = []): StudioContextualAuthoringQualification
    {
        $contract = $root . '/resources/studio-contract';
        $record = json_decode((string) file_get_contents($contract . '/studio-release.json'), true);
        $digest = static fn (string $path): string => is_file($path)
            ? (string) hash_file('sha256', $path)
            : str_repeat('0', 64);
        $coordinates = [
            'release' => is_array($record) && is_string($record['release'] ?? null) ? $record['release'] : '0.0.0',
            'releaseRecordSha256' => $digest($contract . '/studio-release.json'),
            'pinRecordSha256' => $digest($contract . '/PIN.json'),
            'coreCatalogSha256' => $digest($contract . '/core-catalog.json'),
            'browserModuleIntegrity' => StudioContractResources::browserAsset('browser-module')->integrity(),
        ];

        return new StudioContextualAuthoringQualification(...array_replace($coordinates, $drift));
    }

    /**
     * The public registry CDN locator every enabling case uses.
     *
     * @return  StudioBrowserAssetLocator  npm package layout at the public CDN.
     *
     * @since   2.0.0
     */
    private static function locator(): StudioBrowserAssetLocator
    {
        return StudioBrowserAssetLocator::npmPackages('https://cdn.jsdelivr.net/npm');
    }

    /**
     * Decode one object-shaped JSON evidence file.
     *
     * @param   string  $path  Absolute JSON path.
     *
     * @return  array<string, mixed>  Decoded document.
     *
     * @since   2.0.0
     */
    private static function json(string $path): array
    {
        $document = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);

        return $document;
    }

    /**
     * Write one JSON evidence file.
     *
     * @param   string                $path      Absolute JSON path.
     * @param   array<string, mixed>  $document  Document to encode.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function writeJson(string $path, array $document): void
    {
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        self::assertIsInt(file_put_contents($path, json_encode($document, $flags) . "\n"));
    }

    /**
     * Rewrite one JSON evidence file through a mutation of its decoded document.
     *
     * @param   string   $path    Absolute JSON path.
     * @param   Closure  $mutate  Mutation receiving and returning the decoded document.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function rewriteJson(string $path, Closure $mutate): void
    {
        self::writeJson($path, $mutate(self::json($path)));
    }

    /**
     * Delete one temporary deployment tree without following links.
     *
     * @param   string  $path  Absolute file or directory path.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function removeTree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeTree($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (is_file($path) || is_link($path)) {
            unlink($path);
        }
    }
}
