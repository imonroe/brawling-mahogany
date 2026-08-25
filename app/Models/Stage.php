<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StageState;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasProductDefaults;
use App\Models\Concerns\HasStateMachine;
use Database\Factories\StageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A period within a workflow (IA §3 · PRD §4.4 · issue #65).
 *
 * **A stage is a period; a milestone is a moment.** "Listing Preparation" is a
 * stage and not a milestone. "Property Listed" is both. That distinction is
 * why a milestone is not a table of its own — one boolean and one string on
 * this row carry it.
 *
 * ## Nothing outside `AdvanceWorkflow` writes `state`
 *
 * Issue #65's definition of done, and `tests/Unit/SingleMutationPathTest.php`
 * holds it. The state machine below says which transitions are *possible*;
 * #68 decides which are *permitted*, having evaluated the gates. A controller
 * calling `transitionTo()` directly would pass the first check and skip the
 * second, which is exactly the bug the single mutation path exists to prevent.
 *
 * @property string $id
 * @property string $team_id
 * @property string $workflow_id
 * @property string $name
 * @property string|null $description
 * @property int $sort_order
 * @property StageState $state
 * @property Carbon|null $planned_start
 * @property Carbon|null $planned_end
 * @property Carbon|null $actual_start
 * @property Carbon|null $actual_end
 * @property string|null $completed_by
 * @property string|null $skipped_reason
 * @property bool $is_milestone
 * @property string|null $milestone_label
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name', 'description'])]
class Stage extends Model
{
    /** @use HasFactory<StageFactory> */
    use BelongsToTeam, HasFactory, HasProductDefaults, HasStateMachine;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => StageState::class,
            'is_milestone' => 'boolean',
            'planned_start' => 'date',
            'planned_end' => 'date',
            'actual_start' => 'datetime',
            'actual_end' => 'datetime',
        ];
    }

    /**
     * IA §8.
     *
     * `blocked` sits beside `active` rather than after it: a stage becomes
     * blocked when a blocking gate refuses an advance. It is a *display* state
     * for a stage somebody is standing in and cannot leave, not a stage of its
     * own — which is why `blocked → complete` is legal.
     *
     * **The cached badge and the live answer are two different things, and S15
     * reads the live one.** `stages.state` is still only *written* by an
     * advance attempt, so a gate cleared this morning leaves the badge saying
     * blocked until somebody presses Advance. `App\Support\Workflow\
     * DescribeBlockers` (#75) closes the gap for a screen by re-running the
     * evaluators read-only — it writes nothing, so the badge can be stale
     * while the list of blockers beside it is not. Marking a gate met from a
     * route is still Slice 3's work; what arrived early is the *reading*, not
     * the writing.
     *
     * `complete` returns to `active` for #70's reopen. Emily's reason is
     * concrete — an inspection stage closes, the report comes back with a
     * second issue, and the work reopens. A terminal `complete` would force a
     * duplicate workflow to hold work that belongs on the original.
     *
     * `active` and `blocked` return to `pending` for the other half of that
     * same reopen, and for nothing else. Reopening the stage behind the one a
     * team is standing on has to put the standing-on one somewhere, and the
     * only honest answer is back in the queue: it is upcoming again, it has no
     * `actual_start` any more, and the pointer has moved off it. Without this
     * the workflow would hold two active stages, which is the state the
     * `current_stage_id` pointer exists to make impossible.
     *
     * @return array<string, list<string>>
     */
    public static function stateTransitions(): array
    {
        return [
            StageState::Pending->value => [
                StageState::Active->value,
                StageState::Skipped->value,
            ],
            StageState::Active->value => [
                StageState::Blocked->value,
                StageState::Complete->value,
                StageState::Skipped->value,
                StageState::Pending->value,
            ],
            StageState::Blocked->value => [
                StageState::Active->value,
                StageState::Complete->value,
                StageState::Skipped->value,
                StageState::Pending->value,
            ],
            StageState::Complete->value => [
                StageState::Active->value,
            ],
            StageState::Skipped->value => [
                StageState::Pending->value,
            ],
        ];
    }

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * @return HasMany<Gate, $this>
     */
    public function gates(): HasMany
    {
        return $this->hasMany(Gate::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('sort_order');
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'completed_by')->withTrashed();
    }

    /**
     * What a client is told when this stage completes, if anything.
     *
     * IA §9: the internal stage name never reaches a client. Internal names
     * say things like "Chase lender" and "Nudge the other agent", which are
     * accurate, useful, and not for sharing. A milestone with no label is a
     * configuration mistake rather than a silent send, so this returns null and
     * the caller decides — nothing here invents wording for a client.
     */
    public function clientAnnouncement(): ?string
    {
        if (! $this->is_milestone) {
            return null;
        }

        $label = trim((string) $this->milestone_label);

        return $label === '' ? null : $label;
    }

    public function isFinished(): bool
    {
        return in_array($this->state, [StageState::Complete, StageState::Skipped], true);
    }

    /**
     * Somebody is standing in this stage and has not left it.
     *
     * The predicate behind `Workflow::activeStage()`, in the one place both
     * its branches can read it — see `StageState::inProgress()`.
     */
    public function isInProgress(): bool
    {
        return in_array($this->state->value, StageState::inProgress(), true);
    }

    /**
     * The one stage in this workflow a reopen may take, or null.
     *
     * F4.12's rule is *"only the most recently finished stage"*, and the word
     * doing the work is **most recently**. Two places need the answer —
     * `AdvanceWorkflow::reopen()` refuses anything else, and `StageTimeline`
     * decides which row draws the control — and a rail that worked it out for
     * itself would be a second copy drifting from the first.
     *
     * ## Behind the current stage, not merely finished
     *
     * `skip()` may be applied to a **future** stage: it is a note that the
     * stage does not apply to this deal, and it deliberately moves nothing. So
     * "finished, highest sort order" selects a stage the workflow has not
     * reached — and reopening one made the workflow jump *forward*, with the
     * stage the team was actually standing on displaced back to `pending` and
     * its `actual_start` nulled. The work in between was silently skipped, by
     * the verb that exists to undo a skip.
     *
     * Un-skipping a future stage is a real thing somebody may want and is not
     * this verb; nothing offers it yet.
     */
    public static function reopenableIn(Workflow $workflow): ?self
    {
        $stages = $workflow->relationLoaded('stages')
            ? $workflow->stages
            : $workflow->stages()->get();

        $current = $stages->first(fn (self $stage): bool => $stage->isInProgress());

        return $stages
            ->filter(fn (self $stage): bool => $stage->isFinished())
            ->filter(fn (self $stage): bool => ! $current instanceof self
                || $stage->sort_order < $current->sort_order)
            ->sortByDesc('sort_order')
            ->first();
    }
}
