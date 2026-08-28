<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExtractedFieldReviewState;
use App\Enums\ExtractedFieldType;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasProductDefaults;
use Database\Factories\ExtractedFieldFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One proposal, and what a human did about it (PRD §6.2, §7.16 · issue #115).
 *
 * ## The row is the gate
 *
 * PRD §6.2: *"Nothing reaches `key_dates` or `tasks` except through a confirmed
 * row here."* Every field of this model exists to make that sentence
 * enforceable rather than aspirational — the proposal, so there is something to
 * confirm; the snippet and page, so a human can check it against the document;
 * `final_value`, so *what they changed* survives; and the reviewer and the
 * time, so the audit entry has something to agree with.
 *
 * ## Confidence lives here and is not a state
 *
 * Design System §2.5 is explicit, and it is the reason `confidence` is a
 * decimal beside `review_state` rather than folded into it. They answer
 * different questions — *"how sure was the model"* and *"what did a person
 * decide"* — and a reader who saw them share a badge would take the first for
 * the second. F10.2 turns that into a rule with legal weight: confidence is
 * rendered *as information, never as permission*.
 *
 * @property string $id
 * @property string $team_id
 * @property string $extraction_id
 * @property ExtractedFieldType $field_type
 * @property string $label
 * @property string $proposed_value
 * @property string|null $confidence
 * @property int|null $source_page
 * @property string|null $source_snippet
 * @property ExtractedFieldReviewState $review_state
 * @property string|null $final_value
 * @property string|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $created_record_type
 * @property string|null $created_record_id
 * @property array<string, mixed>|null $payload
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([])]
class ExtractedField extends Model
{
    /** @use HasFactory<ExtractedFieldFactory> */
    use BelongsToTeam, HasFactory, HasProductDefaults;

    /**
     * Above this, a proposal is drawn as high confidence.
     *
     * A rendering threshold and nothing else. It decides which icon S66 draws,
     * and it decides nothing about what may be confirmed — there is no branch
     * anywhere that treats a 0.99 differently from a 0.4 when a human presses
     * Confirm, because F10.2 forbids one.
     */
    public const HIGH_CONFIDENCE = 0.80;

    /** Below this, a proposal is drawn as low confidence and flagged. */
    public const LOW_CONFIDENCE = 0.50;

    protected function casts(): array
    {
        return [
            'field_type' => ExtractedFieldType::class,
            'review_state' => ExtractedFieldReviewState::class,
            'reviewed_at' => 'datetime',
            'payload' => 'array',
            'source_page' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Extraction, $this> */
    public function extraction(): BelongsTo
    {
        return $this->belongsTo(Extraction::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'reviewed_by')->withTrashed();
    }

    public function isPending(): bool
    {
        return $this->review_state === ExtractedFieldReviewState::Pending;
    }

    /**
     * The value that stands: what the human settled on, or the proposal.
     *
     * Never a place to decide anything. A pending row has no accepted value and
     * this returns the proposal for *display*, which is what S66 draws in the
     * editable field before anybody touches it.
     */
    public function value(): string
    {
        return $this->final_value ?? $this->proposed_value;
    }

    public function confidenceValue(): ?float
    {
        return $this->confidence === null ? null : (float) $this->confidence;
    }
}
