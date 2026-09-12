<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use JsonException;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\BusinessIntegration\Domain\RecordedIntegrationEvent;
use Kumwe\App\BusinessReporting\Application\JournalProjectionEvent;
use Kumwe\App\BusinessReporting\Application\ProjectionEventSource;
use Kumwe\App\BusinessReporting\Application\ProjectionGenerationWriter;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Domain\CanonicalJson;
use Kumwe\Extension\Spi\BusinessReporting\Application\ProjectionBuilder;
use Kumwe\Extension\Spi\BusinessReporting\Application\ProjectionEvent;
use Kumwe\Extension\Spi\BusinessReporting\Domain\ProjectionDefinition;
use Kumwe\Extension\Spi\BusinessReporting\Domain\ProjectionFieldDefinition;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * DBAL event source and generation writer for deterministic reporting projections.
 *
 * Rebuild rows remain isolated under a fresh generation until `commit()` changes the active pointer in
 * one transaction. Live catch-up locks and advances that active generation with its source checkpoint,
 * so replaying an outbox event cannot repeat an already committed builder effect.
 *
 * @since  2.0.0
 */
final class DoctrineProjectionStore implements ProjectionEventSource, ProjectionGenerationWriter
{
    /**
     * Definition bound to the currently active writer session.
     *
     * @var    ?ProjectionDefinition
     * @since  2.0.0
     */
    private ?ProjectionDefinition $definition = null;

    /**
     * Durable generation identifier bound to the current writer session.
     *
     * @var    ?string
     * @since  2.0.0
     */
    private ?string $generationId = null;

    /**
     * Current writer mode, either `rebuild`, `live`, or null while idle.
     *
     * @var    ?string
     * @since  2.0.0
     */
    private ?string $mode = null;

    /**
     * Last immutable journal sequence committed in the current generation.
     *
     * @var    int
     * @since  2.0.0
     */
    private int $lastSequence = 0;

    /**
     * Bind one stateful writer session to the shared durable projection tables.
     *
     * @param  Connection          $database      Shared authoritative database connection.
     * @param  TableNames          $tables        Portable physical table-name compiler.
     * @param  TransactionManager  $transactions  Atomic live-apply and generation-activation boundary.
     * @param  ClockInterface      $clock         Authoritative persistence clock.
     *
     * @since  2.0.0
     */
    public function __construct(
        private readonly Connection $database,
        private readonly TableNames $tables,
        private readonly TransactionManager $transactions,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Read the next declared source page in immutable journal order.
     *
     * @param   ProjectionDefinition  $definition     Projection source contract.
     * @param   int                   $afterSequence  Last applied global journal sequence.
     * @param   int                   $limit          Maximum page size.
     *
     * @return  list<ProjectionEvent>  Strictly increasing compatible events.
     *
     * @since   2.0.0
     */
    public function next(ProjectionDefinition $definition, int $afterSequence, int $limit): array
    {
        if ($afterSequence < 0 || $limit < 1 || $limit > 1_000) {
            throw new InvalidArgumentException('A projection source page bound is invalid.');
        }
        [$predicate, $parameters, $types] = $this->sourcePredicate($definition);
        array_unshift($parameters, $afterSequence);
        array_unshift($types, Types::BIGINT);
        $parameters[] = $limit;
        $types[] = Types::INTEGER;
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT source_sequence, event_id, event_type, schema_version, sensitivity, envelope, event_checksum '
            . 'FROM %s WHERE source_sequence > ? AND (%s) ORDER BY source_sequence LIMIT ?',
            $this->tables->quoted('business_projection_source_events'),
            $predicate,
        ), $parameters, $types);

        $events = [];
        foreach ($rows as $row) {
            $events[] = $this->projectionEvent($definition, $row);
        }

