<?php

declare(strict_types=1);

use App\Actions\Properties\SaveProperty;
use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Enums\SystemRole;
use App\Models\ActivityEvent;
use App\Models\Deal;
use App\Models\DealProperty;
use App\Models\ExternalLink;
use App\Models\Person;
use App\Models\Property;
use App\Models\Role;
use App\Models\TeamMembership;
use App\Queries\PropertyDirectory;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\DB;

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

it('takes a deleted property off its deals and frees the subject slot', function (): void {
    /*
     * A soft delete does not fire `teamScopedForeign()`'s cascade — that is a
     * hard-delete cascade. Without the unlink, the link row survived holding
     * `is_subject`, `deal_properties_one_subject` stayed satisfied, and the
     * deal could not acquire a replacement subject: IA §10's generated name
     * was pinned to a property nobody could see for thirty days.
     */
    $deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);
    $first = Property::factory()->create(['team_id' => $this->team->getKey()]);
    $second = Property::factory()->create(['team_id' => $this->team->getKey()]);

    $this->post("/properties/{$first->getKey()}/deals", ['deal_id' => $deal->getKey()])->assertRedirect();

    expect(DealProperty::query()->sole()->is_subject)->toBeTrue();

    $this->delete("/properties/{$first->getKey()}")->assertRedirect('/properties');

    expect(DealProperty::query()->count())->toBe(0);

    // The replacement becomes the subject, which is the whole consequence.
    $this->post("/properties/{$second->getKey()}/deals", ['deal_id' => $deal->getKey()])->assertRedirect();

    expect(DealProperty::query()->sole()->is_subject)->toBeTrue();
});

it('refuses a links payload that is not a list', function (): void {
    // `array` alone let a JSON body choose the keys, and the loop's index is
    // `sort_order` — so `"zz"` reached an `unsignedSmallInteger` as a 500.
    $this->postJson('/properties', [
        'type' => PropertyType::SingleFamily->value,
        'status' => PropertyStatus::PreListing->value,
        'links' => ['zz' => ['label' => 'One', 'url' => 'https://zillow.test/1']],
    ])->assertStatus(422)->assertJsonValidationErrors('links');

    expect(Property::query()->count())->toBe(0);
});

it('refuses the same parcel number in another case', function (): void {
    // The index is `lower(parcel_number)`; a rule comparing the column
    // directly is case-sensitive in Postgres and let this through to the
    // constraint.
    Property::factory()->create(['team_id' => $this->team->getKey(), 'parcel_number' => '12-345-67a']);

    $response = $this->post('/properties', [
        'parcel_number' => '12-345-67A',
        'type' => PropertyType::SingleFamily->value,
        'status' => PropertyStatus::PreListing->value,
    ])->assertSessionHasErrors('parcel_number');

    /*
     * The message is the assertion, not the field.
     *
     * Before the rule folded case this still produced an error on
     * `parcel_number` — the constraint caught it and `SaveProperty`'s
     * `try/catch` turned it into one, so a test asserting only the field
     * passed either way. The two layers now say different things: the rule
     * answers the ordinary duplicate, and the handler only speaks for the
     * race window. Which one answered is the whole question.
     */
    $errors = session('errors')->getBag('default')->get('parcel_number');

    expect($errors[0])->toBe('Another property already has this parcel number.');
});

it('lets a property keep its own parcel number when it is edited', function (): void {
    $property = Property::factory()->create(['team_id' => $this->team->getKey(), 'parcel_number' => '12-345-67']);

    $this->patch("/properties/{$property->getKey()}", [
        'parcel_number' => '12-345-67',
        'type' => $property->type->value,
        'status' => PropertyStatus::ForSale->value,
    ])->assertSessionHasNoErrors();
});

it('upper-cases the state code on the way in', function (): void {
    // IA §10 renders "City, ST ZIP". Normalised once here rather than in every
    // screen that shows an address.
    $this->post('/properties', [
        'street' => '1420 Pearl St',
        'city' => 'Boulder',
        'state_code' => 'co',
        'type' => PropertyType::SingleFamily->value,
        'status' => PropertyStatus::PreListing->value,
    ])->assertSessionHasNoErrors();

    expect(Property::query()->sole()->state_code)->toBe('CO');
});

it('lets a link be replaced by one carrying the same address', function (): void {
    /*
     * The UI's only way to edit a link is to drop the row and add it back, and
     * `external_links_unique_url` is partial on `deleted_at IS NULL` — so
     * saving before deleting refused the resubmission against the row it was
     * replacing.
     */
    $property = Property::factory()->create(['team_id' => $this->team->getKey()]);
    ExternalLink::factory()->attachedTo($property)->create(['label' => 'Zillow', 'url' => 'https://zillow.test/1']);

    $this->patch("/properties/{$property->getKey()}", [
        'type' => $property->type->value,
        'status' => $property->status->value,
        'links' => [['label' => 'Zillow listing', 'url' => 'https://zillow.test/1']],
    ])->assertSessionHasNoErrors();

    expect($property->externalLinks()->pluck('label')->all())->toBe(['Zillow listing']);
});

