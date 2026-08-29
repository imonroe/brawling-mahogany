<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Support\Extraction\SpendLedger;

/**
 * The door onto a team's own extraction ceiling (#113).
 *
 * `teams.extraction_monthly_cap_micros` shipped with a reader, a CHECK
 * constraint and tests, and **nothing anywhere able to write it** — while the
 * refusal a team met on hitting the ceiling told them an owner could raise it
 * in Settings. CLAUDE.md records the shape twice already (`teams.logo_path`;
 * F5.9's kill switch), which is why the fix is a writer rather than softer
 * words: a rule nobody can pull is not a rule.
 *
 * It is a console command rather than a screen on purpose, and that is the
 * substance rather than the packaging: `SpendLedger` calls this a *commercial
 * limit*, so a team able to raise its own is not limited. These cases hold the
 * writer, the audit entry, and the refusals — not the console formatting.
 */
beforeEach(function (): void {
    $this->travelTo('2026-09-15 12:00:00');

    [$this->team] = $this->teamWithMember();

    config(['extraction.caps.team_monthly_micros' => 50_000_000]);
});

it('sets a ceiling in dollars and stores it in micros', function (): void {
    /*
     * Dollars in, micros stored, and the conversion asserted rather than
     * assumed: the column is micros because `extractions.cost_micros` is, and
     * a cap written in cents against a spend in micros is a factor of ten
     * thousand — in the direction where the cap never fires.
     */
    $this->artisan('extraction:cap', [
        'team' => $this->team->slug,
        '--dollars' => '12.50',
    ])->assertSuccessful();

    expect($this->team->fresh()?->extraction_monthly_cap_micros)->toBe(12_500_000)
        ->and(app(SpendLedger::class)->capFor($this->team->fresh()))->toBe(12_500_000);
});

it('accepts zero, which is the case the column exists for', function (): void {
    /*
     * The migration's own words: this column is what an operator sets *"for the
     * one team that needs stopping now"*. A command that treated `0` as "no
     * value given" would refuse the only urgent thing anybody ever types into
     * it — and `--dollars=0` is a genuinely present option whose value is
     * falsy, which is exactly how that bug gets written.
     */
    $this->artisan('extraction:cap', [
        'team' => $this->team->slug,
        '--dollars' => '0',
    ])->assertSuccessful();

    expect($this->team->fresh()?->extraction_monthly_cap_micros)->toBe(0);
});

it('clears the override, so the team follows the configured default again', function (): void {
    $this->team->forceFill(['extraction_monthly_cap_micros' => 1_000_000])->save();

    $this->artisan('extraction:cap', [
        'team' => $this->team->slug,
        '--clear' => true,
    ])->assertSuccessful();

    expect($this->team->fresh()?->extraction_monthly_cap_micros)->toBeNull()
        ->and(app(SpendLedger::class)->capFor($this->team->fresh()))->toBe(50_000_000);
});

it('reports without writing when neither option is given', function (): void {
    /*
     * A bare invocation is a read. The trap it avoids is `mail:suppression`'s,
     * recorded there after four review rounds: a command whose read path
     * silently swallowed the verb and still exited zero.
     */
    $this->team->forceFill(['extraction_monthly_cap_micros' => 1_000_000])->save();

    $this->artisan('extraction:cap', ['team' => $this->team->slug])->assertSuccessful();

    expect($this->team->fresh()?->extraction_monthly_cap_micros)->toBe(1_000_000);
});

it('refuses both options at once rather than picking one', function (): void {
    $this->team->forceFill(['extraction_monthly_cap_micros' => 1_000_000])->save();

    $this->artisan('extraction:cap', [
        'team' => $this->team->slug,
        '--dollars' => '5',
        '--clear' => true,
    ])->assertFailed();

    expect($this->team->fresh()?->extraction_monthly_cap_micros)->toBe(1_000_000);
});

it('refuses a negative ceiling, which would mean the opposite of a ceiling', function (): void {
    /*
     * A negative cap is how `SpendLedger` spells *"no ceiling at all"*, so a
     * mistyped `-50` would remove the limit on the team somebody was trying to
     * limit. The CHECK on `teams` refuses it at the database; this refuses it
     * with a sentence, before the constraint has to.
     */
    $this->artisan('extraction:cap', [
        'team' => $this->team->slug,
        '--dollars' => '-50',
    ])->assertFailed();

    expect($this->team->fresh()?->extraction_monthly_cap_micros)->toBeNull();
});

it('says so when no team matches, rather than doing nothing quietly', function (): void {
    $this->artisan('extraction:cap', [
        'team' => 'no-such-team',
        '--dollars' => '5',
    ])->assertFailed();
});

it('writes an audit entry into the team the ceiling was put on', function (): void {
    /*
     * PRD §9 wants a team able to see what was done to it, and *"an operator
     * stopped your extraction"* is exactly what a later support conversation
     * turns on. The entry carries both sides, because the interesting question
     * afterwards is what it was before.
     */
    $this->artisan('extraction:cap', [
        'team' => $this->team->slug,
        '--dollars' => '7',
    ])->assertSuccessful();

    $entry = AuditEntry::query()
        ->where('action', 'extraction.cap_changed')
        ->sole();

    expect($entry->team_id)->toBe($this->team->getKey())
        ->and($entry->after['extraction_monthly_cap_micros'])->toBe(7_000_000)
        ->and($entry->before['extraction_monthly_cap_micros'])->toBeNull();
});
