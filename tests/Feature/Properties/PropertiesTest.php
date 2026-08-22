<?php

declare(strict_types=1);

use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Enums\SystemRole;
use App\Models\Deal;
use App\Models\DealProperty;
use App\Models\ExternalLink;
use App\Models\Person;
use App\Models\Property;
use App\Models\Role;
use App\Models\TeamMembership;
use App\Support\Tenancy\TeamContext;

/**
 * S35, S36, S37 — properties (issue #61 · PRD §4.3 F3.4, §7.11, §7.13, §10).
 *
 * The definition of done has three clauses, and each has a test named for it
 * below: a property shows every deal it has been on, links render and open
 * safely, and the status vocabulary is market status only.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();

    $this->actingAsPerson($this->member, $this->team);
});

/** Somebody in the team who holds no permissions at all (a Contact). */
function permissionlessMember(App\Models\Team $team): Person
{
    $person = Person::factory()->create();

    app(TeamContext::class)->runFor($team, function () use ($team, $person): void {
        $membership = TeamMembership::query()->create([
            'team_id' => $team->getKey(),
            'person_id' => $person->getKey(),
            'first_name' => 'Read',
            'last_name' => 'Only',
            'status' => App\Enums\PersonLifecycleState::Active,
            'joined_at' => now(),
        ]);

        $membership->roles()->attach(
            Role::query()->whereNull('team_id')->where('key', SystemRole::Contact->value)->sole()->getKey(),
        );
    });

    return $person;
}

it('shows an empty directory before anything is added', function (): void {
    $this->get('/properties')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Properties/Index')
            ->where('status', 'all')
            ->has('properties.data', 0));
});

it('lists properties and counts them by status', function (): void {
    Property::factory()->count(2)->create(['team_id' => $this->team->getKey()]);
    Property::factory()->withStatus(PropertyStatus::Sold)->create(['team_id' => $this->team->getKey()]);

    $this->get('/properties')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $page->has('properties.data', 3);

            $counts = collect($page->toArray()['props']['statusCounts'])->keyBy('value');

            expect($counts['all']['count'])->toBe(3)
                ->and($counts['sold']['count'])->toBe(1)
                ->and($counts['pre_listing']['count'])->toBe(2);
        });
});

it('filters by status through the query string', function (): void {
    Property::factory()->create(['team_id' => $this->team->getKey()]);
    $sold = Property::factory()->withStatus(PropertyStatus::Sold)->create(['team_id' => $this->team->getKey()]);

    $this->get('/properties?status=sold')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('status', 'sold')
            ->has('properties.data', 1)
            ->where('properties.data.0.id', $sold->getKey()));
});

it('falls back to everything when the status is not a real one', function (): void {
    Property::factory()->create(['team_id' => $this->team->getKey()]);

    // A hand-typed query string should not empty the screen.
    $this->get('/properties?status=undergoing_improvements')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('status', 'all')->has('properties.data', 1));
});

it('searches the address and the parcel number, and escapes the wildcards', function (): void {
    Property::factory()->create(['team_id' => $this->team->getKey(), 'street' => '123 Main St', 'city' => 'Denver']);
    Property::factory()->create(['team_id' => $this->team->getKey(), 'street' => '99 Elm Ave', 'city' => 'Boulder']);
    $odd = Property::factory()->create(['team_id' => $this->team->getKey(), 'street' => '100% Grade Rd', 'city' => 'Aspen']);

    $this->get('/properties?search=Main')
        ->assertInertia(fn ($page) => $page->has('properties.data', 1));

    // `%` is a literal here, not "match everything".
    $this->get('/properties?search='.urlencode('100%'))
        ->assertInertia(fn ($page) => $page
            ->has('properties.data', 1)
            ->where('properties.data.0.id', $odd->getKey()));
});

it('sends the address as parts, never as a formatted string', function (): void {
    // IA §10 fixes the format and `lib/formatters.ts` owns it. A server that
    // sent one string would put the rule in ninety-one places.
    Property::factory()->create([
        'team_id' => $this->team->getKey(),
        'street' => '123 Main St',
        'city' => 'Denver',
        'state_code' => 'CO',
        'postal_code' => '80202',
    ]);

    $this->get('/properties')
        ->assertInertia(fn ($page) => $page
            ->where('properties.data.0.address.street', '123 Main St')
            ->where('properties.data.0.address.state', 'CO')
            ->where('properties.data.0.address.postalCode', '80202'));
});

