<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Http\Handler;

use JsonException;
use Kumwe\App\Administrator\Http\AdministratorRequest;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\Localization\Application\ActiveLocale;
use Kumwe\Localization\Domain\InvalidLocaleTag;
use Kumwe\Localization\Domain\LocaleTag;
use Kumwe\App\Site\Application\SiteSettings;
use Kumwe\App\Studio\Application\Composition\StudioContentComposition;
use Kumwe\App\Studio\Application\Composition\StudioContentCompositionService;
use Kumwe\App\Studio\Application\Composition\StudioCompositionContributionCatalog;
use Kumwe\App\Studio\Application\Composition\StudioCompositionThemeMismatch;
use Kumwe\App\Studio\Application\Release\StudioReleaseRecord;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Server-rendered entry point for one versioned Content-model Blueprint composition surface.
 *
 * GET remains read-only. The first draft is created only by a CSRF-protected POST and then exposed
 * through a 303 redirect, preserving an essential no-script provisioning journey.
 *
 * @since  2.0.0
 */
final readonly class AdministratorStudioCompositionHandler implements RequestHandlerInterface
{
    /**
     * Exact renderer capabilities implemented by the App preview runtime.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array RENDERERS = ['core.renderer/field', 'core.renderer/layout'];

    /**
     * Bind the composition application services and server-rendered presentation dependencies.
     *
     * @param  StudioContentCompositionService       $compositions   Blueprint composition application service.
     * @param  AdministratorRenderer                 $renderer       Administrator template renderer.
     * @param  ActiveLocale                          $locale         Request-resolved locale projection.
     * @param  SiteSettings                          $settings       Validated site settings source.
     * @param  StudioCompositionContributionCatalog  $contributions  Active trusted contribution catalogue.
     * @param  StudioReleaseRecord                   $studioRelease  Canonical coordinated Studio release.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioContentCompositionService $compositions,
        private AdministratorRenderer $renderer,
        private ActiveLocale $locale,
        private SiteSettings $settings,
        private StudioCompositionContributionCatalog $contributions,
        private StudioReleaseRecord $studioRelease,
    ) {
    }

    /**
     * Render the read-only composition surface or provision its initial draft through POST.
     *
     * @param   ServerRequestInterface  $request  Authorized administrator request.
     *
     * @return  ResponseInterface  No-store surface, conflict diagnostic, or POST redirect.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $contentTypeId = $request->getAttribute('id');
        $versionValue = $request->getAttribute('version');
        if (
            !is_string($contentTypeId)
            || !is_string($versionValue)
            || preg_match('/^[1-9][0-9]*$/D', $versionValue) !== 1
        ) {
            throw new \InvalidArgumentException('The Content model composition coordinate is invalid.');
        }
        $version = (int) $versionValue;
        $context = AdministratorRequest::context($request);
        $path = sprintf(
            '/administrator/content-models/%s/versions/%d/composition',
            rawurlencode($contentTypeId),
            $version,
        );
        $themeMismatch = false;
        try {
            if (strtoupper($request->getMethod()) === 'POST') {
                $this->compositions->provision(
                    $context,
                    $contentTypeId,
                    $version,
                    self::RENDERERS,
                );

                return new RedirectResponse($path, 303);
            }
            $composition = $this->compositions->find($context, $contentTypeId, $version);
        } catch (StudioCompositionThemeMismatch) {
            $composition = null;
            $themeMismatch = true;
        }
        $session = AdministratorRequest::session($request);

        return new HtmlResponse($this->renderer->render('studio-composition', [
            'active_navigation' => 'core.models',
            'artifact_status' => $composition?->blueprint->document()->status ?? null,
            'csrf' => $session->csrfToken,
            'composition' => $composition,
            'composition_path' => $path,
            'theme_mismatch' => $themeMismatch,
            'studio_boot_json' => $composition === null ? null : $this->bootJson(
                $composition,
                $context->actorId(),
                $session->csrfToken,
                AdministratorRequest::capabilityMap($request),
                $this->requestedLocale($request),
            ),
        ]), $themeMismatch ? 409 : 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Encode the closed, trusted browser bootstrap projection.
     *
     * @param   StudioContentComposition  $composition      Exact model, binding, and Blueprint projection.
     * @param   string                    $actorId          Authorized actor identifier.
     * @param   string                    $csrf             Session CSRF token.
     * @param   array<string, true>       $capabilities     Authorized actor capability map.
     * @param   string                    $requestedLocale  Explicit requested locale coordinate.
     *
     * @return  string  HTML-safe JSON bootstrap bytes.
     *
     * @throws  JsonException  When the closed boot projection cannot be encoded.
     *
     * @since   2.0.0
     */
    private function bootJson(
        StudioContentComposition $composition,
        string $actorId,
        string $csrf,
        array $capabilities,
        string $requestedLocale,
    ): string {
        $locale = $this->locale->locale();
        $settings = $this->settings->current();
        $timezone = $settings['timezone'] ?? 'UTC';
        if (!is_string($timezone) || $timezone === '') {
            $timezone = 'UTC';
        }

        $dependencyLock = $composition->blueprint->document()->dependencyLock ?? null;
        $lock = self::lockedBlocks(
            $dependencyLock instanceof \stdClass ? $dependencyLock->blocks ?? null : null,
        );
        $contributions = $this->contributions->project(
            $capabilities,
            self::RENDERERS,
            $lock,
        );

        return json_encode([
            'actor' => ['id' => $actorId, 'displayName' => $actorId],
            'artifact' => [
                'id' => $composition->binding->blueprintId,
                'version' => $composition->binding->blueprintVersion,
                'revision' => $composition->blueprint->revision,
            ],
            'blockRenderers' => (object) $contributions->blockRenderers,
            'csrf' => $csrf,
            'contributionOwners' => $contributions->owners,
            'contributions' => $contributions->documents,
            'document' => $composition->blueprint->document(),
            'endpoints' => [
                'ports' => '/administrator/studio/ports',
                'session' => '/administrator/studio/session',
            ],
            'locale' => [
                'direction' => $locale->direction()->value,
                'fallbacks' => array_values(array_unique(array_merge(
                    $locale->fallbacks(),
                    ['en-GB'],
                ))),
                'requested' => $requestedLocale,
                'resolved' => $locale->toString(),
                'timezone' => $timezone,
            ],
            'model' => $composition->model,
            'release' => $this->studioRelease->release,
            'site' => $composition->binding->site->identifier(),
            'status' => $composition->blueprint->document()->status,
        ], JSON_THROW_ON_ERROR | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Narrow an admitted Blueprint dependency lock to the exact block-object list the catalogue accepts.
     *
     * An invalid persisted shape yields an empty lock so the authoring palette remains fail-closed instead
     * of treating arbitrary values as active block coordinates.
     *
     * @param   mixed  $candidate  Candidate dependency-lock blocks member.
     *
     * @return  list<\stdClass>  Exact lock objects, or an empty list for an invalid shape.
     *
     * @since   2.0.0
     */
    private static function lockedBlocks(mixed $candidate): array
    {
        if (!is_array($candidate) || !array_is_list($candidate)) {
            return [];
        }
        $blocks = [];
        foreach ($candidate as $block) {
            if (!$block instanceof \stdClass) {
                return [];
            }
            $blocks[] = $block;
        }

        return $blocks;
    }

    /**
     * Preserve one explicit, well-formed locale request separately from the carried locale it resolved to.
     *
     * @param   ServerRequestInterface  $request  Current administrator request.
     *
     * @return  string  Canonical requested or resolved locale tag.
     *
     * @since   2.0.0
     */
    private function requestedLocale(ServerRequestInterface $request): string
    {
        $candidate = $request->getQueryParams()['locale'] ?? null;
        if (is_string($candidate) && $candidate !== '') {
            try {
                return LocaleTag::fromString($candidate)->toString();
            } catch (InvalidLocaleTag) {
                // Locale negotiation already discarded an unusable explicit choice.
            }
        }

        return $this->locale->locale()->toString();
    }
}
