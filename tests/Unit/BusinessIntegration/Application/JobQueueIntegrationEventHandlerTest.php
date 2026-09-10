<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessIntegration\Application;

use DateTimeImmutable;
use Kumwe\App\Application\Automation\JobQueue;
use Kumwe\App\BusinessIntegration\Application\JobQueueIntegrationEventHandler;
use Kumwe\App\BusinessIntegration\Domain\RecordedEventEnvelope;
use Kumwe\App\BusinessIntegration\Domain\RecordedIntegrationEvent;
use Kumwe\App\Extension\Runtime\ExtensionExecutionContext;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Extension\Spi\Application\ExecutionContext;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\EventSensitivity;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use ReflectionClass;

#[CoversClass(JobQueueIntegrationEventHandler::class)]
/**
 * Proves the queue-bridging consumer refuses execution contexts the App did not itself issue.
 *
 * @since  2.0.0
 */
final class JobQueueIntegrationEventHandlerTest extends TestCase
{
    /**
     * Prove an extension-minted context never reaches the host job queue.
     *
     * The refusal fires before the queue, clock or job type are consulted, so a forged context cannot
     * even enqueue an otherwise well-formed envelope.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAContextTheAppDidNotIssueIsRefusedBeforeEnqueue(): void
    {
        $handler = (new ReflectionClass(JobQueueIntegrationEventHandler::class))->newInstanceWithoutConstructor();
        $definition = EventConsumerDefinition::fromArray([
            'consumer_id' => 'acme.sample.consumer',
            'event_type' => 'acme.sample.changed',
            'schema_versions' => [1],
            'handler_version' => '1.0.0',
            'queue' => 'acme.sample.integration',
            'aggregate_ordered' => true,
            'idempotency' => 'aggregate_version',
            'maximum_attempts' => 7,
            'sensitivity_ceiling' => 'restricted',
        ]);
        $event = new RecordedIntegrationEvent(
            'acme.sample.changed',
            1,
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb902',
            new DateTimeImmutable('2026-08-12T00:00:00+00:00'),
            null,
            'worker',
            'default',
            null,
            'acme.sample.record',
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb903',
            1,
            'correlation-probe',
            'request-probe',
            EventSensitivity::INTERNAL,
            ['value' => 'probe'],
        );
        $foreign = new class implements ExecutionContext {
            /**
             * Return the fixed site coordinate carried by this forged probe.
             *
             * @return  string  Site identifier.
             *
             * @since   2.0.0
             */
            public function siteIdentifier(): string
            {
                return 'default';
            }

            /**
             * Return the fixed actor coordinate carried by this forged probe.
             *
             * @return  string  Actor identifier.
             *
             * @since   2.0.0
             */
            public function actorId(): string
            {
                return '018f22e2-7c8b-7ab0-8f3a-88e8026bb901';
            }

            /**
             * Return no organization scope for this forged probe.
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
             * Return no workspace scope for this forged probe.
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
             * Return the fixed request coordinate carried by this forged probe.
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
             * Return the fixed correlation coordinate carried by this forged probe.
             *
             * @return  string  Correlation identifier.
             *
             * @since   2.0.0
             */
            public function correlationId(): string
            {
                return 'forged-correlation';
            }

            /**
             * Return the fixed delivery surface carried by this forged probe.
             *
             * @return  string  Delivery surface name.
             *
             * @since   2.0.0
             */
            public function deliverySurface(): string
            {
                return 'worker';
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('App-issued execution context');

        $handler->handle($definition, $event, $foreign);
    }

    /**
     * Prove a host-issued context enqueues the complete envelope on the signed queue.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAHostIssuedContextEnqueuesTheCompleteEnvelope(): void
    {
        $definition = EventConsumerDefinition::fromArray([
            'consumer_id' => 'acme.sample.consumer',
            'event_type' => 'acme.sample.changed',
            'schema_versions' => [1],
            'handler_version' => '1.0.0',
            'queue' => 'acme.sample.integration',
            'aggregate_ordered' => true,
            'idempotency' => 'aggregate_version',
            'maximum_attempts' => 7,
            'sensitivity_ceiling' => 'restricted',
        ]);
        $event = new RecordedIntegrationEvent(
            'acme.sample.changed',
            1,
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb912',
            new DateTimeImmutable('2026-08-12T00:00:00+00:00'),
            null,
            'worker',
            'default',
            null,
            'acme.sample.record',
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb913',
            1,
            'correlation-enqueue',
            'request-enqueue',
            EventSensitivity::INTERNAL,
            ['value' => 'enqueue'],
        );
        $context = AuthorizationContext::principal(['content.read'])->context(
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            'integration-enqueue-request',
        );
        $availableAt = new DateTimeImmutable('2026-08-12T00:00:05+00:00');
        $clock = self::createStub(ClockInterface::class);
        $clock->method('now')->willReturn($availableAt);
        $jobs = $this->createMock(JobQueue::class);
        $jobs->expects(self::once())->method('enqueue')->with(
            $context,
            'kumwe.integration.consume',
            [
                'event_id' => $event->eventId(),
                'event' => RecordedEventEnvelope::document($event),
            ],
            $availableAt,
            'acme.sample.integration',
            0,
            7,
        )->willReturn('job-1');

        (new JobQueueIntegrationEventHandler($jobs, $clock, 'kumwe.integration.consume'))
            ->handle($definition, $event, ExtensionExecutionContext::of($context));
    }
}
