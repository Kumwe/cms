<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Media;

use DateTimeImmutable;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Media\Application\MediaService;
use Kumwe\App\Media\Application\MediaStorage;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Application\Media\StudioExternalAddressResolver;
use Kumwe\App\Studio\Application\Media\StudioExternalMediaFetcher;
use Kumwe\App\Studio\Application\Media\StudioMediaAssetProjector;
use Kumwe\App\Studio\Application\Media\StudioMediaCursorCodec;
use Kumwe\App\Studio\Application\Media\StudioMediaGrantToken;
use Kumwe\App\Studio\Application\Media\StudioMediaService;
use Kumwe\App\Studio\Application\Media\StudioMediaSignatureVerifier;
use Kumwe\App\Studio\Application\Media\StudioMediaStagingStorage;
use Kumwe\App\Studio\Application\Media\StudioMediaUploadRepository;
use Kumwe\App\Studio\Application\Media\StudioPinnedHttpTransport;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\App\Studio\Domain\Media\StudioExternalUrlPolicy;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadPolicy;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadRequest;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use stdClass;

/**
 * Verifies Studio upload lifecycle auditing is transactional and carries only explicit safe metadata.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioMediaService::class)]
final class StudioMediaAuditTest extends TestCase
{
    /**
     * Audit authorization without retaining filename, context, generation, URL, path or capability.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testAuthorizeUploadRecordsOnlySafeLifecycleMetadata(): void
    {
        $event = null;
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::once())->method('record')->willReturnCallback(
            static function (AuditEvent $record) use (&$event): void {
                $event = $record;
            },
        );
        $transactions = self::createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $media = new MediaService(
            self::createStub(MediaStorage::class),
            AuthorizationContext::gateway(),
            $audit,
            self::clock(),
            10_000,
            $transactions,
        );
        $signatures = self::createStub(StudioMediaSignatureVerifier::class);
        $service = new StudioMediaService(
            $media,
            self::createStub(StudioMediaUploadRepository::class),
            self::createStub(StudioMediaStagingStorage::class),
            new StudioMediaUploadPolicy(['image/jpeg'], 10_000, false),
            $signatures,
            new StudioExternalMediaFetcher(
                new StudioExternalUrlPolicy(),
                self::createStub(StudioExternalAddressResolver::class),
                self::createStub(StudioPinnedHttpTransport::class),
                $signatures,
                ['image/jpeg'],
                10_000,
            ),
            new StudioMediaAssetProjector(),
            new StudioMediaCursorCodec(str_repeat('c', 32)),
            new StudioMediaGrantToken(str_repeat('g', 32)),
            $transactions,
            $audit,
            self::clock(),
            'https://app.example.invalid',
        );
        $context = self::context();
        $snapshot = self::snapshot($context);

        $grant = $service->authorizeUpload(
            $context,
            $snapshot,
            new StudioMediaUploadRequest(
                'sensitive filename.jpg',
                'image/jpeg',
                512,
                'studio.media/content',
            ),
        );

        self::assertInstanceOf(AuditEvent::class, $event);
        self::assertSame('studio.media.authorize', $event->action());
        self::assertSame('studio_media', $event->subjectType());
        self::assertSame('success', $event->outcome());
        self::assertSame([
            'byte_size' => 512,
            'context_digest' => hash('sha256', 'contexts/media-audit'),
            'generation_digest' => hash('sha256', 'generation-audit'),
            'media_type' => 'image/jpeg',
            'resumable' => false,
        ], $event->metadata());
        $encoded = $event->metadataAsJson();
        self::assertIsString($grant->url);
        self::assertInstanceOf(stdClass::class, $grant->headers);
        self::assertIsString($grant->headers->{'X-Studio-Upload-Token'});
        self::assertStringNotContainsString('sensitive filename', $encoded);
        self::assertStringNotContainsString('contexts/media-audit', $encoded);
        self::assertStringNotContainsString('generation-audit', $encoded);
        self::assertStringNotContainsString($grant->url, $encoded);
        self::assertStringNotContainsString($grant->headers->{'X-Studio-Upload-Token'}, $encoded);
    }

    /**
     * Mint a trusted administrator execution context for lifecycle auditing.
     *
     * @return  ExecutionContext  Authenticated context.
     *
     * @since  2.0.0
     */
    private static function context(): ExecutionContext
    {
        return AuthenticatedPrincipal::issueFromStrings(
            AuthorizationContext::provenance(),
            AuthorizationContext::SUBJECT,
            ['content.read', 'content.update'],
        )->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'studio-media-audit',
            surface: AuthenticatedSurface::Administrator,
            sessionId: 'administrator-session-media-audit',
        );
    }

    /**
     * Bind the audit test to one exact live Studio resource scope.
     *
     * @param   ExecutionContext  $context  Trusted App context.
     *
     * @return  StudioHostSessionSnapshot  Matching live host authority.
     *
     * @since  2.0.0
     */
    private static function snapshot(ExecutionContext $context): StudioHostSessionSnapshot
    {
        return new StudioHostSessionSnapshot(
            new StudioHostSession(
                'contexts/media-audit',
                $context->actorId(),
                $context->site()->identifier(),
                null,
                null,
                'administrator',
                str_repeat('a', 64),
                StudioSessionMode::Content,
                StudioResourceKind::Content,
                'content-audit',
                'generation-audit',
            ),
            ['studio.permission/read', 'studio.permission/upload-media'],
            'generation-audit',
            true,
            false,
            false,
        );
    }

    /**
     * Return the stable instant shared by the session and its audit event.
     *
     * @return  ClockInterface  Fixed test clock.
     *
     * @since  2.0.0
     */
    private static function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            /**
             * Return one fixed UTC instant.
             *
             * @return  DateTimeImmutable  Stable instant.
             *
             * @since  2.0.0
             */
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-08-24T12:00:00+00:00');
            }
        };
    }
}
