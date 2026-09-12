<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessSecurity\Application;

use DateTimeImmutable;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Context\Value\StepUpProof;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalBinding;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalDenied;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalRepository;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalRequest;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalService;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalStatus;
use Kumwe\App\BusinessSecurity\Application\Approval\StepUpProofConsumer;
use Kumwe\App\BusinessSecurity\Application\MembershipDirectory;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(ApprovalService::class)]
final class ApprovalServiceTest extends TestCase
{
    private const MAKER = '0191574f-f0b8-7bf3-a9aa-91c6b8244e10';
    private const APPROVER = '0191574f-f0b8-7bf3-a9aa-91c6b8244e11';
    private const REQUEST = '0191574f-f0b8-7bf3-a9aa-91c6b8244e12';
    private const RULE = '0191574f-f0b8-7bf3-a9aa-91c6b8244e13';

    public function testSecondDistinctApprovalRequiresFrozenCapabilityAndReachesQuorum(): void
    {
        $now = new DateTimeImmutable('2026-08-09T10:00:00+00:00');
        $context = $this->multiFactorContext(self::APPROVER, 'business.approval.approve', $now);
        $binding = new ApprovalBinding(
            self::MAKER,
            'business.record.action.approve_invoice',
            'business_record',
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e14',
            7,
            SiteContext::DEFAULT,
            null,
            null,
            str_repeat('a', 64),
            str_repeat('b', 64),
        );
        $request = new ApprovalRequest(
            self::REQUEST,
            self::RULE,
            2,
            'vendor.invoice.check',
            null,
            true,
            $binding,
            2,
            ApprovalStatus::Pending,
            $now->modify('+1 hour'),
            3,
        );
        $repository = $this->createMock(ApprovalRepository::class);
        $repository->expects(self::once())->method('lock')->with(self::REQUEST)->willReturn($request);
        $repository->expects(self::once())->method('approverEligible')->with($request, $context)->willReturn(true);
        $repository->expects(self::once())->method('vote')->with(
            self::isString(),
            self::REQUEST,
            self::APPROVER,
            'approve',
            'Checked against source documents',
            $context->authorizationFingerprint(),
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e15',
            $now,
        );
        $repository->expects(self::once())->method('approvalCount')->with(self::REQUEST)->willReturn(2);
        $repository->expects(self::once())->method('transition')->with(
            self::REQUEST,
            ApprovalStatus::Pending,
            ApprovalStatus::Approved,
            3,
            $now,
        );
        $stepUp = $this->createMock(StepUpProofConsumer::class);
        $stepUp->expects(self::once())->method('consume')->with(
            $context->stepUpProof(),
            $context,
            'business.approval.approve',
            $now,
        )->willReturn('0191574f-f0b8-7bf3-a9aa-91c6b8244e15');

        $checked = [];
        $authorization = $this->createStub(AuthorizationGateway::class);
        $authorization->method('assertAllowed')->willReturnCallback(
            static function (
                ExecutionContext $unusedContext,
                \Kumwe\Extension\Spi\Identity\Domain\Capability $capability,
            ) use (&$checked): void {
                $checked[] = $capability->value();
            },
        );
        $service = $this->service($repository, $stepUp, $now, $authorization);

        self::assertSame(
            ApprovalStatus::Approved,
            $service->approve($context, self::REQUEST, 'Checked against source documents'),
        );
        self::assertSame(['business.approval.approve', 'vendor.invoice.check'], $checked);
    }

    public function testPayloadSubstitutionCannotConsumeAnApprovedRequestOrItsProof(): void
    {
        $now = new DateTimeImmutable('2026-08-09T10:00:00+00:00');
        $context = $this->multiFactorContext(self::MAKER, 'business.record.action.approve_invoice', $now);
        $stored = ApprovalBinding::fromContext(
            $context,
            'business.record.action.approve_invoice',
            'business_record',
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e14',
            7,
            str_repeat('a', 64),
        );
        $substituted = ApprovalBinding::fromContext(
            $context,
            'business.record.action.approve_invoice',
            'business_record',
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e14',
            7,
            str_repeat('b', 64),
        );
        $request = new ApprovalRequest(
            self::REQUEST,
            self::RULE,
            2,
            'business.approval.approve',
            null,
            true,
            $stored,
            1,
            ApprovalStatus::Approved,
            $now->modify('+1 hour'),
            4,
        );
        $repository = $this->createMock(ApprovalRepository::class);
        $repository->expects(self::once())->method('lock')->with(self::REQUEST)->willReturn($request);
        $repository->expects(self::never())->method('transition');
        $stepUp = $this->createMock(StepUpProofConsumer::class);
        $stepUp->expects(self::never())->method('consume');
        $service = $this->service($repository, $stepUp, $now);

        $this->expectException(ApprovalDenied::class);
        $service->consume($context, self::REQUEST, $substituted);
    }

