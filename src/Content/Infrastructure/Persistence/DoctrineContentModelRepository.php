<?php

declare(strict_types=1);

namespace Kumwe\App\Content\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use JsonException;
use LogicException;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Content\Application\ContentModelRepository;
use Kumwe\App\Content\Domain\ContentTypeDefinition;
use Kumwe\App\Content\Domain\VersionConflict;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Workflow\Domain\WorkflowDefinition;
use Kumwe\App\Workflow\Domain\WorkflowStateDefinition;
use Kumwe\App\Workflow\Domain\WorkflowTransitionDefinition;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Doctrine DBAL implementation of the content model repository, over paired head and history tables.
 *
 * Every content type and workflow keeps one head row — in `content_types` and `workflows` — naming the
 * version currently published, beside an append-only history in `content_type_definition_versions` and
 * `workflow_definition_versions`. Reads join the head to a version row, so loading a pinned version
 * costs no more than loading the current one and an entry authored against version three keeps
 * resolving long after version four ships. Publication moves the head with an UPDATE filtered on the
 * version the caller read, reporting `VersionConflict` when it matches no row, and refuses to run
 * without an open transaction so the head move and its version row cannot land apart. Driver rows are
 * untyped, so each column is checked as it is mapped and malformed storage is refused here instead of
 * reaching the application layer.
 *
 * @since  2.0.0
 */
