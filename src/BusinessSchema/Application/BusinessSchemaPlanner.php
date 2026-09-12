<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Application;

use DateTimeImmutable;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\App\BusinessDefinition\Application\DefinitionVersionRecord;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\Expression;
use Kumwe\App\BusinessSchema\Domain\InvalidBusinessSchema;
use Kumwe\App\BusinessSchema\Domain\PhysicalColumnBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalForeignKeyBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalIndexBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalSchemaBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalTableBlueprint;
use Kumwe\App\BusinessSchema\Domain\SchemaOperation;
use Kumwe\App\BusinessSchema\Domain\SchemaOperationKind;
use Kumwe\App\BusinessSchema\Domain\SchemaEvolutionHints;
use Kumwe\App\BusinessSchema\Domain\SchemaPlan;
use Kumwe\App\BusinessSchema\Domain\SchemaPlanStatus;
use Kumwe\App\BusinessSchema\Domain\SchemaPlanStep;
use Kumwe\App\BusinessSchema\Domain\SchemaRisk;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * Compiles what a published business definition would change about its physical schema, and writes it down.
 *
 * This is the only producer of `SchemaPlan` records, and it never touches the live database. It compiles the
 * published version into the blueprint that version installs, diffs that against the blueprint recorded as
 * installed, turns the difference into risk-classified `SchemaOperation` steps, and stores the plan together
 * with one pending journal row per step. Applying any of it is a separate act that `BusinessSchemaExecutor`
 * performs only once an operator has approved the exact checksum produced here.
 *
 * Two entry points share that derivation: `plan()` answers an operator asking for one definition's install
 * or upgrade, and `observePublishedGraph()` is the seam `BusinessDefinitionService` calls from inside its
 * own publication transaction, so definitions published together are planned together. Both are idempotent
 * by content — a plan whose checksum equals the latest one already on record is returned instead of a
 * second one, so a repeated publication leaves the approval queue unchanged — and both refuse a change
 * that would break rows still pinned to an older definition version unless the definition declares the
 * bounded re-pin that carries those rows across. `purgePlan()` stands apart: it composes the destructive
 * teardown of everything a definition installed, which is never folded into an upgrade.
 *
 * @since  2.0.0
 */
