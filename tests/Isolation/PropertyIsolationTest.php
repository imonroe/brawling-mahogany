<?php

declare(strict_types=1);

use App\Models\Deal;
use App\Models\DealProperty;
use App\Models\ExternalLink;
use App\Models\Property;
use App\Support\Tenancy\ForeignReferenceException;
use App\Support\Tenancy\TeamContext;

/**
 * The tenant boundary around S35–S37 (issue #61 · ADR 0002).
 *
 * `properties` and `deal_properties` sit inside all five layers, so most of
 * this is confirmation. `external_links` does not, and that is why this file
 * exists: a **polymorphic** pointer has no single table for a composite key to
 * reference, so ADR 0002's second layer cannot make a cross-tenant link
 * unrepresentable. `ExternalLink`'s own guard stands there instead, and these
 * are the tests that keep it honest.
 *
 * Every refusal below is paired with the same actor succeeding on their own
 * row. A 403 or a 404 proved without that control would pass whether or not
 * the tenancy check existed — the vacuous shape earlier reviews kept finding.
 */
beforeEach(function (): void {
    [$this->teamA, $this->memberA] = $this->teamWithMember();
    [$this->teamB, $this->memberB] = $this->teamWithMember();
});

/** @return array{0: Property, 1: Deal} */
function rowsFor(App\Models\Team $team): array
{
    return app(TeamContext::class)->runFor($team, fn (): array => [
        Property::factory()->create(['team_id' => $team->getKey()]),
        Deal::factory()->create(['team_id' => $team->getKey()]),
    ]);
}

it('404s another team’s property on every route that reads or writes', function (): void {
    [$foreign] = rowsFor($this->teamB);
    [$own] = rowsFor($this->teamA);

    $this->actingAsPerson($this->memberA, $this->teamA);

    // The control: this actor can do all of this on their own row, so nothing
    // below is refused for want of a permission.
    $this->get("/properties/{$own->getKey()}")->assertOk();
    $this->patch("/properties/{$own->getKey()}", [
        'type' => $own->type->value,
        'status' => $own->status->value,
    ])->assertRedirect();

    /*
     * 404, not 403. ADR 0002 layer 3: a 403 confirms the record exists, which
     * is a disclosure in itself. The global scope makes the row unreachable,
     * so route binding never finds it.
     */
    $this->get("/properties/{$foreign->getKey()}")->assertNotFound();
    $this->patch("/properties/{$foreign->getKey()}", [
        'type' => $foreign->type->value,
        'status' => $foreign->status->value,
    ])->assertNotFound();
    $this->delete("/properties/{$foreign->getKey()}")->assertNotFound();
});

it('keeps another team’s properties out of the directory and its counts', function (): void {
    rowsFor($this->teamB);
    [$own] = rowsFor($this->teamA);

    $this->actingAsPerson($this->memberA, $this->teamA);

    $this->get('/properties')
        ->assertOk()
        ->assertInertia(function ($page) use ($own): void {
            $page->has('properties.data', 1)->where('properties.data.0.id', $own->getKey());

            // The filter bar counts through the same scope. A count that
            // dropped to the base query builder would have leaked the number
            // of houses the other team owns without showing one of them.
            $counts = collect($page->toArray()['props']['statusCounts'])->keyBy('value');

            expect($counts['all']['count'])->toBe(1);
        });
});

it('refuses a deal from another team on the link route', function (): void {
    [, $foreignDeal] = rowsFor($this->teamB);
    [$own, $ownDeal] = rowsFor($this->teamA);

    $this->actingAsPerson($this->memberA, $this->teamA);

    // The control: their own deal links.
    $this->post("/properties/{$own->getKey()}/deals", ['deal_id' => $ownDeal->getKey()])
        ->assertSessionHasNoErrors();

    /*
     * A 422 rather than a constraint violation. The composite foreign key on
     * `deal_properties` would refuse this at the database anyway — that is the
     * point of `teamScopedForeign` — but a violation is a 500, and the request
     * rule turns it into a named field.
     */
    $this->post("/properties/{$own->getKey()}/deals", ['deal_id' => $foreignDeal->getKey()])
        ->assertSessionHasErrors('deal_id');

    expect(DealProperty::withoutTeamScope()->where('deal_id', $foreignDeal->getKey())->count())->toBe(0);
});

it('404s an unlink aimed at another property’s link row', function (): void {
    // `scopeBindings()` is what makes this a 404: both rows are in the team,
    // so the global scope has no objection and the policy is asked about the
    // property. Only the nesting answers "whose property".
    [$own, $ownDeal] = rowsFor($this->teamA);

    $other = app(TeamContext::class)->runFor(
        $this->teamA,
        fn () => Property::factory()->create(['team_id' => $this->teamA->getKey()]),
    );

    $this->actingAsPerson($this->memberA, $this->teamA);

    $this->post("/properties/{$own->getKey()}/deals", ['deal_id' => $ownDeal->getKey()])->assertRedirect();

    $link = DealProperty::query()->sole();

    // The control: through its own property, it unlinks.
    $this->delete("/properties/{$other->getKey()}/deals/{$link->getKey()}")->assertNotFound();
    $this->delete("/properties/{$own->getKey()}/deals/{$link->getKey()}")->assertRedirect();
});

it('refuses an external link pointed at another team’s property', function (): void {
    [$foreign] = rowsFor($this->teamB);
    [$own] = rowsFor($this->teamA);

    app(TeamContext::class)->runFor($this->teamA, function () use ($own, $foreign): void {
        // The control: a link on their own property saves.
        $ok = new ExternalLink;
        $ok->forceFill(['linkable_type' => Property::class, 'linkable_id' => $own->getKey()]);
        $ok->fill(['label' => 'Zillow', 'url' => 'https://zillow.test/1']);
        $ok->save();

        expect($ok->exists)->toBeTrue();

        $bad = new ExternalLink;
        $bad->forceFill(['linkable_type' => Property::class, 'linkable_id' => $foreign->getKey()]);
        $bad->fill(['label' => 'Zillow', 'url' => 'https://zillow.test/2']);

        expect(fn () => $bad->save())->toThrow(ForeignReferenceException::class);
    });

    expect(ExternalLink::withoutTeamScope()->count())->toBe(1);
});

it('refuses an update that repoints a link at another team’s property', function (): void {
    // The guard runs on `updating` as well as `creating`. A create-only guard
    // is a guard somebody edits their way past.
    [$foreign] = rowsFor($this->teamB);
    [$own] = rowsFor($this->teamA);

    app(TeamContext::class)->runFor($this->teamA, function () use ($own, $foreign): void {
        $link = ExternalLink::factory()->attachedTo($own)->create();

        $link->forceFill(['linkable_id' => $foreign->getKey()]);

        expect(fn () => $link->save())->toThrow(ForeignReferenceException::class);
    });
});

it('refuses a linkable type that is not on the allowlist', function (): void {
    // `linkable_type` is a class name in a database column, and the guard has
    // to load it. An allowlist rather than "any model" is what keeps that
    // from being an arbitrary class name.
    [$team] = [$this->teamA];

    app(TeamContext::class)->runFor($team, function (): void {
        $link = new ExternalLink;
        $link->forceFill(['linkable_type' => App\Models\Person::class, 'linkable_id' => '01J0000000000000000000000A']);
        $link->fill(['label' => 'Nope', 'url' => 'https://example.test/1']);

        expect(fn () => $link->save())->toThrow(InvalidArgumentException::class);
    });
});
