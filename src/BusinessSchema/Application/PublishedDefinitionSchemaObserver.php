<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Application;

use DateTimeImmutable;
use Kumwe\App\BusinessDefinition\Application\DefinitionVersionRecord;
use Kumwe\App\BusinessSchema\Domain\SchemaPlan;
use Kumwe\Context\Value\SiteContext;

/**
 * Port the definition publisher notifies so schema plans exist for the versions it just published.
 *
 * Publishing a definition and changing the database are deliberately separate acts: publication is an
 * editorial decision that must stay cheap and reversible, while applying DDL is an operational one that
 * carries its own approval and its own recovery evidence. This port is the seam between them.
 * `BusinessDefinitionService` hands over the versions it published from inside the very transaction
 * that publishes them, and `DoctrinePackageDefinitionSynchronizer` does the same once an extension's
 * declarations are installed; both receive plans that sit untouched until an operator inspects and
 * approves them. The whole graph arrives in one call rather than one definition
 * at a time, because definitions published together may reference each other and their foreign keys
 * cannot be planned from any one of them alone. Both callers hold the port optionally, so an
 * installation with no schema services still publishes definitions.
 *
 * @since  2.0.0
 */
interface PublishedDefinitionSchemaObserver
{
    /**
     * Persists proposed plans for a complete published graph. It never executes DDL.
     *
     * Republishing a graph whose plans would be identical returns the plans already on record instead
     * of a second set, so a repeated package synchronization leaves the approval queue unchanged.
     *
     * @param   SiteContext                    $site             Site the published definitions belong to.
     * @param   list<DefinitionVersionRecord>  $definitions      Every version published in this one graph.
     * @param   string                         $actorIdentifier  Actor credited as the author of the plans.
     * @param   DateTimeImmutable              $now              Instant the plans are stamped as created at.
     *
     * @return  list<SchemaPlan>  One plan per supplied definition; an unchanged one reuses its existing plan.
     *
     * @since   2.0.0
     */
    public function observePublishedGraph(
        SiteContext $site,
        array $definitions,
        string $actorIdentifier,
        DateTimeImmutable $now,
    ): array;
}
