<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Rendering;

use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Contribution\StudioPreviewRendererContribution;
use Kumwe\App\Extension\Runtime\TrustEnforcingStudioPreviewBlockRenderer;
use Kumwe\App\Studio\Application\Release\StudioCoreCatalog;
use Kumwe\Extension\Spi\Contribution\CanonicalCompositionDocument;
use Kumwe\Extension\Spi\Contribution\CanonicalCompositionKind;
use Kumwe\Extension\Spi\Contribution\CompositionHostBinding;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlockRenderer;
use Kumwe\Producer\Render\BlockCoordinate;
use Kumwe\Producer\Render\BlockRenderer;
use Kumwe\Producer\Render\BlockRendererRegistry;
use Kumwe\Producer\Render\RenderException;

/**
 * Builds a fresh canonical Producer registry from live host trust authority.
 *
 * No renderer chooses its own coordinate. Canonical documents and separate signed host bindings must
 * agree on owner and identity; extension implementations must additionally remain executable under
 * the current package trust generation. Rebuilding for each decision prevents snapshot reuse after
 * disable, removal, distrust or registry mutation. Only `TrustEnforcingStudioPreviewBlockRenderer`
 * re-establishes that trust on every render, so an extension-owned executable of any other shape is a
 * host invariant violation and refuses the whole registry decision instead of being skipped or run.
 *
 * @since  2.0.0
 */
final readonly class StudioBlockRendererRuntime
{
    /**
     * Bind registry composition to the live contribution set and the host field renderer.
     *
     * @param  ExtensionContributionRegistrySet  $registries  Live owner-scoped contribution registries.
     * @param  StudioContentFieldBlockRenderer   $fields      App-owned Content field block renderer.
     * @param  ?StudioCoreCatalog                $catalog     Exact first-party coordinates the pinned Studio
     *         release compiles in; each is bound to Producer's own core implementation so a published
     *         composition may use the same catalog the contextual authoring surface offers.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ExtensionContributionRegistrySet $registries,
        private StudioContentFieldBlockRenderer $fields,
        private ?StudioCoreCatalog $catalog = null,
    ) {
    }

    /**
     * Return one fresh Producer registry containing only currently trusted exact coordinates.
     *
     * @param   ?string  $viewport  Active preview semantic width handed to layout and contributed fragment
     *          renderers; null renders immutable public markup that retains every bounded width.
     *
     * @return  BlockRendererRegistry  Direct canonical registry for one publication or render decision.
     *
     * @since   2.0.0
     */
    public function registry(?string $viewport = null): BlockRendererRegistry
    {
        $registry = BlockRendererRegistry::withCoreCatalog();
        $bindings = [];
        foreach ($this->registries->compositionHostBindings()->entries() as $entry) {
            $binding = $entry['definition'];
            if ($binding instanceof CompositionHostBinding) {
                $bindings[$binding->identifier()] = [
                    'binding' => $binding,
                    'owner' => $entry['owner'],
                ];
            }
        }

        /** @var array<string, array{owner: ContributionOwner, renderer: string}> $extensionCoordinates */
        $extensionCoordinates = [];
        foreach ($this->registries->canonicalCompositionDocuments()->entries() as $entry) {
            $document = $entry['definition'];
            if (
                !$document instanceof CanonicalCompositionDocument
                || $document->kind !== CanonicalCompositionKind::BlockDefinition
            ) {
                continue;
            }
            $bindingEntry = $bindings[$document->identifier()] ?? null;
            $binding = $bindingEntry['binding'] ?? null;
            $bindingOwner = $bindingEntry['owner'] ?? null;
            if (
                !$binding instanceof CompositionHostBinding
                || !$bindingOwner instanceof ContributionOwner
                || $binding->renderer === null
                || $bindingOwner->identifier() !== $entry['owner']->identifier()
            ) {
                continue;
            }
            $canonical = $document->document();
            $type = $canonical->type ?? null;
            $version = $canonical->version ?? null;
            $revision = $canonical->revision ?? null;
            if (!is_string($type) || !is_string($version) || !is_string($revision)) {
                continue;
            }
            try {
                $coordinate = new BlockCoordinate($type, $version, $revision);
            } catch (\InvalidArgumentException) {
                continue;
            }
            if ($entry['owner']->identifier() === ContributionOwner::CORE) {
                $renderer = $this->coreRenderer($coordinate, $binding->renderer, $viewport);
                if ($renderer !== null) {
                    $registry->register($coordinate, $renderer);
                }
                continue;
            }
            if (array_key_exists($coordinate->key(), $extensionCoordinates)) {
                throw new RenderException('A trusted extension block coordinate is ambiguous.');
            }
            $extensionCoordinates[$coordinate->key()] = [
                'owner' => $entry['owner'],
                'renderer' => $binding->renderer,
            ];
        }

        foreach ($this->registries->studioPreviewRenderers()->executableEntries() as $entry) {
            $definition = $entry['definition'];
            $implementation = $entry['implementation'];
            if (
                !$definition instanceof StudioPreviewRendererContribution
                || !$implementation instanceof StudioPreviewBlockRenderer
            ) {
                continue;
            }
            if (
                $entry['owner']->identifier() !== ContributionOwner::CORE
                && !$implementation instanceof TrustEnforcingStudioPreviewBlockRenderer
            ) {
                throw new RenderException('An extension preview renderer is not fenced by live host trust.');
            }
            $coordinate = $definition->coordinate();
            $expected = $extensionCoordinates[$coordinate->key()] ?? null;
            if (
                $expected === null
                || $expected['owner']->identifier() !== $entry['owner']->identifier()
                || $definition->owner->identifier() !== $entry['owner']->identifier()
                || $expected['renderer'] !== $definition->renderer
                || ($implementation instanceof TrustEnforcingStudioPreviewBlockRenderer
                    && !$implementation->isAvailable())
            ) {
                continue;
            }
            $registry->register(
                $coordinate,
                new FragmentStudioPreviewBlockRenderer($implementation, $viewport ?? 'expanded'),
            );
        }
        foreach ($this->catalog?->blockCoordinates() ?? [] as $coordinate) {
            if ($registry->supports($coordinate)) {
                continue;
            }
            $renderer = $registry->draftRendererFor($coordinate->type, $coordinate->version);
            if ($renderer !== null) {
                $registry->register($coordinate, $renderer);
            }
        }

        return $registry;
    }

    /**
     * Select the host implementation named by a core-owned binding.
     *
     * @param   BlockCoordinate  $coordinate  Exact core block coordinate being bound.
     * @param   string           $binding     Core-owned renderer binding identifier.
     * @param   ?string          $viewport    Active preview semantic width, or null for public markup.
     *
     * @return  ?BlockRenderer  Host implementation, or null when the binding names none.
     *
     * @since   2.0.0
     */
    private function coreRenderer(BlockCoordinate $coordinate, string $binding, ?string $viewport): ?BlockRenderer
    {
        return match ($binding) {
            'core.renderer/layout' => in_array(
                $coordinate->type,
                StudioLayoutBlockRenderer::BLOCK_TYPES,
                true,
            ) ? new StudioLayoutBlockRenderer($viewport) : null,
            'core.renderer/field' => in_array(
                $coordinate->type,
                StudioContentFieldBlockRenderer::BLOCK_TYPES,
                true,
            ) ? $this->fields : null,
            default => null,
        };
    }
}
