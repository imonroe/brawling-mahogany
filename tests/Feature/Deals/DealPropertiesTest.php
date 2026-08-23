<?php

declare(strict_types=1);

use App\Enums\DealSide;
use App\Enums\ParticipantRole;
use App\Enums\PersonLifecycleState;
use App\Enums\PropertyInterest;
use App\Models\ActivityEvent;
use App\Models\Deal;
use App\Models\DealProperty;
use App\Models\DealType;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Property;
use App\Models\Role;
use App\Models\TeamMembership;
use App\Support\Deals\DealRoster;
use App\Support\Permissions;
use App\Support\Properties\PropertyDeals;
use App\Support\Tenancy\TeamContext;

/**
 * S20 — deal properties (issue #62 · PRD §4.3 F3.4, F3.5).
 *
 * The definition of done has three clauses and each has a test named for it: a
 * candidate can be promoted and the deal name follows unless it was typed, a
 * buyer deal with no properties renders something useful, and removing
 * detaches rather than deletes.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();

    $this->actingAsPerson($this->member, $this->team);
});

/** Somebody in the team, with a surname a deal can be named after. */
function memberOfThisTeam(string $surname): TeamMembership
{
    return app(TeamContext::class)->runFor(test()->team, fn (): TeamMembership => TeamMembership::query()->create([
        'team_id' => test()->team->getKey(),
        'person_id' => Person::factory()->create()->getKey(),
        'first_name' => 'Claire',
        'last_name' => $surname,
        'status' => PersonLifecycleState::Active,
        'joined_at' => now(),
    ]));
}

/** A member who may run deals and may not touch the property directory. */
function dealsOnlyMember(App\Models\Team $team): Person
{
    $person = Person::factory()->create();

    app(TeamContext::class)->runFor($team, function () use ($team, $person): void {
        $membership = TeamMembership::query()->create([
            'team_id' => $team->getKey(),
            'person_id' => $person->getKey(),
            'first_name' => 'Deals',
            'last_name' => 'Only',
            'status' => PersonLifecycleState::Active,
            'joined_at' => now(),
        ]);

        $role = Role::query()->create([
            'team_id' => $team->getKey(),
            'key' => 'deals_only',
            'name' => 'Deals Only',
        ]);

        $role->permissions()->sync(
            Permission::query()
                ->whereIn('key', [Permissions::VIEW_DEALS, Permissions::MANAGE_DEALS])
                ->pluck('id')
                ->all(),
        );

        $membership->roles()->attach($role->getKey());
    });

    return $person;
}

/** A deal of a given side, in the acting team. */
function dealOn(DealSide $side, array $attributes = []): Deal
{
    $type = DealType::factory()->create([
        'team_id' => test()->team->getKey(),
        'side' => $side,
    ]);

    return Deal::factory()->create([
        'team_id' => test()->team->getKey(),
        'deal_type_id' => $type->getKey(),
        ...$attributes,
    ]);
}

it('shows the empty state rather than a broken header', function (): void {
    // #62's definition of done, verbatim: "a buyer deal with no properties
    // renders a useful empty state, not a broken header."
    $deal = dealOn(DealSide::Buy);

    $this->get("/deals/{$deal->getKey()}/properties")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Deals/Properties')
            ->where('deal.isBuySide', true)
            ->has('links', 0));
});

it('makes a seller’s first property the subject and names the deal', function (): void {
    $deal = dealOn(DealSide::Sell, ['name' => null, 'generated_name' => null]);
    $property = Property::factory()->create(['team_id' => $this->team->getKey(), 'street' => '1420 Pearl St']);

    $this->post("/deals/{$deal->getKey()}/properties", ['property_id' => $property->getKey()])
        ->assertRedirect("/deals/{$deal->getKey()}/properties");

    expect(DealProperty::query()->sole()->is_subject)->toBeTrue()
        ->and($deal->fresh()->generated_name)->toBe('1420 Pearl St');
});

it('leaves a buyer’s properties as candidates until one is promoted', function (): void {
    /*
     * #62: "a buyer-side deal may have twelve candidates and no subject until
     * an offer is accepted." The first house somebody tours is not the house
     * they are buying, and naming the deal after it would put a wrong address
     * on every screen for weeks.
     */
    $deal = dealOn(DealSide::Buy, ['name' => null, 'generated_name' => null]);

    foreach (['1 A St', '2 B St', '3 C St'] as $street) {
        $property = Property::factory()->create(['team_id' => $this->team->getKey(), 'street' => $street]);

        $this->post("/deals/{$deal->getKey()}/properties", ['property_id' => $property->getKey()])
            ->assertRedirect();
    }

    expect(DealProperty::query()->where('is_subject', true)->count())->toBe(0)
        ->and($deal->fresh()->generated_name)->toBeNull();
});

