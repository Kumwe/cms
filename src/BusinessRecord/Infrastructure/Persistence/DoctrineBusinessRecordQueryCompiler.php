<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\App\BusinessDefinition\Domain\ComputationMode;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\App\BusinessDefinition\Domain\IdentityStrategy;
use Kumwe\App\BusinessDefinition\Domain\RelationshipDefinition;
use Kumwe\App\BusinessDefinition\Domain\RelationshipKind;
use Kumwe\App\BusinessDefinition\Domain\Sensitivity;
use Kumwe\App\BusinessRecord\Application\Exception\InvalidBusinessRecordQuery;
use Kumwe\App\BusinessRecord\Application\BusinessRecordMutationFence;
use Kumwe\App\BusinessRecord\Application\RecordCursorCodec;
use Kumwe\App\BusinessRecord\Application\RecordValueCodec;
use Kumwe\App\BusinessRecord\Application\ResolvedBusinessDefinition;
use Kumwe\App\BusinessRecord\Domain\RecordScope;
use Kumwe\Extension\Spi\BusinessRecord\Query\AggregateFunction;
use Kumwe\Extension\Spi\BusinessRecord\Query\BooleanFilter;
use Kumwe\Extension\Spi\BusinessRecord\Query\BooleanOperator;
use Kumwe\Extension\Spi\BusinessRecord\Query\ComparisonFilter;
use Kumwe\Extension\Spi\BusinessRecord\Query\ComparisonOperator;
use Kumwe\Extension\Spi\BusinessRecord\Query\NullFilter;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordAggregate;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordFilter;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordSort;
use Kumwe\Extension\Spi\BusinessRecord\Query\RelationFilter;
use Kumwe\Extension\Spi\BusinessRecord\Query\RelationQuantifier;
use Kumwe\Extension\Spi\BusinessRecord\Query\SetFilter;
use Kumwe\Extension\Spi\BusinessRecord\Query\SortDirection;
use Kumwe\Extension\Spi\BusinessRecord\Query\TextFilter;
use Kumwe\Extension\Spi\BusinessRecord\Query\TextOperator;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaInstallationRepository;
use Kumwe\App\BusinessSchema\Domain\PhysicalColumnBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalTableBlueprint;
use Kumwe\App\BusinessSchema\Domain\SchemaEvolutionHints;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessPlan;
use Kumwe\Extension\Spi\BusinessSecurity\Application\FieldAccessUsage;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicyBoolean;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicyBooleanOperator;
use Kumwe\Extension\Spi\BusinessSecurity\Policy\RecordPolicyComparison;
use Kumwe\Extension\Spi\BusinessSecurity\Policy\RecordPolicyComparisonOperator;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicyConstant;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicyNullCheck;
use Kumwe\Extension\Spi\BusinessSecurity\Policy\RecordPolicyPredicate;
use Kumwe\Extension\Spi\BusinessSecurity\Policy\RecordPolicyValueType;
use Ramsey\Uuid\Uuid;

/**
 * Compiles one business-record browse into the SQL, bindings and read metadata the repository runs.
 *
 * Business records live in tables the schema installer generated for a definition, so no part of a
 * query can be settled until the pinned definition and the installed blueprint are read together.
 * That resolution is this class's whole job: a field handle becomes physical columns, the request
 * scope and the archive and soft-delete state become predicates, keyset paging becomes an explicit
 * `ORDER BY` and a seek predicate, a relationship hop becomes a correlated `EXISTS`, and requested
 * aggregates become a second statement over the same predicates. Compiling only metadata-validated
 * identifiers and always binding caller-supplied values is the guarantee that makes that safe: nothing
 * reaches a statement as text unless the installed blueprint named it, and every literal travels in
 * the parameter list beside the type it is bound with. The definition's own permissions are enforced
 * here too — a field must be declared filterable, sortable, searchable or reportable for the clause
 * that names it, and a restricted or secret field is refused outright rather than queried on.
 * Everything the definition or the installed schema cannot answer is refused as
 * `InvalidBusinessRecordQuery`, which is why `DoctrineBusinessRecordReadRepository` can execute the
 * `CompiledRecordQuery` it gets back without inspecting it.
 *
 * @since  2.0.0
 */
