<?php

declare(strict_types=1);

use App\Enums\PersonLifecycleState;
use App\Enums\SystemRole;
use App\Models\AuditEntry;
use App\Models\Person;
use App\Models\Role;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamMembership;
use App\Support\Tenancy\TeamContext;

/**
 * ADR 0003 — answering an invitation without the email.
 *
 * The failure this suite exists for is not hypothetical. A fresh install has
 * no mail transport, so the platform administrator provisions a team, invites
 * its first owner, and the invitation is unreachable from anywhere in the
 * product: no screen shows it, and only its hash is stored, so nothing can
 * recover the link either. Every case below is a door that has to open while
 * the message stays undelivered.
 */
beforeEach(function (): void {
    [$this->team, $this->owner] = $this->teamWithOwner();

    $this->memberRole = Role::query()
        ->whereNull('team_id')
        ->where('key', SystemRole::TeamMember->value)
        ->sole();
});

/**
 * An unanswered invitation, written directly rather than through the action,
 * so a case can pin the state it is about.
 *
 * @param  array<string, mixed>  $attributes
 */
function inviteWithoutSending(Team $team, Role $role, string $email, array $attributes = []): TeamInvitation
{
    return app(TeamContext::class)->runFor($team, fn (): TeamInvitation => TeamInvitation::factory()->create([
        'team_id' => $team->getKey(),
        'email' => $email,
        'role_id' => $role->getKey(),
        'token_hash' => TeamInvitation::hashToken(TeamInvitation::newToken()),
        'expires_at' => now()->addDays(TeamInvitation::LIFETIME_DAYS),
        ...$attributes,
    ]));
}

it('shows a signed-in person the invitation waiting for their address', function (): void {
    $person = Person::factory()->create(['email' => 'heather@example.test']);

    inviteWithoutSending($this->team, $this->memberRole, 'heather@example.test');

    app(TeamContext::class)->set(null);

    $this->actingAsPerson($person);

    $this->get('/no-team')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Teams/None')
            ->has('invitations', 1)
            ->where('invitations.0.teamName', $this->team->name));
});

it('matches the address case-insensitively, like every other lookup', function (): void {
    $person = Person::factory()->create(['email' => 'heather@example.test']);

    inviteWithoutSending($this->team, $this->memberRole, 'Heather@Example.test');

    app(TeamContext::class)->set(null);

    $this->actingAsPerson($person);

    $this->get('/no-team')
        ->assertInertia(fn ($page) => $page->has('invitations', 1));
});

it('accepts in the application, with no token anywhere', function (): void {
    $person = Person::factory()->create(['email' => 'heather@example.test']);

    $invitation = inviteWithoutSending($this->team, $this->memberRole, 'heather@example.test');

    app(TeamContext::class)->set(null);

    $this->actingAsPerson($person);

    $this->post("/invitations/{$invitation->getKey()}/claim")
        ->assertRedirect(route('dashboard'));

    $membership = TeamMembership::withoutTeamScope()
        ->where('team_id', $this->team->getKey())
        ->where('person_id', $person->getKey())
        ->first();

    expect($membership)->not->toBeNull()
        ->and($membership->roles->pluck('key')->all())->toContain(SystemRole::TeamMember->value)
        ->and($invitation->fresh()->isAccepted())->toBeTrue();
});

it('never lets a claim touch credentials', function (): void {
    /*
     * The property that makes a tokenless accept safe to offer at all: it can
     * add a membership and nothing else. If this stops being true, an id
     * becomes a password-setting mechanism.
     */
    $person = Person::factory()->create(['email' => 'heather@example.test']);
    $before = $person->password;

    $invitation = inviteWithoutSending($this->team, $this->memberRole, 'heather@example.test');

    app(TeamContext::class)->set(null);

    $this->actingAsPerson($person);

    $this->post("/invitations/{$invitation->getKey()}/claim");

    expect($person->fresh()->password)->toBe($before);
});

