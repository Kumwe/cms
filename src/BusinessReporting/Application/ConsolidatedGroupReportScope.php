<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Application;

use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Authorization\SiteGroupRegistry;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Identity\Domain\Capability;

/**
 * Resolves the sites a consolidated report may read across, once the caller has proven it may.
 *
 * A group of businesses keeps its books apart and still has to see them together. Unification therefore
 * happens at the read layer and nowhere else: this returns a list of sites for a query to restrict itself
 * to, and grants nothing. `reports.consolidated.read` is bound to one resource type — the group — so a
 * caller holding it holds authority over a reporting boundary and over nothing that can be written. Every
 * write in the installation still resolves its own resource's owning scope through the same gateway, and
 * a caller whose only authority is this capability fails that check exactly as it did before groups
 * existed.
 *
 * The decision is taken through the ordinary gateway, so a consolidated read is authorized, recorded and
 * refused on the same terms as any other read, and the group's own ownership scope is what settles
 * whether the caller's site is entitled to ask at all.
 *
 * @since  2.0.0
 */
final readonly class ConsolidatedGroupReportScope
{
    /**
     * Capability a consolidated read is gated on.
     *
     * @var    string
     * @since  2.0.0
     */
    private const CAPABILITY = 'reports.consolidated.read';

    /**
     * Wire the resolver to the gateway and the declared groups it reads membership from.
     *
     * @param  AuthorizationGateway  $authorization  Guard consulted before the membership is revealed.
     * @param  SiteGroupRegistry     $groups         Declared groups the reporting membership comes from.
     *
     * @since  2.0.0
     */
    public function __construct(
        private AuthorizationGateway $authorization,
        private SiteGroupRegistry $groups,
    ) {
    }

    /**
     * Name the sites a consolidated report over one group may read across.
     *
     * @param   ExecutionContext  $context     Caller identity, site and provenance the report runs under.
     * @param   string            $identifier  Declared group the report consolidates.
     *
     * @return  list<string>  Member site identifiers, in site-identifier order, for a query to restrict
     *          itself to; never wider than the declared membership and never empty.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the caller may not read
     *          consolidated reports for this group, or is not itself inside it.
     * @throws  \Kumwe\App\Application\Authorization\SiteGroupUnknown  When the group resolves to nothing.
     *
     * @since   2.0.0
     */
    public function sitesFor(ExecutionContext $context, string $identifier): array
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString(self::CAPABILITY),
            AuthorizationResource::item('site_group', $identifier),
        );

        return $this->groups->group($identifier)->members;
    }
}
