<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Composition;

use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Studio\Application\Host\StudioArtifactRepository;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBindingResolver;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBindingValues;
use Kumwe\App\Studio\Application\Projection\ContentProjectionBindingRepository;
use Kumwe\App\Studio\Application\Projection\ContentStudioProjector;
use Kumwe\App\Studio\Application\Projection\StudioProjectionRejected;
use Kumwe\App\Studio\Application\Rendering\StudioBlockRendererRuntime;
use Kumwe\App\Studio\Application\Rendering\StudioRenderResultAdmission;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Producer\Render\CompositionRenderer;
use Kumwe\Producer\Render\RenderContext;
use Kumwe\Producer\Render\RenderPolicy;
use Kumwe\Producer\Render\RenderResult;
use Throwable;
use stdClass;

/**
 * Resolves and renders only the exact published Blueprint pinned to a public Content record.
 *
 * The absence of a binding, or an intentionally unpublished or retired Blueprint, preserves the legacy
 * Content layout. Once a binding selects an active artifact, every identity, model, theme, schema, and
 * executable renderer dependency becomes mandatory and any drift fails closed.
 *
 * @since  2.0.0
 */
final readonly class CanonicalStudioPublishedContentRenderer implements StudioPublishedContentRenderer
{
    /**
     * Bind public rendering to immutable host stores and the same safe projector used by preview.
     *
     * @param  ContentProjectionBindingRepository  $bindings   Exact Content-version binding projection.
     * @param  StudioArtifactRepository            $artifacts  Current and immutable artifact store.
     * @param  StudioPublishedCompositionGuard     $guard      Shared publication dependency guard.
     * @param  ContentStudioProjector              $projector  Lossless public Content value projector.
     * @param  StudioBlockRendererRuntime          $blocks     Fresh live Producer registry authority.
     * @param  StudioPreviewBindingResolver        $resolver   Host-owned Content binding evaluator.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ContentProjectionBindingRepository $bindings,
        private StudioArtifactRepository $artifacts,
        private StudioPublishedCompositionGuard $guard,
        private ContentStudioProjector $projector,
        private StudioBlockRendererRuntime $blocks,
        private StudioPreviewBindingResolver $resolver,
    ) {
    }

    /**
     * Render the record through its exact published Blueprint or retain legacy rendering intentionally.
     *
     * @param   ContentRecord  $record  Published record selected by the public Content boundary.
     *
     * @return  ?RenderResult  Canonical Producer output, or null for no binding, draft, or retired state.
     *
     * @throws  StudioPublishedBlueprintUnavailable  When a configured artifact cannot be loaded.
     * @throws  StudioPublishedBlueprintMismatch  When artifact identity, kind, schema, or ownership drifts.
     * @throws  StudioPublishedModelMismatch  When the pinned Content model cannot be reproduced exactly.
     * @throws  StudioCompositionThemeMismatch  When the live published theme differs from the lock.
     * @throws  \Kumwe\App\Studio\Application\Preview\StudioPublishedBlockRendererUnavailable
     *          When an exact locked renderer is not live.
     *
     * @since   2.0.0
     */
    public function render(ContentRecord $record): ?RenderResult
    {
        $site = SiteContext::fromString($record->siteIdentifier);
        $binding = $this->bindings->blueprint($site, $record->contentTypeId, $record->contentTypeVersion);
        if ($binding === null) {
            return null;
        }
        if (
            $binding->site->identifier() !== $site->identifier()
            || !hash_equals($record->contentTypeId, $binding->contentTypeId)
            || $record->contentTypeVersion !== $binding->contentTypeVersion
        ) {
            throw new StudioPublishedBlueprintMismatch();
        }
        $artifact = $binding->blueprintRevision === null
            ? $this->artifacts->current($site->identifier(), $binding->blueprintId, $binding->blueprintVersion)
            : $this->artifacts->revision(
                $site->identifier(),
                $binding->blueprintId,
                $binding->blueprintVersion,
                $binding->blueprintRevision,
            );
        if ($artifact === null) {
            throw new StudioPublishedBlueprintUnavailable();
        }
        if (
            $artifact->siteIdentifier !== $site->identifier()
            || $artifact->kind !== 'blueprint'
            || !hash_equals($binding->blueprintId, $artifact->id)
            || !hash_equals($binding->blueprintVersion, $artifact->version)
            || ($binding->blueprintRevision !== null
                && !hash_equals($binding->blueprintRevision, $artifact->revision))
        ) {
            throw new StudioPublishedBlueprintMismatch();
        }
        if (in_array($artifact->status, ['draft', 'retired'], true)) {
            return null;
        }
        if ($artifact->status !== 'published') {
            throw new StudioPublishedBlueprintMismatch();
        }

        $document = $artifact->document();
        if (!self::boundIdentity($document, $binding->blueprintId, $binding->blueprintVersion)) {
            throw new StudioPublishedBlueprintMismatch();
        }
        $definition = $this->guard->assertCompatible($site, $document);
        if (
            $definition->id !== $record->contentTypeId
            || $definition->version !== $record->contentTypeVersion
            || !self::modelMatches($document, $record)
        ) {
            throw new StudioPublishedModelMismatch();
        }
        try {
            $values = $this->projector->publishedValues($record, $definition);
        } catch (StudioProjectionRejected) {
            throw new StudioPublishedModelMismatch();
        }
        $bindingValues = new StudioPreviewBindingValues($values, new stdClass());
        try {
            $result = (new CompositionRenderer($this->blocks->registry()))->renderDocument(
                $document,
                new RenderContext(
                    resolveBinding: fn (stdClass $node, string $port) => $this->resolver->resolve(
                        $node,
                        $port,
                        $bindingValues,
                    ),
                    policy: RenderPolicy::RequireRegistered,
                ),
            );
        } catch (Throwable) {
            throw new \Kumwe\App\Studio\Application\Preview\StudioPublishedBlockRendererUnavailable(
                'unavailable',
                'unknown',
                null,
            );
        }
        try {
            StudioRenderResultAdmission::assertSupported($result);
        } catch (\Kumwe\Producer\Render\RenderException) {
            throw new \Kumwe\App\Studio\Application\Preview\StudioPublishedBlockRendererUnavailable(
                'enhancement',
                'unknown',
                null,
            );
        }

        return $result;
    }

    /**
     * Prove the document is the exact App-owned Blueprint selected by the binding.
     *
     * @param   stdClass  $document  Readmitted Blueprint document.
     * @param   string    $id        Bound Blueprint identity.
     * @param   string    $version   Bound Blueprint version.
     *
     * @return  bool  True only for exact routing identity and owner coordinates.
     *
     * @since   2.0.0
     */
    private static function boundIdentity(stdClass $document, string $id, string $version): bool
    {
        return ($document->id ?? null) === $id
            && ($document->version ?? null) === $version;
    }

    /**
     * Compare the immutable Blueprint model coordinate with the record's pinned Content definition.
     *
     * @param   stdClass       $document  Readmitted Blueprint document.
     * @param   ContentRecord  $record    Public record carrying immutable definition coordinates.
     *
     * @return  bool  True only for exact model ID, semantic version, and revision.
     *
     * @since   2.0.0
     */
    private static function modelMatches(stdClass $document, ContentRecord $record): bool
    {
        $model = $document->model ?? null;
        if (!$model instanceof stdClass || count(get_object_vars($model)) !== 3) {
            return false;
        }

        return ($model->id ?? null) === ContentStudioProjector::modelId($record->contentTypeId)
            && ($model->version ?? null) === ContentStudioProjector::modelVersion($record->contentTypeVersion)
            && ($model->revision ?? null) === ContentStudioProjector::modelRevision($record->contentTypeVersion);
    }
}
