<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Application;

use InvalidArgumentException;
use Kumwe\App\Application\Automation\QueueRuntimePolicyCatalog;
use Kumwe\App\Application\Automation\RetryPolicy;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Extension\Runtime\ExtensionExecutionContext;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\BusinessIntegration\Application\IntegrationEventHandler;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\IntegrationEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Applies one integration event through a durable consumer inbox under a pinned runtime generation.
 *
 * @since  2.0.0
 */
final readonly class IntegrationEventConsumerDispatcher
{
    /**
     * Assemble durable consumer execution.
     *
     * @param  InboxStore                     $inbox         Durable receipt and checkpoint ledger.
     * @param  EventContractRegistry          $contracts     Exact event and consumer catalog.
     * @param  RetryPolicy                    $retries       Failure classification and backoff.
     * @param  TrustedRuntimeGenerationGuard  $runtime       Trusted-generation guard.
     * @param  TransactionManager             $transactions  Atomic handler-effect and receipt settlement boundary.
     * @param  LoggerInterface                $logger        Structured observability sink.
     * @param  ?QueueRuntimePolicyCatalog     $policies      Active contributed queue limits; null preserves the
     *         established delivery defaults for isolated core instances.
     *
     * @since  2.0.0
     */
    public function __construct(
        private InboxStore $inbox,
        private EventContractRegistry $contracts,
        private RetryPolicy $retries,
        private TrustedRuntimeGenerationGuard $runtime,
        private TransactionManager $transactions,
        private LoggerInterface $logger,
        private ?QueueRuntimePolicyCatalog $policies = null,
    ) {
    }

    /**
     * Offer an event to one handler, executing only when its durable receipt is claimed.
     *
     * @param   EventConsumerDefinition  $definition         Exact signed consumer declaration.
     * @param   IntegrationEvent         $event              Event delivered at least once.
     * @param   IntegrationEventHandler  $handler            Exact trusted handler implementation.
     * @param   ExecutionContext         $context            Freshly authorised worker context.
     * @param   string                   $workerId           Process identity.
     * @param   string                   $runtimeGeneration  Exact generation that selected the handler.
     * @param   ?int                     $leaseSeconds       Explicit delivery lease duration, or null to use the
     *          queue policy/default.
     *
     * @return  InboxDisposition  Explicit deduplication, ordering or execution outcome.
     *
     * @since   2.0.0
     */
    public function consume(
        EventConsumerDefinition $definition,
        IntegrationEvent $event,
        IntegrationEventHandler $handler,
        ExecutionContext $context,
        string $workerId,
        string $runtimeGeneration,
        ?int $leaseSeconds = null,
    ): InboxDisposition {
        $this->runtime->assertCurrent($runtimeGeneration);
        $this->contracts->assertEvent($event);
        $registered = $this->contracts->consumer($definition->identifier());
        if ($registered->toArray() !== $definition->toArray()) {
            throw new InvalidArgumentException('The executable consumer does not match its trusted declaration.');
        }
        $leaseSeconds = $this->leaseSeconds($registered->queue(), $leaseSeconds);
        $result = $this->inbox->receive(
            $registered,
            $event,
            $workerId,
            $runtimeGeneration,
            $leaseSeconds,
        );
        if ($result->lease === null) {
            return $result->disposition;
        }
        $lease = $result->lease;
        try {
            $this->transactions->transactional(function () use (
                $lease,
                $handler,
                $registered,
                $event,
                $context,
            ): void {
                $this->runtime->assertCurrent($lease->runtimeGeneration);
                $handler->handle($registered, $event, ExtensionExecutionContext::of($context));
                $this->inbox->complete($lease);
            });
            $this->logger->info('Integration event consumer completed.', [
                'consumer_id' => $registered->identifier(),
                'event_id' => $event->eventId(),
                'correlation_id' => $event->correlationId(),
                'causation_id' => $event->causationId(),
                'attempt' => $lease->attempts,
                'runtime_generation' => $lease->runtimeGeneration,
            ]);
        } catch (Throwable $failure) {
            $decision = $this->retries->decide(
                $failure,
                $lease->attempts,
                $lease->consumer->maximumAttempts(),
            );
            $this->inbox->fail($lease, $decision->classification, $failure, $decision->retryAt);
            $this->logger->warning('Integration event consumer failed.', [
                'consumer_id' => $registered->identifier(),
                'event_id' => $event->eventId(),
                'correlation_id' => $event->correlationId(),
                'causation_id' => $event->causationId(),
                'attempt' => $lease->attempts,
                'classification' => $decision->classification->value,
                'will_retry' => $decision->shouldRetry,
                'exception' => $failure,
            ]);
            throw $failure;
        }
        return InboxDisposition::CLAIMED;
    }

    /**
     * Resolve the signed queue lease without silently widening an explicit request.
     *
     * @param   string  $queue      Declared delivery queue.
     * @param   ?int    $requested  Caller override, or null for the policy/default.
     *
     * @return  int  Effective lease duration.
     *
     * @throws  InvalidArgumentException  When an explicit lease exceeds the queue declaration.
     *
     * @since   2.0.0
     */
    private function leaseSeconds(string $queue, ?int $requested): int
    {
        $policy = $this->policies?->policy($queue);
        if ($policy !== null && $requested !== null && $requested > $policy->leaseSeconds) {
            throw new InvalidArgumentException('A contributed queue lease cannot exceed its signed policy.');
        }

        return $requested ?? $policy->leaseSeconds ?? 60;
    }
}
