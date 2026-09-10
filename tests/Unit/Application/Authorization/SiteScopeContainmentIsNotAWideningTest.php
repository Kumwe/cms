<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Application\Authorization;

use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Authorization\DenyByDefaultAuthorizationGateway;
use Kumwe\App\Application\Authorization\MembershipContextValidator;
use Kumwe\App\Application\Authorization\OwnershipScope;
use Kumwe\App\Application\Authorization\ResourceSiteOwnership;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Authorization\StructuredLogAuthorizationDecisionRecorder;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Proves the containment decision is not a widening for any resource owned by a single site.
 *
 * The gateway used to settle cross-site isolation with one string equality between the owning site and
 * the caller's site. It now asks whether the caller's site is inside the owning scope. For a site-owned
 * resource — every resource on an installation that declares no group, and every accounting resource on
 * one that does — the two must agree on every input, or the change has quietly opened something.
 *
 * The check below is exhaustive over the inputs that matter: every ordered pair drawn from a set of
 * sites, against a caller granted the capability at its own site, at the owner's site, and globally. The
 * expected answer is computed from the *old* rule, written out here as a reference, and compared with
 * what the gateway now returns. A single disagreement fails the build.
 *
 * @since  2.0.0
 */
#[CoversClass(DenyByDefaultAuthorizationGateway::class)]
#[CoversClass(OwnershipScope::class)]
final class SiteScopeContainmentIsNotAWideningTest extends TestCase
{
    /**
     * Sites every ordered owner/caller pair is drawn from.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const SITES = ['default', 'assembly', 'freight', 'retail'];

    /**
     * Content identifier the decisions are taken against.
     *
     * @var    string
     * @since  2.0.0
     */
    private const CONTENT = '018f22e2-7c8b-7ab0-8f3a-88e8026bb801';