    public function testMakerCanCancelOwnPendingRequestWithoutCheckerStepUp(): void
    {
        $now = new DateTimeImmutable('2026-08-09T10:00:00+00:00');
        $context = $this->passwordContext(self::MAKER);
        $binding = ApprovalBinding::fromContext(
            $context,
            'business.record.action:approve',
            'business_record',
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e14',
            7,
            str_repeat('a', 64),
        );
        $request = new ApprovalRequest(
            self::REQUEST,
            self::RULE,
            2,
            'vendor.invoice.check',
            null,
            true,
            $binding,
            1,
            ApprovalStatus::Pending,
            $now->modify('+1 hour'),
            3,
        );
        $repository = $this->createMock(ApprovalRepository::class);
        $repository->expects(self::once())->method('lock')->with(self::REQUEST)->willReturn($request);
        $repository->expects(self::once())->method('transition')->with(
            self::REQUEST,
            ApprovalStatus::Pending,
            ApprovalStatus::Cancelled,
            3,
            $now,
        );
        $stepUp = $this->createMock(StepUpProofConsumer::class);
        $stepUp->expects(self::never())->method('consume');
        $checked = [];
        $authorization = $this->createStub(AuthorizationGateway::class);
        $authorization->method('assertAllowed')->willReturnCallback(
            static function (
                ExecutionContext $unusedContext,
                \Kumwe\Extension\Spi\Identity\Domain\Capability $capability,
            ) use (&$checked): void {
                $checked[] = $capability->value();
            },
        );

        $this->service($repository, $stepUp, $now, $authorization)->cancel($context, self::REQUEST);

        self::assertSame(['business.approval.request'], $checked);
    }

    private function multiFactorContext(
        string $subject,
        string $purpose,
        DateTimeImmutable $now,
    ): ExecutionContext {
        $provenance = new \stdClass();
        $principal = AuthenticatedPrincipal::issueFromStrings(
            $provenance,
            $subject,
            ['business.approval.approve', 'business.approval.request'],
        );
        $session = '0191574f-f0b8-7bf3-a9aa-91c6b8244e16';
        $proof = new StepUpProof(
            $subject,
            $session,
            SiteContext::default(),
            null,
            'totp',
            $now->modify('-1 minute'),
            $now->modify('+4 minutes'),
            str_repeat('N', 32),
            purpose: $purpose,
        );

        return ExecutionContext::issueHuman(
            $provenance,
            $principal,
            SiteContext::default(),
            AuthenticationStrength::MultiFactor,
            'approval-service-test',
            surface: AuthenticatedSurface::Administrator,
            sessionId: $session,
            stepUpProof: $proof,
        );
    }

    private function passwordContext(string $subject): ExecutionContext
    {
        $provenance = new \stdClass();
        $principal = AuthenticatedPrincipal::issueFromStrings(
            $provenance,
            $subject,
            ['business.approval.request'],
        );

        return ExecutionContext::issueHuman(
            $provenance,
            $principal,
            SiteContext::default(),
            AuthenticationStrength::Password,
            'approval-cancellation-test',
            surface: AuthenticatedSurface::Administrator,
        );
    }

    private function service(
        ApprovalRepository $repository,
        StepUpProofConsumer $stepUp,
        DateTimeImmutable $now,
        ?AuthorizationGateway $authorization = null,
    ): ApprovalService {
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $authorization ??= $this->createStub(AuthorizationGateway::class);
        $ownership = $this->createStub(ResourceSiteOwnershipWriter::class);
        $memberships = $this->createStub(MembershipDirectory::class);
        $audit = $this->createStub(AuditRecorder::class);
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn($now);

        return new ApprovalService(
            $repository,
            $stepUp,
            $memberships,
            $transactions,
            $authorization,
            $ownership,
            $audit,
            $clock,
        );
    }
}
