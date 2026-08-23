<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DealDraftStep;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasProductDefaults;
use Database\Factories\DealDraftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A deal somebody has started and not finished (S14 · issue #74).
 *
 * The migration argues why this is a staging row rather than a `draft` deal.
 * What the model adds is the reading half: a payload is a form in progress, so
 * every accessor here has to answer *"has this been said yet"* rather than
 * *"what is the value"* — and `null` is the answer for most of the life of the
 * row.
 *
 * **`payload` is not fillable.** A request body sends one step at a time and
 * `RecordDealDraft` merges it; letting a form replace the whole payload would
 * let step four erase steps one to three, which is precisely what a wizard
 * must not do when somebody presses Back.
 *
 * @property string $id
 * @property string $team_id
 * @property string|null $created_by_person_id
 * @property DealDraftStep $step
 * @property array<string, mixed>|null $payload
 * @property string|null $deal_id
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['step'])]
class DealDraft extends Model
{
    /** @use HasFactory<DealDraftFactory> */
    use BelongsToTeam, HasFactory, HasProductDefaults;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'step' => DealDraftStep::class,
            // JSONB, like every other `config()` column in this schema.
            'payload' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'created_by_person_id');
    }

    /**
     * @return BelongsTo<Deal, $this>
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** One value out of the payload, or null when nobody has said. */
    public function answer(string $key): mixed
    {
        return $this->payload[$key] ?? null;
    }

    /** A payload value as a trimmed string, or null when it is blank. */
    public function text(string $key): ?string
    {
        $value = $this->answer($key);

        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * The deal type, if one is still choosable.
     *
     * Resolved rather than trusted: the payload holds an id, and a type
     * archived since the draft was started is no longer a type this deal can
     * be opened on (`Deal::guardDealType()` would refuse it at save time).
     * Returning null makes the wizard ask again, which is the honest
     * behaviour — a resumed draft is not a promise that the world stood still.
     */
    public function dealType(): ?DealType
    {
        $id = $this->text('deal_type_id');

        if ($id === null) {
            return null;
        }

        $type = DealType::query()->whereKey($id)->first();

        return $type instanceof DealType && $type->isSelectableBy($this->team_id) ? $type : null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('completed_at');
    }
}
