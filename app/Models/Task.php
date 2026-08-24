<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TaskSource;
use App\Enums\TaskState;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasProductDefaults;
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
         * **Before today, not before now.**
         *
         * `due_date` is a `date` cast, so it lands at midnight — and
         * `isPast()` compares against the current *instant*, which makes a
         * task due today overdue from 00:00:01 onwards. A deadline of "today"
         * is a deadline somebody still has the day to meet, and badging it
         * Overdue on the morning of the day it is due is the screen telling
         * them they are already late.
         *
         * Found by review on #71. `DateChip` already draws due-today in the
         * danger tone — urgency, which is a different question from state, and
         * §7.2 says so.
         *
         * The zone is the application's, not the team's. PRD §9 stores UTC and
         * displays the team's zone, and doing that here would mean every
         * caller of this method holding a team — which is a broader change
         * than this line, and one worth making when S11's cross-deal queue
         * lands and a team in Denver is reading it at 6pm.
         */
        if ($this->due_date !== null && $this->due_date->lt(now()->startOfDay())) {
            return TaskState::Overdue;
        }

        return TaskState::Open;
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