it('refuses a claim on somebody else’s invitation, as a 404', function (): void {
    $person = Person::factory()->create(['email' => 'somebody@example.test']);

    $invitation = inviteWithoutSending($this->team, $this->memberRole, 'heather@example.test');

    app(TeamContext::class)->set(null);

    // A 403 would confirm the id names a live invitation, which is the one
    // thing the response must not say.
    $this->actingAsPerson($person);

    $this->post("/invitations/{$invitation->getKey()}/claim")->assertNotFound();

    expect(TeamMembership::withoutTeamScope()
        ->where('team_id', $this->team->getKey())
        ->where('person_id', $person->getKey())
        ->exists())->toBeFalse();
});

it('refuses a claim on an invitation that is no longer live', function (array $state): void {
    $person = Person::factory()->create(['email' => 'heather@example.test']);

    $invitation = inviteWithoutSending($this->team, $this->memberRole, 'heather@example.test', $state);

    app(TeamContext::class)->set(null);

    $this->actingAsPerson($person);

    $this->post("/invitations/{$invitation->getKey()}/claim")->assertNotFound();
})->with([
    'expired' => [['expires_at' => '-1 day']],
    'revoked' => [['revoked_at' => '2026-01-01 00:00:00']],
    'accepted' => [['accepted_at' => '2026-01-01 00:00:00']],
]);

it('accepts while another team is already resolved', function (): void {
    /*
     * The case the shell banner exists for, and the one every other claim
     * test here missed: somebody who is *already* on a team, claiming an
     * invitation to a different one.
     *
     * `ResolveCurrentTeam` is global middleware, so their own team is
     * resolved on this request. Spending the invitation outside
     * `runFor($invitation->team)` therefore tripped `BelongsToTeam`'s
     * `updating` guard — a 500, and a rolled-back transaction, on the one
     * population the banner was built to reach. Every case above sets the
     * context to null and claims as somebody with no membership anywhere,
     * which is exactly why they all passed.
     */
    [$otherTeam, $member] = $this->teamWithMember();

    $invitation = inviteWithoutSending($this->team, $this->memberRole, (string) $member->email);

    $this->actingAsPerson($member, $otherTeam);

    $this->post("/invitations/{$invitation->getKey()}/claim")
        ->assertRedirect(route('dashboard'));

    expect($invitation->fresh()->isAccepted())->toBeTrue()
        ->and(TeamMembership::withoutTeamScope()
            ->where('team_id', $this->team->getKey())
            ->where('person_id', $member->getKey())
            ->whereNull('revoked_at')
            ->exists())->toBeTrue()
        // And the team they were already in is untouched.
        ->and(TeamMembership::withoutTeamScope()
            ->where('team_id', $otherTeam->getKey())
            ->where('person_id', $member->getKey())
            ->whereNull('revoked_at')
            ->exists())->toBeTrue();
});

it('keeps the name the team recorded when nobody typed a new one', function (): void {
    /*
     * A claim types nothing, so the name it carries is the invitation's or
     * the address before the @ — neither chosen by anybody. Letting that win
     * turned "Heather Cole" into "heather Cole" on the ordinary
     * revoke-then-re-invite path, silently, on the one field #140 moved onto
     * the membership so that the team would own it.
     */
    $person = Person::factory()->create(['email' => 'heather@example.test']);

    $membership = app(TeamContext::class)->runFor($this->team, function () use ($person): TeamMembership {
        $row = TeamMembership::query()->create([
            'team_id' => $this->team->getKey(),
            'person_id' => $person->getKey(),
            'first_name' => 'Heather',
            'last_name' => 'Cole',
            'email' => 'heather@example.test',
            'status' => PersonLifecycleState::Active,
            'joined_at' => now(),
        ]);

        $row->forceFill(['revoked_at' => now()])->save();

        return $row;
    });

    // Re-invited with no name filled in, which is the common case.
    $invitation = inviteWithoutSending(
        $this->team,
        $this->memberRole,
        'heather@example.test',
        ['first_name' => null, 'last_name' => null],
    );

    app(TeamContext::class)->set(null);

    $this->actingAsPerson($person);

    $this->post("/invitations/{$invitation->getKey()}/claim")->assertRedirect(route('dashboard'));

    $membership->refresh();

    expect($membership->first_name)->toBe('Heather')
        ->and($membership->last_name)->toBe('Cole')
        ->and($membership->revoked_at)->toBeNull();
});

