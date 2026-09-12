<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

use Kumwe\Localization\Application\ActiveLocale;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Site\Application\SiteSettings;
use Kumwe\App\Studio\Application\Composition\StudioPublishedTheme;
use Kumwe\App\Studio\Application\Host\StudioHostAccessRefused;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Domain\Authoring\StudioAuthoringIntent;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\Producer\Deployment\DeploymentException;
use Kumwe\Producer\Deployment\StudioBrowserAssetLocator;
use Kumwe\Producer\Deployment\StudioDeploymentEmitter;
use Kumwe\Producer\Wire\OperationRegistry;
use Kumwe\Producer\Wire\RequestEnvelope;
use LogicException;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * Supplies the canonical, Producer-proven browser deployment for one contextual Content Studio mount.
 *
 * Every mount opens two PHP-authoritative bindings before a single byte reaches the browser: an
 * opaque authoring context bound to the exact create or edit target, and a hybrid host session whose
 * resource is that context. The browser only ever learns the host session's opaque key; the
 * authoring port maps it back to the context and re-authorizes the target on every operation. The
 * session document, the launch, the transport routing and the contribution bundle are then derived
 * from the same catalog, identifiers and generation the authoring service uses to answer
 * `authoring/start`, so Studio's reconciliation of the deployment against the started snapshot holds
 * byte for byte. Producer validates the assembled `studio-deployment` document against the pinned
 * schema and binds it to the exact release before this class hands the inert JSON to the page.
 *
 * @since  2.0.0
 */
