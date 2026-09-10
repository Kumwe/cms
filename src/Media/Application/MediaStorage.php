<?php

declare(strict_types=1);

namespace Kumwe\App\Media\Application;

use DateTimeImmutable;
use Kumwe\Context\Value\SiteContext;

/**
 * Port for the store that holds one site's media library.
 *
 * The implementation owns where the bytes live and what counts as a valid asset. Every `MediaAsset`
 * it returns must already have been checked against the store's own record of the file, so callers —
 * including the public media route, which streams straight from `MediaAsset::$path` — never
 * revalidate. A file the store cannot vouch for is reported as absent rather than handed back.
 *
 * Every operation is scoped to a `SiteContext`. No implementation may return, overwrite, or delete an
 * asset belonging to another site, which is what allows a shared installation to serve several sites
 * from one storage root.
 *
 * @since  2.0.0
 */
interface MediaStorage
{
    /**
     * List every asset the site's library can vouch for.
     *
     * @param   SiteContext  $site  Site whose library is listed.
     *
     * @return  list<MediaAsset>  Newest first, so a caller can paginate without re-sorting; empty when
     *          the site holds no readable media.
     *
     * @since   2.0.0
     */
    public function all(SiteContext $site): array;

    /**
     * Look up a single asset by the identifier the store issued for it.
     *
     * @param   SiteContext  $site  Site the lookup is scoped to.
     * @param   string       $id    Identifier of the asset, as returned by `store()`.
     *
     * @return  ?MediaAsset  Null when the identifier is unknown to this site or the file behind it no
     *          longer passes the store's integrity checks.
     *
     * @since   2.0.0
     */
    public function find(SiteContext $site, string $id): ?MediaAsset;

    /**
     * Take a copy of an uploaded file into the site's library.
     *
     * The store, not the caller, decides the identifier and the recorded media type: the type is
     * detected from the bytes rather than trusted from the client, and an upload it will not serve is
     * refused instead of being stored under a corrected type. The source file is copied, so removing
     * it stays the caller's job.
     *
     * @param   SiteContext        $site          Site the asset is filed under.
     * @param   string             $source        Path of the file holding the uploaded bytes.
     * @param   string             $originalName  Client-supplied filename, the basis for the display name.
     * @param   int                $maximumBytes  Upper bound on the accepted file size, in bytes.
     * @param   DateTimeImmutable  $createdAt     Creation timestamp to record against the asset.
     *
     * @return  MediaAsset  The stored asset, carrying the identifier and media type the store assigned.
     *
     * @throws  \InvalidArgumentException  When the source is unreadable, empty, oversized, or of a type
     *          the library does not accept.
     * @throws  \RuntimeException  When the source is acceptable but the library cannot be written to.
     *
     * @since   2.0.0
     */
    public function store(
        SiteContext $site,
        string $source,
        string $originalName,
        int $maximumBytes,
        DateTimeImmutable $createdAt,
    ): MediaAsset;

    /**
     * Remove an asset and everything the store keeps about it.
     *
     * Deleting an identifier the site's library does not hold succeeds silently, which makes the
     * operation idempotent and a resubmitted delete harmless.
     *
     * @param   SiteContext  $site  Site the removal is scoped to.
     * @param   string       $id    Identifier of the asset to remove.
     *
     * @return  void
     *
     * @throws  \RuntimeException  When the asset is present but cannot be removed.
     *
     * @since   2.0.0
     */
    public function delete(SiteContext $site, string $id): void;
}