it('still lets the invitee name themselves on the emailed path', function (): void {
    // The other half of the rule above: a typed name is authoritative and
    // must keep overwriting whatever the team had.
    $token = TeamInvitation::newToken();

    inviteWithoutSending($this->team, $this->memberRole, 'heather@example.test', [
        'token_hash' => TeamInvitation::hashToken($token),
    ]);

    app(TeamContext::class)->set(null);

    $this->post("/invitations/{$token}", [
        'first_name' => 'Heather',
        'last_name' => 'Quinn',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
    ])->assertRedirect(route('dashboard'));

    $person = Person::query()->where('email', 'heather@example.test')->sole();

    expect($person->membershipIn($this->team)->first_name)->toBe('Heather')
        ->and($person->membershipIn($this->team)->last_name)->toBe('Quinn');
});

it('offers nothing, and accepts nothing, inside an impersonated session', function (): void {
    /*
     * A support session exists so an administrator can see what the customer
     * sees. Joining another team on their behalf is not seeing — and the
     * audit entry would carry the customer's name, not the administrator's.
     */
    [$otherTeam, $member] = $this->teamWithMember();

    $invitation = inviteWithoutSending($this->team, $this->memberRole, (string) $member->email);

    app(TeamContext::class)->set(null);

    // The control, taken first: signed in as themselves, they are offered it.
    // Without this the assertions below pass whether or not the suppression
    // is what silenced the banner.
    $this->actingAsPerson($member);

    $this->get('/no-team')->assertInertia(fn ($page) => $page->has('invitations', 1));

    auth()->logout();
    app(TeamContext::class)->set(null);

    $administrator = Person::factory()->create(['is_super_admin' => true]);
    $this->enrollTwoFactor($administrator);

    $this->actingAsPerson($administrator);

    $this->post("/admin/teams/{$otherTeam->getKey()}/impersonate", [
        'person_id' => $member->getKey(),
        'reason' => 'Investigating a support ticket about their dashboard.',
        'minutes' => 30,
    ])->assertRedirect(route('dashboard'));

    /*
     * Assert the session actually started before asserting what it cannot do.
     * Without this the test passes whether or not impersonation began — the
     * administrator's own address matches no invitation either way — which
     * would make every assertion below vacuous.
     */
    $this->assertAuthenticatedAs($member);

    $this->get('/no-team')->assertInertia(fn ($page) => $page
        ->where('auth.impersonating.name', fn ($name) => $name !== null)
        ->has('invitations', 0));

    $this->post("/invitations/{$invitation->getKey()}/claim")->assertNotFound();

    expect($invitation->fresh()->isAccepted())->toBeFalse();
});

it('requires a session — an id is not a credential', function (): void {
    $invitation = inviteWithoutSending($this->team, $this->memberRole, 'heather@example.test');

    app(TeamContext::class)->set(null);

    $this->post("/invitations/{$invitation->getKey()}/claim")->assertRedirect(route('login'));
});

it('hands the team owner a working link from the members screen', function (): void {
    $this->enrollTwoFactor($this->owner);
    $this->actingAsPerson($this->owner, $this->team);

    $invitation = inviteWithoutSending($this->team, $this->memberRole, 'heather@example.test');
    $originalHash = $invitation->token_hash;

    $this->post("/settings/members/invitations/{$invitation->getKey()}/link")
        ->assertRedirect(route('members.index'));

    $url = session('invitationLink')['url'];

    expect($url)->toBeString()
        // Rotated, because only the hash is stored and there is nothing to
        // read back. The screens say so out loud for the same reason.
        ->and($invitation->fresh()->token_hash)->not->toBe($originalHash);

    auth()->logout();
    app(TeamContext::class)->set(null);

    // The link is the real thing, not a decoration.
    $this->get($url)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/AcceptInvitation')->where('state', 'pending'));
});

