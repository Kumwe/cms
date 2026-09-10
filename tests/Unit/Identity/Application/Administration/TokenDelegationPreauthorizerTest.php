<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Identity\Application\Administration;

use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Identity\Application\Administration\AccessControlRepository;
use Kumwe\App\Identity\Application\Administration\TokenDelegation;
use Kumwe\App\Identity\Application\Administration\TokenDelegationPreauthorizer;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\MembershipContext;
use Kumwe\Context\Value\OrganizationContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Context\Value\WorkspaceContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TokenDelegationPreauthorizer::class)]
#[CoversClass(TokenDelegation::class)]
final class TokenDelegationPreauthorizerTest extends TestCase
{
    public function testOrganizationTokenUsesTargetMembershipRolesAndSnapshot(): void
    {
        $actorId = '0191574f-f0b8-7bf3-a9aa-91c6b8244e10';
        $subjectId = '0191574f-f0b8-7bf3-a9aa-91c6b8244e11';
        $membershipId = '0191574f-f0b8-7bf3-a9aa-91c6b8244e12';
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::once())->method('userIdByEmail')->with('worker@example.com')
            ->willReturn($subjectId);
        $repository->expects(self::once())->method('userGrants')->with($subjectId)->willReturn([]);
        $repository->expects(self::once())->method('organizationMembershipAuthority')->with(
            $subjectId,
            SiteContext::DEFAULT,
            'acme',
            'finance',
            true,
        )->willReturn([
            'membership_id' => $membershipId,
            'membership_version' => 7,
            'policy_generation' => 11,
            'organization_identifier' => 'acme',
            'workspace_identifier' => 'finance',
            'grants' => [[
                'capability' => 'business.record.read',
                'scope_type' => 'site',
                'scope_identifier' => SiteContext::DEFAULT,
            ]],
        ]);
        $authorization = $this->createMock(AuthorizationGateway::class);
        $authorization->expects(self::exactly(2))->method('assertAllowed');
        $authorization->expects(self::once())->method('assertCanDelegate');

        $delegation = (new TokenDelegationPreauthorizer($repository, $authorization))->authorize(
            $this->context($actorId),
            'worker@example.com',
            ['business.record.read'],
            true,
        );

        self::assertSame($subjectId, $delegation->subjectId);
        self::assertSame(['business.record.read'], $delegation->capabilities);
        self::assertSame('acme', $delegation->organization);
        self::assertSame('finance', $delegation->workspace);
        self::assertSame($membershipId, $delegation->membershipId);
        self::assertSame(7, $delegation->membershipVersion);
        self::assertSame(11, $delegation->policyGeneration);
    }

    private function context(string $actorId): ExecutionContext
    {
        $provenance = new \stdClass();
        $principal = AuthenticatedPrincipal::issueFromStrings(
            $provenance,
            $actorId,
            ['users.manage', 'business.record.read'],
        );
        $membership = new MembershipContext(
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e13',
            OrganizationContext::fromString('acme'),
            WorkspaceContext::fromString('finance'),
            3,
            5,
        );

        return ExecutionContext::issueHuman(
            $provenance,
            $principal,
            SiteContext::default(),
            AuthenticationStrength::Password,
            'token-delegation-test',
            surface: AuthenticatedSurface::Administrator,
            membership: $membership,
            sessionId: '0191574f-f0b8-7bf3-a9aa-91c6b8244e14',
        );
    }
}
