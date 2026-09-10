<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use JsonException;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaConflict;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaPlanRepository;
use Kumwe\App\BusinessSchema\Domain\SchemaPlan;
use Kumwe\App\BusinessSchema\Domain\SchemaPlanStep;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Context\Value\SiteContext;
use RuntimeException;

/**
 * Plan and journal store over the prefixed `business_schema_plans` and `business_schema_plan_steps` tables.
 *
 * Every plan is written into its row twice: once as the canonical document in `canonical_plan`, and once
 * across the denormalized columns that let indexes and operators filter plans without decoding JSON. A read
 * rebuilds the plan from the canonical document alone and then asserts that each of those columns still
 * agrees with it — text byte for byte, numbers once the driver's decimal strings are converted, timestamps
 * normalized to UTC microseconds, the outcome by canonical JSON — so a row edited underneath the
 * application is refused rather than quietly believed.
 *
 * Concurrency control lives in the WHERE clause rather than in a lock. `replace()` filters on the revision
 * the caller read and insists the replacement advance it by exactly one; `replaceStep()` filters on the
 * execution fence, spelling an unfenced row as `IS NULL` because SQL equality never matches one. A
 * statement that touches no row becomes a `BusinessSchemaConflict` instead of a silent no-op, and a
 * replacement drops the identity and creation columns before it runs, so no update can move a plan to
 * another definition, site, or author.
 *
 * @since  2.0.0
 */
