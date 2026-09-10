<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\IdentityStrategy;
use Kumwe\App\BusinessDefinition\Domain\RelationshipDefinition;
use Kumwe\App\BusinessDefinition\Domain\RelationshipKind;
use Kumwe\App\BusinessDefinition\Domain\Sensitivity;
use Kumwe\App\BusinessRecord\Application\BusinessRecordReadRepository;
use Kumwe\App\BusinessRecord\Application\BusinessRecordRelationView;
use Kumwe\App\BusinessRecord\Application\BusinessRecordMutationFence;
use Kumwe\App\BusinessRecord\Application\BusinessRecordView;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\InvalidBusinessRecordQuery;
use Kumwe\App\BusinessRecord\Application\RecordBrowseResult;
use Kumwe\App\BusinessRecord\Application\RecordCursorCodec;
use Kumwe\App\BusinessRecord\Application\RecordFieldVisibility;
use Kumwe\App\BusinessRecord\Application\RecordRuleValidator;
use Kumwe\App\BusinessRecord\Application\RecordValueCodec;
use Kumwe\App\BusinessRecord\Application\ResolvedBusinessDefinition;
use Kumwe\App\BusinessRecord\Application\StoredOwnedLine;
use Kumwe\App\BusinessRecord\Application\StoredRecordIdentity;
use Kumwe\App\BusinessRecord\Domain\BusinessRecord;
use Kumwe\App\BusinessRecord\Domain\RecordScope;
use Kumwe\Extension\Spi\BusinessRecord\Query\CursorPosition;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessPlan;
use Kumwe\Extension\Spi\BusinessSecurity\Application\FieldAccessUsage;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaInstallationRepository;
use Kumwe\App\BusinessSchema\Domain\PhysicalTableBlueprint;
use Kumwe\App\BusinessSchema\Domain\SchemaEvolutionHints;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallationStatus;

/**
 * Reads business records straight off the physical tables an installation generated, over DBAL.
 *
 * This is the `BusinessRecordReadRepository` the runtime wires. Every statement it runs is assembled
 * from the `PhysicalTableBlueprint` carried by the resolved definition it is handed, so an identifier
 * only reaches SQL because the installed blueprint named it, and every caller-supplied value travels in
 * the bound parameter list beside its column's Doctrine type. The requested scope arrives as an
 * argument and becomes a predicate on each statement rather than a filter applied afterwards, which is
 * what keeps one tenant's rows out of another's page. A scan decodes each row against the definition
 * version that row is pinned to rather than the installed one — `pinnedForRow()` re-resolves a
 * published definition whenever a row disagrees — so one page may legitimately span several shapes,
 * while a single-record read instead refuses a row the caller has not already pinned correctly.
 * Relationship includes and entity-reference identities are resolved once per page rather than per row,
 * and reaching a relationship target takes a shared `BusinessRecordMutationFence` so an installer
 * cannot move that target's schema mid-read. Compiling a browse belongs to
 * `DoctrineBusinessRecordQueryCompiler` and writing to `DoctrineBusinessRecordWriteRepository`.
 * Anything the installed schema cannot answer is refused as `BusinessRecordSchemaUnavailable`; unlike
 * `DoctrineBusinessRecordMutationFence` this adapter does not translate driver failures, so a DBAL
 * exception reaches the caller as raised.
 *
 * @since  2.0.0
 */
