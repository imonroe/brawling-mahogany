<?php

declare(strict_types=1);

use App\Models\Deal;
use App\Models\DealType;
use App\Support\Tenancy\TeamContext;

/**
 * The tenant boundary around S76 (issue #58 · ADR 0002).
 *
 * `deal_types` carries no `BelongsToTeam`, and that is deliberate: a null
 * `team_id` means "everybody's", which a global scope cannot express. So the
 * five enforcement layers do not reach this table and **the policy is the only
 * thing standing there.** That makes these route-level tests the enforcement
 * rather than a confirmation of it, which is why they get their own file.
 *
 * ## Every team here is a Team Owner, on purpose
 *
 * `settings.manage` is an owner permission. A Team Member is refused these
 * routes whoever owns the row, so a 403 proved with one would pass whether or
 * not the tenancy check existed — the vacuous-assertion shape earlier rounds
 * of review kept finding. Each refusal below is paired with the same actor
 * succeeding on their own row, so the 403 can only be about *whose* it is.
 */
beforeEach(function (): void {
    [$this->teamA, $this->ownerA] = $this->teamWithOwner();
    [$this->teamB, $this->ownerB] = $this->teamWithOwner();

    $this->enrollTwoFactor($this->ownerA);

    $this->seed(Database\Seeders\DealTypeSeeder::class);
});

it('404s another team’s deal type on every route that writes', function (): void {
    $foreign = app(TeamContext::class)->runFor(
        $this->teamB,
        fn () => DealType::factory()->create(['team_id' => $this->teamB->getKey(), 'name' => 'B Private Type']),
    );

    $this->actingAsPerson($this->ownerA, $this->teamA);

    // The control: this actor can edit a type, so nothing below is refused for
    // want of a permission.
    $own = app(TeamContext::class)->runFor(
        $this->teamA,
        fn () => DealType::factory()->create(['team_id' => $this->teamA->getKey(), 'name' => 'A Own Type']),
    );

    $this->patch("/settings/deal-types/{$own->getKey()}", [
        'name' => 'A Renamed Type',
        'side' => 'sell',
    ])->assertRedirect();

    /*
     * **404, not 403.** ADR 0002 layer 3: *"a route-bound model whose `team_id`
     * does not match is a 404, not a 403 — a 403 confirms the record exists,
     * which is itself a disclosure."* Every other table gets that from the
     * global scope; `deal_types` has none, so
     * `DealType::resolveRouteBinding()` does it.
     *
     * This test was written with the right title and the wrong assertion, and
     * the 403s it accepted were a working existence oracle over every
     * deal-type id on the platform.
     */
    $this->patch("/settings/deal-types/{$foreign->getKey()}", [
        'name' => 'Renamed by the wrong team',
        'side' => 'sell',
    ])->assertNotFound();

    $this->post("/settings/deal-types/{$foreign->getKey()}/archive")->assertNotFound();
    $this->post("/settings/deal-types/{$foreign->getKey()}/restore")->assertNotFound();

    // And indistinguishable from an id that exists nowhere, which is the
    // property that makes it not an oracle.
    $this->patch('/settings/deal-types/01JZZZZZZZZZZZZZZZZZZZZZZZ', [
        'name' => 'Nothing',
        'side' => 'sell',
    ])->assertNotFound();

    expect($foreign->fresh()->name)->toBe('B Private Type')
        ->and($foreign->fresh()->isArchived())->toBeFalse();
});

it('never shows one team another team’s deal types', function (): void {
    app(TeamContext::class)->runFor(
        $this->teamB,
        fn () => DealType::factory()->create(['team_id' => $this->teamB->getKey(), 'name' => 'B Private Type']),
    );

    app(TeamContext::class)->runFor(
        $this->teamA,
        fn () => DealType::factory()->create(['team_id' => $this->teamA->getKey(), 'name' => 'A Own Type']),
    );

    $this->actingAsPerson($this->ownerA, $this->teamA);

    $names = collect(
        $this->get('/settings/deal-types')->assertOk()->viewData('page')['props']['dealTypes'],
    )->pluck('name');

    // Both halves: the shared defaults and their own are there, and team B's
    // is not. A test that only asserted the absence would pass on an empty
    // list.
    expect($names)->toContain('A Own Type')
        ->toContain('Buyer Representation')
        ->not->toContain('B Private Type');
});

it('403s a system deal type rather than hiding it', function (): void {
    /*
     * Shared by every team on the platform. One team hiding "Rental Placement"
     * for everybody is not what that team asked for — and taking a system type
     * out of *one* team's picker is a real want and a different feature.
     */
    $system = DealType::query()->whereNull('team_id')->where('name', 'Rental Placement')->sole();

    $this->actingAsPerson($this->ownerA, $this->teamA);

    /*
     * 403 here and 404 for a foreign row, and the difference is not an
     * inconsistency. This actor can genuinely see a system type — it is
     * shared, it is on their screen, it is in their picker — they simply may
     * not edit it. A 403 discloses nothing they did not already know, while a
     * 404 would claim a row they can see does not exist.
     */
    $this->post("/settings/deal-types/{$system->getKey()}/archive")->assertForbidden();
    $this->patch("/settings/deal-types/{$system->getKey()}", [
        'name' => 'Renamed for everybody',
        'side' => 'buy',
    ])->assertForbidden();

    expect($system->fresh()->isArchived())->toBeFalse()
        ->and($system->fresh()->name)->toBe('Rental Placement');
});

it('does not let one team’s deals affect another team’s in-use warning', function (): void {
    // The leak the count itself closes, asserted through the screen: a system
    // type is one row shared by everybody, so an unscoped count would tell
    // team A how many deals team B is running.
    $system = DealType::query()->whereNull('team_id')->where('name', 'Buyer Representation')->sole();

    app(TeamContext::class)->runFor($this->teamB, fn () => Deal::factory()->count(5)->create([
        'team_id' => $this->teamB->getKey(),
        'deal_type_id' => $system->getKey(),
    ]));

    $ownType = app(TeamContext::class)->runFor(
        $this->teamA,
        fn () => DealType::factory()->create(['team_id' => $this->teamA->getKey(), 'name' => 'A Own Type']),
    );

    app(TeamContext::class)->runFor($this->teamA, fn () => Deal::factory()->count(2)->create([
        'team_id' => $this->teamA->getKey(),
        'deal_type_id' => $ownType->getKey(),
    ]));

    $this->actingAsPerson($this->ownerA, $this->teamA);

    $counts = collect(
        $this->get('/settings/deal-types')->assertOk()->viewData('page')['props']['dealTypes'],
    )->pluck('dealCount', 'name');

    expect($counts['A Own Type'])->toBe(2)
        // Null rather than 5: a system type is not something this team
        // archives, so the question is not put.
        ->and($counts['Buyer Representation'])->toBeNull();
});
