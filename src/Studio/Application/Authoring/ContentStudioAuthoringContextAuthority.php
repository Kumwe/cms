<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

use DateInterval;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\App\Content\Application\ContentModelNotFound;
use Kumwe\App\Content\Application\ContentModelService;
use Kumwe\App\Content\Application\ContentNotFound;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Studio\Application\Host\StudioResourceContextKeyFactory;
use Kumwe\App\Studio\Application\Projection\ContentStudioProjector;
use Kumwe\App\Studio\Domain\Authoring\StudioAuthoringIntent;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\ExecutionContext;
use Psr\Clock\ClockInterface;

/**
 * Owns opaque, session-bound contexts for exact Content create and edit targets.
 *
 * An opaque key never grants authority. Opening and every later resolution reload the target through
 * authorized Content services and the canonical target resolver, then compare every coordinate and
 * revision with the immutable server-side binding. This implements the App authority portion of
 * `V2-STU-007`, `STUDIO-PROD-010`, and `STUDIO-PROD-012` without defining a Studio wire contract.
 * Every row expires no later than the administrator-session lifetime and grants nothing without a live
 * matching session and approval generation. Bounded installation-wide retention physically purges expired
 * rows; compare-and-swap context advancement remains deferred to the future coordinated host lifecycle.
 *
 * @since  2.0.0
 */