final readonly class DoctrineBusinessRecordReadRepository implements BusinessRecordReadRepository
{
    /**
     * Maximum related rows one request may materialize across one include handle.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int MAX_INCLUDED_ROWS = 1000;

    /**
     * Largest number of caller-facing identities one batched identity statement resolves.
     *
     * The list is the whole parameter payload apart from the scope and policy predicates, so a batch of
     * this size stays an order of magnitude below the bound-parameter ceiling MariaDB, MySQL and
     * PostgreSQL each impose, and a thousand-line document's references cost a handful of statements.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int IDENTITY_BATCH = 250;

    /**
     * Wire the reader to the connection, codecs and metadata sources one decoded row needs.
     *
     * @param  Connection                            $database       DBAL connection every read runs on,
     *         and whose platform quotes each identifier taken from an installed blueprint.
     * @param  RecordValueCodec                      $values         Rebuilds field values from a fetched
     *         row, and converts a sort column into the value a keyset cursor carries.
     * @param  RecordRuleValidator                   $rules          Recomputes virtual formula fields
     *         over a decoded row, so a virtual value is never taken from storage.
     * @param  DoctrineBusinessRecordQueryCompiler   $queries        Turns a browse specification into the
     *         statement, bindings and cursor metadata this class executes unexamined.
     * @param  RecordCursorCodec                     $cursors        Signs the page position handed back
     *         as the next cursor.
     * @param  BusinessDefinitionRepository          $definitions    Source of the published definition a
     *         row's pinned version, or a relationship target, resolves to.
     * @param  BusinessSchemaInstallationRepository  $installations  Source of the installed schema behind
     *         a relationship target, which decides the columns its part of a read may name.
     * @param  BusinessRecordMutationFence           $fence          Holds a relationship target's
     *         installation still for the rest of the transaction before that target is read.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private RecordValueCodec $values,
        private RecordRuleValidator $rules,
        private DoctrineBusinessRecordQueryCompiler $queries,
        private RecordCursorCodec $cursors,
        private BusinessDefinitionRepository $definitions,
        private BusinessSchemaInstallationRepository $installations,
        private BusinessRecordMutationFence $fence,
    ) {
    }

    /**
     * Resolve a caller-facing record id to the stored row's identity, reading no value columns.
     *
     * Only the key, identity, pinned-version and optimistic-version columns are selected, so a caller
     * learns which definition version to re-resolve before it pays for a decode. An archived row is
     * still matched here; a soft-deleted one only when $includeDeleted admits it.
     *
     * @param   ResolvedBusinessDefinition  $resolved        Definition whose installed record table is
     *          searched, and whose identity strategy decides which column the id is matched against.
     * @param   RecordScope                 $scope           Site and organization the row must belong to,
     *          bound into the statement rather than checked afterwards.
     * @param   BusinessRecordAccessPlan    $access          Row policy applied before identity is returned.
     * @param   string                      $recordId        Caller-facing identity, already normalized.
     * @param   bool                        $includeDeleted  True to also match a soft-deleted row.
     *
     * @return  ?StoredRecordIdentity  Internal key, pinned definition version and optimistic-lock
     *          version, or null when this scope holds no matching row.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the installation has no record table, declares no
     *          column this lookup names, disagrees with the requested scope, or hands back a key or
     *          version column that is not the type it declared.
     * @throws  InvalidArgumentException  When the stored key is not a canonical UUID, the stored identity
     *          is empty or malformed, or either stored version is below one.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the lookup, or the platform to quote
     *          identifiers for cannot be resolved.
     *
     * @since   2.0.0
     */
    public function identity(
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        BusinessRecordAccessPlan $access,
        string $recordId,
        bool $includeDeleted = false,
    ): ?StoredRecordIdentity {
        $table = $this->recordTable($resolved);
        $identityPhysical = $this->identityPhysical($resolved, $table);
        /** @var list<mixed> $parameters */
        $parameters = [$recordId];
        /** @var list<string> $types */
        $types = [$this->physicalType($table, $identityPhysical)];
        $where = [$this->quote($identityPhysical) . ' = ?'];
        $this->scope($table, $scope, $where, $parameters, $types);
        if (!$includeDeleted && $table->column('deleted_at') !== null) {
            $where[] = $this->quote($this->physical($table, 'deleted_at')) . ' IS NULL';
        }
        $policy = $this->queries->compileAccessPredicate($resolved, $scope, $access);
        $where[] = $policy->sql;
        array_push($parameters, ...$policy->parameters);
        array_push($types, ...$policy->types);
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT r0.%s, r0.%s, r0.%s, r0.%s FROM %s r0 WHERE %s',
            $this->quote($this->physical($table, 'record_id')),
            $this->quote($identityPhysical),
            $this->quote($this->physical($table, 'definition_version')),
            $this->quote($this->physical($table, 'version')),
            $this->quote($table->physicalName),
            implode(' AND ', $where),
        ), $parameters, $types);
        if ($row === false) {
            return null;
        }

        return new StoredRecordIdentity(
            $this->string($row, $this->physical($table, 'record_id')),
            $this->string($row, $identityPhysical),
            $this->integer($row, $this->physical($table, 'definition_version')),
            $this->integer($row, $this->physical($table, 'version')),
        );
    }

    /**
     * Resolve many caller-facing record ids of one definition at once, in bounded batches.
     *
     * The statement is `identity()`'s, widened from an equality to an `IN` list and chunked so the bound
     * parameter list stays far below the ceiling any supported engine enforces. Every predicate stays:
     * the same scope filter, the same soft-delete rule and the same compiled access predicate, so asking
     * for a hundred ids never sees a row that asking for one of them would have hidden.
     *
     * @param   ResolvedBusinessDefinition  $resolved        Definition whose identity column is matched.
     * @param   RecordScope                 $scope           Site and organization the rows must belong to.
     * @param   BusinessRecordAccessPlan    $access          Row policy applied before an identity returns.
     * @param   list<string>                $recordIds       Caller-facing identities, already normalized.
     * @param   bool                        $includeDeleted  True to also match soft-deleted rows.
     *
     * @return  array<string, StoredRecordIdentity>  Resolved identities keyed by caller-facing id.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the requested scope disagrees with the installed
     *          columns, or a stored identity is malformed.
     *
     * @since   2.0.0
     */
    public function identities(
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        BusinessRecordAccessPlan $access,
        array $recordIds,
        bool $includeDeleted = false,
    ): array {
        $wanted = array_values(array_unique($recordIds));
        if ($wanted === []) {
            return [];
        }
        $table = $this->recordTable($resolved);
        $identityPhysical = $this->identityPhysical($resolved, $table);
        $resolvedIdentities = [];
        foreach (array_chunk($wanted, self::IDENTITY_BATCH) as $batch) {
            /** @var list<mixed> $parameters */
            $parameters = [$batch];
            /** @var list<ArrayParameterType|string> $types */
            $types = [ArrayParameterType::STRING];
            $where = ['r0.' . $this->quote($identityPhysical) . ' IN (?)'];
            $this->qualifiedScope($table, 'r0', $scope, $where, $parameters, $types);
            if (!$includeDeleted && $table->column('deleted_at') !== null) {
                $where[] = 'r0.' . $this->quote($this->physical($table, 'deleted_at')) . ' IS NULL';
            }
            $policy = $this->queries->compileAccessPredicate($resolved, $scope, $access);
            $where[] = $policy->sql;
            array_push($parameters, ...$policy->parameters);
            array_push($types, ...$policy->types);
            $rows = $this->database->executeQuery(sprintf(
                'SELECT r0.%s, r0.%s, r0.%s, r0.%s FROM %s r0 WHERE %s',
                $this->quote($this->physical($table, 'record_id')),
                $this->quote($identityPhysical),
                $this->quote($this->physical($table, 'definition_version')),
                $this->quote($this->physical($table, 'version')),
                $this->quote($table->physicalName),
                implode(' AND ', $where),
            ), $parameters, $types)->fetchAllAssociative();
            foreach ($rows as $row) {
                $identity = $this->string($row, $identityPhysical);
                $resolvedIdentities[$identity] = new StoredRecordIdentity(
                    $this->string($row, $this->physical($table, 'record_id')),
                    $identity,
                    $this->integer($row, $this->physical($table, 'definition_version')),
                    $this->integer($row, $this->physical($table, 'version')),
                );
            }
        }

        return $resolvedIdentities;
    }

    /**
     * List the records that point at one target record through a relationship column of their own.
     *
     * Only a relationship materialized as a column on the source table is scanned. A relationship held
     * in a junction table, or owned solely by its inverse, reports no referrers rather than failing, so
     * a caller proving that nothing references a record has to ask in every direction it cares about.
     * No lifecycle predicate is applied, so archived and soft-deleted referrers are reported as well.
     *
     * @param   ResolvedBusinessDefinition  $resolved         Source definition whose installed record
     *          table is scanned for the relationship's target column.
     * @param   RecordScope                 $scope            Site and organization the referring rows
     *          must belong to.
     * @param   BusinessRecordAccessPlan    $access           Row policy applied before referrers are returned.
     * @param   RelationshipDefinition      $relationship     Relationship on the source definition whose
     *          stored target column is matched.
     * @param   string                      $targetRecordKey  Internal storage key of the referenced row.
     * @param   int                         $limit            Most rows to return, from 1 to 501; a caller
     *          detecting an overflow asks for one more than it needs.
     *
     * @return  list<BusinessRecord>  Referring records in storage-key order; empty when nothing
     *          references the target, or when this relationship is not stored as a column here.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the bound falls outside 1 to 501, the installation
     *          has no record table, the requested scope disagrees with the installed scope columns, a
     *          row's pinned definition version is unavailable, or a stored column is not its declared
     *          type.
     *
     * @since   2.0.0
     */
    public function referencing(
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        BusinessRecordAccessPlan $access,
        RelationshipDefinition $relationship,
        string $targetRecordKey,
        int $limit,
    ): array {
        return $this->referencingRecords(
            $resolved,
            $scope,
            $relationship,
            $targetRecordKey,
            $limit,
            $access,
        );
    }

    /**
     * Read inbound rows only for the private referential-integrity phase of an authorized hard delete.
     *
     * This path is intentionally independent of actor row disclosure. Returning only to
     * `BusinessRecordService::clearInboundSetNull()`, it lets a hidden source enforce restrict or receive
     * its declared set-null mutation before the target DELETE reaches the foreign key. It must never back
     * a read surface, count response, include, relation result, or authorization decision.
     *
     * @param   ResolvedBusinessDefinition  $resolved         Active source definition whose table is scanned.
     * @param   RecordScope                 $scope            Exact target scope the sources must share.
     * @param   RelationshipDefinition      $relationship     Direct relationship column being checked.
     * @param   string                      $targetRecordKey  Internal target key held by that column.
     * @param   int                         $limit            Bounded maximum including an overflow sentinel.
     *
     * @return  list<BusinessRecord>  Internal referrers ordered by storage key, never for disclosure.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the bound, installed schema, scope or stored row is invalid.
     *
     * @since   2.0.0
     */
    public function referencingForDeleteIntegrity(
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        RelationshipDefinition $relationship,
        string $targetRecordKey,
        int $limit,
    ): array {
        return $this->referencingRecords(
            $resolved,
            $scope,
            $relationship,
            $targetRecordKey,
            $limit,
            null,
        );
    }

    /**
     * Execute the shared bounded inbound scan, optionally narrowed by an actor access plan.
     *
     * @param   ResolvedBusinessDefinition     $resolved         Source definition and installed table.
     * @param   RecordScope                    $scope            Site and organization required of every source.
     * @param   RelationshipDefinition         $relationship     Direct relationship column being matched.
     * @param   string                         $targetRecordKey  Internal target key stored in that column.
     * @param   int                            $limit            Most rows to return, from 1 through 501.
     * @param   BusinessRecordAccessPlan|null  $access           Actor policy, or null only for delete integrity.
     *
     * @return  list<BusinessRecord>  Referring rows in stable storage-key order.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the bound, schema, scope, pinned row or value is invalid.
     *
     * @since   2.0.0
     */
    private function referencingRecords(
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        RelationshipDefinition $relationship,
        string $targetRecordKey,
        int $limit,
        ?BusinessRecordAccessPlan $access,
    ): array {
        if ($limit < 1 || $limit > 501) {
            throw new BusinessRecordSchemaUnavailable('An inbound relationship read has an invalid bound.');
        }
        $table = $this->recordTable($resolved);
        $relationshipColumn = $table->column('relation:' . $relationship->handle . '.target_id');
        if ($relationshipColumn === null) {
            return [];
        }
        /** @var list<mixed> $parameters */
        $parameters = [$targetRecordKey];
        /** @var list<string|ArrayParameterType> $types */
        $types = [$relationshipColumn->doctrineType];
        $where = ['r0.' . $this->quote($relationshipColumn->physicalName) . ' = ?'];
        $this->qualifiedScope($table, 'r0', $scope, $where, $parameters, $types);
        if ($access !== null) {
            $policy = $this->queries->compileAccessPredicate($resolved, $scope, $access);
            $where[] = $policy->sql;
            array_push($parameters, ...$policy->parameters);
            array_push($types, ...$policy->types);
        }
        $records = [];
        foreach (
            $this->database->executeQuery(sprintf(
                'SELECT r0.* FROM %s r0 WHERE %s ORDER BY r0.%s ASC LIMIT %d',
                $this->quote($table->physicalName),
                implode(' AND ', $where),
                $this->quote($this->physical($table, 'record_id')),
                $limit,
            ), $parameters, $types)->fetchAllAssociative() as $row
        ) {
            $rowResolved = $this->pinnedForRow($resolved, $table, $row);
            $records[] = $this->map($rowResolved, $table, $row);
        }

        return $records;
    }

    /**
     * Load one whole row and decode it against the definition version handed in.
     *
     * The row is decoded with $resolved rather than with whatever the installation currently publishes,
     * and a row whose stored version disagrees is refused instead of being reinterpreted — so callers
     * pass the pinned definition that `identity()` pointed them at. Archived and soft-deleted rows stay
     * invisible unless explicitly admitted, which is the whole difference between a normal read and a
     * history or restore read.
     *
     * @param   ResolvedBusinessDefinition  $resolved         Pinned definition the row is decoded with,
     *          and whose identity strategy decides which column the id is matched against.
     * @param   RecordScope                 $scope            Site and organization the row must belong to.
     * @param   BusinessRecordAccessPlan    $access           Row policy applied before the row is decoded.
     * @param   string                      $recordId         Caller-facing identity, already normalized.
     * @param   bool                        $includeArchived  True to also load an archived row.
     * @param   bool                        $includeDeleted   True to also load a soft-deleted row.
     *
     * @return  ?BusinessRecord  The decoded record, or null when this scope holds no matching row.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the row was written under a different definition
     *          version, the installation has no record table or lacks a column this read names, the
     *          requested scope disagrees with the installed scope columns, or a stored column is not its
     *          declared type.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordValidationFailed  When the
     *          decoded row's virtual formula fields cannot be recomputed.
     * @throws  InvalidArgumentException  When a stored value contradicts the physical type declared for
     *          its column, or the decoded row breaks a `BusinessRecord` invariant.
     * @throws  \DateMalformedStringException  When a stored timestamp column holds an unparsable string.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the read, or the platform to quote
     *          identifiers for cannot be resolved.
     *
     * @since   2.0.0
     */
    public function get(
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        BusinessRecordAccessPlan $access,
        string $recordId,
        bool $includeArchived = false,
        bool $includeDeleted = false,
    ): ?BusinessRecord {
        $table = $this->recordTable($resolved);
        $identityPhysical = $this->identityPhysical($resolved, $table);
        /** @var list<mixed> $parameters */
        $parameters = [$recordId];
        /** @var list<string> $types */
        $types = [$this->physicalType($table, $identityPhysical)];
        $where = [$this->quote($identityPhysical) . ' = ?'];
        $this->scope($table, $scope, $where, $parameters, $types);
        if (!$includeArchived && $table->column('archived_at') !== null) {
            $where[] = $this->quote($this->physical($table, 'archived_at')) . ' IS NULL';
        }
        if (!$includeDeleted && $table->column('deleted_at') !== null) {
            $where[] = $this->quote($this->physical($table, 'deleted_at')) . ' IS NULL';
        }
        $policy = $this->queries->compileAccessPredicate($resolved, $scope, $access);
        $where[] = $policy->sql;
        array_push($parameters, ...$policy->parameters);
        array_push($types, ...$policy->types);
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT r0.* FROM %s r0 WHERE %s',
            $this->quote($table->physicalName),
            implode(' AND ', $where),
        ), $parameters, $types);

        return $row === false ? null : $this->map($resolved, $table, $row);
    }

    /**
     * Project one loaded record into the disclosure-safe view a caller may be shown.
     *
     * The record itself is already in hand, so the only query this runs is the one that exchanges each
     * stored entity reference for the target's caller-facing identity — an internal record key never
     * leaves the read side. Fields the definition hides from readers, restricted fields and secret fields
     * are omitted by `BusinessRecordView` itself.
     *
     * @param   ResolvedBusinessDefinition  $resolved         Definition the record was decoded with, whose
     *          reference fields name the targets to resolve.
     * @param   RecordScope                 $scope            Scope the referenced rows must also belong to; a
     *          reference pointing outside it counts as broken.
     * @param   BusinessRecord              $record           Record to project.
     * @param   BusinessRecordAccessPlan    $access           Field and reference-target disclosure decision.
     * @param   list<string>                $projection       Field handles to keep, or empty for every
     *          read-visible field.
     * @param   list<string>                $includes         Relationship handles to hydrate, capped at four.
     * @param   bool                        $includeArchived  True to include archived related records.
     * @param   bool                        $includeDeleted   True to include soft-deleted related records.
     *
     * @return  BusinessRecordView  The projected record with requested relationship includes attached.
     *
     * @throws  BusinessRecordSchemaUnavailable  When a reference field declares no string target, a
     *          stored reference is not a UUID or has no target row in this scope, or the target's
     *          definition or installed schema is unusable.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable  When
     *          a referenced target definition no longer exists on this site.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable  When
     *          the shared fence over a target's installation cannot be taken, or that installation moved
     *          since it was resolved.
     * @throws  InvalidArgumentException  When a target's published definition and its installation
     *          disagree.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the reference lookup, or the platform to
     *          quote identifiers for cannot be resolved.
     *
     * @since   2.0.0
     */
    public function view(
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        BusinessRecord $record,
        BusinessRecordAccessPlan $access,
        array $projection = [],
        array $includes = [],
        bool $includeArchived = false,
        bool $includeDeleted = false,
    ): BusinessRecordView {
        $values = $this->publicReferenceValues(
            $resolved,
            $scope,
            [$record],
            null,
            $access,
            FieldAccessUsage::Detail,
            $projection,
        );

        $view = BusinessRecordView::fromRecord(
            $record,
            $projection,
            $resolved->definition,
            $values[$record->recordKey],
            $access->fields,
            FieldAccessUsage::Detail,
        );
        if ($includes === []) {
            return $view;
        }
        $included = $this->includes(
            $resolved,
            $scope,
            [$record],
            $includes,
            $includeArchived,
            $includeDeleted,
            $access,
        );

        return $view->withIncludes($included[$record->recordKey] ?? []);
    }

    /**
     * Run a compiled query and return one bounded page of projected records.
     *
     * The compiled statement selects one row more than the page size; that extra row is dropped here and
     * is the only evidence a further page exists, which is when a signed cursor is minted from the last
     * row actually returned. Each row is decoded under the definition version it was written with, so a
     * page may span several shapes, and both the relationship includes and the reference identities are
     * resolved once for the whole page rather than per row. Aggregates run as a second statement over
     * the same predicates without the page bound, and one that came back as a float is refused rather
     * than rounded, so a total is always exact.
     *
     * @param   ResolvedBusinessDefinition  $resolved       Definition whose installed table is queried,
     *          supplying the blueprint and the shape for rows already pinned to its version.
     * @param   RecordScope                 $scope          Site and organization the page is confined to.
     * @param   RecordQuerySpecification    $specification  Filter, search, sort, page bound, cursor and
     *          projection to compile.
     * @param   BusinessRecordAccessPlan    $access         Row, field and relationship query decision.
     *
     * @return  RecordBrowseResult  Views for this page, a cursor to continue from only when further rows
     *          matched, and any requested aggregates keyed by their alias.
     *
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\InvalidBusinessRecordQuery  When the
     *          specification cannot be compiled, a cursor was raised against a different query, or a
     *          field may not be filtered, sorted, searched or reported on.
     * @throws  BusinessRecordSchemaUnavailable  When the query names something the installed schema does
     *          not carry, a cursor sort column is absent or holds a value its physical type contradicts,
     *          a row's pinned definition is unavailable, or an aggregate produced an inexact value.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable  When
     *          a definition the query or a reference reaches for no longer exists on this site.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable  When
     *          the shared fence over a relation or reference target's installation cannot be taken, or
     *          that installation moved since it was resolved.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordValidationFailed  When a
     *          decoded row's virtual formula fields cannot be recomputed.
     * @throws  InvalidArgumentException  When a decoded row breaks a `BusinessRecord` invariant, or the
     *          page carries more rows or aggregates than a result may hold.
     * @throws  \JsonException  When a sort value read off the last row cannot be encoded into the cursor.
     * @throws  \DateMalformedStringException  When a stored timestamp column holds an unparsable string.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the page or aggregate statement, or the
     *          platform to quote identifiers for cannot be resolved.
     *
     * @since   2.0.0
     */
    public function browse(
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        RecordQuerySpecification $specification,
        BusinessRecordAccessPlan $access,
    ): RecordBrowseResult {
        $compiled = $this->queries->compile($resolved, $scope, $specification, $access);
        $rows = $this->database->executeQuery(
            $compiled->sql,
            $compiled->parameters,
            $compiled->types,
        )->fetchAllAssociative();
        $hasMore = count($rows) > $specification->pageSize;
        if ($hasMore) {
            array_pop($rows);
        }
        $table = $this->recordTable($resolved);
        $requestedProjection = $specification->projection->fields;
        $records = [];
        $rowDefinitions = [];
        foreach ($rows as $row) {
            $rowResolved = $this->pinnedForRow($resolved, $table, $row);
            $record = $this->map($rowResolved, $table, $row);
            $records[] = $record;
            $rowDefinitions[] = $rowResolved->definition;
        }
        $views = [];
        $usage = $this->collectionUsage($access);
        $publicValues = $this->publicReferenceValues(
            $resolved,
            $scope,
            $records,
            $rowDefinitions,
            $access,
            $usage,
            $requestedProjection,
        );
        foreach ($records as $index => $record) {
            $views[] = BusinessRecordView::fromRecord(
                $record,
                $requestedProjection,
                $rowDefinitions[$index],
                $publicValues[$record->recordKey],
                $access->fields,
                $usage,
            );
        }
        if ($specification->projection->includes !== [] && $records !== []) {
            $included = $this->includes(
                $resolved,
                $scope,
                $records,
                $specification->projection->includes,
                $specification->includeArchived,
                $specification->includeDeleted,
                $access,
            );
            foreach ($views as $index => $view) {
                $views[$index] = $view->withIncludes($included[$view->recordKey] ?? []);
            }
        }
        $next = null;
        if ($hasMore && $rows !== []) {
            $last = $rows[array_key_last($rows)];
            $sortValues = [];
            foreach ($compiled->cursorColumns as $cursor) {
                $column = $table->physicalColumn($cursor['physical']);
                if ($column === null) {
                    throw new BusinessRecordSchemaUnavailable('A cursor sort column is absent from the schema.');
                }
                try {
                    $value = $this->values->cursorValue($column, $last[$cursor['physical']] ?? null);
                } catch (InvalidArgumentException) {
                    throw new BusinessRecordSchemaUnavailable(
                        'A stored cursor sort value does not match its physical schema type.',
                    );
                }
                $sortValues[] = $value;
            }
            $next = $this->cursors->encode(new CursorPosition(
                $compiled->cursorDigest,
                $sortValues,
                $this->string($last, $this->physical($table, 'record_id')),
            ));
        }

        $aggregates = [];
        if ($compiled->aggregateSql !== null) {
            $row = $this->database->executeQuery(
                $compiled->aggregateSql,
                $compiled->aggregateParameters,
                $compiled->aggregateTypes,
            )->fetchAssociative();
            if ($row !== false) {
                foreach ($row as $alias => $value) {
                    if (is_float($value) || (!is_int($value) && !is_string($value) && $value !== null)) {
                        throw new BusinessRecordSchemaUnavailable('An aggregate produced a non-exact database value.');
                    }
                    $aggregates[$alias] = $value;
                }
            }
        }

        return new RecordBrowseResult($views, $next, $aggregates);
    }

    /**
     * Exchange every stored entity reference across a set of records for the target's public identity.
     *
     * A view must never carry an internal record key, so each reference field is swapped for the
     * caller-facing identity of the row it points at. References are gathered per target definition and
     * resolved in one statement for the whole set, which is what keeps a page of records to a fixed
     * number of round trips. Passing $pinnedDefinitions splits a mixed page by the version each row was
     * decoded under and recurses once per group, because a reference field may not exist, or may point
     * elsewhere, in another version. A reference whose target is missing from this scope is refused
     * rather than left as a key.
     *
     * @param   ResolvedBusinessDefinition       $source             Definition whose reference fields
     *          name the targets, used for every record when no pinned definitions are supplied.
     * @param   RecordScope                      $scope              Scope the referenced rows must also
     *          belong to.
     * @param   list<BusinessRecord>             $records            Records whose reference fields are
     *          resolved.
     * @param   list<EntityTypeDefinition>|null  $pinnedDefinitions  Definition the record at the same
     *          offset was decoded with, or null when every record shares $source.
     * @param   BusinessRecordAccessPlan         $access             Field and target-row disclosure decision.
     * @param   FieldAccessUsage                 $usage              Exact collection disclosure use.
     * @param   list<string>                     $projection         Requested fields, or empty for all fields
     *          admitted by the access plan.
     *
     * @return  array<string, array<string, mixed>>  Each record's values keyed by its storage key, with
     *          reference fields carrying the target's public identity instead of its key.
     *
     * @throws  BusinessRecordSchemaUnavailable  When a browsed row has no pinned definition, a reference
     *          field declares no string target, a stored reference is not a UUID or has no target row in
     *          this scope, or a target's definition or installed schema is unusable.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable  When
     *          a referenced target definition no longer exists on this site.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable  When
     *          the shared fence over a target's installation cannot be taken, or that installation moved
     *          since it was resolved.
     * @throws  InvalidArgumentException  When a row's pinned definition does not fit the installation it
     *          is paired with, or a target's published definition and installation disagree.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects a target lookup, or the platform to
     *          quote identifiers for cannot be resolved.
     *
     * @since   2.0.0
     */
    private function publicReferenceValues(
        ResolvedBusinessDefinition $source,
        RecordScope $scope,
        array $records,
        ?array $pinnedDefinitions,
        BusinessRecordAccessPlan $access,
        FieldAccessUsage $usage,
        array $projection = [],
    ): array {
        if ($pinnedDefinitions !== null) {
            /** @var array<int, EntityTypeDefinition> $groupDefinitions */
            $groupDefinitions = [];
            /** @var array<int, list<BusinessRecord>> $groupRecords */
            $groupRecords = [];
            foreach ($records as $index => $record) {
                $definition = $pinnedDefinitions[$index] ?? null;
                if (!$definition instanceof EntityTypeDefinition) {
                    throw new BusinessRecordSchemaUnavailable('A browsed pinned definition is unavailable.');
                }
                $groupDefinitions[$definition->definitionVersion] = $definition;
                $groupRecords[$definition->definitionVersion][] = $record;
            }
            $groupedResult = [];
            foreach ($groupRecords as $version => $versionRecords) {
                $definition = $groupDefinitions[$version];
                $groupResolved = new ResolvedBusinessDefinition($definition, $source->installation);
                $groupedResult = [
                    ...$groupedResult,
                    ...$this->publicReferenceValues(
                        $groupResolved,
                        $scope,
                        $versionRecords,
                        null,
                        $access,
                        $usage,
                        $projection,
                    ),
                ];
            }

            return $groupedResult;
        }
        $result = [];
        foreach ($records as $record) {
            $result[$record->recordKey] = $record->values();
        }
        $groups = [];
        foreach ($source->definition->fields() as $field) {
            if (
                $field->type !== 'core.entity_reference'
                || !$access->fields->allows($usage, $field->handle)
                || ($projection !== [] && !in_array($field->handle, $projection, true))
            ) {
                continue;
            }
            if ($access->related($field->handle) === null) {
                throw new BusinessRecordSchemaUnavailable('An entity-reference target is unavailable.');
            }
            $targetHandle = $field->configuration['target'] ?? null;
            if (!is_string($targetHandle)) {
                throw new BusinessRecordSchemaUnavailable('An entity-reference target is unavailable.');
            }
            $groups[$field->handle] = $targetHandle;
        }
        foreach ($groups as $fieldHandle => $targetHandle) {
            $keys = [];
            foreach ($records as $record) {
                $value = $record->values()[$fieldHandle] ?? null;
                if ($value !== null) {
                    if (!is_string($value) || !\Ramsey\Uuid\Uuid::isValid($value)) {
                        throw new BusinessRecordSchemaUnavailable('A stored entity reference is invalid.');
                    }
                    $keys[] = $value;
                }
            }
            $keys = array_values(array_unique($keys));
            if ($keys === []) {
                continue;
            }
            $target = $this->targetByHandle($source, $targetHandle);
            $targetAccess = $access->related($fieldHandle)
                ?? throw new BusinessRecordSchemaUnavailable('An entity-reference target is unavailable.');
            if (!$this->publicIdentityAvailable($target->definition, $targetAccess)) {
                foreach ($records as $record) {
                    if (($record->values()[$fieldHandle] ?? null) !== null) {
                        unset($result[$record->recordKey][$fieldHandle]);
                    }
                }
                continue;
            }
            $table = $this->recordTable($target);
            $identity = $target->definition->identityStrategy === IdentityStrategy::Uuid
                ? $this->physical($table, 'record_id')
                : $this->physical($table, $this->identityHandle($target->definition));
            /** @var list<mixed> $parameters */
            $parameters = [$keys];
            /** @var list<ArrayParameterType|string> $types */
            $types = [ArrayParameterType::STRING];
            $where = ['r0.' . $this->quote($this->physical($table, 'record_id')) . ' IN (?)'];
            $this->qualifiedScope($table, 'r0', $scope, $where, $parameters, $types);
            $policy = $this->queries->compileAccessPredicate($target, $scope, $targetAccess);
            $where[] = $policy->sql;
            array_push($parameters, ...$policy->parameters);
            array_push($types, ...$policy->types);
            $rows = $this->database->executeQuery(sprintf(
                'SELECT r0.%s AS record_key, r0.%s AS public_id FROM %s r0 WHERE %s',
                $this->quote($this->physical($table, 'record_id')),
                $this->quote($identity),
                $this->quote($table->physicalName),
                implode(' AND ', $where),
            ), $parameters, $types)->fetchAllAssociative();
            $public = [];
            foreach ($rows as $row) {
                $public[$this->string($row, 'record_key')] = $this->string($row, 'public_id');
            }
            foreach ($records as $record) {
                $key = $record->values()[$fieldHandle] ?? null;
                if ($key !== null) {
                    if (!is_string($key)) {
                        throw new BusinessRecordSchemaUnavailable('A stored entity reference is invalid.');
                    }
                    if (isset($public[$key])) {
                        $result[$record->recordKey][$fieldHandle] = $public[$key];
                    } else {
                        unset($result[$record->recordKey][$fieldHandle]);
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Resolve the requested relationship includes for a whole page of source records at once.
     *
     * One statement per handle covers every source on the page, which is what stops an include from
     * costing a query per row. Owned lines are projected directly from the line table, decoded and
     * narrowed here with withheld handles omitted; every other relationship is decoded as a record and
     * flattened into the same
     * relation view, so a caller cannot tell the two apart. Included rows carry no includes of their
     * own, which bounds how far one browse can walk the relationship graph. Every source key is
     * pre-seeded, so a source with no related rows is present with an empty list rather than absent.
     *
     * @param   ResolvedBusinessDefinition  $source           Definition the source records were decoded
     *          with, whose relationships the handles are resolved against.
     * @param   RecordScope                 $scope            Site and organization the related rows must
     *          belong to.
     * @param   list<BusinessRecord>        $sources          Records of the page the includes hang from.
     * @param   list<string>                $handles          Relationship handles the projection asked to
     *          include.
     * @param   bool                        $includeArchived  True to also include archived related
     *          records; owned lines are unaffected.
     * @param   bool                        $includeDeleted   True to also include soft-deleted related
     *          records; owned lines are unaffected.
     * @param   BusinessRecordAccessPlan    $access           Source plan carrying target relation plans.
     *
     * @return  array<string, array<string, list<BusinessRecordRelationView>>>  Relation views per source
     *          storage key, keyed by handle, in source then position then key order.
     *
     * @throws  BusinessRecordSchemaUnavailable  When a handle names no relationship, a related row falls
     *          outside the source page, a target's definition or installed schema is unusable, an
     *          include has no canonical inverse to traverse, or a stored column is not its declared type.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable  When
     *          a relationship target definition no longer exists on this site.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable  When
     *          the shared fence over a target's installation cannot be taken, or that installation moved
     *          since it was resolved.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordValidationFailed  When an
     *          included row's virtual formula fields cannot be recomputed.
     * @throws  InvalidArgumentException  When an included row breaks a `BusinessRecord` invariant, or a
     *          stored value contradicts the physical type declared for its column.
     * @throws  \DateMalformedStringException  When a stored timestamp column holds an unparsable string.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects an include statement, or the platform to
     *          quote identifiers for cannot be resolved.
     *
     * @since   2.0.0
     */
    private function includes(
        ResolvedBusinessDefinition $source,
        RecordScope $scope,
        array $sources,
        array $handles,
        bool $includeArchived,
        bool $includeDeleted,
        BusinessRecordAccessPlan $access,
    ): array {
        $result = [];
        $sourceKeys = [];
        foreach ($sources as $record) {
            $sourceKeys[] = $record->recordKey;
            $result[$record->recordKey] = array_fill_keys($handles, []);
        }
        foreach ($handles as $handle) {
            $relationship = $this->relationship($source->definition, $handle);
            $targetAccess = $access->related($handle)
                ?? throw new BusinessRecordSchemaUnavailable('An included relationship is unavailable.');
            $target = $this->relationshipTarget($source, $relationship);
            if (!$this->publicIdentityAvailable($target->definition, $targetAccess)) {
                continue;
            }
            [$rows, $targetTable, $ownedLine] = $this->includeRows(
                $source,
                $target,
                $scope,
                $relationship,
                $sourceKeys,
                $includeArchived,
                $includeDeleted,
                $targetAccess,
            );
            foreach ($rows as $row) {
                $sourceKey = $this->string($row, '__source_key');
                if (!isset($result[$sourceKey])) {
                    throw new BusinessRecordSchemaUnavailable('An included relationship escaped its source page.');
                }
                $position = $this->nullableInteger($row['__position'] ?? null);
                if ($ownedLine) {
                    $recordKey = $this->string($row, $this->physical($targetTable, 'line_id'));
                    $values = $this->values->decodeColumns(
                        $target->definition,
                        $targetTable,
                        $row,
                        $target->definition->siteIdentifier,
                        $recordKey,
                    );
                    $values = $this->rules->materialize(
                        $target->definition,
                        $values,
                        $target->definition->siteIdentifier,
                        $recordKey,
                    );
                    if (!$targetAccess->records->allows($values)) {
                        continue;
                    }
                    $result[$sourceKey][$handle][] = new BusinessRecordRelationView(
                        $target->definition->id,
                        $target->definition->definitionVersion,
                        $recordKey,
                        $this->values->publicIdentity($target->definition, $recordKey, $values),
                        $this->integer($row, $this->physical($targetTable, 'version')),
                        $position,
                        $this->visibleValues(
                            $target->definition,
                            $values,
                            $targetAccess,
                            FieldAccessUsage::Include,
                        ),
                    );
                    continue;
                }
                $rowResolved = $this->pinnedForRow($target, $targetTable, $row);
                $record = $this->map($rowResolved, $targetTable, $row);
                if (!$targetAccess->records->allows($record->values())) {
                    continue;
                }
                $view = BusinessRecordView::fromRecord(
                    $record,
                    [],
                    $rowResolved->definition,
                    null,
                    $targetAccess->fields,
                    FieldAccessUsage::Include,
                );
                $result[$sourceKey][$handle][] = new BusinessRecordRelationView(
                    $view->definitionId,
                    $view->definitionVersion,
                    $view->recordKey,
                    $view->recordId,
                    $view->version,
                    $position,
                    $view->values,
                );
            }
        }

        return $result;
    }

    /**
     * Fetch the raw rows behind one relationship handle for every source on the page.
     *
     * How the rows are reached depends on where the relationship is actually stored, and the storage
     * shapes are tried in a fixed order: an owned-line collection reads the owner's own line table; a
     * relationship materialized as a column on the source table joins back through it; a junction table
     * on the source installation joins through that; otherwise the canonical inverse on the target is
     * traversed, through either its own column or its junction. Every shape projects the same two
     * synthetic columns, `__source_key` and `__position`, so the caller can group and order rows without
     * knowing which shape produced them; `__position` is NULL for a relationship that carries no order.
     * Archive and soft-delete filtering applies to related records only — an owned line lives and dies
     * with its owner, so its rows are always returned.
     *
     * @param   ResolvedBusinessDefinition  $source           Definition the page's records belong to,
     *          whose installation supplies the source, line and junction tables.
     * @param   ResolvedBusinessDefinition  $target           Definition on the far side, whose
     *          installation supplies the related record table; unused for an owned-line collection.
     * @param   RecordScope                 $scope            Site and organization the related rows and
     *          any junction rows must belong to.
     * @param   RelationshipDefinition      $relationship     Relationship being traversed, whose kind
     *          selects the storage shape.
     * @param   list<string>                $sourceKeys       Storage keys of the page's source records.
     * @param   bool                        $includeArchived  True to also return archived related
     *          records.
     * @param   bool                        $includeDeleted   True to also return soft-deleted related
     *          records.
     * @param   BusinessRecordAccessPlan    $targetAccess     Target row policy compiled into the include.
     *
     * @return  array{list<array<string, mixed>>, PhysicalTableBlueprint, bool}  Raw rows in source then
     *          position then key order, the blueprint they must be decoded against, and whether they are
     *          owned lines rather than records.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the owned-line table is absent, no storage shape
     *          reaches the relationship, the relationship declares no canonical inverse to traverse, or
     *          the requested scope disagrees with the installed scope columns.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the statement, or the platform to quote
     *          identifiers for cannot be resolved.
     *
     * @since   2.0.0
     */
    private function includeRows(
        ResolvedBusinessDefinition $source,
        ResolvedBusinessDefinition $target,
        RecordScope $scope,
        RelationshipDefinition $relationship,
        array $sourceKeys,
        bool $includeArchived,
        bool $includeDeleted,
        BusinessRecordAccessPlan $targetAccess,
    ): array {
        $sourceTable = $this->recordTable($source);
        /** @var list<mixed> $parameters */
        $parameters = [$sourceKeys];
        /** @var list<string|ArrayParameterType> $types */
        $types = [ArrayParameterType::STRING];
        if ($relationship->kind === RelationshipKind::OwnedLineCollection) {
            $targetTable = $source->installation->blueprint->table('line:' . $relationship->handle)
                ?? throw new BusinessRecordSchemaUnavailable('An included owned-line table is unavailable.');
            $alias = 'r0';
            $where = [sprintf(
                '%s.%s IN (?)',
                $alias,
                $this->quote($this->physical($targetTable, 'owner_id')),
            )];
            $this->qualifiedScope($targetTable, $alias, $scope, $where, $parameters, $types);
            $policy = $this->queries->compileAccessPredicate(
                $target,
                $scope,
                $targetAccess,
                $targetTable,
            );
            $where[] = $policy->sql;
            array_push($parameters, ...$policy->parameters);
            array_push($types, ...$policy->types);
            $sql = sprintf(
                'SELECT %s.*, %s.%s AS __source_key, %s.%s AS __position FROM %s %s '
                . 'WHERE %s ORDER BY %s.%s, %s.%s, %s.%s LIMIT %d',
                $alias,
                $alias,
                $this->quote($this->physical($targetTable, 'owner_id')),
                $alias,
                $this->quote($this->physical($targetTable, 'position')),
                $this->quote($targetTable->physicalName),
                $alias,
                implode(' AND ', $where),
                $alias,
                $this->quote($this->physical($targetTable, 'owner_id')),
                $alias,
                $this->quote($this->physical($targetTable, 'position')),
                $alias,
                $this->quote($this->physical($targetTable, 'line_id')),
                self::MAX_INCLUDED_ROWS + 1,
            );
            $rows = $this->boundedIncludedRows($sql, $parameters, $types);

            return [$rows, $targetTable, true];
        }

        $targetTable = $this->recordTable($target);
        /** @var list<mixed> $parameters */
        $parameters = [];
        /** @var list<string|ArrayParameterType> $types */
        $types = [];
        $targetAlias = 'r0';
        $sourceAlias = 's';
        $direct = $sourceTable->column('relation:' . $relationship->handle . '.target_id');
        $junction = $source->installation->blueprint->table('relation:' . $relationship->handle);
        $from = '';
        $sourceExpression = '';
        $positionExpression = 'NULL';
        $where = [];
        if ($direct !== null) {
            $from = sprintf(
                '%s %s INNER JOIN %s %s ON %s.%s = %s.%s',
                $this->quote($targetTable->physicalName),
                $targetAlias,
                $this->quote($sourceTable->physicalName),
                $sourceAlias,
                $sourceAlias,
                $this->quote($direct->physicalName),
                $targetAlias,
                $this->quote($this->physical($targetTable, 'record_id')),
            );
            $sourceExpression = $sourceAlias . '.' . $this->quote($this->physical($sourceTable, 'record_id'));
        } elseif ($junction !== null) {
            $junctionAlias = 'j';
            $from = sprintf(
                '%s %s INNER JOIN %s %s ON %s.%s = %s.%s',
                $this->quote($targetTable->physicalName),
                $targetAlias,
                $this->quote($junction->physicalName),
                $junctionAlias,
                $junctionAlias,
                $this->quote($this->physical($junction, 'target_id')),
                $targetAlias,
                $this->quote($this->physical($targetTable, 'record_id')),
            );
            $sourceExpression = $junctionAlias . '.' . $this->quote($this->physical($junction, 'source_id'));
            $positionExpression = $junctionAlias . '.' . $this->quote($this->physical($junction, 'position'));
            $this->qualifiedScope($junction, $junctionAlias, $scope, $where, $parameters, $types);
        } else {
            $inverse = $this->inverseRelationship($source->definition, $target->definition, $relationship);
            $inverseDirect = $targetTable->column('relation:' . $inverse->handle . '.target_id');
            if ($inverseDirect !== null) {
                $from = $this->quote($targetTable->physicalName) . ' ' . $targetAlias;
                $sourceExpression = $targetAlias . '.' . $this->quote($inverseDirect->physicalName);
            } else {
                $junction = $target->installation->blueprint->table('relation:' . $inverse->handle)
                    ?? throw new BusinessRecordSchemaUnavailable(
                        'An included canonical inverse junction is unavailable.',
                    );
                $junctionAlias = 'j';
                $from = sprintf(
                    '%s %s INNER JOIN %s %s ON %s.%s = %s.%s',
                    $this->quote($targetTable->physicalName),
                    $targetAlias,
                    $this->quote($junction->physicalName),
                    $junctionAlias,
                    $junctionAlias,
                    $this->quote($this->physical($junction, 'source_id')),
                    $targetAlias,
                    $this->quote($this->physical($targetTable, 'record_id')),
                );
                $sourceExpression = $junctionAlias . '.' . $this->quote($this->physical($junction, 'target_id'));
                $positionExpression = $junctionAlias . '.' . $this->quote($this->physical($junction, 'position'));
                $this->qualifiedScope($junction, $junctionAlias, $scope, $where, $parameters, $types);
            }
        }
        $where[] = $sourceExpression . ' IN (?)';
        $parameters[] = $sourceKeys;
        $types[] = ArrayParameterType::STRING;
        $this->qualifiedScope($targetTable, $targetAlias, $scope, $where, $parameters, $types);
        if (!$includeArchived && $targetTable->column('archived_at') !== null) {
            $where[] = $targetAlias . '.' . $this->quote($this->physical($targetTable, 'archived_at')) . ' IS NULL';
        }
        if (!$includeDeleted && $targetTable->column('deleted_at') !== null) {
            $where[] = $targetAlias . '.' . $this->quote($this->physical($targetTable, 'deleted_at')) . ' IS NULL';
        }
        $policy = $this->queries->compileAccessPredicate($target, $scope, $targetAccess);
        $where[] = $policy->sql;
        array_push($parameters, ...$policy->parameters);
        array_push($types, ...$policy->types);
        $sql = sprintf(
            'SELECT %s.*, %s AS __source_key, %s AS __position FROM %s WHERE %s '
            . 'ORDER BY __source_key, __position, %s.%s LIMIT %d',
            $targetAlias,
            $sourceExpression,
            $positionExpression,
            $from,
            implode(' AND ', $where),
            $targetAlias,
            $this->quote($this->physical($targetTable, 'record_id')),
            self::MAX_INCLUDED_ROWS + 1,
        );
        $rows = $this->boundedIncludedRows($sql, $parameters, $types);

        return [$rows, $targetTable, false];
    }

    /**
     * Execute an include query with one overflow sentinel and reject excessive fan-out.
     *
     * The literal SQL limit is a server-owned constant accepted by every supported database. Fetching
     * one sentinel distinguishes an exact thousand-row result from an unbounded relationship without a
     * second count query that could itself become expensive.
     *
     * @param   string                           $sql         Fully compiled bounded include SQL.
     * @param   list<mixed>                      $parameters  Bound statement values.
     * @param   list<string|ArrayParameterType>  $types       Doctrine binding types.
     *
     * @return  list<array<string, mixed>>  At most one thousand included rows.
     *
     * @throws  InvalidBusinessRecordQuery  When the requested include would materialize too many rows.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the statement.
     *
     * @since   2.0.0
     */
    private function boundedIncludedRows(string $sql, array $parameters, array $types): array
    {
        $rows = $this->database->executeQuery($sql, $parameters, $types)->fetchAllAssociative();
        if (count($rows) > self::MAX_INCLUDED_ROWS) {
            throw new InvalidBusinessRecordQuery(
                'A relationship include exceeds the bounded row budget; reduce the page or requested includes.',
            );
        }

        return $rows;
    }

    /**
     * Narrow a decoded owned line to the values a reader may see.
     *
     * Owned lines never pass through `BusinessRecordView`, so they are filtered here instead, under the
     * same rule: a field the definition statically or conditionally hides from readers is dropped entirely,
     * as is a restricted, secret or entity-reference field. References are withheld rather than resolved
     * because the stored value is an internal record key.
     *
     * @param   EntityTypeDefinition      $definition  Line definition supplying read visibility and per-field
     *          sensitivity.
     * @param   array<string, mixed>      $values      Decoded line values keyed by field handle.
     * @param   BusinessRecordAccessPlan  $access      Target field disclosure decision.
     * @param   FieldAccessUsage          $usage       Exact collection disclosure use.
     *
     * @return  array<string, mixed>  Reader-visible values keyed by handle; withheld and absent handles
     *          both stay absent.
     *
     * @since   2.0.0
     */
    private function visibleValues(
        EntityTypeDefinition $definition,
        array $values,
        BusinessRecordAccessPlan $access,
        FieldAccessUsage $usage,
    ): array {
        $visible = [];
        $definitionVisible = RecordFieldVisibility::fields($definition, $values);
        foreach ($definition->fields() as $field) {
            if (
                !isset($definitionVisible[$field->handle])
                || !$access->fields->allows($usage, $field->handle)
                || !array_key_exists($field->handle, $values)
                || $field->type === 'core.entity_reference'
                || in_array($field->sensitivity, [Sensitivity::Restricted, Sensitivity::Secret], true)
            ) {
                continue;
            }
            $visible[$field->handle] = $values[$field->handle];
        }

        return $visible;
    }

    /**
     * Prove one nested plan belongs to its declared target and may release that target's public identity.
     *
     * A source field grant does not itself authorize an entity-reference value or included row identity.
     * Both release paths require the exact nested resource and its explicit `public_reference` identity
     * field; a mismatched or narrower plan is treated as withheld rather than passed to a later query.
     *
     * @param   EntityTypeDefinition      $target  Definition reached through the source declaration.
     * @param   BusinessRecordAccessPlan  $access  Exact nested target policy plan.
     *
     * @return  bool  True only when the plan owns and publicly identifies this target.
     *
     * @since   2.0.0
     */
    private function publicIdentityAvailable(
        EntityTypeDefinition $target,
        BusinessRecordAccessPlan $access,
    ): bool {
        return hash_equals($target->id, $access->resourceIdentifier)
            && $access->fields->allows(
                FieldAccessUsage::PublicReference,
                $this->identityHandle($target),
            );
    }

    /**
     * Select the exact field-disclosure use for collection rows and their includes.
     *
     * @param   BusinessRecordAccessPlan  $access  Canonical operation-specific access decision.
     *
     * @return  FieldAccessUsage  Browse list, report, or export use.
     *
     * @since   2.0.0
     */
    private function collectionUsage(BusinessRecordAccessPlan $access): FieldAccessUsage
    {
        return match ($access->operation) {
            'business.record.report' => FieldAccessUsage::Report,
            'business.record.export' => FieldAccessUsage::Export,
            default => FieldAccessUsage::List,
        };
    }

    /**
     * Resolve an include handle to the relationship the definition declares under it.
     *
     * Goes through the definition's runtime view, so a legacy ordered-line field answers as the
     * owned-line relationship it stands for and an include can name either spelling.
     *
     * @param   EntityTypeDefinition  $definition  Definition the include was requested against.
     * @param   string                $handle      Relationship handle, or ordered-line field handle,
     *          named by the projection.
     *
     * @return  RelationshipDefinition  The declared or synthesized relationship.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the definition declares nothing under that handle.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When a matching
     *          ordered-line field names no usable target entity.
     *
     * @since   2.0.0
     */
    private function relationship(
        EntityTypeDefinition $definition,
        string $handle,
    ): RelationshipDefinition {
        return $definition->runtimeRelationship($handle)
            ?? throw new BusinessRecordSchemaUnavailable(
                'An included relationship is unavailable in the definition.',
            );
    }

    /**
     * Find the relationship on the target side that owns the storage for a non-canonical association.
     *
     * Only one side of an association materializes a column or junction table. When the side being
     * browsed is the other one, the include has to be read backwards through the declared inverse, and
     * this is where that inverse is proved to exist: it must be named by the source relationship, be
     * declared on the target, and point back at the source, or the include is refused rather than
     * silently answered as empty.
     *
     * @param   EntityTypeDefinition    $source        Definition the include was requested on.
     * @param   EntityTypeDefinition    $target        Definition on the far side, whose declarations are
     *          searched.
     * @param   RelationshipDefinition  $relationship  Non-canonical relationship whose `inverse` names
     *          the declaration to find.
     *
     * @return  RelationshipDefinition  The target-side relationship whose storage the include reads.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the relationship names no inverse, or the target
     *          declares nothing under that name that points back at the source.
     *
     * @since   2.0.0
     */
    private function inverseRelationship(
        EntityTypeDefinition $source,
        EntityTypeDefinition $target,
        RelationshipDefinition $relationship,
    ): RelationshipDefinition {
        if ($relationship->inverse !== null) {
            foreach ($target->relationships() as $candidate) {
                if ($candidate->handle === $relationship->inverse && $candidate->target === $source->handle) {
                    return $candidate;
                }
            }
        }
        throw new BusinessRecordSchemaUnavailable('An included canonical inverse relationship is unavailable.');
    }

    /**
     * Resolve the definition and installed schema on the far side of one relationship.
     *
     * @param   ResolvedBusinessDefinition  $source        Definition the relationship is declared on,
     *          supplying the site and the version to pin the target at.
     * @param   RelationshipDefinition      $relationship  Relationship whose declared target is resolved.
     *
     * @return  ResolvedBusinessDefinition  The target definition paired with its installed schema, fenced
     *          for the rest of the caller's transaction.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the target is absent or disabled, its schema is not
     *          active on this site, or the version it is pinned to is newer than the installed one.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable  When
     *          the target definition no longer exists on this site.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable  When
     *          the shared fence cannot be taken, or the installation moved since it was resolved.
     * @throws  \Kumwe\App\BusinessSchema\Domain\InvalidBusinessSchema  When the source definition's
     *          evolution metadata cannot be read for a pinned target version.
     * @throws  InvalidArgumentException  When the site identifier stored on the definition is malformed,
     *          or the resolved definition and installation disagree.
     *
     * @since   2.0.0
     */
    private function relationshipTarget(
        ResolvedBusinessDefinition $source,
        RelationshipDefinition $relationship,
    ): ResolvedBusinessDefinition {
        return $this->targetByHandle($source, $relationship->target);
    }

    /**
     * Fence and resolve another definition on the same site, at the version this source pins it to.
     *
     * Reaching sideways into a second definition is the one place a read touches a schema it was not
     * handed, so the target is fenced first: a shared lock on the target's installation is taken and
     * held for the rest of the caller's transaction, and the pair resolved afterwards is proved against
     * the generation observed under that lock. The version read is whatever the source's evolution
     * hints repin the handle to, defaulting to the installed one, and a pin ahead of what is installed
     * is refused rather than decoded against a shape that does not exist yet.
     *
     * @param   ResolvedBusinessDefinition  $source        Definition doing the reaching, supplying the
     *          site and the evolution hints that decide the pinned version.
     * @param   string                      $targetHandle  Handle of the definition to resolve, as a
     *          relationship or entity-reference field names it.
     *
     * @return  ResolvedBusinessDefinition  The target definition at its pinned version, paired with the
     *          installed schema the fence is holding.
     *
     * @throws  BusinessRecordSchemaUnavailable  When no such definition exists on the site, its owner is
     *          inactive, its installation is missing, not active, or registered to another site, the
     *          pinned version runs ahead of the installed one, or that version was never published.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable  When
     *          the fence finds no definition under the handle on this site.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable  When
     *          no transaction is open to hold the shared lock, the platform offers none, the lock cannot
     *          be taken, or the resolved pair differs from the fenced generation.
     * @throws  \Kumwe\App\BusinessSchema\Domain\InvalidBusinessSchema  When the source definition's
     *          evolution metadata is malformed, or the handle is not a namespaced definition handle.
     * @throws  InvalidArgumentException  When the site identifier stored on the definition is malformed,
     *          or the published definition and the installation disagree.
     *
     * @since   2.0.0
     */
    private function targetByHandle(
        ResolvedBusinessDefinition $source,
        string $targetHandle,
    ): ResolvedBusinessDefinition {
        $site = SiteContext::fromString($source->definition->siteIdentifier);
        $generation = $this->fence->shared($site, $targetHandle);
        $entry = $this->definitions->entry($site, $targetHandle);
        if ($entry === null || !$entry->ownerActive) {
            throw new BusinessRecordSchemaUnavailable('An included relationship target is unavailable.');
        }
        $installation = $this->installations->find($entry->id);
        if (
            $installation === null || $installation->status !== SchemaInstallationStatus::Active
            || $installation->siteIdentifier !== $source->definition->siteIdentifier
        ) {
            throw new BusinessRecordSchemaUnavailable('An included relationship target schema is unavailable.');
        }
        $version = SchemaEvolutionHints::fromDefinition($source->definition)->repin($targetHandle)
            ?? $installation->definitionVersion;
        if ($version > $installation->definitionVersion) {
            throw new BusinessRecordSchemaUnavailable('An included relationship target is newer than its schema.');
        }
        $published = $this->definitions->published($site, $entry->id, $version);
        if ($published === null) {
            throw new BusinessRecordSchemaUnavailable('An included relationship pinned definition is unavailable.');
        }

        $resolved = new ResolvedBusinessDefinition($published->definition, $installation);
        $generation->assertMatches($resolved);

        return $resolved;
    }

    /**
     * Append the tenant-scope predicates for one aliased table of a joined include statement.
     *
     * The alias-qualified counterpart of `scope()`, used where a statement names more than one table.
     * Scope is never optional: a dimension the caller carries but the table does not declare, and a
     * dimension the table declares but the caller left null, are both refused, so an include cannot
     * quietly widen past the page it hangs from. The three lists grow together and stay aligned by
     * position, which is what the caller binds them on.
     *
     * @param   PhysicalTableBlueprint           $table       Installed table whose scope columns are
     *          matched.
     * @param   string                           $alias       Alias that table carries in the statement.
     * @param   RecordScope                      $scope       Site and organization the rows must belong
     *          to.
     * @param   list<string>                     $where       Predicate list the new comparisons are
     *          appended to.
     * @param   list<mixed>                      $parameters  Bound value list the scope identifiers are
     *          appended to.
     * @param   list<string|ArrayParameterType>  $types       Bound type list the columns' Doctrine types
     *          are appended to.
     *
     * @return  void
     *
     * @throws  BusinessRecordSchemaUnavailable  When the caller carries a scope dimension the table does
     *          not declare, or the table declares one the caller left null.
     * @throws  \Doctrine\DBAL\Exception  When the platform to quote the column name for cannot be
     *          resolved.
     *
     * @since   2.0.0
     */
    private function qualifiedScope(
        PhysicalTableBlueprint $table,
        string $alias,
        RecordScope $scope,
        array &$where,
        array &$parameters,
        array &$types,
    ): void {
        foreach (
            [
            'site_identifier' => $scope->siteIdentifier,
            'organization_identifier' => $scope->organizationIdentifier,
            ] as $logical => $value
        ) {
            $column = $table->column($logical);
            if ($column === null) {
                if ($value !== null) {
                    throw new BusinessRecordSchemaUnavailable('Included relationship scope disagrees with storage.');
                }
                continue;
            }
            if ($value === null) {
                throw new BusinessRecordSchemaUnavailable('An included relationship requires a missing scope.');
            }
            $where[] = $alias . '.' . $this->quote($column->physicalName) . ' = ?';
            $parameters[] = $value;
            $types[] = $column->doctrineType;
        }
    }

    /**
     * Read an optional ordinal out of an include row's synthetic position column.
     *
     * Drivers report an integer column as either an int or a decimal string depending on the platform,
     * so both are accepted; anything else is a corrupt row rather than a missing position.
     *
     * @param   mixed  $value  Raw `__position` column of an include row, or null.
     *
     * @return  ?int  The ordinal, or null when the relationship carries no order.
     *
     * @throws  BusinessRecordSchemaUnavailable  When a non-null value is neither an integer nor a string
     *          of digits, optionally signed.
     *
     * @since   2.0.0
     */
    private function nullableInteger(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^-?[0-9]+$/D', $value) !== 1) {
            throw new BusinessRecordSchemaUnavailable('An included relationship position is invalid.');
        }

        return (int) $value;
    }

    /**
     * Resolve a line's caller-facing id inside one owner record's owned-line collection.
     *
     * An owned line has no identity outside its owner, so the lookup is keyed on the owner's storage key
     * rather than on a record scope — the owner was already resolved in scope — and a line belonging to
     * another owner is simply not found. The pinned version reported back is the line definition's own,
     * taken from the argument rather than read off the row.
     *
     * @param   ResolvedBusinessDefinition  $owner         Owner definition whose installation carries
     *          the line table for this relationship.
     * @param   BusinessRecord              $ownerRecord   Owner record the line must belong to.
     * @param   RelationshipDefinition      $relationship  Owned-line relationship naming that table.
     * @param   ResolvedBusinessDefinition  $lineResolved  Pinned line definition and installed schema;
     *          its identity strategy decides which column the id is matched against.
     * @param   BusinessRecordAccessPlan    $access        Target row policy evaluated before identity
     *          is returned.
     * @param   string                      $lineId        Caller-facing identity of the line.
     *
     * @return  ?StoredRecordIdentity  Internal key and optimistic-lock version of the line, carrying the
     *          line definition's version; null when this owner holds no such line.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the installation carries no line table for the
     *          relationship, the table lacks a column this lookup names, the line definition declares no
     *          identity field, or a stored key or version column is not its declared type.
     * @throws  InvalidArgumentException  When the stored line key is not a canonical UUID, the stored
     *          identity is empty or malformed, or either version is below one.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the lookup, or the platform to quote
     *          identifiers for cannot be resolved.
     *
     * @since   2.0.0
     */
    public function ownedLineIdentity(
        ResolvedBusinessDefinition $owner,
        BusinessRecord $ownerRecord,
        RelationshipDefinition $relationship,
        ResolvedBusinessDefinition $lineResolved,
        BusinessRecordAccessPlan $access,
        string $lineId,
    ): ?StoredRecordIdentity {
        $table = $owner->installation->blueprint->table('line:' . $relationship->handle)
            ?? throw new BusinessRecordSchemaUnavailable('The installed owned-line table is unavailable.');
        $lineDefinition = $lineResolved->definition;
        $identityPhysical = $lineDefinition->identityStrategy === IdentityStrategy::Uuid
            ? $this->physical($table, 'line_id')
            : $this->physical($table, $this->identityHandle($lineDefinition));
        $policy = $this->queries->compileAccessPredicate(
            $lineResolved,
            $ownerRecord->scope,
            $access,
            $table,
        );
        $parameters = [$ownerRecord->recordKey, $lineId, ...$policy->parameters];
        $types = [
            $this->type($table, 'owner_id'),
            $this->physicalType($table, $identityPhysical),
            ...$policy->types,
        ];
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT r0.%s, r0.%s, r0.%s FROM %s r0 WHERE r0.%s = ? AND r0.%s = ? AND %s',
            $this->quote($this->physical($table, 'line_id')),
            $this->quote($identityPhysical),
            $this->quote($this->physical($table, 'version')),
            $this->quote($table->physicalName),
            $this->quote($this->physical($table, 'owner_id')),
            $this->quote($identityPhysical),
            $policy->sql,
        ), $parameters, $types);
        if ($row === false) {
            return null;
        }
        $recordKey = $this->string($row, $this->physical($table, 'line_id'));

        return new StoredRecordIdentity(
            $recordKey,
            $this->string($row, $identityPhysical),
            $lineDefinition->definitionVersion,
            $this->integer($row, $this->physical($table, 'version')),
        );
    }

    /**
     * Read one owner's whole owned-line collection in position order, unfiltered by actor row policy.
     *
     * One statement returns the collection, because a document write and a document rule both need it
     * whole and neither can afford a query per line. Rows are decoded against the pinned line definition
     * and their virtual computations rebuilt, so a rule that reduces a computed line field reads the same
     * value a reader would. No access predicate is compiled into the statement, deliberately: the caller
     * is replacing or judging the collection that exists, and it is the caller that must hold each line
     * against the relationship's row policy and refuse the command rather than quietly work on a subset.
     *
     * @param   ResolvedBusinessDefinition  $owner         Owner definition whose installation carries the
     *          line table for this relationship.
     * @param   BusinessRecord              $ownerRecord   Owner record whose collection is read.
     * @param   RelationshipDefinition      $relationship  Owned-line relationship naming that table.
     * @param   ResolvedBusinessDefinition  $lineResolved  Pinned line definition the rows decode against.
     * @param   int                         $limit         Largest number of rows to return.
     *
     * @return  list<StoredOwnedLine>  The collection in position then storage-key order.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the installation carries no line table for the
     *          relationship, or the table lacks a column this read names.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordValidationFailed  When a
     *          decoded line's virtual computations cannot be rebuilt.
     * @throws  InvalidArgumentException  When a stored line key, identity or version is malformed.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the read, or the platform to quote
     *          identifiers for cannot be resolved.
     *
     * @since   2.0.0
     */
    public function ownedLinesForDocumentIntegrity(
        ResolvedBusinessDefinition $owner,
        BusinessRecord $ownerRecord,
        RelationshipDefinition $relationship,
        ResolvedBusinessDefinition $lineResolved,
        int $limit,
    ): array {
        if ($relationship->kind !== RelationshipKind::OwnedLineCollection) {
            throw new BusinessRecordSchemaUnavailable('Only an owned-line collection carries document lines.');
        }
        $table = $owner->installation->blueprint->table('line:' . $relationship->handle)
            ?? throw new BusinessRecordSchemaUnavailable('The installed owned-line table is unavailable.');
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT r0.* FROM %s r0 WHERE r0.%s = ? ORDER BY r0.%s, r0.%s LIMIT %d',
            $this->quote($table->physicalName),
            $this->quote($this->physical($table, 'owner_id')),
            $this->quote($this->physical($table, 'position')),
            $this->quote($this->physical($table, 'line_id')),
            max(1, $limit),
        ), [$ownerRecord->recordKey], [$this->type($table, 'owner_id')]);
        $lines = [];
        foreach ($rows as $row) {
            $recordKey = $this->string($row, $this->physical($table, 'line_id'));
            $values = $this->values->decodeColumns(
                $lineResolved->definition,
                $table,
                $row,
                $lineResolved->definition->siteIdentifier,
                $recordKey,
            );
            $values = $this->rules->materialize(
                $lineResolved->definition,
                $values,
                $lineResolved->definition->siteIdentifier,
                $recordKey,
            );
            $lines[] = new StoredOwnedLine(
                $recordKey,
                $this->values->publicIdentity($lineResolved->definition, $recordKey, $values),
                $this->integer($row, $this->physical($table, 'position')),
                $this->integer($row, $this->physical($table, 'version')),
                $values,
            );
        }

        return $lines;
    }

    /**
     * Turn one fetched row into the domain record, under the definition version it was written with.
     *
     * This is the single decode path every read shares. The row's own `definition_version` column must
     * equal the version of $resolved, so a caller that failed to re-pin gets a refusal instead of a
     * record decoded against the wrong shape. Values are rebuilt through the codec, virtual formula
     * fields are recomputed rather than trusted from storage, and the caller-facing identity is derived
     * last, from the values the identity strategy actually keeps it in.
     *
     * @param   ResolvedBusinessDefinition  $resolved  Pinned definition the row must have been written
     *          under, and whose fields the columns are decoded into.
     * @param   PhysicalTableBlueprint      $table     Installed table the row was fetched from.
     * @param   array<string, mixed>        $row       Raw column values as the driver returned them.
     *
     * @return  BusinessRecord  The decoded record, with its scope reconstituted from the stored columns.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the row was written under a different definition
     *          version, the table lacks a column the decode names, or a stored control column is not its
     *          declared type.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordValidationFailed  When the
     *          decoded row's virtual formula fields cannot be recomputed.
     * @throws  InvalidArgumentException  When a stored value contradicts the physical type declared for
     *          its column, the stored scope columns disagree with the definition's scope mode, a
     *          reference-identity row decoded no identity, or the row breaks a `BusinessRecord`
     *          invariant.
     * @throws  \DateMalformedStringException  When a stored timestamp column holds an unparsable string.
     *
     * @since   2.0.0
     */
    private function map(
        ResolvedBusinessDefinition $resolved,
        PhysicalTableBlueprint $table,
        array $row,
    ): BusinessRecord {
        $recordKey = $this->string($row, $this->physical($table, 'record_id'));
        $definitionVersion = $this->integer($row, $this->physical($table, 'definition_version'));
        if ($definitionVersion !== $resolved->definition->definitionVersion) {
            throw new BusinessRecordSchemaUnavailable('A row was decoded with the wrong pinned definition version.');
        }
        $site = $this->optionalString($row, $table, 'site_identifier');
        $organization = $this->optionalString($row, $table, 'organization_identifier');
        $scope = RecordScope::reconstitute($resolved->definition->scope, $site, $organization);
        $values = $this->values->decodeColumns(
            $resolved->definition,
            $table,
            $row,
            $resolved->definition->siteIdentifier,
            $recordKey,
        );
        $values = $this->rules->materialize(
            $resolved->definition,
            $values,
            $resolved->definition->siteIdentifier,
            $recordKey,
        );
        $recordId = $this->values->publicIdentity($resolved->definition, $recordKey, $values);

        return new BusinessRecord(
            $resolved->definition->id,
            $definitionVersion,
            $recordKey,
            $recordId,
            $scope,
            $this->integer($row, $this->physical($table, 'version')),
            $this->optionalString($row, $table, 'workflow_state'),
            $values,
            $this->string($row, $this->physical($table, 'created_by')),
            $this->date($row[$this->physical($table, 'created_at')] ?? null),
            $this->string($row, $this->physical($table, 'updated_by')),
            $this->date($row[$this->physical($table, 'updated_at')] ?? null),
            $this->optionalString($row, $table, 'archived_by'),
            $this->optionalDate($row, $table, 'archived_at'),
            $this->optionalString($row, $table, 'deleted_by'),
            $this->optionalDate($row, $table, 'deleted_at'),
        );
    }

    /**
     * Append the tenant-scope predicates for a statement that names one unaliased table.
     *
     * Scope is bound, never interpolated, and it is never optional: a dimension the caller carries but
     * the installed table does not declare, and a dimension the table declares but the caller left null,
     * are both refused rather than dropped, so a read cannot escape its tenant by omission. The three
     * lists grow together and stay aligned by position, which is what the caller binds them on.
     *
     * @param   PhysicalTableBlueprint  $table       Installed table whose scope columns are matched.
     * @param   RecordScope             $scope       Site and organization the rows must belong to.
     * @param   list<string>            $where       Predicate list the new comparisons are appended to.
     * @param   list<mixed>             $parameters  Bound value list the scope identifiers are appended
     *          to.
     * @param   list<string>            $types       Bound type list the columns' Doctrine types are
     *          appended to.
     *
     * @return  void
     *
     * @throws  BusinessRecordSchemaUnavailable  When the request carries a scope dimension the installed
     *          table does not declare, or the table declares one the request left null.
     * @throws  \Doctrine\DBAL\Exception  When the platform to quote the column name for cannot be
     *          resolved.
     *
     * @since   2.0.0
     */
    private function scope(
        PhysicalTableBlueprint $table,
        RecordScope $scope,
        array &$where,
        array &$parameters,
        array &$types,
    ): void {
        foreach (
            [
            'site_identifier' => $scope->siteIdentifier,
            'organization_identifier' => $scope->organizationIdentifier,
            ] as $logical => $value
        ) {
            $column = $table->column($logical);
            if ($column === null) {
                if ($value !== null) {
                    throw new BusinessRecordSchemaUnavailable('Request scope disagrees with installed schema.');
                }
                continue;
            }
            if ($value === null) {
                throw new BusinessRecordSchemaUnavailable('The installed schema requires a missing scope.');
            }
            $where[] = $this->quote($column->physicalName) . ' = ?';
            $parameters[] = $value;
            $types[] = $column->doctrineType;
        }
    }

    /**
     * Read a control column that the installed table may not declare at all.
     *
     * Lifecycle and scope columns exist only when the definition asked for them, so an absent column and
     * a NULL value are deliberately both reported as null: the caller wants "no value", not "no column".
     *
     * @param   array<string, mixed>    $row      Raw column values as the driver returned them.
     * @param   PhysicalTableBlueprint  $table    Installed table the row was fetched from.
     * @param   string                  $logical  Logical column handle, such as `archived_by`.
     *
     * @return  ?string  The stored text, or null when the table declares no such column or it is NULL.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the column is present but holds something other
     *          than a string.
     *
     * @since   2.0.0
     */
    private function optionalString(
        array $row,
        PhysicalTableBlueprint $table,
        string $logical,
    ): ?string {
        $column = $table->column($logical);
        if ($column === null) {
            return null;
        }
        $value = $row[$column->physicalName] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new BusinessRecordSchemaUnavailable('A stored business-record string is invalid.');
        }

        return $value;
    }

    /**
     * Read a lifecycle timestamp that the installed table may not declare at all.
     *
     * The archive and soft-delete columns exist only when the definition asked for them, so an absent
     * column and a NULL value both mean the record never reached that state.
     *
     * @param   array<string, mixed>    $row      Raw column values as the driver returned them.
     * @param   PhysicalTableBlueprint  $table    Installed table the row was fetched from.
     * @param   string                  $logical  Logical column handle, such as `deleted_at`.
     *
     * @return  ?DateTimeImmutable  The instant the column records, or null when the table declares no
     *          such column or it is NULL.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the column holds neither a date-time nor a string.
     * @throws  \DateMalformedStringException  When the stored string cannot be parsed as an instant.
     *
     * @since   2.0.0
     */
    private function optionalDate(
        array $row,
        PhysicalTableBlueprint $table,
        string $logical,
    ): ?DateTimeImmutable {
        $column = $table->column($logical);
        if ($column === null || ($row[$column->physicalName] ?? null) === null) {
            return null;
        }

        return $this->date($row[$column->physicalName]);
    }

    /**
     * Read a column the decode cannot proceed without, and prove it came back as text.
     *
     * Used for the key, identity and actor columns, where a missing column and a NULL are equally fatal,
     * so both are reported as a corrupt row rather than as an absent value.
     *
     * @param   array<string, mixed>  $row       Raw column values as the driver returned them.
     * @param   string                $physical  Installed column name to read.
     *
     * @return  string  The stored text.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the column is absent, NULL, or holds something
     *          other than a string.
     *
     * @since   2.0.0
     */
    private function string(array $row, string $physical): string
    {
        $value = $row[$physical] ?? null;
        if (!is_string($value)) {
            throw new BusinessRecordSchemaUnavailable('A stored business-record string is invalid.');
        }

        return $value;
    }

    /**
     * Read a version-style column and prove it came back as a whole number.
     *
     * Drivers report an integer column as either an int or a decimal string depending on the platform,
     * so both are accepted; a string that is anything but a plain run of digits is a corrupt row.
     *
     * @param   array<string, mixed>  $row       Raw column values as the driver returned them.
     * @param   string                $physical  Installed column name to read.
     *
     * @return  int  The stored number.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the column is absent, NULL, or holds neither an
     *          integer nor a string of digits.
     *
     * @since   2.0.0
     */
    private function integer(array $row, string $physical): int
    {
        $value = $row[$physical] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1) {
            throw new BusinessRecordSchemaUnavailable('A stored business-record integer is invalid.');
        }

        return (int) $value;
    }

    /**
     * Normalize whatever a driver returned for a timestamp column into an immutable instant.
     *
     * Platforms differ over whether a date-time column arrives already converted or as a string, so both
     * are accepted. A stored string carrying no offset is read as UTC, since that is how the write side
     * stores one; a value that already states an offset keeps it, so the point in time is preserved
     * rather than reinterpreted.
     *
     * @param   mixed  $value  Raw timestamp column as the driver returned it.
     *
     * @return  DateTimeImmutable  The instant the column records.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the value is neither a date-time nor a string.
     * @throws  \DateMalformedStringException  When the string cannot be parsed as an instant.
     *
     * @since   2.0.0
     */
    private function date(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof DateTimeInterface || is_string($value)) {
            return new DateTimeImmutable(
                $value instanceof DateTimeInterface ? $value->format(DateTimeInterface::ATOM) : $value,
                new DateTimeZone('UTC'),
            );
        }
        throw new BusinessRecordSchemaUnavailable('A stored business-record timestamp is invalid.');
    }

    /**
     * Take the installed record table out of a resolved definition's blueprint.
     *
     * Every read of a record table starts here, so a definition whose installation carries no `record`
     * table is refused up front rather than producing a statement against a table that does not exist.
     *
     * @param   ResolvedBusinessDefinition  $resolved  Definition paired with the schema installed for it.
     *
     * @return  PhysicalTableBlueprint  Blueprint of the table the definition's records live in.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the installed blueprint declares no record table.
     *
     * @since   2.0.0
     */
    private function recordTable(ResolvedBusinessDefinition $resolved): PhysicalTableBlueprint
    {
        return $resolved->installation->blueprint->table('record')
            ?? throw new BusinessRecordSchemaUnavailable('The installed schema has no record table.');
    }

    /**
     * Re-resolve the published definition one fetched row was actually written under.
     *
     * A table holds rows from every version that has ever been written to it, so a scan cannot assume
     * the caller's definition describes them all. The caller's own pair is returned unchanged in the
     * common case, and only a row that disagrees costs a lookup; the result is paired with the same
     * installation, since an older version of the same definition still lives in the same tables.
     *
     * @param   ResolvedBusinessDefinition  $resolved  Definition the read was started with, reused when
     *          the row agrees with it and supplying the installation either way.
     * @param   PhysicalTableBlueprint      $table     Installed table the row was fetched from.
     * @param   array<string, mixed>        $row       Raw column values as the driver returned them.
     *
     * @return  ResolvedBusinessDefinition  The pair the row must be decoded with.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the row's version column is unusable, or that
     *          version was never published for this definition.
     * @throws  InvalidArgumentException  When the site identifier stored on the definition is malformed,
     *          or the older definition version disagrees with the installation.
     *
     * @since   2.0.0
     */
    private function pinnedForRow(
        ResolvedBusinessDefinition $resolved,
        PhysicalTableBlueprint $table,
        array $row,
    ): ResolvedBusinessDefinition {
        $version = $this->integer($row, $this->physical($table, 'definition_version'));
        if ($version === $resolved->definition->definitionVersion) {
            return $resolved;
        }
        $published = $this->definitions->published(
            SiteContext::fromString($resolved->definition->siteIdentifier),
            $resolved->definition->id,
            $version,
        );
        if ($published === null) {
            throw new BusinessRecordSchemaUnavailable('A browsed row\'s pinned definition is unavailable.');
        }

        return new ResolvedBusinessDefinition($published->definition, $resolved->installation);
    }

    /**
     * Translate a logical column handle into the name it was installed under.
     *
     * Column names are interpolated into these statements rather than bound, so every one is looked up
     * in the installed blueprint first: a handle the blueprint does not declare produces a refusal
     * rather than text.
     *
     * @param   PhysicalTableBlueprint  $table    Installed table to resolve against.
     * @param   string                  $logical  Logical column handle, such as `record_id`.
     *
     * @return  string  The installed column name, still unquoted.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the table declares no column under that handle.
     *
     * @since   2.0.0
     */
    private function physical(PhysicalTableBlueprint $table, string $logical): string
    {
        return $table->column($logical)->physicalName
            ?? throw new BusinessRecordSchemaUnavailable('An installed business-record column is unavailable.');
    }

    /**
     * Report the Doctrine type a logical column's value must be bound with.
     *
     * Values are bound by type rather than interpolated, so the type comes from the same installed
     * blueprint the column name does and never from a guess about the value.
     *
     * @param   PhysicalTableBlueprint  $table    Installed table to resolve against.
     * @param   string                  $logical  Logical column handle, such as `owner_id`.
     *
     * @return  string  Doctrine type name to bind a parameter for that column with.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the table declares no column under that handle.
     *
     * @since   2.0.0
     */
    private function type(PhysicalTableBlueprint $table, string $logical): string
    {
        return $table->column($logical)->doctrineType
            ?? throw new BusinessRecordSchemaUnavailable('An installed business-record column type is unavailable.');
    }

    /**
     * Decide which installed column a caller-facing record id is matched against.
     *
     * A UUID definition is looked up on the storage key itself, because the key and the public identity
     * are the same value. A reference-identity definition keeps its identity in a field of its own, and
     * which field that is was recorded in the table's own options when the schema was compiled — so the
     * answer comes from the installed metadata rather than from the current definition.
     *
     * @param   ResolvedBusinessDefinition  $resolved  Definition whose identity strategy decides the
     *          source of the answer.
     * @param   PhysicalTableBlueprint      $table     Installed table whose options record the identity
     *          field.
     *
     * @return  string  Installed name of the column to match a record id against.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the table metadata records no string
     *          `identity_field`, or declares no column under it.
     *
     * @since   2.0.0
     */
    private function identityPhysical(
        ResolvedBusinessDefinition $resolved,
        PhysicalTableBlueprint $table,
    ): string {
        if ($resolved->definition->identityStrategy === IdentityStrategy::Uuid) {
            return $this->physical($table, 'record_id');
        }
        $handle = $table->options['identity_field'] ?? null;
        if (!is_string($handle)) {
            throw new BusinessRecordSchemaUnavailable('A reference identity field is absent from schema metadata.');
        }

        return $this->physical($table, $handle);
    }

    /**
     * Find the field handle a definition carries its identity in.
     *
     * The counterpart of `identityPhysical()` for the cases where no table metadata is available — an
     * owned line, or a relationship target being read for its public id — so the handle is recovered
     * from the definition's own fields, by the field type its identity strategy implies.
     *
     * @param   EntityTypeDefinition  $definition  Definition whose identity strategy names the field
     *          type to look for.
     *
     * @return  string  Handle of the `core.uuid` or `core.reference_identity` field.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the definition declares no field of the type its
     *          identity strategy requires.
     *
     * @since   2.0.0
     */
    private function identityHandle(EntityTypeDefinition $definition): string
    {
        $type = $definition->identityStrategy === IdentityStrategy::Uuid
            ? 'core.uuid'
            : 'core.reference_identity';
        foreach ($definition->fields() as $field) {
            if ($field->type === $type) {
                return $field->handle;
            }
        }
        throw new BusinessRecordSchemaUnavailable('A line definition identity field is unavailable.');
    }

    /**
     * Report the Doctrine type of a column already known by its installed name.
     *
     * The identity column is chosen by physical name rather than by logical handle, so its binding type
     * has to be recovered the same way instead of through `type()`.
     *
     * @param   PhysicalTableBlueprint  $table     Installed table to search.
     * @param   string                  $physical  Installed column name to bind a value for.
     *
     * @return  string  Doctrine type name to bind a parameter for that column with.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the table declares no column under that installed
     *          name.
     *
     * @since   2.0.0
     */
    private function physicalType(PhysicalTableBlueprint $table, string $physical): string
    {
        foreach ($table->columns() as $column) {
            if ($column->physicalName === $physical) {
                return $column->doctrineType;
            }
        }
        throw new BusinessRecordSchemaUnavailable('A physical identity column is absent from its blueprint.');
    }

    /**
     * Quote one installed identifier for the connected platform.
     *
     * Table and column names reach these statements from the installed blueprint rather than from a
     * request, and quoting them keeps a generated name that collides with a reserved word usable on
     * every supported engine.
     *
     * @param   string  $identifier  Single installed table or column name, never a dotted path.
     *
     * @return  string  The identifier quoted the way the connected driver expects.
     *
     * @throws  \Doctrine\DBAL\Exception  When the platform to quote for cannot be resolved.
     *
     * @since   2.0.0
     */
    private function quote(string $identifier): string
    {
        return $this->database->getDatabasePlatform()->quoteSingleIdentifier($identifier);
    }
}
