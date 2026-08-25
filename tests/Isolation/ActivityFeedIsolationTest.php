<?php

declare(strict_types=1);

use App\Enums\ActivitySource;
use App\Enums\PersonLifecycleState;
use App\Models\ActivityEvent;
use App\Models\Deal;
use App\Models\DealType;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Queries\ActivityFeed;
use App\Support\Activity\RecordActivity;
use App\Support\Permissions;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * S12 and S26 across the tenant boundary (PRD §8.2 · ADR 0002 · issue #81).
 *
 * The feed is the widest read in the product: one screen showing every kind of
 * event a team has produced. A gap here would not leak one record, it would
 * leak the whole timeline — so it gets its own file rather than a case
 * appended to somebody else's.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    [$this->otherTeam, $this->otherMember] = $this->teamWithMember();
});

function isolationDeal(Team $team): Deal
{
    return app(TeamContext::class)->runFor($team, fn (): Deal => Deal::factory()->create([
        'team_id' => $team->getKey(),
        'deal_type_id' => DealType::query()->whereNull('team_id')->firstOrFail()->getKey(),
    ]));
}

it('never shows one team another team’s activity', function (): void {
    app(TeamContext::class)->runFor($this->otherTeam, fn () => app(RecordActivity::class)->record(
        subject: $this->otherMember,
        eventType: 'contact.logged',
        summary: 'Phone call about 4 Privet Drive',
        source: ActivitySource::Manual,
    ));

    // The row exists — otherwise this test passes on an empty table, which is
    // exactly what a broken scope would also produce.
    expect(ActivityEvent::withoutGlobalScopes()->count())->toBe(1);

    $this->actingAsPerson($this->member, $this->team);

    $this->get('/activity')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('events', 0));
});

it('refuses to attach a contact to another team’s deal', function (): void {
    $theirDeal = isolationDeal($this->otherTeam);

    $this->actingAsPerson($this->member, $this->team);

    $membership = $this->member->membershipIn($this->team);

    $this->post("/people/{$membership?->getKey()}/contact-log", [
        'contact_type' => 'phone_call',
        'deal_id' => $theirDeal->getKey(),
    ])->assertSessionHasErrors('deal_id');

    expect(ActivityEvent::withoutGlobalScopes()->count())->toBe(0);
});

it('never offers another team’s people to the shell’s modal', function (): void {
    $theirMembership = $this->otherMember->membershipIn($this->otherTeam);

    app(TeamContext::class)->runFor(
        $this->otherTeam,
        fn () => $theirMembership?->forceFill(['first_name' => 'Zebediah'])->save(),
    );

    $mine = $this->member->membershipIn($this->team);

    app(TeamContext::class)->runFor(
        $this->team,
        fn () => $mine?->forceFill(['first_name' => 'Zebediah'])->save(),
    );

    $this->actingAsPerson($this->member, $this->team);

    $response = $this->getJson('/people/candidates?q=Zebediah')->assertOk();

    /*
     * Both teams now hold a Zebediah, which is what stops this asserting on an
     * empty list. A broken endpoint returning nothing at all would pass "no
     * foreign candidates" and fail here.
     */
    expect($response->json('candidates'))->toHaveCount(1)
        ->and($response->json('candidates.0.id'))->toBe($mine?->getKey());
});

it('never leaks a deal name through the feed’s deal label', function (): void {
    // The one column #81 added, exercised the way a bug would exercise it: an
    // event in team A whose `deal_id` names a deal in team B. The composite
    // foreign key refuses the row outright, which is ADR 0002 layer 2 doing
    // the work a scope alone could not.
    $theirDeal = isolationDeal($this->otherTeam);

    app(TeamContext::class)->runFor($this->team, function () use ($theirDeal): void {
        expect(fn () => ActivityEvent::factory()->create([
            'team_id' => $this->team->getKey(),
            'subject_type' => (new Person)->getMorphClass(),
            'subject_id' => $this->member->getKey(),
            'deal_id' => $theirDeal->getKey(),
        ]))->toThrow(Illuminate\Database\QueryException::class);
    });
});

