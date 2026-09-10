<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Portal\Infrastructure\Identity;

use Doctrine\DBAL\Connection;
use Kumwe\Context\Value\MembershipContext;
use Kumwe\Context\Value\OrganizationContext;
use Kumwe\Context\Value\WorkspaceContext;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Portal\Infrastructure\Identity\DoctrinePortalPrincipalLoader;
use Kumwe\App\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrinePortalPrincipalLoader::class)]
#[UsesClass(AuthenticatedPrincipal::class)]
final class DoctrinePortalPrincipalLoaderTest extends TestCase
{
    private const string SUBJECT = '018f0000-0000-7000-8000-000000000001';

    private const string MEMBERSHIP = '018f0000-0000-7000-8000-000000000002';

    public function testGlobalLoginIdentityDoesNotReadMembershipRoles(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('fetchOne')->willReturn('7');
        $database->expects(self::once())->method('fetchAllAssociative')->with(
            self::callback(static fn (string $sql): bool => str_contains($sql, 'kumwe_user_roles')
                && !str_contains($sql, 'kumwe_membership_roles')),
            [self::SUBJECT],
        )->willReturn([[
            'capability' => 'portal.access',
            'scope_type' => 'global',
            'scope_identifier' => null,
        ]]);

        $identity = $this->loader($database)->load(self::SUBJECT, 'portal-password:test');

        self::assertNotNull($identity);
        self::assertTrue($identity->principal->hasCapability(Capability::fromString('portal.access')));
        self::assertSame(7, $identity->securityEpoch);
    }

    public function testSessionIdentityUnionsOnlyTheExactCurrentMembershipRoles(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('fetchOne')->willReturn(7);
        $database->expects(self::once())->method('fetchAllAssociative')->with(
            self::callback(static fn (string $sql): bool => str_contains($sql, 'kumwe_membership_roles')
                && str_contains($sql, 'mr.membership_id = m.id')
                && str_contains($sql, "m.status = 'active'")
                && str_contains($sql, 'm.valid_until > CURRENT_TIMESTAMP')
                && str_contains($sql, "o.status = 'active'")
                && str_contains($sql, 'o.policy_generation = ?')
                && str_contains($sql, "w.status = 'active'")
                && str_contains($sql, 'u.security_epoch = ?')
                && str_contains($sql, 'SELECT DISTINCT')),
            [self::MEMBERSHIP, self::SUBJECT, 7, 3, 'acme', 11, 'north'],
        )->willReturn([
            [
                'capability' => 'content.read',
                'scope_type' => 'site',
                'scope_identifier' => 'default',
                'validated_membership_id' => self::MEMBERSHIP,
            ],
            [
                'capability' => 'portal.access',
                'scope_type' => 'organization',
                'scope_identifier' => 'acme',
                'validated_membership_id' => self::MEMBERSHIP,
            ],
        ]);

        $identity = $this->loader($database)->load(
            self::SUBJECT,
            'portal-session:test',
            $this->membership(),
        );

        self::assertNotNull($identity);
        self::assertTrue($identity->principal->hasCapability(Capability::fromString('content.read')));
        self::assertTrue($identity->principal->hasCapability(Capability::fromString('portal.access')));
    }

    public function testStaleSelectedMembershipFailsClosedEvenWhenGlobalRolesExist(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('fetchOne')->willReturn(7);
        $database->expects(self::once())->method('fetchAllAssociative')->willReturn([]);

        self::assertNull($this->loader($database)->load(
            self::SUBJECT,
            'portal-session:test',
            $this->membership(),
        ));
    }

    private function loader(Connection $database): DoctrinePortalPrincipalLoader
    {
        return new DoctrinePortalPrincipalLoader(
            $database,
            new TableNames($database, 'kumwe_'),
            AuthorizationContext::provenance(),
        );
    }

    private function database(): Connection
    {
        $database = $this->createMock(Connection::class);
        $database->method('quoteSingleIdentifier')->willReturnCallback(static fn (string $name): string => $name);

        return $database;
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
