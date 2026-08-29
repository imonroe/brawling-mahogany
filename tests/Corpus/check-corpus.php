<?php

declare(strict_types=1);

/**
 * Validate the #14 contract corpus, and write the manifest the harness reads.
 *
 *     php tests/Corpus/check-corpus.php            validate, exit 1 on failure
 *     php tests/Corpus/check-corpus.php --write    rewrite index.json, then validate
 *
 * ## Why this is a script and not a Pest test
 *
 * It has to run in an environment where `vendor/` is not installed, because
 * the corpus is edited by hand — a ground truth nobody can correct by hand is
 * not a hand-checked ground truth, which is the whole claim #14 makes about
 * it. So this is plain PHP with no framework, and it is the thing you run
 * after editing a fixture. #118's harness will call the same invariants from
 * inside the suite once it exists; until then this is the guard.
 *
 * ## Why the corpus is not generated
 *
 * The text of each contract was written, not templated, and this script
 * deliberately cannot produce one. A generator that owns the corpus is a
 * generator that cannot represent the twenty real contracts PRD §14.1 Q5 asks
 * for, and #14 does not close until those are in here beside the synthetic
 * ones. What is generated is the manifest — `contracts/index.json` — because a
 * manifest that can disagree with the files it lists is a manifest nobody
 * trusts, and a missing fixture should be a diff rather than a silent shortfall
 * in a score.
 *
 * ## What it refuses
 *
 * The checks below are the ones where being wrong is invisible: a critical
 * date silently not marked critical, a label spelled two ways across two
 * fixtures (which makes them un-comparable rather than failing), a manifest
 * that has drifted, an `identifiers` key naming a rule the redactor does not
 * have. Each of those produces a plausible number rather than an error, and a
 * plausible number is exactly what LIMITATIONS.md says not to trust.
 */
const CORPUS_DIR = __DIR__.'/contracts';

/**
 * The five deadlines PRD §12.3 gives zero tolerance on missing.
 *
 * The list lives in **§12.3's *Which dates are critical* table**, which now
 * exists — for a round it did not, and this comment credited the set to *"the
 * PRD table it comes from"* over a §12.3 that had one row naming one example.
 * `extraction:score` exits non-zero on a miss, so the set is a release gate,
 * and a gate whose definition is invented by the thing being gated and then
 * attributed upstream is worse than an undocumented one: the attribution is
 * what stops anybody checking. Found in review; the table and a Decision Log
 * row (2026-08-29) are what settled it.
 *
 * These are the labels this corpus spells those five concepts with, and the
 * mapping is argued in README.md §Canonical labels. A sixth changes the PRD
 * first, then this constant — in that order, because the corpus measures
 * against the list rather than defining it.
 */
const CRITICAL_LABELS = [
    'Record Title Objection Deadline',
    'New Loan Terms Deadline',
    'Appraisal Deadline',
    'Inspection Objection Deadline',
    'Closing Date',
];

/**
 * Every label the corpus is allowed to use.
 *
 * A closed list, because the failure it prevents is silent: two fixtures
 * spelling one deadline two ways still score, and score against different
 * things. Adding a deadline the CTM form has and this list does not is a
 * two-line change here plus a row in README.md — deliberately not free.
 */
const CANONICAL_LABELS = [
    'Mutual Acceptance',
    'Alternative Earnest Money Deadline',
    'Due Diligence Documents Delivery Deadline',
    'Record Title Deadline',
    'Off-Record Title Deadline',
    'Record Title Objection Deadline',
    'Title Resolution Deadline',
    'New ILC or New Survey Deadline',
    'New ILC or New Survey Objection Deadline',
    'New Loan Application Deadline',
    'New Loan Terms Deadline',
    'New Loan Availability Deadline',
    'Appraisal Deadline',
    'Appraisal Resolution Deadline',
    'Inspection Objection Deadline',
    'Inspection Termination Deadline',
    'Inspection Resolution Deadline',
    'Property Insurance Objection Deadline',
    'Security Deposit Deadline',
    'Lease Commencement Date',
    'Move-In Inspection Deadline',
    'Lease Expiration Date',
    'Closing Date',
    'Possession Date',
];

/** The rule keys `App\Support\Extraction\Redaction\Redactor` actually has. */
const REDACTION_RULES = ['routing_number', 'account_number', 'government_id', 'social_security_number', 'card_number'];