it('names a duplicate URL in the payload rather than reaching the constraint', function (): void {
    $property = Property::factory()->create(['team_id' => $this->team->getKey()]);

    $this->patch("/properties/{$property->getKey()}", [
        'type' => $property->type->value,
        'status' => $property->status->value,
        'links' => [
            ['label' => 'One', 'url' => 'https://zillow.test/1'],
            // The index folds case, so the rule has to as well.
            ['label' => 'Two', 'url' => 'https://ZILLOW.test/1'],
        ],
    ])->assertSessionHasErrors('links.1.url');

    // Same reasoning as the parcel number: the constraint would have produced
    // an error on this field too. The message says which layer answered, and
    // only the rule's is acceptable here.
    $errors = session('errors')->getBag('default')->get('links.1.url');

    expect($errors[0])->not->toContain('Somebody just added');
});

it('refuses a payload that claims one link id twice', function (): void {
    // The same instance came back for a repeated id, so the second row
    // overwrote the first: two links in, one link out, and no error.
    $property = Property::factory()->create(['team_id' => $this->team->getKey()]);
    $link = ExternalLink::factory()->attachedTo($property)->create();

    $this->patch("/properties/{$property->getKey()}", [
        'type' => $property->type->value,
        'status' => $property->status->value,
        'links' => [
            ['id' => $link->getKey(), 'label' => 'A', 'url' => 'https://a.test/1'],
            ['id' => $link->getKey(), 'label' => 'B', 'url' => 'https://b.test/1'],
        ],
    ])->assertSessionHasErrors('links.1.id');

    expect($property->externalLinks()->count())->toBe(1);
});

it('trims a link before it stores it', function (): void {
    // `SafeUrl` trims before it judges, so the stored value should be the one
    // that was judged. `TrimStrings` covers HTTP and nothing else.
    $property = Property::factory()->create(['team_id' => $this->team->getKey()]);

    app(SaveProperty::class)->update($property, [], [
        ['label' => 'Zillow', 'url' => '  https://zillow.test/1  '],
    ]);

    expect($property->externalLinks()->value('url'))->toBe('https://zillow.test/1');
});

it('does not fall over when a linked deal has been deleted', function (): void {
    // `DealProperty::deal()` is a plain `belongsTo`, so a trashed deal reads
    // as null and the screen's mapping would throw. #74 brings a deal destroy
    // route; this screen should not break the day it lands.
    $property = Property::factory()->create(['team_id' => $this->team->getKey()]);
    $deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);

    $this->post("/properties/{$property->getKey()}/deals", ['deal_id' => $deal->getKey()])->assertRedirect();

    $deal->delete();

    $this->get("/properties/{$property->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('deals', 0));
});

it('renders a property that has only a parcel number', function (): void {
    // The form allows it and `displayName()` falls back for it, so the
    // directory has to survive it — every sort key is null on this row.
    Property::factory()->withoutAddress()->create([
        'team_id' => $this->team->getKey(),
        'parcel_number' => '0512-14-002-0031',
    ]);

    $this->get('/properties')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('properties.data.0.name', 'Parcel 0512-14-002-0031')
            ->where('properties.data.0.address.street', null));
});

it('orders the directory by something unique, so a page cannot repeat a row', function (): void {
    /*
     * Asserted against the source rather than against two pages of results,
     * and the reason is worth writing down because two behavioural versions of
     * this test passed against the bug.
     *
     * `city` and `street` are both null for a parcel-number-only property, so
     * every row ties — and a tie under `LIMIT`/`OFFSET` is where a row appears
     * on two pages or on none. But `properties` carries
     * `unique(['team_id', 'id'])` and Postgres answers this query through that
     * index, which returns id order whether or not anything asked for it.
     * Rewriting half the tuples to disturb the heap did not change it either.
     * The outcome is stable here by accident of the plan, and no assertion
     * about results can fail while that accident holds.
     *
     * What can fail is the query. A plan is not a guarantee — a different
     * Postgres, a dropped index, an added filter — and the tiebreaker is what
     * makes the order the product's decision rather than the planner's. Some
     * rules are only checkable where they are written, which is the argument
     * `SingleMutationPathTest` and `UnscopedQueryConventionTest` already make.
     */
    $method = new ReflectionMethod(PropertyDirectory::class, 'paginate');

    $source = implode('', array_slice(
        (array) file((string) $method->getFileName()),
        $method->getStartLine() - 1,
        $method->getEndLine() - $method->getStartLine() + 1,
    ));

    /*
     * Offsets, not a regex over the whitespace between them.
     *
     * The first version required a literal newline, so joining the two calls
     * onto one line — valid, Pint-clean, and behaviourally identical — failed
     * it. A guard that fails on a reformat is a guard the next person deletes.
     */
    $tiebreaker = strpos($source, "->orderBy('id')");
    $paginate = strpos($source, '->paginate(');

    expect($tiebreaker)->not->toBeFalse('the directory query has no unique tiebreaker')
        ->and($paginate)->not->toBeFalse()
        ->and($tiebreaker)->toBeLessThan($paginate, 'the tiebreaker has to be part of this query');
});

