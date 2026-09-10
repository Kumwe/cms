<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessSecurity\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\BusinessDefinition\Domain\ScopeMode;
use Kumwe\App\BusinessRecord\Application\BusinessRecordDefinitionResolver;
use Kumwe\App\BusinessRecord\Application\ResolvedBusinessDefinition;
use Kumwe\App\BusinessRecord\Domain\RecordScope;
use Kumwe\App\BusinessSecurity\Application\MembershipDirectory;
use Kumwe\App\BusinessSecurity\Infrastructure\Persistence\DoctrineBusinessRecordAccessController;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\MembershipContext;
use Kumwe\Context\Value\OrganizationContext;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use ReflectionClass;
use RuntimeException;
use stdClass;

#[CoversClass(DoctrineBusinessRecordAccessController::class)]
final class DoctrineBusinessRecordAccessControllerTest extends TestCase
{
    public function testDurablePolicyFingerprintSurvivesCredentialRehydration(): void
    {
        $request = $this->context('api-token:request');
        $rehydrated = $this->context('report-export-rehydrated');
        $changedEpoch = $this->context('report-export-rehydrated', 2);
        $changedGrants = $this->context(
            'report-export-rehydrated',
            capabilities: ['business.record.export', 'business.record.read', 'users.manage'],
        );
        $controller = $this->controller();
        $method = (new ReflectionClass($controller))->getMethod('policyFingerprints');
        $requestFingerprints = $method->invoke($controller, $request, []);
        $rehydratedFingerprints = $method->invoke($controller, $rehydrated, []);
        $changedEpochFingerprints = $method->invoke($controller, $changedEpoch, []);
        $changedGrantFingerprints = $method->invoke($controller, $changedGrants, []);

        self::assertNotSame($request->authorizationFingerprint(), $rehydrated->authorizationFingerprint());
        self::assertSame($request->approvalFingerprint(), $rehydrated->approvalFingerprint());
        self::assertIsArray($requestFingerprints);
        self::assertIsArray($rehydratedFingerprints);
        self::assertIsArray($changedEpochFingerprints);
        self::assertIsArray($changedGrantFingerprints);
        self::assertNotSame($requestFingerprints['strict'], $rehydratedFingerprints['strict']);
        self::assertSame($requestFingerprints['durable'], $rehydratedFingerprints['durable']);
        self::assertNotSame($requestFingerprints['durable'], $changedEpochFingerprints['durable']);
        self::assertNotSame($requestFingerprints['durable'], $changedGrantFingerprints['durable']);
    }

    public function testExportPolicyFingerprintStillBindsExactPolicyIdentity(): void
    {
        $context = $this->context('api-token:request');
        $controller = $this->controller();
        $method = (new ReflectionClass($controller))->getMethod('policyFingerprints');
        $first = $this->policyRow(1, true);
        $changedVersion = $this->policyRow(2, true);
        $changedDocument = $this->policyRow(1, false);

        $fingerprint = $method->invoke($controller, $context, [$first]);
        $versionFingerprint = $method->invoke($controller, $context, [$changedVersion]);
        $documentFingerprint = $method->invoke($controller, $context, [$changedDocument]);

        self::assertIsArray($fingerprint);
        self::assertIsArray($versionFingerprint);
        self::assertIsArray($documentFingerprint);
        self::assertNotSame($fingerprint['durable'], $versionFingerprint['durable']);
        self::assertNotSame($fingerprint['durable'], $documentFingerprint['durable']);
    }

    public function testPolicySnapshotUsesSiteGenerationSharedLockInsideActiveTransaction(): void
    {
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())->method('isTransactionActive')->willReturn(true);
        $database->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $database->expects(self::once())->method('fetchOne')->with(
            self::callback(static fn (string $sql): bool => str_contains($sql, 'SELECT policy_generation')
                && str_contains($sql, 'WHERE identifier = ?')
                && str_ends_with($sql, ' LOCK IN SHARE MODE')),
            [SiteContext::DEFAULT],
        )->willReturn('4');
        $provenance = new stdClass();
        $principal = AuthenticatedPrincipal::issueFromStrings(
            $provenance,
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e10',
            ['business.record.read'],
        );
        $context = ExecutionContext::issueHuman(
            $provenance,
            $principal,
            SiteContext::default(),
            AuthenticationStrength::Password,
            'policy-lock-test',
            surface: AuthenticatedSurface::Administrator,
        );
        $controller = new DoctrineBusinessRecordAccessController(
            $database,
            new TableNames($database, 'kumwe_'),
            $this->createStub(BusinessRecordDefinitionResolver::class),
            $this->createStub(MembershipDirectory::class),
            $this->createStub(ClockInterface::class),
        );
        $method = (new ReflectionClass($controller))->getMethod('lockPolicySnapshot');

