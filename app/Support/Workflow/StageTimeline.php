<?php

declare(strict_types=1);

namespace App\Support\Workflow;

use App\Enums\StageState;
use App\Models\Gate;
use App\Models\Stage;
use App\Models\Task;
use App\Models\Workflow;

/**
 * One workflow, drawn as Design System §7.4's stage rail (S16 · PRD F4.6–F4.8).
 *
 * Screen Inventory calls this *"the one interaction with no obvious precedent
 * to copy"*, and §7.4 specifies it to the pixel for that reason. What is
 * decided **here** rather than in the component is everything that is a
 * question about the domain rather than about layout — because a rail that
 * worked out for itself what "overridden" means would be a second opinion, and
 * this product already keeps one copy of every such answer.
 *
 * Three of those answers are worth stating outright.
 *
 * ## A rail per workflow, never a merged one
 *
 * F4.7 lets pre-listing improvements and the sale itself run concurrently, and
 * #76 names that as the case that *"breaks naive designs"*. It breaks them by
 * inviting a single merged rail — and two workflows have independent stage
 * sequences with no shared order, so a merged rail has to invent one. Sorting
 * by date is the obvious invention and it is wrong: stage four of the sale can
 * be planned before stage two of the improvements without either being "next".
 *
 * So the screen is a list of rails. Two workflows are two rails, which is also
 * what `DealHeader::advance()` already assumes when it refuses to name an
 * advance target while two are running.
 *
 * ## Overridden is a marker, not a stage state
 *
 * §7.4's marker table has an Overridden row. IA §8's stage vocabulary does
 * not — it has exactly five states, and `lib/states.ts` throws on a sixth
 * rather than render an unstyled badge. Both are right, and they are answering
 * different questions: the stage is `complete`, and *how* it completed is a
 * separate fact.
 *
 * So `hasOverride` rides alongside `state`. The marker takes §7.4's
 * `shield-alert` when it is true; the badge goes on saying Complete. IA §8 is
 * emphatic that overridden is not a kind of met, and this is the same
 * distinction one level up: it is not a kind of *state* either.
 *
 * ## The active stage's state is live; every other stage's is history
 *
 * `stages.state` is a cache that only an advance attempt refreshes — its own
 * model says so. So a stage cached `blocked`, whose gate somebody has since
 * satisfied, goes on badging Blocked until the next attempt. On a hub that is
 * a stale badge; on §7.4's expanded card it is incoherent *within one card*,
 * because the requirements pane beside the badge would show nothing in the
 * way.
 *
 * `DescribeBlockers` is the live answer and writes nothing, so the active
 * stage is badged from it. Nothing else is: a complete stage's gates are a
 * record of what happened, not a question still open, and re-evaluating twenty
 * of them per render to re-derive a fact that cannot change is work with no
 * reader.
 *
 * That same split decides which gates each row carries — live checklist for
 * the active stage, recorded state for the rest.
 */