it('takes a deleted property’s links with it, so the purge can reach them', function (): void {
    /*
     * `external_links` is polymorphic, so no foreign key cascades to it, and
     * `records:purge` finds a row by its `deleted_at` or not at all. A link
     * left live when its property was soft-deleted survived the purge that
     * hard-deleted the property — orphaned permanently, and past PRD §9's
     * "then hard delete".
     */
    $property = Property::factory()->create(['team_id' => $this->team->getKey()]);
    ExternalLink::factory()->attachedTo($property)->create();

    $this->delete("/properties/{$property->getKey()}")->assertRedirect('/properties');

    expect(ExternalLink::withTrashed()->count())->toBe(1)
        ->and(ExternalLink::query()->count())->toBe(0);

    // And the sweep reaches it once the window closes.
    DB::table('external_links')->update(['deleted_at' => now()->subDays(60)]);
    DB::table('properties')->update(['deleted_at' => now()->subDays(60)]);

    $this->artisan('records:purge')->assertSuccessful();

    expect(DB::table('external_links')->count())->toBe(0)
        ->and(DB::table('properties')->count())->toBe(0);
});

it('records the deletion on the property’s own timeline', function (): void {
    // Adding one writes `property.added`; removing it wrote only the unlink
    // entries, which live on the deals — so a property with no deals left the
    // directory with no trace anywhere.
    $property = Property::factory()->create(['team_id' => $this->team->getKey()]);

    $this->delete("/properties/{$property->getKey()}")->assertRedirect('/properties');

    expect(ActivityEvent::query()->where('event_type', 'property.deleted')->count())->toBe(1);
});

it('normalises a parcel number however it is written', function (): void {
    /*
     * The rule trimmed before it asked and the write did not, so a value with
     * surrounding whitespace was invisible to the rule *and* to
     * `lower(parcel_number)` — two live properties on one parcel number in one
     * team. Over HTTP `TrimStrings` hid it; the seeder and #62 do not go
     * through `TrimStrings`, which is the whole reason the normalisation lives
     * on the model.
     */
    app(SaveProperty::class)->create([
        'type' => PropertyType::SingleFamily->value,
        'status' => PropertyStatus::PreListing->value,
        'parcel_number' => '  12-345  ',
    ]);

    expect(Property::query()->sole()->parcel_number)->toBe('12-345');

    $this->post('/properties', [
        'parcel_number' => '12-345',
        'type' => PropertyType::SingleFamily->value,
        'status' => PropertyStatus::PreListing->value,
    ])->assertSessionHasErrors('parcel_number');

    expect(Property::query()->count())->toBe(1);
});

it('refuses a bath count the column cannot hold without rounding', function (): void {
    // `decimal(3, 1)` stored `2.55` as `2.6` — a value quietly becoming a
    // different value.
    $this->post('/properties', [
        'baths' => '2.55',
        'type' => PropertyType::SingleFamily->value,
        'status' => PropertyStatus::PreListing->value,
    ])->assertSessionHasErrors('baths');
});

it('names a deal after the property that is linked to it', function (): void {
    /*
     * The whole argument for `is_subject` is that IA §10 names a deal after
     * its subject property's street. Setting the flag and stopping left S36's
     * own panel rendering "Untitled deal" beside the house that had just been
     * linked to it.
     */
    $property = Property::factory()->create([
        'team_id' => $this->team->getKey(),
        'street' => '1420 Pearl St',
    ]);
    $deal = Deal::factory()->create(['team_id' => $this->team->getKey(), 'name' => null, 'generated_name' => null]);

    $this->post("/properties/{$property->getKey()}/deals", ['deal_id' => $deal->getKey()])->assertRedirect();

    expect($deal->fresh()->generated_name)->toBe('1420 Pearl St')
        ->and($deal->fresh()->displayName())->toBe('1420 Pearl St');
});