/**
 * How many fixtures carry a financial identifier, exactly.
 *
 * Fixed rather than minimum. #114's tests want to assert a count of redactions
 * over the whole corpus, and a count that grows whenever somebody adds a
 * fixture is a count that gets updated to match rather than read.
 */
const IDENTIFIER_FIXTURES = 3;

/** Below this a fixture is a stub, and a stub is easier to read than a contract. */
const MIN_WORDS = 300;

/** Traits that must appear exactly one of, per fixture. */
const TRAIT_GROUPS = [
    ['native', 'scanned'],
    ['complete', 'sparse'],
];

$write = in_array('--write', array_slice($argv, 1), true);

/** @var list<string> $problems */
$problems = [];
/** @var list<string> $notes */
$notes = [];

$fail = static function (string $slug, string $message) use (&$problems): void {
    $problems[] = "{$slug}: {$message}";
};

$groundTruthFiles = glob(CORPUS_DIR.'/*.json');
sort($groundTruthFiles);
$groundTruthFiles = array_values(array_filter(
    $groundTruthFiles,
    static fn (string $path): bool => basename($path) !== 'index.json',
));

if ($groundTruthFiles === []) {
    fwrite(STDERR, 'No ground-truth files found in '.CORPUS_DIR."\n");
    exit(1);
}

$manifest = [];
$identifierFixtures = 0;
$totalDates = 0;
$totalCritical = 0;
$traitCounts = [];

