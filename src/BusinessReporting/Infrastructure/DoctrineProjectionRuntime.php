<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Infrastructure;

use DateTimeInterface;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\BusinessIntegration\Application\TrustedRuntimeGenerationGuard;
use Kumwe\App\BusinessReporting\Application\ProjectionRebuildResult;
use Kumwe\App\BusinessReporting\Application\ProjectionRebuildService;
use Kumwe\App\BusinessReporting\Application\ProjectionRuntime;
use Kumwe\App\Extension\Runtime\RuntimeMaterializationState;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\IntegrationEvent;
use Kumwe\Extension\Spi\BusinessReporting\Application\ProjectionBuilder;
use Kumwe\Extension\Spi\BusinessReporting\Domain\ProjectionDefinition;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Executes the exact projection builders selected by one trusted extension runtime generation.
 *
 * @since  2.0.0
 */
final readonly class DoctrineProjectionRuntime implements ProjectionRuntime
{
    /**
     * Exact active definitions and executable builders keyed by projection identifier.
     *
     * @var    array<string, array{definition: ProjectionDefinition, builder: ProjectionBuilder}>
     * @since  2.0.0
     */
    private array $entries;

    /**
     * Capture active projection implementations and durable-store dependencies.
     *
     * @param   Connection                     $database       Shared authoritative database connection.
     * @param   TableNames                     $tables         Portable physical table-name compiler.
     * @param   TransactionManager             $transactions   Atomic generation and live-apply boundary.
     * @param   ClockInterface                 $clock          Authoritative persistence clock.
     * @param   TrustedRuntimeGenerationGuard  $runtime        Staleness fence for worker and operator execution.
     * @param   RuntimeMaterializationState    $loadedRuntime  Exact trusted generation loaded by this process.
     * @param   iterable<mixed>                $entries        Active registry entries containing definitions and
     *          builders.
     *
     * @throws  InvalidArgumentException  When an entry is malformed or duplicated.
     *
     * @since   2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private TransactionManager $transactions,
        private ClockInterface $clock,
        private TrustedRuntimeGenerationGuard $runtime,
        private RuntimeMaterializationState $loadedRuntime,
        iterable $entries,
    ) {
        $indexed = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new InvalidArgumentException('A projection runtime entry must be an array.');
            }
            $definition = $entry['definition'] ?? null;
            $builder = $entry['implementation'] ?? $entry['builder'] ?? null;
            if (!$definition instanceof ProjectionDefinition || !$builder instanceof ProjectionBuilder) {
                throw new InvalidArgumentException('A projection runtime entry is not executable.');
            }
            if (isset($indexed[$definition->identifier()])) {
                throw new InvalidArgumentException('A projection runtime entry is duplicated.');
            }
            $indexed[$definition->identifier()] = ['definition' => $definition, 'builder' => $builder];
        }
        ksort($indexed, SORT_STRING);
        $this->entries = $indexed;
    }

    /**
     * Catch up every compatible active projection before the outbox fact is settled.
     *
     * @param   IntegrationEvent  $event  Durable integration fact being dispatched.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function apply(IntegrationEvent $event): void
    {
        $this->assertCurrentRuntime();
        $sequence = null;
        foreach ($this->entries as $entry) {
            $definition = $entry['definition'];
            if (!$this->accepts($definition, $event)) {
                continue;
            }
            if (!$event->sensitivity()->allowedBy($definition->sensitivityCeiling)) {
                throw new RuntimeException('A projection sensitivity ceiling rejects its source event.');
            }
            $store = $this->store();
            $sequence ??= $store->eventSequence($event->eventId());
            if (!$store->catchUp($definition, $entry['builder'], $sequence)) {
                $this->rebuildEntry($definition, $entry['builder']);
            }
        }
    }

    /**
     * Build and activate one exact trusted projection contribution.
     *
     * @param   string  $projectionId  Namespaced active projection identifier.
     *
     * @return  ProjectionRebuildResult  Reproducibility evidence for the active replacement.
     *
     * @since   2.0.0
     */
    public function rebuild(string $projectionId): ProjectionRebuildResult
    {
        $this->assertCurrentRuntime();
        $entry = $this->entries[$projectionId] ?? null;
        if ($entry === null) {
            throw new InvalidArgumentException('The requested projection is not active in this runtime generation.');
        }

        return $this->rebuildEntry($entry['definition'], $entry['builder']);
    }

    /**
     * Return active definitions with their durable generation evidence.
     *
     * @return  list<array<string, mixed>>  Stable projection inventory in identifier order.
     *
     * @since   2.0.0
     */
    public function inventory(): array
    {
        $this->assertCurrentRuntime();
        $store = $this->store();
        $result = [];
        foreach ($this->entries as $entry) {
            $definition = $entry['definition'];
            $status = $store->activeStatus($definition->identifier());
            $result[] = [
                'projection_id' => $definition->identifier(),
                'version' => $definition->version,
                'handler_version' => $definition->handlerVersion,
                'definition_checksum' => $definition->checksum(),
                'active_generation' => $status === null ? null : $this->status($status, $definition),
            ];
        }

        return $result;
    }

    /**
     * Rebuild one already-resolved trusted definition and builder pair.
     *
     * @param   ProjectionDefinition  $definition  Exact active definition.
     * @param   ProjectionBuilder     $builder     Matching executable builder.
     *
     * @return  ProjectionRebuildResult  Activated generation reproducibility evidence.
     *
     * @since   2.0.0
     */
    private function rebuildEntry(
        ProjectionDefinition $definition,
        ProjectionBuilder $builder,
    ): ProjectionRebuildResult {
        $store = $this->store();

        return (new ProjectionRebuildService($store, $builder, $store))->rebuild($definition);
    }

    /**
     * Decide whether an event type and schema version are declared by a projection.
     *
     * @param   ProjectionDefinition  $definition  Exact projection source contract.
     * @param   IntegrationEvent      $event       Durable event being routed.
     *
     * @return  bool  True when the event is a declared compatible source.
     *
     * @since   2.0.0
     */
    private function accepts(ProjectionDefinition $definition, IntegrationEvent $event): bool
    {
        foreach ($definition->sources as $source) {
            if (
                $source->eventType === $event->eventType()
                && in_array($event->schemaVersion(), $source->schemaVersions, true)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fence every operation to the exact trusted extension generation loaded by this process.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertCurrentRuntime(): void
    {
        if (!$this->loadedRuntime->trusted || $this->loadedRuntime->generation < 0) {
            throw new RuntimeException('Projection execution requires a trusted runtime generation.');
        }
        $this->runtime->assertCurrent((string) $this->loadedRuntime->generation);
    }

    /**
     * Create a fresh stateful projection store session over the shared durable dependencies.
     *
     * @return  DoctrineProjectionStore  Idle projection source and writer session.
     *
     * @since   2.0.0
     */
    private function store(): DoctrineProjectionStore
    {
        return new DoctrineProjectionStore(
            $this->database,
            $this->tables,
            $this->transactions,
            $this->clock,
        );
    }

    /**
     * Convert a persisted active-generation row into stable operator evidence.
     *
     * @param   array<string, mixed>  $status      Raw active generation row.
     * @param   ProjectionDefinition  $definition  Current trusted definition.
     *
     * @return  array<string, mixed>  JSON-safe generation evidence.
     *
     * @since   2.0.0
     */
    private function status(array $status, ProjectionDefinition $definition): array
    {
        $lastSequence = $status['last_sequence'] ?? null;
        if (
            (!is_int($lastSequence) || $lastSequence < 0)
            && (!is_string($lastSequence) || !ctype_digit($lastSequence))
        ) {
            throw new RuntimeException('A persisted projection generation sequence is invalid.');
        }

        return [
            'generation_id' => $this->string($status, 'generation_id'),
            'definition_checksum' => $this->string($status, 'definition_checksum'),
            'definition_current' => hash_equals(
                $definition->checksum(),
                $this->string($status, 'definition_checksum'),
            ),
            'handler_version' => $this->string($status, 'handler_version'),
            'last_sequence' => (int) $lastSequence,
            'source_checksum' => $this->string($status, 'source_checksum'),
            'projection_checksum' => $this->string($status, 'projection_checksum'),
            'created_at' => $this->instant($status['created_at'] ?? null),
            'activated_at' => $this->instant($status['activated_at'] ?? null),
            'updated_at' => $this->instant($status['updated_at'] ?? null),
        ];
    }

    /**
     * Read one required non-empty string from persisted generation evidence.
     *
     * @param   array<string, mixed>  $row    Raw durable generation row.
     * @param   string                $field  Required field name.
     *
     * @return  string  Validated non-empty string value.
     *
     * @since   2.0.0
     */
    private function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('A persisted projection field "%s" is invalid.', $field));
        }

        return $value;
    }

    /**
     * Normalize a DBAL timestamp into a JSON-safe representation.
     *
     * @param   mixed  $value  Raw string or date-time value.
     *
     * @return  string  Stable timestamp representation.
     *
     * @since   2.0.0
     */
    private function instant(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('A persisted projection timestamp is invalid.');
        }

        return $value;
    }
}
