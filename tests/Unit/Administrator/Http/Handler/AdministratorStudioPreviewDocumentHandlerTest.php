<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Administrator\Http\Handler;

use DateTimeImmutable;
use Kumwe\App\Administrator\Http\Handler\AdministratorStudioPreviewDocumentHandler;
use Kumwe\App\Administrator\Http\Handler\AdministratorStudioPreviewStylesheetHandler;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Application\Host\StudioHostSessionRepository;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Application\Host\StudioResourceContextKeyFactory;
use Kumwe\App\Studio\Application\Preview\StudioPreviewDocumentClaimer;
use Kumwe\App\Studio\Application\Preview\StudioPreviewStylesheet;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewGrant;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewRenderedDocument;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewRenderRequest;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewTransport;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;

/**
 * Proves the authenticated preview document is single-use application output with hardened delivery headers.
 *
 * @since  2.0.0
 */
#[CoversClass(AdministratorStudioPreviewDocumentHandler::class)]
#[CoversClass(AdministratorStudioPreviewStylesheetHandler::class)]
#[CoversClass(StudioPreviewStylesheet::class)]
final class AdministratorStudioPreviewDocumentHandlerTest extends TestCase
{
    /**
     * The handler re-resolves live authority and claims only the exact same-origin document coordinates.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLiveSingleUseGrantIsDeliveredWithNoStoreFrameHardening(): void
    {
        [$authority, $context] = $this->authority();
        $snapshot = $authority->open(
            $context,
            StudioSessionMode::Blueprint,
            StudioResourceKind::Blueprint,
            'blueprints/document-test',
        );
        $requestId = 'renders/document-test';
        $channel = 'channels/document-test';
        $source = 'sources/document-test';
        $preview = $this->createMock(StudioPreviewDocumentClaimer::class);
        $preview->expects(self::once())
            ->method('claimDocument')
            ->with(
                self::identicalTo($context),
                self::callback(static fn (StudioHostSessionSnapshot $live): bool =>
                    $live->generation === $snapshot->generation),
                $requestId,
                self::callback(static fn (StudioPreviewTransport $transport): bool =>
                    $transport->origin === 'https://kumwe.test'
                    && $transport->channelId === $channel
                    && $transport->sourceId === $source
                    && $transport->sequence === 0),
            )
            ->willReturn(new StudioPreviewGrant(
                $snapshot->session->resourceContextKey,
                $snapshot->session->actorId,
                $snapshot->session->siteId,
                null,
                null,
                $snapshot->session->sessionBinding,
                $snapshot->generation,
                'https://kumwe.test',
                $channel,
                $source,
                new StudioPreviewRenderRequest(
                    'blueprints/document-test',
                    str_repeat('a', 64),
                    'draft-r1',
                    $requestId,
                    'expanded',
                ),
                new StudioPreviewRenderedDocument(
                    '<!doctype html><head><link rel="stylesheet" href="'
                        . StudioPreviewStylesheet::HREF_PLACEHOLDER
                        . '" data-studio-composition></head>',
                    [],
                    [],
                    [],
                    'body{--site-accent:#0c9189;}',
                ),
                new DateTimeImmutable('2026-08-24T12:01:00+00:00'),
            ));
        $query = [
            'context' => $snapshot->session->resourceContextKey,
            'render' => $requestId,
            'generation' => $snapshot->generation,
            'channel' => $channel,
            'source' => $source,
            'sequence' => '0',
        ];
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/administrator/studio/preview')
            ->withQueryParams($query)
            ->withHeader('Referer', 'https://kumwe.test/administrator/content/entry/edit')
            ->withAttribute(ExecutionContextAttribute::NAME, $context);

        $response = (new AdministratorStudioPreviewDocumentHandler($authority, $preview))->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertStringNotContainsString(StudioPreviewStylesheet::HREF_PLACEHOLDER, $html);
        self::assertStringContainsString(
            'href="/administrator/studio/preview/styles.css?',
            $html,
        );
        self::assertStringContainsString('render=renders%2Fdocument-test', $html);
        self::assertStringNotContainsString('<style', $html);
        self::assertSame('no-store, private', $response->getHeaderLine('Cache-Control'));
        self::assertSame('same-origin', $response->getHeaderLine('Cross-Origin-Resource-Policy'));
        self::assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
    }

    /**
     * The combined Producer/theme subresource revalidates the grant without consuming a protocol sequence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testClaimedStylesheetIsDeliveredFromTheSameOriginWithoutInlinePolicy(): void
    {
        [$authority, $context] = $this->authority();
        $snapshot = $authority->open(
            $context,
            StudioSessionMode::Blueprint,
            StudioResourceKind::Blueprint,
            'blueprints/document-test',
        );
        $requestId = 'renders/document-test';
        $channel = 'channels/document-test';
        $source = 'sources/document-test';
        $preview = $this->createMock(StudioPreviewDocumentClaimer::class);
        $preview->expects(self::once())
            ->method('stylesheet')
            ->with(
                self::identicalTo($context),
                self::callback(static fn (StudioHostSessionSnapshot $live): bool =>
                    $live->generation === $snapshot->generation),
                $requestId,
                self::callback(static fn (StudioPreviewTransport $transport): bool =>
                    $transport->origin === 'https://kumwe.test'
                    && $transport->channelId === $channel
                    && $transport->sourceId === $source
                    && $transport->sequence === 0),
            )
            ->willReturn('body{--site-accent:#0c9189;}');
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/administrator/studio/preview/styles.css')
            ->withQueryParams([
                'context' => $snapshot->session->resourceContextKey,
                'render' => $requestId,
                'generation' => $snapshot->generation,
                'channel' => $channel,
                'source' => $source,
            ])
            ->withAttribute(ExecutionContextAttribute::NAME, $context);

        $response = (new AdministratorStudioPreviewStylesheetHandler($authority, $preview))->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('body{--site-accent:#0c9189;}', (string) $response->getBody());
        self::assertSame('text/css; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('same-origin', $response->getHeaderLine('Cross-Origin-Resource-Policy'));
    }

    /**
     * Compose a real authority over deterministic in-memory host-session boundaries.
     *
     * @return  array{StudioHostSessionAuthority, ExecutionContext}  Authority and administrator context.
     *
     * @since   2.0.0
     */
    private function authority(): array
    {
        $sessions = new class implements StudioHostSessionRepository {
            /**
             * Retained session by its opaque key.
             *
             * @var    StudioHostSession|null
             * @since  2.0.0
             */
            private ?StudioHostSession $session = null;

            /**
             * Retain the newly opened session.
             *
             * @param   StudioHostSession  $session  Verified host session.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function add(StudioHostSession $session): void
            {
                $this->session = $session;
            }

            /**
             * Resolve only the retained opaque session key.
             *
             * @param   string  $resourceContextKey  Candidate opaque key.
             *
             * @return  StudioHostSession|null  Matching retained session.
             *
             * @since   2.0.0
             */
            public function find(string $resourceContextKey): ?StudioHostSession
            {
                return $this->session?->resourceContextKey === $resourceContextKey ? $this->session : null;
            }
        };
        $keys = new class implements StudioResourceContextKeyFactory {
            /**
             * Return one deterministic opaque test key.
             *
             * @return  string  Opaque resource-context key.
             *
             * @since   2.0.0
             */
            public function create(): string
            {
                return 'contexts/document-handler';
            }
        };
        $context = AuthenticatedPrincipal::issueFromStrings(
            AuthorizationContext::provenance(),
            AuthorizationContext::SUBJECT,
            ['studio.mode.blueprint'],
        )->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'preview-document-handler',
            surface: AuthenticatedSurface::Administrator,
            sessionId: 'preview-document-session',
        );

        return [new StudioHostSessionAuthority(AuthorizationContext::gateway(), $sessions, $keys), $context];
    }
    /**
     * A document request missing its exact query coordinates is refused without disclosure.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMissingQueryCoordinateIsRefusedCanonically(): void
    {
        [$authority] = $this->authority();
        $handler = new AdministratorStudioPreviewDocumentHandler(
            $authority,
            self::createStub(StudioPreviewDocumentClaimer::class),
        );

        $response = $handler->handle((new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/administrator/studio/preview'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('no-store, private', $response->getHeaderLine('Cache-Control'));
        self::assertJson((string) $response->getBody());
    }
}
