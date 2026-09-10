<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessIntegration\Application;

use DateTimeImmutable;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\BusinessIntegration\Application\BusinessRecordMutationEventPublisher;
use Kumwe\Extension\Spi\BusinessIntegration\Application\DomainEventHandler;
use Kumwe\App\BusinessIntegration\Application\OutboxStore;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\DomainEvent;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\DomainListenerDefinition;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\IntegrationEvent;
use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Kumwe\App\BusinessIntegration\Application\DomainEventDispatcher;

/**
 * Proves extension generation authority is checked before contributed mutation listeners execute.
 *
 * @since  2.0.0
 */
#[CoversClass(DomainEventDispatcher::class)]
#[CoversClass(BusinessRecordMutationEventPublisher::class)]
final class BusinessRecordMutationEventPublisherTest extends TestCase
{
    /**
     * A current generation admits its listener before the event is appended to the outbox.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCurrentGenerationDispatchesListenerBeforeOutboxAppend(): void
    {
        $order = [];
        $definition = new DomainListenerDefinition(
            'acme.listener.record_mutated',
            'core.business_record.mutated',
            [1],
            '1.0.0',
        );
        $handler = $this->createMock(DomainEventHandler::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturnCallback(static function (
                DomainListenerDefinition $dispatched,
                DomainEvent $event,
            ) use (
                $definition,
                &$order
            ): void {
                $order[] = 'listener';
                self::assertSame($definition, $dispatched);
                self::assertSame('core.business_record.mutated', $event->eventType());
            });
        $contributions = new ExtensionContributionRegistrySet();
        $contributions->domainListeners()->register(
            ContributionOwner::extension('acme/listener'),
            $definition,
            $handler,
        );
        $outbox = $this->createMock(OutboxStore::class);
        $outbox->expects(self::once())
            ->method('append')
            ->willReturnCallback(static function (IntegrationEvent $event) use (&$order): void {
                $order[] = 'outbox';
                self::assertSame('core.business_record.mutated', $event->eventType());
            });
        $execution = $this->createMock(ExtensionExecutionGate::class);
        $execution->expects(self::once())
            ->method('assertCurrent')
            ->willReturnCallback(static function () use (&$order): void {
                $order[] = 'generation';
            });
        $publisher = new BusinessRecordMutationEventPublisher(
            $contributions->validateIntegrationContributions(),
            $contributions,
            $outbox,
            $execution,
        );

        $publisher->publish(
            ExecutionContext::issueSystem(
                new \stdClass(),
                SystemIdentity::CommandLine,
                SiteContext::default(),
                'mutation-request',
                'mutation-correlation',
            ),
            'site.default.contact',
            1,
            '0191574f-f0b8-7bf3-a9aa-91c6b8244f92',
            2,
            'update',
            ['name'],
            new DateTimeImmutable('2026-08-22T10:15:30Z'),
        );

        self::assertSame(['generation', 'listener', 'outbox'], $order);
    }

    /**
     * Two equal-priority listeners dispatch in stable identifier order, not registration order.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEqualPriorityListenersDispatchInStableIdentifierOrder(): void
    {
        $order = [];
        $observer = function () use (&$order): DomainEventHandler {
            $handler = $this->createMock(DomainEventHandler::class);
            $handler->expects(self::once())
                ->method('handle')
                ->willReturnCallback(static function (
                    DomainListenerDefinition $dispatched,
                    DomainEvent $event,
                ) use (&$order): void {
                    unset($event);
                    $order[] = $dispatched->identifier();
                });

            return $handler;
        };
        $contributions = new ExtensionContributionRegistrySet();
        $contributions->domainListeners()->register(
            ContributionOwner::extension('acme/listener'),
            new DomainListenerDefinition('acme.listener.beta', 'core.business_record.mutated', [1], '1.0.0'),
            $observer(),
        );
        $contributions->domainListeners()->register(
            ContributionOwner::extension('acme/listener'),
            new DomainListenerDefinition('acme.listener.alpha', 'core.business_record.mutated', [1], '1.0.0'),
            $observer(),
        );
        $publisher = new BusinessRecordMutationEventPublisher(
            $contributions->validateIntegrationContributions(),
            $contributions,
            $this->createStub(OutboxStore::class),
            $this->createStub(ExtensionExecutionGate::class),
        );

        $publisher->publish(
            ExecutionContext::issueSystem(
                new \stdClass(),
                SystemIdentity::CommandLine,
                SiteContext::default(),
                'mutation-order-request',
                'mutation-order-correlation',
            ),
            'site.default.contact',
            1,
            '0191574f-f0b8-7bf3-a9aa-91c6b8244f93',
            3,
            'update',
            ['name'],
            new DateTimeImmutable('2026-08-22T10:15:30Z'),
        );

        self::assertSame(['acme.listener.alpha', 'acme.listener.beta'], $order);
    }

    /**
     * A stale runtime refuses the whole listener-and-outbox boundary without invoking extension code.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStaleGenerationRefusesBeforeListenerOrOutboxInvocation(): void
    {
        $definition = new DomainListenerDefinition(
            'acme.listener.record_mutated',
            'core.business_record.mutated',
            [1],
            '1.0.0',
        );
        $handler = $this->createMock(DomainEventHandler::class);
        $handler->expects(self::never())->method('handle');
        $contributions = new ExtensionContributionRegistrySet();
        $contributions->domainListeners()->register(
            ContributionOwner::extension('acme/listener'),
            $definition,
            $handler,
        );
        $outbox = $this->createMock(OutboxStore::class);
        $outbox->expects(self::never())->method('append');
        $execution = $this->createMock(ExtensionExecutionGate::class);
        $execution->expects(self::once())
            ->method('assertCurrent')
            ->willThrowException(new RuntimeException('The extension runtime generation is stale.'));
        $publisher = new BusinessRecordMutationEventPublisher(
            $contributions->validateIntegrationContributions(),
            $contributions,
            $outbox,
            $execution,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The extension runtime generation is stale.');
        $publisher->publish(
            ExecutionContext::issueSystem(
                new \stdClass(),
                SystemIdentity::CommandLine,
                SiteContext::default(),
                'mutation-request',
                'mutation-correlation',
            ),
            'site.default.contact',
            1,
            '0191574f-f0b8-7bf3-a9aa-91c6b8244f92',
            2,
            'update',
            ['name'],
            new DateTimeImmutable('2026-08-22T10:15:30Z'),
        );
    }
}
