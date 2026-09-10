<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Preview;

use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Application\Projection\StudioContentProjectionService;
use Kumwe\App\Studio\Application\Projection\StudioProjectionRejected;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewDraft;
use Kumwe\Context\Value\ExecutionContext;
use stdClass;

/**
 * Resolves preview values through the existing authorized Content projection without copying authority.
 *
 * A Blueprint session proves direct artifact ownership and carries no entry values. A Content session
 * reuses AP-2 to read the projected model or entry, verifies that model's host-owned Blueprint binding,
 * and exposes only the projected values that survived Content's record and field disclosure policy.
 *
 * @since  2.0.0
 */
final readonly class ContentStudioPreviewBindingSource implements StudioPreviewBindingSource
{
    /**
     * Bind preview resolution to the read-only authorized Content projection.
     *
     * @param  StudioContentProjectionService  $content  Existing App-owned model and entry read boundary.
     *
     * @since  2.0.0
     */
    public function __construct(private StudioContentProjectionService $content)
    {
    }

    /**
     * Resolve the session resource and prove its exact model-to-Blueprint coordinate.
     *
     * @param   ExecutionContext           $context   Authenticated App request authority.
     * @param   StudioHostSessionSnapshot  $snapshot  Live resource and permission binding.
     * @param   StudioPreviewDraft         $draft     Exact unpublished Blueprint being rendered.
     *
     * @return  StudioPreviewBindingValues  Canonical values authorized by Content disclosure policy.
     *
     * @throws  StudioPreviewRefused  When the resource cannot be disclosed or is bound elsewhere.
     *
     * @since   2.0.0
     */
    public function resolve(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        StudioPreviewDraft $draft,
    ): StudioPreviewBindingValues {
        if ($snapshot->session->resourceKind === StudioResourceKind::Blueprint) {
            if (!hash_equals($snapshot->session->resourceId, $draft->artifactId())) {
                throw new StudioPreviewRefused('forbidden', 'studio.preview/resource-refused');
            }

            return new StudioPreviewBindingValues(new stdClass(), new stdClass());
        }

        try {
            [$model, $values] = str_starts_with($snapshot->session->resourceId, 'content-entry:')
                ? $this->entry($context, $snapshot->session->resourceId)
                : $this->model($context, $snapshot->session->resourceId, $draft);
        } catch (StudioProjectionRejected) {
            throw new StudioPreviewRefused('forbidden', 'studio.preview/resource-refused');
        }
        $document = $draft->document();
        $draftModel = $document->model ?? null;
        if (!$draftModel instanceof stdClass || !self::sameCoordinate($draftModel, $model)) {
            throw new StudioPreviewRefused('conflict', 'studio.preview/model-binding-mismatch');
        }
        self::assertBlueprintBinding($model, $document);

        return new StudioPreviewBindingValues($values, new stdClass());
    }

    /**
     * Project a Content entry and its exact pinned model through App authority.
     *
     * @param   ExecutionContext  $context  Authenticated App request authority.
     * @param   string            $entryId  Reversible projected Content entry identifier.
     *
     * @return  array{stdClass, stdClass}  Exact projected model coordinate and authorized values.
     *
     * @throws  StudioProjectionRejected  When Content refuses or cannot project the entry.
     *
     * @since   2.0.0
     */
    private function entry(ExecutionContext $context, string $entryId): array
    {
        $entry = $this->content->entry($context, $entryId);
        $model = $entry->model ?? null;
        $values = $entry->values ?? null;
        if (!$model instanceof stdClass || !$values instanceof stdClass) {
            throw new StudioPreviewRefused('unavailable', 'studio.preview/content-projection-invalid');
        }
        $id = $model->id ?? null;
        $version = $model->version ?? null;
        if (!is_string($id) || !is_string($version)) {
            throw new StudioPreviewRefused('unavailable', 'studio.preview/content-projection-invalid');
        }

        return [$this->content->model($context, $id, $version), $values];
    }

    /**
     * Project a model-bound preview that intentionally has no entry values yet.
     *
     * @param   ExecutionContext    $context  Authenticated App request authority.
     * @param   string              $modelId  Reversible projected Content model identifier.
     * @param   StudioPreviewDraft  $draft    Blueprint whose exact model version is requested.
     *
     * @return  array{stdClass, stdClass}  Projected model and empty entry values.
     *
     * @throws  StudioProjectionRejected  When Content refuses or cannot project the model.
     *
     * @since   2.0.0
     */
    private function model(ExecutionContext $context, string $modelId, StudioPreviewDraft $draft): array
    {
        $model = $draft->document()->model ?? null;
        $draftModelId = $model instanceof stdClass ? $model->id ?? null : null;
        if (!$model instanceof stdClass || !is_string($draftModelId) || !hash_equals($draftModelId, $modelId)) {
            throw new StudioPreviewRefused('forbidden', 'studio.preview/resource-refused');
        }
        $version = $model->version ?? null;
        if (!is_string($version)) {
            throw new StudioPreviewRefused('conflict', 'studio.preview/model-binding-mismatch');
        }

        return [$this->content->model($context, $modelId, $version), new stdClass()];
    }

    /**
     * Compare a Blueprint model lock with the authoritative projected model coordinate.
     *
     * @param   stdClass  $draftModel      Model coordinate locked by the Blueprint.
     * @param   stdClass  $projectedModel  Authoritative projected Content model.
     *
     * @return  bool  True only when ID, version, and revision agree byte for byte.
     *
     * @since   2.0.0
     */
    private static function sameCoordinate(stdClass $draftModel, stdClass $projectedModel): bool
    {
        foreach (['id', 'version', 'revision'] as $member) {
            $left = $draftModel->{$member} ?? null;
            $right = $projectedModel->{$member} ?? null;
            if (!is_string($left) || !is_string($right) || !hash_equals($left, $right)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Prove the authoritative model selected this exact Blueprint coordinate.
     *
     * @param   stdClass  $model      Authoritative projected Content model.
     * @param   stdClass  $blueprint  Exact Blueprint document being rendered.
     *
     * @return  void
     *
     * @throws  StudioPreviewRefused  When no exact binding exists.
     *
     * @since   2.0.0
     */
    private static function assertBlueprintBinding(stdClass $model, stdClass $blueprint): void
    {
        $extensions = $model->extensions ?? null;
        $binding = $extensions instanceof stdClass ? $extensions->{'kumwe.app/blueprint-binding'} ?? null : null;
        if (!$binding instanceof stdClass) {
            throw new StudioPreviewRefused('conflict', 'studio.preview/model-binding-mismatch');
        }
        foreach (['id', 'version'] as $member) {
            $expected = $blueprint->{$member} ?? null;
            $actual = $binding->{$member} ?? null;
            if (!is_string($expected) || !is_string($actual) || !hash_equals($expected, $actual)) {
                throw new StudioPreviewRefused('conflict', 'studio.preview/model-binding-mismatch');
            }
        }
        $boundRevision = $binding->revision ?? null;
        if ($boundRevision !== null) {
            $revision = $blueprint->revision ?? null;
            if (!is_string($boundRevision) || !is_string($revision) || !hash_equals($revision, $boundRevision)) {
                throw new StudioPreviewRefused('conflict', 'studio.preview/model-binding-mismatch');
            }
        }
    }
}
