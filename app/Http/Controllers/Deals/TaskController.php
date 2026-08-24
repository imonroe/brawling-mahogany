<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deals;

use App\Enums\TaskState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Deals\StoreTaskRequest;
use App\Http\Requests\Deals\UpdateTaskRequest;
use App\Models\Deal;
use App\Models\Person;
use App\Models\Stage;
use App\Models\Task;
use App\Models\TeamMembership;
use App\Models\Workflow;
use App\Queries\TaskAssignees;
use App\Support\Activity\ActorDirectory;
use App\Support\Deals\DealHeader;
use App\Support\Deals\DealTasks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * S17 and S27 — a deal's tasks (PRD §4.4 F4.10 · §7.10 · issue #71).
 *
 * ## The feature the product is sold on
 *
 * PRD §7.10: *"customizable task lists are the differentiator both
 * practitioners named independently."* Emily: *"You can customize your task
 * list, and that's what we don't have anywhere."* Heather, on CTM: it *"states
 * the deadline, it doesn't give you the other, like, check, send this now."*
 *
 * Until this screen existed the engine created tasks from `task_templates`
 * that no route could reach, so `required_tasks_complete` had exactly one way
 * to clear — an **override**, which IA §7 reserves for the case where the
 * condition should have been met and was not. The routine path through a gate
 * was the audited exception. That is what this closes.
 *
 * ## A task is work owed; a gate is a condition on advancement
 *
 * PRD F4.10 keeps them apart and this screen keeps them apart. Completing the
 * last required task on a stage does not advance anything — it makes the
 * advance *possible*, and somebody still presses Advance. Nothing here writes
 * to `gates`; the evaluator counts the tasks each time it is asked.
 *
 * ## No stage badges on this screen
 *
 * The group header names the stage and says which one the workflow is on. It
 * does **not** badge the stage's state, and that is deliberate: `stages.state`
 * is a cache only an advance attempt refreshes (see `StageTimeline`), so a
 * badge here would either be stale or would need this screen to evaluate every
 * gate on the deal to draw a label nobody came here for. S15 and S16 are the
 * two screens that answer that question, and both answer it the same way.
 */
class TaskController extends Controller
{
    public function index(Deal $deal, TaskAssignees $assignees): Response
    {
        $this->authorize('viewAny', [Task::class, $deal]);

        /*
         * One pass for the screen. `tasks.stage` is not loaded — the grouping
         * walks the stages and asks each for its tasks, so the relation is
         * already in memory from the other side.
         */
        $deal->load([
            'dealType',
            'participants.membership',
            'propertyLinks.property',
            'workflows.stages.tasks',
            'tasks',
        ]);

        /*
         * Every person any row on this page names, resolved in the two
         * queries `ActorDirectory` already makes for the activity feed —
         * rather than one per task, which is what `Person::displayNameWithin()`
         * inside a `map()` costs. Assignees and completers together, because
         * they are frequently different people and always the same question.
         */
        $names = ActorDirectory::forPeople(
            $deal->tasks
                ->flatMap(fn (Task $task): array => [$task->assignee_id, $task->completed_by]),
        );

        return Inertia::render('Deals/Tasks', [
            'dealHeader' => DealHeader::for($deal),
            'dealUrl' => "/deals/{$deal->getKey()}",
            'groups' => $this->groups($deal, $names),
            'counts' => $this->counts($deal->tasks),
            /*
             * The picker's options, which are not the same set as the names
             * above: somebody whose membership was revoked keeps the tasks
             * already assigned to them and cannot be given a new one. See
             * `App\Queries\TaskAssignees`.
             */
            'assignees' => array_values($assignees->memberships()
                ->map(fn (TeamMembership $membership): array => [
                    'id' => (string) $membership->person_id,
                    'name' => $membership->fullName(),
                ])->all()),
            'stageOptions' => $this->stageOptions($deal),
        ]);
    }

    public function store(StoreTaskRequest $request, Deal $deal, DealTasks $tasks): RedirectResponse
    {
        $tasks->add($deal, $request->resolveStage($deal), $request->taskAttributes());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Task added.')]);

        return to_route('deals.tasks.index', $deal);
    }

