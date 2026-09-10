<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Application\Authorization;

use ArrayObject;
use DateTimeImmutable;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\App\Application\Authorization\AuthorizationAuditUnavailable;
use Kumwe\App\Application\Authorization\AuthorizationDecision;
use Kumwe\App\Application\Authorization\AuthorizationDecisionRecorder;
use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Authorization\DenyByDefaultAuthorizationGateway;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Application\Authorization\MembershipContextValidator;
use Kumwe\App\Application\Authorization\OwnershipScope;
use Kumwe\App\Application\Authorization\ResourceSiteOwnership;
use Kumwe\App\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\App\Application\Authorization\ResourcePolicyTarget;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Application\ContentRepository;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Content\Domain\ContentEntry;
use Kumwe\App\Delivery\Http\Api\Content\ContentApiResponder;
use Kumwe\App\Delivery\Http\Api\Content\ContentCollectionHandler;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Contribution\CapabilityDefinition as ExtensionCapabilityDefinition;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\App\Extension\Contribution\ResourcePolicyDefinition as ExtensionResourcePolicyDefinition;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Identity\Domain\GrantScope;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Workflow\Domain\Workflow;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;

#[CoversClass(DenyByDefaultAuthorizationGateway::class)]
#[UsesClass(AuthorizationDenied::class)]
#[UsesClass(AuthorizationResource::class)]
final class ApplicationAuthorizationTest extends TestCase
{
    private const SUBJECT = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';
    private const PAGE_ONE = '018f22e2-7c8b-7ab0-8f3a-88e8026bb401';
    private const PAGE_TWO = '018f22e2-7c8b-7ab0-8f3a-88e8026bb402';

    public function testScopedGrantAllowsOnlyItsExactResource(): void
    {
        $context = $this->context('content.update', 'content', self::PAGE_ONE);
        $gateway = AuthorizationContext::gateway();
        $gateway->assertAllowed(
            $context,
            Capability::fromString('content.update'),
            AuthorizationResource::item('content', self::PAGE_ONE),
        );

        $this->expectException(AuthorizationDenied::class);
        $gateway->assertAllowed(
            $context,
            Capability::fromString('content.update'),
            AuthorizationResource::item('content', self::PAGE_TWO),
        );
    }

    public function testContextIssuedByAnotherAuthorityCannotBeForgedIntoAcceptance(): void
    {
        $context = AuthenticatedPrincipal::issueFromStrings(
            new \stdClass(),
            self::SUBJECT,
            ['content.update'],
        )->context(
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            'forged-request',
        );

        try {
            AuthorizationContext::gateway()->assertAllowed(
                $context,
                Capability::fromString('content.update'),
                AuthorizationResource::item('content', self::PAGE_ONE),
            );
            self::fail('A foreign authority must never issue an accepted execution context.');
        } catch (AuthorizationDenied $denied) {
            self::assertSame('core.provenance.v1', $denied->policy);
            self::assertSame('untrusted_execution_context', $denied->reason);
        }
    }

    public function testRegistryRejectsUnsupportedActionResourceCombination(): void
    {
        $this->expectException(AuthorizationDenied::class);

        AuthorizationContext::gateway()->assertAllowed(
            AuthorizationContext::human(['themes.site.manage'], self::SUBJECT),
            Capability::fromString('themes.site.manage'),
            AuthorizationResource::item('theme', 'administrator'),
        );
    }

    public function testRegistryAllowsContentModelResourcesAndDelegationScopes(): void
    {
        $gateway = AuthorizationContext::gateway();
        self::assertTrue($gateway->decide(
            $this->context('content.read', 'content_type', self::PAGE_ONE),
            Capability::fromString('content.read'),
            AuthorizationResource::item('content_type', self::PAGE_ONE),
        )->allowed);
        self::assertTrue($gateway->decide(
            AuthorizationContext::human(['content.read']),
            Capability::fromString('content.read'),
            AuthorizationResource::collection('media'),
        )->allowed);
        self::assertTrue($gateway->decide(
            $this->context('content.update', 'workflow', self::PAGE_TWO),
            Capability::fromString('content.update'),
            AuthorizationResource::item('workflow', self::PAGE_TWO),
        )->allowed);
        $gateway->assertCanDelegate(
            AuthorizationContext::human(['content.update']),
            Capability::fromString('content.update'),
            GrantScope::named('content_type', self::PAGE_ONE),
        );
    }

