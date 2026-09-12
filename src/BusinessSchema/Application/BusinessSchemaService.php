<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Application;

use DateInterval;
use Kumwe\App\Application\Authorization\AuthorizationGateway;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallation;
use Kumwe\App\BusinessSchema\Domain\SchemaPlan;
use Kumwe\App\BusinessSchema\Domain\SchemaOperationKind;
use Kumwe\App\BusinessSchema\Domain\SchemaPlanStep;
use Kumwe\App\BusinessSchema\Domain\SchemaPlanStatus;
use Kumwe\App\BusinessSchema\Domain\SchemaRecoveryEvidence;
use Kumwe\App\BusinessSchema\Domain\SchemaRisk;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Authorized facade used by every delivery surface; planning, approval, and execution remain separate actions.
 *
 * Administrator screens, the REST API, MCP tools and the console all reach business-schema work through
 * this one object, so a capability refused on one surface is refused on all of them. Keeping the three
 * steps apart is the product policy it enforces: the planner proposes, an operator approves the exact
 * plan they inspected, and only an approved plan may execute. Approval is where the cost of a mistake
 * is paid — the plan's checksum must still match, a destructive plan needs its own capability, any plan
 * above online-safe-additive needs that checksum echoed back as confirmation, and a rebuilding or
 * destructive plan needs a tested clean-target restore bound to the very schema it starts from.
 * Execution itself belongs to `BusinessSchemaExecutor`; what this service adds around it is the graph
 * bootstrap, driving the connected peers of an initial plan that paused on a foreign key so a set of
 * definitions referring to each other can finish installing.
 *
 * @since  2.0.0
 */