foreach ($groundTruthFiles as $path) {
    $slug = basename($path, '.json');
    $raw = file_get_contents($path);
    $truth = json_decode((string) $raw, true);

    if (! is_array($truth)) {
        $fail($slug, 'ground truth is not valid JSON');

        continue;
    }

    foreach (['slug', 'description', 'traits', 'dates', 'provisions'] as $key) {
        if (! array_key_exists($key, $truth)) {
            $fail($slug, "ground truth has no \"{$key}\" key");
        }
    }

    if (($truth['slug'] ?? null) !== $slug) {
        $fail($slug, 'the "slug" key does not match the file name');
    }

    $textPath = CORPUS_DIR."/{$slug}.txt";
    if (! is_file($textPath)) {
        $fail($slug, 'has no matching .txt — every ground truth needs the text it was read from');

        continue;
    }

    $text = (string) file_get_contents($textPath);
    $words = str_word_count(strip_tags($text));
    if ($words < MIN_WORDS) {
        $fail($slug, "the text is {$words} words, under the ".MIN_WORDS.'-word floor');
    }

    // Traits.
    $traits = $truth['traits'] ?? [];
    if (! is_array($traits) || $traits === []) {
        $fail($slug, 'has no traits');
        $traits = [];
    }
    foreach (TRAIT_GROUPS as $group) {
        $present = array_values(array_intersect($group, $traits));
        if (count($present) !== 1) {
            $fail($slug, 'must carry exactly one of '.implode('/', $group).', carries '.(count($present) ?: 'none'));
        }
    }
    foreach ($traits as $trait) {
        $traitCounts[$trait] = ($traitCounts[$trait] ?? 0) + 1;
    }

    // Identifiers. The trait and the key are two halves of one claim, so
    // either without the other is a fixture #114 will read wrongly.
    $hasIdentifierTrait = in_array('has-identifiers', $traits, true);
    $identifiers = $truth['identifiers'] ?? null;
    if ($hasIdentifierTrait !== ($identifiers !== null)) {
        $fail($slug, 'the has-identifiers trait and the "identifiers" key must appear together or not at all');
    }
    if (is_array($identifiers)) {
        $identifierFixtures++;
        if ($identifiers === []) {
            $fail($slug, 'the "identifiers" key is empty');
        }
        foreach ($identifiers as $rule) {
            if (! in_array($rule, REDACTION_RULES, true)) {
                $fail($slug, "names redaction rule \"{$rule}\", which Redactor does not have");
            }
        }
    }

    // Dates.
    $dates = $truth['dates'] ?? [];
    if (! is_array($dates) || $dates === []) {
        $fail($slug, 'has no dates');
        $dates = [];
    }

    $seenLabels = [];
    $isoByLabel = [];
    foreach ($dates as $index => $date) {
        $label = $date['label'] ?? null;
        $value = $date['value'] ?? null;

        if (! is_string($label) || ! in_array($label, CANONICAL_LABELS, true)) {
            $fail($slug, 'date '.$index.' uses label "'.(is_string($label) ? $label : '?').'", which is not in the canonical list');

            continue;
        }
        if (isset($seenLabels[$label])) {
            $fail($slug, "records \"{$label}\" twice — one deadline, one row");
        }
        $seenLabels[$label] = true;

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            $fail($slug, "\"{$label}\" has a value that is not an ISO date");

            continue;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));
        if (! checkdate($m, $d, $y)) {
            $fail($slug, "\"{$label}\" is {$value}, which is not a day that exists");

            continue;
        }
        $isoByLabel[$label] = $value;

        if (! array_key_exists('critical', $date) || ! is_bool($date['critical'])) {
            $fail($slug, "\"{$label}\" has no boolean \"critical\" flag");

            continue;
        }
        $shouldBeCritical = in_array($label, CRITICAL_LABELS, true);
        if ($date['critical'] !== $shouldBeCritical) {
            $fail($slug, "\"{$label}\" is marked ".($date['critical'] ? 'critical' : 'not critical').' and PRD §12.3 says otherwise');
        }
    }

    // Ordering. Mutual Acceptance is the floor and Closing is the ceiling;
    // an off-by-one in an offset shows up here and nowhere else.
    $acceptance = $isoByLabel['Mutual Acceptance'] ?? null;
    if ($acceptance === null) {
        $fail($slug, 'records no Mutual Acceptance date, so no offset in the text can be resolved against it');
    } else {
        foreach ($isoByLabel as $label => $iso) {
            if ($iso < $acceptance) {
                $fail($slug, "\"{$label}\" ({$iso}) falls before Mutual Acceptance ({$acceptance})");
            }
        }
    }
    $closing = $isoByLabel['Closing Date'] ?? null;
    if ($closing !== null) {
        foreach ($isoByLabel as $label => $iso) {
            if (in_array($label, ['Possession Date', 'Lease Expiration Date'], true)) {
                continue;
            }
            if ($iso > $closing) {
                $fail($slug, "\"{$label}\" ({$iso}) falls after Closing ({$closing})");
            }
        }
    }

    // Does the text actually say what the ground truth claims?
    //
    // Only asserted for the critical dates, and only where the fixture does
    // not declare that its dates are offsets — `derived-only` means the
    // calendar day is deliberately absent from the page, which is the thing
    // that fixture exists to test. Everything else is reported, not refused:
    // a legitimately restated or amended date can be present in a rendering
    // this list does not know, and a warning that is sometimes wrong is worth
    // more than a rule that is sometimes wrong.
    $derivedOnly = in_array('derived-only', $traits, true);
    $literal = 0;
    foreach ($isoByLabel as $label => $iso) {
        $found = textStates($text, $iso);
        $literal += $found ? 1 : 0;
        if (! $found && ! $derivedOnly && in_array($label, CRITICAL_LABELS, true)) {
            $fail($slug, "\"{$label}\" is {$iso} and no rendering of that day appears in the text");
        }
    }
    if ($isoByLabel !== []) {
        $notes[] = sprintf(
            '%-42s %2d/%2d dates written on the page%s',
            $slug,
            $literal,
            count($isoByLabel),
            $derivedOnly ? '  (derived-only)' : '',
        );
    }

    /*
     * An amended fixture has to prove it is the hard case it claims to be.
     *
     * `textStates()` only asks whether the ISO day appears *somewhere* in the
     * text, and an Amend/Extend states **both** days — the superseded one in
     * the From column and the amendment in the To column. So a ground truth
     * recording `2026-04-21` for `0008`'s Closing Date passed every check in
     * this file exactly as `2026-05-05` does, and the amendment is the only
     * reason that fixture exists. Round 2 of review found the guard blind to
     * the one thing the fixture is for.
     *
     * `superseded` is what closes it, and it is asserted in both directions:
     * the old day must be **in the text** (or this is not an amendment, it is
     * a typo in the manifest), and it must **not be** what `dates` records for
     * that label (or the ground truth is the superseded reading).
     */
    $superseded = $truth['superseded'] ?? [];

    if (in_array('amended', $traits, true) && $superseded === []) {
        $fail($slug, 'is tagged `amended` and records no `superseded` dates — the superseded reading is what makes the fixture hard, so it has to be written down');
    }

    if (! is_array($superseded)) {
        $fail($slug, 'has a `superseded` key that is not a list');
        $superseded = [];
    }

    foreach ($superseded as $index => $old) {
        if (! is_array($old) || ! isset($old['label'], $old['value'])) {
            $fail($slug, "superseded[{$index}] needs a label and a value");

            continue;
        }

        $label = (string) $old['label'];
        $value = (string) $old['value'];

        if (! isset($isoByLabel[$label])) {
            $fail($slug, "superseded \"{$label}\" is not a label this fixture records at all");

            continue;
        }

        if ($isoByLabel[$label] === $value) {
            $fail($slug, "\"{$label}\" records {$value}, which is the day the amendment superseded");

            continue;
        }

        if (! textStates($text, $value)) {
            $fail($slug, "superseded \"{$label}\" is {$value} and that day is nowhere in the text — an amendment states the day it replaces");
        }
    }

    // Provisions.
    $provisions = $truth['provisions'] ?? [];
    if (! is_array($provisions) || $provisions === []) {
        $fail($slug, 'records no provisions — F10.1 captures those as notes, so an empty list is a claim the document has none');
    }

    $criticalCount = count(array_filter(
        $dates,
        static fn (array $date): bool => ($date['critical'] ?? false) === true,
    ));

    $totalDates += count($dates);
    $totalCritical += $criticalCount;

    $manifest[] = [
        'slug' => $slug,
        'traits' => array_values($traits),
        'dateCount' => count($dates),
        'criticalCount' => $criticalCount,
    ];
}

