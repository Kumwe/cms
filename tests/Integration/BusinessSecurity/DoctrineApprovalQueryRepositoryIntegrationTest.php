<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessSecurity;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalRequestView;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalStatus;
use Kumwe\App\BusinessSecurity\Infrastructure\Persistence\DoctrineApprovalQueryRepository;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

#[CoversClass(DoctrineApprovalQueryRepository::class)]
/**
 * Exercises approval visibility predicates against every supported relational database.
 *
 * @since  2.0.0
 */
final class DoctrineApprovalQueryRepositoryIntegrationTest extends TestCase
{
    /**
     * Proves a voter may revisit only their exact terminal request without receiving decision controls.
     *
     * The historical vote must not expand the approver inbox, and an unrelated approver must receive
     * the same null projection as an absent request.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTerminalVoteGrantsExactDetailVisibilityWithoutInboxOrControlExpansion(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $database->beginTransaction();

        try {
            $now = new DateTimeImmutable('2035-08-10T10:00:00+00:00');
            $requestId = Uuid::uuid7()->toString();
            $voterId = Uuid::uuid7()->toString();
            $this->storeApprovedRequest($database, $tables, $requestId, $voterId, $now);
            $repository = new DoctrineApprovalQueryRepository($database, $tables);
            $voter = AuthorizationContext::human(['business.approval.approve'], $voterId);

            $detail = $repository->findVisible(
                $voter,
                $requestId,
                false,
                true,
                false,
                $now,
            );

            self::assertNotNull($detail);
            self::assertSame(ApprovalStatus::Approved, $detail->status);
            self::assertFalse($detail->canApprove);
            self::assertFalse($detail->canCancel);
            self::assertFalse($detail->canRevoke);
            self::assertCount(1, $detail->votes);
            self::assertSame($voterId, $detail->votes[0]->approverId);
            self::assertNotContains(
                $requestId,
                array_map(
                    static fn (ApprovalRequestView $request): string => $request->id,
                    $repository->visible($voter, false, true, false, $now, 100),
                ),
            );

            $unrelated = AuthorizationContext::human(
                ['business.approval.approve'],
                Uuid::uuid7()->toString(),
            );
            self::assertNull($repository->findVisible(
                $unrelated,
                $requestId,
                false,
                true,
                false,
                $now,
            ));
        } finally {
            $database->rollBack();
        }
    }

    /**
     * Store one approved request and its deciding vote inside the caller's rollback-only transaction.
     *
     * @param   Connection         $database   Matrix database under test.
     * @param   TableNames         $tables     Installation table namespace.
     * @param   string             $requestId  Terminal approval request UUID.
     * @param   string             $voterId    Actor recorded on the immutable vote.
     * @param   DateTimeImmutable  $now        Resolution instant used by every stored row.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function storeApprovedRequest(
        Connection $database,
        TableNames $tables,
        string $requestId,
        string $voterId,
        DateTimeImmutable $now,
    ): void {
        $ruleId = Uuid::uuid7()->toString();
        $scopeKey = 'test:' . str_replace('-', '', Uuid::uuid7()->toString());
        $database->insert($tables->raw('separation_duty_rules'), [
            'id' => $ruleId,
            'site_identifier' => SiteContext::DEFAULT,
            'organization_id' => null,
            'scope_key' => $scopeKey,
            'rule_code' => 'approval-query-' . str_replace('-', '', $requestId),
            'resource_type' => 'business_record',
            'request_action' => 'business.record.action:approve',
            'approval_action' => 'business.approval.approve',
            'requester_role_id' => null,
            'approver_role_id' => null,
            'quorum' => 1,
            'distinct_actors' => true,
            'status' => 'active',
            'version' => 1,
            'created_by' => 'approval-query-integration-test',
            'created_at' => $now->modify('-1 hour'),
            'updated_at' => $now,
        ], [
            'id' => Types::GUID,
            'organization_id' => Types::GUID,
            'requester_role_id' => Types::GUID,
            'approver_role_id' => Types::GUID,
            'quorum' => Types::SMALLINT,
            'distinct_actors' => Types::BOOLEAN,
            'version' => Types::INTEGER,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
        $database->insert($tables->raw('approval_requests'), [
            'id' => $requestId,
            'rule_id' => $ruleId,
            'rule_version' => 1,
            'approval_action' => 'business.approval.approve',
            'approver_role_id' => null,
            'distinct_actors' => true,
            'site_identifier' => SiteContext::DEFAULT,
            'organization_id' => null,
            'workspace_id' => null,
            'requester_id' => Uuid::uuid7()->toString(),
            'action' => 'business.record.action:approve',
            'resource_type' => 'business_record',
            'resource_id' => Uuid::uuid7()->toString(),
            'resource_version' => 1,
            'context_fingerprint' => str_repeat('a', 64),
            'payload_digest' => str_repeat('b', 64),
            'required_quorum' => 1,
            'status' => ApprovalStatus::Approved->value,
            'expires_at' => $now->modify('+1 day'),
            'created_at' => $now->modify('-1 hour'),
            'resolved_at' => $now,
            'consumed_at' => null,
            'revoked_at' => null,
            'version' => 2,
        ], [
            'id' => Types::GUID,
            'rule_id' => Types::GUID,
            'rule_version' => Types::INTEGER,
            'approver_role_id' => Types::GUID,
            'distinct_actors' => Types::BOOLEAN,
            'organization_id' => Types::GUID,
            'workspace_id' => Types::GUID,
            'resource_version' => Types::BIGINT,
            'required_quorum' => Types::SMALLINT,
            'expires_at' => Types::DATETIME_IMMUTABLE,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'resolved_at' => Types::DATETIME_IMMUTABLE,
            'consumed_at' => Types::DATETIME_IMMUTABLE,
            'revoked_at' => Types::DATETIME_IMMUTABLE,
            'version' => Types::INTEGER,
        ]);
        $database->insert($tables->raw('approval_votes'), [
            'id' => Uuid::uuid7()->toString(),
            'request_id' => $requestId,
            'approver_id' => $voterId,
            'decision' => 'approve',
            'reason' => 'Approved by the integration voter.',
            'context_fingerprint' => str_repeat('c', 64),
            'step_up_proof_id' => null,
            'decided_at' => $now,
        ], [
            'id' => Types::GUID,
            'request_id' => Types::GUID,
            'step_up_proof_id' => Types::GUID,
            'decided_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }
}
