<?php

declare(strict_types=1);

namespace Kumwe\App\Portal\Application;

use Kumwe\Context\Value\MembershipContext;
use Kumwe\Context\Value\SiteContext;

/**
 * Site and optional membership selected by trusted portal resolution rather than request input.
 *
 * @since  2.0.0
 */
final readonly class PortalContext
{
    /**
     * Hold a live server-resolved portal scope.
     *
     * @param  SiteContext         $site        Site selected by host and membership resolution.
     * @param  ?MembershipContext  $membership  Active membership and optional workspace snapshot.
     *
     * @since  2.0.0
     */
    public function __construct(
        public SiteContext $site,
        public ?MembershipContext $membership,
    ) {
    }
}