// Every .txt needs its ground truth, not only the other way round.
foreach (glob(CORPUS_DIR.'/*.txt') as $textPath) {
    $slug = basename($textPath, '.txt');
    if (! is_file(CORPUS_DIR."/{$slug}.json")) {
        $problems[] = "{$slug}: has a .txt with no ground truth beside it";
    }
}

if ($identifierFixtures !== IDENTIFIER_FIXTURES) {
    $problems[] = sprintf(
        'the corpus carries %d fixtures with identifiers and #114 expects exactly %d',
        $identifierFixtures,
        IDENTIFIER_FIXTURES,
    );
}

$index = [
    'contracts' => $manifest,
    'totals' => [
        'contracts' => count($manifest),
        'dates' => $totalDates,
        'criticalDates' => $totalCritical,
    ],
];

$indexPath = CORPUS_DIR.'/index.json';
$rendered = json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

if ($write) {
    file_put_contents($indexPath, $rendered);
    echo 'Wrote '.count($manifest)." contracts to contracts/index.json\n";
} elseif (! is_file($indexPath)) {
    $problems[] = 'contracts/index.json does not exist — run with --write';
} elseif (file_get_contents($indexPath) !== $rendered) {
    $problems[] = 'contracts/index.json disagrees with the ground truth on disk — run with --write';
}

foreach ($notes as $note) {
    echo "  {$note}\n";
}

ksort($traitCounts);
echo "\nTraits: ";
echo implode(', ', array_map(
    static fn (string $trait, int $count): string => "{$trait}={$count}",
    array_keys($traitCounts),
    $traitCounts,
));
echo sprintf(
    "\nTotals: %d contracts, %d dates, %d critical dates\n",
    count($manifest),
    $totalDates,
    $totalCritical,
);

if ($problems !== []) {
    echo "\n".count($problems)." problem(s):\n";
    foreach ($problems as $problem) {
        echo "  - {$problem}\n";
    }
    exit(1);
}

echo "\nCorpus is consistent.\n";
exit(0);

/**
 * Does the text write this day in any rendering a Colorado contract uses?
 *
 * Deliberately generous. A false "yes" here weakens a warning; a false "no"
 * fails a build over a fixture that is correct, which is how a check gets
 * deleted rather than fixed.
 */
function textStates(string $text, string $iso): bool
{
    $day = new DateTimeImmutable($iso);

    foreach (['Y-m-d', 'm/d/Y', 'n/j/Y', 'n/j/y', 'm/d/y', 'F j, Y', 'M j, Y', 'j F Y'] as $format) {
        if (str_contains($text, $day->format($format))) {
            return true;
        }
    }

    return false;
}