it('names a buyer’s deal after the client, with no property on it at all', function (): void {
    /*
     * The claim this whole issue rests on, and the one nothing was keeping.
     *
     * Dropping buy-side auto-subject is only correct because IA §10's fallback
     * names a buyer's deal after the client — and `NameDeal` had four callers,
     * none of them the roster, so the surname never reached the column. A
     * buy-side deal with a named Buyer on it rendered "Untitled deal", on the
     * very screen whose copy promises otherwise.
     */
    $deal = dealOn(DealSide::Buy, ['name' => null, 'generated_name' => null]);

    app(DealRoster::class)->add(
        deal: $deal,
        membership: memberOfThisTeam('Nakamura'),
        role: ParticipantRole::Buyer,
        isPrimary: true,
    );

    expect($deal->fresh()->generated_name)->toBe('Nakamura Purchase')
        ->and($deal->fresh()->displayName())->toBe('Nakamura Purchase');
});

it('renames the deal when the client is removed from it', function (): void {
    // The other half of the same rule: `remove()` refreshes too, and
    // `NameDeal` leaves the column alone when nothing is left to build from.
    $deal = dealOn(DealSide::Buy, ['name' => null, 'generated_name' => null]);
    $property = Property::factory()->create(['team_id' => $this->team->getKey(), 'street' => '1420 Pearl St']);

    $this->post("/deals/{$deal->getKey()}/properties", ['property_id' => $property->getKey()])->assertRedirect();

    $participant = app(DealRoster::class)->add(
        deal: $deal,
        membership: memberOfThisTeam('Nakamura'),
        role: ParticipantRole::Buyer,
        isPrimary: true,
    );

    $link = DealProperty::query()->sole();
    $this->post("/deals/{$deal->getKey()}/properties/{$link->getKey()}/subject")->assertRedirect();

    expect($deal->fresh()->generated_name)->toBe('1420 Pearl St · Nakamura Purchase');

    app(DealRoster::class)->remove($participant);

    expect($deal->fresh()->generated_name)->toBe('1420 Pearl St');
});

it('promotes a candidate and the deal name follows', function (): void {
    $deal = dealOn(DealSide::Buy, ['name' => null, 'generated_name' => null]);
    $first = Property::factory()->create(['team_id' => $this->team->getKey(), 'street' => '1 A St']);
    $second = Property::factory()->create(['team_id' => $this->team->getKey(), 'street' => '2 B St']);

    foreach ([$first, $second] as $property) {
        $this->post("/deals/{$deal->getKey()}/properties", ['property_id' => $property->getKey()])->assertRedirect();
    }

    $link = DealProperty::query()->where('property_id', $second->getKey())->sole();

    $this->post("/deals/{$deal->getKey()}/properties/{$link->getKey()}/subject")->assertRedirect();

    expect($link->fresh()->is_subject)->toBeTrue()
        ->and($deal->fresh()->generated_name)->toBe('2 B St');
});

it('demotes the incumbent when a second candidate is promoted', function (): void {
    // `deal_properties_one_subject` is a partial unique index, so the demotion
    // has to happen in the same transaction or the promotion is refused.
    $deal = dealOn(DealSide::Sell, ['name' => null, 'generated_name' => null]);
    $first = Property::factory()->create(['team_id' => $this->team->getKey(), 'street' => '1 A St']);
    $second = Property::factory()->create(['team_id' => $this->team->getKey(), 'street' => '2 B St']);

    foreach ([$first, $second] as $property) {
        $this->post("/deals/{$deal->getKey()}/properties", ['property_id' => $property->getKey()])->assertRedirect();
    }

    $secondLink = DealProperty::query()->where('property_id', $second->getKey())->sole();

    $this->post("/deals/{$deal->getKey()}/properties/{$secondLink->getKey()}/subject")->assertRedirect();

    expect(DealProperty::query()->where('is_subject', true)->pluck('property_id')->all())
        ->toBe([$second->getKey()])
        ->and($deal->fresh()->generated_name)->toBe('2 B St');
});

