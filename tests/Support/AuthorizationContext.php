<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use Kumwe\App\Application\Authorization\AuthorizationDecision;
use Kumwe\App\Application\Authorization\AuthorizationDecisionRecorder;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Authorization\DenyByDefaultAuthorizationGateway;
use Kumwe\App\Application\Authorization\MembershipContextValidator;
use Kumwe\App\Application\Authorization\OwnershipScope;
use Kumwe\App\Application\Authorization\ResourceOwnership;
use Kumwe\App\Application\Authorization\ResourceSiteOwnership;
use Kumwe\App\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Application\Authorization\SystemPrincipal;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\MembershipContext;
use Kumwe\Context\Value\OrganizationContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Context\Value\WorkspaceContext;
use Kumwe\Extension\Spi\Identity\Domain\Capability;

final class AuthorizationContext
{
    public const SUBJECT = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';

    private static ?object $provenance = null;

    /**
     * Issue a human test context with optional server-shaped membership state.
     *
     * @param   list<string>        $capabilities  Global capability names carried by the principal.
     * @param   string              $subject       Canonical test actor UUID.
     * @param   string              $site          Site identifier the context executes in.
     * @param   ?MembershipContext  $membership    Optional organization/workspace credential snapshot.
     *
     * @return  ExecutionContext  Provenance-bound test context.
     *
     * @since   2.0.0
     */
    public static function human(
        array $capabilities,
        string $subject = self::SUBJECT,
        string $site = SiteContext::DEFAULT,
        ?MembershipContext $membership = null,
    ): ExecutionContext {
        return self::principal($capabilities, $subject)->context(
            SiteContext::fromString($site),
            AuthenticationStrength::BearerToken,
            'test-request-0001',
            membership: $membership,
        );
    }

    /** @param list<string> $capabilities */
    public static function principal(array $capabilities, string $subject = self::SUBJECT): AuthenticatedPrincipal
    {
        return AuthenticatedPrincipal::issueFromStrings(self::provenance(), $subject, $capabilities);
    }

    /**
     * @param list<array{capability: string, scope_type: string, scope_identifier: ?string}> $grants
     */
    public static function principalFromGrantRows(
        array $grants,
        string $subject = self::SUBJECT,
    ): AuthenticatedPrincipal {
        return AuthenticatedPrincipal::issueFromGrantRows(self::provenance(), $subject, $grants);
    }

    public static function system(SystemIdentity $identity): SystemPrincipal
    {
        return SystemPrincipal::issue(self::provenance(), $identity);
    }

    public static function provenance(): object
    {
        return self::$provenance ??= new \stdClass();
    }

    /**
     * Construct a versioned membership snapshot for authorization boundary tests.
     *
     * @param   string   $organization       Selected organization identifier.
     * @param   ?string  $workspace          Selected workspace identifier, or null for organization-wide work.
     * @param   int      $membershipVersion  Stored membership version represented by the credential.
     * @param   int      $policyGeneration   Stored organization policy generation represented by the credential.
     * @param   string   $membershipId       Stable membership row UUID.
     *
     * @return  MembershipContext  Validated immutable membership snapshot.
     *
     * @since   2.0.0
     */
    public static function membership(
        string $organization = 'acme',
        ?string $workspace = null,
        int $membershipVersion = 1,
        int $policyGeneration = 1,
        string $membershipId = '018f22e2-7c8b-7ab0-8f3a-88e8026bb302',
    ): MembershipContext {
        return new MembershipContext(
            $membershipId,
            OrganizationContext::fromString($organization),
            $workspace === null ? null : WorkspaceContext::fromString($workspace),
            $membershipVersion,
            $policyGeneration,
        );
    }

    /**
     * Build the canonical gateway around optional test doubles for external decision authorities.
     *
     * @param   ?AuthorizationDecisionRecorder  $recorder     Optional decision recorder; defaults to a no-op sink.
     * @param   ?ResourceSiteOwnership          $ownership    Optional site authority; defaults to the test site.
     * @param   ?MembershipContextValidator     $memberships  Optional live membership validator; defaults to deny.
     *
     * @return  DenyByDefaultAuthorizationGateway  Configured canonical authorization gateway.
     *
     * @since   2.0.0
     */
    public static function gateway(
        ?AuthorizationDecisionRecorder $recorder = null,
        ?ResourceSiteOwnership $ownership = null,
        ?MembershipContextValidator $memberships = null,
    ): DenyByDefaultAuthorizationGateway {
        return new DenyByDefaultAuthorizationGateway(
            self::provenance(),
            (new ExtensionContributionRegistrySet())->authorizationPolicies(),
            $memberships ?? new class implements MembershipContextValidator {
                /**
                 * Fail closed when a test did not explicitly supply live membership state.
                 *
                 * @param   string             $subjectId   Actor expected to hold the membership.
                 * @param   SiteContext        $site        Exact site the decision executes in.
                 * @param   MembershipContext  $membership  Credential snapshot being checked.
                 * @param   bool               $lock        Whether a caller requested a mutation lock.
                 *
                 * @return  bool  Always false.
                 *
                 * @since   2.0.0
                 */
                public function current(
                    string $subjectId,
                    SiteContext $site,
                    MembershipContext $membership,
                    bool $lock = false,
                ): bool {
                    return false;
                }
            },
            $ownership ?? self::ownership(),
            $recorder ?? new class implements AuthorizationDecisionRecorder {
                public function record(
                    ExecutionContext $context,
                    Capability $action,
                    AuthorizationResource $resource,
                    AuthorizationDecision $decision,
                ): void {
                }
            },
        );
    }

    public static function ownership(string $site = SiteContext::DEFAULT): ResourceSiteOwnership
    {
        return new class ($site) implements ResourceSiteOwnership {
            public function __construct(private string $site)
            {
            }

            public function scopeFor(AuthorizationResource $resource): OwnershipScope
            {
                return OwnershipScope::site(SiteContext::fromString($this->site));
            }
        };
    }

    public static function ownershipWriter(): ResourceSiteOwnershipWriter
    {
        return new class implements ResourceSiteOwnershipWriter {
            public function record(AuthorizationResource $resource, SiteContext $site): void
            {
            }

            public function remove(AuthorizationResource $resource, SiteContext $expectedSite): void
            {
            }

            public function reassign(ResourceOwnership $owner, OwnershipScope $expected): void
            {
            }
        };
    }

    public static function siteScoped(string $capability, string $site = SiteContext::DEFAULT): ExecutionContext
    {
        return self::principalFromGrantRows([[
            'capability' => $capability,
            'scope_type' => 'site',
            'scope_identifier' => $site,
        ]])->context(
            SiteContext::fromString($site),
            AuthenticationStrength::BearerToken,
            'test-request-scoped-0001',
        );
    }
}
