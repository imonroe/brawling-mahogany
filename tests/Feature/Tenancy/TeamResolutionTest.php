<?php

declare(strict_types=1);

use App\Http\Middleware\ResolveCurrentTeam;
use App\Models\Person;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Tenancy\TeamContext;

/**
 * ADR 0002, layer 3: how the current team is resolved, and what happens when
 * it cannot be.
 */
it('sends a person with no membership to the no-team screen', function (): void {
    // Not an error page: somebody whose access was revoked lands somewhere
    // that explains itself rather than on a page of empty lists.
    $this->actingAs(Person::factory()->create());

    $this->get('/dashboard')->assertRedirect(route('teams.none'));
    $this->get('/no-team')->assertOk();
});

/**
 * Issue #156: the team must be resolved *before* route model binding.
 *
 * `SubstituteBindings` sits in Laravel's middleware priority list; the
 * tenancy middleware, appended to the web group, did not — so the binding ran
 * first, queried a team-scoped table with nothing established, and the global
 * scope threw `MissingTeamContextException`. Every screen that binds a
 * team-scoped model answered 500, while the index beside it, binding nothing,
 * was fine.
 *
 * These three tests deliberately do **not** use `actingAsPerson()`: that
 * helper sets the context in the container before the request is made, which
 * is what hid the fault from the whole suite. A browser arrives carrying a
 * session and nothing else, so that is all these give it.
 */
it('resolves the team before a route binds a team-scoped model', function (): void {
    [$team, $member] = $this->teamWithMember();

    $membership = app(TeamContext::class)->runFor(
        $team,
        fn (): TeamMembership => TeamMembership::query()
            ->where('person_id', $member->getKey())
            ->sole(),
    );

    $this->actingAs($member);
    $this->withSession([ResolveCurrentTeam::SESSION_KEY => $team->getKey()]);

    // The state a real request starts in: the session knows the team, the
    // container does not, and the middleware is the only thing that can say.
    app(TeamContext::class)->set(null);

    $this->get("/people/{$membership->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('membership.id', $membership->getKey()));
});

it('binds the record a redirect lands on, straight after creating it', function (): void {
    // The reporter's second symptom, and the same fault underneath: adding a
    // property redirects to `properties.show`, which binds `{property}`.
    [$team, $member] = $this->teamWithMember();

    $this->actingAs($member);
    $this->withSession([ResolveCurrentTeam::SESSION_KEY => $team->getKey()]);
    app(TeamContext::class)->set(null);

    $created = $this->post('/properties', [
        'street' => '12 Alder Way',
        'city' => 'Denver',
        'state_code' => 'CO',
        'postal_code' => '80202',
        'type' => 'single_family',
        'status' => 'pre_listing',
    ]);

    $created->assertRedirect();

    app(TeamContext::class)->set(null);

    $this->get((string) $created->headers->get('Location'))->assertOk();
});

it('refuses rather than throws when a bound route is reached with no team', function (): void {
    // The other half of the ordering: `EnsureTeamContext` has to run ahead of
    // the binding as well, or somebody with no live membership meets a 500
    // from the global scope instead of the "no team" screen (S09).
    [$team, $member] = $this->teamWithMember();

    $membership = app(TeamContext::class)->runFor(
        $team,
        fn (): TeamMembership => TeamMembership::query()
            ->where('person_id', $member->getKey())
            ->sole(),
    );

    $this->actingAs(Person::factory()->create());
    app(TeamContext::class)->set(null);

    $this->get("/people/{$membership->getKey()}")->assertRedirect(route('teams.none'));
});

it('remembers the team across requests', function (): void {
    [$team, $member] = $this->teamWithMember();

    $this->actingAs($member);

    $this->get('/dashboard')
        ->assertOk()
        ->assertSessionHas(ResolveCurrentTeam::SESSION_KEY, $team->getKey());
});

it('falls back rather than stranding somebody on a revoked team', function (): void {
    [$teamA, $member] = $this->teamWithMember();
    [$teamB] = $this->teamWithMember($member);

    $this->actingAs($member);
    $this->withSession([ResolveCurrentTeam::SESSION_KEY => $teamA->getKey()]);

    app(TeamContext::class)->runFor($teamA, function () use ($member, $teamA): void {
        TeamMembership::query()
            ->where('team_id', $teamA->getKey())
            ->where('person_id', $member->getKey())
            ->sole()
            ->revoke();
    });

    // The remembered team is gone; the other one is still theirs.
    $this->get('/dashboard')
        ->assertOk()
        ->assertSessionHas(ResolveCurrentTeam::SESSION_KEY, $teamB->getKey());
});

it('keeps a suspended team out of the switcher', function (): void {
    [$team, $member] = $this->teamWithMember();

    $team->forceFill(['suspended_at' => now()])->save();

    $this->actingAs($member);

    $this->get('/dashboard')->assertRedirect(route('teams.none'));
});

it('hides the switcher when somebody belongs to one team', function (): void {
    [$team, $member] = $this->teamWithMember();

    $this->actingAsPerson($member, $team);

    // S09: "single team (hidden entirely)". The prop is what the component
    // reads, so asserting on it is asserting on the behaviour.
    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('teams', 1));
});

