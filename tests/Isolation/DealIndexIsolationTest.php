<?php

declare(strict_types=1);

use App\Enums\DealState;
use App\Models\Deal;
use App\Models\DealType;
use App\Models\Team;
use App\Support\Tenancy\TeamContext;

/**
 * S13's filter bar counts one team's deals (issue #78).
 *
 * The index's own rows are covered by the global scope like every other list.
 * The **counts** are the part worth a test of their own, because
 * `DealDirectory::segmentCounts()` groups — and `PropertyDirectory`'s docblock
 * records what grouping through the base query builder did there: ADR 0002's
 * first layer is applied when the *Eloquent* builder runs, so a
 * `getQuery()->groupBy()` would have counted every team's deals into one
 * team's filter bar.
 *
 * A count is a disclosure even when the rows are not. "All (412)" over an
 * empty list tells a competitor how much business the platform's other tenants
 * are doing.
 */
beforeEach(function (): void {
    [$this->teamA, $this->memberA] = $this->teamWithMember();
    [$this->teamB, $this->memberB] = $this->teamWithMember();
});

function dealsFor(Team $team, int $open, int $closed): void
{
    app(TeamContext::class)->runFor($team, function () use ($team, $open, $closed): void {
        $type = DealType::query()->whereNull('team_id')->firstOrFail();

        foreach ([[DealState::Active, $open], [DealState::Closed, $closed]] as [$state, $count]) {
            for ($i = 0; $i < $count; $i++) {
                Deal::factory()->create([
                    'team_id' => $team->getKey(),
                    'deal_type_id' => $type->getKey(),
                    'state' => $state,
                ]);
            }
        }
    });
}

it('counts one team’s deals in the filter bar, not the platform’s', function (): void {
    dealsFor($this->teamA, open: 2, closed: 1);
    dealsFor($this->teamB, open: 7, closed: 5);

    $this->actingAsPerson($this->memberA, $this->teamA);

    $this->get('/deals')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $counts = collect($page->toArray()['props']['segmentCounts'])->keyBy('value');

            expect($counts['open']['count'])->toBe(2)
                ->and($counts['all']['count'])->toBe(3)
                ->and($counts['closed']['count'])->toBe(1);
        });

    /*
     * The control, which is the half `PropertyIsolationTest` insists on: the
     * other team really does have twelve, so the three above is scoping rather
     * than an empty database. Without it the assertion passes whether or not
     * the scope exists.
     */
    $this->actingAsPerson($this->memberB, $this->teamB);

    $this->get('/deals')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $counts = collect($page->toArray()['props']['segmentCounts'])->keyBy('value');

            expect($counts['all']['count'])->toBe(12);
        });
});

it('offers only its own team’s deal types as filter options', function (): void {
    $theirs = app(TeamContext::class)->runFor($this->teamB, fn (): DealType => DealType::factory()->create([
        'team_id' => $this->teamB->getKey(),
        'name' => 'Their private type',
    ]));

    app(TeamContext::class)->runFor($this->teamB, fn () => Deal::factory()->create([
        'team_id' => $this->teamB->getKey(),
        'deal_type_id' => $theirs->getKey(),
    ]));

    $mine = app(TeamContext::class)->runFor($this->teamA, fn (): DealType => DealType::factory()->create([
        'team_id' => $this->teamA->getKey(),
        'name' => 'My private type',
    ]));

    app(TeamContext::class)->runFor($this->teamA, fn () => Deal::factory()->create([
        'team_id' => $this->teamA->getKey(),
        'deal_type_id' => $mine->getKey(),
    ]));

    $this->actingAsPerson($this->memberA, $this->teamA);

    $this->get('/deals')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $labels = collect($page->toArray()['props']['dealTypeOptions'])->pluck('label');

            // `deal_types` carries no global team scope of its own — a chip
            // built from `DealType::query()` would have named the other team's
            // private type in this team's filter bar. It is derived from the
            // deals instead, which are scoped.
            expect($labels)->toContain('My private type')
                ->and($labels)->not->toContain('Their private type');
        });
});
