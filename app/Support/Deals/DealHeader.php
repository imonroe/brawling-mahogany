<?php

declare(strict_types=1);

namespace App\Support\Deals;

use App\Models\Deal;
use App\Models\DealParticipant;
use App\Models\DealProperty;
use App\Models\Stage;
use App\Models\Task;
use App\Models\Workflow;
use App\Queries\PropertyDirectory;

/**
 * The props behind `components/app/DealHeader.vue` (Design System §8.4 · #75).
 *
 * §8.4's first line is *"Shared by all eight deal tabs (S15–S22)"*, so the
 * payload is shared too. Building it in each deal controller is the shape this
 * codebase keeps finding a defect in: the Overview would carry a client name,
 * People would carry a slightly different one, and the header would read
 * differently depending on which tab you were standing on.
 *
 * It is a static presenter rather than a service because it decides nothing —
 * it reads a deal that is already in memory and names its parts.
 *
 * ## What it loads, and why it can afford to
 *
 * `loadMissing`, so a controller that has already eager-loaded a relation for
 * its own screen pays nothing, and one that has not pays a constant handful of
 * queries rather than one per row. Counts come from a loaded relation when
 * there is one and from `loadCount` otherwise — never from a `count()` per
 * render of a row, which is what the budget tests refuse.
 */
final class DealHeader
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Deal $deal): array
    {
        $deal->loadMissing([
            'dealType',
            'participants.membership',
            'propertyLinks.property',
            'workflows.stages',
        ]);

        return [
            'id' => $deal->getKey(),
            // IA §10: the typed name wins over the derived one, and
            // `displayName()` is the only thing that decides which.
            'name' => $deal->displayName(),
            'state' => $deal->state->value,
            'dealTypeName' => $deal->dealType->name,
            'sideLabel' => $deal->dealType->side->label(),
            'clientName' => self::clientName($deal),
            /*
             * Address parts, not a formatted string. IA §10 fixes every
             * formatting rule and `lib/formatters.ts` is where they live; a
             * controller assembling "Indianapolis, IN" here is the
             * ninety-one-screens problem starting.
             */
            'location' => self::location($deal),
            'counts' => [
                'people' => $deal->participants->count(),
                'properties' => $deal->propertyLinks->count(),
                'tasks' => self::openTasks($deal),
            ],
            'advance' => self::advanceTarget($deal),
        ];
    }

    /**
     * The number on the Tasks tab: what is still **open** (#71).
     *
     * The one count in this payload that is not a total, and the difference is
     * deliberate. People and Properties count what the deal *has*; a checklist
     * counts what is left, because Emily's listing pack seeds eighty tasks and
     * a tab reading `80` on a deal where all eighty are done says the opposite
     * of what happened. It is the same argument §7.4 makes for the stage rail's
     * counts — the number has to mean what a reader will assume it means.
     *
     * Counted from the relation when a screen has already loaded it, and with
     * a constrained `loadCount` otherwise: one query, whatever the deal holds.
     * The tabs that do load it (S17) pay nothing for this.
     */
    private static function openTasks(Deal $deal): int
    {
        if ($deal->relationLoaded('tasks')) {
            return $deal->tasks->filter(
                fn (Task $task): bool => ! $task->isComplete(),
            )->count();
        }

        $deal->loadCount([
            'tasks as open_tasks_count' => fn ($query) => $query->whereNull('completed_at'),
        ]);

        return (int) $deal->getAttribute('open_tasks_count');
    }

    /**
     * Which workflow §8.4's single **Advance Stage** button would move.
     *
     * ## The gap this closes, and the reason it is a gap
     *
     * PRD §7.5 gives a deal concurrent workflows on purpose — pre-listing
     * improvements and the sale run at once — so `Deal::workflows()` is a
     * `HasMany`. Design System §8.4 nonetheless specifies **one** primary
     * Advance button in the header, and neither document says what the button
     * does when there are two workflows to advance.
     *
     * Settled as: the header offers it only when exactly one workflow is
     * running with a stage to leave. With two, the header has no primary
     * action and the Overview's per-workflow cards carry one each — a primary
     * action that silently picks one of two workflows is worse than no primary
     * action, and there is no honest label for "advance one of these".
     *
     * ## No gates are evaluated here
     *
     * This runs on every deal tab, and seven evaluators per gate per tab to
     * decide whether a button is *enabled* is a poor trade. The button is an
     * attempt: `AdvanceWorkflow` evaluates inside its transaction and
     * `AdvanceResult` carries every reason it refused, which is the behaviour
     * S23 is built on. The Overview, which is the screen #75 holds to
     * *"visible without interaction"*, does show the blockers — from
     * `DescribeBlockers`, beside the card.
     *
     * `stageId` travels with it so the post can say which stage the reader was
     * looking at; `AdvanceWorkflow` refuses when somebody else has moved on.
     *
     * @return array{workflowId: string, stageId: string}|null
     */
    private static function advanceTarget(Deal $deal): ?array
    {
        $candidates = $deal->workflows
            ->filter(fn (Workflow $workflow): bool => $workflow->isRunning()
                && $workflow->activeStage() instanceof Stage)
            ->values();

        if ($candidates->count() !== 1) {
            return null;
        }

        /** @var Workflow $workflow */
        $workflow = $candidates->first();
        /** @var Stage $stage */
        $stage = $workflow->activeStage();

        return [
            'workflowId' => (string) $workflow->getKey(),
            'stageId' => (string) $stage->getKey(),
        ];
    }

    /**
     * Whose deal this is, for the meta row.
     *
     * The main contact when somebody has said which, and otherwise the first
     * participant — the same order `Deal::participants()` sorts by, so the
     * header and the People tab name the same human. Null when the deal has
     * nobody on it yet, which is an ordinary state on a deal five minutes old
     * and renders as an absent pair rather than as "Unknown".
     */
    private static function clientName(Deal $deal): ?string
    {
        $participant = $deal->participants->firstWhere('is_primary', true)
            ?? $deal->participants->first();

        return $participant instanceof DealParticipant
            ? $participant->fullName()
            : null;
    }

    /**
     * @return array<string, string|null>|null
     */
    private static function location(Deal $deal): ?array
    {
        $subject = $deal->propertyLinks->firstWhere('is_subject', true);

        if (! $subject instanceof DealProperty || $subject->property === null) {
            return null;
        }

        return PropertyDirectory::address($subject->property);
    }
}
