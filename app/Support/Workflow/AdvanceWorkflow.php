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
     * Why this gate cannot be overridden, or null when it can.
     *
     * Four sentences rather than one, because each names a different thing to
     * do next. They also keep the §12.2 metric honest: *"share of advances
     * using override, target under 15%"* is unreadable if the column also
     * holds overrides of gates that were never in the way.
     */
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
