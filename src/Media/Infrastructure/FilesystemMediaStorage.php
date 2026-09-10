<?php

declare(strict_types=1);

namespace Kumwe\App\Media\Infrastructure;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Kumwe\App\Media\Application\BoundedMediaChoiceStorage;
use Kumwe\App\Media\Application\MediaAsset;
use Kumwe\App\Media\Application\MediaStorage;
use Kumwe\Context\Value\SiteContext;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Media library kept as plain files in two directories on the local filesystem.
 *
 * Each asset is a payload file beside a JSON sidecar of the same identifier: the sidecar records the
 * display name, media type, extension, byte size, creation time and SHA-256 of the payload. Every
 * read re-derives that pairing — extension against recorded type, resolved path against the
 * directory it was found in, size and checksum against the file itself — and reports any asset that
 * fails as absent. A tampered sidecar, a swapped payload, or a symlink pointing outside the library
 * therefore disappears from listings instead of being served, which is what lets callers stream
 * `MediaAsset::$path` directly.
 *
 * Reads consult two roots. The writable root holds uploads and is the only place `store()` and
 * `delete()` ever touch. The optional bundled root supplies assets shipped with the distribution:
 * they are listed with `MediaAsset::$deletable` false, must carry a checksum to be trusted at all,
 * and are shadowed by a writable asset of the same identifier.
 *
 * @since  2.0.0
 */
