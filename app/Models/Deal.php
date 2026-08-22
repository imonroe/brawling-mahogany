<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DealState;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasProductDefaults;
use App\Models\Concerns\HasStateMachine;
use Database\Factories\DealFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The transaction (PRD §4.3 · IA §2, §8, §10 · issue #59).
 *
 * **Deal, never Project.** The rename is in IA §12 and the PRD decision log,
 * and `tests/Unit/CodeDisciplineTest.php` fails the build if the old word
 * appears in code. Emily and Heather never said "project."
 *
 * @property string $id
 * @property string $team_id
 * @property string $deal_type_id
 * @property string|null $name
 * @property string|null $generated_name
 * @property DealState $state
 * @property Carbon|null $opened_at
 * @property Carbon|null $closed_at
 * @property int|null $transaction_value
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['deal_type_id', 'name', 'opened_at', 'transaction_value', 'notes'])]
class Deal extends Model
{
    /** @use HasFactory<DealFactory> */
    use BelongsToTeam, HasFactory, HasProductDefaults, HasStateMachine;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => DealState::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * IA §8, and the correction in PRD §7.15.
     *
     * **Closing does not terminate a deal.** The rough data model *"had no
     * place for anything after closing"*, which is why `closed` leads to
     * `nurture` rather than to nothing: the participants stay attached and
     * Slice 6 picks them up as past clients. A terminal `closed` would throw
     * away the most valuable list a small agency owns.
     *
     * `fell_through` is not terminal either. A deal that collapses at
     * inspection often comes back — the buyer finds another house with the
     * same agent — so it can reopen to `active`. `cancelled` is where a deal
     * goes when it should never have been opened, and that one is final.
     *
     * @return array<string, list<string>>
     */
    public static function stateTransitions(): array
    {
        return [
            DealState::Active->value => [
                DealState::Closed->value,
                DealState::FellThrough->value,
                DealState::Cancelled->value,
            ],
            DealState::Closed->value => [
                DealState::Nurture->value,
            ],
            DealState::Nurture->value => [],
            DealState::FellThrough->value => [
                DealState::Active->value,
                DealState::Cancelled->value,
            ],
            DealState::Cancelled->value => [],
        ];
    }

    /**
     * @return BelongsTo<DealType, $this>
     */
    public function dealType(): BelongsTo
    {
        return $this->belongsTo(DealType::class);
    }

    /**
     * Many, deliberately (F4.7).
     *
     * PRD §7.5: the rough data model gave a deal one workflow and contradicted
     * itself about it. Pre-listing improvements and the sale itself run
     * concurrently.
     *
     * @return HasMany<Workflow, $this>
     */
    public function workflows(): HasMany
    {
        return $this->hasMany(Workflow::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * What to call this deal on a screen.
     *
     * The typed name wins whenever there is one. That is the whole reason both
     * columns exist: `generated_name` goes on tracking the facts, and the
     * moment somebody types something they mean it.
     */
    public function displayName(): string
    {
        $typed = trim((string) $this->name);

        if ($typed !== '') {
            return $typed;
        }

        return trim((string) $this->generated_name) ?: 'Untitled deal';
    }

    /** Whether a person has renamed this deal by hand. */
    public function hasManualName(): bool
    {
        return trim((string) $this->name) !== '';
    }

    /**
     * Deals a client may be shown, and their client-facing state (IA §9).
     *
     * `nurture`, `fell_through`, and `cancelled` have no client label at all —
     * a client reading "Fell Through" about their own purchase is being told
     * something by a status badge that should come from their agent.
     */
    public function clientVisibleState(): ?string
    {
        return $this->state->clientLabel();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('state', DealState::Active->value);
    }
}