    public function testRegistryRejectsUnrelatedDelegationScope(): void
    {
        $this->expectException(AuthorizationDenied::class);

        AuthorizationContext::gateway()->assertCanDelegate(
            AuthorizationContext::human(['content.update']),
            Capability::fromString('content.update'),
            GrantScope::named('menu', 'primary'),
        );
    }

    /**
     * Allows an installation extension manager to make the explicit first grant of a trusted capability.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGlobalExtensionManagerMayBootstrapOnlyExtensionOwnedDelegation(): void
    {
        $registries = new ExtensionContributionRegistrySet();
        $owner = ContributionOwner::extension('acme/inspection');
        $registries->capabilities()->register($owner, new ExtensionCapabilityDefinition(
            'acme.inspection.view',
            'View inspections',
            'View policy-filtered inspection records.',
        ));
        $registries->resourcePolicies()->register($owner, new ExtensionResourcePolicyDefinition(
            'acme.inspection.view-records',
            'acme.inspection.view',
            [new ResourcePolicyTarget('business_record')],
        ));
        $gateway = new DenyByDefaultAuthorizationGateway(
            AuthorizationContext::provenance(),
            $registries->authorizationPolicies(),
            $this->createStub(MembershipContextValidator::class),
            AuthorizationContext::ownership(),
            $this->createStub(AuthorizationDecisionRecorder::class),
        );

        $gateway->assertCanDelegate(
            AuthorizationContext::human(['extensions.manage']),
            Capability::fromString('acme.inspection.view'),
            GrantScope::global(),
        );

        try {
            $gateway->assertCanDelegate(
                AuthorizationContext::human(['extensions.manage']),
                Capability::fromString('content.update'),
                GrantScope::global(),
            );
            self::fail('Extension management must never bootstrap a core capability grant.');
        } catch (AuthorizationDenied $denied) {
            self::assertSame('core.delegation-ceiling.v1', $denied->policy);
            self::assertSame('delegation_exceeds_effective_authority', $denied->reason);
        }
    }

    public function testSiteScopedIdentityGrantCannotManageInstallationGlobalRole(): void
    {
        $context = $this->context('users.manage', 'site', SiteContext::DEFAULT);

        try {
            AuthorizationContext::gateway()->assertAllowed(
                $context,
                Capability::fromString('users.manage'),
                AuthorizationResource::item('role', '018f22e2-7c8b-7ab0-8f3a-88e8026bb499'),
            );
            self::fail('A site grant must not control installation-global identity resources.');
        } catch (AuthorizationDenied $denied) {
            self::assertSame('global_grant_required', $denied->reason);
        }
    }

    public function testRegistryRejectsSiteScopedDelegationForInstallationGlobalIdentityAction(): void
    {
        $this->expectException(AuthorizationDenied::class);
        AuthorizationContext::gateway()->assertCanDelegate(
            AuthorizationContext::human(['users.manage']),
            Capability::fromString('users.manage'),
            GrantScope::named('site', SiteContext::DEFAULT),
        );
    }

    public function testAuthoritativeResourceSiteMustMatchExecutionSite(): void
    {
        $gateway = new DenyByDefaultAuthorizationGateway(
            AuthorizationContext::provenance(),
            (new ExtensionContributionRegistrySet())->authorizationPolicies(),
            $this->createStub(MembershipContextValidator::class),
            new class implements ResourceSiteOwnership {
                public function scopeFor(AuthorizationResource $resource): OwnershipScope
                {
                    return OwnershipScope::site(SiteContext::fromString('resource-owner-site'));
                }
            },
            new class implements AuthorizationDecisionRecorder {
                public function record(
                    ExecutionContext $context,
                    Capability $action,
                    AuthorizationResource $resource,
                    AuthorizationDecision $decision,
                ): void {
                }
            },
        );

        try {
            $gateway->assertAllowed(
                AuthorizationContext::human(['content.read']),
                Capability::fromString('content.read'),
                AuthorizationResource::item('content', self::PAGE_ONE),
            );
            self::fail('A resource owned by another site must never be readable.');
        } catch (AuthorizationDenied $denied) {
            self::assertSame('resource_site_mismatch', $denied->reason);
        }
    }

    /**
     * Allows organization and workspace grants only after exact live membership revalidation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCurrentMembershipAddsOrganizationAndWorkspaceScopes(): void
    {
        $membership = AuthorizationContext::membership('acme', 'finance', 4, 7);
        $memberships = $this->createMock(MembershipContextValidator::class);
        $memberships->expects(self::exactly(2))->method('current')->with(
            self::SUBJECT,
            self::callback(static fn (SiteContext $site): bool => $site->identifier() === 'default'),
            self::identicalTo($membership),
            false,
        )->willReturn(true);
        $gateway = AuthorizationContext::gateway(memberships: $memberships);

        foreach ([['organization', 'acme'], ['workspace', 'finance']] as [$scopeType, $scopeIdentifier]) {
            $context = AuthorizationContext::principalFromGrantRows([[
                'capability' => 'business.record.read',
                'scope_type' => $scopeType,
                'scope_identifier' => $scopeIdentifier,
            ]], self::SUBJECT)->context(
                SiteContext::default(),
                AuthenticationStrength::BearerToken,
                'membership-scope-' . $scopeType,
                membership: $membership,
            );

            self::assertTrue($gateway->decide(
                $context,
                Capability::fromString('business.record.read'),
                AuthorizationResource::item('business_record', self::PAGE_ONE),
            )->allowed);
        }
    }

    /**
     * Refuses organization authority when the credential's membership snapshot is stale.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStaleMembershipCannotAddOrganizationScope(): void
    {
        $membership = AuthorizationContext::membership('acme', null, 4, 7);
        $memberships = $this->createMock(MembershipContextValidator::class);
        $memberships->expects(self::exactly(3))->method('current')->with(
            self::SUBJECT,
            self::callback(static fn (SiteContext $site): bool => $site->identifier() === 'default'),
            self::identicalTo($membership),
            false,
        )->willReturn(false);
        $context = AuthorizationContext::principalFromGrantRows([[
            'capability' => 'portal.access',
            'scope_type' => 'organization',
            'scope_identifier' => 'acme',
        ]], self::SUBJECT)->context(
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            'stale-membership-scope',
            membership: $membership,
        );
        $gateway = AuthorizationContext::gateway(memberships: $memberships);

        $decision = $gateway->decide(
            $context,
            Capability::fromString('portal.access'),
            AuthorizationResource::item('portal_session', self::PAGE_ONE),
        );

        self::assertFalse($decision->allowed);
        self::assertSame('no_matching_effective_grant', $decision->reason);
        try {
            $gateway->assertCanDelegate(
                $context,
                Capability::fromString('portal.access'),
                GrantScope::named('organization', 'acme'),
            );
            self::fail('A stale organization membership must not satisfy the delegation ceiling.');
        } catch (AuthorizationDenied $denied) {
            self::assertSame('delegation_exceeds_effective_authority', $denied->reason);
        }
        $siteContext = AuthorizationContext::principalFromGrantRows([[
            'capability' => 'portal.access',
            'scope_type' => 'site',
            'scope_identifier' => SiteContext::DEFAULT,
        ]], self::SUBJECT)->context(
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            'stale-membership-site-delegation',
            membership: $membership,
        );
        $gateway->assertCanDelegate(
            $siteContext,
            Capability::fromString('portal.access'),
            GrantScope::named('organization', 'acme'),
        );
    }

    /**
     * Prevents a valid organization selection from authorizing a differently named tenant target.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMembershipScopeCannotAuthorizeForgedOrganizationTarget(): void
    {
        $membership = AuthorizationContext::membership('acme', null, 4, 7);
        $memberships = $this->createStub(MembershipContextValidator::class);
        $memberships->method('current')->willReturn(true);
        $context = AuthorizationContext::principalFromGrantRows([[
            'capability' => 'portal.access',
            'scope_type' => 'organization',
            'scope_identifier' => 'acme',
        ]], self::SUBJECT)->context(
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            'forged-organization-target',
            membership: $membership,
        );

        $decision = AuthorizationContext::gateway(memberships: $memberships)->decide(
            $context,
            Capability::fromString('portal.access'),
            AuthorizationResource::item('organization', 'other-organization'),
        );

        self::assertFalse($decision->allowed);
    }

    /**
     * Keeps global, site, and exact-resource authority usable when membership freshness is unavailable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnverifiableMembershipDoesNotSuppressBaseScopes(): void
    {
        $membership = AuthorizationContext::membership('acme', 'finance', 4, 7);
        $memberships = $this->createMock(MembershipContextValidator::class);
        $memberships->expects(self::exactly(3))->method('current')->willThrowException(
            new \RuntimeException('membership store unavailable'),
        );
        $gateway = AuthorizationContext::gateway(memberships: $memberships);

        foreach (
            [
            ['global', null],
            ['site', SiteContext::DEFAULT],
            ['business_record', self::PAGE_ONE],
            ] as [$scopeType, $scopeIdentifier]
        ) {
            $context = AuthorizationContext::principalFromGrantRows([[
                'capability' => 'business.record.read',
                'scope_type' => $scopeType,
                'scope_identifier' => $scopeIdentifier,
            ]], self::SUBJECT)->context(
                SiteContext::default(),
                AuthenticationStrength::BearerToken,
                'unverifiable-membership-' . $scopeType,
                membership: $membership,
            );

            self::assertTrue($gateway->decide(
                $context,
                Capability::fromString('business.record.read'),
                AuthorizationResource::item('business_record', self::PAGE_ONE),
            )->allowed);
        }
    }

    public function testNonDefaultSiteBindsCollectionsAndTransportQueuesToTheExecutionContext(): void
    {
        $site = '018f22e2-7c8b-7ab0-8f3a-88e8026bb499';
        $gateway = AuthorizationContext::gateway(ownership: AuthorizationContext::ownership());

        $gateway->assertAllowed(
            AuthorizationContext::human(['automation.manage'], self::SUBJECT, $site),
            Capability::fromString('automation.manage'),
            AuthorizationResource::collection('schedule'),
        );
        $gateway->assertAllowed(
            AuthorizationContext::system(SystemIdentity::Worker)->context(
                SiteContext::fromString($site),
                'worker-queue-site-test',
            ),
            Capability::fromString('system.worker.operate'),
            AuthorizationResource::item('queue', 'default'),
        );
        self::assertTrue(true);
    }

    public function testEveryAllowAndDenyDecisionCarriesRequestSiteAndPolicyMetadata(): void
    {
        /** @var ArrayObject<int, array<string, mixed>> $records */
        $records = new ArrayObject();
        $recorder = new class ($records) implements AuthorizationDecisionRecorder {
            /** @param ArrayObject<int, array<string, mixed>> $records */
            public function __construct(private ArrayObject $records)
            {
            }

            public function record(
                ExecutionContext $context,
                Capability $action,
                AuthorizationResource $resource,
                AuthorizationDecision $decision,
            ): void {
                $this->records->append([
                    'subject' => $context->actorId(),
                    'request' => $context->requestId(),
                    'correlation' => $context->correlationId(),
                    'site' => $context->site()->identifier(),
                    'strength' => $context->authenticationStrength()->value,
                    'action' => $action->value(),
                    'resource' => $resource->type() . ':' . $resource->identifier(),
                    'allowed' => $decision->allowed,
                    'policy' => $decision->policy,
                    'reason' => $decision->reason,
                ]);
            }
        };
        $gateway = AuthorizationContext::gateway($recorder);
        $context = AuthorizationContext::human(['content.read'], self::SUBJECT);

