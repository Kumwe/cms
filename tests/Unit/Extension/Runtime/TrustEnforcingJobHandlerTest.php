<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Runtime;

use DateTimeImmutable;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\App\Extension\Application\Trust\ExtensionArtifactVerifier;
use Kumwe\App\Extension\Application\Trust\RuntimePublicationMismatch;
use Kumwe\App\Extension\Application\Trust\TrustRuntimeInvalidator;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Application\Trust\TrustStoreRepository;
use Kumwe\App\Extension\Application\Trust\UntrustedPackage;
use Kumwe\App\Extension\Runtime\TrustEnforcingJobHandler;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Extension\Package\PublicKeyPackageSignatureVerifier;
use Kumwe\Extension\Spi\Application\Automation\JobHandler;
use Kumwe\Extension\Spi\Application\ExecutionContext;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\JobContributionDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;

#[CoversClass(TrustEnforcingJobHandler::class)]
#[UsesClass(TrustStore::class)]
/**
 * Proves a contributed job implementation runs only behind the live trust and boot-generation fence.
 *
 * The fence is the one Studio preview renderers already carry: the generation is checked before the
 * lifecycle lock is taken and again inside it, package trust is re-run against the exact signed runtime
 * entry, and the SDK implementation is reached only when every check passed inside that lock.
 *
 * @since  2.0.0
 */
