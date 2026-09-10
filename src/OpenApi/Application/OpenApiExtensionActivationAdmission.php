<?php

declare(strict_types=1);

namespace Kumwe\App\OpenApi\Application;

use Closure;
use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\App\BusinessDefinition\Domain\DefinitionOwnerType;
use Kumwe\App\BusinessDefinition\Domain\DefinitionStatus;
use Kumwe\App\Extension\Application\Package\ExtensionActivationAdmission;
use Kumwe\App\Extension\Contribution\CanonicalManifestInterpreter;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Extension\Manifest\ExtensionManifest;

/**
 * Rejects extension-owned generated OpenAPI component collisions before lifecycle publication.
 *
 * Generic business routes are core-owned and fixed, so an extension cannot declare an HTTP path or
 * operation identifier. Its collision surface is the deterministic component family derived from entity,
 * view, and action handles. This admission pass evaluates that complete family across the post-activation
 * manifest set without running provider code. Custom input/output schemas have already crossed the closed,
 * bounded `CustomBusinessSchema` parser while each manifest was constructed.
 *
 * @since  2.0.0
 */
final readonly class OpenApiExtensionActivationAdmission implements ExtensionActivationAdmission
{
    /**
     * Bind extension activation to the same component claims used by site publication.
     *
     * @param  OpenApiComponentClaimAdmission            $claims       Shared declarative component-name admission.
     * @param  ?Closure(): BusinessDefinitionRepository  $definitions  Late repository lookup for core/site heads.
     *
     * @since  2.0.0
     */
    public function __construct(
        private OpenApiComponentClaimAdmission $claims,
        private ?Closure $definitions = null,
    ) {
    }

    /**
     * Validate every generated component name in the authoritative post-activation manifest set.
     *
     * @param   ExtensionManifest        $candidate        Candidate that must occur in the active set.
     * @param   SiteContext              $site             Site whose definitions participate in this contract.
     * @param   list<ExtensionManifest>  $activeManifests  Post-change active manifests in stable order.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the set is unbounded, omits the candidate, or claims a component
     *          twice (including normalization collisions and core shadowing).
     *
     * @since   2.0.0
     */
    public function admit(
        ExtensionManifest $candidate,
        SiteContext $site,
        array $activeManifests,
    ): void {
        if (!array_is_list($activeManifests) || count($activeManifests) > 1024) {
            throw new InvalidArgumentException('The active extension contract set is invalid or unbounded.');
        }
        $candidateSeen = false;
        $definitions = $this->publishedDefinitions($site);
        foreach ($activeManifests as $manifest) {
            if (!$manifest instanceof ExtensionManifest) {
                throw new InvalidArgumentException('The active extension contract set is invalid.');
            }
            if ($manifest->identifier()->equals($candidate->identifier())) {
                $candidateSeen = true;
                if ((string) $manifest->version() !== (string) $candidate->version()) {
                    throw new InvalidArgumentException('The candidate extension contract version is stale.');
                }
            }
            foreach (CanonicalManifestInterpreter::fromManifest($manifest)->businessDefinitions() as $definition) {
                if ($definition->siteIdentifier !== $site->identifier()) {
                    continue;
                }
                $definitions[$definition->handle] = $definition;
            }
        }
        if (!$candidateSeen) {
            throw new InvalidArgumentException('The candidate extension is absent from the active contract set.');
        }
        ksort($definitions, SORT_STRING);
        $this->claims->admit($site, array_values($definitions));
    }

    /**
     * Load core and site-owned published heads that are outside extension manifest declarations.
     *
     * @param   SiteContext  $site  Site whose post-activation component namespace is being checked.
     *
     * @return  array<string, \Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition>  Definitions by handle.
     *
     * @throws  InvalidArgumentException  When the late repository is invalid or a published head is unavailable.
     * @throws  \LogicException  When activation admission is invoked outside its lifecycle transaction.
     * @throws  \RuntimeException  When the stable site namespace row is unavailable.
     *
     * @since   2.0.0
     */
    private function publishedDefinitions(SiteContext $site): array
    {
        if ($this->definitions === null) {
            return [];
        }
        $repository = ($this->definitions)();
        if (!$repository instanceof BusinessDefinitionRepository) {
            throw new InvalidArgumentException('The OpenAPI activation definition repository is invalid.');
        }
        $repository->lockContractNamespace($site);
        $requested = [];
        foreach ($repository->catalog($site) as $entry) {
            if (
                $entry->owner->type === DefinitionOwnerType::Extension
                || $entry->publishedVersion === null
                || $entry->status === DefinitionStatus::Rejected
            ) {
                continue;
            }
            $requested[$entry->id] = $entry->publishedVersion;
        }
        $versions = $repository->publishedBatch($site, $requested);
        $definitions = [];
        foreach ($requested as $identifier => $version) {
            $record = $versions[$identifier] ?? null;
            if ($record === null || $record->definition->definitionVersion !== $version) {
                throw new InvalidArgumentException('An active OpenAPI definition contract version is unavailable.');
            }
            $definitions[$record->definition->handle] = $record->definition;
        }

        return $definitions;
    }
}
