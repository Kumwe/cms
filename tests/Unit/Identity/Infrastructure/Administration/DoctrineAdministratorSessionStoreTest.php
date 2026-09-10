<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Identity\Infrastructure\Administration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\MembershipContext;
use Kumwe\Context\Value\OrganizationContext;
use Kumwe\App\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Context\Value\WorkspaceContext;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\BusinessSecurity\Application\MembershipDirectory;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Identity\Infrastructure\Administration\DoctrineAdministratorSessionStore;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(DoctrineAdministratorSessionStore::class)]
final class DoctrineAdministratorSessionStoreTest extends TestCase
{
    private const SESSION_ONE = '018f22e2-7c8b-7ab0-8f3a-88e8026bb410';
    private const SESSION_TWO = '018f22e2-7c8b-7ab0-8f3a-88e8026bb411';
    private const MEMBERSHIP = '018f22e2-7c8b-7ab0-8f3a-88e8026bb412';
    private const USER = '018f22e2-7c8b-7ab0-8f3a-88e8026bb413';

    public function testDeleteRemovesExactSessionOwnershipInTheSameTransaction(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('delete')->with(
            'kumwe_administrator_sessions',
            ['id' => self::SESSION_ONE],
        )->willReturn(1);
        $ownership = $this->createMock(ResourceSiteOwnershipWriter::class);
        $ownership->expects(self::once())->method('remove')->with(
            self::callback(static fn (AuthorizationResource $resource): bool =>
                $resource->type() === 'administrator_session'
                && $resource->identifier() === self::SESSION_ONE),
            self::callback(static fn (SiteContext $site): bool => $site->identifier() === SiteContext::DEFAULT),
        );
        $transactions = $this->transactionManager();

        $this->store($database, $transactions, $ownership)->delete(
            AuthorizationContext::human(['administrator.access']),
            self::SESSION_ONE,
        );
    }

    public function testPurgeDeletesOnlyLockedSessionsForTheAuthorizedSiteAndTheirOwnership(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('fetchFirstColumn')->with(
            self::stringContains('o.site_identifier = ?'),
            self::callback(static fn (array $parameters): bool =>
                $parameters[0] === 'administrator_session'
                && $parameters[1] === SiteContext::DEFAULT
                && $parameters[2] instanceof DateTimeImmutable),
            self::isArray(),
        )->willReturn([self::SESSION_ONE, self::SESSION_TWO]);
        $deleted = [];
        $database->expects(self::exactly(2))->method('delete')->willReturnCallback(
            static function (string $table, array $criteria) use (&$deleted): int {
                self::assertSame('kumwe_administrator_sessions', $table);
                $sessionId = $criteria['id'] ?? null;
                self::assertIsString($sessionId);
                $deleted[] = $sessionId;

                return 1;
            },
        );
        $removed = [];
        $ownership = $this->createMock(ResourceSiteOwnershipWriter::class);
        $ownership->expects(self::exactly(2))->method('remove')->with(
            self::callback(static function (AuthorizationResource $resource) use (&$removed): bool {
                $removed[] = $resource->identifier();

                return $resource->type() === 'administrator_session';
            }),
            self::callback(static fn (SiteContext $site): bool => $site->identifier() === SiteContext::DEFAULT),
        );

        $count = $this->store($database, $this->transactionManager(), $ownership)->purgeExpired(
            AuthorizationContext::human(['automation.manage']),
        );

        self::assertSame(2, $count);
        self::assertSame([self::SESSION_ONE, self::SESSION_TWO], $deleted);
        self::assertSame([self::SESSION_ONE, self::SESSION_TWO], $removed);
    }

