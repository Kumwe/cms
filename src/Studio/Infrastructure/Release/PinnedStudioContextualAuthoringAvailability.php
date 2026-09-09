<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Infrastructure\Release;

use JsonException;
use Kumwe\App\Studio\Application\Authoring\StudioContextualAuthoringAvailability;
use Kumwe\App\Studio\Application\Authoring\StudioContextualAuthoringFallbackReason;
use Kumwe\App\Studio\Application\Authoring\StudioContextualAuthoringReadiness;
use Kumwe\App\Studio\Application\Release\StudioCoreCatalog;
use Kumwe\Producer\Deployment\StudioBrowserAssetLocator;
use Kumwe\Producer\Schema\StudioContractRelease;
use Kumwe\Producer\Schema\StudioContractResources;
use Kumwe\Producer\Schema\StudioDocumentSchemaRegistry;
use Kumwe\Producer\Wire\OperationRegistry;
use Throwable;

/**
 * Fail-closed contextual-authoring gate over one exact App deployment.
 *
 * Producer is the sole authority for the pinned Studio schemas, the host-operation registry and the
 * browser-asset manifest. App proves only the deployment evidence it owns: the coordinated release
 * record, the registry pin of the eight npm packages, the materialized first-party catalog, the
 * configured browser-asset origin, the compiled start module, and an explicit reviewed host
 * qualification. A release profile claim cannot enable contextual authoring when Producer does not
 * publish every required document kind, operation and browser asset.
 *
 * @since  2.0.0
 */
