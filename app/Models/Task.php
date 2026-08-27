<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationType;
use App\Enums\TaskSource;
use App\Enums\TaskState;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasProductDefaults;
use App\Support\Notifications\Notify;
use App\Support\Tenancy\TeamContext;
use Carbon\CarbonImmutable;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A piece of work on a deal (PRD §4.4 · IA §8 · issue #65).
 *
 * Lives under a stage, belongs to a deal. `stage_id` is nullable because an
 * ad-hoc job — or one extraction creates in Slice 5 — exists on the deal
 * outside any stage. `deal_id` is not, because My Work (S11) groups by deal
 * and a task belonging to nothing has nowhere to appear.
 *
 * @property string $id
 * @property string $team_id
 * @property string $deal_id
 * @property string|null $stage_id
 * @property string $title
 * @property string|null $description
 * @property string|null $assignee_id
 * @property Carbon|null $due_date
 * @property Carbon|null $completed_at
 * @property string|null $completed_by
 * @property bool $is_required
 * @property TaskSource $source
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['title', 'description', 'assignee_id', 'due_date', 'is_required', 'sort_order'])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use BelongsToTeam, HasFactory, HasProductDefaults;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'is_required' => 'boolean',
            'source' => TaskSource::class,
        ];
    }

    /**
     * @return BelongsTo<Deal, $this>
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /**
     * @return BelongsTo<Stage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'assignee_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'completed_by')->withTrashed();
    }

    /**
     * `overdue` is derived, never stored (see `TaskState`).
     *
     * A stored copy is a second source of truth that goes stale at midnight,
     * every night, on every open task in the system — and the one screen that
     * cannot be wrong about it is the one Heather opens first (S11).
     */
    public function state(): TaskState
    {
        if ($this->completed_at !== null) {
            return TaskState::Completed;
        }

        /*
         * **Before today, in the team's calendar.**
         *
         * Two bugs live in the obvious spelling of this, and review on #71
         * found them one round apart.
         *
         * `due_date` is a `date` cast, so it lands at midnight — and
         * `isPast()` compares against the current *instant*, which made a task
         * due today overdue from 00:00:01. A deadline of "today" is one
         * somebody still has the day to meet.
         *
         * Comparing against **UTC's** start of day then moved the same
         * mistake seven hours: at 18:00 in Denver it is already tomorrow in
         * UTC, so a task due today read as overdue while the reader still had
         * six hours of their working day — and `lib/formatters.ts` renders
         * every date in the team's zone, so the badge and the chip beside it
         * disagreed during exactly those hours.
         *
         * The zone comes from the resolved team (`TeamContext`, which is
         * already in memory — asking `$this->team` would be a query per row on
         * a list of forty). Calendar days are compared as days, because that
         * is what a deadline is.
         *
         * `DateChip` still draws due-today in the danger tone. That is
         * urgency, which §7.2 says is a different question from state.
         */
        if ($this->due_date !== null && $this->due_date->toDateString() < $this->today()) {
            return TaskState::Overdue;
        }

        return TaskState::Open;
    }

    /**
     * Today, where the team is.
     *
     * Falls back to the application's zone when no team is resolved — a
     * console command sweeping every team, or a test that has not established
     * one. PRD §9 stores UTC and displays the team's zone; this is the second
     * half of that rule reaching a question the server has to answer.
     */
    private function today(): string
    {
        $team = app(TeamContext::class)->get();

        $timeZone = $team instanceof Team ? $team->timezone : config('app.timezone');

        return CarbonImmutable::now(is_string($timeZone) ? $timeZone : 'UTC')->toDateString();
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('completed_at');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRequired(Builder $query): Builder
    {
        return $query->where('is_required', true);
    }

    /**
     * Telling somebody a task is theirs (#101 · F12.4).
     *
     * ## On the column, not on a caller
     *
     * `assignee_id` is written from four places — `DealTasks::add()` and
     * `::edit()`, `InstantiateWorkflow` when a workflow is attached, and
     * `AdvanceWorkflow::override()`'s follow-up task — and `CLAUDE.md` records
     * what happens to a trigger hung off one of them: *"a trigger wired to one
     * implementation of a thing is wired to none of it."* `gate_cleared`
     * shipped exactly that way. A model hook fires wherever the value actually
     * changes, including from the caller somebody adds next slice.
     *
     * ## `saved`, not `saving`
     *
     * The notification names the task, so the row has to exist — and a
     * `saving` hook fires before `BelongsToTeam` has filled `team_id`, which
     * is the ordering `docs/adr/0002`'s S76 table records somebody getting
     * wrong on a different guard.
     *
     * ## Never about your own doing
     *
     * `Notify` filters the actor out, and the actor is the resolved person
     * rather than one passed in — a hook has no argument to carry one. Somebody
     * assigning a task to themselves is the common case and is exactly the
     * notification nobody wants.
     */
    protected static function booted(): void
    {
        /*
         * **Two hooks, not one `saved` with a predicate**, and the second
         * attempt at this is why.
         *
         * `wasChanged()` is **false** for a model that was just inserted:
         * `performInsert()` calls `syncOriginal()`, so `getDirty()` is empty by
         * the time `finishSave()` fills `changes`. Written as `saved` +
         * `wasChanged`, this fired for a reassignment and for **no**
         * assignment made at creation time — which is most of them: every task
         * a workflow instantiation hands out, and every one added with an
         * assignee already picked. Measured at zero.
         *
         * Reaching for `wasRecentlyCreated` to patch that is the next trap: it
         * stays true for every later save of the same instance, so editing the
         * title of a task announced a moment ago announced it again. Measured
         * at two.
         *
         * `created` fires exactly once, on the insert. `updated` fires only on
         * an update, which is the only place `wasChanged` means what it reads
         * as. Neither needs a predicate about which one it is.
         */
        static::created(static fn (self $task) => self::announceAssignment($task));

        static::updated(static function (self $task): void {
            if ($task->wasChanged('assignee_id')) {
                self::announceAssignment($task);
            }
        });
    }

    /**
     * Telling somebody a task is theirs (#101 · F12.4).
     *
     * ## On the column, not on a caller
     *
     * `assignee_id` is written from four places — `DealTasks::add()` and
     * `::edit()`, `InstantiateWorkflow` when a workflow is attached, and
     * `AdvanceWorkflow::override()`'s follow-up task — and `CLAUDE.md` records
     * what happens to a trigger hung off one of them: *"a trigger wired to one
     * implementation of a thing is wired to none of it."* `gate_cleared`
     * shipped exactly that way. A model hook fires wherever the value actually
     * changes, including from the caller somebody adds next slice.
     *
     * ## Never about your own doing
     *
     * `Notify` filters the actor out, and the actor is the resolved person
     * rather than one passed in — a hook has no argument to carry one.
     * Assigning a task to yourself is the common case and is exactly the
     * notification nobody wants.
     */
    private static function announceAssignment(self $task): void
    {
        $assignee = $task->assignee_id;

        if ($assignee === null) {
            return;
        }

        $person = Person::query()->find($assignee);
        $team = app(TeamContext::class)->get();

        if (! $person instanceof Person || ! $team instanceof Team) {
            return;
        }

        app(Notify::class)->send(
            type: NotificationType::TaskAssigned,
            people: [$person],
            team: $team,
            summary: 'You were assigned “'.$task->title.'”',
            deal: $task->deal,
            data: ['taskId' => $task->getKey()],
            actor: auth()->user() instanceof Person ? auth()->user() : null,
        );
    }
}
