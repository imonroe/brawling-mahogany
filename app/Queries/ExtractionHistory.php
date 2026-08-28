<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\ExtractedFieldReviewState;
use App\Enums\ExtractedFieldType;
use App\Models\ExtractedField;
use App\Models\Extraction;
use App\Models\Team;
use App\Support\Extraction\Money;
use App\Support\Extraction\SpendLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * S68's props — three questions, composed once (PRD §4.10 F10.4, §12.3 · #118).
 *
 * #118 is explicit that this screen is not one table. Three different people
 * ask three different things of the same rows:
 *
 * 1. **Audit** — *who confirmed this date, from which page, on what date?*
 *    Asked when something has gone wrong.
 * 2. **Cost** — *what is this team costing, and are we under $2 per deal?*
 *    Asked monthly.
 * 3. **Quality** — *what is the model getting wrong, and has a version change
 *    made it worse?* Asked continuously.
 *
 * So this returns four separately shaped payloads rather than one row list,
 * and the page draws them as four regions. An undifferentiated table would
 * answer the first question badly and the other two not at all.
 *
 * ## What the human changed is the top of the screen, not a column in it
 *
 * F10.4 asks for the source document, the model and version, the prompt
 * version, the raw output, the confidence, and **what the human changed**.
 * #118 calls that last one *"the valuable one — simultaneously the audit
 * trail, the quality metric, and the input to improving the prompt."* It is
 * therefore `edits()`, its own payload with the proposal and the final value
 * side by side, rather than a cell somebody has to scroll to find. Every other
 * fact on this screen is derived from it — the confirmed-without-edit rate is
 * literally a count of the rows that do *not* appear in that list.
 *
 * ## Critical dates missed is deliberately absent as a number
 *
 * PRD §12.3's second target is *"Critical dates missed entirely by extraction —
 * **Zero tolerance** … Measure against a hand-checked corpus before shipping."*
 * This class cannot measure it and will never be able to: a miss is a date the
 * contract contained and the model did not report, so it leaves **no row**
 * anywhere in `extractions` or `extracted_fields`. The only honest thing the
 * live data can say about misses is nothing at all.
 *
 * The failure mode of getting this wrong is specific and bad. A tile reading
 * *"0 missed"* would be true of a perfect model and equally true of a model
 * that read one page of a twelve-page contract, and somebody would eventually
 * make a decision on it. CLAUDE.md's rule for exactly this shape — *"report a
 * number, do not imply a limit"* — is why S50 shows storage used with no
 * ceiling drawn beside it.
 *
 * So `criticalDates` carries the **target and where it is measured**, and no
 * count. It is shown rather than omitted because omitting it would lose PRD
 * §12.3's third target from the one screen the other two are read on, and a
 * reader would fairly conclude there are only two. `app/Console/Commands/
 * ScoreExtractions.php` — `php artisan extraction:score` — is the thing that
 * answers it, against #14's hand-checked corpus, and the screen names that
 * command so the question has somewhere to go.
 *
 * ## The rate is over **dates**, and the denominator is shown
 *
 * PRD §12.3 says *"Extracted **dates** confirmed without edit"*, so the rate is
 * computed over `field_type = key_date` and nothing else — an inspection
 * report's tasks are a different proposal answering a different prompt, and
 * folding them in would make the number drift for a reason that has nothing to
 * do with the target. `versions()` filters the same way, or the headline and
 * the breakdown would be two numbers claiming to be one.
 *
 * `rejected` is in the denominator with `confirmed` and `edited`. The question
 * is *what did the model get right*, and a proposal a human threw away is a
 * proposal the model got wrong — counting only the accepted ones would let a
 * model that proposes nonsense score 100% by having it all rejected. `pending`
 * is excluded, because nobody has judged it yet.
 *
 * The **denominator travels with the percentage**, because 100% of three
 * proposals is not a quality measurement and a bare *"100%"* reads as though it
 * were. `meetsTarget` is decided on the exact ratio rather than on the rounded
 * percentage, so 84.6% does not display as 85 and claim a pass.
 *
 * ## The query budget
 *
 * Eight queries, fixed, whatever the volume — the discipline
 * `PeopleIndexBudgetTest` sets: *"the same page, ten times the rows, the same
 * number of queries."* One grouped count of review outcomes, one derived-table
 * aggregate for per-deal cost, one month sum through `SpendLedger`, two for the
 * version breakdown (attempts and outcomes, merged in PHP rather than joined,
 * because joining them would multiply the cost sum by the field count), and
 * three for the two capped row lists and their eager loads. Nothing here asks a
 * question per row.
 *
 * Both row lists are **capped**, not paginated, and the payload says how many
 * rows the cap is hiding. A team with a year of extractions must not load all
 * of them, and this screen's job is the recent picture; the per-deal audit
 * trail in full lives on S66, which every row links to.
 */
