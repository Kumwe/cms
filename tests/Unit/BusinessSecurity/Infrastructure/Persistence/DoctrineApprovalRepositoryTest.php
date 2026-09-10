<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessSecurity\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalBinding;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalRequest;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalRule;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalStatus;
use Kumwe\App\BusinessSecurity\Infrastructure\Persistence\DoctrineApprovalRepository;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\MembershipContext;
use Kumwe\Context\Value\OrganizationContext;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrineApprovalRepository::class)]
final class DoctrineApprovalRepositoryTest extends TestCase
{
    private const string REQUESTER = '0191574f-f0b8-7bf3-a9aa-91c6b8244e10';
    private const string REQUEST = '0191574f-f0b8-7bf3-a9aa-91c6b8244e11';
    private const string RULE = '0191574f-f0b8-7bf3-a9aa-91c6b8244e12';
    private const string RESOURCE = '0191574f-f0b8-7bf3-a9aa-91c6b8244e13';

    public function testRuleLookupIsSiteScopedAndFreezesCheckerCapability(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('fetchAllAssociative')->with(
            self::callback(static fn (string $sql): bool => str_contains($sql, 'r.site_identifier = ?')),
            ['default', 'business_record', 'business.record.action:approve', 'acme', 'default'],
        )->willReturn([[
            'id' => self::RULE,
            'organization_id' => '0191574f-f0b8-7bf3-a9aa-91c6b8244e14',
            'rule_code' => 'invoice-approval',
            'approval_action' => 'vendor.invoice.check',
            'quorum' => 2,
            'distinct_actors' => 1,
            'version' => 4,
            'approver_role_id' => null,
        ]]);
        $binding = $this->binding(organization: 'acme');

        $rule = $this->repository($database)->rule($binding);

        self::assertNotNull($rule);
        self::assertSame('vendor.invoice.check', $rule->approvalAction);
        self::assertSame(4, $rule->version);
        self::assertTrue($rule->distinctActors);
    }

    public function testDistinctRequesterCannotApproveEvenWithAStillLiveRule(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('fetchOne')->with(
            self::stringContains("status = 'active'"),
            [self::RULE],
        )->willReturn(2);
        $request = new ApprovalRequest(
            self::REQUEST,
            self::RULE,
            2,
            'business.approval.approve',
            null,
            true,
            $this->binding(),
            1,
            ApprovalStatus::Pending,
            new DateTimeImmutable('+1 hour'),
            1,
        );

        self::assertFalse($this->repository($database)->approverEligible(
            $request,
            $this->context(self::REQUESTER),
        ));
    }

    public function testRuleVersionChangeInvalidatesAPreviouslyCreatedRequest(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('fetchOne')->willReturn(3);
        $request = new ApprovalRequest(
            self::REQUEST,
            self::RULE,
            2,
            'business.approval.approve',
            null,
            false,
            $this->binding(),
            1,
            ApprovalStatus::Pending,
            new DateTimeImmutable('+1 hour'),
            1,
        );

        self::assertFalse($this->repository($database)->approverEligible(
            $request,
            $this->context('0191574f-f0b8-7bf3-a9aa-91c6b8244e15'),
        ));
    }

    public function testGlobalCheckerRoleRemainsEligibleInAnOrganizationContext(): void
    {
        $roleId = '0191574f-f0b8-7bf3-a9aa-91c6b8244e16';
        $approverId = '0191574f-f0b8-7bf3-a9aa-91c6b8244e17';
        $database = $this->database();
        $database->expects(self::exactly(2))->method('fetchOne')->willReturnCallback(
            static function (string $sql, array $parameters) use ($roleId, $approverId): string|int {
                if (str_contains($sql, 'separation_duty_rules')) {
                    self::assertSame([self::RULE, 'active'], $parameters);

                    return $roleId;
                }
                self::assertStringContainsString('kumwe_user_roles', $sql);
                self::assertSame([$approverId, $roleId], $parameters);

                return 1;
            },
        );
        $rule = new ApprovalRule(
            self::RULE,
            'invoice-approval',
            'business.approval.approve',
            1,
            true,
            2,
            $roleId,
        );

        self::assertTrue($this->repository($database)->requesterEligible(
            $rule,
            $this->context($approverId, new MembershipContext(
                '0191574f-f0b8-7bf3-a9aa-91c6b8244e18',
                OrganizationContext::fromString('acme'),
                null,
                3,
                7,
            )),
        ));
    }

    public function testInsertPersistsFrozenRuleAndCheckerFields(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('insert')->with(
            'kumwe_approval_requests',
            self::callback(static fn (array $row): bool => $row['rule_version'] === 7
                && $row['approval_action'] === 'vendor.invoice.check'
                && $row['approver_role_id'] === null
                && $row['distinct_actors'] === true
                && $row['site_identifier'] === 'default'
                && $row['resource_version'] === 8
                && $row['status'] === 'pending'),
            self::isArray(),
        );
        $rule = new ApprovalRule(
            self::RULE,
            'invoice-approval',
            'vendor.invoice.check',
            2,
            true,
            7,
            null,
        );
        $createdAt = new DateTimeImmutable('2026-08-09T10:00:00+00:00');

        $this->repository($database)->insert(
            self::REQUEST,
            $rule,
            $this->binding(resourceVersion: 8),
            $createdAt->modify('+1 day'),
            $createdAt,
        );
    }

    public function testPostgreSqlLocksOnlyTheApprovalRowAcrossNullableScopeJoins(): void
    {
        $database = $this->database();
        $database->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $database->expects(self::once())->method('fetchAssociative')->with(
            self::callback(static fn (string $sql): bool => str_ends_with($sql, 'FOR UPDATE OF a')),
            [self::REQUEST],
        )->willReturn(false);

        self::assertNull($this->repository($database)->lock(self::REQUEST));
    }

    private function repository(Connection $database): DoctrineApprovalRepository
    {
        return new DoctrineApprovalRepository($database, new TableNames($database, 'kumwe_'));
    }

    private function database(): Connection
    {
        $database = $this->createMock(Connection::class);
        $database->method('quoteSingleIdentifier')->willReturnCallback(
            static fn (string $identifier): string => $identifier,
        );

        return $database;
    }

    private function binding(?string $organization = null, int $resourceVersion = 1): ApprovalBinding
    {
        return new ApprovalBinding(
            self::REQUESTER,
            'business.record.action:approve',
            'business_record',
            self::RESOURCE,
            $resourceVersion,
            SiteContext::DEFAULT,
            $organization,
            null,
            str_repeat('a', 64),
            str_repeat('b', 64),
        );
    }

    private function context(string $subject, ?MembershipContext $membership = null): ExecutionContext
    {
        $provenance = new \stdClass();
        $principal = AuthenticatedPrincipal::issueFromStrings(
            $provenance,
            $subject,
            ['business.approval.approve'],
        );

        return ExecutionContext::issueHuman(
            $provenance,
            $principal,
            SiteContext::default(),
            AuthenticationStrength::Password,
            'approval-repository-test',
            membership: $membership,
        );
    }
}