final readonly class HostedContentStudioAuthoringConfigurationProvider implements
    StudioContextualAuthoringConfigurationProvider
{
    /**
     * `id` of the mount target element on the Content editor page.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string MOUNT_ID = 'kumwe-studio-content';

    /**
     * `id` of the inert configuration block on the Content editor page.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string CONFIGURATION_ID = 'kumwe-studio-content-configuration';

    /**
     * Site-absolute prefix every Studio host port route is served under.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string PORT_ROUTE_PREFIX = '/administrator/studio/ports/';

    /**
     * Header the same-origin session transport carries the administrator CSRF token in.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string CSRF_HEADER = 'X-CSRF-Token';

    /**
     * Qualified identity the session advertises for this host.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string HOST_ID = 'kumwe.app/administrator';

    /**
     * Host ports and operations a contextual Content session advertises, in wire order.
     *
     * The contextual shell needs the seven authoring operations; resource search and media browsing are
     * read-only services the same session already authorizes. Media upload, external import, preview,
     * recovery and the artifact lifecycle stay unadvertised: Studio requires precompiled grant
     * transfers, staged previews or feature flags for them that a hosted mount does not supply.
     *
     * @var    array<string, list<string>>
     * @since  2.0.0
     */
    public const array PORTS = [
        'studio.port/authoring' => [
            'studio.operation/authoring.list-types',
            'studio.operation/authoring.plan-save',
            'studio.operation/authoring.resolve-target',
            'studio.operation/authoring.save-as-new-type',
            'studio.operation/authoring.save-item',
            'studio.operation/authoring.save-new-type-version',
            'studio.operation/authoring.start',
        ],
        'studio.port/media' => [
            'studio.operation/media.get',
            'studio.operation/media.list',
        ],
        'studio.port/resource' => [
            'studio.operation/resource.search',
        ],
    ];

    /**
     * Session limits every contextual Content session negotiates.
     *
     * @var    array<string, int>
     * @since  2.0.0
     */
    public const array LIMITS = [
        'maxNodes' => 5000,
        'maxDepth' => 32,
        'maxSlotsPerNode' => 20,
        'maxChildrenPerSlot' => 1000,
        'maxPropertyBytes' => 1048576,
        'maxExtensionBytes' => 1048576,
        'maxCommandBatch' => 100,
        'maxHistoryEntries' => 1000,
        'maxRichTextBytes' => 1048576,
        'maxRichTextDepth' => 32,
        'maxPreviewRequestsPerMinute' => 600,
        'maxPreviewBytes' => 10485760,
        'maxMediaUploadBytes' => 1073741824,
        'maxMediaBatch' => 50,
        'maxPluginCount' => 50,
        'maxContributionsPerPlugin' => 500,
        'maxLocaleBytes' => 1048576,
    ];

    /**
     * Feature policy: nothing executable, nothing uploaded, nothing recovered from the browser.
     *
     * @var    array<string, bool>
     * @since  2.0.0
     */
    public const array FEATURES = [
        'executablePlugins' => false,
        'customInspectors' => false,
        'externalMediaImport' => false,
        'clipboardMediaUpload' => false,
        'collaboration' => false,
        'offlineRecovery' => false,
    ];

    /**
     * Compose the PHP authorities one mount is derived from.
     *
     * @param  ContentStudioAuthoringContextAuthority  $contexts  Opaque exact-target authoring contexts.
     * @param  StudioHostSessionAuthority              $sessions  Canonical host session authority.
     * @param  ContentStudioAuthoringCatalog           $catalog   The one catalog the session locks.
     * @param  StudioPublishedTheme                    $theme     Exact active public theme.
     * @param  ActiveLocale                            $locale    Resolved administrator locale.
     * @param  SiteSettings                            $settings  Site settings holding the time zone.
     * @param  StudioDeploymentEmitter                 $emitter   Producer's pinned deployment emitter.
     * @param  StudioBrowserAssetLocator               $assets    Configured pinned browser-asset origin.
     * @param  ?LoggerInterface                        $logger    Sink for a refused or invalid mount.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ContentStudioAuthoringContextAuthority $contexts,
        private StudioHostSessionAuthority $sessions,
        private ContentStudioAuthoringCatalog $catalog,
        private StudioPublishedTheme $theme,
        private ActiveLocale $locale,
        private SiteSettings $settings,
        private StudioDeploymentEmitter $emitter,
        private StudioBrowserAssetLocator $assets,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Open the context and host session for one mount and emit its proven deployment.
     *
     * @param   ExecutionContext              $context    Authenticated actor and site resolved by PHP.
     * @param   ContentStudioAuthoringTarget  $target     Trusted Content coordinates for this mount.
     * @param   string                        $csrfToken  Administrator session's current CSRF value.
     *
     * @return  ?StudioContextualAuthoringConfiguration  Emitted deployment, or null when any boundary refused.
     *
     * @since   2.0.0
     */
    public function forMount(
        ExecutionContext $context,
        ContentStudioAuthoringTarget $target,
        string $csrfToken,
    ): ?StudioContextualAuthoringConfiguration {
        if ($csrfToken === '') {
            return null;
        }
        try {
            $contextKey = $this->contexts->open($context, $target);
            $snapshot = $this->sessions->open(
                $context,
                StudioSessionMode::Hybrid,
                StudioResourceKind::ContentAuthoring,
                $contextKey,
            );
            $session = new ContentStudioAuthoringSession(
                $snapshot->session,
                $target,
                $snapshot->generation,
                $snapshot->permissions,
            );
            $module = $this->assets->locate('browser-module');
            $document = $this->deployment($context, $session, $csrfToken);
            $json = $this->emitter->document(self::MOUNT_ID, $document);
        } catch (
            ContentStudioAuthoringContextRefused
            | StudioHostAccessRefused
            | DeploymentException
            | LogicException $error
        ) {
            $this->logger?->warning('Contextual Studio mount refused; the structured editor stays in place.', [
                'reason' => $error::class,
                'message' => $error->getMessage(),
            ]);

            return null;
        }

        return new StudioHostedDeploymentConfiguration(
            self::MOUNT_ID,
            self::CONFIGURATION_ID,
            $json,
            $module->url(),
            $module->integrity(),
            $module->origin(),
            $target->returnPath,
        );
    }

    /**
     * Assemble the complete `studio-deployment` document for one opened session.
     *
     * @param   ExecutionContext               $context    Authenticated actor and site.
     * @param   ContentStudioAuthoringSession  $session    Opened host session bound to its target.
     * @param   string                         $csrfToken  Administrator CSRF token the transport sends.
     *
     * @return  stdClass  Decoded deployment document in canonical member shape.
     *
     * @since   2.0.0
     */
    private function deployment(
        ExecutionContext $context,
        ContentStudioAuthoringSession $session,
        string $csrfToken,
    ): stdClass {
        $resourceContext = $session->resourceContext();
        $payloads = $this->catalog->contributionPayloads();
        $document = (object) [
            'contractVersion' => ContentStudioAuthoringDocuments::CONTRACT_VERSION,
            'kind' => 'studio-deployment',
            'instanceId' => 'kumwe.app/content-authoring/' . substr(hash('sha256', $session->key()), 0, 32),
            'mount' => '#' . self::MOUNT_ID,
            'release' => $this->emitter->releaseBinding(),
            'launch' => (object) [
                'targetId' => ContentStudioAuthoringTarget::TARGET_ID,
                'intent' => $session->target->intent->value,
                'resourceContext' => $resourceContext,
                'start' => self::start($session->target),
                'initialPresentation' => 'inline',
            ],
            'session' => $this->sessionDocument($context, $session, $resourceContext),
        ];
        if ($payloads !== []) {
            $document->contributions = (object) [
                'generation' => $this->catalog->contributionGeneration(),
                'payloads' => $payloads,
            ];
        }
        $document->transport = (object) [
            'kind' => 'http',
            'routing' => (object) ['kind' => 'operation-map', 'endpoints' => self::endpoints()],
            'authentication' => (object) [
                'kind' => 'same-origin-session',
                'credentials' => 'same-origin',
                'csrf' => (object) ['headerName' => self::CSRF_HEADER, 'token' => $csrfToken],
            ],
            'requestTimeoutMilliseconds' => 10000,
            'maximumResponseBytes' => 8388608,
        ];

        return $document;
    }

    /**
     * The resolved `studio-config` session document for one opened host session.
     *
     * @param   ExecutionContext               $context          Authenticated actor and site.
     * @param   ContentStudioAuthoringSession  $session          Opened host session bound to its target.
     * @param   stdClass                       $resourceContext  The exact context the launch names.
     *
     * @return  stdClass  Decoded session configuration.
     *
     * @since   2.0.0
     */
    private function sessionDocument(
        ExecutionContext $context,
        ContentStudioAuthoringSession $session,
        stdClass $resourceContext,
    ): stdClass {
        $locale = $this->locale->locale();
        $settings = $this->settings->current();
        $timeZone = $settings['timezone'] ?? 'UTC';
        if (!is_string($timeZone) || $timeZone === '') {
            $timeZone = 'UTC';
        }
        $theme = $this->theme->reference($context->site());
        $ports = [];
        foreach (self::PORTS as $id => $operations) {
            $ports[] = (object) ['id' => $id, 'version' => '1.0.0', 'operations' => $operations];
        }

        return (object) [
            'contractVersion' => ContentStudioAuthoringDocuments::CONTRACT_VERSION,
            'protocolVersion' => RequestEnvelope::WIRE_PROTOCOL_VERSION,
            'sessionId' => $session->sessionId(),
            'sessionGeneration' => $session->generation,
            'mode' => 'content',
            'composite' => 'hybrid',
            'sessionState' => 'editable',
            'actor' => (object) [
                'id' => $context->actorId(),
                'displayName' => $context->actorId(),
            ],
            'locale' => (object) [
                'requested' => $locale->toString(),
                'resolved' => $locale->toString(),
                'fallbacks' => array_values(array_unique(array_filter(
                    $locale->fallbacks(),
                    static fn (string $fallback): bool => $fallback !== $locale->toString(),
                ))),
                'direction' => $locale->direction()->value,
                'timeZone' => $timeZone,
            ],
            'displayPreferences' => (object) [
                'calendar' => 'gregory',
                'numberingSystem' => 'latn',
                'hourCycle' => 'h23',
                'measurementSystem' => 'metric',
            ],
            'resourceContext' => $resourceContext,
            'permissions' => $session->permissions,
            'artifacts' => (object) [
                'theme' => (object) ['id' => $theme->id, 'version' => $theme->version, 'revision' => $theme->revision],
            ],
            'blocks' => $this->catalog->blockLocks(),
            'plugins' => [],
            'hostCapabilities' => (object) [
                'contractVersion' => ContentStudioAuthoringDocuments::CONTRACT_VERSION,
                'kind' => 'host-capabilities',
                'host' => (object) [
                    'id' => self::HOST_ID,
                    'version' => ContentStudioAuthoringDocuments::OWNER['version'],
                    'generation' => $session->generation,
                ],
                'protocolVersions' => [RequestEnvelope::WIRE_PROTOCOL_VERSION],
                'ports' => $ports,
                'capabilities' => [],
            ],
            'limits' => (object) self::LIMITS,
            'features' => (object) self::FEATURES,
            'preview' => (object) [
                'enabled' => false,
                'sameOriginRequired' => true,
                'allowApproximateRenderer' => false,
            ],
        ];
    }

    /**
     * The start source one target launches with.
     *
     * @param   ContentStudioAuthoringTarget  $target  PHP-resolved Content target.
     *
     * @return  stdClass  Schema-valid `startSource`.
     *
     * @since   2.0.0
     */
    private static function start(ContentStudioAuthoringTarget $target): stdClass
    {
        if ($target->intent === StudioAuthoringIntent::Edit) {
            return (object) ['kind' => 'existing'];
        }
        if ($target->modelId !== null && $target->modelVersion !== null && $target->modelRevision !== null) {
            return (object) [
                'kind' => 'from-type',
                'type' => (object) [
                    'id' => ContentStudioAuthoringDocuments::TYPE_ID_PREFIX
                        . substr($target->modelId, strlen('content-model:')),
                    'version' => $target->modelVersion,
                    'revision' => $target->modelRevision,
                ],
            ];
        }

        return (object) ['kind' => 'blank'];
    }

    /**
     * The exact operation-map routing for every advertised operation.
     *
     * @return  stdClass  Route to site-absolute endpoint.
     *
     * @since   2.0.0
     */
    private static function endpoints(): stdClass
    {
        $endpoints = [];
        foreach (self::PORTS as $operations) {
            foreach ($operations as $capability) {
                $route = OperationRegistry::byCapability($capability)->route;
                $endpoints[$route] = self::PORT_ROUTE_PREFIX . $route;
            }
        }
        ksort($endpoints, SORT_STRING);

        return (object) $endpoints;
    }
}
