<?php

declare(strict_types=1);

use App\Enums\ExtractedFieldReviewState;
use App\Models\Deal;
use App\Models\ExtractedField;
use App\Models\Extraction;
use App\Models\TeamMembership;
use App\Support\Tenancy\TeamContext;
use Inertia\Testing\AssertableInertia;

/**
 * S68 — extraction history (#118).
 *
 * Vitest holds what the page *renders*; this holds the two things it cannot
 * reach — who is allowed in, and whose rows are counted. `AuthorizationCoverageTest`
 * proves the action asks a question; it does not prove the answer is right.
 */
beforeEach(function (): void {
    /*
     * Pinned at midday, and the hour is load-bearing (#198): the spend month is
     * a **UTC** boundary and midday is the same calendar day in every timezone
     * this product supports. A date-only value would put a UTC-7 team in the
     * previous month for a third of every working day.
     */
    $this->travelTo('2026-09-15 12:00:00');

    [$this->team, $this->member] = $this->teamWithMember();
    [$this->otherTeam, $this->stranger] = $this->teamWithMember();

    /*
     * The owner is found on the team `teamWithMember()` already provisioned,
     * rather than made with `teamWithOwner()`.
     *
     * `SendSafetyTest` does it this way and it is the pattern that works:
     * `ProvisionTeam` attaches an owner when the team is created, so asking
     * for the holder of the `team_owner` role gets the person the roles were
     * actually granted to. Building a second owner on top of that is how this
     * file spent a CI round watching its own owner meet a 403.
     */
    $this->owner = app(TeamContext::class)->runFor(
        $this->team,
        fn (): TeamMembership => TeamMembership::query()
            ->whereHas('roles', fn ($query) => $query->where('roles.key', 'team_owner'))
            ->sole(),
    )->person;

    $this->enrollTwoFactor($this->owner);
});

function extractionOn(Deal $deal, int $costMicros = 40_000): Extraction
{
    return Extraction::factory()
        ->complete()
        ->costing($costMicros)
        ->create([
            'team_id' => $deal->team_id,
            'deal_id' => $deal->getKey(),
        ]);
}

it('refuses a member who cannot manage settings', function (): void {
    /*
     * Paired with the owner succeeding below. A refusal proved on its own
     * passes whether or not the check exists.
     */
    $this->actingAsPerson($this->member, $this->team);

    $this->get('/settings/extractions')->assertForbidden();
});

it('lets an owner in', function (): void {
    $this->actingAsPerson($this->owner, $this->team);

    $this->get('/settings/extractions')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/Extractions')
            ->has('scorecard')
            ->has('spend')
            ->has('versions')
            ->has('edits')
            ->has('attempts'));
});

it('counts only the asking team’s extractions', function (): void {
    /*
     * The isolation half, on a screen whose whole content is aggregates. A
     * cross-tenant leak here is not a row somebody can see — it is a *number*,
     * which is the kind that goes unnoticed. ADR 0002 makes the count as much
     * the tenant boundary as the list.
     */
    $mine = app(TeamContext::class)->runFor(
        $this->team,
        fn (): Deal => Deal::factory()->create(['team_id' => $this->team->getKey()]),
    );

    $theirs = app(TeamContext::class)->runFor(
        $this->otherTeam,
        fn (): Deal => Deal::factory()->create(['team_id' => $this->otherTeam->getKey()]),
    );

    app(TeamContext::class)->runFor($this->team, fn (): Extraction => extractionOn($mine, 40_000));

    app(TeamContext::class)->runFor(
        $this->otherTeam,
        fn (): Extraction => extractionOn($theirs, 9_000_000),
    );

    $this->actingAsPerson($this->owner, $this->team);

    $this->get('/settings/extractions')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $props = $page->toArray()['props'];

            expect($props['attemptsTotal'])->toBe(1)
                ->and(collect($props['attempts'])->pluck('id'))->toHaveCount(1);

            /*
             * The other team spent $9. If any of it reached this screen the
             * month-to-date figure would say so, and nothing else would.
             */
            expect($props['spend']['monthToDate'])->not->toContain('9.0');
        });
});

