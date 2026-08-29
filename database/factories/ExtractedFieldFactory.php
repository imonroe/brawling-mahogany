<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ExtractedFieldReviewState;
use App\Enums\ExtractedFieldType;
use App\Models\ExtractedField;
use App\Models\Extraction;
use App\Models\Person;
use App\Models\Team;
use Database\Factories\Concerns\ForcesAttributes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * One proposal, and what a human did about it (#115, #116, #117).
 *
 * ## Pending by default, because that is the state the invariant is about
 *
 * PRD §6.2: *"nothing reaches `key_dates` or `tasks` except through a confirmed
 * row here."* A factory that produced confirmed rows by default would make the
 * ordinary fixture the one case the rule does not cover.
 *
 * ## Why the reviewed states take the person
 *
 * `extracted_fields_reviewed_completely_check` refuses a row that has left
 * `pending` without a `reviewed_at`, so a `confirmed()` state that only wrote
 * the enum would fail at the database rather than in the test that meant to use
 * it. Taking the reviewer as an argument is what stops the two halves drifting:
 * there is no way to spell "confirmed" here without saying who.
 *
 * `ForcesAttributes` for the reason `ExtractionFactory` gives — the model is
 * `#[Fillable([])]`, and `extraction_id` is a composite key over
 * `(team_id, extraction_id)`, so the parent has to be built inside the row's own
 * team.
 *
 * @extends Factory<ExtractedField>
 */
class ExtractedFieldFactory extends Factory
{
    use ForcesAttributes;

    protected $model = ExtractedField::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'extraction_id' => fn (array $attributes): string => Extraction::factory()
                ->create(['team_id' => $attributes['team_id']])
                ->getKey(),
            'field_type' => ExtractedFieldType::KeyDate,
            'label' => 'Inspection Objection Deadline',
            /*
             * ISO, because that is the shape the prompt asks for and the only
             * shape `ConfirmExtractedField::asDay()` accepts. A default the
             * confirm path refuses would make every test that did not think
             * about it assert on the *"that is not a date"* refusal.
             */
            'proposed_value' => '2026-07-25',
            'confidence' => 0.92,
            'source_page' => 3,
            'source_snippet' => 'Inspection Objection Deadline           July 25, 2026',
            'review_state' => ExtractedFieldReviewState::Pending,
            'sort_order' => 0,
        ];
    }

    public function keyDate(string $label = 'Inspection Objection Deadline', string $value = '2026-07-25'): static
    {
        return $this->state(fn (): array => [
            'field_type' => ExtractedFieldType::KeyDate,
            'label' => $label,
            'proposed_value' => $value,
        ]);
    }

    /**
     * An inspection finding somebody may accept as work (#117).
     *
     * `label` and `proposed_value` are the same sentence, which is what
     * `ReadProposals` produces: a task's name *is* its value, and the review
     * screen's editable field is the title that would be created.
     */
    public function task(string $title = 'Repair the loose stair handrail'): static
    {
        return $this->state(fn (): array => [
            'field_type' => ExtractedFieldType::Task,
            'label' => $title,
            'proposed_value' => $title,
            'payload' => ['detail' => 'The handrail on the basement stair is not secured at the lower bracket.', 'severity' => 'safety'],
        ]);
    }

    /**
     * A contract provision, which becomes a note rather than a row (F10.1).
     *
     * The label is the type's own word because a provision has no name of its
     * own — it is a sentence, and the sentence is the value.
     */
    public function provision(string $summary = 'Seller conveys the two garage door openers at Possession.'): static
    {
        return $this->state(fn (): array => [
            'field_type' => ExtractedFieldType::Provision,
            'label' => 'Provision',
            'proposed_value' => $summary,
        ]);
    }

    /**
     * Accepted as proposed.
     *
     * `final_value` is written even though it equals the proposal, and that is
     * F10.4's point rather than an oversight: a null there would make
     * *"confirmed unchanged"* and *"never reviewed"* the same row, and the
     * 85%-without-edit target is exactly the difference between them.
     */
    public function confirmed(Person $by): static
    {
        return $this->state(fn (array $attributes): array => [
            'review_state' => ExtractedFieldReviewState::Confirmed,
            'final_value' => $attributes['proposed_value'] ?? null,
            'reviewed_by' => $by->getKey(),
            'reviewed_at' => now(),
        ]);
    }

    /**
     * Discarded — an ordinary outcome, and for an inspection report the common
     * one (#117). The row stays, because *what the model got wrong* is the
     * question #118 asks this table.
     */
    public function rejected(Person $by): static
    {
        return $this->state(fn (): array => [
            'review_state' => ExtractedFieldReviewState::Rejected,
            'final_value' => null,
            'reviewed_by' => $by->getKey(),
            'reviewed_at' => now(),
        ]);
    }

    /** A date the model flagged as one a deal turns on. */
    public function critical(): static
    {
        return $this->state(fn (array $attributes): array => [
            'payload' => [
                ...(is_array($attributes['payload'] ?? null) ? $attributes['payload'] : []),
                'critical' => true,
            ],
        ]);
    }

    /**
     * Design System §2.5: confidence is information, never permission.
     *
     * Two decimal places on a 0..1 scale, which is what the column holds — more
     * precision would imply a calibration no provider offers, and the CHECK
     * refuses anything outside the range.
     */
    public function withConfidence(float $confidence): static
    {
        return $this->state(fn (): array => ['confidence' => $confidence]);
    }
}