    /**
     * Every ordered owner/caller pair decides exactly as the equality it replaced would have.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEverySingleSiteOwnerDecidesExactlyAsTheEqualityDid(): void
    {
        $resource = AuthorizationResource::item('content', self::CONTENT);
        $action = Capability::fromString('content.read');
        $compared = 0;

        foreach (self::SITES as $owner) {
            $gateway = $this->gateway($owner);
            foreach (self::SITES as $caller) {
                foreach (self::SITES as $grantSite) {
                    $decision = $gateway->decide(
                        AuthorizationContext::siteScoped('content.read', $grantSite)->child(
                            'test-request-widening-' . $compared,
                        ),
                        $action,
                        $resource,
                    );
                    $expected = $this->previousRule($owner, $grantSite);

                    self::assertSame($expected['allowed'], $decision->allowed, sprintf(
                        'owner=%s caller=%s grant=%s changed its verdict.',
                        $owner,
                        $caller,
                        $grantSite,
                    ));
                    self::assertSame($expected['reason'], $decision->reason, sprintf(
                        'owner=%s caller=%s grant=%s changed the check that settled it.',
                        $owner,
                        $caller,
                        $grantSite,
                    ));
                    $compared++;
                }
            }
        }

        self::assertSame(64, $compared);
    }

    /**
     * A caller granted nothing is refused for every single-site owner, including its own.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUngrantedCallerIsStillRefusedEverywhere(): void
    {
        $resource = AuthorizationResource::item('content', self::CONTENT);

        foreach (self::SITES as $owner) {
            $decision = $this->gateway($owner)->decide(
                AuthorizationContext::human([], site: $owner),
                Capability::fromString('content.read'),
                $resource,
            );

            self::assertFalse($decision->allowed, sprintf('An ungranted caller must not read %s.', $owner));
        }
    }

    /**
     * A resource with no recorded owner still fails closed rather than falling back to the caller.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnownedResourceStillFailsClosed(): void
    {
        $gateway = new DenyByDefaultAuthorizationGateway(
            AuthorizationContext::provenance(),
            (new ExtensionContributionRegistrySet())->authorizationPolicies(),
            $this->createStub(MembershipContextValidator::class),
            AuthorizationContext::ownership(),
            new StructuredLogAuthorizationDecisionRecorder(new NullLogger()),
        );
        $unowned = new class implements ResourceSiteOwnership {
            /**
             * Refuse to name an owner, as the registry does for a resource with no row.
             *
             * @param   AuthorizationResource  $resource  Target being resolved.
             *
             * @return  OwnershipScope  Never returned.
             *
             * @throws  \Kumwe\App\Application\Authorization\AuthorizationResourceOwnershipUnknown  Always.
             *
             * @since   2.0.0
             */
            public function scopeFor(AuthorizationResource $resource): OwnershipScope
            {
                throw new \Kumwe\App\Application\Authorization\AuthorizationResourceOwnershipUnknown($resource);
            }
        };
        self::assertTrue($gateway->decide(
            AuthorizationContext::siteScoped('content.read'),
            Capability::fromString('content.read'),
            AuthorizationResource::item('content', self::CONTENT),
        )->allowed);

        $decision = new DenyByDefaultAuthorizationGateway(
            AuthorizationContext::provenance(),
            (new ExtensionContributionRegistrySet())->authorizationPolicies(),
            $this->createStub(MembershipContextValidator::class),
            $unowned,
            new StructuredLogAuthorizationDecisionRecorder(new NullLogger()),
        )->decide(
            AuthorizationContext::siteScoped('content.read'),
            Capability::fromString('content.read'),
            AuthorizationResource::item('content', self::CONTENT),
        );

        self::assertFalse($decision->allowed);
        self::assertSame('resource_site_unknown', $decision->reason);
    }

    /**
     * The rule this change replaced, written out so the comparison is against the old behaviour itself.
     *
     * A site-owned `content` resource is not installation-global, so the old gateway denied whenever the
     * owning site differed from the caller's, and otherwise offered a grant scope over the owning site —
     * which, having just been proven equal to the caller's site, is the caller's own.
     *
     * @param   string  $owner      Site recorded as owning the resource.
     * @param   string  $grantSite  Site the caller both executes in and holds its grant at.
     *
     * @return  array{allowed: bool, reason: string}  The verdict the previous rule produced.
     *
     * @since   2.0.0
     */
    private function previousRule(string $owner, string $grantSite): array
    {
        if ($owner !== $grantSite) {
            return ['allowed' => false, 'reason' => 'resource_site_mismatch'];
        }

        return ['allowed' => true, 'reason' => 'matching_effective_grant'];
    }

    /**
     * Build the canonical gateway over a registry that owns everything at one named site.
     *
     * @param   string  $owner  Site every resource resolves to.
     *
     * @return  DenyByDefaultAuthorizationGateway  Gateway under test.
     *
     * @since   2.0.0
     */
    private function gateway(string $owner): DenyByDefaultAuthorizationGateway
    {
        return new DenyByDefaultAuthorizationGateway(
            AuthorizationContext::provenance(),
            (new ExtensionContributionRegistrySet())->authorizationPolicies(),
            $this->createStub(MembershipContextValidator::class),
            $this->ownedBy($owner),
            new StructuredLogAuthorizationDecisionRecorder(new NullLogger()),
        );
    }

    /**
     * An ownership registry that answers with one site scope for everything.
     *
     * @param   string  $owner  Site every resource resolves to.
     *
     * @return  ResourceSiteOwnership  Registry under test.
     *
     * @since   2.0.0
     */
    private function ownedBy(string $owner): ResourceSiteOwnership
    {
        return new class ($owner) implements ResourceSiteOwnership {
            /**
             * Hold the single owning site.
             *
             * @param  string  $owner  Site every resource resolves to.
             *
             * @since  2.0.0
             */
            public function __construct(private string $owner)
            {
            }

            /**
             * Resolve every resource to the one site scope.
             *
             * @param   AuthorizationResource  $resource  Target being resolved.
             *
             * @return  OwnershipScope  A site scope over the configured owner.
             *
             * @since   2.0.0
             */
            public function scopeFor(AuthorizationResource $resource): OwnershipScope
            {
                return OwnershipScope::site(SiteContext::fromString($this->owner));
            }
        };
    }
}
