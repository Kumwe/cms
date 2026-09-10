<?php

declare(strict_types=1);

namespace Kumwe\App\Http\Handler;

use Kumwe\App\Media\Application\MediaAsset;
use Kumwe\App\Media\Application\MediaStorage;
use Kumwe\Context\Value\SiteContext;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Streams a file from the site's media library on the public `/media/{id}/{name}` route.
 *
 * The identifier alone would be enough to find the asset; the filename is in the URL so that browsers,
 * download dialogs, and caches get a stable human-readable name. This handler is what keeps the pair
 * honest — a request whose name segment does not match the stored one is refused rather than served
 * under a name the library never assigned. That comparison uses `hash_equals`, so the route cannot be
 * turned into an oracle for guessing stored filenames a character at a time.
 *
 * Every lookup is scoped to the configured public site, which is what stops a shared installation from
 * being walked out of one site's URL space into another site's library. Bytes are streamed straight from
 * the path `MediaStorage` has already vouched for, and that guarantee is what makes the immutable,
 * year-long cache policy on the response safe to publish.
 *
 * @since  2.0.0
 */
final readonly class MediaAssetHandler implements RequestHandlerInterface
{
    /**
     * Bind the public media route to one site's library and the stream factory that serves it.
     *
     * @param  MediaStorage            $media    Library the asset is looked up in and streamed from.
     * @param  StreamFactoryInterface  $streams  Factory used to open the stored file as a response body.
     * @param  SiteContext             $site     Site every public media lookup is confined to.
     *
     * @since  2.0.0
     */
    public function __construct(
        private MediaStorage $media,
        private StreamFactoryInterface $streams,
        private SiteContext $site,
    ) {
    }

    /**
     * Streams the requested asset when its identifier and filename both match one stored file.
     *
     * A mismatched name, an unknown identifier, and a file the library will no longer vouch for are all
     * answered identically, so the route reveals nothing about which assets exist.
     *
     * @param   ServerRequestInterface  $request  Request carrying the `id` and `name` route attributes;
     *          the name is percent-decoded before it is compared.
     *
     * @return  ResponseInterface  A 200 inline stream with the stored media type, length, and an
     *          immutable cache policy, or an uncacheable empty 404 when the pair identifies nothing this
     *          site can serve.
     *
     * @throws  \RuntimeException  When the vouched-for file cannot be opened for reading.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $id = $request->getAttribute('id');
        $name = $request->getAttribute('name');
        if (!is_string($id) || !is_string($name)) {
            return new EmptyResponse(404, ['Cache-Control' => 'no-store']);
        }
        $asset = $this->media->find($this->site, $id);
        if (!$asset instanceof MediaAsset || !hash_equals($asset->name, rawurldecode($name))) {
            return new EmptyResponse(404, ['Cache-Control' => 'no-store']);
        }

        return new Response($this->streams->createStreamFromFile($asset->path, 'rb'), 200, [
            'Content-Type' => $asset->mimeType,
            'Content-Length' => (string) $asset->size,
            'Content-Disposition' => 'inline; filename*=UTF-8\'\'' . rawurlencode($asset->name),
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
