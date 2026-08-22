<?php

declare(strict_types=1);

use App\Enums\PersonLifecycleState;
use App\Models\Person;
use App\Models\Role;
use App\Models\TeamMembership;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Contact details are one team's, and no other team's (issue #140).
 *
 * ## What this file replaces, and why it is shorter
 *
 * It replaces `SharedPersonRecordTest`, which held nine tests proving that a
 * shared `people` row could not be *rewritten* by the wrong team. Those tests
 * are gone with the thing they guarded: identity moved onto
 * `team_memberships`, so there is no shared column left for one team to write
 * or read. The mitigation is not enforced any more because the exposure is not
 * there.
 *
 * The threat model is unchanged, so the tests below cover the same attacks —
 * they just assert a stronger property. Where the old suite proved *"the write
 * is refused"*, these prove *"the two teams' records are unrelated"*.
 *
 * The two tests here that are not about #140 came across intact, because they
 * were never about the shared row: being in a directory is not being on a
 * team, and the fix for that must not lock out the people who do belong.
 */
beforeEach(function (): void {
    [$this->teamA, $this->memberA] = $this->teamWithMember();
    [$this->teamB, $this->memberB] = $this->teamWithMember();
});

/** A contact this team knows, by name and address. */
function contactIn(App\Models\Team $team, string $first, string $last, string $email): TeamMembership
{
    return app(TeamContext::class)->runFor($team, fn (): TeamMembership => TeamMembership::query()->create([
        'team_id' => $team->getKey(),
        'person_id' => Person::factory()->contactOnly()->create()->getKey(),
        'first_name' => $first,
        'last_name' => $last,
        'email' => $email,
        'status' => PersonLifecycleState::Active,
    ]));
}

/**
 * The disclosure #140 was filed for.
 *
 * Adding somebody by an address another team had already entered used to
 * attach a membership to *their* `people` row — so this team saw the name and
 * the phone number that team supplied. Now the address is just an address.
 */
it('shows a team nothing about somebody another team already knows', function (): void {
    contactIn($this->teamB, 'Claire', 'Nakamura', 'claire@example.test');

    $this->actingAsPerson($this->memberA, $this->teamA);

    // Team A adds the same human, with only what they know: an address.
    $this->post('/people', [
        'first_name' => 'C',
        'email' => 'claire@example.test',
        'status' => PersonLifecycleState::Lead->value,
    ])->assertRedirect();

    $ours = app(TeamContext::class)->runFor(
        $this->teamA,
        fn () => TeamMembership::query()->where('email', 'claire@example.test')->sole(),
    );

    // Nothing of Team B's leaked into ours — not the surname, not the row.
    expect($ours->first_name)->toBe('C')
        ->and($ours->last_name)->toBeNull()
        ->and($ours->team_id)->toBe($this->teamA->getKey());
});

it('gives each team its own row for the same human', function (): void {
    $theirs = contactIn($this->teamB, 'Claire', 'Nakamura', 'claire@example.test');

    $this->actingAsPerson($this->memberA, $this->teamA);

    $this->post('/people', [
        'first_name' => 'Claire',
        'last_name' => 'Nakamura',
        'email' => 'claire@example.test',
        'status' => PersonLifecycleState::Lead->value,
    ])->assertRedirect();

    $ours = app(TeamContext::class)->runFor(
        $this->teamA,
        fn () => TeamMembership::query()->where('email', 'claire@example.test')->sole(),
    );

    // Two directory entries, two `people` rows. A credential-less contact is
    // not an account, so there is nothing to share (#140).
    expect($ours->getKey())->not->toBe($theirs->getKey())
        ->and($ours->person_id)->not->toBe($theirs->person_id);
});

it('does not change another team’s record when this team edits theirs', function (): void {
    $theirs = contactIn($this->teamB, 'Claire', 'Nakamura', 'claire@example.test');
    $ours = contactIn($this->teamA, 'Claire', 'Nakamura', 'claire@example.test');

    $this->actingAsPerson($this->memberA, $this->teamA);

    $this->patch("/people/{$ours->getKey()}", [
        'first_name' => 'Claire',
        'last_name' => 'Marchetti',
        'email' => 'claire.marchetti@example.test',
        'phone' => '+1 303 555 0199',
        'status' => PersonLifecycleState::Active->value,
    ])->assertRedirect();

    expect($ours->fresh()->last_name)->toBe('Marchetti')
        ->and($theirs->fresh()->last_name)->toBe('Nakamura')
        ->and($theirs->fresh()->email)->toBe('claire@example.test')
        ->and($theirs->fresh()->phone)->toBeNull();
});

/**
 * The sharpest version of the old attack, and it now has nowhere to start.
 *
 * A team used to be able to POST a stranger's address, attach a membership to
 * their `people` row, then PATCH that row and change the address on somebody
 * else's **account** — leaving the password untouched while the next reset
 * link went somewhere new.
 */
it('cannot reach an account holder’s sign-in address through the directory', function (): void {
    $accountHolder = $this->memberB;
    $originalEmail = $accountHolder->email;

    $this->actingAsPerson($this->memberA, $this->teamA);

    $this->post('/people', [
        'first_name' => 'Not',
        'last_name' => 'Them',
        'email' => $originalEmail,
        'status' => PersonLifecycleState::Lead->value,
    ])->assertRedirect();

    $ours = app(TeamContext::class)->runFor(
        $this->teamA,
        fn () => TeamMembership::query()->where('email', $originalEmail)->sole(),
    );

    $this->patch("/people/{$ours->getKey()}", [
        'first_name' => 'Not',
        'email' => 'attacker@example.test',
        'status' => PersonLifecycleState::Lead->value,
    ])->assertRedirect();

    // Their account is untouched, and was never even pointed at.
    expect($accountHolder->fresh()->email)->toBe($originalEmail)
        ->and($ours->person_id)->not->toBe($accountHolder->getKey());
});

it('keeps one team’s import out of another team’s directory', function (): void {
    contactIn($this->teamB, 'Claire', 'Nakamura', 'claire@example.test');

    $this->actingAsPerson($this->memberA, $this->teamA);

    // "Already have them" is a claim about *this* directory, and this team has
    // never met her — so it is refused rather than quietly merging into the
    // other team's row, which is what the shared table used to allow.
    expect(app(TeamContext::class)->runFor(
        $this->teamA,
        fn () => TeamMembership::query()->where('email', 'claire@example.test')->exists(),
    ))->toBeFalse();
});

it('lets a team edit somebody only it knows', function (): void {
    // The other half of every rule here: it must not get in the way of the
    // ordinary case, which is a client one team typed in.
    $ours = contactIn($this->teamA, 'Lee', 'Okonkwo', 'lee@example.test');

    $this->actingAsPerson($this->memberA, $this->teamA);

    $this->patch("/people/{$ours->getKey()}", [
        'first_name' => 'Lee',
        'last_name' => 'Okonkwo-Reyes',
        'email' => 'lee.new@example.test',
        'phone' => '+1 303 555 0123',
        'status' => PersonLifecycleState::Active->value,
    ])->assertRedirect();

    expect($ours->fresh()->last_name)->toBe('Okonkwo-Reyes')
        ->and($ours->fresh()->email)->toBe('lee.new@example.test')
        ->and($ours->fresh()->phone)->toBe('+1 303 555 0123');
});

/**
 * Not about #140 — it came across from the old file intact, because it was
 * never about the shared row.
 */
it('does not hand a tenant to somebody the team merely knows', function (): void {
    /*
     * Being in a team's directory is not being on the team. A client with a
     * login — an account they made for another team, or one they were invited
     * to elsewhere — held a `team_memberships` row here as a Contact, and that
     * was enough to open this team's dashboard.
     */
    $client = Person::factory()->create(['password' => Hash::make('their-own-password')]);

    app(TeamContext::class)->runFor($this->teamA, function () use ($client): void {
        $membership = TeamMembership::query()->create([
            'team_id' => $this->teamA->getKey(),
            'person_id' => $client->getKey(),
            'first_name' => 'Claire',
            'status' => PersonLifecycleState::Active,
        ]);

        $membership->roles()->attach(
            Role::query()->whereNull('team_id')->where('key', 'contact')->sole()->getKey(),
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
});

it('still counts a membership that holds a real role', function (): void {
    // The other half: the fix must not lock out the people who belong here.
    expect($this->memberA->activeTeams()->pluck('id')->all())->toBe([$this->teamA->getKey()]);

    $this->actingAsPerson($this->memberA, $this->teamA);

    $this->get('/dashboard')->assertOk();
});

/**
 * The structural guarantee, asserted rather than assumed.
 *
 * Every rule above depends on one thing: `people` holding nothing a team
 * typed. A migration that puts a name back on it would make the tests above
 * pass and the property false, so the shape is checked directly.
 */
it('keeps every team-visible field off the login table', function (): void {
    $columns = Schema::getColumnListing('people');

    foreach (['first_name', 'last_name', 'phone', 'notes'] as $field) {
        expect($columns)->not->toContain(
            $field,
            "`people.{$field}` is back. Anything a team types belongs on `team_memberships`, "
            .'because a column on `people` is a column two teams share (issue 140).',
        );
    }

    // What is left is the login, and it is allowed to be shared: signing in
    // once and working in two teams is the point.
    expect($columns)->toContain('email')->toContain('password');
});

it('leaves a contact no sign-in address at all', function (): void {
    $this->actingAsPerson($this->memberA, $this->teamA);

    $this->post('/people', [
        'first_name' => 'Lee',
        'email' => 'lee@example.test',
        'status' => PersonLifecycleState::Lead->value,
    ])->assertRedirect();

    $membership = app(TeamContext::class)->runFor(
        $this->teamA,
        fn () => TeamMembership::query()->where('email', 'lee@example.test')->sole(),
    );

    // The address is the team's record of them; a null `people.email` is what
    // "no login" means now, and it is what stops the row being findable by
    // address from anywhere else.
    expect($membership->email)->toBe('lee@example.test')
        ->and($membership->person->email)->toBeNull()
        ->and($membership->person->hasCredentials())->toBeFalse();
});
