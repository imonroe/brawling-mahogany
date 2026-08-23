<?php

declare(strict_types=1);

use App\Enums\DealSide;
use App\Models\Deal;
use App\Models\DealProperty;
use App\Models\DealType;
use App\Models\Property;
use App\Support\Tenancy\TeamContext;

/**
 * The tenant boundary around S20 (issue #62 · ADR 0002).
 *
 * `deal_properties` sits inside all five layers, so most of this confirms
 * rather than enforces. The part that is enforcement is the **nesting**: two
 * link rows in one team both pass the global scope and both satisfy the
 * policy, and only `Route::scopeBindings()` answers "whose deal".
 *
 * Every refusal is paired with the same actor succeeding on their own row. A
 * 403 or 404 proved without that control passes whether or not the check
 * exists — the vacuous shape earlier reviews on #61 kept finding.
 */
beforeEach(function (): void {
    [$this->teamA, $this->memberA] = $this->teamWithMember();
    [$this->teamB, $this->memberB] = $this->teamWithMember();
});

/** @return array{0: Deal, 1: Property} */
function pairFor(App\Models\Team $team): array
{
    return app(TeamContext::class)->runFor($team, function () use ($team): array {
        $type = DealType::factory()->create(['team_id' => $team->getKey(), 'side' => DealSide::Buy]);

        return [
            Deal::factory()->create(['team_id' => $team->getKey(), 'deal_type_id' => $type->getKey()]),
            Property::factory()->create(['team_id' => $team->getKey()]),
        ];
    });
}

it('404s another team’s deal on every S20 route', function (): void {
    [$foreignDeal] = pairFor($this->teamB);
    [$ownDeal, $ownProperty] = pairFor($this->teamA);

    $this->actingAsPerson($this->memberA, $this->teamA);

    // The control: their own deal answers all of this.
    $this->get("/deals/{$ownDeal->getKey()}/properties")->assertOk();
    $this->post("/deals/{$ownDeal->getKey()}/properties", ['property_id' => $ownProperty->getKey()])
        ->assertRedirect();

    /*
     * 404, not 403. ADR 0002 layer 3: a 403 confirms the record exists, which
     * is a disclosure in itself.
     */
    $this->get("/deals/{$foreignDeal->getKey()}/properties")->assertNotFound();
    $this->post("/deals/{$foreignDeal->getKey()}/properties", ['property_id' => $ownProperty->getKey()])
        ->assertNotFound();
    $this->getJson("/deals/{$foreignDeal->getKey()}/properties/candidates")->assertNotFound();

    /*
     * And the four the first version of this stopped short of, while calling
     * itself "every S20 route". A test named for coverage it does not have is
     * worse than one named for what it checks.
     */
    $ownLink = DealProperty::query()->sole();

    $this->put("/deals/{$foreignDeal->getKey()}/properties/order", ['order' => [$ownLink->getKey()]])
        ->assertNotFound();
    $this->patch("/deals/{$foreignDeal->getKey()}/properties/{$ownLink->getKey()}", [
        'interest_status' => 'shortlisted',
    ])->assertNotFound();
    $this->post("/deals/{$foreignDeal->getKey()}/properties/{$ownLink->getKey()}/subject")
        ->assertNotFound();
    $this->delete("/deals/{$foreignDeal->getKey()}/properties/{$ownLink->getKey()}")
        ->assertNotFound();

    // Untouched by any of it.
    expect($ownLink->fresh()->trashed())->toBeFalse()
        ->and($ownLink->fresh()->interest_status)->toBeNull();
});

it('refuses a property from another team on the link route', function (): void {
    [, $foreignProperty] = pairFor($this->teamB);
    [$ownDeal, $ownProperty] = pairFor($this->teamA);

    $this->actingAsPerson($this->memberA, $this->teamA);

    // The control: their own property links.
    $this->post("/deals/{$ownDeal->getKey()}/properties", ['property_id' => $ownProperty->getKey()])
        ->assertSessionHasNoErrors();

    /*
     * A 422 rather than a constraint violation. `teamScopedForeign` would
     * refuse this at the database — that is the point of it — but a violation
     * is a 500, and the rule turns it into a named field.
     */
    $this->post("/deals/{$ownDeal->getKey()}/properties", ['property_id' => $foreignProperty->getKey()])
        ->assertSessionHasErrors('property_id');

    expect(DealProperty::withoutTeamScope()->where('property_id', $foreignProperty->getKey())->count())
        ->toBe(0);
});

