<?php

declare(strict_types=1);

namespace App\Support\Deals;

use App\Enums\TaskSource;
use App\Models\Deal;
use App\Models\Person;
use App\Models\Stage;
use App\Models\Task;
use App\Support\Activity\RecordActivity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one service that writes `tasks` (S17, S27 · PRD §4.4 F4.10 · issue #71).
 *
 * The same argument `DealRoster` and `PropertyDeals` make for their tables. A
 * task is not just a row: completing one writes an activity event, and a
 * controller that wrote `completed_at` and forgot the event would look like it
 * worked — the checkbox ticks, and the team's record of who did what quietly
 * stops being complete.
 *
 * ## What this deliberately does not do
 *
 * **It never touches a gate.** `required_tasks_complete` reads the tasks on a
 * stage every time it is evaluated, so completing the last required task
 * clears the gate by arithmetic rather than by anybody writing `gates.is_met`.
 * Issue #71 asks for exactly that — *"through the gate evaluator, not by
 * directly setting `gates.is_met`"* — and it is also what `AdvanceWorkflow`
 * being the single mutation path means: the cache on `gates.is_met` is
 * refreshed by an advance attempt and by nothing else, and a service that
 * refreshed it from the side would be a second writer of workflow state.
 *
 * **It never advances.** Clearing the last requirement on a stage does not
 * move the deal; somebody still presses Advance. That is the same rule
 * `AdvanceWorkflow::override()` follows for the same reason — an advance has
 * consequences a client sees, and it should be a thing a person did.
 *
 * ## Completion is idempotent, and the event is what makes that matter
 *
 * Two people ticking the same box, or one person on a stale tab, must not
 * write two `task.completed` events — the feed would report the work twice and
 * attribute it to whoever was second. So completing a completed task is a
 * no-op that returns quietly rather than an error: nothing is wrong, and the
 * row already says what the person wanted it to say.
 */
final readonly class DealTasks
{
    public function __construct(private RecordActivity $activity) {}

    /*
     * Every method takes the `Deal` rather than reading `$task->deal`.
     *
     * The caller already has it — the route binds it, and `scopeBindings()`
     * has already established that the task is on *that* deal — so reading it
     * back off the task is a query to re-learn something the request proved,
     * and it is the object an activity event is recorded against.
     */

    /**
     * IA §7: **Add** attaches something to a parent. The parent is the deal.
     *
     * @param  array<string, mixed>  $attributes  from `StoreTaskRequest::taskAttributes()`
     */
    public function add(Deal $deal, ?Stage $stage, array $attributes): Task
    {
        return DB::transaction(function () use ($deal, $stage, $attributes): Task {
            $task = new Task;

            $task->fill([
                ...$attributes,
                'sort_order' => $this->nextSortOrder($deal, $stage),
            ]);

            $task->deal()->associate($deal);
            $task->stage()->associate($stage);

            /*
             * Typed by a person, and the column exists to be able to say so
             * (see `TaskSource`). Slice 5 needs `extracted` to be tellable
             * from this, and #69's follow-up carries `override` — a manual
             * task and a task the machine proposed must never render alike.
             */
            $task->source = TaskSource::Manual;

            $task->save();

            $this->activity->record(
                subject: $deal,
                eventType: 'task.added',
                summary: "Added task “{$task->title}”",
            );

            return $task;
        });
    }

    /**
     * IA §7: **Edit** changes an existing record.
     *
     * Mostly no activity event. Editing a title or a due date is housekeeping
     * on work that is already on the record, and a feed that reported every
     * one of them would bury the entries somebody scans it to find.
     *
     * **Two of the fields are exceptions, and neither is small.**
     * `required_tasks_complete` counts *the required tasks on one stage*, so
     * both halves of that sentence are ways past a blocking gate: unticking
     * `is_required`, and moving the task to a **different stage**. Neither
     * needs `workflow.override` or a typed reason, and a Team Member holds
     * both by default.
     *
     * Review on #71 found the first and named the second in the same
     * sentence; the first fix shipped only the first half, so the second round
     * proved the identical bypass one control higher up the same form — same
     * permissions, same one click, zero events. Both are recorded now, which
     * is the only reason the copy under that checkbox is true.
     *
     * The answer is not to refuse either edit. A task list is the customer's
     * to shape — PRD §7.10 makes that the whole product — and somebody who
     * mis-filed a task at 9am must be able to fix it at 10. What was wrong was
     * that it happened in silence: an override is in the audit log because it
     * defers an obligation somebody else set, and these are on the deal's
     * activity because they change what the team decided the obligation *is*.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function edit(
        Deal $deal,
        Task $task,
        array $attributes,
        ?Stage $stage,
        bool $moveStage,
    ): Task {
        return DB::transaction(function () use ($deal, $task, $attributes, $stage, $moveStage): Task {
            $task->fill($attributes);

            if ($moveStage) {
                /*
                 * Re-ranked, because `sort_order` is a position *within* a
                 * group and carrying the old one across lands the task in the
                 * middle of a checklist it was never part of.
                 */
                if (! $this->sameStage($task, $stage)) {
                    $task->sort_order = $this->nextSortOrder($deal, $stage);
                }

                $task->stage()->associate($stage);
            }

            /*
             * Read before the save is committed but after `fill()`, so what is
             * compared is the value that is about to be written against the
             * one that was there. `isDirty()` is what makes this record a
             * *change* rather than every submit of the form.
             */
            $flagChanged = $task->isDirty('is_required');
            $nowRequired = (bool) $task->is_required;

            /*
             * Read before the save, because after it `getOriginal()` is the
             * value that was just written and the move is invisible.
             */
            $movedFrom = $task->isDirty('stage_id')
                ? $this->stageName($task->getOriginal('stage_id'))
                : null;

            $task->save();

            if ($flagChanged) {
                $this->activity->record(
                    subject: $deal,
                    eventType: 'task.required_changed',
                    summary: $nowRequired
                        ? "Made “{$task->title}” required to advance the stage"
                        : "Made “{$task->title}” no longer required to advance the stage",
                );
            }

            if ($movedFrom !== null) {
                /*
                 * Recorded for every task, not only a required one. A gate is
                 * the reason this cannot be silent, but "which stage is this
                 * work under" is a fact about the process that a team reads
                 * back — and a rule that only fired on required tasks would be
                 * one flag away from being silent again.
                 */
                $this->activity->record(
                    subject: $deal,
                    eventType: 'task.moved',
                    summary: sprintf(
                        'Moved “%s” from %s to %s',
                        $task->title,
                        $movedFrom,
                        $stage instanceof Stage ? $stage->name : 'no stage',
                    ),
                );
            }

            return $task;
        });
    }

    /**
     * IA §7: **Complete** finishes a task — never Done, Close, or Check off.
     *
     * `completed_by` is the person who ticked it, which is not always the
     * assignee and is the more useful of the two to record: §7.3's meta line
     * inside a deal is the completion attribution, and "Completed by Heather"
     * on a task assigned to Emily is a fact somebody will want back.
     */
    public function complete(Deal $deal, Task $task, Person $actor): Task
    {
        if ($task->isComplete()) {
            return $task;
        }

        return DB::transaction(function () use ($deal, $task, $actor): Task {
            /*
             * `Carbon::now()`, not `now()`. The helper returns a
             * `CarbonImmutable` in this application, and `completed_at` is
             * typed `Illuminate\Support\Carbon` — a direct property
             * assignment is the one place that difference is visible, which is
             * why the timestamps written through `forceFill()` elsewhere use
             * the helper happily.
             */
            $task->completed_at = Carbon::now();
            $task->completedBy()->associate($actor);
            $task->save();

            /*
             * Subjected to the **deal**, not to the task.
             *
             * CLAUDE.md's rule is that the subject is what this happened to
             * and `deal_id` is where a team looks for it, and the closest
             * precedents settle it the same way: `participant.added` and every
             * `property.*` event that happens *on* a deal subject the deal.
             * The tie-breaker is that `ActivityFeed::subject()` renders the
             * subject as a link, and there is no per-task screen to link to —
             * a subject with no URL renders as nothing at all.
             */
            $this->activity->record(
                subject: $deal,
                eventType: 'task.completed',
                summary: "Completed “{$task->title}”",
                actor: $actor,
            );

            return $task;
        });
    }

    /**
     * Unticking the box.
     *
     * Recorded rather than silent, and that is the whole reason it is a
     * separate act with a separate route. A completion is already in the feed
     * saying the work is done; if reopening left no trace, the record would go
     * on asserting something the team has since decided is not true.
     */
    public function reopen(Deal $deal, Task $task): Task
    {
        if (! $task->isComplete()) {
            return $task;
        }

        return DB::transaction(function () use ($deal, $task): Task {
            $task->completed_at = null;
            $task->completedBy()->disassociate();
            $task->save();

            $this->activity->record(
                subject: $deal,
                eventType: 'task.reopened',
                summary: "Reopened “{$task->title}”",
            );

            return $task;
        });
    }

    /**
     * IA §7: **Delete** destroys; **Remove** detaches and the record survives.
     *
     * This is a delete — a task belongs to one deal and detaching it from that
     * deal leaves it nowhere — and it is soft, so PRD §9's thirty-day window
     * covers a task somebody deleted by accident.
     */
    public function delete(Deal $deal, Task $task): void
    {
        DB::transaction(function () use ($deal, $task): void {
            $title = $task->title;

            $task->delete();

            $this->activity->record(
                subject: $deal,
                eventType: 'task.deleted',
                summary: "Deleted task “{$title}”",
            );
        });
    }

    /**
     * What a stage the task is leaving was called, for the timeline entry.
     *
     * One query, and only when something actually moved. Null `stage_id` is
     * an ordinary state rather than a missing row — PRD §6.4 makes it nullable
     * so an ad-hoc job can live on the deal outside any stage — so it has a
     * name rather than an absence.
     */
    private function stageName(mixed $stageId): string
    {
        if (! is_string($stageId) || $stageId === '') {
            return 'no stage';
        }

        $stage = Stage::query()->whereKey($stageId)->first();

        return $stage instanceof Stage ? $stage->name : 'a stage that no longer exists';
    }

    /** Whether the task is already in the group it is being moved to. */
    private function sameStage(Task $task, ?Stage $stage): bool
    {
        return $task->stage_id === $stage?->getKey();
    }

    /**
     * The end of the group a task is joining.
     *
     * A stage's tasks come out of `Stage::tasks()` in `sort_order`, and the
     * seeded ones arrive from `task_templates` already ordered — a checklist
     * is a sequence, and Emily's lists are read top to bottom. So a task added
     * by hand goes on the end rather than into the middle of somebody's
     * procedure, which is where a default of zero would put it.
     */
    private function nextSortOrder(Deal $deal, ?Stage $stage): int
    {
        $highest = Task::query()
            ->where('deal_id', $deal->getKey())
            ->when(
                $stage instanceof Stage,
                fn ($query) => $query->where('stage_id', $stage->getKey()),
                fn ($query) => $query->whereNull('stage_id'),
            )
            ->max('sort_order');

        return $highest === null ? 0 : ((int) $highest) + 1;
    }
}