it('offers every live team when somebody belongs to two', function (): void {
    [, $member] = $this->teamWithMember();
    $this->teamWithMember($member);

    $this->actingAs($member);

    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('teams', 2));
});

it('shares the team’s timezone so every rendered time uses it', function (): void {
    [$team, $member] = $this->teamWithMember();

    $team->forceFill(['timezone' => 'America/Denver'])->save();

    $this->actingAsPerson($member, $team);

    // PRD §9: storage is UTC, display is the team's zone. The front end reads
    // this prop once at boot rather than each screen guessing.
    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('team.timezone', 'America/Denver'));
});

it('refuses to stand in a team that only knows you', function (): void {
    // Being in a team's directory is not being on the team. A client is a
    // Contact — no permissions — and a Contact has no tenant to stand in,
    // however many memberships they hold.
    [$teamA, $member] = $this->teamWithMember();

    $teamB = Team::factory()->create();

    app(TeamContext::class)->runFor($teamB, function () use ($teamB, $member): void {
        $membership = TeamMembership::query()->create([
            'team_id' => $teamB->getKey(),
            'person_id' => $member->getKey(),
            // The second team's own record of them (#140).
            'first_name' => 'Known here too',
            'status' => App\Enums\PersonLifecycleState::Active,
            'joined_at' => now(),
        ]);

        $membership->roles()->attach(
            App\Models\Role::query()->whereNull('team_id')->where('key', 'contact')->sole()->getKey(),
        );
    });

    $this->actingAsPerson($member, $teamA);

    expect($member->activeTeams()->pluck('id')->all())->toBe([$teamA->getKey()]);

    $this->put('/teams/current', ['team' => $teamB->getKey()])->assertNotFound();
});

it('scopes a person’s permissions to the team they are standing in', function (): void {
    // The same human, two teams, two different sets of what they may do —
    // which is the point of assigning roles per team (PRD F2.2).
    [$teamA, $member] = $this->teamWithMember();

    $teamB = Team::factory()->create();

    app(TeamContext::class)->runFor($teamB, function () use ($teamB, $member): void {
        // A team's own composed role (PRD F2.3), holding one permission that
        // is not the one being asserted on.
        $role = App\Models\Role::query()->create([
            'team_id' => $teamB->getKey(),
            'key' => 'calendar_only',
            'name' => 'Calendar Only',
        ]);

        $role->permissions()->attach(
            App\Models\Permission::query()->where('key', App\Support\Permissions::VIEW_CALENDAR)->sole()->getKey(),
        );

        $membership = TeamMembership::query()->create([
            'team_id' => $teamB->getKey(),
            'person_id' => $member->getKey(),
            // The second team's own record of them (#140).
            'first_name' => 'Known here too',
            'status' => App\Enums\PersonLifecycleState::Active,
            'joined_at' => now(),
        ]);

        $membership->roles()->attach($role->getKey());
    });

    $this->actingAsPerson($member, $teamA);

    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where(
            'auth.permissions',
            fn ($permissions) => collect($permissions)->contains('people.view'),
        ));

    $this->put('/teams/current', ['team' => $teamB->getKey()])->assertRedirect('/dashboard');

    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where(
            'auth.permissions',
            fn ($permissions) => collect($permissions)->contains('calendar.view')
                && ! collect($permissions)->contains('people.view'),
        ));

    // And the screen that permission guards is refused outright.
    $this->get('/people')->assertForbidden();
});
