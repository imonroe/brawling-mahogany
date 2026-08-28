<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\ExtractedFieldType;
use App\Models\Deal;
use App\Models\ExtractedField;
use App\Models\Extraction;
use App\Models\KeyDate;
use App\Support\Dates\SaveKeyDate;
use App\Support\Extraction\KeyDateNames;
use App\Support\Extraction\Money;
use App\Support\Formatting\Format;

/**
 * S66 and S67's props, composed server-side (#116, #117).
 *
 * Screen Inventory gives the two screens **the same URL**, discriminated by
 * `extractions.kind`, so this is one query answering both. Saying that in both
 * places is what stops somebody building two.
 *
 * ## The conflict and the cascade are computed here, with the writer's own function
 *
 * `SaveKeyDate::preview()` is called for the cascade, not a re-implementation
 * of it. CLAUDE.md: *"the preview and the save are the same function or they
 * are two answers"* — and the consequence of two answers here is a screen that
 * says confirming will move four deadlines over a save that moves six.
 *
 * ## Reading is not advancing, and it is not extracting either
 *
 * Nothing in this class writes. `preview()` is the pure half of `SaveKeyDate`
 * by design (#106), the same way `DescribeBlockers` is the pure half of
 * `AdvanceWorkflow`.
 */
final class DealExtraction
{
    public function __construct(private readonly SaveKeyDate $keyDates) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Extraction $extraction, Deal $deal): array
    {
        $extraction->loadMissing(['document', 'fields.reviewer']);

        return [
            'id' => $extraction->getKey(),
            'kind' => $extraction->kind->value,
            'kindLabel' => $extraction->kind->label(),
            'state' => $extraction->state->value,
            'documentName' => $extraction->document->original_name,
            'documentUrl' => "/deals/{$deal->getKey()}/documents/{$extraction->document_id}",
            'provenance' => [
                'provider' => $extraction->provider,
                'model' => $extraction->model,
                'modelVersion' => $extraction->model_version,
                'promptVersion' => $extraction->prompt_version,
                /*
                 * Words rather than a number, because Design System §9.5 puts
                 * the cost in a 12px muted line beside the model — and because
                 * `cost_micros` is a unit no front end should have to know.
                 * A zero-cost row shows nothing rather than "$0.00", which
                 * would read as "this was free" over a row that was never
                 * priced.
                 */
                'cost' => $extraction->cost_micros > 0 ? Money::words($extraction->cost_micros) : null,
                'latencyMs' => $extraction->latency_ms,
            ],
            'error' => $extraction->error,
            'omittedCount' => $this->omittedCount($extraction),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fields(Extraction $extraction, Deal $deal): array
    {
        /*
         * Loaded once for the whole page rather than per row. Eleven proposals
         * each asking the database whether the deal already has that deadline
         * is eleven queries for one screen, and the answer is the same list
         * every time.
         */
        $existing = KeyDate::query()->where('deal_id', $deal->getKey())->get();

        /*
         * `array_values` around the collection's own `all()`: a collection
         * preserves keys through `map()`, and `values()` before it is not
         * something PHPStan can carry through to the return type. The wrapper
         * is what makes the `list<>` promise true rather than merely intended.
         */
        return array_values($extraction->fields
            ->sortBy('sort_order')
            ->map(fn (ExtractedField $field): array => $this->row($field, $existing, $deal))
            ->all());
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, KeyDate>  $existing
     * @return array<string, mixed>
     */
    private function row(ExtractedField $field, $existing, Deal $deal): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $field->payload ?? [];

        $conflict = null;
        $cascade = [];

        if ($field->field_type === ExtractedFieldType::KeyDate && $field->isPending()) {
            $match = KeyDateNames::match($field->label, $existing);

            if ($match instanceof KeyDate && $match->date->toDateString() !== $field->proposed_value) {
                /*
                 * The same computation the save runs. `preview()` walks the
                 * graph from the proposed day and returns what would move —
                 * which is what Design System §7.4 band 3 requires the conflict
                 * strip to state: *"shifts 4 derived deadlines"*, the
                 * consequence, not merely that there is a difference.
                 */
                $changes = $this->keyDates->preview($match, ['date' => $field->proposed_value]);

                $cascade = array_map(
                    static fn ($change): array => $change->toArray(),
                    $changes,
                );

                $conflict = [
                    'name' => $match->name,
                    'currentDate' => Format::date($match->date),
                    'movesCount' => count($cascade),
                ];
            }
        }

        return [
            'id' => $field->getKey(),
            'fieldType' => $field->field_type->value,
            'label' => $field->label,
            'proposedValue' => $field->proposed_value,
            'value' => $field->value(),
            'confidence' => $field->confidenceValue(),
            'sourcePage' => $field->source_page,
            'sourceSnippet' => $field->source_snippet,
            'reviewState' => $field->review_state->value,
            'reviewedByName' => $field->reviewer?->displayNameWithin($deal->team),
            'reviewedAt' => $field->reviewed_at?->toIso8601String(),
            'isCritical' => (bool) ($payload['critical'] ?? false),
            /*
             * A date the model *worked out* rather than read. S66 has to be
             * able to say so: an offset resolved against the wrong acceptance
             * date looks exactly like a date read correctly off the page, and
             * this is the only thing that tells them apart.
             */
            'derivation' => is_string($payload['derivation'] ?? null) ? $payload['derivation'] : null,
            'detail' => is_string($payload['detail'] ?? null) ? $payload['detail'] : null,
            'severity' => is_string($payload['severity'] ?? null) ? $payload['severity'] : null,
            'conflict' => $conflict,
            'cascade' => $cascade,
            'createdRecordUrl' => $this->createdRecordUrl($field, $deal),
        ];
    }

    /**
     * @return array{reviewed: int, total: int}
     */
    public function progress(Extraction $extraction): array
    {
        return [
            'reviewed' => $extraction->fields->reject(
                static fn (ExtractedField $field): bool => $field->isPending(),
            )->count(),
            'total' => $extraction->fields->count(),
        ];
    }

    /**
     * How many findings the model read and left out (#117).
     *
     * Shown so a reviewer knows how much was cut and can go looking if the
     * number surprises them. It comes out of the raw response rather than a
     * column, because it is a fact about the *answer* rather than about the
     * extraction, and inventing a column for one kind's one number would put a
     * null on every contract row forever.
     */
    private function omittedCount(Extraction $extraction): ?int
    {
        $raw = $extraction->raw_response;

        if (! is_array($raw)) {
            return null;
        }

        foreach ($raw['content'] ?? [] as $block) {
            if (! is_array($block) || ($block['type'] ?? null) !== 'text') {
                continue;
            }

            $text = is_string($block['text'] ?? null) ? $block['text'] : '';

            if (preg_match('/"omitted"\s*:\s*(\d{1,4})/', $text, $found) === 1) {
                return (int) $found[1];
            }
        }

        return null;
    }

    private function createdRecordUrl(ExtractedField $field, Deal $deal): ?string
    {
        if ($field->created_record_id === null) {
            return null;
        }

        return match ($field->field_type) {
            ExtractedFieldType::KeyDate => "/deals/{$deal->getKey()}/dates",
            ExtractedFieldType::Task => "/deals/{$deal->getKey()}/tasks",
            ExtractedFieldType::Provision => "/deals/{$deal->getKey()}/timeline",
        };
    }
}
