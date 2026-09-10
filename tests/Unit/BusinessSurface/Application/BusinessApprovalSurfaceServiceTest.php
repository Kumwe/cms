<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessSurface\Application;

use DateTimeImmutable;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\App\Application\Authorization\AuthorizationDecision;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalQueryRepository;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalQueryService;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalRequestView;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalStatus;
use Kumwe\App\BusinessSecurity\Application\MembershipDirectory;
use Kumwe\App\BusinessSurface\Application\BusinessApprovalExposureCatalog;
use Kumwe\App\BusinessSurface\Application\BusinessApprovalSurfaceService;
use Kumwe\App\BusinessSurface\Application\BusinessSurface;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(BusinessApprovalSurfaceService::class)]
/**
 * Proves generic approvals are narrowed through exact generated-business surface exposure.
 *
 * @since  2.0.0
 */
final class BusinessApprovalSurfaceServiceTest extends TestCase
{
    /**
     * Active definition identity shared by canonical business-record bindings.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string DEFINITION = '018f22e2-7c8b-7ab0-8f3a-88e8026bb702';

    /**
     * Proves business adapters omit unrelated, malformed, and no-longer-exposed approvals.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBusinessEndpointsReturnOnlyCanonicalExposedBusinessApprovals(): void
    {
        $exposed = $this->approval(
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e21',
            'business_record',
            self::DEFINITION . ':018f22e2-7c8b-7ab0-8f3a-88e8026bb801',
            'business.record.action:approve',
        );
        $hidden = $this->approval(
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e22',
            'business_record',
            self::DEFINITION . ':018f22e2-7c8b-7ab0-8f3a-88e8026bb802',
            'business.record.action:withdraw',
        );
        $malformed = $this->approval(
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e23',
            'business_record',
            'internal-record-key',
            'business.record.action:approve',
        );
        $unrelated = $this->approval(
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e24',
            'schema_plan',
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e25',
            'business.schema.execute',
        );
        $service = $this->service([$unrelated, $malformed, $hidden, $exposed]);
        $context = $this->context(AuthenticatedSurface::Api);

        self::assertSame(
            [$exposed],
            $service->businessInbox($context, BusinessSurface::Api),
        );
        self::assertSame($exposed, $service->businessDetail(
            $context,
            BusinessSurface::Api,
            $exposed->id,
        ));
        self::assertNull($service->businessDetail($context, BusinessSurface::Api, $hidden->id));
        self::assertNull($service->businessDetail($context, BusinessSurface::Api, $malformed->id));
        self::assertNull($service->businessDetail($context, BusinessSurface::Api, $unrelated->id));
    }

    /**
     * Proves approver-only portal access preserves non-business workflows and filters business actions.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPortalPreservesNonBusinessApprovalsForAnApproverOnlyActor(): void
    {
        $business = $this->approval(
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e31',
            'business_record',
            self::DEFINITION . ':018f22e2-7c8b-7ab0-8f3a-88e8026bb811',
            'business.record.action:approve',
        );
        $hidden = $this->approval(
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e32',
            'business_record',
            self::DEFINITION . ':018f22e2-7c8b-7ab0-8f3a-88e8026bb812',
            'business.record.action:withdraw',
        );
        $nonBusiness = $this->approval(
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e33',
            'schema_plan',
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e34',
            'business.schema.execute',
        );
        $service = $this->service([$nonBusiness, $hidden, $business]);
        $context = $this->context(AuthenticatedSurface::Portal);

        self::assertSame([$nonBusiness, $business], $service->portalInbox($context));
        self::assertSame($nonBusiness, $service->portalDetail($context, $nonBusiness->id));
        self::assertSame($business, $service->portalDetail($context, $business->id));
        self::assertNull($service->portalDetail($context, $hidden->id));
    }

    /**
     * Build the gate around real generic query authorization and deterministic exposure metadata.
     *
     * @param   list<ApprovalRequestView>  $approvals  Repository-visible generic approvals.
     *
     * @return  BusinessApprovalSurfaceService  Fully executable shared surface gate.
     *
     * @since   2.0.0
     */
    private function service(array $approvals): BusinessApprovalSurfaceService
    {
        $repository = $this->createStub(ApprovalQueryRepository::class);
        $repository->method('visible')->willReturn($approvals);
        $repository->method('findVisible')->willReturnCallback(
            static function (
                ExecutionContext $_context,
                string $requestId,
            ) use ($approvals): ?ApprovalRequestView {
                foreach ($approvals as $approval) {
                    if ($approval->id === $requestId) {
                        return $approval;
                    }
                }

                return null;
            },
        );
        $authorization = $this->createStub(AuthorizationGateway::class);
        $authorization->method('decide')->willReturnCallback(
            static fn (
                ExecutionContext $_context,
                Capability $capability,
                AuthorizationResource $_resource,
            ): AuthorizationDecision => new AuthorizationDecision(
                $capability->value() === 'business.approval.approve',
                'test',
                $capability->value() === 'business.approval.approve' ? 'allowed' : 'denied',
            ),
        );
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-10T10:00:00+00:00'));
        $query = new ApprovalQueryService(
            $repository,
            $authorization,
            $this->createStub(MembershipDirectory::class),
            $clock,
        );
        $exposure = $this->createStub(BusinessApprovalExposureCatalog::class);
        $exposure->method('approvalActions')->willReturnCallback(
            static function (ExecutionContext $_context, BusinessSurface $_surface, array $bindings): array {
                /** @var list<array{request_id: string, definition_id: string, action: string}> $bindings */
                $available = [];
                foreach ($bindings as $binding) {
                    if (($binding['action'] ?? null) === 'approve') {
                        $available[$binding['request_id']] = true;
                    }
                }

                return $available;
            },
        );

        return new BusinessApprovalSurfaceService($query, $exposure);
    }

    /**
     * Mint an actor with approval-review authority but no business action execution grant.
     *
     * @param   AuthenticatedSurface  $surface  API or portal provenance under test.
     *
     * @return  ExecutionContext  Approver-only authenticated context.
     *
     * @since   2.0.0
     */
    private function context(AuthenticatedSurface $surface): ExecutionContext
    {
        return AuthorizationContext::principal(['business.approval.approve'])->context(
            SiteContext::default(),
            $surface === AuthenticatedSurface::Portal
                ? AuthenticationStrength::Password
                : AuthenticationStrength::BearerToken,
            'business-approval-surface-test-0001',
            surface: $surface,
        );
    }

    /**
     * Build one valid scoped generic approval projection.
     *
     * @param   string  $id            Approval request UUID.
     * @param   string  $resourceType  Generic resource family.
     * @param   string  $resourceId    Internal binding resource identity.
     * @param   string  $action        Exact protected action.
     *
     * @return  ApprovalRequestView  Valid approval projection.
     *
     * @since   2.0.0
     */
    private function approval(
        string $id,
        string $resourceType,
        string $resourceId,
        string $action,
    ): ApprovalRequestView {
        $created = new DateTimeImmutable('2026-08-10T09:00:00+00:00');

        return new ApprovalRequestView(
            $id,
            'approval.surface.test',
            1,
            'business.approval.approve',
            null,
            true,
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb301',
            $action,
            $resourceType,
            $resourceId,
            3,
            'default',
            null,
            null,
            str_repeat('a', 64),
            str_repeat('b', 64),
            1,
            0,
            ApprovalStatus::Pending,
            $created,
            new DateTimeImmutable('2026-08-11T09:00:00+00:00'),
            1,
            true,
            false,
            false,
            [],
        );
    }
}