it('never shows a person-subjected event to somebody who may not see people', function (): void {
    /*
     * The **other** axis this file exists for: not the wrong team, the wrong
     * colleague.
     *
     * A person-subjected event is a contact log (F2.5) — a client's name, and
     * a free-text note about what was said. `/activity` gates on `people.view`
     * at the **screen**, and that covered it for exactly as long as the feed
     * had one caller. S10's dashboard panel reuses the same query behind a
     * `deals.view` gate, so a composed *"deals but not the client directory"*
     * role got the client's full name and the note on the screen they land on,
     * with a link to a person page that answers 403.
     *
     * `ActivityFeed::query()`'s own docblock predicted it — *"the next surface
     * the feed reaches into needs its own rule … a subject type with no rule
     * is visible to everyone who can open the feed"* — and there was a rule
     * for deals and one for properties and none for people.
     *
     * So the rule lives in the query rather than in either screen: a filter
     * written into a caller is a filter the next caller is written without,
     * which is exactly what happened.
     */
    $client = null;

    app(TeamContext::class)->runFor($this->team, function () use (&$client): void {
        $client = TeamMembership::query()->create([
            'team_id' => $this->team->getKey(),
            'person_id' => Person::factory()->create()->getKey(),
            'first_name' => 'Marguerite',
            'last_name' => 'Vanterpool',
            'status' => PersonLifecycleState::Active,
            'joined_at' => now(),
        ]);

        // Subjected to the **person**, which is what `ContactLogController`
        // does — F2.5 logs a contact against a person, and the membership is
        // how the feed resolves the name to show.
        app(RecordActivity::class)->record(
            subject: $client->person,
            eventType: 'contact.logged',
            summary: 'Discussed her budget and the second mortgage',
            source: ActivitySource::Manual,
        );
    });

    $narrow = Person::factory()->create();

    app(TeamContext::class)->runFor($this->team, function () use ($narrow): void {
        $membership = TeamMembership::query()->create([
            'team_id' => $this->team->getKey(),
            'person_id' => $narrow->getKey(),
            'first_name' => 'Dana',
            'last_name' => 'Alvarez',
            'joined_at' => now(),
        ]);

        $role = Role::factory()->create([
            'team_id' => $this->team->getKey(),
            'key' => 'deals_only',
            'name' => 'Deals Only',
        ]);

        $role->permissions()->sync(
            Permission::query()->whereIn('key', [
                Permissions::VIEW_DEALS,
                Permissions::MANAGE_DEALS,
            ])->pluck('id')->all(),
        );

        $membership->roles()->attach($role->getKey());
    });

    $this->actingAsPerson($narrow, $this->team);

    // The control: they *can* open the dashboard, so an absent name below is
    // the filter working rather than the page refusing.
    $dashboard = $this->get('/dashboard')->assertOk();

    expect($dashboard->getContent())->not->toContain('Vanterpool')
        ->and($dashboard->getContent())->not->toContain('second mortgage');

    // And the screen whose gate used to be the only thing protecting it still
    // refuses them outright, which is the behaviour that must not change.
    $this->get('/activity')->assertForbidden();
});

it('gives every subject type the feed carries a permission rule', function (): void {
    /*
     * The guard on the guard, and the reason the filter is an **allowlist**.
     *
     * It was three `!=` rules with a docblock warning that *"a subject type
     * with no rule is visible to everyone who can open the feed"* — and the
     * warning came true, twice: the person rule was missing outright, and the
     * dashboard then reused the query behind a different screen gate. An
     * exclusion list fails open, which is the lesson ADR 0002 already records
     * from the purge cascade.
     *
     * So this reads every `subject:` argument in `app/` and fails when one
     * names a class `subjectPermissions()` does not. A fifth subject type in a
     * later slice is invisible until somebody gives it a rule — and this test
     * says so at the moment it is added, rather than a reviewer noticing.
     */
    $sources = collect(File::allFiles(app_path()))
        ->filter(fn ($file): bool => $file->getExtension() === 'php')
        ->map(fn ($file): string => (string) file_get_contents($file->getPathname()));

    preg_match_all('/subject:\s*\$([A-Za-z_>\-]+)/', $sources->implode("\n"), $matches);

    $subjects = collect($matches[1])->unique()->values();

    // The scan has to be finding things: a pattern that quietly stopped
    // matching would make the assertion below pass over an empty list.
    expect($subjects->count())->toBeGreaterThanOrEqual(4);

    /*
     * `$membership->person` and `$link->deal` resolve to the same four classes
     * as the bare variables, so the check is on the tail of each expression.
     */
    /*
     * No exclusion list. `Str::afterLast` already takes the tail of
     * `$participant->deal` and `$link->deal`, so both resolve to `Deal` — an
     * earlier version carried a `reject(['Participant', 'Link'])` that could
     * never fire, which is a fail-open branch inside a fail-closed guard and
     * exactly the shape this test exists to catch one layer down.
     */
    $resolved = $subjects
        ->map(fn (string $expression): string => (string) Str::afterLast($expression, '>'))
        ->map(fn (string $name): string => Str::studly(Str::singular($name)))
        ->unique()
        ->values();

    $named = collect(ActivityFeed::subjectPermissions())
        ->keys()
        ->map(fn (string $morph): string => class_basename($morph));

    $missing = $resolved->reject(fn (string $class): bool => $named->contains($class));

    expect($missing->all())->toBe([], sprintf(
        'These subject types have no permission rule in ActivityFeed::subjectPermissions(), '
        .'so the feed would either hide them from everyone or show them to anyone: %s',
        $missing->implode(', '),
    ));
});