        self::assertTrue($gateway->decide(
            $context,
            Capability::fromString('content.read'),
            AuthorizationResource::item('content', self::PAGE_ONE),
        )->allowed);
        self::assertFalse($gateway->decide(
            $context,
            Capability::fromString('content.update'),
            AuthorizationResource::item('content', self::PAGE_ONE),
        )->allowed);

        self::assertCount(2, $records);
        self::assertSame([
            'subject' => self::SUBJECT,
            'request' => 'test-request-0001',
            'correlation' => 'test-request-0001',
            'site' => 'default',
            'strength' => 'bearer_token',
            'action' => 'content.read',
            'resource' => 'content:' . self::PAGE_ONE,
            'allowed' => true,
            'policy' => 'core.scoped-grants.v1',
            'reason' => 'matching_effective_grant',
        ], $records[0]);
        $denied = $records[1];
        self::assertIsArray($denied);
        self::assertFalse($denied['allowed']);
    }

    public function testAllowedOperationFailsClosedWhenDecisionAuditIsUnavailable(): void
    {
        $gateway = AuthorizationContext::gateway(new class implements AuthorizationDecisionRecorder {
            public function record(
                ExecutionContext $context,
                Capability $action,
                AuthorizationResource $resource,
                AuthorizationDecision $decision,
            ): void {
                throw new \RuntimeException('audit unavailable');
            }
        });

        $this->expectException(AuthorizationAuditUnavailable::class);
        $gateway->assertAllowed(
            AuthorizationContext::human(['content.read']),
            Capability::fromString('content.read'),
            AuthorizationResource::item('content', self::PAGE_ONE),
        );
    }

    public function testAuditFailureCannotTurnDenialIntoAnInfrastructureError(): void
    {
        $gateway = AuthorizationContext::gateway(new class implements AuthorizationDecisionRecorder {
            public function record(
                ExecutionContext $context,
                Capability $action,
                AuthorizationResource $resource,
                AuthorizationDecision $decision,
            ): void {
                throw new \RuntimeException('audit unavailable');
            }
        });

        $this->expectException(AuthorizationDenied::class);
        $gateway->assertAllowed(
            AuthorizationContext::human(['content.read']),
            Capability::fromString('content.update'),
            AuthorizationResource::item('content', self::PAGE_ONE),
        );
    }

    public function testDirectUseCaseInvocationDeniesBeforePersistence(): void
    {
        $repository = $this->createMock(ContentRepository::class);
        $repository->expects(self::never())->method('insert');
        $ownership = $this->createMock(ResourceSiteOwnershipWriter::class);
        $ownership->expects(self::never())->method('record');

        $this->expectException(AuthorizationDenied::class);
        $this->content($repository, $ownership)->create(
            $this->context('content.create', 'site', 'another-site'),
            'Restricted page',
            'restricted-page',
            [],
        );
    }

    public function testIdempotencyFingerprintChangesWithCredentialEpochAndScope(): void
    {
        $proof = AuthorizationContext::provenance();
        $grants = [[
            'capability' => 'content.update',
            'scope_type' => 'content',
            'scope_identifier' => self::PAGE_ONE,
        ]];
        $first = AuthenticatedPrincipal::issueFromGrantRows(
            $proof,
            self::SUBJECT,
            $grants,
            'api-token:first',
            1,
        );
        $rotated = AuthenticatedPrincipal::issueFromGrantRows(
            $proof,
            self::SUBJECT,
            $grants,
            'api-token:first',
            2,
        );
        $otherCredential = AuthenticatedPrincipal::issueFromGrantRows(
            $proof,
            self::SUBJECT,
            $grants,
            'api-token:second',
            1,
        );
        $otherScope = AuthenticatedPrincipal::issueFromGrantRows(
            $proof,
            self::SUBJECT,
            [[
                'capability' => 'content.update',
                'scope_type' => 'content',
                'scope_identifier' => self::PAGE_TWO,
            ]],
            'api-token:first',
            1,
        );

        self::assertNotSame($first->authorizationFingerprint(), $rotated->authorizationFingerprint());
        self::assertNotSame($first->authorizationFingerprint(), $otherCredential->authorizationFingerprint());
        self::assertNotSame($first->authorizationFingerprint(), $otherScope->authorizationFingerprint());
        self::assertNotSame(
            $first->context(
                SiteContext::default(),
                AuthenticationStrength::BearerToken,
                'fingerprint-bearer',
            )->authorizationFingerprint(),
            $first->context(
                SiteContext::default(),
                AuthenticationStrength::Password,
                'fingerprint-password',
            )->authorizationFingerprint(),
        );
    }

    public function testSensitiveCollectionReadFiltersRecordsByExactResourceScope(): void
    {
        $repository = $this->createMock(ContentRepository::class);
        $repository->expects(self::once())->method('all')->with(100, true, 0)->willReturn([
            $this->record(self::PAGE_ONE, 'Allowed page'),
            $this->record(self::PAGE_TWO, 'Hidden page'),
        ]);

        $records = $this->content($repository)->list(
            $this->context('content.read', 'content', self::PAGE_ONE),
            includeDeleted: true,
        );

        self::assertCount(1, $records);
        self::assertSame(self::PAGE_ONE, $records[0]->entry->id());
    }

    public function testCollectionLimitIsFilledAfterAuthorizationFiltering(): void
    {
        $repository = $this->createMock(ContentRepository::class);
        $firstPage = [];
        for ($index = 1; $index <= 50; ++$index) {
            $firstPage[] = $this->record(sprintf('00000000-0000-7000-8000-%012x', $index), 'Hidden ' . $index);
        }
        $repository->expects(self::exactly(2))->method('all')->willReturnMap([
            [50, true, 0, $firstPage],
            [50, true, 50, [$this->record(self::PAGE_ONE, 'Allowed page')]],
        ]);

        $records = $this->content($repository)->list(
            $this->context('content.read', 'content', self::PAGE_ONE),
            limit: 1,
            includeDeleted: true,
        );

        self::assertCount(1, $records);
        self::assertSame(self::PAGE_ONE, $records[0]->entry->id());
    }

    public function testSensitiveItemReadDeniesBeforeLoadingDraftOrDeletedRecord(): void
    {
        $repository = $this->createMock(ContentRepository::class);
        $repository->expects(self::never())->method('find');

        $this->expectException(AuthorizationDenied::class);
        $this->content($repository)->get(
            $this->context('content.read', 'content', self::PAGE_ONE),
            self::PAGE_TWO,
            includeDeleted: true,
        );
    }

    public function testSystemAuthorityIsExplicitAndConfinedToItsCapabilityAndSite(): void
    {
        $gateway = AuthorizationContext::gateway();
        $scheduler = AuthorizationContext::system(SystemIdentity::Scheduler);
        $gateway->assertAllowed(
            $scheduler->context(SiteContext::default(), 'scheduler-test-request'),
            Capability::fromString('system.scheduler.dispatch'),
            AuthorizationResource::collection('schedule'),
        );
        $gateway->assertAllowed(
            AuthorizationContext::system(SystemIdentity::Worker)->context(
                SiteContext::default(),
                'worker-operational-test',
            ),
            Capability::fromString('system.worker.operate'),
            AuthorizationResource::item('queue', 'default'),
        );
        $gateway->assertAllowed(
            AuthorizationContext::system(SystemIdentity::ExtensionMaterializer)->context(
                SiteContext::default(),
                'extension-materializer-test',
            ),
            Capability::fromString('extensions.manage'),
            AuthorizationResource::collection('extension_runtime_map'),
        );
        $gateway->assertAllowed(
            AuthorizationContext::system(SystemIdentity::InstallationMaintenance)->context(
                SiteContext::default(),
                'installation-maintenance-test',
            ),
            Capability::fromString('automation.manage'),
            AuthorizationResource::item('automation_installation', 'system.idempotency.purge'),
        );

        self::assertFalse($gateway->decide(
            $scheduler->context(SiteContext::default(), 'scheduler-escalation-test'),
            Capability::fromString('extensions.manage'),
            AuthorizationResource::collection('extension'),
        )->allowed);
        self::assertFalse($gateway->decide(
            $scheduler->context(SiteContext::fromString('another-site'), 'scheduler-cross-site-test'),
            Capability::fromString('automation.manage'),
            AuthorizationResource::collection('schedule'),
        )->allowed);
        self::assertFalse($gateway->decide(
            AuthorizationContext::system(SystemIdentity::Worker)->context(
                SiteContext::default(),
                'worker-global-escalation-test',
            ),
            Capability::fromString('extensions.manage'),
            AuthorizationResource::collection('extension_runtime_map'),
        )->allowed);
        self::assertFalse($gateway->decide(
            AuthorizationContext::system(SystemIdentity::Worker)->context(
                SiteContext::default(),
                'worker-global-maintenance-escalation-test',
            ),
            Capability::fromString('automation.manage'),
            AuthorizationResource::item('automation_installation', 'system.idempotency.purge'),
        )->allowed);
        self::assertFalse($gateway->decide(
            AuthorizationContext::human(['system.worker.operate']),
            Capability::fromString('system.worker.operate'),
            AuthorizationResource::item('queue', 'default'),
        )->allowed);
    }

    public function testHttpAdapterCannotTurnWrongScopeIntoAllowance(): void
    {
        $repository = $this->createMock(ContentRepository::class);
        $repository->expects(self::never())->method('insert');
        $handler = new ContentCollectionHandler(
            $this->content($repository),
            new ContentApiResponder(new ProblemDetailsResponseFactory()),
        );
        $context = $this->context('content.create', 'site', 'another-site');
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/api/v1/content')
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $context->principal())
            ->withAttribute(
                ExecutionContextAttribute::NAME,
                $context,
            )
            ->withBody((new StreamFactory())->createStream(
                '{"title":"Restricted page","slug":"restricted-page","data":{}}',
            ));

        $response = $handler->handle($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('authorization-denied', (string) $response->getBody());
    }

    /**
     * Prove the SDK-facing scope accessors disclose exactly the coordinates the context carries.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSdkScopeAccessorsDiscloseExactlyTheCarriedCoordinates(): void
    {
        $context = $this->context('content.read', 'content', self::PAGE_ONE);

        self::assertSame(SiteContext::DEFAULT, $context->siteIdentifier());
        self::assertNull($context->organizationIdentifier());
        self::assertNull($context->workspaceIdentifier());
        self::assertSame('api', $context->deliverySurface());
    }

    private function content(
        ContentRepository $repository,
        ?ResourceSiteOwnershipWriter $ownership = null,
    ): ContentService {
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-05T10:00:00+00:00'));

        return new ContentService(
            $repository,
            $this->createStub(AuditRecorder::class),
            $transactions,
            $clock,
            new Workflow(),
            AuthorizationContext::gateway(),
            $ownership ?? AuthorizationContext::ownershipWriter(),
        );
    }

    private function context(string $capability, string $scopeType, ?string $scopeIdentifier): ExecutionContext
    {
        return AuthorizationContext::principalFromGrantRows([[
            'capability' => $capability,
            'scope_type' => $scopeType,
            'scope_identifier' => $scopeIdentifier,
        ]], self::SUBJECT)->context(
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            'test-request-authorization',
        );
    }

    private function record(string $id, string $title): ContentRecord
    {
        $at = new DateTimeImmutable('2026-08-05T10:00:00+00:00');

        return new ContentRecord(
            ContentEntry::create($id, $title, strtolower(str_replace(' ', '-', $title))),
            ContentService::CORE_PAGE_TYPE_ID,
            ContentService::CORE_WORKFLOW_ID,
            $at,
            $at,
        );
    }
}
