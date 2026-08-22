<?php

declare(strict_types=1);

use App\Actions\Teams\InvitePersonToTeam;
use App\Enums\SystemRole;
use App\Mail\TeamInvitationMail;
use App\Models\Person;
use App\Models\Role;
use App\Models\TeamInvitation;
use App\Models\TeamMembership;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * S04, S74, S90 — invitations (PRD §4.1 F1.3).
 *
 * *"Invite by email, assign role on invite, revoke without destroying
 * historical attribution."*
 */
beforeEach(function (): void {
    [$this->team, $this->owner] = $this->teamWithOwner();

    $this->enrollTwoFactor($this->owner);
    $this->actingAsPerson($this->owner, $this->team);
});

it('invites, emails, accepts, and lands in the team', function (): void {
    $role = Role::query()->whereNull('team_id')->where('key', SystemRole::TeamMember->value)->sole();

    $this->post('/settings/members/invitations', [
        'email' => 'heather@example.test',
        'role_id' => $role->getKey(),
    ])->assertRedirect(route('members.index'));

    Mail::assertSent(TeamInvitationMail::class, function (TeamInvitationMail $mail): bool {
        // The plaintext token exists for exactly as long as it takes to put
        // it in an email; what is stored is its hash.
        $this->token = $mail->token;

        return $mail->hasTo('heather@example.test');
    });

    $invitation = TeamInvitation::query()->sole();

    expect($invitation->token_hash)->toBe(TeamInvitation::hashToken($this->token))
        ->and($invitation->token_hash)->not->toBe($this->token);

    // Accepting is done by somebody with no session and no membership.
    auth()->logout();
    app(TeamContext::class)->set(null);

    $this->get("/invitations/{$this->token}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/AcceptInvitation')->where('state', 'pending'));

    $this->post("/invitations/{$this->token}", [
        'first_name' => 'Heather',
        'last_name' => 'Quinn',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
    ])->assertRedirect(route('dashboard'));

    $person = Person::query()->where('email', 'heather@example.test')->sole();

    $this->assertAuthenticatedAs($person);

    expect($person->hasCredentials())->toBeTrue()
        ->and(TeamMembership::withoutTeamScope()
            ->where('team_id', $this->team->getKey())
            ->where('person_id', $person->getKey())
            ->exists())->toBeTrue();
});

it('gives an expired token its own screen rather than a 500', function (): void {
    $token = TeamInvitation::newToken();

    app(TeamContext::class)->runFor($this->team, fn () => TeamInvitation::factory()->create([
        'team_id' => $this->team->getKey(),
        'email' => 'heather@example.test',
        'role_id' => Role::query()->whereNull('team_id')->where('key', 'team_member')->sole()->getKey(),
        'token_hash' => TeamInvitation::hashToken($token),
        'expires_at' => now()->subDay(),
    ]));

    auth()->logout();

    $this->get("/invitations/{$token}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('state', 'expired'));

    // And it cannot be used by posting straight past the screen.
    $this->post("/invitations/{$token}", [
        'first_name' => 'Heather',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
    ])->assertRedirect();

    expect(Person::query()->where('email', 'heather@example.test')->exists())->toBeFalse();
});

it('refuses a token that has already been used', function (): void {
    $token = TeamInvitation::newToken();

    app(TeamContext::class)->runFor($this->team, fn () => TeamInvitation::factory()->create([
        'team_id' => $this->team->getKey(),
        'email' => 'heather@example.test',
        'role_id' => Role::query()->whereNull('team_id')->where('key', 'team_member')->sole()->getKey(),
        'token_hash' => TeamInvitation::hashToken($token),
        'expires_at' => now()->addDay(),
        'accepted_at' => now(),
    ]));

    auth()->logout();

    $this->get("/invitations/{$token}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('state', 'accepted'));
});

it('404s an unknown token without saying why', function (): void {
    auth()->logout();

    // Never a message distinguishing "no such invitation" from "not yours",
    // which would let somebody probe for live tokens.
    $this->get('/invitations/'.TeamInvitation::newToken())->assertNotFound();
});

it('attaches a membership to somebody who already has a record', function (): void {
    // Issue #45: "Accepting an invitation for an email that already has a
    // `people` record attaches a new `team_membership` rather than creating a
    // second person."
    $existing = Person::factory()->contactOnly()->create([
        'email' => 'sam@example.test',
        'first_name' => 'Sam',
        'last_name' => 'Ferreira',
    ]);

    $invitation = app(InvitePersonToTeam::class)->handle(
        team: $this->team,
        email: 'sam@example.test',
        role: Role::query()->whereNull('team_id')->where('key', 'team_member')->sole(),
    );

    Mail::assertSent(TeamInvitationMail::class, function (TeamInvitationMail $mail): bool {
        $this->token = $mail->token;

        return true;
    });

    auth()->logout();
    app(TeamContext::class)->set(null);

    $this->post("/invitations/{$this->token}", [
        'first_name' => 'Samuel',
        'last_name' => 'Ferreira',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
    ])->assertRedirect(route('dashboard'));

    expect(Person::query()->where('email', 'sam@example.test')->count())->toBe(1);

    $existing->refresh();

    // Their name is not overwritten by whatever the invitee typed — another
    // team already knows them by the name on the record.
    expect($existing->first_name)->toBe('Sam')
        ->and($existing->hasCredentials())->toBeTrue();

    unset($invitation);
});

it('is not a way into an account that already has a password', function (): void {
    /*
     * The link proves possession of an inbox, not of a password. Signing the
     * holder in would make an emailed URL a working credential for somebody
     * else's account — silently, since the password is not even changed, so
     * the owner would have nothing to notice.
     */
    $existing = Person::factory()->create([
        'email' => 'casey@example.test',
        'password' => Hash::make('caseys-real-password'),
    ]);

    $this->enrollTwoFactor($existing);

    $invitation = app(InvitePersonToTeam::class)->handle(
        team: $this->team,
        email: 'casey@example.test',
        role: Role::query()->whereNull('team_id')->where('key', 'team_member')->sole(),
    );

    Mail::assertSent(TeamInvitationMail::class, function (TeamInvitationMail $mail): bool {
        $this->token = $mail->token;

        return true;
    });

    auth()->logout();
    app(TeamContext::class)->set(null);

    $this->post("/invitations/{$this->token}", [
        'first_name' => 'Not',
        'last_name' => 'Casey',
        'password' => 'an-attacker-chosen-password',
        'password_confirmation' => 'an-attacker-chosen-password',
    ])->assertRedirect(route('login'));

    $this->assertGuest();

    $existing->refresh();

    // Their password is untouched, and their name is not rewritten either.
    expect(Hash::check('caseys-real-password', (string) $existing->password))->toBeTrue()
        ->and($existing->first_name)->not->toBe('Not');

    // The membership is still attached — the team owner decided that, and it
    // costs the invitee nothing. They sign in as themselves.
    expect(TeamMembership::withoutTeamScope()
        ->where('team_id', $this->team->getKey())
        ->where('person_id', $existing->getKey())
        ->exists())->toBeTrue();

    unset($invitation);
});

it('matches an invited address whatever its capitals', function (): void {
    // A duplicate row for one human breaks the shared-record decision at its
    // foundation, and the duplicate then shadows the original at sign-in.
    $existing = Person::factory()->create([
        'email' => 'casey@example.test',
        'password' => Hash::make('caseys-real-password'),
    ]);

    app(InvitePersonToTeam::class)->handle(
        team: $this->team,
        email: 'Casey@Example.TEST',
        role: Role::query()->whereNull('team_id')->where('key', 'team_member')->sole(),
    );

    Mail::assertSent(TeamInvitationMail::class, function (TeamInvitationMail $mail): bool {
        $this->token = $mail->token;

        return true;
    });

    auth()->logout();
    app(TeamContext::class)->set(null);

    $this->post("/invitations/{$this->token}", [
        'first_name' => 'Casey',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
    ]);

    expect(Person::query()->whereRaw('lower(email) = ?', ['casey@example.test'])->count())->toBe(1);

    // And the real Casey can still sign in with the password she chose.
    $this->post('/login', ['email' => 'casey@example.test', 'password' => 'caseys-real-password'])
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($existing);
});

it('refuses to revoke the last owner, and says why', function (): void {
    $membership = TeamMembership::query()
        ->where('person_id', $this->owner->getKey())
        ->sole();

    $this->delete("/settings/members/{$membership->getKey()}")
        ->assertSessionHasErrors('membership');

    expect(session('errors')->first('membership'))->toContain('last owner');

    $membership->refresh();

    expect($membership->isRevoked())->toBeFalse();
});

it('allows revoking an owner once there is another one', function (): void {
    $second = Person::factory()->create();

    app(TeamContext::class)->runFor($this->team, function () use ($second): void {
        $membership = TeamMembership::query()->create([
            'team_id' => $this->team->getKey(),
            'person_id' => $second->getKey(),
            'status' => App\Enums\PersonLifecycleState::Active,
        ]);

        $membership->roles()->attach(
            Role::query()->whereNull('team_id')->where('key', 'team_owner')->sole()->getKey(),
        );
    });

    $membership = TeamMembership::query()->where('person_id', $this->owner->getKey())->sole();

    $this->delete("/settings/members/{$membership->getKey()}")->assertSessionHasNoErrors();

    expect($membership->fresh()->isRevoked())->toBeTrue();
});

it('keeps a revoked member’s name on what they already did', function (): void {
    // PRD F1.3: "revoke without destroying historical attribution."
    $second = Person::factory()->create(['first_name' => 'Heather', 'last_name' => 'Quinn']);

    $membership = app(TeamContext::class)->runFor($this->team, function () use ($second): TeamMembership {
        $membership = TeamMembership::query()->create([
            'team_id' => $this->team->getKey(),
            'person_id' => $second->getKey(),
            'status' => App\Enums\PersonLifecycleState::Active,
        ]);

        $membership->roles()->attach(
            Role::query()->whereNull('team_id')->where('key', 'team_member')->sole()->getKey(),
        );

        app(App\Support\Activity\RecordActivity::class)->record(
            subject: $second,
            eventType: 'contact.logged',
            summary: 'Phone call',
            actor: $second,
        );

        return $membership;
    });

    $this->delete("/settings/members/{$membership->getKey()}")->assertSessionHasNoErrors();

    $event = app(TeamContext::class)->runFor(
        $this->team,
        fn () => App\Models\ActivityEvent::query()->with('actor')->sole(),
    );

    expect($event->actor?->fullName())->toBe('Heather Quinn');
});

it('refuses an invitation from somebody who cannot manage members', function (): void {
    [$otherTeam, $member] = $this->teamWithMember();

    $this->actingAsPerson($member, $otherTeam);

    $this->post('/settings/members/invitations', [
        'email' => 'nope@example.test',
        'role_id' => Role::query()->whereNull('team_id')->where('key', 'team_member')->sole()->getKey(),
    ])->assertForbidden();
});

it('never offers the platform role from inside a team', function (): void {
    // Super Administrator is handed out by the console, never by a customer's
    // own members screen.
    $superAdmin = Role::query()->whereNull('team_id')->where('key', SystemRole::SuperAdministrator->value)->sole();

    $this->post('/settings/members/invitations', [
        'email' => 'nope@example.test',
        'role_id' => $superAdmin->getKey(),
    ])->assertSessionHasErrors('role_id');
});