it('renames the deal when the subject property’s street is corrected', function (): void {
    /*
     * The half of #59's requirement three docblocks were quoting while nothing
     * implemented it: *"editing the name does not stop `generated_name` from
     * updating when the property changes."*
     *
     * It matters most on the screen it happens on — S37's edit dialog sits
     * beside S36's "Linked deals" panel, so a corrected street left the deal
     * next to it showing the old address. A property created from a parcel
     * number and given a street later read "Untitled deal" for good.
     */
    $property = Property::factory()->withoutAddress()->create([
        'team_id' => $this->team->getKey(),
        'parcel_number' => '0512-14-002-0031',
    ]);
    $deal = Deal::factory()->create(['team_id' => $this->team->getKey(), 'name' => null, 'generated_name' => null]);

    $this->post("/properties/{$property->getKey()}/deals", ['deal_id' => $deal->getKey()])->assertRedirect();

    // No street yet, so nothing to name it after.
    expect($deal->fresh()->displayName())->toBe('Untitled deal');

    $this->patch("/properties/{$property->getKey()}", [
        'street' => '1420 Pearl St',
        'type' => $property->type->value,
        'status' => $property->status->value,
    ])->assertRedirect();

    expect($deal->fresh()->generated_name)->toBe('1420 Pearl St');
});

it('leaves deals alone when a property that names nothing is edited', function (): void {
    // The second property on a deal is not its subject, so editing it cannot
    // change what the deal is called.
    $subject = Property::factory()->create(['team_id' => $this->team->getKey(), 'street' => '1420 Pearl St']);
    $other = Property::factory()->create(['team_id' => $this->team->getKey(), 'street' => '88 Mapleton Ave']);
    $deal = Deal::factory()->create(['team_id' => $this->team->getKey(), 'name' => null, 'generated_name' => null]);

    $this->post("/properties/{$subject->getKey()}/deals", ['deal_id' => $deal->getKey()])->assertRedirect();
    $this->post("/properties/{$other->getKey()}/deals", ['deal_id' => $deal->getKey()])->assertRedirect();

    $this->patch("/properties/{$other->getKey()}", [
        'street' => '90 Mapleton Ave',
        'type' => $other->type->value,
        'status' => $other->status->value,
    ])->assertRedirect();

    expect($deal->fresh()->generated_name)->toBe('1420 Pearl St');
});

it('looks a parcel number up the way the index compares them', function (): void {
    /*
     * The mutator governs writes; a query's `where` is not a write. So
     * `firstOrCreate(['parcel_number' => '  zz  '])` asked for the untrimmed
     * string, missed the row it had just written, and inserted a second —
     * straight into the partial unique index. `whereParcel()` is the lookup
     * that folds and trims the way the index does.
     */
    $property = Property::factory()->create([
        'team_id' => $this->team->getKey(),
        'parcel_number' => '0512-14-002-0031',
    ]);

    expect(Property::query()->whereParcel('  0512-14-002-0031  ')->first()?->getKey())
        ->toBe($property->getKey())
        ->and(Property::query()->whereParcel('0512-14-002-0031A')->exists())->toBeFalse();
});

it('leaves a typed deal name alone when a property is linked', function (): void {
    // Issue #59: "editing the name does not stop `generated_name` from
    // updating when the property changes." Two columns, and the typed one
    // wins on every screen.
    $property = Property::factory()->create([
        'team_id' => $this->team->getKey(),
        'street' => '1420 Pearl St',
    ]);
    $deal = Deal::factory()->create(['team_id' => $this->team->getKey(), 'name' => 'The Pearl job']);

    $this->post("/properties/{$property->getKey()}/deals", ['deal_id' => $deal->getKey()])->assertRedirect();

    expect($deal->fresh()->generated_name)->toBe('1420 Pearl St')
        ->and($deal->fresh()->displayName())->toBe('The Pearl job');
});

it('keeps the name a deal had when its subject property is removed', function (): void {
    // A stale name beats a list of "Untitled deal" a moment after somebody
    // tidied up a property.
    $property = Property::factory()->create([
        'team_id' => $this->team->getKey(),
        'street' => '1420 Pearl St',
    ]);
    $deal = Deal::factory()->create(['team_id' => $this->team->getKey(), 'name' => null, 'generated_name' => null]);

    $this->post("/properties/{$property->getKey()}/deals", ['deal_id' => $deal->getKey()])->assertRedirect();

    $link = DealProperty::query()->sole();

    $this->delete("/properties/{$property->getKey()}/deals/{$link->getKey()}")->assertRedirect();

    expect($deal->fresh()->generated_name)->toBe('1420 Pearl St');
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
