<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Application;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\BusinessIntegration\Domain\ProcessInstance;
use Kumwe\App\BusinessReporting\Application\ProjectionRuntime;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * Authorized operator surface for integration visibility, replay, retention and process cancellation.
 *
 * Durable integration tables contain installation-wide operational evidence, including failures and
 * bounded error diagnostics from every site. Consequently, this service authorizes every read and
 * mutation against the installation automation resource before touching a store. Replay, retention,
 * and cancellation are committed with an audit event in the same transaction; delivery adapters never
 * reach the repositories directly and therefore cannot accidentally create an unaudited control path.
 *
 * @since  2.0.0
 */
final readonly class IntegrationOperationsService
{
    /**
     * Bind the operator surface to durable stores, centralized authorization and atomic audit recording.
     *
     * @param  OutboxStore            $outbox          Transactional integration-event ledger.
     * @param  InboxStore             $inbox           Per-consumer delivery and deduplication ledger.
     * @param  ProcessManagerStore    $processes       Durable process state and work ledger.
     * @param  ProcessManagerService  $processManager  Optimistic process transition service.
     * @param  AuthorizationGateway   $authorization   Installation policy decision gateway.
     * @param  TransactionManager     $transactions    Atomic mutation and audit boundary.
     * @param  AuditRecorder          $audit           Durable security audit sink.
     * @param  ClockInterface         $clock           Authoritative operation timestamp source.
     * @param  ?ProjectionRuntime     $projections     Trusted durable projection runtime, when enabled.
     *
     * @since  2.0.0
     */
    public function __construct(
        private OutboxStore $outbox,
        private InboxStore $inbox,
        private ProcessManagerStore $processes,
        private ProcessManagerService $processManager,
        private AuthorizationGateway $authorization,
        private TransactionManager $transactions,
        private AuditRecorder $audit,
        private ClockInterface $clock,
        private ?ProjectionRuntime $projections = null,
    ) {
    }

    /**
     * List recent outbox records after proving installation-wide automation authority.
     *
     * @param   ExecutionContext  $context  Authenticated operator context.
     * @param   int               $limit    Maximum number of rows, between 1 and 1000.
     *
     * @return  list<array<string, mixed>>  Recent event records in deterministic newest-first order.
     *
     * @since   2.0.0
     */
    public function outbox(ExecutionContext $context, int $limit = 100): array
    {
        $this->assertAuthorized($context);

        return $this->outbox->recent($limit);
    }

    /**
     * List recent delivery receipts for one exact consumer after installation-wide authorization.
     *
     * @param   ExecutionContext  $context     Authenticated operator context.
     * @param   string            $consumerId  Stable contributed consumer identifier.
     * @param   int               $limit       Maximum number of rows, between 1 and 1000.
     *
     * @return  list<array<string, mixed>>  Recent receipts in deterministic newest-first order.
     *
     * @throws  InvalidArgumentException  When the consumer identifier is malformed.
     *
     * @since   2.0.0
     */
    public function inbox(ExecutionContext $context, string $consumerId, int $limit = 100): array
    {
        $this->assertAuthorized($context);
        $this->assertToken($consumerId, 'consumer');

        return $this->inbox->recent($consumerId, $limit);
    }

    /**
     * List recent process-manager snapshots after proving installation-wide automation authority.
     *
     * @param   ExecutionContext  $context  Authenticated operator context.
     * @param   int               $limit    Maximum number of rows, between 1 and 1000.
     *
     * @return  list<array<string, mixed>>  Recent process snapshots in deterministic order.
     *
     * @since   2.0.0
     */
    public function processes(ExecutionContext $context, int $limit = 100): array
    {
        $this->assertAuthorized($context);

        return $this->processes->recent($limit);
    }

    /**
     * List durable work emitted by one process after proving installation-wide automation authority.
     *
     * @param   ExecutionContext  $context    Authenticated operator context.
     * @param   string            $processId  Canonical process UUID.
     * @param   int               $limit      Maximum number of rows, between 1 and 1000.
     *
     * @return  list<array<string, mixed>>  Process work in deterministic creation order.
     *
     * @throws  InvalidArgumentException  When the process identifier is not a canonical lowercase UUID.
     *
     * @since   2.0.0
     */
    public function processWork(ExecutionContext $context, string $processId, int $limit = 100): array
    {
        $this->assertAuthorized($context);
        $this->assertUuid($processId, 'process');

        return $this->processes->work($processId, $limit);
    }

    /**
     * Requeue one terminal event and atomically record who authorized the replay.
     *
     * @param   ExecutionContext  $context  Authenticated operator context.
     * @param   string            $eventId  Canonical event UUID currently dispatched or quarantined.
     *
     * @return  array<string, mixed>  Stable replay acknowledgement suitable for delivery adapters.
     *
     * @throws  InvalidArgumentException  When the event identifier is not a canonical lowercase UUID.
     *
     * @since   2.0.0
     */
    public function replay(ExecutionContext $context, string $eventId): array
    {
        $this->assertAuthorized($context);
        $this->assertUuid($eventId, 'event');

        return $this->transactions->transactional(function () use ($context, $eventId): array {
            $this->outbox->replay($eventId, $context->actorId(), $this->clock->now());
            $this->record($context, 'integration.event.replay', 'integration_event', $eventId);

            return ['event_id' => $eventId, 'status' => 'pending'];
        });
    }

    /**
     * Purge a bounded batch of expired terminal outbox rows and atomically audit the exact count.
     *
     * @param   ExecutionContext  $context  Authenticated operator context.
     * @param   int               $limit    Maximum number of expired rows, between 1 and 10000.
     *
     * @return  array<string, mixed>  Stable acknowledgement containing the number of rows removed.
     *
     * @since   2.0.0
     */
    public function purge(ExecutionContext $context, int $limit = 1_000): array
    {
        $this->assertAuthorized($context);

        return $this->transactions->transactional(function () use ($context, $limit): array {
            $purged = $this->outbox->purgeExpired($this->clock->now(), $limit);
            $this->record(
                $context,
                'integration.retention.purge',
                'integration_retention',
                'business-integrations',
                ['limit' => $limit, 'purged' => $purged],
            );

            return ['purged' => $purged, 'limit' => $limit];
        });
    }

    /**
     * Cancel one optimistic process snapshot and atomically record the operator rationale.
     *
     * Cancellation retains state and work history. This generic control deliberately emits no guessed
     * compensation: a process-specific operator tool must provide explicit compensation work through
     * `ProcessManagerService` when its signed contract defines safe compensating commands.
     *
     * @param   ExecutionContext  $context          Authenticated operator context.
     * @param   string            $processId        Canonical process UUID.
     * @param   int               $expectedVersion  Optimistic version observed by the operator.
     * @param   string            $note             Rationale containing between 1 and 1000 characters.
     *
     * @return  array<string, mixed>  Stable cancelled-process acknowledgement.
     *
     * @throws  InvalidArgumentException  When an identifier, version, or note is invalid or stale.
     *
     * @since   2.0.0
     */
    public function cancel(
        ExecutionContext $context,
        string $processId,
        int $expectedVersion,
        string $note,
    ): array {
        $this->assertAuthorized($context);
        $this->assertUuid($processId, 'process');
        if ($expectedVersion < 1) {
            throw new InvalidArgumentException('A process version must be positive.');
        }
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 1_000) {
            throw new InvalidArgumentException('A cancellation note must contain 1 to 1000 characters.');
        }

        return $this->transactions->transactional(function () use (
            $context,
            $processId,
            $expectedVersion,
            $note,
        ): array {
            $process = $this->processManager->cancel(
                $processId,
                $expectedVersion,
                $context->actorId(),
                $note,
            );
            $this->record(
                $context,
                'integration.process.cancel',
                'business_process',
                $processId,
                ['previous_version' => $expectedVersion, 'version' => $process->version()],
            );

            return $this->processResult($process);
        });
    }

    /**
     * List active projection definitions and their durable generation evidence.
     *
     * @param   ExecutionContext  $context  Authenticated installation operator.
     *
     * @return  list<array<string, mixed>>  Trusted active projection inventory.
     *
     * @since   2.0.0
     */
    public function projections(ExecutionContext $context): array
    {
        $this->assertAuthorized($context);

        return $this->projectionRuntime()->inventory();
    }

    /**
     * Rebuild one active projection and audit its reproducibility evidence.
     *
     * @param   ExecutionContext  $context       Authenticated installation operator.
     * @param   string            $projectionId  Namespaced active projection identifier.
     *
     * @return  array<string, mixed>  Activated generation and source/output checksum evidence.
     *
     * @since   2.0.0
     */
    public function rebuildProjection(ExecutionContext $context, string $projectionId): array
    {
        $this->assertAuthorized($context);
        $this->assertToken($projectionId, 'projection');
        $runtime = $this->projectionRuntime();
        $result = $runtime->rebuild($projectionId);
        $active = null;
        foreach ($runtime->inventory() as $projection) {
            if (($projection['projection_id'] ?? null) === $projectionId) {
                $active = $projection['active_generation'] ?? null;
                break;
            }
        }
        if (!is_array($active)) {
            throw new \RuntimeException('The rebuilt projection did not publish an active generation.');
        }
        $generationId = $active['generation_id'] ?? null;
        if (
            !is_string($generationId)
            || $generationId === ''
            || ($active['last_sequence'] ?? null) !== $result->lastSequence
            || ($active['source_checksum'] ?? null) !== $result->sourceChecksum
            || ($active['projection_checksum'] ?? null) !== $result->projectionChecksum
        ) {
            throw new \RuntimeException('The rebuilt projection evidence lost its active-generation fence.');
        }
        $response = [
            'projection_id' => $projectionId,
            'generation_id' => $generationId,
            'last_sequence' => $result->lastSequence,
            'event_count' => $result->eventCount,
            'source_checksum' => $result->sourceChecksum,
            'projection_checksum' => $result->projectionChecksum,
        ];
        $this->transactions->transactional(function () use ($context, $projectionId, $response): void {
            $this->record(
                $context,
                'integration.projection.rebuild',
                'business_projection',
                $projectionId,
                $response,
            );
        });

        return $response;
    }

    /**
     * Require installation-wide automation authority for all integration operations.
     *
     * @param   ExecutionContext  $context  Authenticated operator context.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertAuthorized(ExecutionContext $context): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('automation.manage'),
            AuthorizationResource::item('automation_installation', 'business-integrations'),
        );
    }

    /**
     * Return the configured durable projection runtime or fail closed.
     *
     * @return  ProjectionRuntime  Trusted projection runtime.
     *
     * @since   2.0.0
     */
    private function projectionRuntime(): ProjectionRuntime
    {
        if ($this->projections === null) {
            throw new \RuntimeException('The durable projection runtime is unavailable.');
        }

        return $this->projections;
    }

    /**
     * Record one successful integration control inside its surrounding transaction.
     *
     * @param   ExecutionContext      $context      Authenticated operator context.
     * @param   string                $action       Stable audit action token.
     * @param   string                $subjectType  Stable audit subject family.
     * @param   string                $subjectId    Opaque subject identity.
     * @param   array<string, mixed>  $metadata     Bounded safe operation metadata.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function record(
        ExecutionContext $context,
        string $action,
        string $subjectType,
        string $subjectId,
        array $metadata = [],
    ): void {
        $this->audit->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $this->clock->now(),
            $context->actorId(),
            $action,
            $subjectType,
            $subjectId,
            'success',
            $metadata,
        ));
    }

    /**
     * Project a cancelled process without exposing implementation objects to delivery adapters.
     *
     * @param   ProcessInstance  $process  Persisted cancelled snapshot.
     *
     * @return  array<string, mixed>  Stable process identity, type, version, status and update instant.
     *
     * @since   2.0.0
     */
    private function processResult(ProcessInstance $process): array
    {
        return [
            'process_id' => $process->id(),
            'process_type' => $process->processType(),
            'version' => $process->version(),
            'status' => $process->status()->value,
            'updated_at' => $process->updatedAt()->format(DATE_ATOM),
        ];
    }

    /**
     * Reject an identifier that is not a canonical lowercase UUID.
     *
     * @param   string  $value  Candidate UUID.
     * @param   string  $field  Safe field label for the validation message.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the value is not canonical and lowercase.
     *
     * @since   2.0.0
     */
    private function assertUuid(string $value, string $field): void
    {
        if (!Uuid::isValid($value) || strtolower($value) !== $value) {
            throw new InvalidArgumentException(sprintf('The %s ID must be a canonical lowercase UUID.', $field));
        }
    }

    /**
     * Reject an unbounded or control-bearing integration identifier before it reaches a query.
     *
     * @param   string  $value  Candidate stable integration token.
     * @param   string  $field  Safe field label for the validation message.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the token is empty, too long, or contains controls.
     *
     * @since   2.0.0
     */
    private function assertToken(string $value, string $field): void
    {
        if ($value === '' || strlen($value) > 191 || preg_match('/[\x00-\x1F\x7F]/D', $value) === 1) {
            throw new InvalidArgumentException(sprintf('The %s identifier is invalid.', $field));
        }
    }
}