    public function testSelectedMembershipRolesJoinTheRebuiltAdministratorPrincipal(): void
    {
        $database = $this->database();
        $userAgent = 'test-browser';
        $database->expects(self::once())->method('fetchAssociative')->willReturn([
            'id' => self::SESSION_ONE,
            'user_id' => self::USER,
            'csrf_token' => str_repeat('c', 43),
            'expires_at' => new DateTimeImmutable('2026-08-05T11:00:00+00:00'),
            'user_agent_digest' => hash_hmac('sha256', $userAgent, str_repeat('s', 64)),
            'security_epoch' => 3,
            'site_identifier' => SiteContext::DEFAULT,
            'organization_identifier' => 'acme',
            'workspace_identifier' => 'finance',
            'membership_id' => self::MEMBERSHIP,
            'membership_version' => 5,
            'policy_generation' => 8,
        ]);
        $database->expects(self::once())->method('update')->willReturn(1);
        $database->expects(self::once())->method('fetchAllAssociative')->with(
            self::callback(static fn (string $sql): bool => str_contains($sql, 'kumwe_membership_roles')
                && str_contains($sql, 'w.identifier = ?')
                && !str_contains($sql, '? IS NULL')),
            [self::USER, self::MEMBERSHIP, self::USER, 5, SiteContext::DEFAULT, 'acme', 8, 'finance'],
        )->willReturn([[
            'capability' => 'business.record.read',
            'scope_type' => 'organization',
            'scope_identifier' => 'acme',
        ]]);
        $membership = new MembershipContext(
            self::MEMBERSHIP,
            OrganizationContext::fromString('acme'),
            WorkspaceContext::fromString('finance'),
            5,
            8,
        );
        $memberships = $this->createMock(MembershipDirectory::class);
        $memberships->expects(self::once())->method('resolve')->with(
            self::USER,
            self::callback(static fn (SiteContext $site): bool => $site->identifier() === SiteContext::DEFAULT),
            'acme',
            'finance',
        )->willReturn($membership);

        $session = $this->store(
            $database,
            $this->createStub(TransactionManager::class),
            $this->createStub(ResourceSiteOwnershipWriter::class),
            $memberships,
        )->find(str_repeat('A', 48), $userAgent);

        self::assertNotNull($session);
        self::assertSame(self::MEMBERSHIP, $session->membership?->membershipId());
        self::assertTrue($session->principal->hasCapability(Capability::fromString('business.record.read')));
    }