it('never shows a deal’s name on a client page to somebody who may not see deals', function (): void {
    /*
     * The sibling of the case above, on the screen that does **not** go
     * through `ActivityFeed::query()`.
     *
     * S31 builds its own `forSubject($person)` query with its own limit, so it
     * inherited none of the per-viewer rules — and F2.5 logs a contact against
     * a person and *optionally* a deal, so a `people.view`-only reader was
     * shown the deal the contact was attached to and a link to a page
     * answering 403.
     *
     * That is why the rules are `visibleToViewer()` rather than lines inside
     * `query()`: three callers apply them now, and the sentence this branch
     * has proved twice is that a filter written into one caller is a filter
     * the next caller is written without.
     *
     * Written because the fix shipped without it. Reverting
     * `PersonController` to its previous form left the whole suite green,
     * which is the same silence that let the original leak through.
     */
    $client = null;
    $deal = null;

    app(TeamContext::class)->runFor($this->team, function () use (&$client, &$deal): void {
        $deal = Deal::factory()->create([
            'team_id' => $this->team->getKey(),
            'name' => 'Ravenscroft Sale',
            'deal_type_id' => DealType::query()->whereNull('team_id')->firstOrFail()->getKey(),
        ]);

        $client = TeamMembership::query()->create([
            'team_id' => $this->team->getKey(),
            'person_id' => Person::factory()->create()->getKey(),
            'first_name' => 'Imogen',
            'last_name' => 'Ravenscroft',
            'status' => PersonLifecycleState::Active,
            'joined_at' => now(),
        ]);

        app(RecordActivity::class)->record(
            subject: $client->person,
            eventType: 'contact.logged',
            summary: 'Talked through the survey',
            source: ActivitySource::Manual,
            deal: $deal,
        );
    });

    $directoryOnly = Person::factory()->create();

    app(TeamContext::class)->runFor($this->team, function () use ($directoryOnly): void {
        $membership = TeamMembership::query()->create([
            'team_id' => $this->team->getKey(),
            'person_id' => $directoryOnly->getKey(),
            'first_name' => 'Rowan',
            'last_name' => 'Ellis',
            'joined_at' => now(),
        ]);

        $role = Role::factory()->create([
            'team_id' => $this->team->getKey(),
            'key' => 'directory_only_feed',
            'name' => 'Directory Only',
        ]);

        $role->permissions()->sync(
            Permission::query()->whereIn('key', [
                Permissions::VIEW_PEOPLE,
                Permissions::MANAGE_PEOPLE,
            ])->pluck('id')->all(),
        );

        $membership->roles()->attach($role->getKey());
    });

    $this->actingAsPerson($directoryOnly, $this->team);

    $response = $this->get("/people/{$client->getKey()}")->assertOk();

    $activity = $response->viewData('page')['props']['activity'];

    /*
     * The **control**, and it is what stops this passing vacuously: a full
     * viewer sees the row, so an empty list below would be the filter working
     * rather than the fixture failing to produce anything.
     */
    $this->actingAsPerson($this->member, $this->team);

    $full = $this->get("/people/{$client->getKey()}")->assertOk();

    expect($full->viewData('page')['props']['activity'])->toHaveCount(1)
        ->and($full->viewData('page')['props']['activity'][0]['deal']['label'])
        ->toBe('Ravenscroft Sale');

    // And the narrow reader gets no deal-context row at all.
    expect($activity)->toBe([]);
});