it('never reports a critical date as missed, because it cannot see one', function (): void {
    /*
     * #118's most important line about this screen, and the one easiest to
     * regress into a reassuring zero.
     *
     * The application can never know what a contract contained but the model
     * did not report — a miss leaves no row. So a count here would read `0` for
     * a perfect model and `0` for one that read a single page of twelve, and
     * PRD §12.3 gives that metric **zero tolerance**. The screen names the
     * command that measures it instead.
     */
    $this->actingAsPerson($this->owner, $this->team);

    $this->get('/settings/extractions')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $critical = $page->toArray()['props']['scorecard']['criticalDates'];

            expect($critical['measuredHere'])->toBeFalse()
                ->and($critical)->not->toHaveKey('missed')
                ->and($critical['command'])->toContain('extraction:score');
        });
});

it('shows what the human changed, which is F10.4’s valuable column', function (): void {
    app(TeamContext::class)->runFor($this->team, function (): void {
        $deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);
        $extraction = extractionOn($deal);

        ExtractedField::factory()->keyDate()->create([
            'team_id' => $this->team->getKey(),
            'extraction_id' => $extraction->getKey(),
            'label' => 'Inspection Objection Deadline',
            'proposed_value' => '2026-03-08',
            'final_value' => '2026-03-28',
            'review_state' => ExtractedFieldReviewState::Edited->value,
            'reviewed_by' => $this->owner->getKey(),
            'reviewed_at' => now(),
        ]);
    });

    $this->actingAsPerson($this->owner, $this->team);

    $this->get('/settings/extractions')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $edits = collect($page->toArray()['props']['edits']);
            $row = $edits->firstWhere('label', 'Inspection Objection Deadline');

            expect($row)->not->toBeNull()
                ->and($row['proposedValue'])->toBe('2026-03-08')
                ->and($row['finalValue'])->toBe('2026-03-28');
        });
});

it('draws a ceiling of zero as a ceiling, not as no limit at all', function (): void {
    /*
     * The half of the zero-cap rule that lives on this screen, and the one that
     * was wrong for two rounds after `SpendLedger` was fixed.
     *
     * `spend()` read `$cap > 0`, so the team an operator had just stopped got
     * the presentation reserved for *"there is no ceiling here"*: no figure, no
     * bar, no percentage — on a team whose next press is refused. The operator
     * checking their own change would have found the screen reporting that they
     * had not made it.
     *
     * Both keys are asserted because either alone passes for the wrong reason:
     * a `cap` with no `percent` draws a number with no bar, and a `percent`
     * with no `cap` draws a bar against nothing.
     */
    $this->team->forceFill(['extraction_monthly_cap_micros' => 0])->save();

    $this->actingAsPerson($this->owner, $this->team);

    $this->get('/settings/extractions')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $spend = $page->toArray()['props']['spend'];

            expect($spend['cap'])->not->toBeNull()
                ->and($spend['percent'])->toBe(100);
        });
});

it('draws no ceiling when there genuinely is not one', function (): void {
    /*
     * The control. Without it the case above passes against a screen that
     * always draws a bar, which is the same defect with the sign flipped —
     * S50's invented-maximum lie, told about spend instead of storage.
     *
     * A negative cap is the only way to say *"no ceiling"*, and it comes from
     * configuration rather than the column: the CHECK on `teams` refuses one.
     */
    config(['extraction.caps.team_monthly_micros' => -1]);

    $this->actingAsPerson($this->owner, $this->team);

    $this->get('/settings/extractions')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $spend = $page->toArray()['props']['spend'];

            expect($spend['cap'])->toBeNull()
                ->and($spend['percent'])->toBeNull()
                ->and($spend['monthToDate'])->not->toBeNull();
        });
});
