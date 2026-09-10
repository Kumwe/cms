<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use Kumwe\App\Studio\Domain\Artifact\StoredStudioArtifact;
use Kumwe\App\Studio\Domain\Artifact\StudioArtifactReference;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Producer\Canonical\CanonicalJson;
use Kumwe\Producer\Error\HostRefusal;
use Kumwe\Producer\Wire\HostResult;
use Kumwe\Producer\Wire\Port\ArtifactPortInterface;
use Kumwe\Producer\Wire\RequestContext;
use stdClass;

/**
 * Complete versioned implementation of Studio's canonical artifact host port.
 *
 * @since  2.0.0
 */
final readonly class StudioArtifactHostPort implements ArtifactPortInterface
{
    /**
     * Bind artifact admission and persistence to the App's direct Producer port.
     *
     * @param  StudioArtifactRepository             $artifacts    Versioned artifact persistence port.
     * @param  StudioArtifactAdmission              $admission    Schema and active-content boundary.
     * @param  StudioArtifactPublicationGuard       $publication  Exact public-runtime dependency guard.
     * @param  StudioProducerRequestAuthority|null  $authority    Authorized Producer request scope, when bound.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioArtifactRepository $artifacts,
        private StudioArtifactAdmission $admission,
        private StudioArtifactPublicationGuard $publication,
        private ?StudioProducerRequestAuthority $authority = null,
    ) {
    }

    /**
     * Bind this App-owned port implementation to one successfully authorized Producer request.
     *
     * @param   StudioProducerRequestAuthority  $authority  Trusted evidence for one exact dispatch.
     *
     * @return  self  Request-scoped artifact port.
     *
     * @since   2.0.0
     */
    public function forRequest(StudioProducerRequestAuthority $authority): self
    {
        return new self($this->artifacts, $this->admission, $this->publication, $authority);
    }

    /**
     * Return the admitted artifact's complete locked dependency set.
     *
     * @param   mixed           $arguments  Validated Producer operation arguments.
     * @param   RequestContext  $context    Validated Producer request context.
     *
     * @return  HostResult  Canonical dependency list.
     *
     * @since   2.0.0
     */
    public function dependencies(mixed $arguments, RequestContext $context): HostResult
    {
        $snapshot = $this->requestAuthority()->snapshot();
        $this->requireReadContext($context);
        $reference = $this->referenceArgument($arguments);
        $artifact = $this->find($snapshot, $reference);

        return new HostResult($artifact->dependencies());
    }

    /**
     * Load a current or immutable historical artifact revision.
     *
     * @param   mixed           $arguments  Validated Producer operation arguments.
     * @param   RequestContext  $context    Validated Producer request context.
     *
     * @return  HostResult  Canonical artifact document and revision.
     *
     * @since   2.0.0
     */
    public function load(mixed $arguments, RequestContext $context): HostResult
    {
        $snapshot = $this->requestAuthority()->snapshot();
        $this->requireReadContext($context);
        $reference = $this->referenceArgument($arguments);
        $artifact = $this->find($snapshot, $reference);

        return new HostResult($artifact->document(), $artifact->revision);
    }

    /**
     * Append one schema-valid artifact revision under optimistic concurrency.
     *
     * @param   mixed           $arguments  Validated Producer operation arguments.
     * @param   RequestContext  $context    Validated Producer request context.
     *
     * @return  HostResult  Empty success value and generated revision.
     *
     * @since   2.0.0
     */
    public function save(mixed $arguments, RequestContext $context): HostResult
    {
        $snapshot = $this->requestAuthority()->snapshot();
        $this->requirePermission($snapshot, 'studio.permission/save');
        $expectedRevision = $this->requireExpectedRevision($context);
        $wrapper = $this->exactArguments($arguments, ['document']);
        $document = $wrapper->document;
        $admitted = $this->admission->admit($snapshot->session->siteId, $document);
        $this->requireResource($snapshot, $admitted->id, $admitted->kind);
        if (!hash_equals($expectedRevision, $admitted->revision)) {
            StudioProducerError::refuse(
                'conflict',
                'studio.artifact/revision-conflict',
                $this->safeCurrentRevision($snapshot, $admitted->id, $admitted->version),
            );
        }

        $current = $this->artifacts->current(
            $snapshot->session->siteId,
            $admitted->id,
            $admitted->version,
        );
        if ($current === null) {
            StudioProducerError::refuse('not-found', 'studio.artifact/not-found');
        }
        $this->requireResource($snapshot, $current->id, $current->kind);
        if (!hash_equals($current->revision, $expectedRevision)) {
            StudioProducerError::refuse(
                'conflict',
                'studio.artifact/revision-conflict',
                $current->revision,
            );
        }
        $this->requireSaveContinuity($current, $admitted);
        $nextRevision = $this->nextRevision($admitted, $context, $expectedRevision);
        $next = $this->admission->revise($admitted, $nextRevision, $current->status);
        try {
            $stored = $this->artifacts->store($next, $current->revision);
        } catch (StudioPersistenceRace) {
            StudioProducerError::refuse(
                'unavailable',
                'studio.host/concurrent-mutation',
                retryable: true,
            );
        }
        if (!$stored) {
            StudioProducerError::refuse(
                'conflict',
                'studio.artifact/revision-conflict',
                $this->safeCurrentRevision($snapshot, $admitted->id, $admitted->version),
            );
        }

        return new HostResult(null, $nextRevision);
    }

    /**
     * Keep generic saves inside the current draft's lifecycle, coordinate and Blueprint locks.
     *
     * @param   StoredStudioArtifact  $current    Transactionally read current artifact head.
     * @param   StoredStudioArtifact  $candidate  Schema-admitted save candidate.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function requireSaveContinuity(
        StoredStudioArtifact $current,
        StoredStudioArtifact $candidate,
    ): void {
        if (
            !hash_equals($current->siteIdentifier, $candidate->siteIdentifier)
            || !hash_equals($current->id, $candidate->id)
            || !hash_equals($current->version, $candidate->version)
            || !hash_equals($current->kind, $candidate->kind)
        ) {
            StudioProducerError::refuse(
                'conflict',
                'studio.artifact/coordinate-conflict',
                $current->revision,
            );
        }
        if ($current->status !== 'draft') {
            StudioProducerError::refuse(
                'conflict',
                'studio.artifact/not-draft',
                $current->revision,
            );
        }
        if (!hash_equals($current->status, $candidate->status)) {
            StudioProducerError::refuse(
                'conflict',
                'studio.artifact/lifecycle-change-requires-publish',
                $current->revision,
            );
        }
        if (
            $current->kind === 'blueprint'
            && !hash_equals($this->blueprintLockBytes($current), $this->blueprintLockBytes($candidate))
        ) {
            StudioProducerError::refuse(
                'conflict',
                'studio.artifact/blueprint-lock-conflict',
                $current->revision,
            );
        }
    }

    /**
     * Canonicalize the Blueprint ownership, model and dependency-lock seam as one comparison value.
     *
     * @param   StoredStudioArtifact  $artifact  Schema-admitted Blueprint artifact.
     *
     * @return  string  Canonical bytes for every immutable Blueprint lock.
     *
     * @since   2.0.0
     */
    private function blueprintLockBytes(StoredStudioArtifact $artifact): string
    {
        $document = $artifact->document();

        return CanonicalJson::stringify((object) [
            'dependencyLock' => $document->dependencyLock,
            'model' => $document->model,
            'owner' => $document->owner,
        ]);
    }

    /**
     * Append one publish lifecycle revision.
     *
     * @param   mixed           $arguments  Validated Producer operation arguments.
     * @param   RequestContext  $context    Validated Producer request context.
     *
     * @return  HostResult  Empty success value and generated revision.
     *
     * @since   2.0.0
     */
    public function publish(mixed $arguments, RequestContext $context): HostResult
    {
        return $this->setPublished($arguments, $context, true);
    }

    /**
     * Append one unpublish lifecycle revision.
     *
     * @param   mixed           $arguments  Validated Producer operation arguments.
     * @param   RequestContext  $context    Validated Producer request context.
     *
     * @return  HostResult  Empty success value and generated revision.
     *
     * @since   2.0.0
     */
    public function unpublish(mixed $arguments, RequestContext $context): HostResult
    {
        return $this->setPublished($arguments, $context, false);
    }

    /**
     * Append a publish or unpublish lifecycle revision.
     *
     * @param   mixed           $arguments  Validated Producer operation arguments.
     * @param   RequestContext  $context    Validated Producer request context.
     * @param   bool            $published  Whether the target status is published.
     *
     * @return  HostResult  Empty success value and generated revision.
     *
     * @since   2.0.0
     */
    private function setPublished(mixed $arguments, RequestContext $context, bool $published): HostResult
    {
        $snapshot = $this->requestAuthority()->snapshot();
        $this->requireLifecyclePermission($snapshot, $published);
        $expectedRevision = $this->requireExpectedRevision($context);
        $reference = $this->referenceArgument($arguments);
        $this->requireResourceId($snapshot, $reference->id);

        $current = $this->artifacts->current(
            $snapshot->session->siteId,
            $reference->id,
            $reference->version,
        );
        if ($current === null) {
            StudioProducerError::refuse('not-found', 'studio.artifact/not-found');
        }
        $this->requireResource($snapshot, $current->id, $current->kind);
        if (!hash_equals($current->revision, $expectedRevision)) {
            StudioProducerError::refuse(
                'conflict',
                'studio.artifact/revision-conflict',
                $current->revision,
            );
        }
        if ($current->status === 'retired') {
            StudioProducerError::refuse('conflict', 'studio.artifact/retired');
        }
        if ($published && $this->isAppOwnedBlueprint($current)) {
            try {
                $this->publication->assertPublishable(
                    SiteContext::fromString($current->siteIdentifier),
                    $current->document(),
                );
            } catch (HostRefusal $refused) {
                $error = $refused->error();
                $diagnostics = $error->diagnostics();
                StudioProducerError::refuse(
                    $error->category(),
                    isset($diagnostics[0])
                        ? $diagnostics[0]->code()
                        : 'studio.artifact/blueprint-incompatible',
                    $current->revision,
                    $error->retryable(),
                    $error->retryAfterMilliseconds(),
                );
            }
        }
        $status = $published ? 'published' : 'draft';
        $nextRevision = $this->nextRevision($current, $context, $expectedRevision);
        $next = $this->admission->revise($current, $nextRevision, $status);
        try {
            $stored = $this->artifacts->store($next, $current->revision);
        } catch (StudioPersistenceRace) {
            StudioProducerError::refuse(
                'unavailable',
                'studio.host/concurrent-mutation',
                retryable: true,
            );
        }
        if (!$stored) {
            StudioProducerError::refuse(
                'conflict',
                'studio.artifact/revision-conflict',
                $this->safeCurrentRevision($snapshot, $current->id, $current->version),
            );
        }

        return new HostResult(null, $nextRevision);
    }

    /**
     * Select only the host-owned Content composition family governed by the public App renderer.
     *
     * Other Blueprint owners retain their own publication authority and are never interpreted as App
     * Content pages merely because they use the same portable Studio artifact kind.
     *
     * @param   StoredStudioArtifact  $artifact  Current schema-admitted artifact head.
     *
     * @return  bool  True only for the exact App Content owner coordinate.
     *
     * @since   2.0.0
     */
    private function isAppOwnedBlueprint(StoredStudioArtifact $artifact): bool
    {
        if ($artifact->kind !== 'blueprint') {
            return false;
        }
        $owner = $artifact->document()->owner ?? null;

        return $owner instanceof stdClass
            && count(get_object_vars($owner)) === 2
            && ($owner->id ?? null) === 'kumwe.app/content'
            && ($owner->version ?? null) === '2.0.0';
    }

    /**
     * Resolve a current or immutable historical artifact without disclosing foreign resources.
     *
     * @param   StudioHostSessionSnapshot  $snapshot   Trusted live host session.
     * @param   StudioArtifactReference    $reference  Strict canonical artifact reference.
     *
     * @return  StoredStudioArtifact  Authorized admitted artifact.
     *
     * @since   2.0.0
     */
    private function find(
        StudioHostSessionSnapshot $snapshot,
        StudioArtifactReference $reference,
    ): StoredStudioArtifact {
        $this->requireResourceId($snapshot, $reference->id);
        $artifact = $reference->revision !== null
            ? $this->artifacts->revision(
                $snapshot->session->siteId,
                $reference->id,
                $reference->version,
                $reference->revision,
            )
            : $this->artifacts->current(
                $snapshot->session->siteId,
                $reference->id,
                $reference->version,
            );
        if ($artifact === null) {
            StudioProducerError::refuse('not-found', 'studio.artifact/not-found');
        }
        $this->requireResource($snapshot, $artifact->id, $artifact->kind);

        return $artifact;
    }

    /**
     * Decode the exact published ArtifactReference HTTP wrapper member.
     *
     * @param   mixed  $arguments  Validated Producer operation arguments.
     *
     * @return  StudioArtifactReference  Strict canonical reference value.
     *
     * @since   2.0.0
     */
    private function referenceArgument(mixed $arguments): StudioArtifactReference
    {
        $wrapper = $this->exactArguments($arguments, ['reference']);
        $reference = $wrapper->reference;
        if (!$reference instanceof stdClass) {
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-arguments');
        }
        $members = array_keys(get_object_vars($reference));
        sort($members, SORT_STRING);
        if (
            !in_array($members, [
            ['id', 'version'],
            ['id', 'integrity', 'version'],
            ['id', 'revision', 'version'],
            ['id', 'integrity', 'revision', 'version'],
            ], true)
        ) {
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-arguments');
        }
        $id = $reference->id;
        $version = $reference->version;
        $revision = property_exists($reference, 'revision') ? $reference->revision : null;
        $integrity = property_exists($reference, 'integrity') ? $reference->integrity : null;
        if (
            !is_string($id) || $id === '' || strlen($id) > 240
            || !is_string($version) || $version === '' || strlen($version) > 100
            || $revision !== null && (!is_string($revision) || $revision === '' || strlen($revision) > 200)
            || $integrity !== null && (!is_string($integrity) || $integrity === '' || strlen($integrity) > 500)
        ) {
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-arguments');
        }

        return new StudioArtifactReference($id, $version, $revision, $integrity);
    }

    /**
     * Require an exact closed HTTP wrapper shape.
     *
     * @param   mixed         $arguments  Validated Producer operation arguments.
     * @param   list<string>  $members    Required member names.
     *
     * @return  stdClass  Exact argument wrapper.
     *
     * @since   2.0.0
     */
    private function exactArguments(mixed $arguments, array $members): stdClass
    {
        if (!$arguments instanceof stdClass) {
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-arguments');
        }
        $actual = array_keys(get_object_vars($arguments));
        sort($actual, SORT_STRING);
        sort($members, SORT_STRING);
        if ($actual !== $members) {
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-arguments');
        }

        return $arguments;
    }

    /**
     * Refuse mutation context fields on read-only artifact operations.
     *
     * @param   RequestContext  $context  Validated Producer request context.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function requireReadContext(RequestContext $context): void
    {
        if ($context->expectedRevision !== null || $context->idempotencyKey !== null) {
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-context');
        }
    }

    /**
     * Require the optimistic revision carried by every artifact mutation.
     *
     * @param   RequestContext  $context  Validated Producer request context.
     *
     * @return  string  Non-empty expected revision.
     *
     * @since   2.0.0
     */
    private function requireExpectedRevision(RequestContext $context): string
    {
        if ($context->expectedRevision === null || $context->expectedRevision === '') {
            StudioProducerError::refuse('invalid-request', 'studio.artifact/expected-revision-required');
        }

        return $context->expectedRevision;
    }

    /**
     * Require one effective server-resolved Studio permission.
     *
     * @param   StudioHostSessionSnapshot  $snapshot    Trusted live host session.
     * @param   string                     $permission  Canonical permission name.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function requirePermission(StudioHostSessionSnapshot $snapshot, string $permission): void
    {
        if (!in_array($permission, $snapshot->permissions, true)) {
            StudioProducerError::refuse('forbidden', 'studio.host/action-forbidden');
        }
    }

    /**
     * Require the exact server-resolved authority for one lifecycle transition target.
     *
     * @param   StudioHostSessionSnapshot  $snapshot   Trusted live host session.
     * @param   bool                       $published  Whether the target status is published.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function requireLifecyclePermission(StudioHostSessionSnapshot $snapshot, bool $published): void
    {
        if ($published ? !$snapshot->canPublish : !$snapshot->canUnpublish) {
            StudioProducerError::refuse('forbidden', 'studio.host/action-forbidden');
        }
    }

    /**
     * Require the artifact identifier bound into the trusted session.
     *
     * @param   StudioHostSessionSnapshot  $snapshot  Trusted live host session.
     * @param   string                     $id        Candidate artifact identifier.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function requireResourceId(StudioHostSessionSnapshot $snapshot, string $id): void
    {
        if (!hash_equals($snapshot->session->resourceId, $id)) {
            StudioProducerError::refuse('not-found', 'studio.artifact/not-found');
        }
    }

    /**
     * Require the artifact kind to fit the trusted resource family and canonical mode.
     *
     * @param   StudioHostSessionSnapshot  $snapshot  Trusted live host session.
     * @param   string                     $id        Candidate artifact identifier.
     * @param   string                     $kind      Admitted artifact kind.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function requireResource(StudioHostSessionSnapshot $snapshot, string $id, string $kind): void
    {
        $this->requireResourceId($snapshot, $id);
        $fits = match ($kind) {
            'blueprint' => $snapshot->session->resourceKind === StudioResourceKind::Blueprint
                && in_array(
                    $snapshot->session->mode,
                    [StudioSessionMode::Blueprint, StudioSessionMode::ReadOnly],
                    true,
                ),
            'content-model' => $snapshot->session->resourceKind === StudioResourceKind::Content
                && in_array($snapshot->session->mode, [StudioSessionMode::Model, StudioSessionMode::ReadOnly], true),
            'entry' => $snapshot->session->resourceKind === StudioResourceKind::Content
                && in_array(
                    $snapshot->session->mode,
                    [StudioSessionMode::Content, StudioSessionMode::Hybrid, StudioSessionMode::ReadOnly],
                    true,
                ),
            default => false,
        };
        if (!$fits) {
            StudioProducerError::refuse('forbidden', 'studio.host/session-refused');
        }
    }

    /**
     * Reveal a current revision only for the artifact already bound to the session.
     *
     * @param   StudioHostSessionSnapshot  $snapshot  Trusted live host session.
     * @param   string                     $id        Candidate artifact identifier.
     * @param   string                     $version   Candidate artifact version.
     *
     * @return  string|null  Safe current revision or null.
     *
     * @since   2.0.0
     */
    private function safeCurrentRevision(
        StudioHostSessionSnapshot $snapshot,
        string $id,
        string $version,
    ): ?string {
        if (!hash_equals($snapshot->session->resourceId, $id)) {
            return null;
        }

        return $this->artifacts->current($snapshot->session->siteId, $id, $version)?->revision;
    }

    /**
     * Derive one deterministic host revision from the admitted artifact and request.
     *
     * @param   StoredStudioArtifact  $artifact  Admitted mutation input.
     * @param   RequestContext        $context   Validated Producer request context.
     * @param   string                $previous  Optimistic predecessor revision.
     *
     * @return  string  Deterministic opaque revision.
     *
     * @since   2.0.0
     */
    private function nextRevision(
        StoredStudioArtifact $artifact,
        RequestContext $context,
        string $previous,
    ): string {
        return 'studio-r/' . hash('sha256', CanonicalJson::stringify((object) [
            'artifactDigest' => hash('sha256', $artifact->canonicalDocument),
            'id' => $artifact->id,
            'operationId' => $context->operationId,
            'previousRevision' => $previous,
            'requestId' => $context->requestId,
            'version' => $artifact->version,
        ]));
    }

    /**
     * Require the per-request authority installed by the Producer host factory.
     *
     * @return  StudioProducerRequestAuthority  Trusted evidence for this dispatch.
     *
     * @since   2.0.0
     */
    private function requestAuthority(): StudioProducerRequestAuthority
    {
        return $this->authority ?? throw new \LogicException('A Studio artifact port requires request authority.');
    }
}
