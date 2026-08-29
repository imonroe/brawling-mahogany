<?php

declare(strict_types=1);

use App\Support\Extraction\ConfirmExtractedField;
use Tests\Support\Sources;

/**
 * Nothing but a confirmation puts extracted output into the record (#115, #116).
 *
 * ## The claim this holds, stated exactly
 *
 * PRD §6.2, and it is the sentence the whole slice turns on:
 *
 * > **Nothing reaches `key_dates` or `tasks` except through a confirmed row
 * > here.**
 *
 * §7.16 gives the reason: model output cannot be written straight into a
 * contingency calendar, because a missed inspection deadline is a legal
 * problem and *"an unreviewed model output must never cause one"* (F10.2).
 *
 * `SaveKeyDate` and `DealTasks` each grew a second entry point for this slice —
 * `addConfirmedExtraction()` — and this guard is what makes those entry points
 * a **door** rather than a convenience. There is exactly one caller of each,
 * and the day there are two is the day the invariant stops being enforced by
 * anything.
 *
 * ## Why a source-reading test rather than a runtime one
 *
 * `SingleMutationPathTest`'s argument, unchanged: a runtime test catches the
 * path it thought to exercise, and the path that breaks an invariant is the
 * one nobody thought about. A feature added next slice that called
 * `addConfirmedExtraction()` from its own importer would pass every
 * behavioural test in the suite, because it *works* — that is the failure mode.
 *
 * ## And the candidate filter is half the guard
 *
 * CLAUDE.md records what `SingleMutationPathTest` cost to learn: it missed
 * `action_instances.state` for a whole slice because its file filter only
 * opened files that mentioned `Stage`/`Workflow`/`Gate`, and `ExecuteAction`
 * mentions none of them. So this one has **no filter at all** — every `.php`
 * file under `app/` is read. The pattern is specific enough that scanning
 * everything is cheap, and a filter is a thing that can be wrong in a
 * direction nothing reports.
 */
$sanctioned = ['Support/Extraction/ConfirmExtractedField.php'];

/** The two files that *define* the entry points. Defining is not calling. */
$writers = ['Support/Dates/SaveKeyDate.php', 'Support/Deals/DealTasks.php'];

it('has exactly one caller of each extracted entry point', function () use ($sanctioned, $writers): void {
    $callers = [];

    foreach (Sources::files(['app'], ['php']) as $relative) {
        if (in_array($relative, $writers, true)) {
            continue;
        }

        $contents = (string) file_get_contents(app_path($relative));

        if (str_contains($contents, 'addConfirmedExtraction')) {
            $callers[] = $relative;
        }
    }

    sort($callers);

    expect($callers)->toBe($sanctioned);
});

it('names sanctioned paths that exist', function () use ($sanctioned, $writers): void {
    /*
     * The failure this catches is a rename. A guard whose allowlist points at a
     * file that no longer exists passes trivially and forever, and nothing says
     * so — the same trap `SingleMutationPathTest` guards itself against.
     */
    foreach ([...$sanctioned, ...$writers] as $path) {
        expect(is_file(app_path($path)))->toBeTrue("Sanctioned path is missing: {$path}");
    }

    expect(class_exists(ConfirmExtractedField::class))->toBeTrue();
});

it('keeps the extracted source enums out of every other writer', function (): void {
    /*
     * The other direction, and the cheaper mistake: an ordinary `add()` that
     * quietly started stamping `KeyDateSource::Extracted` would put a model's
     * output in `key_dates` through a door with no confirmation behind it and
     * no `confirmed_by` to show for it.
     *
     * The two writers and the enums themselves are exempt because they are
     * where the case legitimately appears.
     */
    $allowed = [
        'Enums/KeyDateSource.php',
        'Enums/TaskSource.php',
        /*
         * `KeyDate::isPending()` reads the case rather than writing it, and
         * reading is the point: it is the predicate six screens use to keep an
         * unconfirmed extracted date out of a deadline count. A guard that
         * refused it would be refusing the invariant's own reader.
         */
        'Models/KeyDate.php',
        'Support/Dates/SaveKeyDate.php',
        'Support/Deals/DealTasks.php',
        /*
         * The confirmation path itself, named as **one file** rather than as
         * the directory it lives in.
         *
         * This read `str_starts_with($relative, 'Support/Extraction/')` for a
         * round, which exempted thirty-odd files from a rule that needs one —
         * `PerformExtraction`, `ReadProposals`, `StartExtraction` and every
         * provider could have stamped `KeyDateSource::Extracted` straight into
         * `key_dates` with the guard silent. The candidate filter is half of
         * any source-reading guard (CLAUDE.md records `SingleMutationPathTest`
         * missing `action_instances.state` the same way), and a filter wide in
         * the direction nothing reports is the half that fails quietly.
         *
         * As it happens the directory currently names the enum nowhere at all
         * — the confirmation goes through `SaveKeyDate` and `DealTasks` — so
         * this entry is a placeholder for the one door that may legitimately
         * grow it, and every other file in the directory is now covered.
         */
        'Support/Extraction/ConfirmExtractedField.php',
    ];

    $offenders = [];

    foreach (Sources::files(['app'], ['php']) as $relative) {
        if (in_array($relative, $allowed, true)) {
            continue;
        }

        $contents = (string) file_get_contents(app_path($relative));

        if (preg_match('/(KeyDateSource|TaskSource)::Extracted/', $contents) === 1) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([]);
});

it('can tell a violation from a clean tree', function (): void {
    /*
     * The positive control, and it is not decoration. Every assertion above
     * passes on an empty result set, so a filter that quietly stopped matching
     * — a renamed method, a changed extension list, a `Sources::files()`
     * signature that shifted under it — would leave three green tests
     * asserting nothing at all.
     *
     * This proves the machinery reads real files, and that the file the guard
     * is written about is one of them.
     */
    $files = Sources::files(['app'], ['php']);

    expect(count($files))->toBeGreaterThan(100)
        ->and($files)->toContain('Support/Extraction/ConfirmExtractedField.php')
        ->and((string) file_get_contents(app_path('Support/Extraction/ConfirmExtractedField.php')))
        ->toContain('addConfirmedExtraction');
});
