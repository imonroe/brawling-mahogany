<?php

declare(strict_types=1);

use App\Enums\ParticipantRole;
use App\Models\Deal;
use App\Models\DealParticipant;
use App\Models\Person;
use App\Models\TeamMembership;
use App\Support\Deals\DealRoster;
use App\Support\Tenancy\TeamContext;
use Illuminate\Database\QueryException;

/**
 * The tenant boundary around deal participants (ADR 0002 · issue #60).
 *
 * Unlike `deal_types`, this table carries `team_id` and gets all five layers.
 * What it needs proving is the two things the layers do *not* answer on their
 * own:
 *
 * 1. **Composite keys make a cross-tenant pointer unrepresentable.** The row
 *    references both a deal and a membership, and either could be another
 *    team's.
 * 2. **Whose team is not whose deal.** A participant on deal A and a
 *    participant on deal B are both in the team, so the global scope and the
 *    policy are both content — only the route's scoped binding refuses one
 *    reached through the other's URL.
 */
beforeEach(function (): void {
    [$this->teamA, $this->memberA] = $this->teamWithMember();
    [$this->teamB, $this->memberB] = $this->teamWithMember();

    $this->seed(Database\Seeders\DealTypeSeeder::class);
});

function membershipIn(App\Models\Team $team, string $name = 'Claire'): TeamMembership
{
    return app(TeamContext::class)->runFor($team, fn () => TeamMembership::query()->create([
        'team_id' => $team->getKey(),
        'person_id' => Person::factory()->create()->getKey(),
        'first_name' => $name,
        'status' => App\Enums\PersonLifecycleState::Active,
    ]));
}

it('cannot point a participant at another team’s deal', function (): void {
    $foreignDeal = app(TeamContext::class)->runFor(
        $this->teamB,
        fn () => Deal::factory()->create(['team_id' => $this->teamB->getKey()]),
    );

    $ourMembership = membershipIn($this->teamA);

    app(TeamContext::class)->runFor($this->teamA, function () use ($foreignDeal, $ourMembership): void {
        // The composite key over (team_id, deal_id) makes this a database
        // error rather than a code review.
        expect(fn () => DealParticipant::query()->create([
            'team_id' => $this->teamA->getKey(),
            'deal_id' => $foreignDeal->getKey(),
            'team_membership_id' => $ourMembership->getKey(),
            'participant_role' => ParticipantRole::Seller->value,
        ]))->toThrow(QueryException::class);
    });
});

it('cannot point a participant at another team’s directory entry', function (): void {
    $foreignMembership = membershipIn($this->teamB, 'Someone Else');

    app(TeamContext::class)->runFor($this->teamA, function () use ($foreignMembership): void {
        $deal = Deal::factory()->create(['team_id' => $this->teamA->getKey()]);

        // The second composite key, over (team_id, team_membership_id). This
        // is the one a `person_id` column could not have offered, because
        // `people` carries no team_id — see the migration's docblock.
        expect(fn () => DealParticipant::query()->create([
            'team_id' => $this->teamA->getKey(),
            'deal_id' => $deal->getKey(),
            'team_membership_id' => $foreignMembership->getKey(),
            'participant_role' => ParticipantRole::Seller->value,
        ]))->toThrow(QueryException::class);
    });
});

it('404s another team’s deal on the people tab', function (): void {
    $foreignDeal = app(TeamContext::class)->runFor(
        $this->teamB,
        fn () => Deal::factory()->create(['team_id' => $this->teamB->getKey()]),
    );

    $this->actingAsPerson($this->memberA, $this->teamA);

    // Not 403: a 403 confirms the deal exists (ADR 0002, layer 3). `Deal`
    // carries the global scope, so this comes for free — asserted anyway,
    // because "for free" is a property that can be lost.
    $this->get("/deals/{$foreignDeal->getKey()}/people")->assertNotFound();
    $this->post("/deals/{$foreignDeal->getKey()}/people", [
        'first_name' => 'Intruder',
        'participant_role' => ParticipantRole::Seller->value,
    ])->assertNotFound();
});

/**
 * Whose team is not whose deal.
 */
it('404s a participant reached through the wrong deal’s URL', function (): void {
    $this->actingAsPerson($this->memberA, $this->teamA);

    [$participant, $otherDeal] = app(TeamContext::class)->runFor($this->teamA, function (): array {
        $dealOne = Deal::factory()->create(['team_id' => $this->teamA->getKey()]);
        $dealTwo = Deal::factory()->create(['team_id' => $this->teamA->getKey()]);

        $participant = app(DealRoster::class)
            ->add($dealOne, membershipIn($this->teamA), ParticipantRole::Seller);

        return [$participant, $dealTwo];
    });

    /*
     * Both rows are in the same team, so the global scope has no objection and
     * the policy — which asks about the team — would agree. Only the route's
     * `scopeBindings()` knows the participant belongs to the *other* deal.
     */
    $this->patch("/deals/{$otherDeal->getKey()}/people/{$participant->getKey()}", [
        'participant_role' => ParticipantRole::Buyer->value,
    ])->assertNotFound();

    $this->delete("/deals/{$otherDeal->getKey()}/people/{$participant->getKey()}")
        ->assertNotFound();

    expect($participant->fresh()->participant_role)->toBe(ParticipantRole::Seller);
});

it('refuses a foreign directory entry named in a form body', function (): void {
    $foreignMembership = membershipIn($this->teamB, 'Someone Else');

    $this->actingAsPerson($this->memberA, $this->teamA);

    $deal = app(TeamContext::class)->runFor(
        $this->teamA,
        fn () => Deal::factory()->create(['team_id' => $this->teamA->getKey()]),
    );

    /*
     * A 422 rather than the 500 the composite key would give. The database
     * would refuse it either way — that is the layer that makes it safe — but
     * a constraint violation is a stack trace, and this is a sentence naming
     * the field.
     */
    $this->post("/deals/{$deal->getKey()}/people", [
        'team_membership_id' => $foreignMembership->getKey(),
        'participant_role' => ParticipantRole::Seller->value,
    ])->assertSessionHasErrors('team_membership_id');

    expect(DealParticipant::withoutTeamScope()->count())->toBe(0);
});

it('never offers one team’s people as candidates on another team’s deal', function (): void {
    membershipIn($this->teamB, 'Bee');

    $this->actingAsPerson($this->memberA, $this->teamA);

    $deal = app(TeamContext::class)->runFor($this->teamA, function () {
        membershipIn($this->teamA, 'Ay');

        return Deal::factory()->create(['team_id' => $this->teamA->getKey()]);
    });

    $names = collect(
        $this->getJson("/deals/{$deal->getKey()}/people/candidates")->assertOk()->json('candidates'),
    )->pluck('name');

    // Both halves: team A's own person is offered and team B's is not. An
    // assertion of absence alone passes on an empty list.
    expect($names)->toContain('Ay')->not->toContain('Bee');
});
