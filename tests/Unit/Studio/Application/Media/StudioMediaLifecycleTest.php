<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Media;

use ArrayObject;
use DateTimeImmutable;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Media\Application\MediaAsset;
use Kumwe\App\Media\Application\MediaService;
use Kumwe\App\Media\Application\MediaStorage;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Application\Media\StudioExternalAddressResolver;
use Kumwe\App\Studio\Application\Media\StudioExternalMediaFetcher;
use Kumwe\App\Studio\Application\Media\StudioMediaAssetProjector;
use Kumwe\App\Studio\Application\Media\StudioMediaCursorCodec;
use Kumwe\App\Studio\Application\Media\StudioMediaGrantToken;
use Kumwe\App\Studio\Application\Media\StudioMediaPortRejected;
use Kumwe\App\Studio\Application\Media\StudioMediaService;
use Kumwe\App\Studio\Application\Media\StudioMediaSignatureVerifier;
use Kumwe\App\Studio\Application\Media\StudioMediaUploadRepository;
use Kumwe\App\Studio\Application\Media\StudioPinnedHttpResponse;
use Kumwe\App\Studio\Application\Media\StudioPinnedHttpTransport;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\App\Studio\Domain\Media\StudioExternalUrlPolicy;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadPolicy;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadRequest;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadSession;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadState;
use Kumwe\App\Studio\Infrastructure\Media\FilesystemStudioMediaStagingStorage;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\SiteContext;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use stdClass;

/**
 * Typed mutable asset map shared by the media-storage callbacks in the lifecycle fixture.
 *
 * @extends  ArrayObject<string, MediaAsset>
 *
 * @since  2.0.0
 */
final class StudioMediaAssetMap extends ArrayObject
{
}

/**
 * Mutable fault controls and upload snapshots shared by the lifecycle fixture collaborators.
 *
 * @since  2.0.0
 */
final class StudioMediaLifecycleState
{
    /**
     * Durable in-memory upload snapshots indexed by opaque upload identity.
     *
     * @var    array<string, StudioMediaUploadSession>
     * @since  2.0.0
     */
    public array $sessions = [];

    /**
     * One-shot optimistic-save refusal selected by a lifecycle scenario.
     *
     * @var    (\Closure(StudioMediaUploadSession, int): bool)|null
     * @since  2.0.0
     */
    public ?\Closure $saveRejection = null;

    /**
     * Media type detected by the signature-verifier fixture, or null for an invalid body.
     *
     * @var    string|null
     * @since  2.0.0
     */
    public ?string $detectedMediaType = 'image/jpeg';
}

/**
 * Executes lifecycle fixture operations with commit hooks and rollback compensation at the real boundary.
 *
 * @since  2.0.0
 */
final class StudioMediaLifecycleTransactionManager implements TransactionManager
{
    /**
     * Effects waiting for the active outer transaction to commit.
     *
     * @var    list<callable(): void>
     * @since  2.0.0
     */
    private array $commitOperations = [];

    /**
     * Compensations waiting for the active outer transaction to roll back.
     *
     * @var    list<callable(): void>
     * @since  2.0.0
     */
    private array $rollbackOperations = [];

    /**
     * Current joined transaction depth.
     *
     * @var    int
     * @since  2.0.0
     */
    private int $depth = 0;

    /**
     * Bind transactional settlement to the fixture's durable upload map.
     *
     * @param  StudioMediaLifecycleState  $state  Mutable repository state restored on rollback.
     *
     * @since  2.0.0
     */
    public function __construct(private StudioMediaLifecycleState $state)
    {
    }

