<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StageState;
use App\Enums\WorkflowState;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasProductDefaults;
use App\Models\Concerns\HasStateMachine;
use Database\Factories\WorkflowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A process actually running on a deal (PRD §4.4 F4.6, F4.7 · issue #65).
 *
 * The runtime half of the split. What this workflow *is* lives in
 * `template_snapshot`, not in the template it came from — F4.5, and the reason
 * `workflow_template_id` is never read at advance time.
 *
 * @property string $id
 * @property string $team_id
 * @property string $deal_id
 * @property string|null $workflow_template_id
 * @property array<string, mixed> $template_snapshot
 * @property string $name
 * @property WorkflowState $state
 * @property string|null $current_stage_id
 * @property Carbon|null $planned_start
 * @property Carbon|null $planned_end
 * @property Carbon|null $actual_start
 * @property Carbon|null $actual_end
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name'])]
class Workflow extends Model
{
    /** @use HasFactory<WorkflowFactory> */
    use BelongsToTeam, HasFactory, HasProductDefaults, HasStateMachine;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => WorkflowState::class,
            'template_snapshot' => 'array',
            'planned_start' => 'date',
            'planned_end' => 'date',
            'actual_start' => 'datetime',
            'actual_end' => 'datetime',
        ];
    }

    /**
     * IA §8.
     *
     * `on_hold` returns to `active` because that is the entire point of a
     * hold — a listing paused while the sellers travel is not a cancellation,
     * and forcing a team to cancel and re-instantiate would lose the stage
     * history that made the workflow worth having.
     *
     * `completed` is terminal. Reopening a finished workflow is #70's
     * territory and works at the stage level, deliberately: "reopen the
     * inspection stage" is a real request, "un-complete the entire sale" is
     * not.
     *
     * @return array<string, list<string>>
     */
    public static function stateTransitions(): array
    {
        return [
            WorkflowState::NotStarted->value => [
                WorkflowState::Active->value,
                WorkflowState::Cancelled->value,
            ],
            WorkflowState::Active->value => [
                WorkflowState::OnHold->value,
                WorkflowState::Completed->value,
                WorkflowState::Cancelled->value,
            ],
            WorkflowState::OnHold->value => [
                WorkflowState::Active->value,
                WorkflowState::Cancelled->value,
            ],
            WorkflowState::Completed->value => [],
            WorkflowState::Cancelled->value => [],
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
     * Kept for reporting and for S41's in-use warning. **Never read at advance
     * time** — that is what the snapshot is for.
     *
     * @return BelongsTo<WorkflowTemplate, $this>
     */
    public function workflowTemplate(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplate::class);
    }

    /**
     * @return HasMany<Stage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(Stage::class)->orderBy('sort_order');
    }

    /**
     * @return BelongsTo<Stage, $this>
     */
    public function currentStage(): BelongsTo
    {
        return $this->belongsTo(Stage::class, 'current_stage_id');
    }

    /**
     * The stage a person would actually be working in.
     *
     * Read from the stages rather than trusted from `current_stage_id`, which
     * is a denormalised convenience for the deals index and can be null on a
     * workflow that has not started. When they disagree the stages win: they
     * are what `AdvanceWorkflow` writes in the transaction.
     *
     * **Blocked counts as in progress**, and leaving it out was a bug with
     * teeth: a refused advance marks the stage blocked, so a workflow that had
     * been refused once had no active stage at all and every later attempt
     * threw `NothingToAdvance` — clearing the gate could not unstick it,
     * because nothing could find the stage to advance. Blocked is a display
     * state for a stage somebody is standing in and cannot leave, not a state
     * they have left.
     */
    public function activeStage(): ?Stage
    {
        return $this->stages()
            ->whereIn('state', [StageState::Active->value, StageState::Blocked->value])
            ->first();
    }

    /**
     * The next stage after this one, by order.
     *
     * Skipped stages are not candidates — a skipped stage is one somebody
     * decided does not apply to this deal, and advancing into it would undo
     * that decision.
     */
    public function stageAfter(Stage $stage): ?Stage
    {
        return $this->stages()
            ->where('sort_order', '>', $stage->sort_order)
            ->whereNotIn('state', [StageState::Skipped->value, StageState::Complete->value])
            ->orderBy('sort_order')
            ->first();
    }

    public function isRunning(): bool
    {
        return $this->state === WorkflowState::Active;
    }
}