final readonly class FilesystemMediaStorage implements MediaStorage, BoundedMediaChoiceStorage
{
    /**
     * Media types this store will serve, mapped to the extension a stored payload must use.
     *
     * A sidecar whose recorded type and extension disagree with this table is treated as corrupt, so
     * the mapping doubles as the guard against a metadata file that renames a payload's type.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/svg+xml' => 'svg',
        'application/pdf' => 'pdf',
    ];

    /**
     * Media types accepted from an upload, deliberately narrower than the set the store will serve.
     *
     * SVG is readable because bundled distribution assets may ship it, but it is never accepted from a
     * client: an uploaded SVG is a script-bearing document served from the site's own origin.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const UPLOAD_MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'application/pdf' => 'pdf',
    ];

    /**
     * Bind the store to the directory roots it reads from and writes to.
     *
     * @param  string   $root         Writable base directory; per-site libraries hang below it by identifier.
     * @param  ?string  $bundledRoot  Read-only base directory for distribution assets, or null for none.
     *
     * @since  2.0.0
     */
    public function __construct(private string $root, private ?string $bundledRoot = null)
    {
    }

    /**
     * List every asset in the site's directories that still passes its integrity checks.
     *
     * Both roots are scanned in precedence order and the writable root wins an identifier collision,
     * so a site can shadow a bundled asset with its own upload. Sidecars that fail validation are
     * skipped silently: a damaged file must not take the whole media screen down with it.
     *
     * @param   SiteContext  $site  Site whose directories are scanned.
     *
     * @return  list<MediaAsset>  Newest first, with the identifier breaking ties; empty when neither
     *          root holds readable media for the site.
     *
     * @since   2.0.0
     */
    public function all(SiteContext $site): array
    {
        $assets = [];
        foreach ($this->directories($site) as [$directory, $deletable]) {
            if (!is_dir($directory)) {
                continue;
            }
            $files = glob($directory . '/*.json');
            if (!is_array($files)) {
                continue;
            }
            foreach ($files as $metadata) {
                $id = pathinfo($metadata, PATHINFO_FILENAME);
                if (isset($assets[$id])) {
                    continue;
                }
                $asset = $this->findInDirectory($directory, $id, $deletable);
                if ($asset instanceof MediaAsset) {
                    $assets[$id] = $asset;
                }
            }
        }
        $assets = array_values($assets);
        usort($assets, static fn (MediaAsset $left, MediaAsset $right): int => [
            $right->createdAt->getTimestamp(),
            $right->id,
        ] <=> [
            $left->createdAt->getTimestamp(),
            $left->id,
        ]);

        return $assets;
    }

    /**
     * Search a bounded number of sidecars for generated media-reference choices.
     *
     * Directory iteration is used instead of `glob()` so a library with millions of entries cannot make
     * the selector first allocate a list of every filename. Writable entries retain collision precedence;
     * every candidate still passes the same payload, metadata and checksum verification as `find()`.
     *
     * @param   SiteContext  $site       Site whose library is searched.
     * @param   string       $query      Case-insensitive display-name substring, at most 200 bytes.
     * @param   int          $limit      Most matches to return, from one to fifty.
     * @param   int          $scanLimit  Most directory entries to inspect, from `$limit` to 4096.
     *
     * @return  list<MediaAsset>  Validated matches ordered by name and identifier.
     *
     * @throws  InvalidArgumentException  When query or bounds are invalid.
     *
     * @since   2.0.0
     */
    public function choices(SiteContext $site, string $query, int $limit, int $scanLimit): array
    {
        $query = trim($query);
        if (strlen($query) > 200 || $limit < 1 || $limit > 50 || $scanLimit < $limit || $scanLimit > 4096) {
            throw new InvalidArgumentException('A media-choice query or bound is invalid.');
        }
        $needle = mb_strtolower($query);
        $assets = [];
        $seen = [];
        $scanned = 0;
        foreach ($this->directories($site) as [$directory, $deletable]) {
            if (!is_dir($directory)) {
                continue;
            }
            foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $file) {
                if (++$scanned > $scanLimit) {
                    break 2;
                }
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'json') {
                    continue;
                }
                $id = $file->getBasename('.json');
                if (isset($seen[$id])) {
                    continue;
                }
                $asset = $this->findInDirectory($directory, $id, $deletable);
                if (!$asset instanceof MediaAsset) {
                    continue;
                }
                $seen[$id] = true;
                if ($needle !== '' && !str_contains(mb_strtolower($asset->name), $needle)) {
                    continue;
                }
                $assets[] = $asset;
                if (count($assets) === $limit) {
                    break 2;
                }
            }
        }
        usort($assets, static fn (MediaAsset $left, MediaAsset $right): int => [
            mb_strtolower($left->name),
            $left->id,
        ] <=> [
            mb_strtolower($right->name),
            $right->id,
        ]);

        return $assets;
    }

    /**
     * Look up one asset, checking the writable root before the bundled root.
     *
     * The identifier is required to be a UUID before any path is built from it, so a traversal attempt
     * arriving on the public media route is rejected without a filesystem call.
     *
     * @param   SiteContext  $site  Site whose directories are searched.
     * @param   string       $id    Asset identifier; matched case-insensitively against the filenames.
     *
     * @return  ?MediaAsset  Null when the identifier is not a UUID, no such asset exists in either
     *          root, or the files behind it fail validation.
     *
     * @since   2.0.0
     */
    public function find(SiteContext $site, string $id): ?MediaAsset
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        foreach ($this->directories($site) as [$directory, $deletable]) {
            $asset = $this->findInDirectory($directory, $id, $deletable);
            if ($asset instanceof MediaAsset) {
                return $asset;
            }
        }

        return null;
    }

    /**
     * Read one asset out of a single directory and validate it end to end.
     *
     * This is the only place an on-disk pair becomes a `MediaAsset`, so it carries every check the
     * rest of the class relies on: the sidecar must be a regular file rather than a symlink, parse as
     * an object and carry each field with the type expected of it, the recorded type must match its
     * extension, the payload must resolve inside the directory and not be a symlink, the size must
     * equal what was recorded, and the recorded timestamp must parse. A recorded SHA-256 is always
     * verified, and bundled read-only assets must carry one — an unchecksummed file in the
     * distribution root is refused rather than trusted.
     *
     * @param   string  $directory  Directory to read from, and the boundary the payload must resolve inside.
     * @param   string  $id         Asset identifier; the filenames it maps to are lowercase.
     * @param   bool    $deletable  Whether assets from this directory may be deleted; false for bundled.
     *
     * @return  ?MediaAsset  Null whenever any check fails, which every caller reads as "not present".
     *
     * @since   2.0.0
     */
    private function findInDirectory(string $directory, string $id, bool $deletable): ?MediaAsset
    {
        $metadataPath = $directory . '/' . strtolower($id) . '.json';
        if (!is_file($metadataPath) || is_link($metadataPath)) {
            return null;
        }
        try {
            $metadata = json_decode((string) file_get_contents($metadataPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($metadata)) {
            return null;
        }
        $name = $metadata['name'] ?? null;
        $mime = $metadata['mime_type'] ?? null;
        $size = $metadata['size'] ?? null;
        $created = $metadata['created_at'] ?? null;
        $extension = $metadata['extension'] ?? null;
        $sha256 = $metadata['sha256'] ?? null;
        if (
            !is_string($name) || $name === '' || strlen($name) > 180
            || preg_match('/[\x00-\x1F\x7F]/', $name) === 1
            || !is_string($mime) || !is_int($size)
            || !is_string($created) || !is_string($extension)
            || (self::MIME_EXTENSIONS[$mime] ?? null) !== $extension
            || ($sha256 !== null && (!is_string($sha256) || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1))
            || (!$deletable && !is_string($sha256))
        ) {
            return null;
        }
        $path = $directory . '/' . strtolower($id) . '.' . $extension;
        $root = realpath($directory);
        $resolved = realpath($path);
        if (
            !is_string($root) || !is_string($resolved) || !str_starts_with($resolved, $root . '/')
            || !is_file($resolved) || is_link($path)
        ) {
            return null;
        }
        $actualSize = filesize($resolved);
        if (!is_int($actualSize) || $actualSize !== $size) {
            return null;
        }
        $actualHash = is_string($sha256) ? hash_file('sha256', $resolved) : null;
        if (is_string($sha256) && (!is_string($actualHash) || !hash_equals($sha256, $actualHash))) {
            return null;
        }

        try {
            $createdAt = new DateTimeImmutable($created);
        } catch (\Exception) {
            return null;
        }

        return new MediaAsset(strtolower($id), $name, $mime, $size, $createdAt, $resolved, $deletable);
    }

    /**
     * Copy an uploaded file into the site's writable directory and write its metadata sidecar.
     *
     * The media type is detected from the bytes with `finfo` and checked against the upload whitelist,
     * so a renamed file or a lying `Content-Type` is rejected rather than stored. The payload is
     * copied to a hidden temporary name, given restrictive permissions and only then renamed into
     * place, so a concurrent reader never observes a partial file. If the sidecar cannot be written
     * afterwards both files are removed before the failure propagates, leaving no orphaned payload.
     *
     * @param   SiteContext        $site          Site the asset is filed under.
     * @param   string             $source        Path of the uploaded file; it is copied, not moved.
     * @param   string             $originalName  Client-supplied filename, reduced to a safe display name.
     * @param   int                $maximumBytes  Largest accepted size in bytes; larger files are refused.
     * @param   DateTimeImmutable  $createdAt     Creation timestamp recorded in the sidecar.
     *
     * @return  MediaAsset  The stored asset, carrying its new UUIDv7 identifier and detected type.
     *
     * @throws  InvalidArgumentException  When the source is missing, a symlink, empty, over the limit, or
     *          not one of JPEG, PNG, GIF, WebP, AVIF or PDF.
     * @throws  RuntimeException  When the directory, the payload, the checksum or the sidecar cannot be written.
     *
     * @since   2.0.0
     */
    public function store(
        SiteContext $site,
        string $source,
        string $originalName,
        int $maximumBytes,
        DateTimeImmutable $createdAt,
    ): MediaAsset {
        if (!is_file($source) || is_link($source)) {
            throw new InvalidArgumentException('The uploaded media file is unavailable.');
        }
        $size = filesize($source);
        if (!is_int($size) || $size < 1 || $size > $maximumBytes) {
            throw new InvalidArgumentException('The media file is empty or exceeds the configured upload limit.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($source);
        if (!is_string($mime) || !isset(self::UPLOAD_MIME_EXTENSIONS[$mime])) {
            throw new InvalidArgumentException('Only JPEG, PNG, GIF, WebP, AVIF and PDF files are supported.');
        }
        $extension = self::UPLOAD_MIME_EXTENSIONS[$mime];
        $name = $this->displayName($originalName, $extension);
        $id = Uuid::uuid7()->toString();
        $directory = $this->siteDirectory($site);
        // The diagnostics are suppressed because each refusal below is immediately reported as a typed
        // exception naming the step that failed. Leaving them on adds a second, unstructured account of
        // the same event to the PHP error log, which on an unwritable volume is one line per upload.
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('The media directory could not be created.');
        }
        $temporary = $directory . '/.upload-' . bin2hex(random_bytes(16));
        $path = $directory . '/' . $id . '.' . $extension;
        if (!@copy($source, $temporary) || !@chmod($temporary, 0640) || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('The media file could not be stored.');
        }
        $metadataPath = $directory . '/' . $id . '.json';
        try {
            $sha256 = hash_file('sha256', $path);
            if (!is_string($sha256)) {
                throw new RuntimeException('The stored media checksum could not be calculated.');
            }
            $metadata = json_encode([
                'name' => $name,
                'mime_type' => $mime,
                'extension' => $extension,
                'size' => $size,
                'sha256' => $sha256,
                'created_at' => $createdAt->format(DATE_ATOM),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (file_put_contents($metadataPath, $metadata, LOCK_EX) === false || !chmod($metadataPath, 0640)) {
                throw new RuntimeException('The media metadata could not be stored.');
            }
        } catch (\Throwable $failure) {
            @unlink($path);
            @unlink($metadataPath);
            throw $failure;
        }

        return new MediaAsset($id, $name, $mime, $size, $createdAt, $path);
    }

    /**
     * Remove an asset's payload and sidecar from the site's writable directory.
     *
     * Only the writable root is searched, so a bundled distribution asset is left alone even when its
     * identifier is passed in, as is an identifier the library does not hold. Removal is not atomic:
     * a failure between the two unlinks leaves a sidecar whose payload is gone, which the validation
     * in `findInDirectory()` then reports as absent.
     *
     * @param   SiteContext  $site  Site whose writable directory is searched.
     * @param   string       $id    Identifier of the asset to remove.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the payload or its sidecar exists but cannot be unlinked.
     *
     * @since   2.0.0
     */
    public function delete(SiteContext $site, string $id): void
    {
        $asset = $this->findInDirectory($this->siteDirectory($site), $id, true);
        if (!$asset instanceof MediaAsset) {
            return;
        }
        if (!unlink($asset->path)) {
            throw new RuntimeException('The media file could not be deleted.');
        }
        $metadata = $this->siteDirectory($site) . '/' . strtolower($id) . '.json';
        if (is_file($metadata) && !unlink($metadata)) {
            throw new RuntimeException('The media metadata could not be deleted.');
        }
    }

    /**
     * Build the writable directory path that holds one site's uploads.
     *
     * @param   SiteContext  $site  Site whose directory is wanted.
     *
     * @return  string  Path below the writable root; it may not exist until the first upload creates it.
     *
     * @since   2.0.0
     */
    private function siteDirectory(SiteContext $site): string
    {
        return rtrim($this->root, '/') . '/' . $site->identifier();
    }

    /**
     * List the directories a read consults, in the order that decides precedence.
     *
     * The writable root always comes first so that a site's own upload shadows a bundled asset sharing
     * its identifier, and the bundled entry is omitted entirely when no bundled root was configured.
     *
     * @param   SiteContext  $site  Site whose directories are wanted.
     *
     * @return  list<array{string, bool}>  Directory path paired with whether assets found there may be
     *          deleted; false marks the read-only bundled root.
     *
     * @since   2.0.0
     */
    private function directories(SiteContext $site): array
    {
        $directories = [[$this->siteDirectory($site), true]];
        if ($this->bundledRoot !== null) {
            $directories[] = [rtrim($this->bundledRoot, '/') . '/' . $site->identifier(), false];
        }

        return $directories;
    }

    /**
     * Reduce a client-supplied filename to a name that is safe to store and to echo back.
     *
     * Both directory separators are collapsed to a basename and control characters are stripped, so
     * the result cannot escape its directory or inject a header when it is returned in a
     * `Content-Disposition`. A name that reduces to nothing, `.` or `..` — including one that was not
     * valid UTF-8 — falls back to `upload.<extension>`, and the survivor is capped at 180 characters.
     *
     * @param   string  $originalName  Filename as the client sent it, path separators and all.
     * @param   string  $extension     Extension derived from the detected type, used by the fallback name.
     *
     * @return  string  A basename with no directory component, never empty.
     *
     * @since   2.0.0
     */
    private function displayName(string $originalName, string $extension): string
    {
        $name = basename(str_replace('\\', '/', trim($originalName)));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        if ($name === '' || $name === '.' || $name === '..') {
            return 'upload.' . $extension;
        }

        return mb_substr($name, 0, 180);
    }
}
