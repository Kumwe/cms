<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Application\Authorization;

use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Authorization\AuthorizationResourceOwnershipUnknown;
use Kumwe\App\Application\Authorization\DenyByDefaultAuthorizationGateway;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Application\Authorization\OwnershipScope;
use Kumwe\App\Application\Authorization\ResourceSiteOwnership;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Authorization\SiteGroup;
use Kumwe\App\Application\Authorization\SiteGroupRegistry;
use Kumwe\App\Application\Authorization\SiteGroupUnknown;
use Kumwe\App\BusinessReporting\Application\ConsolidatedGroupReportScope;
use Kumwe\App\Extension\Contribution\CapabilityDefinition;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Contribution\ResourcePolicyDefinition;
use Kumwe\App\Application\Authorization\ResourcePolicyTarget;
use Kumwe\App\Application\Authorization\StructuredLogAuthorizationDecisionRecorder;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Walks the four-business installation the ownership model exists to serve.
 *
 * Four legal entities share one installation, share their people by explicit declaration, keep their
 * books apart, and consolidate their reporting. Every assertion here is a sentence the owner stated as a
 * requirement, checked against the gateway that actually decides it.
 *
 * @since  2.0.0
 */
#[CoversClass(DenyByDefaultAuthorizationGateway::class)]
#[CoversClass(ConsolidatedGroupReportScope::class)]
#[CoversClass(OwnershipScope::class)]
#[CoversClass(SiteGroup::class)]
final class BusinessGroupOwnershipTest extends TestCase
{
    /**
     * The four businesses sharing the installation.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const BUSINESSES = ['assembly', 'freight', 'retail', 'workshop'];

    /**
     * Identifier of the person record the four businesses share.
     *
     * @var    string
     * @since  2.0.0
     */
    private const SHARED_PERSON = '018f22e2-7c8b-7ab0-8f3a-88e8026bb701';

    /**
     * Identifier of the ledger belonging to one business alone.
     *
     * @var    string
     * @since  2.0.0
     */
    private const ASSEMBLY_LEDGER = '018f22e2-7c8b-7ab0-8f3a-88e8026bb702';

    /**
     * Capability the payroll extension binds to reading a person or a ledger.
     *
     * @var    string
     * @since  2.0.0
     */
    private const READ = 'kumwe.payroll.read';

    /**
     * Capability the payroll extension binds to changing a person or a ledger.
     *
     * @var    string
     * @since  2.0.0
     */
    private const WRITE = 'kumwe.payroll.write';

