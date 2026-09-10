<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Application;

use DateInterval;
use DateTimeImmutable;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessSchema\Domain\PhysicalSchemaBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalTableBlueprint;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallation;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\App\BusinessSchema\Domain\SchemaOperation;
use Kumwe\App\BusinessSchema\Domain\SchemaOperationKind;
use Kumwe\App\BusinessSchema\Domain\SchemaPlan;
use Kumwe\App\BusinessSchema\Domain\SchemaPlanStatus;
use Kumwe\App\BusinessSchema\Domain\SchemaPlanStep;
use Kumwe\App\BusinessSchema\Domain\SchemaStepStatus;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Applies an approved schema plan to the live database, and is the only thing in Kumwe that may.
 *
 * Planning decides what will happen and approval decides that an operator wants it; this decides that it
 * still may. Before a statement runs, the plan's definition is resolved and rechecksummed, the installed
 * schema is inspected, and any restore drill the risk class demands is re-qualified against this engine
 * and release, so a plan approved against a world that has since moved is refused rather than applied. It
 * then takes the global execution lock and stamps the fence it is issued onto every plan and journal row
 * it writes, which is what stops a run that lost the lock mid-migration from overwriting its successor's
 * work.
 *
 * Progress lives in the durable step journal rather than in the process. Each operation is marked running
 * before it is attempted and completed only once the gateway confirms its postcondition, long row
 * rewrites checkpoint their keyset after every batch, and each settled step extends a hash chain, so a
 * resumed run skips what is already done and continues a half-written rewrite where it stopped. Anything
 * that interrupts a run leaves the plan recovery-required for an operator; only a first install whose
 * completed steps merely created tables is undone automatically. An initial plan whose foreign keys point
 * at tables a peer definition has not created yet stops in that same recovery-required state on purpose,
 * which `isGraphBootstrapPause()` recognises and `BusinessSchemaService` drives to completion.
 *
 * @since  2.0.0
 */
