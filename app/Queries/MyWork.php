<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\TaskState;
use App\Models\Deal;
use App\Models\Person;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * S11 — every task assigned to me, across every deal (PRD F9.2 · #80).
 *
 * *"Heather's primary screen."* PRD §3.4 puts her on a phone between showings
 * with twenty-five deals running, and F9.2 asks for one thing: *"every task
 * assigned to me across all deals, ordered by urgency."*
 *
 * ## Why this is a query object and not a controller method
 *
 * Two readers, not one. The screen needs the rows; the shell needs the
 * **count**, on every page, because Design System §10.4 puts it in the sidebar
 * beside the link. A count derived separately from the list is a count that
 * disagrees with it the first time either changes — so `countFor()` and
 * `rows()` narrow through the same `assigned()`.
 *
 * ## Assignment is by person, and `people` has no `team_id`
 *
 * `TaskAssignees` records the hazard: `tasks.assignee_id` points at `people`,
 * which carries no tenancy column, so the global scope protects nothing there.
 * It does not have to here — the filter is *this person's own id*, and the
 * tasks themselves are team-scoped. Somebody in two teams sees each team's
 * tasks only while that team is resolved, which is the behaviour a team
 * switcher is for.
 */
final class MyWork
{
    /**
     * The tasks this person owes, in the team currently resolved.
     *
     * @return Builder<Task>
     */
    public static function assigned(Person $person): Builder
    {
        return Task::query()
            ->where('assignee_id', $person->getKey())
            /*
             * A task on a soft-deleted deal is not work. The relation is what
             * asks — `whereHas` applies `Deal`'s own scopes, so a purged or
             * archived deal drops out without this file knowing how that is
             * spelled.
             */
            ->whereHas('deal');
    }

    /**
     * The number the sidebar carries, and the definition of it.
     *
     * **Open, not all.** A badge counting completed work would climb all week
     * and mean nothing; §10.4's count answers *"how much is on me"*, which is
     * a question about what is left.
     */
    public static function countFor(Person $person): int
    {
        return self::assigned($person)->open()->count();
    }

    /**
     * Every open task, ordered by urgency and grouped by deal.
     *
     * One query for the tasks and one for their deals, because the screen
     * shows fifty rows across a dozen deals and a per-row `$task->deal` is the
     * N+1 `MyWorkBudgetTest` exists to catch.
     *
     * @return array{groups: array<int, array<string, mixed>>, counts: array<string, int>}
     */
    public static function forPerson(Person $person, string $segment = 'open'): array
    {
        $tasks = self::assigned($person)
            ->with('deal')
            ->get();

        $open = $tasks->filter(fn (Task $task): bool => ! $task->isComplete());

        $overdue = $open->filter(
            /*
             * Asked of `Task::state()` rather than re-derived from the column.
             * `state()` reads today in the **team's** calendar, and two prior
             * bugs are recorded on it: a task due today counted as overdue
             * from 00:00:01, and then the same mistake moved seven hours west
             * by comparing against UTC's start of day. The one screen that
             * cannot be wrong about which day it is is the one Heather opens
             * first.
             */
            fn (Task $task): bool => $task->state() === TaskState::Overdue,
        );

        $counts = [
            'open' => $open->count(),
            'overdue' => $overdue->count(),
            'all' => $tasks->count(),
            'deals' => $open->pluck('deal_id')->unique()->count(),
        ];

        $shown = match ($segment) {
            'overdue' => $overdue,
            'all' => $tasks,
            default => $open,
        };

        return [
            'groups' => self::groups($shown),
            'counts' => $counts,
        ];
    }

    /**
     * Grouped by deal, and the **groups** are ordered by their most urgent row.
     *
     * F9.2 asks for one list ordered by urgency, and PRD §6.1's schema already
     * decided the grouping — `tasks.deal_id` is not nullable *"because My Work
     * groups by deal"*. Both are satisfied by sorting the rows once and letting
     * the groups fall out in that order: the deal holding the most overdue
     * thing is the deal at the top, which is what "ordered by urgency" means
     * when the rows are grouped at all.
     *
     * @param  Collection<int, Task>  $tasks
     * @return array<int, array<string, mixed>>
     */
    private static function groups(Collection $tasks): array
    {
        return $tasks
            ->sort(self::byUrgency(...))
            ->groupBy('deal_id')
            ->map(function (Collection $forDeal): array {
                /** @var Task $first */
                $first = $forDeal->first();
                $deal = $first->deal;

                return [
                    'dealId' => $first->deal_id,
                    'dealName' => $deal instanceof Deal ? $deal->displayName() : 'Untitled deal',
                    'dealUrl' => '/deals/'.$first->deal_id,
                    'tasks' => $forDeal->map(self::row(...))->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(Task $task): array
    {
        return [
            'id' => $task->getKey(),
            'title' => $task->title,
            'state' => $task->state()->value,
            'isRequired' => $task->is_required,
            // A day, not an instant (#165): `toDateString()`, and the browser
            // reads a bare date as the day it says.
            'dueDate' => $task->due_date?->toDateString(),
            'completedAt' => $task->completed_at?->toIso8601String(),
        ];
    }

    /**
     * Open before complete, then soonest due, then undated, then the order the
     * checklist was written in.
     *
     * The same comparator S17's tab uses, and the same argument: a task with
     * no date is not urgent, it is **unscheduled**, and belongs under the dated
     * ones rather than at the top where a null would sort it.
     */
    private static function byUrgency(Task $task, Task $other): int
    {
        return [
            $task->isComplete(),
            $task->due_date === null,
            $task->due_date?->getTimestamp() ?? 0,
            $task->sort_order,
            (string) $task->getKey(),
        ] <=> [
            $other->isComplete(),
            $other->due_date === null,
            $other->due_date?->getTimestamp() ?? 0,
            $other->sort_order,
            (string) $other->getKey(),
        ];
    }
}
