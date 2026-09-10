<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessReporting;

use DateTimeImmutable;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\BusinessReporting\Application\ExportGenerationRejected;
use Kumwe\App\BusinessReporting\Domain\ExportArtifact;
use Kumwe\App\BusinessReporting\Domain\ExportArtifactStatus;
use Kumwe\App\BusinessReporting\Infrastructure\LiveExportExecutionContextResolver;
use Kumwe\App\BusinessSecurity\Application\MembershipDirectory;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Portal\Application\PortalPasswordIdentity;
use Kumwe\App\Portal\Application\PortalPrincipalLoader;
use Kumwe\App\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LiveExportExecutionContextResolver::class)]
#[UsesClass(AuthenticatedPrincipal::class)]
#[UsesClass(ExportArtifact::class)]
final class LiveExportExecutionContextResolverTest extends TestCase
{
    private const ACTOR = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';

    /**
     * @var    list<array{capability: string, scope_type: string, scope_identifier: ?string}>
     */
    private const CAPTURED_GRANTS = [
        [
            'capability' => 'business.record.export',
            'scope_type' => 'global',
            'scope_identifier' => null,
        ],
        [
            'capability' => 'kumwe.asset-inspection-example.view',
            'scope_type' => 'global',
            'scope_identifier' => null,
        ],
    ];

    public function testRehydratesALiveSupersetUnderTheCapturedCredentialCeiling(): void
    {
        $request = $this->requestContext(self::CAPTURED_GRANTS);
        $live = $this->principal([
            ...self::CAPTURED_GRANTS,
            [
                'capability' => 'users.manage',
                'scope_type' => 'global',
                'scope_identifier' => null,
            ],
        ]);

        $resolved = $this->resolver($live)->resolve($this->artifact($request), $this->workerContext());
        $principal = $resolved->principal();

        self::assertNotNull($principal);
        self::assertNotSame($request->authorizationFingerprint(), $resolved->authorizationFingerprint());
        self::assertSame($request->approvalFingerprint(), $resolved->approvalFingerprint());
        self::assertSame(AuthenticatedSurface::Cli, $resolved->surface());
        self::assertTrue($principal->hasCapability(Capability::fromString('business.record.export')));
        self::assertFalse($principal->hasCapability(Capability::fromString('users.manage')));
    }

    public function testRejectsWhenOneCapturedGrantIsNoLongerHeld(): void
    {
        $request = $this->requestContext(self::CAPTURED_GRANTS);
        $live = $this->principal([self::CAPTURED_GRANTS[0]]);

        $this->expectException(ExportGenerationRejected::class);
        $this->expectExceptionMessage('The export actor authority changed.');

        $this->resolver($live)->resolve($this->artifact($request), $this->workerContext());
    }

    public function testRejectsAChangedLiveSecurityEpoch(): void
    {
        $request = $this->requestContext(self::CAPTURED_GRANTS);
        $live = $this->principal(self::CAPTURED_GRANTS, 2);

        $this->expectException(ExportGenerationRejected::class);
        $this->expectExceptionMessage('The export actor authority changed.');

        $this->resolver($live)->resolve($this->artifact($request), $this->workerContext());
    }

    public function testLegacyAttenuatedArtifactsRetainTheirFailClosedFingerprintBehavior(): void
    {
        $request = $this->requestContext(self::CAPTURED_GRANTS);
        $live = $this->principal([
            ...self::CAPTURED_GRANTS,
            [
                'capability' => 'users.manage',
                'scope_type' => 'global',
                'scope_identifier' => null,
            ],
        ]);

        $this->expectException(ExportGenerationRejected::class);
        $this->expectExceptionMessage('The export actor authority changed.');

        $this->resolver($live)->resolve($this->artifact($request, null), $this->workerContext());
    }

    /**
     * @param  list<array{capability: string, scope_type: string, scope_identifier: ?string}>  $grants
     */
    private function requestContext(array $grants): ExecutionContext
    {
        return AuthenticatedPrincipal::issueFromGrantRows(
            AuthorizationContext::provenance(),
            self::ACTOR,
            $grants,
            'request-credential-fixture',
        )->context(
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            'cli-export-request',
            surface: AuthenticatedSurface::Cli,
        );
    }

    /**
     * @param  list<array{capability: string, scope_type: string, scope_identifier: ?string}>  $grants
     */
    private function principal(array $grants, int $securityEpoch = 1): AuthenticatedPrincipal
    {
        return AuthenticatedPrincipal::issueFromGrantRows(
            AuthorizationContext::provenance(),
            self::ACTOR,
            $grants,
            'report-export-rehydrated',
            $securityEpoch,
        );
    }

    private function resolver(AuthenticatedPrincipal $principal): LiveExportExecutionContextResolver
    {
        $loader = $this->createMock(PortalPrincipalLoader::class);
        $loader->expects(self::once())
            ->method('load')
            ->with(self::ACTOR, 'report-export-rehydrated', null)
            ->willReturn(new PortalPasswordIdentity($principal, $principal->securityEpoch()));

        return new LiveExportExecutionContextResolver(
            $loader,
            $this->createStub(MembershipDirectory::class),
        );
    }

    /**
     * @param  ?list<array{capability: string, scope_type: string, scope_identifier: ?string}>
     *         $authorityGrantRows  Captured request authority, or null for a legacy artifact.
     */
    private function artifact(
        ExecutionContext $request,
        ?array $authorityGrantRows = self::CAPTURED_GRANTS,
    ): ExportArtifact {
        $createdAt = new DateTimeImmutable('2026-08-10T12:00:00+00:00');

        return new ExportArtifact(
            '019fecc6-8b97-7079-98e9-dc666b067438',
            'kumwe.asset-inspection-example.inspection-summary',
            1,
            str_repeat('a', 64),
            $request->actorId(),
            $request->site()->identifier(),
            null,
            null,
            $request->surface(),
            $request->approvalFingerprint(),
            str_repeat('b', 64),
            [],
            str_repeat('c', 64),
            ExportArtifactStatus::Queued,
            $createdAt,
            $createdAt->modify('+1 hour'),
            null,
            null,
            'inspection-summary-20260810-120000.csv',
            null,
            null,
            null,
            null,
            null,
            null,
            1,
            authorityGrantRows: $authorityGrantRows,
        );
    }

    private function workerContext(): ExecutionContext
    {
        return AuthorizationContext::system(SystemIdentity::Worker)->context(
            SiteContext::default(),
            'export-worker',
        );
    }
}
