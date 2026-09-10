<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Administrator\Http\Handler;

use Kumwe\App\Administrator\Http\Handler\AdministratorStudioMediaUploadHandler;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Application\Host\StudioHostSessionRepository;
use Kumwe\App\Studio\Application\Host\StudioResourceContextKeyFactory;
use Kumwe\App\Studio\Application\Media\StudioMediaOperations;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\SiteContext;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the direct upload route to fresh session authority and its one-time media operation.
 *
 * @since  2.0.0
 */
#[CoversClass(AdministratorStudioMediaUploadHandler::class)]
final class AdministratorStudioMediaUploadHandlerTest extends TestCase
{
    /**
     * A matching live context reaches binary custody while malformed opaque identities reveal nothing.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testBinaryGrantIsBoundToTheFreshAdministratorSession(): void
    {
        $stored = null;
        $repository = self::createStub(StudioHostSessionRepository::class);
        $repository->method('add')->willReturnCallback(
            static function (StudioHostSession $session) use (&$stored): void {
                $stored = $session;
            },
        );
        $repository->method('find')->willReturnCallback(
            static function (string $key) use (&$stored): ?StudioHostSession {
                return $stored instanceof StudioHostSession && $stored->resourceContextKey === $key
                    ? $stored
                    : null;
            },
        );
        $keys = self::createStub(StudioResourceContextKeyFactory::class);
        $keys->method('create')->willReturn('contexts/upload-handler');
        $authority = new StudioHostSessionAuthority(
            AuthorizationContext::gateway(),
            $repository,
            $keys,
        );
        $context = AuthorizationContext::principal([
            'content.read',
            'content.update',
            'studio.mode.content',
        ])->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'studio-upload-handler',
            surface: AuthenticatedSurface::Administrator,
            sessionId: 'administrator-studio-upload-handler',
        );
        $snapshot = $authority->open(
            $context,
            StudioSessionMode::Content,
            StudioResourceKind::Content,
            'content-handler',
        );
        $media = $this->createMock(StudioMediaOperations::class);
        $media->expects(self::once())->method('receive')->with(
            $context,
            'uploads/0123456789abcdef0123456789abcdef',
            'contexts/upload-handler',
            $snapshot->generation,
            'one-time-token',
            'image/jpeg',
            self::anything(),
        );
        $handler = new AdministratorStudioMediaUploadHandler($media, $authority);
        $request = (new ServerRequestFactory())
            ->createServerRequest('PUT', 'https://app.example.invalid/administrator/studio/media/uploads/x')
            ->withAttribute(ExecutionContextAttribute::NAME, $context)
            ->withAttribute('upload', '0123456789abcdef0123456789abcdef')
            ->withHeader('Content-Type', 'image/jpeg')
            ->withHeader('X-Studio-Resource-Context', 'contexts/upload-handler')
            ->withHeader('X-Studio-Session-Generation', $snapshot->generation)
            ->withHeader('X-Studio-Upload-Token', 'one-time-token');

        self::assertSame(204, $handler->handle($request)->getStatusCode());
        self::assertSame(404, $handler->handle($request->withAttribute('upload', '../secret'))->getStatusCode());
    }
}