    public function testMalformedGrantRowCannotRebuildAdministratorPrincipal(): void
    {
        $database = $this->database();
        $userAgent = 'test-browser';
        $database->expects(self::once())->method('fetchAssociative')->willReturn([
            'id' => self::SESSION_ONE,
            'user_id' => self::USER,
            'csrf_token' => str_repeat('c', 43),
            'expires_at' => new DateTimeImmutable('2026-08-05T11:00:00+00:00'),
            'user_agent_digest' => hash_hmac('sha256', $userAgent, str_repeat('s', 64)),
            'security_epoch' => 3,
            'site_identifier' => SiteContext::DEFAULT,
        ]);
        $database->expects(self::once())->method('update')->willReturn(1);
        $database->expects(self::once())->method('fetchAllAssociative')->willReturn([[
            'capability' => ['business.record.read'],
            'scope_type' => 'organization',
            'scope_identifier' => 'acme',
        ]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A stored administrator principal grant is invalid.');

        $this->store(
            $database,
            $this->createStub(TransactionManager::class),
            $this->createStub(ResourceSiteOwnershipWriter::class),
        )->find(str_repeat('A', 48), $userAgent);
    }

    public function testResolutionRefusesASessionIssuedUnderASupersededSecurityEpoch(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('fetchAssociative')->with(
            self::callback(static function (string $sql): bool {
                self::assertStringContainsString('s.security_epoch = u.security_epoch', $sql);
                self::assertStringContainsString("u.status = 'active'", $sql);

                return true;
            }),
            self::isArray(),
            self::isArray(),
        )->willReturn(false);
        $database->expects(self::never())->method('update');

        self::assertNull($this->store(
            $database,
            $this->createStub(TransactionManager::class),
            $this->createStub(ResourceSiteOwnershipWriter::class),
        )->find(str_repeat('A', 48), 'test-browser'));
    }

    public function testTerminationRemovesEverySessionWithTheOwnershipRowOfItsOwnSite(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('fetchAllAssociative')->with(
            self::callback(static function (string $sql): bool {
                self::assertStringContainsString('kumwe_administrator_sessions', $sql);
                self::assertStringContainsString('user_id = ?', $sql);
                self::assertStringContainsString('ORDER BY id', $sql);

                return true;
            }),
            [self::USER],
            self::isArray(),
        )->willReturn([
            ['id' => self::SESSION_ONE, 'site_identifier' => SiteContext::DEFAULT],
            ['id' => self::SESSION_TWO, 'site_identifier' => 'secondary'],
        ]);
        $deleted = [];
        $database->expects(self::exactly(2))->method('delete')->willReturnCallback(
            static function (string $table, array $criteria) use (&$deleted): int {
                self::assertSame('kumwe_administrator_sessions', $table);
                $sessionId = $criteria['id'] ?? null;
                self::assertIsString($sessionId);
                $deleted[] = $sessionId;

                return 1;
            },
        );
        $sites = [];
        $ownership = $this->createMock(ResourceSiteOwnershipWriter::class);
        $ownership->expects(self::exactly(2))->method('remove')->with(
            self::callback(static fn (AuthorizationResource $resource): bool =>
                $resource->type() === 'administrator_session'),
            self::callback(static function (SiteContext $site) use (&$sites): bool {
                $sites[] = $site->identifier();

                return true;
            }),
        );

        $count = $this->store($database, $this->transactionManager(), $ownership)->deleteAllForUser(
            AuthorizationContext::human(['users.manage']),
            self::USER,
        );

        self::assertSame(2, $count);
        self::assertSame([self::SESSION_ONE, self::SESSION_TWO], $deleted);
        self::assertSame([SiteContext::DEFAULT, 'secondary'], $sites);
    }

    public function testTerminationDemandsIdentityAuthorityForAnotherAccountAndSessionAuthorityForYourOwn(): void
    {
        $demanded = [];
        $authorization = $this->createMock(AuthorizationGateway::class);
        $authorization->expects(self::exactly(2))->method('assertAllowed')->willReturnCallback(
            static function (
                ExecutionContext $context,
                Capability $capability,
                AuthorizationResource $resource,
            ) use (&$demanded): void {
                $demanded[] = $capability->value() . ':' . $resource->type();
            },
        );
        $database = $this->database();
        $database->method('fetchAllAssociative')->willReturn([]);

        $store = $this->store(
            $database,
            $this->createTransactionManager(),
            $this->createStub(ResourceSiteOwnershipWriter::class),
            null,
            $authorization,
        );
        $actor = '018f22e2-7c8b-7ab0-8f3a-88e8026bb420';
        $store->deleteAllForUser(AuthorizationContext::human(['users.manage'], $actor), self::USER);
        $store->deleteAllForUser(AuthorizationContext::human(['administrator.access'], $actor), $actor);

        self::assertSame(
            ['users.manage:user', 'administrator.access:administrator_session'],
            $demanded,
        );
    }

    private function createTransactionManager(): TransactionManager
    {
        $transactions = $this->createMock(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );

        return $transactions;
    }

    private function store(
        Connection $database,
        TransactionManager $transactions,
        ResourceSiteOwnershipWriter $ownership,
        ?MembershipDirectory $memberships = null,
        ?AuthorizationGateway $authorization = null,
    ): DoctrineAdministratorSessionStore {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-05T10:00:00+00:00'));

        return new DoctrineAdministratorSessionStore(
            $database,
            new TableNames($database, 'kumwe_'),
            $clock,
            str_repeat('s', 64),
            $authorization ?? $this->createStub(AuthorizationGateway::class),
            $transactions,
            $ownership,
            AuthorizationContext::provenance(),
            28_800,
            $memberships,
        );
    }

    private function database(): Connection
    {
        $database = $this->createMock(Connection::class);
        $database->method('quoteSingleIdentifier')->willReturnCallback(
            static fn (string $identifier): string => $identifier,
        );

        return $database;
    }

    private function transactionManager(): TransactionManager
    {
        $transactions = $this->createMock(TransactionManager::class);
        $transactions->expects(self::once())->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );

        return $transactions;
    }
}