final readonly class DoctrineBusinessSchemaPlanRepository implements BusinessSchemaPlanRepository
{
    /**
     * Bind the store to the connection its statements run on and the resolver that names both tables.
     *
     * @param  Connection  $database  DBAL connection carrying the caller's transaction, when one is open.
     * @param  TableNames  $tables    Resolver applying the configured prefix to the plan and step tables.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * List every plan one site has recorded, whatever state it reached.
     *
     * @param   SiteContext  $site  Site whose plans are listed; a plan never crosses sites.
     *
     * @return  list<SchemaPlan>  That site's plans, most recently created first with the plan ID breaking
     *          ties; empty when the site has never planned a schema change.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the read.
     * @throws  RuntimeException  When a stored row disagrees with its own canonical plan document.
     * @throws  \Kumwe\App\BusinessSchema\Domain\InvalidBusinessSchema  When a stored canonical document is
     *          not a valid plan.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When a stored plan cannot be
     *          canonically fingerprinted, as one holding more than 512 operations cannot.
     *
     * @since   2.0.0
     */
    public function all(SiteContext $site): array
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT * FROM %s WHERE site_identifier = ? ORDER BY created_at DESC, id DESC',
            $this->tables->quoted('business_schema_plans'),
        ), [$site->identifier()]);

        return array_map($this->mapPlan(...), $rows);
    }

    /**
     * Read one plan of a site by identifier.
     *
     * Always goes back to the table: callers re-read a plan after an execution attempt to see the state
     * that run left behind, so two reads of the same identifier are not interchangeable.
     *
     * @param   SiteContext  $site    Site the plan must belong to.
     * @param   string       $planId  UUID of the plan to read.
     *
     * @return  ?SchemaPlan  The plan as currently stored, or null when this site holds none under that
     *          identifier.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the read.
     * @throws  RuntimeException  When the stored row disagrees with its own canonical plan document.
     * @throws  \Kumwe\App\BusinessSchema\Domain\InvalidBusinessSchema  When the stored canonical document is
     *          not a valid plan.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When the stored plan cannot be
     *          canonically fingerprinted, as one holding more than 512 operations cannot.
     *
     * @since   2.0.0
     */
    public function find(SiteContext $site, string $planId): ?SchemaPlan
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE site_identifier = ? AND id = ?',
            $this->tables->quoted('business_schema_plans'),
        ), [$site->identifier(), $planId]);

        return $row === false ? null : $this->mapPlan($row);
    }

    /**
     * Fetch the plan most recently created for one definition on one site.
     *
     * Newest is by creation time with the plan ID breaking ties, and no status filter applies, so a
     * superseded or failed plan is still what comes back when it is the newest one recorded.
     *
     * @param   SiteContext  $site          Site the plan must belong to.
     * @param   string       $definitionId  UUID of the definition the plan targets.
     *
     * @return  ?SchemaPlan  The newest plan for that definition, or null when none was ever planned.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the read.
     * @throws  RuntimeException  When the stored row disagrees with its own canonical plan document.
     * @throws  \Kumwe\App\BusinessSchema\Domain\InvalidBusinessSchema  When the stored canonical document is
     *          not a valid plan.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When the stored plan cannot be
     *          canonically fingerprinted, as one holding more than 512 operations cannot.
     *
     * @since   2.0.0
     */
    public function latestForDefinition(SiteContext $site, string $definitionId): ?SchemaPlan
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE site_identifier = ? AND definition_id = ? '
            . 'ORDER BY created_at DESC, id DESC',
            $this->tables->quoted('business_schema_plans'),
        ), [$site->identifier(), $definitionId]);

        return $row === false ? null : $this->mapPlan($row);
    }

    /**
     * Report whether any plan for a definition stopped part way through execution.
     *
     * Matches the stored `executing`, `failed`, and `recovery_required` statuses and stops at the first
     * hit rather than counting them. No canonical document is decoded and no plan is rebuilt — the query
     * touches only the indexed status column — so this stays cheap enough for the lifecycle sweep to ask
     * before every installation it considers returning to service.
     *
     * @param   SiteContext  $site          Site the plans are read within.
     * @param   string       $definitionId  UUID of the definition being checked.
     *
     * @return  bool  True while at least one plan for that definition is executing, failed, or awaiting
     *          recovery.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the read.
     *
     * @since   2.0.0
     */
    public function hasUnfinishedExecution(SiteContext $site, string $definitionId): bool
    {
        return $this->database->fetchOne(sprintf(
            'SELECT 1 FROM %s WHERE site_identifier = ? AND definition_id = ? '
                . 'AND status IN (?, ?, ?) LIMIT 1',
            $this->tables->quoted('business_schema_plans'),
        ), [
            $site->identifier(),
            $definitionId,
            'executing',
            'failed',
            'recovery_required',
        ]) !== false;
    }

    /**
     * Insert a freshly planned plan, the only write here that creates a plan row.
     *
     * The table carries a unique index over `plan_checksum`, so two planners racing on the same definition
     * collide on the insert instead of both succeeding; the driver's uniqueness failure is translated into
     * the conflict the application layer already knows how to answer. Journal rows are written separately
     * through `saveStep()`, and every later state change goes through `replace()`.
     *
     * @param   SchemaPlan  $plan  Newly planned plan; its canonical document and the denormalized columns
     *          derived from it are written together.
     *
     * @return  void
     *
     * @throws  BusinessSchemaConflict  When a row with the same plan ID or canonical checksum already exists.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the insert for any other reason.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When the plan cannot be
     *          canonically fingerprinted, as one holding more than 512 operations cannot.
     *
     * @since   2.0.0
     */
    public function save(SchemaPlan $plan): void
    {
        try {
            $this->database->insert(
                $this->tables->raw('business_schema_plans'),
                $this->values($plan),
                $this->types(),
            );
        } catch (UniqueConstraintViolationException $exception) {
            throw new BusinessSchemaConflict('An identical or colliding schema plan already exists.', 0, $exception);
        }
    }

    /**
     * Overwrite a plan's mutable state, but only while it still stands where the caller last read it.
     *
     * Three things have to line up. The replacement must carry exactly one revision more than the caller
     * read; the stored row must still sit at that revision, which the UPDATE enforces by filtering on it;
     * and the stored execution fence must be the one named. A caller holding a fence passes it and it joins
     * the criteria, while a caller expecting an unfenced plan passes null — that case needs a SELECT of its
     * own, because `execution_fence = NULL` matches nothing in SQL, and a row that has vanished or moved
     * off the expected revision is reported as a conflict from there too. Identity and creation columns are
     * stripped before the update runs, so a replacement cannot move a plan to another definition, site, or
     * author.
     *
     * @param   SchemaPlan  $plan              Plan state to store, at $expectedRevision plus one.
     * @param   int         $expectedRevision  Revision the caller read this plan at.
     * @param   ?int        $expectedFence     Fence the writing run holds, or null to demand an unfenced plan.
     *
     * @return  void
     *
     * @throws  BusinessSchemaConflict  When the revision does not advance by exactly one, the stored plan has
     *          already moved on, or the stored fence is not the expected one.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the fence probe or the update.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When the plan cannot be
     *          canonically fingerprinted, as one holding more than 512 operations cannot.
     *
     * @since   2.0.0
     */
    public function replace(SchemaPlan $plan, int $expectedRevision, ?int $expectedFence = null): void
    {
        if ($plan->revision !== $expectedRevision + 1) {
            throw new BusinessSchemaConflict('A schema plan replacement must advance exactly one revision.');
        }
        $values = $this->values($plan);
        unset(
            $values['id'],
            $values['definition_id'],
            $values['site_identifier'],
            $values['created_by'],
            $values['created_at'],
        );
        $types = $this->types();
        unset(
            $types['id'],
            $types['definition_id'],
            $types['site_identifier'],
            $types['created_by'],
            $types['created_at'],
        );
        $criteria = ['id' => $plan->id, 'site_identifier' => $plan->siteIdentifier, 'revision' => $expectedRevision];
        if ($expectedFence !== null) {
            $criteria['execution_fence'] = $expectedFence;
        } else {
            $currentFence = $this->database->fetchOne(sprintf(
                'SELECT execution_fence FROM %s WHERE id = ? AND site_identifier = ? AND revision = ?',
                $this->tables->quoted('business_schema_plans'),
            ), [$plan->id, $plan->siteIdentifier, $expectedRevision]);
            if ($currentFence !== null) {
                throw new BusinessSchemaConflict('The schema plan execution fence changed concurrently.');
            }
        }
        $affected = $this->database->update(
            $this->tables->raw('business_schema_plans'),
            $values,
            $criteria,
            $types,
        );
        if ($affected !== 1) {
            throw new BusinessSchemaConflict('The schema plan changed concurrently.');
        }
    }

    /**
     * Read one plan's execution journal, in the order the executor replays it.
     *
     * Addressed by plan alone rather than by site, so a caller that must not reach across sites resolves
     * the plan through `find()` first. The stored `chunk_cursor` column is surfaced as the step's `cursor`.
     *
     * @param   string  $planId  UUID of the plan whose journal is wanted.
     *
     * @return  list<SchemaPlanStep>  One entry per plan operation, in ordinal order; empty when the plan has
     *          no journal rows.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the read.
     * @throws  RuntimeException  When a journal column is absent, empty, wrongly typed, or holds invalid JSON.
     * @throws  \Kumwe\App\BusinessSchema\Domain\InvalidBusinessSchema  When a stored row breaks a step
     *          invariant, or its kind, risk, or state is not one this build knows.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When a stored cursor or
     *          outcome cannot be canonically encoded.
     *
     * @since   2.0.0
     */
    public function steps(string $planId): array
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT * FROM %s WHERE plan_id = ? ORDER BY ordinal',
            $this->tables->quoted('business_schema_plan_steps'),
        ), [$planId]);

        return array_map(fn (array $row): SchemaPlanStep => SchemaPlanStep::fromArray([
            'plan_id' => $this->string($row, 'plan_id'),
            'ordinal' => $this->integer($row, 'ordinal'),
            'operation_checksum' => $this->string($row, 'operation_checksum'),
            'operation_kind' => $this->string($row, 'operation_kind'),
            'risk' => $this->string($row, 'risk'),
            'state' => $this->string($row, 'state'),
            'attempt' => $this->integer($row, 'attempt'),
            'execution_fence' => $this->nullableInteger($row, 'execution_fence'),
            'cursor' => $this->nullableJsonObject($row['chunk_cursor'] ?? null, 'schema step cursor'),
            'before_schema_checksum' => $this->nullableString($row, 'before_schema_checksum'),
            'after_schema_checksum' => $this->nullableString($row, 'after_schema_checksum'),
            'outcome' => $this->nullableJsonObject($row['outcome'] ?? null, 'schema step outcome'),
            'error_code' => $this->nullableString($row, 'error_code'),
            'started_at' => $this->nullableStringValue($row['started_at'] ?? null),
            'completed_at' => $this->nullableStringValue($row['completed_at'] ?? null),
            'updated_at' => $this->nullableStringValue($row['updated_at'] ?? null),
        ]), $rows);
    }

    /**
     * Write a journal row without regard to any execution fence.
     *
     * This is how the planner lays down the pending journal for a plan it has just saved. An existing row
     * under the same plan and ordinal is updated rather than duplicated, and an update that matched nothing
     * is re-probed by key before an insert is attempted, because a driver reports zero affected rows for a
     * write that leaves every column unchanged. Anything that must not overtake a concurrent run goes
     * through `replaceStep()` instead.
     *
     * @param   SchemaPlanStep  $step  Journal state to store, addressed by its plan ID and ordinal.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the step carries no update timestamp, which a persisted row requires.
     * @throws  BusinessSchemaConflict  When a concurrent writer inserted that plan and ordinal first.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the update, the key probe, or the insert.
     *
     * @since   2.0.0
     */
    public function saveStep(SchemaPlanStep $step): void
    {
        $values = [
            'operation_checksum' => $step->operationChecksum,
            'operation_kind' => $step->operationKind->value,
            'risk' => $step->risk->value,
            'state' => $step->state->value,
            'attempt' => $step->attempt,
            'execution_fence' => $step->executionFence,
            'chunk_cursor' => $step->cursor,
            'before_schema_checksum' => $step->beforeSchemaChecksum,
            'after_schema_checksum' => $step->afterSchemaChecksum,
            'outcome' => $step->outcome,
            'error_code' => $step->errorCode,
            'started_at' => $step->startedAt,
            'completed_at' => $step->completedAt,
            'updated_at' => $step->updatedAt
                ?? throw new RuntimeException('A persisted schema-plan step requires an update timestamp.'),
        ];
        $types = [
            'attempt' => Types::INTEGER,
            'execution_fence' => Types::BIGINT,
            'chunk_cursor' => Types::JSON,
            'outcome' => Types::JSON,
            'started_at' => Types::DATETIME_IMMUTABLE,
            'completed_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ];
        $affected = $this->database->update(
            $this->tables->raw('business_schema_plan_steps'),
            $values,
            ['plan_id' => $step->planId, 'ordinal' => $step->ordinal],
            $types,
        );
        if ($affected !== 0) {
            return;
        }
        $exists = $this->database->fetchOne(sprintf(
            'SELECT plan_id FROM %s WHERE plan_id = ? AND ordinal = ?',
            $this->tables->quoted('business_schema_plan_steps'),
        ), [$step->planId, $step->ordinal]);
        if ($exists !== false) {
            return;
        }
        try {
            $this->database->insert($this->tables->raw('business_schema_plan_steps'), [
                'plan_id' => $step->planId,
                'ordinal' => $step->ordinal,
                ...$values,
            ], ['ordinal' => Types::INTEGER, ...$types]);
        } catch (UniqueConstraintViolationException $exception) {
            throw new BusinessSchemaConflict('The schema-plan step changed concurrently.', 0, $exception);
        }
    }

    /**
     * Rewrite a journal row, and only for the run whose fence the stored row already carries.
     *
     * The statement is assembled by hand rather than handed to `Connection::update()` so that the fence can
     * be expressed as `execution_fence IS NULL` — the shape a first attempt claims an untouched row with —
     * while any other value demands exactly that fence. A superseded run therefore fails here instead of
     * overwriting the journal of the run that displaced it. The row must already exist; this never inserts.
     *
     * @param   SchemaPlanStep  $step           Journal state to store, addressed by its plan ID and ordinal.
     * @param   ?int            $expectedFence  Fence the stored row must carry, or null to demand none.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the step carries no update timestamp, which a persisted row requires.
     * @throws  BusinessSchemaConflict  When no row matches the plan, ordinal, and fence together.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the update.
     *
     * @since   2.0.0
     */
    public function replaceStep(SchemaPlanStep $step, ?int $expectedFence): void
    {
        $values = [
            'operation_checksum' => $step->operationChecksum,
            'operation_kind' => $step->operationKind->value,
            'risk' => $step->risk->value,
            'state' => $step->state->value,
            'attempt' => $step->attempt,
            'execution_fence' => $step->executionFence,
            'chunk_cursor' => $step->cursor,
            'before_schema_checksum' => $step->beforeSchemaChecksum,
            'after_schema_checksum' => $step->afterSchemaChecksum,
            'outcome' => $step->outcome,
            'error_code' => $step->errorCode,
            'started_at' => $step->startedAt,
            'completed_at' => $step->completedAt,
            'updated_at' => $step->updatedAt
                ?? throw new RuntimeException('A replaced schema-plan step requires an update timestamp.'),
        ];
        $types = [
            'attempt' => Types::INTEGER,
            'execution_fence' => Types::BIGINT,
            'chunk_cursor' => Types::JSON,
            'outcome' => Types::JSON,
            'started_at' => Types::DATETIME_IMMUTABLE,
            'completed_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ];
        $set = [];
        $parameters = [];
        $parameterTypes = [];
        foreach ($values as $column => $value) {
            $set[] = $column . ' = ?';
            $parameters[] = $value;
            $parameterTypes[] = $types[$column] ?? Types::STRING;
        }
        $parameters[] = $step->planId;
        $parameters[] = $step->ordinal;
        $parameterTypes[] = Types::GUID;
        $parameterTypes[] = Types::INTEGER;
        $fencePredicate = 'execution_fence IS NULL';
        if ($expectedFence !== null) {
            $fencePredicate = 'execution_fence = ?';
            $parameters[] = $expectedFence;
            $parameterTypes[] = Types::BIGINT;
        }
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET %s WHERE plan_id = ? AND ordinal = ? AND %s',
            $this->tables->quoted('business_schema_plan_steps'),
            implode(', ', $set),
            $fencePredicate,
        ), $parameters, $parameterTypes);
        if ($affected !== 1) {
            throw new BusinessSchemaConflict('The schema-plan step fence changed concurrently.');
        }
    }

    /**
     * Project a plan onto the full column set its row is written from.
     *
     * Carries both halves of the row: the canonical document under `canonical_plan`, and the denormalized
     * columns a later read checks back against it. `plan_checksum` is computed from the plan here rather
     * than carried on it, which is what makes the stored digest a fingerprint of what was actually written.
     *
     * @param   SchemaPlan  $plan  Plan to project.
     *
     * @return  array<string, mixed>  Column name to value, covering every column of `business_schema_plans`.
     *
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When the plan cannot be
     *          canonically fingerprinted, as one holding more than 512 operations cannot.
     *
     * @since   2.0.0
     */
    private function values(SchemaPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'definition_id' => $plan->definitionId,
            'site_identifier' => $plan->siteIdentifier,
            'from_definition_version' => $plan->fromDefinitionVersion,
            'to_definition_version' => $plan->toDefinitionVersion,
            'from_definition_checksum' => $plan->fromDefinitionChecksum,
            'to_definition_checksum' => $plan->toDefinitionChecksum,
            'from_schema_checksum' => $plan->fromSchemaChecksum,
            'target_schema_checksum' => $plan->targetSchemaChecksum,
            'plan_checksum' => $plan->checksum(),
            'risk' => $plan->risk->value,
            'status' => $plan->status->value,
            'revision' => $plan->revision,
            'canonical_plan' => $plan->toArray(),
            'created_by' => $plan->createdBy,
            'created_at' => $plan->createdAt,
            'approved_by' => $plan->approval?->actorIdentifier,
            'approved_at' => $plan->approval?->approvedAt,
            'approval_checksum' => $plan->approval?->approvedChecksum,
            'confirmation_digest' => $plan->approval?->confirmationDigest,
            'recovery_evidence_id' => $plan->recoveryEvidenceId,
            'execution_fence' => $plan->executionFence,
            'outcome' => $plan->outcome,
            'updated_at' => $plan->updatedAt,
        ];
    }

    /**
     * Name the DBAL types for the plan columns a driver cannot infer from the PHP value alone.
     *
     * Columns left out bind through DBAL's default handling, which is what the identifiers, checksums, and
     * enumeration values making up the rest of the row want.
     *
     * @return  array<string, string>  Column name to DBAL type constant, covering the integer, big-integer,
     *          JSON, and timestamp columns only.
     *
     * @since   2.0.0
     */
    private function types(): array
    {
        return [
            'from_definition_version' => Types::INTEGER,
            'to_definition_version' => Types::INTEGER,
            'revision' => Types::INTEGER,
            'canonical_plan' => Types::JSON,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'approved_at' => Types::DATETIME_IMMUTABLE,
            'execution_fence' => Types::BIGINT,
            'outcome' => Types::JSON,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ];
    }

    /**
     * Turn a stored JSON column into the string-keyed document it was written from.
     *
     * Drivers disagree over whether a JSON column arrives decoded, so text is decoded here and an
     * already-decoded array is taken as it is. An empty array is accepted — an outcome or a cursor is
     * legitimately `{}` — while any other list is refused.
     *
     * @param   mixed   $value    Raw column value, decoded by the driver or still encoded.
     * @param   string  $subject  What the column holds, named in the message an operator reads.
     *
     * @return  array<string, mixed>  The decoded document.
     *
     * @throws  RuntimeException  When the column is not valid JSON, or decodes to anything other than a
     *          string-keyed object.
     *
     * @since   2.0.0
     */
    private function jsonObject(mixed $value, string $subject): array
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Stored ' . $subject . ' JSON is invalid.', 0, $exception);
            }
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new RuntimeException('Stored ' . $subject . ' must be a JSON object.');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Rebuild a plan from its canonical document and prove the row's other columns still agree with it.
     *
     * `canonical_plan` is the authority; the columns beside it exist so indexes and operator queries do not
     * have to decode JSON. Each is compared back against the rebuilt plan — text byte for byte, integers
     * once the driver's decimal strings are converted, timestamps normalized to UTC microseconds, the
     * outcome by canonical JSON — and the first disagreement refuses the read. That is what stops a row
     * edited directly in SQL from presenting a plan that reads one way and indexes another.
     *
     * @param   array<string, mixed>  $row  One associative row of `business_schema_plans`.
     *
     * @return  SchemaPlan  The plan its canonical document describes, every ledger column verified against it.
     *
     * @throws  RuntimeException  When `canonical_plan` is absent or is not a JSON object, a ledger column
     *          disagrees with the rebuilt plan, or a stored timestamp cannot be read.
     * @throws  \Kumwe\App\BusinessSchema\Domain\InvalidBusinessSchema  When the canonical document is not a
     *          valid plan, or its stored checksum does not match its content.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When the stored plan or a
     *          stored outcome cannot be canonically encoded.
     *
     * @since   2.0.0
     */
    private function mapPlan(array $row): SchemaPlan
    {
        $plan = SchemaPlan::fromArray($this->jsonObject($row['canonical_plan'] ?? null, 'schema plan'));
        $stringChecks = [
            'id' => $plan->id,
            'definition_id' => $plan->definitionId,
            'site_identifier' => $plan->siteIdentifier,
            'from_definition_checksum' => $plan->fromDefinitionChecksum,
            'to_definition_checksum' => $plan->toDefinitionChecksum,
            'from_schema_checksum' => $plan->fromSchemaChecksum,
            'target_schema_checksum' => $plan->targetSchemaChecksum,
            'plan_checksum' => $plan->checksum(),
            'risk' => $plan->risk->value,
            'status' => $plan->status->value,
            'created_by' => $plan->createdBy,
            'approved_by' => $plan->approval?->actorIdentifier,
            'approval_checksum' => $plan->approval?->approvedChecksum,
            'confirmation_digest' => $plan->approval?->confirmationDigest,
            'recovery_evidence_id' => $plan->recoveryEvidenceId,
        ];
        foreach ($stringChecks as $column => $expected) {
            $this->assertLedgerString($row, $column, $expected);
        }
        $integerChecks = [
            'from_definition_version' => $plan->fromDefinitionVersion,
            'to_definition_version' => $plan->toDefinitionVersion,
            'revision' => $plan->revision,
            'execution_fence' => $plan->executionFence,
        ];
        foreach ($integerChecks as $column => $expected) {
            $this->assertLedgerInteger($row, $column, $expected);
        }
        $this->assertLedgerDate($row, 'created_at', $plan->createdAt);
        $this->assertLedgerDate($row, 'approved_at', $plan->approval?->approvedAt);
        $this->assertLedgerDate($row, 'updated_at', $plan->updatedAt);
        $this->assertLedgerDocument($row, 'outcome', $plan->outcome);

        return $plan;
    }

    /**
     * Require a text column to hold exactly what the rebuilt plan says it should.
     *
     * A column the plan leaves empty has to be null in the row as well, which is how an approval or a
     * recovery reference cannot be attached to a row the plan itself does not claim.
     *
     * @param   array<string, mixed>  $row       Driver row being verified.
     * @param   string                $column    Column to compare.
     * @param   ?string               $expected  Text the rebuilt plan carries, or null when it carries none.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the column is absent or not text where a value was expected, holds
     *          different text, or holds anything at all where the plan carries none.
     *
     * @since   2.0.0
     */
    private function assertLedgerString(array $row, string $column, ?string $expected): void
    {
        $actual = $row[$column] ?? null;
        if (
            ($expected === null && $actual !== null)
            || ($expected !== null && (!is_string($actual) || !hash_equals($expected, $actual)))
        ) {
            $this->ledgerMismatch($column);
        }
    }

    /**
     * Require a whole-number column to hold exactly what the rebuilt plan says it should.
     *
     * The comparison happens after the driver's decimal-string form is converted, so a `BIGINT` handed back
     * as text still matches the fence or version the plan carries.
     *
     * @param   array<string, mixed>  $row       Driver row being verified.
     * @param   string                $column    Column to compare.
     * @param   ?int                  $expected  Number the rebuilt plan carries, or null when it carries none.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the column holds something that is neither null nor a whole number, or
     *          a number the plan does not carry.
     *
     * @since   2.0.0
     */
    private function assertLedgerInteger(array $row, string $column, ?int $expected): void
    {
        $actual = $this->nullableInteger($row, $column);
        if ($actual !== $expected) {
            $this->ledgerMismatch($column);
        }
    }

    /**
     * Require a timestamp column to name the same instant the rebuilt plan does.
     *
     * Both sides pass through UTC microsecond normalization before they are compared, so a driver that
     * returns a date object, a different offset, or a different textual form still matches.
     *
     * @param   array<string, mixed>  $row       Driver row being verified.
     * @param   string                $column    Column to compare.
     * @param   ?DateTimeInterface    $expected  Instant the rebuilt plan carries, or null for none.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the column is null where an instant was expected, holds one where the
     *          plan holds none, names a different instant, or cannot be read as a date.
     *
     * @since   2.0.0
     */
    private function assertLedgerDate(array $row, string $column, ?DateTimeInterface $expected): void
    {
        $actual = $row[$column] ?? null;
        if ($expected === null) {
            if ($actual !== null) {
                $this->ledgerMismatch($column);
            }

            return;
        }
        if ($actual === null || $this->ledgerDate($actual) !== $this->ledgerDate($expected)) {
            $this->ledgerMismatch($column);
        }
    }

    /**
     * Require a JSON column to encode the same document the rebuilt plan carries.
     *
     * Compared through canonical JSON rather than raw bytes, so a driver or a migration that re-serialized
     * the column with different key order or spacing does not read as tampering, while a changed value does.
     *
     * @param   array<string, mixed>       $row       Driver row being verified.
     * @param   string                     $column    Column to compare.
     * @param   array<string, mixed>|null  $expected  Document the rebuilt plan carries, or null for none.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the column is null where a document was expected, holds one where the
     *          plan holds none, is not a JSON object, or encodes to different canonical bytes.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When either document holds a
     *          value the canonical encoder refuses, such as a float.
     *
     * @since   2.0.0
     */
    private function assertLedgerDocument(array $row, string $column, ?array $expected): void
    {
        $actual = $row[$column] ?? null;
        if ($expected === null) {
            if ($actual !== null) {
                $this->ledgerMismatch($column);
            }

            return;
        }
        if ($actual === null) {
            $this->ledgerMismatch($column);
        }
        $document = $this->jsonObject($actual, 'schema plan ' . $column);
        if (!hash_equals(CanonicalDefinitionJson::encode($expected), CanonicalDefinitionJson::encode($document))) {
            $this->ledgerMismatch($column);
        }
    }

    /**
     * Render an instant, however the driver typed it, as the UTC text ledger comparisons are made in.
     *
     * A date object and a date string are both accepted. Any other value, and any string that cannot be
     * parsed, surfaces as the same invalid-timestamp fault with the original cause attached, so an operator
     * sees one message for a broken ledger timestamp rather than two.
     *
     * @param   mixed  $value  Stored column value or plan instant to normalize.
     *
     * @return  string  UTC text with the six fractional digits every supported engine preserves.
     *
     * @throws  RuntimeException  When the value is not a date object and cannot be read as a date string.
     *
     * @since   2.0.0
     */
    private function ledgerDate(mixed $value): string
    {
        try {
            if ($value instanceof DateTimeInterface) {
                $date = DateTimeImmutable::createFromInterface($value);
            } elseif (is_string($value)) {
                $date = new DateTimeImmutable($value);
            } else {
                throw new RuntimeException('Stored schema plan contains a non-textual ledger timestamp.');
            }
        } catch (\Throwable $exception) {
            throw new RuntimeException('Stored schema plan contains an invalid ledger timestamp.', 0, $exception);
        }

        // Kumwe's registered temporal types preserve six fractional digits on every supported engine.
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    /**
     * Refuse a row whose ledger column disagrees with the canonical plan beside it.
     *
     * The single exit every ledger assertion takes, so a tampered or half-migrated row always surfaces as
     * one message naming the column that broke rather than as a different fault per column.
     *
     * @param   string  $column  Column that disagreed, named in the message.
     *
     * @return  never
     *
     * @throws  RuntimeException  Always; raising it is the whole purpose of the call.
     *
     * @since   2.0.0
     */
    private function ledgerMismatch(string $column): never
    {
        throw new RuntimeException('Stored schema plan disagrees with its ' . $column . ' ledger value.');
    }

    /**
     * Decode a JSON column the journal schema allows to be null.
     *
     * @param   mixed   $value    Raw column value, decoded by the driver, still encoded, or null.
     * @param   string  $subject  What the column holds, named in the message an operator reads.
     *
     * @return  array<string, mixed>|null  The decoded document, or null when the column holds none.
     *
     * @throws  RuntimeException  When a present column is not valid JSON, or decodes to anything other than
     *          a string-keyed object.
     *
     * @since   2.0.0
     */
    private function nullableJsonObject(mixed $value, string $subject): ?array
    {
        return $value === null ? null : $this->jsonObject($value, $subject);
    }

    /**
     * Read a journal column that has to carry text, refusing an absent or empty one.
     *
     * @param   array<string, mixed>  $row  Driver row being mapped.
     * @param   string                $key  Column to read out of it.
     *
     * @return  string  The stored text, never an empty string.
     *
     * @throws  RuntimeException  When the column is absent, holds a non-string, or holds an empty string.
     *
     * @since   2.0.0
     */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('Stored schema-plan property ' . $key . ' is invalid.');
        }

        return $value;
    }

    /**
     * Read a journal column that carries text or nothing.
     *
     * An empty string is refused rather than folded into null, so a column blanked out by hand is reported
     * instead of read as "never set".
     *
     * @param   array<string, mixed>  $row  Driver row being mapped.
     * @param   string                $key  Column to read out of it.
     *
     * @return  ?string  The stored text, or null when the column holds none.
     *
     * @throws  RuntimeException  When the column holds a non-string, or an empty string.
     *
     * @since   2.0.0
     */
    private function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value !== null && (!is_string($value) || $value === '')) {
            throw new RuntimeException('Stored schema-plan property ' . $key . ' is invalid.');
        }

        return $value;
    }

    /**
     * Read a whole-number column, accepting the decimal text some drivers hand back for it.
     *
     * @param   array<string, mixed>  $row  Driver row being mapped.
     * @param   string                $key  Column to read out of it.
     *
     * @return  int  The stored number, converted from text when the driver did not type it.
     *
     * @throws  RuntimeException  When the column is absent, or holds neither an integer nor a run of digits.
     *
     * @since   2.0.0
     */
    private function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            return (int) $value;
        }
        throw new RuntimeException('Stored schema-plan property ' . $key . ' is invalid.');
    }

    /**
     * Read a whole-number column that the schema allows to be null.
     *
     * Used where the schema leaves the column empty — an execution fence before a run claims the row, a
     * plan with no prior definition version — so absence stays distinguishable from zero.
     *
     * @param   array<string, mixed>  $row  Driver row being mapped.
     * @param   string                $key  Column to read out of it.
     *
     * @return  ?int  The stored number, or null when the column holds none.
     *
     * @throws  RuntimeException  When a present column holds neither an integer nor a run of digits.
     *
     * @since   2.0.0
     */
    private function nullableInteger(array $row, string $key): ?int
    {
        if (($row[$key] ?? null) === null) {
            return null;
        }

        return $this->integer($row, $key);
    }

    /**
     * Render a nullable journal timestamp as the text `SchemaPlanStep::fromArray()` parses.
     *
     * A driver may hand back a date object or the raw column text; an object is formatted with microseconds
     * and its offset, and text passes through untouched.
     *
     * @param   mixed  $value  Raw `started_at`, `completed_at`, or `updated_at` column value.
     *
     * @return  ?string  The timestamp as text, or null when the column holds none.
     *
     * @throws  RuntimeException  When the value is neither null, a date object, nor a non-empty string.
     *
     * @since   2.0.0
     */
    private function nullableStringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('A stored schema-plan timestamp is invalid.');
        }

        return $value;
    }
}
