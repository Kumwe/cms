<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Runtime;

use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Extension\Runtime\ExtensionExecutionContext;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Extension\Spi\Application\ExecutionContext as ExtensionContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the adapter that presents a host execution context to extension code.
 *
 * @since  2.0.0
 */
#[CoversClass(ExtensionExecutionContext::class)]
final class ExtensionExecutionContextTest extends TestCase
{
    /**
     * The adapter discloses exactly the seven SPI coordinates of a human context, membership included.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAHumanContextIsDisclosedThroughTheSevenSpiCoordinates(): void
    {
        $host = AuthorizationContext::principal(['content.read'])->context(
            SiteContext::fromString('north'),
            AuthenticationStrength::Password,
            'request-0001',
            'correlation-0001',
            AuthenticatedSurface::Portal,
            AuthorizationContext::membership('acme', 'sales'),
        );

        $adapter = ExtensionExecutionContext::of($host);

        self::assertInstanceOf(ExtensionContext::class, $adapter);
        self::assertSame('north', $adapter->siteIdentifier());
        self::assertSame(AuthorizationContext::SUBJECT, $adapter->actorId());
        self::assertSame('acme', $adapter->organizationIdentifier());
        self::assertSame('sales', $adapter->workspaceIdentifier());
        self::assertSame('request-0001', $adapter->requestId());
        self::assertSame('correlation-0001', $adapter->correlationId());
        self::assertSame('portal', $adapter->deliverySurface());
    }

    /**
     * A system context is disclosed with its actor token, no organization and the background surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testASystemContextIsDisclosedWithItsActorTokenAndNoScopes(): void
    {
        $host = AuthorizationContext::system(SystemIdentity::Worker)->context(
            SiteContext::default(),
            'job-0001',
        );

        $adapter = ExtensionExecutionContext::of($host);

        self::assertSame(SiteContext::DEFAULT, $adapter->siteIdentifier());
        self::assertSame('system:worker', $adapter->actorId());
        self::assertNull($adapter->organizationIdentifier());
        self::assertNull($adapter->workspaceIdentifier());
        self::assertSame('job-0001', $adapter->requestId());
        self::assertSame('job-0001', $adapter->correlationId());
        self::assertSame('background', $adapter->deliverySurface());
    }

    /**
     * The exact host context is recovered from the adapter and from nothing else that answers the SPI.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOnlyTheAdapterYieldsTheHostContextItWasBuiltAround(): void
    {
        $host = AuthorizationContext::human(['content.read']);
        $foreign = new class implements ExtensionContext {
            /**
             * Answer the site coordinate of this forged envelope.
             *
             * @return  string  Site identifier.
             *
             * @since   2.0.0
             */
            public function siteIdentifier(): string
            {
                return SiteContext::DEFAULT;
            }

            /**
             * Answer the actor coordinate of this forged envelope.
             *
             * @return  string  Actor identifier.
             *
             * @since   2.0.0
             */
            public function actorId(): string
            {
                return AuthorizationContext::SUBJECT;
            }

            /**
             * Answer no organization.
             *
             * @return  ?string  Always null.
             *
             * @since   2.0.0
             */
            public function organizationIdentifier(): ?string
            {
                return null;
            }

            /**
             * Answer no workspace.
             *
             * @return  ?string  Always null.
             *
             * @since   2.0.0
             */
            public function workspaceIdentifier(): ?string
            {
                return null;
            }

            /**
             * Answer the request coordinate of this forged envelope.
             *
             * @return  string  Request identifier.
             *
             * @since   2.0.0
             */
            public function requestId(): string
            {
                return 'forged-request';
            }

            /**
             * Answer the correlation coordinate of this forged envelope.
             *
             * @return  string  Correlation identifier.
             *
             * @since   2.0.0
             */
            public function correlationId(): string
            {
                return 'forged-request';
            }

            /**
             * Answer the surface coordinate of this forged envelope.
             *
             * @return  string  Surface value.
             *
             * @since   2.0.0
             */
            public function deliverySurface(): string
            {
                return 'api';
            }
        };

        self::assertSame($host, ExtensionExecutionContext::host(ExtensionExecutionContext::of($host)));
        self::assertNull(ExtensionExecutionContext::host($foreign));
        self::assertNull(ExtensionExecutionContext::host($this->createStub(ExtensionContext::class)));
    }

    /**
     * Debug output of the adapter shows the seven coordinates and none of the host context's secrets.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDebugOutputDisclosesOnlyTheSpiCoordinates(): void
    {
        $host = AuthorizationContext::principal(['content.read'])->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'request-0002',
            sessionId: 'session-row-0002',
        );

        $dump = print_r(ExtensionExecutionContext::of($host), true);

        self::assertStringContainsString('[request_id] => request-0002', $dump);
        self::assertStringContainsString('[actor] => ' . AuthorizationContext::SUBJECT, $dump);
        self::assertStringNotContainsString('session-row-0002', $dump);
        self::assertStringNotContainsString('content.read', $dump);
        self::assertStringNotContainsString(ExecutionContext::class, $dump);
    }
}
