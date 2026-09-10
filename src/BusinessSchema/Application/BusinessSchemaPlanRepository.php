<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Application;

use Kumwe\App\BusinessSchema\Domain\SchemaPlan;
use Kumwe\App\BusinessSchema\Domain\SchemaPlanStep;
use Kumwe\Context\Value\SiteContext;

/**
 * Store of schema plans and of the per-step journal their execution is replayed from.
 *
 * A plan is what an operator approves and what the executor may resume after an interruption, so this
 * port carries more than load and store: the optimistic-concurrency rules that stop two runs of the same
 * plan from overwriting each other live here. Plans advance exactly one revision at a time through
 * `replace()`, journal rows are rewritten only by the run holding the matching execution fence through
 * `replaceStep()`, and a mismatch on either is reported as a conflict rather than reconciled. Plan reads
 * are site-scoped; journal reads are addressed by plan alone, so a caller reaches a journal only after
 * resolving its plan for its own site.
 *
 * @since  2.0.0
 */
interface BusinessSchemaPlanRepository
{
    /**
     * List every plan recorded for one site, whatever state it has reached.
     *
     * @param   SiteContext  $site  Site whose plans are listed; a plan never crosses sites.
     *
     * @return  list<SchemaPlan>  Plans of that site, most recently created first; empty when the site has
     *          never planned a schema change.
     *
     * @since   2.0.0
     */
    public function all(SiteContext $site): array;

    /**
     * Look up one plan within a site.
     *
     * Deliberately impure: callers re-read a plan after an execution attempt to see the state that run
     * left behind, so analysis must not fold a second read into the first.
     *
     * @param   SiteContext  $site    Site the plan must belong to.
     * @param   string       $planId  UUID of the plan to read.
     *
     * @return  ?SchemaPlan  The plan as currently stored, or null when this site holds none under that
     *          identifier.
     *
     * @phpstan-impure
     *
     * @since   2.0.0
     */
    public function find(SiteContext $site, string $planId): ?SchemaPlan;

    /**
     * Fetch the plan most recently created for one definition on one site.
     *
     * The planner reads it before persisting: an identical plan already on record is returned instead of
     * a duplicate, which is what makes re-publishing the same definition version harmless. "Latest" is by
     * creation time, regardless of the status the plan has reached.
     *
     * @param   SiteContext  $site          Site the plan must belong to.
     * @param   string       $definitionId  UUID of the definition the plan targets.
     *
     * @return  ?SchemaPlan  The newest plan for that definition, or null when none was ever planned.
     *
     * @since   2.0.0
     */
    public function latestForDefinition(SiteContext $site, string $definitionId): ?SchemaPlan;

    /**
     * Report whether a definition has a plan that stopped part way through execution.
     *
     * The lifecycle sweep asks before returning a withheld installation to service: a plan left
     * executing, failed, or recovery-required means the physical schema is in a state only an operator
     * can settle, and nothing may be layered on top of it until then.
     *
     * @param   SiteContext  $site          Site the plans are read within.
     * @param   string       $definitionId  UUID of the definition being checked.
     *
     * @return  bool  True while any plan for that definition is executing, failed, or recovery-required.
     *
     * @since   2.0.0
     */
    public function hasUnfinishedExecution(SiteContext $site, string $definitionId): bool;

    /**
     * Record a freshly planned plan for the first time.
     *
     * Insert only, and a plan's canonical checksum is unique per store, so this is the point at which two
     * planners racing on the same definition collide. Every later state change goes through `replace()`.
     *
     * @param   SchemaPlan  $plan  Newly planned plan at revision one; its steps are saved separately.
     *
     * @return  void
     *
     * @throws  BusinessSchemaConflict  When a plan with the same identifier or canonical checksum is
     *          already stored.
     *
     * @since   2.0.0
     */
    public function save(SchemaPlan $plan): void;

    /**
     * Overwrite a plan's mutable state, but only while it still stands where the caller last read it.
     *
     * The replacement must advance the revision by exactly one, and the fence decides which run may
     * write: pass the fence the caller holds to require the stored plan still carries it, or leave it
     * null to require that no run has fenced the plan at all. Identity and creation columns are never
     * rewritten, so a replacement cannot move a plan to another definition, site, or author.
     *
     * @param   SchemaPlan  $plan              Plan state to store, at $expectedRevision plus one.
     * @param   int         $expectedRevision  Revision the caller read this plan at.
     * @param   ?int        $expectedFence     Fence the writing run holds, or null to demand an unfenced plan.
     *
     * @return  void
     *
     * @throws  BusinessSchemaConflict  When the revision does not advance by exactly one, the stored plan
     *          has already moved on, or the stored fence is not the expected one.
     *
     * @since   2.0.0
     */
    public function replace(SchemaPlan $plan, int $expectedRevision, ?int $expectedFence = null): void;

    /**
     * Read one plan's execution journal.
     *
     * Not site-scoped: resolve the plan through `find()` first so a journal cannot be read across sites.
     *
     * @param   string  $planId  UUID of the plan whose journal is wanted.
     *
     * @return  list<SchemaPlanStep>  One entry per plan operation, in ordinal order from one.
     *
     * @since   2.0.0
     */
    public function steps(string $planId): array;

    /**
     * Write a journal row without regard to any execution fence.
     *
     * This is how the planner lays down the pending journal for a plan it has just saved; a row already
     * present under the same plan and ordinal is updated rather than duplicated, so re-running the write
     * is harmless. Anything that must not overtake a concurrent run uses `replaceStep()` instead.
     *
     * @param   SchemaPlanStep  $step  Journal state to store, addressed by its plan ID and ordinal.
     *
     * @return  void
     *
     * @throws  BusinessSchemaConflict  When a concurrent writer inserts that plan and ordinal first.
     *
     * @since   2.0.0
     */
    public function saveStep(SchemaPlanStep $step): void;

    /**
     * Rewrite a journal row, and only for the run whose fence the stored row already carries.
     *
     * Null demands that the row is still unfenced, which is how a first attempt claims it; any other
     * value demands exactly that fence. A superseded run therefore fails here instead of overwriting the
     * journal of the run that displaced it. The row must already exist — this never inserts.
     *
     * @param   SchemaPlanStep  $step           Journal state to store, addressed by its plan ID and ordinal.
     * @param   ?int            $expectedFence  Fence the stored row must carry, or null to demand none.
     *
     * @return  void
     *
     * @throws  BusinessSchemaConflict  When no row matches the plan, ordinal, and fence together.
     *
     * @since   2.0.0
     */
    public function replaceStep(SchemaPlanStep $step, ?int $expectedFence): void;
}