final class TrustEnforcingJobHandlerTest extends TestCase
{
    /**
     * Canonical owner of the probe package every fixture describes.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string EXTENSION = 'acme/probe';

    /**
     * Provider class the probe manifest and release record both name.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string PROVIDER = 'Acme\\Probe\\Provider';

    /**
     * Signing key the probe release was admitted under.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string KEY_ID = 'acme.probe.signing';

    /**
     * Prove a current, trusted generation hands the identical invocation to the delegate inside the lock.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACurrentTrustedGenerationDelegatesTheExactInvocationInsideTheLifecycleLock(): void
    {
        $definition = self::definition();
        $payload = ['site_identifier' => 'default'];
        $context = $this->createStub(ExecutionContext::class);
        $execution = $this->createMock(ExtensionExecutionGate::class);
        $execution->expects(self::exactly(2))->method('assertCurrent');
        $locked = false;
        $repository = $this->observedRepository([self::EXTENSION], self::release());
        $repository->expects(self::once())->method('synchronizedLifecycle')->willReturnCallback(
            static function (callable $operation) use (&$locked): mixed {
                $locked = true;
                try {
                    return $operation();
                } finally {
                    $locked = false;
                }
            },
        );
        $inner = $this->createMock(JobHandler::class);
        $inner->expects(self::once())->method('handle')->with(
            self::identicalTo($definition),
            self::identicalTo($payload),
            self::identicalTo($context),
        )->willReturnCallback(static function () use (&$locked): void {
            self::assertTrue($locked, 'The implementation must run inside the lifecycle lock.');
        });
        $handler = $this->handler($inner, $repository, $execution);

        $handler->handle($definition, $payload, $context);
    }

    /**
     * Prove a stale generation is refused before the lifecycle lock is taken and never reaches the delegate.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAStaleGenerationIsRefusedBeforeTheLifecycleLockIsTaken(): void
    {
        $execution = $this->createStub(ExtensionExecutionGate::class);
        $execution->method('assertCurrent')->willThrowException(new RuntimeException('stale generation'));
        $repository = $this->observedRepository([self::EXTENSION], self::release());
        $repository->expects(self::never())->method('synchronizedLifecycle');
        $inner = $this->createMock(JobHandler::class);
        $inner->expects(self::never())->method('handle');
        $handler = $this->handler($inner, $repository, $execution);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('stale generation');

        $handler->handle(self::definition(), [], $this->createStub(ExecutionContext::class));
    }

    /**
     * Prove a generation superseded between the first check and the lock is refused inside the lock.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAGenerationSupersededInsideTheLockNeverReachesTheDelegate(): void
    {
        $checks = 0;
        $execution = $this->createMock(ExtensionExecutionGate::class);
        $execution->expects(self::exactly(2))->method('assertCurrent')->willReturnCallback(
            static function () use (&$checks): void {
                if (++$checks === 2) {
                    throw new RuntimeException('superseded inside the lock');
                }
            },
        );
        $repository = $this->observedRepository([self::EXTENSION], self::release());
        $repository->expects(self::once())->method('synchronizedLifecycle')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $repository->expects(self::never())->method('installedRelease');
        $inner = $this->createMock(JobHandler::class);
        $inner->expects(self::never())->method('handle');
        $handler = $this->handler($inner, $repository, $execution);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('superseded inside the lock');

        $handler->handle(self::definition(), [], $this->createStub(ExecutionContext::class));
    }

    /**
     * Prove a release that no longer verifies is quarantined and refused before the delegate runs.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUntrustedPackageIsQuarantinedAndNeverReachesTheDelegate(): void
    {
        $repository = $this->observedRepository([self::EXTENSION], self::release('revoked'));
        $repository->method('synchronizedLifecycle')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $repository->expects(self::once())->method('quarantineExtension')
            ->with(self::EXTENSION)
            ->willReturn(true);
        $inner = $this->createMock(JobHandler::class);
        $inner->expects(self::never())->method('handle');
        $handler = $this->handler($inner, $repository, $this->createStub(ExtensionExecutionGate::class));

        $this->expectException(UntrustedPackage::class);

        $handler->handle(self::definition(), [], $this->createStub(ExecutionContext::class));
    }

    /**
     * Prove a compiled entry for a package that is no longer active is refused without quarantine.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAPublicationForAnInactivePackageIsRefusedWithoutQuarantine(): void
    {
        $repository = $this->observedRepository([], self::release());
        $repository->method('synchronizedLifecycle')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $repository->expects(self::never())->method('quarantineExtension');
        $inner = $this->createMock(JobHandler::class);
        $inner->expects(self::never())->method('handle');
        $handler = $this->handler($inner, $repository, $this->createStub(ExtensionExecutionGate::class));

        $this->expectException(RuntimePublicationMismatch::class);

        $handler->handle(self::definition(), [], $this->createStub(ExecutionContext::class));
    }

    /**
     * Prove availability holds only while both the generation and the package remain trusted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAvailabilityHoldsOnlyWhileTheGenerationAndPackageRemainTrusted(): void
    {
        $execution = $this->createStub(ExtensionExecutionGate::class);
        $execution->method('isCurrent')->willReturn(true);
        $inner = $this->createMock(JobHandler::class);
        $inner->expects(self::never())->method('handle');

        $trusted = $this->repository([self::EXTENSION], self::release());
        $trusted->method('synchronizedLifecycle')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        self::assertTrue($this->handler($inner, $trusted, $execution)->isAvailable());

        $untrusted = $this->repository([self::EXTENSION], self::release('revoked'));
        $untrusted->method('synchronizedLifecycle')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $untrusted->method('quarantineExtension')->willReturn(true);
        self::assertFalse($this->handler($inner, $untrusted, $execution)->isAvailable());
    }

    /**
     * Prove a stale generation reports unavailable without taking the lifecycle lock.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAvailabilityIsDeniedWithoutTakingTheLockOnceTheGenerationIsStale(): void
    {
        $execution = $this->createStub(ExtensionExecutionGate::class);
        $execution->method('isCurrent')->willReturn(false);
        $repository = $this->observedRepository([self::EXTENSION], self::release());
        $repository->expects(self::never())->method('synchronizedLifecycle');
        $inner = $this->createMock(JobHandler::class);
        $inner->expects(self::never())->method('handle');

        self::assertFalse($this->handler($inner, $repository, $execution)->isAvailable());
    }

    /**
     * Wrap one delegate in the fence under a real trust boundary backed by the given repository double.
     *
     * @param   JobHandler              $inner       Delegate the fence guards.
     * @param   TrustStoreRepository    $repository  Repository double the trust boundary consults.
     * @param   ExtensionExecutionGate  $execution   Boot-generation gate double.
     *
     * @return  TrustEnforcingJobHandler  Fenced handler bound to the probe package entry.
     *
     * @since   2.0.0
     */
    private function handler(
        JobHandler $inner,
        TrustStoreRepository $repository,
        ExtensionExecutionGate $execution,
    ): TrustEnforcingJobHandler {
        return new TrustEnforcingJobHandler(
            $inner,
            $this->trustStore($repository),
            $execution,
            self::EXTENSION,
            self::runtimeEntry(),
        );
    }

    /**
     * Build the real trust boundary over a repository double, with every verifier accepting the probe.
     *
     * @param   TrustStoreRepository  $repository  Repository double holding the release inventory.
     *
     * @return  TrustStore  Trust boundary whose enforcement path runs against the double.
     *
     * @since   2.0.0
     */
    private function trustStore(TrustStoreRepository $repository): TrustStore
    {
        $verifier = $this->createStub(PublicKeyPackageSignatureVerifier::class);
        $verifier->method('verify')->willReturn(true);
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-05T08:00:00+00:00'));

        return new TrustStore(
            $repository,
            $verifier,
            $this->createStub(ExtensionArtifactVerifier::class),
            $this->createStub(TrustRuntimeInvalidator::class),
            $transactions,
            $this->createStub(AuditRecorder::class),
            $clock,
            AuthorizationContext::gateway(),
        );
    }

