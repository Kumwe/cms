<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use Kumwe\Context\Value\SiteContext;
use Kumwe\Producer\Error\HostRefusal;
use stdClass;

/**
 * Fail-closed publication boundary invoked before an App-owned Blueprint becomes public.
 *
 * @since  2.0.0
 */
interface StudioArtifactPublicationGuard
{
    /**
     * Prove the candidate can be reproduced by the exact live public runtime.
     *
     * @param   SiteContext  $site       Trusted owning site.
     * @param   stdClass     $blueprint  Schema-admitted App-owned Blueprint document.
     *
     * @return  void
     *
     * @throws  HostRefusal  When any immutable public dependency is unavailable.
     *
     * @since   2.0.0
     */
    public function assertPublishable(SiteContext $site, stdClass $blueprint): void;
}
