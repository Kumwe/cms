<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Identity\Application\Administration;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Security\HighImpactAuthenticationRequired;
use Kumwe\App\Application\Security\HighImpactCredentialGuard;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Identity\Application\Administration\AccessControlRepository;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Identity\Application\Administration\AdministratorSessionStore;
use Kumwe\App\Identity\Application\Security\PasswordHasher;
use Kumwe\App\Identity\Application\StepUp\StepUpCredentialStore;
use Kumwe\App\Identity\Domain\EmailAddress;
use Kumwe\App\Identity\Domain\UserStatus;
use Kumwe\App\Shared\Domain\CanonicalJson;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Context\Value\ExecutionContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(AccessControlService::class)]
#[UsesClass(AuditEvent::class)]
#[UsesClass(EmailAddress::class)]
final class AccessControlServiceTest extends TestCase
{
    private const ACTOR = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';
    private const USER = '018f22e2-7c8b-7ab0-8f3a-88e8026bb302';
    private const ROLE = '018f22e2-7c8b-7ab0-8f3a-88e8026bb303';

    public function testCreatesNormalizedUserWithHashedPasswordAndAuditEvent(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::once())->method('insertUser')->with(
            self::isString(),
            'editor@example.test',
            'Site Editor',
            'active',
            'verified-password-hash',
            self::equalTo(new DateTimeImmutable('2026-08-04T10:00:00+00:00')),
        );
        $passwords = $this->createMock(PasswordHasher::class);
        $passwords->expects(self::once())->method('hash')
            ->with('correct horse battery staple')
            ->willReturn('verified-password-hash');
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::once())->method('record')->with(self::callback(
            static fn (AuditEvent $event): bool => $event->actorId() === self::ACTOR
                && $event->action() === 'user.create'
                && $event->metadata() === ['status' => 'active'],
        ));

        $id = $this->service($repository, $passwords, $audit)->createUser(
            $this->context(),
            ' Editor@Example.Test ',
            ' Site Editor ',
            'correct horse battery staple',
        );

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
            $id,
        );
    }

    public function testPreventsAdministratorFromDisablingOwnAccount(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::never())->method('updateUser');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('your own administrator account');

        $this->service($repository)->updateUser(
            $this->context(),
            self::ACTOR,
            'administrator@example.test',
            'Administrator',
            UserStatus::Disabled,
            2,
        );
    }

    public function testAppliesAPermittedLifecycleTransitionUnderTheUserLock(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::once())->method('userStatus')->with(self::USER)->willReturn('active');
        $repository->expects(self::once())->method('lockUser')->with(self::USER);
        $repository->expects(self::once())->method('updateUser')->with(
            self::USER,
            'member@example.test',
            'Member',
            'suspended',
            3,
            self::equalTo(new DateTimeImmutable('2026-08-04T10:00:00+00:00')),
        );

        $this->service($repository)->updateUser(
            $this->context(),
            self::USER,
            'member@example.test',
            'Member',
            UserStatus::Suspended,
            3,
        );
    }

    public function testRefusesToReactivateADisabledUser(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::once())->method('userStatus')->with(self::USER)->willReturn('disabled');
        $repository->expects(self::never())->method('updateUser');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A disabled user cannot become active.');

        $this->service($repository)->updateUser(
            $this->context(),
            self::USER,
            'member@example.test',
            'Member',
            UserStatus::Active,
            3,
        );
    }

    public function testRefusesToSuspendAUserThatIsNotActive(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::once())->method('userStatus')->with(self::USER)->willReturn('pending');
        $repository->expects(self::never())->method('updateUser');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A pending user cannot become suspended.');

        $this->service($repository)->updateUser(
            $this->context(),
            self::USER,
            'member@example.test',
            'Member',
            UserStatus::Suspended,
            3,
        );
    }

    public function testRefusesToUpdateAUserThatNoLongerExists(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::once())->method('userStatus')->with(self::USER)->willReturn(null);
        $repository->expects(self::never())->method('updateUser');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The user does not exist.');

        $this->service($repository)->updateUser(
            $this->context(),
            self::USER,
            'member@example.test',
            'Member',
            UserStatus::Active,
            3,
        );
    }

    public function testCreatesScopedCapabilityGrantInsideAuditBoundary(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::once())->method('grant')->with(
            self::isString(),
            self::ROLE,
            'content.update',
            'content',
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb309',
            self::ACTOR,
            self::equalTo(new DateTimeImmutable('2026-08-04T10:00:00+00:00')),
        );
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::once())->method('record')->with(self::callback(
            static fn (AuditEvent $event): bool => $event->action() === 'capability.grant'
                && $event->metadata()['scope_identifier'] === '018f22e2-7c8b-7ab0-8f3a-88e8026bb309',
        ));

        $grantId = $this->service($repository, audit: $audit)->grant(
            $this->context(['users.manage', 'content.update']),
            self::ROLE,
            ' Content.Update ',
            'content',
            ' 018f22e2-7c8b-7ab0-8f3a-88e8026bb309 ',
        );

        self::assertNotSame('', $grantId);
    }

    /**
     * Applies one verified role change set under one lock while preserving scoped grants.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSynchronizesGlobalRoleGrantsAsOneAtomicChangeSet(): void
    {
        $removedId = '018f22e2-7c8b-7ab0-8f3a-88e8026bb304';
        $scopedId = '018f22e2-7c8b-7ab0-8f3a-88e8026bb305';
        $current = [
            [
                'id' => $removedId,
                'capability' => 'content.delete',
                'scope_type' => 'global',
                'scope_identifier' => null,
            ],
            [
                'id' => $scopedId,
                'capability' => 'content.update',
                'scope_type' => 'content',
                'scope_identifier' => self::USER,
            ],
        ];
        $snapshot = CanonicalJson::digest([
            [$removedId, 'content.delete', 'global', null],
            [$scopedId, 'content.update', 'content', self::USER],
        ]);
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::once())->method('capabilities')->with(500, 0)->willReturn([[
            'code' => 'content.publish',
            'description' => 'Publish content.',
        ]]);
        $repository->expects(self::once())->method('lockRole')->with(self::ROLE);
        $repository->expects(self::exactly(2))->method('roleGrantRecords')->with(self::ROLE)
            ->willReturnOnConsecutiveCalls($current, [$current[1], [
                'id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb306',
                'capability' => 'content.publish',
                'scope_type' => 'global',
                'scope_identifier' => null,
            ]]);
        $repository->expects(self::once())->method('revokeGrant')->with($removedId);
        $repository->expects(self::once())->method('grant')->with(
            self::isString(),
            self::ROLE,
            'content.publish',
            'global',
            null,
            self::ACTOR,
            self::equalTo(new DateTimeImmutable('2026-08-04T10:00:00+00:00')),
        );

        $this->service($repository)->synchronizeGlobalRoleGrants(
            $this->context(['users.manage', 'content.publish']),
            self::ROLE,
            ['content.publish'],
            $snapshot,
        );
    }

    /**
     * Refuses a stale role grant snapshot before any grant write is attempted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsStaleRoleGrantChangeSet(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->method('capabilities')->willReturn([]);
        $repository->expects(self::once())->method('lockRole')->with(self::ROLE);
        $repository->expects(self::once())->method('roleGrantRecords')->willReturn([]);
        $repository->expects(self::never())->method('grant');
        $repository->expects(self::never())->method('revokeGrant');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('changed; reload');
        $this->service($repository)->synchronizeGlobalRoleGrants(
            $this->context(),
            self::ROLE,
            [],
            str_repeat('a', 64),
        );
    }

    public function testCannotAssignRoleWhoseGrantsExceedActorsEffectiveAuthority(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::once())->method('roleGrants')->with(self::ROLE)->willReturn([[
            'capability' => 'content.publish',
            'scope_type' => 'global',
            'scope_identifier' => null,
        ]]);
        $repository->expects(self::never())->method('assignRole');

        $this->expectException(AuthorizationDenied::class);

        $this->service($repository)->assignRole($this->context(), self::USER, self::ROLE);
    }

    public function testCannotGrantCapabilityBroaderThanActorsEffectiveAuthority(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::never())->method('grant');

        $this->expectException(AuthorizationDenied::class);

        $this->service($repository)->grant(
            AuthorizationContext::principalFromGrantRows([
                ['capability' => 'users.manage', 'scope_type' => 'global', 'scope_identifier' => null],
                [
                    'capability' => 'content.update',
                    'scope_type' => 'content',
                    'scope_identifier' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb309',
                ],
            ], self::ACTOR)->context(
                \Kumwe\Context\Value\SiteContext::default(),
                \Kumwe\Context\Value\AuthenticationStrength::BearerToken,
                'delegation-ceiling-test',
            ),
            self::ROLE,
            'content.update',
            'global',
        );
    }

    public function testSiteScopedUsersManagerCannotSelfEscalateThroughGlobalRoleGrant(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::never())->method('grant');
        $context = AuthorizationContext::principalFromGrantRows([[
            'capability' => 'users.manage',
            'scope_type' => 'site',
            'scope_identifier' => 'default',
        ]], self::ACTOR)->context(
            \Kumwe\Context\Value\SiteContext::default(),
            \Kumwe\Context\Value\AuthenticationStrength::BearerToken,
            'site-identity-escalation-test',
        );

        $this->expectException(AuthorizationDenied::class);
        $this->service($repository)->grant(
            $context,
            self::ROLE,
            'users.manage',
            'global',
        );
    }

    public function testRejectsMalformedGlobalGrantBeforePersistence(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::never())->method('grant');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Global grants cannot have a scope identifier');

        $this->service($repository)->grant(
            $this->context(),
            self::ROLE,
            'content.update',
            'global',
            'news',
        );
    }

    public function testPreventsAdministratorFromRevokingOwnAdministratorRole(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::once())->method('roleCode')->with(self::ROLE)->willReturn('administrator');
        $repository->expects(self::never())->method('revokeRole');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('own administrator role');

        $this->service($repository)->revokeRole($this->context(), self::ACTOR, self::ROLE);
    }

    public function testSiteScopedManagerCannotReadInstallationWideIdentityInventory(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::never())->method('users');

        $this->expectException(AuthorizationDenied::class);
        $this->service($repository)->users(AuthorizationContext::siteScoped('users.manage'));
    }

    public function testManagerCannotMintAnInstallationWideGrantItDoesNotHold(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::never())->method('grant');

        $this->expectException(AuthorizationDenied::class);
        $this->service($repository)->grant(
            AuthorizationContext::human(['users.manage'], self::ACTOR),
            self::ROLE,
            'extensions.manage',
        );
    }

    public function testManagerCannotAssignARoleContainingCapabilitiesItCannotDelegate(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::once())->method('roleGrants')->with(self::ROLE)->willReturn([[
            'capability' => 'extensions.manage',
            'scope_type' => 'global',
            'scope_identifier' => null,
        ]]);
        $repository->expects(self::never())->method('assignRole');

        $this->expectException(AuthorizationDenied::class);
        $this->service($repository)->assignRole(
            AuthorizationContext::human(['users.manage'], self::ACTOR),
            self::USER,
            self::ROLE,
        );
    }

    public function testRevokesTokenInsideAuditedTransaction(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::once())->method('tokenSite')->willReturn('default');
        $repository->expects(self::once())->method('revokeToken')->with(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb305',
            self::equalTo(new DateTimeImmutable('2026-08-04T10:00:00+00:00')),
        );
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::once())->method('record')->with(self::callback(
            static fn (AuditEvent $event): bool => $event->action() === 'token.revoke'
                && $event->actorId() === self::ACTOR,
        ));

        $this->service($repository, audit: $audit)->revokeToken(
            $this->context(),
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb305',
        );
    }

    public function testRevokingGrantAlsoRemovesItsInstallationOwnership(): void
    {
        $grantId = '018f22e2-7c8b-7ab0-8f3a-88e8026bb306';
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::once())->method('grantRecord')->with($grantId)->willReturn([
            'role_id' => self::ROLE,
            'capability' => 'content.update',
            'scope_type' => 'global',
            'scope_identifier' => null,
        ]);
        $repository->expects(self::once())->method('lockRole')->with(self::ROLE);
        $repository->expects(self::once())->method('revokeGrant')->with($grantId);
        $ownership = $this->createMock(ResourceSiteOwnershipWriter::class);
        $ownership->expects(self::once())->method('remove')->with(
            self::callback(static fn (AuthorizationResource $resource): bool => $resource->type() === 'grant'
                && $resource->identifier() === $grantId),
            self::callback(static fn (SiteContext $site): bool => $site->identifier() === SiteContext::DEFAULT),
        );

        $this->service(
            $repository,
            audit: $this->createStub(AuditRecorder::class),
            ownership: $ownership,
        )->revokeGrant($this->context(), $grantId);
    }

    public function testSiteScopedGrantCannotReadInstallationGlobalUsers(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::never())->method('users');
        $context = AuthorizationContext::principalFromGrantRows([[
            'capability' => 'users.manage',
            'scope_type' => 'site',
            'scope_identifier' => 'default',
        ]], self::ACTOR)->context(
            \Kumwe\Context\Value\SiteContext::default(),
            \Kumwe\Context\Value\AuthenticationStrength::BearerToken,
            'access-page-test',
        );

        $this->expectException(AuthorizationDenied::class);
        $this->service($repository)->users($context);
    }

    /**
     * Proves the identity event timeline is gated before its repository projection is read.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSiteScopedManagerCannotReadInstallationSecurityEvents(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::never())->method('securityEvents');

        $this->expectException(AuthorizationDenied::class);
        $this->service($repository)->securityEvents(AuthorizationContext::siteScoped('users.manage'));
    }

    /**
     * Proves an installation identity administrator receives the closed security-event projection.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInstallationManagerCanReadSecurityEvents(): void
    {
        $events = [[
            'id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb304',
            'action' => 'user.create',
            'outcome' => 'success',
        ]];
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::once())->method('securityEvents')->willReturn($events);

        self::assertSame($events, $this->service($repository)->securityEvents($this->context()));
    }

    public function testSiteTokenRevocationLocksTheSameUserRowUsedByIssuance(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $order = 0;
        $repository->expects(self::once())
            ->method('lockUser')
            ->with(self::USER)
            ->willReturnCallback(static function () use (&$order): void {
                self::assertSame(0, $order++);
            });
        $repository->expects(self::once())
            ->method('revokeSubjectTokensForSite')
            ->with(
                self::USER,
                'default',
                self::equalTo(new DateTimeImmutable('2026-08-04T10:00:00+00:00')),
                'site compromise',
            )
            ->willReturnCallback(static function () use (&$order): int {
                self::assertSame(1, $order++);
                return 2;
            });

        self::assertSame(2, $this->service($repository)->revokeSubjectTokens(
            AuthorizationContext::siteScoped('users.manage'),
            self::USER,
            'site compromise',
        ));
    }

    public function testSelfServicePasswordChangeProvesTheCurrentOneAndRetiresEverySession(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $order = 0;
        $repository->expects(self::once())->method('lockUser')->with(self::ACTOR)
            ->willReturnCallback(static function () use (&$order): void {
                self::assertSame(0, $order++);
            });
        $repository->expects(self::once())->method('changePassword')->with(
            self::ACTOR,
            'replacement-hash',
            self::equalTo(new DateTimeImmutable('2026-08-04T10:00:00+00:00')),
        )->willReturnCallback(static function () use (&$order): void {
            self::assertSame(1, $order++);
        });
        $passwords = $this->createMock(PasswordHasher::class);
        $passwords->expects(self::once())->method('hash')
            ->with('a replacement passphrase')->willReturn('replacement-hash');
        $credentials = $this->createMock(HighImpactCredentialGuard::class);
        $credentials->expects(self::once())->method('assertCurrentPassword')
            ->with(self::anything(), 'identity.password.change', 'the current passphrase');
        $sessions = $this->createMock(AdministratorSessionStore::class);
        $sessions->expects(self::once())->method('deleteAllForUser')
            ->with(self::anything(), self::ACTOR)
            ->willReturnCallback(static function () use (&$order): int {
                self::assertSame(2, $order++);
                return 3;
            });
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::once())->method('record')->with(self::callback(
            static fn (AuditEvent $event): bool => $event->action() === 'user.password.change'
                && $event->actorId() === self::ACTOR
                && $event->subjectId() === self::ACTOR
                && $event->metadata() === ['self_service' => true, 'sessions_terminated' => 3],
        ));

        self::assertSame(3, $this->service(
            $repository,
            $passwords,
            $audit,
            null,
            $credentials,
            null,
            $sessions,
        )->changeOwnPassword($this->context(), 'the current passphrase', 'a replacement passphrase'));
    }

    public function testSelfServicePasswordChangeStopsBeforeTheStoreWhenTheCurrentOneIsWrong(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::never())->method('changePassword');
        $credentials = $this->createStub(HighImpactCredentialGuard::class);
        $credentials->method('assertCurrentPassword')
            ->willThrowException(new HighImpactAuthenticationRequired('nope'));

        $this->expectException(HighImpactAuthenticationRequired::class);

        $this->service($repository, null, null, null, $credentials)
            ->changeOwnPassword($this->context(), 'wrong passphrase', 'a replacement passphrase');
    }

    public function testSelfServicePasswordChangeRefusesAReplacementThatIsTooShortOrUnchanged(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::never())->method('changePassword');

        $service = $this->service($repository);
        try {
            $service->changeOwnPassword($this->context(), 'the current passphrase', 'short');
            self::fail('A short replacement password was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('at least 12 characters', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must differ from the current one');
        $service->changeOwnPassword($this->context(), 'the same passphrase', 'the same passphrase');
    }

    public function testAdministrativeResetRecordsAnActorOtherThanTheSubjectWithItsReason(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::once())->method('lockUser')->with(self::USER);
        $repository->expects(self::once())->method('changePassword')->with(self::USER, 'issued-hash');
        $passwords = $this->createStub(PasswordHasher::class);
        $passwords->method('hash')->willReturn('issued-hash');
        $sessions = $this->createMock(AdministratorSessionStore::class);
        $sessions->expects(self::once())->method('deleteAllForUser')->willReturn(2);
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::once())->method('record')->with(self::callback(
            static fn (AuditEvent $event): bool => $event->action() === 'user.password.reset'
                && $event->actorId() === self::ACTOR
                && $event->subjectId() === self::USER
                && $event->metadata() === [
                    'self_service' => false,
                    'reason' => 'lost device, ticket 4711',
                    'sessions_terminated' => 2,
                ],
        ));

        self::assertSame(2, $this->service(
            $repository,
            $passwords,
            $audit,
            null,
            null,
            null,
            $sessions,
        )->resetUserPassword(
            $this->context(),
            self::USER,
            'an issued passphrase',
            '  lost device, ticket 4711  ',
        ));
    }

    public function testAdministrativeResetRefusesToReplaceTheActorsOwnPassword(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::never())->method('changePassword');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('self-service change');

        $this->service($repository)->resetUserPassword(
            $this->context(),
            self::ACTOR,
            'an issued passphrase',
            'trying to skip the current password check',
        );
    }

    public function testAdministrativeResetDemandsAReason(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::never())->method('changePassword');

        $this->expectException(InvalidArgumentException::class);

        $this->service($repository)->resetUserPassword(
            $this->context(),
            self::USER,
            'an issued passphrase',
            '   ',
        );
    }

    public function testStepUpRetirementAdvancesTheEpochAndEndsSessionsUnderTheUserLock(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $order = 0;
        $repository->expects(self::once())->method('lockUser')->with(self::USER)
            ->willReturnCallback(static function () use (&$order): void {
                self::assertSame(0, $order++);
            });
        $repository->expects(self::once())->method('advanceSecurityEpoch')->with(self::USER)
            ->willReturnCallback(static function () use (&$order): void {
                self::assertSame(2, $order++);
            });
        $stepUp = $this->createMock(StepUpCredentialStore::class);
        $stepUp->expects(self::once())->method('revokeForSubject')->with(
            self::USER,
            self::equalTo(new DateTimeImmutable('2026-08-04T10:00:00+00:00')),
            'authenticator lost',
        )->willReturnCallback(static function () use (&$order): int {
            self::assertSame(1, $order++);
            return 1;
        });
        $sessions = $this->createMock(AdministratorSessionStore::class);
        $sessions->expects(self::once())->method('deleteAllForUser')->willReturn(1);
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::once())->method('record')->with(self::callback(
            static fn (AuditEvent $event): bool => $event->action() === 'identity.step_up.credential.revoke'
                && $event->subjectId() === self::USER
                && $event->metadata() === [
                    'revoked_credentials' => 1,
                    'sessions_terminated' => 1,
                    'reason' => 'authenticator lost',
                    'self_service' => false,
                ],
        ));

        self::assertSame(1, $this->service(
            $repository,
            null,
            $audit,
            null,
            null,
            $stepUp,
            $sessions,
        )->revokeStepUpCredentials($this->context(), self::USER, 'authenticator lost'));
    }

    public function testSessionTerminationAdvancesTheEpochWithoutTouchingCredentials(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::once())->method('lockUser')->with(self::USER);
        $repository->expects(self::once())->method('advanceSecurityEpoch')->with(self::USER);
        $repository->expects(self::never())->method('changePassword');
        $repository->expects(self::never())->method('revokeSubjectTokens');
        $sessions = $this->createMock(AdministratorSessionStore::class);
        $sessions->expects(self::once())->method('deleteAllForUser')->willReturn(4);
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::once())->method('record')->with(self::callback(
            static fn (AuditEvent $event): bool => $event->action() === 'user.sessions.terminate'
                && $event->metadata() === [
                    'sessions_terminated' => 4,
                    'reason' => 'shared workstation',
                    'self_service' => false,
                ],
        ));

        self::assertSame(4, $this->service(
            $repository,
            null,
            $audit,
            null,
            null,
            null,
            $sessions,
        )->terminateUserSessions($this->context(), self::USER, 'shared workstation'));
    }

    public function testEmergencyTokenRevocationNowAlsoEndsTheSubjectsBrowserSessions(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::once())->method('lockUser')->with(self::USER);
        $repository->expects(self::once())->method('revokeSubjectTokens')->willReturn(5);
        $sessions = $this->createMock(AdministratorSessionStore::class);
        $sessions->expects(self::once())->method('deleteAllForUser')
            ->with(self::anything(), self::USER)->willReturn(2);
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::once())->method('record')->with(self::callback(
            static fn (AuditEvent $event): bool => $event->action() === 'token.emergency_revoke_all'
                && $event->metadata() === [
                    'revoked_tokens' => 5,
                    'sessions_terminated' => 2,
                    'reason' => 'account compromise',
                ],
        ));

        self::assertSame(5, $this->service(
            $repository,
            null,
            $audit,
            null,
            null,
            null,
            $sessions,
        )->emergencyRevokeAllSubjectTokens($this->context(), self::USER, 'account compromise'));
    }

    private function service(
        AccessControlRepository $repository,
        ?PasswordHasher $passwords = null,
        ?AuditRecorder $audit = null,
        ?ResourceSiteOwnershipWriter $ownership = null,
        ?HighImpactCredentialGuard $credentials = null,
        ?StepUpCredentialStore $stepUp = null,
        ?AdministratorSessionStore $sessions = null,
    ): AccessControlService {
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-04T10:00:00+00:00'));

        return new AccessControlService(
            $repository,
            $passwords ?? $this->createStub(PasswordHasher::class),
            $transactions,
            $audit ?? $this->createStub(AuditRecorder::class),
            $clock,
            AuthorizationContext::gateway(),
            $ownership ?? AuthorizationContext::ownershipWriter(),
            $credentials ?? $this->createStub(HighImpactCredentialGuard::class),
            $stepUp ?? $this->createStub(StepUpCredentialStore::class),
            $sessions ?? $this->createStub(AdministratorSessionStore::class),
        );
    }

    /** @param list<string> $capabilities */
    private function context(array $capabilities = ['users.manage']): ExecutionContext
    {
        return AuthorizationContext::human($capabilities, self::ACTOR);
    }
}
