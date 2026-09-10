<?php

declare(strict_types=1);

namespace Kumwe\App\Media\Application;

use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * Use case boundary for the media library: browse, upload and delete are all authorized here, and the
 * two mutations are audited.
 *
 * Handlers call this service rather than `MediaStorage` directly, so that each operation is checked
 * against the caller's capabilities on the `media` collection and scoped to the site carried on the
 * execution context. Uploads and deletions are written to the audit trail; a browse is a read and
 * leaves no entry behind. The public media route is the one deliberate exception to going through
 * this service: serving an already-published file is a read of a resolved URL, not a library
 * operation.
 *
 * An upload whose audit record cannot be written is deleted again before the failure propagates, so
 * the library never keeps a file that the trail does not account for.
 *
 * @since  2.0.0
 */
final readonly class MediaService
{
    /**
     * Wire the service to its storage, policy, audit and clock collaborators.
     *
     * @param  MediaStorage             $storage        Library the assets are read from and written to.
     * @param  AuthorizationGateway     $authorization  Decides whether the caller holds the needed capability.
     * @param  AuditRecorder            $audit          Receives the `media.upload` and `media.delete` events.
     * @param  ClockInterface           $clock          Supplies the timestamp shared by the asset and its event.
     * @param  int                      $maximumBytes   Largest upload accepted, in bytes; the storage enforces it.
     * @param TransactionManager|null $transactions Compensates stored bytes when composition supplies a transaction.
     *
     * @since  2.0.0
     */
    public function __construct(
        private MediaStorage $storage,
        private AuthorizationGateway $authorization,
        private AuditRecorder $audit,
        private ClockInterface $clock,
        private int $maximumBytes,
        private ?TransactionManager $transactions = null,
    ) {
    }

    /**
     * List the site's media, narrowed by a name search and a kind filter, as one page.
     *
     * Filtering and slicing happen in memory over the whole library, so the page size is clamped to 96
     * to bound the work a single request can ask for. The `$total` on the returned page counts the
     * filtered set, not the library.
     *
     * @param   ExecutionContext  $context  Identity and site the listing is scoped to.
     * @param   string            $query    Case-insensitive substring matched against the display name.
     * @param   string            $kind     `image`, `document` for PDFs, or anything else for no filter.
     * @param   int               $page     One-based page to return; anything below 1 is clamped.
     * @param   int               $perPage  Requested page size, clamped to between 1 and 96.
     *
     * @return  MediaPage  The requested slice plus the counters the pager renders from.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When `content.read` is refused.
     *
     * @since   2.0.0
     */
    public function browse(
        ExecutionContext $context,
        string $query = '',
        string $kind = 'all',
        int $page = 1,
        int $perPage = 24,
    ): MediaPage {
        $this->authorize($context, 'content.read');
        $query = mb_strtolower(trim($query));
        $assets = array_values(array_filter(
            $this->storage->all($context->site()),
            static function (MediaAsset $asset) use ($query, $kind): bool {
                if ($query !== '' && !str_contains(mb_strtolower($asset->name), $query)) {
                    return false;
                }

                return match ($kind) {
                    'image' => str_starts_with($asset->mimeType, 'image/'),
                    'document' => $asset->mimeType === 'application/pdf',
                    default => true,
                };
            },
        ));
        $page = max(1, $page);
        $perPage = min(96, max(1, $perPage));

        return new MediaPage(
            array_slice($assets, ($page - 1) * $perPage, $perPage),
            count($assets),
            $page,
            $perPage,
        );
    }

    /**
     * Return a bounded media-reference choice page without loading the complete library.
     *
     * @param   ExecutionContext  $context  Authenticated actor and exact site.
     * @param   string            $query    Case-insensitive display-name substring, at most 200 bytes.
     * @param   int               $limit    Most choices to return, from one to fifty.
     *
     * @return  list<MediaAsset>  Validated assets safe to identify in a generated selector.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When `content.read` is refused.
     * @throws  \InvalidArgumentException  When the query or limit is outside the selector bounds.
     * @throws  \RuntimeException  When the configured storage cannot guarantee bounded choice work.
     *
     * @since   2.0.0
     */
    public function choices(ExecutionContext $context, string $query = '', int $limit = 50): array
    {
        $this->authorize($context, 'content.read');
        if (!$this->storage instanceof BoundedMediaChoiceStorage) {
            throw new \RuntimeException('Bounded media choices are unavailable for this storage backend.');
        }

        return $this->storage->choices($context->site(), $query, $limit, 4096);
    }

    /**
     * Return the complete validated site catalog for a trusted bounded adapter to filter and page.
     *
     * This seam exists for adapters, such as the Studio media port, whose cursor and page contract is
     * different from the administrator screen. It preserves authorization and site scoping while leaving
     * protocol-specific filtering outside the core media module.
     *
     * @param   ExecutionContext  $context  Identity and exact site whose catalog may be read.
     *
     * @return  list<MediaAsset>  Newest-first validated assets.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When `content.read` is refused.
     *
     * @since   2.0.0
     */
    public function catalog(ExecutionContext $context): array
    {
        $this->authorize($context, 'content.read');

        return $this->storage->all($context->site());
    }

    /**
     * Resolve one validated asset inside the caller's authorized site.
     *
     * @param   ExecutionContext  $context  Identity and exact site whose asset may be read.
     * @param   string            $id       Media-store identity.
     *
     * @return  MediaAsset|null  Validated asset or null when absent from this site.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When `content.read` is refused.
     *
     * @since   2.0.0
     */
    public function get(ExecutionContext $context, string $id): ?MediaAsset
    {
        $this->authorize($context, 'content.read');

        return $this->storage->find($context->site(), $id);
    }

    /**
     * Add an uploaded file to the site's library and record the upload in the audit trail.
     *
     * The file is stored first and audited second. If the recorder rejects the event the stored asset
     * is deleted again and the original failure is re-thrown, so a caller either gets an asset that is
     * fully accounted for or gets an error and no file at all.
     *
     * @param   ExecutionContext  $context       Identity and site the upload is attributed to.
     * @param   string            $source        Path of the temporary file holding the uploaded bytes.
     * @param   string            $originalName  Filename as the client sent it, sanitised by the storage.
     *
     * @return  MediaAsset  The stored asset, with the identifier and media type the storage assigned.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When `content.update` is refused.
     * @throws  \InvalidArgumentException  When the source is unreadable, empty, oversized, or unsupported.
     * @throws  \RuntimeException  When the storage cannot write the file or its metadata.
     *
     * @since   2.0.0
     */
    public function upload(ExecutionContext $context, string $source, string $originalName): MediaAsset
    {
        $this->authorize($context, 'content.update');
        $now = $this->clock->now();
        $asset = $this->storage->store(
            $context->site(),
            $source,
            $originalName,
            $this->maximumBytes,
            $now,
        );
        $this->transactions?->afterRollback(
            function () use ($context, $asset): void {
                $this->storage->delete($context->site(), $asset->id);
            },
        );
        try {
            $this->audit->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $now,
                $context->actorId(),
                'media.upload',
                'media',
                $asset->id,
                'success',
                ['mime_type' => $asset->mimeType, 'size' => $asset->size],
            ));
        } catch (\Throwable $failure) {
            $this->storage->delete($context->site(), $asset->id);
            throw $failure;
        }

        return $asset;
    }

    /**
     * Remove an asset from the site's library and record the deletion.
     *
     * An identifier the library does not hold is ignored rather than reported, so a resubmitted delete
     * form is harmless. Read-only bundled assets are visible to the lookup but live outside the
     * writable root, so the file survives the call untouched even though the `media.delete` event is
     * still recorded for it.
     *
     * @param   ExecutionContext  $context  Identity and site the deletion is attributed to.
     * @param   string            $id       Identifier of the asset to remove.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When `content.delete` is refused.
     * @throws  \RuntimeException  When the storage cannot remove the file or its metadata.
     *
     * @since   2.0.0
     */
    public function delete(ExecutionContext $context, string $id): void
    {
        $this->authorize($context, 'content.delete');
        $asset = $this->storage->find($context->site(), $id);
        if (!$asset instanceof MediaAsset) {
            return;
        }
        $now = $this->clock->now();
        $this->storage->delete($context->site(), $id);
        $this->audit->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $now,
            $context->actorId(),
            'media.delete',
            'media',
            $asset->id,
            'success',
            ['name' => $asset->name],
        ));
    }

    /**
     * Assert the caller may perform a capability against the media collection as a whole.
     *
     * Media is authorized at collection granularity: there is no per-asset policy, so the resource is
     * always the `media` collection and a grant covers every file in the site's library.
     *
     * @param   ExecutionContext  $context     Identity the decision is made for.
     * @param   string            $capability  Capability name, such as `content.update`.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the gateway refuses it.
     *
     * @since   2.0.0
     */
    private function authorize(ExecutionContext $context, string $capability): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString($capability),
            AuthorizationResource::collection('media'),
        );
    }
}
