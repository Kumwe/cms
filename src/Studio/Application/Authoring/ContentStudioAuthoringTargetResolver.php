<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Domain\ContentTypeDefinition;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Studio\Application\Projection\ContentStudioProjector;
use Kumwe\App\Studio\Domain\Authoring\StudioAuthoringIntent;

/**
 * Resolves the core Content authoring target exclusively from PHP-authoritative facts.
 *
 * The Content editor has already loaded records and definitions through their application services;
 * this resolver adds the exact create/update authorization decision and maps those trusted objects to
 * the established read-projection coordinates. It never accepts a target, resource, actor, site,
 * capability, or revision from browser input.
 *
 * @since  2.0.0
 */
final readonly class ContentStudioAuthoringTargetResolver
{
    /**
     * Bind target resolution to the same authorization gateway as Content mutations.
     *
     * @param  AuthorizationGateway  $authorization  Canonical deny-by-default authorization boundary.
     *
     * @since  2.0.0
     */
    public function __construct(private AuthorizationGateway $authorization)
    {
    }

    /**
     * Resolve a create target, optionally carrying an explicitly selected reusable type.
     *
     * The unqualified Content New route intentionally passes no definition even though its structured
     * fallback renders the core Page fields. That keeps Studio's normal create intent blank and makes
     * reuse an explicit choice instead of a prerequisite inherited from the legacy form.
     *
     * @param   ExecutionContext        $context     Authenticated actor and site.
     * @param   ?ContentTypeDefinition  $definition  Explicitly selected exact type, or null for blank.
     *
     * @return  ContentStudioAuthoringTarget  Trusted create target with no previous Entry values.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When create is refused.
     * @throws  ContentStudioAuthoringTargetMismatch  When the selected definition belongs to another site.
     *
     * @since   2.0.0
     */
    public function create(
        ExecutionContext $context,
        ?ContentTypeDefinition $definition = null,
    ): ContentStudioAuthoringTarget {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('content.create'),
            AuthorizationResource::collection('content'),
        );
        if ($definition !== null && $definition->site->identifier() !== $context->site()->identifier()) {
            throw new ContentStudioAuthoringTargetMismatch(
                'The selected Content type does not belong to the authoring site.',
            );
        }

        return new ContentStudioAuthoringTarget(
            StudioAuthoringIntent::Create,
            $definition === null ? null : ContentStudioProjector::modelId($definition->id),
            $definition === null ? null : ContentStudioProjector::modelVersion($definition->version),
            $definition === null ? null : ContentStudioProjector::modelRevision($definition->version),
            null,
            null,
            $definition === null
                ? '/administrator/content/new'
                : '/administrator/content/new?content_type=' . rawurlencode($definition->id),
        );
    }

    /**
     * Resolve one exact editable item and its pinned Content type.
     *
     * The route-derived record identity wins. The stored type version must match the authorized exact
     * definition supplied by ContentModelService; head is never substituted and a Blueprint is never
     * provisioned as a side effect of resolving the target.
     *
     * @param   ExecutionContext       $context     Authenticated actor and site.
     * @param   ContentRecord          $record      Exact record loaded through ContentService.
     * @param   ContentTypeDefinition  $definition  Exact definition version pinned by the record.
     *
     * @return  ContentStudioAuthoringTarget  Trusted edit target with exact Model and Entry revisions.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When update is refused.
     * @throws  ContentStudioAuthoringTargetMismatch  When record and definition coordinates disagree.
     *
     * @since   2.0.0
     */
    public function edit(
        ExecutionContext $context,
        ContentRecord $record,
        ContentTypeDefinition $definition,
    ): ContentStudioAuthoringTarget {
        $entryId = $record->entry->id();
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('content.update'),
            AuthorizationResource::item('content', $entryId),
        );
        if (
            $record->siteIdentifier !== $context->site()->identifier()
            || $definition->site->identifier() !== $context->site()->identifier()
            || $record->contentTypeId !== $definition->id
            || $record->contentTypeVersion !== $definition->version
        ) {
            throw new ContentStudioAuthoringTargetMismatch(
                'The Content authoring target coordinates are inconsistent.',
            );
        }

        return new ContentStudioAuthoringTarget(
            StudioAuthoringIntent::Edit,
            ContentStudioProjector::modelId($definition->id),
            ContentStudioProjector::modelVersion($definition->version),
            ContentStudioProjector::modelRevision($definition->version),
            ContentStudioProjector::entryId($entryId),
            ContentStudioProjector::entryRevision($record->entry->version()),
            '/administrator/content/' . rawurlencode($entryId) . '/edit',
        );
    }
}
