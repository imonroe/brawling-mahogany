<?php

declare(strict_types=1);

use App\Enums\ExtractedFieldType;
use App\Support\Extraction\Proposal;
use App\Support\Extraction\Redaction\RedactionReport;
use App\Support\Extraction\Scoring\CorpusCase;
use App\Support\Extraction\Scoring\Scorecard;

/**
 * The harness catches a deliberate regression (#118's definition of done).
 *
 * #118 lists three things done means, and this is the third: *"A deliberate
 * prompt regression is caught by it."* A scoring harness nobody has ever seen
 * fail is a harness that might not be able to — the same argument CLAUDE.md
 * makes about a healthcheck: **prove it can go red before trusting that it is
 * green.**
 *
 * Everything here is pure. `Scorecard` takes proposals and ground truth and
 * returns numbers; no provider, no database, no money. The scoring *logic* is
 * therefore covered by ordinary tests even though the harness itself is a
 * command that costs a few dollars to run.
 */
function scorecardFixture(array $dates): CorpusCase
{
    /*
     * Built through the real loader over a temporary pair of files, rather than
     * through a constructor this class deliberately keeps private. A fixture
     * assembled a different way would be testing a shape the command cannot
     * produce.
     */
    $directory = sys_get_temp_dir().'/corpus-'.bin2hex(random_bytes(6));
    mkdir($directory);

    file_put_contents($directory.'/case.txt', 'CONTRACT TO BUY AND SELL REAL ESTATE');
    file_put_contents($directory.'/case.json', json_encode([
        'slug' => 'case',
        'description' => 'A fixture.',
        'traits' => ['native'],
        'dates' => $dates,
        'provisions' => [],
    ]));

    $cases = CorpusCase::load($directory);

    unlink($directory.'/case.txt');
    unlink($directory.'/case.json');
    rmdir($directory);

    return $cases[0];
}

function scorecardProposal(string $label, string $value): Proposal
{
    return new Proposal(
        type: ExtractedFieldType::KeyDate,
        label: $label,
        value: $value,
        confidence: 0.9,
        sourcePage: 3,
        sourceSnippet: 'a quote',
    );
}

it('passes a run that reads every date correctly', function (): void {
    $case = scorecardFixture([
        ['label' => 'Inspection Objection', 'value' => '2026-03-28', 'critical' => true],
        ['label' => 'Closing', 'value' => '2026-04-30', 'critical' => true],
    ]);

    $card = new Scorecard('anthropic', 'claude-sonnet-5', 'contract-2026-08-28');

    $card->record($case, [
        scorecardProposal('Inspection Objection Deadline', '2026-03-28'),
        scorecardProposal('Closing Date', '2026-04-30'),
    ], 40_000, RedactionReport::empty());

    expect($card->passes())->toBeTrue()
        ->and($card->exactRate())->toBe(1.0)
        ->and($card->criticalMissedCount())->toBe(0);
});

it('fails a run that misses one critical date, however good the rest is', function (): void {
    /*
     * The zero-tolerance metric, and the direction of the failure is the point.
     * This run reads nine dates out of ten perfectly — a 90% rate, comfortably
     * over PRD §12.3's 85% bar — and it still does not pass, because the one it
     * dropped was a critical one.
     *
     * #118: *"A model upgrade that regresses this does not ship, however much
     * better it looks elsewhere."*
     */
    $dates = [['label' => 'Inspection Objection', 'value' => '2026-03-28', 'critical' => true]];

    for ($i = 1; $i <= 9; $i++) {
        $dates[] = ['label' => "Ordinary {$i}", 'value' => '2026-04-0'.$i, 'critical' => false];
    }

    $case = corpusCase($dates);

    $card = new Scorecard('anthropic', 'claude-sonnet-5', 'contract-2026-08-28');

    $proposals = [];

    for ($i = 1; $i <= 9; $i++) {
        $proposals[] = scorecardProposal("Ordinary {$i}", '2026-04-0'.$i);
    }

    $card->record($case, $proposals, 40_000, RedactionReport::empty());

    expect($card->passes())->toBeFalse()
        ->and($card->criticalMissedCount())->toBe(1)
        ->and($card->exactRate())->toBeGreaterThan(0.85)
        ->and($card->misses())->toContain('case: missed a CRITICAL date — Inspection Objection');
});

