<?php

declare(strict_types=1);

use App\Models\Deal;
use App\Models\Person;
use App\Models\Property;
use App\Models\TeamMembership;
use App\Support\Tenancy\TeamContext;

/**
 * S07 — the global search overlay (PRD F9.3 · #82).
 *
 * The cross-tenant case lives in `tests/Isolation/GlobalSearchIsolationTest`,
 * because #82 calls it *"a security requirement, not a convenience"* and the
 * isolation suite is where this project keeps the properties it will not
 * ship without.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);
});

it('groups what it finds by type, and names the type', function (): void {
    /*
     * *"'123 Main St' is plausibly a deal, a property, or a document."* Which
     * is exactly this fixture: one string, three kinds of thing.
     */
    app(TeamContext::class)->runFor($this->team, function (): void {
        Deal::factory()->create(['team_id' => $this->team->getKey(), 'name' => '123 Main St sale']);

        Property::factory()->create(['team_id' => $this->team->getKey(), 'street' => '123 Main St']);

        TeamMembership::query()->create([
            'team_id' => $this->team->getKey(),
            'person_id' => Person::factory()->create()->getKey(),
            'first_name' => 'Main',
            'last_name' => 'Streeter',
            'joined_at' => now(),
        ]);
    });

    $body = $this->getJson('/search?q=main')->assertOk()->json();

    $types = array_column($body['groups'], 'type');

    expect($types)->toContain('deal')->toContain('property')->toContain('person');
});

it('does not render a heading over nothing', function (): void {
    // Three empty headings above one result buries it.
    app(TeamContext::class)->runFor($this->team, function (): void {
        Deal::factory()->create(['team_id' => $this->team->getKey(), 'name' => 'Solitary']);
    });

    $body = $this->getJson('/search?q=solitary')->assertOk()->json();

    expect(array_column($body['groups'], 'type'))->toBe(['deal']);
});

it('asks for more letters rather than claiming there is nothing', function (): void {
    /*
     * "No results" is a claim about the team's data. One letter against
     * hundreds of past clients is not a search, and answering it with that
     * claim would be answering a question nobody asked.
     */
    $this->getJson('/search?q=a')
        ->assertOk()
        ->assertJson(['tooShort' => true, 'groups' => []]);
});

it('offers recent deals before anything is typed', function (): void {
    // *"The fastest search is the one you do not have to type."*
    app(TeamContext::class)->runFor($this->team, function (): void {
        Deal::factory()->create(['team_id' => $this->team->getKey(), 'name' => 'Lately touched']);
    });

    $body = $this->getJson('/search')->assertOk()->json();

    expect($body['groups'][0]['label'])->toBe('Recent deals')
        ->and($body['groups'][0]['results'][0]['label'])->toBe('Lately touched')
        ->and($body['tooShort'])->toBeFalse();
});

it('finds a deal by the name it was given and the one it derived', function (): void {
    // `deals` carries both, and `displayName()` decides which a screen sees —
    // so a search that read only one of them would miss half the deals.
    app(TeamContext::class)->runFor($this->team, function (): void {
        Deal::factory()->create([
            'team_id' => $this->team->getKey(),
            'name' => null,
            'generated_name' => 'Nakamura — 88 Larkspur Ln',
        ]);
    });

    $body = $this->getJson('/search?q=larkspur')->assertOk()->json();

    expect($body['groups'][0]['results'][0]['label'])->toBe('Nakamura — 88 Larkspur Ln');
});

it('refuses somebody who cannot see the team’s deals', function (): void {
    $outsider = Person::factory()->create();

    app(TeamContext::class)->runFor($this->team, function () use ($outsider): void {
        TeamMembership::query()->create([
            'team_id' => $this->team->getKey(),
            'person_id' => $outsider->getKey(),
            'first_name' => 'No',
            'last_name' => 'Access',
            'joined_at' => now(),
        ]);
    });

    $this->actingAsPerson($outsider, $this->team);

    $this->getJson('/search?q=anything')->assertForbidden();
});
