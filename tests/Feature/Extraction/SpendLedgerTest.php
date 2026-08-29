<?php

declare(strict_types=1);

use App\Models\Deal;
use App\Models\Extraction;
use App\Models\Team;
use App\Support\Extraction\SpendLedger;
use App\Support\Tenancy\TeamContext;

/**
 * What extraction has cost, and whether it may cost any more (#113 · PRD §14.3).
 *
 * PRD §14.3 is the reason this exists on day one rather than as a later
 * optimisation:
 *
 * > **Extraction cost grows with deal volume rather than team count, so a heavy
 * > user could be unprofitable at a flat price. Track cost per deal from day one
 * > of slice 5 and cap it.**
 *
 * ## A Feature test, not a Unit one
 *
 * `SpendLedger` is three aggregates over `extractions`, so it makes rows.
 * docs/Testing.md is explicit that the suite a test lives in is a claim about
 * what it needs — *"if it makes a row, it is a Feature test"* — and #103 shipped
 * exactly this mistake: a Unit test that built a team and passed locally against
 * a database another suite had migrated.
 *
 * ## The clock is moved on purpose, not pinned and left
 *
 * docs/Testing.md: *"a frozen clock hides a whole class of defect… for anything
 * that partitions time — a sweep with a high-water mark, a rolling window, a
 * rate ceiling — that is the one arrangement production never produces."* This
 * ledger partitions time by calendar month, so the cases that matter are the
 * ones where the spend and the question are on **different sides** of a
 * boundary. Every fixture here is created at a named instant and asked about at
 * a different one.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();

    $this->withTeam($this->team);

    $this->ledger = app(SpendLedger::class);

    /*
     * Small round numbers, so the arithmetic in each case is readable. The real
     * defaults are fifty and five hundred dollars in micros, which would make
     * every fixture below a wall of noughts and hide which digit was the point.
     */
    config([
        'extraction.caps.team_monthly_micros' => 1_000_000,
        'extraction.caps.platform_monthly_micros' => 10_000_000,
        'extraction.caps.warn_at_percent' => 80,
    ]);
});

/** A row that spent money, in the given team, at whatever the clock says now. */
function billedExtraction(Team $team, int $micros, ?Deal $deal = null): Extraction
{
    return app(TeamContext::class)->runFor($team, fn (): Extraction => Extraction::factory()
        ->complete()
        ->costing($micros)
        ->create(array_filter([
            'team_id' => $team->getKey(),
            'deal_id' => $deal?->getKey(),
        ])));
}

it('stops a team that has spent its month', function (): void {
    $this->travelTo('2026-09-10 12:00:00');

    billedExtraction($this->team, 600_000);

    // The control: two thirds spent is not a stop.
    expect($this->ledger->decide($this->team)->allowed)->toBeTrue();

    billedExtraction($this->team, 400_000);

    $decision = $this->ledger->decide($this->team);

    /*
     * #113: hitting the cap *"stops extraction and tells the user plainly — it
     * does not silently degrade"*. So the refusal carries a sentence, and the
     * sentence names the two things this person can actually do about it: ask an
     * owner, or wait for the month to turn over.
     */
    expect($decision->allowed)->toBeFalse()
        ->and($decision->reasonCode)->toBe('team_spend_cap_reached')
        ->and($decision->message)->toContain('Settings')
        ->and($decision->spentMicros)->toBe(1_000_000)
        ->and($decision->capMicros)->toBe(1_000_000)
        ->and($decision->percentUsed())->toBe(100);
});

it('says something different when it is the platform that has stopped', function (): void {
    /*
     * Two ceilings, and they fail differently. A team cap is something the
     * team's own administrator can raise; a platform cap is not — and
     * `SpendDecision` says so in as many words: *"telling somebody to 'contact
     * your administrator' about a limit their administrator cannot move is worse
     * than telling them nothing."*
     *
     * The spend is put in **another** team, which is the other half of what
     * makes this the platform ceiling rather than the team one: the asking team
     * has spent nothing at all.
     */
    $this->travelTo('2026-09-10 12:00:00');

    [$other] = $this->teamWithMember();

    billedExtraction($other, 10_000_000);

    $decision = $this->ledger->decide($this->team);

    expect($this->ledger->teamSpentThisMonth($this->team))->toBe(0)
        ->and($decision->allowed)->toBeFalse()
        ->and($decision->reasonCode)->toBe('platform_spend_cap_reached')
        ->and($decision->message)->not->toContain('Settings')
        ->and($decision->message)->toContain('Nothing has been lost');
});

it('lets a team carry its own ceiling', function (): void {
    $this->travelTo('2026-09-10 12:00:00');

    $this->team->forceFill(['extraction_monthly_cap_micros' => 250_000])->save();

    expect($this->ledger->capFor($this->team))->toBe(250_000);

    billedExtraction($this->team, 250_000);

    /*
     * Under the configured default, and stopped anyway. Without the override the
     * same spend is a quarter of the month's budget — which is what makes this
     * assertion about the column rather than about the number.
     */
    expect($this->ledger->decide($this->team)->allowed)->toBeFalse()
        ->and($this->ledger->decide($this->team)->capMicros)->toBe(250_000);
});

