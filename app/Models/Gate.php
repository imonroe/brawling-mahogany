<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GateState;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasProductDefaults;
use Database\Factories\GateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A condition that must clear before a stage can be left (PRD §4.4 F4.8 ·
 * issue #65).
 *
 * The gate holds *state*; what clears it is an evaluator (#67), resolved from
 * `gate_type`. That split is what makes gate types user-editable data rather
 * than a hand-written conditional per stage — PRD §7.8 names the alternative
 * as the thing that would make templates unusable.
 *
 * ## `is_met` is a cache, and `state()` is the truth
 *
 * Most gate types are *derived*: "all required tasks are done" is a fact about
 * the tasks, not about this row. `is_met` records the derived answer at the
 * moment of an evaluation so a screen can render a list without running seven
 * evaluators, and `met_at`/`met_by` record who was standing there when a
 * manual gate was ticked.
 *
 * Nothing may treat `is_met` as authoritative at advance time. #68 re-evaluates
 * every gate inside the transaction, because a stale cached `true` on a gate is
 * precisely the failure this product cannot have.
 *
 * @property string $id
 * @property string $team_id
 * @property string $stage_id
 * @property string $gate_type
 * @property string $label
 * @property array<string, mixed>|null $config
 * @property bool $is_blocking
 * @property bool $is_met
 * @property Carbon|null $met_at
 * @property string|null $met_by
 * @property bool $overridden
 * @property string|null $override_reason
 * @property string|null $overridden_by
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['label'])]
class Gate extends Model
{
    /** @use HasFactory<GateFactory> */
    use BelongsToTeam, HasFactory, HasProductDefaults;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'config' => 'array',
            'is_blocking' => 'boolean',
            'is_met' => 'boolean',
            'overridden' => 'boolean',
            'met_at' => 'datetime',
        ];
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
    public function metBy(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'met_by')->withTrashed();
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function overriddenBy(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'overridden_by')->withTrashed();
    }

    /**
     * IA §8: **overridden is not a kind of met.**
     *
     * It means the gate should have been met, was not, and somebody went ahead
     * with a reason. Collapsing the two would lose the only thing anybody
     * wants to know six weeks later — whether the survey was actually back, or
     * whether Emily decided to proceed without it.
     *
     * Checked before `is_met` deliberately: a gate that was overridden and has
     * since become genuinely met still reads as overridden, because the
     * advance that mattered happened on the override.
     */
    public function state(): GateState
    {
        if ($this->overridden) {
            return GateState::Overridden;
        }

        return $this->is_met ? GateState::Met : GateState::Unmet;
    }

    /**
     * Does this gate stand in the way right now?
     *
     * An advisory gate never does — it is shown and explained and never
     * refuses, which is how a team says "you probably want the survey" without
     * building a wall. An overridden gate does not either: that is what the
     * override was for.
     */
    public function blocksAdvance(): bool
    {
        return $this->is_blocking && ! $this->overridden;
    }

    /**
     * @return array<string, mixed>
     */
    public function configuration(): array
    {
        return $this->config ?? [];
    }
}