    /**
     * Build a repository stub reporting the given active set and installed release for the probe.
     *
     * @param   list<string>          $active   Extensions the store reports as active.
     * @param   array<string, mixed>  $release  Installed release record the store returns for the probe.
     *
     * @return  Stub&TrustStoreRepository  Repository double no test states expectations about.
     *
     * @since   2.0.0
     */
    private function repository(array $active, array $release): Stub&TrustStoreRepository
    {
        return $this->configure($this->createStub(TrustStoreRepository::class), $active, $release);
    }

    /**
     * Build a repository mock reporting the given active set and installed release for the probe.
     *
     * @param   list<string>          $active   Extensions the store reports as active.
     * @param   array<string, mixed>  $release  Installed release record the store returns for the probe.
     *
     * @return  MockObject&TrustStoreRepository  Repository double a test states lock or quarantine
     *          expectations about.
     *
     * @since   2.0.0
     */
    private function observedRepository(array $active, array $release): MockObject&TrustStoreRepository
    {
        return $this->configure($this->createMock(TrustStoreRepository::class), $active, $release);
    }

    /**
     * Stub the lock, key and release lookups the enforcement path reads on the given repository double.
     *
     * The lifecycle lock and quarantine are deliberately left unconfigured so each test can state its
     * own expectation about them.
     *
     * @template T of Stub&TrustStoreRepository
     *
     * @param   T                     $repository  Repository double to configure.
     * @param   list<string>          $active      Extensions the store reports as active.
     * @param   array<string, mixed>  $release     Installed release record the store returns for the probe.
     *
     * @return  T  The same double, configured.
     *
     * @since   2.0.0
     */
    private function configure(
        Stub&TrustStoreRepository $repository,
        array $active,
        array $release,
    ): Stub&TrustStoreRepository {
        $repository->method('lockGeneration')->willReturn(1);
        $repository->method('activeExtensions')->willReturn($active);
        $repository->method('installedRelease')->willReturn($release);
        $repository->method('usable')->willReturn([
            'public_key_base64' => base64_encode(str_repeat('k', SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)),
        ]);

        return $repository;
    }

    /**
     * Build one signed job declaration for the probe package.
     *
     * @return  JobContributionDefinition  Signed probe job declaration.
     *
     * @since   2.0.0
     */
    private static function definition(): JobContributionDefinition
    {
        return JobContributionDefinition::fromArray([
            'job_type' => 'acme.probe.summarize',
            'schema_version' => 1,
            'handler_version' => '1.0.0',
            'payload_schema' => [
                'type' => 'object',
                'required' => ['site_identifier'],
                'properties' => ['site_identifier' => ['type' => 'string']],
                'additionalProperties' => false,
            ],
            'queue' => 'acme.probe',
            'maximum_attempts' => 3,
            'installation_wide' => false,
        ]);
    }

    /**
     * Build the installed release record the probe package was admitted with.
     *
     * @param   string  $trustState  Trust state recorded on the release.
     *
     * @return  array<string, mixed>  Release record matching the compiled runtime entry.
     *
     * @since   2.0.0
     */
    private static function release(string $trustState = 'verified'): array
    {
        return [
            'identifier' => self::EXTENSION,
            'installed_version' => '1.0.0',
            'service_provider' => self::PROVIDER,
            'extension_type' => 'plugin',
            'runtime_path' => 'acme/probe/1.0.0',
            'manifest' => json_encode([
                'schema' => 1,
                'name' => self::EXTENSION,
                'type' => 'plugin',
                'version' => '1.0.0',
                'provider' => self::PROVIDER,
                'autoload' => ['psr-4' => ['Acme\\Probe\\' => 'src/']],
                'requires' => ['kumwe' => '^2.0.0', 'php' => '^8.5.0'],
                'dependencies' => [],
                'migrations' => [],
                'configuration' => new \stdClass(),
                'permissions' => [],
                'routes' => [],
                'events' => [],
                'assets' => [],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'package_sha256' => str_repeat('a', 64),
            'artifact_sha256' => str_repeat('a', 64),
            'deployed_tree_sha256' => str_repeat('b', 64),
            'signing_key_id' => self::KEY_ID,
            'signature_base64' => base64_encode(str_repeat('s', SODIUM_CRYPTO_SIGN_BYTES)),
            'trust_state' => $trustState,
        ];
    }

    /**
     * Build the exact compiled runtime entry that loaded the probe package.
     *
     * @return  array<string, mixed>  Runtime-map entry agreeing with the release record.
     *
     * @since   2.0.0
     */
    private static function runtimeEntry(): array
    {
        return [
            'identifier' => self::EXTENSION,
            'version' => '1.0.0',
            'provider' => self::PROVIDER,
            'type' => 'plugin',
            'root' => 'acme/probe/1.0.0',
            'autoload' => ['Acme\\Probe\\' => 'src/'],
            'signing_key_id' => self::KEY_ID,
            'artifact_sha256' => str_repeat('a', 64),
            'deployed_tree_sha256' => str_repeat('b', 64),
        ];
    }
}
