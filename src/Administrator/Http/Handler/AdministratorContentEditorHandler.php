<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Http\Handler;

use Kumwe\App\Administrator\Content\ContentEditorSubmission;
use Kumwe\App\Administrator\Content\ContentFormPresenter;
use Kumwe\App\Administrator\Http\AdministratorRequest;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Content\Application\ContentModelService;
use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Domain\ContentTypeDefinition;
use Kumwe\App\Http\Middleware\SecurityHeadersMiddleware;
use Kumwe\App\Media\Application\MediaAsset;
use Kumwe\App\Media\Application\MediaService;
use Kumwe\App\Site\Application\PublicPageLocator;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringLaunchResolver;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringTargetResolver;
use Kumwe\App\Studio\Application\Authoring\StudioHostedDeploymentConfiguration;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves the administrator content editor, for both a brand new entry and an existing one.
 *
 * The same screen backs `/administrator/content/new` and `/administrator/content/{id}/edit`; the
 * presence of an `id` route attribute is what decides which. It writes nothing — the rendered form
 * posts to the separate create and update handlers — so this class exists purely to assemble
 * everything the form needs in one place: the stored entry, the content type it is pinned to, the
 * workflow governing it, the field descriptors derived from that type's schema, and the media
 * library to pick from. Definitions are read at the versions the entry pinned rather than at head,
 * which is what keeps an older entry editable after its type or workflow was republished.
 *
 * Assembling the screen in one place is also what lets a refused save come back to it. `render()`
 * takes an optional `ContentEditorSubmission`, and a create or update handler whose write was
 * rejected returns the operator to this same editor with their submitted values in the inputs rather
 * than to an error page that would lose them.
 *
 * @since  2.0.0
 */