it('reads a null cap as the default now, not the default when the row was written', function (): void {
    /*
     * The migration's own argument, and it is the trap `teams
     * .approval_required_until` walked into running the other way: *"a column
     * defaulted to the config value at creation time would freeze last year's
     * number onto every team that existed then, and raising the platform default
     * would silently not raise theirs."*
     *
     * So the assertion is not that the column is null — it is that the answer
     * **moves** when the default moves, which is the only observable difference
     * between the two designs.
     */
    expect($this->team->extraction_monthly_cap_micros)->toBeNull()
        ->and($this->ledger->capFor($this->team))->toBe(1_000_000);

    config(['extraction.caps.team_monthly_micros' => 4_000_000]);

    expect($this->ledger->capFor($this->team->fresh()))->toBe(4_000_000);
});

it('counts a month in UTC, whatever the team’s own clock says', function (): void {
    /*
     * Every other date question in this product is asked in the **team's**
     * timezone and CLAUDE.md is emphatic about it, so this deviation needs
     * asserting rather than assuming. `SpendLedger` gives two reasons: a
     * platform-wide ceiling cannot roll over at thirty different instants and
     * still be a ceiling, and the two numbers sit side by side on the admin
     * health screen where a team month and a platform month covering different
     * days would make the smaller one occasionally exceed the larger.
     *
     * `TeamFactory` is `America/Denver`, so the six hours after midnight UTC are
     * where the two answers differ. The spend below is made at 23:00 UTC on the
     * 31st — *still August* in both — and asked about at 02:00 UTC on the 1st,
     * which is **20:00 on 31 August** for this team. A ledger keyed on the
     * team's month would count it; this one must not.
     */
    expect($this->team->timezone)->toBe('America/Denver');

    $this->travelTo('2026-08-31 23:00:00');

    billedExtraction($this->team, 900_000);

    expect($this->ledger->teamSpentThisMonth($this->team))->toBe(900_000);

    $this->travelTo('2026-09-01 02:00:00');

    expect($this->ledger->teamSpentThisMonth($this->team))->toBe(0)
        ->and($this->ledger->platformSpentThisMonth())->toBe(0)
        ->and($this->ledger->resetsAt()->toDateTimeString())->toBe('2026-10-01 00:00:00');
});

it('does not spend this month’s budget on last month’s reading', function (): void {
    /*
     * The case a frozen clock cannot see at all, and the one somebody actually
     * meets: a team that reached its ceiling on the 29th must be able to work on
     * the 1st. Both halves are asserted from the **same fixture**, moving only
     * the clock — a test that built a second fixture would be measuring the
     * fixture rather than the window.
     */
    $this->travelTo('2026-08-29 12:00:00');

    billedExtraction($this->team, 1_000_000);

    expect($this->ledger->decide($this->team)->allowed)->toBeFalse();

    $this->travelTo('2026-09-01 00:00:01');

    $decision = $this->ledger->decide($this->team);

    expect($decision->allowed)->toBeTrue()
        ->and($decision->spentMicros)->toBe(0)
        ->and($decision->shouldWarn)->toBeFalse();
});

it('warns before it stops', function (): void {
    /*
     * `config/extraction.php`: *"tell somebody before it stops, not when it
     * stops. Eighty per cent of a month's budget with a week to go is a
     * conversation; a hard stop on the twenty-eighth is an outage."*
     *
     * Asserted on both sides of the threshold, because a flag that is always
     * true and a flag that is always false both pass a one-sided check.
     */
    $this->travelTo('2026-09-10 12:00:00');

    billedExtraction($this->team, 790_000);

    expect($this->ledger->decide($this->team)->shouldWarn)->toBeFalse();

    billedExtraction($this->team, 10_000);

    $decision = $this->ledger->decide($this->team);

    expect($decision->allowed)->toBeTrue()
        ->and($decision->shouldWarn)->toBeTrue()
        ->and($decision->percentUsed())->toBe(80);
});

/*
 * There is deliberately no test here for a per-deal total on `SpendLedger`.
 *
 * One existed, against a `dealSpend()` method that review found had no caller
 * anywhere in the application — and PRD §12.3's *"under $2 per deal"* is
 * already produced by `App\Queries\ExtractionHistory`, which computes the
 * **distribution** across every deal in one statement rather than one deal at a
 * time. Two implementations of one figure is how the number on a screen and the
 * number in a report start to disagree, so the ledger keeps the two totals it
 * has a caller for (a team's month, and the platform's) and S68 owns this one.
 *
 * Recorded rather than silently dropped, because the metric is real and the
 * next person to want it should find out where it lives instead of adding a
 * third.
 */


it('does not count a row that never called anything', function (): void {
    /*
     * A `blocked` row is a refusal rather than a call: nothing was sent and
     * nothing was billed. It carries a zero cost by construction, so a ledger
     * that summed rows rather than money would make every cap lower each time it
     * fired — the ceiling closing behind the person who reached it.
     */
    $this->travelTo('2026-09-10 12:00:00');

    app(TeamContext::class)->runFor($this->team, fn () => Extraction::factory()
        ->blocked()
        ->create(['team_id' => $this->team->getKey()]));

    expect($this->ledger->teamSpentThisMonth($this->team))->toBe(0)
        ->and($this->ledger->decide($this->team)->allowed)->toBeTrue();
});
