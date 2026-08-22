<?php

declare(strict_types=1);

use App\Enums\ParticipantRole;
use App\Models\Deal;
use App\Models\Person;
use App\Models\TeamMembership;
use App\Support\Deals\DealRoster;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\DB;

/**
 * S25's typeahead, and S19's list (issue #60).
 *
 * `PeopleIndexBudgetTest` states the house standard and the reason:
 * *"a per-row query for the person behind each membership is how this screen
 * becomes unusable at exactly the point a customer starts relying on it."*
 *
 * The candidates endpoint is the worst place in this slice to carry one,
 * because it fires on a 250ms debounce as somebody types. It shipped with
 * exactly that — `rolesAlreadyHeld()` per candidate, measured at 16 queries
 * for 3 rows and 33 for 20. This is what stops it coming back.
 */
function seedCandidates(int $count, string $label): array
{
    [$team, $member] = test()->teamWithMember();

    app(TeamContext::class)->runFor($team, function () use ($team, $count, $label): void {
        for ($i = 0; $i < $count; $i++) {
            TeamMembership::query()->create([
                'team_id' => $team->getKey(),
                'person_id' => Person::factory()->create()->getKey(),
                'first_name' => "{$label}{$i}",
                'last_name' => 'Candidate',
                'status' => App\Enums\PersonLifecycleState::Active,
            ]);
        }
    });

    return [$team, $member];
}

function countParticipantQueries(Closure $callback): int
{
    $queries = 0;

    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $callback();

    return $queries;
}

it('does not grow the typeahead’s query count with the directory', function (): void {
    [$smallTeam, $smallMember] = seedCandidates(2, 'small');

    $this->actingAsPerson($smallMember, $smallTeam);

    $smallDeal = app(TeamContext::class)->runFor(
        $smallTeam,
        fn () => Deal::factory()->create(['team_id' => $smallTeam->getKey()]),
    );

    $small = countParticipantQueries(
        fn () => $this->getJson("/deals/{$smallDeal->getKey()}/people/candidates?q=Candidate")->assertOk(),
    );

    [$largeTeam, $largeMember] = seedCandidates(20, 'large');

    $this->actingAsPerson($largeMember, $largeTeam);

    $largeDeal = app(TeamContext::class)->runFor(
        $largeTeam,
        fn () => Deal::factory()->create(['team_id' => $largeTeam->getKey()]),
    );

    $large = countParticipantQueries(
        fn () => $this->getJson("/deals/{$largeDeal->getKey()}/people/candidates?q=Candidate")->assertOk(),
    );

    // Exactly equal, which is `PeopleIndexBudgetTest`'s assertion and the only
    // one that catches a +1-per-row. A budget of "under 40" would have passed
    // on the version this replaces, at 33.
    expect($large)->toBe(
        $small,
        'The candidates endpoint gained queries as the directory grew. '
        .'`rolesAlreadyHeld()` takes the whole page of memberships and answers '
        .'in one grouped query; a per-candidate call is the usual cause.',
    );
});

it('does not grow the people tab’s query count with the participants', function (): void {
    // Measured as already correct — `$deal->load('participants.membership')`
    // does its job — and pinned so it stays that way.
    [$team, $member] = seedCandidates(20, 'tab');

    $this->actingAsPerson($member, $team);

    [$smallDeal, $largeDeal] = app(TeamContext::class)->runFor($team, function () use ($team): array {
        $memberships = TeamMembership::query()->where('first_name', 'like', 'tab%')->get();

        $small = Deal::factory()->create(['team_id' => $team->getKey()]);
        $large = Deal::factory()->create(['team_id' => $team->getKey()]);

        $roster = app(DealRoster::class);
        $roles = ParticipantRole::cases();

        foreach ($memberships->take(2) as $index => $membership) {
            $roster->add($small, $membership, $roles[$index]);
        }

        foreach ($memberships as $index => $membership) {
            $roster->add($large, $membership, $roles[$index % count($roles)]);
        }

        return [$small, $large];
    });

    $small = countParticipantQueries(
        fn () => $this->get("/deals/{$smallDeal->getKey()}/people")->assertOk(),
    );

    $large = countParticipantQueries(
        fn () => $this->get("/deals/{$largeDeal->getKey()}/people")->assertOk(),
    );

    expect($large)->toBe($small, 'The people tab gained queries as it gained participants.');
});
