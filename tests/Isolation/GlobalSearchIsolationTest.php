<?php

declare(strict_types=1);

use App\Models\Deal;
use App\Models\Person;
use App\Models\Property;
use App\Models\TeamMembership;
use App\Support\Tenancy\TeamContext;

/**
 * S07's tenancy, which #82 calls *"a security requirement, not a convenience"*.
 *
 * > *"Search is the classic place tenancy leaks, because it is the one query
 * > that deliberately spans every table."*
 *
 * And the definition of done says exactly what to assert: *"a member of Team A
 * searching for a string that exists only in Team B's data gets zero
 * results."* So this searches for a string that is **only** in the other
 * team's rows, across all three kinds of thing at once — a leak in any one of
 * them fails the case.
 */
it('returns nothing for a string that exists only in another team', function (): void {
    [$mine, $me] = $this->teamWithMember();
    [$theirs] = $this->teamWithMember();

    app(TeamContext::class)->runFor($theirs, function () use ($theirs): void {
        Deal::factory()->create(['team_id' => $theirs->getKey(), 'name' => 'Zetaquin sale']);

        Property::factory()->create(['team_id' => $theirs->getKey(), 'street' => '9 Zetaquin Way']);

        TeamMembership::query()->create([
            'team_id' => $theirs->getKey(),
            'person_id' => Person::factory()->create()->getKey(),
            'first_name' => 'Zetaquin',
            'last_name' => 'Holt',
            'joined_at' => now(),
        ]);
    });

    $this->actingAsPerson($me, $mine);

    $body = $this->getJson('/search?q=zetaquin')->assertOk()->json();

    expect($body['groups'])->toBe([]);
});

it('finds the same string once it is this team’s', function (): void {
    /*
     * The control, and it is not optional: a search that returns nothing for
     * everything passes the case above perfectly. This is the same query, the
     * same string, and the only difference is whose row it is.
     */
    [$mine, $me] = $this->teamWithMember();

    app(TeamContext::class)->runFor($mine, function () use ($mine): void {
        Deal::factory()->create(['team_id' => $mine->getKey(), 'name' => 'Zetaquin sale']);
    });

    $this->actingAsPerson($me, $mine);

    $body = $this->getJson('/search?q=zetaquin')->assertOk()->json();

    expect($body['groups'])->not->toBe([])
        ->and($body['groups'][0]['results'][0]['label'])->toContain('Zetaquin');
});