    /**
     * A person owned by the declared group is readable from every business in it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSharedStaffAreVisibleFromEveryBusinessOfTheGroup(): void
    {
        $gateway = $this->gateway();

        foreach (self::BUSINESSES as $business) {
            $decision = $gateway->decide(
                $this->actor(self::READ, $business),
                Capability::fromString(self::READ),
                AuthorizationResource::item('person', self::SHARED_PERSON),
            );

            self::assertTrue($decision->allowed, sprintf('%s must see the shared person.', $business));
        }
    }

    /**
     * A site outside the declared group sees nothing the group owns.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testASiteOutsideTheGroupSeesNothingItOwns(): void
    {
        $decision = $this->gateway()->decide(
            $this->actor(self::READ, 'unrelated'),
            Capability::fromString(self::READ),
            AuthorizationResource::item('person', self::SHARED_PERSON),
        );

        self::assertFalse($decision->allowed);
        self::assertSame('resource_site_mismatch', $decision->reason);
    }

    /**
     * A business's books stay invisible to the other three, group or no group.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAccountingRowsAreNotVisibleAcrossTheGroup(): void
    {
        $gateway = $this->gateway();

        foreach (['freight', 'retail', 'workshop'] as $business) {
            $decision = $gateway->decide(
                $this->actor(self::READ, $business),
                Capability::fromString(self::READ),
                AuthorizationResource::item('ledger', self::ASSEMBLY_LEDGER),
            );

            self::assertFalse($decision->allowed, sprintf('%s must not reach another entity\'s books.', $business));
            self::assertSame('resource_site_mismatch', $decision->reason);
        }

        self::assertTrue($gateway->decide(
            $this->actor(self::READ, 'assembly'),
            Capability::fromString(self::READ),
            AuthorizationResource::item('ledger', self::ASSEMBLY_LEDGER),
        )->allowed);
    }

    /**
     * A consolidated report spans all four businesses once the reading capability is held.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAConsolidatedReportSpansAllFourBusinesses(): void
    {
        $scope = new ConsolidatedGroupReportScope($this->gateway(), $this->groupRegistry());

        $sites = $scope->sitesFor(
            $this->actor('reports.consolidated.read', 'retail'),
            'kumwe-group',
        );

        self::assertSame(self::BUSINESSES, $sites);
    }

    /**
     * Consolidated reading is a read capability and buys no write anywhere in the group.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testConsolidatedReadingGrantsNoWriteAcrossTheBoundary(): void
    {
        $gateway = $this->gateway();
        $reader = $this->actor('reports.consolidated.read', 'retail');

        foreach ([self::SHARED_PERSON => 'person', self::ASSEMBLY_LEDGER => 'ledger'] as $id => $type) {
            $decision = $gateway->decide(
                $reader,
                Capability::fromString(self::WRITE),
                AuthorizationResource::item($type, $id),
            );

            self::assertFalse(
                $decision->allowed,
                sprintf('Consolidated reading must not authorize a %s write.', $type),
            );
            self::assertNotSame('matching_effective_grant', $decision->reason);
        }
    }

    /**
     * Writes stay isolated even where reads unify: the group shares, the books do not.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testWritesStayIsolatedWhereReadsUnify(): void
    {
        $gateway = $this->gateway();
        $writer = $this->actor(self::WRITE, 'retail');

        self::assertTrue($gateway->decide(
            $writer,
            Capability::fromString(self::WRITE),
            AuthorizationResource::item('person', self::SHARED_PERSON),
        )->allowed);

        $refused = $gateway->decide(
            $writer,
            Capability::fromString(self::WRITE),
            AuthorizationResource::item('ledger', self::ASSEMBLY_LEDGER),
        );
        self::assertFalse($refused->allowed);
        self::assertSame('resource_site_mismatch', $refused->reason);
    }

    /**
     * A caller who may consolidate cannot consolidate a group it does not belong to.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testConsolidatedReadingIsRefusedForAGroupTheCallerIsOutside(): void
    {
        $scope = new ConsolidatedGroupReportScope($this->gateway(), $this->groupRegistry());

        $this->expectException(\Kumwe\App\Application\Authorization\AuthorizationDenied::class);
        $scope->sitesFor($this->actor('reports.consolidated.read', 'unrelated'), 'kumwe-group');
    }

    /**
     * A group scope does not pool the member sites' grants; each caller still acts for its own site.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAGroupDoesNotPoolTheMemberSitesGrants(): void
    {
        $freightGrantHeldByARetailCaller = AuthorizationContext::principalFromGrantRows([[
            'capability' => self::READ,
            'scope_type' => 'site',
            'scope_identifier' => 'freight',
        ]])->context(
            SiteContext::fromString('retail'),
            \Kumwe\Context\Value\AuthenticationStrength::BearerToken,
            'test-request-group-0001',
        );

        $decision = $this->gateway()->decide(
            $freightGrantHeldByARetailCaller,
            Capability::fromString(self::READ),
            AuthorizationResource::item('person', self::SHARED_PERSON),
        );

        self::assertFalse($decision->allowed);
        self::assertSame('no_matching_effective_grant', $decision->reason);
    }

    /**
     * Build a gateway over the four-business ownership map.
     *
     * @return  DenyByDefaultAuthorizationGateway  Gateway resolving the group-owned person, the
     *          site-owned ledger and the group itself.
     *
     * @since   2.0.0
     */
    private function gateway(): DenyByDefaultAuthorizationGateway
    {
        return new DenyByDefaultAuthorizationGateway(
            AuthorizationContext::provenance(),
            $this->policies(),
            $this->createStub(\Kumwe\App\Application\Authorization\MembershipContextValidator::class),
            $this->ownership(),
            new StructuredLogAuthorizationDecisionRecorder(new \Psr\Log\NullLogger()),
        );
    }

