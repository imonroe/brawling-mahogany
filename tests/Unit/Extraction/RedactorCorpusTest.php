<?php

declare(strict_types=1);

use App\Support\Extraction\Redaction\Redactor;
use App\Support\Extraction\Scoring\CorpusCase;

/**
 * The redactor, measured against twenty contracts (#114 · #14).
 *
 * #114's definition of done asks for *"redaction quality measured against the
 * corpus"*, and — the half that is easy to skip — *"test the redactor against
 * the corpus for both false negatives **and** damage to extractable content."*
 * Both directions are here, and they are different tests because they fail
 * differently:
 *
 * - A **miss** puts a routing number in a third party's logs.
 * - An **over-match** deletes a date or a price before the model ever sees it,
 *   and #114 is blunt about the consequence: *"a redactor that masks a purchase
 *   price or a deadline has broken the feature."*
 *
 * ## This test found three real defects, and they are worth naming
 *
 * The first pass over the corpus caught all three declared identifiers and
 * quietly masked three things nobody declared:
 *
 * 1. `tin` — taxpayer identification number — was matched as a **substring**,
 *    so it fired inside `lighting`, `listing` and `existing`. Every Colorado
 *    contract has an inclusions clause, so a county schedule number beside the
 *    word *lighting* was being deleted as a government ID.
 * 2. A county **schedule number**, `6318-04-031-0310`, is thirteen digits and
 *    **passes Luhn** — defeating `Redactor`'s own argument that words are what
 *    tell a parcel number from a card.
 * 3. An *Account Number* caption claimed a number two lines below it, across a
 *    paragraph break.
 *
 * All three failed *closed* rather than open, which is why nothing leaked and
 * why nothing would have told anybody: a field simply arrived at the model
 * deleted. That is the specific reason this test exists rather than a handful
 * of hand-written strings — the strings would all have passed.
 *
 * ## And the fix for one of them broke the document it was written about
 *
 * Narrowing the label window from 48 to 32 characters fixed (3) and broke the
 * wire instruction in `0017`, where the caption is wrapped:
 * `Routing\n    Number  . . . . .  987654321` puts the word 34 characters back,
 * so a 32-character window began mid-word and matched nothing at all. The
 * window is wide again and the **paragraph break** does the narrowing, which is
 * the rule that actually describes what a caption reaches.
 *
 * ## Read `tests/Corpus/LIMITATIONS.md` before quoting any of this
 *
 * The corpus is synthetic. It is a floor, not a measurement.
 */
it('redacts every identifier the corpus declares', function (): void {
    $redactor = new Redactor;
    $misses = [];

    foreach (CorpusCase::load(base_path('tests/Corpus/contracts')) as $case) {
        $declared = corpus_declared_identifiers($case->slug);

        if ($declared === []) {
            continue;
        }

        $counts = $redactor->redact($case->text)->report->counts;

        foreach ($declared as $rule) {
            if (($counts[$rule] ?? 0) < 1) {
                $misses[] = "{$case->slug}: declared {$rule} was not redacted";
            }
        }
    }

    expect($misses)->toBe([]);
});

it('masks nothing the corpus did not declare', function (): void {
    /*
     * The direction that fails silently. Over-redaction never leaks anything,
     * so nothing reports it — the only symptom is a model reading a contract
     * with a hole in it and a date it cannot find.
     */
    $redactor = new Redactor;
    $surprises = [];

    foreach (CorpusCase::load(base_path('tests/Corpus/contracts')) as $case) {
        $declared = corpus_declared_identifiers($case->slug);
        $found = array_keys($redactor->redact($case->text)->report->counts);

        foreach (array_diff($found, $declared) as $rule) {
            $surprises[] = "{$case->slug}: masked an undeclared {$rule}";
        }
    }

    expect($surprises)->toBe([]);
});

it('leaves every deadline in the corpus readable', function (): void {
    /*
     * The assertion #114 is actually about. A date is checked in every form a
     * contract could have written it, because the corpus deliberately mixes
     * `03/28/2026` and `March 28, 2026` in one document — and a date only
     * counts as damaged if it was in the original and is not in what left.
     */
    $redactor = new Redactor;
    $damage = [];

    foreach (CorpusCase::load(base_path('tests/Corpus/contracts')) as $case) {
        $redacted = $redactor->redact($case->text)->text;

        foreach ($case->dates as $date) {
            $forms = corpus_date_forms($date['value']);

            $before = array_filter($forms, static fn (string $form): bool => str_contains($case->text, $form));
            $after = array_filter($forms, static fn (string $form): bool => str_contains($redacted, $form));

            if ($before !== [] && $after === []) {
                $damage[] = "{$case->slug}: redaction destroyed {$date['label']} ({$date['value']})";
            }
        }
    }

    expect($damage)->toBe([]);
});

it('leaves every dollar figure in the corpus readable', function (): void {
    /*
     * #114 names the purchase price specifically, and it is the figure most at
     * risk: strip the punctuation off `$685,000.00` and it is a run of digits
     * that a shape-based rule would take.
     */
    $redactor = new Redactor;
    $damage = [];

    foreach (CorpusCase::load(base_path('tests/Corpus/contracts')) as $case) {
        $redacted = $redactor->redact($case->text)->text;

        preg_match_all('/\$[\d,]+(?:\.\d{2})?/', $case->text, $found);

        foreach (array_unique($found[0]) as $amount) {
            if (! str_contains($redacted, $amount)) {
                $damage[] = "{$case->slug}: redaction destroyed the amount {$amount}";
            }
        }
    }

    expect($damage)->toBe([]);
});

it('is reading a corpus with something in it', function (): void {
    /*
     * The positive control, and every assertion above needs it: all four pass
     * on an empty corpus, so a moved directory or a renamed fixture would leave
     * four green tests measuring nothing.
     */
    $cases = CorpusCase::load(base_path('tests/Corpus/contracts'));

    $declared = array_merge(...array_map(
        static fn (CorpusCase $case): array => corpus_declared_identifiers($case->slug),
        $cases,
    ));

    expect(count($cases))->toBe(20)
        ->and(array_sum(array_map(static fn (CorpusCase $case): int => count($case->dates), $cases)))
        ->toBeGreaterThan(200)
        ->and($declared)->not->toBe([]);
});

/**
 * Which redaction rules a corpus case says it carries.
 *
 * Read straight off the ground truth rather than through `CorpusCase`, because
 * this is a fact about the *redactor's* fixtures and not part of what the
 * scoring harness needs — putting it on the value object would give every
 * caller of `CorpusCase` a field only this file reads.
 *
 * @return list<string>
 */
function corpus_declared_identifiers(string $slug): array
{
    $path = base_path("tests/Corpus/contracts/{$slug}.json");

    /** @var array<string, mixed> $truth */
    $truth = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    return array_values(array_filter($truth['identifiers'] ?? [], 'is_string'));
}

/**
 * Every way a Colorado contract writes one day.
 *
 * @return list<string>
 */
function corpus_date_forms(string $iso): array
{
    [$year, $month, $day] = array_map('intval', explode('-', $iso));
    $stamp = mktime(0, 0, 0, $month, $day, $year);

    return [
        $iso,
        sprintf('%02d/%02d/%d', $month, $day, $year),
        sprintf('%d/%d/%d', $month, $day, $year),
        date('F j, Y', (int) $stamp),
        date('M j, Y', (int) $stamp),
        date('j F Y', (int) $stamp),
    ];
}