        $method->invoke($controller, $context);
    }

    public function testMutationPlanFailsClosedBeforePolicyLookupWhenMembershipIsStale(): void
    {
        $database = $this->createMock(Connection::class);
        $database->expects(self::never())->method('fetchAllAssociative');
        $resolver = $this->createStub(BusinessRecordDefinitionResolver::class);
        $memberships = $this->createMock(MembershipDirectory::class);
        $clock = $this->createStub(ClockInterface::class);
        $membership = new MembershipContext(
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e12',
            OrganizationContext::fromString('organization.one'),
            null,
            3,
            7,
        );
        $provenance = new stdClass();
        $principal = AuthenticatedPrincipal::issueFromStrings(
            $provenance,
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e10',
            ['business.record.update'],
        );
        $context = ExecutionContext::issueHuman(
            $provenance,
            $principal,
            SiteContext::default(),
            AuthenticationStrength::Password,
            'stale-membership-test',
            surface: AuthenticatedSurface::Administrator,
            membership: $membership,
        );
        $memberships->expects(self::once())->method('current')->with(
            $context->actorId(),
            $context->site(),
            $membership,
            true,
        )->willReturn(false);
        $controller = new DoctrineBusinessRecordAccessController(
            $database,
            new TableNames($database, 'kumwe_'),
            $resolver,
            $memberships,
            $clock,
        );
        $resolved = (new ReflectionClass(ResolvedBusinessDefinition::class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(ResolvedBusinessDefinition::class, $resolved);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The business-record authorization context is stale.');
        $controller->plan(
            $context,
            'business.record.update',
            $resolved,
            RecordScope::reconstitute(ScopeMode::Site, SiteContext::DEFAULT, null),
        );
    }

    /**
     * Build one human context whose credential identity is independently selectable.
     *
     * @param   string        $credentialId  Credential identity included only in the strict authorization digest.
     * @param   int           $securityEpoch  Current actor security epoch.
     * @param   list<string>  $capabilities   Exact effective grant set to fingerprint.
     *
     * @return  ExecutionContext  CLI bearer context with a fixed actor, epoch and grant set.
     *
     * @since   2.0.0
     */
    private function context(
        string $credentialId,
        int $securityEpoch = 1,
        array $capabilities = ['business.record.export', 'business.record.read'],
    ): ExecutionContext {
        $provenance = new stdClass();
        $principal = AuthenticatedPrincipal::issueFromStrings(
            $provenance,
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e10',
            $capabilities,
            $credentialId,
            $securityEpoch,
        );

        return ExecutionContext::issueHuman(
            $provenance,
            $principal,
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            'export-policy-fingerprint-test',
            surface: AuthenticatedSurface::Cli,
        );
    }

    /**
     * Build the controller used to exercise its deterministic policy fingerprint boundary.
     *
     * @return  DoctrineBusinessRecordAccessController  Controller whose database collaborators remain unused.
     *
     * @since   2.0.0
     */
    private function controller(): DoctrineBusinessRecordAccessController
    {
        $database = $this->createStub(Connection::class);

        return new DoctrineBusinessRecordAccessController(
            $database,
            new TableNames($database, 'kumwe_'),
            $this->createStub(BusinessRecordDefinitionResolver::class),
            $this->createStub(MembershipDirectory::class),
            $this->createStub(ClockInterface::class),
        );
    }

    /**
     * Build one stored policy row with independently variable version and document checksum.
     *
     * @param   int   $version  Positive stored policy version.
     * @param   bool  $allowed  Value embedded in the canonical policy document.
     *
     * @return  array<string, mixed>  Driver-shaped policy row accepted by the fingerprint compiler.
     *
     * @since   2.0.0
     */
    private function policyRow(int $version, bool $allowed): array
    {
        $ast = ['constant' => $allowed];
        $fields = ['export' => ['id']];

        return [
            'canonical_ast' => $ast,
            'field_rules' => $fields,
            'ast_checksum' => CanonicalDefinitionJson::checksum([
                'ast' => $ast,
                'fields' => $fields,
            ]),
            'policy_code' => 'test.export-policy',
            'effect' => 'allow',
            'policy_version' => $version,
            'owner_kind' => 'core',
            'owner_identifier' => 'core',
        ];
    }
}
