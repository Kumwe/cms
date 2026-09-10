<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Preview;

use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\App\Studio\Infrastructure\Observability\StructuredLogStudioPreviewActivityRecorder;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Proves the ephemeral preview activity trail cannot receive content or transport secrets.
 *
 * @since  2.0.0
 */
#[CoversClass(StructuredLogStudioPreviewActivityRecorder::class)]
final class StudioPreviewActivityRecorderTest extends TestCase
{
    /**
     * The structured allowlist records accountability while excluding every sensitive preview value.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testActivityRecordHasAnExactSensitiveDataFreeShape(): void
    {
        $logger = new class extends AbstractLogger {
            /**
             * Most recent structured context.
             *
             * @var    array<mixed>
             * @since  2.0.0
             */
            public array $context = [];

            /**
             * Capture the most recent structured log context.
             *
             * @param   mixed              $level    Accepted PSR log level.
             * @param   string|Stringable  $message  Bounded preview activity message.
             * @param   array<mixed>       $context  Structured activity fields.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function log(
                mixed $level,
                string|Stringable $message,
                array $context = [],
            ): void {
                $this->context = $context;
            }
        };
        $recorder = new StructuredLogStudioPreviewActivityRecorder($logger);
        $recorder->record(
            self::context(),
            self::snapshot(),
            'render',
            'completed',
            'studio.preview/render-completed',
        );

        self::assertSame([
            'subject',
            'site',
            'resource_kind',
            'resource_fingerprint',
            'request_id',
            'correlation_id',
            'action',
            'outcome',
            'reason',
        ], array_keys($logger->context));
        self::assertSame(hash('sha256', 'blueprint:activity-test'), $logger->context['resource_fingerprint']);
        foreach (
            [
                'draft',
                'draft_digest',
                'document',
                'html',
                'token',
                'channel_id',
                'source_id',
                'sequence',
                'markers',
                'marker_map',
            ] as $forbidden
        ) {
            self::assertArrayNotHasKey($forbidden, $logger->context);
        }
    }

    /**
     * Mint one authenticated administrator context for structured attribution.
     *
     * @return  ExecutionContext  Trusted actor and correlation context.
     *
     * @since  2.0.0
     */
    private static function context(): ExecutionContext
    {
        return AuthenticatedPrincipal::issueFromStrings(
            AuthorizationContext::provenance(),
            AuthorizationContext::SUBJECT,
            ['studio.read'],
        )->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'preview-activity-request',
            surface: AuthenticatedSurface::Administrator,
            sessionId: 'preview-activity-session',
        );
    }

    /**
     * Build a trusted Blueprint session whose raw resource ID must not enter the log.
     *
     * @return  StudioHostSessionSnapshot  Live preview authority.
     *
     * @since  2.0.0
     */
    private static function snapshot(): StudioHostSessionSnapshot
    {
        $session = new StudioHostSession(
            'contexts/activity-test',
            AuthorizationContext::SUBJECT,
            SiteContext::DEFAULT,
            null,
            null,
            'administrator',
            hash('sha256', 'activity-session-binding'),
            StudioSessionMode::Blueprint,
            StudioResourceKind::Blueprint,
            'blueprint:activity-test',
            'session-activity-test',
        );

        return new StudioHostSessionSnapshot(
            $session,
            ['studio.permission/read'],
            'session-activity-test',
            true,
            false,
            false,
        );
    }
}
