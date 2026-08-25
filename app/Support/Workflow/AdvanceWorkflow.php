<?php

declare(strict_types=1);

namespace App\Support\Workflow;

use App\Enums\StageState;
use App\Enums\TaskSource;
use App\Enums\WorkflowState;
use App\Models\Gate;
use App\Models\Person;
use App\Models\Stage;
use App\Models\Task;
use App\Models\Workflow;
use App\Support\Activity\RecordActivity;
use App\Support\Audit\AuditLogger;
use App\Support\Workflow\Gates\Evaluators\ManualConfirmationEvaluator;
use App\Support\Workflow\Gates\GateRegistry;
use App\Support\Workflow\Gates\GateVerdict;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only path that mutates workflow state (PRD §8.3 · `CLAUDE.md` · #68).
 *
 * The Build Plan calls this the architectural keystone, and says why:
 *
 * > If a controller ever writes `stages.state` directly, the audit trail, the
 * > automation dispatch, and the gate guarantees all become optional — and
 * > nobody notices until something has been silently skipped.
 *
 * `tests/Unit/SingleMutationPathTest.php` enforces that by reading the source
 * of every other class. It is the same shape as the isolation suite's
 * enumerating tests, and for the same reason: a rule held by review alone is a
 * rule with a half-life.
 *
 * ## Transport-agnostic, per F12.5
 *
 * No `Request`, no Inertia response, no session read. It takes a workflow, an
 * actor, and options, and returns a result object; the controller adapts.
 * PRD F12.5 wants a native client addable without rework, and this is the
 * service that would otherwise accrete HTTP concerns. It also makes the thing
 * testable without a browser, which is how the gate logic gets exercised at
 * all.
 *
 * ## The ordering that matters most
 *
 * The transaction commits the state change; **the queue dispatch happens after
 * commit, never inside it.** A rolled-back transaction that has already queued
 * a client email is the failure PRD §4.5 calls unrecallable — the email goes,
 * the advance did not, and nobody can take it back. Slice 3 hangs the actual
 * dispatch on `pendingDispatch`.
 *
 * ## Two public methods, one of them a way *past* the gates
 *
 * `handle()` advances; `override()` clears one unmet gate with a reason
 * (F4.9, #69). Override lives here rather than in a controller for the same
 * reason advancing does: it is four artefacts that have to happen together —
 * the flag, an immutable audit entry, a distinct timeline marker, and a
 * follow-up task — and three of the four are the kind a second implementation
 * forgets. `SingleMutationPathTest` would not have caught it, because
 * `gates.overridden` is not `stages.state`.
 *
 * F4.12's **skip** is the third verb and is deliberately absent. IA §7 calls
 * conflating it with override legally material, and it needs a reopen path of
 * its own (#70) rather than a fifth branch here.
 */
final class AdvanceWorkflow
{
    /**
     * The shortest reason an override may carry (F4.9 · issue #69).
     *
     * Named here rather than in the form request, because the form is one
     * caller and this service is the rule. `OverrideGateRequest` reads this
     * constant to build its rule, so the sentence a person is shown and the
     * check that actually holds cannot drift apart — the defect this codebase
     * keeps finding is a rule written into one caller and forgotten in the
     * next one somebody adds.
     */
    public const MINIMUM_REASON_LENGTH = 10;

    public function __construct(
        private readonly GateRegistry $gates,
        private readonly RecordActivity $activity,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Advance one stage, or refuse and say why.
     *
     * Authorisation is the caller's job, not this service's. It has no request
     * and no session, so it cannot ask a policy about "the current user"
     * without inventing one — and `AuthorizationCoverageTest` already proves
     * every route asks. What this owns is everything a policy cannot see: the
     * gates, the ordering, and the audit trail.
     */
    public function handle(
        Workflow $workflow,
        ?Person $actor = null,
        ?string $expectedStageId = null,
    ): AdvanceResult {
        return DB::transaction(function () use ($workflow, $actor, $expectedStageId): AdvanceResult {
            /*
             * Lock the workflow row first.
             *
             * Two people clicking Advance on the same deal within a second of
             * each other is not hypothetical — it is Emily and Heather looking
             * at the same screen after a call. Without the lock both evaluate
             * the gates, both pass, and the deal jumps two stages with one
             * stage never actually worked. Issue #68's definition of done
             * asks for exactly this.
             */
            $workflow = Workflow::query()
                ->whereKey($workflow->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * The workflow has to be running before its stages are asked
             * anything.
             *
             * Without this an `on_hold` or `cancelled` workflow advanced
             * silently — the stage completed, the next one activated, and the
             * timeline said so — because the stage state machine had no
             * opinion about the workflow's. Worse, the *last* stage of the
             * same workflow threw instead, because completing it needs a
             * workflow transition the map forbids. One bug with two faces:
             * quietly wrong in the middle, loudly wrong at the end.
             *
             * A hold exists so a listing can pause while the sellers travel.
             * Advancing through one is exactly what a hold is for preventing.
             */
            if (! $workflow->isRunning()) {
                return AdvanceResult::refused($workflow->state->advanceRefusal());
            }

            $stage = $workflow->activeStage();

            if (! $stage instanceof Stage) {
                throw NothingToAdvance::for($workflow);
            }

            /*
             * The lock serialises the two clicks; it does not make them one.
             *
             * Under READ COMMITTED the second transaction re-reads the row
             * after the first commits — and finds the *newly activated* stage,
             * which it then advances. Emily and Heather click within a second
             * of each other and the deal moves two stages, exactly the outcome
             * the lock was written to prevent, just in sequence rather than in
             * parallel.
             *
             * The caller says which stage it was looking at when it rendered
             * the button. If that is no longer the active one, somebody else
             * got there first and the honest answer is to say so rather than
             * to advance a stage this person never saw.
             */
            if ($expectedStageId !== null && $expectedStageId !== $stage->getKey()) {
                return AdvanceResult::refused(
                    'Somebody else advanced this workflow while you were looking at it. '
                    .'Reload to see where it is now.',
                );
            }

            $verdicts = $this->evaluateGates($stage);

            $blocking = array_filter(
                $verdicts,
                fn (GateVerdict $verdict, string $gateId): bool => ! $verdict->met
                    && $this->gateById($stage, $gateId)->blocksAdvance(),
                ARRAY_FILTER_USE_BOTH,
            );

            $advisories = array_filter(
                $verdicts,
                fn (GateVerdict $verdict, string $gateId): bool => ! $verdict->met
                    && ! $this->gateById($stage, $gateId)->blocksAdvance(),
                ARRAY_FILTER_USE_BOTH,
            );

            if ($blocking !== []) {
                /*
                 * The stage is marked blocked so the deals index and the stage
                 * rail can show it without re-running seven evaluators per
                 * row. It is a display state for a stage somebody is standing
                 * in and cannot leave; it is refreshed the next time an
                 * advance is attempted and not before (see below).
                 */
                if ($stage->state === StageState::Active) {
                    $stage->transitionTo(StageState::Blocked)->save();
                }

                return AdvanceResult::blocked($stage, $blocking, $advisories);
            }

            /*
             * No re-activation here, and the reason is worth writing down
             * because the obvious line is a no-op.
             *
             * A `blocked → active` write on this path would sit inside the
             * same transaction opened above: if `applyAdvance` throws, the
             * rollback discards it and the stage reads `blocked` again; if it
             * does not throw, the very next line completes the stage and the
             * intermediate `active` is never observable. Neither branch can
             * see it. (An earlier round shipped exactly that line with a
             * comment claiming the rollback case as its justification, which
             * is backwards.)
             *
             * The real complaint stands and is not solvable here: between a
             * gate clearing and somebody clicking Advance, the deals index
             * shows a blocked badge with nothing blocking it, because gates
             * are only ever evaluated inside `handle()`. Fixing that needs a
             * re-evaluation path a screen can call without advancing —
             * Slice 3's work, when a route can mark a gate met. `blocked →
             * complete` is a legal transition precisely so this does not have
             * to be pretended otherwise.
             */

            return $this->applyAdvance($workflow, $stage, $actor, $advisories);
        });
    }

    /**
     * Force past one unmet gate, with a reason (PRD §4.4 F4.9 · §5.5 · #69).
     *
     * ## Why this is a method on the advance service and not a controller
     *
     * F4.9 is four artefacts, not one write: the flag on the gate, an
     * immutable audit entry naming **who, when, which gate, and why**, a
     * distinct timeline marker, and an auto-created follow-up task. A
     * controller that wrote the flag and remembered three of the four would
     * look like it worked. `tests/Unit/SingleMutationPathTest.php` would not
     * catch it either — `gates.overridden` is not `stages.state` — so the
     * thing that keeps them together is that there is one place they are
     * written.
     *
     * ## Override is not Skip, and this method is not the other one
     *
     * IA §7, flagged as legally material: **Override** means the gate should
     * have been met and was not, and you are proceeding anyway. **Skip** means
     * the stage does not apply to this deal at all. F4.12's skip is a
     * different verb with a different audit meaning and is #70's work; nothing
     * here writes `stages.skipped_reason`.
     *
     * ## It does not advance
     *
     * Deliberately. Overriding one of three blocking gates must not move the
     * deal past the other two, and there is no reading of F4.9 in which it
     * does. The caller presses Advance afterwards, and `handle()` evaluates
     * every gate again under its own lock. PRD §5.5 reads as one motion
     * because the modal reopens onto the refreshed checklist.
     *
     * @throws OverrideNeedsAReason when the reason is missing or too short
     * @throws GateNotOnWorkflow when the gate belongs to a different workflow
     */
    public function override(
        Workflow $workflow,
        Gate $gate,
        Person $actor,
        string $reason,
    ): OverrideResult {
        $reason = trim($reason);

        /*
         * Checked before the transaction opens, because it is not a fact about
         * the deal. `Person` is non-nullable for the same reason: `handle()`
         * takes a null actor so a queue or a webhook can advance a workflow,
         * and F4.9's audit entry has to name **who** — an override attributed
         * to nobody is an audit entry that cannot answer the only question it
         * exists for.
         */
        if (mb_strlen($reason) < self::MINIMUM_REASON_LENGTH) {
            throw OverrideNeedsAReason::atLeast(self::MINIMUM_REASON_LENGTH);
        }

        return DB::transaction(function () use ($workflow, $gate, $actor, $reason): OverrideResult {
            $workflow = Workflow::query()
                ->whereKey($workflow->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Re-read under the lock, so two people overriding the same gate
             * from two screens produce one override and one refusal rather
             * than two audit entries disagreeing about the reason.
             */
            $gate = Gate::query()->whereKey($gate->getKey())->lockForUpdate()->firstOrFail();
            $stage = $gate->stage;

            if (! $stage instanceof Stage || $stage->workflow_id !== $workflow->getKey()) {
                throw GateNotOnWorkflow::for($gate, $workflow);
            }

            if (! $workflow->isRunning()) {
                return OverrideResult::refused($workflow->state->advanceRefusal());
            }

            $refusal = $this->overrideRefusal($gate, $stage);

            if ($refusal !== null) {
                return OverrideResult::refused($refusal);
            }

            $gate->forceFill([
                // IA §8: **overridden is not a kind of met.** `is_met` is left
                // exactly as it was, so six weeks later the record still says
                // whether the survey came back or whether somebody decided to
                // proceed without it.
                'overridden' => true,
                'override_reason' => $reason,
                'overridden_by' => $actor->getKey(),
            ])->save();

            $followUp = $this->followUpFor($gate, $stage, $actor);

            $deal = $workflow->deal;

            /*
             * The distinct timeline marker (F4.9, and #69's fourth bullet).
             *
             * Its own event type rather than a `stage.advanced` with different
             * wording: Design System §7.3 tints an override `state-warning`
             * and everything else neutral, and `lib/activity.ts` decides that
             * from the type. A marker that has to be recognised by reading the
             * summary is not a marker.
             *
             * The summary names the gate and never the reason. The reason is
             * in the audit entry, which has the retention and the access
             * control for it; the timeline is read by anyone who can see the
             * deal.
             */
            $this->activity->record(
                subject: $workflow,
                eventType: 'gate.overridden',
                summary: "Overrode {$gate->label} on {$stage->name}",
                actor: $actor,
                deal: $deal,
            );

            /*
             * The immutable entry (#51). `audit_log`'s own triggers refuse an
             * UPDATE, a DELETE and a TRUNCATE, so "immutable" is a property of
             * the table rather than a promise made here.
             *
             * Who is `actorPersonId`, when is `created_at`, why is `reason`,
             * and **which gate** is both the auditable subject and the
             * `after` payload — the subject alone is an id, and an id whose
             * row a later slice archives is an entry nobody can read.
             */
            $this->audit->record(
                action: 'workflow.gate_overridden',
                auditable: $gate,
                teamId: $workflow->team_id,
                actorPersonId: $actor->getKey(),
                reason: $reason,
                after: [
                    'gate_id' => $gate->getKey(),
                    'gate_label' => $gate->label,
                    'gate_type' => $gate->gate_type,
                    'stage_id' => $stage->getKey(),
                    'stage_name' => $stage->name,
                    'workflow_id' => $workflow->getKey(),
                    'follow_up_task_id' => $followUp->getKey(),
                ],
            );

            return OverrideResult::overridden($gate, $followUp);
        });
    }

    /**
     * Tick a manual gate — the routine way past one (PRD §4.4 F4.8 · #67).
     *
     * ## The hole this closes
     *
     * `ManualConfirmationEvaluator` answers by reading `gates.is_met`, and
     * until now the **only** writer of that column was the cache refresh at
     * the bottom of this file, which reads the evaluator. A manual gate could
     * therefore never clear on its own: the sole way past the most common gate
     * type in the product was an **override** — the act IA §7 reserves for
     * *"the condition should have been met and was not"*, with an audit entry
     * and a follow-up task each time.
     *
     * That is precisely the shape `CLAUDE.md` records from S17: *"a row
     * nothing can reach is a rule nobody is following"*, and *"when a gate
     * type, a state or a flag has exactly one way to be satisfied, check that
     * the way is the one somebody would actually take."* `GatePolicy::update`
     * even carried the docblock *"Ticking a manual gate is ordinary deal
     * work"* — for a permission no route ever asked for. Nothing failed,
     * because each half worked.
     *
     * ## Confirming is not overriding, and the record has to keep them apart
     *
     * IA §8: **overridden is not a kind of met.** This writes `is_met` and
     * never touches `overridden`, exactly as `override()` writes the flag and
     * never touches this column. Six weeks later the record still distinguishes
     * *the survey came back* from *somebody decided to proceed without it* —
     * and §12.2's override metric goes on measuring processes that failed
     * rather than gates people had no other way to clear.
     *
     * It needs no reason for the same reason: confirming asserts the ordinary
     * fact the gate asks about, and a product that demanded a paragraph for it
     * would train people to type "done" forty times a deal.
     *
     * ## It does not advance
     *
     * The argument `override()` makes, unchanged. Clearing one of three
     * blocking gates must not move the deal past the other two.
     *
     * ## Only a manual gate
     *
     * Every other evaluator derives its answer from something real — the
     * required tasks, a populated field, a document. Letting somebody tick one
     * of those would make `is_met` a claim rather than a cache, and the next
     * advance would overwrite it from the evaluator anyway, which is the worst
     * of both: a control that appears to work and silently does not.
     *
     * @throws GateNotOnWorkflow when the gate belongs to a different workflow
     */
    public function confirm(Workflow $workflow, Gate $gate, Person $actor): ConfirmResult
    {
        return $this->setConfirmation($workflow, $gate, $actor, confirmed: true);
    }

    /**
     * Untick one, because a person who ticked the wrong row needs a way back.
     *
     * The mirror of `DealTasks::reopen()` and, like it, a separate verb rather
     * than a boolean on an edit: *"I confirmed the survey"* and *"I confirmed
     * the wrong thing"* are different events, and only one of them is somebody
     * correcting themselves.
     *
     * It refuses once the stage has moved on. A completed stage's gates are
     * what happened, not a question still open, and unticking one would
     * rewrite history to say a stage advanced over a gate that was never met —
     * which is what `overridden` exists to record honestly.
     *
     * @throws GateNotOnWorkflow when the gate belongs to a different workflow
     */
    public function unconfirm(Workflow $workflow, Gate $gate, Person $actor): ConfirmResult
    {
        return $this->setConfirmation($workflow, $gate, $actor, confirmed: false);
    }

    /**
     * Mark a stage **not applicable to this deal** (PRD §4.4 F4.12 · #70).
     *
     * ## Skip is not Override, and the difference is the whole point
     *
     * IA §7, flagged as legally material: **Override** means the gate should
     * have been met and was not, and you are proceeding anyway. **Skip** means
     * the stage does not apply at all. PRD §14.2 A9 names the case — *"cash
     * and unusual deals need skippable stages"* — and a cash purchase
     * genuinely has no appraisal contingency. Forcing an override there would
     * fill §12.2's override metric with deals that differ rather than
     * processes that failed, which is the same as not measuring it.
     *
     * So this writes `stages.skipped_reason` and never touches
     * `gates.overridden`, exactly as `override()` writes the flag and never
     * touches this column.
     *
     * ## It moves the pointer only when it has to
     *
     * Skipping the stage a team is standing on is an advance-shaped act: the
     * next stage activates and `current_stage_id` follows, because a workflow
     * whose current stage is skipped has nowhere to be. Skipping a **future**
     * stage moves nothing — it is a note about a stage the team has not
     * reached, and the pointer is still correct.
     *
     * @throws SkipNeedsAReason when the reason is missing or too short
     * @throws StageNotOnWorkflow when the stage belongs to a different workflow
     */
    public function skip(
        Workflow $workflow,
        Stage $stage,
        Person $actor,
        string $reason,
    ): StageChangeResult {
        $reason = trim($reason);

        if (mb_strlen($reason) < self::MINIMUM_REASON_LENGTH) {
            throw SkipNeedsAReason::atLeast(self::MINIMUM_REASON_LENGTH);
        }

        return DB::transaction(function () use ($workflow, $stage, $actor, $reason): StageChangeResult {
            [$workflow, $stage] = $this->lockedPair($workflow, $stage);

            if (! $workflow->isRunning()) {
                return StageChangeResult::refused($workflow->state->advanceRefusal());
            }

            if ($stage->state === StageState::Skipped) {
                return StageChangeResult::refused('That stage is already skipped.');
            }

            if ($stage->state === StageState::Complete) {
                return StageChangeResult::refused(
                    'That stage is already complete. A stage that was worked cannot be marked not applicable — reopen it first if it was finished by mistake.',
                );
            }

            $wasCurrent = $workflow->current_stage_id === $stage->getKey();

            $stage->transitionTo(StageState::Skipped);
            $stage->forceFill([
                'skipped_reason' => $reason,
                // Not `completed_by`: nobody completed this. The audit entry
                // below is what names who decided it did not apply.
                'actual_end' => now(),
            ])->save();

            $next = $wasCurrent ? $this->nextWorkableStage($workflow, $stage) : null;
            $workflowCompleted = false;

            if ($wasCurrent) {
                if ($next instanceof Stage) {
                    $next->transitionTo(StageState::Active);
                    $next->forceFill(['actual_start' => now()])->save();
                } else {
                    $workflowCompleted = true;
                }

                $workflow->forceFill(['current_stage_id' => $next?->getKey()]);

                if ($workflowCompleted) {
                    $workflow->transitionTo(WorkflowState::Completed);
                    $workflow->forceFill(['actual_end' => now()]);
                }

                $workflow->save();
            }

            $deal = $workflow->deal;

            /*
             * Its own event type, like `gate.overridden`. `lib/activity.ts`
             * tints a row from the type, and a marker that has to be
             * recognised by reading the summary is not a marker.
             *
             * The summary names the stage and never the reason — the reason
             * is in the audit entry, which has the retention and the access
             * control for it. The same split `override()` makes.
             */
            $this->activity->record(
                subject: $workflow,
                eventType: 'stage.skipped',
                summary: "Skipped {$stage->name}",
                actor: $actor,
                deal: $deal,
            );

            if ($workflowCompleted) {
                $this->activity->record(
                    subject: $workflow,
                    eventType: 'workflow.completed',
                    summary: "Completed {$workflow->name}",
                    actor: $actor,
                    deal: $deal,
                );
            }

            $this->audit->record(
                action: 'workflow.stage_skipped',
                auditable: $stage,
                teamId: $workflow->team_id,
                actorPersonId: $actor->getKey(),
                reason: $reason,
                after: [
                    'stage_id' => $stage->getKey(),
                    'stage_name' => $stage->name,
                    'workflow_id' => $workflow->getKey(),
                    'activated_stage_id' => $next?->getKey(),
                ],
            );

            return StageChangeResult::applied(
                stage: $stage,
                current: $wasCurrent ? $next : $workflow->activeStage(),
                workflowCompleted: $workflowCompleted,
            );
        });
    }

    /**
     * Undo the last thing the workflow finished with (F4.12 · #70).
     *
     * *"People are wrong sometimes, and the alternative is a workaround that
     * leaves no trace."* An inspection stage closes, the report comes back
     * with a second issue, and the work reopens — #70's own example, and the
     * reason `Stage::stateTransitions()` already lets `complete` return to
     * `active`.
     *
     * ## Only the most recent one
     *
     * Reopening stage 3 of 8 while stage 6 is active has no defensible
     * meaning: either three completed stages silently un-complete, or the
     * workflow holds two active stages, and neither is what anybody asked
     * for. So this reopens the **last** stage that finished, which makes it
     * exactly "undo the last advance" — and repeating it walks backwards one
     * stage at a time, which is the same thing said slowly.
     *
     * ## The workflow stays completed, because it stays terminal
     *
     * `Workflow::stateTransitions()` decided this before #70 existed and said
     * why: *"reopen the inspection stage" is a real request, "un-complete the
     * entire sale" is not.* A completed workflow is refused here rather than
     * quietly reopened, because a stage made active inside a workflow that
     * `handle()` will not advance is a dead end — the shape of defect this
     * codebase keeps finding, where each half works and the pair does not.
     *
     * ## What does not un-happen
     *
     * An action that already fired stays fired: a client emailed when the
     * stage first completed must not be emailed again on the second advance.
     * Nothing here can enforce that yet — `action_instances` is Slice 3's
     * table — so the contract is recorded rather than implemented, and the
     * dedupe belongs on the sending side, keyed by the stage and the action
     * rather than by a count of advances.
     *
     * @throws StageNotOnWorkflow when the stage belongs to a different workflow
     */
    public function reopen(
        Workflow $workflow,
        Stage $stage,
        Person $actor,
    ): StageChangeResult {
        return DB::transaction(function () use ($workflow, $stage, $actor): StageChangeResult {
            [$workflow, $stage] = $this->lockedPair($workflow, $stage);

            if (! $workflow->isRunning()) {
                return StageChangeResult::refused(
                    $workflow->state === WorkflowState::Completed
                        ? 'That workflow is finished. Reopening a stage inside it would leave the stage somewhere the deal can never advance from.'
                        : $workflow->state->advanceRefusal(),
                );
            }

            if (! in_array($stage->state, [StageState::Complete, StageState::Skipped], true)) {
                return StageChangeResult::refused('That stage has not been finished, so there is nothing to reopen.');
            }

            $last = $this->lastFinishedStage($workflow);

            if (! $last instanceof Stage || $last->getKey() !== $stage->getKey()) {
                return StageChangeResult::refused(
                    'Only the most recently finished stage can be reopened. Reopen the ones after it first.',
                );
            }

            /*
             * Read **before** anything is written.
             *
             * `activeStage()` answers from the loaded `stages` collection when
             * there is one and from a query when there is not, so asking it
             * after `$stage` has been made active gets the stage being
             * reopened — and the displacement below then quietly does nothing,
             * leaving the workflow with two stages in progress. It survived
             * for a round only because `lastFinishedStage()` happened to
             * lazy-load the relation on its way past; a refactor that stopped
             * touching the property changed the answer of a line ten below it.
             */
            $displaced = $workflow->activeStage();

            $wasSkipped = $stage->state === StageState::Skipped;

            /*
             * A skipped stage returns through `pending`, because that is what
             * the map allows and what the state means: it was never worked, so
             * it goes back to the queue before it goes back to being the work.
             */
            if ($wasSkipped) {
                $stage->transitionTo(StageState::Pending);

                // Saved between the two hops on purpose: `HasStateMachine`'s
                // guard reads the **stored** state, so two transitions in one
                // save read as the single illegal one they add up to.
                $stage->save();
            }

            $stage->transitionTo(StageState::Active);
            $stage->forceFill([
                'actual_end' => null,
                'completed_by' => null,
                'skipped_reason' => null,
                'actual_start' => $stage->actual_start ?? now(),
            ])->save();

            // Whatever the team was standing on goes back in the queue. There
            // is always one, because a running workflow has a current stage.
            if ($displaced instanceof Stage && $displaced->getKey() !== $stage->getKey()) {
                $displaced->transitionTo(StageState::Pending);
                $displaced->forceFill(['actual_start' => null])->save();
            }

            $workflow->forceFill(['current_stage_id' => $stage->getKey()])->save();

            $this->activity->record(
                subject: $workflow,
                eventType: 'stage.reopened',
                summary: "Reopened {$stage->name}",
                actor: $actor,
                deal: $workflow->deal,
            );

            $this->audit->record(
                action: 'workflow.stage_reopened',
                auditable: $stage,
                teamId: $workflow->team_id,
                actorPersonId: $actor->getKey(),
                after: [
                    'stage_id' => $stage->getKey(),
                    'stage_name' => $stage->name,
                    'workflow_id' => $workflow->getKey(),
                    'was' => $wasSkipped ? StageState::Skipped->value : StageState::Complete->value,
                    'displaced_stage_id' => $displaced?->getKey(),
                ],
            );

            return StageChangeResult::applied(stage: $stage, current: $stage);
        });
    }

    /**
     * Both rows under lock, and the stage proved to be this workflow's.
     *
     * The same pairing `override()` does for a gate, and for the same reason:
     * without the ownership check a stage id from another team's workflow is
     * a cross-tenant write that the global scope alone does not stop, because
     * both rows can be inside the acting team.
     *
     * @return array{0: Workflow, 1: Stage}
     *
     * @throws StageNotOnWorkflow
     */
    private function lockedPair(Workflow $workflow, Stage $stage): array
    {
        $workflow = Workflow::query()
            ->whereKey($workflow->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $stage = Stage::query()->whereKey($stage->getKey())->lockForUpdate()->firstOrFail();

        if ($stage->workflow_id !== $workflow->getKey()) {
            throw StageNotOnWorkflow::for($stage, $workflow);
        }

        return [$workflow, $stage];
    }

    /**
     * The next stage a team could actually work, skipping the skipped.
     *
     * `stageAfter()` returns the literal next row, which is right for an
     * advance — it has no reason to walk past anything. Skipping the current
     * stage does: a team that marked three financing stages inapplicable on a
     * cash deal should land on the fourth, not stop on the second and have to
     * skip it again.
     */
    private function nextWorkableStage(Workflow $workflow, Stage $from): ?Stage
    {
        $candidate = $workflow->stageAfter($from);

        while ($candidate instanceof Stage && $candidate->state === StageState::Skipped) {
            $candidate = $workflow->stageAfter($candidate);
        }

        return $candidate;
    }

    /** The last stage, in the workflow's own order, that is complete or skipped. */
    /**
     * The stage a reopen would take, or null when there is none.
     *
     * **Behind the current one, not merely finished.** A `skip()` may be
     * applied to a *future* stage — it is a note that the stage does not
     * apply to this deal, and moves nothing — so "finished" alone selects a
     * stage the workflow has not reached. Reopening one of those made the
     * workflow jump **forward**: the skipped stage four became current, the
     * stage the team was actually standing on was displaced back to `pending`
     * with its `actual_start` nulled, and the deal silently skipped the work
     * in between.
     *
     * `StageTimeline` draws the Reopen control from `Stage::isReopenableIn()`
     * for exactly this reason — one rule, so the button and the service cannot
     * disagree about which row it belongs on.
     */
    private function lastFinishedStage(Workflow $workflow): ?Stage
    {
        return Stage::reopenableIn($workflow);
    }

    /**
     * Why this gate cannot be overridden, or null when it can.
     *
     * Four sentences rather than one, because each names a different thing to
     * do next. They also keep the §12.2 metric honest: *"share of advances
     * using override, target under 15%"* is unreadable if the column also
     * holds overrides of gates that were never in the way.
     */
    /**
     * Both halves of confirming, because the only difference is the boolean.
     *
     * Written once so the two verbs cannot drift apart on the questions that
     * are the same for both — whose workflow, is it running, is it the stage
     * we are standing on, and is it the one gate type this applies to.
     */
    private function setConfirmation(
        Workflow $workflow,
        Gate $gate,
        Person $actor,
        bool $confirmed,
    ): ConfirmResult {
        return DB::transaction(function () use ($workflow, $gate, $actor, $confirmed): ConfirmResult {
            $workflow = Workflow::query()
                ->whereKey($workflow->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Re-read under the lock, the way `override()` does. Two people
             * ticking the same row from two screens produce one activity entry
             * and one "already confirmed", rather than two entries claiming
             * two different people did it.
             */
            $gate = Gate::query()->whereKey($gate->getKey())->lockForUpdate()->firstOrFail();
            $stage = $gate->stage;

            if (! $stage instanceof Stage || $stage->workflow_id !== $workflow->getKey()) {
                throw GateNotOnWorkflow::for($gate, $workflow);
            }

            if (! $workflow->isRunning()) {
                return ConfirmResult::refused($workflow->state->advanceRefusal());
            }

            $refusal = $this->confirmationRefusal($gate, $stage, $confirmed);

            if ($refusal !== null) {
                return ConfirmResult::refused($refusal);
            }

            /*
             * `overridden` is deliberately untouched, in both directions.
             * IA §8 keeps met and overridden apart, and a gate that was
             * overridden and is now genuinely met should read as both — the
             * override is what happened, and no later tick unhappens it.
             */
            /*
             * `met_by` and `met_at` alongside, because **this** is what those
             * columns were reserved for.
             *
             * `evaluateGates()`'s own note calls them the record of *"a human
             * ticking something"*, and until this route existed nothing in the
             * application wrote either — the cache refresh sets `is_met` from
             * an evaluator, which is not a person. So `Gate::metBy()` resolved
             * to null forever and two columns sat dead beside the one this
             * service had learned to move.
             *
             * Cleared on the way back, for the reason the flag is: an unticked
             * gate that still names who ticked it is a record disagreeing with
             * itself.
             */
            $gate->forceFill([
                'is_met' => $confirmed,
                'met_by' => $confirmed ? $actor->getKey() : null,
                'met_at' => $confirmed ? now() : null,
            ])->save();

            $deal = $workflow->deal;

            $this->activity->record(
                subject: $workflow,
                eventType: $confirmed ? 'gate.confirmed' : 'gate.unconfirmed',
                summary: $confirmed
                    ? "Confirmed {$gate->label} on {$stage->name}"
                    : "Took back the confirmation of {$gate->label} on {$stage->name}",
                actor: $actor,
                deal: $deal,
            );

            /*
             * No audit entry, and that is the line rather than an oversight.
             * PRD §9 lists what `audit_log` covers — auth, permission changes,
             * **gate overrides**, document access, extractions, impersonation
             * — and confirming is the ordinary path, done many times a deal.
             * Writing every tick into an append-only table with its own
             * retention would bury the overrides it exists to make findable.
             * The timeline is the record for this, and it names the actor.
             */

            return ConfirmResult::confirmed($gate);
        });
    }

    /**
     * Why a confirmation cannot be written, or null when it can.
     */
    private function confirmationRefusal(Gate $gate, Stage $stage, bool $confirmed): ?string
    {
        if ($gate->gate_type !== ManualConfirmationEvaluator::type()) {
            /*
             * Every other evaluator derives its answer from something real, so
             * a tick would be a claim rather than a cache — and the next
             * advance would overwrite it from the evaluator anyway.
             */
            return "{$gate->label} is not something to tick — it clears when the thing it checks is true.";
        }

        if (! $stage->isInProgress()) {
            return "{$stage->name} is not the stage this workflow is in, so confirming a requirement "
                .'on it would change nothing about what happens next.';
        }

        if ($gate->is_met === $confirmed) {
            return $confirmed
                ? "{$gate->label} is already confirmed. Advance when you are ready."
                : "{$gate->label} is not confirmed, so there is nothing to take back.";
        }

        return null;
    }

    private function overrideRefusal(Gate $gate, Stage $stage): ?string
    {
        if (! $stage->isInProgress()) {
            return "{$stage->name} is not the stage this workflow is in, so overriding a gate on it "
                .'would change nothing about what happens next.';
        }

        if ($gate->overridden) {
            return "{$gate->label} has already been overridden. Advance when you are ready.";
        }

        if (! $gate->is_blocking) {
            return "{$gate->label} is an advisory — it never stops an advance, so there is nothing "
                .'to override. Advance when you are ready.';
        }

        /*
         * Evaluated, not read off `is_met`.
         *
         * The cache is refreshed only by an advance attempt, so a gate a
         * colleague cleared this morning still reads unmet — and an override
         * written against it would say in the permanent record that the survey
         * was missing when it was not.
         */
        if ($this->gates->evaluate($gate)->met) {
            return "{$gate->label} is met. Nothing needs overriding — advance when you are ready.";
        }

        return null;
    }

    /**
     * The task that carries the bypassed obligation forward (#69).
     *
     * *"An override defers an obligation; it does not delete one."*
     *
     * **Not required, and that is not timidity.** `is_required` feeds the
     * `required_tasks_complete` evaluator, which counts the required tasks on
     * *this* stage — the stage the person is about to advance out of. A
     * required follow-up would therefore be counted by a tasks gate on the
     * same stage and would block the very advance the override exists to
     * permit, one click after clearing the thing that was blocking it.
     *
     * **Due today**, because the obligation was due when the gate was. The
     * override moved the workflow, not the deadline, so this is overdue
     * tomorrow — which is loud, and is the point. A follow-up with no due date
     * sorts to the bottom of My Work (S11 is *"my open tasks, soonest
     * first"*), which is where a deferred obligation goes to be forgotten.
     */
    /*
     * No `$reason` parameter, deliberately. It was passed in and interpolated
     * into the description; dropping the argument rather than just the
     * interpolation means putting it back is a signature change somebody has
     * to mean, not a string edit.
     */
    private function followUpFor(Gate $gate, Stage $stage, Person $actor): Task
    {
        $task = new Task;

        $task->forceFill([
            'team_id' => $gate->team_id,
            'deal_id' => $stage->workflow->deal_id,
            'stage_id' => $stage->getKey(),
            // #69: the follow-up appears in My Work "with the deal and the
            // gate named". The deal comes from the row; the gate has to be in
            // the title, because a task list shows titles.
            'title' => "Follow up on the overridden gate: {$gate->label}",
            /*
             * **Not "was advanced".** Overriding does not advance — waiving
             * one of three blocking gates must not move the deal past the
             * other two — so at the moment this task is written the stage is
             * exactly where it was. A description that says otherwise is
             * wrong on the deal that is still sitting there, and it
             * contradicts the invariant three methods up.
             *
             * **And not the reason.** The reason is free text somebody typed
             * about a live transaction, and the comment beside the timeline
             * marker above says where it lives: the audit entry, which has
             * the retention and the access control for it. A task is read by
             * anyone who can open My Work (S11), so copying it here is the
             * protection being described in one place and given away in
             * another. The task names the gate; the audit log holds the why.
             */
            'description' => 'This gate was overridden rather than met, so the obligation behind it '
                ."still stands. {$stage->name} has not advanced. The reason given is recorded in "
                .'the audit log against this gate.',
            'assignee_id' => $actor->getKey(),
            'due_date' => now()->toDateString(),
            'is_required' => false,
            'source' => TaskSource::Override->value,
            'sort_order' => 0,
        ])->save();

        return $task;
    }

    /**
     * @param  array<string, GateVerdict>  $advisories
     */
    private function applyAdvance(
        Workflow $workflow,
        Stage $stage,
        ?Person $actor,
        array $advisories,
    ): AdvanceResult {
        $stage->transitionTo(StageState::Complete);
        $stage->forceFill([
            'actual_end' => now(),
            'completed_by' => $actor?->getKey(),
        ])->save();

        $next = $workflow->stageAfter($stage);

        if ($next instanceof Stage) {
            $next->transitionTo(StageState::Active);
            $next->forceFill(['actual_start' => now()])->save();
        }

        $workflowCompleted = ! $next instanceof Stage;

        $workflow->forceFill(['current_stage_id' => $next?->getKey()]);

        if ($workflowCompleted) {
            $workflow->transitionTo(WorkflowState::Completed);
            $workflow->forceFill(['actual_end' => now()]);
        }

        $workflow->save();

        /*
         * The milestone moment (IA §3).
         *
         * A stage is a period and a milestone is a moment, so the moment is
         * *this* — the completion — rather than anything stored on the stage.
         * The label is what a client would be told; the internal stage name
         * never reaches them (IA §9). Slice 3 turns this into a message; here
         * it is recorded and returned so the advance modal can say "this will
         * tell the client" before Slice 3 makes that true.
         */
        $announcement = $stage->clientAnnouncement();

        /*
         * The subject is the workflow and the deal is where a team looks for
         * it. One deal runs several workflows at once (F4.7), so an advance
         * subjected to the workflow but with no `deal_id` is an event the
         * deal's own timeline (S16) and the team feed's deal filter cannot
         * find.
         */
        $deal = $workflow->deal;

        $this->activity->record(
            subject: $workflow,
            eventType: 'stage.advanced',
            summary: "Advanced past {$stage->name}",
            actor: $actor,
            deal: $deal,
        );

        if ($announcement !== null) {
            $this->activity->record(
                subject: $workflow,
                eventType: 'milestone.reached',
                summary: $announcement,
                actor: $actor,
                deal: $deal,
            );
        }

        if ($workflowCompleted) {
            $this->activity->record(
                subject: $workflow,
                eventType: 'workflow.completed',
                summary: "Completed {$workflow->name}",
                actor: $actor,
                deal: $deal,
            );
        }

        /*
         * Audited, not just timelined.
         *
         * The two are different records with different readers and different
         * retention (`CLAUDE.md`: Activity is not History is not Audit). An
         * advance changes what a team is contractually on the hook for next,
         * so it belongs in the append-only record as well as the readable one.
         */
        $this->audit->record(
            action: 'workflow.advanced',
            auditable: $workflow,
            teamId: $workflow->team_id,
            actorPersonId: $actor?->getKey(),
            after: [
                'completed_stage_id' => $stage->getKey(),
                'activated_stage_id' => $next?->getKey(),
            ],
        );

        return AdvanceResult::advanced(
            completedStage: $stage,
            activatedStage: $next,
            workflowCompleted: $workflowCompleted,
            milestoneAnnouncement: $announcement,
            advisories: $advisories,
        );
    }

    /**
     * Every gate on the stage, evaluated fresh.
     *
     * `gates.is_met` is not consulted as authority — it is a cache for
     * rendering, and a stale cached `true` read at advance time is precisely
     * the failure this product cannot have. The cache is refreshed here as a
     * side effect so the screens that do read it stay honest.
     *
     * @return array<string, GateVerdict>
     */
    private function evaluateGates(Stage $stage): array
    {
        $verdicts = [];

        foreach ($stage->gates as $gate) {
            $verdict = $this->gates->evaluate($gate);

            $verdicts[$gate->getKey()] = $verdict;

            // Only the derived answer is cached. `met_at` and `met_by` record
            // a human ticking something and are never written by an
            // evaluation, or every re-render would rewrite who confirmed it.
            if ($gate->is_met !== $verdict->met) {
                $gate->forceFill(['is_met' => $verdict->met])->save();
            }
        }

        return $verdicts;
    }

    private function gateById(Stage $stage, string $gateId): Gate
    {
        return $stage->gates->firstWhere('id', $gateId)
            ?? throw new RuntimeException("Gate [{$gateId}] is not on stage [{$stage->getKey()}].");
    }
}
