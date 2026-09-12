<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Demo\Infrastructure;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Demo\Infrastructure\DemoExampleExtensionInstaller;
use Kumwe\App\Extension\Application\ExtensionManager;
use Kumwe\App\Extension\Application\Trust\ExtensionArtifactVerifier;
use Kumwe\App\Extension\Application\Trust\TrustRuntimeInvalidator;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Application\Trust\TrustStoreRepository;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Extension\Package\PublicKeyPackageSignatureVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use ReflectionClass;
use RuntimeException;

/**
 * Proves the example installer discovers the shipped set and stays idempotent per identifier.
 *
 * The fresh-install path signs and installs through the real pipeline and is exercised end to end
 * against a database; what belongs here is the release contract around it — which examples exist,
 * that unknown names are refused, that an already-listed identifier is confirmed or reactivated
 * instead of repackaged, and that a refused package leaves no enabled ephemeral key behind while an
 * admitted one keeps the key that vouches for it.
 *
 * @since  2.0.0
 */
#[CoversClass(DemoExampleExtensionInstaller::class)]
final class DemoExampleExtensionInstallerTest extends TestCase
{
    /**
     * The shipped examples discover from disk in stable alphabetical order.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDiscoversTheShippedExamplesAlphabetically(): void
    {
        self::assertSame(
            [
                'announcements',
                'asset-inspection',
                'audit-listener',
                'horizon-theme',
                'minimal-administrator-template',
                'minimal-template',
            ],
            $this->installer($this->createStub(ExtensionManager::class))->available(),
        );
    }

    /**
     * An example this release does not ship is refused before any service is touched.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRefusesAnUnshippedExampleName(): void
    {
        $manager = $this->createMock(ExtensionManager::class);
        $manager->expects(self::never())->method('installed');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not shipped');

        $this->installer($manager)->install($this->context(), 'ecommerce');
    }

    /**
     * An identifier the registry lists as active is confirmed without another install.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testConfirmsAnAlreadyActiveExampleWithoutReinstalling(): void
    {
        $manager = $this->createMock(ExtensionManager::class);
        $manager->method('installed')->willReturn([
            ['identifier' => 'kumwe/announcements-example', 'status' => 'active'],
        ]);
        $manager->expects(self::never())->method('install');
        $manager->expects(self::never())->method('activate');

        $result = $this->installer($manager)->install($this->context(), 'announcements');

        self::assertSame(
            [
                'identifier' => 'kumwe/announcements-example',
                'installed' => false,
                'activated' => false,
                'contributions' => [],
            ],
            $result,
        );
    }

    /**
     * A disabled identifier is reactivated in place rather than packaged again.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReactivatesADisabledExampleWithoutRepackaging(): void
    {
        $manager = $this->createMock(ExtensionManager::class);
        $manager->method('installed')->willReturn([
            ['identifier' => 'kumwe/asset-inspection-example', 'status' => 'disabled'],
        ]);
        $manager->expects(self::never())->method('install');
        $manager->expects(self::once())->method('activate')
            ->with('kumwe/asset-inspection-example')
            ->willReturn(['identifier' => 'kumwe/asset-inspection-example', 'status' => 'active']);

        $result = $this->installer($manager)->install($this->context(), 'asset-inspection');

        self::assertSame(
            [
                'identifier' => 'kumwe/asset-inspection-example',
                'installed' => false,
                'activated' => true,
                'contributions' => [],
            ],
            $result,
        );
    }

    /**
     * A package the manager refuses leaves no enabled key: the one added for it is revoked, then the
     * refusal propagates unchanged.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRevokesTheEphemeralKeyWhenTheManagerRefusesThePackage(): void
    {
        $manager = $this->createMock(ExtensionManager::class);
        $manager->method('installed')->willReturn([]);
        $manager->expects(self::once())->method('install')
            ->willThrowException(new RuntimeException('The package requires PHP ^8.5.0.'));
        $manager->expects(self::never())->method('activate');
        $keyId = null;
        $repository = $this->trustRepository();
        $repository->expects(self::once())->method('add')
            ->willReturnCallback(static function (array $key) use (&$keyId): void {
                self::assertIsString($key['key_id']);
                self::assertStringStartsWith('demo.announcements.', $key['key_id']);
                self::assertSame('kumwe', $key['vendor_namespace']);
                self::assertSame('announcements-example', $key['extension_pattern']);
                self::assertTrue($key['enabled']);
                $keyId = $key['key_id'];
            });
        $repository->method('extensionsRequiringKey')->willReturn([]);
        $repository->expects(self::once())->method('revoke')
            ->with(
                self::callback(static function (string $revoked) use (&$keyId): bool {
                    self::assertSame($keyId, $revoked);

                    return true;
                }),
                AuthorizationContext::SUBJECT,
                self::stringContains('announcements example was refused at install'),
                new DateTimeImmutable('2026-08-12T00:00:00+00:00'),
            );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The package requires PHP ^8.5.0.');

        $this->installer($manager, $this->trustStore($repository))->install($this->context(), 'announcements');
    }

    /**
     * A revocation the store refuses does not mask the install failure; the refusal still propagates.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnrevocableKeyDoesNotMaskTheInstallFailure(): void
    {
        $manager = $this->createStub(ExtensionManager::class);
        $manager->method('installed')->willReturn([]);
        $manager->method('install')
            ->willThrowException(new RuntimeException('The package archive is not well formed.'));
        $repository = $this->trustRepository();
        $repository->expects(self::once())->method('add');
        $repository->method('extensionsRequiringKey')->willReturn(['kumwe/announcements-example']);
        $repository->expects(self::never())->method('revoke');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The package archive is not well formed.');

        $this->installer($manager, $this->trustStore($repository))->install($this->context(), 'announcements');
    }

    /**
     * A key that signed an admitted release is kept as its provenance even when activation then fails.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testKeepsTheKeyOfAnAdmittedReleaseWhenActivationFails(): void
    {
        $manager = $this->createMock(ExtensionManager::class);
        $manager->method('installed')->willReturn([]);
        $manager->expects(self::once())->method('install')
            ->willReturn(['identifier' => 'kumwe/announcements-example', 'status' => 'disabled']);
        $manager->expects(self::once())->method('activate')
            ->willThrowException(new RuntimeException('The activation lease is held by another process.'));
        $repository = $this->trustRepository();
        $repository->expects(self::once())->method('add');
        $repository->expects(self::never())->method('revoke');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The activation lease is held by another process.');

        $this->installer($manager, $this->trustStore($repository))->install($this->context(), 'announcements');
    }

    /**
     * Build the installer over the real repository examples with a stubbed extension pipeline.
     *
     * @param   ExtensionManager  $manager  Stubbed or mocked canonical pipeline.
     * @param   ?TrustStore       $trust    Trust boundary for scenarios that reach signing, or null for
     *          an uninitialized instance in scenarios that must never touch it.
     *
     * @return  DemoExampleExtensionInstaller  Installer under test.
     *
     * @since   2.0.0
     */
    private function installer(ExtensionManager $manager, ?TrustStore $trust = null): DemoExampleExtensionInstaller
    {
        $trust ??= (new ReflectionClass(TrustStore::class))->newInstanceWithoutConstructor();

        return new DemoExampleExtensionInstaller(dirname(__DIR__, 4), $manager, $trust, $this->clock());
    }