    /**
     * Run one operation and settle its deferred effects at the outermost boundary.
     *
     * @template T
     *
     * @param   callable(): T  $operation  Work joined to the active transaction.
     *
     * @return  T  Operation result after successful commit settlement.
     *
     * @since  2.0.0
     */
    public function transactional(callable $operation): mixed
    {
        $outermost = $this->depth === 0;
        $sessionsBefore = $outermost ? $this->state->sessions : [];
        if ($outermost) {
            $this->commitOperations = [];
            $this->rollbackOperations = [];
        }
        $this->depth++;
        try {
            $result = $operation();
        } catch (\Throwable $failure) {
            $this->depth--;
            if ($outermost) {
                self::execute(array_reverse($this->rollbackOperations));
                $this->state->sessions = $sessionsBefore;
                $this->commitOperations = [];
                $this->rollbackOperations = [];
            }

            throw $failure;
        }
        $this->depth--;
        if ($outermost) {
            $commits = $this->commitOperations;
            $this->commitOperations = [];
            $this->rollbackOperations = [];
            self::execute($commits);
        }

        return $result;
    }

    /**
     * Defer one effect until the outer transaction commits, or execute it immediately outside a transaction.
     *
     * @param   callable(): void  $operation  Effect safe only after commit.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function afterCommit(callable $operation): void
    {
        if ($this->depth === 0) {
            $operation();

            return;
        }
        $this->commitOperations[] = $operation;
    }

    /**
     * Register one compensation only while a transaction can still roll back.
     *
     * @param   callable(): void  $operation  Compensation for an unsettled external effect.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function afterRollback(callable $operation): void
    {
        if ($this->depth > 0) {
            $this->rollbackOperations[] = $operation;
        }
    }

    /**
     * Execute a detached ordered hook list without permitting settlement to mutate the iteration.
     *
     * @param   list<callable(): void>  $operations  Commit effects or reversed rollback compensations.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    private static function execute(array $operations): void
    {
        foreach ($operations as $operation) {
            $operation();
        }
    }
}

/**
 * Exercises every Studio media operation as one scoped lifecycle over the real private staging adapter.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioMediaService::class)]
#[CoversClass(StudioMediaAssetProjector::class)]
#[CoversClass(StudioMediaCursorCodec::class)]
#[CoversClass(StudioExternalMediaFetcher::class)]
#[CoversClass(FilesystemStudioMediaStagingStorage::class)]
#[CoversClass(MediaService::class)]
final class StudioMediaLifecycleTest extends TestCase
{
    /**
     * Authorize, transfer, complete, read, page, abort and import under one live Studio scope.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testCompleteSevenOperationLifecycleIsScopedAuditedAndPortable(): void
    {
        $root = sys_get_temp_dir() . '/kumwe-studio-media-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0700, true));
        $assets = new StudioMediaAssetMap();
        $assetPaths = [];
        $storage = self::createStub(MediaStorage::class);
        $storage->method('all')->willReturnCallback(
            static function (SiteContext $site) use ($assets): array {
                return array_reverse(array_values($assets->getArrayCopy()));
            },
        );
        $storage->method('find')->willReturnCallback(
            static function (SiteContext $site, string $id) use ($assets): ?MediaAsset {
                return $assets->offsetExists($id) ? $assets->offsetGet($id) : null;
            },
        );
        $storage->method('store')->willReturnCallback(
            static function (
                SiteContext $site,
                string $source,
                string $originalName,
                int $maximumBytes,
                DateTimeImmutable $createdAt,
            ) use (
                $assets,
                &$assetPaths,
                $root,
            ): MediaAsset {
                $number = count($assets) + 1;
                $path = $root . '/asset-' . $number . '.jpg';
                self::assertTrue(copy($source, $path));
                $id = sprintf('00000000-0000-4000-8000-%012d', $number);
                $asset = new MediaAsset(
                    $id,
                    $originalName,
                    'image/jpeg',
                    (int) filesize($path),
                    $createdAt,
                    $path,
                );
                $assets->offsetSet($id, $asset);
                $assetPaths[] = $path;

                return $asset;
            },
        );
        $storage->method('delete')->willReturnCallback(
            static function (SiteContext $site, string $id) use ($assets): void {
                $assets->offsetUnset($id);
            },
        );
        $events = [];
        $audit = self::createStub(AuditRecorder::class);
        $audit->method('record')->willReturnCallback(
            static function (AuditEvent $event) use (&$events): void {
                $events[] = $event;
            },
        );
        $state = new StudioMediaLifecycleState();
        $transactions = new StudioMediaLifecycleTransactionManager($state);
        $now = new DateTimeImmutable('2026-08-24T12:00:00+00:00');
        $clock = self::createStub(ClockInterface::class);
        $clock->method('now')->willReturnCallback(
            static function () use (&$now): DateTimeImmutable {
                return $now;
            },
        );
        $uploads = self::createStub(StudioMediaUploadRepository::class);
        $uploads->method('add')->willReturnCallback(
            static function (StudioMediaUploadSession $session) use ($state): void {
                $state->sessions[$session->id] = $session;
            },
        );
        $uploads->method('find')->willReturnCallback(
            static function (
                string $id,
                string $actorId,
                string $siteId,
                string $contextKey,
                string $generation,
            ) use ($state): ?StudioMediaUploadSession {
                $session = $state->sessions[$id] ?? null;
                if (
                    !$session instanceof StudioMediaUploadSession
                    || $session->actorId !== $actorId
                    || $session->siteId !== $siteId
                    || $session->contextKey !== $contextKey
                    || $session->generation !== $generation
                ) {
                    return null;
                }

                return $session;
            },
        );
        $uploads->method('save')->willReturnCallback(
            static function (
                StudioMediaUploadSession $session,
                int $expected,
            ) use ($state): bool {
                if ($state->saveRejection instanceof \Closure && ($state->saveRejection)($session, $expected)) {
                    $state->saveRejection = null;

                    return false;
                }
                $current = $state->sessions[$session->id] ?? null;
                if (!$current instanceof StudioMediaUploadSession || $current->version !== $expected) {
                    return false;
                }
                $state->sessions[$session->id] = $session;

                return true;
            },
        );
        $signatures = self::createStub(StudioMediaSignatureVerifier::class);
        $signatures->method('verify')->willReturnCallback(
            static function () use ($state): ?string {
                return $state->detectedMediaType;
            },
        );
        $resolver = self::createStub(StudioExternalAddressResolver::class);
        $resolver->method('resolve')->willReturn(['93.184.216.34']);
        $transport = self::createStub(StudioPinnedHttpTransport::class);
        $transport->method('get')->willReturnCallback(
            static function () use ($root): StudioPinnedHttpResponse {
                $path = $root . '/external-' . bin2hex(random_bytes(6));
                self::assertNotFalse(file_put_contents($path, "\xff\xd8\xff\xe0external"));

                return new StudioPinnedHttpResponse(
                    200,
                    ['content-encoding' => 'identity', 'content-type' => 'image/jpeg'],
                    $path,
                    (int) filesize($path),
                );
            },
        );
        $media = new MediaService(
            $storage,
            AuthorizationContext::gateway(),
            $audit,
            $clock,
            10_000,
            $transactions,
        );
        $staging = new FilesystemStudioMediaStagingStorage($root . '/staging');
        $policy = new StudioMediaUploadPolicy(['image/jpeg'], 10_000, false);
        $external = new StudioExternalMediaFetcher(
            new StudioExternalUrlPolicy(),
            $resolver,
            $transport,
            $signatures,
            ['image/jpeg'],
            10_000,
        );
        $projector = new StudioMediaAssetProjector();
        $cursors = new StudioMediaCursorCodec(str_repeat('c', 32));
        $grants = new StudioMediaGrantToken(str_repeat('g', 32));
        $createService = static function (
            ?StudioMediaUploadPolicy $uploadPolicy = null,
            string $baseUrl = 'https://app.example.invalid',
            int $grantSeconds = 300,
        ) use (
            $audit,
            $clock,
            $cursors,
            $external,
            $grants,
            $media,
            $policy,
            $projector,
            $signatures,
            $staging,
            $transactions,
            $uploads,
        ): StudioMediaService {
            return new StudioMediaService(
                $media,
                $uploads,
                $staging,
                $uploadPolicy ?? $policy,
                $signatures,
                $external,
                $projector,
                $cursors,
                $grants,
                $transactions,
                $audit,
                $clock,
                $baseUrl,
                $grantSeconds,
            );
        };
        $service = $createService();
        $context = AuthenticatedPrincipal::issueFromStrings(
            AuthorizationContext::provenance(),
            AuthorizationContext::SUBJECT,
            ['content.read', 'content.update'],
        )->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'studio-media-lifecycle',
            surface: AuthenticatedSurface::Administrator,
            sessionId: 'administrator-session-media-lifecycle',
        );
        $snapshot = new StudioHostSessionSnapshot(
            new StudioHostSession(
                'contexts/media-lifecycle',
                $context->actorId(),
                $context->site()->identifier(),
                null,
                null,
                'administrator',
                str_repeat('a', 64),
                StudioSessionMode::Content,
                StudioResourceKind::Content,
                'content-lifecycle',
                'generation-lifecycle',
            ),
            ['studio.permission/read', 'studio.permission/upload-media'],
            'generation-lifecycle',
            true,
            false,
            false,
        );

        try {
            $bytes = "\xff\xd8\xff\xe0direct";
            try {
                $createService(grantSeconds: 29);
                self::fail('A grant lifetime below the secure minimum must be refused.');
            } catch (\InvalidArgumentException $failure) {
                self::assertSame('The Studio media grant lifetime is invalid.', $failure->getMessage());
            }

            $staleSnapshot = new StudioHostSessionSnapshot(
                $snapshot->session,
                $snapshot->permissions,
                'generation-stale',
                true,
                false,
                false,
            );
            self::assertRejected(
                static fn (): stdClass => $service->authorizeUpload(
                    $context,
                    $staleSnapshot,
                    new StudioMediaUploadRequest(
                        'scope-refused.jpg',
                        'image/jpeg',
                        strlen($bytes),
                        'studio.media/content',
                    ),
                ),
                'forbidden',
                'studio.media/scope-refused',
            );
            self::assertRejected(
                static fn (): stdClass => $service->authorizeUpload(
                    $context,
                    $snapshot,
                    new StudioMediaUploadRequest(
                        'unsupported.pdf',
                        'application/pdf',
                        strlen($bytes),
                        'studio.media/content',
                    ),
                ),
                'validation-failed',
                'studio.media/upload-failed',
            );
            self::assertRejected(
                static fn (): stdClass => $service->authorizeUpload(
                    $context,
                    $snapshot,
                    new StudioMediaUploadRequest(
                        'oversized.jpg',
                        'image/jpeg',
                        10_001,
                        'studio.media/content',
                    ),
                ),
                'limit-exceeded',
                'studio.media/upload-too-large',
            );

            $invalidOriginService = $createService(baseUrl: '/relative');
            try {
                $invalidOriginService->authorizeUpload(
                    $context,
                    $snapshot,
                    new StudioMediaUploadRequest(
                        'invalid-origin.jpg',
                        'image/jpeg',
                        strlen($bytes),
                        'studio.media/content',
                    ),
                );
                self::fail('A relative upload grant origin must be refused.');
            } catch (\RuntimeException $failure) {
                self::assertSame('The Studio media grant origin is invalid.', $failure->getMessage());
            }
            self::assertSame([], glob($root . '/staging/*.upload'));

            $portGrant = $createService(baseUrl: 'https://app.example.invalid:8443')->authorizeUpload(
                $context,
                $snapshot,
                new StudioMediaUploadRequest(
                    'port-origin.jpg',
                    'image/jpeg',
                    strlen($bytes),
                    'studio.media/content',
                ),
            );
            $portGrantUrl = $portGrant->url ?? null;
            self::assertIsString($portGrantUrl);
            self::assertStringStartsWith(
                'https://app.example.invalid:8443/administrator/studio/media/uploads/',
                $portGrantUrl,
            );
            $portUploadId = self::uploadId($portGrant);
            $portStagingPath = $staging->path($portUploadId);
            self::assertFileExists($portStagingPath);
            $service->abortUpload($context, $snapshot, $portUploadId);
            self::assertFileDoesNotExist($portStagingPath);

            $grant = $service->authorizeUpload($context, $snapshot, new StudioMediaUploadRequest(
                'direct.jpg',
                'image/jpeg',
                strlen($bytes),
                'studio.media/content',
            ));
            self::assertIsString($grant->uploadId);
            self::assertInstanceOf(stdClass::class, $grant->headers);
            self::assertIsString($grant->headers->{'X-Studio-Upload-Token'});
            self::assertRejected(
                static fn (): stdClass => $service->replayUploadGrant(
                    $context,
                    $snapshot,
                    (object) ['uploadId' => $grant->uploadId, 'headers' => []],
                ),
                'unavailable',
                'studio.media/idempotency-corrupt',
            );
            self::assertRejected(
                static fn (): stdClass => $service->replayUploadGrant(
                    $context,
                    $snapshot,
                    (object) [
                        'uploadId' => 'uploads/00000000000000000000000000000000',
                        'headers' => new stdClass(),
                    ],
                ),
                'not-found',
                'studio.media/upload-not-found',
            );
            $storedGrant = clone $grant;
            $storedGrant->headers = clone $grant->headers;
            unset($storedGrant->headers->{'X-Studio-Upload-Token'});
            $replayedGrant = $service->replayUploadGrant($context, $snapshot, $storedGrant);
            $replayedHeaders = $replayedGrant->headers ?? null;
            self::assertInstanceOf(stdClass::class, $replayedHeaders);
            $replayedToken = $replayedHeaders->{'X-Studio-Upload-Token'} ?? null;
            self::assertIsString($replayedToken);
            self::assertSame(
                $grant->headers->{'X-Studio-Upload-Token'},
                $replayedToken,
            );

            self::assertRejected(
                static function () use ($bytes, $context, $service): void {
                    $service->receive(
                        $context,
                        'uploads/00000000000000000000000000000000',
                        'contexts/media-lifecycle',
                        'generation-lifecycle',
                        'unknown-token',
                        'image/jpeg',
                        (new StreamFactory())->createStream($bytes),
                    );
                },
                'not-found',
                'studio.media/upload-not-found',
            );
            self::assertRejected(
                static function () use ($bytes, $context, $grant, $service): void {
                    $service->receive(
                        $context,
                        $grant->uploadId,
                        'contexts/media-lifecycle',
                        'generation-lifecycle',
                        '',
                        'image/jpeg',
                        (new StreamFactory())->createStream($bytes),
                    );
                },
                'not-found',
                'studio.media/upload-not-found',
            );
            $service->receive(
                $context,
                $grant->uploadId,
                'contexts/media-lifecycle',
                'generation-lifecycle',
                $grant->headers->{'X-Studio-Upload-Token'},
                'image/jpeg',
                (new StreamFactory())->createStream($bytes),
            );
            $completed = $service->completeUpload($context, $snapshot, $grant->uploadId);
            self::assertIsString($completed->id);
            self::assertSame('ready', $completed->state);
            self::assertInstanceOf(stdClass::class, $service->get($context, $completed->id));
            self::assertSame($completed->id, $service->uploadStatus($context, $completed->id)->id);
            self::assertNull($service->get($context, '00000000-0000-4000-8000-999999999999'));
            self::assertRejected(
                static fn (): stdClass => $service->uploadStatus(
                    $context,
                    '00000000-0000-4000-8000-999999999999',
                ),
                'not-found',
                'studio.media/asset-not-found',
            );
            self::assertEquals($completed, $service->completeUpload($context, $snapshot, $grant->uploadId));
            $service->abortUpload($context, $snapshot, $grant->uploadId);

            $authorize = static fn (
                string $filename,
                int $byteSize,
                ?string $checksum = null,
            ): stdClass => $service->authorizeUpload(
                $context,
                $snapshot,
                new StudioMediaUploadRequest(
                    $filename,
                    'image/jpeg',
                    $byteSize,
                    'studio.media/content',
                    $checksum,
                ),
            );
            $receive = static function (stdClass $uploadGrant, string $body) use ($context, $service): void {
                $headers = $uploadGrant->headers ?? null;
                self::assertInstanceOf(stdClass::class, $headers);
                $token = $headers->{'X-Studio-Upload-Token'} ?? null;
                self::assertIsString($token);
                $service->receive(
                    $context,
                    self::uploadId($uploadGrant),
                    'contexts/media-lifecycle',
                    'generation-lifecycle',
                    $token,
                    'image/jpeg',
                    (new StreamFactory())->createStream($body),
                );
            };

            $transferConflict = $authorize('transfer-conflict.jpg', strlen($bytes));
            $state->saveRejection = static fn (StudioMediaUploadSession $candidate, int $expected): bool =>
                $candidate->state === StudioMediaUploadState::Transferring;
            self::assertRejected(
                static function () use ($bytes, $receive, $transferConflict): void {
                    $receive($transferConflict, $bytes);
                },
                'conflict',
                'studio.media/upload-concurrent',
            );

            $failedStateConflict = $authorize('failed-state-conflict.jpg', strlen($bytes) + 1);
            $state->saveRejection = static fn (StudioMediaUploadSession $candidate, int $expected): bool =>
                $candidate->state === StudioMediaUploadState::Failed;
            self::assertRejected(
                static function () use ($bytes, $failedStateConflict, $receive): void {
                    $receive($failedStateConflict, $bytes);
                },
                'conflict',
                'studio.media/upload-concurrent',
            );
            $service->abortUpload($context, $snapshot, self::uploadId($failedStateConflict));

            $verifyingStateConflict = $authorize('verifying-state-conflict.jpg', strlen($bytes));
            $state->saveRejection = static fn (StudioMediaUploadSession $candidate, int $expected): bool =>
                $candidate->state === StudioMediaUploadState::Verifying;
            self::assertRejected(
                static function () use ($bytes, $receive, $verifyingStateConflict): void {
                    $receive($verifyingStateConflict, $bytes);
                },
                'conflict',
                'studio.media/upload-concurrent',
            );
            $service->abortUpload($context, $snapshot, self::uploadId($verifyingStateConflict));

            $sizeMismatch = $authorize('size-mismatch.jpg', strlen($bytes) + 1);
            self::assertRejected(
                static function () use ($bytes, $receive, $sizeMismatch): void {
                    $receive($sizeMismatch, $bytes);
                },
                'validation-failed',
                'studio.media/upload-size-mismatch',
                true,
            );
            self::assertRejected(
                static fn (): null => $service->abortUpload(
                    $context,
                    $snapshot,
                    self::uploadId($sizeMismatch),
                ),
                'not-found',
                'studio.media/upload-not-found',
            );

            $expiredGrant = $authorize('expired.jpg', strlen($bytes));
            $expiredUploadId = self::uploadId($expiredGrant);
            $now = $state->sessions[$expiredUploadId]->expiresAt;
            self::assertRejected(
                static function () use ($bytes, $expiredGrant, $receive): void {
                    $receive($expiredGrant, $bytes);
                },
                'not-found',
                'studio.media/upload-not-found',
            );
            $now = new DateTimeImmutable('2026-08-24T12:00:00+00:00');
            $service->abortUpload($context, $snapshot, $expiredUploadId);

            $abortConflict = $authorize('abort-conflict.jpg', strlen($bytes));
            $state->saveRejection = static fn (StudioMediaUploadSession $candidate, int $expected): bool =>
                $candidate->state === StudioMediaUploadState::Cancelled;
            self::assertRejected(
                static fn (): null => $service->abortUpload(
                    $context,
                    $snapshot,
                    self::uploadId($abortConflict),
                ),
                'conflict',
                'studio.media/upload-concurrent',
            );
            $service->abortUpload($context, $snapshot, self::uploadId($abortConflict));

            $notVerifying = $authorize('not-verifying.jpg', strlen($bytes));
            self::assertRejected(
                static fn (): stdClass => $service->completeUpload(
                    $context,
                    $snapshot,
                    self::uploadId($notVerifying),
                ),
                'not-found',
                'studio.media/upload-not-found',
            );
            $service->abortUpload($context, $snapshot, self::uploadId($notVerifying));

            $completionClaimConflict = $authorize('completion-claim-conflict.jpg', strlen($bytes));
            $receive($completionClaimConflict, $bytes);
            $state->saveRejection = static fn (StudioMediaUploadSession $candidate, int $expected): bool =>
                $candidate->state === StudioMediaUploadState::Verifying;
            self::assertRejected(
                static fn (): stdClass => $service->completeUpload(
                    $context,
                    $snapshot,
                    self::uploadId($completionClaimConflict),
                ),
                'conflict',
                'studio.media/upload-concurrent',
            );
            $service->abortUpload($context, $snapshot, self::uploadId($completionClaimConflict));

            $verificationSaveConflict = $authorize('verification-save-conflict.jpg', strlen($bytes));
            $receive($verificationSaveConflict, $bytes);
            $state->detectedMediaType = null;
            $state->saveRejection = static fn (StudioMediaUploadSession $candidate, int $expected): bool =>
                $candidate->state === StudioMediaUploadState::Failed;
            self::assertRejected(
                static fn (): stdClass => $service->completeUpload(
                    $context,
                    $snapshot,
                    self::uploadId($verificationSaveConflict),
                ),
                'conflict',
                'studio.media/upload-concurrent',
            );
            $state->detectedMediaType = 'image/jpeg';
            $service->abortUpload($context, $snapshot, self::uploadId($verificationSaveConflict));

            $verificationFailure = $authorize('verification-failure.jpg', strlen($bytes));
            $receive($verificationFailure, $bytes);
            $state->detectedMediaType = null;
            self::assertRejected(
                static fn (): stdClass => $service->completeUpload(
                    $context,
                    $snapshot,
                    self::uploadId($verificationFailure),
                ),
                'validation-failed',
                'studio.media/upload-verification-failed',
                true,
            );
            $state->detectedMediaType = 'image/jpeg';

            $mismatchedChecksum = 'sha256-' . base64_encode(str_repeat("\0", 32));
            $checksumFailure = $authorize('checksum-failure.jpg', strlen($bytes), $mismatchedChecksum);
            $receive($checksumFailure, $bytes);
            self::assertRejected(
                static fn (): stdClass => $service->completeUpload(
                    $context,
                    $snapshot,
                    self::uploadId($checksumFailure),
                ),
                'validation-failed',
                'studio.media/upload-verification-failed',
                true,
            );

            $matchingChecksum = 'sha256-' . base64_encode(hash('sha256', $bytes, true));
            $checksumSuccess = $authorize('checksum-success.jpg', strlen($bytes), $matchingChecksum);
            $receive($checksumSuccess, $bytes);
            self::assertSame(
                'ready',
                $service->completeUpload($context, $snapshot, self::uploadId($checksumSuccess))->state,
            );

            $completionSaveConflict = $authorize('completion-save-conflict.jpg', strlen($bytes));
            $receive($completionSaveConflict, $bytes);
            $state->saveRejection = static fn (StudioMediaUploadSession $candidate, int $expected): bool =>
                $candidate->state === StudioMediaUploadState::Complete;
            self::assertRejected(
                static fn (): stdClass => $service->completeUpload(
                    $context,
                    $snapshot,
                    self::uploadId($completionSaveConflict),
                ),
                'conflict',
                'studio.media/upload-concurrent',
            );
            $service->abortUpload($context, $snapshot, self::uploadId($completionSaveConflict));

            $cancelGrant = $service->authorizeUpload($context, $snapshot, new StudioMediaUploadRequest(
                'cancelled.jpg',
                'image/jpeg',
                strlen($bytes),
                'studio.media/content',
            ));
            self::assertIsString($cancelGrant->uploadId);
            $service->abortUpload($context, $snapshot, $cancelGrant->uploadId);
            $imported = $service->importExternal($context, 'https://cdn.example/photo.jpg');
            self::assertIsString($imported->id);

            $firstPage = $service->list($context, (object) ['limit' => 1]);
            self::assertIsArray($firstPage->assets);
            self::assertCount(1, $firstPage->assets);
            self::assertIsString($firstPage->nextCursor);
            $secondPage = $service->list($context, (object) [
                'cursor' => $firstPage->nextCursor,
                'limit' => 1,
            ]);
            self::assertIsArray($secondPage->assets);
            self::assertCount(1, $secondPage->assets);
            $filteredPage = $service->list($context, (object) [
                'limit' => 100,
                'mediaTypes' => ['image/png', 'image/jpeg'],
                'search' => ' DIRECT ',
            ]);
            $filteredAssets = $filteredPage->assets ?? null;
            self::assertIsArray($filteredAssets);
            self::assertCount(1, $filteredAssets);
            $filteredAsset = $filteredAssets[0] ?? null;
            self::assertInstanceOf(stdClass::class, $filteredAsset);
            self::assertSame('direct.jpg', $filteredAsset->filename);
            self::assertObjectNotHasProperty('nextCursor', $filteredPage);
            self::assertRejected(
                static fn (): stdClass => $service->list($context, (object) [
                    'limit' => 1,
                    'unknown' => true,
                ]),
                'invalid-request',
                'studio.media/query-invalid',
            );
            self::assertRejected(
                static fn (): stdClass => $service->list($context, (object) [
                    'limit' => 1,
                    'search' => str_repeat('a', 201),
                ]),
                'invalid-request',
                'studio.media/query-invalid',
            );
            self::assertRejected(
                static fn (): stdClass => $service->list($context, (object) [
                    'limit' => 1,
                    'mediaTypes' => array_fill(0, 51, 'image/jpeg'),
                ]),
                'invalid-request',
                'studio.media/query-invalid',
            );
            self::assertRejected(
                static fn (): stdClass => $service->list($context, (object) [
                    'limit' => 1,
                    'mediaTypes' => ['image/jpeg', 'image/jpeg'],
                ]),
                'invalid-request',
                'studio.media/query-invalid',
            );

            $actions = array_map(static fn (AuditEvent $event): string => $event->action(), $events);
            foreach (
                [
                'studio.media.authorize',
                'studio.media.transfer',
                'studio.media.complete',
                'studio.media.abort',
                'studio.media.import',
                ] as $action
            ) {
                self::assertContains($action, $actions);
            }
        } finally {
            $stagedPaths = glob($root . '/staging/*');
            foreach (is_array($stagedPaths) ? $stagedPaths : [] as $path) {
                @unlink($path);
            }
            $paths = glob($root . '/*');
            foreach (is_array($paths) ? array_reverse($paths) : [] as $path) {
                if (is_dir($path)) {
                    @rmdir($path);
                } else {
                    @unlink($path);
                }
            }
            foreach ($assetPaths as $path) {
                @unlink($path);
            }
            @rmdir($root . '/staging');
            @rmdir($root);
        }
    }

    /**
     * Assert one public media operation returns only its stable delivery-safe refusal.
     *
     * @param   callable(): mixed  $operation     Public operation expected to refuse.
     * @param   string             $category      Expected closed host-error category.
     * @param   string             $failureCode   Expected stable diagnostic code.
     * @param   bool               $commitsState  Whether the refusal must preserve a committed lifecycle state.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    private static function assertRejected(
        callable $operation,
        string $category,
        string $failureCode,
        bool $commitsState = false,
    ): void {
        try {
            $operation();
        } catch (StudioMediaPortRejected $failure) {
            self::assertSame($category, $failure->category);
            self::assertSame($failureCode, $failure->failureCode);
            self::assertSame($commitsState, $failure->commitsState);

            return;
        }

        self::fail('The Studio media operation did not return its expected refusal.');
    }

    /**
     * Extract the typed opaque identity from one canonical upload-grant document.
     *
     * @param   stdClass  $grant  Upload-grant document returned by the public operation.
     *
     * @return  string  Validated opaque upload identity.
     *
     * @since  2.0.0
     */
    private static function uploadId(stdClass $grant): string
    {
        $uploadId = $grant->uploadId ?? null;
        self::assertIsString($uploadId);

        return $uploadId;
    }
}