    /**
     * Register the payroll extension's categories the way an extension author would.
     *
     * The two categories the scenario turns on — a person and a ledger — are contributed here, bound to
     * one read and one write capability. Their ownership-scope rules are not contributed: this build
     * reserves them, which is exactly the property the accounting assertions rely on.
     *
     * @return  \Kumwe\App\Application\Authorization\AuthorizationPolicyRegistry  Core plus the payroll
     *          extension's bindings.
     *
     * @since   2.0.0
     */
    private function policies(): \Kumwe\App\Application\Authorization\AuthorizationPolicyRegistry
    {
        $registries = new ExtensionContributionRegistrySet();
        $owner = ContributionOwner::extension('kumwe/payroll');
        foreach ([self::READ => 'Read', self::WRITE => 'Change'] as $capability => $verb) {
            $registries->capabilities()->register($owner, new CapabilityDefinition(
                $capability,
                $verb . ' payroll master data',
                $verb . ' the people and books the payroll extension contributes.',
                ['global', 'site', 'person', 'ledger'],
            ));
            $registries->resourcePolicies()->register($owner, new ResourcePolicyDefinition(
                $capability . '.master',
                $capability,
                [new ResourcePolicyTarget('person'), new ResourcePolicyTarget('ledger')],
            ));
        }
        return $registries->authorizationPolicies();
    }

    /**
     * The ownership map this installation would hold once the group is declared.
     *
     * @return  ResourceSiteOwnership  Authoritative resolver for the two resources under test.
     *
     * @since   2.0.0
     */
    private function ownership(): ResourceSiteOwnership
    {
        $group = OwnershipScope::group($this->group());
        $ledger = OwnershipScope::site(SiteContext::fromString('assembly'));

        return new class ($group, $ledger) implements ResourceSiteOwnership {
            /**
             * Hold the two owners the four-business scenario needs.
             *
             * @param  OwnershipScope  $group   Owner of the shared person and of the group itself.
             * @param  OwnershipScope  $ledger  Owner of one entity's books.
             *
             * @since  2.0.0
             */
            public function __construct(private OwnershipScope $group, private OwnershipScope $ledger)
            {
            }

            /**
             * Resolve the owner of a resource in the four-business installation.
             *
             * @param   AuthorizationResource  $resource  Target being resolved.
             *
             * @return  OwnershipScope  The recorded owner.
             *
             * @throws  AuthorizationResourceOwnershipUnknown  When the scenario records no owner.
             *
             * @since   2.0.0
             */
            public function scopeFor(AuthorizationResource $resource): OwnershipScope
            {
                return match ($resource->type()) {
                    'person', 'site_group' => $this->group,
                    'ledger' => $this->ledger,
                    default => throw new AuthorizationResourceOwnershipUnknown($resource),
                };
            }
        };
    }

    /**
     * The declared group of four businesses.
     *
     * @return  SiteGroup  Declaration naming all four member sites.
     *
     * @since   2.0.0
     */
    private function group(): SiteGroup
    {
        return new SiteGroup('kumwe-group', 'Kumwe business group', self::BUSINESSES);
    }

    /**
     * A registry answering for the one declared group.
     *
     * @return  SiteGroupRegistry  Registry resolving `kumwe-group` and nothing else.
     *
     * @since   2.0.0
     */
    private function groupRegistry(): SiteGroupRegistry
    {
        $group = $this->group();

        return new class ($group) implements SiteGroupRegistry {
            /**
             * Hold the single declared group.
             *
             * @param  SiteGroup  $group  Declaration this registry answers with.
             *
             * @since  2.0.0
             */
            public function __construct(private SiteGroup $group)
            {
            }

            /**
             * Resolve the declared group.
             *
             * @param   string  $identifier  Group identifier being resolved.
             *
             * @return  SiteGroup  The declaration.
             *
             * @throws  SiteGroupUnknown  When another identifier is asked for.
             *
             * @since   2.0.0
             */
            public function group(string $identifier): SiteGroup
            {
                if ($identifier !== $this->group->identifier) {
                    throw new SiteGroupUnknown($identifier);
                }

                return $this->group;
            }

            /**
             * List the declarations this registry holds.
             *
             * @return  list<SiteGroup>  The single declared group.
             *
             * @since   2.0.0
             */
            public function all(): array
            {
                return [$this->group];
            }
        };
    }

    /**
     * Build a caller holding one capability at one business's site.
     *
     * @param   string  $capability  Capability granted at the caller's own site.
     * @param   string  $site        Business the caller is executing in.
     *
     * @return  ExecutionContext  Provenance-bound context for that business.
     *
     * @since   2.0.0
     */
    private function actor(string $capability, string $site): ExecutionContext
    {
        return AuthorizationContext::siteScoped($capability, $site);
    }
}
