<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TaskSource;
use App\Enums\TaskState;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasProductDefaults;
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
}
