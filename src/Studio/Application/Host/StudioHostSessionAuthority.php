<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Studio\Application\Composition\StudioPublishedTheme;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;

/**
 * Production identity, policy and generation boundary for Studio host sessions.
 *
 * Every decision is evaluated through the deny-by-default application gateway. The Studio request can
 * name a mode and resource only while opening; the authenticated execution context supplies identity,
 * site, organization, workspace and surface. Later calls resolve those coordinates from the opaque key
 * and compare them to fresh trusted context before permissions or resource existence can be disclosed.
 *
 * @since  2.0.0
 */
final readonly class StudioHostSessionAuthority
{
    /**
     * Host capabilities the composed artifact, media, permission, preview and recovery runtime implements.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const array HOST_CAPABILITIES = [
        'studio.operation/artifact.dependencies',
        'studio.operation/artifact.load',
        'studio.operation/artifact.publish',
        'studio.operation/artifact.save',
        'studio.operation/artifact.unpublish',
        'studio.operation/localization.messages',
        'studio.operation/media.abort-upload',
        'studio.operation/media.authorize-upload',
        'studio.operation/media.complete-upload',
        'studio.operation/media.get',
        'studio.operation/media.import-external',
        'studio.operation/media.list',
        'studio.operation/media.upload-status',
        'studio.operation/model.get',
        'studio.operation/model.list',
        'studio.operation/permission.explain',
        'studio.operation/permission.refresh',
        'studio.operation/preview.cancel',
        'studio.operation/preview.render',
        'studio.operation/recovery.discard',
        'studio.operation/recovery.load',
        'studio.operation/recovery.store',
        'studio.operation/resource.search',
        'studio.operation/telemetry.emit',
        'studio.port/artifact',
        'studio.port/localization',
        'studio.port/media',
        'studio.port/model',
        'studio.port/permission',
        'studio.port/preview',
        'studio.port/recovery',
        'studio.port/resource',
        'studio.port/telemetry',
    ];

    /**
     * Additional host capabilities served only to a contextual Content authoring session.
     *
     * The seven authoring operations and their port are advertised, routed and authorized only for a
     * session whose resource is an opaque Content authoring context; a Blueprint or plain Content
     * session never sees them, so its generation and route table stay exactly as before.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const array AUTHORING_CAPABILITIES = [
        'studio.operation/authoring.list-types',
        'studio.operation/authoring.plan-save',
        'studio.operation/authoring.resolve-target',
        'studio.operation/authoring.save-as-new-type',
        'studio.operation/authoring.save-item',
        'studio.operation/authoring.save-new-type-version',
        'studio.operation/authoring.start',
        'studio.port/authoring',
    ];

    /**
     * Closed canonical permission vocabulary that permission explanation will recognize.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array PERMISSIONS = [
        'studio.permission/compose',
        'studio.permission/edit-blueprint',
        'studio.permission/edit-content',
        'studio.permission/publish',
        'studio.permission/read',
        'studio.permission/save',
        'studio.permission/upload-media',
    ];

    /**
     * Compose audited authorization, opaque binding persistence and context-key allocation.
     *
     * @param  AuthorizationGateway             $authorization  Canonical audited policy gateway.
     * @param  StudioHostSessionRepository      $sessions       Opaque resource-context bindings.
     * @param  StudioResourceContextKeyFactory  $keys           CSPRNG-backed production allocator.
     * @param  ?StudioPublishedTheme            $theme          Exact public-theme authority; null is
     *         retained only for isolated pre-AP7 tests.
     *
     * @since  2.0.0
     */
    public function __construct(
        private AuthorizationGateway $authorization,
        private StudioHostSessionRepository $sessions,
        private StudioResourceContextKeyFactory $keys,
        private ?StudioPublishedTheme $theme = null,
    ) {
    }

    /**
     * Resolve the sorted host capabilities one resource family is served.
     *
     * @param   StudioResourceKind  $kind  Stored resource family.
     *
     * @return  list<string>  Sorted qualified port and operation capabilities.
     *
     * @since   2.0.0
     */
    public static function capabilities(StudioResourceKind $kind): array
    {
        $capabilities = $kind === StudioResourceKind::ContentAuthoring
            ? [...self::HOST_CAPABILITIES, ...self::AUTHORING_CAPABILITIES]
            : self::HOST_CAPABILITIES;
        sort($capabilities, SORT_STRING);

        return array_values(array_unique($capabilities));
    }

    /**
     * Open one mode-specific session from trusted App identity and policy state.
     *
     * @param   ExecutionContext    $context       Fresh administrator execution context.
     * @param   StudioSessionMode   $mode          Exact canonical authoring mode requested.
     * @param   StudioResourceKind  $resourceKind  Content or Blueprint host resource family.
     * @param   string              $resourceId    Host resource identifier to bind opaquely.
     *
     * @return  StudioHostSessionSnapshot  Stored session and its live permission snapshot.
     *
     * @throws  StudioHostAccessRefused  When the surface, resource/mode pair or live policy refuses it.
     *
     * @since   2.0.0
     */
    public function open(
        ExecutionContext $context,
        StudioSessionMode $mode,
        StudioResourceKind $resourceKind,
        string $resourceId,
    ): StudioHostSessionSnapshot {
        if ($context->surface() !== AuthenticatedSurface::Administrator || !self::modeFits($mode, $resourceKind)) {
            throw new StudioHostAccessRefused('studio.host/session-refused', 'forbidden');
        }
        $modeAllowed = $this->modeAllowed($context, $mode);
        if (!$modeAllowed) {
            throw new StudioHostAccessRefused('studio.host/session-refused', 'forbidden');
        }

        $organizationId = $context->organization()?->identifier();
        $workspaceId = $context->workspace()?->identifier();
        $sessionBinding = self::sessionBinding($context);
        $lifecycle = $this->lifecycleAuthority($context, $mode, $resourceKind, $modeAllowed);
        $permissions = $this->permissions($context, $mode, $resourceKind, $modeAllowed, $lifecycle);
        $session = new StudioHostSession(
            $this->keys->create(),
            $context->actorId(),
            $context->site()->identifier(),
            $organizationId,
            $workspaceId,
            $context->surface()->value,
            $sessionBinding,
            $mode,
            $resourceKind,
            $resourceId,
            $this->generation(
                $context,
                $mode,
                $resourceKind,
                $resourceId,
                $sessionBinding,
                $permissions,
                $modeAllowed,
                $lifecycle['canPublish'],
                $lifecycle['canUnpublish'],
            ),
        );
        $this->sessions->add($session);

        return new StudioHostSessionSnapshot(
            $session,
            $permissions,
            $session->sessionGeneration,
            true,
            $lifecycle['canPublish'],
            $lifecycle['canUnpublish'],
        );
    }

    /**
     * Resolve a stored binding against fresh authenticated identity and live authorization state.
     *
     * @param   ExecutionContext  $context             Fresh administrator execution context.
     * @param   string            $resourceContextKey  Opaque key carried by the canonical envelope.
     *
     * @return  StudioHostSessionSnapshot  Stored binding and freshly computed authority state.
     *
     * @throws  StudioHostAccessRefused  When the key is absent or belongs to another trusted scope.
     *
     * @since   2.0.0
     */
    public function resolve(ExecutionContext $context, string $resourceContextKey): StudioHostSessionSnapshot
    {
        $session = $this->sessions->find($resourceContextKey);
        if ($session === null || !self::sameTrustedScope($context, $session)) {
            throw new StudioHostAccessRefused('studio.host/context-refused', 'forbidden');
        }
        $modeAllowed = $this->modeAllowed($context, $session->mode);
        $lifecycle = $this->lifecycleAuthority($context, $session->mode, $session->resourceKind, $modeAllowed);
        $permissions = $this->permissions(
            $context,
            $session->mode,
            $session->resourceKind,
            $modeAllowed,
            $lifecycle,
        );

        return new StudioHostSessionSnapshot(
            $session,
            $permissions,
            $this->generation(
                $context,
                $session->mode,
                $session->resourceKind,
                $session->resourceId,
                $session->sessionBinding,
                $permissions,
                $modeAllowed,
                $lifecycle['canPublish'],
                $lifecycle['canUnpublish'],
            ),
            $modeAllowed,
            $lifecycle['canPublish'],
            $lifecycle['canUnpublish'],
        );
    }

    /**
     * Explain only the canonical Studio permission vocabulary, never internal policy reasons.
     *
     * @param   StudioHostSessionSnapshot  $snapshot    Fresh trusted authority snapshot.
     * @param   string                     $permission  Canonical Studio permission candidate.
     *
     * @return  bool  False for unknown and withheld permissions alike.
     *
     * @since   2.0.0
     */
    public function permits(StudioHostSessionSnapshot $snapshot, string $permission): bool
    {
        return in_array($permission, self::PERMISSIONS, true)
            && in_array($permission, $snapshot->permissions, true);
    }

    /**
     * Ask the canonical audited gateway for one exact Studio mode capability.
     *
     * @param   ExecutionContext   $context  Fresh authenticated App context.
     * @param   StudioSessionMode  $mode     Exact mode whose capability is required.
     *
     * @return  bool  True only for a positively justified gateway decision.
     *
     * @since   2.0.0
     */
    private function modeAllowed(ExecutionContext $context, StudioSessionMode $mode): bool
    {
        return $this->authorization->decide(
            $context,
            Capability::fromString($mode->capability()),
            AuthorizationResource::collection('studio_session'),
        )->allowed;
    }

    /**
     * Resolve the permission snapshot exposed to Studio from live App policy decisions.
     *
     * @param   ExecutionContext                             $context      Fresh authenticated App context.
     * @param   StudioSessionMode                            $mode         Stored exact authoring mode.
     * @param   StudioResourceKind                           $kind         Stored Content or Blueprint resource family.
     * @param   bool                                         $modeAllowed  Whether the exact mode decision succeeded.
     * @param   array{canPublish: bool, canUnpublish: bool}  $lifecycle    Target-specific lifecycle authority.
     *
     * @return  list<string>
     *
     * @since   2.0.0
     */
    private function permissions(
        ExecutionContext $context,
        StudioSessionMode $mode,
        StudioResourceKind $kind,
        bool $modeAllowed,
        array $lifecycle,
    ): array {
        if (!$modeAllowed) {
            return [];
        }
        $permissions = ['studio.permission/read'];
        if ($mode !== StudioSessionMode::ReadOnly) {
            $permissions[] = 'studio.permission/save';
        }
        if (in_array($mode, [StudioSessionMode::Content, StudioSessionMode::Hybrid], true)) {
            $permissions[] = 'studio.permission/edit-content';
        }
        if (in_array($mode, [StudioSessionMode::Blueprint, StudioSessionMode::Hybrid], true)) {
            $permissions[] = 'studio.permission/compose';
            $permissions[] = 'studio.permission/edit-blueprint';
        }
        if ($lifecycle['canPublish'] || $lifecycle['canUnpublish']) {
            $permissions[] = 'studio.permission/publish';
        }
        if (
            in_array($kind, [StudioResourceKind::Content, StudioResourceKind::ContentAuthoring], true)
            && $mode !== StudioSessionMode::ReadOnly
            && $this->authorization->decide(
                $context,
                Capability::fromString('content.update'),
                AuthorizationResource::collection('media'),
            )->allowed
        ) {
            $permissions[] = 'studio.permission/upload-media';
        }
        sort($permissions, SORT_STRING);

        return array_values(array_unique($permissions));
    }

    /**
     * Resolve exact target-specific publication authority without widening either transition into the other.
     *
     * Studio's current public protocol exposes one lifecycle permission for both operations. The App keeps
     * that display projection separate and authorizes each mutation from these private live decisions.
     *
     * @param   ExecutionContext    $context      Fresh authenticated App context.
     * @param   StudioSessionMode   $mode         Stored exact authoring mode.
     * @param   StudioResourceKind  $kind         Stored Content or Blueprint resource family.
     * @param   bool                $modeAllowed  Whether the exact mode decision succeeded.
     *
     * @return  array{canPublish: bool, canUnpublish: bool}  Exact lifecycle transition decisions.
     *
     * @since   2.0.0
     */
    private function lifecycleAuthority(
        ExecutionContext $context,
        StudioSessionMode $mode,
        StudioResourceKind $kind,
        bool $modeAllowed,
    ): array {
        $eligible = $modeAllowed && (
            $kind === StudioResourceKind::Blueprint
            && $mode === StudioSessionMode::Blueprint
            || in_array($kind, [StudioResourceKind::Content, StudioResourceKind::ContentAuthoring], true)
            && in_array(
                $mode,
                [StudioSessionMode::Content, StudioSessionMode::Hybrid, StudioSessionMode::Model],
                true,
            )
        );
        if (!$eligible) {
            return ['canPublish' => false, 'canUnpublish' => false];
        }

        $resource = AuthorizationResource::collection('content');

        return [
            'canPublish' => $this->authorization->decide(
                $context,
                Capability::fromString('content.publish'),
                $resource,
            )->allowed,
            'canUnpublish' => $this->authorization->decide(
                $context,
                Capability::fromString('content.unpublish'),
                $resource,
            )->allowed,
        ];
    }

    /**
     * Derive the revision binding authority, mode, resource and host-capability epochs.
     *
     * @param   ExecutionContext    $context         Fresh authenticated App context.
     * @param   StudioSessionMode   $mode            Stored exact authoring mode.
     * @param   StudioResourceKind  $kind            Stored Content or Blueprint resource family.
     * @param   string              $resourceId      Stored resource identity, hashed before the preimage.
     * @param   string              $sessionBinding  Digest of the authenticated host session.
     * @param   list<string>        $permissions     Sorted current Studio permissions.
     * @param   bool                $modeAllowed     Whether the exact mode decision succeeded.
     * @param   bool                $canPublish      Whether the target can become published.
     * @param   bool                $canUnpublish    Whether the target can return to draft.
     *
     * @return  string  Canonical revision that changes with any bound authority state.
     *
     * @since   2.0.0
     */
    private function generation(
        ExecutionContext $context,
        StudioSessionMode $mode,
        StudioResourceKind $kind,
        string $resourceId,
        string $sessionBinding,
        array $permissions,
        bool $modeAllowed,
        bool $canPublish,
        bool $canUnpublish,
    ): string {
        $capabilities = self::capabilities($kind);
        $themeRevision = $this->theme?->reference($context->site())->revision ?? 'unbound-test-theme';

        return 'session-' . hash('sha256', implode("\n", [
            'kumwe-studio-host-authority-v2',
            $context->approvalFingerprint(),
            $mode->value,
            $kind->value,
            hash('sha256', $resourceId),
            $sessionBinding,
            $modeAllowed ? 'allowed' : 'denied',
            $canPublish ? 'publish:allowed' : 'publish:denied',
            $canUnpublish ? 'unpublish:allowed' : 'unpublish:denied',
            implode(',', $permissions),
            implode(',', $capabilities),
            $themeRevision,
        ]));
    }

    /**
     * Compare a binding only to coordinates re-established by the authenticated App request.
     *
     * @param   ExecutionContext   $context  Fresh authenticated App context.
     * @param   StudioHostSession  $session  Stored opaque-key binding.
     *
     * @return  bool  True only when actor, site, membership and surface all match exactly.
     *
     * @since   2.0.0
     */
    private static function sameTrustedScope(ExecutionContext $context, StudioHostSession $session): bool
    {
        return $context->surface() === AuthenticatedSurface::Administrator
            && hash_equals($session->actorId, $context->actorId())
            && hash_equals($session->siteId, $context->site()->identifier())
            && $session->organizationId === $context->organization()?->identifier()
            && $session->workspaceId === $context->workspace()?->identifier()
            && $session->surface === $context->surface()->value
            && hash_equals($session->sessionBinding, self::sessionBinding($context));
    }

    /**
     * Bind an opaque resource context to the authenticated administrator session that opened it.
     *
     * @param   ExecutionContext  $context  Fresh trusted administrator context.
     *
     * @return  string  Lowercase SHA-256 digest of the non-exported host session identity.
     *
     * @throws  StudioHostAccessRefused  When no authenticated browser session is present.
     *
     * @since   2.0.0
     */
    private static function sessionBinding(ExecutionContext $context): string
    {
        $sessionId = $context->sessionId();
        if ($sessionId === null) {
            throw new StudioHostAccessRefused('studio.host/session-refused', 'forbidden');
        }

        return hash('sha256', $sessionId);
    }

    /**
     * Enforce the closed Content-versus-Blueprint mode matrix before persistence.
     *
     * @param   StudioSessionMode   $mode  Requested authoring mode.
     * @param   StudioResourceKind  $kind  Requested host resource family.
     *
     * @return  bool  Whether this canonical pair is meaningful.
     *
     * @since   2.0.0
     */
    private static function modeFits(StudioSessionMode $mode, StudioResourceKind $kind): bool
    {
        return match ($kind) {
            StudioResourceKind::Blueprint => in_array(
                $mode,
                [StudioSessionMode::Blueprint, StudioSessionMode::ReadOnly],
                true,
            ),
            StudioResourceKind::Content => $mode !== StudioSessionMode::Blueprint,
            StudioResourceKind::ContentAuthoring => in_array(
                $mode,
                [StudioSessionMode::Hybrid, StudioSessionMode::ReadOnly],
                true,
            ),
        };
    }
}
