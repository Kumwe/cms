<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Projection;

use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\App\Content\Application\ContentModelNotFound;
use Kumwe\App\Content\Application\ContentModelService;
use Kumwe\App\Content\Application\ContentNotFound;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Studio\Domain\Projection\StudioProjectionRejection;
use Kumwe\Context\Value\ExecutionContext;
use stdClass;

/**
 * Read-only application surface backing Studio's `model.list` and `model.get` operations for Content.
 *
 * Existing Content application services remain the authority for model and entry reads. This service
 * never reaches a repository around their authorization, and it translates absence and denial into
 * the same non-disclosing refusal. Definition writes, entry writes, policy changes, and composition
 * artifact persistence are intentionally outside this AP-2 surface.
 *
 * @since  2.0.0
 */
final readonly class StudioContentProjectionService
{
    /**
     * Bind authorized Content reads to the pure projector and host-owned projection coordinates.
     *
     * @param  ContentModelService                 $models     Authorized Content definition service.
     * @param  ContentService                      $content    Authorized Content entry service.
     * @param  ContentProjectionBindingRepository  $bindings   Blueprint and override read boundary.
     * @param  ContentStudioProjector              $projector  Exact schema-validated mapping rules.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ContentModelService $models,
        private ContentService $content,
        private ContentProjectionBindingRepository $bindings,
        private ContentStudioProjector $projector,
    ) {
    }

    /**
     * List every Content model the actor may read, in the Content service's deterministic order.
     *
     * Field policy may remove members from each model but never adds a diagnostic naming what was
     * removed. Each returned document has already passed the pinned `content-model` schema.
     *
     * @param   ExecutionContext  $context  Actor and site issuing the model-port request.
     *
     * @return  list<stdClass>  Authorized Studio content models ordered by Content handle.
     *
     * @throws  StudioProjectionRejected  When the collection is denied or an authorized definition has no
     *          lossless projection.
     *
     * @since   2.0.0
     */
    public function models(ExecutionContext $context): array
    {
        try {
            $definitions = $this->models->contentTypes($context);
        } catch (AuthorizationDenied) {
            throw new StudioProjectionRejected(StudioProjectionRejection::Unavailable);
        }

        $documents = [];
        foreach ($definitions as $definition) {
            $documents[] = $this->projector->contentModel(
                $context,
                $definition,
                $this->bindings->blueprint($context->site(), $definition->id, $definition->version),
            );
        }

        return $documents;
    }

    /**
     * Load one exact projected Content model by its reversible Studio coordinate.
     *
     * @param   ExecutionContext  $context  Actor and site issuing the model-port request.
     * @param   string            $modelId  `content-model:<uuid>` identifier.
     * @param   ?string           $version  Exact projected semantic version, or null for the current head.
     *
     * @return  stdClass  Authorized, schema-valid Studio content model.
     *
     * @throws  StudioProjectionRejected  On an invalid coordinate, denial, absence, or lossy definition.
     *
     * @since   2.0.0
     */
    public function model(ExecutionContext $context, string $modelId, ?string $version = null): stdClass
    {
        $contentTypeId = ContentStudioProjector::contentTypeId($modelId);
        $contentTypeVersion = $version === null ? null : ContentStudioProjector::contentTypeVersion($version);
        if ($contentTypeId === null || ($version !== null && $contentTypeVersion === null)) {
            throw new StudioProjectionRejected(StudioProjectionRejection::InvalidIdentifier);
        }
        try {
            $definition = $this->models->contentType($context, $contentTypeId, $contentTypeVersion);
        } catch (ContentModelNotFound | AuthorizationDenied) {
            throw new StudioProjectionRejected(StudioProjectionRejection::Unavailable);
        }

        return $this->projector->contentModel(
            $context,
            $definition,
            $this->bindings->blueprint($context->site(), $definition->id, $definition->version),
        );
    }

    /**
     * Load one Content entry through its authoritative service and project its pinned definitions.
     *
     * A custom workflow is read at the exact version the entry pins; the built-in workflow needs no
     * repository row. Denial and absence collapse to one refusal so the model port cannot probe IDs.
     *
     * @param   ExecutionContext  $context  Actor and site issuing the model-port request.
     * @param   string            $entryId  `content-entry:<uuid>` identifier.
     *
     * @return  stdClass  Authorized, schema-valid Studio entry.
     *
     * @throws  StudioProjectionRejected  On an invalid coordinate, denial, absence, or lossy value.
     *
     * @since   2.0.0
     */
    public function entry(ExecutionContext $context, string $entryId): stdClass
    {
        $contentEntryId = ContentStudioProjector::contentEntryId($entryId);
        if ($contentEntryId === null) {
            throw new StudioProjectionRejected(StudioProjectionRejection::InvalidIdentifier);
        }
        try {
            $record = $this->content->get($context, $contentEntryId);
            $definition = $this->models->contentType(
                $context,
                $record->contentTypeId,
                $record->contentTypeVersion,
            );
            $workflow = $record->workflowId === ContentService::CORE_WORKFLOW_ID
                ? null
                : $this->models->workflow($context, $record->workflowId, $record->workflowVersion);
        } catch (ContentNotFound | ContentModelNotFound | AuthorizationDenied) {
            throw new StudioProjectionRejected(StudioProjectionRejection::Unavailable);
        }

        return $this->projector->entry(
            $context,
            $record,
            $definition,
            $workflow,
            $this->bindings->overrides($context->site(), $contentEntryId),
        );
    }
}
