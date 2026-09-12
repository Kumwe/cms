<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessIntegration\Infrastructure;

use DateTimeImmutable;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Application\Authorization\SystemPrincipal;
use Kumwe\App\Application\Automation\JitterSource;
use Kumwe\App\Application\Automation\PermanentFailure;
use Kumwe\App\Application\Automation\RetryPolicy;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\BusinessIntegration\Application\DurableOutboundAdapterDispatcher;
use Kumwe\App\BusinessIntegration\Application\EventContractRegistry;
use Kumwe\App\BusinessIntegration\Application\InboxClaimResult;
use Kumwe\App\BusinessIntegration\Application\InboxDisposition;
use Kumwe\App\BusinessIntegration\Application\InboxStore;
use Kumwe\App\BusinessIntegration\Application\IntegrationEventConsumerDispatcher;
use Kumwe\App\BusinessIntegration\Application\TrustedRuntimeGenerationGuard;
use Kumwe\App\BusinessIntegration\Domain\EventSchemaDefinition;
use Kumwe\App\BusinessIntegration\Domain\RecordedIntegrationEvent;
use Kumwe\App\BusinessIntegration\Infrastructure\RuntimeIntegrationEventTransport;
use Kumwe\App\BusinessReporting\Application\ProjectionRebuildResult;
use Kumwe\App\BusinessReporting\Application\ProjectionRuntime;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Runtime\RuntimeMaterializationState;
use Kumwe\Extension\Spi\Application\ExecutionContext;
use Kumwe\Extension\Spi\BusinessIntegration\Application\IntegrationEventHandler;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\EventSensitivity;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\IntegrationEvent;
use Kumwe\Extension\Spi\Contribution\ContributionDefinition;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use ReflectionClass;
use RuntimeException;

#[CoversClass(RuntimeIntegrationEventTransport::class)]
/**
 * Proves runtime fan-out refuses a consumer registry entry whose executable contract degraded.
 *
 * @since  2.0.0
 */
final class RuntimeIntegrationEventTransportTest extends TestCase
{
    /**
     * Prove an executable entry without a consumer contract fails delivery permanently.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAConsumerEntryWithoutItsContractFailsPermanently(): void
    {
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $registries->eventConsumers()->register(
            ContributionOwner::extension('acme/probe'),
            self::degradedDefinition(),
            self::integrationEventHandler(),
        );
        $transport = new RuntimeIntegrationEventTransport(
            $registries,
            self::uncalled(IntegrationEventConsumerDispatcher::class),
            self::uncalled(DurableOutboundAdapterDispatcher::class),
            self::projections(),
            SystemPrincipal::issue(new \stdClass(), SystemIdentity::Worker),
            new RuntimeMaterializationState('replica-1', 7, '', '', true),
        );

        $this->expectException(PermanentFailure::class);
        $this->expectExceptionMessage('invalid executable entry');

        $transport->publish(self::event());
    }

    /**
     * Prove an active consumer whose event type matches receives the delivery through its inbox.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnActiveConsumerReceivesTheDeliveryThroughItsDurableInbox(): void
    {
        $event = self::event();
        $definition = new EventConsumerDefinition(
            'acme.probe.observe-later',
            'acme.probe.observed',
            [1],
            '1.0.0',
        );
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $registries->eventConsumers()->register(
            ContributionOwner::extension('acme/probe'),
            $definition,
            self::integrationEventHandler(),
        );
        $contracts = new EventContractRegistry([new EventSchemaDefinition(
            'acme.probe.observed',
            1,
            EventSensitivity::INTERNAL,
            [
                'type' => 'object',
                'required' => ['record_id'],
                'properties' => ['record_id' => ['type' => 'string']],
                'additionalProperties' => false,
            ],
        )], [$definition]);
        $inbox = $this->createMock(InboxStore::class);
        $inbox->expects(self::once())->method('receive')
            ->with($definition, $event, 'replica-1:integration', '7', self::anything())
            ->willReturn(new InboxClaimResult(InboxDisposition::DUPLICATE));
        $transport = new RuntimeIntegrationEventTransport(
            $registries,
            new IntegrationEventConsumerDispatcher(
                $inbox,
                $contracts,
                new RetryPolicy(self::clock(), self::jitter()),
                self::createStub(TrustedRuntimeGenerationGuard::class),
                self::createStub(TransactionManager::class),
                new NullLogger(),
            ),
            self::uncalled(DurableOutboundAdapterDispatcher::class),
            self::projections(),
            SystemPrincipal::issue(new \stdClass(), SystemIdentity::Worker),
            new RuntimeMaterializationState('replica-1', 7, '', '', true),
        );

        $transport->publish($event);

        self::assertSame('core.runtime-fanout', $transport->identifier());
        self::assertSame(EventSensitivity::SECRET, $transport->sensitivityCeiling());
    }

    /**
     * Build the probe integration event the fan-out delivers.
     *
     * @return  RecordedIntegrationEvent  Versioned probe event.
     *
     * @since   2.0.0
     */
    private static function event(): RecordedIntegrationEvent
    {
        return new RecordedIntegrationEvent(
            'acme.probe.observed',
            1,
            Uuid::uuid7()->toString(),
            new DateTimeImmutable('2026-08-10T10:00:00+00:00'),
            null,
            'worker',
            'default',
            null,
            'acme.record',
            'record-1',
            1,
            'correlation-1',
            'request-1',
            EventSensitivity::INTERNAL,
            ['record_id' => 'record-1'],
        );
    }