final readonly class PinnedStudioContextualAuthoringAvailability implements StudioContextualAuthoringAvailability
{
    /**
     * Public Studio packages that must belong to one exact release family.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array STUDIO_PACKAGES = [
        '@kumwe/studio',
        '@kumwe/studio-core',
        '@kumwe/studio-media',
        '@kumwe/studio-preview',
        '@kumwe/studio-protocol',
        '@kumwe/studio-renderer-web',
        '@kumwe/studio-rich-text',
        '@kumwe/studio-testkit',
    ];

    /**
     * Definition-only contextual kinds and the pinned definition each must interpret.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const array REQUIRED_DEFINITIONS = [
        'authoring-target' => 'declaration',
        'authoring-session' => 'snapshot',
        'authoring-save' => 'saveResult',
    ];

    /**
     * Whole-document contextual kinds the pinned registry must admit.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array REQUIRED_DOCUMENT_KINDS = [
        'reusable-content-type',
        'studio-config',
        'studio-deployment',
        'host-capabilities',
    ];

    /**
     * Exact capability-to-route pairs required by Studio's contextual Authoring port.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const array REQUIRED_OPERATIONS = [
        'studio.operation/authoring.resolve-target' => 'authoring/resolve-target',
        'studio.operation/authoring.list-types' => 'authoring/list-types',
        'studio.operation/authoring.start' => 'authoring/start',
        'studio.operation/authoring.plan-save' => 'authoring/plan-save',
        'studio.operation/authoring.save-item' => 'authoring/save-item',
        'studio.operation/authoring.save-new-type-version' => 'authoring/save-new-type-version',
        'studio.operation/authoring.save-as-new-type' => 'authoring/save-as-new-type',
    ];

    /**
     * Browser asset roles the pinned manifest must resolve at the configured origin.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array BROWSER_ASSET_ROLES = ['browser-module', 'enhancement-runtime'];

    /**
     * Vite source key of the App start module that imports the pinned browser module and mounts it.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string LAUNCH_MODULE_ENTRY = 'assets/administrator/components/studio-launch.ts';

    /**
     * Point the gate at one exact App deployment.
     *
     * @param  string                                   $root           Absolute App root holding its release
     *         pin, materialized catalog and built assets.
     * @param  ?StudioContextualAuthoringQualification  $qualification  App-owned exact host qualification.
     * @param  ?StudioBrowserAssetLocator               $locator        Configured browser-asset origin, or null
     *         while no origin is configured.
     *
     * @since  2.0.0
     */
    public function __construct(
        private string $root,
        private ?StudioContextualAuthoringQualification $qualification,
        private ?StudioBrowserAssetLocator $locator = null,
    ) {
    }

    /**
     * Require exact protocol, browser and PHP host evidence in that order.
     *
     * @return  StudioContextualAuthoringReadiness  First failed boundary, or qualified readiness.
     *
     * @since   2.0.0
     */
    public function current(): StudioContextualAuthoringReadiness
    {
        if (!$this->protocolAvailable()) {
            return StudioContextualAuthoringReadiness::fallback(
                StudioContextualAuthoringFallbackReason::ProtocolUnavailable,
            );
        }
        if (!$this->browserRuntimeAvailable()) {
            return StudioContextualAuthoringReadiness::fallback(
                StudioContextualAuthoringFallbackReason::BrowserRuntimeUnavailable,
            );
        }
        if (!$this->hostImplementationQualified()) {
            return StudioContextualAuthoringReadiness::fallback(
                StudioContextualAuthoringFallbackReason::HostAdapterUnavailable,
            );
        }

        return StudioContextualAuthoringReadiness::available();
    }

    /**
     * Require App-owned qualification to match the exact deployed evidence bytes.
     *
     * @return  bool  True only for the reviewed release, pin, catalog and browser-module coordinates.
     *
     * @since   2.0.0
     */
    private function hostImplementationQualified(): bool
    {
        if ($this->qualification === null) {
            return false;
        }

        $contractRoot = $this->root . '/resources/studio-contract';
        $release = $this->decode($contractRoot . '/studio-release.json');
        if ($release === null || ($release['release'] ?? null) !== $this->qualification->release) {
            return false;
        }

        foreach (
            [
                $contractRoot . '/studio-release.json' => $this->qualification->releaseRecordSha256,
                $contractRoot . '/PIN.json' => $this->qualification->pinRecordSha256,
                $contractRoot . '/core-catalog.json' => $this->qualification->coreCatalogSha256,
            ] as $path => $expected
        ) {
            $actual = is_file($path) ? hash_file('sha256', $path) : false;
            if (!is_string($actual) || !hash_equals($expected, $actual)) {
                return false;
            }
        }

        try {
            $integrity = StudioContractResources::browserAsset('browser-module')->integrity();
        } catch (Throwable) {
            return false;
        }

        return hash_equals($this->qualification->browserModuleIntegrity, $integrity);
    }

    /**
     * Verify Producer's exact schema and operation authorities against App's deployment pin.
     *
     * @return  bool  True only when one coordinated release publishes every contextual member.
     *
     * @since   2.0.0
     */
    private function protocolAvailable(): bool
    {
        try {
            $release = StudioContractResources::releaseRecord();
            $registry = StudioDocumentSchemaRegistry::fromVendoredCorpus();
        } catch (Throwable) {
            return false;
        }
        if (!$this->appPinMatches($release) || !$this->coreCatalogMatches($release)) {
            return false;
        }
        try {
            // Every contextual kind is proven reachable through the registry's own pinned interpreter.
            foreach (self::REQUIRED_DEFINITIONS as $kind => $definition) {
                $registry->validateDefinition($kind, $definition, null);
            }
            foreach (self::REQUIRED_DOCUMENT_KINDS as $kind) {
                $registry->validate($kind, null);
            }
        } catch (Throwable) {
            return false;
        }
        foreach (self::REQUIRED_OPERATIONS as $capability => $route) {
            if (
                !OperationRegistry::isCapability($capability)
                || !OperationRegistry::isRoute($route)
                || OperationRegistry::byCapability($capability)->route !== $route
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Bind App-owned release and registry-pin records to Producer's immutable release coordinates.
     *
     * The pin no longer carries package bytes: it names the registry, the exact version, the official
     * tarball URL, the tarball SHA-256 and the SHA-512 integrity of every package, and each of those
     * must agree with the provenance Producer verified for the same release. A leftover package
     * directory is refused so a vendored substitute can never shadow the registry resolution.
     *
     * @param   StudioContractRelease  $installed  Producer's fully verified coordinated release.
     *
     * @return  bool  True only when App pins exactly the same release and eight package coordinates.
     *
     * @since   2.0.0
     */
    private function appPinMatches(StudioContractRelease $installed): bool
    {
        $contractRoot = $this->root . '/resources/studio-contract';
        $releasePath = $contractRoot . '/studio-release.json';
        $releaseBytes = is_file($releasePath) ? file_get_contents($releasePath) : false;
        $release = $this->decode($releasePath);
        $pin = $this->decode($contractRoot . '/PIN.json');
        if (!is_string($releaseBytes) || $release === null || $pin === null) {
            return false;
        }

        $packages = $release['packages'] ?? null;
        $profiles = $release['claimedProfiles'] ?? null;
        if (!is_array($packages) || array_is_list($packages) || !is_array($profiles) || !array_is_list($profiles)) {
            return false;
        }
        $releasePackages = [];
        foreach ($packages as $package => $version) {
            if (!is_string($package) || !is_string($version)) {
                return false;
            }
            $releasePackages[$package] = $version;
        }
        ksort($releasePackages);
        $installedPackages = $installed->packages();
        ksort($installedPackages);
        if (
            ($release['kind'] ?? null) !== 'studio-release'
            || ($release['contractVersion'] ?? null) !== $installed->contractVersion()
            || ($release['release'] ?? null) !== $installed->release()
            || ($release['protocolVersion'] ?? null) !== $installed->protocolVersion()
            || ($release['corpusManifestDigest'] ?? null) !== $installed->corpusManifestDigest()
            || $profiles !== $installed->claimedProfiles()
            || $releasePackages !== $installedPackages
            || !hash_equals($installed->recordSha256(), hash('sha256', $releaseBytes))
        ) {
            return false;
        }

        $releasePin = $pin['release_record'] ?? null;
        $registry = $pin['registry'] ?? null;
        $pinned = $pin['pinned'] ?? null;
        if (
            !is_array($releasePin)
            || ($releasePin['file'] ?? null) !== 'studio-release.json'
            || ($releasePin['release'] ?? null) !== $installed->release()
            || ($releasePin['sha256'] ?? null) !== $installed->recordSha256()
            || !is_string($registry)
            || preg_match('#^https://[a-z0-9.-]+$#D', $registry) !== 1
            || !is_array($pinned)
            || array_is_list($pinned)
        ) {
            return false;
        }

        $expectedPackages = self::STUDIO_PACKAGES;
        sort($expectedPackages);
        $pinnedNames = array_keys($pinned);
        sort($pinnedNames);
        $releaseNames = array_keys($installedPackages);
        sort($releaseNames);
        if ($pinnedNames !== $expectedPackages || $releaseNames !== $expectedPackages) {
            return false;
        }

        $integrities = $installed->packageIntegrities();
        foreach (self::STUDIO_PACKAGES as $package) {
            $packagePin = $pinned[$package] ?? null;
            $version = $installedPackages[$package] ?? null;
            $tarball = is_array($packagePin) ? ($packagePin['tarball'] ?? null) : null;
            $digest = is_array($packagePin) ? ($packagePin['npm_tarball_sha256'] ?? null) : null;
            $integrity = is_array($packagePin) ? ($packagePin['integrity'] ?? null) : null;
            $unscoped = substr($package, strlen('@kumwe/'));
            if (
                !is_array($packagePin)
                || !is_string($version)
                || ($packagePin['version'] ?? null) !== $version
                || !is_string($tarball)
                || $tarball !== sprintf('%s/%s/-/%s-%s.tgz', $registry, $package, $unscoped, $version)
                || !is_string($digest)
                || preg_match('/^[0-9a-f]{64}$/D', $digest) !== 1
                || !is_string($integrity)
                || preg_match('#^sha512-[A-Za-z0-9+/]{86}==$#D', $integrity) !== 1
                || !is_string($integrities[$package] ?? null)
                || !hash_equals($integrities[$package], $integrity)
            ) {
                return false;
            }
        }

        return !file_exists($contractRoot . '/packages');
    }

    /**
     * Require the materialized first-party catalog to belong to the installed release.
     *
     * @param   StudioContractRelease  $installed  Producer's fully verified coordinated release.
     *
     * @return  bool  True when the catalog record decodes for exactly this release.
     *
     * @since   2.0.0
     */
    private function coreCatalogMatches(StudioContractRelease $installed): bool
    {
        try {
            StudioCoreCatalog::fromFile(
                $this->root . '/resources/studio-contract/core-catalog.json',
                $installed->release(),
            );
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Verify the configured origin resolves both pinned browser assets and the start module is built.
     *
     * @return  bool  True only when every browser artifact the mount needs can be addressed.
     *
     * @since   2.0.0
     */
    private function browserRuntimeAvailable(): bool
    {
        if ($this->locator === null) {
            return false;
        }
        try {
            foreach (self::BROWSER_ASSET_ROLES as $role) {
                $this->locator->locate($role);
            }
        } catch (Throwable) {
            return false;
        }

        return $this->launchModulePath() !== null;
    }

    /**
     * Resolve the packaged start module without accepting an absolute or traversing path.
     *
     * @return  ?string  Existing absolute module path, or null when the browser evidence is unsafe.
     *
     * @since   2.0.0
     */
    private function launchModulePath(): ?string
    {
        $manifest = $this->decode($this->root . '/public/assets/build/.vite/manifest.json');
        $entry = is_array($manifest) ? ($manifest[self::LAUNCH_MODULE_ENTRY] ?? null) : null;
        $file = is_array($entry) ? ($entry['file'] ?? null) : null;
        if (!is_string($file) || preg_match('#^js/[A-Za-z0-9._-]+\.js$#D', $file) !== 1) {
            return null;
        }

        $path = $this->root . '/public/assets/build/' . $file;

        return is_file($path) ? $path : null;
    }

    /**
     * Decode one deployment-owned JSON document without letting malformed evidence enable a feature.
     *
     * @param   string  $path  Absolute JSON path.
     *
     * @return  array<string, mixed>|null  Object-shaped JSON, or null when absent or malformed.
     *
     * @since   2.0.0
     */
    private function decode(string $path): ?array
    {
        $json = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($json)) {
            return null;
        }
        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            return null;
        }
        $document = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                return null;
            }
            $document[$key] = $value;
        }

        return $document;
    }
}
