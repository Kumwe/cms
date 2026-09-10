<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Application;

use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\Context\Value\SiteContext;

/**
 * Admits a complete post-publication definition set to derived public contracts before commit.
 *
 * @since  2.0.0
 */
interface BusinessDefinitionContractAdmission
{
    /**
     * Validate every declarative name that downstream public contracts derive for one site.
     *
     * @param   SiteContext                 $site         Site whose contract namespace is being admitted.
     * @param   list<EntityTypeDefinition>  $definitions  Complete bounded post-publication definition set.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When a derived contract name
     *          is unsafe or collides.
     *
     * @since   2.0.0
     */
    public function admit(SiteContext $site, array $definitions): void;
}
