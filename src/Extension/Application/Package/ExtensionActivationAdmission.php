<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Application\Package;

use Kumwe\Context\Value\SiteContext;
use Kumwe\Extension\Manifest\ExtensionManifest;

/**
 * Validates a candidate extension's declarative public contract before activation is committed.
 *
 * Implementations receive only manifests already accepted by the package trust boundary. They must not
 * execute provider code, write caches, or publish runtime state. Throwing leaves the lifecycle transaction
 * to roll back the candidate status and its definition availability before any new generation is staged.
 *
 * @since  2.0.0
 */
interface ExtensionActivationAdmission
{
    /**
     * Admit one candidate against every extension that would be active if the transaction committed.
     *
     * @param   ExtensionManifest        $candidate        Installed candidate being enabled or upgraded.
     * @param   SiteContext              $site             Site whose contributed definitions are changing.
     * @param   list<ExtensionManifest>  $activeManifests  Authoritative post-change active manifest set.
     *
     * @return  void
     *
     * @throws  \InvalidArgumentException  When a declaration is unsafe, ambiguous, or collides with another
     *          active/core public contract.
     *
     * @since   2.0.0
     */
    public function admit(
        ExtensionManifest $candidate,
        SiteContext $site,
        array $activeManifests,
    ): void;
}
