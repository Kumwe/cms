<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Administrator\Http\Handler;

use Kumwe\App\Administrator\Http\Handler\AdministratorStudioSessionHandler;
use Kumwe\App\Application\Authorization\AuthenticatedSurface;
use Kumwe\App\Application\Authorization\AuthenticationStrength;
use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\App\Application\Authorization\SiteContext;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Application\Host\StudioHostSessionRepository;
use Kumwe\App\Studio\Application\Host\StudioResourceContextKeyFactory;
use Kumwe\App\Studio\Application\Preview\StudioPreviewSequenceRepository;
use Kumwe\App\Studio\Application\Preview\StudioPreviewSequenceWaiter;
use Kumwe\App\Studio\Application\Preview\StudioPreviewTransportGuard;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Pins the private administrator session projection to target-specific live lifecycle authority.
 *
 * @since  2.0.0
 */
#[CoversClass(AdministratorStudioSessionHandler::class)]
final class AdministratorStudioSessionHandlerTest extends TestCase
{
    /**
     * An unpublish-only actor receives one compatible protocol permission and only the truthful private target.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSessionProjectionKeepsPublishAndUnpublishAuthorityDistinct(): void
    {
        $sessions = self::createStub(StudioHostSessionRepository::class);
        $keys = self::createStub(StudioResourceContextKeyFactory::class);
        $keys->method('create')->willReturn('contexts/session-handler');
        $authority = new StudioHostSessionAuthority(AuthorizationContext::gateway(), $sessions, $keys);
        $context = AuthorizationContext::principal([
            'content.unpublish',
            'studio.mode.blueprint',
        ])->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'studio-session-handler',
            surface: AuthenticatedSurface::Administrator,
            sessionId: 'administrator-studio-session-handler',
        );
        $preview = new StudioPreviewTransportGuard(
            'https://kumwe.test',
            self::createStub(StudioPreviewSequenceRepository::class),
            self::createStub(StudioPreviewSequenceWaiter::class),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/administrator/studio/session')
            ->withAttribute(ExecutionContext::REQUEST_ATTRIBUTE, $context)
            ->withBody((new StreamFactory())->createStream(json_encode([
                'mode' => 'blueprint',
                'resourceId' => 'blueprints/session-handler',
                'resourceKind' => 'blueprint',
            ], JSON_THROW_ON_ERROR)));

        $response = (new AdministratorStudioSessionHandler($authority, $preview))->handle($request);
        $document = json_decode((string) $response->getBody(), false, 32, JSON_THROW_ON_ERROR);

        self::assertSame(201, $response->getStatusCode());
        self::assertInstanceOf(stdClass::class, $document);
        self::assertInstanceOf(stdClass::class, $document->lifecycle);
        self::assertIsArray($document->permissions);
        self::assertIsString($document->sessionGeneration);
        self::assertEquals((object) ['canPublish' => false, 'canUnpublish' => true], $document->lifecycle);
        self::assertContains('studio.permission/publish', $document->permissions);
        self::assertStringStartsWith('session-', $document->sessionGeneration);
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    /**
     * A body outside the closed open grammar is refused canonically without disclosure.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    /**
     * The public open endpoint never mints a contextual Content authoring session; only the editor mount may.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAContentAuthoringKindIsRefusedBeforeTheAuthorityIsConsulted(): void
    {
        $sessions = self::createMock(StudioHostSessionRepository::class);
        $sessions->expects(self::never())->method('add');
        $keys = self::createStub(StudioResourceContextKeyFactory::class);
        $keys->method('create')->willReturn('contexts/session-handler-authoring');
        $authority = new StudioHostSessionAuthority(AuthorizationContext::gateway(), $sessions, $keys);
        $preview = new StudioPreviewTransportGuard(
            'https://kumwe.test',
            self::createStub(StudioPreviewSequenceRepository::class),
            self::createStub(StudioPreviewSequenceWaiter::class),
        );
        $handler = new AdministratorStudioSessionHandler($authority, $preview);
        $context = AuthorizationContext::principal(['content.read', 'content.update', 'studio.mode.hybrid'])->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'studio-session-handler-authoring',
            surface: AuthenticatedSurface::Administrator,
            sessionId: 'administrator-studio-session-authoring',
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/administrator/studio/session')
            ->withAttribute(ExecutionContext::REQUEST_ATTRIBUTE, $context)
            ->withBody((new StreamFactory())->createStream(json_encode([
                'mode' => 'hybrid',
                'resourceId' => 'contexts/' . str_repeat('a', 64),
                'resourceKind' => 'content-authoring',
            ], JSON_THROW_ON_ERROR)));

        $response = $handler->handle($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertJson((string) $response->getBody());
        self::assertStringContainsString('studio.host/invalid-request', (string) $response->getBody());
    }

    /**
     * Every body outside the closed open grammar is refused as an invalid request.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testABodyOutsideTheClosedGrammarIsRefused(): void
    {
        $sessions = self::createStub(StudioHostSessionRepository::class);
        $keys = self::createStub(StudioResourceContextKeyFactory::class);
        $keys->method('create')->willReturn('contexts/session-handler-refusal');
        $authority = new StudioHostSessionAuthority(AuthorizationContext::gateway(), $sessions, $keys);
        $preview = new StudioPreviewTransportGuard(
            'https://kumwe.test',
            self::createStub(StudioPreviewSequenceRepository::class),
            self::createStub(StudioPreviewSequenceWaiter::class),
        );
        $handler = new AdministratorStudioSessionHandler($authority, $preview);
        $context = AuthorizationContext::principal(['content.read', 'studio.mode.content'])->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'studio-session-handler-refusal',
            surface: AuthenticatedSurface::Administrator,
            sessionId: 'administrator-studio-session-refusal',
        );
        $bodies = [
            'not json at all',
            json_encode(['mode' => 'content'], JSON_THROW_ON_ERROR),
            json_encode(['mode' => 1, 'resourceId' => 'x', 'resourceKind' => 'content'], JSON_THROW_ON_ERROR),
            json_encode([
                'mode' => 'hostile',
                'resourceId' => 'contents/refused',
                'resourceKind' => 'content',
            ], JSON_THROW_ON_ERROR),
        ];
        foreach ($bodies as $body) {
            $request = (new ServerRequestFactory())
                ->createServerRequest('POST', 'https://kumwe.test/administrator/studio/session')
                ->withAttribute(ExecutionContext::REQUEST_ATTRIBUTE, $context)
                ->withBody((new StreamFactory())->createStream($body));
            $response = $handler->handle($request);
            self::assertSame(400, $response->getStatusCode(), $body);
            self::assertJson((string) $response->getBody());
        }
    }

    /**
     * A well-formed request for a mode the live authority withholds is refused as forbidden.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAModeTheLiveAuthorityWithholdsIsRefusedAsForbidden(): void
    {
        $sessions = self::createStub(StudioHostSessionRepository::class);
        $keys = self::createStub(StudioResourceContextKeyFactory::class);
        $keys->method('create')->willReturn('contexts/session-handler-forbidden');
        $authority = new StudioHostSessionAuthority(AuthorizationContext::gateway(), $sessions, $keys);
        $preview = new StudioPreviewTransportGuard(
            'https://kumwe.test',
            self::createStub(StudioPreviewSequenceRepository::class),
            self::createStub(StudioPreviewSequenceWaiter::class),
        );
        $context = AuthorizationContext::principal(['content.read'])->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'studio-session-handler-forbidden',
            surface: AuthenticatedSurface::Administrator,
            sessionId: 'administrator-studio-session-forbidden',
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/administrator/studio/session')
            ->withAttribute(ExecutionContext::REQUEST_ATTRIBUTE, $context)
            ->withBody((new StreamFactory())->createStream(json_encode([
                'mode' => 'content',
                'resourceId' => 'contents/forbidden',
                'resourceKind' => 'content',
            ], JSON_THROW_ON_ERROR)));

        $response = (new AdministratorStudioSessionHandler($authority, $preview))->handle($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertJson((string) $response->getBody());
    }
}