final readonly class StageTimeline
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Workflow $workflow, ?StageReadiness $readiness): array
    {
        $active = $workflow->activeStage();

        return [
            'id' => $workflow->getKey(),
            'name' => $workflow->name,
            'state' => $workflow->state->value,
            'stateLabel' => $workflow->state->label(),
            'isRunning' => $workflow->isRunning(),
            /*
             * Why there is no Advance on this rail, when there is not one.
             * `WorkflowState::advanceRefusal()` is a sentence per state, and a
             * card that simply omits the button leaves the reader to guess
             * between "on hold", "finished" and "not started".
             */
            'refusal' => $workflow->isRunning() ? null : $workflow->state->advanceRefusal(),
            'activeStageId' => $active?->getKey(),
            'canAdvance' => $workflow->isRunning() && $readiness?->canAdvance() === true,
            'stages' => $workflow->stages
                ->values()
                ->map(fn (Stage $stage, int $index): array => self::stage(
                    $stage,
                    $index,
                    $active instanceof Stage && $stage->is($active) ? $readiness : null,
                ))
                ->all(),
        ];
    }

    /**
     * One row of the rail.
     *
     * @return array<string, mixed>
     */
    private static function stage(Stage $stage, int $index, ?StageReadiness $readiness): array
    {
        $isActive = $readiness instanceof StageReadiness;

        return [
            'id' => $stage->getKey(),
            'name' => $stage->name,
            /*
             * 1-based, and it has a reader: the row's accessible name is
             * *"Stage 3 of 20: Listing Preparation"*, because the rail's whole
             * argument is that a stage is legible **in its sequence** and a
             * screen reader gets none of the line and markers that carry that
             * visually.
             *
             * `description` used to ride along here and was read by nothing —
             * §7.4's expanded card has no slot for it. CLAUDE.md's rule for an
             * eager-load is the same rule for a payload field: name the cell
             * that reads it, and if there is not one, that is the finding.
             */
            'position' => $index + 1,
            'isActive' => $isActive,

            /*
             * Live for the active stage, cached for the rest — see the class
             * docblock. `blocked` and `active` are the only two an evaluation
             * can move between, which is why this reads as a choice of badge
             * rather than a state machine: `DescribeBlockers` writes nothing,
             * so this is a *rendering* of the live answer and not a transition.
             */
            'state' => $isActive
                ? $readiness->stageState()->value
                : $stage->state->value,

            /*
             * Whether, not which. §7.4's pill carries the word `Milestone`, and
             * `milestone_label` is the sentence a **client** is told about the
             * moment (IA §3) — its reader is the status page, S62. Shipping it
             * here left a typed, asserted field nothing rendered.
             */
            'isMilestone' => $stage->is_milestone,

            /*
             * Planned dates are days and actual dates are moments — the casts
             * say so — and the row needs both, because it prefers what happened
             * over what was planned. All four go out as ISO 8601 and the screen
             * formats them; IA §10 fixes the rule and `lib/formatters.ts` owns
             * it, so nothing here spells a date.
             */
            'plannedStart' => $stage->planned_start?->toIso8601String(),
            'plannedEnd' => $stage->planned_end?->toIso8601String(),
            'actualStart' => $stage->actual_start?->toIso8601String(),
            'actualEnd' => $stage->actual_end?->toIso8601String(),

            /*
             * F4.12's skip is #70's work and nothing writes this column yet.
             * It is carried anyway because a skipped stage with no reason
             * beside it is the exact shape of the complaint IA §7 makes about
             * conflating Skip with Override: both remove an obligation, and
             * only one of them is supposed to say why.
             */
            'skippedReason' => $stage->skipped_reason,

            'hasOverride' => $stage->gates->contains(
                fn (Gate $gate): bool => $gate->overridden,
            ),

            'tasks' => self::tasks($stage),
            'gates' => $isActive
                ? $readiness->checklist()
                : self::recordedGates($stage),
            'gateCounts' => $isActive
                ? $readiness->counts()
                : self::recordedCounts($stage),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function tasks(Stage $stage): array
    {
        $tasks = $stage->tasks;

        return [
            'total' => $tasks->count(),
            'complete' => $tasks->filter(
                fn (Task $task): bool => $task->isComplete(),
            )->count(),
            'items' => $tasks->map(fn (Task $task): array => [
                'id' => $task->getKey(),
                'title' => $task->title,
                'state' => $task->state()->value,
                'isRequired' => $task->is_required,
                'dueDate' => $task->due_date?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * A finished or unstarted stage's gates, as recorded rather than evaluated.
     *
     * Deliberately the same field names `StageReadiness::describe()` emits, so
     * one component draws either — `GateRow` takes a `GateSummary` and does not
     * care which question produced it.
     *
     * `linkTarget` is empty because a row that is not a live question has
     * nothing to go and clear.
     *
     * **`explanation` is not empty**, though, and it cannot be: §7.4 says the
     * sub-line *"always states the gate type and its evidence"*, and that it is
     * *"what makes a refusal actionable"*. An empty one renders as a blank line
     * under every gate on every finished stage. What it says here is what was
     * **recorded** rather than what an evaluator would find — which is the
     * honest thing to say about a stage that is over, and needs no evaluation
     * to say it.
     *
     * An overridden gate says who decided and why, because F4.9 requires the
     * reason to be captured and this is the one screen that shows the stage it
     * was captured on.
     *
     * @return list<array<string, mixed>>
     */
    private static function recordedGates(Stage $stage): array
    {
        return array_values($stage->gates->map(fn (Gate $gate): array => [
            'id' => $gate->getKey(),
            'label' => $gate->label,
            'gateType' => $gate->gate_type,
            'isBlocking' => $gate->is_blocking,
            // Nothing on a stage nobody is advancing is in the way *right now*.
            'blocksAdvance' => false,
            'gateState' => $gate->state()->value,
            'met' => $gate->is_met,
            'explanation' => self::recordedExplanation($gate, $stage),
            'linkTarget' => [],
        ])->all());
    }

    /**
     * What the record says about a gate, in §7.4's sub-line shape.
     *
     * The gate type first, because that is what the sub-line leads with
     * everywhere else — `Manual confirmation · Heather Nguyen, 12 Aug` — and a
     * reader comparing a finished stage with the current one should not have to
     * change how they read the line halfway down the page.
     */
    private static function recordedExplanation(Gate $gate, Stage $stage): string
    {
        $type = ucfirst(str_replace('_', ' ', $gate->gate_type));

        if ($gate->overridden) {
            $reason = trim((string) $gate->override_reason);

            return $reason === ''
                ? "{$type} · overridden, with no reason recorded"
                : "{$type} · overridden: {$reason}";
        }

        if ($gate->is_met) {
            // "Recorded as", not "is": `is_met` is a cache that an advance
            // attempt writes, and the model says so outright.
            return "{$type} · recorded as met";
        }

        /*
         * **A stage that has not started has not failed anything.**
         *
         * An unmet gate on an upcoming stage is a condition somebody will meet
         * in a fortnight, and the first wording here — *"never met on this
         * stage"* — put that past tense on every requirement of every future
         * stage in the workflow. On a twenty-stage rail that is a page of
         * things reading as though they had already gone wrong.
         *
         * **And a skipped stage is not a completed one**, which is why this
         * reads `$stage->state` rather than `isFinished()`: that method is
         * `[Complete, Skipped]`, and lumping them together says a skipped
         * stage's condition went unmet *before the stage ended* — as though
         * somebody worked through it and fell short. Nobody worked it. IA §7
         * keeps Skip and Override apart for exactly this reason, and the same
         * care applies one method along.
         *
         * The skipped arm says what was **recorded**, not what was required.
         * It read *"not required, because this stage was skipped"* for a round,
         * under a badge reading **Not Met** — the sub-line saying the condition
         * did not apply, eight pixels beneath a badge saying somebody failed to
         * satisfy it. Skipping a stage does not retroactively make its
         * conditions optional; it means nobody evaluated them.
         *
         * (Two earlier versions of this comment guessed at the styling too, and
         * both were wrong: these rows are never tinted `state-warning`, because
         * `GateRow` tints on `blocksAdvance` and `recordedGates()` sets that
         * false for every one of them. Describing only what was checked is the
         * whole point of the rule this comment is about.)
         */
        return match ($stage->state) {
            StageState::Complete => "{$type} · not recorded as met before this stage ended",
            StageState::Skipped => "{$type} · not recorded as met; this stage was skipped",
            default => "{$type} · not yet recorded",
        };
    }

    /**
     * @return array<string, int>
     */
    private static function recordedCounts(Stage $stage): array
    {
        $overridden = $stage->gates->filter(
            fn (Gate $gate): bool => $gate->overridden,
        )->count();

        $met = $stage->gates->filter(
            fn (Gate $gate): bool => $gate->is_met && ! $gate->overridden,
        )->count();

        return [
            'total' => $stage->gates->count(),
            'blocking' => 0,
            'advisory' => $stage->gates->count() - $met - $overridden,
            'overridden' => $overridden,
            // §7.4: *"cleared", not "met"* — met plus overridden, the same
            // arithmetic `StageReadiness::counts()` does for the live case.
            'cleared' => $met + $overridden,
        ];
    }
}
