<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessSecurity\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalDenied;
use Kumwe\App\BusinessSecurity\Infrastructure\Persistence\DoctrineStepUpProofConsumer;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\MembershipContext;
use Kumwe\Context\Value\OrganizationContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Context\Value\StepUpProof;
use Kumwe\Context\Value\WorkspaceContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrineStepUpProofConsumer::class)]
final class DoctrineStepUpProofConsumerTest extends TestCase
{
    private const string SUBJECT = '0191574f-f0b8-7bf3-a9aa-91c6b8244e10';
    private const string SESSION = '0191574f-f0b8-7bf3-a9aa-91c6b8244e11';
    private const string PROOF = '0191574f-f0b8-7bf3-a9aa-91c6b8244e12';

    /**
     * Prove consumption revalidates the current user epoch and atomically marks one proof used.
     *
     * @return void
     *
     * @since  2.0.0
     */
    public function testConsumesOnlyProofWhoseUserEpochIsStillLive(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('fetchAssociative')->with(
            self::callback(static fn (string $sql): bool => str_contains(
                $sql,
                'INNER JOIN kumwe_users u ON u.id = p.user_id',
            ) && str_contains($sql, 'u.security_epoch = p.security_epoch')
                && str_contains(
                    $sql,
                    'INNER JOIN kumwe_administrator_sessions s ON s.id = p.session_id',
                )
                && str_contains($sql, 'p.organization_identifier IS NULL')
                && str_contains($sql, 'p.workspace_identifier IS NULL')
                && !str_contains($sql, '? IS NULL')
                && str_contains($sql, 's.expires_at > ?')
                && str_contains($sql, "u.status = 'active'")),
            self::callback(static fn (array $parameters): bool => count($parameters) === 9
                && $parameters[4] === 'business.approval.approve'),
            self::callback(static fn (array $types): bool => count($types) === 9),
        )->willReturn(['id' => self::PROOF]);
        $database->expects(self::once())->method('executeStatement')->with(
            self::stringContains('consumed_at IS NULL AND revoked_at IS NULL'),
            self::callback(static fn (array $parameters): bool => $parameters[1] === self::PROOF),
            self::isArray(),
        )->willReturn(1);
        [$context, $proof, $now] = $this->context();

        self::assertSame(
            self::PROOF,
            (new DoctrineStepUpProofConsumer(
                $database,
                new TableNames($database, 'kumwe_'),
            ))->consume($proof, $context, 'business.approval.approve', $now),
        );
    }

    /**
     * Prove tenant-bound proof lookup uses typed equality without nullable sentinel placeholders.
     *
     * @return void
     *
     * @since  2.0.0
     */
    public function testTenantProofUsesOnlyBoundScopeParameters(): void
    {
        $membership = new MembershipContext(
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e13',
            OrganizationContext::fromString('acme'),
            WorkspaceContext::fromString('finance'),
            4,
            7,
        );
        $database = $this->database();
        $database->expects(self::once())->method('fetchAssociative')->with(
            self::callback(static fn (string $sql): bool => str_contains(
                $sql,
                'p.organization_identifier = ?',
            ) && str_contains($sql, 'p.workspace_identifier = ?')
                && !str_contains($sql, '? IS NULL')),
            self::callback(static fn (array $parameters): bool => count($parameters) === 11
                && $parameters[4] === 'acme'
                && $parameters[5] === 'finance'
                && $parameters[6] === 'business.approval.approve'),
            self::callback(static fn (array $types): bool => count($types) === 11),
        )->willReturn(['id' => self::PROOF]);
        $database->expects(self::once())->method('executeStatement')->willReturn(1);
        [$context, $proof, $now] = $this->context(membership: $membership);

        self::assertSame(
            self::PROOF,
            (new DoctrineStepUpProofConsumer(
                $database,
                new TableNames($database, 'kumwe_'),
            ))->consume($proof, $context, 'business.approval.approve', $now),
        );
    }

    /**
     * Prove portal proof consumption also locks the live rotated session and its security epoch.
     *
     * @return void
     *
     * @since  2.0.0
     */
    public function testPortalProofRequiresLiveEpochBoundPortalSession(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('fetchAssociative')->with(
            self::callback(static fn (string $sql): bool => str_contains(
                $sql,
                'INNER JOIN kumwe_portal_sessions s ON s.id = p.session_id',
            ) && str_contains($sql, 's.security_epoch = p.security_epoch')),
            self::isArray(),
            self::isArray(),
        )->willReturn(['id' => self::PROOF]);
        $database->expects(self::once())->method('executeStatement')->willReturn(1);
        [$context, $proof, $now] = $this->context(AuthenticatedSurface::Portal);

        self::assertSame(
            self::PROOF,
            (new DoctrineStepUpProofConsumer(
                $database,
                new TableNames($database, 'kumwe_'),
            ))->consume($proof, $context, 'business.approval.approve', $now),
        );
    }

    /**
     * Prove a missing, revoked, replayed, or stale-epoch persisted proof fails identically.
     *
     * @return void
     *
     * @since  2.0.0
     */
    public function testUnavailableProofCannotBeConsumedOrUpdated(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('fetchAssociative')->willReturn(false);
        $database->expects(self::never())->method('executeStatement');
        [$context, $proof, $now] = $this->context();

        $this->expectException(ApprovalDenied::class);
        (new DoctrineStepUpProofConsumer(
            $database,
            new TableNames($database, 'kumwe_'),
        ))->consume($proof, $context, 'business.approval.approve', $now);
    }

    /**
     * Build a DBAL mock with the platform and identifier behavior used by this adapter.
     *
     * @return Connection Mocked connection.
     *
     * @since  2.0.0
     */
    private function database(): Connection
    {
        $database = $this->createMock(Connection::class);
        $database->method('quoteSingleIdentifier')->willReturnCallback(
            static fn (string $identifier): string => $identifier,
        );
        $database->method('getDatabasePlatform')->willReturn(new SQLitePlatform());

        return $database;
    }

    /**
     * Issue one internally consistent multi-factor context and proof.
     *
     * @param  AuthenticatedSurface  $surface     Browser boundary whose rotated session is bound.
     * @param  ?MembershipContext    $membership  Optional organization and workspace proof binding.
     *
     * @return array{ExecutionContext, StepUpProof, DateTimeImmutable} Test values.
     *
     * @since  2.0.0
     */
    private function context(
        AuthenticatedSurface $surface = AuthenticatedSurface::Administrator,
        ?MembershipContext $membership = null,
    ): array {
        $now = new DateTimeImmutable('2026-08-09T10:00:00+00:00');
        $provenance = new \stdClass();
        $principal = AuthenticatedPrincipal::issueFromStrings(
            $provenance,
            self::SUBJECT,
            ['business.approval.approve'],
            securityEpoch: 7,
        );
        $proof = new StepUpProof(
            self::SUBJECT,
            self::SESSION,
            SiteContext::default(),
            $membership?->organization(),
            'totp',
            $now->modify('-1 minute'),
            $now->modify('+4 minutes'),
            str_repeat('N', 32),
            workspace: $membership?->workspace(),
            purpose: 'business.approval.approve',
            securityEpoch: 7,
        );
        $context = ExecutionContext::issueHuman(
            $provenance,
            $principal,
            SiteContext::default(),
            AuthenticationStrength::MultiFactor,
            'step-up-consumer-test',
            surface: $surface,
            membership: $membership,
            sessionId: self::SESSION,
            stepUpProof: $proof,
        );

        return [$context, $proof, $now];
    }
}