final class ExtractionHistory
{
    /** PRD §12.3: *"Extracted dates confirmed without edit — above 85%."* */
    public const CONFIRMED_WITHOUT_EDIT_TARGET = 85;

    /** PRD §12.3, §14.3: *"AI cost per deal — under $2."* */
    public const COST_PER_DEAL_TARGET_MICROS = 2 * Money::MICROS_PER_DOLLAR;

    /** The command that measures what this class cannot. */
    public const REGRESSION_COMMAND = 'php artisan extraction:score';

    private const ATTEMPT_LIMIT = 50;

    private const EDIT_LIMIT = 20;

    public function __construct(private readonly SpendLedger $ledger) {}

    /**
     * @return array<string, mixed>
     */
    public function scorecard(): array
    {
        return [
            'confirmedWithoutEdit' => $this->confirmedWithoutEdit(),
            'costPerDeal' => $this->costPerDeal(),
            'criticalDates' => $this->criticalDates(),
        ];
    }

    /**
     * The month's spend against the ceiling (#113 · PRD §14.3).
     *
     * `resetsAt` is a **UTC** month boundary, which is the one date in this
     * product that is not asked in the team's timezone — `SpendLedger` argues
     * why, and the consequence is that a team in UTC-7 sees its budget reset at
     * 5pm on the last day of the month. It is sent as an instant so the screen
     * can render it in the reader's own zone and say plainly that the month
     * itself is counted in UTC. A team told *"resets on the 1st"* over a ledger
     * that rolled over the previous afternoon has been told the wrong thing.
     *
     * @return array<string, mixed>
     */
    public function spend(Team $team): array
    {
        $spent = $this->ledger->teamSpentThisMonth($team);
        $cap = $this->ledger->capFor($team);

        /*
         * A zero or negative cap means "no ceiling" to `SpendLedger::decide()`,
         * so it has to mean the same thing here. Rendering it as `$0.00` would
         * say the opposite of what it does.
         */
        $capped = $cap > 0;

        return [
            'monthToDate' => Money::words($spent),
            'cap' => $capped ? Money::words($cap) : null,
            'percent' => $capped ? $this->percent($spent, $cap) : null,
            /*
             * The same threshold `SpendLedger::shouldWarn()` uses, read from
             * the same config key. Two numbers here would mean the screen going
             * amber at a different moment from the one an extraction is warned
             * at.
             */
            'warnAtPercent' => (int) config('extraction.caps.warn_at_percent', 80),
            'resetsAt' => $this->ledger->resetsAt()->toIso8601String(),
        ];
    }

