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
    public function handle(Workflow $workflow, ?Person $actor = null): AdvanceResult
    {
        return DB::transaction(function () use ($workflow, $actor): AdvanceResult {
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

            $stage = $workflow->activeStage();

            if (! $stage instanceof Stage) {
                throw NothingToAdvance::for($workflow);
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
                 * in and cannot leave, and clearing the gate puts it back to
                 * active on the next attempt.
                 */
                if ($stage->state === StageState::Active) {
                    $stage->transitionTo(StageState::Blocked)->save();
                }

                return AdvanceResult::blocked($stage, $blocking, $advisories);
            }

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

        $this->activity->record(
            subject: $workflow,
            eventType: 'stage.advanced',
            summary: "Advanced past {$stage->name}",
            actor: $actor,
        );

        if ($announcement !== null) {
            $this->activity->record(
                subject: $workflow,
                eventType: 'milestone.reached',
                summary: $announcement,
                actor: $actor,
            );
        }

        if ($workflowCompleted) {
            $this->activity->record(
                subject: $workflow,
                eventType: 'workflow.completed',
                summary: "Completed {$workflow->name}",
                actor: $actor,
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
