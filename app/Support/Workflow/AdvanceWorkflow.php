<?php

declare(strict_types=1);

namespace App\Support\Workflow;

use App\Enums\StageState;
use App\Enums\WorkflowState;
use App\Models\Gate;
use App\Models\Person;
use App\Models\Stage;
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
 */
final class AdvanceWorkflow
{
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