final readonly class AdministratorContentEditorHandler implements RequestHandlerInterface
{
    /**
     * Wire the editor to the services supplying the entry and the vocabulary it is edited against.
     *
     * @param ContentService $content Loads the entry being edited, trashed ones included.
     * @param ContentModelService $models Supplies the pinned content type and workflow versions.
     * @param  AdministratorRenderer                 $renderer        Renders the `content-form` template.
     * @param  ContentStudioAuthoringTargetResolver  $studioTargets   Resolves trusted create/edit coordinates.
     * @param  ContentStudioAuthoringLaunchResolver  $studioLaunches  Resolves one atomic configured launch.
     * @param ?ContentFormPresenter $form Turns a schema into field descriptors; null builds a default.
     * @param ?MediaService $media Backs the media picker; null renders the form without one.
     * @param ?PublicPageLocator $publicPages Resolves the entry's public URL; null omits the link.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ContentService $content,
        private ContentModelService $models,
        private AdministratorRenderer $renderer,
        private ContentStudioAuthoringTargetResolver $studioTargets,
        private ContentStudioAuthoringLaunchResolver $studioLaunches,
        private ?ContentFormPresenter $form = null,
        private ?MediaService $media = null,
        private ?PublicPageLocator $publicPages = null,
    ) {
    }

    /**
     * Serve the editor as its own GET route, with nothing retained.
     *
     * @param   ServerRequestInterface  $request  Administrator request; an `id` attribute selects edit mode.
     *
     * @return  ResponseInterface  The rendered editor, marked `no-store` because it carries a CSRF token.
     *
     * @throws  \RuntimeException  When the stored entry's pinned type or workflow reference is unusable.
     * @throws  \Kumwe\App\Content\Application\ContentNotFound  When the route names an entry out of reach.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->render($request);
    }

    /**
     * Assemble and render the editor for a new entry or for the entry the route names.
     *
     * An existing entry is loaded with trashed entries included, so an operator about to restore one
     * can still see it. Stored entry data is filtered down to string keys before it reaches the
     * presenter, because a numeric key could never correspond to a schema field.
     *
     * A `$submission` re-renders the same screen after a refused save. Its values are laid over the
     * stored ones, so every input shows what the operator typed rather than what the store still
     * holds, while the entry itself — and therefore the version the form quotes and the workflow
     * controls beside it — is re-read. That re-read is what makes the conflict screen actionable:
     * saving again applies the retained values to the version another writer left behind.
     *
     * @param   ServerRequestInterface    $request     Administrator request; an `id` attribute selects
     *          edit mode.
     * @param   ?ContentEditorSubmission  $submission  Refused submission to retain, or null for a
     *          first visit.
     * @param   int                       $status      HTTP status; a refused save re-renders at 422 or 409.
     *
     * @return  ResponseInterface  The rendered editor, marked `no-store` because it carries a CSRF token; when
     *          the page mounts Studio from the configured CDN it also asks the security-header boundary for
     *          that one exact script origin.
     *
     * @throws  \RuntimeException  When the stored entry's pinned type or workflow reference is unusable.
     * @throws  \Kumwe\App\Content\Application\ContentNotFound  When the route names an entry out of reach.
     *
     * @since   2.0.0
     */
    public function render(
        ServerRequestInterface $request,
        ?ContentEditorSubmission $submission = null,
        int $status = 200,
    ): ResponseInterface {
        $session = AdministratorRequest::session($request);
        $id = $request->getAttribute('id');
        $entry = null;
        $record = null;

        if (is_string($id) && $id !== '') {
            $record = $this->content->get(AdministratorRequest::context($request), $id, true);
            $entry = $record->toArray() + ['public_url' => $this->publicPages?->publicPathFor($record)];
        }

        $context = AdministratorRequest::context($request);
        $definitions = $this->models->contentTypes($context);
        $types = array_map(static fn (ContentTypeDefinition $type): array => $type->toArray(), $definitions);
        $selectedType = $this->selectedType($request, $definitions, $entry, $submission);
        $studioTarget = $record instanceof ContentRecord
            ? $this->studioTargets->edit($context, $record, $selectedType)
            : $this->studioTargets->create(
                $context,
                $this->explicitTypeSelected($request, $submission, $selectedType) ? $selectedType : null,
            );
        $studioLaunch = $this->studioLaunches->resolve($context, $studioTarget, $session->csrfToken);
        $studioAuthoring = $studioLaunch->toArray();
        $workflow = null;
        if (is_array($entry)) {
            $workflowId = $entry['workflow_id'] ?? null;
            $workflowVersion = $entry['workflow_version'] ?? null;
            if (!is_string($workflowId) || !is_int($workflowVersion)) {
                throw new \RuntimeException('The stored content workflow reference is invalid.');
            }
            $workflow = $this->models->workflow(
                $context,
                $workflowId,
                $workflowVersion,
            )->toArray();
        }
        $values = [];
        $storedData = $entry['data'] ?? null;
        if (is_array($storedData)) {
            foreach ($storedData as $key => $value) {
                if (is_string($key)) {
                    $values[$key] = $value;
                }
            }
        }
        if ($submission !== null) {
            $values = [...$values, ...$submission->values];
        }

        $headers = ['Cache-Control' => 'no-store'];
        $studioConfiguration = $studioLaunch->configuration;
        if (
            $studioConfiguration instanceof StudioHostedDeploymentConfiguration
            && $studioConfiguration->scriptOrigin !== null
        ) {
            $headers[SecurityHeadersMiddleware::SCRIPT_ORIGIN_HEADER] = $studioConfiguration->scriptOrigin;
        }

        return new HtmlResponse($this->renderer->render('content-form', [
            'csrf' => $session->csrfToken,
            'capabilities' => AdministratorRequest::capabilityMap($request),
            'entry' => $entry,
            'content_types' => $types,
            'content_type' => $selectedType->toArray(),
            'studio_authoring' => $studioAuthoring,
            'editor_input' => $this->editorInput($submission),
            'content_violations' => $submission === null ? [] : $submission->violations,
            'version_conflict' => $this->versionConflict($entry, $submission),
            'fields' => ($this->form ?? new ContentFormPresenter())->fields(
                $selectedType,
                $values,
            ),
            'workflow' => $workflow,
            'media_assets' => $this->media === null ? [] : array_map(
                static fn (MediaAsset $asset): array => $asset->toArray(),
                $this->media->browse($context, perPage: 48)->items,
            ),
        ]), $status, $headers);
    }

    /**
     * Decide whether create authoring explicitly selected a reusable Content type.
     *
     * The structured fallback always needs a definition and therefore defaults to the core Page type.
     * Studio must not inherit that implementation detail: only a recognized query or retained
     * submission makes the type part of the contextual target; an unqualified Content New visit stays
     * a blank create intent.
     *
     * @param   ServerRequestInterface    $request     Request whose query may select a type.
     * @param   ?ContentEditorSubmission  $submission  Retained create submission, or null.
     * @param   ContentTypeDefinition     $selected    Definition selected for the structured fallback.
     *
     * @return  bool  True only when the selected definition was explicitly and validly requested.
     *
     * @since   2.0.0
     */
    private function explicitTypeSelected(
        ServerRequestInterface $request,
        ?ContentEditorSubmission $submission,
        ContentTypeDefinition $selected,
    ): bool {
        $requested = $submission?->contentType;
        if ($requested === null || $requested === '') {
            $requested = $request->getQueryParams()['content_type'] ?? null;
        }

        return is_string($requested)
            && ($requested === $selected->id || $requested === $selected->handle);
    }

    /**
     * Hand the identity and scheduling inputs the text the operator submitted, when there was one.
     *
     * Null is returned for a first visit so the template keeps reading the stored entry exactly as it
     * always has; only a refused save replaces those bindings. A submitted value then wins even when
     * it is blank, because clearing a field is an edit the operator expects to see preserved, and the
     * publication timestamps are handed back in the `datetime-local` shape the inputs posted so a
     * rejected save never silently reformats them.
     *
     * @param   ?ContentEditorSubmission  $submission  Refused submission to retain, or null.
     *
     * @return  array{title: string, slug: string, publish_at: string, unpublish_at: string}|null  Values
     *          for the identity and scheduling inputs, or null to keep the stored entry's own values.
     *
     * @since   2.0.0
     */
    private function editorInput(?ContentEditorSubmission $submission): ?array
    {
        if ($submission === null) {
            return null;
        }

        return [
            'title' => $submission->title,
            'slug' => $submission->slug,
            'publish_at' => $submission->publishAt,
            'unpublish_at' => $submission->unpublishAt,
        ];
    }

    /**
     * Describe an optimistic-concurrency refusal for the editor to explain and offer choices for.
     *
     * The version the operator composed against comes from the refused submission; the version the
     * entry carries now comes from the re-read above, so the two are always the pair that failed to
     * match rather than a snapshot taken at the moment of the conflict.
     *
     * @param   array<string, mixed>|null  $entry       Re-read entry, or null while creating.
     * @param   ?ContentEditorSubmission   $submission  Refused submission to retain, or null.
     *
     * @return  array{expected_version: int, current_version: int}|null  Conflict pair, or null when the
     *          refusal was not a version conflict.
     *
     * @since   2.0.0
     */
    private function versionConflict(?array $entry, ?ContentEditorSubmission $submission): ?array
    {
        $stale = $submission?->staleVersion;
        $current = $entry['version'] ?? null;
        if ($stale === null || !is_int($current)) {
            return null;
        }

        return ['expected_version' => $stale, 'current_version' => $current];
    }

    /**
     * Decide which content type the editor builds its fields from.
     *
     * An existing entry always wins, and is resolved at the version it pinned, so opening an old
     * entry never quietly migrates it onto a newer schema. For a new entry the `content_type` query
     * parameter selects by UUID or handle — that is how the "new entry of this type" links work — and
     * a refused submission names its own type instead, because a create is posted with the type in
     * the body and would otherwise come back rendered against the default type's fields.
     * When nothing was asked for, the core Page type is the ambient default so seeding further
     * layout types never changes what an unqualified `/administrator/content/new` creates; the
     * first defined type remains the fallback only on sites that removed the core type.
     *
     * @param   ServerRequestInterface       $request      Request whose query string may name a type.
     * @param   list<ContentTypeDefinition>  $definitions  Head versions available to the acting site.
     * @param   array<string, mixed>|null    $entry        Stored entry being edited, or null when creating.
     * @param   ?ContentEditorSubmission     $submission   Refused submission naming its own type, or null.
     *
     * @return  ContentTypeDefinition  The definition whose schema the rendered form is built from.
     *
     * @throws  \RuntimeException  When the entry's pinned type reference is unusable, or no type is defined.
     *
     * @since   2.0.0
     */
    private function selectedType(
        ServerRequestInterface $request,
        array $definitions,
        ?array $entry,
        ?ContentEditorSubmission $submission = null,
    ): ContentTypeDefinition {
        if ($entry !== null) {
            $id = $entry['content_type_id'] ?? null;
            $version = $entry['content_type_version'] ?? null;
            if (!is_string($id) || !is_int($version)) {
                throw new \RuntimeException('The stored content type reference is invalid.');
            }
            return $this->models->contentType(AdministratorRequest::context($request), $id, $version);
        }
        if ($definitions === []) {
            throw new \RuntimeException('At least one content type is required before content can be created.');
        }
        $requested = $submission === null ? '' : $submission->contentType;
        if ($requested === '') {
            $requested = $request->getQueryParams()['content_type'] ?? '';
        }
        if (is_string($requested) && $requested !== '') {
            foreach ($definitions as $definition) {
                if ($definition->id === $requested || $definition->handle === $requested) {
                    return $definition;
                }
            }
        }
        foreach ($definitions as $definition) {
            if ($definition->id === ContentService::CORE_PAGE_TYPE_ID) {
                return $definition;
            }
        }

        return $definitions[0];
    }
}