it('audits every link it issues', function (): void {
    $this->enrollTwoFactor($this->owner);
    $this->actingAsPerson($this->owner, $this->team);

    $invitation = inviteWithoutSending($this->team, $this->memberRole, 'heather@example.test');

    $this->post("/settings/members/invitations/{$invitation->getKey()}/link");

    expect(AuditEntry::query()
        ->where('action', 'invitation.link_issued')
        ->where('auditable_id', $invitation->getKey())
        ->where('actor_person_id', $this->owner->getKey())
        ->exists())->toBeTrue();
});

it('refuses to issue a link to somebody who cannot manage members', function (): void {
    [$team, $member] = $this->teamWithMember();

    $invitation = inviteWithoutSending($team, $this->memberRole, 'heather@example.test');

    $this->actingAsPerson($member, $team);

    $this->post("/settings/members/invitations/{$invitation->getKey()}/link")
        ->assertForbidden();
});

it('lists a team’s outstanding invitations in the platform console', function (): void {
    $administrator = Person::factory()->create(['is_super_admin' => true]);
    $this->enrollTwoFactor($administrator);

    inviteWithoutSending($this->team, $this->memberRole, 'heather@example.test');

    app(TeamContext::class)->set(null);

    $this->actingAsPerson($administrator);

    $this->get("/admin/teams/{$this->team->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Teams/Show')
            ->has('invitations', 1)
            ->where('invitations.0.email', 'heather@example.test'));
});

it('issues the first owner’s link from the platform console', function (): void {
    /*
     * PRD §5.1 step 1 on an install where no mail transport exists. Without
     * this there is no path at all from "team provisioned" to "somebody can
     * sign in", which is where every local environment starts.
     */
    $administrator = Person::factory()->create(['is_super_admin' => true]);
    $this->enrollTwoFactor($administrator);

    $invitation = inviteWithoutSending($this->team, $this->memberRole, 'heather@example.test');

    app(TeamContext::class)->set(null);

    $this->actingAsPerson($administrator);

    $this->post("/admin/teams/{$this->team->getKey()}/invitations/{$invitation->getKey()}/link")
        ->assertRedirect(route('admin.teams.show', ['team' => $this->team->getKey()]));

    $url = session('invitationLink')['url'];

    auth()->logout();

    $this->get($url)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('state', 'pending'));
});

it('prints a working link from the console, with no session at all', function (): void {
    inviteWithoutSending($this->team, $this->memberRole, 'heather@example.test');

    app(TeamContext::class)->set(null);

    $this->artisan('invitation:link', ['email' => 'heather@example.test'])
        ->assertSuccessful();

    // The command prints the URL; the audit entry is what proves it minted a
    // real one, and PRD §9 requires it either way.
    expect(AuditEntry::query()
        ->where('action', 'invitation.link_issued')
        ->where('actor_person_id', null)
        ->exists())->toBeTrue();
});

it('will not name a team for an address invited to two of them', function (): void {
    [$other] = $this->teamWithOwner();

    inviteWithoutSending($this->team, $this->memberRole, 'heather@example.test');
    inviteWithoutSending($other, $this->memberRole, 'heather@example.test');

    app(TeamContext::class)->set(null);

    $this->artisan('invitation:link', ['email' => 'heather@example.test'])->assertFailed();

    $this->artisan('invitation:link', [
        'email' => 'heather@example.test',
        '--team' => $this->team->slug,
    ])->assertSuccessful();
});

it('fails plainly when no invitation is outstanding', function (): void {
    app(TeamContext::class)->set(null);

    $this->artisan('invitation:link', ['email' => 'nobody@example.test'])->assertFailed();
});