    public function update(UpdateTaskRequest $request, Deal $deal, Task $task, DealTasks $tasks): RedirectResponse
    {
        $tasks->edit(
            $deal,
            $task,
            $request->changes(),
            $request->resolveStage($deal),
            $request->movesStage(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Task updated.')]);

        return to_route('deals.tasks.index', $deal);
    }

    /**
     * IA §7: **Complete** finishes a task.
     *
     * Its own endpoint rather than a boolean on the edit, because it is a
     * different act: it writes an activity event, it is the thing a
     * `required_tasks_complete` gate is counting, and it is the one a person
     * does fifty times a deal from a checkbox rather than from a form.
     *
     * **Back, not to the tasks tab.** Two screens tick these boxes — this one
     * and S16's stage rail (#71 wired it up) — and sending everybody to
     * `deals.tasks.index` would yank a reader off the timeline they were
     * working, at the moment they most want to look at the requirements pane
     * beside the checklist they just cleared. Every other route here redirects
     * to the tab, because the tab is the only place that posts to them.
     */
    public function complete(Request $request, Deal $deal, Task $task, DealTasks $tasks): RedirectResponse
    {
        $this->authorize('update', $task);

        /** @var Person $actor */
        $actor = $request->user();

        $tasks->complete($deal, $task, $actor);

        return back();
    }

    /**
     * Unticking the box. A `DELETE` on the completion, not on the task.
     *
     * Back, for the reason `complete()` gives.
     */
    public function reopen(Deal $deal, Task $task, DealTasks $tasks): RedirectResponse
    {
        $this->authorize('update', $task);

        $tasks->reopen($deal, $task);

        return back();
    }

    /**
     * IA §7: **Delete** destroys — and it is soft, so PRD §9's thirty-day
     * window covers a task somebody deleted by accident.
     */
    public function destroy(Deal $deal, Task $task, DealTasks $tasks): RedirectResponse
    {
        $this->authorize('delete', $task);

        $tasks->delete($deal, $task);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Task deleted.')]);

        return to_route('deals.tasks.index', $deal);
    }

    /**
     * The list, grouped by stage — issue #71's *"grouped by stage"*.
     *
     * In workflow order and then stage order, which is the order a checklist
     * is written in and the order the timeline draws. Not urgency order: a
     * procedure read out of sequence is not the procedure, and the urgent work
     * is already picked out inside each group and by S11's cross-deal queue,
     * which is the screen for *"what do I do next"*.
     *
     * A stage with no tasks is not a group. Emily's listing checklist has
     * twenty stages and work on six of them, and eighteen empty headers is a
     * page somebody has to scroll past to find the two that matter.
     *
     * @return list<array<string, mixed>>
     */
    private function groups(Deal $deal, ActorDirectory $names): array
    {
        $groups = [];

        foreach ($deal->workflows as $workflow) {
            foreach ($workflow->stages as $stage) {
                if ($stage->tasks->isEmpty()) {
                    continue;
                }

                $groups[] = [
                    'key' => (string) $stage->getKey(),
                    'stageId' => (string) $stage->getKey(),
                    'stageName' => $stage->name,
                    /*
                     * Named on every group, not only when a deal has two
                     * workflows. F4.7 makes two the ordinary case, and a
                     * *Photography* stage means something different under
                     * Pre-Listing than under Under Contract — a label that
                     * appears only sometimes is a label the reader has to
                     * check for.
                     */
                    'workflowName' => $workflow->name,
                    /*
                     * A record fact, not an evaluation: `current_stage_id` is
                     * where the workflow is, which is why this is a chip
                     * saying "Current stage" rather than a state badge saying
                     * whether it can move. See the class docblock.
                     */
                    'isCurrent' => $workflow->current_stage_id === $stage->getKey(),
                    'tasks' => $this->rows($stage->tasks, $names),
                ];
            }
        }

        /*
         * PRD §6.4 makes `stage_id` nullable so an ad-hoc job can sit on the
         * deal outside any stage, and this is where those land. Last, because
         * it is the group with no place in the sequence — and present only
         * when it holds something, like every other group.
         */
        $unstaged = $deal->tasks->filter(fn (Task $task): bool => $task->stage_id === null);

        if ($unstaged->isNotEmpty()) {
            $groups[] = [
                'key' => 'unstaged',
                'stageId' => null,
                'stageName' => null,
                'workflowName' => null,
                'isCurrent' => false,
                'tasks' => $this->rows($unstaged, $names),
            ];
        }

        /*
         * No `array_values()` here, unlike the other two: this one is built by
         * appending, so it is a list already and PHPStan says so.
         */
        return $groups;
    }

    /**
     * @param  Collection<int, Task>  $tasks
     * @return list<array<string, mixed>>
     */
    private function rows(Collection $tasks, ActorDirectory $names): array
    {
        return array_values($tasks
            ->sort($this->byUrgency(...))
            ->values()
            ->map(fn (Task $task): array => [
                'id' => (string) $task->getKey(),
                'title' => $task->title,
                'description' => $task->description,
                'stageId' => $task->stage_id,
                /*
                 * IA §8's task vocabulary, derived here and never stored —
                 * `overdue` is a fact about today, and a stored copy is wrong
                 * every night at midnight on every open task in the system.
                 */
                'state' => $task->state()->value,
                'isRequired' => $task->is_required,
                'dueDate' => $task->due_date?->toIso8601String(),
                'completedAt' => $task->completed_at?->toIso8601String(),
                'completedByName' => $names->name($task->completed_by),
                'assigneeId' => $task->assignee_id,
                'assigneeName' => $names->name($task->assignee_id),
                /*
                 * Provenance, and Slice 5 is the reason it is on the row:
                 * PRD §4.10 is firm that nothing a model proposes reaches a
                 * live record unmarked, and a task nobody can tell apart from
                 * one Heather typed is a task that has quietly done that.
                 * `override` is here for #69's follow-up, which is the same
                 * argument — an obligation somebody deferred should not read
                 * as one somebody chose to write.
                 */
                'source' => $task->source->value,
                'sourceLabel' => $task->source->label(),
            ])
            ->all());
    }

    /**
     * Urgency, within a group.
     *
     * Open before complete, then soonest due, then undated, then the order the
     * checklist was written in. The Definition of Done asks for *"sort by
     * urgency"*, and a task with no date is not urgent — it is unscheduled,
     * which is a different thing and belongs under the dated ones rather than
     * at the top where a null would sort it.
     */
    private function byUrgency(Task $task, Task $other): int
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

    /**
     * The three numbers the filter row carries.
     *
     * Counted in PHP over the collection the page already holds, rather than
     * with three `count()` queries — the same reason `DealHeader` reads a
     * loaded relation.
     *
     * @param  Collection<int, Task>  $tasks
     * @return array<string, int>
     */
    private function counts(Collection $tasks): array
    {
        $open = $tasks->filter(fn (Task $task): bool => ! $task->isComplete());

        return [
            'open' => $open->count(),
            'completed' => $tasks->count() - $open->count(),
            'all' => $tasks->count(),
            /*
             * Overdue and unassigned are counted over the **open** tasks only.
             * A task completed a week after its due date was late; it is not
             * *overdue*, because there is nothing left to do — `Task::state()`
             * says the same thing, and a count that disagreed with the badges
             * under it would be the screen arguing with itself.
             */
            /*
             * Asked of `Task::state()` rather than re-derived, so the number
             * and the badges under it cannot disagree — the first version
             * spelled out `due_date->isPast()` here, which counted a task due
             * *today* as overdue while the row beside it said Open.
             */
            'overdue' => $open->filter(
                fn (Task $task): bool => $task->state() === TaskState::Overdue,
            )->count(),
            'unassigned' => $open->filter(
                fn (Task $task): bool => $task->assignee_id === null,
            )->count(),
        ];
    }

    /**
     * Which stage a new task may go on, grouped by workflow for the picker.
     *
     * Every stage, not only the ones that can still be worked. A team catching
     * up puts a task on a stage they have already advanced past, which is a
     * record of work that was owed rather than an attempt to change the past —
     * and `required_tasks_complete` reads the stage a workflow is *in*, so a
     * task filed behind it blocks nothing.
     *
     * @return list<array<string, mixed>>
     */
    private function stageOptions(Deal $deal): array
    {
        return array_values($deal->workflows->map(fn (Workflow $workflow): array => [
            'workflowName' => $workflow->name,
            'stages' => array_values($workflow->stages->map(fn (Stage $stage): array => [
                'id' => (string) $stage->getKey(),
                'name' => $stage->name,
            ])->all()),
        ])->all());
    }
}