it('fails a run that reads a date wrongly rather than missing it', function (): void {
    /*
     * A wrong day is not a miss and is not fine either. It counts as matched —
     * the model found the deadline — and not as exact, which is what drags the
     * confirmed-without-edit rate down. That distinction is the whole point of
     * the two numbers being separate.
     */
    $case = scorecardFixture([
        ['label' => 'Inspection Objection', 'value' => '2026-03-28', 'critical' => true],
        ['label' => 'Closing', 'value' => '2026-04-30', 'critical' => true],
    ]);

    $card = new Scorecard('anthropic', 'claude-sonnet-5', 'contract-2026-08-28');

    $card->record($case, [
        scorecardProposal('Inspection Objection Deadline', '2026-03-08'),
        scorecardProposal('Closing Date', '2026-04-30'),
    ], 40_000, RedactionReport::empty());

    expect($card->criticalMissedCount())->toBe(0)
        ->and($card->exactRate())->toBe(0.5)
        ->and($card->passes())->toBeFalse();
});

it('matches a deadline by the same rule the product does', function (): void {
    /*
     * `KeyDateNames::key()`, so *Inspection Objection Deadline* and *Inspection
     * objection* are one deadline here exactly as they are on S66. A scorer
     * with its own idea of when two names are the same would report misses the
     * product would not make.
     */
    $case = scorecardFixture([['label' => 'inspection objection', 'value' => '2026-03-28', 'critical' => true]]);

    $card = new Scorecard('anthropic', 'claude-sonnet-5', 'contract-2026-08-28');
    $card->record($case, [scorecardProposal('INSPECTION OBJECTION DEADLINE', '2026-03-28')], 40_000, RedactionReport::empty());

    expect($card->criticalMissedCount())->toBe(0)
        ->and($card->exactRate())->toBe(1.0);
});

it('fails a run that cost more than two dollars a contract', function (): void {
    $case = scorecardFixture([['label' => 'Closing', 'value' => '2026-04-30', 'critical' => true]]);

    $card = new Scorecard('anthropic', 'claude-sonnet-5', 'contract-2026-08-28');
    $card->record($case, [scorecardProposal('Closing Date', '2026-04-30')], 2_500_000, RedactionReport::empty());

    expect($card->criticalMissedCount())->toBe(0)
        ->and($card->passes())->toBeFalse();
});

it('fails a run where the provider could not read a contract at all', function (): void {
    /*
     * Nineteen of twenty erroring and the twentieth scoring perfectly must not
     * read as a perfect run. Without this the rate is computed over the cases
     * that worked, which is the most flattering possible denominator.
     */
    $good = scorecardFixture([['label' => 'Closing', 'value' => '2026-04-30', 'critical' => true]]);
    $bad = scorecardFixture([['label' => 'Closing', 'value' => '2026-04-30', 'critical' => true]]);

    $card = new Scorecard('anthropic', 'claude-sonnet-5', 'contract-2026-08-28');
    $card->record($good, [scorecardProposal('Closing Date', '2026-04-30')], 40_000, RedactionReport::empty());
    $card->recordFailure($bad, 'provider_response_unreadable');

    expect($card->exactRate())->toBe(1.0)
        ->and($card->passes())->toBeFalse()
        ->and($card->misses())->toContain('case: could not be read — provider_response_unreadable');
});

it('records the prompt version so two runs can be compared', function (): void {
    $case = scorecardFixture([['label' => 'Closing', 'value' => '2026-04-30', 'critical' => true]]);

    $card = new Scorecard('anthropic', 'claude-sonnet-5', 'contract-2026-08-28');
    $card->record($case, [scorecardProposal('Closing Date', '2026-04-30')], 40_000, RedactionReport::empty());

    $written = $card->toArray();

    expect($written['promptVersion'])->toBe('contract-2026-08-28')
        ->and($written['model'])->toBe('claude-sonnet-5')
        ->and($written)->toHaveKeys(['exactRate', 'criticalMissed', 'averageCostMicros', 'passes']);
});

it('refuses a corpus case whose document is missing', function (): void {
    /*
     * Loudly, rather than scoring the cases that happen to be complete — a
     * corpus quietly missing a case reports a *better* number than the real
     * one, and that is the direction that matters when the metric is misses.
     */
    $directory = sys_get_temp_dir().'/corpus-'.bin2hex(random_bytes(6));
    mkdir($directory);
    file_put_contents($directory.'/orphan.json', json_encode(['dates' => []]));

    expect(fn (): array => CorpusCase::load($directory))->toThrow(RuntimeException::class);

    unlink($directory.'/orphan.json');
    rmdir($directory);
});
