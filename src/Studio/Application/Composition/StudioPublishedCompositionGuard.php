<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Composition;

use InvalidArgumentException;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Content\Application\ContentModelRepository;
use Kumwe\App\Content\Domain\ContentTypeDefinition;
use Kumwe\Extension\Spi\Contribution\CanonicalCompositionDocument;
use Kumwe\Extension\Spi\Contribution\CanonicalCompositionKind;
use Kumwe\Extension\Spi\Contribution\CompositionHostBinding;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Studio\Application\Host\StudioArtifactAdmission;
use Kumwe\App\Studio\Application\Host\StudioArtifactPublicationGuard;
use Kumwe\App\Studio\Application\Host\StudioProducerError;
use Kumwe\App\Studio\Application\Preview\StudioPublishedBlockRendererUnavailable;
use Kumwe\App\Studio\Application\Projection\ContentStudioProjector;
use Kumwe\App\Studio\Application\Rendering\StudioBlockRendererRuntime;
use Kumwe\Producer\Error\HostRefusal;
use Kumwe\Producer\Render\BlockCoordinate;
use Kumwe\Producer\Render\BlockRendererRegistry;
use Kumwe\Producer\Schema\SchemaAdmissionException;
use Kumwe\Producer\Schema\SchemaPropertyProfile;
use Kumwe\Producer\Schema\SchemaPropertyValidator;
use stdClass;

/**
 * Reusable publication guard for App-owned Blueprint schema, model, theme, and renderer locks.
 *
 * Both the public request path and the artifact publication mutation can call this service. Keeping the
 * compatibility decision here prevents a Blueprint from passing publication under a weaker dependency
 * interpretation than the one later used for public output.
 *
 * @since  2.0.0
 */
