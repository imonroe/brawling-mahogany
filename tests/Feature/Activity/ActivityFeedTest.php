<?php

declare(strict_types=1);

use App\Enums\ActivitySource;
use App\Models\ActivityEvent;
use App\Models\Deal;
use App\Models\DealType;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Property;
use App\Models\Role;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Queries\ActivityFeed;
use App\Support\Activity\RecordActivity;
use App\Support\Permissions;
use App\Support\Tenancy\TeamContext;
use Illuminate\Database\Eloquent\Model;

/**
 * S12 — the team activity feed (PRD §4.9 F9.4 · issue #81).
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();

    $this->actingAsPerson($this->member, $this->team);
});

/**
 * One event, written the only way anything writes one — through the service
 * that owns the table, so `deal_id` and the client-visibility default are the
 * ones the product actually produces.
 */
function feedEvent(Model $subject, string $eventType, string $summary): ActivityEvent
{
    return app(RecordActivity::class)->record(
        subject: $subject,
        eventType: $eventType,
        summary: $summary,
        source: ActivitySource::System,
    );
}

/** A deal in a team, built inside that team's context. */
function feedDeal(Team $team): Deal
{
    return app(TeamContext::class)->runFor($team, fn (): Deal => Deal::factory()->create([
        'team_id' => $team->getKey(),
        'deal_type_id' => DealType::query()->whereNull('team_id')->firstOrFail()->getKey(),
    ]));
}

/**
 * Give somebody the directory and nothing else — a role a team could compose
 * today (PRD F2.3) even though neither shipped role looks like it.
 *
 * Named for what it grants rather than for what it withholds: it started as
 * "without deals" and is now the fixture for the properties rule too, and a
 * name that lists the exclusions goes stale every time one is added.
 */
function feedRoleWithDirectoryOnly(Team $team, Person $person): void
{
    app(TeamContext::class)->runFor($team, function () use ($team, $person): void {
        $membership = $person->membershipIn($team);

        expect($membership)->toBeInstanceOf(TeamMembership::class);

        $role = Role::factory()->create([
            'team_id' => $team->getKey(),
            'key' => 'directory_only',
            'name' => 'Directory Only',
        ]);

        $role->permissions()->attach(
            Permission::query()
                ->whereIn('key', [Permissions::VIEW_PEOPLE, Permissions::MANAGE_PEOPLE])
                ->pluck('id')
                ->all(),
        );

        $membership?->roles()->sync([$role->getKey()]);
    });
}

it('renders the feed with the actor named', function (): void {
    feedEvent($this->member, 'person.added', 'Added to the team directory');

    $this->get('/activity')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Activity/Index')
            ->has('events', 1)
            ->where('events.0.summary', 'Added to the team directory')
            // The name this team knows them by, not their sign-in address:
            // since #140 that is what a membership carries.
            ->where(
                'events.0.actorName',
                $this->member->membershipIn($this->team)?->fullName(),
            ));
});

/**
 * A removed member still gets the name this team typed.
 *
 * The timeline outlives the membership: the event is a record of something
 * that happened, and archiving the person does not un-happen it. Without
 * `withTrashed()` the resolver drops to `Person::email`, which is both a
 * worse sentence and a sign-in address on a screen that never needed one.
 */
it('names an actor whose membership this team has since removed', function (): void {
    // A colleague who did something and has since left.
    [, $departed] = $this->teamWithMember();

    $membership = app(TeamContext::class)->runFor($this->team, function () use ($departed): TeamMembership {
        return TeamMembership::query()->create([
            'team_id' => $this->team->getKey(),
            'person_id' => $departed->getKey(),
            'first_name' => 'Priya',
            'last_name' => 'Raman',
        ]);
    });

    $this->actingAsPerson($departed, $this->team);
    feedEvent($this->member, 'person.added', 'Added to the team directory');

    app(TeamContext::class)->runFor($this->team, fn () => $membership->delete());

    $this->actingAsPerson($this->member, $this->team);

    $this->get('/activity')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('events.0.actorName', 'Priya Raman'));
});

/**
 * Once the purge has been through, all that is left is the sign-in address.
 *
 * `withTrashed()` defers this rather than avoiding it: `records:purge`
 * hard-deletes a removed membership after thirty days, and then nothing this
 * team typed about the person survives. The event does — it is a record of
 * something that happened — so the resolver falls back the way
 * `Person::displayNameWithin()` does.
 *
 * Pinned because it is a disclosure, and one worth being deliberate about: it
 * is a colleague's work address, never a client's, because a client has no
 * login and so is never an actor. If that stops being true, this test is where
 * it fails.
 */
