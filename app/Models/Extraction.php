<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExtractionKind;
use App\Enums\ExtractionState;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasProductDefaults;
use Database\Factories\ExtractionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One attempt at reading a document (PRD §6.2, §8.4 · issue #115).
 *
 * ## Nothing here writes itself either
 *
 * `App\Support\Extraction\StartExtraction` creates the row and
 * `App\Support\Extraction\PerformExtraction` is the only thing that moves its
 * state. The reason is the same one `AdvanceWorkflow` gives: a state change
 * here is never only a state change. Claiming `queued → processing` is how two
 * workers are stopped from spending twice for one document, and completing is
 * what writes the cost that the next extraction's cap check reads.
 *
 * @property string $id
 * @property string $team_id
 * @property string $document_id
 * @property string $deal_id
 * @property ExtractionKind $kind
 * @property ExtractionState $state
 * @property string|null $provider
 * @property string|null $model
 * @property string|null $model_version
 * @property string|null $prompt_version
 * @property array<string, mixed>|null $raw_response
 * @property string|null $redacted_text
 * @property array<string, mixed>|null $redaction_report
 * @property int $cost_micros
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property int|null $latency_ms
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property string|null $error
 * @property string|null $error_code
 * @property string|null $requested_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([])]
class Extraction extends Model
{
    /** @use HasFactory<ExtractionFactory> */
    use BelongsToTeam, HasFactory, HasProductDefaults;

    /**
     * Nothing is mass-assignable, and that is the point.
     *
     * Every column here is either a fact about a provider call or a fact about
     * the pipeline's own progress. Neither is ever a thing a request body
     * supplies, so an empty `#[Fillable]` makes the writer services' use of
     * `forceFill` the only way in — and makes a controller that tried to
     * shortcut it fail loudly rather than write a plausible-looking row.
     */
    protected function casts(): array
    {
        return [
            'kind' => ExtractionKind::class,
            'state' => ExtractionState::class,
            'raw_response' => 'array',
            'redaction_report' => 'array',
            'cost_micros' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'latency_ms' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<Deal, $this> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'requested_by')->withTrashed();
    }

    /** @return HasMany<ExtractedField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(ExtractedField::class);
    }
}