    /**
     * Build the real trust boundary over a repository double, so `add()` and `revoke()` run their
     * authorization, lock, transaction and audit path against the mock instead of a fake store.
     *
     * @param   TrustStoreRepository  $repository  Repository double recording key rows and revocations.
     *
     * @return  TrustStore  Trust boundary whose key mutations land on the double.
     *
     * @since   2.0.0
     */
    private function trustStore(TrustStoreRepository $repository): TrustStore
    {
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );

        return new TrustStore(
            $repository,
            $this->createStub(PublicKeyPackageSignatureVerifier::class),
            $this->createStub(ExtensionArtifactVerifier::class),
            $this->createStub(TrustRuntimeInvalidator::class),
            $transactions,
            $this->createStub(AuditRecorder::class),
            $this->clock(),
            AuthorizationContext::gateway(),
        );
    }

    /**
     * Build a repository mock whose lifecycle lock simply runs the operation it is handed.
     *
     * @return  TrustStoreRepository&\PHPUnit\Framework\MockObject\MockObject  Mock awaiting expectations.
     *
     * @since   2.0.0
     */
    private function trustRepository(): TrustStoreRepository
    {
        $repository = $this->createMock(TrustStoreRepository::class);
        $repository->method('synchronizedLifecycle')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );

        return $repository;
    }

    /**
     * Build the fixed clock the installer and the trust boundary share.
     *
     * @return  ClockInterface  Clock pinned to one instant.
     *
     * @since   2.0.0
     */
    private function clock(): ClockInterface
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-12T00:00:00+00:00'));

        return $clock;
    }

    /**
     * Build the administrator context every scenario runs under.
     *
     * @return  \Kumwe\Context\Value\ExecutionContext  Provenance-bound test context.
     *
     * @since   2.0.0
     */
    private function context(): \Kumwe\Context\Value\ExecutionContext
    {
        return AuthorizationContext::human(['extensions.manage']);
    }
}