it('adds a property with its links', function (): void {
    $this->post('/properties', [
        'street' => '123 Main St',
        'city' => 'Denver',
        'state_code' => 'CO',
        'postal_code' => '80202',
        'type' => PropertyType::SingleFamily->value,
        'status' => PropertyStatus::PreListing->value,
        'links' => [
            ['label' => 'Zillow', 'url' => 'https://zillow.test/123'],
            ['label' => 'County assessor', 'url' => 'https://assessor.test/123'],
        ],
    ])->assertRedirect();

    $property = Property::query()->sole();

    expect($property->team_id)->toBe($this->team->getKey())
        ->and($property->externalLinks()->pluck('label')->all())->toBe(['Zillow', 'County assessor'])
        // Ordered as the form sent them: the first link is the one somebody
        // meant.
        ->and($property->externalLinks()->pluck('sort_order')->all())->toBe([0, 1]);
});

it('refuses a link whose scheme is not http or https', function (): void {
    // A stored `javascript:` URL is stored XSS the moment it is an `href`.
    $this->post('/properties', [
        'type' => PropertyType::SingleFamily->value,
        'status' => PropertyStatus::PreListing->value,
        'links' => [['label' => 'Innocent', 'url' => 'javascript:alert(1)']],
    ])->assertSessionHasErrors('links.0.url');

    expect(Property::query()->count())->toBe(0)
        ->and(ExternalLink::query()->count())->toBe(0);
});

it('refuses the same parcel number twice in one team, with a sentence', function (): void {
    Property::factory()->create(['team_id' => $this->team->getKey(), 'parcel_number' => '12-345-67']);

    $this->post('/properties', [
        'parcel_number' => '12-345-67',
        'type' => PropertyType::SingleFamily->value,
        'status' => PropertyStatus::PreListing->value,
    ])->assertSessionHasErrors('parcel_number');
});

it('lets another team use the same parcel number', function (): void {
    [$otherTeam, $otherMember] = $this->teamWithMember();

    app(TeamContext::class)->runFor(
        $otherTeam,
        fn () => Property::factory()->create(['team_id' => $otherTeam->getKey(), 'parcel_number' => '12-345-67']),
    );

    unset($otherMember);

    $this->post('/properties', [
        'parcel_number' => '12-345-67',
        'type' => PropertyType::SingleFamily->value,
        'status' => PropertyStatus::PreListing->value,
    ])->assertSessionHasNoErrors();
});

it('replaces the links the form sends and leaves them alone when it sends none', function (): void {
    $property = Property::factory()->create(['team_id' => $this->team->getKey()]);

    $kept = ExternalLink::factory()->attachedTo($property)->create(['label' => 'Zillow', 'url' => 'https://zillow.test/1']);
    $dropped = ExternalLink::factory()->attachedTo($property)->create(['label' => 'Old', 'url' => 'https://old.test/1']);

    $this->patch("/properties/{$property->getKey()}", [
        'type' => $property->type->value,
        'status' => $property->status->value,
        'links' => [
            ['id' => $kept->getKey(), 'label' => 'Zillow listing', 'url' => 'https://zillow.test/1'],
            ['label' => 'Tour', 'url' => 'https://tour.test/1'],
        ],
    ])->assertRedirect();

    expect($property->externalLinks()->pluck('label')->all())->toBe(['Zillow listing', 'Tour'])
        ->and($dropped->fresh()->trashed())->toBeTrue();

    // A request with no `links` key at all leaves them where they are — the
    // distinction `[]` cannot carry.
    $this->patch("/properties/{$property->getKey()}", [
        'type' => $property->type->value,
        'status' => PropertyStatus::ForSale->value,
    ])->assertRedirect();

    expect($property->externalLinks()->count())->toBe(2);
});

it('removes every link when the form sends an empty list', function (): void {
    $property = Property::factory()->create(['team_id' => $this->team->getKey()]);
    ExternalLink::factory()->attachedTo($property)->create();

    $this->patch("/properties/{$property->getKey()}", [
        'type' => $property->type->value,
        'status' => $property->status->value,
        'links' => [],
    ])->assertRedirect();

    expect($property->externalLinks()->count())->toBe(0);
});

