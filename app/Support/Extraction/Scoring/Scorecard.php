<?php

declare(strict_types=1);

namespace App\Support\Extraction\Scoring;

use App\Enums\ExtractedFieldType;
use App\Support\Extraction\KeyDateNames;
use App\Support\Extraction\Money;
use App\Support\Extraction\Proposal;
use App\Support\Extraction\Redaction\RedactionReport;

/**
 * The three numbers PRD §12.3 asks for, and one it deliberately refuses to guess.
 *
 * | Metric | Target |
 * |---|---|
 * | Extracted dates confirmed without edit | Above 85% |
 * | Critical dates missed entirely | **Zero** |
 * | Cost per contract | Under $2 |
 *
 * ## "Confirmed without edit" is simulated, and the simulation is stated
 *
 * In production that metric is measured from `extracted_fields`: a row whose
 * `final_value` equals its `proposed_value` was confirmed unchanged. Offline
 * there is no human, so this stands in the reviewer's place — a proposal that
 * matches the ground truth exactly is one that *would have been* confirmed
 * without edit. That is a fair proxy and it is not the same measurement, and
 * saying so is the difference between a harness and a number somebody quotes.
 *
 * ## A miss is the one metric that cannot be computed from live data at all
 *
 * The application can never know what a contract contained but the model did
 * not report — a miss is by construction invisible to it. That is the whole
 * reason this harness exists rather than a dashboard, and it is why S68 must
 * not print a zero it cannot see (#118).
 *
 * ## Matching is by name, through the same function the product uses
 *
 * `KeyDateNames::key()` — so *Inspection Objection Deadline* and *Inspection
 * objection* are one deadline here exactly as they are on S66. A scorer with
 * its own idea of when two names are the same would report misses the product
 * would not make, or hide ones it would.
 */
final class Scorecard
{
    /** @var list<array{case: CorpusCase, matched: int, exact: int, missed: list<string>, criticalMissed: list<string>, cost: int}> */
    private array $rows = [];

    /** @var list<array{slug: string, reason: string}> */
    private array $failures = [];

    private int $redactionsTotal = 0;

    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly string $promptVersion,
    ) {}

    /**
     * @param  list<Proposal>  $proposals
     */
    public function record(CorpusCase $case, array $proposals, int $costMicros, RedactionReport $report): void
    {
        $proposed = [];

        foreach ($proposals as $proposal) {
            if ($proposal->type !== ExtractedFieldType::KeyDate) {
                continue;
            }

            $proposed[KeyDateNames::key($proposal->label)] = $proposal->value;
        }

        $matched = 0;
        $exact = 0;
        $missed = [];
        $criticalMissed = [];

        foreach ($case->dates as $truth) {
            $key = KeyDateNames::key($truth['label']);

            if (! array_key_exists($key, $proposed)) {
                $missed[] = $truth['label'];

                if ($truth['critical']) {
                    $criticalMissed[] = $truth['label'];
                }

                continue;
            }

            $matched++;

            if ($proposed[$key] === $truth['value']) {
                $exact++;
            }
        }

        $this->redactionsTotal += $report->total();

        $this->rows[] = [
            'case' => $case,
            'matched' => $matched,
            'exact' => $exact,
            'missed' => $missed,
            'criticalMissed' => $criticalMissed,
            'cost' => $costMicros,
        ];
    }

    public function recordFailure(CorpusCase $case, string $reason): void
    {
        $this->failures[] = ['slug' => $case->slug, 'reason' => $reason];
    }

    /** Proportion of the truth's dates the model reported with the right day. */
    public function exactRate(): float
    {
        $truth = $this->truthCount();

        return $truth === 0 ? 0.0 : $this->sum('exact') / $truth;
    }

    public function criticalMissedCount(): int
    {
        return array_sum(array_map(
            static fn (array $row): int => count($row['criticalMissed']),
            $this->rows,
        ));
    }

    public function missedCount(): int
    {
        return array_sum(array_map(
            static fn (array $row): int => count($row['missed']),
            $this->rows,
        ));
    }

    public function averageCostMicros(): int
    {
        return $this->rows === [] ? 0 : (int) round($this->sum('cost') / count($this->rows));
    }

    /**
     * Did this run clear every target?
     *
     * A **failure to call the provider counts as a fail**, and that is not
     * pedantry: a run where nineteen of twenty contracts errored would
     * otherwise report a beautiful rate over the one that worked.
     */
    public function passes(): bool
    {
        return $this->failures === []
            && $this->criticalMissedCount() === 0
            && $this->exactRate() > 0.85
            && $this->averageCostMicros() < Money::fromDollars(2.0);
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    public function lines(): array
    {
        $rate = $this->exactRate();
        $cost = $this->averageCostMicros();

        return [
            [
                'Dates matching the ground truth exactly',
                number_format($rate * 100, 1).'%',
                'above 85%',
                $rate > 0.85 ? 'pass' : 'fail',
            ],
            [
                'Critical dates missed entirely',
                (string) $this->criticalMissedCount(),
                'zero',
                $this->criticalMissedCount() === 0 ? 'pass' : 'fail',
            ],
            [
                'Dates missed entirely (all)',
                (string) $this->missedCount(),
                'as low as possible',
                $this->missedCount() === 0 ? 'pass' : 'fail',
            ],
            [
                'Average cost per contract',
                Money::words($cost),
                'under $2.00',
                $cost < Money::fromDollars(2.0) ? 'pass' : 'fail',
            ],
            [
                'Contracts that could not be read at all',
                (string) count($this->failures),
                'zero',
                $this->failures === [] ? 'pass' : 'fail',
            ],
            [
                'Identifiers redacted before sending',
                (string) $this->redactionsTotal,
                'informational',
                'pass',
            ],
        ];
    }

    /**
     * Every miss, named.
     *
     * A percentage tells somebody the harness failed; this tells them which
     * deadline, in which contract, so the prompt can be fixed rather than
     * argued about.
     *
     * @return list<string>
     */
    public function misses(): array
    {
        $lines = [];

        foreach ($this->rows as $row) {
            foreach ($row['criticalMissed'] as $label) {
                $lines[] = "{$row['case']->slug}: missed a CRITICAL date — {$label}";
            }

            foreach (array_diff($row['missed'], $row['criticalMissed']) as $label) {
                $lines[] = "{$row['case']->slug}: missed {$label}";
            }
        }

        foreach ($this->failures as $failure) {
            $lines[] = "{$failure['slug']}: could not be read — {$failure['reason']}";
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            /*
             * #118: *"record results against `prompt_version` so the history is
             * queryable."* It is the first thing a later reader needs, because
             * a scorecard with no prompt version is a number that cannot be
             * compared with anything.
             */
            'promptVersion' => $this->promptVersion,
            'ranAt' => now()->toIso8601String(),
            'cases' => count($this->rows),
            'exactRate' => round($this->exactRate(), 4),
            'criticalMissed' => $this->criticalMissedCount(),
            'missed' => $this->missedCount(),
            'averageCostMicros' => $this->averageCostMicros(),
            'failures' => $this->failures,
            'misses' => $this->misses(),
            'passes' => $this->passes(),
        ];
    }

    private function truthCount(): int
    {
        return array_sum(array_map(
            static fn (array $row): int => count($row['case']->dates),
            $this->rows,
        ));
    }

    private function sum(string $key): int
    {
        return array_sum(array_map(
            static fn (array $row): int => (int) $row[$key],
            $this->rows,
        ));
    }
}