it('falls back to the sign-in address once the membership is really gone', function (): void {
    [, $departed] = $this->teamWithMember();

    $membership = app(TeamContext::class)->runFor($this->team, fn (): TeamMembership => TeamMembership::query()->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $departed->getKey(),
        'first_name' => 'Priya',
        'last_name' => 'Raman',
    ]));

    $this->actingAsPerson($departed, $this->team);
    feedEvent($this->member, 'person.added', 'Added to the team directory');

    // What the purge leaves behind: the event, and no membership at all.
    app(TeamContext::class)->runFor($this->team, fn () => $membership->forceDelete());

    $this->actingAsPerson($this->member, $this->team);

    $this->get('/activity')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('events.0.actorName', $departed->email));
});

/**
 * Two removals and no live row: the most recent spelling wins.
 *
 * Added, removed, added under a new surname, removed again — nothing stops it,
 * and the partial unique index only makes the *live* row single-valued. Left
 * to the order `whereIn` returns, the answer is whichever Postgres reaches
 * first.
 */
it('names a twice-removed member by the most recent record', function (): void {
    [, $departed] = $this->teamWithMember();

    $first = app(TeamContext::class)->runFor($this->team, fn (): TeamMembership => TeamMembership::query()->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $departed->getKey(),
        'first_name' => 'Priya',
        'last_name' => 'Nayar',
    ]));

    $this->actingAsPerson($departed, $this->team);
    feedEvent($this->member, 'person.added', 'Added to the team directory');

    app(TeamContext::class)->runFor($this->team, function () use ($departed, $first): void {
        $first->forceFill(['deleted_at' => now()->subYear()])->save();

        TeamMembership::query()->create([
            'team_id' => $this->team->getKey(),
            'person_id' => $departed->getKey(),
            'first_name' => 'Priya',
            'last_name' => 'Raman',
        ])->delete();
    });

    $this->actingAsPerson($this->member, $this->team);

    $this->get('/activity')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('events.0.actorName', 'Priya Raman'));
});

/**
 * Removed and re-added: the live membership is the one that names them.
 *
 * Both rows come back through `withTrashed()`, and the resolver keys by
 * person, so the order decides. The current record is what the team means by
 * that person now — an old row is a fallback for when there is no current one,
 * not a competing answer.
 */
it('prefers the live membership over one this team removed', function (): void {
    [, $rejoined] = $this->teamWithMember();

    app(TeamContext::class)->runFor($this->team, function () use ($rejoined): void {
        TeamMembership::query()->create([
            'team_id' => $this->team->getKey(),
            'person_id' => $rejoined->getKey(),
            'first_name' => 'Priya',
            'last_name' => 'Nayar',
        ])->delete();
    });

    app(TeamContext::class)->runFor($this->team, fn () => TeamMembership::query()->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $rejoined->getKey(),
        'first_name' => 'Priya',
        'last_name' => 'Raman',
    ]));

    $this->actingAsPerson($rejoined, $this->team);
    feedEvent($this->member, 'person.added', 'Added to the team directory');

    $this->actingAsPerson($this->member, $this->team);

    $this->get('/activity')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('events.0.actorName', 'Priya Raman'));
});

it('links a person subject to the record this team holds for them', function (): void {
    feedEvent($this->member, 'person.added', 'Added to the team directory');

    $membership = $this->member->membershipIn($this->team);

    $this->get('/activity')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('events.0.subject.label', $membership?->fullName())
            ->where('events.0.subject.url', route('people.show', $membership)));
});

it('filters to one category and leaves the others out', function (): void {
    feedEvent($this->member, 'contact.logged', 'Phone call');
    feedEvent($this->member, 'property.added', '14 Elm St');

    $this->get('/activity?category=contact_log')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('events', 1)
            ->where('events.0.summary', 'Phone call'));

    // The other half is still there under its own category, so the filter is
    // filtering rather than the fixture being empty.
    $this->get('/activity?category=properties')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('events', 1)
            ->where('events.0.summary', '14 Elm St'));
});

