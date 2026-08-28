<?php

declare(strict_types=1);

use App\Support\Extraction\Contracts\ExtractionPrompt;
use App\Support\Extraction\PromptRegistry;

/**
 * Editing a prompt means bumping its version (#118 · F10.4).
 *
 * ## Why this test is a nuisance on purpose
 *
 * It fails on **any** edit to a prompt's text, including a typo fix. That is
 * the design, and the reason is what `extractions.prompt_version` is for.
 *
 * #118 turns that column into the axis its regression harness reports against:
 * *"record results against `prompt_version` so the history is queryable"*, and
 * *"re-run it on every prompt version change and every model version change."*
 * A scorecard from last week and one from today are only comparable if the
 * version says whether the prompt moved between them. A prompt improved
 * without a version bump makes every stored score a comparison between two
 * different questions, silently, forever — and the shape of that failure is
 * that the numbers still look fine.
 *
 * So: the hash below is not a claim that the words are right. It is a tripwire
 * that says *you changed the prompt; go and change the version, then update
 * this hash.* Two lines of work, and it is meant to be two lines rather than
 * zero.
 *
 * ## Why hashing rather than comparing to a fixture
 *
 * A fixture holding a copy of the prompt would be a second copy of the words,
 * and CLAUDE.md records what happens to those: `calendarNavigation.test.ts`
 * held a copy of the component's arithmetic and *"stayed green with the fix
 * deleted."* A hash cannot drift, because there is nothing in it to drift.
 */
$hashes = [
    'contract-2026-08-28' => '8a43f84e00a14e19ef857993403cd18fe2e2a4988ebe528110f9ea48f63a0332',
    'inspection-2026-08-28' => 'ed69adabe219a7759c6208fe75360bc5e68091aa16df313c2fed23572856781b',
];

it('has a recorded hash for every prompt', function () use ($hashes): void {
    $versions = array_map(
        static fn (ExtractionPrompt $prompt): string => $prompt->version(),
        (new PromptRegistry)->all(),
    );

    sort($versions);
    $recorded = array_keys($hashes);
    sort($recorded);

    expect($versions)->toBe($recorded);
});

it('bumps the version when the words change', function () use ($hashes): void {
    foreach ((new PromptRegistry)->all() as $prompt) {
        /*
         * Both halves, and a fixed document body so the hash is over the
         * instruction rather than over whatever was passed in. The body is a
         * constant here for the same reason `temperature` is zero in the
         * provider: a hash that moved with its input would catch nothing.
         */
        $hash = hash('sha256', $prompt->system()."\n---\n".$prompt->instructions('CORPUS BODY'));

        expect($hash)->toBe(
            $hashes[$prompt->version()],
            "The {$prompt->version()} prompt changed. Bump its version() and record the new hash here: {$hash}",
        );
    }
});

it('gives every kind a prompt', function (): void {
    /*
     * A kind added without a prompt is a `match` that throws inside a queue
     * worker, which surfaces as a failed extraction with a stack trace where a
     * sentence should be.
     */
    foreach (\App\Enums\ExtractionKind::cases() as $kind) {
        $prompt = (new PromptRegistry)->for($kind);

        expect($prompt->kind())->toBe($kind)
            ->and($prompt->version())->not->toBe('')
            ->and($prompt->system())->not->toBe('')
            ->and($prompt->instructions('x'))->toContain('x');
    }
});
