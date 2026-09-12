<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use Kumwe\Transaction\Testing\ImmediateTransactionManager;
use DateTimeImmutable;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\App\Extension\Application\Trust\ExtensionArtifactVerifier;
use Kumwe\App\Extension\Application\Trust\TrustRuntimeInvalidator;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Application\Trust\TrustStoreRepository;
use Kumwe\App\Extension\Runtime\TrustEnforcingStudioPreviewBlockRenderer;
use Kumwe\Extension\Manifest\ExtensionManifest;
use Kumwe\Extension\Package\PublicKeyPackageSignatureVerifier;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlockRenderer;
use Psr\Clock\ClockInterface;
use stdClass;

/**
 * Fences unit-test preview renderers behind the exact live trust wrapper the extension registrar installs.
 *
 * `StudioBlockRendererRuntime` refuses any extension-owned executable that is not
 * `TrustEnforcingStudioPreviewBlockRenderer`, so a unit test that expects an extension block to render
 * must register the same wrapper production does. The fence built here re-establishes trust through a
 * real `TrustStore` whose repository, verifier and artifact doubles hold one verified release for the
 * owning extension, so `isAvailable()` and `render()` pass for exactly that owner and nothing else.
 *
 * @since  2.0.0
 */
trait TrustFencedStudioPreviewRenderers
{
    /**
     * Wrap one SDK implementation in live trust enforcement that admits the named extension.
     *
     * @param   StudioPreviewBlockRenderer  $inner      Owner-local SDK implementation under test.
     * @param   string                      $extension  Canonical `vendor/name` owner of the implementation.
     *
     * @return  TrustEnforcingStudioPreviewBlockRenderer  Exact production wrapper around the implementation.
     *
     * @since   2.0.0
     */
    protected static function trustFencedPreviewRenderer(
        StudioPreviewBlockRenderer $inner,
        string $extension,
    ): TrustEnforcingStudioPreviewBlockRenderer {
        $manifest = json_encode([
            'schema' => 1,
            'name' => $extension,
            'type' => 'plugin',
            'version' => '1.0.0',
            'provider' => 'Acme\\Fixture\\Provider',
            'autoload' => ['psr-4' => ['Acme\\Fixture\\' => 'src/']],
            'requires' => ['kumwe' => '^2.0.0', 'php' => '^8.5.0'],
            'dependencies' => [],
            'migrations' => [],
            'configuration' => new stdClass(),
            'permissions' => [],
            'routes' => [],
            'events' => [],
            'assets' => [],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $parsed = ExtensionManifest::fromJson($manifest);
        $release = [
            'identifier' => $extension,
            'installed_version' => '1.0.0',
            'service_provider' => 'Acme\\Fixture\\Provider',
            'extension_type' => 'plugin',
            'runtime_path' => $extension . '/1.0.0',
            'manifest' => $manifest,
            'package_sha256' => str_repeat('a', 64),
            'artifact_sha256' => str_repeat('a', 64),
            'deployed_tree_sha256' => str_repeat('b', 64),
            'signing_key_id' => 'vendor.fixture',
            'signature_base64' => base64_encode(str_repeat('s', SODIUM_CRYPTO_SIGN_BYTES)),
            'trust_state' => 'verified',
        ];
        $runtimeEntry = [
            'identifier' => $extension,
            'version' => '1.0.0',
            'provider' => 'Acme\\Fixture\\Provider',
            'type' => 'plugin',
            'root' => $extension . '/1.0.0',
            'autoload' => $parsed->autoload(),
            'signing_key_id' => 'vendor.fixture',
            'artifact_sha256' => str_repeat('a', 64),
            'deployed_tree_sha256' => str_repeat('b', 64),
        ];

        $repository = self::createStub(TrustStoreRepository::class);
        $repository->method('synchronizedLifecycle')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $repository->method('activeExtensions')->willReturn([$extension]);
        $repository->method('installedRelease')->willReturn($release);
        $repository->method('usable')->willReturn([
            'public_key_base64' => base64_encode(str_repeat('p', SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)),
        ]);
        $verifier = self::createStub(PublicKeyPackageSignatureVerifier::class);
        $verifier->method('verify')->willReturn(true);
        $clock = self::createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-09-02T08:00:00+00:00'));
        $execution = self::createStub(ExtensionExecutionGate::class);
        $execution->method('isCurrent')->willReturn(true);
        $trust = new TrustStore(
            $repository,
            $verifier,
            self::createStub(ExtensionArtifactVerifier::class),
            self::createStub(TrustRuntimeInvalidator::class),
            new ImmediateTransactionManager(),
            self::createStub(AuditRecorder::class),
            $clock,
            AuthorizationContext::gateway(),
        );

        return new TrustEnforcingStudioPreviewBlockRenderer($inner, $trust, $execution, $extension, $runtimeEntry);
    }
}
