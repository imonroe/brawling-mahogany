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

it('scopes a person’s permissions to the team they are standing in', function (): void {
    [$teamA, $member] = $this->teamWithMember();

    // A second team where they are only a Contact: same human, no access.
    $teamB = Team::factory()->create();

    app(TeamContext::class)->runFor($teamB, function () use ($teamB, $member): void {
        $membership = TeamMembership::query()->create([
            'team_id' => $teamB->getKey(),
            'person_id' => $member->getKey(),
            'status' => App\Enums\PersonLifecycleState::Active,
            'joined_at' => now(),
        ]);

        $membership->roles()->attach(
            App\Models\Role::query()->whereNull('team_id')->where('key', 'contact')->sole()->getKey(),
        );
    });

    $this->actingAsPerson($member, $teamA);

    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where(
            'auth.permissions',
            fn ($permissions) => collect($permissions)->contains('people.view'),
        ));

    $this->put('/teams/current', ['team' => $teamB->getKey()]);

    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where(
            'auth.permissions',
            fn ($permissions) => ! collect($permissions)->contains('people.view'),
        ));

    // And the screen that permission guards is refused outright.
    $this->get('/people')->assertForbidden();
});