final readonly class BusinessSchemaExecutor
{
    /**
     * Rows one backfill, transform, or re-pin batch may visit before the journal is checkpointed.
     *
     * The bound is what keeps a row rewrite from holding a table for the length of a full scan, and it
     * sets how much work an interruption can cost: at most one batch is repeated.
     *
     * @var    int
     * @since  2.0.0
     */
    private const CHUNK_SIZE = 250;

    /**
     * Chain value a first install starts from, being the SHA-256 digest of the empty string.
     *
     * A plan with no source schema has no prior checksum to chain onto, so its first step is measured
     * against this constant instead of against a predecessor.
     *
     * @var    string
     * @since  2.0.0
     */
    private const EMPTY_SCHEMA_CHECKSUM = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    /**
     * How old a restore drill may be, as an ISO-8601 interval, before this executor stops accepting it.
     *
     * Both the backup and its verification must fall inside the window, so evidence that was fresh at
     * approval can still be refused when the run finally happens.
     *
     * @var    string
     * @since  2.0.0
     */
    private const RECOVERY_MAX_AGE = 'P7D';

    /**
     * Wire the collaborators a run re-verifies, applies, journals, and audits through.
     *
     * @param  BusinessDefinitionRepository              $definitions     Catalog the definition is re-read from.
     * @param  DefinitionPhysicalSchemaCompiler          $compiler        Recompiles the approved blueprint.
     * @param  BusinessSchemaPlanRepository              $plans           Store of plans and their step journal.
     * @param  BusinessSchemaInstallationRepository      $installations   Record of each definition's schema.
     * @param  BusinessSchemaRecoveryEvidenceRepository  $evidence        Store of tested-restore drills.
     * @param  BusinessSchemaExecutionLock               $lock            Serialises runs and issues the fence.
     * @param  BusinessSchemaExecutionStateGuard         $executionState  Locking re-reads before finalizing.
     * @param  PhysicalSchemaGateway                     $physicalSchema  Applies and verifies one operation.
     * @param  BusinessSchemaRecordRepinGateway          $recordRepins    Revalidates rows onto a new version.
     * @param  BusinessSchemaEnvironment                 $environment     Engine and release the run is bound to.
     * @param  AuthorizationGateway                      $authorization   Decides what the actor may do here.
     * @param  AuditRecorder                             $audit           Sink every finished run is logged to.
     * @param  TransactionManager                        $transactions    Commits a write with its justification.
     * @param  ClockInterface                            $clock           Source of every timestamp written here.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessDefinitionRepository $definitions,
        private DefinitionPhysicalSchemaCompiler $compiler,
        private BusinessSchemaPlanRepository $plans,
        private BusinessSchemaInstallationRepository $installations,
        private BusinessSchemaRecoveryEvidenceRepository $evidence,
        private BusinessSchemaExecutionLock $lock,
        private BusinessSchemaExecutionStateGuard $executionState,
        private PhysicalSchemaGateway $physicalSchema,
        private BusinessSchemaRecordRepinGateway $recordRepins,
        private BusinessSchemaEnvironment $environment,
        private AuthorizationGateway $authorization,
        private AuditRecorder $audit,
        private TransactionManager $transactions,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Apply an approved plan for the first time, starting at its first step.
     *
     * Only a plan still standing at approved is accepted. A run that was interrupted has already left
     * that status, so carrying it on is `recover()` — or `resumeGraphBootstrap()` for the one pause an
     * initial graph install takes deliberately.
     *
     * @param   ExecutionContext  $context  Actor and site the run is authorized and scoped for.
     * @param   string            $planId   UUID of the approved plan to apply.
     *
     * @return  SchemaExecutionOutcome  The fence the run held, the steps it applied and skipped, and the
     *          checksum of the schema it left behind.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When `business.schema.execute`,
     *          or `business.schema.destructive` for a destructive plan, is refused.
     * @throws  BusinessSchemaNotFound  When this site holds no plan under that identifier, or the plan's
     *          definition, published version, or recovery evidence can no longer be resolved.
     * @throws  BusinessSchemaConflict  When the plan is not approved, the installed schema or published
     *          definition no longer matches what was approved, or a step fails its postcondition.
     *
     * @since   2.0.0
     */
    public function execute(ExecutionContext $context, string $planId): SchemaExecutionOutcome
    {
        $this->authorize($context, 'business.schema.execute');
        $plan = $this->requiredPlan($context, $planId);
        if ($plan->status !== SchemaPlanStatus::Approved) {
            throw new BusinessSchemaConflict('Only an approved schema plan can execute.');
        }

        return $this->run($context, $plan, false, false);
    }

    /**
     * Re-enter a plan whose execution stopped part way, under the separate operator recovery authority.
     *
     * Accepts a plan left executing by a run that never returned as well as one recorded failed or
     * recovery-required, and puts it back into execution under a freshly issued fence. Steps the journal
     * already records as completed are skipped on their recorded checksums rather than repeated, and the
     * run is audited as a recovery so the trail distinguishes it from an ordinary execution.
     *
     * @param   ExecutionContext  $context  Actor and site the recovery is authorized and scoped for.
     * @param   string            $planId   UUID of the executing, failed, or recovery-required plan.
     *
     * @return  SchemaExecutionOutcome  The same report a first run produces, marked as resumed, with the
     *          steps an earlier attempt had finished counted as skipped.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When `business.schema.recover`,
     *          or `business.schema.destructive` for a destructive plan, is refused.
     * @throws  BusinessSchemaNotFound  When this site holds no plan under that identifier, or the plan's
     *          definition, published version, or recovery evidence can no longer be resolved.
     * @throws  BusinessSchemaConflict  When the plan was never interrupted, its journal disagrees with
     *          the immutable plan, or the resumed run fails a step's postcondition.
     *
     * @since   2.0.0
     */
    public function recover(ExecutionContext $context, string $planId): SchemaExecutionOutcome
    {
        $this->authorize($context, 'business.schema.recover');
        $plan = $this->requiredPlan($context, $planId);
        if (
            !in_array(
                $plan->status,
                [SchemaPlanStatus::Executing, SchemaPlanStatus::Failed, SchemaPlanStatus::RecoveryRequired],
                true,
            )
        ) {
            throw new BusinessSchemaConflict('Only an interrupted schema plan can be recovered.');
        }

        return $this->run($context, $plan, true, true);
    }

    /**
     * Resume an initial plan that stopped only at its foreign keys, under ordinary execute authority.
     *
     * Definitions that reference each other install through separate plans, and tables are created before
     * any foreign key, so the first plan of such a set necessarily fails its foreign-key step while a
     * peer's table is still missing. That is a pause rather than a failure, and this is how it is picked
     * back up without an operator having to hold the recovery capability. `isGraphBootstrapPause()` has to
     * recognise the plan first, so a plan that stopped for any other reason is refused here.
     *
     * @param   ExecutionContext  $context  Actor and site the run is authorized and scoped for.
     * @param   string            $planId   UUID of the paused initial plan to carry on.
     *
     * @return  SchemaExecutionOutcome  The report of the completed install, marked as resumed.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When `business.schema.execute`,
     *          or `business.schema.destructive` for a destructive plan, is refused.
     * @throws  BusinessSchemaNotFound  When this site holds no plan under that identifier, or the plan's
     *          definition, published version, or recovery evidence can no longer be resolved.
     * @throws  BusinessSchemaConflict  When the plan is not stopped at a verified graph-bootstrap pause,
     *          or the resumed run fails a step's postcondition.
     *
     * @since   2.0.0
     */
    public function resumeGraphBootstrap(ExecutionContext $context, string $planId): SchemaExecutionOutcome
    {
        $this->authorize($context, 'business.schema.execute');
        $plan = $this->requiredPlan($context, $planId);
        if (!$this->isGraphBootstrapPause($context, $plan)) {
            throw new BusinessSchemaConflict('Only a verified initial graph pause can resume during execution.');
        }

        return $this->run($context, $plan, true, false);
    }

    /**
     * Report whether a plan is stopped at exactly the deferred-foreign-key pause a graph install produces.
     *
     * This is the only state `resumeGraphBootstrap()` will act on, and `BusinessSchemaService` asks the
     * same question before it drives a set of connected plans, so the test is deliberately exhaustive: the
     * plan must be an initial install for this site awaiting recovery, must still recompile to a target
     * that needs no purge, must have one journal entry per operation, and every one of those entries must
     * be a completed create-table or add-foreign-key whose postcondition still holds, a failed
     * add-foreign-key, or untouched — with at least one foreign key among the failures. Anything that goes
     * wrong while re-deriving the target answers false rather than raising, because the only question
     * being asked is whether resuming would be safe.
     *
     * @param   ExecutionContext  $context  Actor and site the plan must belong to.
     * @param   SchemaPlan        $plan     Plan to inspect, as currently stored.
     *
     * @return  bool  True only when carrying the plan on would do nothing but retry its foreign keys.
     *
     * @since   2.0.0
     */
    public function isGraphBootstrapPause(ExecutionContext $context, SchemaPlan $plan): bool
    {
        if (
            $plan->siteIdentifier !== $context->site()->identifier()
            || $plan->fromSchemaChecksum !== null
            || $plan->status !== SchemaPlanStatus::RecoveryRequired
        ) {
            return false;
        }
        try {
            [$target, $purge] = $this->target($context, $plan, true);
        } catch (Throwable) {
            return false;
        }
        if ($purge) {
            return false;
        }
        $operations = $plan->operations();
        $steps = $this->plans->steps($plan->id);
        if (count($steps) !== count($operations)) {
            return false;
        }
        $foreignKeyFailure = false;
        foreach ($steps as $offset => $step) {
            $operation = $operations[$offset];
            if ($step->state === SchemaStepStatus::Completed) {
                if (
                    !in_array(
                        $operation->kind,
                        [SchemaOperationKind::CreateTable, SchemaOperationKind::AddForeignKey],
                        true,
                    ) || !$this->physicalSchema->operationSatisfied($operation, $target)
                ) {
                    return false;
                }
                continue;
            }
            if (
                $step->state === SchemaStepStatus::Failed
                && $operation->kind === SchemaOperationKind::AddForeignKey
            ) {
                $foreignKeyFailure = true;
                continue;
            }
            if ($step->state !== SchemaStepStatus::Pending) {
                return false;
            }
        }

        return $foreignKeyFailure;
    }

    /**
     * Carry out one execution attempt end to end: re-verify, apply under the lock, and settle the plan.
     *
     * Every entry point funnels here, and the order is the safety property. Everything before the lock is
     * verification that costs nothing to fail; everything inside it is written under the fence the lock
     * issues, so a superseded run's writes are refused by the repository rather than applied. Steps run
     * one at a time against the journal, and the finalization — the installation row, the completed plan,
     * and the audit entry — is committed as one transaction taken after the owner and installation rows
     * have been locked and re-read, so a lifecycle change that arrived during the migration stops the run
     * instead of being overwritten.
     *
     * A failure anywhere parks the plan as recovery-required and audits the attempt as rejected before
     * propagating. Parking is best-effort, so a fence the repository has already superseded cannot replace
     * the failure the caller is about to see. Only a first install that did nothing but create tables is
     * then compensated, by dropping them again and settling the plan as compensated; anything less certain
     * is left recovery-required with every row still in place.
     *
     * @param   ExecutionContext  $context           Actor and site the run is authorized and scoped for.
     * @param   SchemaPlan        $initialPlan       Plan as read before the lock; re-read again under it.
     * @param   bool              $recovery          Whether to resume an interrupted plan rather than
     *          begin an approved one.
     * @param   bool              $operatorRecovery  Whether to audit the run as `business.schema.recover`
     *          rather than `business.schema.execute`.
     *
     * @return  SchemaExecutionOutcome  What this attempt applied and skipped, and the checksum the schema
     *          ended on — the purge sentinel when the plan dropped the installation.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When a destructive plan's
     *          `business.schema.destructive` capability is refused.
     * @throws  BusinessSchemaNotFound  When the definition, its published version, or the recovery
     *          evidence the plan cites cannot be resolved for this site.
     * @throws  BusinessSchemaConflict  When the plan or the world moved after approval, the plan's status
     *          does not admit this attempt, a step fails its postcondition, or the finished schema does
     *          not hash to the approved blueprint.
     *
     * @since   2.0.0
     */
    private function run(
        ExecutionContext $context,
        SchemaPlan $initialPlan,
        bool $recovery,
        bool $operatorRecovery,
    ): SchemaExecutionOutcome {
        if ($initialPlan->risk->value === 'destructive') {
            $this->authorize($context, 'business.schema.destructive');
        }
        [$target, $purge, $ownerIdentifier, $definition] = $this->target($context, $initialPlan, $recovery);
        $this->assertRecoveryEvidence($context, $initialPlan);

        try {
            $outcome = $this->lock->synchronized(
                $initialPlan->definitionId,
                function (int $fence) use (
                    $context,
                    $initialPlan,
                    $target,
                    $purge,
                    $ownerIdentifier,
                    $definition,
                    $recovery,
                    $operatorRecovery,
                ): SchemaExecutionOutcome {
                    $plan = $this->requiredPlan($context, $initialPlan->id);
                    if ($recovery) {
                        if (
                            !in_array(
                                $plan->status,
                                [
                                    SchemaPlanStatus::Executing,
                                    SchemaPlanStatus::Failed,
                                    SchemaPlanStatus::RecoveryRequired,
                                ],
                                true,
                            )
                        ) {
                            throw new BusinessSchemaConflict('The schema plan is no longer recoverable.');
                        }
                        $priorFence = $plan->executionFence;
                        $next = $plan->resume($fence, $this->clock->now());
                        $this->journal(fn () => $this->plans->replace($next, $plan->revision, $priorFence));
                        $plan = $next;
                    } else {
                        if ($plan->status !== SchemaPlanStatus::Approved) {
                            throw new BusinessSchemaConflict('The schema plan changed before lock acquisition.');
                        }
                        $next = $plan->begin($fence, $this->clock->now());
                        $this->journal(fn () => $this->plans->replace($next, $plan->revision));
                        $plan = $next;
                    }

                    try {
                        if ($plan->fromSchemaChecksum !== null) {
                            $source = $this->installations->find($plan->definitionId)
                            ?? throw new BusinessSchemaConflict('The upgrade source installation disappeared.');
                            $this->assertSourceInstallationBinding(
                                $context,
                                $plan,
                                $source,
                                $ownerIdentifier,
                            );
                            $this->prepareSourceInstallation(
                                $context,
                                $source,
                                $ownerIdentifier,
                                $purge,
                            );
                        } else {
                            $partial = $this->installations->find($plan->definitionId);
                            if ($partial !== null) {
                                if (!$recovery) {
                                    throw new BusinessSchemaConflict(
                                        'An initial schema installation appeared after approval.',
                                    );
                                }
                                $this->assertInitialRecoveryInstallation(
                                    $context,
                                    $plan,
                                    $partial,
                                    $ownerIdentifier,
                                    $target,
                                );
                            }
                            $this->prepareInitialInstallation(
                                $context,
                                $plan,
                                $partial,
                                $ownerIdentifier,
                            );
                        }

                        $steps = $this->validatedSteps($plan);
                    } catch (Throwable $failure) {
                        $this->recordInterruption($plan, $fence, $failure);
                        throw $failure;
                    }
                    $completed = 0;
                    $skipped = 0;
                    $chain = $plan->fromSchemaChecksum ?? self::EMPTY_SCHEMA_CHECKSUM;
                    $running = null;
                    $installingRecorded = $this->installations->find($plan->definitionId) !== null;
                    try {
                        foreach ($plan->operations() as $offset => $operation) {
                            $step = $steps[$offset];
                            if ($step->state === SchemaStepStatus::Completed) {
                                $chain = $step->afterSchemaChecksum
                                ?? throw new BusinessSchemaConflict('A completed journal step has no checksum.');
                                ++$skipped;
                                continue;
                            }

                            $expectedFence = $step->executionFence;
                            $step = $step->state === SchemaStepStatus::Pending
                            ? $step->start($fence, $chain, $this->clock->now())
                            : $step->resume($fence, $this->clock->now());
                            $this->journal(fn () => $this->plans->replaceStep($step, $expectedFence));
                            $running = $step;

                            if (
                                $operation->kind === SchemaOperationKind::AddForeignKey
                                && $plan->fromSchemaChecksum === null
                                && !$installingRecorded
                            ) {
                                $partial = $this->withoutForeignKeys($target);
                                $inspectedPartial = $this->physicalSchema->inspect($partial);
                                if (
                                    $inspectedPartial === null
                                    || !hash_equals($inspectedPartial->checksum(), $partial->checksum())
                                ) {
                                    throw new BusinessSchemaConflict(
                                        'Initial entity tables must exist exactly before graph foreign keys '
                                        . 'are deferred.',
                                    );
                                }
                                $now = $this->clock->now();
                                $installing = new SchemaInstallation(
                                    $plan->definitionId,
                                    $plan->siteIdentifier,
                                    $ownerIdentifier,
                                    $plan->toDefinitionVersion,
                                    $plan->toDefinitionChecksum,
                                    $partial->checksum(),
                                    $partial,
                                    SchemaInstallationStatus::Installing,
                                    $now,
                                    $now,
                                );
                                $this->journal(fn () => $this->installations->save($installing));
                                $installingRecorded = true;
                            }

                            $isRewrite = in_array(
                                $operation->kind,
                                [
                                SchemaOperationKind::Backfill,
                                SchemaOperationKind::Transform,
                                SchemaOperationKind::RepinRecords,
                                ],
                                true,
                            );
                            if ($this->environment->databaseDriver() === 'pgsql') {
                                if ($isRewrite) {
                                    [$step, $chain] = $this->rewritePostgres(
                                        $step,
                                        $operation,
                                        $target,
                                        $definition,
                                        $fence,
                                        $chain,
                                    );
                                } else {
                                    [$step, $chain] = $this->ordinaryPostgres(
                                        $step,
                                        $operation,
                                        $target,
                                        $fence,
                                        $chain,
                                    );
                                }
                            } else {
                                $alreadySatisfied = $operation->kind !== SchemaOperationKind::Transform
                                && $this->physicalSchema->operationSatisfied($operation, $target);
                                $processed = 0;
                                if (!$alreadySatisfied) {
                                    if (
                                        in_array($operation->kind, [
                                        SchemaOperationKind::Backfill,
                                        SchemaOperationKind::Transform,
                                        SchemaOperationKind::RepinRecords,
                                        ], true)
                                    ) {
                                        [$step, $processed] = $this->rewrite(
                                            $step,
                                            $operation,
                                            $target,
                                            $definition,
                                            $fence,
                                        );
                                    } else {
                                        $this->physicalSchema->execute($operation, $target);
                                        if (!$this->physicalSchema->operationSatisfied($operation, $target)) {
                                            throw new BusinessSchemaConflict(
                                                'A physical schema operation did not satisfy its approved '
                                                . 'postcondition.',
                                            );
                                        }
                                    }
                                }
                                $chain = $this->nextChain($chain, $operation, $fence, $alreadySatisfied);
                                $step = $step->complete($chain, [
                                'already_satisfied' => $alreadySatisfied,
                                'processed_rows' => $processed,
                                'fence' => $fence,
                                'transactional_ddl' => false,
                                ], $this->clock->now());
                                $this->journal(fn () => $this->plans->replaceStep($step, $fence));
                            }
                            $running = null;
                            ++$completed;
                        }

                        $completedAt = $this->clock->now();
                        $installation = null;
                        if ($purge) {
                            $schemaChecksum = BusinessSchemaPlanner::PURGED_SCHEMA_CHECKSUM;
                        } else {
                            $inspected = $this->physicalSchema->inspect($target);
                            if (
                                $inspected === null
                                || !hash_equals($inspected->checksum(), $plan->targetSchemaChecksum)
                            ) {
                                throw new BusinessSchemaConflict(
                                    'The final physical schema does not match the approved blueprint checksum.',
                                );
                            }
                            $priorInstallation = $this->installations->find($plan->definitionId);
                            $installation = new SchemaInstallation(
                                $plan->definitionId,
                                $plan->siteIdentifier,
                                $ownerIdentifier,
                                $plan->toDefinitionVersion,
                                $plan->toDefinitionChecksum,
                                $target->checksum(),
                                $target,
                                SchemaInstallationStatus::Active,
                                $priorInstallation->installedAt ?? $completedAt,
                                $completedAt,
                            );
                            $schemaChecksum = $target->checksum();
                        }
                        $result = new SchemaExecutionOutcome(
                            $plan->id,
                            $fence,
                            $completed,
                            $skipped,
                            $schemaChecksum,
                            $completedAt,
                            $recovery,
                        );
                        $finished = $plan->complete($result->toArray(), $completedAt);
                        $this->journal(function () use (
                            $purge,
                            $plan,
                            $installation,
                            $finished,
                            $fence,
                            $context,
                            $operatorRecovery,
                            $ownerIdentifier,
                            $result,
                        ): void {
                            $ownerActive = $this->executionState->lockOwner(
                                $context->site(),
                                $plan->definitionId,
                                $ownerIdentifier,
                                false,
                            );
                            $status = $this->executionState->lockInstallationStatus($plan->definitionId);
                            if ($purge) {
                                if (
                                    !in_array($status, [
                                    SchemaInstallationStatus::Active,
                                    SchemaInstallationStatus::Installing,
                                    SchemaInstallationStatus::Disabled,
                                    SchemaInstallationStatus::Preserved,
                                    ], true)
                                ) {
                                    throw new BusinessSchemaConflict(
                                        'The purge source installation changed during execution.',
                                    );
                                }
                                $this->installations->remove($plan->definitionId, $plan->siteIdentifier);
                            } else {
                                $allowedStatus = $plan->fromSchemaChecksum === null
                                ? [null, SchemaInstallationStatus::Installing, SchemaInstallationStatus::Preserved]
                                : [SchemaInstallationStatus::Installing, SchemaInstallationStatus::Preserved];
                                if (!in_array($status, $allowedStatus, true)) {
                                    throw new BusinessSchemaConflict(
                                        'The schema installation lifecycle changed during execution.',
                                    );
                                }
                                $finalInstallation = $installation
                                ?? throw new \LogicException('Schema finalization lost its installation state.');
                                $this->installations->save(
                                    $ownerActive
                                        ? $finalInstallation
                                        : $finalInstallation->preserve($result->completedAt),
                                );
                            }
                            $this->plans->replace($finished, $plan->revision, $fence);
                            $this->audit->record(new AuditEvent(
                                Uuid::uuid7()->toString(),
                                $result->completedAt,
                                $context->actorId(),
                                $operatorRecovery ? 'business.schema.recover' : 'business.schema.execute',
                                'business_schema_plan',
                                $plan->id,
                                'success',
                                $result->toArray(),
                            ));
                        });

                        return $result;
                    } catch (Throwable $failure) {
                        $failedAt = $this->clock->now();
                        if ($running instanceof SchemaPlanStep && $running->state === SchemaStepStatus::Running) {
                            try {
                                $failed = $running->fail('schema_execution_interrupted', [
                                'failure_digest' => hash('sha256', get_class($failure)),
                                'fence' => $fence,
                                ], $failedAt);
                                $this->journal(fn () => $this->plans->replaceStep($failed, $fence));
                            } catch (Throwable) {
                                // A stale fence must never be allowed to overwrite the current executor journal.
                            }
                        }
                        $interrupted = $this->recordInterruption($plan, $fence, $failure, $failedAt);
                        if ($interrupted instanceof SchemaPlan && $this->canCompensateInitial($plan)) {
                            try {
                                $journal = $this->plans->steps($plan->id);
                                $operations = $plan->operations();
                                $created = [];
                                foreach ($journal as $offset => $journalStep) {
                                    if ($journalStep->state === SchemaStepStatus::Completed) {
                                        $created[] = $operations[$offset];
                                    }
                                }
                                foreach (array_reverse($created) as $createdOperation) {
                                    $this->physicalSchema->compensateCreateTable($createdOperation);
                                }
                                $installation = $this->installations->find($plan->definitionId);
                                if ($installation?->status === SchemaInstallationStatus::Installing) {
                                    $this->journal(fn () => $this->installations->remove(
                                        $plan->definitionId,
                                        $plan->siteIdentifier,
                                    ));
                                }
                                $compensated = $interrupted->compensate([
                                'reason' => 'initial_additive_failure',
                                'removed_created_tables' => count($created),
                                'fence' => $fence,
                                ], $this->clock->now());
                                $this->journal(fn () => $this->plans->replace(
                                    $compensated,
                                    $interrupted->revision,
                                    $fence,
                                ));
                            } catch (Throwable) {
                                // Any uncertainty leaves the durable plan recovery-required and preserves all data.
                            }
                        }
                        throw $failure;
                    }
                },
            );
        } catch (Throwable $failure) {
            $failedAt = $this->clock->now();
            $this->journal(fn () => $this->audit->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $failedAt,
                $context->actorId(),
                $operatorRecovery ? 'business.schema.recover' : 'business.schema.execute',
                'business_schema_plan',
                $initialPlan->id,
                'rejected',
                ['failure_digest' => hash('sha256', get_class($failure))],
            )));
            throw $failure;
        }

        return $outcome;
    }

    /**
     * Mark the installation an upgrade or purge starts from as in flight, if its lifecycle still allows it.
     *
     * The definition and installation rows are locked before either is judged and stay locked for the rest
     * of the transaction, so the status this saw is the status the run proceeds on. What counts as
     * acceptable depends on the work: a purge starts from anything but a failed installation, an upgrade
     * under an active owner only from an active or already in-flight one, and an upgrade under an inactive
     * owner only from a withheld or in-flight one. A row already marked installing is left untouched, so a
     * recovering run does not rewrite the marker its own earlier attempt wrote.
     *
     * @param   ExecutionContext    $context          Actor and site the definition row is locked within.
     * @param   SchemaInstallation  $source           Installation the plan was built against, read before
     *          the lock was taken.
     * @param   string              $ownerIdentifier  Owner the plan was approved for; the definition must
     *          still name it.
     * @param   bool                $purge            Whether the plan drops the installation rather than
     *          upgrading it.
     *
     * @return  void
     *
     * @throws  BusinessSchemaConflict  When the locked status differs from the one read before the lock,
     *          the installation's lifecycle does not admit this run, the definition's site or owner has
     *          changed, or the rows cannot be locked.
     *
     * @since   2.0.0
     */
    private function prepareSourceInstallation(
        ExecutionContext $context,
        SchemaInstallation $source,
        string $ownerIdentifier,
        bool $purge,
    ): void {
        $this->journal(function () use ($context, $source, $ownerIdentifier, $purge): void {
            $ownerActive = $this->executionState->lockOwner(
                $context->site(),
                $source->definitionId,
                $ownerIdentifier,
                false,
            );
            $status = $this->executionState->lockInstallationStatus($source->definitionId);
            if ($status !== $source->status) {
                throw new BusinessSchemaConflict('The source installation changed before schema execution.');
            }
            $allowed = $purge
                ? [
                    SchemaInstallationStatus::Active,
                    SchemaInstallationStatus::Installing,
                    SchemaInstallationStatus::Disabled,
                    SchemaInstallationStatus::Preserved,
                ]
                : ($ownerActive
                    ? [SchemaInstallationStatus::Active, SchemaInstallationStatus::Installing]
                    : [
                        SchemaInstallationStatus::Disabled,
                        SchemaInstallationStatus::Preserved,
                        SchemaInstallationStatus::Installing,
                    ]);
            if (!in_array($status, $allowed, true)) {
                throw new BusinessSchemaConflict('The upgrade source installation is not executable.');
            }
            if ($status === SchemaInstallationStatus::Installing) {
                return;
            }
            $this->installations->save(new SchemaInstallation(
                $source->definitionId,
                $source->siteIdentifier,
                $source->ownerIdentifier,
                $source->definitionVersion,
                $source->definitionChecksum,
                $source->schemaChecksum,
                $source->blueprint,
                SchemaInstallationStatus::Installing,
                $source->installedAt,
                $this->clock->now(),
            ));
        });
    }

    /**
     * Confirm a first install may proceed, adopting a partial installation an earlier attempt left behind.
     *
     * The usual case is that there is no installation row at all, and this does nothing but prove that
     * under lock. Where one does exist it is already in flight — a recovering run rejoining its own marker
     * — or it is a preserved row under an inactive owner, which is the only foreign state safe to take
     * over, since anything else means another run or an operator still owns that installation. Adopting it
     * puts it back to installing so the resumed run can finalize it.
     *
     * @param   ExecutionContext     $context          Actor and site the definition row is locked within.
     * @param   SchemaPlan           $plan             Initial plan whose installation is being claimed.
     * @param   ?SchemaInstallation  $partial          Installation found before the lock, or null when the
     *          definition has none.
     * @param   string               $ownerIdentifier  Owner the plan was approved for; the definition must
     *          still name it.
     *
     * @return  void
     *
     * @throws  BusinessSchemaConflict  When the locked status differs from the one read before the lock,
     *          or the partial installation is not one this run may adopt.
     *
     * @since   2.0.0
     */
    private function prepareInitialInstallation(
        ExecutionContext $context,
        SchemaPlan $plan,
        ?SchemaInstallation $partial,
        string $ownerIdentifier,
    ): void {
        $this->journal(function () use ($context, $plan, $partial, $ownerIdentifier): void {
            $ownerActive = $this->executionState->lockOwner(
                $context->site(),
                $plan->definitionId,
                $ownerIdentifier,
                false,
            );
            $status = $this->executionState->lockInstallationStatus($plan->definitionId);
            if ($status !== $partial?->status) {
                throw new BusinessSchemaConflict('The initial installation changed before schema execution.');
            }
            if ($partial === null || $status === SchemaInstallationStatus::Installing) {
                return;
            }
            if ($ownerActive || $status !== SchemaInstallationStatus::Preserved) {
                throw new BusinessSchemaConflict('The partial initial installation is not recoverable.');
            }
            $this->installations->save(new SchemaInstallation(
                $partial->definitionId,
                $partial->siteIdentifier,
                $partial->ownerIdentifier,
                $partial->definitionVersion,
                $partial->definitionChecksum,
                $partial->schemaChecksum,
                $partial->blueprint,
                SchemaInstallationStatus::Installing,
                $partial->installedAt,
                $this->clock->now(),
            ));
        });
    }

    /**
     * Park an interrupted plan as recovery-required so what stopped the run outlives the process.
     *
     * The stored outcome carries a digest of the failure's class rather than its message, and records
     * whether the engine could have committed DDL implicitly — MySQL and MariaDB can — which is what tells
     * an operator whether the journal can still be read as a description of the database. A failure to
     * write is swallowed on purpose: when a fence the repository has already superseded rejects this
     * write, the execution failure the caller is about to see must not be replaced by a bookkeeping one.
     *
     * @param   SchemaPlan          $plan      Plan whose run was interrupted, at the revision this run
     *          last wrote.
     * @param   int                 $fence     Fence this run holds; the stored plan must still carry it.
     * @param   Throwable           $failure   Failure that stopped the run; only its class is recorded.
     * @param   ?DateTimeImmutable  $failedAt  Instant to record, or null to read the clock now.
     *
     * @return  ?SchemaPlan  The parked plan, or null when it could not be written and the caller must not
     *          treat the plan as having moved.
     *
     * @since   2.0.0
     */
    private function recordInterruption(
        SchemaPlan $plan,
        int $fence,
        Throwable $failure,
        ?DateTimeImmutable $failedAt = null,
    ): ?SchemaPlan {
        $failedAt ??= $this->clock->now();
        try {
            $interrupted = $plan->recoveryRequired('schema_execution_interrupted', [
                'failure_digest' => hash('sha256', get_class($failure)),
                'implicit_ddl_possible' => in_array(
                    $this->environment->databaseDriver(),
                    ['mysql', 'mariadb'],
                    true,
                ),
                'fence' => $fence,
            ], $failedAt);
            $this->journal(fn () => $this->plans->replace($interrupted, $plan->revision, $fence));

            return $interrupted;
        } catch (Throwable) {
            // Preserve the authoritative execution failure if persistence fencing already rejected us.
            return null;
        }
    }

    /**
     * Re-derive what this run will act on, and prove the world still matches the plan that was approved.
     *
     * Nothing planning saw is taken on trust. A purge re-resolves the definition's catalog entry and works
     * from the installed blueprint, demanding that the installation still belongs to this site and that
     * owner and still hashes to the checksum the plan starts from. Every other plan reloads the published
     * version, re-checks that it hashes to the approved definition checksum, recompiles it, and re-checks
     * that the result is the approved blueprint checksum. A first attempt additionally measures the live
     * database — an initial plan must find no tables at all, a plan with a source must find no drift — and
     * refuses a narrowing change that would reach rows still pinned to an older definition version with no
     * re-pin step to revalidate them. A recovering attempt skips those live-state checks, since the schema
     * it is recovering is by definition not the one planning measured.
     *
     * @param   ExecutionContext  $context   Actor and site the definition must belong to.
     * @param   SchemaPlan        $plan      Plan about to run.
     * @param   bool              $recovery  Whether to skip the checks that assume the schema is untouched.
     *
     * @return  array{PhysicalSchemaBlueprint, bool, string, ?EntityTypeDefinition}  The blueprint the run
     *          works against, whether it is a purge, the owner the installation is recorded under, and the
     *          published definition a re-pin step needs — null for a purge, which re-pins nothing.
     *
     * @throws  BusinessSchemaNotFound  When this site holds no such definition, or never published the
     *          version the plan installs.
     * @throws  BusinessSchemaConflict  When the published definition, the recompiled blueprint, or the
     *          installed schema no longer matches the plan, an initial plan finds tables already present,
     *          or a narrowing change would reach rows pinned to an older version.
     *
     * @since   2.0.0
     */
    private function target(ExecutionContext $context, SchemaPlan $plan, bool $recovery): array
    {
        $purge = hash_equals($plan->targetSchemaChecksum, BusinessSchemaPlanner::PURGED_SCHEMA_CHECKSUM);
        $installed = $this->installations->find($plan->definitionId);
        if ($purge) {
            $entry = $this->definitions->entry($context->site(), $plan->definitionId)
                ?? throw new BusinessSchemaNotFound($plan->definitionId);
            if (
                $installed === null || $installed->siteIdentifier !== $context->site()->identifier()
                || $installed->ownerIdentifier !== $entry->owner->identifier
                || !hash_equals($installed->schemaChecksum, $plan->fromSchemaChecksum ?? '')
            ) {
                throw new BusinessSchemaConflict('The purge source installation changed after planning.');
            }
            if (!$recovery) {
                $this->assertInstalledPhysicalState($installed);
            }

            return [$installed->blueprint, true, $entry->owner->identifier, null];
        }
        $entry = $this->definitions->entry($context->site(), $plan->definitionId)
            ?? throw new BusinessSchemaNotFound($plan->definitionId);
        $record = $this->definitions->published($context->site(), $plan->definitionId, $plan->toDefinitionVersion)
            ?? throw new BusinessSchemaNotFound($plan->definitionId);
        if (!hash_equals($record->definition->checksum(), $plan->toDefinitionChecksum)) {
            throw new BusinessSchemaConflict('The published definition changed after schema planning.');
        }
        $target = $this->compiler->compile($record->definition, $context->site());
        if (!hash_equals($target->checksum(), $plan->targetSchemaChecksum)) {
            throw new BusinessSchemaConflict('The compiled target blueprint changed after schema planning.');
        }
        if (!$recovery) {
            if ($plan->fromSchemaChecksum === null) {
                if ($installed !== null || $this->physicalSchema->inspect($target) !== null) {
                    throw new BusinessSchemaConflict('An initial plan found an untracked physical installation.');
                }
            } else {
                if ($installed === null || !hash_equals($installed->schemaChecksum, $plan->fromSchemaChecksum)) {
                    throw new BusinessSchemaConflict('The source installation changed after schema planning.');
                }
                $this->assertInstalledPhysicalState($installed);
                if (
                    $this->containsPinnedRowBreakingChange($plan)
                    && !$this->hasRecordRepin($plan)
                    && $this->physicalSchema->hasRowsPinnedBefore($installed->blueprint, $plan->toDefinitionVersion)
                ) {
                    throw new BusinessSchemaConflict(
                        'Older pinned rows appeared after approval; destructive evolution is blocked.',
                    );
                }
            }
        }

        return [$target, false, $record->definition->owner->identifier, $record->definition];
    }

    /**
     * Require the live database to still be exactly the schema the installation row claims is there.
     *
     * Absent tables and drifted ones fail alike, because either way the recorded checksum has stopped
     * describing what exists, and a plan that began from it would be applying its operations to something
     * other than what an operator approved.
     *
     * @param   SchemaInstallation  $installation  Installation whose recorded blueprint and checksum are
     *          being measured against the database.
     *
     * @return  void
     *
     * @throws  BusinessSchemaConflict  When the blueprint's tables are missing, only partly present, or no
     *          longer hash to the recorded checksum.
     *
     * @since   2.0.0
     */
    private function assertInstalledPhysicalState(SchemaInstallation $installation): void
    {
        $inspected = $this->physicalSchema->inspect($installation->blueprint);
        if ($inspected === null || !hash_equals($inspected->checksum(), $installation->schemaChecksum)) {
            throw new BusinessSchemaConflict('The installed physical schema has drifted from its persisted map.');
        }
    }

    /**
     * Require the installation an upgrade found under the lock to be the exact one it was approved against.
     *
     * Run after the lock is taken, so it closes the window between the pre-lock verification and the first
     * statement: an installation swapped, re-owned, moved to another site, or advanced to another version
     * in that gap is refused rather than upgraded. Every source binding the plan names must be present as
     * well as matching, which is what stops a plan carrying no source at all from reaching this path.
     *
     * @param   ExecutionContext    $context          Actor and site the installation must also belong to.
     * @param   SchemaPlan          $plan             Upgrade plan whose source bindings must still hold.
     * @param   SchemaInstallation  $installation     Installation row as read again under the lock.
     * @param   string              $ownerIdentifier  Owner this run derived from the definition catalog.
     *
     * @return  void
     *
     * @throws  BusinessSchemaConflict  When the plan carries no source version, definition checksum, or
     *          schema checksum, or any of its bindings disagrees with the installation.
     *
     * @since   2.0.0
     */
    private function assertSourceInstallationBinding(
        ExecutionContext $context,
        SchemaPlan $plan,
        SchemaInstallation $installation,
        string $ownerIdentifier,
    ): void {
        if (
            $plan->fromDefinitionVersion === null || $plan->fromDefinitionChecksum === null
            || $plan->fromSchemaChecksum === null
            || $installation->definitionId !== $plan->definitionId
            || $installation->siteIdentifier !== $context->site()->identifier()
            || $installation->siteIdentifier !== $plan->siteIdentifier
            || $installation->ownerIdentifier !== $ownerIdentifier
            || $installation->definitionVersion !== $plan->fromDefinitionVersion
            || !hash_equals($installation->definitionChecksum, $plan->fromDefinitionChecksum)
            || !hash_equals($installation->schemaChecksum, $plan->fromSchemaChecksum)
        ) {
            throw new BusinessSchemaConflict(
                'The source installation metadata no longer matches the approved schema plan.',
            );
        }
    }

    /**
     * Require an installation met by a recovering first install to be the graph-pause marker it wrote.
     *
     * An initial plan records an installation at exactly one moment — the deferred-foreign-key pause — and
     * what it records there is the target blueprint with every foreign key stripped out. Demanding that
     * precise shape, on top of the plan's own definition, site, owner, version, and definition checksum,
     * is what stops a recovery from adopting an installation some other plan or an operator left behind.
     *
     * @param   ExecutionContext         $context          Actor and site the installation must belong to.
     * @param   SchemaPlan               $plan             Initial plan being recovered.
     * @param   SchemaInstallation       $installation     Installation found for the plan's definition.
     * @param   string                   $ownerIdentifier  Owner this run derived from the catalog.
     * @param   PhysicalSchemaBlueprint  $target           Blueprint the plan compiles to; compared against
     *          with its foreign keys removed.
     *
     * @return  void
     *
     * @throws  BusinessSchemaConflict  When the installation names another definition, site, owner, or
     *          version, is not installing or preserved, or does not carry the foreign-key-free blueprint.
     *
     * @since   2.0.0
     */
    private function assertInitialRecoveryInstallation(
        ExecutionContext $context,
        SchemaPlan $plan,
        SchemaInstallation $installation,
        string $ownerIdentifier,
        PhysicalSchemaBlueprint $target,
    ): void {
        $partial = $this->withoutForeignKeys($target);
        if (
            $installation->definitionId !== $plan->definitionId
            || $installation->siteIdentifier !== $context->site()->identifier()
            || $installation->siteIdentifier !== $plan->siteIdentifier
            || $installation->ownerIdentifier !== $ownerIdentifier
            || $installation->definitionVersion !== $plan->toDefinitionVersion
            || !hash_equals($installation->definitionChecksum, $plan->toDefinitionChecksum)
            || !in_array($installation->status, [
                SchemaInstallationStatus::Installing,
                SchemaInstallationStatus::Preserved,
            ], true)
            || !hash_equals($installation->schemaChecksum, $partial->checksum())
            || !hash_equals($installation->blueprint->checksum(), $partial->checksum())
        ) {
            throw new BusinessSchemaConflict(
                'The partial initial installation metadata does not match the recoverable plan state.',
            );
        }
    }

    /**
     * Project a blueprint onto the shape it has once its tables exist but none of its foreign keys do.
     *
     * That is precisely the state an initial graph install reaches when it pauses, so this one projection
     * serves both sides of the pause: it is the blueprint recorded on the in-flight installation, and the
     * blueprint a recovering run compares that installation against.
     *
     * @param   PhysicalSchemaBlueprint  $blueprint  Compiled target blueprint to strip.
     *
     * @return  PhysicalSchemaBlueprint  The same tables, columns, primary keys, indexes, and options,
     *          carrying no foreign keys, and so a different checksum whenever the original declared any.
     *
     * @since   2.0.0
     */
    private function withoutForeignKeys(PhysicalSchemaBlueprint $blueprint): PhysicalSchemaBlueprint
    {
        return new PhysicalSchemaBlueprint(
            $blueprint->definitionId,
            $blueprint->definitionVersion,
            $blueprint->definitionChecksum,
            array_map(
                static fn (PhysicalTableBlueprint $table): PhysicalTableBlueprint => new PhysicalTableBlueprint(
                    $table->logicalName,
                    $table->physicalName,
                    $table->kind,
                    $table->columns(),
                    $table->primaryKey,
                    $table->indexes(),
                    [],
                    $table->options,
                ),
                $blueprint->tables(),
            ),
        );
    }

    /**
     * Re-prove that the restore drill a rebuilding or destructive plan was approved on still qualifies.
     *
     * Approval already checked this evidence, but the gap between approving a plan and running it can be
     * long, so it is checked again here against the moment the run begins. The drill must name this site,
     * this engine and server version, this application release, and the very schema checksum the plan
     * starts from, and both the backup and its verification must fall inside `RECOVERY_MAX_AGE`. Risk
     * classes that need no evidence return immediately.
     *
     * @param   ExecutionContext  $context  Actor and site the evidence must have been recorded for.
     * @param   SchemaPlan        $plan     Plan whose risk decides whether evidence is needed at all.
     *
     * @return  void
     *
     * @throws  BusinessSchemaNotFound  When this site holds no evidence under the identifier the plan
     *          cites.
     * @throws  BusinessSchemaConflict  When a plan that needs evidence carries none or has no source
     *          schema to bind it to, or the drill is stale or was run against another site, engine,
     *          server version, release, or schema.
     *
     * @since   2.0.0
     */
    private function assertRecoveryEvidence(ExecutionContext $context, SchemaPlan $plan): void
    {
        if (!$plan->risk->requiresRecoveryEvidence()) {
            return;
        }
        if ($plan->fromSchemaChecksum === null || $plan->recoveryEvidenceId === null) {
            throw new BusinessSchemaConflict('The plan lacks source-bound recovery evidence.');
        }
        $evidence = $this->evidence->find($context->site(), $plan->recoveryEvidenceId)
            ?? throw new BusinessSchemaNotFound($plan->recoveryEvidenceId);
        $notBefore = $this->clock->now()->sub(new DateInterval(self::RECOVERY_MAX_AGE));
        if (
            !$evidence->qualifies(
                $context->site()->identifier(),
                $this->environment->databaseDriver(),
                $this->environment->databaseServerVersion(),
                $this->environment->applicationRelease(),
                $plan->fromSchemaChecksum,
                $notBefore,
            )
        ) {
            throw new BusinessSchemaConflict('Recovery evidence is stale or does not match this release and engine.');
        }
    }

    /**
     * Load the plan's journal and prove it still describes the immutable plan, entry for entry.
     *
     * A resumed run trusts the journal instead of the database, so an entry that no longer matches its
     * operation's content address would let it skip a step that was never applied. Entries are compared by
     * position, since both the journal and the plan's operations are in ordinal order.
     *
     * @param   SchemaPlan  $plan  Plan whose execution journal is being loaded.
     *
     * @return  list<SchemaPlanStep>  One entry per operation in ordinal order, index-aligned with
     *          `operations()` so a step's offset is one below its ordinal.
     *
     * @throws  BusinessSchemaConflict  When the journal holds a different number of entries from the plan,
     *          or an entry's ordinal, content address, kind, or risk disagrees with its operation.
     *
     * @since   2.0.0
     */
    private function validatedSteps(SchemaPlan $plan): array
    {
        $steps = $this->plans->steps($plan->id);
        $operations = $plan->operations();
        if (count($steps) !== count($operations)) {
            throw new BusinessSchemaConflict('The persisted execution journal is incomplete.');
        }
        foreach ($operations as $offset => $operation) {
            $step = $steps[$offset];
            if (
                $step->ordinal !== $operation->ordinal
                || !hash_equals($step->operationChecksum, $operation->checksum())
                || $step->operationKind !== $operation->kind
                || $step->risk !== $operation->risk
            ) {
                throw new BusinessSchemaConflict('The execution journal disagrees with the immutable plan.');
            }
        }

        return $steps;
    }

    /**
     * Drive a row-rewriting step to the end in bounded batches, checkpointing the journal after each one.
     *
     * This is the non-transactional path, taken on MySQL and MariaDB: every batch commits on its own and
     * its keyset is written to the journal before the next begins, so an interruption costs one batch
     * rather than the whole rewrite. A batch that reports neither completion nor progress is treated as a
     * stalled rewrite instead of being looped on forever. The closing postcondition check is skipped for a
     * transform, which rewrites values rather than shape and leaves the gateway nothing to observe.
     *
     * @param   SchemaPlanStep           $step        Journal entry for this operation, already running.
     * @param   SchemaOperation          $operation   Approved backfill, transform, or re-pin step to run.
     * @param   PhysicalSchemaBlueprint  $target      Blueprint the operation's names resolve against.
     * @param   ?EntityTypeDefinition    $definition  Published definition a re-pin revalidates rows onto;
     *          null only for a purge, which never re-pins.
     * @param   int                      $fence       Fence this run holds; every checkpoint is written
     *          under it.
     *
     * @return  array{SchemaPlanStep, int}  The journal entry as last checkpointed, still running, and the
     *          rows rewritten across every batch of this call.
     *
     * @throws  BusinessSchemaConflict  When a batch reports neither progress nor completion, or the
     *          finished rewrite leaves the operation's postcondition unsatisfied.
     *
     * @since   2.0.0
     */
    private function rewrite(
        SchemaPlanStep $step,
        SchemaOperation $operation,
        PhysicalSchemaBlueprint $target,
        ?EntityTypeDefinition $definition,
        int $fence,
    ): array {
        $processed = 0;
        do {
            $chunk = $this->rewriteChunk($operation, $target, $definition, $step->cursor);
            $processed += $chunk->processed;
            if ($chunk->complete) {
                break;
            }
            if ($chunk->processed === 0 || $chunk->cursor === null) {
                throw new BusinessSchemaConflict('A chunked schema rewrite made no durable progress.');
            }
            $step = $step->checkpoint($chunk->cursor, $this->clock->now());
            $this->journal(fn () => $this->plans->replaceStep($step, $fence));
        } while (true);

        if (
            $operation->kind !== SchemaOperationKind::Transform
            && !$this->physicalSchema->operationSatisfied($operation, $target)
        ) {
            throw new BusinessSchemaConflict('A schema rewrite completed without satisfying its postcondition.');
        }

        return [$step, $processed];
    }

    /**
     * Apply one shape-changing step and journal its completion in a single PostgreSQL transaction.
     *
     * PostgreSQL commits DDL transactionally, so the statement, the check that it took effect, and the
     * journal entry recording it either all land or none do — this path never has to ask afterwards
     * whether an interrupted step got through. A step whose postcondition already holds is journalled as
     * already satisfied rather than reapplied, which is what makes a resumed run idempotent.
     *
     * @param   SchemaPlanStep           $step       Journal entry for this operation, already running.
     * @param   SchemaOperation          $operation  Approved shape-changing step to apply.
     * @param   PhysicalSchemaBlueprint  $target     Blueprint the operation's names resolve against.
     * @param   int                      $fence      Fence this run holds; the journal entry must carry it.
     * @param   string                   $chain      Chain value the preceding steps produced.
     *
     * @return  array{SchemaPlanStep, string}  The completed journal entry, and the chain value this step
     *          extended it to, which the next step measures itself from.
     *
     * @throws  BusinessSchemaConflict  When the statement runs but leaves the postcondition unsatisfied.
     *
     * @since   2.0.0
     */
    private function ordinaryPostgres(
        SchemaPlanStep $step,
        SchemaOperation $operation,
        PhysicalSchemaBlueprint $target,
        int $fence,
        string $chain,
    ): array {
        return $this->journal(function () use ($step, $operation, $target, $fence, $chain): array {
            $alreadySatisfied = $this->physicalSchema->operationSatisfied($operation, $target);
            if (!$alreadySatisfied) {
                $this->physicalSchema->execute($operation, $target);
                if (!$this->physicalSchema->operationSatisfied($operation, $target)) {
                    throw new BusinessSchemaConflict(
                        'A PostgreSQL DDL operation did not satisfy its transactional postcondition.',
                    );
                }
            }
            $nextChain = $this->nextChain($chain, $operation, $fence, $alreadySatisfied);
            $completed = $step->complete($nextChain, [
                'already_satisfied' => $alreadySatisfied,
                'processed_rows' => 0,
                'fence' => $fence,
                'transactional_ddl' => true,
            ], $this->clock->now());
            $this->plans->replaceStep($completed, $fence);

            return [$completed, $nextChain];
        });
    }

    /**
     * Drive a row-rewriting step in batches where the rows and the checkpoint commit together.
     *
     * The PostgreSQL counterpart to `rewrite()`: each batch and the journal entry recording where it
     * stopped are written in one transaction, so a batch either happened and is recorded or did neither.
     * The step counts as already satisfied only when the very first batch found nothing left to do; once
     * any rows have been rewritten the completion is journalled as applied, which keeps the chain value
     * honest about whether this run touched the data.
     *
     * @param   SchemaPlanStep           $step        Journal entry for this operation, already running.
     * @param   SchemaOperation          $operation   Approved backfill, transform, or re-pin step to run.
     * @param   PhysicalSchemaBlueprint  $target      Blueprint the operation's names resolve against.
     * @param   ?EntityTypeDefinition    $definition  Published definition a re-pin revalidates rows onto;
     *          null only for a purge, which never re-pins.
     * @param   int                      $fence       Fence this run holds; every write demands it.
     * @param   string                   $chain       Chain value the preceding steps produced.
     *
     * @return  array{SchemaPlanStep, string}  The completed journal entry, and the chain value this step
     *          extended it to, which the next step measures itself from.
     *
     * @throws  BusinessSchemaConflict  When a batch reports neither progress nor completion, or the
     *          finished rewrite leaves the operation's postcondition unsatisfied.
     *
     * @since   2.0.0
     */
    private function rewritePostgres(
        SchemaPlanStep $step,
        SchemaOperation $operation,
        PhysicalSchemaBlueprint $target,
        ?EntityTypeDefinition $definition,
        int $fence,
        string $chain,
    ): array {
        $processed = 0;
        do {
            [$step, $chunkProcessed, $complete, $nextChain] = $this->journal(
                function () use ($step, $operation, $target, $definition, $fence, $chain, $processed): array {
                    if (
                        $operation->kind !== SchemaOperationKind::Transform
                        && $this->physicalSchema->operationSatisfied($operation, $target)
                    ) {
                        $chunk = new SchemaChunkResult($step->cursor, 0, true);
                        $alreadySatisfied = $processed === 0;
                    } else {
                        $chunk = $this->rewriteChunk($operation, $target, $definition, $step->cursor);
                        $alreadySatisfied = false;
                    }
                    if (!$chunk->complete) {
                        if ($chunk->processed === 0 || $chunk->cursor === null) {
                            throw new BusinessSchemaConflict(
                                'A transactional schema rewrite made no durable keyset progress.',
                            );
                        }
                        $checkpoint = $step->checkpoint($chunk->cursor, $this->clock->now());
                        $this->plans->replaceStep($checkpoint, $fence);

                        return [$checkpoint, $chunk->processed, false, $chain];
                    }
                    if (
                        $operation->kind !== SchemaOperationKind::Transform
                        && !$this->physicalSchema->operationSatisfied($operation, $target)
                    ) {
                        throw new BusinessSchemaConflict(
                            'A PostgreSQL schema rewrite failed its transactional postcondition.',
                        );
                    }
                    $resultChain = $this->nextChain($chain, $operation, $fence, $alreadySatisfied);
                    $completed = $step->complete($resultChain, [
                        'already_satisfied' => $alreadySatisfied,
                        'processed_rows' => $processed + $chunk->processed,
                        'fence' => $fence,
                        'transactional_ddl' => true,
                    ], $this->clock->now());
                    $this->plans->replaceStep($completed, $fence);

                    return [$completed, $chunk->processed, true, $resultChain];
                },
            );
            $processed += $chunkProcessed;
            if ($complete) {
                return [$step, $nextChain];
            }
        } while (true);
    }

    /**
     * Hand one bounded batch of a row rewrite to the gateway that owns that kind of rewrite.
     *
     * Backfills and transforms belong to the physical schema gateway, re-pins to the record store, since
     * only the record side knows how a definition's values are encoded into columns. Anything that does
     * not rewrite rows has no business here and is refused rather than quietly treated as complete.
     *
     * @param   SchemaOperation                      $operation   Rewrite step whose kind picks the gateway.
     * @param   PhysicalSchemaBlueprint              $target      Blueprint the operation's names resolve against.
     * @param   ?EntityTypeDefinition                $definition  Published definition a re-pin revalidates rows onto.
     * @param   array<string, bool|int|string>|null  $cursor      Keyset the previous batch reached, or null to
     *          start from the beginning.
     *
     * @return  SchemaChunkResult  What this batch rewrote, whether anything is left, and where a further
     *          batch resumes.
     *
     * @throws  BusinessSchemaConflict  When a re-pin step arrives without the definition it re-pins onto,
     *          or an operation that rewrites no rows reaches the chunked path.
     *
     * @since   2.0.0
     */
    private function rewriteChunk(
        SchemaOperation $operation,
        PhysicalSchemaBlueprint $target,
        ?EntityTypeDefinition $definition,
        ?array $cursor,
    ): SchemaChunkResult {
        return match ($operation->kind) {
            SchemaOperationKind::Backfill => $this->physicalSchema->backfillChunk(
                $operation,
                $target,
                $cursor,
                self::CHUNK_SIZE,
            ),
            SchemaOperationKind::Transform => $this->physicalSchema->transformChunk(
                $operation,
                $target,
                $cursor,
                self::CHUNK_SIZE,
            ),
            SchemaOperationKind::RepinRecords => $this->recordRepins->repinChunk(
                $definition ?? throw new BusinessSchemaConflict('A record-repin operation has no definition.'),
                $operation,
                $target,
                $cursor,
                self::CHUNK_SIZE,
            ),
            default => throw new BusinessSchemaConflict('A non-rewrite operation entered chunked execution.'),
        };
    }

    /**
     * Extend the execution chain with the step that has just been settled.
     *
     * Each completed journal entry stores the value returned here, so the journal carries a hash chain
     * over what ran, in which order, under which fence, and whether the step was applied or found already
     * done. A resumed run picks the chain up from the last completed entry rather than recomputing it,
     * which is what makes a journal that was written by two different runs still verifiable as one.
     *
     * @param   string           $chain             Chain value the preceding steps produced.
     * @param   SchemaOperation  $operation         Step being folded in, by its content address.
     * @param   int              $fence             Fence the step was settled under.
     * @param   bool             $alreadySatisfied  Whether the step was found already applied rather than
     *          executed by this run.
     *
     * @return  string  Lowercase SHA-256 digest the next step chains from.
     *
     * @since   2.0.0
     */
    private function nextChain(
        string $chain,
        SchemaOperation $operation,
        int $fence,
        bool $alreadySatisfied,
    ): string {
        return hash('sha256', implode("\0", [
            $chain,
            $operation->checksum(),
            (string) $fence,
            $alreadySatisfied ? 'already-satisfied' : 'applied',
        ]));
    }

    /**
     * Decide whether a failed run may undo itself by dropping the tables it created.
     *
     * Automatic compensation is allowed only where undoing the work cannot lose anything: a first install,
     * not destructive, every completed step of which created a table the planner declared safe to remove.
     * A plan carrying a foreign key is excluded even then, because that is the graph bootstrap, whose
     * empty in-flight tables are deliberately left standing for its peers to reference. A run that
     * completed nothing has nothing to compensate and is refused too, so the plan stays recovery-required.
     *
     * @param   SchemaPlan  $plan  Plan whose interrupted run is being considered for compensation.
     *
     * @return  bool  True only when at least one step completed and every completed step created a table
     *          the plan marked as a safe addition.
     *
     * @since   2.0.0
     */
    private function canCompensateInitial(SchemaPlan $plan): bool
    {
        if ($plan->fromSchemaChecksum !== null || $plan->risk->value === 'destructive') {
            return false;
        }
        $operations = $plan->operations();
        foreach ($operations as $operation) {
            if ($operation->kind === SchemaOperationKind::AddForeignKey) {
                // A graph bootstrap may intentionally preserve its empty Installing table for peer plans.
                return false;
            }
        }
        $steps = $this->plans->steps($plan->id);
        $created = 0;
        foreach ($steps as $offset => $step) {
            if ($step->state !== SchemaStepStatus::Completed) {
                continue;
            }
            $operation = $operations[$offset] ?? null;
            if (
                $operation === null
                || $operation->kind !== SchemaOperationKind::CreateTable
                || $operation->recoveryImplication !== 'compensate_safe_addition'
            ) {
                return false;
            }
            ++$created;
        }

        return $created > 0;
    }

    /**
     * Report whether the plan narrows the schema in a way rows pinned to an older version may not survive.
     *
     * Dropping a table or column, renaming a column, transforming values, and any column change that is
     * not a pure relaxation all leave values an earlier definition accepted with nowhere valid to live.
     * Answering true is what makes the executor demand a re-pin step before the plan may touch a table
     * that still holds rows validated under an older version.
     *
     * @param   SchemaPlan  $plan  Plan whose operations are inspected.
     *
     * @return  bool  True as soon as one operation is unsafe for rows pinned to an earlier version.
     *
     * @since   2.0.0
     */
    private function containsPinnedRowBreakingChange(SchemaPlan $plan): bool
    {
        foreach ($plan->operations() as $operation) {
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
     * Report whether the plan revalidates its stored rows onto the version it installs, stranding none.
     *
     * A re-pin only counts when it names this plan's own target version, and it is not sufficient on its
     * own: a table drop takes the rows with it, and a column drop is accepted only when a transform on
     * `<subject>.transform` moves the values across first. Either of those without its partner answers
     * false however many re-pin steps the plan also carries.
     *
     * @param   SchemaPlan  $plan  Plan whose operations are inspected.
     *
     * @return  bool  True when the plan re-pins rows onto its target version and drops nothing that would
     *          leave values behind unmigrated.
     *
     * @since   2.0.0
     */
    private function hasRecordRepin(SchemaPlan $plan): bool
    {
        $operations = $plan->operations();
        $repin = false;
        foreach ($operations as $operation) {
            if (
                $operation->kind === SchemaOperationKind::RepinRecords
                && ($operation->after['definition_version'] ?? null) === $plan->toDefinitionVersion
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
     * Decide whether a column change only ever widens what the column already accepts.
     *
     * The logical name, the physical name, and the stored type must be unchanged, a nullable column may
     * not become required, and a length or precision the old column declared may only grow. The default is
     * ignored on both sides, because it governs rows written from now on rather than rows already stored.
     * Every remaining option must match exactly, so an option this build does not recognise counts as a
     * narrowing rather than being waved through.
     *
     * @param   SchemaOperation  $operation  Alter-column step, carrying the column before and after.
     *
     * @return  bool  True when every stored value the old column allowed is still valid under the new one.
     *
     * @since   2.0.0
     */
    private function additiveColumnRelaxation(SchemaOperation $operation): bool
    {
        if ($operation->before === null || $operation->after === null) {
            return false;
        }
        $before = \Kumwe\App\BusinessSchema\Domain\PhysicalColumnBlueprint::fromArray($operation->before);
        $after = \Kumwe\App\BusinessSchema\Domain\PhysicalColumnBlueprint::fromArray($operation->after);
        if (
            $before->logicalName !== $after->logicalName
            || $before->physicalName !== $after->physicalName
            || $before->doctrineType !== $after->doctrineType
            || ($before->nullable && !$after->nullable)
        ) {
            return false;
        }
        $old = $before->options;
        $new = $after->options;
        unset($old['default'], $new['default']);
        foreach (['length', 'precision'] as $key) {
            if (isset($old[$key]) && (!isset($new[$key]) || $new[$key] < $old[$key])) {
                return false;
            }
            unset($old[$key], $new[$key]);
        }

        return $old === $new;
    }

    /**
     * Load a plan within the actor's site, refusing to continue when the site holds none.
     *
     * Called once before the lock and again under it, so the run works from the state the lock made
     * stable rather than from whatever was read while deciding to take it.
     *
     * @param   ExecutionContext  $context  Actor and site the plan must belong to.
     * @param   string            $planId   UUID of the plan to load.
     *
     * @return  SchemaPlan  The plan exactly as currently stored, at its current revision.
     *
     * @throws  BusinessSchemaNotFound  When this site holds no plan under that identifier.
     *
     * @since   2.0.0
     */
    private function requiredPlan(ExecutionContext $context, string $planId): SchemaPlan
    {
        return $this->plans->find($context->site(), $planId) ?? throw new BusinessSchemaNotFound($planId);
    }

    /**
     * Require one business-schema capability of the actor before any of the run's side effects.
     *
     * The question is always asked of the whole `business_schema` collection rather than of an individual
     * plan, so authority here means being allowed to do schema work on the site, never being allowed to
     * run one particular plan.
     *
     * @param   ExecutionContext  $context     Actor, site, and provenance the run is attributed to.
     * @param   string            $capability  Capability code to require, such as `business.schema.execute`.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses the actor
     *          that capability on this site.
     *
     * @since   2.0.0
     */
    private function authorize(ExecutionContext $context, string $capability): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString($capability),
            AuthorizationResource::collection('business_schema'),
        );
    }

    /**
     * Run one unit of durable bookkeeping inside a transaction.
     *
     * Every plan, journal, installation, and audit write this executor makes goes through here, so a write
     * and the locked reads that justified it commit together or not at all. Nested calls join the
     * transaction already open, which is what lets a step's own write sit inside the larger finalization
     * scope without committing ahead of it.
     *
     * @template T
     *
     * @param   callable(): T  $operation  Bookkeeping to perform inside the transaction scope.
     *
     * @return  T  Whatever the operation returned, passed straight back.
     *
     * @since   2.0.0
     */
    private function journal(callable $operation): mixed
    {
        return $this->transactions->transactional($operation);
    }
}
