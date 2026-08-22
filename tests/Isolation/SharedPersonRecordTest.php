<?php

declare(strict_types=1);

use App\Models\Person;
use App\Models\TeamMembership;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\Hash;

/**
 * The sharp edge of the shared-record decision (PRD decision log, 2026-08-22).
 *
 * One `people` row per human is the right model — a stager working for two
 * teams is one record with one phone number — and it puts a row that somebody
 * else's account depends on within reach of a team that merely knows them.
 * These are the tests that keep the reach short.
 */
it('refuses to let a team rewrite somebody else’s account', function (): void {
    /*
     * The attack, end to end: an attacker's own team adds a person by typing
     * the victim's address — which correctly attaches a membership to the
     * shared row — and then edits that row to point at an address they
     * control. The password is never touched, so nothing looks wrong, and the
     * next reset link goes to the attacker.
     */
    $victim = Person::factory()->create([
        'email' => 'emily@bosartgroup.test',
        'password' => Hash::make('emilys-real-password'),
    ]);

    $originalName = $victim->first_name;

    [$attackersTeam, $attacker] = $this->teamWithMember();

    $this->actingAsPerson($attacker, $attackersTeam);

    $this->post('/people', [
        'first_name' => 'Emily',
        'email' => 'emily@bosartgroup.test',
        'status' => 'lead',
    ])->assertRedirect();

    $membership = TeamMembership::query()
        ->whereHas('person', fn ($query) => $query->where('email', 'emily@bosartgroup.test'))
        ->sole();

    $this->patch("/people/{$membership->getKey()}", [
        'first_name' => 'Not',
        'last_name' => 'Emily',
        'email' => 'attacker@evil.test',
        'phone' => '0000000000',
        'status' => 'lead',
    ])->assertRedirect();

    $victim->refresh();

    expect($victim->email)->toBe('emily@bosartgroup.test')
        ->and($victim->first_name)->toBe($originalName)
        ->and(Hash::check('emilys-real-password', (string) $victim->password))->toBeTrue()
        ->and(Person::query()->where('email', 'attacker@evil.test')->exists())->toBeFalse();
});

it('refuses to let one team rewrite what another team knows', function (): void {
    // No credentials involved: two teams, one shared contact, and neither
    // gets to rename them out from under the other.
    [$firstTeam, $firstMember] = $this->teamWithMember();
    [$secondTeam, $secondMember] = $this->teamWithMember();

    $this->actingAsPerson($firstMember, $firstTeam);

    $this->post('/people', [
        'first_name' => 'Sam',
        'last_name' => 'Ferreira',
        'email' => 'sam@example.test',
        'status' => 'active',
    ]);

    $this->actingAsPerson($secondMember, $secondTeam);

    $this->post('/people', [
        'first_name' => 'Sam',
        'email' => 'sam@example.test',
        'status' => 'lead',
    ]);

    $membership = TeamMembership::query()
        ->whereHas('person', fn ($query) => $query->where('email', 'sam@example.test'))
        ->sole();

    $this->patch("/people/{$membership->getKey()}", [
        'first_name' => 'Renamed',
        'email' => 'renamed@example.test',
        'status' => 'lead',
    ])->assertRedirect();

    $person = Person::query()->whereRaw('lower(email) = ?', ['sam@example.test'])->sole();

    expect($person->first_name)->toBe('Sam')
        ->and($person->last_name)->toBe('Ferreira');

    // What *is* theirs to change is the membership: their own status and their
    // own private note.
    expect($membership->fresh()->status->value)->toBe('lead');
});

it('lets a team edit somebody only it knows', function (): void {
    // The ordinary case, which must keep working: a client typed in by one
    // team, with no login and nobody else holding them.
    [$team, $member] = $this->teamWithMember();

    $this->actingAsPerson($member, $team);

    $this->post('/people', [
        'first_name' => 'Claire',
        'email' => 'claire@example.test',
        'status' => 'lead',
    ]);

    $membership = TeamMembership::query()
        ->whereHas('person', fn ($query) => $query->where('email', 'claire@example.test'))
        ->sole();

    $this->patch("/people/{$membership->getKey()}", [
        'first_name' => 'Claire',
        'last_name' => 'Nakamura',
        'email' => 'claire.nakamura@example.test',
        'phone' => '3035550100',
        'status' => 'active',
    ])->assertRedirect();

    $person = $membership->fresh()->person;

    expect($person->last_name)->toBe('Nakamura')
        ->and($person->email)->toBe('claire.nakamura@example.test')
        ->and($person->phone)->toBe('3035550100');
});

it('does not backfill an account holder’s blanks from a stranger’s form', function (): void {
    $victim = Person::factory()->create([
        'email' => 'emily@bosartgroup.test',
        'last_name' => null,
        'phone' => null,
        'password' => Hash::make('emilys-real-password'),
    ]);

    [$team, $member] = $this->teamWithMember();

    $this->actingAsPerson($member, $team);

    $this->post('/people', [
        'first_name' => 'Emily',
        'last_name' => 'Impostor',
        'email' => 'emily@bosartgroup.test',
        'phone' => '0000000000',
        'status' => 'lead',
    ]);

    $victim->refresh();

    expect($victim->last_name)->toBeNull()
        ->and($victim->phone)->toBeNull();
});

it('does not hand a tenant to somebody the team merely knows', function (): void {
    /*
     * Being in a team's directory is not being on the team. A client with a
     * login — an account they made for another team, or one they were invited
     * to elsewhere — held a `team_memberships` row here as a Contact, and that
     * was enough to open this team's dashboard.
     */
    [$team, $member] = $this->teamWithMember();

    $client = Person::factory()->create(['password' => Hash::make('their-own-password')]);

    app(TeamContext::class)->runFor($team, function () use ($team, $client): void {
        $membership = TeamMembership::query()->create([
            'team_id' => $team->getKey(),
            'person_id' => $client->getKey(),
            'status' => App\Enums\PersonLifecycleState::Active,
        ]);

        $membership->roles()->attach(
            App\Models\Role::query()->whereNull('team_id')->where('key', 'contact')->sole()->getKey(),
        );
    });

    $this->actingAs($client);

    expect($client->activeTeams())->toHaveCount(0);

    $this->get('/dashboard')->assertRedirect(route('teams.none'));

    // Including the placeholder routes, which carry the team's branding in
    // their shared props even though they render nothing of their own.
    foreach (['/work', '/deals', '/properties', '/calendar', '/keep-in-touch', '/templates'] as $path) {
        $this->get($path)->assertRedirect(route('teams.none'));
    }

    unset($member);
});

it('still counts a membership that holds a real role', function (): void {
    // The other half: the fix must not lock out the people who belong here.
    [$team, $member] = $this->teamWithMember();

    expect($member->activeTeams()->pluck('id')->all())->toBe([$team->getKey()]);

    $this->actingAsPerson($member, $team);

    $this->get('/dashboard')->assertOk();
});