it('does not overwrite a name somebody typed', function (): void {
    // #62's definition of done: "the deal name follows unless manually
    // overridden." `NameDeal` writes `generated_name`; `displayName()` prefers
    // the typed one.
    $deal = dealOn(DealSide::Buy, ['name' => 'The Pearl job']);
    $property = Property::factory()->create(['team_id' => $this->team->getKey(), 'street' => '1420 Pearl St']);

    $this->post("/deals/{$deal->getKey()}/properties", ['property_id' => $property->getKey()])->assertRedirect();

    $link = DealProperty::query()->sole();

    $this->post("/deals/{$deal->getKey()}/properties/{$link->getKey()}/subject")->assertRedirect();

    expect($deal->fresh()->name)->toBe('The Pearl job')
        ->and($deal->fresh()->generated_name)->toBe('1420 Pearl St')
        ->and($deal->fresh()->displayName())->toBe('The Pearl job');
});

it('records the buyer’s interest in a candidate', function (): void {
    $deal = dealOn(DealSide::Buy);
    $property = Property::factory()->create(['team_id' => $this->team->getKey()]);

    $this->post("/deals/{$deal->getKey()}/properties", ['property_id' => $property->getKey()])->assertRedirect();

    $link = DealProperty::query()->sole();

    $this->patch("/deals/{$deal->getKey()}/properties/{$link->getKey()}", [
        'interest_status' => PropertyInterest::Shortlisted->value,
    ])->assertRedirect();

    expect($link->fresh()->interest_status)->toBe(PropertyInterest::Shortlisted);
});

it('lets an interest be cleared back to nobody having said', function (): void {
    // Null is a real value here, and different from "Interested". By presence,
    // because `ConvertEmptyStringsToNull` erases the other distinction.
    $deal = dealOn(DealSide::Buy);
    $property = Property::factory()->create(['team_id' => $this->team->getKey()]);

    $this->post("/deals/{$deal->getKey()}/properties", ['property_id' => $property->getKey()])->assertRedirect();

    $link = DealProperty::query()->sole();

    $this->patch("/deals/{$deal->getKey()}/properties/{$link->getKey()}", [
        'interest_status' => PropertyInterest::Passed->value,
    ])->assertRedirect();

    $this->patch("/deals/{$deal->getKey()}/properties/{$link->getKey()}", [
        'interest_status' => null,
    ])->assertRedirect();

    expect($link->fresh()->interest_status)->toBeNull();
});

it('refuses an interest on a deal that is not buy-side', function (): void {
    // PRD F3.5 is one line: "Buyer-side: per-property interest status." A
    // column filling up with values no screen renders is the dead-data shape
    // this codebase keeps finding.
    $deal = dealOn(DealSide::Sell);
    $property = Property::factory()->create(['team_id' => $this->team->getKey()]);

    $this->post("/deals/{$deal->getKey()}/properties", ['property_id' => $property->getKey()])->assertRedirect();

    $link = DealProperty::query()->sole();

    $this->patch("/deals/{$deal->getKey()}/properties/{$link->getKey()}", [
        'interest_status' => PropertyInterest::Shortlisted->value,
    ])->assertSessionHasErrors('interest_status');

    expect($link->fresh()->interest_status)->toBeNull();
});

it('ranks the candidates in the order the agent puts them', function (): void {
    $deal = dealOn(DealSide::Buy);

    $properties = collect(['1 A St', '2 B St', '3 C St'])->map(function (string $street) use ($deal) {
        $property = Property::factory()->create(['team_id' => $this->team->getKey(), 'street' => $street]);

        $this->post("/deals/{$deal->getKey()}/properties", ['property_id' => $property->getKey()])->assertRedirect();

        return $property;
    });

    // Arrival order is the default, so the ranking has to be visibly different.
    $links = DealProperty::query()->orderBy('sort_order')->pluck('id', 'property_id');

    $this->put("/deals/{$deal->getKey()}/properties/order", [
        'order' => [
            $links[$properties[2]->getKey()],
            $links[$properties[0]->getKey()],
            $links[$properties[1]->getKey()],
        ],
    ])->assertRedirect();

    expect(DealProperty::query()->orderBy('sort_order')->pluck('property_id')->all())
        ->toBe([
            $properties[2]->getKey(),
            $properties[0]->getKey(),
            $properties[1]->getKey(),
        ]);
});