it('carries the next page on a partial reload rather than the first page again', function (): void {
    $total = ActivityFeed::PER_PAGE + 5;

    foreach (range(1, $total) as $index) {
        ActivityEvent::factory()->create([
            'team_id' => $this->team->getKey(),
            'subject_type' => (new Person)->getMorphClass(),
            'subject_id' => $this->member->getKey(),
            'summary' => 'Event '.$index,
            // A cursor keyed on `occurred_at` needs the fixture to have an
            // order for "the next page" to mean anything.
            'occurred_at' => now()->subMinutes($total - $index),
        ]);
    }

    $first = $this->get('/activity')->assertOk();

    $cursor = $first->viewData('page')['props']['nextCursor'] ?? null;

    expect($cursor)->toBeString();

    /*
     * The partial reload the Load more button issues. `X-Inertia-Partial-Data`
     * is what makes Inertia merge the rows rather than replace them, and it is
     * also what keeps this from being a whole page render.
     */
    $second = $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $first->viewData('page')['version'],
        'X-Inertia-Partial-Component' => 'Activity/Index',
        'X-Inertia-Partial-Data' => 'events,nextCursor',
    ])->getJson('/activity?cursor='.$cursor);

    $body = $second->assertOk()->json();

    expect($body['props']['events'])->toHaveCount(5)
        // The remainder, newest of what is left first — not page one again,
        // which is what a paginator handed no cursor would have returned.
        ->and($body['props']['events'][0]['summary'])->toBe('Event 5')
        ->and($body['props']['nextCursor'])->toBeNull()
        // Only the two props asked for came back.
        ->and($body['props'])->not->toHaveKey('categories');
});

it('names the deal a logged contact was attached to', function (): void {
    $deal = feedDeal($this->team);
    $membership = $this->member->membershipIn($this->team);

    $this->post("/people/{$membership?->getKey()}/contact-log", [
        'contact_type' => 'phone_call',
        'deal_id' => $deal->getKey(),
    ])->assertRedirect();

    $this->get('/activity')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('events.0.summary', 'Phone call')
            ->where('events.0.deal.label', $deal->displayName())
            ->where('events.0.contactType', 'phone_call'));
});

/**
 * And the same for a property, which is its own permission.
 *
 * `properties.view` is a separate key with its own policy — `people.view`
 * does not open a property. Without a rule of its own, a viewer holding only
 * the directory read the address in the summary, read it again as the subject
 * label, and was offered a link to the 403 they would get for following it.
 */
it('keeps property activity from somebody who cannot open a property', function (): void {
    $property = app(TeamContext::class)->runFor($this->team, fn (): Property => Property::factory()->create([
        'team_id' => $this->team->getKey(),
        'street' => '4 Confidential Way',
    ]));

    feedEvent($property, 'property.added', 'Added 4 Confidential Way');
    feedEvent($this->member, 'person.added', 'Added to the team directory');

    // Both rows for the ordinary Team Member role, which holds
    // `properties.view`.
    $this->get('/activity')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('events', 2));

    feedRoleWithDirectoryOnly($this->team, $this->member);

    $this->get('/activity')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('events', 1)
            ->where('events.0.summary', 'Added to the team directory'));
});

it('keeps deal activity from somebody who cannot open a deal', function (): void {
    $deal = feedDeal($this->team);

    feedEvent($deal, 'stage.advanced', 'Advanced past Inspection');
    feedEvent($this->member, 'person.added', 'Added to the team directory');

    // Both rows for the ordinary Team Member role, which holds `deals.view`.
    $this->get('/activity')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('events', 2));

    // A composed role (PRD F2.3) with the directory but not the deals. The
    // feed is the one screen where events about several parts of the product
    // arrive together, so the parts they cannot open have to be filtered.
    feedRoleWithDirectoryOnly($this->team, $this->member);

    $this->get('/activity')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('events', 1)
            ->where('events.0.summary', 'Added to the team directory'));
});

it('refuses somebody who cannot read the directory at all', function (): void {
    [$team, $person] = $this->teamWithMember();

    app(TeamContext::class)->runFor(
        $team,
        fn () => $person->membershipIn($team)?->roles()->detach(),
    );

    $this->actingAsPerson($person, $team);

    $this->get('/activity')->assertForbidden();
});

it('says what belongs here rather than “no results”', function (): void {
    // A team with activity, filtered to a category that has none — which is
    // the empty state somebody actually meets. An empty table would make the
    // count below pass whether or not the filter worked.
    feedEvent($this->member, 'person.added', 'Added to the team directory');

    $this->get('/activity?category=contact_log')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('events', 0)
            // IA §10: an empty state says what goes here, and the category's
            // own copy rather than one shared sentence.
            ->where('emptyMessage', fn (string $message): bool => str_contains($message, 'Log a call')));

    // The other tab is not empty, so "nothing here" is this filter's answer
    // rather than the screen's.
    $this->get('/activity?category=people')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('events', 1));
});