    /**
     * Build a fixed clock for the retry policy.
     *
     * @return  ClockInterface  Clock pinned to one instant.
     *
     * @since   2.0.0
     */
    private static function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            /**
             * Report the pinned probe instant.
             *
             * @return  DateTimeImmutable  Fixed timestamp.
             *
             * @since   2.0.0
             */
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-08-10T10:00:00+00:00');
            }
        };
    }

    /**
     * Build a deterministic jitter source for the retry policy.
     *
     * @return  JitterSource  Jitter that always answers the minimum.
     *
     * @since   2.0.0
     */
    private static function jitter(): JitterSource
    {
        return new class implements JitterSource {
            /**
             * Answer the minimum of the requested range.
             *
             * @param   int  $minimum  Lower bound.
             * @param   int  $maximum  Upper bound.
             *
             * @return  int  The lower bound.
             *
             * @since   2.0.0
             */
            public function between(int $minimum, int $maximum): int
            {
                unset($maximum);

                return $minimum;
            }
        };
    }

    /**
     * Build a declarative definition that is not an event-consumer contract.
     *
     * The generic runtime registry accepts any contribution definition, so a degraded composition
     * can pair a foreign declaration with an executable; fan-out has to catch it at delivery.
     *
     * @return  ContributionDefinition  Definition of the wrong contract type.
     *
     * @since   2.0.0
     */
    private static function degradedDefinition(): ContributionDefinition
    {
        return new class implements ContributionDefinition {
            /**
             * Report the owner-namespaced probe identifier.
             *
             * @return  string  Probe identifier.
             *
             * @since   2.0.0
             */
            public function identifier(): string
            {
                return 'acme.probe.degraded-consumer';
            }

            /**
             * Export the inert declaration document.
             *
             * @return  array<string, mixed>  Probe declaration.
             *
             * @since   2.0.0
             */
            public function toArray(): array
            {
                return ['id' => 'acme.probe.degraded-consumer'];
            }
        };
    }

    /**
     * Build an inert durable-consumer executable probe.
     *
     * @return  IntegrationEventHandler  Executable that records nothing.
     *
     * @since   2.0.0
     */
    private static function integrationEventHandler(): IntegrationEventHandler
    {
        return new class implements IntegrationEventHandler {
            /**
             * Accept one durable delivery without side effects.
             *
             * @param   EventConsumerDefinition  $definition  Declared consumer contract.
             * @param   IntegrationEvent         $event       Delivered integration event.
             * @param   ExecutionContext         $context     Host-issued execution capabilities.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function handle(
                EventConsumerDefinition $definition,
                IntegrationEvent $event,
                ExecutionContext $context,
            ): void {
                unset($definition, $event, $context);
            }
        };
    }

    /**
     * Build a projection runtime that tolerates live application and refuses maintenance calls.
     *
     * @return  ProjectionRuntime  Runtime that applies silently.
     *
     * @since   2.0.0
     */
    private static function projections(): ProjectionRuntime
    {
        return new class implements ProjectionRuntime {
            /**
             * Accept one live event without deriving state.
             *
             * @param   IntegrationEvent  $event  Delivered integration event.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function apply(IntegrationEvent $event): void
            {
                unset($event);
            }

            /**
             * Refuse rebuilds; this probe only observes live fan-out.
             *
             * @param   string  $projectionId  Namespaced projection identifier.
             *
             * @return  ProjectionRebuildResult  Never returned.
             *
             * @since   2.0.0
             */
            public function rebuild(string $projectionId): ProjectionRebuildResult
            {
                unset($projectionId);

                throw new RuntimeException('The probe projection runtime never rebuilds.');
            }

            /**
             * Report an empty projection inventory.
             *
             * @return  array<string, mixed>  Empty inventory.
             *
             * @since   2.0.0
             */
            public function inventory(): array
            {
                return [];
            }
        };
    }

    /**
     * Materialize a collaborator that the refusal path must never invoke.
     *
     * @template T of object
     *
     * @param   class-string<T>  $class  Final collaborator class.
     *
     * @return  T  Structurally complete but unwired instance.
     *
     * @since   2.0.0
     */
    private static function uncalled(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