    /**
     * One row per model + model version + prompt version (#118's quality axis).
     *
     * *"Has a version change made it worse"* is only answerable if the versions
     * are the grouping rather than a column, which is why the row list beneath
     * cannot serve this question: a change of prompt is visible as a **new row
     * with its own rate**, next to the old one, rather than as a value that
     * differs somewhere down a list of fifty.
     *
     * Two queries merged in PHP rather than one joined query. Joining
     * `extracted_fields` to `extractions` and summing `cost_micros` in the same
     * statement multiplies each attempt's cost by its number of proposals —
     * an eleven-date contract would report eleven times what it cost.
     *
     * @return list<array<string, mixed>>
     */
    public function versions(): array
    {
        $attempts = Extraction::query()
            /*
             * A row that never reached a provider has no version to report —
             * `blocked` by the cap, or `queued` and not yet started. Grouping
             * them would produce a null-null-null row whose rate is a mixture
             * of every version that ever ran.
             */
            ->whereNotNull('model')
            ->toBase()
            ->selectRaw('model, model_version, prompt_version')
            ->selectRaw('count(*) as attempts')
            ->selectRaw('coalesce(sum(cost_micros), 0) as cost')
            ->selectRaw('max(created_at) as last_used')
            ->groupBy('model', 'model_version', 'prompt_version')
            ->orderByRaw('max(created_at) desc')
            ->get();

        $outcomes = $this->outcomesByVersion();

        return $attempts->map(function (object $row) use ($outcomes): array {
            $key = $this->versionKey(
                $this->text($row->model),
                $this->text($row->model_version),
                $this->text($row->prompt_version),
            );

            /** @var array{reviewed: int, confirmed: int} $outcome */
            $outcome = $outcomes[$key] ?? ['reviewed' => 0, 'confirmed' => 0];

            return [
                'key' => $key,
                'model' => $this->text($row->model),
                'modelVersion' => $this->text($row->model_version),
                'promptVersion' => $this->text($row->prompt_version),
                'attempts' => (int) $row->attempts,
                /*
                 * Null rather than "$0.00" for a version that was never
                 * priced — a provider we hold no rate for, or a run against a
                 * fixture. `DealExtraction` makes the same call for the same
                 * reason: a zero cost reads as *"this was free"* over a row
                 * nothing ever costed.
                 */
                'cost' => (int) $row->cost > 0 ? Money::words((int) $row->cost) : null,
                'reviewed' => $outcome['reviewed'],
                'confirmedWithoutEdit' => $this->rate($outcome['confirmed'], $outcome['reviewed']),
                'lastUsedAt' => $this->instant($row->last_used),
            ];
        })->all();
    }

