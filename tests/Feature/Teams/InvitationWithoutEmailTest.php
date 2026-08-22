<?php

declare(strict_types=1);

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

    $this->actingAsPerson($person)
        ->get('/no-team')
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

    $this->actingAsPerson($person)
        ->get('/no-team')
        ->assertInertia(fn ($page) => $page->has('invitations', 1));
});

it('accepts in the application, with no token anywhere', function (): void {
    $person = Person::factory()->create(['email' => 'heather@example.test']);

    $invitation = inviteWithoutSending($this->team, $this->memberRole, 'heather@example.test');

    app(TeamContext::class)->set(null);

    $this->actingAsPerson($person)
        ->post("/invitations/{$invitation->getKey()}/claim")
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

    $this->actingAsPerson($person)->post("/invitations/{$invitation->getKey()}/claim");

    expect($person->fresh()->password)->toBe($before);
});

it('refuses a claim on somebody else’s invitation, as a 404', function (): void {
    $person = Person::factory()->create(['email' => 'somebody@example.test']);

    $invitation = inviteWithoutSending($this->team, $this->memberRole, 'heather@example.test');

    app(TeamContext::class)->set(null);

    // A 403 would confirm the id names a live invitation, which is the one
    // thing the response must not say.
    $this->actingAsPerson($person)
        ->post("/invitations/{$invitation->getKey()}/claim")
        ->assertNotFound();

    expect(TeamMembership::withoutTeamScope()
        ->where('team_id', $this->team->getKey())
        ->where('person_id', $person->getKey())
        ->exists())->toBeFalse();
});

it('refuses a claim on an invitation that is no longer live', function (array $state): void {
    $person = Person::factory()->create(['email' => 'heather@example.test']);

    $invitation = inviteWithoutSending($this->team, $this->memberRole, 'heather@example.test', $state);

    app(TeamContext::class)->set(null);

    $this->actingAsPerson($person)
        ->post("/invitations/{$invitation->getKey()}/claim")
        ->assertNotFound();
})->with([
    'expired' => [['expires_at' => '-1 day']],
    'revoked' => [['revoked_at' => '2026-01-01 00:00:00']],
    'accepted' => [['accepted_at' => '2026-01-01 00:00:00']],
]);

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

    $this->actingAsPerson($member, $team)
        ->post("/settings/members/invitations/{$invitation->getKey()}/link")
        ->assertForbidden();
});

it('lists a team’s outstanding invitations in the platform console', function (): void {
    $administrator = Person::factory()->create(['is_super_admin' => true]);
    $this->enrollTwoFactor($administrator);

    inviteWithoutSending($this->team, $this->memberRole, 'heather@example.test');

    app(TeamContext::class)->set(null);

    $this->actingAsPerson($administrator)
        ->get("/admin/teams/{$this->team->getKey()}")
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

    $this->actingAsPerson($administrator)
        ->post("/admin/teams/{$this->team->getKey()}/invitations/{$invitation->getKey()}/link")
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
