<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Http\Handler;

use InvalidArgumentException;
use Kumwe\App\Administrator\Http\AdministratorRequest;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\Localization\Application\Translator;
use Kumwe\App\Media\Application\MediaAsset;
use Kumwe\App\Media\Application\MediaService;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves the media library screen together with the upload and delete actions posted from it.
 *
 * Three routes share this handler because they share one rendering: whatever a browse, an upload or a
 * delete does, the operator ends up looking at the same filtered, paged library. Search, kind filter
 * and page live in the query string so a view can be linked and bookmarked, and every mutating branch
 * redirects back to that URL with a flag rather than rendering, so a refresh cannot upload or delete a
 * second time. A rejected upload is the single exception: it re-renders in place with the reason and a
 * 422, because the operator needs to see which file was refused.
 *
 * @since  2.0.0
 */
final readonly class AdministratorMediaHandler implements RequestHandlerInterface
{
    /**
     * Wire the library screen to the media service, the renderer and the upload staging area.
     *
     * @param  MediaService           $media               Browses, stores and removes the site's assets.
     * @param  AdministratorRenderer  $renderer            Renders the `media` template.
     * @param  Translator             $translator          Resolves the refusal wording for the locale in flight.
     * @param  string                 $temporaryDirectory  Private directory uploads are staged in; it is created
     *         mode 0700 when it does not yet exist.
     *
     * @since  2.0.0
     */
    public function __construct(
        private MediaService $media,
        private AdministratorRenderer $renderer,
        private Translator $translator,
        private string $temporaryDirectory,
    ) {
    }

    /**
     * Render the media library, or apply the upload or deletion a `POST` carries.
     *
     * The route's `id` attribute is what separates the two mutating routes, since both are posts to
     * this class: present means delete, absent means upload. An uploaded file is staged under an
     * unguessable name and removed again whether the service accepted it or not, so a refused file
     * leaves nothing behind. A refusal re-renders the library at 422; both successes redirect with
     * `?uploaded=1` or `?deleted=1`, which is the flag the next render turns into a confirmation.
     *
     * @param   ServerRequestInterface  $request  Administrator request; `GET` browses, `POST` uploads or deletes.
     *
     * @return  ResponseInterface  The rendered library, or a 303 back to it after a successful change.
     *
     * @throws  InvalidArgumentException  When the route was mounted without administrator authorization.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not read or change media.
     * @throws  \RuntimeException  When the storage cannot write or remove the asset or its metadata.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) === 'GET') {
            return $this->page($request);
        }
        $id = $request->getAttribute('id');
        if (is_string($id) && $id !== '') {
            $this->media->delete(AdministratorRequest::context($request), $id);

            return new RedirectResponse('/administrator/media?deleted=1', 303);
        }
        $upload = $request->getUploadedFiles()['media'] ?? null;
        if (!$upload instanceof UploadedFileInterface || $upload->getError() !== UPLOAD_ERR_OK) {
            return $this->page(
                $request,
                $this->translator->translate('core.administrator.media.choose_file_first'),
                422,
            );
        }
        if (!is_dir($this->temporaryDirectory)) {
            mkdir($this->temporaryDirectory, 0700, true);
        }
        $temporary = $this->temporaryDirectory . '/media-' . bin2hex(random_bytes(16));
        try {
            $upload->moveTo($temporary);
            $this->media->upload(
                AdministratorRequest::context($request),
                $temporary,
                $upload->getClientFilename() ?? 'upload',
            );
        } catch (InvalidArgumentException $failure) {
            return $this->page($request, $failure->getMessage(), 422);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return new RedirectResponse('/administrator/media?uploaded=1', 303);
    }

    /**
     * Render one page of the library for the query string as submitted.
     *
     * Query parameters are read defensively because these URLs are bookmarked and hand-edited: a
     * non-string or non-numeric value falls back to the neutral default instead of failing the request,
     * so a truncated link still renders a library. The paging figures come back from the service rather
     * than being recomputed here, because it clamps the page size it actually used.
     *
     * @param   ServerRequestInterface  $request  Request whose query string carries search, kind and page.
     * @param   ?string                 $error    Message to show above the upload form, or null when nothing failed.
     * @param   int                     $status   Status to answer with; 422 when re-rendering a refused upload.
     *
     * @return  ResponseInterface  The rendered library, marked `no-store` because it carries a CSRF token.
     *
     * @throws  InvalidArgumentException  When the route was mounted without administrator session middleware.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When `content.read` is refused.
     *
     * @since   2.0.0
     */
    private function page(ServerRequestInterface $request, ?string $error = null, int $status = 200): ResponseInterface
    {
        $query = $request->getQueryParams();
        $search = is_string($query['q'] ?? null) ? $query['q'] : '';
        $kind = is_string($query['kind'] ?? null) ? $query['kind'] : 'all';
        $page = is_string($query['page'] ?? null) && ctype_digit($query['page']) ? (int) $query['page'] : 1;
        $result = $this->media->browse(AdministratorRequest::context($request), $search, $kind, $page);
        $session = AdministratorRequest::session($request);

        return new HtmlResponse($this->renderer->render('media', [
            'csrf' => $session->csrfToken,
            'capabilities' => AdministratorRequest::capabilityMap($request),
            'assets' => array_map(static fn (MediaAsset $asset): array => $asset->toArray(), $result->items),
            'total' => $result->total,
            'page' => $result->page,
            'pages' => $result->pages(),
            'query' => $search,
            'kind' => $kind,
            'error' => $error,
            'uploaded' => ($query['uploaded'] ?? null) === '1',
            'deleted' => ($query['deleted'] ?? null) === '1',
        ]), $status, ['Cache-Control' => 'no-store']);
    }
}