    /**
     * What the human changed — F10.4's column, as its own payload (#118).
     *
     * Both values, never a diff and never a flag. *"Edited"* on its own is the
     * fact that is useless to all three readers: the auditor needs to see what
     * the record now says, the quality reader needs to see what the model said,
     * and the prompt author needs the pair. `finalValue` is `final_value`
     * directly rather than `ExtractedField::value()`, which falls back to the
     * proposal for display — a fallback would render a row where the two
     * columns agree, over an edit where they do not.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function edits(Team $team): array
    {
        $query = ExtractedField::query()
            ->where('review_state', ExtractedFieldReviewState::Edited->value);

        $total = (clone $query)->count();

        $rows = $query
            ->with(['extraction.deal', 'extraction.document', 'reviewer'])
            ->latest('reviewed_at')
            ->limit(self::EDIT_LIMIT)
            ->get()
            ->map(fn (ExtractedField $field): array => [
                'id' => $field->getKey(),
                'label' => $field->label,
                'fieldTypeLabel' => $field->field_type->label(),
                /*
                 * Every row in this list is `edited` by construction, and the
                 * badge is still drawn from the row rather than hard-coded.
                 * A literal in the template is a second statement of the
                 * query's `where`, and the day the list widens to carry
                 * rejections the badge is the half nobody remembers.
                 */
                'reviewState' => $field->review_state->value,
                'proposedValue' => $field->proposed_value,
                'finalValue' => $field->final_value,
                'confidence' => $field->confidenceValue(),
                'sourcePage' => $field->source_page,
                'reviewedByName' => $field->reviewer?->displayNameWithin($team),
                'reviewedAt' => $field->reviewed_at?->toIso8601String(),
                'dealName' => $field->extraction->deal?->displayName(),
                'documentName' => $field->extraction->document?->original_name,
                'promptVersion' => $field->extraction->prompt_version,
                'model' => $field->extraction->model,
                'url' => $this->reviewUrl($field->extraction),
            ])
            ->all();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * The attempts themselves, newest first — the audit spine (#118, F10.4).
     *
     * Every fact F10.4 names that is a fact about the *attempt* rather than
     * about a proposal: the source document, the model and its version, the
     * prompt version, the cost. The raw output and the per-field confidence are
     * deliberately not here — they belong to one extraction and live on S66,
     * which every row links to. Copying them onto a settings list would mean
     * shipping the whole raw response of fifty attempts to a browser.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function attempts(Team $team): array
    {
        $total = Extraction::query()->count();

        $rows = Extraction::query()
            ->with(['deal', 'document', 'requestedBy'])
            /*
             * Counted in the same statement as the rows, which is the whole
             * reason `withCount` exists — the alternative is a query per row on
             * a screen that shows fifty.
             */
            ->withCount([
                'fields',
                'fields as pending_fields_count' => fn (Builder $query) => $query
                    ->where('review_state', ExtractedFieldReviewState::Pending->value),
                'fields as edited_fields_count' => fn (Builder $query) => $query
                    ->where('review_state', ExtractedFieldReviewState::Edited->value),
            ])
            ->latest('created_at')
            ->limit(self::ATTEMPT_LIMIT)
            ->get()
            ->map(fn (Extraction $extraction): array => [
                'id' => $extraction->getKey(),
                'state' => $extraction->state->value,
                'kindLabel' => $extraction->kind->label(),
                'dealName' => $extraction->deal?->displayName(),
                'documentName' => $extraction->document?->original_name,
                'model' => $extraction->model,
                'modelVersion' => $extraction->model_version,
                'promptVersion' => $extraction->prompt_version,
                'cost' => $extraction->cost_micros > 0
                    ? Money::words($extraction->cost_micros)
                    : null,
                'requestedByName' => $extraction->requestedBy?->displayNameWithin($team),
                'createdAt' => $extraction->created_at?->toIso8601String(),
                'proposals' => (int) $extraction->getAttribute('fields_count'),
                'pending' => (int) $extraction->getAttribute('pending_fields_count'),
                'edited' => (int) $extraction->getAttribute('edited_fields_count'),
                'error' => $extraction->error,
                'url' => $this->reviewUrl($extraction),
            ])
            ->all();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * PRD §12.3's first target, over date proposals a human has ruled on.
     *
     * @return array<string, mixed>
     */
    private function confirmedWithoutEdit(): array
    {
        /** @var \Illuminate\Support\Collection<string, int> $counts */
        $counts = ExtractedField::query()
            ->where('field_type', ExtractedFieldType::KeyDate->value)
            ->toBase()
            ->selectRaw('review_state, count(*) as total')
            ->groupBy('review_state')
            ->pluck('total', 'review_state');

        $confirmed = (int) $counts->get(ExtractedFieldReviewState::Confirmed->value, 0);
        $edited = (int) $counts->get(ExtractedFieldReviewState::Edited->value, 0);
        $rejected = (int) $counts->get(ExtractedFieldReviewState::Rejected->value, 0);
        $reviewed = $confirmed + $edited + $rejected;

        return [
            'percent' => $this->rate($confirmed, $reviewed),
            'confirmed' => $confirmed,
            'edited' => $edited,
            'rejected' => $rejected,
            'reviewed' => $reviewed,
            'pending' => (int) $counts->get(ExtractedFieldReviewState::Pending->value, 0),
            'targetPercent' => self::CONFIRMED_WITHOUT_EDIT_TARGET,
            /*
             * Decided on the exact ratio, never on the rounded percentage. A
             * team at 84.6% would otherwise read 85% beside a target of 85%
             * and be told they had met it.
             *
             * Null when nothing has been reviewed: *"does not meet the target"*
             * is a claim, and there is nothing here to make it about.
             */
            'meetsTarget' => $reviewed === 0
                ? null
                : $confirmed * 100 >= $reviewed * self::CONFIRMED_WITHOUT_EDIT_TARGET,
        ];
    }

    /**
     * PRD §12.3's *"under $2 per deal"*, as a distribution rather than a mean.
     *
     * The average alone is the number that hides the case somebody is asking
     * about. Twenty deals at forty cents and one at nine dollars averages under
     * target, and the nine-dollar deal is the entire reason the question gets
     * asked — so `overTarget` counts the deals that individually exceed it, and
     * that is what decides `meetsTarget`.
     *
     * Every extraction a deal has ever paid for, not this month's: a deal runs
     * for weeks and a monthly slice would report each of its months as
     * comfortably under a per-deal ceiling it had already passed.
     *
     * One statement. The inner query is `Extraction`'s own builder, so the
     * global team scope is inside the derived table rather than being restated
     * — `ADR 0002`'s point about a `where` somebody has to remember.
     *
     * @return array<string, mixed>
     */
    private function costPerDeal(): array
    {
        $perDeal = Extraction::query()
            ->toBase()
            ->select('deal_id')
            ->selectRaw('sum(cost_micros) as total')
            ->groupBy('deal_id');

        /*
         * Interpolated rather than bound, and safe because it is an integer
         * constant: `fromSub()` bindings and `selectRaw()` bindings are
         * compiled from different bags, and getting their order wrong is a
         * silent mis-parameterisation rather than an error.
         */
        $row = DB::query()
            ->fromSub($perDeal, 'deal_costs')
            ->selectRaw('count(*) as deals')
            ->selectRaw('coalesce(sum(total), 0) as spent')
            ->selectRaw(sprintf(
                'count(*) filter (where total > %d) as over_target',
                self::COST_PER_DEAL_TARGET_MICROS,
            ))
            ->first();

        $deals = (int) ($row->deals ?? 0);
        $spent = (int) ($row->spent ?? 0);
        $overTarget = (int) ($row->over_target ?? 0);

        return [
            'deals' => $deals,
            'average' => $deals > 0 ? Money::words(intdiv($spent, $deals)) : null,
            'total' => Money::words($spent),
            'overTarget' => $overTarget,
            'target' => Money::words(self::COST_PER_DEAL_TARGET_MICROS),
            'meetsTarget' => $deals === 0 ? null : $overTarget === 0,
        ];
    }

    /**
     * PRD §12.3's zero-tolerance target, with no number attached.
     *
     * See this class's docblock. The screen shows the target and says where it
     * is measured; it does not report a count, because the count this data
     * could produce would always be zero and would always be meaningless.
     *
     * @return array<string, mixed>
     */
    private function criticalDates(): array
    {
        return [
            'target' => 'Zero',
            'measuredHere' => false,
            'command' => self::REGRESSION_COMMAND,
        ];
    }

    /**
     * Review outcomes per version, keyed the same way `versions()` groups.
     *
     * @return array<string, array{reviewed: int, confirmed: int}>
     */
    private function outcomesByVersion(): array
    {
        $rows = ExtractedField::query()
            ->join('extractions', 'extractions.id', '=', 'extracted_fields.extraction_id')
            ->where('extracted_fields.field_type', ExtractedFieldType::KeyDate->value)
            ->whereIn('extracted_fields.review_state', [
                ExtractedFieldReviewState::Confirmed->value,
                ExtractedFieldReviewState::Edited->value,
                ExtractedFieldReviewState::Rejected->value,
            ])
            ->toBase()
            ->selectRaw('extractions.model, extractions.model_version, extractions.prompt_version')
            ->selectRaw('count(*) as reviewed')
            ->selectRaw(sprintf(
                "count(*) filter (where extracted_fields.review_state = '%s') as confirmed",
                ExtractedFieldReviewState::Confirmed->value,
            ))
            ->groupBy('extractions.model', 'extractions.model_version', 'extractions.prompt_version')
            ->get();

        $keyed = [];

        foreach ($rows as $row) {
            $key = $this->versionKey(
                $this->text($row->model),
                $this->text($row->model_version),
                $this->text($row->prompt_version),
            );

            $keyed[$key] = [
                'reviewed' => (int) $row->reviewed,
                'confirmed' => (int) $row->confirmed,
            ];
        }

        return $keyed;
    }

    private function versionKey(?string $model, ?string $modelVersion, ?string $promptVersion): string
    {
        return implode('|', [$model ?? '', $modelVersion ?? '', $promptVersion ?? '']);
    }

    /**
     * A percentage to one decimal place, or null when there is nothing to
     * divide by.
     *
     * Null rather than zero, everywhere. Zero per cent confirmed is a real and
     * alarming measurement; nothing reviewed yet is not a measurement at all,
     * and a screen that drew them the same way would raise an alarm about an
     * empty table.
     */
    private function rate(int $part, int $whole): ?float
    {
        return $whole === 0 ? null : round($part * 100 / $whole, 1);
    }

    private function percent(int $part, int $whole): ?int
    {
        return $whole === 0 ? null : (int) round($part * 100 / $whole);
    }

    private function reviewUrl(?Extraction $extraction): ?string
    {
        /*
         * No deal, no link. The deal is soft-deleted inside PRD §9's thirty-day
         * window and the extraction row is still here — a URL built from a
         * missing id is a 404 dressed as a control, which is the thing
         * `routeTargets.test.ts` exists to stop being written.
         */
        if (! $extraction instanceof Extraction || $extraction->deal === null) {
            return null;
        }

        return "/deals/{$extraction->deal_id}/extractions/{$extraction->getKey()}";
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function instant(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        /*
         * A raw aggregate, not a cast attribute: `max(created_at)` arrives as
         * whatever string the driver produced, so it is parsed here rather than
         * shipped in a format the front end would have to guess at.
         */
        return Carbon::parse((string) $value)->toIso8601String();
    }
}