final readonly class DoctrineBusinessRecordQueryCompiler
{
    /**
     * Wire the compiler to the metadata, codecs and connection it resolves a query against.
     *
     * @param  Connection                            $database       Connection whose platform quotes every
     *         identifier, and which resolves an entity-reference literal to the record key it is stored as.
     * @param  BusinessDefinitionRepository          $definitions    Source of the published definition a
     *         relationship hop or entity reference points at.
     * @param  BusinessSchemaInstallationRepository  $installations  Source of the installed schema behind
     *         such a target, which decides the columns its part of the statement may name.
     * @param  RecordValueCodec                      $values         Normalizes and encodes caller literals
     *         and cursor values into the storage form each physical column is bound with.
     * @param  RecordCursorCodec                     $cursors        Verifies and decodes the signed page
     *         cursor a caller presents.
     * @param  BusinessRecordMutationFence           $fence          Holds a relation target's installation
     *         still for the rest of the transaction, so a hop cannot read a table an installer is moving.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private BusinessDefinitionRepository $definitions,
        private BusinessSchemaInstallationRepository $installations,
        private RecordValueCodec $values,
        private RecordCursorCodec $cursors,
        private BusinessRecordMutationFence $fence,
    ) {
    }

    /**
     * Compile one page of a browse into an executable statement, its bindings and its read metadata.
     *
     * This is the only entry point, and it exists to make refusal uniform: the value codecs reject a
     * literal with `InvalidArgumentException` while the resolvers refuse a handle with
     * `InvalidBusinessRecordQuery`, and a caller should not have to tell the two apart, so everything
     * leaves here under the second name.
     *
     * @param   ResolvedBusinessDefinition  $resolved       Definition version being browsed together with
     *          the schema installed for it, which between them decide every identifier the statement may
     *          name.
     * @param   RecordScope                 $scope          Site and organization the browse is confined to,
     *          bound into the statement rather than written into it.
     * @param   RecordQuerySpecification    $specification  Filter, search, ordering, cursor, page size and
     *          projection of the page being read.
     * @param   BusinessRecordAccessPlan    $access         Row, field and related-resource query decision.
     *
     * @return  CompiledRecordQuery  Page statement and optional aggregate statement with their bindings,
     *          the field handles the projection resolved to, the columns the next cursor is read from, and
     *          the digest that cursor must carry.
     *
     * @throws  InvalidBusinessRecordQuery  When the specification names a field, relationship or operator
     *          the definition or the installed schema does not offer, presents a cursor minted for a
     *          different query, or carries a literal the queried field cannot hold.
     *
     * @since   2.0.0
     */
    public function compile(
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        RecordQuerySpecification $specification,
        BusinessRecordAccessPlan $access,
    ): CompiledRecordQuery {
        try {
            return $this->doCompile($resolved, $scope, $specification, $access);
        } catch (InvalidBusinessRecordQuery $exception) {
            throw $exception;
        } catch (InvalidArgumentException $exception) {
            throw new InvalidBusinessRecordQuery($exception->getMessage());
        }
    }

    /**
     * Compile the access predicate used by a single-record or relationship read.
     *
     * The returned predicate targets the `r0` alias, matching the read repository's policy-sensitive
     * statements. It is the same compiler used by browse, so direct reads and pages cannot disagree on
     * allow/deny or null semantics.
     *
     * @param   ResolvedBusinessDefinition  $resolved  Pinned definition and installed schema being read.
     * @param   RecordScope                 $scope     Site and organization entity references are confined to.
     * @param   BusinessRecordAccessPlan    $access    Authoritative row and related-target decision.
     * @param   ?PhysicalTableBlueprint     $table     Alternate installed line table, or null for records.
     *
     * @return  CompiledRecordPredicate  Definite-boolean SQL and ordered Doctrine bindings.
     *
     * @throws  InvalidBusinessRecordQuery  When the plan names another resource or an unavailable field/type.
     *
     * @since   2.0.0
     */
    public function compileAccessPredicate(
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        BusinessRecordAccessPlan $access,
        ?PhysicalTableBlueprint $table = null,
    ): CompiledRecordPredicate {
        try {
            $this->assertAccessResource($resolved, $access);
            $parameters = [];
            $types = [];
            $sql = $this->recordPolicy(
                $resolved,
                $table ?? $this->recordTable($resolved),
                'r0',
                $scope,
                $access,
                $parameters,
                $types,
            );

            return new CompiledRecordPredicate($sql, $parameters, $types);
        } catch (InvalidBusinessRecordQuery $exception) {
            throw $exception;
        } catch (InvalidArgumentException $exception) {
            throw new InvalidBusinessRecordQuery($exception->getMessage());
        }
    }

    /**
     * Compile immutable row policy over the canonical JSON snapshot of a hard-deleted record revision.
     *
     * Hard-deleted history has no live row to join, so its identity-digest lookup must apply policy to the
     * append-only snapshot itself. The returned predicate targets the fixed `rv0.snapshot` expression and
     * preserves evaluator null, scalar-type, decimal and temporal semantics on both MySQL-compatible and
     * PostgreSQL platforms. No policy-authored identifier or SQL fragment is accepted.
     *
     * @param   ResolvedBusinessDefinition  $resolved  Installed definition supplying the closed field schema.
     * @param   BusinessRecordAccessPlan    $access    Default-deny policy resolved for history access.
     *
     * @return  CompiledRecordPredicate  Definite-boolean revision predicate and ordered bindings.
     *
     * @throws  InvalidBusinessRecordQuery  When the policy belongs to another resource, names an unavailable
     *          field or scalar domain, or the configured database cannot query canonical JSON safely.
     *
     * @since   2.0.0
     */
    public function compileRevisionAccessPredicate(
        ResolvedBusinessDefinition $resolved,
        BusinessRecordAccessPlan $access,
    ): CompiledRecordPredicate {
        try {
            $this->assertAccessResource($resolved, $access);
            $platform = $this->database->getDatabasePlatform();
            if (!$platform instanceof AbstractMySQLPlatform && !$platform instanceof PostgreSQLPlatform) {
                throw new InvalidBusinessRecordQuery(
                    'The configured database cannot enforce policy over revision snapshots.',
                );
            }
            $parameters = [];
            $types = [];
            $sql = $this->revisionRecordPolicy(
                $resolved,
                $this->recordTable($resolved),
                $access,
                $parameters,
                $types,
            );

            return new CompiledRecordPredicate($sql, $parameters, $types);
        } catch (InvalidBusinessRecordQuery $exception) {
            throw $exception;
        } catch (InvalidArgumentException $exception) {
            throw new InvalidBusinessRecordQuery($exception->getMessage());
        }
    }

    /**
     * Build the page statement, its optional aggregate companion and the metadata the reader needs.
     *
     * Assembly order is load-bearing. Scope, lifecycle, filter and search predicates are collected first
     * and copied aside for the aggregate statement, so a total is measured over every record the query
     * matches; only then is the cursor's seek predicate appended to the page statement, which is what
     * keeps the page bound out of that total. The `SELECT` covers the projection plus the columns the
     * next cursor is read from, and the `LIMIT` asks for one row more than the page size so the
     * repository can see that a further page exists without counting.
     *
     * @param   ResolvedBusinessDefinition  $resolved       Definition version and installed schema the
     *          statement is built against.
     * @param   RecordScope                 $scope          Site and organization every predicate is
     *          confined to.
     * @param   RecordQuerySpecification    $specification  The page being read, already inside the bounds
     *          its own constructor enforces.
     * @param   BusinessRecordAccessPlan    $access         Authorization decision compiled before paging.
     *
     * @return  CompiledRecordQuery  The compiled page, ready to execute.
     *
     * @throws  InvalidBusinessRecordQuery  When a handle resolves to nothing, a field is not permitted in
     *          the clause that names it, a search field is not stored in a single textual column, or the
     *          presented cursor was minted for a different query.
     * @throws  InvalidArgumentException  When a cursor value cannot be restored to the storage form of the
     *          ordering column it belongs to. `compile()` is the only caller and converts it, so no
     *          caller outside this class sees it.
     *
     * @since   2.0.0
     */
    private function doCompile(
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        RecordQuerySpecification $specification,
        BusinessRecordAccessPlan $access,
    ): CompiledRecordQuery {
        $this->assertAccessResource($resolved, $access);
        $table = $this->recordTable($resolved);
        $alias = 'r0';
        /** @var list<mixed> $parameters */
        $parameters = [];
        /** @var list<string> $types */
        $types = [];
        $where = $this->scopePredicates($table, $alias, $scope, $parameters, $types);
        $this->lifecyclePredicates($table, $alias, $specification, $where);
        $where[] = $this->recordPolicy(
            $resolved,
            $table,
            $alias,
            $scope,
            $access,
            $parameters,
            $types,
        );
        $counter = 0;
        if ($specification->filter !== null) {
            $where[] = $this->filter(
                $resolved,
                $table,
                $alias,
                $scope,
                $access,
                FieldAccessUsage::Filter,
                $specification->filter,
                $parameters,
                $types,
                $counter,
            );
        }
        if ($specification->search !== null) {
            $search = [];
            foreach ($specification->search->fields as $handle) {
                $field = $this->field($resolved->definition, $handle);
                if (
                    !$field->searchable
                    || !$this->queryVisible($field)
                    || !$access->fields->allows(FieldAccessUsage::Search, $handle)
                ) {
                    throw new InvalidBusinessRecordQuery('A requested query field is unavailable.');
                }
                $columns = $this->fieldColumns($resolved->definition, $table, $field);
                if (
                    count($columns) !== 1
                    || !in_array($columns[0]->doctrineType, ['string', 'text', 'ascii_string'], true)
                ) {
                    throw new InvalidBusinessRecordQuery('Search requires a single textual physical field.');
                }
                $search[] = sprintf(
                    'LOWER(%s.%s) LIKE ? ESCAPE \'!\'',
                    $alias,
                    $this->quote($columns[0]->physicalName),
                );
                $parameters[] = '%' . $this->escapeLike(mb_strtolower($specification->search->term)) . '%';
                $types[] = Types::STRING;
            }
            $where[] = '(' . implode(' OR ', $search) . ')';
        }
        $aggregateWhere = $where;
        $aggregateParameters = $parameters;
        $aggregateTypes = $types;

        [$order, $cursorColumns] = $this->sorts($resolved, $table, $alias, $specification, $access);
        $cursorDigest = $this->cursorDigest($resolved, $scope, $specification, $access);
        if ($specification->after !== null) {
            $position = $this->cursors->decode($specification->after);
            if (!hash_equals($cursorDigest, $position->specificationDigest)) {
                throw new InvalidBusinessRecordQuery('The cursor belongs to a different query specification.');
            }
            $where[] = $this->cursorPredicate(
                $resolved,
                $table,
                $alias,
                $specification,
                $position->sortValues,
                $position->recordKey,
                $parameters,
                $types,
            );
        }

        $projection = $this->projection($resolved->definition, $table, $specification, $access);
        $select = $this->selectedPhysicalColumns($resolved->definition, $table, $projection);
        foreach ($cursorColumns as $cursorColumn) {
            $select[] = $cursorColumn['physical'];
        }
        $select = array_values(array_unique($select));
        $sql = sprintf(
            'SELECT %s FROM %s %s WHERE %s ORDER BY %s LIMIT %d',
            implode(', ', array_map(
                fn (string $physical): string => $alias . '.' . $this->quote($physical),
                $select,
            )),
            $this->quote($table->physicalName),
            $alias,
            implode(' AND ', $where),
            implode(', ', $order),
            $specification->pageSize + 1,
        );

        $aggregateSql = $this->aggregateSql(
            $resolved,
            $table,
            $alias,
            $specification->projection->aggregates,
            $aggregateWhere,
            $access,
        );

        return new CompiledRecordQuery(
            $sql,
            $cursorDigest,
            $parameters,
            $types,
            $projection,
            $cursorColumns,
            $aggregateSql,
            $aggregateParameters,
            $aggregateTypes,
        );
    }

    /**
     * Compile the equality predicates that confine a statement to one site and organization.
     *
     * The installed table decides which scope dimensions exist, and the two sides have to agree exactly:
     * a scope value with no column to bind against, or a scope column the request leaves empty, means the
     * definition's scope mode and the tables on disk have drifted apart, so the query is refused rather
     * than run across a boundary it cannot express. Bindings are appended to the caller's lists, which is
     * how one placeholder order is kept across the whole statement.
     *
     * @param   PhysicalTableBlueprint  $table       Installed table whose scope columns are consulted: a
     *          record table, a junction table or an owned-line table.
     * @param   string                  $alias       Alias the predicates qualify those columns with.
     * @param   RecordScope             $scope       Resolved site and organization to confine to.
     * @param   list<mixed>             $parameters  Bound values so far; the scope values are appended.
     * @param   list<string>            $types       Doctrine type names so far, appended in step with $parameters.
     *
     * @return  list<string>  One `alias.column = ?` predicate per scope dimension the table declares;
     *          empty when it declares none.
     *
     * @throws  InvalidBusinessRecordQuery  When the request carries a scope dimension the table has no
     *          column for, or the table declares one the request leaves null.
     *
     * @since   2.0.0
     */
    private function scopePredicates(
        PhysicalTableBlueprint $table,
        string $alias,
        RecordScope $scope,
        array &$parameters,
        array &$types,
    ): array {
        $where = [];
        foreach (
            [
            'site_identifier' => $scope->siteIdentifier,
            'organization_identifier' => $scope->organizationIdentifier,
            ] as $logical => $value
        ) {
            $column = $table->column($logical);
            if ($column === null) {
                if ($value !== null) {
                    throw new InvalidBusinessRecordQuery('The requested scope is absent from the installed schema.');
                }
                continue;
            }
            if ($value === null) {
                throw new InvalidBusinessRecordQuery('The installed schema requires a missing request scope.');
            }
            $where[] = $alias . '.' . $this->quote($column->physicalName) . ' = ?';
            $parameters[] = $value;
            $types[] = $column->doctrineType;
        }

        return $where;
    }

    /**
     * Append the archive and soft-delete exclusions, and guarantee the predicate list is not empty.
     *
     * Both exclusions are conditional on the installed table actually carrying the column, since a
     * definition may declare neither lifecycle. When nothing at all has been collected — no scope
     * dimension and no lifecycle column — a `1 = 1` is appended, so the caller can always emit a `WHERE`
     * and join what follows with `AND`.
     *
     * @param   PhysicalTableBlueprint    $table          Installed record table being queried.
     * @param   string                    $alias          Alias the predicates qualify columns with.
     * @param   RecordQuerySpecification  $specification  Page request, which decides whether archived and
     *          soft-deleted records are admitted.
     * @param   list<string>              $where          Predicates collected so far, scope predicates
     *          included; appended to in place.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function lifecyclePredicates(
        PhysicalTableBlueprint $table,
        string $alias,
        RecordQuerySpecification $specification,
        array &$where,
    ): void {
        if (!$specification->includeArchived && $table->column('archived_at') !== null) {
            $where[] = $alias . '.' . $this->quote($this->physical($table, 'archived_at')) . ' IS NULL';
        }
        if (!$specification->includeDeleted && $table->column('deleted_at') !== null) {
            $where[] = $alias . '.' . $this->quote($this->physical($table, 'deleted_at')) . ' IS NULL';
        }
        if ($where === []) {
            $where[] = '1 = 1';
        }
    }

    /**
     * Compile default-deny, deny-overrides access over one table alias.
     *
     * @param   ResolvedBusinessDefinition  $resolved    Definition whose policy fields are resolved.
     * @param   PhysicalTableBlueprint      $table       Installed table carrying those fields.
     * @param   string                      $alias       Trusted compiler-owned table alias.
     * @param   RecordScope                 $scope       Scope entity-reference literals are resolved within.
     * @param   BusinessRecordAccessPlan    $access      Validated access decision to compile.
     * @param   list<mixed>                 $parameters  Bindings collected so far, appended in place.
     * @param   list<string>                $types       Doctrine types collected in lockstep.
     *
     * @return  string  Definite-boolean SQL: one allow must hold and no deny may hold.
     *
     * @throws  InvalidBusinessRecordQuery  When a policy field or physical type is unavailable.
     *
     * @since   2.0.0
     */
    private function recordPolicy(
        ResolvedBusinessDefinition $resolved,
        PhysicalTableBlueprint $table,
        string $alias,
        RecordScope $scope,
        BusinessRecordAccessPlan $access,
        array &$parameters,
        array &$types,
    ): string {
        $policy = $access->records;
        if ($policy->allows === []) {
            return '(1 = 0)';
        }
        $allows = [];
        foreach ($policy->allows as $predicate) {
            $allows[] = $this->policyPredicate(
                $resolved,
                $table,
                $alias,
                $scope,
                $access,
                $predicate,
                $parameters,
                $types,
            );
        }
        $allow = '(' . implode(' OR ', $allows) . ')';
        if ($policy->denies === []) {
            return $allow;
        }
        $denies = [];
        foreach ($policy->denies as $predicate) {
            $denies[] = $this->policyPredicate(
                $resolved,
                $table,
                $alias,
                $scope,
                $access,
                $predicate,
                $parameters,
                $types,
            );
        }

        return '(' . $allow . ' AND NOT (' . implode(' OR ', $denies) . '))';
    }

    /**
     * Compile allow-first, deny-overrides policy against one immutable revision snapshot.
     *
     * @param   ResolvedBusinessDefinition  $resolved    Definition whose field schema is authoritative.
     * @param   PhysicalTableBlueprint      $table       Installed record table supplying exact field types.
     * @param   BusinessRecordAccessPlan    $access      Validated history access decision.
     * @param   list<mixed>                 $parameters  Bindings collected in predicate order.
     * @param   list<string>                $types       Doctrine binding types collected in lockstep.
     *
     * @return  string  Definite-boolean predicate targeting `rv0.snapshot`.
     *
     * @throws  InvalidBusinessRecordQuery  When a policy node cannot be represented over canonical JSON.
     *
     * @since   2.0.0
     */
    private function revisionRecordPolicy(
        ResolvedBusinessDefinition $resolved,
        PhysicalTableBlueprint $table,
        BusinessRecordAccessPlan $access,
        array &$parameters,
        array &$types,
    ): string {
        if ($access->records->allows === []) {
            return '(1 = 0)';
        }
        $allows = [];
        foreach ($access->records->allows as $predicate) {
            $allows[] = $this->revisionPolicyPredicate(
                $resolved,
                $table,
                $predicate,
                $parameters,
                $types,
            );
        }
        $allow = '(' . implode(' OR ', $allows) . ')';
        if ($access->records->denies === []) {
            return $allow;
        }
        $denies = [];
        foreach ($access->records->denies as $predicate) {
            $denies[] = $this->revisionPolicyPredicate(
                $resolved,
                $table,
                $predicate,
                $parameters,
                $types,
            );
        }

        return '(' . $allow . ' AND NOT (' . implode(' OR ', $denies) . '))';
    }

    /**
     * Compile one typed policy node over canonical revision JSON without coercing another JSON type.
     *
     * @param   ResolvedBusinessDefinition  $resolved    Definition whose field is addressed.
     * @param   PhysicalTableBlueprint      $table       Installed record table proving the field's type.
     * @param   RecordPolicyPredicate       $predicate   Closed policy node to compile.
     * @param   list<mixed>                 $parameters  Bindings collected so far, appended in place.
     * @param   list<string>                $types       Doctrine binding types collected in lockstep.
     *
     * @return  string  SQL predicate that is always true or false, never unknown.
     *
     * @throws  InvalidBusinessRecordQuery  When the node, field, type, or literal is not portable.
     *
     * @since   2.0.0
     */
    private function revisionPolicyPredicate(
        ResolvedBusinessDefinition $resolved,
        PhysicalTableBlueprint $table,
        RecordPolicyPredicate $predicate,
        array &$parameters,
        array &$types,
    ): string {
        if ($predicate instanceof RecordPolicyConstant) {
            return $predicate->value ? '(1 = 1)' : '(1 = 0)';
        }
        if ($predicate instanceof RecordPolicyBoolean) {
            $children = [];
            foreach ($predicate->children as $child) {
                $children[] = $this->revisionPolicyPredicate(
                    $resolved,
                    $table,
                    $child,
                    $parameters,
                    $types,
                );
            }

            return '(' . implode(
                $predicate->operator === RecordPolicyBooleanOperator::All ? ' AND ' : ' OR ',
                $children,
            ) . ')';
        }
        if ($predicate instanceof RecordPolicyNullCheck) {
            [$json, , $kind] = $this->revisionJsonField($predicate->field);
            $nullKind = $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform
                ? 'NULL'
                : 'null';
            $null = sprintf('(%s IS NULL OR %s = \'%s\')', $json, $kind, $nullKind);

            return $predicate->isNull
                ? '(CASE WHEN ' . $null . ' THEN 1 ELSE 0 END = 1)'
                : '(CASE WHEN ' . $null . ' THEN 0 ELSE 1 END = 1)';
        }
        if (!$predicate instanceof RecordPolicyComparison) {
            throw new InvalidBusinessRecordQuery('A revision record-policy predicate type is unsupported.');
        }
        [, $text, $kind] = $this->revisionJsonField($predicate->field);
        $field = $this->field($resolved->definition, $predicate->field);
        $columns = $this->fieldColumns($resolved->definition, $table, $field);
        if (count($columns) !== 1 || !$this->policyTypeMatches($predicate->valueType, $columns[0])) {
            throw new InvalidBusinessRecordQuery('A revision record-policy field type is unavailable.');
        }
        $operator = $this->policyOperator($predicate->operator);
        $mysql = $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        $expectedKind = match ($predicate->valueType) {
            RecordPolicyValueType::String,
            RecordPolicyValueType::Decimal,
            RecordPolicyValueType::Temporal => $mysql ? 'STRING' : 'string',
            RecordPolicyValueType::Integer => $mysql ? 'INTEGER' : 'number',
            RecordPolicyValueType::Boolean => $mysql ? 'BOOLEAN' : 'boolean',
        };
        $guard = sprintf('%s = \'%s\'', $kind, $expectedKind);
        $comparison = '';
        if ($predicate->valueType === RecordPolicyValueType::String) {
            $parameters[] = $predicate->value;
            $types[] = Types::STRING;
            $comparison = $this->revisionTextComparison($text, $operator);
        } elseif ($predicate->valueType === RecordPolicyValueType::Integer) {
            $parameters[] = $predicate->value;
            $types[] = Types::BIGINT;
            $guard .= ' AND ' . $this->revisionRegex($text, '^-?(0|[1-9][0-9]*)$');
            $cast = $mysql ? 'SIGNED' : 'BIGINT';
            $comparison = sprintf('CAST(%s AS %s) %s ?', $text, $cast, $operator);
        } elseif ($predicate->valueType === RecordPolicyValueType::Decimal) {
            [$precision, $scale] = $this->assertRevisionDecimalFits($columns[0], (string) $predicate->value);
            $parameters[] = $predicate->value;
            $types[] = Types::STRING;
            $guard .= ' AND ' . $this->revisionRegex(
                $text,
                '^-?(0|[1-9][0-9]*)([.][0-9]+)?$',
            );
            $cast = $mysql
                ? sprintf(
                    'DECIMAL(%d, %d)',
                    $precision,
                    $scale,
                )
                : 'NUMERIC';
            $comparison = sprintf('CAST(%s AS %s) %s CAST(? AS %s)', $text, $cast, $operator, $cast);
        } elseif ($predicate->valueType === RecordPolicyValueType::Boolean) {
            $parameters[] = $predicate->value === true ? 'true' : 'false';
            $types[] = Types::STRING;
            $comparison = $this->revisionTextComparison($text, $operator);
        } else {
            $expected = (string) $predicate->value;
            $normalized = match ($columns[0]->doctrineType) {
                'date_immutable' => sprintf('SUBSTR(%s, 1, 10)', $text),
                'time_immutable' => sprintf('SUBSTR(%s, 12, 15)', $text),
                'datetime_immutable', 'datetimetz_immutable' => sprintf(
                    'CONCAT(SUBSTR(%s, 1, 26), \'Z\')',
                    $text,
                ),
                default => throw new InvalidBusinessRecordQuery(
                    'A revision temporal policy field type is unavailable.',
                ),
            };
            if (
                $columns[0]->doctrineType === 'time_immutable'
                && preg_match('/^[0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $expected) === 1
            ) {
                $expected .= '.000000';
            }
            $parameters[] = $expected;
            $types[] = Types::STRING;
            $guard .= ' AND CHAR_LENGTH(' . $text . ') = 32 AND ' . $this->revisionRegex(
                $text,
                '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}'
                    . '[.][0-9]{6}[+-][0-9]{2}:[0-9]{2}$',
            );
            if (in_array($columns[0]->doctrineType, ['datetime_immutable', 'datetimetz_immutable'], true)) {
                $guard .= sprintf(' AND SUBSTR(%s, 27, 6) = \'+00:00\'', $text);
            }
            $comparison = $this->revisionTextComparison($normalized, $operator);
        }

        return sprintf('(CASE WHEN %s THEN CASE WHEN %s THEN 1 ELSE 0 END ELSE 0 END = 1)', $guard, $comparison);
    }

    /**
     * Return driver-specific expressions for one validated field in `rv0.snapshot`.
     *
     * @param   string  $field  Stable policy field handle from the closed AST.
     *
     * @return  array{string, string, string}  JSON value, unquoted text value, and JSON type expressions.
     *
     * @throws  InvalidBusinessRecordQuery  When the configured database has no supported JSON operators.
     *
     * @since   2.0.0
     */
    private function revisionJsonField(string $field): array
    {
        $snapshot = 'rv0.' . $this->quote('snapshot');
        $platform = $this->database->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $value = sprintf('JSON_EXTRACT(%s, \'$."%s"\')', $snapshot, $field);

            return [$value, 'JSON_UNQUOTE(' . $value . ')', 'JSON_TYPE(' . $value . ')'];
        }
        if ($platform instanceof PostgreSQLPlatform) {
            $value = sprintf('(CAST(%s AS JSONB) -> \'%s\')', $snapshot, $field);
            $text = sprintf('(CAST(%s AS JSONB) ->> \'%s\')', $snapshot, $field);

            return [$value, $text, 'jsonb_typeof(' . $value . ')'];
        }
        throw new InvalidBusinessRecordQuery(
            'The configured database cannot enforce policy over revision snapshots.',
        );
    }

    /**
     * Compile a byte-stable text comparison for the configured database platform.
     *
     * @param   string  $expression  Trusted compiler-produced textual SQL expression.
     * @param   string  $operator    Closed comparison operator.
     *
     * @return  string  Comparison against one bound string placeholder.
     *
     * @since   2.0.0
     */
    private function revisionTextComparison(string $expression, string $operator): string
    {
        if ($this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return sprintf('BINARY %s %s BINARY ?', $expression, $operator);
        }

        return sprintf("convert_to(%s, 'UTF8') %s convert_to(?, 'UTF8')", $expression, $operator);
    }

    /**
     * Compile a canonical-number shape guard without evaluating a cast first.
     *
     * @param   string  $expression  Trusted unquoted JSON scalar expression.
     * @param   string  $pattern     Compiler-owned regular expression without quotes.
     *
     * @return  string  Platform-specific regular-expression predicate.
     *
     * @since   2.0.0
     */
    private function revisionRegex(string $expression, string $pattern): string
    {
        return $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? sprintf('%s REGEXP \'%s\'', $expression, $pattern)
            : sprintf('%s ~ \'%s\'', $expression, $pattern);
    }

    /**
     * Refuse a decimal policy literal that the field's installed precision and scale cannot represent.
     *
     * @param   PhysicalColumnBlueprint  $column  Installed decimal column supplying precision and scale.
     * @param   string                   $value   Canonical decimal policy literal.
     *
     * @return  array{int, int}  Validated installed precision and scale for the SQL cast.
     *
     * @throws  InvalidBusinessRecordQuery  When casting the literal would round or overflow.
     *
     * @since   2.0.0
     */
    private function assertRevisionDecimalFits(PhysicalColumnBlueprint $column, string $value): array
    {
        $precision = $column->options['precision'] ?? null;
        $scale = $column->options['scale'] ?? null;
        if (!is_int($precision) || !is_int($scale)) {
            throw new InvalidBusinessRecordQuery('A revision decimal policy field has no installed bounds.');
        }
        [$integer, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
        $integer = ltrim($integer, '0');
        if ($integer === '') {
            $integer = '0';
        }
        $integerDigits = $integer === '0' ? 0 : strlen($integer);
        $significantFraction = rtrim($fraction, '0');
        if ($integerDigits > $precision - $scale || strlen($significantFraction) > $scale) {
            throw new InvalidBusinessRecordQuery('A revision decimal policy literal exceeds installed bounds.');
        }

        return [$precision, $scale];
    }

    /**
     * Translate a closed record-policy comparison operator to SQL.
     *
     * @param   RecordPolicyComparisonOperator  $operator  Portable policy operator.
     *
     * @return  string  Trusted SQL operator token.
     *
     * @since   2.0.0
     */
    private function policyOperator(RecordPolicyComparisonOperator $operator): string
    {
        return match ($operator) {
            RecordPolicyComparisonOperator::Equal => '=',
            RecordPolicyComparisonOperator::NotEqual => '<>',
            RecordPolicyComparisonOperator::LessThan => '<',
            RecordPolicyComparisonOperator::LessThanOrEqual => '<=',
            RecordPolicyComparisonOperator::GreaterThan => '>',
            RecordPolicyComparisonOperator::GreaterThanOrEqual => '>=',
        };
    }

    /**
     * Compile one closed policy node with two-valued null semantics matching `RecordPolicyEvaluator`.
     *
     * @param   ResolvedBusinessDefinition  $resolved    Definition whose field is addressed.
     * @param   PhysicalTableBlueprint      $table       Installed table carrying that field.
     * @param   string                      $alias       Trusted table alias.
     * @param   RecordScope                 $scope       Scope for entity-reference normalization.
     * @param   BusinessRecordAccessPlan    $access      Related-target access used by reference values.
     * @param   RecordPolicyPredicate       $predicate   Validated policy node.
     * @param   list<mixed>                 $parameters  Bindings collected so far, appended in place.
     * @param   list<string>                $types       Doctrine types collected in lockstep.
     *
     * @return  string  SQL predicate that is always true or false, never unknown.
     *
     * @throws  InvalidBusinessRecordQuery  When a field or operator cannot be represented portably.
     *
     * @since   2.0.0
     */
    private function policyPredicate(
        ResolvedBusinessDefinition $resolved,
        PhysicalTableBlueprint $table,
        string $alias,
        RecordScope $scope,
        BusinessRecordAccessPlan $access,
        RecordPolicyPredicate $predicate,
        array &$parameters,
        array &$types,
    ): string {
        if ($predicate instanceof RecordPolicyConstant) {
            return $predicate->value ? '(1 = 1)' : '(1 = 0)';
        }
        if ($predicate instanceof RecordPolicyBoolean) {
            $children = [];
            foreach ($predicate->children as $child) {
                $children[] = $this->policyPredicate(
                    $resolved,
                    $table,
                    $alias,
                    $scope,
                    $access,
                    $child,
                    $parameters,
                    $types,
                );
            }

            return '(' . implode(
                $predicate->operator === RecordPolicyBooleanOperator::All ? ' AND ' : ' OR ',
                $children,
            ) . ')';
        }
        if ($predicate instanceof RecordPolicyNullCheck) {
            $field = $this->field($resolved->definition, $predicate->field);
            $columns = $this->fieldColumns($resolved->definition, $table, $field);
            if (count($columns) !== 1) {
                throw new InvalidBusinessRecordQuery('A record-policy field requires one physical column.');
            }

            return sprintf(
                '(%s.%s IS %sNULL)',
                $alias,
                $this->quote($columns[0]->physicalName),
                $predicate->isNull ? '' : 'NOT ',
            );
        }
        if (!$predicate instanceof RecordPolicyComparison) {
            throw new InvalidBusinessRecordQuery('A record-policy predicate type is unsupported.');
        }
        $field = $this->field($resolved->definition, $predicate->field);
        $columns = $this->fieldColumns($resolved->definition, $table, $field);
        if (count($columns) !== 1 || !$this->policyTypeMatches($predicate->valueType, $columns[0])) {
            throw new InvalidBusinessRecordQuery('A record-policy field type is unavailable.');
        }
        $operator = $this->policyOperator($predicate->operator);
        $parameters[] = $predicate->valueType === RecordPolicyValueType::Temporal
            ? $this->values->cursorStorageValue($columns[0], $predicate->value)
            : $predicate->value;
        $types[] = $columns[0]->doctrineType;
        $qualified = $alias . '.' . $this->quote($columns[0]->physicalName);
        $placeholder = '?';
        if (
            $predicate->valueType === RecordPolicyValueType::String
            && $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform
        ) {
            $qualified = 'BINARY ' . $qualified;
            $placeholder = 'BINARY ?';
        }

        return sprintf(
            '(CASE WHEN %s %s %s THEN 1 ELSE 0 END = 1)',
            $qualified,
            $operator,
            $placeholder,
        );
    }

    /**
     * Prove a policy scalar matches the installed column without coercion.
     *
     * @param   RecordPolicyValueType    $type    Portable comparison domain declared by policy.
     * @param   PhysicalColumnBlueprint  $column  Installed column used by the predicate.
     *
     * @return  bool  True when evaluator and database comparisons share a scalar domain.
     *
     * @since   2.0.0
     */
    private function policyTypeMatches(
        RecordPolicyValueType $type,
        PhysicalColumnBlueprint $column,
    ): bool {
        return match ($type) {
            RecordPolicyValueType::String => in_array(
                $column->doctrineType,
                ['ascii_string', 'guid', 'string', 'text'],
                true,
            ),
            RecordPolicyValueType::Integer => in_array(
                $column->doctrineType,
                ['bigint', 'integer', 'smallint'],
                true,
            ),
            RecordPolicyValueType::Decimal => $column->doctrineType === 'decimal',
            RecordPolicyValueType::Boolean => $column->doctrineType === 'boolean',
            RecordPolicyValueType::Temporal => in_array(
                $column->doctrineType,
                ['date_immutable', 'datetime_immutable', 'datetimetz_immutable', 'time_immutable'],
                true,
            ),
        };
    }

    /**
     * Require an access plan to describe the definition being compiled.
     *
     * @param   ResolvedBusinessDefinition  $resolved  Definition whose query is being compiled.
     * @param   BusinessRecordAccessPlan    $access    Plan that must name that definition.
     *
     * @return  void
     *
     * @throws  InvalidBusinessRecordQuery  When a plan is replayed against another definition.
     *
     * @since   2.0.0
     */
    private function assertAccessResource(
        ResolvedBusinessDefinition $resolved,
        BusinessRecordAccessPlan $access,
    ): void {
        if (!hash_equals($resolved->definition->id, $access->resourceIdentifier)) {
            throw new InvalidBusinessRecordQuery('A business-record access plan belongs to another resource.');
        }
    }

    /**
     * Compile one node of a filter tree into a predicate, descending into everything below it.
     *
     * The node kinds are dispatched here and the shared counter is charged one operation for each,
     * capping a whole tree — relation hops and the filters nested inside them included — at sixty-four
     * compiled operations. That repeats a bound `RecordQuerySpecification` already enforces, because a
     * hop compiles the target definition's filter through this same method and so can multiply the work
     * one specification asked for. Negation is spelled through `notTrue()` rather than SQL `NOT`, so a
     * record whose column holds no value falls on the negative side instead of out of the result.
     *
     * @param   ResolvedBusinessDefinition  $resolved    Definition and installed schema the handles in
     *          this node resolve against; inside a relation hop that is the target's, not the browsed
     *          definition's.
     * @param   PhysicalTableBlueprint      $table       Installed table the node's columns live in.
     * @param   string                      $alias       Alias those columns are qualified with.
     * @param   RecordScope                 $scope       Scope entity references are resolved within and
     *          related rows are confined to.
     * @param   BusinessRecordAccessPlan    $access      Field and related-target query permissions.
     * @param   FieldAccessUsage            $usage       Direct-filter or relationship-selector permission.
     * @param   RecordFilter                $filter      Node to compile.
     * @param   list<mixed>                 $parameters  Bound values so far; this node's are appended.
     * @param   list<string>                $types       Doctrine type names so far, appended in step with $parameters.
     * @param   int                         $counter     Running count of compiled operations, shared by
     *          the whole tree and also used to number the aliases a relation hop introduces.
     *
     * @return  string  A predicate covering this node and everything below it, ready to join with `AND`.
     *
     * @throws  InvalidBusinessRecordQuery  When the tree exceeds sixty-four operations, a handle names no
     *          field or one the definition does not allow a filter to use, the field's storage cannot
     *          answer the operator portably, or the node is of a kind this compiler does not implement.
     *
     * @since   2.0.0
     */
    private function filter(
        ResolvedBusinessDefinition $resolved,
        PhysicalTableBlueprint $table,
        string $alias,
        RecordScope $scope,
        BusinessRecordAccessPlan $access,
        FieldAccessUsage $usage,
        RecordFilter $filter,
        array &$parameters,
        array &$types,
        int &$counter,
    ): string {
        ++$counter;
        if ($counter > 64) {
            throw new InvalidBusinessRecordQuery('A query exceeds 64 compiled filter operations.');
        }
        if ($filter instanceof ComparisonFilter) {
            return $this->comparison(
                $resolved,
                $table,
                $alias,
                $scope,
                $access,
                $usage,
                $filter,
                $parameters,
                $types,
            );
        }
        if ($filter instanceof SetFilter) {
            $field = $this->filterable($resolved->definition, $filter->field, $access, $usage);
            $columns = $this->fieldColumns($resolved->definition, $table, $field);
            if (count($columns) !== 1) {
                throw new InvalidBusinessRecordQuery('A set predicate requires a single physical field.');
            }
            if (!$this->equalityComparable($columns[0])) {
                throw new InvalidBusinessRecordQuery(
                    'A set predicate requires a portably equality-comparable scalar field.',
                );
            }
            $placeholders = [];
            foreach ($filter->values as $value) {
                $encoded = $this->queryColumns($resolved, $table, $field, $value, $scope, $access);
                $parameters[] = array_values($encoded)[0];
                $types[] = $columns[0]->doctrineType;
                $placeholders[] = '?';
            }
            $predicate = sprintf(
                '%s.%s IN (%s)',
                $alias,
                $this->quote($columns[0]->physicalName),
                implode(', ', $placeholders),
            );

            return $filter->negated ? $this->notTrue($predicate) : $predicate;
        }
        if ($filter instanceof NullFilter) {
            $field = $this->filterable($resolved->definition, $filter->field, $access, $usage);
            $columns = $this->fieldColumns($resolved->definition, $table, $field);
            $parts = array_map(
                fn (PhysicalColumnBlueprint $column): string => sprintf(
                    '%s.%s IS %sNULL',
                    $alias,
                    $this->quote($column->physicalName),
                    $filter->isNull ? '' : 'NOT ',
                ),
                $columns,
            );
            return '(' . implode(' AND ', $parts) . ')';
        }
        if ($filter instanceof TextFilter) {
            $field = $this->filterable($resolved->definition, $filter->field, $access, $usage);
            $columns = $this->fieldColumns($resolved->definition, $table, $field);
            if (
                count($columns) !== 1
                || !in_array($columns[0]->doctrineType, ['string', 'text', 'ascii_string'], true)
            ) {
                throw new InvalidBusinessRecordQuery('A text predicate requires a single textual field.');
            }
            $text = $this->escapeLike(mb_strtolower($filter->text));
            $parameters[] = match ($filter->operator) {
                TextOperator::Contains => '%' . $text . '%',
                TextOperator::StartsWith => $text . '%',
                TextOperator::EndsWith => '%' . $text,
            };
            $types[] = Types::STRING;
            return sprintf(
                'LOWER(%s.%s) LIKE ? ESCAPE \'!\'',
                $alias,
                $this->quote($columns[0]->physicalName),
            );
        }
        if ($filter instanceof BooleanFilter) {
            $parts = [];
            foreach ($filter->children as $child) {
                $parts[] = $this->filter(
                    $resolved,
                    $table,
                    $alias,
                    $scope,
                    $access,
                    $usage,
                    $child,
                    $parameters,
                    $types,
                    $counter,
                );
            }
            if ($filter->operator === BooleanOperator::Not) {
                return '(' . $this->notTrue($parts[0]) . ')';
            }
            return '(' . implode($filter->operator === BooleanOperator::All ? ' AND ' : ' OR ', $parts) . ')';
        }
        if ($filter instanceof RelationFilter) {
            return $this->relation(
                $resolved,
                $table,
                $alias,
                $scope,
                $access,
                $filter,
                $parameters,
                $types,
                $counter,
            );
        }

        throw new InvalidBusinessRecordQuery('A business-record filter node is unsupported.');
    }

    /**
     * Compile a single field-against-value comparison across every column that field occupies.
     *
     * A composite field is compared column by column: equality joins the parts with `AND` and inequality
     * with `OR`, which is why an ordering comparison is admitted over one column only. Inequality is
     * emitted as the negation of the equality test through `notTrue()`, so a record holding no value
     * counts as different rather than dropping out of the result.
     *
     * @param   ResolvedBusinessDefinition  $resolved    Definition and installed schema the field handle
     *          resolves against.
     * @param   PhysicalTableBlueprint      $table       Installed table the field's columns live in.
     * @param   string                      $alias       Alias those columns are qualified with.
     * @param   RecordScope                 $scope       Scope an entity-reference value is resolved
     *          within.
     * @param   BusinessRecordAccessPlan    $access      Field and related-target query permissions.
     * @param   FieldAccessUsage            $usage       Direct-filter or relationship-selector permission.
     * @param   ComparisonFilter            $filter      Field, operator and literal to compare.
     * @param   list<mixed>                 $parameters  Bound values so far; the encoded literal is
     *          appended once per column.
     * @param   list<string>                $types       Doctrine type names so far, appended in step with $parameters.
     *
     * @return  string  A parenthesized predicate over every column the field occupies.
     *
     * @throws  InvalidBusinessRecordQuery  When the field is not one a filter may name, an ordering
     *          comparison is asked of a composite field, or a column's type carries no portable meaning
     *          under the operator.
     *
     * @since   2.0.0
     */
    private function comparison(
        ResolvedBusinessDefinition $resolved,
        PhysicalTableBlueprint $table,
        string $alias,
        RecordScope $scope,
        BusinessRecordAccessPlan $access,
        FieldAccessUsage $usage,
        ComparisonFilter $filter,
        array &$parameters,
        array &$types,
    ): string {
        $field = $this->filterable($resolved->definition, $filter->field, $access, $usage);
        $columns = $this->fieldColumns($resolved->definition, $table, $field);
        $encoded = $this->queryColumns($resolved, $table, $field, $filter->value, $scope, $access);
        $operator = match ($filter->operator) {
            ComparisonOperator::Equal => '=',
            ComparisonOperator::NotEqual => '<>',
            ComparisonOperator::LessThan => '<',
            ComparisonOperator::LessThanOrEqual => '<=',
            ComparisonOperator::GreaterThan => '>',
            ComparisonOperator::GreaterThanOrEqual => '>=',
        };
        if (
            count($columns) > 1 && !in_array(
                $filter->operator,
                [ComparisonOperator::Equal, ComparisonOperator::NotEqual],
                true,
            )
        ) {
            throw new InvalidBusinessRecordQuery('Ordered comparison is ambiguous for a composite field.');
        }
        $comparable = in_array($filter->operator, [ComparisonOperator::Equal, ComparisonOperator::NotEqual], true)
            ? $this->allEqualityComparable($columns)
            : count($columns) === 1 && $this->orderedComparable($columns[0]);
        if (!$comparable) {
            throw new InvalidBusinessRecordQuery(
                'A comparison requires physical fields with portable operator semantics.',
            );
        }
        $parts = [];
        foreach ($columns as $column) {
            $predicate = sprintf('%s.%s %s ?', $alias, $this->quote($column->physicalName), $operator);
            $parts[] = $filter->operator === ComparisonOperator::NotEqual
                ? $this->notTrue(sprintf('%s.%s = ?', $alias, $this->quote($column->physicalName)))
                : $predicate;
            $parameters[] = $encoded[$column->physicalName];
            $types[] = $column->doctrineType;
        }
        $join = $filter->operator === ComparisonOperator::NotEqual ? ' OR ' : ' AND ';

        return '(' . implode($join, $parts) . ')';
    }

    /**
     * Report whether a column's storage type answers equality the same way on every supported engine.
     *
     * @param   PhysicalColumnBlueprint  $column  Installed column whose Doctrine type is examined.
     *
     * @return  bool  True for the scalar types listed here; false for binary, blob and JSON storage,
     *          whose equality is engine-defined.
     *
     * @since   2.0.0
     */
    private function equalityComparable(PhysicalColumnBlueprint $column): bool
    {
        return in_array($column->doctrineType, [
            'ascii_string', 'bigint', 'boolean', 'date_immutable', 'datetime_immutable', 'datetimetz_immutable',
            'decimal', 'guid', 'integer', 'smallint', 'string', 'text', 'time_immutable',
        ], true);
    }

    /**
     * Report whether every column of a composite field can be compared for equality.
     *
     * @param   list<PhysicalColumnBlueprint>  $columns  Columns backing one definition field.
     *
     * @return  bool  True only when the list is non-empty and each column qualifies, so a field with no
     *          installed storage is never treated as comparable.
     *
     * @since   2.0.0
     */
    private function allEqualityComparable(array $columns): bool
    {
        if ($columns === []) {
            return false;
        }
        foreach ($columns as $column) {
            if (!$this->equalityComparable($column)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Report whether a column's storage type orders the same way on every supported engine.
     *
     * Narrower than equality comparison: `boolean` and `guid` are absent, because neither carries an
     * ordering a caller could rely on, and both a range filter and a keyset page depend on the ordering
     * being reproducible.
     *
     * @param   PhysicalColumnBlueprint  $column  Installed column whose Doctrine type is examined.
     *
     * @return  bool  True when `<` and `>` over the column mean the same thing on every engine.
     *
     * @since   2.0.0
     */
    private function orderedComparable(PhysicalColumnBlueprint $column): bool
    {
        return in_array($column->doctrineType, [
            'ascii_string', 'bigint', 'date_immutable', 'datetime_immutable', 'datetimetz_immutable',
            'decimal', 'integer', 'smallint', 'string', 'text', 'time_immutable',
        ], true);
    }

    /**
     * Compile a relationship hop into a correlated `EXISTS` over the related table.
     *
     * Which storage the installed schema actually provides decides the correlation: an owned-line table
     * keyed by its owner, a target column on the source row, a junction table, or — when the source side
     * carries no storage at all — the canonical inverse declared on the target, whose own column or
     * junction is used instead. Related rows are then narrowed the way the outer query is: soft-deleted
     * rows are excluded, and the scope is bound except under owned lines, which are already reached
     * through their owner. `None` and `All` are expressed as the negation of an existence test rather
     * than with SQL `NOT`, so a related row whose predicate is unknown counts against `All`.
     *
     * @param   ResolvedBusinessDefinition  $source       Definition and installed schema the hop starts
     *          from.
     * @param   PhysicalTableBlueprint      $sourceTable  Installed table of the records being filtered.
     * @param   string                      $sourceAlias  Alias the subquery correlates back to.
     * @param   RecordScope                 $scope        Scope the junction and the related rows are
     *          confined to.
     * @param   BusinessRecordAccessPlan    $access       Source and target relationship decision.
     * @param   RelationFilter              $filter       Relationship to traverse, the quantifier to
     *          apply, and the filter the related records are measured against.
     * @param   list<mixed>                 $parameters   Bound values so far; the hop's are appended.
     * @param   list<string>                $types        Doctrine type names so far, appended in step with $parameters.
     * @param   int                         $counter      Running operation count, also incremented here to
     *          number the target and junction aliases this hop introduces.
     *
     * @return  string  An `EXISTS` predicate, or its negation, correlated to the outer row.
     *
     * @throws  InvalidBusinessRecordQuery  When the handle names no relationship, the target definition or
     *          its schema is unavailable, the installed schema offers no storage for the association in
     *          either direction, or the nested filter is itself refused.
     *
     * @since   2.0.0
     */
    private function relation(
        ResolvedBusinessDefinition $source,
        PhysicalTableBlueprint $sourceTable,
        string $sourceAlias,
        RecordScope $scope,
        BusinessRecordAccessPlan $access,
        RelationFilter $filter,
        array &$parameters,
        array &$types,
        int &$counter,
    ): string {
        $relationship = $this->relationship($source->definition, $filter->relationship);
        $targetAccess = $access->related($relationship->handle);
        if ($targetAccess === null) {
            throw new InvalidBusinessRecordQuery('A requested relationship is unavailable.');
        }
        $target = $this->target($source->definition, $relationship);
        $this->assertAccessResource($target, $targetAccess);
        $number = ++$counter;
        $targetAlias = 'r' . $number;
        if ($relationship->kind === RelationshipKind::OwnedLineCollection) {
            $targetTable = $source->installation->blueprint->table('line:' . $relationship->handle)
                ?? throw new InvalidBusinessRecordQuery('The requested owned-line table is unavailable.');
            $join = sprintf(
                '%s.%s = %s.%s',
                $targetAlias,
                $this->quote($this->physical($targetTable, 'owner_id')),
                $sourceAlias,
                $this->quote($this->physical($sourceTable, 'record_id')),
            );
        } else {
            $targetTable = $this->recordTable($target);
            $direct = $sourceTable->column('relation:' . $relationship->handle . '.target_id');
            $junction = $source->installation->blueprint->table('relation:' . $relationship->handle);
            if ($direct !== null) {
                $join = sprintf(
                    '%s.%s = %s.%s',
                    $targetAlias,
                    $this->quote($this->physical($targetTable, 'record_id')),
                    $sourceAlias,
                    $this->quote($direct->physicalName),
                );
            } elseif ($junction !== null) {
                $junctionAlias = 'j' . $number;
                $junctionWhere = [sprintf(
                    '%s.%s = %s.%s',
                    $junctionAlias,
                    $this->quote($this->physical($junction, 'source_id')),
                    $sourceAlias,
                    $this->quote($this->physical($sourceTable, 'record_id')),
                ), sprintf(
                    '%s.%s = %s.%s',
                    $junctionAlias,
                    $this->quote($this->physical($junction, 'target_id')),
                    $targetAlias,
                    $this->quote($this->physical($targetTable, 'record_id')),
                ), ...$this->scopePredicates($junction, $junctionAlias, $scope, $parameters, $types)];
                $join = sprintf(
                    'EXISTS (SELECT 1 FROM %s %s WHERE %s)',
                    $this->quote($junction->physicalName),
                    $junctionAlias,
                    implode(' AND ', $junctionWhere),
                );
            } else {
                $inverse = $this->inverseRelationship($source->definition, $target->definition, $relationship);
                $inverseDirect = $targetTable->column('relation:' . $inverse->handle . '.target_id');
                if ($inverseDirect !== null) {
                    $join = sprintf(
                        '%s.%s = %s.%s',
                        $targetAlias,
                        $this->quote($inverseDirect->physicalName),
                        $sourceAlias,
                        $this->quote($this->physical($sourceTable, 'record_id')),
                    );
                } else {
                    $junction = $target->installation->blueprint->table('relation:' . $inverse->handle)
                        ?? throw new InvalidBusinessRecordQuery('The canonical inverse junction is unavailable.');
                    $junctionAlias = 'j' . $number;
                    $junctionWhere = [sprintf(
                        '%s.%s = %s.%s',
                        $junctionAlias,
                        $this->quote($this->physical($junction, 'source_id')),
                        $targetAlias,
                        $this->quote($this->physical($targetTable, 'record_id')),
                    ), sprintf(
                        '%s.%s = %s.%s',
                        $junctionAlias,
                        $this->quote($this->physical($junction, 'target_id')),
                        $sourceAlias,
                        $this->quote($this->physical($sourceTable, 'record_id')),
                    ), ...$this->scopePredicates($junction, $junctionAlias, $scope, $parameters, $types)];
                    $join = sprintf(
                        'EXISTS (SELECT 1 FROM %s %s WHERE %s)',
                        $this->quote($junction->physicalName),
                        $junctionAlias,
                        implode(' AND ', $junctionWhere),
                    );
                }
            }
        }

        $targetWhere = [$join];
        if ($targetTable->column('deleted_at') !== null) {
            $targetWhere[] = $targetAlias . '.'
                . $this->quote($this->physical($targetTable, 'deleted_at')) . ' IS NULL';
        }
        if ($relationship->kind !== RelationshipKind::OwnedLineCollection) {
            array_push(
                $targetWhere,
                ...$this->scopePredicates($targetTable, $targetAlias, $scope, $parameters, $types),
            );
        }
        $targetWhere[] = $this->recordPolicy(
            $target,
            $targetTable,
            $targetAlias,
            $scope,
            $targetAccess,
            $parameters,
            $types,
        );
        $targetPredicate = $this->filter(
            $target,
            $targetTable,
            $targetAlias,
            $scope,
            $targetAccess,
            FieldAccessUsage::Relation,
            $filter->target,
            $parameters,
            $types,
            $counter,
        );
        $exists = fn (string $predicate): string => sprintf(
            'EXISTS (SELECT 1 FROM %s %s WHERE %s)',
            $this->quote($targetTable->physicalName),
            $targetAlias,
            implode(' AND ', [...$targetWhere, $predicate]),
        );
        if ($filter->quantifier === RelationQuantifier::None) {
            return 'NOT (' . $exists($targetPredicate) . ')';
        }
        if ($filter->quantifier === RelationQuantifier::All) {
            return 'NOT (' . $exists($this->notTrue($targetPredicate)) . ')';
        }

        return $exists($targetPredicate);
    }

    /**
     * Wrap a predicate so it holds exactly when that predicate does not, unknown outcomes included.
     *
     * SQL `NOT` leaves an unknown comparison unknown and the row is dropped, which is the wrong answer
     * for a negative filter over a column holding no value. The `CASE` emitted here maps both false and
     * unknown to a match, and every negation the compiler produces — inequality, a negated set, and the
     * `None` and `All` relation quantifiers — is spelled through it.
     *
     * @param   string  $predicate  Predicate to invert; it is parenthesized as part of the wrapping.
     *
     * @return  string  A comparison usable wherever a predicate is, true when $predicate is not true.
     *
     * @since   2.0.0
     */
    private function notTrue(string $predicate): string
    {
        return 'CASE WHEN (' . $predicate . ') THEN 0 ELSE 1 END = 1';
    }

    /**
     * Compile the requested ordering into `ORDER BY` terms and the columns a cursor is read from.
     *
     * Null placement is emitted as an explicit rank expression ahead of the column itself, so the order
     * does not depend on where an engine puts empty values, and the record identity is always appended
     * last, which makes the ordering total and therefore safe to page on. A specification that declares
     * no sort orders by last update, newest first, and its cursor is read from that column.
     *
     * @param   ResolvedBusinessDefinition  $resolved       Definition and installed schema the sort
     *          handles resolve against.
     * @param   PhysicalTableBlueprint      $table          Installed record table being ordered.
     * @param   string                      $alias          Alias the ordering qualifies columns with.
     * @param   RecordQuerySpecification    $specification  Page request carrying the ordering keys.
     * @param   BusinessRecordAccessPlan    $access         Dynamic sort-field permissions.
     *
     * @return  array{list<string>, list<array{field: ?string, physical: string}>}  The `ORDER BY` terms in
     *          order, and the columns the next cursor is built from, whose `field` is null for the default
     *          last-updated ordering.
     *
     * @throws  InvalidBusinessRecordQuery  When a sort names no field, names one the definition does not
     *          allow to be sorted or shown, spans more than one column, or reads a column whose type has
     *          no portable keyset ordering.
     *
     * @since   2.0.0
     */
    private function sorts(
        ResolvedBusinessDefinition $resolved,
        PhysicalTableBlueprint $table,
        string $alias,
        RecordQuerySpecification $specification,
        BusinessRecordAccessPlan $access,
    ): array {
        $order = [];
        $cursor = [];
        if ($specification->sorts === []) {
            $updated = $this->physical($table, 'updated_at');
            $order[] = $alias . '.' . $this->quote($updated) . ' DESC';
            $cursor[] = ['field' => null, 'physical' => $updated];
        } else {
            foreach ($specification->sorts as $sort) {
                $field = $this->field($resolved->definition, $sort->field);
                if (
                    !$field->sortable
                    || !$this->queryVisible($field)
                    || !$access->fields->allows(FieldAccessUsage::Sort, $field->handle)
                ) {
                    throw new InvalidBusinessRecordQuery('A requested query field is unavailable.');
                }
                $columns = $this->fieldColumns($resolved->definition, $table, $field);
                if (count($columns) !== 1) {
                    throw new InvalidBusinessRecordQuery('Sorting a composite field is ambiguous.');
                }
                if (in_array($columns[0]->doctrineType, ['binary', 'blob', 'json', 'text'], true)) {
                    throw new InvalidBusinessRecordQuery(
                        'Sorting requires a scalar physical field with portable keyset semantics.',
                    );
                }
                $physical = $columns[0]->physicalName;
                $qualified = $alias . '.' . $this->quote($physical);
                $nullRank = $sort->nullsLast ? '1' : '0';
                $nonNullRank = $sort->nullsLast ? '0' : '1';
                $order[] = sprintf('CASE WHEN %s IS NULL THEN %s ELSE %s END ASC', $qualified, $nullRank, $nonNullRank);
                $order[] = $qualified . ' ' . strtoupper($sort->direction->value);
                $cursor[] = ['field' => $field->handle, 'physical' => $physical];
            }
        }
        $order[] = $alias . '.' . $this->quote($this->physical($table, 'record_id')) . ' ASC';

        return [$order, $cursor];
    }

    /**
     * Compile the keyset seek that resumes a browse immediately after the row a cursor names.
     *
     * The result is a disjunction: one branch per ordering key, requiring the keys before it to be equal
     * and that key to lie beyond the cursor's value, plus a final branch matching every key exactly and a
     * greater record identity. Keys holding no value are compared with `IS NULL`, and a nulls-last key
     * that held none contributes no branch of its own, so no row is repeated or skipped where the valued
     * rows meet the empty ones. Bindings are appended as the branches are emitted, which is why the
     * caller takes its aggregate snapshot before calling this.
     *
     * @param   ResolvedBusinessDefinition  $resolved       Definition and installed schema the sort
     *          handles resolve against.
     * @param   PhysicalTableBlueprint      $table          Installed record table being paged.
     * @param   string                      $alias          Alias the seek qualifies columns with.
     * @param   RecordQuerySpecification    $specification  Page request whose ordering the seek has to
     *          reproduce.
     * @param   list<mixed>                 $cursorValues   Ordering values of the last row of the
     *          previous page, one per declared sort, or a single `updated_at` timestamp when the query
     *          declares none.
     * @param   string                      $recordKey      Identity of that row, which breaks ties between
     *          rows whose ordering values are equal.
     * @param   list<mixed>                 $parameters     Bound values so far; the seek's are appended.
     * @param   list<string>                $types          Doctrine type names so far, appended in step.
     *
     * @return  string  A parenthesized disjunction admitting only rows after the cursor position.
     *
     * @throws  InvalidBusinessRecordQuery  When the cursor carries a different number of ordering values
     *          than the query declares, or its default timestamp is not a string.
     * @throws  InvalidArgumentException  When a cursor value cannot be restored to the storage form of its
     *          column, which `compile()` reports as a refused query.
     *
     * @since   2.0.0
     */
    private function cursorPredicate(
        ResolvedBusinessDefinition $resolved,
        PhysicalTableBlueprint $table,
        string $alias,
        RecordQuerySpecification $specification,
        array $cursorValues,
        string $recordKey,
        array &$parameters,
        array &$types,
    ): string {
        $sorts = $specification->sorts;
        if (count($cursorValues) !== max(1, count($sorts))) {
            throw new InvalidBusinessRecordQuery('The cursor sort-value count does not match the query.');
        }
        $keys = [];
        if ($sorts === []) {
            $value = $cursorValues[0];
            if (!is_string($value)) {
                throw new InvalidBusinessRecordQuery('The default cursor timestamp is invalid.');
            }
            $keys[] = [
                'column' => $this->physical($table, 'updated_at'),
                'type' => Types::DATETIME_IMMUTABLE,
                'value' => new DateTimeImmutable($value, new DateTimeZone('UTC')),
                'direction' => SortDirection::Descending,
                'nulls_last' => true,
            ];
        } else {
            foreach ($sorts as $index => $sort) {
                $field = $this->field($resolved->definition, $sort->field);
                $columns = $this->fieldColumns($resolved->definition, $table, $field);
                $encoded = $this->values->cursorStorageValue($columns[0], $cursorValues[$index]);
                $keys[] = [
                    'column' => $columns[0]->physicalName,
                    'type' => $columns[0]->doctrineType,
                    'value' => $encoded,
                    'direction' => $sort->direction,
                    'nulls_last' => $sort->nullsLast,
                ];
            }
        }
        $parts = [];
        foreach ($keys as $index => $key) {
            $branch = [];
            for ($prefixIndex = 0; $prefixIndex < $index; ++$prefixIndex) {
                $prefixKey = $keys[$prefixIndex];
                $prefixColumn = $alias . '.' . $this->quote($prefixKey['column']);
                if ($prefixKey['value'] === null) {
                    $branch[] = $prefixColumn . ' IS NULL';
                } else {
                    $branch[] = $prefixColumn . ' = ?';
                    $parameters[] = $prefixKey['value'];
                    $types[] = $prefixKey['type'];
                }
            }
            $qualified = $alias . '.' . $this->quote($key['column']);
            $seek = $this->seek(
                $qualified,
                $key['value'],
                $key['direction'],
                $key['nulls_last'],
                $parameters,
                $types,
                $key['type'],
            );
            if ($seek !== null) {
                $branch[] = $seek;
                $parts[] = '(' . implode(' AND ', $branch) . ')';
            }
        }
        $tie = [];
        foreach ($keys as $key) {
            $qualified = $alias . '.' . $this->quote($key['column']);
            if ($key['value'] === null) {
                $tie[] = $qualified . ' IS NULL';
            } else {
                $tie[] = $qualified . ' = ?';
                $parameters[] = $key['value'];
                $types[] = $key['type'];
            }
        }
        $tie[] = $alias . '.' . $this->quote($this->physical($table, 'record_id')) . ' > ?';
        $parameters[] = $recordKey;
        $types[] = $this->type($table, 'record_id');
        $parts[] = '(' . implode(' AND ', $tie) . ')';

        return '(' . implode(' OR ', $parts) . ')';
    }

    /**
     * Compile the comparison that steps one ordering key past the value the cursor recorded.
     *
     * A key that held no value bounds nothing when empty values rank last — nothing is ordered after the
     * last of them — so no comparison is produced and the caller drops that branch; when they rank first,
     * every row holding a value still lies ahead. A key that did hold a value keeps the empty rows
     * reachable when they rank last, which is what stops the page from ending at the first of them.
     *
     * @param   string         $column      Alias-qualified, quoted column this ordering key reads.
     * @param   mixed          $value       Value the cursor recorded for the key, already in the column's
     *          storage form, or null when that row held none.
     * @param   SortDirection  $direction   Direction the key is ordered in, which decides whether the seek
     *          looks for greater or for smaller values.
     * @param   bool           $nullsLast   Where empty values rank in this key's ordering.
     * @param   list<mixed>    $parameters  Bound values so far; the seek value is appended when one is bound.
     * @param   list<string>   $types       Doctrine type names so far, appended in step with $parameters.
     * @param   string         $type        Doctrine type the seek value is bound with.
     *
     * @return  ?string  The comparison, or null when an empty nulls-last key leaves nothing after it.
     *
     * @since   2.0.0
     */
    private function seek(
        string $column,
        mixed $value,
        SortDirection $direction,
        bool $nullsLast,
        array &$parameters,
        array &$types,
        string $type,
    ): ?string {
        if ($value === null) {
            return $nullsLast ? null : $column . ' IS NOT NULL';
        }
        $comparison = $column . ($direction === SortDirection::Ascending ? ' > ?' : ' < ?');
        $parameters[] = $value;
        $types[] = $type;

        return $nullsLast ? '(' . $column . ' IS NULL OR ' . $comparison . ')' : $comparison;
    }

    /**
     * Settle which field handles the page returns, expanding defaults and expression dependencies.
     *
     * An empty projection stands for every field the definition marks readable. A formula field then
     * pulls the fields it reads in behind it, and because the loop re-measures the list as it grows, a
     * dependency of a dependency is picked up as well. Formula inputs have to be present for computation,
     * and visibility inputs have to be present for fail-closed disclosure even when the caller did not request
     * those internal fields. Requested includes are resolved only to prove the relationship exists — related
     * records are fetched separately, not by this statement.
     *
     * @param   EntityTypeDefinition      $definition     Definition the handles resolve against.
     * @param   PhysicalTableBlueprint    $table          Installed record table of that definition; the
     *          projection is settled from the definition alone, so it is not consulted here.
     * @param   RecordQuerySpecification  $specification  Page request carrying the requested fields and
     *          includes.
     * @param   BusinessRecordAccessPlan  $access         Explicit list-field and relation disclosure.
     *
     * @return  list<string>  Field handles to read, each named once and in request order, with internal
     *          formula and visibility dependencies appended after it.
     *
     * @throws  InvalidBusinessRecordQuery  When a requested handle names no field, names one the
     *          definition does not expose to readers, or an include names no relationship.
     *
     * @since   2.0.0
     */
    private function projection(
        EntityTypeDefinition $definition,
        PhysicalTableBlueprint $table,
        RecordQuerySpecification $specification,
        BusinessRecordAccessPlan $access,
    ): array {
        $projection = $specification->projection->fields;
        $usage = $this->collectionUsage($access);
        if ($projection === []) {
            $projection = $access->fields->fields($usage);
        }
        foreach ($projection as $handle) {
            $field = $this->field($definition, $handle);
            if (!$field->readVisible || !$access->fields->allows($usage, $handle)) {
                throw new InvalidBusinessRecordQuery('A requested projection field is unavailable.');
            }
        }
        for ($index = 0; $index < count($projection); ++$index) {
            $handle = $projection[$index];
            $field = $this->field($definition, $handle);
            $dependencies = $field->formula?->dependencies() ?? [];
            array_push($dependencies, ...($field->visibilityCondition?->dependencies() ?? []));
            foreach ($dependencies as $dependency) {
                if (!in_array($dependency, $projection, true)) {
                    $projection[] = $dependency;
                }
            }
        }
        foreach ($specification->projection->includes as $relationship) {
            $this->relationship($definition, $relationship);
            if ($access->related($relationship) === null) {
                throw new InvalidBusinessRecordQuery('A requested relationship is unavailable.');
            }
        }

        return array_values(array_unique($projection));
    }

    /**
     * Select the exact field-disclosure use for this collection operation.
     *
     * @param   BusinessRecordAccessPlan  $access  Operation-specific access decision.
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
     * Resolve projected handles to the physical columns the `SELECT` names, control columns included.
     *
     * The identity, version, scope, workflow and audit columns are selected whether or not the caller
     * asked for them, because the reader needs them to pin a row to the definition version it was written
     * under and to describe its lifecycle; one the installed table does not carry is simply left out.
     * Ordered-line fields and virtual computed fields are skipped, since neither keeps anything on this
     * table to read.
     *
     * @param   EntityTypeDefinition    $definition  Definition the projected handles resolve against.
     * @param   PhysicalTableBlueprint  $table       Installed record table whose columns are selected.
     * @param   list<string>            $projection  Field handles the projection settled on.
     *
     * @return  list<string>  Physical column names, control columns first and each named once.
     *
     * @throws  InvalidBusinessRecordQuery  When a projected handle names no field, or names one the
     *          installed table has no column for.
     *
     * @since   2.0.0
     */
    private function selectedPhysicalColumns(
        EntityTypeDefinition $definition,
        PhysicalTableBlueprint $table,
        array $projection,
    ): array {
        $logicalControls = [
            'record_id', 'definition_version', 'site_identifier', 'organization_identifier', 'version',
            'workflow_state', 'created_by', 'created_at', 'updated_by', 'updated_at', 'archived_by',
            'archived_at', 'deleted_by', 'deleted_at',
        ];
        $physical = [];
        foreach ($logicalControls as $logical) {
            $column = $table->column($logical);
            if ($column !== null) {
                $physical[] = $column->physicalName;
            }
        }
        if ($definition->identityStrategy === IdentityStrategy::Reference) {
            foreach ($this->fieldColumns($definition, $table, $this->identityField($definition)) as $column) {
                $physical[] = $column->physicalName;
            }
        }
        foreach ($projection as $handle) {
            $field = $this->field($definition, $handle);
            if (
                $field->type === 'core.ordered_lines'
                || ($field->computed && $field->computationMode === ComputationMode::Virtual)
            ) {
                continue;
            }
            foreach ($this->fieldColumns($definition, $table, $field) as $column) {
                $physical[] = $column->physicalName;
            }
        }

        return array_values(array_unique($physical));
    }

    /**
     * Compile the requested aggregates into a second statement over the same predicates.
     *
     * The statement reuses the page's predicates without its ordering, page bound or cursor seek, so a
     * total describes every record the query matches rather than the page in hand. Each function is held
     * to storage it can answer exactly: a sum or average only over exact numeric columns, so no float
     * ever enters a total, and a minimum or maximum only over a column that orders portably.
     *
     * @param   ResolvedBusinessDefinition  $resolved    Definition and installed schema the aggregate
     *          fields resolve against.
     * @param   PhysicalTableBlueprint      $table       Installed record table being summarized.
     * @param   string                      $alias       Alias the aggregate expressions qualify columns
     *          with, matching the one the reused predicates were compiled under.
     * @param   list<RecordAggregate>       $aggregates  Summaries the projection asked for.
     * @param   list<string>                $where       Predicates compiled for the page, taken before the
     *          cursor seek was appended to them.
     * @param   BusinessRecordAccessPlan    $access      Dynamic aggregate-field permissions.
     *
     * @return  ?string  The aggregate statement, or null when the projection requested no aggregate.
     *
     * @throws  InvalidBusinessRecordQuery  When an aggregate field is not reportable or not visible to
     *          queries, spans more than one column, or has a storage type the requested function cannot
     *          answer exactly.
     *
     * @since   2.0.0
     */
    private function aggregateSql(
        ResolvedBusinessDefinition $resolved,
        PhysicalTableBlueprint $table,
        string $alias,
        array $aggregates,
        array $where,
        BusinessRecordAccessPlan $access,
    ): ?string {
        if ($aggregates === []) {
            return null;
        }
        /** @var list<string> $select */
        $select = [];
        foreach ($aggregates as $aggregate) {
            if ($aggregate->function === AggregateFunction::Count) {
                $select[] = 'COUNT(*) AS ' . $this->quote($aggregate->alias);
                continue;
            }
            $field = $this->field($resolved->definition, (string) $aggregate->field);
            if (
                !$field->reportable
                || !$this->queryVisible($field)
                || !$access->fields->allows(FieldAccessUsage::Aggregate, $field->handle)
            ) {
                throw new InvalidBusinessRecordQuery('A requested aggregate field is unavailable.');
            }
            $columns = $this->fieldColumns($resolved->definition, $table, $field);
            if (count($columns) !== 1) {
                throw new InvalidBusinessRecordQuery('An aggregate requires one physical field.');
            }
            if (
                in_array($aggregate->function, [AggregateFunction::Sum, AggregateFunction::Average], true)
                && !in_array($columns[0]->doctrineType, ['integer', 'smallint', 'bigint', 'decimal'], true)
            ) {
                throw new InvalidBusinessRecordQuery('Sum and average require an exact numeric field.');
            }
            if (
                in_array($aggregate->function, [AggregateFunction::Minimum, AggregateFunction::Maximum], true)
                && !in_array($columns[0]->doctrineType, [
                    'ascii_string', 'bigint', 'date_immutable', 'datetime_immutable', 'datetimetz_immutable',
                    'decimal', 'integer', 'smallint', 'string', 'text', 'time_immutable',
                ], true)
            ) {
                throw new InvalidBusinessRecordQuery(
                    'Minimum and maximum require a portably comparable scalar field.',
                );
            }
            $select[] = sprintf(
                '%s(%s.%s) AS %s',
                strtoupper($aggregate->function->value),
                $alias,
                $this->quote($columns[0]->physicalName),
                $this->quote($aggregate->alias),
            );
        }

        return sprintf(
            'SELECT %s FROM %s %s WHERE %s',
            implode(', ', $select),
            $this->quote($table->physicalName),
            $alias,
            implode(' AND ', $where),
        );
    }

    /**
     * Resolve a handle to a field the definition permits a filter to name.
     *
     * @param   EntityTypeDefinition      $definition  Definition the handle resolves against.
     * @param   string                    $handle      Field handle taken from a filter node.
     * @param   BusinessRecordAccessPlan  $access      Dynamic field permissions.
     * @param   FieldAccessUsage          $usage       Direct-filter or relationship-selector use.
     *
     * @return  FieldDefinition  The declared field, proved filterable and visible to queries.
     *
     * @throws  InvalidBusinessRecordQuery  When no field carries the handle, the field is not declared
     *          filterable, or its sensitivity keeps it out of queries altogether.
     *
     * @since   2.0.0
     */
    private function filterable(
        EntityTypeDefinition $definition,
        string $handle,
        BusinessRecordAccessPlan $access,
        FieldAccessUsage $usage,
    ): FieldDefinition {
        $field = $this->field($definition, $handle);
        if (
            !$field->filterable
            || !$this->queryVisible($field)
            || !$access->fields->allows($usage, $handle)
            || ($usage === FieldAccessUsage::Relation
                && $field === $this->identityField($definition)
                && !$access->fields->allows(FieldAccessUsage::PublicReference, $handle))
        ) {
            throw new InvalidBusinessRecordQuery('A requested query field is unavailable.');
        }

        return $field;
    }

    /**
     * Report whether a field may take part in a query at all.
     *
     * Restricted and secret fields come back redacted on the read path, so letting one be filtered,
     * searched, sorted or aggregated would let a caller recover the hidden value from which records the
     * query returns. A conditional field is also refused defensively even though definition construction
     * disallows query flags on it: a legacy or corrupt installed shape must not become an inference channel.
     *
     * @param   FieldDefinition  $field  Declared field being considered.
     *
     * @return  bool  True when the field is unconditionally readable and classified below `Restricted`.
     *
     * @since   2.0.0
     */
    private function queryVisible(FieldDefinition $field): bool
    {
        return $field->readVisible
            && $field->visibilityCondition === null
            && !in_array($field->sensitivity, [Sensitivity::Restricted, Sensitivity::Secret], true);
    }

    /**
     * Encode a caller's literal into the physical column values a predicate binds it as.
     *
     * Three cases are distinguished. A generated identity field is bound against the table's own record
     * key rather than a column of its own. An entity reference is stored as the target's record key, so a
     * public identity is looked up first — unless no scope is available, which is how a cursor value is
     * restored, and the literal must then already be a record key. Everything else is normalized and
     * encoded by the value codec, which is what refuses a literal the field cannot hold.
     *
     * @param   ResolvedBusinessDefinition  $resolved  Definition and installed schema the field belongs
     *          to.
     * @param   PhysicalTableBlueprint      $table     Installed table whose column names are returned.
     * @param   FieldDefinition             $field     Field the literal is being compared against.
     * @param   mixed                       $value     Literal exactly as the filter or cursor carried it.
     * @param   ?RecordScope                $scope     Scope an entity reference is resolved within; null
     *          while a cursor value is being restored, which then requires a record key.
     * @param   ?BusinessRecordAccessPlan   $access    Related-target decision for entity references.
     *
     * @return  array<string, mixed>  Bound values keyed by physical column name; a composite field yields
     *          one entry per column it occupies.
     *
     * @throws  InvalidBusinessRecordQuery  When an identity or reference literal is not a string, a
     *          reference cursor key is not a UUID, or the field has no installed physical storage.
     * @throws  InvalidArgumentException  When the value codec refuses the literal for this field, which
     *          `compile()` reports as a refused query.
     *
     * @since   2.0.0
     */
    private function queryColumns(
        ResolvedBusinessDefinition $resolved,
        PhysicalTableBlueprint $table,
        FieldDefinition $field,
        mixed $value,
        ?RecordScope $scope = null,
        ?BusinessRecordAccessPlan $access = null,
    ): array {
        $identity = $this->identityField($resolved->definition);
        if ($field === $identity && $resolved->definition->identityStrategy === IdentityStrategy::Uuid) {
            if (!is_string($value)) {
                throw new InvalidBusinessRecordQuery('An identity query value must be a string.');
            }
            $normalized = $this->values->identity($resolved->definition, [$field->handle => $value], null);
            return [$this->physical($table, 'record_id') => $normalized];
        }
        if ($field->type === 'core.entity_reference') {
            if (!is_string($value)) {
                throw new InvalidBusinessRecordQuery('An entity-reference query value must be a string.');
            }
            if ($scope === null) {
                if (!Uuid::isValid($value)) {
                    throw new InvalidBusinessRecordQuery('A signed entity-reference cursor key is invalid.');
                }
                $recordKey = strtolower($value);
            } else {
                if ($access === null) {
                    throw new InvalidBusinessRecordQuery('An entity-reference access plan is unavailable.');
                }
                $recordKey = $this->referenceRecordKey(
                    $resolved->definition,
                    $field,
                    $scope,
                    $value,
                    $access,
                );
            }

            return [$this->physical($table, $field->handle) => $recordKey];
        }
        $normalized = $this->values->normalize(
            $field,
            $value,
            $resolved->definition->siteIdentifier,
            $resolved->definition->id,
            'query-value',
        );
        $encoded = $this->values->encodeColumns($resolved->definition, $table, [$field->handle => $normalized]);
        if ($encoded === []) {
            throw new InvalidBusinessRecordQuery('The queried field has no installed physical storage.');
        }

        return $encoded;
    }

    /**
     * Look up the record key that an entity reference's public identity points at.
     *
     * A reference is stored as the target's record key, while a caller filters with the identity the
     * target publishes; under a natural-key identity strategy those are different strings, so the
     * translation has to go through the target's own table. The lookup is confined to the request scope
     * and ignores soft-deleted rows, and an identity matching nothing yields the nil UUID rather than no
     * predicate at all, so the filter matches no record instead of every record.
     *
     * @param   EntityTypeDefinition      $source          Definition holding the reference field, whose site
     *          the target is resolved on.
     * @param   FieldDefinition           $field           The `core.entity_reference` field being filtered,
     *          whose configuration names the target definition.
     * @param   ?RecordScope              $scope           Scope the lookup is confined to; a public identity
     *          cannot be resolved without one.
     * @param   string                    $publicIdentity  Identity of the target record as the caller wrote
     *          it.
     * @param   BusinessRecordAccessPlan  $access          Source plan carrying the reference target plan.
     *
     * @return  string  Record key of the referenced row, or the nil UUID when nothing matches.
     *
     * @throws  InvalidBusinessRecordQuery  When no scope is available, the field declares no string
     *          target, or the target definition or its installed schema is unavailable.
     * @throws  InvalidArgumentException  When the identity is not one the target's identity field accepts,
     *          which `compile()` reports as a refused query.
     *
     * @since   2.0.0
     */
    private function referenceRecordKey(
        EntityTypeDefinition $source,
        FieldDefinition $field,
        ?RecordScope $scope,
        string $publicIdentity,
        BusinessRecordAccessPlan $access,
    ): string {
        if ($scope === null) {
            throw new InvalidBusinessRecordQuery('A public entity-reference cursor cannot be resolved without scope.');
        }
        $targetHandle = $field->configuration['target'] ?? null;
        if (!is_string($targetHandle)) {
            throw new InvalidBusinessRecordQuery('An entity-reference target is unavailable.');
        }
        $target = $this->targetHandle($source, $targetHandle);
        $targetAccess = $access->related($field->handle);
        if ($targetAccess === null) {
            throw new InvalidBusinessRecordQuery('An entity-reference target is unavailable.');
        }
        $this->assertAccessResource($target, $targetAccess);
        $identity = $this->identityField($target->definition);
        if (!$targetAccess->fields->allows(FieldAccessUsage::PublicReference, $identity->handle)) {
            throw new InvalidBusinessRecordQuery('An entity-reference public identity is unavailable.');
        }
        $normalized = $this->values->identity(
            $target->definition,
            [$identity->handle => $publicIdentity],
            null,
        );
        $table = $this->recordTable($target);
        $identityColumn = $target->definition->identityStrategy === IdentityStrategy::Uuid
            ? $this->physical($table, 'record_id')
            : $this->physical($table, $identity->handle);
        $parameters = [$normalized];
        $types = [$table->physicalColumn($identityColumn)->doctrineType ?? Types::STRING];
        $where = ['x.' . $this->quote($identityColumn) . ' = ?'];
        array_push($where, ...$this->scopePredicates($table, 'x', $scope, $parameters, $types));
        if ($table->column('deleted_at') !== null) {
            $where[] = 'x.' . $this->quote($this->physical($table, 'deleted_at')) . ' IS NULL';
        }
        $where[] = $this->recordPolicy(
            $target,
            $table,
            'x',
            $scope,
            $targetAccess,
            $parameters,
            $types,
        );
        $recordKey = $this->database->fetchOne(sprintf(
            'SELECT x.%s FROM %s x WHERE %s',
            $this->quote($this->physical($table, 'record_id')),
            $this->quote($table->physicalName),
            implode(' AND ', $where),
        ), $parameters, $types);

        return is_string($recordKey) ? $recordKey : '00000000-0000-0000-0000-000000000000';
    }

    /**
     * List the installed columns one definition field occupies.
     *
     * The identity field is the exception: under a generated-key strategy it lives in the table's own key
     * column — `record_id` on a record table, `line_id` on an owned-line table — rather than under its
     * handle. Every other field owns the column named after its handle together with any column prefixed
     * by it, which is how the parts of a composite field such as a money or quantity value are collected.
     *
     * @param   EntityTypeDefinition    $definition  Definition the field is declared on.
     * @param   PhysicalTableBlueprint  $table       Installed table the columns are looked up in.
     * @param   FieldDefinition         $field       Field whose storage is wanted.
     *
     * @return  list<PhysicalColumnBlueprint>  The columns backing the field, never empty.
     *
     * @throws  InvalidBusinessRecordQuery  When the installed table carries no column for the field, which
     *          is what a definition newer than the schema on disk looks like from here.
     *
     * @since   2.0.0
     */
    private function fieldColumns(
        EntityTypeDefinition $definition,
        PhysicalTableBlueprint $table,
        FieldDefinition $field,
    ): array {
        if ($field === $this->identityField($definition)) {
            $logical = $definition->identityStrategy === IdentityStrategy::Uuid
                ? ($table->logicalName === 'record' ? 'record_id' : 'line_id')
                : $field->handle;
            return [$table->column($logical)
                ?? throw new InvalidBusinessRecordQuery('The identity column is unavailable.')];
        }
        $prefix = $field->handle . '.';
        $columns = array_values(array_filter(
            $table->columns(),
            static fn (PhysicalColumnBlueprint $column): bool =>
                $column->logicalName === $field->handle || str_starts_with($column->logicalName, $prefix),
        ));
        if ($columns === []) {
            throw new InvalidBusinessRecordQuery('A requested field has no installed physical column.');
        }

        return $columns;
    }

    /**
     * Resolve a handle to the field the definition declares under it.
     *
     * @param   EntityTypeDefinition  $definition  Definition version the query is pinned to, which is what
     *          decides whether a handle exists at all.
     * @param   string                $handle      Field handle as the query wrote it.
     *
     * @return  FieldDefinition  The declared field.
     *
     * @throws  InvalidBusinessRecordQuery  When the definition declares no field with that handle.
     *
     * @since   2.0.0
     */
    private function field(EntityTypeDefinition $definition, string $handle): FieldDefinition
    {
        foreach ($definition->fields() as $field) {
            if ($field->handle === $handle) {
                return $field;
            }
        }
        throw new InvalidBusinessRecordQuery('A requested query field is unavailable.');
    }

    /**
     * Find the field carrying the definition's identity under its declared strategy.
     *
     * Which field type counts depends on the strategy: `core.uuid` when keys are generated, and
     * `core.reference_identity` when the caller supplies a natural key. The answer decides both which
     * column an identity filter binds against and whether a public identity has to be translated into a
     * record key at all.
     *
     * @param   EntityTypeDefinition  $definition  Definition whose identity field is wanted.
     *
     * @return  FieldDefinition  The first field of the type the strategy requires.
     *
     * @throws  InvalidBusinessRecordQuery  When the definition declares no field of that type.
     *
     * @since   2.0.0
     */
    private function identityField(EntityTypeDefinition $definition): FieldDefinition
    {
        $type = $definition->identityStrategy === IdentityStrategy::Uuid
            ? 'core.uuid'
            : 'core.reference_identity';
        foreach ($definition->fields() as $field) {
            if ($field->type === $type) {
                return $field;
            }
        }
        throw new InvalidBusinessRecordQuery('The definition identity field is unavailable.');
    }

    /**
     * Resolve a relationship handle, ordered-line fields included.
     *
     * The lookup goes through the definition's runtime resolution, so a legacy `core.ordered_lines` field
     * answers as the owned-line association it stands for and a hop can traverse either kind through one
     * path.
     *
     * @param   EntityTypeDefinition  $definition  Definition the relationship is declared on.
     * @param   string                $handle      Relationship handle as the query wrote it.
     *
     * @return  RelationshipDefinition  The declared association, or the one synthesized for an
     *          ordered-line field.
     *
     * @throws  InvalidBusinessRecordQuery  When neither a declared relationship nor an ordered-line field
     *          carries the handle.
     *
     * @since   2.0.0
     */
    private function relationship(EntityTypeDefinition $definition, string $handle): RelationshipDefinition
    {
        return $definition->runtimeRelationship($handle)
            ?? throw new InvalidBusinessRecordQuery('A query references an unknown relationship.');
    }

    /**
     * Find the relationship on the target definition that mirrors the one being traversed.
     *
     * Reached only when the source side carries no storage of its own, which is the normal shape of a
     * one-to-many: the association is materialized on its many-to-one partner, so the join has to be read
     * from the target's declaration. The candidate must name the source as its own target as well, so a
     * relationship that merely shares the inverse handle is not accepted.
     *
     * @param   EntityTypeDefinition    $source        Definition being queried, which the inverse must
     *          name as its target.
     * @param   EntityTypeDefinition    $target        Definition the traversal leads to, whose declared
     *          relationships are searched.
     * @param   RelationshipDefinition  $relationship  Association being traversed, which names the inverse
     *          handle to look for.
     *
     * @return  RelationshipDefinition  The target's reciprocal association, whose storage the join reads.
     *
     * @throws  InvalidBusinessRecordQuery  When the association declares no inverse, or the target
     *          declares none under that handle pointing back at the source.
     *
     * @since   2.0.0
     */
    private function inverseRelationship(
        EntityTypeDefinition $source,
        EntityTypeDefinition $target,
        RelationshipDefinition $relationship,
    ): RelationshipDefinition {
        if ($relationship->inverse === null) {
            throw new InvalidBusinessRecordQuery('The relationship has no installed source or inverse storage.');
        }
        foreach ($target->relationships() as $candidate) {
            if ($candidate->handle === $relationship->inverse && $candidate->target === $source->handle) {
                return $candidate;
            }
        }
        throw new InvalidBusinessRecordQuery('The canonical inverse relationship definition is unavailable.');
    }

    /**
     * Resolve the definition and installed schema a relationship points at.
     *
     * @param   EntityTypeDefinition    $source        Definition doing the traversal, which supplies the
     *          site and the version the target is pinned to.
     * @param   RelationshipDefinition  $relationship  Association whose declared target is resolved.
     *
     * @return  ResolvedBusinessDefinition  The target definition version paired with its installation,
     *          fenced by `targetHandle()`, whose refusals propagate unchanged.
     *
     * @throws  InvalidBusinessRecordQuery  When the target is unpublished, has no active installation on
     *          this site, or is pinned to a version the installed schema does not carry.
     *
     * @since   2.0.0
     */
    private function target(
        EntityTypeDefinition $source,
        RelationshipDefinition $relationship,
    ): ResolvedBusinessDefinition {
        return $this->targetHandle($source, $relationship->target);
    }

    /**
     * Resolve a definition handle on the source's site into a fenced, version-pinned definition.
     *
     * A hop reads a second set of tables, so the target has to be pinned exactly as the browsed
     * definition is. A shared fence holds the target's installation still for the rest of the
     * transaction, the source's evolution hints decide which version the target's rows are read under,
     * and the generation observed under the fence is re-checked against what was resolved outside it.
     * Anything short of that — a disabled owner, an installation that is not active or belongs to another
     * site, a repin ahead of the installed version, a version never published — refuses the query rather
     * than reading a table under a shape it does not have.
     *
     * @param   EntityTypeDefinition  $source        Definition doing the traversal; supplies the site and
     *          the repin hints the target version is taken from.
     * @param   string                $targetHandle  Handle of the definition to resolve.
     *
     * @return  ResolvedBusinessDefinition  The pinned target definition paired with its installation.
     *
     * @throws  InvalidBusinessRecordQuery  When the target is unknown or its owner disabled, its
     *          installation is missing, inactive or on another site, the repin is newer than the installed
     *          schema, or that version was never published.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable  When
     *          the fence finds no definition of that handle on the site.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable  When the
     *          fence finds no installation it admits for the definition.
     * @throws  \Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable  When
     *          no transaction is open to hold the fence, the fence cannot be taken, or the installation
     *          moved between being fenced and being resolved.
     *
     * @since   2.0.0
     */
    private function targetHandle(
        EntityTypeDefinition $source,
        string $targetHandle,
    ): ResolvedBusinessDefinition {
        $site = SiteContext::fromString($source->siteIdentifier);
        $generation = $this->fence->shared($site, $targetHandle);
        $entry = $this->definitions->entry($site, $targetHandle);
        if ($entry === null || !$entry->ownerActive) {
            throw new InvalidBusinessRecordQuery('A relation query target is unavailable.');
        }
        $installation = $this->installations->find($entry->id);
        if (
            $installation === null || $installation->status !== SchemaInstallationStatus::Active
            || $installation->siteIdentifier !== $source->siteIdentifier
        ) {
            throw new InvalidBusinessRecordQuery('A relation query target schema is unavailable.');
        }
        $targetVersion = SchemaEvolutionHints::fromDefinition($source)->repin($targetHandle)
            ?? $installation->definitionVersion;
        if ($targetVersion > $installation->definitionVersion) {
            throw new InvalidBusinessRecordQuery('A relation target is newer than its installed schema.');
        }
        $published = $this->definitions->published($site, $entry->id, $targetVersion);
        if ($published === null) {
            throw new InvalidBusinessRecordQuery('A relation query target definition version is unavailable.');
        }

        $resolved = new ResolvedBusinessDefinition($published->definition, $installation);
        $generation->assertMatches($resolved);

        return $resolved;
    }

    /**
     * Take the installed record table of a resolved definition.
     *
     * @param   ResolvedBusinessDefinition  $resolved  Definition paired with the schema installed for it.
     *
     * @return  PhysicalTableBlueprint  Blueprint of the table this definition's records are stored in.
     *
     * @throws  InvalidBusinessRecordQuery  When the installation describes no `record` table, which is
     *          what a half-applied installation looks like from here.
     *
     * @since   2.0.0
     */
    private function recordTable(ResolvedBusinessDefinition $resolved): PhysicalTableBlueprint
    {
        return $resolved->installation->blueprint->table('record')
            ?? throw new InvalidBusinessRecordQuery('The installed record table is unavailable.');
    }

    /**
     * Take the physical name of an installed column, refusing a query the schema cannot answer.
     *
     * @param   PhysicalTableBlueprint  $table    Installed table to look the column up in.
     * @param   string                  $logical  Logical column name, such as `record_id`, `updated_at`
     *          or a field handle.
     *
     * @return  string  The name the installer actually created the column under, before quoting.
     *
     * @throws  InvalidBusinessRecordQuery  When the table declares no column under that logical name.
     *
     * @since   2.0.0
     */
    private function physical(PhysicalTableBlueprint $table, string $logical): string
    {
        return $table->column($logical)->physicalName
            ?? throw new InvalidBusinessRecordQuery('An installed query column is unavailable.');
    }

    /**
     * Take the Doctrine type of an installed column, so a value is bound the way the column stores it.
     *
     * @param   PhysicalTableBlueprint  $table    Installed table to look the column up in.
     * @param   string                  $logical  Logical column name whose storage type is wanted.
     *
     * @return  string  Doctrine type name to bind a value for this column with.
     *
     * @throws  InvalidBusinessRecordQuery  When the table declares no column under that logical name.
     *
     * @since   2.0.0
     */
    private function type(PhysicalTableBlueprint $table, string $logical): string
    {
        return $table->column($logical)->doctrineType
            ?? throw new InvalidBusinessRecordQuery('An installed query column type is unavailable.');
    }

    /**
     * Delimit one identifier the way the connected database platform expects.
     *
     * Every identifier that reaches a compiled statement passes through here. What is quoted is always a
     * physical name taken from the installed blueprint rather than caller text, so this guards the
     * grammar against ordinary names that collide with reserved words; it is not what keeps caller input
     * out of the statement.
     *
     * @param   string  $identifier  Physical table or column name from the installed blueprint.
     *
     * @return  string  The identifier delimited for the platform in use.
     *
     * @since   2.0.0
     */
    private function quote(string $identifier): string
    {
        return $this->database->getDatabasePlatform()->quoteSingleIdentifier($identifier);
    }

    /**
     * Neutralize `LIKE` metacharacters in caller text so a search matches them literally.
     *
     * `!` is the escape character the compiled `LIKE` predicates declare, and it is substituted first so
     * a caller cannot smuggle an escape through the replacement. Without this, a `%` in a search term
     * would turn an anchored lookup into a sweep of the whole column.
     *
     * @param   string  $value  Search text as the caller supplied it, already lowercased for matching.
     *
     * @return  string  The same text with `!`, `%` and `_` escaped for an `ESCAPE '!'` predicate.
     *
     * @since   2.0.0
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /**
     * Fingerprint the query a page cursor is allowed to be used with.
     *
     * The digest covers the definition's identity, version and checksum, the installed schema checksum,
     * the request scope, and the specification with its cursor left out. Every page of one browse
     * therefore hashes the same, while changing a filter, an ordering, the scope, or the shape the
     * records are stored under invalidates the tokens already handed out. `doCompile()` compares this
     * against the digest carried inside a presented cursor and refuses the cursor when the two differ.
     *
     * @param   ResolvedBusinessDefinition  $resolved       Definition version and installation the page is
     *          read under.
     * @param   RecordScope                 $scope          Site and organization the page is confined to.
     * @param   RecordQuerySpecification    $specification  Page request, canonicalized without its cursor.
     * @param   BusinessRecordAccessPlan    $access         Authorization decision the token is bound to.
     *
     * @return  string  Lowercase 64-character SHA-256 over the canonical form of all of it.
     *
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When something the query
     *          carries cannot be canonically encoded, a string that is not valid UTF-8 being the case the
     *          query's own bounds still admit.
     *
     * @since   2.0.0
     */
    private function cursorDigest(
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        RecordQuerySpecification $specification,
        BusinessRecordAccessPlan $access,
    ): string {
        return CanonicalDefinitionJson::checksum([
            'definition_id' => $resolved->definition->id,
            'definition_version' => $resolved->definition->definitionVersion,
            'definition_checksum' => $resolved->definition->checksum(),
            'schema_checksum' => $resolved->installation->schemaChecksum,
            'scope' => $scope->toArray(),
            'specification' => $specification->toArray(false),
            'access' => $access->digest(),
        ]);
    }
}