        return $events;
    }

    /**
     * Start an isolated replacement generation.
     *
     * @param   ProjectionDefinition  $definition  Exact rebuild contract.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function begin(ProjectionDefinition $definition): void
    {
        $this->assertIdle();
        $generationId = Uuid::uuid7()->toString();
        $now = $this->clock->now();
        $sourceChecksum = hash('sha256', $definition->checksum());
        $this->database->insert($this->tables->raw('business_projection_generations'), [
            'generation_id' => $generationId,
            'projection_id' => $definition->identifier(),
            'definition_checksum' => $definition->checksum(),
            'handler_version' => $definition->handlerVersion,
            'status' => 'building',
            'last_sequence' => 0,
            'source_checksum' => $sourceChecksum,
            'projection_checksum' => null,
            'created_at' => $now,
            'activated_at' => null,
            'superseded_at' => null,
            'updated_at' => $now,
        ], [
            'created_at' => Types::DATETIME_IMMUTABLE,
            'activated_at' => Types::DATETIME_IMMUTABLE,
            'superseded_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
        $this->definition = $definition;
        $this->generationId = $generationId;
        $this->mode = 'rebuild';
        $this->lastSequence = 0;
    }

    /**
     * Upsert one complete, typed row in the current writer generation.
     *
     * @param   array<string, bool|int|string>       $key     Complete composite key.
     * @param   array<string, bool|int|string|null>  $values  Complete projection row.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function put(array $key, array $values): void
    {
        $definition = $this->requireDefinition();
        $generationId = $this->requireGeneration();
        $this->assertRow($definition, $key, $values);
        $key = $this->canonicalObject($key);
        $values = $this->canonicalObject($values);
        $keyChecksum = CanonicalDefinitionJson::checksum($key);
        $now = $this->clock->now();
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET row_key = ?, row_values = ?, updated_at = ? '
            . 'WHERE generation_id = ? AND row_key_checksum = ?',
            $this->tables->quoted('business_projection_rows'),
        ), [$key, $values, $now, $generationId, $keyChecksum], [
            Types::JSON, Types::JSON, Types::DATETIME_IMMUTABLE, Types::GUID, Types::STRING,
        ]);
        if ((int) $affected !== 0) {
            return;
        }
        $this->database->insert($this->tables->raw('business_projection_rows'), [
            'generation_id' => $generationId,
            'projection_id' => $definition->identifier(),
            'row_key_checksum' => $keyChecksum,
            'row_key' => $key,
            'row_values' => $values,
            'updated_at' => $now,
        ], [
            'generation_id' => Types::GUID,
            'row_key' => Types::JSON,
            'row_values' => Types::JSON,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * Remove one row from the current writer generation.
     *
     * @param   array<string, bool|int|string>  $key  Complete composite key.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function remove(array $key): void
    {
        $definition = $this->requireDefinition();
        $generationId = $this->requireGeneration();
        $this->assertKey($definition, $key);
        $keyChecksum = CanonicalDefinitionJson::checksum($this->canonicalObject($key));
        $this->database->delete($this->tables->raw('business_projection_rows'), [
            'generation_id' => $generationId,
            'row_key_checksum' => $keyChecksum,
        ], [
            'generation_id' => Types::GUID,
            'row_key_checksum' => Types::STRING,
        ]);
    }

    /**
     * Persist the ordered source checkpoint in the current generation.
     *
     * @param   int     $sequence       Last applied journal sequence.
     * @param   string  $eventChecksum  Running source checksum.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function checkpoint(int $sequence, string $eventChecksum): void
    {
        $generationId = $this->requireGeneration();
        $status = $this->mode === 'live' ? 'active' : 'building';
        if (
            $sequence <= $this->lastSequence
            || preg_match('/^[0-9a-f]{64}$/D', $eventChecksum) !== 1
        ) {
            throw new InvalidArgumentException('A projection checkpoint is invalid or out of order.');
        }
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET last_sequence = ?, source_checksum = ?, updated_at = ? '
            . 'WHERE generation_id = ? AND status = ?',
            $this->tables->quoted('business_projection_generations'),
        ), [$sequence, $eventChecksum, $this->clock->now(), $generationId, $status], [
            Types::BIGINT, Types::STRING, Types::DATETIME_IMMUTABLE, Types::GUID, Types::STRING,
        ]);
        $this->assertOne($affected, 'The projection generation checkpoint is no longer writable.');
        $this->lastSequence = $sequence;
    }

    /**
     * Fence the immutable journal head and atomically activate the completed replacement generation.
     *
     * @return  string  Canonical active-row checksum.
     *
     * @since   2.0.0
     */
    public function commit(): string
    {
        if ($this->mode !== 'rebuild') {
            throw new RuntimeException('Only a replacement projection generation can be committed.');
        }
        $definition = $this->requireDefinition();
        $generationId = $this->requireGeneration();
        $checksum = $this->transactions->transactional(function () use ($definition, $generationId): string {
            $head = $this->lockJournalHead();
            if ($this->latestRelevantSequence($definition, $head) > $this->lastSequence) {
                throw new RuntimeException('The projection source advanced during rebuild; retry the rebuild.');
            }
            $checksum = $this->rowsChecksum($generationId);
            $now = $this->clock->now();
            $this->database->executeStatement(sprintf(
                "UPDATE %s SET status = 'superseded', superseded_at = ?, updated_at = ? "
                . "WHERE projection_id = ? AND status = 'active'",
                $this->tables->quoted('business_projection_generations'),
            ), [$now, $now, $definition->identifier()], [
                Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::STRING,
            ]);
            $affected = $this->database->executeStatement(sprintf(
                "UPDATE %s SET status = 'active', projection_checksum = ?, activated_at = ?, updated_at = ? "
                . "WHERE generation_id = ? AND projection_id = ? AND status = 'building'",
                $this->tables->quoted('business_projection_generations'),
            ), [$checksum, $now, $now, $generationId, $definition->identifier()], [
                Types::STRING, Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::GUID, Types::STRING,
            ]);
            $this->assertOne($affected, 'The replacement projection generation is no longer publishable.');

            return $checksum;
        });
        $this->clearState();

        return $checksum;
    }

    /**
     * Delete an incomplete replacement while leaving the active generation untouched.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function rollback(): void
    {
        if ($this->mode !== 'rebuild' || $this->generationId === null) {
            $this->clearState();
            return;
        }
        $generationId = $this->generationId;
        $this->transactions->transactional(function () use ($generationId): void {
            $this->database->delete(
                $this->tables->raw('business_projection_rows'),
                ['generation_id' => $generationId],
                ['generation_id' => Types::GUID],
            );
            $this->database->executeStatement(sprintf(
                "DELETE FROM %s WHERE generation_id = ? AND status = 'building'",
                $this->tables->quoted('business_projection_generations'),
            ), [$generationId], [Types::GUID]);
        });
        $this->clearState();
    }

    /**
     * Apply every missing source event through a builder inside the active generation transaction.
     *
     * @param   ProjectionDefinition  $definition     Exact active projection definition.
     * @param   ProjectionBuilder     $builder        Deterministic builder implementation.
     * @param   int                   $untilSequence  Journal sequence that triggered catch-up.
     *
     * @return  bool  True when a matching current active generation existed, including an idempotent replay.
     *
     * @since   2.0.0
     */
    public function catchUp(
        ProjectionDefinition $definition,
        ProjectionBuilder $builder,
        int $untilSequence,
    ): bool {
        $this->assertIdle();
        if ($untilSequence < 1) {
            throw new InvalidArgumentException('A live projection sequence must be positive.');
        }
        try {
            return $this->transactions->transactional(function () use (
                $definition,
                $builder,
                $untilSequence,
            ): bool {
                $row = $this->database->fetchAssociative(sprintf(
                    "SELECT generation_id, definition_checksum, last_sequence, source_checksum FROM %s "
                    . "WHERE projection_id = ? AND status = 'active'%s",
                    $this->tables->quoted('business_projection_generations'),
                    $this->lockClause(),
                ), [$definition->identifier()], [Types::STRING]);
                if ($row === false || ($row['definition_checksum'] ?? null) !== $definition->checksum()) {
                    return false;
                }
                $generationId = $this->requiredString($row, 'generation_id');
                $sequence = $this->integer($row, 'last_sequence');
                $sourceChecksum = $this->requiredChecksum($row, 'source_checksum');
                $this->definition = $definition;
                $this->generationId = $generationId;
                $this->mode = 'live';
                $this->lastSequence = $sequence;
                if ($sequence >= $untilSequence) {
                    return true;
                }

                $done = false;
                while (!$done) {
                    $page = $this->next($definition, $sequence, $definition->rebuildBatchSize);
                    if ($page === []) {
                        break;
                    }
                    foreach ($page as $event) {
                        if ($event->sequence() > $untilSequence) {
                            $done = true;
                            break;
                        }
                        $builder->apply($definition, $event, $this);
                        $sequence = $event->sequence();
                        $sourceChecksum = hash('sha256', $sourceChecksum . "\n" . $event->checksum());
                        $this->checkpoint($sequence, $sourceChecksum);
                    }
                }
                $checksum = $this->rowsChecksum($generationId);
                $affected = $this->database->executeStatement(sprintf(
                    "UPDATE %s SET projection_checksum = ?, updated_at = ? "
                    . "WHERE generation_id = ? AND status = 'active'",
                    $this->tables->quoted('business_projection_generations'),
                ), [$checksum, $this->clock->now(), $generationId], [
                    Types::STRING, Types::DATETIME_IMMUTABLE, Types::GUID,
                ]);
                $this->assertOne($affected, 'The live projection generation lost its activation fence.');

                return true;
            });
        } finally {
            $this->clearState();
        }
    }

    /**
     * Resolve the immutable journal sequence for an outbox event.
     *
     * @param   string  $eventId  Canonical integration event UUID.
     *
     * @return  int  Positive immutable source sequence.
     *
     * @since   2.0.0
     */
    public function eventSequence(string $eventId): int
    {
        if (!Uuid::isValid($eventId) || strtolower($eventId) !== $eventId) {
            throw new InvalidArgumentException('A projection source event ID must be a canonical lowercase UUID.');
        }
        $sequence = $this->database->fetchOne(sprintf(
            'SELECT source_sequence FROM %s WHERE event_id = ?',
            $this->tables->quoted('business_projection_source_events'),
        ), [$eventId], [Types::GUID]);
        if ($sequence === false) {
            throw new RuntimeException('A durable outbox event is missing from the projection source journal.');
        }

        return $this->positiveInteger($sequence, 'projection source sequence');
    }

    /**
     * Return persisted active-generation evidence for one projection.
     *
     * @param   string  $projectionId  Namespaced projection identifier.
     *
     * @return  ?array<string, mixed>  Active generation evidence, or null before its first rebuild.
     *
     * @since   2.0.0
     */
    public function activeStatus(string $projectionId): ?array
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT generation_id, definition_checksum, handler_version, last_sequence, source_checksum, '
            . 'projection_checksum, created_at, activated_at, updated_at FROM %s '
            . "WHERE projection_id = ? AND status = 'active'",
            $this->tables->quoted('business_projection_generations'),
        ), [$projectionId], [Types::STRING]);

        return $row === false ? null : $row;
    }

    /**
     * Compile the definition's closed event-source set into a bound SQL predicate.
     *
     * @param   ProjectionDefinition  $definition  Exact projection source contract.
     *
     * @return  array{0: string, 1: list<mixed>, 2: list<string>}  SQL, parameters and DBAL types.
     *
     * @since   2.0.0
     */
    private function sourcePredicate(ProjectionDefinition $definition): array
    {
        $parts = [];
        $parameters = [];
        $types = [];
        foreach ($definition->sources as $source) {
            $placeholders = implode(', ', array_fill(0, count($source->schemaVersions), '?'));
            $parts[] = sprintf('(event_type = ? AND schema_version IN (%s))', $placeholders);
            $parameters[] = $source->eventType;
            $types[] = Types::STRING;
            foreach ($source->schemaVersions as $version) {
                $parameters[] = $version;
                $types[] = Types::INTEGER;
            }
        }

        return [implode(' OR ', $parts), $parameters, $types];
    }

    /**
     * Verify and convert one immutable source row into a deterministic builder event.
     *
     * @param   ProjectionDefinition  $definition  Projection whose ceiling constrains the event.
     * @param   array<string, mixed>  $row         Durable source row.
     *
     * @return  ProjectionEvent  Validated deterministic builder input.
     *
     * @since   2.0.0
     */
    private function projectionEvent(ProjectionDefinition $definition, array $row): ProjectionEvent
    {
        $sequence = $this->positiveInteger($row['source_sequence'] ?? null, 'projection source sequence');
        $envelope = $this->jsonObject($row['envelope'] ?? null, 'projection source envelope');
        $expectedChecksum = $this->requiredChecksum($row, 'event_checksum');
        if (!hash_equals($expectedChecksum, CanonicalJson::digest($envelope))) {
            throw new RuntimeException('A projection source envelope checksum does not match.');
        }
        $event = RecordedIntegrationEvent::fromArray($envelope);
        if (
            $event->eventId() !== ($row['event_id'] ?? null)
            || $event->eventType() !== ($row['event_type'] ?? null)
            || $event->schemaVersion() !== $this->positiveInteger(
                $row['schema_version'] ?? null,
                'projection schema version',
            )
            || $event->sensitivity()->value !== ($row['sensitivity'] ?? null)
            || !$event->sensitivity()->allowedBy($definition->sensitivityCeiling)
        ) {
            throw new RuntimeException('A projection source row contradicts its immutable envelope.');
        }

        return new JournalProjectionEvent(
            $sequence,
            $event->eventId(),
            $event->eventType(),
            $event->schemaVersion(),
            $event->occurredAt(),
            $event->payload(),
        );
    }

    /**
     * Require a complete, exactly typed composite projection key.
     *
     * @param   ProjectionDefinition            $definition  Exact projection field contract.
     * @param   array<string, bool|int|string>  $key         Candidate composite key.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertKey(ProjectionDefinition $definition, array $key): void
    {
        $actual = array_keys($key);
        $expected = $definition->keyFields;
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException('A projection row key must contain every declared key field.');
        }
        $fields = $this->fields($definition);
        foreach ($key as $name => $value) {
            if (!isset($fields[$name]) || !$fields[$name]->type->accepts($value)) {
                throw new InvalidArgumentException('A projection row key value has the wrong declared type.');
            }
        }
    }

    /**
     * Require a complete row whose values conform to the projection definition and key.
     *
     * @param   ProjectionDefinition                 $definition  Exact projection field contract.
     * @param   array<string, bool|int|string>       $key         Complete candidate composite key.
     * @param   array<string, bool|int|string|null>  $values      Complete candidate row values.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertRow(ProjectionDefinition $definition, array $key, array $values): void
    {
        $this->assertKey($definition, $key);
        $fields = $this->fields($definition);
        $actual = array_keys($values);
        $expected = array_keys($fields);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException('A projection row must contain every declared field and no others.');
        }
        foreach ($fields as $name => $field) {
            $value = $values[$name];
            if (($value === null && !$field->nullable) || ($value !== null && !$field->type->accepts($value))) {
                throw new InvalidArgumentException('A projection row value has the wrong declared type.');
            }
        }
        foreach ($key as $name => $value) {
            if ($values[$name] !== $value) {
                throw new InvalidArgumentException('A projection row value contradicts its composite key.');
            }
        }
    }

    /**
     * Index the definition's fields by their unique declared handles.
     *
     * @param   ProjectionDefinition  $definition  Exact projection field contract.
     *
     * @return  array<string, ProjectionFieldDefinition>  Field definitions by handle.
     *
     * @since   2.0.0
     */
    private function fields(ProjectionDefinition $definition): array
    {
        $fields = [];
        foreach ($definition->fields as $field) {
            $fields[$field->name] = $field;
        }

        return $fields;
    }

    /**
     * Sort an object-shaped scalar map into its canonical key order.
     *
     * @template T of bool|int|string|null
     *
     * @param   array<string, T>  $value  Object-shaped scalar map.
     *
     * @return  array<string, T>  Same values in canonical string-key order.
     *
     * @since   2.0.0
     */
    private function canonicalObject(array $value): array
    {
        ksort($value, SORT_STRING);

        return $value;
    }

    /**
     * Hash the canonical key-sorted rows in one durable generation.
     *
     * @param   string  $generationId  Canonical generation UUID.
     *
     * @return  string  Lowercase SHA-256 projection checksum.
     *
     * @since   2.0.0
     */
    private function rowsChecksum(string $generationId): string
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT row_key, row_values FROM %s WHERE generation_id = ? ORDER BY row_key_checksum',
            $this->tables->quoted('business_projection_rows'),
        ), [$generationId], [Types::GUID]);
        $canonical = [];
        foreach ($rows as $row) {
            $canonical[] = [
                'key' => $this->jsonObject($row['row_key'] ?? null, 'projection row key'),
                'values' => $this->jsonObject($row['row_values'] ?? null, 'projection row values'),
            ];
        }

        return CanonicalDefinitionJson::checksum($canonical);
    }

    /**
     * Lock and read the serialization fence shared with source-event append transactions.
     *
     * @return  int  Latest fully committed immutable source sequence, or zero for an empty journal.
     *
     * @since   2.0.0
     */
    private function lockJournalHead(): int
    {
        $value = $this->database->fetchOne(sprintf(
            'SELECT last_sequence FROM %s WHERE singleton_id = 1%s',
            $this->tables->quoted('business_projection_event_head'),
            $this->lockClause(),
        ));
        if ($value === false) {
            throw new RuntimeException('The projection source journal head is unavailable.');
        }
        if ($value === 0 || $value === '0') {
            return 0;
        }

        return $this->positiveInteger($value, 'projection source journal head');
    }

    /**
     * Find the latest compatible event no newer than a locked journal head.
     *
     * @param   ProjectionDefinition  $definition  Exact projection source contract.
     * @param   int                   $head        Locked global source sequence ceiling.
     *
     * @return  int  Latest relevant sequence, or zero when the source is empty.
     *
     * @since   2.0.0
     */
    private function latestRelevantSequence(ProjectionDefinition $definition, int $head): int
    {
        [$predicate, $parameters, $types] = $this->sourcePredicate($definition);
        array_unshift($parameters, $head);
        array_unshift($types, Types::BIGINT);
        $value = $this->database->fetchOne(sprintf(
            'SELECT MAX(source_sequence) FROM %s WHERE source_sequence <= ? AND (%s)',
            $this->tables->quoted('business_projection_source_events'),
            $predicate,
        ), $parameters, $types);

        return $value === false || $value === null ? 0 : $this->positiveInteger($value, 'latest projection sequence');
    }

    /**
     * Return the platform-specific row-lock suffix used for projection fences.
     *
     * @return  string  `FOR UPDATE` on supported production platforms, otherwise an empty suffix.
     *
     * @since   2.0.0
     */
    private function lockClause(): string
    {
        $platform = $this->database->getDatabasePlatform();

        return $platform instanceof PostgreSQLPlatform || $platform instanceof AbstractMySQLPlatform
            ? ' FOR UPDATE'
            : '';
    }

    /**
     * Read one required non-empty string from a durable projection row.
     *
     * @param   array<string, mixed>  $row    Durable database row.
     * @param   string                $field  Required field name.
     *
     * @return  string  Validated non-empty string.
     *
     * @since   2.0.0
     */
    private function requiredString(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Projection field "%s" is invalid.', $field));
        }

        return $value;
    }

    /**
     * Read one required lowercase SHA-256 checksum from a durable projection row.
     *
     * @param   array<string, mixed>  $row    Durable database row.
     * @param   string                $field  Required checksum field name.
     *
     * @return  string  Validated lowercase SHA-256 checksum.
     *
     * @since   2.0.0
     */
    private function requiredChecksum(array $row, string $field): string
    {
        $value = $this->requiredString($row, $field);
        if (preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            throw new RuntimeException(sprintf('Projection checksum field "%s" is invalid.', $field));
        }

        return $value;
    }

    /**
     * Read a non-negative integer from a durable projection row.
     *
     * @param   array<string, mixed>  $row    Durable database row.
     * @param   string                $field  Required integer field name.
     *
     * @return  int  Parsed non-negative integer.
     *
     * @since   2.0.0
     */
    private function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1) {
            throw new RuntimeException(sprintf('Projection integer field "%s" is invalid.', $field));
        }

        return (int) $value;
    }

    /**
     * Normalize a DBAL scalar into a positive integer.
     *
     * @param   mixed   $value  Raw DBAL scalar.
     * @param   string  $field  Safe diagnostic label.
     *
     * @return  int  Positive integer value.
     *
     * @since   2.0.0
     */
    private function positiveInteger(mixed $value, string $field): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            return (int) $value;
        }
        throw new RuntimeException(sprintf('The %s is invalid.', $field));
    }

    /**
     * Decode and require an object-shaped JSON value.
     *
     * @param   mixed   $value  Raw DBAL JSON value or decoded array.
     * @param   string  $field  Safe diagnostic label.
     *
     * @return  array<string, mixed>  Decoded object-shaped value.
     *
     * @since   2.0.0
     */
    private function jsonObject(mixed $value, string $field): array
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException(sprintf('The %s contains invalid JSON.', $field), 0, $exception);
            }
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new RuntimeException(sprintf('The %s must be a JSON object.', $field));
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Return the definition bound to the current writer session.
     *
     * @return  ProjectionDefinition  Active writer definition.
     *
     * @since   2.0.0
     */
    private function requireDefinition(): ProjectionDefinition
    {
        if ($this->definition === null) {
            throw new RuntimeException('No projection writer generation is active.');
        }

        return $this->definition;
    }

    /**
     * Return the generation identifier bound to the current writer session.
     *
     * @return  string  Canonical active writer generation UUID.
     *
     * @since   2.0.0
     */
    private function requireGeneration(): string
    {
        if ($this->generationId === null) {
            throw new RuntimeException('No projection writer generation is active.');
        }

        return $this->generationId;
    }

    /**
     * Refuse a second operation on a stateful writer session already in use.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertIdle(): void
    {
        if ($this->mode !== null) {
            throw new RuntimeException('A projection writer session is already active.');
        }
    }

    /**
     * Require an exactly-one-row fenced persistence result.
     *
     * @param   int|string  $affected  DBAL affected-row count.
     * @param   string      $message   Safe failure message.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertOne(int|string $affected, string $message): void
    {
        if ((string) $affected !== '1') {
            throw new RuntimeException($message);
        }
    }

    /**
     * Return this store instance to its idle state after success or failure.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function clearState(): void
    {
        $this->definition = null;
        $this->generationId = null;
        $this->mode = null;
        $this->lastSequence = 0;
    }
}