final readonly class BusinessSchemaPlanner implements PublishedDefinitionSchemaObserver
{
    /**
     * Sentinel target-schema checksum a purge plan names, since a purge arrives at no blueprint at all.
     *
     * Every plan has to declare the physical schema it ends at, and a completed purge ends with the
     * definition's tables gone, which no `PhysicalSchemaBlueprint` can describe. `BusinessSchemaExecutor`
     * therefore recognises a plan as a purge by comparing its `targetSchemaChecksum` against this value,
     * and reports it as the schema the run arrived at.
     *
     * @var    string
     * @since  2.0.0
     */
    public const PURGED_SCHEMA_CHECKSUM = 'c6cb2eb24aa57518c4c1639014771aa76c3fe4c909b1ff9c18ba7eda35c35e93';

    /**
     * Wire the catalog, compiler, stores, and gateways one planning run reads from and writes to.
     *
     * @param  BusinessDefinitionRepository          $definitions     Catalog the published versions are read from.
     * @param  DefinitionPhysicalSchemaCompiler      $compiler        Turns a version into the tables it installs.
     * @param  BusinessSchemaInstallationRepository  $installations   Records what each definition has installed.
     * @param  BusinessSchemaPlanRepository          $plans           Store the plan and its journal are written to.
     * @param  PhysicalSchemaGateway                 $physicalSchema  Live database, asked about drift and pinned rows.
     * @param  AuthorizationGateway                  $authorization   Guards the two operator-facing entry points.
     * @param  AuditRecorder                         $audit           Trail the operator-facing plans are recorded in.
     * @param  TransactionManager                    $transactions    Scope a whole planning run is persisted in.
     * @param  ClockInterface                        $clock           Source of the instant plans are stamped with.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessDefinitionRepository $definitions,
        private DefinitionPhysicalSchemaCompiler $compiler,
        private BusinessSchemaInstallationRepository $installations,
        private BusinessSchemaPlanRepository $plans,
        private PhysicalSchemaGateway $physicalSchema,
        private AuthorizationGateway $authorization,
        private AuditRecorder $audit,
        private TransactionManager $transactions,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Propose the plan that takes one definition's tables to the version its site currently publishes.
     *
     * The target version is read from the catalog rather than named by the caller, so an operator cannot
     * plan against a version that is not live. Everything is written inside one transaction and audited as
     * `business.schema.plan`; no DDL runs and no row is rewritten here.
     *
     * @param   ExecutionContext  $context       Actor and site the plan is authorized, scoped, and credited to.
     * @param   string            $definitionId  UUID or handle of the definition whose published version to plan.
     *
     * @return  SchemaPlan  A plan awaiting approval, or the identical plan already on record for it.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not exercise
     *          `business.schema.plan` over the business-schema collection.
     * @throws  BusinessSchemaNotFound  When this site publishes no definition under that identifier, or a
     *          handle the definition references resolves to no published version.
     * @throws  BusinessSchemaConflict  When the installed schema belongs to another site, is not older than
     *          the published version, no longer matches the blueprint recorded for it, or an identical plan
     *          is inserted concurrently.
     * @throws  InvalidBusinessSchema  When the definition crosses site scope, its evolution hints do not
     *          describe this evolution, or a narrowing change would reach rows pinned to an older version.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When the derived plan holds
     *          more than 512 operations, which the canonical encoder refuses to fingerprint.
     *
     * @since   2.0.0
     */
    public function plan(ExecutionContext $context, string $definitionId): SchemaPlan
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('business.schema.plan'),
            AuthorizationResource::collection('business_schema'),
        );
        $record = $this->definitions->published($context->site(), $definitionId)
            ?? throw new BusinessSchemaNotFound($definitionId);

        return $this->transactions->transactional(fn (): SchemaPlan => $this->persistPlan(
            $context->site(),
            $record,
            $context->actorId(),
            $this->clock->now(),
            true,
            $this->dependencyBlueprints($record->definition, $context->site()),
        ));
    }

    /**
     * Propose the destructive plan that drops every table one definition has installed.
     *
     * Purging is never a side effect of an upgrade or an uninstall: it is asked for on its own, guarded by
     * `business.schema.destructive` here as well as at approval, and audited as
     * `business.schema.destructive.plan`. The drops are ordered so that `record` goes last and the
     * remaining tables descend by logical name, which retires the tables referencing `record` before the
     * table they point at. Unlike `plan()` this does not reuse an existing plan, so proposing the same
     * purge twice collides on the plan store's uniqueness rule instead.
     *
     * @param   ExecutionContext  $context       Actor and site the purge is authorized, scoped, and credited to.
     * @param   string            $definitionId  UUID of the definition whose installed tables are to be dropped.
     *
     * @return  SchemaPlan  A destructive plan awaiting its own approval and recovery evidence.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not exercise
     *          `business.schema.destructive` over the business-schema collection.
     * @throws  BusinessSchemaNotFound  When this site has nothing installed under that identifier, when the
     *          installation belongs to another site, or when the definition is no longer published.
     * @throws  BusinessSchemaConflict  When an identical purge plan is already stored.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When the plan holds more than
     *          512 operations, which the canonical encoder refuses to fingerprint.
     *
     * @since   2.0.0
     */
    public function purgePlan(ExecutionContext $context, string $definitionId): SchemaPlan
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('business.schema.destructive'),
            AuthorizationResource::collection('business_schema'),
        );
        $installed = $this->installations->find($definitionId)
            ?? throw new BusinessSchemaNotFound($definitionId);
        if ($installed->siteIdentifier !== $context->site()->identifier()) {
            throw new BusinessSchemaNotFound($definitionId);
        }
        $record = $this->definitions->published($context->site(), $definitionId)
            ?? throw new BusinessSchemaNotFound($definitionId);
        $now = $this->clock->now();

        return $this->transactions->transactional(function () use ($context, $record, $installed, $now): SchemaPlan {
            $tables = $installed->blueprint->tables();
            usort($tables, static function (PhysicalTableBlueprint $left, PhysicalTableBlueprint $right): int {
                if ($left->logicalName === 'record') {
                    return 1;
                }
                if ($right->logicalName === 'record') {
                    return -1;
                }

                return strcmp($right->logicalName, $left->logicalName);
            });
            $specifications = [];
            foreach ($tables as $table) {
                $specifications[] = $this->spec(
                    SchemaOperationKind::DropTable,
                    SchemaRisk::Destructive,
                    $table->logicalName,
                    $table->logicalName,
                    $table->toArray(),
                    null,
                    false,
                    'restore_required',
                );
            }
            $operations = $this->number($specifications);
            $plan = new SchemaPlan(
                Uuid::uuid7()->toString(),
                $installed->definitionId,
                $installed->siteIdentifier,
                null,
                $installed->definitionVersion,
                null,
                $installed->definitionChecksum,
                $installed->schemaChecksum,
                self::PURGED_SCHEMA_CHECKSUM,
                $operations,
                SchemaRisk::Destructive,
                SchemaPlanStatus::PendingApproval,
                1,
                $context->actorId(),
                $now,
            );
            $this->plans->save($plan);
            foreach ($operations as $operation) {
                $this->plans->saveStep(SchemaPlanStep::pending($plan->id, $operation, $now));
            }
            $this->audit->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $now,
                $context->actorId(),
                'business.schema.destructive.plan',
                'business_schema_plan',
                $plan->id,
                'success',
                [
                    'definition_id' => $record->definition->id,
                    'plan_checksum' => $plan->checksum(),
                    'operation_count' => count($operations),
                ],
            ));

            return $plan;
        });
    }

    /**
     * Plan every definition of a freshly published graph inside the publisher's own transaction.
     *
     * The whole graph is taken at once because definitions published together reference one another: each
     * one is compiled up front and keyed by handle, so a sibling's blueprint is reused rather than reloaded
     * when it turns up as a dependency, and only handles outside the graph are read back from the catalog —
     * at their currently published version, since a graph is planned as it was published. Definitions are
     * then processed in handle order, which fixes the order the plans come back in. This entry point
     * authorizes nothing and records no audit event of its own; the publication it accompanies has already
     * done both, and its transaction is what discards these plans if that publication fails.
     *
     * @param   SiteContext                    $site             Site every published definition belongs to.
     * @param   list<DefinitionVersionRecord>  $definitions      Versions published in this graph; 1 to 128 of them.
     * @param   string                         $actorIdentifier  Actor credited as the author of the plans.
     * @param   DateTimeImmutable              $now              Instant the plans and journal rows are stamped with.
     *
     * @return  list<SchemaPlan>  One plan per supplied definition in handle order; an unchanged definition
     *          yields the plan already on record rather than a new one.
     *
     * @throws  InvalidBusinessSchema  When the graph is empty or holds more than 128 definitions, one of
     *          them belongs to another site, its evolution hints do not describe this evolution, or a
     *          narrowing change would reach rows pinned to an older version.
     * @throws  BusinessSchemaNotFound  When a handle one definition references is published nowhere on this
     *          site.
     * @throws  BusinessSchemaConflict  When an installed schema belongs to another site, is not older than
     *          the version being published, no longer matches the blueprint recorded for it, or an
     *          identical plan is inserted concurrently.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When a derived plan holds
     *          more than 512 operations, which the canonical encoder refuses to fingerprint.
     *
     * @since   2.0.0
     */
    public function observePublishedGraph(
        SiteContext $site,
        array $definitions,
        string $actorIdentifier,
        DateTimeImmutable $now,
    ): array {
        if ($definitions === [] || count($definitions) > 128) {
            throw new InvalidBusinessSchema('A published definition graph is empty or unbounded.');
        }
        usort($definitions, static fn (DefinitionVersionRecord $left, DefinitionVersionRecord $right): int =>
            strcmp($left->definition->handle, $right->definition->handle));

        return $this->transactions->transactional(function () use (
            $site,
            $definitions,
            $actorIdentifier,
            $now,
        ): array {
            $byHandle = [];
            foreach ($definitions as $record) {
                $byHandle[$record->definition->handle] = $this->compiler->compile($record->definition, $site);
            }
            $result = [];
            foreach ($definitions as $record) {
                $dependencies = [];
                foreach ($this->dependencyHandles($record->definition) as $handle) {
                    $dependencies[] = $byHandle[$handle]
                        ?? $this->compiler->compile(
                            $this->definitions->published($site, $handle)->definition
                                ?? throw new BusinessSchemaNotFound($handle),
                            $site,
                        );
                }
                $result[] = $this->persistPlan(
                    $site,
                    $record,
                    $actorIdentifier,
                    $now,
                    false,
                    $dependencies,
                );
            }

            return $result;
        });
    }

    /**
     * Derive one definition's plan, refuse it if the ground has moved, and store it with its journal.
     *
     * Every entry point funnels through here, which is where the guards that make a plan trustworthy live.
     * The definition may not cross site scope; an installation recorded for another site, or one that is
     * not older than the version being published, is a conflict; and the live schema is re-inspected so a
     * plan is never derived from a blueprint the database no longer matches. Two deduplication paths make
     * replanning harmless — republishing the exact version already installed returns its existing plan, and
     * a freshly derived plan whose checksum matches the latest one on record is dropped in favour of it.
     * Only after both is the plan written, followed by one pending journal row per operation.
     *
     * @param   SiteContext                    $site                  Site the definition must belong to.
     * @param   DefinitionVersionRecord        $record                Published version the schema is moved to.
     * @param   string                         $actorIdentifier       Actor credited as the author of the plan.
     * @param   DateTimeImmutable              $now                   Instant the plan and its journal carry.
     * @param   bool                           $audit                 Whether to record a `business.schema.plan`
     *          event; false where the caller already audits the operation these plans accompany.
     * @param   list<PhysicalSchemaBlueprint>  $dependencyBlueprints  Compiled schemas of the definitions this
     *          one references, in handle order.
     *
     * @return  SchemaPlan  The stored plan, or the equivalent one that was already on record.
     *
     * @throws  InvalidBusinessSchema  When the definition belongs to another site, its evolution hints do
     *          not describe this evolution, or a narrowing change would reach rows pinned to an older
     *          definition version without a bounded re-pin to carry them across.
     * @throws  BusinessSchemaConflict  When the installed metadata belongs to another site, the installed
     *          version is not older than the published one, the live schema has drifted from that metadata,
     *          or an identical plan is inserted concurrently.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When the plan holds more than
     *          512 operations, which the canonical encoder refuses to fingerprint.
     *
     * @since   2.0.0
     */
    private function persistPlan(
        SiteContext $site,
        DefinitionVersionRecord $record,
        string $actorIdentifier,
        DateTimeImmutable $now,
        bool $audit,
        array $dependencyBlueprints = [],
    ): SchemaPlan {
        $definition = $record->definition;
        if ($definition->siteIdentifier !== $site->identifier()) {
            throw new InvalidBusinessSchema('A published schema graph cannot cross site scope.');
        }
        $target = $this->compiler->compile($definition, $site);
        $installed = $this->installations->find($definition->id);
        $prior = null;
        if ($installed !== null) {
            if ($installed->siteIdentifier !== $site->identifier()) {
                throw new BusinessSchemaConflict('Installed schema metadata belongs to another site.');
            }
            if ($installed->definitionVersion >= $definition->definitionVersion) {
                $existing = $this->plans->latestForDefinition($site, $definition->id);
                if (
                    $installed->definitionVersion === $definition->definitionVersion
                    && hash_equals($installed->definitionChecksum, $definition->checksum())
                    && $existing !== null
                ) {
                    return $existing;
                }
                throw new BusinessSchemaConflict('The installed schema is not older than the published definition.');
            }
            $inspected = $this->physicalSchema->inspect($installed->blueprint);
            if ($inspected === null || !hash_equals($inspected->checksum(), $installed->schemaChecksum)) {
                throw new BusinessSchemaConflict('The installed physical schema checksum no longer matches metadata.');
            }
            $prior = $installed->blueprint;
        }
        $operations = $this->operations($prior, $target, $definition, $dependencyBlueprints);
        if (
            $prior !== null && $this->containsPinnedRowBreakingChange($operations)
            && !$this->hasRecordRepin($operations, $definition->definitionVersion)
            && $this->physicalSchema->hasRowsPinnedBefore($prior, $definition->definitionVersion)
        ) {
            throw new InvalidBusinessSchema(
                'Older definition-version rows remain pinned; drop/type replacement requires a bounded re-pin plan.',
            );
        }
        $risk = SchemaRisk::highest(array_map(
            static fn (SchemaOperation $operation): SchemaRisk => $operation->risk,
            $operations,
        ));
        $plan = new SchemaPlan(
            Uuid::uuid7()->toString(),
            $definition->id,
            $site->identifier(),
            $installed?->definitionVersion,
            $definition->definitionVersion,
            $installed?->definitionChecksum,
            $definition->checksum(),
            $installed?->schemaChecksum,
            $target->checksum(),
            $operations,
            $risk,
            SchemaPlanStatus::PendingApproval,
            1,
            $actorIdentifier,
            $now,
        );
        $existing = $this->plans->latestForDefinition($site, $definition->id);
        if ($existing !== null && hash_equals($existing->checksum(), $plan->checksum())) {
            return $existing;
        }
        $this->plans->save($plan);
        foreach ($operations as $operation) {
            $this->plans->saveStep(SchemaPlanStep::pending($plan->id, $operation, $now));
        }
        if ($audit) {
            $this->audit->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $now,
                $actorIdentifier,
                'business.schema.plan',
                'business_schema_plan',
                $plan->id,
                'success',
                [
                    'definition_id' => $definition->id,
                    'target_version' => $definition->definitionVersion,
                    'plan_checksum' => $plan->checksum(),
                    'risk' => $plan->risk->value,
                    'operation_count' => count($operations),
                ],
            ));
        }

        return $plan;
    }

    /**
     * Derive the ordered steps that carry a definition's tables from their installed shape to the target.
     *
     * A first install has no prior blueprint, so each table is created without its referential constraints
     * and every foreign key is added in a second pass; that frees tables which reference each other from
     * any creation order, and `record` is created first with the rest following by logical name. An upgrade
     * instead proves the definition's declared evolution hints against the two blueprints, then emits table
     * drops, table creations, the per-table alterations, and last the re-pin step when the definition
     * re-pins its own records. Drops and creations are sorted by logical name, so the same evolution always
     * numbers its steps the same way and therefore always checksums the same way.
     *
     * @param   ?PhysicalSchemaBlueprint       $prior                 Blueprint recorded as installed, or null
     *          when nothing of this definition is installed yet.
     * @param   PhysicalSchemaBlueprint        $target                Blueprint the published version installs.
     * @param   EntityTypeDefinition           $definition            Published version supplying the evolution
     *          hints and the version a re-pin moves records to.
     * @param   list<PhysicalSchemaBlueprint>  $dependencyBlueprints  Compiled schemas of the definitions this
     *          one references, threaded through from the caller and not consulted by this diff.
     *
     * @return  list<SchemaOperation>  Steps in the order they must run, numbered contiguously from one.
     *
     * @throws  InvalidBusinessSchema  When the definition's compatibility metadata is malformed, a declared
     *          rename, transform, or backfill matches no change in this evolution, a column changes type
     *          without a declared transform, a non-null column arrives without a default or backfill, or an
     *          expression reads a field the prior table does not have.
     *
     * @since   2.0.0
     */
    private function operations(
        ?PhysicalSchemaBlueprint $prior,
        PhysicalSchemaBlueprint $target,
        EntityTypeDefinition $definition,
        array $dependencyBlueprints = [],
    ): array {
        if ($prior === null) {
            $tables = $target->tables();
            usort($tables, static function (PhysicalTableBlueprint $left, PhysicalTableBlueprint $right): int {
                if ($left->logicalName === 'record') {
                    return -1;
                }
                if ($right->logicalName === 'record') {
                    return 1;
                }
                return strcmp($left->logicalName, $right->logicalName);
            });
            $specifications = [];
            foreach ($tables as $table) {
                $withoutKeys = $this->withoutForeignKeys($table);
                $specifications[] = $this->spec(
                    SchemaOperationKind::CreateTable,
                    SchemaRisk::OnlineSafeAdditive,
                    $table->logicalName,
                    $table->logicalName,
                    null,
                    $withoutKeys->toArray(),
                    false,
                    'compensate_safe_addition',
                );
            }
            foreach ($tables as $table) {
                foreach ($table->foreignKeys() as $foreignKey) {
                    $specifications[] = $this->spec(
                        SchemaOperationKind::AddForeignKey,
                        SchemaRisk::BehaviorChanging,
                        $table->logicalName,
                        $foreignKey->logicalName,
                        null,
                        $foreignKey->toArray(),
                        false,
                        'resume_required',
                    );
                }
            }

            return $this->number($specifications);
        }

        $hints = SchemaEvolutionHints::fromDefinition($definition);
        $this->validateEvolutionHints($prior, $target, $definition, $hints);

        $oldTables = $this->tablesByLogical($prior);
        $newTables = $this->tablesByLogical($target);
        $drops = [];
        $creates = [];
        $alters = [];
        foreach (array_diff_key($oldTables, $newTables) as $logical => $table) {
            $drops[] = [
                SchemaOperationKind::DropTable,
                SchemaRisk::Destructive,
                $logical,
                $logical,
                $table->toArray(),
                null,
                false,
                'restore_required',
            ];
        }
        foreach (array_diff_key($newTables, $oldTables) as $logical => $table) {
            $creates[] = [
                SchemaOperationKind::CreateTable,
                SchemaRisk::OnlineSafeAdditive,
                $logical,
                $logical,
                null,
                $table->toArray(),
                false,
                'compensate_safe_addition',
            ];
        }
        foreach (array_intersect_key($newTables, $oldTables) as $logical => $table) {
            array_push($alters, ...$this->tableOperations(
                $oldTables[$logical],
                $table,
                $hints,
            ));
        }
        usort($drops, static fn (array $left, array $right): int => strcmp($left[2], $right[2]));
        usort($creates, static fn (array $left, array $right): int => strcmp($left[2], $right[2]));

        $repin = [];
        if ($hints->repin($definition->handle) !== null) {
            $repin[] = $this->spec(
                SchemaOperationKind::RepinRecords,
                SchemaRisk::BackfillRequired,
                'record',
                'definition_version',
                ['before_version' => $prior->definitionVersion],
                ['definition_version' => $definition->definitionVersion],
                true,
                'resume_required',
            );
        }

        return $this->number([...$drops, ...$creates, ...$alters, ...$repin]);
    }

    /**
     * Diff one table that both blueprints declare into the steps that reshape it.
     *
     * The work is emitted in three phases so that a constraint never stands in the way of the change
     * underneath it: every index and foreign key that is gone, altered, or attached to a column being
     * rewritten is dropped first, the column work then runs against the unconstrained table, and the
     * surviving constraints are recreated last. Two column changes are deliberately expanded rather than
     * attempted in place — a new non-null column becomes add-nullable, backfill, tighten, and a column
     * whose type changes is routed through a shadow column that is added, transformed into, then renamed
     * over the dropped original — so in both cases the row rewrite lands in a `Backfill` or `Transform`
     * step the executor can run in bounded chunks. Tightening an existing column to non-null likewise gets
     * its backfill first.
     *
     * @param   PhysicalTableBlueprint  $prior   The table as the installation records it.
     * @param   PhysicalTableBlueprint  $target  The same logical table as the published version compiles it.
     * @param   SchemaEvolutionHints    $hints   Renames, backfills, and transforms the published version declares.
     *
     * @return  list<array{
     *            SchemaOperationKind,
     *            SchemaRisk,
     *            string,
     *            string,
     *            array<string, mixed>|null,
     *            array<string, mixed>|null,
     *            bool,
     *            string
     *          }>  Unnumbered operation specifications, in the order they must run.
     *
     * @throws  InvalidBusinessSchema  When a declared rename names a column missing from either side, a
     *          column changes type with no transform declared for it, a transform or backfill expression
     *          reads a field the prior table does not have, or a non-null column has neither a canonical
     *          default nor a declared backfill.
     *
     * @since   2.0.0
     */
    private function tableOperations(
        PhysicalTableBlueprint $prior,
        PhysicalTableBlueprint $target,
        SchemaEvolutionHints $hints,
    ): array {
        $dropConstraints = [];
        $columnWork = [];
        $addConstraints = [];
        $oldForeignKeys = $this->foreignKeysByLogical($prior);
        $newForeignKeys = $this->foreignKeysByLogical($target);
        $oldColumnsForTransform = $this->columnsByLogical($prior);
        $newColumnsForTransform = $this->columnsByLogical($target);
        $transformedPhysical = [];
        foreach ($hints->transforms() as $logical => $_expression) {
            if (
                $prior->logicalName === 'record'
                && isset($oldColumnsForTransform[$logical], $newColumnsForTransform[$logical])
                && $oldColumnsForTransform[$logical]->doctrineType !== $newColumnsForTransform[$logical]->doctrineType
            ) {
                $transformedPhysical[] = $oldColumnsForTransform[$logical]->physicalName;
            }
        }
        foreach ($oldForeignKeys as $logical => $key) {
            if (
                !isset($newForeignKeys[$logical])
                || $newForeignKeys[$logical]->toArray() !== $key->toArray()
                || array_intersect($key->localColumns, $transformedPhysical) !== []
            ) {
                $dropConstraints[] = $this->spec(
                    SchemaOperationKind::DropForeignKey,
                    SchemaRisk::BehaviorChanging,
                    $prior->logicalName,
                    $logical,
                    $key->toArray(),
                    null,
                    false,
                    'resume_required',
                );
            }
        }
        $oldIndexes = $this->indexesByLogical($prior);
        $newIndexes = $this->indexesByLogical($target);
        foreach ($oldIndexes as $logical => $index) {
            if (
                !isset($newIndexes[$logical])
                || $newIndexes[$logical]->toArray() !== $index->toArray()
                || array_intersect($index->columns, $transformedPhysical) !== []
            ) {
                $dropConstraints[] = $this->spec(
                    SchemaOperationKind::DropIndex,
                    SchemaRisk::RebuildOrLocking,
                    $prior->logicalName,
                    $logical,
                    $index->toArray(),
                    null,
                    false,
                    'resume_required',
                );
            }
        }

        $oldColumns = $this->columnsByLogical($prior);
        $newColumns = $this->columnsByLogical($target);
        $renamedOld = $hints->renameForTable($prior->logicalName);
        foreach ($renamedOld as $oldLogical => $newLogical) {
            if (!isset($oldColumns[$oldLogical], $newColumns[$newLogical])) {
                throw new InvalidBusinessSchema('A declared schema rename does not match compiled columns.');
            }
            $columnWork[] = $this->spec(
                SchemaOperationKind::RenameColumn,
                SchemaRisk::BehaviorChanging,
                $prior->logicalName,
                $newLogical,
                $oldColumns[$oldLogical]->toArray(),
                $newColumns[$newLogical]->toArray(),
                false,
                'resume_required',
            );
            unset($oldColumns[$oldLogical], $newColumns[$newLogical]);
        }
        foreach (array_diff_key($oldColumns, $newColumns) as $logical => $column) {
            $columnWork[] = $this->spec(
                SchemaOperationKind::DropColumn,
                SchemaRisk::Destructive,
                $prior->logicalName,
                $logical,
                $column->toArray(),
                null,
                false,
                'restore_required',
            );
        }
        foreach (array_diff_key($newColumns, $oldColumns) as $logical => $column) {
            if ($column->nullable) {
                $columnWork[] = $this->spec(
                    SchemaOperationKind::AddColumn,
                    SchemaRisk::OnlineSafeAdditive,
                    $target->logicalName,
                    $logical,
                    null,
                    $column->toArray(),
                    false,
                    'compensate_safe_addition',
                );
                continue;
            }
            $value = $this->backfillValueOrDefault($column, $hints, $logical);
            $nullable = new PhysicalColumnBlueprint(
                $column->logicalName,
                $column->physicalName,
                $column->doctrineType,
                $column->options,
                true,
            );
            $columnWork[] = $this->spec(
                SchemaOperationKind::AddColumn,
                SchemaRisk::OnlineSafeAdditive,
                $target->logicalName,
                $logical,
                null,
                $nullable->toArray(),
                false,
                'compensate_safe_addition',
            );
            $columnWork[] = $this->spec(
                SchemaOperationKind::Backfill,
                SchemaRisk::BackfillRequired,
                $target->logicalName,
                $logical,
                null,
                $this->backfillState($nullable, $value, $oldColumns),
                true,
                'resume_required',
            );
            $columnWork[] = $this->spec(
                SchemaOperationKind::AlterColumn,
                SchemaRisk::BehaviorChanging,
                $target->logicalName,
                $logical,
                $nullable->toArray(),
                $column->toArray(),
                false,
                'resume_required',
            );
        }
        foreach (array_intersect_key($newColumns, $oldColumns) as $logical => $column) {
            $old = $oldColumns[$logical];
            if ($old->toArray() === $column->toArray()) {
                continue;
            }
            if ($old->doctrineType !== $column->doctrineType) {
                $expression = $hints->transform($logical)
                    ?? throw new InvalidBusinessSchema(
                        'A physical type change requires an explicit bounded transform expression.',
                    );
                $shadow = $this->transformShadowColumn($target, $column);
                $dependencies = [];
                foreach ($expression->dependencies() as $dependency) {
                    $dependencyColumn = $oldColumns[$dependency] ?? null;
                    if ($dependencyColumn === null) {
                        throw new InvalidBusinessSchema(
                            'A schema transform references a field unavailable in the prior physical table.',
                        );
                    }
                    $dependencies[$dependency] = $dependencyColumn->toArray();
                }
                ksort($dependencies, SORT_STRING);
                $columnWork[] = $this->spec(
                    SchemaOperationKind::AddColumn,
                    SchemaRisk::OnlineSafeAdditive,
                    $target->logicalName,
                    $logical . '.transform',
                    null,
                    $shadow->toArray(),
                    false,
                    'compensate_safe_addition',
                );
                $columnWork[] = $this->spec(
                    SchemaOperationKind::Transform,
                    SchemaRisk::RebuildOrLocking,
                    $target->logicalName,
                    $logical . '.transform',
                    $old->toArray(),
                    [
                        'source' => $old->toArray(),
                        'target' => $shadow->toArray(),
                        'expression' => $expression->toArray(),
                        'dependencies' => $dependencies,
                        'primary_key' => $prior->primaryKey,
                    ],
                    true,
                    'resume_required',
                );
                $columnWork[] = $this->spec(
                    SchemaOperationKind::DropColumn,
                    SchemaRisk::RebuildOrLocking,
                    $target->logicalName,
                    $logical,
                    $old->toArray(),
                    null,
                    false,
                    'resume_required',
                );
                $columnWork[] = $this->spec(
                    SchemaOperationKind::RenameColumn,
                    SchemaRisk::RebuildOrLocking,
                    $target->logicalName,
                    $logical,
                    $shadow->toArray(),
                    $column->toArray(),
                    false,
                    'resume_required',
                );
                continue;
            }
            if ($old->nullable && !$column->nullable) {
                $value = $this->backfillValueOrDefault($column, $hints, $logical);
                $columnWork[] = $this->spec(
                    SchemaOperationKind::Backfill,
                    SchemaRisk::BackfillRequired,
                    $target->logicalName,
                    $logical,
                    null,
                    $this->backfillState($old, $value, $oldColumns),
                    true,
                    'resume_required',
                );
            }
            $columnWork[] = $this->spec(
                SchemaOperationKind::AlterColumn,
                SchemaRisk::BehaviorChanging,
                $target->logicalName,
                $logical,
                $old->toArray(),
                $column->toArray(),
                false,
                'resume_required',
            );
        }

        foreach ($newIndexes as $logical => $index) {
            if (
                !isset($oldIndexes[$logical])
                || $oldIndexes[$logical]->toArray() !== $index->toArray()
                || array_intersect($index->columns, $transformedPhysical) !== []
            ) {
                $addConstraints[] = $this->spec(
                    SchemaOperationKind::AddIndex,
                    SchemaRisk::RebuildOrLocking,
                    $target->logicalName,
                    $logical,
                    null,
                    $index->toArray(),
                    false,
                    'resume_required',
                );
            }
        }
        foreach ($newForeignKeys as $logical => $key) {
            if (
                !isset($oldForeignKeys[$logical])
                || $oldForeignKeys[$logical]->toArray() !== $key->toArray()
                || array_intersect($key->localColumns, $transformedPhysical) !== []
            ) {
                $addConstraints[] = $this->spec(
                    SchemaOperationKind::AddForeignKey,
                    SchemaRisk::BehaviorChanging,
                    $target->logicalName,
                    $logical,
                    null,
                    $key->toArray(),
                    false,
                    'resume_required',
                );
            }
        }

        return [...$dropConstraints, ...$columnWork, ...$addConstraints];
    }

    /**
     * Turn collected specifications into the numbered operations a plan is allowed to contain.
     *
     * Ordinals come from array position, so the order in which the callers concatenated their
     * specifications is exactly the order the executor will apply them; a plan requires those ordinals to
     * be contiguous from one, which is why numbering happens once, here, after all sorting is done.
     *
     * @param   list<array{
     *            SchemaOperationKind,
     *            SchemaRisk,
     *            string,
     *            string,
     *            array<string, mixed>|null,
     *            array<string, mixed>|null,
     *            bool,
     *            string
     *          }> $specs  Specifications already in execution order.
     *
     * @return  list<SchemaOperation>  The same steps, numbered from one.
     *
     * @since   2.0.0
     */
    private function number(array $specs): array
    {
        $operations = [];
        foreach (array_values($specs) as $offset => $spec) {
            $operations[] = new SchemaOperation($offset + 1, ...$spec);
        }

        return $operations;
    }

    /**
     * Copy a table with its referential constraints stripped off.
     *
     * A first install creates every table this way and adds the foreign keys in a later pass, so a table
     * may be created before the table it points at exists.
     *
     * @param   PhysicalTableBlueprint  $table  Compiled table whose constraints are being deferred.
     *
     * @return  PhysicalTableBlueprint  The same columns, primary key, indexes, and options, with no
     *          foreign keys.
     *
     * @since   2.0.0
     */
    private function withoutForeignKeys(PhysicalTableBlueprint $table): PhysicalTableBlueprint
    {
        return new PhysicalTableBlueprint(
            $table->logicalName,
            $table->physicalName,
            $table->kind,
            $table->columns(),
            $table->primaryKey,
            $table->indexes(),
            [],
            $table->options,
        );
    }

    /**
     * Report whether the plan would change structure that rows on an older definition version still rely on.
     *
     * Dropping a table or a column, renaming a column, and rewriting one through a transform all qualify
     * outright. An alter-column step qualifies too unless it is a pure relaxation, which is the one shape
     * of change that leaves a row written against the older version readable exactly as it stands.
     *
     * @param   list<SchemaOperation>  $operations  Steps derived for the plan.
     *
     * @return  bool  True when at least one step would invalidate a row still pinned to an older version.
     *
     * @since   2.0.0
     */
    private function containsPinnedRowBreakingChange(array $operations): bool
    {
        foreach ($operations as $operation) {
            if (
                in_array(
                    $operation->kind,
                    [
                    SchemaOperationKind::DropTable,
                    SchemaOperationKind::DropColumn,
                    SchemaOperationKind::Transform,
                    SchemaOperationKind::RenameColumn,
                    ],
                    true,
                )
            ) {
                return true;
            }
            if (
                $operation->kind === SchemaOperationKind::AlterColumn
                && !$this->additiveColumnRelaxation($operation)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Report whether the plan re-pins its records onto the published version and leaves nothing behind.
     *
     * A re-pin only counts when it lands on exactly the version being published, since a step aiming at any
     * other version would leave rows the plan is about to break unmigrated. Two shapes disqualify the plan
     * whatever the re-pin says: a table drop, which no re-pin can carry rows through, and a column drop
     * that is not paired with the transform writing its replacement.
     *
     * @param   list<SchemaOperation>  $operations     Steps derived for the plan.
     * @param   int                    $targetVersion  Definition version records must end up pinned to.
     *
     * @return  bool  True only when a matching re-pin is present and every drop in the plan is covered.
     *
     * @since   2.0.0
     */
    private function hasRecordRepin(array $operations, int $targetVersion): bool
    {
        $repin = false;
        foreach ($operations as $operation) {
            if (
                $operation->kind === SchemaOperationKind::RepinRecords
                && ($operation->after['definition_version'] ?? null) === $targetVersion
            ) {
                $repin = true;
            }
            if ($operation->kind === SchemaOperationKind::DropTable) {
                return false;
            }
            if ($operation->kind === SchemaOperationKind::DropColumn) {
                $transform = false;
                foreach ($operations as $candidate) {
                    if (
                        $candidate->kind === SchemaOperationKind::Transform
                        && $candidate->subject === $operation->subject . '.transform'
                    ) {
                        $transform = true;
                        break;
                    }
                }
                if (!$transform) {
                    return false;
                }
            }
        }

        return $repin;
    }

    /**
     * Decide whether an alter-column step only loosens the column, leaving stored values readable as they are.
     *
     * This is the exception that keeps ordinary widening out of the pinned-row guard. Logical name, physical
     * name, and Doctrine type must be untouched, nullability may only relax, a declared length or precision
     * may only grow, and every remaining portable option must be identical. The `default` option is excluded
     * from the comparison because it governs rows written from now on, not rows already stored. A step
     * missing either state is not treated as a relaxation.
     *
     * @param   SchemaOperation  $operation  Alter-column step to classify.
     *
     * @return  bool  True when the change cannot invalidate a row pinned to an older definition version.
     *
     * @since   2.0.0
     */
    private function additiveColumnRelaxation(SchemaOperation $operation): bool
    {
        if ($operation->before === null || $operation->after === null) {
            return false;
        }
        $before = PhysicalColumnBlueprint::fromArray($operation->before);
        $after = PhysicalColumnBlueprint::fromArray($operation->after);
        if (
            $before->logicalName !== $after->logicalName
            || $before->physicalName !== $after->physicalName
            || $before->doctrineType !== $after->doctrineType
            || ($before->nullable && !$after->nullable)
        ) {
            return false;
        }
        $oldOptions = $before->options;
        $newOptions = $after->options;
        unset($oldOptions['default'], $newOptions['default']);
        $oldLength = $oldOptions['length'] ?? null;
        $newLength = $newOptions['length'] ?? null;
        if (is_int($oldLength) && (!is_int($newLength) || $newLength < $oldLength)) {
            return false;
        }
        $oldPrecision = $oldOptions['precision'] ?? null;
        $newPrecision = $newOptions['precision'] ?? null;
        if (is_int($oldPrecision) && (!is_int($newPrecision) || $newPrecision < $oldPrecision)) {
            return false;
        }
        unset($oldOptions['length'], $newOptions['length'], $oldOptions['precision'], $newOptions['precision']);

        return $oldOptions === $newOptions;
    }

    /**
     * List the definition handles this definition points at, from its relationships and reference fields.
     *
     * Fields contribute a handle only when they are `core.entity_reference` or `core.ordered_lines` and
     * name a string target. The result is deduplicated and sorted, so dependencies are always resolved in
     * the same order however the definition happened to be written.
     *
     * @param   EntityTypeDefinition  $definition  Definition version whose outgoing references are wanted.
     *
     * @return  list<string>  Referenced handles in ascending order, each once; empty when it references none.
     *
     * @since   2.0.0
     */
    private function dependencyHandles(EntityTypeDefinition $definition): array
    {
        $handles = [];
        foreach ($definition->relationships() as $relationship) {
            $handles[] = $relationship->target;
        }
        foreach ($definition->fields() as $field) {
            if (!in_array($field->type, ['core.entity_reference', 'core.ordered_lines'], true)) {
                continue;
            }
            $target = $field->configuration['target'] ?? null;
            if (is_string($target)) {
                $handles[] = $target;
            }
        }
        $handles = array_values(array_unique($handles));
        sort($handles, SORT_STRING);

        return $handles;
    }

    /**
     * Compile the physical schema of every definition this one references.
     *
     * A handle the definition re-pins is resolved at exactly the version that re-pin names, so the
     * dependency is read as the plan intends to leave it; every other handle is taken at whatever version
     * the site publishes now. A referenced handle that resolves to nothing stops planning rather than
     * yielding a plan derived from a partial graph.
     *
     * @param   EntityTypeDefinition  $definition  Definition version whose references are being resolved.
     * @param   SiteContext           $site        Site the referenced definitions must be published on.
     *
     * @return  list<PhysicalSchemaBlueprint>  One blueprint per referenced handle, in handle order; empty
     *          when the definition references none.
     *
     * @throws  BusinessSchemaNotFound  When a referenced handle has no published version on this site, or
     *          none at the version a re-pin names.
     * @throws  InvalidBusinessSchema  When the definition's compatibility metadata is malformed, a
     *          referenced handle is not a namespaced definition handle, or a dependency fails to compile.
     *
     * @since   2.0.0
     */
    private function dependencyBlueprints(EntityTypeDefinition $definition, SiteContext $site): array
    {
        $blueprints = [];
        $hints = SchemaEvolutionHints::fromDefinition($definition);
        foreach ($this->dependencyHandles($definition) as $handle) {
            $record = $this->definitions->published($site, $handle, $hints->repin($handle))
                ?? throw new BusinessSchemaNotFound($handle);
            $blueprints[] = $this->compiler->compile($record->definition, $site);
        }

        return $blueprints;
    }

    /**
     * Package one step's arguments in the positional order `SchemaOperation` takes them after its ordinal.
     *
     * Specifications are collected, sorted, and concatenated before anything is numbered, so this stops
     * short of constructing the operation; `number()` supplies the ordinal once the final order is settled.
     *
     * @param   SchemaOperationKind        $kind              Semantic change the gateway must realise.
     * @param   SchemaRisk                 $risk              Impact class this step contributes to the plan.
     * @param   string                     $table             Logical table the step acts on.
     * @param   string                     $subject           Logical object within that table, such as a column
     *          or constraint name.
     * @param   array<string, mixed>|null  $before            State of the subject before the step, or null when
     *          the step only adds.
     * @param   array<string, mixed>|null  $after             State the subject must reach, or null when the step
     *          only removes.
     * @param   bool                       $requiresBackfill  Whether the step rewrites rows rather than shape.
     * @param   string                     $recovery          Recovery implication an interrupted run leaves.
     *
     * @return  array{
     *            SchemaOperationKind,
     *            SchemaRisk,
     *            string,
     *            string,
     *            array<string, mixed>|null,
     *            array<string, mixed>|null,
     *            bool,
     *            string
     *          }  The arguments in constructor order, ready to be spread after an ordinal.
     *
     * @since   2.0.0
     */
    private function spec(
        SchemaOperationKind $kind,
        SchemaRisk $risk,
        string $table,
        string $subject,
        ?array $before,
        ?array $after,
        bool $requiresBackfill,
        string $recovery,
    ): array {
        return [$kind, $risk, $table, $subject, $before, $after, $requiresBackfill, $recovery];
    }

    /**
     * Index a schema's tables by the logical name plan operations address them with.
     *
     * The diff is expressed as `array_diff_key` and `array_intersect_key` over two of these maps, so the
     * keys have to be the logical names; sorting them keeps the resulting drops, creations, and
     * alterations in a stable order.
     *
     * @param   PhysicalSchemaBlueprint  $blueprint  Schema whose tables are being indexed.
     *
     * @return  array<string, PhysicalTableBlueprint>  Tables keyed by logical name, sorted by key.
     *
     * @since   2.0.0
     */
    private function tablesByLogical(PhysicalSchemaBlueprint $blueprint): array
    {
        $result = [];
        foreach ($blueprint->tables() as $table) {
            $result[$table->logicalName] = $table;
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * Index a table's columns by logical name, which is the identity the whole column diff is keyed on.
     *
     * Additions, removals, declared renames, and retypes are all resolved against logical names, so this is
     * the map both sides are compared through; sorting keeps the emitted steps in a stable order.
     *
     * @param   PhysicalTableBlueprint  $table  Table whose columns are being indexed.
     *
     * @return  array<string, PhysicalColumnBlueprint>  Columns keyed by logical name, sorted by key.
     *
     * @since   2.0.0
     */
    private function columnsByLogical(PhysicalTableBlueprint $table): array
    {
        $result = [];
        foreach ($table->columns() as $column) {
            $result[$column->logicalName] = $column;
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * Index a table's indexes and unique constraints by logical name.
     *
     * @param   PhysicalTableBlueprint  $table  Table whose indexes are being indexed.
     *
     * @return  array<string, PhysicalIndexBlueprint>  Indexes keyed by logical name, sorted by key.
     *
     * @since   2.0.0
     */
    private function indexesByLogical(PhysicalTableBlueprint $table): array
    {
        $result = [];
        foreach ($table->indexes() as $index) {
            $result[$index->logicalName] = $index;
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * Index a table's outgoing referential constraints by logical name.
     *
     * @param   PhysicalTableBlueprint  $table  Table whose foreign keys are being indexed.
     *
     * @return  array<string, PhysicalForeignKeyBlueprint>  Constraints keyed by logical name, sorted by key.
     *
     * @since   2.0.0
     */
    private function foreignKeysByLogical(PhysicalTableBlueprint $table): array
    {
        $result = [];
        foreach ($table->foreignKeys() as $key) {
            $result[$key->logicalName] = $key;
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * Read the value the published version declares for filling a column existing rows do not have.
     *
     * Absence is a planning failure rather than a licence to guess: a required column with no declared
     * backfill could only be filled with a value the definition never approved, so planning stops here
     * rather than handing an unfillable step to the executor.
     *
     * @param   SchemaEvolutionHints  $hints          Evolution hints the published version declares.
     * @param   string                $logicalColumn  Logical column the backfill is keyed under.
     *
     * @return  bool|int|string|Expression  The declared literal, or the bounded expression to evaluate per row.
     *
     * @throws  InvalidBusinessSchema  When the column name is not a metadata identifier, no backfill is
     *          declared for it, or the declared value is not an exact scalar or expression.
     *
     * @since   2.0.0
     */
    private function backfillValue(
        SchemaEvolutionHints $hints,
        string $logicalColumn,
    ): bool|int|string|Expression {
        if (!$hints->hasBackfill($logicalColumn)) {
            throw new InvalidBusinessSchema(sprintf(
                'Non-null column %s requires canonical compatibility_metadata.backfills data.',
                $logicalColumn,
            ));
        }
        $value = $hints->backfill($logicalColumn);
        if (!is_bool($value) && !is_int($value) && !is_string($value) && !$value instanceof Expression) {
            throw new InvalidBusinessSchema('A validated schema backfill value became unavailable.');
        }

        return $value;
    }

    /**
     * Choose what a newly required column is filled with, preferring the column's own compiled default.
     *
     * A column that declares a default already says what a row without a value should hold, so the
     * definition only has to declare a backfill for columns that do not.
     *
     * @param   PhysicalColumnBlueprint  $column         Column being added as, or tightened to, non-null.
     * @param   SchemaEvolutionHints     $hints          Evolution hints consulted when there is no default.
     * @param   string                   $logicalColumn  Logical column a declared backfill is keyed under.
     *
     * @return  bool|int|string|Expression  The column's default, or the declared literal or expression.
     *
     * @throws  InvalidBusinessSchema  When the compiled default is not an exact scalar, or the column has no
     *          default and the version declares no usable backfill for it.
     *
     * @since   2.0.0
     */
    private function backfillValueOrDefault(
        PhysicalColumnBlueprint $column,
        SchemaEvolutionHints $hints,
        string $logicalColumn,
    ): bool|int|string|Expression {
        if (!array_key_exists('default', $column->options)) {
            return $this->backfillValue($hints, $logicalColumn);
        }
        $value = $column->options['default'];
        if (!is_bool($value) && !is_int($value) && !is_string($value)) {
            throw new InvalidBusinessSchema('A non-null schema column has an invalid canonical default.');
        }

        return $value;
    }

    /**
     * Assemble the target state a backfill step carries, resolving an expression's inputs to real columns.
     *
     * A literal needs nothing but the column and the value. An expression additionally has to travel with
     * the physical shape of every column it reads, because the gateway builds its statement from this state
     * alone and never re-reads the definition; the dependencies are sorted so the step checksums the same
     * way each time it is derived.
     *
     * @param   PhysicalColumnBlueprint                 $column            Column being filled, in the nullable
     *          shape it holds while the fill runs.
     * @param   bool|int|string|Expression              $value             Literal to write, or the expression
     *          evaluated per row.
     * @param   array<string, PhysicalColumnBlueprint>  $availableColumns  Columns of the source table an
     *          expression may read, keyed by logical name.
     *
     * @return  array<string, mixed>  `column` with `value`, or `column` with `expression` and `dependencies`.
     *
     * @throws  InvalidBusinessSchema  When the expression reads a field the source table does not carry.
     *
     * @since   2.0.0
     */
    private function backfillState(
        PhysicalColumnBlueprint $column,
        bool|int|string|Expression $value,
        array $availableColumns,
    ): array {
        $state = ['column' => $column->toArray()];
        if (!$value instanceof Expression) {
            return [...$state, 'value' => $value];
        }
        $dependencies = [];
        foreach ($value->dependencies() as $logical) {
            $dependency = $availableColumns[$logical] ?? null;
            if ($dependency === null) {
                throw new InvalidBusinessSchema(
                    'A schema backfill Expression references a field unavailable in the source table.',
                );
            }
            $dependencies[$logical] = $dependency->toArray();
        }
        ksort($dependencies, SORT_STRING);

        return [
            ...$state,
            'expression' => $value->toArray(),
            'dependencies' => $dependencies,
        ];
    }

    /**
     * Derive the temporary column a type change converts its values into before taking the real name.
     *
     * The physical name is an `x_` prefix over a digest of the table and target column names, so the same
     * change derives the same name on every compilation, and the plan checksum an approver was bound to
     * survives the executor recompiling the blueprint before it runs. The shadow is always nullable, since
     * it holds nothing until the chunked transform fills it.
     *
     * @param   PhysicalTableBlueprint   $table   Table the column lives on, mixed into the generated name.
     * @param   PhysicalColumnBlueprint  $target  Column in its target shape, supplying the new type and options.
     *
     * @return  PhysicalColumnBlueprint  A nullable column logically named `<column>.transform`.
     *
     * @since   2.0.0
     */
    private function transformShadowColumn(
        PhysicalTableBlueprint $table,
        PhysicalColumnBlueprint $target,
    ): PhysicalColumnBlueprint {
        $physical = 'x_' . substr(hash(
            'sha256',
            $table->physicalName . "\0" . $target->physicalName . "\0transform",
        ), 0, 40);

        return new PhysicalColumnBlueprint(
            $target->logicalName . '.transform',
            $physical,
            $target->doctrineType,
            $target->options,
            true,
        );
    }

    /**
     * Prove every declared evolution hint corresponds to a change this evolution actually makes.
     *
     * A hint that matches nothing is refused rather than ignored, because it was written to authorize a
     * rewrite and quietly dropping it would let the plan run without one. A repin must name either this
     * definition's own newly published version — never a first version, which has no older rows — or a
     * handle the definition genuinely depends on. A rename must name columns present on both sides. A
     * transform must sit on a record column whose type really changes, and a backfill on a record column
     * that is new or newly non-null.
     *
     * @param   PhysicalSchemaBlueprint  $prior       Blueprint recorded as installed.
     * @param   PhysicalSchemaBlueprint  $target      Blueprint the published version installs.
     * @param   EntityTypeDefinition     $definition  Published version whose hints are being proved.
     * @param   SchemaEvolutionHints     $hints       Hints already parsed from that version's metadata.
     *
     * @return  void
     *
     * @throws  InvalidBusinessSchema  When a repin targets a version other than the one being published,
     *          or a handle that is not a declared dependency; when a rename names a table or column absent
     *          from either blueprint; when a transform names a record column whose type is unchanged; or
     *          when a backfill names a record column that is neither new nor newly non-null.
     *
     * @since   2.0.0
     */
    private function validateEvolutionHints(
        PhysicalSchemaBlueprint $prior,
        PhysicalSchemaBlueprint $target,
        EntityTypeDefinition $definition,
        SchemaEvolutionHints $hints,
    ): void {
        $dependencies = $this->dependencyHandles($definition);
        foreach ($hints->repins() as $handle => $version) {
            if ($handle === $definition->handle) {
                if ($version !== $definition->definitionVersion || $definition->definitionVersion < 2) {
                    throw new InvalidBusinessSchema(
                        'A self record repin must target the exact newly published definition version.',
                    );
                }
                continue;
            }
            if (!in_array($handle, $dependencies, true)) {
                throw new InvalidBusinessSchema('A schema repin targets an undeclared definition dependency.');
            }
        }
        foreach ($hints->renames() as $tableLogical => $renames) {
            $oldTable = $prior->table($tableLogical);
            $newTable = $target->table($tableLogical);
            if ($oldTable === null || $newTable === null) {
                throw new InvalidBusinessSchema('A schema rename targets a table unavailable in this evolution.');
            }
            foreach ($renames as $old => $new) {
                if ($oldTable->column($old) === null || $newTable->column($new) === null) {
                    throw new InvalidBusinessSchema('A schema rename does not match prior and target columns.');
                }
            }
        }
        $oldRecord = $prior->table('record');
        $newRecord = $target->table('record');
        foreach ($hints->transforms() as $logical => $_expression) {
            $old = $oldRecord?->column($logical);
            $new = $newRecord?->column($logical);
            if ($old === null || $new === null || $old->doctrineType === $new->doctrineType) {
                throw new InvalidBusinessSchema(
                    'A schema transform must correspond to one explicit record-column type change.',
                );
            }
        }
        foreach ($hints->backfills() as $logical => $_literal) {
            $old = $oldRecord?->column($logical);
            $new = $newRecord?->column($logical);
            if ($new === null || $new->nullable || ($old !== null && !$old->nullable)) {
                throw new InvalidBusinessSchema(
                    'A schema backfill must correspond to one added or newly non-null record column.',
                );
            }
        }
    }
}
