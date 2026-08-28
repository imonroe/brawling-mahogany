<?php

declare(strict_types=1);

namespace App\Support\Extraction;

use App\Enums\ActivitySource;
use App\Enums\ExtractedFieldReviewState;
use App\Enums\ExtractedFieldType;
use App\Models\Deal;
use App\Models\ExtractedField;
use App\Models\KeyDate;
use App\Models\Person;
use App\Models\Stage;
use App\Models\Task;
use App\Support\Activity\RecordActivity;
use App\Support\Audit\AuditLogger;
use App\Support\Dates\SaveKeyDate;
use App\Support\Deals\DealTasks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The one door from a proposal into the record (PRD §6.2, §7.16 · #116, #117).
 *
 * PRD §6.2, and it is the sentence the whole slice turns on:
 *
 * > **Nothing reaches `key_dates` or `tasks` except through a confirmed row
 * > here.**
 *
 * This class is what makes that structural rather than a convention. It is the
 * only caller of `SaveKeyDate::addConfirmedExtraction()` and
 * `DealTasks::addConfirmedExtraction()`, and
 * `tests/Unit/ExtractionConfirmationPathTest.php` reads the source and fails
 * when a second one appears — the shape `SingleMutationPathTest` established,
 * chosen because a rule with no test is a hope.
 *
 * ## Confirming is one field at a time, even when a screen offers a button for several
 *
 * S67 may accept a batch of tasks; S66 may never accept a batch of dates.
 * Neither distinction lives here. A caller loops, and each iteration writes its
 * own provenance and its own audit entry — because the thing PRD §9 asks the
 * audit log to record is *"extraction reviews"*, and a batch that wrote one
 * entry for eleven decisions would record the press rather than the reviews.
 *
 * ## What is written, and why all four
 *
 * F10.4 asks for the source document, model and version, prompt version, raw
 * output, confidence, and **what the human changed**. The last is the one #118
 * calls *"the valuable one"*, and it is the reason `final_value` is written
 * even when it equals the proposal: a null there would make "confirmed
 * unchanged" and "never reviewed" the same row, and the 85%-without-edit
 * target is exactly the difference between them.
 */
final class ConfirmExtractedField
{
    public function __construct(
        private readonly SaveKeyDate $keyDates,
        private readonly DealTasks $tasks,
        private readonly RecordActivity $activity,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Accept a proposal, as proposed or as the person edited it.
     *
     * @throws ExtractionNotReviewable
     */
    public function confirm(ExtractedField $field, ?string $value, Person $actor): ExtractedField
    {
        if (! $field->isPending()) {
            /*
             * Not a race and not an error worth a stack trace: two tabs, or a
             * double press on a slow connection. The refusal exists because
             * confirming twice would create the key date twice, and `key_dates`
             * has no natural key to stop it.
             */
            throw ExtractionNotReviewable::alreadyReviewed();
        }

        $value = $value === null || trim($value) === '' ? $field->proposed_value : trim($value);
        $edited = $value !== $field->proposed_value;

        $deal = $field->extraction->deal;

        $created = DB::transaction(function () use ($field, $value, $deal, $actor): ?object {
            $record = match ($field->field_type) {
                ExtractedFieldType::KeyDate => $this->createKeyDate($field, $value, $deal, $actor),
                ExtractedFieldType::Task => $this->createTask($field, $value, $deal, $actor),
                ExtractedFieldType::Provision => $this->recordProvision($field, $value, $deal, $actor),
            };

            $field->forceFill([
                'review_state' => ($value !== $field->proposed_value
                    ? ExtractedFieldReviewState::Edited
                    : ExtractedFieldReviewState::Confirmed)->value,
                'final_value' => $value,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
                'created_record_type' => $record?->getMorphClass(),
                'created_record_id' => $record?->getKey(),
            ])->save();

            return $record;
        });

        $this->recordReview($field, $actor, $edited ? 'extraction.field_edited' : 'extraction.field_confirmed', [
            'created_record_type' => $created?->getMorphClass(),
            'created_record_id' => $created?->getKey(),
        ]);

        return $field;
    }

    /**
     * Discard a proposal.
     *
     * PRD §5.3 has Heather *"discard one that is not relevant"*, and #117 says
     * rejection is *"the expected common case rather than the exception"* for
     * an inspection report. So this is an ordinary outcome with an ordinary
     * record — not a deletion. The row stays, because #118's quality question
     * is *what did the model get wrong*, and a rejected proposal is the clearest
     * possible answer to it.
     *
     * @throws ExtractionNotReviewable
     */
    public function reject(ExtractedField $field, Person $actor): ExtractedField
    {
        if (! $field->isPending()) {
            throw ExtractionNotReviewable::alreadyReviewed();
        }

        $field->forceFill([
            'review_state' => ExtractedFieldReviewState::Rejected->value,
            'reviewed_by' => $actor->getKey(),
            'reviewed_at' => now(),
        ])->save();

        $this->recordReview($field, $actor, 'extraction.field_rejected', []);

        return $field;
    }

    private function createKeyDate(ExtractedField $field, string $value, Deal $deal, Person $actor): ?KeyDate
    {
        $day = $this->asDay($value);

        if ($day === null) {
            /*
             * A person pressed Confirm on something that is not a date. The
             * field is refused rather than coerced: `Carbon::parse` would read
             * *"ten days after closing"* as today, and today's date on a
             * contingency calendar with somebody's name against it is precisely
             * the failure F10.2 exists to prevent.
             */
            throw ExtractionNotReviewable::notADate($value);
        }

        /** @var array<string, mixed> $payload */
        $payload = $field->payload ?? [];

        /*
         * #116's *"conflict with existing"* state, resolved here rather than
         * on the screen.
         *
         * If the deal already carries this deadline, confirming **moves** it
         * instead of adding a second one — and moving is what runs
         * `KeyDateGraph::cascadeFrom()`, so everything derived from it follows.
         * That is also why the cascade preview on S66 is real: `DealExtraction`
         * builds it with `SaveKeyDate::preview()`, which is the same function
         * this call ends up in.
         *
         * The row's `source` is deliberately left alone. A date a person typed
         * and then corrected against a contract is still a date a person owns;
         * what happened to it is the audit entry's business, and rewriting the
         * provenance would lose that it was ever typed.
         */
        $existing = KeyDateNames::match(
            $field->label,
            KeyDate::query()->where('deal_id', $deal->getKey())->get(),
        );

        if ($existing instanceof KeyDate) {
            $this->keyDates->edit($existing, ['date' => $day], $actor);

            return $existing;
        }

        return $this->keyDates->addConfirmedExtraction($deal, [
            'name' => mb_substr($field->label, 0, 120),
            'date' => $day,
            'is_critical' => (bool) ($payload['critical'] ?? false),
            /*
             * The derivation is a **note**, not an anchor.
             *
             * The model reports "MEC + 10 days" and this could plausibly be
             * turned into `anchor_key_date_id` + `offset_days`, which would
             * make the date move when acceptance moves. It is deliberately not,
             * for one reason: the anchor it names may not exist yet. Eleven
             * proposals are confirmed in whatever order somebody works down the
             * screen, and a derivation resolved against a row that is not there
             * would either fail or silently attach to the wrong date.
             *
             * So the day is stored — which is what #106 decided for every date
             * in this product — and the sentence the model read is kept beside
             * it, so a person can link it up by hand on S18 if they want the
             * cascade. Making that automatic is a real improvement and a
             * separate one.
             */
            'notes' => isset($payload['derivation']) && is_string($payload['derivation'])
                ? 'From Extract: '.$payload['derivation']
                : null,
        ], $actor);
    }

    private function createTask(ExtractedField $field, string $value, Deal $deal, Person $actor): ?Task
    {
        /** @var array<string, mixed> $payload */
        $payload = $field->payload ?? [];

        return $this->tasks->addConfirmedExtraction($deal, $this->inspectionStage($deal), [
            'title' => mb_substr($value, 0, 255),
            'description' => is_string($payload['detail'] ?? null) ? $payload['detail'] : null,
            'due_date' => $this->objectionDeadline($deal),
            /*
             * Never `is_required`. An accepted inspection finding is work
             * somebody chose to take on, and marking it required would let it
             * block a `required_tasks_complete` gate — which is the same trap
             * `AdvanceWorkflow::override()`'s follow-up task avoids, for the
             * same reason.
             */
            'is_required' => false,
        ]);
    }

    /**
     * A provision becomes a note on the deal's timeline.
     *
     * PRD F10.1: *"capture additional provisions as notes."* Not a `key_dates`
     * row (it is not a date), not a task (nothing has to be done about "seller
     * conveys the washer"), and not a new table. `NoteController` already puts
     * a note on the timeline as an `activity_events` row, and this is the same
     * thing arriving by a different door.
     *
     * **Internal, always.** `RecordActivity` defaults `isClientVisible` to
     * false and this does not override it: a provision is a sentence a model
     * wrote about somebody's contract, and putting it in front of a client on
     * the strength of one internal confirmation is not a decision this path
     * gets to make.
     */
    private function recordProvision(ExtractedField $field, string $value, Deal $deal, Person $actor): null
    {
        $this->activity->record(
            subject: $deal,
            eventType: 'note.added',
            summary: $value,
            source: ActivitySource::System,
            actor: $actor,
            payload: ['extractedFieldId' => $field->getKey(), 'sourcePage' => $field->source_page],
            deal: $deal,
        );

        /*
         * Null, and `created_record_id` stays empty. An activity event is not
         * a record the review screen can link back to as *"here is the thing
         * your confirmation made"* — the timeline is where it went, and the
         * timeline is a screen rather than a row.
         */
        return null;
    }

    /**
     * #117 step 4: due dates derive from the objection deadline.
     *
     * **Computed once, here, and an ordinary typed date afterwards.** The
     * backlog audit on #117 found that the issue promised a live cascade the
     * schema cannot do — `tasks` has a plain `due_date` and no anchor, offset
     * or basis, and only `key_dates` carries derivation. Rather than promise it
     * anyway, this resolves the deadline at the moment somebody accepts the
     * task, and #117's body has been corrected so it no longer says moving the
     * deadline moves the tasks.
     *
     * Null when the deal has no objection deadline yet, which is the ordinary
     * case for a report that arrives before the contract has been read. A task
     * with no due date is honest; a task due today because there was nothing to
     * derive from is not.
     */
    private function objectionDeadline(Deal $deal): ?string
    {
        $deadline = KeyDate::query()
            ->where('deal_id', $deal->getKey())
            ->where(function ($query): void {
                $query->where('name', 'ilike', '%objection%')
                    ->orWhere('name', 'ilike', '%resolution%');
            })
            ->orderBy('date')
            ->first();

        return $deadline?->date->toDateString();
    }

    /**
     * The Inspection stage, when the deal has one.
     *
     * Matched on the stage's name because there is no stage *type* — stages
     * come from a template and a team may call theirs anything. A null stage is
     * a task on the deal rather than under a stage, which is a supported shape
     * (`tasks.stage_id` is nullable) and much better than guessing.
     */
    private function inspectionStage(Deal $deal): ?Stage
    {
        return Stage::query()
            ->whereIn('workflow_id', $deal->workflows()->select('id'))
            ->where('name', 'ilike', '%inspection%')
            ->orderBy('sort_order')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function recordReview(ExtractedField $field, Person $actor, string $action, array $after): void
    {
        /*
         * PRD §9 lists extraction reviews among the six things the audit log
         * must cover, and this is the entry. It carries what was proposed and
         * what was accepted, because F10.4's *"what the human changed"* is the
         * question somebody asks it — and it names the extraction, so the model
         * and prompt version behind the proposal are one join away.
         *
         * `AuditRedactor` will strip `notes`-shaped keys on the way in, which
         * is why the values are keyed `proposed_value` and `final_value`
         * rather than folded into a sentence.
         */
        $this->audit->record(
            action: $action,
            auditable: $field,
            actorPersonId: $actor->getKey(),
            before: [
                'review_state' => ExtractedFieldReviewState::Pending->value,
                'proposed_value' => $field->proposed_value,
            ],
            after: [
                'review_state' => $field->review_state->value,
                'final_value' => $field->final_value,
                'extraction_id' => $field->extraction_id,
                'field_type' => $field->field_type->value,
                'confidence' => $field->confidence,
                'source_page' => $field->source_page,
                ...$after,
            ],
        );
    }

    private function asDay(string $value): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d', $value);
        } catch (Throwable) {
            // Carbon 3 throws where Carbon 2 returned false. See `ReadProposals`.
            return null;
        }

        /*
         * Round-tripped rather than merely parsed: `createFromFormat` reads
         * `2026-13-45` as a real day in 2027, and the shape passes the regex
         * above. A date nobody wrote landing on a contingency calendar is
         * exactly the failure F10.2 exists to prevent.
         */
        return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : null;
    }
}
