<?php

declare(strict_types=1);

namespace Kumwe\App\Kernel;

use Closure;
use DateTimeImmutable;
use Kumwe\App\BusinessDefinition\Application\DefinitionVersionRecord;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaLifecycleObserver;
use Kumwe\App\BusinessSchema\Application\PublishedDefinitionSchemaObserver;
use Kumwe\App\BusinessSchema\Domain\SchemaPlan;
use Kumwe\Context\Value\SiteContext;

/**
 * Stand-in for both business-schema observers that resolves the real ones only when a call arrives.
 *
 * Breaks the composition-time cycle between the trusted extension registry and schema compilation
 * without hiding either runtime dependency from `ContainerFactory`: the extension wiring is assembled
 * before the schema services can be pulled out of the container, so `PackageDefinitionSynchronizer`
 * is handed this proxy for both roles while each closure stays declared at the wiring site. It adds
 * no behaviour of its own — every call is forwarded verbatim to whatever the closure returns, and the
 * closure runs afresh each time rather than being memoised.
 *
 * @since  2.0.0
 */
final readonly class DeferredBusinessSchemaObserver implements
    PublishedDefinitionSchemaObserver,
    BusinessSchemaLifecycleObserver
{
    /**
     * Capture the two lookups the forwarding methods go through.
     *
     * @param  Closure(): PublishedDefinitionSchemaObserver  $publication  Late lookup of the planning observer.
     * @param  Closure(): BusinessSchemaLifecycleObserver    $lifecycle    Late lookup of the lifecycle observer.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Closure $publication,
        private Closure $lifecycle,
    ) {
    }

    /**
     * Forward a whole published definition graph to the observer the publication closure returns.
     *
     * Resolution happens on this call, so the container must be fully composed by the time the first
     * graph is published; a lookup failure surfaces here rather than during wiring.
     *
     * @param   SiteContext                    $site             Site whose schema plans are being recorded.
     * @param   list<DefinitionVersionRecord>  $definitions      Every definition published in one graph.
     * @param   string                         $actorIdentifier  Identity credited with the resulting plans.
     * @param   DateTimeImmutable              $now              Instant the delegate stamps the plans with.
     *
     * @return  list<SchemaPlan>  The plans the delegate persisted, unaltered and in its own order.
     *
     * @since   2.0.0
     */
    public function observePublishedGraph(
        SiteContext $site,
        array $definitions,
        string $actorIdentifier,
        DateTimeImmutable $now,
    ): array {
        return ($this->publication)()->observePublishedGraph(
            $site,
            $definitions,
            $actorIdentifier,
            $now,
        );
    }

    /**
     * Forward an owner's activation change to the observer the lifecycle closure returns.
     *
     * @param   string             $ownerIdentifier  Extension owner whose installed schemas change availability.
     * @param   bool               $active           True to re-enable the owner, false to disable it.
     * @param   DateTimeImmutable  $at               Instant the delegate records against the installations.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function setOwnerActive(string $ownerIdentifier, bool $active, DateTimeImmutable $at): void
    {
        ($this->lifecycle)()->setOwnerActive($ownerIdentifier, $active, $at);
    }
}