final readonly class DoctrineContentModelRepository implements ContentModelRepository
{
    /**
     * Binds the repository to the connection and table-name resolver it works through.
     *
     * @param  Connection  $database  DBAL connection every content model statement runs on.
     * @param  TableNames  $tables    Resolver applying the configured prefix to the model tables.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * Reads the head version of every content type published for a site.
     *
     * @param   SiteContext  $site  Site whose content model is being listed.
     *
     * @return  list<ContentTypeDefinition>  Ordered by handle so administrator pickers are stable; empty
     *          when the site has published no content types.
     *
     * @throws  RuntimeException  When a stored definition row lacks a column or holds the wrong type.
     *
     * @since   2.0.0
     */
    public function contentTypes(SiteContext $site): array
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT v.* FROM %s v INNER JOIN %s h ON h.id = v.content_type_id AND h.version = v.version '
            . 'WHERE h.site_identifier = ? ORDER BY h.handle ASC',
            $this->tables->quoted('content_type_definition_versions'),
            $this->tables->quoted('content_types'),
        ), [$site->identifier()]);
        return array_map($this->mapContentType(...), $rows);
    }

    /**
     * Reads one content type definition, at its head or at a specific published version.
     *
     * An identifier that parses as a UUID is matched against the id and the handle alike, so an
     * installation whose handles happen to look like UUIDs still resolves either way.
     *
     * @param   SiteContext  $site        Site the definition must belong to.
     * @param   string       $identifier  UUID or operator-facing handle of the content type.
     * @param   ?int         $version     Version to load, or null for the site's current head.
     *
     * @return  ?ContentTypeDefinition  Null when no row joins that site, identifier and version.
     *
     * @throws  RuntimeException  When the stored definition row lacks a column or holds the wrong type.
     *
     * @since   2.0.0
     */
    public function contentType(SiteContext $site, string $identifier, ?int $version = null): ?ContentTypeDefinition
    {
        $identity = Uuid::isValid($identifier) ? '(h.id = ? OR h.handle = ?)' : 'h.handle = ?';
        $sql = sprintf(
            'SELECT v.* FROM %s v INNER JOIN %s h ON h.id = v.content_type_id '
            . 'WHERE h.site_identifier = ? AND %s AND v.version = %s',
            $this->tables->quoted('content_type_definition_versions'),
            $this->tables->quoted('content_types'),
            $identity,
            $version === null ? 'h.version' : '?',
        );
        $parameters = Uuid::isValid($identifier)
            ? [$site->identifier(), $identifier, $identifier]
            : [$site->identifier(), $identifier];
        if ($version !== null) {
            $parameters[] = $version;
        }
        $row = $this->database->fetchAssociative($sql, $parameters);
        return $row === false ? null : $this->mapContentType($row);
    }

    /**
     * Writes the head row for a brand new content type together with its first history row.
     *
     * @param   ContentTypeDefinition  $definition  Definition to store; its version is written as
     *          supplied, so the caller owns where the version sequence starts.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function insertContentType(ContentTypeDefinition $definition): void
    {
        $this->database->insert($this->tables->raw('content_types'), [
            'id' => $definition->id,
            'site_identifier' => $definition->site->identifier(),
            'workflow_id' => $definition->workflowId,
            'handle' => $definition->handle,
            'name' => $definition->name,
            'field_schema' => $definition->schema(),
            'version' => $definition->version,
            'created_at' => $definition->createdAt,
            'updated_at' => $definition->publishedAt,
        ], [
            'field_schema' => Types::JSON,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
        $this->insertContentTypeVersion($definition);
    }

    /**
     * Moves a content type's head onto the supplied definition and appends the matching history row.
     *
     * The UPDATE is filtered on the version the caller read, so a competing publication is detected by
     * the affected-row count rather than by holding a lock across the operator's edit. Both statements
     * must share a transaction the caller has already opened, otherwise a rejected head move would
     * leave an orphaned version row behind.
     *
     * @param   ContentTypeDefinition  $definition       Definition carrying the already-incremented version.
     * @param   int                    $expectedVersion  Version the caller read before editing.
     *
     * @return  void
     *
     * @throws  LogicException  When no transaction is open, so the two writes could not be made atomic.
     * @throws  VersionConflict  When the head no longer carries the expected version, or has been removed.
     *
     * @since   2.0.0
     */
    public function publishContentType(ContentTypeDefinition $definition, int $expectedVersion): void
    {
        if (!$this->database->isTransactionActive()) {
            throw new LogicException('Content type publication requires an active transaction.');
        }
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET workflow_id = ?, name = ?, field_schema = ?, version = ?, updated_at = ? '
            . 'WHERE id = ? AND site_identifier = ? AND version = ?',
            $this->tables->quoted('content_types'),
        ), [
            $definition->workflowId,
            $definition->name,
            $definition->schema(),
            $definition->version,
            $definition->publishedAt,
            $definition->id,
            $definition->site->identifier(),
            $expectedVersion,
        ], [
            Types::GUID,
            Types::STRING,
            Types::JSON,
            Types::INTEGER,
            Types::DATETIME_IMMUTABLE,
            Types::GUID,
            Types::STRING,
            Types::INTEGER,
        ]);
        if ((string) $affected !== '1') {
            throw new VersionConflict($expectedVersion, $this->headVersion('content_types', $definition->id));
        }
        $this->insertContentTypeVersion($definition);
    }

    /**
     * Reads the head version of every workflow published for a site.
     *
     * @param   SiteContext  $site  Site whose workflows are being listed.
     *
     * @return  list<WorkflowDefinition>  Ordered by handle so administrator pickers are stable; empty
     *          when the site has published no workflows.
     *
     * @throws  RuntimeException  When a stored definition row lacks a column or holds the wrong type.
     *
     * @since   2.0.0
     */
    public function workflows(SiteContext $site): array
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT v.* FROM %s v INNER JOIN %s h ON h.id = v.workflow_id AND h.version = v.version '
            . 'WHERE h.site_identifier = ? ORDER BY h.handle ASC',
            $this->tables->quoted('workflow_definition_versions'),
            $this->tables->quoted('workflows'),
        ), [$site->identifier()]);
        return array_map($this->mapWorkflow(...), $rows);
    }

    /**
     * Reads one workflow definition, at its head or at a specific published version.
     *
     * Content entries pin the workflow version they were authored against, so loading an older version
     * is the ordinary path here rather than an administrative exception.
     *
     * @param   SiteContext  $site        Site the definition must belong to.
     * @param   string       $identifier  UUID or operator-facing handle of the workflow.
     * @param   ?int         $version     Version to load, or null for the site's current head.
     *
     * @return  ?WorkflowDefinition  Null when no row joins that site, identifier and version.
     *
     * @throws  RuntimeException  When the stored definition row lacks a column or holds the wrong type.
     *
     * @since   2.0.0
     */
    public function workflow(SiteContext $site, string $identifier, ?int $version = null): ?WorkflowDefinition
    {
        $identity = Uuid::isValid($identifier) ? '(h.id = ? OR h.handle = ?)' : 'h.handle = ?';
        $sql = sprintf(
            'SELECT v.* FROM %s v INNER JOIN %s h ON h.id = v.workflow_id '
            . 'WHERE h.site_identifier = ? AND %s AND v.version = %s',
            $this->tables->quoted('workflow_definition_versions'),
            $this->tables->quoted('workflows'),
            $identity,
            $version === null ? 'h.version' : '?',
        );
        $parameters = Uuid::isValid($identifier)
            ? [$site->identifier(), $identifier, $identifier]
            : [$site->identifier(), $identifier];
        if ($version !== null) {
            $parameters[] = $version;
        }
        $row = $this->database->fetchAssociative($sql, $parameters);
        return $row === false ? null : $this->mapWorkflow($row);
    }

    /**
     * Writes the head row for a brand new workflow together with its first history row.
     *
     * @param   WorkflowDefinition  $definition  Definition to store; its version is written as supplied,
     *          so the caller owns where the version sequence starts.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function insertWorkflow(WorkflowDefinition $definition): void
    {
        $this->database->insert($this->tables->raw('workflows'), [
            'id' => $definition->id,
            'site_identifier' => $definition->site->identifier(),
            'handle' => $definition->handle,
            'name' => $definition->name,
            'version' => $definition->version,
            'created_at' => $definition->createdAt,
            'updated_at' => $definition->publishedAt,
        ], ['created_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE]);
        $this->insertWorkflowVersion($definition);
    }

    /**
     * Moves a workflow's head onto the supplied definition and appends the matching history row.
     *
     * The head row carries no state or transition columns, so only the name and version are rewritten
     * here; the shape of the workflow lives entirely in the appended history row. As with content
     * types, the caller must already hold a transaction open.
     *
     * @param   WorkflowDefinition  $definition       Definition carrying the already-incremented version.
     * @param   int                 $expectedVersion  Version the caller read before editing.
     *
     * @return  void
     *
     * @throws  LogicException  When no transaction is open, so the two writes could not be made atomic.
     * @throws  VersionConflict  When the head no longer carries the expected version, or has been removed.
     *
     * @since   2.0.0
     */
    public function publishWorkflow(WorkflowDefinition $definition, int $expectedVersion): void
    {
        if (!$this->database->isTransactionActive()) {
            throw new LogicException('Workflow publication requires an active transaction.');
        }
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET name = ?, version = ?, updated_at = ? WHERE id = ? AND site_identifier = ? AND version = ?',
            $this->tables->quoted('workflows'),
        ), [
            $definition->name,
            $definition->version,
            $definition->publishedAt,
            $definition->id,
            $definition->site->identifier(),
            $expectedVersion,
        ], [
            Types::STRING,
            Types::INTEGER,
            Types::DATETIME_IMMUTABLE,
            Types::GUID,
            Types::STRING,
            Types::INTEGER,
        ]);
        if ((string) $affected !== '1') {
            throw new VersionConflict($expectedVersion, $this->headVersion('workflows', $definition->id));
        }
        $this->insertWorkflowVersion($definition);
    }

    /**
     * Appends one immutable row to the content type history.
     *
     * The history row denormalises the handle, site and pinned workflow version alongside the schema,
     * so an old definition can be rebuilt from history alone without consulting the head row that has
     * since moved on.
     *
     * @param   ContentTypeDefinition  $definition  Definition to record under its own version number.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function insertContentTypeVersion(ContentTypeDefinition $definition): void
    {
        $this->database->insert($this->tables->raw('content_type_definition_versions'), [
            'content_type_id' => $definition->id,
            'version' => $definition->version,
            'site_identifier' => $definition->site->identifier(),
            'handle' => $definition->handle,
            'name' => $definition->name,
            'workflow_id' => $definition->workflowId,
            'workflow_version' => $definition->workflowVersion,
            'validation_schema' => $definition->schema(),
            'created_at' => $definition->createdAt,
            'published_at' => $definition->publishedAt,
        ], [
            'validation_schema' => Types::JSON,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'published_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * Appends one immutable row to the workflow history.
     *
     * States and transitions are stored as JSON documents, and the keys of the publicly visible states
     * are duplicated into a column of their own so delivery queries can decide visibility without
     * decoding the whole state list.
     *
     * @param   WorkflowDefinition  $definition  Definition to record under its own version number.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function insertWorkflowVersion(WorkflowDefinition $definition): void
    {
        $publicStates = [];
        foreach ($definition->states() as $state) {
            if ($state->public) {
                $publicStates[] = $state->key;
            }
        }
        $this->database->insert($this->tables->raw('workflow_definition_versions'), [
            'workflow_id' => $definition->id,
            'version' => $definition->version,
            'site_identifier' => $definition->site->identifier(),
            'handle' => $definition->handle,
            'name' => $definition->name,
            'states' => array_map(
                static fn (WorkflowStateDefinition $state): array => $state->toArray(),
                $definition->states(),
            ),
            'transitions' => array_map(
                static fn (WorkflowTransitionDefinition $transition): array => $transition->toArray(),
                $definition->transitions(),
            ),
            'public_states' => $publicStates,
            'created_at' => $definition->createdAt,
            'published_at' => $definition->publishedAt,
        ], [
            'states' => Types::JSON,
            'transitions' => Types::JSON,
            'public_states' => Types::JSON,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'published_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * Rebuilds a content type definition from a joined head and history row.
     *
     * @param   array<string, mixed>  $row  Associative row selected from the content type history table.
     *
     * @return  ContentTypeDefinition  The definition exactly as it was published at that version.
     *
     * @throws  RuntimeException  When a required column is absent, empty, or holds the wrong type.
     *
     * @since   2.0.0
     */
    private function mapContentType(array $row): ContentTypeDefinition
    {
        return new ContentTypeDefinition(
            $this->string($row, 'content_type_id'),
            SiteContext::fromString($this->string($row, 'site_identifier')),
            $this->string($row, 'handle'),
            $this->string($row, 'name'),
            $this->string($row, 'workflow_id'),
            $this->integer($row, 'workflow_version'),
            $this->jsonObject($row, 'validation_schema'),
            $this->integer($row, 'version'),
            $this->date($row['created_at'] ?? null),
            $this->date($row['published_at'] ?? null),
        );
    }

    /**
     * Rebuilds a workflow definition, decoding its stored state and transition documents.
     *
     * The `public_states` column written alongside them is a query convenience and is not read back
     * here; visibility is recovered from each state's own flag so the two can never disagree.
     *
     * @param   array<string, mixed>  $row  Associative row selected from the workflow history table.
     *
     * @return  WorkflowDefinition  The definition exactly as it was published at that version.
     *
     * @throws  RuntimeException  When a required column is absent or wrongly typed, or a stored state
     *          or transition is not a JSON object.
     *
     * @since   2.0.0
     */
    private function mapWorkflow(array $row): WorkflowDefinition
    {
        $states = [];
        foreach ($this->jsonList($row, 'states') as $state) {
            if (!is_array($state)) {
                throw new RuntimeException('Stored workflow state is invalid.');
            }
            /** @var array<string, mixed> $state */
            $states[] = new WorkflowStateDefinition(
                $this->string($state, 'key'),
                $this->string($state, 'name'),
                $this->boolean($state, 'initial'),
                $this->boolean($state, 'public'),
            );
        }
        $transitions = [];
        foreach ($this->jsonList($row, 'transitions') as $transition) {
            if (!is_array($transition)) {
                throw new RuntimeException('Stored workflow transition is invalid.');
            }
            /** @var array<string, mixed> $transition */
            $transitions[] = new WorkflowTransitionDefinition(
                $this->string($transition, 'from'),
                $this->string($transition, 'to'),
                Capability::fromString($this->string($transition, 'required_capability')),
            );
        }
        return new WorkflowDefinition(
            $this->string($row, 'workflow_id'),
            SiteContext::fromString($this->string($row, 'site_identifier')),
            $this->string($row, 'handle'),
            $this->string($row, 'name'),
            $states,
            $transitions,
            $this->integer($row, 'version'),
            $this->date($row['created_at'] ?? null),
            $this->date($row['published_at'] ?? null),
        );
    }

    /**
     * Reads the version a head row carries right now, to describe a lost publication race.
     *
     * This runs only on the failure path, after a conditional UPDATE matched nothing, so it reports
     * rather than decides: a missing row and an unreadable version are both flattened to zero so the
     * conflict can still be raised with a concrete number.
     *
     * @param   string  $table  Unprefixed head table to read, `content_types` or `workflows`.
     * @param   string  $id     UUID of the definition whose current head version is wanted.
     *
     * @return  int  The stored head version, or zero when the row is gone or holds no readable number.
     *
     * @since   2.0.0
     */
    private function headVersion(string $table, string $id): int
    {
        $value = $this->database->fetchOne(
            sprintf('SELECT version FROM %s WHERE id = ?', $this->tables->quoted($table)),
            [$id],
        );
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Reads a column that has to hold a non-empty string.
     *
     * @param   array<string, mixed>  $row  Associative row being mapped.
     * @param   string                $key  Column name to read.
     *
     * @return  string  The column value, guaranteed non-empty.
     *
     * @throws  RuntimeException  When the column is absent, empty, or not a string.
     *
     * @since   2.0.0
     */
    private function string(array $row, string $key): string
    {
        if (!isset($row[$key]) || !is_string($row[$key]) || $row[$key] === '') {
            throw new RuntimeException('Stored definition ' . $key . ' is invalid.');
        }
        return $row[$key];
    }

    /**
     * Reads a column that has to hold an integer, accepting the digit strings some drivers hand back.
     *
     * @param   array<string, mixed>  $row  Associative row being mapped.
     * @param   string                $key  Column name to read.
     *
     * @return  int  The column value as an integer.
     *
     * @throws  RuntimeException  When the column is absent, or holds neither an integer nor a run of
     *          digits.
     *
     * @since   2.0.0
     */
    private function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) {
            throw new RuntimeException('Stored definition ' . $key . ' is invalid.');
        }
        return (int) $value;
    }

    /**
     * Reads a flag that has to be a genuine boolean.
     *
     * Unlike the integer reader this makes no allowance for a driver's `0` and `1`, because these flags
     * only ever arrive from a decoded JSON document rather than from a column of their own; anything
     * else means the stored workflow document is malformed.
     *
     * @param   array<string, mixed>  $row  Decoded state document being mapped.
     * @param   string                $key  Key to read.
     *
     * @return  bool  The flag as stored.
     *
     * @throws  RuntimeException  When the key is absent or holds anything other than a boolean.
     *
     * @since   2.0.0
     */
    private function boolean(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;
        if (!is_bool($value)) {
            throw new RuntimeException('Stored definition ' . $key . ' is invalid.');
        }

        return $value;
    }

    /**
     * Decodes a JSON column that has to hold an object, such as a stored field schema.
     *
     * @param   array<string, mixed>  $row  Associative row being mapped.
     * @param   string                $key  Column name to read.
     *
     * @return  array<string, mixed>  The decoded document; empty when the column held an empty object.
     *
     * @throws  RuntimeException  When the column is not valid JSON, or decodes to a list rather than to
     *          an object.
     *
     * @since   2.0.0
     */
    private function jsonObject(array $row, string $key): array
    {
        $value = $this->json($row[$key] ?? null);
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new RuntimeException('Stored definition ' . $key . ' must be a JSON object.');
        }
        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Decodes a JSON column that has to hold a list, such as stored states or transitions.
     *
     * @param   array<string, mixed>  $row  Associative row being mapped.
     * @param   string                $key  Column name to read.
     *
     * @return  list<mixed>  The entries in stored order; individual entries are still unchecked here and
     *          the caller validates each one.
     *
     * @throws  RuntimeException  When the column is not valid JSON, or decodes to an object rather than
     *          to a list.
     *
     * @since   2.0.0
     */
    private function jsonList(array $row, string $key): array
    {
        $value = $this->json($row[$key] ?? null);
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException('Stored definition ' . $key . ' must be a JSON list.');
        }
        /** @var list<mixed> $value */
        return $value;
    }

    /**
     * Decodes a JSON column, passing through whatever the driver already decoded for itself.
     *
     * Doctrine's JSON type hands back a decoded structure while a plain text column hands back the raw
     * string, so both shapes reach here and only the string is parsed. The decoder's failure is
     * translated into the repository's own exception so no `JsonException` escapes this adapter.
     *
     * @param   mixed  $value  Raw column value: a JSON string, or a structure the driver already decoded.
     *
     * @return  mixed  The decoded value, or the input unchanged when it was not a string.
     *
     * @throws  RuntimeException  When the string is not valid JSON or nests deeper than 64 levels.
     *
     * @since   2.0.0
     */
    private function json(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        try {
            return json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored definition JSON is invalid.', 0, $exception);
        }
    }

    /**
     * Normalises whatever the driver returned for a timestamp column into an immutable date.
     *
     * Drivers differ: some hydrate a date object, others hand back the raw string, so both are accepted
     * rather than pinning the mapper to one platform. A bare string is read as UTC, which is the zone
     * every definition timestamp is written in.
     *
     * @param   mixed  $value  Raw timestamp column value from a definition row.
     *
     * @return  DateTimeImmutable  The timestamp, converted when the driver returned another date type.
     *
     * @throws  RuntimeException  When the value is neither a date object nor a string.
     * @throws  \DateMalformedStringException  When the string cannot be read as a date.
     *
     * @since   2.0.0
     */
    private function date(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (is_string($value)) {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        }
        throw new RuntimeException('Stored definition timestamp is invalid.');
    }
}
