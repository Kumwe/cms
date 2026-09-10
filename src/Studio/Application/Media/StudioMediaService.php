<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Media;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Media\Application\MediaAsset;
use Kumwe\App\Media\Application\MediaService;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Domain\Media\StudioMediaPolicyRejected;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadPolicy;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadRequest;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadSession;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadState;
use Kumwe\Context\Value\ExecutionContext;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\StreamInterface;
use Ramsey\Uuid\Uuid;
use stdClass;

/**
 * Complete App implementation of Studio's seven-operation media host port and binary grant seam.
 *
 * This use case delegates catalog reads and durable acceptance to the existing App `MediaService`, so
 * authorization, site isolation, byte detection, storage integrity and upload auditing remain owned by
 * the media module. Studio-specific policy is limited to the portable port shapes, upload-session state,
 * opaque paging, grant custody and hardened external fetching.
 *
 * @since  2.0.0
 */
final readonly class StudioMediaService implements StudioMediaOperations
{
    /**
     * Compose App media custody with the canonical Studio policy and durable grant lifecycle.
     *
     * @param  MediaService                  $media         Existing authorized and audited App media use case.
     * @param  StudioMediaUploadRepository   $uploads       Durable scoped upload snapshots.
     * @param  StudioMediaStagingStorage     $staging       Private upload byte custody.
     * @param  StudioMediaUploadPolicy       $policy        Host-declared upload policy.
     * @param  StudioMediaSignatureVerifier  $signatures    Completion-time byte verification.
     * @param  StudioExternalMediaFetcher    $external      Hardened external importer.
     * @param  StudioMediaAssetProjector     $projector     Portable media document projection.
     * @param  StudioMediaCursorCodec        $cursors       Site/query-bound opaque paging.
     * @param  StudioMediaGrantToken         $grants        Non-persisted capability derivation.
     * @param  TransactionManager            $transactions  Shared database/filesystem boundary.
     * @param  AuditRecorder                 $audit         Transactional safe lifecycle trail.
     * @param  ClockInterface                $clock         Grant expiry clock.
     * @param  string                        $baseUrl       Configured application origin.
     * @param  int                           $grantSeconds  Short-lived grant lifetime.
     *
     * @since  2.0.0
     */
    public function __construct(
        private MediaService $media,
        private StudioMediaUploadRepository $uploads,
        private StudioMediaStagingStorage $staging,
        private StudioMediaUploadPolicy $policy,
        private StudioMediaSignatureVerifier $signatures,
        private StudioExternalMediaFetcher $external,
        private StudioMediaAssetProjector $projector,
        private StudioMediaCursorCodec $cursors,
        private StudioMediaGrantToken $grants,
        private TransactionManager $transactions,
        private AuditRecorder $audit,
        private ClockInterface $clock,
        private string $baseUrl,
        private int $grantSeconds = 300,
    ) {
        if ($grantSeconds < 30 || $grantSeconds > 900) {
            throw new InvalidArgumentException('The Studio media grant lifetime is invalid.');
        }
    }

    /**
     * Authorize one descriptor before bytes move and persist its single-purpose transfer capability.
     *
     * @param   ExecutionContext           $context   Fresh authenticated App execution context.
     * @param   StudioHostSessionSnapshot  $snapshot  Current Studio session authority.
     * @param   StudioMediaUploadRequest   $request   Validated declared upload.
     *
     * @return  stdClass  Canonical bounded `MediaUploadGrant`.
     *
     * @throws  StudioMediaPortRejected  When host type or size policy refuses the request.
     *
     * @since   2.0.0
     */
    public function authorizeUpload(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        StudioMediaUploadRequest $request,
    ): stdClass {
        return $this->transactions->transactional(
            fn (): stdClass => $this->authorizeWithinTransaction($context, $snapshot, $request),
        );
    }

    /**
     * Persist one authorized grant and compensate its private staging object on rollback.
     *
     * @param   ExecutionContext           $context   Fresh authenticated App execution context.
     * @param   StudioHostSessionSnapshot  $snapshot  Current Studio session authority.
     * @param   StudioMediaUploadRequest   $request   Validated declared upload.
     *
     * @return  stdClass  Canonical bounded `MediaUploadGrant`.
     *
     * @since   2.0.0
     */
    private function authorizeWithinTransaction(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        StudioMediaUploadRequest $request,
    ): stdClass {
        self::scope($context, $snapshot);
        try {
            $plan = $this->policy->authorize($request);
        } catch (StudioMediaPolicyRejected $failure) {
            throw new StudioMediaPortRejected(
                $failure->failureCode === 'studio.media/upload-too-large'
                    ? 'limit-exceeded'
                    : 'validation-failed',
                $failure->failureCode,
            );
        }
        $id = 'uploads/' . bin2hex(random_bytes(16));
        $expiresAt = $this->clock->now()->modify('+' . $this->grantSeconds . ' seconds');
        $expiry = $expiresAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        $token = $this->grants->derive(
            $id,
            $context->actorId(),
            $context->site()->identifier(),
            $snapshot->session->resourceContextKey,
            $snapshot->generation,
            $expiry,
        );
        $session = new StudioMediaUploadSession(
            $id,
            $context->actorId(),
            $context->site()->identifier(),
            $snapshot->session->resourceContextKey,
            $snapshot->generation,
            $request,
            $plan,
            StudioMediaUploadState::Authorized,
            0,
            hash('sha256', $token),
            $expiresAt,
        );
        $this->staging->create($id);
        $this->transactions->afterRollback(function () use ($id): void {
            $this->staging->delete($id);
        });
        $this->uploads->add($session);
        $this->audit(
            $context,
            'studio.media.authorize',
            $id,
            'success',
            [
                'byte_size' => $request->byteSize,
                'context_digest' => hash('sha256', $snapshot->session->resourceContextKey),
                'generation_digest' => hash('sha256', $snapshot->generation),
                'media_type' => $request->mediaType,
                'resumable' => $plan->resumable,
            ],
        );

        return (object) [
            'expiresAt' => self::instant($expiresAt),
            'method' => 'PUT',
            'plan' => $plan->document(),
            'uploadId' => $id,
            'url' => $this->grantUrl($id),
            'headers' => (object) [
                'Content-Type' => $request->mediaType,
                'X-Studio-Resource-Context' => $snapshot->session->resourceContextKey,
                'X-Studio-Session-Generation' => $snapshot->generation,
                'X-Studio-Upload-Token' => $token,
            ],
        ];
    }

    /**
     * Restore only the plaintext capability stripped from a durable authorize-upload replay.
     *
     * @param   ExecutionContext           $context   Fresh authenticated App execution context.
     * @param   StudioHostSessionSnapshot  $snapshot  Current Studio session authority.
     * @param   stdClass                   $stored    Integrity-checked secret-free stored grant.
     *
     * @return  stdClass  Original grant shape with its deterministically restored transfer token.
     *
     * @throws  StudioMediaPortRejected  When the stored replay no longer names this exact upload scope.
     *
     * @since   2.0.0
     */
    public function replayUploadGrant(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        stdClass $stored,
    ): stdClass {
        $uploadId = $stored->uploadId ?? null;
        $headers = $stored->headers ?? null;
        if (!is_string($uploadId) || !$headers instanceof stdClass) {
            throw new StudioMediaPortRejected('unavailable', 'studio.media/idempotency-corrupt');
        }
        $session = $this->session($context, $snapshot, $uploadId);
        $grant = clone $stored;
        $grant->headers = clone $headers;
        $grant->headers->{'X-Studio-Upload-Token'} = $this->grants->restore($session);

        return $grant;
    }

    /**
     * Accept a direct binary PUT under the exact grant scope and close transfer into verifying state.
     *
     * @param   ExecutionContext  $context     Fresh authenticated App context.
     * @param   string            $uploadId    Opaque upload identity from the route.
     * @param   string            $contextKey  Required grant context header.
     * @param   string            $generation  Required grant generation header.
     * @param   string            $token       Required one-time transfer token.
     * @param   string            $mediaType   Declared transfer Content-Type.
     * @param   StreamInterface   $body        Bounded streaming request body.
     *
     * @return  void
     *
     * @throws  StudioMediaPortRejected  When scope, token, state, expiry, type or size is invalid.
     *
     * @since   2.0.0
     */
    public function receive(
        ExecutionContext $context,
        string $uploadId,
        string $contextKey,
        string $generation,
        string $token,
        string $mediaType,
        StreamInterface $body,
    ): void {
        $result = $this->transactions->transactional(function () use (
            $context,
            $uploadId,
            $contextKey,
            $generation,
            $token,
            $mediaType,
            $body,
        ): ?StudioMediaPortRejected {
            try {
                $this->receiveWithinTransaction(
                    $context,
                    $uploadId,
                    $contextKey,
                    $generation,
                    $token,
                    $mediaType,
                    $body,
                );
            } catch (StudioMediaPortRejected $failure) {
                if (!$failure->commitsState) {
                    throw $failure;
                }

                return $failure;
            }

            return null;
        });
        if ($result instanceof StudioMediaPortRejected) {
            throw $result;
        }
    }

    /**
     * Claim transfer before touching shared staging, then persist its exact byte outcome atomically.
     *
     * @param   ExecutionContext  $context     Fresh authenticated App context.
     * @param   string            $uploadId    Opaque upload identity from the route.
     * @param   string            $contextKey  Required grant context header.
     * @param   string            $generation  Required grant generation header.
     * @param   string            $token       Required one-time transfer token.
     * @param   string            $mediaType   Declared transfer Content-Type.
     * @param   StreamInterface   $body        Bounded streaming request body.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function receiveWithinTransaction(
        ExecutionContext $context,
        string $uploadId,
        string $contextKey,
        string $generation,
        string $token,
        string $mediaType,
        StreamInterface $body,
    ): void {
        $session = $this->uploads->find(
            $uploadId,
            $context->actorId(),
            $context->site()->identifier(),
            $contextKey,
            $generation,
        );
        if (
            $session === null
            || $session->state !== StudioMediaUploadState::Authorized
            || $this->clock->now() >= $session->expiresAt
            || $token === ''
            || !hash_equals($session->tokenDigest, hash('sha256', $token))
            || !hash_equals($session->request->mediaType, strtolower(trim($mediaType)))
        ) {
            throw new StudioMediaPortRejected('not-found', 'studio.media/upload-not-found');
        }
        $transferring = $session->transition(StudioMediaUploadState::Transferring, 0);
        if (!$this->uploads->save($transferring, $session->version)) {
            throw new StudioMediaPortRejected('conflict', 'studio.media/upload-concurrent');
        }
        $session = $transferring;
        $bytes = $this->staging->write($session->id, $body, $session->plan->maximumBytes);
        if ($bytes !== $session->request->byteSize) {
            $failed = $session->transition(
                StudioMediaUploadState::Failed,
                min($bytes, $session->request->byteSize),
                failureCode: 'studio.media/upload-failed',
            );
            if (!$this->uploads->save($failed, $session->version)) {
                throw new StudioMediaPortRejected('conflict', 'studio.media/upload-concurrent');
            }
            $this->audit(
                $context,
                'studio.media.transfer',
                $session->id,
                'failed',
                [
                    'declared_bytes' => $session->request->byteSize,
                    'failure_code' => 'studio.media/upload-size-mismatch',
                    'received_bytes' => $bytes,
                ],
            );
            $this->deleteStagingAfterCommit($session->id);
            throw new StudioMediaPortRejected(
                'validation-failed',
                'studio.media/upload-size-mismatch',
                true,
            );
        }
        $verifying = $session->transition(StudioMediaUploadState::Verifying, $bytes);
        if (!$this->uploads->save($verifying, $session->version)) {
            throw new StudioMediaPortRejected('conflict', 'studio.media/upload-concurrent');
        }
        $this->audit(
            $context,
            'studio.media.transfer',
            $session->id,
            'success',
            ['byte_size' => $bytes, 'media_type' => $session->request->mediaType],
        );
    }

    /**
     * Cancel one active grant without deleting an already accepted App asset.
     *
     * @param   ExecutionContext           $context   Fresh authenticated App context.
     * @param   StudioHostSessionSnapshot  $snapshot  Current Studio session authority.
     * @param   string                     $uploadId  Opaque upload identity.
     *
     * @return  null
     *
     * @since   2.0.0
     */
    public function abortUpload(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        string $uploadId,
    ): null {
        return $this->transactions->transactional(
            fn (): null => $this->abortWithinTransaction($context, $snapshot, $uploadId),
        );
    }

    /**
     * Persist cancellation and defer private-byte deletion until that state is durable.
     *
     * @param   ExecutionContext           $context   Fresh authenticated App context.
     * @param   StudioHostSessionSnapshot  $snapshot  Current Studio session authority.
     * @param   string                     $uploadId  Opaque upload identity.
     *
     * @return  null
     *
     * @since   2.0.0
     */
    private function abortWithinTransaction(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        string $uploadId,
    ): null {
        $session = $this->session($context, $snapshot, $uploadId);
        if ($session->state === StudioMediaUploadState::Complete) {
            $this->audit(
                $context,
                'studio.media.abort',
                $session->id,
                'noop',
                ['previous_state' => $session->state->value],
            );
            return null;
        }
        if (
            !in_array($session->state, [
            StudioMediaUploadState::Authorized,
            StudioMediaUploadState::Transferring,
            StudioMediaUploadState::Verifying,
            ], true)
        ) {
            throw new StudioMediaPortRejected('not-found', 'studio.media/upload-not-found');
        }
        $cancelled = $session->transition(StudioMediaUploadState::Cancelled, $session->transferred);
        if (!$this->uploads->save($cancelled, $session->version)) {
            throw new StudioMediaPortRejected('conflict', 'studio.media/upload-concurrent');
        }
        $this->audit(
            $context,
            'studio.media.abort',
            $session->id,
            'success',
            ['previous_state' => $session->state->value],
        );
        $this->deleteStagingAfterCommit($session->id);

        return null;
    }

    /**
     * Verify received bytes and admit them through the existing audited App media use case.
     *
     * @param   ExecutionContext           $context   Fresh authenticated App context.
     * @param   StudioHostSessionSnapshot  $snapshot  Current Studio session authority.
     * @param   string                     $uploadId  Opaque upload identity.
     *
     * @return  stdClass  Small accepted-asset identity.
     *
     * @since   2.0.0
     */
    public function completeUpload(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        string $uploadId,
    ): stdClass {
        $result = $this->transactions->transactional(
            function () use ($context, $snapshot, $uploadId): stdClass|StudioMediaPortRejected {
                try {
                    return $this->completeWithinTransaction($context, $snapshot, $uploadId);
                } catch (StudioMediaPortRejected $failure) {
                    if (!$failure->commitsState) {
                        throw $failure;
                    }

                    return $failure;
                }
            },
        );
        if ($result instanceof StudioMediaPortRejected) {
            throw $result;
        }

        return $result;
    }

    /**
     * Claim, verify and admit one upload inside the shared idempotency transaction.
     *
     * @param   ExecutionContext           $context   Fresh authenticated App context.
     * @param   StudioHostSessionSnapshot  $snapshot  Current Studio session authority.
     * @param   string                     $uploadId  Opaque upload identity.
     *
     * @return  stdClass  Small accepted-asset identity.
     *
     * @since   2.0.0
     */
    private function completeWithinTransaction(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        string $uploadId,
    ): stdClass {
        $session = $this->session($context, $snapshot, $uploadId);
        if ($session->state === StudioMediaUploadState::Complete && $session->asset !== null) {
            $this->audit(
                $context,
                'studio.media.complete',
                $session->id,
                'noop',
                ['asset_id' => $session->asset->id],
            );
            return $session->asset->document();
        }
        if ($session->state !== StudioMediaUploadState::Verifying) {
            throw new StudioMediaPortRejected('not-found', 'studio.media/upload-not-found');
        }
        $claimed = $session->claimCompletion();
        if (!$this->uploads->save($claimed, $session->version)) {
            throw new StudioMediaPortRejected('conflict', 'studio.media/upload-concurrent');
        }
        $session = $claimed;
        $path = $this->staging->path($session->id);
        $detected = $this->signatures->verify($path);
        if (
            $detected === null
            || !hash_equals($session->request->mediaType, $detected)
            || !$this->checksum($session, $path)
        ) {
            $failed = $session->transition(
                StudioMediaUploadState::Failed,
                $session->transferred,
                failureCode: 'studio.media/upload-failed',
            );
            if (!$this->uploads->save($failed, $session->version)) {
                throw new StudioMediaPortRejected('conflict', 'studio.media/upload-concurrent');
            }
            $this->audit(
                $context,
                'studio.media.complete',
                $session->id,
                'failed',
                [
                    'failure_code' => 'studio.media/upload-verification-failed',
                    'media_type' => $session->request->mediaType,
                ],
            );
            $this->deleteStagingAfterCommit($session->id);
            throw new StudioMediaPortRejected(
                'validation-failed',
                'studio.media/upload-verification-failed',
                true,
            );
        }
        $asset = $this->media->upload($context, $path, $session->request->filename);
        $accepted = $this->projector->accepted($asset);
        $complete = $session->transition(
            StudioMediaUploadState::Complete,
            $session->transferred,
            $accepted,
        );
        if (!$this->uploads->save($complete, $session->version)) {
            throw new StudioMediaPortRejected('conflict', 'studio.media/upload-concurrent');
        }
        $this->audit(
            $context,
            'studio.media.complete',
            $session->id,
            'success',
            [
                'asset_id' => $asset->id,
                'byte_size' => $asset->size,
                'media_type' => $asset->mimeType,
            ],
        );
        $this->deleteStagingAfterCommit($session->id);

        return $accepted->document();
    }

    /**
     * Resolve one App media asset into a canonical Studio document, with absence as null.
     *
     * @param   ExecutionContext  $context  Fresh authenticated App context.
     * @param   string            $assetId  Media asset identity.
     *
     * @return  stdClass|null  Detached asset snapshot or null.
     *
     * @since   2.0.0
     */
    public function get(ExecutionContext $context, string $assetId): ?stdClass
    {
        $asset = $this->media->get($context, $assetId);

        return $asset instanceof MediaAsset ? $this->projector->document($asset) : null;
    }

    /**
     * Filter and page the authorized App catalog under an authenticated opaque cursor.
     *
     * @param   ExecutionContext  $context  Fresh authenticated App context.
     * @param   stdClass          $query    Closed canonical `MediaQuery` object.
     *
     * @return  stdClass  Canonical `MediaPage`.
     *
     * @since   2.0.0
     */
    public function list(ExecutionContext $context, stdClass $query): stdClass
    {
        [$limit, $search, $mediaTypes, $cursor] = self::query($query);
        $digest = hash('sha256', json_encode(
            ['search' => $search, 'mediaTypes' => $mediaTypes],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
        $offset = $cursor === null
            ? 0
            : $this->cursors->decode($cursor, $context->site()->identifier(), $digest);
        $assets = array_values(array_filter(
            $this->media->catalog($context),
            static fn (MediaAsset $asset): bool => ($mediaTypes === [] || in_array($asset->mimeType, $mediaTypes, true))
                && ($search === '' || str_contains(mb_strtolower($asset->name), $search)),
        ));
        $slice = array_slice($assets, $offset, $limit);
        $page = (object) [
            'assets' => array_map(
                fn (MediaAsset $asset): stdClass => $this->projector->document($asset),
                $slice,
            ),
        ];
        if ($offset + count($slice) < count($assets)) {
            $page->nextCursor = $this->cursors->encode(
                $context->site()->identifier(),
                $digest,
                $offset + count($slice),
            );
        }

        return $page;
    }

    /**
     * Import one URL through the hardened fetcher and existing audited App media use case.
     *
     * @param   ExecutionContext  $context  Fresh authenticated App context.
     * @param   string            $url      Untrusted external candidate.
     *
     * @return  stdClass  Small accepted-asset identity.
     *
     * @since   2.0.0
     */
    public function importExternal(ExecutionContext $context, string $url): stdClass
    {
        return $this->transactions->transactional(
            fn (): stdClass => $this->importWithinTransaction($context, $url),
        );
    }

    /**
     * Fetch and admit one external candidate inside the shared mutation transaction.
     *
     * @param   ExecutionContext  $context  Fresh authenticated App context.
     * @param   string            $url      Untrusted external candidate.
     *
     * @return  stdClass  Small accepted-asset identity.
     *
     * @since   2.0.0
     */
    private function importWithinTransaction(ExecutionContext $context, string $url): stdClass
    {
        $fetched = $this->external->fetch($url);
        try {
            $asset = $this->media->upload($context, $fetched->path, $fetched->filename);
            $this->audit(
                $context,
                'studio.media.import',
                $asset->id,
                'success',
                ['byte_size' => $asset->size, 'media_type' => $asset->mimeType],
            );
        } finally {
            if (is_file($fetched->path)) {
                @unlink($fetched->path);
            }
        }

        return $this->projector->accepted($asset)->document();
    }

    /**
     * Return the small ready status for an accepted App asset or a safe not-found refusal.
     *
     * @param   ExecutionContext  $context  Fresh authenticated App context.
     * @param   string            $assetId  Media asset identity.
     *
     * @return  stdClass  Small accepted-asset identity.
     *
     * @since   2.0.0
     */
    public function uploadStatus(ExecutionContext $context, string $assetId): stdClass
    {
        $asset = $this->media->get($context, $assetId);
        if (!$asset instanceof MediaAsset) {
            throw new StudioMediaPortRejected('not-found', 'studio.media/asset-not-found');
        }

        return $this->projector->accepted($asset)->document();
    }

    /**
     * Resolve an upload only inside the already verified Studio/App scope.
     *
     * @param   ExecutionContext           $context   Fresh authenticated App context.
     * @param   StudioHostSessionSnapshot  $snapshot  Current Studio authority.
     * @param   string                     $uploadId  Opaque upload identity.
     *
     * @return  StudioMediaUploadSession  Scoped upload snapshot.
     *
     * @since   2.0.0
     */
    private function session(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        string $uploadId,
    ): StudioMediaUploadSession {
        self::scope($context, $snapshot);
        $session = $this->uploads->find(
            $uploadId,
            $context->actorId(),
            $context->site()->identifier(),
            $snapshot->session->resourceContextKey,
            $snapshot->generation,
        );
        if ($session === null) {
            throw new StudioMediaPortRejected('not-found', 'studio.media/upload-not-found');
        }

        return $session;
    }

    /**
     * Re-assert that application identity and the resolved host snapshot still name one scope.
     *
     * @param   ExecutionContext           $context   Fresh authenticated App context.
     * @param   StudioHostSessionSnapshot  $snapshot  Current Studio authority.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function scope(ExecutionContext $context, StudioHostSessionSnapshot $snapshot): void
    {
        if (
            !hash_equals($snapshot->session->actorId, $context->actorId())
            || !hash_equals($snapshot->session->siteId, $context->site()->identifier())
            || !hash_equals($snapshot->generation, $snapshot->session->sessionGeneration)
        ) {
            throw new StudioMediaPortRejected('forbidden', 'studio.media/scope-refused');
        }
    }

    /**
     * Decode a closed query object and normalize filters for deterministic cursor binding.
     *
     * @param   stdClass  $query  Decoded canonical query candidate.
     *
     * @return  array{int, string, list<string>, string|null}  Limit, search, types and cursor.
     *
     * @since   2.0.0
     */
    private static function query(stdClass $query): array
    {
        $members = array_keys(get_object_vars($query));
        sort($members, SORT_STRING);
        $limit = $query->limit ?? null;
        $cursor = property_exists($query, 'cursor') ? $query->cursor : null;
        $searchValue = property_exists($query, 'search') ? $query->search : '';
        $mediaTypeValues = property_exists($query, 'mediaTypes') ? $query->mediaTypes : [];
        if (
            !in_array($members, [
                ['limit'],
                ['cursor', 'limit'],
                ['limit', 'mediaTypes'],
                ['limit', 'search'],
                ['cursor', 'limit', 'mediaTypes'],
                ['cursor', 'limit', 'search'],
                ['limit', 'mediaTypes', 'search'],
                ['cursor', 'limit', 'mediaTypes', 'search'],
            ], true)
            || !is_int($limit)
            || $limit < 1
            || $limit > 100
            || ($cursor !== null && !is_string($cursor))
            || !is_string($searchValue)
            || !is_array($mediaTypeValues)
            || !array_is_list($mediaTypeValues)
        ) {
            throw new StudioMediaPortRejected('invalid-request', 'studio.media/query-invalid');
        }
        $search = mb_strtolower(trim($searchValue));
        if (mb_strlen($search) > 200) {
            throw new StudioMediaPortRejected('invalid-request', 'studio.media/query-invalid');
        }
        if (count($mediaTypeValues) > 50) {
            throw new StudioMediaPortRejected('invalid-request', 'studio.media/query-invalid');
        }
        $mediaTypes = [];
        foreach ($mediaTypeValues as $mediaType) {
            if (
                !is_string($mediaType)
                || in_array($mediaType, $mediaTypes, true)
                || preg_match(
                    '/^[a-z0-9!#$&^_.+-]+\/[a-z0-9!#$&^_.+-]+$/D',
                    $mediaType,
                ) !== 1
            ) {
                throw new StudioMediaPortRejected('invalid-request', 'studio.media/query-invalid');
            }
            $mediaTypes[] = $mediaType;
        }
        sort($mediaTypes, SORT_STRING);

        return [$limit, $search, $mediaTypes, $cursor];
    }

    /**
     * Compare an optional SRI checksum with the received bytes in constant time.
     *
     * @param   StudioMediaUploadSession  $session  Upload carrying the declared checksum.
     * @param   string                    $path     Private received body.
     *
     * @return  bool  True when absent or matching.
     *
     * @since   2.0.0
     */
    private function checksum(StudioMediaUploadSession $session, string $path): bool
    {
        if ($session->request->checksum === null) {
            return true;
        }
        [$algorithm, $expected] = explode('-', $session->request->checksum, 2);
        $actual = hash_file($algorithm, $path, true);

        return is_string($actual) && hash_equals($expected, base64_encode($actual));
    }

    /**
     * Remove private staging only after the upload state that released it has committed.
     *
     * @param   string  $uploadId  Opaque upload identity.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function deleteStagingAfterCommit(string $uploadId): void
    {
        $this->transactions->afterCommit(function () use ($uploadId): void {
            $this->staging->delete($uploadId);
        });
    }

    /**
     * Record one Studio media lifecycle effect with identifiers and metadata safe for the platform trail.
     *
     * Upload subjects are represented by their random hexadecimal suffix, never by a capability, path,
     * filename or URL. Callers supply only policy facts, byte counts, stable states and accepted asset IDs.
     *
     * @param   ExecutionContext      $context    Accountable authenticated actor.
     * @param   string                $action     Closed audit action token.
     * @param   string                $subjectId  Upload identity or accepted asset identity.
     * @param   string                $outcome    Closed audit outcome token.
     * @param   array<string, mixed>  $metadata   Explicit safe metadata.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function audit(
        ExecutionContext $context,
        string $action,
        string $subjectId,
        string $outcome,
        array $metadata,
    ): void {
        $subject = str_starts_with($subjectId, 'uploads/') ? substr($subjectId, 8) : $subjectId;
        $this->audit->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $this->clock->now(),
            $context->actorId(),
            $action,
            'studio_media',
            $subject,
            $outcome,
            $metadata,
        ));
    }

    /**
     * Format a canonical UTC RFC 3339 instant.
     *
     * @param   DateTimeImmutable  $instant  Instant to normalize.
     *
     * @return  string  Millisecond UTC representation.
     *
     * @since   2.0.0
     */
    private static function instant(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Build the absolute HTTPS host-controlled grant URL.
     *
     * @param   string  $uploadId  Opaque upload identity.
     *
     * @return  string  Absolute transfer URL.
     *
     * @since   2.0.0
     */
    private function grantUrl(string $uploadId): string
    {
        $parts = parse_url($this->baseUrl);
        if (!is_array($parts) || !is_string($parts['host'] ?? null)) {
            throw new \RuntimeException('The Studio media grant origin is invalid.');
        }
        $origin = 'https://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin . '/administrator/studio/media/uploads/' . substr($uploadId, 8);
    }
}