final readonly class ContentStudioAuthoringContextAuthority
{
    /**
     * Compose context persistence, opaque-key allocation, and authoritative Content resolution.
     *
     * @param   ContentStudioAuthoringContextRepository  $contexts                Immutable server-side context store.
     * @param   StudioResourceContextKeyFactory          $keys                    CSPRNG-backed opaque-key allocator.
     * @param ContentStudioAuthoringTargetResolver $targets Exact create/update authorization boundary.
     * @param   ContentModelService                      $models                  Authorized exact Content-type reader.
     * @param   ContentService                           $content                 Authorized exact Content-entry reader.
     * @param   ClockInterface                           $clock                   Trusted expiry clock.
     * @param   int                                      $sessionLifetimeSeconds  Configured administrator upper bound.
     *
     * @throws  InvalidArgumentException  When the supplied lifetime falls outside the administrator-session bounds.
     *
     * @since   2.0.0
     */
    public function __construct(
        private ContentStudioAuthoringContextRepository $contexts,
        private StudioResourceContextKeyFactory $keys,
        private ContentStudioAuthoringTargetResolver $targets,
        private ContentModelService $models,
        private ContentService $content,
        private ClockInterface $clock,
        private int $sessionLifetimeSeconds,
    ) {
        if ($sessionLifetimeSeconds < 300 || $sessionLifetimeSeconds > 604_800) {
            throw new InvalidArgumentException('The Studio Content authoring lifetime is invalid.');
        }
    }

    /**
     * Open one immutable context only after independently reproducing the supplied trusted target.
     *
     * The returned value is a lookup key, not a token: a later request still needs the same authenticated
     * administrator scope and browser session, and must pass fresh Content reads and mutation authorization.
     *
     * @param   ExecutionContext              $context  Current authenticated administrator request.
     * @param   ContentStudioAuthoringTarget  $target   PHP-resolved target proposed for this mount.
     *
     * @return  string  Opaque key carrying no actor, scope, resource, or authorization information.
     *
     * @throws  ContentStudioAuthoringContextRefused  When scope, session, target, or live authority is invalid.
     * @throws  ContentStudioAuthoringContextStale  When the target changed after it was first resolved.
     *
     * @since   2.0.0
     */
    public function open(ExecutionContext $context, ContentStudioAuthoringTarget $target): string
    {
        if ($context->surface() !== AuthenticatedSurface::Administrator) {
            self::refuse();
        }
        $sessionBinding = self::sessionBinding($context);
        $target = $this->revalidate($context, $target);
        $key = $this->keys->create();
        $createdAt = $this->clock->now();
        $this->contexts->add(new ContentStudioAuthoringContextBinding(
            $key,
            $context->actorId(),
            $context->site()->identifier(),
            $context->organization()?->identifier(),
            $context->workspace()?->identifier(),
            $context->surface()->value,
            $sessionBinding,
            $context->approvalFingerprint(),
            $target,
            $createdAt,
            $createdAt->add(new DateInterval('PT' . $this->sessionLifetimeSeconds . 'S')),
        ));

        return $key;
    }

    /**
     * Resolve one key against fresh identity, session, Content records, revisions, and policy.
     *
     * @param   ExecutionContext  $context     Current authenticated administrator request.
     * @param   string            $contextKey  Opaque server-issued context key.
     *
     * @return  ContentStudioAuthoringTarget  Fresh target equal to the immutable opened coordinates.
     *
     * @throws  ContentStudioAuthoringContextRefused  When the binding is absent, foreign, malformed, or unauthorized.
     * @throws  ContentStudioAuthoringContextStale  When an authorized target or authority generation moved.
     *
     * @since   2.0.0
     */
    public function resolve(ExecutionContext $context, string $contextKey): ContentStudioAuthoringTarget
    {
        if (!self::validContextKey($contextKey)) {
            self::refuse();
        }
        $binding = $this->contexts->find($contextKey);
        if ($binding === null || !self::sameTrustedScope($context, $binding)) {
            self::refuse();
        }
        if ($this->clock->now() >= $binding->expiresAt) {
            self::refuse();
        }
        $target = $this->revalidate($context, $binding->target);
        if (!hash_equals($binding->authorityBinding, $context->approvalFingerprint())) {
            throw new ContentStudioAuthoringContextStale($target);
        }

        return $target;
    }

    /**
     * Advance one opened context to the successor target an accepted durable effect produced.
     *
     * The successor is reproduced from live App state before it is stored, exactly as `resolve()`
     * would, so a caller cannot advance a context to coordinates the authenticated actor could not
     * open; the scope, session and expiry of the binding never move.
     *
     * @param   ExecutionContext              $context     Current authenticated administrator request.
     * @param   string                        $contextKey  Opaque server-issued context key.
     * @param   ContentStudioAuthoringTarget  $successor   PHP-resolved target after the accepted effect.
     *
     * @return  void
     *
     * @throws  ContentStudioAuthoringContextRefused  When the binding is absent, foreign, expired, or the
     *          successor cannot be reproduced from live authorized state.
     *
     * @since   2.0.0
     */
    public function advance(
        ExecutionContext $context,
        string $contextKey,
        ContentStudioAuthoringTarget $successor,
    ): void {
        if (!self::validContextKey($contextKey)) {
            self::refuse();
        }
        $binding = $this->contexts->find($contextKey);
        if ($binding === null || !self::sameTrustedScope($context, $binding)) {
            self::refuse();
        }
        if ($this->clock->now() >= $binding->expiresAt) {
            self::refuse();
        }
        try {
            $fresh = $this->revalidate($context, $successor);
        } catch (ContentStudioAuthoringContextStale $stale) {
            $fresh = $stale->current;
        }
        if ($fresh->toArray() !== $successor->toArray()) {
            self::refuse();
        }
        $this->contexts->advance(new ContentStudioAuthoringContextBinding(
            $binding->contextKey,
            $binding->actorId,
            $binding->siteId,
            $binding->organizationId,
            $binding->workspaceId,
            $binding->surface,
            $binding->sessionBinding,
            $binding->authorityBinding,
            $successor,
            $binding->createdAt,
            $binding->expiresAt,
        ));
    }

    /**
     * Reconstruct one target exclusively from live authorized App state and require exact equality.
     *
     * @param   ExecutionContext              $context  Fresh authenticated administrator request.
     * @param   ContentStudioAuthoringTarget  $stored   Immutable target whose coordinates must still be current.
     *
     * @return  ContentStudioAuthoringTarget  Freshly authorized target equal to the stored value.
     *
     * @throws  ContentStudioAuthoringContextRefused  On malformed, absent, denied, or changed target state.
     * @throws  ContentStudioAuthoringContextStale  When only the entry revision moved and the caller may advance.
     *
     * @since   2.0.0
     */
    private function revalidate(
        ExecutionContext $context,
        ContentStudioAuthoringTarget $stored,
    ): ContentStudioAuthoringTarget {
        try {
            $fresh = match ($stored->intent) {
                StudioAuthoringIntent::Create => $this->revalidateCreate($context, $stored),
                StudioAuthoringIntent::Edit => $this->revalidateEdit($context, $stored),
            };
        } catch (
            AuthorizationDenied
            | ContentModelNotFound
            | ContentNotFound
            | ContentStudioAuthoringTargetMismatch
        ) {
            self::refuse();
        }
        if ($fresh->toArray() !== $stored->toArray()) {
            if (self::sameTargetGenerationExceptEntryRevision($stored, $fresh)) {
                throw new ContentStudioAuthoringContextStale($fresh);
            }
            self::refuse();
        }

        return $fresh;
    }

    /**
     * Rebuild a blank or explicitly typed create target through current model reads and create authority.
     *
     * @param   ExecutionContext              $context  Fresh authenticated administrator request.
     * @param   ContentStudioAuthoringTarget  $stored   Stored create target.
     *
     * @return  ContentStudioAuthoringTarget  Freshly authorized create target.
     *
     * @throws  ContentStudioAuthoringContextRefused  When the stored coordinate combination is malformed.
     * @throws  ContentModelNotFound  When an explicitly bound Content type no longer resolves.
     * @throws  AuthorizationDenied  When current model-read or create authority is refused.
     * @throws  ContentStudioAuthoringTargetMismatch  When the type belongs to an inconsistent scope.
     *
     * @since   2.0.0
     */
    private function revalidateCreate(
        ExecutionContext $context,
        ContentStudioAuthoringTarget $stored,
    ): ContentStudioAuthoringTarget {
        if ($stored->entryId !== null || $stored->entryRevision !== null) {
            self::refuse();
        }
        if (
            $stored->modelId === null
            && $stored->modelVersion === null
            && $stored->modelRevision === null
        ) {
            return $this->targets->create($context);
        }
        if ($stored->modelId === null || $stored->modelVersion === null || $stored->modelRevision === null) {
            self::refuse();
        }
        $contentTypeId = ContentStudioProjector::contentTypeId($stored->modelId);
        $contentTypeVersion = ContentStudioProjector::contentTypeVersion($stored->modelVersion);
        if (
            $contentTypeId === null
            || $contentTypeVersion === null
            || $stored->modelRevision !== ContentStudioProjector::modelRevision($contentTypeVersion)
        ) {
            self::refuse();
        }

        return $this->targets->create(
            $context,
            $this->models->contentType($context, $contentTypeId, $contentTypeVersion),
        );
    }

    /**
     * Rebuild an edit target from the current entry and its exact pinned Content-type version.
     *
     * @param   ExecutionContext              $context  Fresh authenticated administrator request.
     * @param   ContentStudioAuthoringTarget  $stored   Stored exact edit target.
     *
     * @return  ContentStudioAuthoringTarget  Freshly read and authorized edit target.
     *
     * @throws  ContentStudioAuthoringContextRefused  When required stored coordinates are absent or malformed.
     * @throws  ContentNotFound  When the exact Content entry no longer resolves.
     * @throws  ContentModelNotFound  When the entry's pinned Content type no longer resolves.
     * @throws  AuthorizationDenied  When current entry-read, model-read, or update authority is refused.
     * @throws  ContentStudioAuthoringTargetMismatch  When authoritative Content coordinates disagree.
     *
     * @since   2.0.0
     */
    private function revalidateEdit(
        ExecutionContext $context,
        ContentStudioAuthoringTarget $stored,
    ): ContentStudioAuthoringTarget {
        if (
            $stored->modelId === null
            || $stored->modelVersion === null
            || $stored->modelRevision === null
            || $stored->entryId === null
            || $stored->entryRevision === null
        ) {
            self::refuse();
        }
        $entryId = ContentStudioProjector::contentEntryId($stored->entryId);
        $contentTypeId = ContentStudioProjector::contentTypeId($stored->modelId);
        $contentTypeVersion = ContentStudioProjector::contentTypeVersion($stored->modelVersion);
        $entryVersion = ContentStudioProjector::contentEntryVersion($stored->entryRevision);
        if (
            $entryId === null
            || $contentTypeId === null
            || $contentTypeVersion === null
            || $stored->modelRevision !== ContentStudioProjector::modelRevision($contentTypeVersion)
            || $entryVersion === null
        ) {
            self::refuse();
        }
        $record = $this->content->get($context, $entryId);
        if (
            $record->contentTypeId !== $contentTypeId
            || $record->contentTypeVersion !== $contentTypeVersion
        ) {
            self::refuse();
        }
        $definition = $this->models->contentType(
            $context,
            $record->contentTypeId,
            $record->contentTypeVersion,
        );

        return $this->targets->edit($context, $record, $definition);
    }

    /**
     * Compare a binding only with scope coordinates re-established by the authenticated request.
     *
     * @param   ExecutionContext                      $context  Fresh authenticated App context.
     * @param   ContentStudioAuthoringContextBinding  $binding  Stored opaque-key binding.
     *
     * @return  bool  True only when actor, site, membership, surface, and browser session match exactly.
     *
     * @since   2.0.0
     */
    private static function sameTrustedScope(
        ExecutionContext $context,
        ContentStudioAuthoringContextBinding $binding,
    ): bool {
        return $context->surface() === AuthenticatedSurface::Administrator
            && hash_equals($binding->actorId, $context->actorId())
            && hash_equals($binding->siteId, $context->site()->identifier())
            && $binding->organizationId === $context->organization()?->identifier()
            && $binding->workspaceId === $context->workspace()?->identifier()
            && $binding->surface === $context->surface()->value
            && $context->sessionId() !== null
            && hash_equals($binding->sessionBinding, self::sessionBinding($context));
    }

    /**
     * Detect only an authorized optimistic Entry revision change, never a coordinate substitution.
     *
     * @param   ContentStudioAuthoringTarget  $stored  Immutable target generation opened earlier.
     * @param   ContentStudioAuthoringTarget  $fresh   Current authorized target generation.
     *
     * @return  bool  True when the Entry revision is the sole changed coordinate.
     *
     * @since   2.0.0
     */
    private static function sameTargetGenerationExceptEntryRevision(
        ContentStudioAuthoringTarget $stored,
        ContentStudioAuthoringTarget $fresh,
    ): bool {
        if ($stored->intent !== StudioAuthoringIntent::Edit || $fresh->intent !== StudioAuthoringIntent::Edit) {
            return false;
        }
        $storedState = $stored->toArray();
        $freshState = $fresh->toArray();
        unset($storedState['entry_revision'], $freshState['entry_revision']);

        return $storedState === $freshState && $stored->entryRevision !== $fresh->entryRevision;
    }

    /**
     * Bind an authoring context to the rotated administrator-session identity without storing it.
     *
     * @param   ExecutionContext  $context  Fresh trusted administrator context.
     *
     * @return  string  Lowercase SHA-256 digest of the non-exported host-session identity.
     *
     * @throws  ContentStudioAuthoringContextRefused  When no authenticated browser session is present.
     *
     * @since   2.0.0
     */
    private static function sessionBinding(ExecutionContext $context): string
    {
        $sessionId = $context->sessionId();
        if ($sessionId === null) {
            self::refuse();
        }

        return hash('sha256', $sessionId);
    }

    /**
     * Bound an opaque lookup before it reaches persistence.
     *
     * @param   string  $contextKey  Candidate server-issued stable identifier.
     *
     * @return  bool  True only for the bounded canonical stable-ID profile.
     *
     * @since   2.0.0
     */
    private static function validContextKey(string $contextKey): bool
    {
        return strlen($contextKey) <= 240
            && preg_match('/^contexts\/[a-f0-9]{64}$/D', $contextKey) === 1;
    }

    /**
     * Raise one stable refusal that discloses no target, identity, scope, or policy reason.
     *
     * @return  never
     *
     * @throws  ContentStudioAuthoringContextRefused  Always.
     *
     * @since   2.0.0
     */
    private static function refuse(): never
    {
        throw new ContentStudioAuthoringContextRefused('The Studio Content authoring context was refused.');
    }
}
