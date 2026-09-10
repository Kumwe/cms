<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Portal\Application;

use Kumwe\App\BusinessSecurity\Application\MembershipDirectory;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Portal\Application\MembershipPortalSessionIdentityLoader;
use Kumwe\App\Portal\Application\PortalPasswordIdentity;
use Kumwe\App\Portal\Application\PortalPrincipalLoader;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Context\Value\MembershipContext;
use Kumwe\Context\Value\OrganizationContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Context\Value\WorkspaceContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MembershipPortalSessionIdentityLoader::class)]
final class MembershipPortalSessionIdentityLoaderTest extends TestCase
{
    private const string SUBJECT = '018f0000-0000-7000-8000-000000000001';

    private const string MEMBERSHIP = '018f0000-0000-7000-8000-000000000002';

    private const string SESSION = '018f0000-0000-7000-8000-000000000003';

    public function testResolvesMembershipBeforeLoadingItsExactPrincipalGrants(): void
    {
        $membership = $this->membership();
        $directory = $this->createMock(MembershipDirectory::class);
        $directory->expects(self::once())->method('resolve')->with(
            self::SUBJECT,
            self::callback(static fn (SiteContext $site): bool => $site->identifier() === 'default'),
            'acme',
            'north',
        )->willReturn($membership);
        $principals = $this->createMock(PortalPrincipalLoader::class);
        $principals->expects(self::once())->method('load')->with(
            self::SUBJECT,
            'portal-session:' . self::SESSION,
            self::identicalTo($membership),
        )->willReturn($this->identity());

        $identity = (new MembershipPortalSessionIdentityLoader($principals, $directory))->load(
            self::SUBJECT,
            'default',
            'acme',
            self::MEMBERSHIP,
            'north',
            self::SESSION,
        );

        self::assertNotNull($identity);
        self::assertSame($membership, $identity->context->membership);
    }

    public function testStaleMembershipStopsBeforePrincipalRoleLoading(): void
    {
        $directory = $this->createMock(MembershipDirectory::class);
        $directory->expects(self::once())->method('resolve')->willReturn(null);
        $principals = $this->createMock(PortalPrincipalLoader::class);
        $principals->expects(self::never())->method('load');

        self::assertNull((new MembershipPortalSessionIdentityLoader($principals, $directory))->load(
            self::SUBJECT,
            'default',
            'acme',
            self::MEMBERSHIP,
            'north',
            self::SESSION,
        ));
    }

    public function testGlobalSessionLoadsNoMembershipRoles(): void
    {
        $directory = $this->createMock(MembershipDirectory::class);
        $directory->expects(self::never())->method('resolve');
        $principals = $this->createMock(PortalPrincipalLoader::class);
        $principals->expects(self::once())->method('load')->with(
            self::SUBJECT,
            'portal-session:' . self::SESSION,
        )->willReturn($this->identity());

        $identity = (new MembershipPortalSessionIdentityLoader($principals, $directory))->load(
            self::SUBJECT,
            'default',
            null,
            null,
            null,
            self::SESSION,
        );

        self::assertNotNull($identity);
        self::assertNull($identity->context->membership);
    }

    private function identity(): PortalPasswordIdentity
    {
        return new PortalPasswordIdentity(AuthenticatedPrincipal::issueFromStrings(
            AuthorizationContext::provenance(),
            self::SUBJECT,
            ['portal.access'],
            securityEpoch: 7,
        ), 7);
    }

    private function membership(): MembershipContext
    {
        return new MembershipContext(
            self::MEMBERSHIP,
            OrganizationContext::fromString('acme'),
            WorkspaceContext::fromString('north'),
            3,
            11,
        );
    }
}