final readonly class StudioPublishedCompositionGuard implements StudioArtifactPublicationGuard
{
    /**
     * Bind compatibility to exact host schema, Content model, theme, and executable block authorities.
     *
     * @param  StudioArtifactAdmission           $admission   Pinned schema and active-content admission.
     * @param  ContentModelRepository            $models      Exact published Content definition store.
     * @param  StudioPublishedTheme              $theme       Live exact public theme authority.
     * @param  StudioBlockRendererRuntime        $blocks      Fresh live canonical Producer registry authority.
     * @param  ExtensionContributionRegistrySet  $registries  Live owner-bound canonical block definitions.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioArtifactAdmission $admission,
        private ContentModelRepository $models,
        private StudioPublishedTheme $theme,
        private StudioBlockRendererRuntime $blocks,
        private ExtensionContributionRegistrySet $registries,
    ) {
    }

    /**
     * Map public-runtime dependency refusals to stable host protocol diagnostics before publication.
     *
     * @param   SiteContext  $site       Trusted owning site.
     * @param   stdClass     $blueprint  Candidate App-owned Blueprint document.
     *
     * @return  void
     *
     * @throws  HostRefusal  When the exact public runtime cannot reproduce the Blueprint.
     *
     * @since   2.0.0
     */
    public function assertPublishable(SiteContext $site, stdClass $blueprint): void
    {
        try {
            $this->assertCompatible($site, $blueprint);
        } catch (StudioPublishedBlueprintMismatch) {
            StudioProducerError::refuse('conflict', 'studio.artifact/blueprint-incompatible');
        } catch (StudioPublishedModelMismatch) {
            StudioProducerError::refuse('conflict', 'studio.artifact/model-lock-mismatch');
        } catch (StudioCompositionThemeMismatch) {
            StudioProducerError::refuse('conflict', 'studio.artifact/theme-lock-mismatch');
        } catch (StudioPublishedBlockRendererUnavailable) {
            StudioProducerError::refuse('conflict', 'studio.artifact/block-renderer-unavailable');
        }
    }

    /**
     * Prove that a Blueprint is publishable against the current exact host dependencies.
     *
     * @param   SiteContext  $site       Trusted owning site.
     * @param   stdClass     $blueprint  Candidate App-owned Blueprint document.
     *
     * @return  ContentTypeDefinition  Exact Content definition named by the immutable model lock.
     *
     * @throws  StudioPublishedBlueprintMismatch  When schema or App ownership is incompatible.
     * @throws  StudioPublishedModelMismatch  When the exact model cannot be reproduced.
     * @throws  StudioCompositionThemeMismatch  When the published theme differs from the lock.
     * @throws  StudioPublishedBlockRendererUnavailable  When any block lock or node is not executable.
     *
     * @since   2.0.0
     */
    public function assertCompatible(SiteContext $site, stdClass $blueprint): ContentTypeDefinition
    {
        try {
            $admitted = $this->admission->admit($site->identifier(), $blueprint);
        } catch (HostRefusal | InvalidArgumentException) {
            throw new StudioPublishedBlueprintMismatch();
        }
        if ($admitted->kind !== 'blueprint' || !self::appOwned($blueprint)) {
            throw new StudioPublishedBlueprintMismatch();
        }

        $model = $blueprint->model ?? null;
        if (!$model instanceof stdClass || count(get_object_vars($model)) !== 3) {
            throw new StudioPublishedModelMismatch();
        }
        $modelId = $model->id ?? null;
        $modelVersion = $model->version ?? null;
        $modelRevision = $model->revision ?? null;
        $contentTypeId = is_string($modelId) ? ContentStudioProjector::contentTypeId($modelId) : null;
        $contentTypeVersion = is_string($modelVersion)
            ? ContentStudioProjector::contentTypeVersion($modelVersion)
            : null;
        if (
            $contentTypeId === null
            || $contentTypeVersion === null
            || !is_string($modelRevision)
            || $modelRevision !== ContentStudioProjector::modelRevision($contentTypeVersion)
        ) {
            throw new StudioPublishedModelMismatch();
        }
        $definition = $this->models->contentType($site, $contentTypeId, $contentTypeVersion);
        if (
            $definition === null
            || $definition->site->identifier() !== $site->identifier()
            || $definition->id !== $contentTypeId
            || $definition->version !== $contentTypeVersion
        ) {
            throw new StudioPublishedModelMismatch();
        }

        $dependencyLock = $blueprint->dependencyLock ?? null;
        $lockedTheme = $dependencyLock instanceof stdClass ? $dependencyLock->theme ?? null : null;
        if (!$this->theme->reference($site)->matches($lockedTheme)) {
            throw new StudioCompositionThemeMismatch();
        }
        $definitions = $this->liveDefinitions();
        try {
            $runtime = $this->blocks->registry();
        } catch (\Throwable) {
            throw new StudioPublishedBlockRendererUnavailable('unavailable', 'unknown', null);
        }
        $locks = $this->liveLocks($dependencyLock, $definitions, $runtime);
        $roots = $blueprint->roots ?? null;
        if (!is_array($roots)) {
            throw new StudioPublishedBlueprintMismatch();
        }
        $identifiers = [];
        $validators = [];
        $fieldPaths = self::fieldPaths($definition);
        foreach ($roots as $root) {
            $this->assertNode($root, $locks, $definitions, $fieldPaths, $identifiers, $validators);
        }

        return $definition;
    }

    /**
     * Require the one host owner coordinate reserved for Content compositions.
     *
     * @param   stdClass  $blueprint  Schema-admitted Blueprint.
     *
     * @return  bool  True only for the exact two-member App owner reference.
     *
     * @since   2.0.0
     */
    private static function appOwned(stdClass $blueprint): bool
    {
        $owner = $blueprint->owner ?? null;

        return $owner instanceof stdClass
            && count(get_object_vars($owner)) === 2
            && ($owner->id ?? null) === 'kumwe.app/content'
            && ($owner->version ?? null) === '2.0.0';
    }

    /**
     * Index every immutable block lock only after proving its renderer is live now.
     *
     * @param   mixed                                                        $dependencyLock  Schema-admitted
     *          Blueprint dependency lock.
     * @param   array<string, array{document: stdClass, integrity: string}>  $definitions     Live exact block
     *          definitions and their canonical digests.
     * @param   BlockRendererRegistry                                        $runtime         Fresh trusted
     *          Producer renderer registry the locks must resolve in.
     *
     * @return  array<string, BlockCoordinate>  Exact type/version lock map.
     *
     * @throws  StudioPublishedBlockRendererUnavailable  When a lock is duplicate or unavailable.
     *
     * @since   2.0.0
     */
    private function liveLocks(
        mixed $dependencyLock,
        array $definitions,
        BlockRendererRegistry $runtime,
    ): array {
        $blocks = $dependencyLock instanceof stdClass ? $dependencyLock->blocks ?? null : null;
        if (!is_array($blocks)) {
            throw new StudioPublishedBlueprintMismatch();
        }
        $locks = [];
        foreach ($blocks as $block) {
            if (
                !$block instanceof stdClass
                || !is_string($block->type ?? null)
                || !is_string($block->version ?? null)
                || !is_string($block->revision ?? null)
            ) {
                throw new StudioPublishedBlueprintMismatch();
            }
            try {
                $coordinate = new BlockCoordinate($block->type, $block->version, $block->revision);
            } catch (InvalidArgumentException) {
                throw new StudioPublishedBlueprintMismatch();
            }
            $key = $coordinate->versionKey();
            $definition = $definitions[$key] ?? null;
            $lockedIntegrity = $block->integrity ?? null;
            if (
                isset($locks[$key])
                || $definition === null
                || ($definition['document']->revision ?? null) !== $coordinate->revision
                || ($lockedIntegrity !== null && $lockedIntegrity !== $definition['integrity'])
                || !$runtime->supports($coordinate)
            ) {
                throw new StudioPublishedBlockRendererUnavailable(
                    $coordinate->type,
                    $coordinate->version,
                    $coordinate->revision,
                );
            }
            $locks[$key] = $coordinate;
        }

        return $locks;
    }

    /**
     * Prove one node and every descendant resolve through their exact immutable lock.
     *
     * @param   mixed                                                        $candidate    Candidate Blueprint node.
     * @param   array<string, BlockCoordinate>                               $locks        Exact live lock map.
     * @param   array<string, array{document: stdClass, integrity: string}>  $definitions  Live exact definitions.
     * @param   array<string, true>                                          $fieldPaths   Exact projected Content
     *          field paths.
     * @param   array<string, true>                                          $identifiers  Node identities already
     *          encountered in this tree.
     * @param   array<string, SchemaPropertyValidator>                       $validators   Per-definition property
     *          validators compiled during this decision.
     *
     * @return  void
     *
     * @throws  StudioPublishedBlockRendererUnavailable  When the node has no exact live lock.
     * @throws  StudioPublishedBlueprintMismatch  When the admitted tree shape is unavailable.
     *
     * @since   2.0.0
     */
    private function assertNode(
        mixed $candidate,
        array $locks,
        array $definitions,
        array $fieldPaths,
        array &$identifiers,
        array &$validators,
    ): void {
        $identifier = $candidate instanceof stdClass ? $candidate->id ?? null : null;
        $type = $candidate instanceof stdClass ? $candidate->type ?? null : null;
        $version = $candidate instanceof stdClass ? $candidate->version ?? null : null;
        $properties = $candidate instanceof stdClass ? $candidate->properties ?? null : null;
        $bindings = $candidate instanceof stdClass ? $candidate->bindings ?? null : null;
        $nodeSlots = $candidate instanceof stdClass ? $candidate->slots ?? null : null;
        if (
            !$candidate instanceof stdClass
            || !is_string($identifier)
            || !is_string($type)
            || !is_string($version)
            || !$properties instanceof stdClass
            || !$bindings instanceof stdClass
            || !$nodeSlots instanceof stdClass
        ) {
            throw new StudioPublishedBlueprintMismatch();
        }
        if (isset($identifiers[$identifier])) {
            throw new StudioPublishedBlueprintMismatch();
        }
        $identifiers[$identifier] = true;
        $key = $type . "\0" . $version;
        $coordinate = $locks[$key] ?? null;
        $definition = $definitions[$key]['document'] ?? null;
        if (
            !$coordinate instanceof BlockCoordinate
            || $coordinate->type !== $type
            || $coordinate->version !== $version
        ) {
            throw new StudioPublishedBlockRendererUnavailable(
                $type,
                $version,
                $coordinate?->revision,
            );
        }
        if (!$definition instanceof stdClass || ($definition->revision ?? null) !== $coordinate->revision) {
            throw new StudioPublishedBlockRendererUnavailable(
                $type,
                $version,
                $coordinate->revision,
            );
        }

        $this->assertProperties($properties, $candidate->responsive ?? null, $definition, $key, $validators);
        self::assertBindings($bindings, $fieldPaths);
        $slots = self::slots($definition);
        $slotMembers = get_object_vars($nodeSlots);
        foreach ($slotMembers as $slotName => $members) {
            $slot = $slots[$slotName] ?? null;
            if (!is_array($members) || !$slot instanceof stdClass) {
                throw new StudioPublishedBlueprintMismatch();
            }
            $maximum = $slot->maximum ?? null;
            $accepted = ($slot->accepts ?? null) instanceof stdClass ? $slot->accepts->types ?? null : null;
            if (!is_int($maximum) || !is_array($accepted) || count($members) > $maximum) {
                throw new StudioPublishedBlueprintMismatch();
            }
            foreach ($members as $member) {
                if (!$member instanceof stdClass || !in_array($member->type ?? null, $accepted, true)) {
                    throw new StudioPublishedBlueprintMismatch();
                }
                $this->assertNode($member, $locks, $definitions, $fieldPaths, $identifiers, $validators);
            }
        }
        foreach ($slots as $slot) {
            $slotId = $slot->id ?? null;
            $minimum = $slot->minimum ?? null;
            if (!is_string($slotId) || !is_int($minimum)) {
                throw new StudioPublishedBlueprintMismatch();
            }
            $members = $slotMembers[$slotId] ?? [];
            if (count($members) < $minimum) {
                throw new StudioPublishedBlueprintMismatch();
            }
        }
    }

    /**
     * Reconcile active canonical block documents with their owner-bound host bindings.
     *
     * The contribution set is mutable across extension disable, removal, and distrust. Rebuilding this
     * index for each decision ensures a previously published Blueprint cannot retain a withdrawn schema.
     * Canonical bytes are decoded afresh so mutable `stdClass` aliases never become an authority.
     *
     * @return  array<string, array{document: stdClass, integrity: string}>  Live exact definitions keyed by
     *          type and semantic version.
     *
     * @since   2.0.0
     */
    private function liveDefinitions(): array
    {
        $bindings = [];
        foreach ($this->registries->compositionHostBindings()->entries() as $entry) {
            $binding = $entry['definition'];
            if (
                $binding instanceof CompositionHostBinding
                && $binding->kind === CanonicalCompositionKind::BlockDefinition
                && $binding->renderer !== null
            ) {
                $bindings[$binding->identifier()] = $entry['owner'];
            }
        }

        $definitions = [];
        foreach ($this->registries->canonicalCompositionDocuments()->entries() as $entry) {
            $canonical = $entry['definition'];
            $boundOwner = $canonical instanceof CanonicalCompositionDocument
                ? $bindings[$canonical->identifier()] ?? null
                : null;
            if (
                !$canonical instanceof CanonicalCompositionDocument
                || $canonical->kind !== CanonicalCompositionKind::BlockDefinition
                || !$boundOwner instanceof ContributionOwner
                || $boundOwner->identifier() !== $entry['owner']->identifier()
            ) {
                continue;
            }
            $document = $canonical->document();
            if (
                !is_string($document->type ?? null)
                || !is_string($document->version ?? null)
                || !is_string($document->revision ?? null)
                || !($document->propertySchema ?? null) instanceof stdClass
                || !is_array($document->slots ?? null)
            ) {
                continue;
            }
            $definitions[$document->type . "\0" . $document->version] = [
                'document' => $document,
                'integrity' => 'sha256-' . base64_encode(hash('sha256', $canonical->canonical, true)),
            ];
        }

        return $definitions;
    }

    /**
     * Validate base and responsive node properties with the definition's admitted schema interpreter.
     *
     * Responsive values are property-keyed in a Blueprint. Each viewport is materialized over the base
     * object and validated as the effective property set, closing the gap where an invalid override could
     * otherwise survive publication and fail only while rendering a public request.
     *
     * @param   stdClass                                $properties  Candidate base property object.
     * @param   mixed                                   $responsive  Candidate property-keyed viewport overrides.
     * @param   stdClass                                $definition  Exact live block definition.
     * @param   string                                  $key         Definition cache key.
     * @param   array<string, SchemaPropertyValidator>  $validators  Per-decision validator cache.
     *
     * @return  void
     *
     * @throws  StudioPublishedBlueprintMismatch  When any effective property object violates its schema.
     *
     * @since   2.0.0
     */
    private function assertProperties(
        stdClass $properties,
        mixed $responsive,
        stdClass $definition,
        string $key,
        array &$validators,
    ): void {
        try {
            $validator = $validators[$key] ??= SchemaPropertyProfile::admit($definition->propertySchema);
        } catch (SchemaAdmissionException) {
            throw new StudioPublishedBlueprintMismatch();
        }
        if (!$validator->validate($properties)) {
            throw new StudioPublishedBlueprintMismatch();
        }

        if ($responsive === null) {
            return;
        }
        if (!$responsive instanceof stdClass) {
            throw new StudioPublishedBlueprintMismatch();
        }
        $effective = [];
        foreach (get_object_vars($responsive) as $property => $overrides) {
            if (!$overrides instanceof stdClass) {
                throw new StudioPublishedBlueprintMismatch();
            }
            foreach (get_object_vars($overrides) as $viewport => $value) {
                $viewportProperties = isset($effective[$viewport])
                    ? get_object_vars($effective[$viewport])
                    : get_object_vars($properties);
                $viewportProperties[$property] = $value;
                $effective[$viewport] = (object) $viewportProperties;
            }
        }
        foreach ($effective as $effectiveProperties) {
            if (!$validator->validate($effectiveProperties)) {
                throw new StudioPublishedBlueprintMismatch();
            }
        }
    }

    /**
     * Index one definition's declared slots while rejecting ambiguity defensively.
     *
     * @param   stdClass  $definition  Exact live canonical block definition.
     *
     * @return  array<string, stdClass>  Declared slots keyed by local identifier.
     *
     * @throws  StudioPublishedBlueprintMismatch  When live definition metadata is ambiguous.
     *
     * @since   2.0.0
     */
    private static function slots(stdClass $definition): array
    {
        $slots = $definition->slots ?? null;
        if (!is_array($slots)) {
            throw new StudioPublishedBlueprintMismatch();
        }
        $declared = [];
        foreach ($slots as $slot) {
            $id = $slot instanceof stdClass ? $slot->id ?? null : null;
            if (!is_string($id) || isset($declared[$id])) {
                throw new StudioPublishedBlueprintMismatch();
            }
            $declared[$id] = $slot;
        }

        return $declared;
    }

    /**
     * Require every entry-field binding to address the exact App-projected Content model.
     *
     * @param   stdClass             $bindings    Candidate Blueprint binding map.
     * @param   array<string, true>  $fieldPaths  Exact projected Content field paths.
     *
     * @return  void
     *
     * @throws  StudioPublishedBlueprintMismatch  When an entry-field path is unavailable.
     *
     * @since   2.0.0
     */
    private static function assertBindings(stdClass $bindings, array $fieldPaths): void
    {
        foreach (get_object_vars($bindings) as $binding) {
            $source = $binding instanceof stdClass ? $binding->source ?? null : null;
            if (!$source instanceof stdClass || ($source->kind ?? null) !== 'entry-field') {
                continue;
            }
            $path = $source->fieldPath ?? null;
            if (!is_array($path) || !array_is_list($path)) {
                throw new StudioPublishedBlueprintMismatch();
            }
            $segments = [];
            foreach ($path as $segment) {
                if (!is_string($segment)) {
                    throw new StudioPublishedBlueprintMismatch();
                }
                $segments[] = $segment;
            }
            if (!isset($fieldPaths[implode('.', $segments)])) {
                throw new StudioPublishedBlueprintMismatch();
            }
        }
    }

    /**
     * Project the exact flat field identifiers exposed by the App-owned Content model port.
     *
     * @param   ContentTypeDefinition  $definition  Exact published Content definition.
     *
     * @return  array<string, true>  Title, slug, and every schema-owned `data_` field path.
     *
     * @since   2.0.0
     */
    private static function fieldPaths(ContentTypeDefinition $definition): array
    {
        $paths = ['title' => true, 'slug' => true];
        foreach ($definition->fields() as $field) {
            $paths['data_' . $field->key] = true;
        }

        return $paths;
    }
}