it('404s a link row reached through the wrong deal', function (): void {
    /*
     * The nesting, and nothing else, answers this. Both deals are in the team,
     * so the global scope has no objection and `DealPropertyPolicy` is asked
     * about a link that genuinely belongs to the actor's team.
     */
    [$ownDeal, $ownProperty] = pairFor($this->teamA);
    [$otherDeal] = pairFor($this->teamA);

    $this->actingAsPerson($this->memberA, $this->teamA);

    $this->post("/deals/{$ownDeal->getKey()}/properties", ['property_id' => $ownProperty->getKey()])
        ->assertRedirect();

    $link = DealProperty::query()->sole();

    $this->post("/deals/{$otherDeal->getKey()}/properties/{$link->getKey()}/subject")->assertNotFound();

    $this->patch("/deals/{$otherDeal->getKey()}/properties/{$link->getKey()}", [
        'interest_status' => 'shortlisted',
    ])->assertNotFound();

    $this->delete("/deals/{$otherDeal->getKey()}/properties/{$link->getKey()}")->assertNotFound();

    // The control: through its own deal, every one of those works.
    $this->post("/deals/{$ownDeal->getKey()}/properties/{$link->getKey()}/subject")->assertRedirect();
    $this->delete("/deals/{$ownDeal->getKey()}/properties/{$link->getKey()}")->assertRedirect();
});

it('keeps another team’s properties out of the picker', function (): void {
    [, $foreignProperty] = pairFor($this->teamB);
    [$ownDeal, $ownProperty] = pairFor($this->teamA);

    $this->actingAsPerson($this->memberA, $this->teamA);

    $this->getJson("/deals/{$ownDeal->getKey()}/properties/candidates")
        ->assertOk()
        ->assertJsonCount(1, 'properties')
        ->assertJsonPath('properties.0.id', $ownProperty->getKey());

    // No "and the two ids differ" assertion here: two freshly generated ULIDs
    // cannot collide, so it would pass whatever the picker returned. The
    // count plus the path above is the whole control.
    expect($foreignProperty->fresh())->not->toBeNull();
});

it('ranks only the links on the deal the route names', function (): void {
    /*
     * `rank()` writes by id, and a list of ids is exactly the shape that
     * smuggles a row past a screen.
     *
     * **Two deals in the same team**, deliberately. Another team's link is
     * already invisible to the global scope, so a cross-tenant version of this
     * test would pass whether or not `rank()` scoped to the deal at all. The
     * deal filter is what the nesting adds, and this is what holds it.
     */
    [$ownDeal, $ownProperty] = pairFor($this->teamA);
    [$otherDeal, $otherProperty] = pairFor($this->teamA);

    $this->actingAsPerson($this->memberA, $this->teamA);

    $this->post("/deals/{$ownDeal->getKey()}/properties", ['property_id' => $ownProperty->getKey()])
        ->assertRedirect();
    $this->post("/deals/{$otherDeal->getKey()}/properties", ['property_id' => $otherProperty->getKey()])
        ->assertRedirect();

    $ownLink = DealProperty::query()->where('deal_id', $ownDeal->getKey())->sole();
    $otherLink = DealProperty::query()->where('deal_id', $otherDeal->getKey())->sole();

    /*
     * The other deal's link is named *second*, so a `rank()` that wrote by id
     * alone would move it to 1. Naming it first would leave it on 0 either
     * way, which is how a test like this ends up proving nothing.
     */
    $this->put("/deals/{$ownDeal->getKey()}/properties/order", [
        'order' => [$ownLink->getKey(), $otherLink->getKey()],
    ])->assertRedirect();

    expect($ownLink->fresh()->sort_order)->toBe(0)
        ->and($otherLink->fresh()->sort_order)->toBe(0);
});