it('shows every deal a property has been on', function (): void {
    // The definition of done, in one case: *"a property can be linked to more
    // than one deal over time and shows both."*
    $property = Property::factory()->create(['team_id' => $this->team->getKey()]);

    $first = Deal::factory()->create(['team_id' => $this->team->getKey()]);
    $second = Deal::factory()->create(['team_id' => $this->team->getKey()]);

    $this->post("/properties/{$property->getKey()}/deals", ['deal_id' => $first->getKey()])->assertRedirect();
    $this->post("/properties/{$property->getKey()}/deals", ['deal_id' => $second->getKey()])->assertRedirect();

    $this->get("/properties/{$property->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Properties/Show')
            ->has('deals', 2)
            // Each deal's first property is its subject; neither borrows the
            // other's.
            ->where('deals.0.isSubject', true)
            ->where('deals.1.isSubject', true));
});

it('makes the first property on a deal its subject and leaves the second alone', function (): void {
    $deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);
    $first = Property::factory()->create(['team_id' => $this->team->getKey()]);
    $second = Property::factory()->create(['team_id' => $this->team->getKey()]);

    $this->post("/properties/{$first->getKey()}/deals", ['deal_id' => $deal->getKey()])->assertRedirect();
    $this->post("/properties/{$second->getKey()}/deals", ['deal_id' => $deal->getKey()])->assertRedirect();

    expect(DealProperty::query()->where('property_id', $first->getKey())->sole()->is_subject)->toBeTrue()
        ->and(DealProperty::query()->where('property_id', $second->getKey())->sole()->is_subject)->toBeFalse();
});

it('refuses the same property on the same deal twice', function (): void {
    $property = Property::factory()->create(['team_id' => $this->team->getKey()]);
    $deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);

    $this->post("/properties/{$property->getKey()}/deals", ['deal_id' => $deal->getKey()])->assertRedirect();

    $this->post("/properties/{$property->getKey()}/deals", ['deal_id' => $deal->getKey()])
        ->assertSessionHasErrors('deal_id');
});

it('lets a property be re-linked after it was removed', function (): void {
    // The unique index is partial on `deleted_at`, so removing has to free the
    // pairing again — a property taken off by mistake must be able to go back.
    $property = Property::factory()->create(['team_id' => $this->team->getKey()]);
    $deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);

    $this->post("/properties/{$property->getKey()}/deals", ['deal_id' => $deal->getKey()])->assertRedirect();

    $link = DealProperty::query()->sole();

    $this->delete("/properties/{$property->getKey()}/deals/{$link->getKey()}")->assertRedirect();

    $this->post("/properties/{$property->getKey()}/deals", ['deal_id' => $deal->getKey()])
        ->assertSessionHasNoErrors();
});

it('offers only deals this property is not already on', function (): void {
    $property = Property::factory()->create(['team_id' => $this->team->getKey()]);
    $linked = Deal::factory()->create(['team_id' => $this->team->getKey()]);
    $free = Deal::factory()->create(['team_id' => $this->team->getKey()]);

    $this->post("/properties/{$property->getKey()}/deals", ['deal_id' => $linked->getKey()])->assertRedirect();

    $this->getJson("/properties/{$property->getKey()}/deals/candidates")
        ->assertOk()
        ->assertJsonCount(1, 'deals')
        ->assertJsonPath('deals.0.id', $free->getKey());
});

it('deletes a property softly, so the 30-day window applies', function (): void {
    $property = Property::factory()->create(['team_id' => $this->team->getKey()]);

    $this->delete("/properties/{$property->getKey()}")->assertRedirect('/properties');

    expect($property->fresh()->trashed())->toBeTrue();
});

it('refuses somebody with no permissions, on read and on write', function (): void {
    $property = Property::factory()->create(['team_id' => $this->team->getKey()]);

    $this->actingAsPerson(permissionlessMember($this->team), $this->team);

    $this->get('/properties')->assertForbidden();
    $this->get("/properties/{$property->getKey()}")->assertForbidden();
    $this->post('/properties', [
        'type' => PropertyType::SingleFamily->value,
        'status' => PropertyStatus::PreListing->value,
    ])->assertForbidden();
});

it('carries market status only — no workflow positions', function (): void {
    // PRD §7.11: "Undergoing improvements" and "Staged" describe where the
    // work is, not where the market is, and belong to a stage.
    expect(PropertyStatus::values())->not->toContain('undergoing_improvements')
        ->and(PropertyStatus::values())->not->toContain('staged');
});
