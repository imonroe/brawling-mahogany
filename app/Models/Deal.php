<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DealState;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasProductDefaults;
use App\Models\Concerns\HasStateMachine;
use App\Support\Tenancy\ArchivedReferenceException;
use App\Support\Tenancy\ForeignReferenceException;
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
     * A deal cannot borrow another team's private deal type.
     *
     * The one relationship in the runtime layer that a composite foreign key
     * cannot police, because a **system** type has `team_id = null` and a
     * composite key from a NOT NULL `deals.team_id` can never match it
     * (see the migration). ADR 0002 anticipates exactly this — *"where
     * Postgres cannot express the constraint, the relationship carries a test
     * instead"* — and a test alone turned out not to be enough: the first
     * review of Slice 2 created a deal against another team's private type
     * and nothing objected.
     *
     * So the model carries it, for the same reason Slice 1's identity rule
     * ended up on `Person`. The deal screens are #74 and not written yet, and
     * a rule that lives in a controller nobody has written is a rule the
     * controller will be written without.
     */
    protected static function booted(): void
    {
        /*
         * `creating` and `updating`, deliberately **not** `saving`.
         *
         * `saving` fires before `creating`, and `BelongsToTeam` fills
         * `team_id` on `creating` — so a guard on `saving` compares a real
         * deal type id against a `team_id` that is still null, and refuses
         * every team-owned type on insert while waving the shared ones
         * through. The guard's whole effect on the create path was inverted,
         * and the error said the row "belongs to another team" when it
         * belonged to this one.
         *
         * The suite agreed with it, because `DealFactory` sets `team_id` and
         * every call site passed it again on top. `CLAUDE.md` is explicit that
         * a request body must not choose a tenant, so the shape that matters
         * is the one with no `team_id` at all — which is the shape nothing
         * tested.
         */
        static::creating(fn (self $deal) => $deal->guardDealType());
        static::updating(fn (self $deal) => $deal->guardDealType());
    }

    /**
     * The deal type has to be this team's, or everybody's.
     *
     * `deals.deal_type_id` is a plain foreign key rather than a composite one,
     * because a system deal type has `team_id = null` and a composite key from
     * a NOT NULL `deals.team_id` can never match `(null, id)`. So the database
     * accepts any id in the table and the model is what refuses.
     *
     * ## Which half this covers
     *
     * A model-event guard, so it covers the model's save path and nothing
     * else. `Deal::query()->update(['deal_type_id' => …])`, `saveQuietly()`,
     * and a query-builder write all skip model events by design, and a foreign
     * type written any of those ways lands. `HasStateMachine` has the same
     * seam and says so; the difference is that `stages.state` also has
     * `SingleMutationPathTest` reading the source for the spellings the hook
     * cannot see, and `deal_type_id` has no equivalent. **Do not mass-update
     * it.** #74 is the first code that will be tempted to.
     *
     * It also asks only when the column is *dirty*, so a row already pointing
     * at a foreign type can be renamed or closed without the question being
     * re-put. That is deliberate — the alternative is a `deal_types` query on
     * every save of every deal — and it is sound as long as nothing writes the
     * column past this guard, which is the same sentence as the paragraph
     * above.
     */
    private function guardDealType(): void
    {
        if (! $this->isDirty('deal_type_id')) {
            return;
        }

        $type = DealType::query()->whereKey($this->deal_type_id)->first();

        // Ours, or the shared kind. Never another team's.
        if (! $type instanceof DealType || (! $type->isSystem() && $type->team_id !== $this->team_id)) {
            throw ForeignReferenceException::for('deal_types', (string) $this->deal_type_id, $this->team_id);
        }

        /*
         * And not one that has been archived.
         *
         * S76's archive dialog promises *"no new deal will be able to use
         * it"*, and nothing was keeping that promise: `scopeSelectable()`
         * existed for the pickers and had no production caller, so an archived
         * id posted by hand — or held in a form somebody left open while a
         * colleague archived the type — was accepted.
         *
         * Only when the column is changing, which the `isDirty` check above
         * already guarantees. A deal that already points at a type archived
         * since is untouched, and stays renameable and closeable: taking a
         * type out of the pickers must never strand the deals that were
         * already on it, which is the whole reason archiving exists here
         * instead of deletion.
         */
        if ($type->isArchived()) {
            throw ArchivedReferenceException::for('deal_types', $type->getKey());
        }
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