it('ignores a stale id in a reorder rather than losing the whole ranking', function (): void {
    // The list comes from a drag on a screen somebody may have had open while
    // a colleague removed a row.
    $deal = dealOn(DealSide::Buy);
    $property = Property::factory()->create(['team_id' => $this->team->getKey()]);

    $this->post("/deals/{$deal->getKey()}/properties", ['property_id' => $property->getKey()])->assertRedirect();

    $link = DealProperty::query()->sole();

    $this->put("/deals/{$deal->getKey()}/properties/order", [
        'order' => ['01J0000000000000000000000A', $link->getKey()],
    ])->assertRedirect();

    expect($link->fresh()->sort_order)->toBe(1);
});

it('refuses the same property on the deal twice', function (): void {
    $deal = dealOn(DealSide::Buy);
    $property = Property::factory()->create(['team_id' => $this->team->getKey()]);

    $this->post("/deals/{$deal->getKey()}/properties", ['property_id' => $property->getKey()])->assertRedirect();

    $this->post("/deals/{$deal->getKey()}/properties", ['property_id' => $property->getKey()])
        ->assertSessionHasErrors('property_id');
});

it('detaches on remove, and the property survives', function (): void {
    // IA §7: **Remove** detaches, **Delete** destroys.
    $deal = dealOn(DealSide::Buy);
    $other = dealOn(DealSide::Buy);
    $property = Property::factory()->create(['team_id' => $this->team->getKey()]);

    $this->post("/deals/{$deal->getKey()}/properties", ['property_id' => $property->getKey()])->assertRedirect();
    $this->post("/deals/{$other->getKey()}/properties", ['property_id' => $property->getKey()])->assertRedirect();

    $link = DealProperty::query()->where('deal_id', $deal->getKey())->sole();

    $this->delete("/deals/{$deal->getKey()}/properties/{$link->getKey()}")->assertRedirect();

    expect($property->fresh()->trashed())->toBeFalse()
        ->and(DealProperty::query()->where('deal_id', $other->getKey())->count())->toBe(1);
});

it('shows the subject first, then the agent’s ranking', function (): void {
    $deal = dealOn(DealSide::Sell);

    $properties = collect(['1 A St', '2 B St', '3 C St'])->map(function (string $street) use ($deal) {
        $property = Property::factory()->create(['team_id' => $this->team->getKey(), 'street' => $street]);

        $this->post("/deals/{$deal->getKey()}/properties", ['property_id' => $property->getKey()])->assertRedirect();

        return $property;
    });

    $links = DealProperty::query()->pluck('id', 'property_id');

    // Rank the candidates backwards; the subject stays on top regardless.
    $this->put("/deals/{$deal->getKey()}/properties/order", [
        'order' => [
            $links[$properties[2]->getKey()],
            $links[$properties[1]->getKey()],
            $links[$properties[0]->getKey()],
        ],
    ])->assertRedirect();

    $this->get("/deals/{$deal->getKey()}/properties")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('links.0.isSubject', true)
            ->where('links.0.propertyId', $properties[0]->getKey())
            ->where('links.1.propertyId', $properties[2]->getKey())
            ->where('links.2.propertyId', $properties[1]->getKey()));
});

it('lets somebody who runs deals but not properties reorder them', function (): void {
    /*
     * `DealPropertyPolicy`'s own docblock says ranking is `deals.manage`, and
     * the first version of the controller asked for `create` — which wants
     * `properties.manage` too, and refused a reorder to exactly the person the
     * policy describes.
     */
    $deal = dealOn(DealSide::Buy);
    $property = Property::factory()->create(['team_id' => $this->team->getKey()]);

    $this->post("/deals/{$deal->getKey()}/properties", ['property_id' => $property->getKey()])->assertRedirect();

    $link = DealProperty::query()->sole();

    $this->actingAsPerson(dealsOnlyMember($this->team), $this->team);

    // The control: this actor genuinely cannot link, so the reorder below can
    // only be about the ability it asks for.
    $this->post("/deals/{$deal->getKey()}/properties", [
        'property_id' => Property::factory()->create(['team_id' => $this->team->getKey()])->getKey(),
    ])->assertForbidden();

    $this->put("/deals/{$deal->getKey()}/properties/order", ['order' => [$link->getKey()]])
        ->assertRedirect();

    // And the other two abilities the docblock groups with it.
    $this->patch("/deals/{$deal->getKey()}/properties/{$link->getKey()}", [
        'interest_status' => PropertyInterest::Passed->value,
    ])->assertRedirect();
    $this->post("/deals/{$deal->getKey()}/properties/{$link->getKey()}/subject")->assertRedirect();
});

