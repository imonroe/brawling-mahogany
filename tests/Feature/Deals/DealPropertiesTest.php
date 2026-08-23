<?php

declare(strict_types=1);

use App\Enums\DealSide;
use App\Enums\PropertyInterest;
use App\Models\Deal;
use App\Models\DealProperty;
use App\Models\DealType;
use App\Models\Property;

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