final readonly class BusinessSchemaService
{
    /**
     * Proofs a drill must record before its evidence counts as a rehearsed restore.
     *
     * Each flag stands for something the operator verified against a freshly restored target rather
     * than the live database, so evidence assembled from an untested backup cannot pass for a drill.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const REQUIRED_DRILL_FLAGS = [
        'clean_target_restore',
        'blueprint_checksum_verified',
        'typed_command_verified',
        'record_revision_audit_checksums_verified',
    ];

    /**
     * Wire the collaborators every schema read, approval, and execution request passes through.
     *
     * @param  BusinessDefinitionRepository              $definitions    Catalog the selectable definitions come from.
     * @param  BusinessSchemaPlanner                     $planner        Builds and persists proposed plans.
     * @param  BusinessSchemaExecutor                    $executor       Applies approved plans under the lock.
     * @param  BusinessSchemaPlanRepository              $plans          Store of plans and their journals.
     * @param  BusinessSchemaInstallationRepository      $installations  Record of the schema each definition has.
     * @param  BusinessSchemaRecoveryEvidenceRepository  $evidence       Store of tested-restore drill evidence.
     * @param  BusinessSchemaEnvironment                 $environment    Engine and release evidence must match.
     * @param  AuthorizationGateway                      $authorization  Decides what the actor may do here.
     * @param  AuditRecorder                             $audit          Sink approvals and drills are logged to.
     * @param  TransactionManager                        $transactions   Commits a write and its audit as one.
     * @param  ClockInterface                            $clock          Source of every timestamp stamped here.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessDefinitionRepository $definitions,
        private BusinessSchemaPlanner $planner,
        private BusinessSchemaExecutor $executor,
        private BusinessSchemaPlanRepository $plans,
        private BusinessSchemaInstallationRepository $installations,
        private BusinessSchemaRecoveryEvidenceRepository $evidence,
        private BusinessSchemaEnvironment $environment,
        private AuthorizationGateway $authorization,
        private AuditRecorder $audit,
        private TransactionManager $transactions,
        private ClockInterface $clock,
    ) {
    }

    /**
     * List every schema plan recorded for the actor's site, whatever state it has reached.
     *
     * @param   ExecutionContext  $context  Actor and site the listing is read for.
     *
     * @return  list<SchemaPlan>  Plans of that site, most recently created first.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When `business.schema.read` is
     *          refused.
     *
     * @since   2.0.0
     */
    public function plans(ExecutionContext $context): array
    {
        $this->authorize($context, 'business.schema.read');

        return $this->plans->all($context->site());
    }

    /**
     * Read one schema plan of the actor's site.
     *
     * @param   ExecutionContext  $context  Actor and site the plan must belong to.
     * @param   string            $planId   UUID of the plan to read.
     *
     * @return  SchemaPlan  The plan, including its immutable operations and current status.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When `business.schema.read` is
     *          refused.
     * @throws  BusinessSchemaNotFound  When the actor's site holds no plan under that identifier.
     *
     * @since   2.0.0
     */
    public function plan(ExecutionContext $context, string $planId): SchemaPlan
    {
        $this->authorize($context, 'business.schema.read');

        return $this->plans->find($context->site(), $planId) ?? throw new BusinessSchemaNotFound($planId);
    }

    /**
     * Read the execution journal of one plan, step by step.
     *
     * The journal is what an operator inspects after an interrupted run: it says which operations
     * completed, which one was in flight, and the checksum chain each completed step left behind.
     *
     * @param   ExecutionContext  $context  Actor and site the plan must belong to.
     * @param   string            $planId   UUID of the plan whose journal is wanted.
     *
     * @return  list<SchemaPlanStep>  One entry per plan operation, in ordinal order.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When `business.schema.read` is
     *          refused.
     * @throws  BusinessSchemaNotFound  When the actor's site holds no plan under that identifier.
     *
     * @since   2.0.0
     */
    public function steps(ExecutionContext $context, string $planId): array
    {
        $plan = $this->plan($context, $planId);

        return $this->plans->steps($plan->id);
    }

    /**
     * Read what is currently installed for one definition, if anything is.
     *
     * Installations are stored per definition rather than per site, so the record is re-checked against
     * the caller's site here and a foreign one is reported as nothing installed rather than disclosed.
     *
     * @param   ExecutionContext  $context       Actor and site the installation must belong to.
     * @param   string            $definitionId  UUID of the definition to look up.
     *
     * @return  ?SchemaInstallation  The installed blueprint and its status, or null when this site has none.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When `business.schema.read` is
     *          refused.
     *
     * @since   2.0.0
     */
    public function installation(ExecutionContext $context, string $definitionId): ?SchemaInstallation
    {
        $this->authorize($context, 'business.schema.read');
        $installation = $this->installations->find($definitionId);

        return $installation !== null && $installation->siteIdentifier === $context->site()->identifier()
            ? $installation
            : null;
    }

    /**
     * Read one recorded restore drill, so an approver can see what an approval would be citing.
     *
     * The record is returned as stored; whether it is still fresh enough and still matches this engine
     * and release is decided at approval time, not here.
     *
     * @param   ExecutionContext  $context     Actor and site the evidence must belong to.
     * @param   string            $evidenceId  UUID the drill was recorded under.
     *
     * @return  ?SchemaRecoveryEvidence  The drill record, or null when this site holds none under that id.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When `business.schema.read` is
     *          refused.
     *
     * @since   2.0.0
     */
    public function recoveryEvidence(
        ExecutionContext $context,
        string $evidenceId,
    ): ?SchemaRecoveryEvidence {
        $this->authorize($context, 'business.schema.read');

        return $this->evidence->find($context->site(), $evidenceId);
    }

    /**
     * Schema-specific definition selector; does not require the separate content.read capability.
     *
     * Only definitions that have a published version and whose owner — core, an extension, or the site
     * itself — is still active can be planned against, so the catalog is filtered to those and sorted
     * by handle for a stable picker. Each `owner` entry is that owner's type and identifier, colon
     * separated.
     *
     * @param   ExecutionContext  $context  Actor and site the catalog is read for.
     *
     * @return  list<array{id: string, handle: string, version: int, owner: string}>
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When `business.schema.read` is
     *          refused.
     *
     * @since   2.0.0
     */
    public function definitions(ExecutionContext $context): array
    {
        $this->authorize($context, 'business.schema.read');
        $result = [];
        foreach ($this->definitions->catalog($context->site()) as $entry) {
            if ($entry->publishedVersion === null || !$entry->ownerActive) {
                continue;
            }
            $result[] = [
                'id' => $entry->id,
                'handle' => $entry->handle,
                'version' => $entry->publishedVersion,
                'owner' => $entry->owner->type->value . ':' . $entry->owner->identifier,
            ];
        }
        usort($result, static fn (array $left, array $right): int => strcmp($left['handle'], $right['handle']));

        return $result;
    }

    /**
     * Propose the plan that moves one definition's installed schema to its published version.
     *
     * The plan is only written down, never applied. Proposing the same change twice returns the plan
     * already on record rather than a second one, so a retried request does not fill the approval queue.
     *
     * @param   ExecutionContext  $context       Actor and site the plan is created for.
     * @param   string            $definitionId  UUID of the published definition to plan against.
     *
     * @return  SchemaPlan  The proposed plan, carrying the checksum an approver must quote back.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When `business.schema.plan` is
     *          refused.
     * @throws  BusinessSchemaNotFound  When the site has no published definition under that identifier.
     * @throws  BusinessSchemaConflict  When the installed schema is not older than the published
     *          definition, or has drifted from the blueprint recorded for it.
     *
     * @since   2.0.0
     */
    public function createPlan(ExecutionContext $context, string $definitionId): SchemaPlan
    {
        return $this->planner->plan($context, $definitionId);
    }

    /**
     * Propose the destructive plan that removes every table one definition installed.
     *
     * Purging is never folded into an ordinary upgrade plan: it is asked for explicitly, guarded by the
     * destructive capability at planning as well as approval, and drops the record table last so the
     * tables referencing it go first.
     *
     * @param   ExecutionContext  $context       Actor and site the purge is planned for.
     * @param   string            $definitionId  UUID of the definition whose tables are to be dropped.
     *
     * @return  SchemaPlan  A destructive plan awaiting its own approval and recovery evidence.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When
     *          `business.schema.destructive` is refused.
     * @throws  BusinessSchemaNotFound  When this site has nothing installed, or no published definition,
     *          under that identifier.
     *
     * @since   2.0.0
     */
    public function createPurgePlan(ExecutionContext $context, string $definitionId): SchemaPlan
    {
        return $this->planner->purgePlan($context, $definitionId);
    }

    /**
     * Approve a plan for execution after re-proving it is still the plan the operator inspected.
     *
     * The bar rises with the plan's risk. Every approval must quote the current checksum. Anything
     * above online-safe-additive must quote it a second time as confirmation, which is digested
     * together with the actor and its authorization fingerprint so the stored proof belongs to this
     * approver alone; a low-risk plan must not carry one at all. A destructive plan is separately
     * checked against `business.schema.destructive`. A rebuilding or destructive plan must name
     * recovery evidence bound to the exact schema it starts from, trusted for this site, engine, server
     * version and release, and no older than seven days or than the plan itself, whichever is later —
     * and a plan that needs no evidence must not name any. The approval, the plan's new revision and
     * the audit event commit together, so a refused write leaves the plan pending.
     *
     * @param   ExecutionContext  $context           Actor and site the approval is recorded for.
     * @param   string            $planId            UUID of the plan being approved.
     * @param   string            $expectedChecksum  Checksum of the plan as inspected; refused if it moved.
     * @param   ?string           $confirmation      Repeat of that checksum for a high-impact plan.
     * @param   ?string           $evidenceId        Drill a rebuilding or destructive plan is approved on.
     *
     * @return  SchemaPlan  The plan in its approved state, at the revision the approval wrote.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When `business.schema.approve`
     *          or, for a destructive plan, `business.schema.destructive` is refused.
     * @throws  BusinessSchemaNotFound  When the site holds no such plan, or no such recovery evidence.
     * @throws  BusinessSchemaConflict  When the plan changed after inspection, the confirmation is
     *          missing, wrong or unwanted, or the recovery evidence is missing, unwanted, bound to
     *          another source schema, foreign to this environment, or stale.
     *
     * @since   2.0.0
     */
    public function approve(
        ExecutionContext $context,
        string $planId,
        string $expectedChecksum,
        ?string $confirmation,
        ?string $evidenceId,
    ): SchemaPlan {
        $this->authorize($context, 'business.schema.approve');
        $plan = $this->plans->find($context->site(), $planId) ?? throw new BusinessSchemaNotFound($planId);
        if (!hash_equals($plan->checksum(), $expectedChecksum)) {
            throw new BusinessSchemaConflict('The schema plan changed after it was inspected.');
        }
        $confirmationDigest = null;
        if ($plan->risk->requiresHighImpactAuthorization()) {
            if ($confirmation === null || !hash_equals($plan->checksum(), $confirmation)) {
                throw new BusinessSchemaConflict('High-impact approval requires the exact current plan checksum.');
            }
            $confirmationDigest = hash('sha256', implode("\0", [
                'kumwe:business-schema-confirmation:v1',
                $confirmation,
                $context->actorId(),
                $context->authorizationFingerprint(),
            ]));
        } elseif ($confirmation !== null) {
            throw new BusinessSchemaConflict('Low-risk approval must not carry high-impact confirmation state.');
        }
        if ($plan->risk === SchemaRisk::Destructive) {
            $this->authorize($context, 'business.schema.destructive');
        }
        if ($plan->risk->requiresRecoveryEvidence()) {
            if ($evidenceId === null) {
                throw new BusinessSchemaConflict('This plan requires tested source-bound recovery evidence.');
            }
            $evidence = $this->evidence->find($context->site(), $evidenceId)
                ?? throw new BusinessSchemaNotFound($evidenceId);
            if (
                $plan->fromSchemaChecksum === null
                || !hash_equals($evidence->sourceSchemaChecksum, $plan->fromSchemaChecksum)
            ) {
                throw new BusinessSchemaConflict('Recovery evidence is bound to another source schema.');
            }
            $this->assertTrustedEvidence($context, $evidence, false);
            $freshnessFloor = $this->clock->now()->sub(new DateInterval('P7D'));
            if ($plan->createdAt > $freshnessFloor) {
                $freshnessFloor = $plan->createdAt;
            }
            if (
                !$evidence->qualifies(
                    $context->site()->identifier(),
                    $this->environment->databaseDriver(),
                    $this->environment->databaseServerVersion(),
                    $this->environment->applicationRelease(),
                    $plan->fromSchemaChecksum,
                    $freshnessFloor,
                )
            ) {
                throw new BusinessSchemaConflict(
                    'Recovery evidence must be a fresh clean-target drill created for this persisted plan.',
                );
            }
        } elseif ($evidenceId !== null) {
            throw new BusinessSchemaConflict('This schema plan does not require recovery evidence.');
        }
        $now = $this->clock->now();

        return $this->transactions->transactional(function () use (
            $context,
            $plan,
            $expectedChecksum,
            $confirmationDigest,
            $evidenceId,
            $now,
        ): SchemaPlan {
            $approved = $plan->approve(
                $context->actorId(),
                $now,
                $expectedChecksum,
                $confirmationDigest,
                $evidenceId,
            );
            $this->plans->replace($approved, $plan->revision);
            $this->audit->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $now,
                $context->actorId(),
                'business.schema.approve',
                'business_schema_plan',
                $plan->id,
                'success',
                [
                    'plan_checksum' => $plan->checksum(),
                    'risk' => $plan->risk->value,
                    'recovery_evidence_id' => $evidenceId,
                ],
            ));

            return $approved;
        });
    }

    /**
     * Execute an approved plan, first completing the graph when an initial plan paused on a foreign key.
     *
     * Ordinary failures propagate untouched; the single case this method handles itself is the pause
     * the executor takes when definitions that reference each other are installed for the first time.
     * Tables are created before any foreign key, so the first plan of such a set stops with its peer's
     * table still missing. On recognising exactly that state, every connected peer is executed or
     * resumed, the set is then walked in reverse to resume peers that paused in turn, and only then is
     * the requested plan resumed. A connected plan in any other state stops the whole attempt, and a
     * peer that fails again while still safely paused is left for the next pass rather than aborting.
     *
     * @param   ExecutionContext  $context  Actor and site the execution runs as.
     * @param   string            $planId   UUID of the approved plan to execute.
     *
     * @return  SchemaExecutionOutcome  Fence, completed and skipped step counts, and the resulting checksum.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When `business.schema.execute`
     *          or, for a destructive plan, `business.schema.destructive` is refused.
     * @throws  BusinessSchemaNotFound  When the site holds no such plan, or a connected peer disappeared
     *          mid-graph.
     * @throws  BusinessSchemaConflict  When the plan is not approved, when the physical schema disagrees
     *          with the approved blueprint, or when a connected initial plan is neither executable nor
     *          safely resumable.
     *
     * @since   2.0.0
     */
    public function execute(ExecutionContext $context, string $planId): SchemaExecutionOutcome
    {
        try {
            return $this->executor->execute($context, $planId);
        } catch (Throwable $failure) {
            $plan = $this->plans->find($context->site(), $planId);
            if ($plan === null || !$this->isGraphBootstrapPause($context, $plan)) {
                throw $failure;
            }
            $peers = $this->approvedGraphPeers($context, $plan);
            foreach ($peers as $peer) {
                $current = $this->plans->find($context->site(), $peer->id)
                    ?? throw new BusinessSchemaNotFound($peer->id);
                if ($current->status === SchemaPlanStatus::Completed) {
                    continue;
                }
                try {
                    if ($current->status === SchemaPlanStatus::Approved) {
                        $this->executor->execute($context, $peer->id);
                    } elseif ($this->isGraphBootstrapPause($context, $current)) {
                        $this->executor->resumeGraphBootstrap($context, $peer->id);
                    } else {
                        throw new BusinessSchemaConflict(
                            'A connected initial schema plan is not in an executable graph state.',
                        );
                    }
                } catch (Throwable $peerFailure) {
                    $current = $this->plans->find($context->site(), $peer->id) ?? throw $peerFailure;
                    if (!$this->isGraphBootstrapPause($context, $current)) {
                        throw $peerFailure;
                    }
                }
            }
            foreach (array_reverse($peers) as $peer) {
                $current = $this->plans->find($context->site(), $peer->id)
                    ?? throw new BusinessSchemaNotFound($peer->id);
                if ($current->status === SchemaPlanStatus::Completed) {
                    continue;
                }
                if (!$this->isGraphBootstrapPause($context, $current)) {
                    throw new BusinessSchemaConflict('A connected initial schema plan is not safely resumable.');
                }
                $this->executor->resumeGraphBootstrap($context, $current->id);
            }

            return $this->executor->resumeGraphBootstrap($context, $planId);
        }
    }

    /**
     * Resume an interrupted plan under operator recovery authority.
     *
     * Recovery is a separate capability from execution because it re-enters a plan whose journal is
     * already partly written. Completed steps are skipped on their recorded checksums rather than
     * repeated, so an operator can drive a run that stopped mid-way to completion without replaying it.
     *
     * @param   ExecutionContext  $context  Actor and site the recovery runs as.
     * @param   string            $planId   UUID of the executing, failed or recovery-required plan.
     *
     * @return  SchemaExecutionOutcome  The same outcome shape as a first run, marked as resumed.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When `business.schema.recover`
     *          is refused.
     * @throws  BusinessSchemaNotFound  When the actor's site holds no plan under that identifier.
     * @throws  BusinessSchemaConflict  When the plan was never interrupted, or its journal disagrees
     *          with the immutable plan it belongs to.
     *
     * @since   2.0.0
     */
    public function recover(ExecutionContext $context, string $planId): SchemaExecutionOutcome
    {
        return $this->executor->recover($context, $planId);
    }

    /**
     * Record a completed restore drill so a high-impact plan can later be approved against it.
     *
     * The drill has to have been run by the caller, on this site, engine, server version and release,
     * and it must carry every clean-target proof plus bounded client and restore-target references;
     * timestamps in the future are refused outright. Evidence is immutable once stored, so submitting
     * the identical drill again is harmless while a changed one under the same identifier is rejected.
     *
     * @param   ExecutionContext        $context   Actor and site the drill is credited to.
     * @param   SchemaRecoveryEvidence  $evidence  Verified drill result, already self-validated.
     *
     * @return  SchemaRecoveryEvidence  The same evidence, once stored and audited under its identifier.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When `business.schema.recover`
     *          is refused.
     * @throws  BusinessSchemaConflict  When the drill does not match this environment or this actor, is
     *          missing a required proof, or is dated in the future.
     *
     * @since   2.0.0
     */
    public function recordRecoveryEvidence(
        ExecutionContext $context,
        SchemaRecoveryEvidence $evidence,
    ): SchemaRecoveryEvidence {
        $this->authorize($context, 'business.schema.recover');
        $this->assertTrustedEvidence($context, $evidence, true);
        $now = $this->clock->now();
        if ($evidence->backupCreatedAt > $now || $evidence->verifiedAt > $now) {
            throw new BusinessSchemaConflict('Recovery evidence timestamps cannot be in the future.');
        }

        return $this->transactions->transactional(function () use ($context, $evidence, $now): SchemaRecoveryEvidence {
            $this->evidence->save($evidence);
            $this->audit->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $now,
                $context->actorId(),
                'business.schema.recovery_evidence.record',
                'business_schema_recovery_evidence',
                $evidence->id,
                'success',
                [
                    'evidence_checksum' => $evidence->checksum(),
                    'source_schema_checksum' => $evidence->sourceSchemaChecksum,
                    'drill_reference' => $evidence->drillReference,
                ],
            ));

            return $evidence;
        });
    }

    /**
     * Refuse recovery evidence that does not describe a drill run here, on this release.
     *
     * The environment is compared field by field rather than taken on trust, so a drill performed on a
     * different engine, server version or application release cannot be used to authorize a rebuild.
     * The declared clean-drill flags must each be exactly `true`, and the client version and restore
     * target must be short, printable, non-empty strings so the evidence names a real target.
     *
     * @param   ExecutionContext        $context                 Actor and site the drill must match.
     * @param   SchemaRecoveryEvidence  $evidence                Drill result under scrutiny.
     * @param   bool                    $requireCurrentVerifier  True when the actor must be its verifier.
     *
     * @return  void
     *
     * @throws  BusinessSchemaConflict  When the drill was untested, belongs to another site, engine or
     *          release, was verified by someone else where that is required, or lacks a required proof.
     *
     * @since   2.0.0
     */
    private function assertTrustedEvidence(
        ExecutionContext $context,
        SchemaRecoveryEvidence $evidence,
        bool $requireCurrentVerifier,
    ): void {
        if (
            !$evidence->restoreTested
            || $evidence->siteIdentifier !== $context->site()->identifier()
            || ($requireCurrentVerifier && $evidence->verifiedBy !== $context->actorId())
            || $evidence->databaseDriver !== $this->environment->databaseDriver()
            || !hash_equals($evidence->databaseServerVersion, $this->environment->databaseServerVersion())
            || !hash_equals($evidence->applicationRelease, $this->environment->applicationRelease())
        ) {
            throw new BusinessSchemaConflict('Recovery evidence does not match the authenticated environment.');
        }
        foreach (self::REQUIRED_DRILL_FLAGS as $flag) {
            if (($evidence->details[$flag] ?? null) !== true) {
                throw new BusinessSchemaConflict('Recovery evidence is missing a required clean-drill proof.');
            }
        }
        foreach (['client_version', 'restore_target_reference'] as $key) {
            $value = $evidence->details[$key] ?? null;
            if (
                !is_string($value) || trim($value) === '' || strlen($value) > 191
                || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            ) {
                throw new BusinessSchemaConflict('Recovery evidence is missing bounded drill identity data.');
            }
        }
    }

    /**
     * Assert one business-schema capability against the collection resource this service guards.
     *
     * Schema work is authorized at the collection rather than per definition, so a grant is held over
     * business schema as a whole and every entry point here checks it the same way.
     *
     * @param   ExecutionContext  $context     Actor and site the check is made for.
     * @param   string            $capability  Dotted capability name, such as `business.schema.approve`.
     *
     * @return  void
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When policy refuses the actor
     *          that capability.
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
     * Ask the executor whether a plan stopped only at the deliberate foreign-key pause of a new graph.
     *
     * A true answer is what separates a recoverable graph bootstrap from a genuine failure, so the
     * question is left with the executor, which re-verifies the journal against live introspection:
     * the plan must be an initial one of this site, every step it completed must be a create or
     * foreign key that physically exists, at least one foreign key must have failed, and no other
     * step may have started.
     *
     * @param   ExecutionContext  $context  Actor and site the plan must belong to.
     * @param   SchemaPlan        $plan     Plan whose interrupted journal is being classified.
     *
     * @return  bool  True when a foreign key failed, every completed step verified, and nothing else started.
     *
     * @since   2.0.0
     */
    private function isGraphBootstrapPause(ExecutionContext $context, SchemaPlan $plan): bool
    {
        return $this->executor->isGraphBootstrapPause($context, $plan);
    }

    /**
     * Collect the other initial plans the root's foreign keys reach, proving each is already approved.
     *
     * Initial plans are indexed by the physical table each of them creates, then followed transitively
     * from the root's foreign keys, so only plans genuinely in the reference graph are pulled in.
     * Every plan reached must have been approved on its own merits — the graph bootstrap
     * completes peers, it never authorizes them — and the result is ordered by definition identifier so
     * repeated attempts drive the same sequence.
     *
     * @param   ExecutionContext  $context  Actor and site the plans are read for.
     * @param   SchemaPlan        $root     Plan whose foreign keys start the walk.
     *
     * @return  list<SchemaPlan>  Connected initial plans without the root, ordered by definition id.
     *
     * @throws  BusinessSchemaConflict  When a connected plan is not approved, recovery-required or
     *          already completed.
     *
     * @since   2.0.0
     */
    private function approvedGraphPeers(ExecutionContext $context, SchemaPlan $root): array
    {
        $all = $this->plans->all($context->site());
        $providers = [];
        foreach ($all as $candidate) {
            if ($candidate->fromSchemaChecksum !== null) {
                continue;
            }
            foreach ($candidate->operations() as $operation) {
                $name = $operation->kind === SchemaOperationKind::CreateTable
                    ? ($operation->after['physical_name'] ?? null)
                    : null;
                if (is_string($name)) {
                    $providers[$name] ??= $candidate;
                }
            }
        }
        $selected = [];
        $pending = [$root];
        while ($pending !== []) {
            $plan = array_pop($pending);
            if (!$plan instanceof SchemaPlan || isset($selected[$plan->id])) {
                continue;
            }
            $selected[$plan->id] = $plan;
            foreach ($plan->operations() as $operation) {
                if ($operation->kind !== SchemaOperationKind::AddForeignKey) {
                    continue;
                }
                $foreignTable = $operation->after['foreign_table'] ?? null;
                if (!is_string($foreignTable) || !isset($providers[$foreignTable])) {
                    continue;
                }
                $pending[] = $providers[$foreignTable];
            }
        }
        unset($selected[$root->id]);
        foreach ($selected as $candidate) {
            if (
                !in_array(
                    $candidate->status,
                    [SchemaPlanStatus::Approved, SchemaPlanStatus::RecoveryRequired, SchemaPlanStatus::Completed],
                    true,
                )
            ) {
                throw new BusinessSchemaConflict(
                    'Every connected initial schema plan must be independently approved before graph execution.',
                );
            }
        }
        $result = array_values($selected);
        usort($result, static fn (SchemaPlan $left, SchemaPlan $right): int => strcmp(
            $left->definitionId,
            $right->definitionId,
        ));

        return $result;
    }
}