it('leaves the interest alone when a request does not mention it', function (): void {
    /*
     * Presence, not value. `interest_status: null` is an instruction — nobody
     * has said — and an absent key means leave it. Deleting that distinction
     * left all twenty tests on this screen green, which is what makes it worth
     * its own case: the next field added to this endpoint is what breaks it.
     */
    $deal = dealOn(DealSide::Buy);
    $property = Property::factory()->create(['team_id' => $this->team->getKey()]);

    $this->post("/deals/{$deal->getKey()}/properties", ['property_id' => $property->getKey()])->assertRedirect();

    $link = DealProperty::query()->sole();

    $this->patch("/deals/{$deal->getKey()}/properties/{$link->getKey()}", [
        'interest_status' => PropertyInterest::Shortlisted->value,
    ])->assertRedirect();

    // A PATCH carrying no `interest_status` at all.
    $this->patch("/deals/{$deal->getKey()}/properties/{$link->getKey()}", [])->assertRedirect();

    expect($link->fresh()->interest_status)->toBe(PropertyInterest::Shortlisted);
});

it('refuses an interest on a sell-side deal at the service, not only the request', function (): void {
    // `DemoTeamSeeder` is already the second caller of `describe()`, and it is
    // buy-side only by luck. The rule belongs where every caller meets it.
    $deal = dealOn(DealSide::Sell);
    $property = Property::factory()->create(['team_id' => $this->team->getKey()]);

    $link = app(PropertyDeals::class)->link($property, $deal);

    expect(fn () => app(PropertyDeals::class)->describe(
        $link,
        ['interest_status' => PropertyInterest::Shortlisted],
    ))->toThrow(InvalidArgumentException::class);

    // And nothing was written on the way to the refusal.
    expect($link->fresh()->interest_status)->toBeNull();
});

it('writes an interest change to the deal’s timeline', function (): void {
    // "The buyer passed on 1420 Pearl" is half of what F3.5 is for, and the
    // deal's timeline is where somebody reads back how an opinion moved.
    $deal = dealOn(DealSide::Buy);
    $property = Property::factory()->create(['team_id' => $this->team->getKey(), 'street' => '1420 Pearl St']);

    $this->post("/deals/{$deal->getKey()}/properties", ['property_id' => $property->getKey()])->assertRedirect();

    $link = DealProperty::query()->sole();

    $this->patch("/deals/{$deal->getKey()}/properties/{$link->getKey()}", [
        'interest_status' => PropertyInterest::Passed->value,
    ])->assertRedirect();

    $event = ActivityEvent::query()->where('event_type', 'property.interest_recorded')->sole();

    expect($event->summary)->toBe('1420 Pearl St: Passed')
        ->and($event->payload['from'])->toBeNull()
        ->and($event->payload['to'])->toBe('passed');
});

it('refuses a reorder that names the same link twice', function (): void {
    // The position in the list *is* the rank, so `[B, B, A]` put nothing at 0.
    $deal = dealOn(DealSide::Buy);
    $property = Property::factory()->create(['team_id' => $this->team->getKey()]);

    $this->post("/deals/{$deal->getKey()}/properties", ['property_id' => $property->getKey()])->assertRedirect();

    $link = DealProperty::query()->sole();

    $this->put("/deals/{$deal->getKey()}/properties/order", [
        'order' => [$link->getKey(), $link->getKey()],
    ])->assertSessionHasErrors('order.1');
});

it('sends a real linked-deal count with every property it renders', function (): void {
    /*
     * `PropertyDirectory::row()` reports `deal_links_count`, and a caller that
     * does not supply it ships a hard-coded 0 while the type declares a
     * number. Nothing renders it on these two surfaces yet, which is the only
     * reason no other test would catch it — and that is the dead-data shape.
     */
    $deal = dealOn(DealSide::Buy);
    $other = dealOn(DealSide::Buy);
    $property = Property::factory()->create(['team_id' => $this->team->getKey()]);

    $this->post("/deals/{$deal->getKey()}/properties", ['property_id' => $property->getKey()])->assertRedirect();
    $this->post("/deals/{$other->getKey()}/properties", ['property_id' => $property->getKey()])->assertRedirect();

    $this->get("/deals/{$deal->getKey()}/properties")
        ->assertInertia(fn ($page) => $page->where('links.0.property.dealCount', 2));

    $free = Property::factory()->create(['team_id' => $this->team->getKey()]);

    $this->getJson("/deals/{$deal->getKey()}/properties/candidates")
        ->assertJsonPath('properties.0.id', $free->getKey())
        ->assertJsonPath('properties.0.dealCount', 0);
});
