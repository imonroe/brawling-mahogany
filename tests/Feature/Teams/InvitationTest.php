<?php

declare(strict_types=1);

use App\Actions\Teams\InvitePersonToTeam;
use App\Enums\SystemRole;
use App\Mail\TeamInvitationMail;
use App\Models\Person;
use App\Models\Role;
use App\Models\Team;
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

it('attaches a membership to somebody who already has an account', function (): void {
    /*
     * Issue #45: "Accepting an invitation for an email that already has a
     * `people` record attaches a new `team_membership` rather than creating a
     * second person."
     *
     * Still true, and now narrower: the record it attaches to is a **login**
     * (#140). A credential-less contact of another team is not one, so this
     * is about somebody who can already sign in.
     */
    $existing = Person::factory()->create(['email' => 'sam@example.test']);

    [$otherTeam] = $this->teamWithMember($existing);

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

    /*
     * Not signed in by the link, and that is the round-one security fix
     * holding: an emailed URL must never be a way into an account that
     * already has a password. The membership is still attached, because the
     * team owner decided that — they just sign in as themselves.
     */
    $this->post("/invitations/{$this->token}", [
        'first_name' => 'Samuel',
        'last_name' => 'Ferreira',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
    ])->assertRedirect(route('login'));

    // One login, two teams.
    expect(Person::query()->where('email', 'sam@example.test')->count())->toBe(1)
        ->and($existing->fresh()->hasCredentials())->toBeTrue();

    // The name they typed lands on the team they just joined, and the other
    // team's record of them is untouched (#140).
    expect($existing->membershipIn($this->team)->first_name)->toBe('Samuel')
        ->and($existing->membershipIn($otherTeam)->first_name)->not->toBe('Samuel');

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
            'first_name' => 'Second',
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

it('refuses to let the last owner delete their own account', function (): void {
    // Revoking the last owner from the members screen is refused; deleting
    // the account was the way round the back, and it left the team with
    // nobody able to manage members, settings, or billing — and no route in
    // `/admin` to repair it.
    $this->delete('/settings/profile', ['password' => 'password'])
        ->assertSessionHasErrors('password');

    expect(session('errors')->first('password'))->toContain('last owner');

    expect(Person::query()->find($this->owner->getKey()))->not->toBeNull();
});

it('lets an owner delete their account once somebody else owns the team', function (): void {
    $second = Person::factory()->create();

    app(TeamContext::class)->runFor($this->team, function () use ($second): void {
        $membership = TeamMembership::query()->create([
            'team_id' => $this->team->getKey(),
            'person_id' => $second->getKey(),
            'first_name' => 'Second',
            'status' => App\Enums\PersonLifecycleState::Active,
        ]);

        $membership->roles()->attach(
            Role::query()->whereNull('team_id')->where('key', 'team_owner')->sole()->getKey(),
        );
    });

    $this->delete('/settings/profile', ['password' => 'password'])
        ->assertSessionHasNoErrors();

    expect(Person::query()->find($this->owner->getKey()))->toBeNull();
});

it('leaves no live membership behind when somebody deletes their account', function (): void {
    /*
     * A soft-deleted person with a live membership broke three screens at
     * once — the members list, the export, and the person detail — because
     * each dereferenced a person the relation had scoped away. The members
     * list is the worst of them: it 500s for the whole team, and it is the
     * only screen that could have undone the membership.
     */
    $second = Person::factory()->create();

    app(TeamContext::class)->runFor($this->team, function () use ($second): void {
        $membership = TeamMembership::query()->create([
            'team_id' => $this->team->getKey(),
            'person_id' => $second->getKey(),
            'first_name' => 'Heather',
            'last_name' => 'Quinn',
            'status' => App\Enums\PersonLifecycleState::Active,
        ]);

        $membership->roles()->attach(
            Role::query()->whereNull('team_id')->where('key', 'team_member')->sole()->getKey(),
        );
    });

    $this->actingAs($second);
    $this->delete('/settings/profile', ['password' => 'password'])->assertSessionHasNoErrors();

    $this->actingAsPerson($this->owner, $this->team);

    // The screens still render, and the membership still carries their name.
    $this->get('/settings/members')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where(
            'members',
            fn ($members) => collect($members)->contains(
                fn (array $member): bool => $member['name'] === 'Heather Quinn' && $member['revokedAt'] !== null,
            ),
        ));

    $this->post('/settings/export')->assertRedirect();

    expect(App\Models\DataExport::query()->sole()->state)
        ->toBe(App\Enums\DataExportState::Ready);
});

it('does not refuse account deletion to an owner of two healthy teams', function (): void {
    /*
     * `otherOwnerCount()` asked a cross-team question through the team scope,
     * so the SQL was `team_id = <resolved> AND team_id = <the other one>` —
     * always zero. An owner of two teams that each had a second owner was
     * refused with a message she could never satisfy.
     */
    $person = Person::factory()->create();

    foreach ([$this->team, Team::factory()->create()] as $team) {
        app(TeamContext::class)->runFor($team, function () use ($team, $person): void {
            foreach ([$person, Person::factory()->create()] as $owner) {
                $membership = TeamMembership::query()->firstOrCreate(
                    ['team_id' => $team->getKey(), 'person_id' => $owner->getKey()],
                    ['first_name' => 'Owner', 'status' => App\Enums\PersonLifecycleState::Active],
                );

                $membership->roles()->syncWithoutDetaching([
                    Role::query()->whereNull('team_id')->where('key', 'team_owner')->sole()->getKey(),
                ]);
            }
        });
    }

    $this->actingAs($person);

    $this->delete('/settings/profile', ['password' => 'password'])->assertSessionHasNoErrors();

    expect(Person::query()->find($person->getKey()))->toBeNull();
});

it('does not 500 when the owner’s only team is suspended', function (): void {
    // No team resolves, so the scoped query threw rather than answering.
    $this->team->forceFill(['suspended_at' => now()])->save();

    $this->actingAs($this->owner);

    $this->delete('/settings/profile', ['password' => 'password'])
        ->assertSessionHasErrors('password');

    expect(session('errors')->first('password'))->toContain('last owner');
});

it('keeps a revoked member’s name on what they already did', function (): void {
    // PRD F1.3: "revoke without destroying historical attribution."
    $second = Person::factory()->create();

    $membership = app(TeamContext::class)->runFor($this->team, function () use ($second): TeamMembership {
        $membership = TeamMembership::query()->create([
            'team_id' => $this->team->getKey(),
            'person_id' => $second->getKey(),
            'first_name' => 'Heather',
            'last_name' => 'Quinn',
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

    // Revoked, and still named on what they did. `membershipIn()` excludes a
    // revoked row, so the name has to come from the membership itself.
    expect($membership->fresh()->revoked_at)->not->toBeNull()
        ->and($membership->fresh()->fullName())->toBe('Heather Quinn')
        ->and($event->actor_person_id)->toBe($second->getKey());
});

/**
 * A lead becoming a team member: the ordinary reason to invite anybody.
 *
 * Round 1 found this was a 500 on every click, forever. Round 2 found the fix
 * for that had replaced it with a validation error on a screen with no field
 * to render it, which is the same dead end without the stack trace.
 */
it('lets a contact in the directory accept an invitation', function (): void {
    $role = Role::query()->whereNull('team_id')->where('key', SystemRole::TeamMember->value)->sole();

    // Claire is a lead this team added. Her `people` row holds no credentials
    // and no address at all (#140); the address lives on the membership.
    $this->post('/people', [
        'first_name' => 'Claire',
        'email' => 'claire@example.test',
        'status' => 'lead',
        'notes' => 'Met at the open house.',
    ])->assertSessionHasNoErrors();

    $this->post('/settings/members/invitations', [
        'email' => 'claire@example.test',
        'role_id' => $role->getKey(),
    ])->assertSessionHasNoErrors();

    Mail::assertSent(TeamInvitationMail::class, function (TeamInvitationMail $mail): bool {
        $this->token = $mail->token;

        return true;
    });

    auth()->logout();
    app(TeamContext::class)->set(null);

    $this->post("/invitations/{$this->token}", [
        'first_name' => 'Claire',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
    ])->assertRedirect(route('dashboard'));

    // One membership, upgraded rather than duplicated — so her notes and her
    // lifecycle history survive her getting a login, which is what the team
    // expects and the only reason the row was worth keeping.
    $membership = TeamMembership::withoutTeamScope()
        ->where('team_id', $this->team->getKey())
        ->whereRaw('lower(email) = ?', ['claire@example.test'])
        ->sole();

    expect($membership->notes)->toBe('Met at the open house.')
        ->and($membership->person->hasCredentials())->toBeTrue();
});

/**
 * The one conflict the product cannot resolve on its own, refused where
 * somebody can act on it.
 *
 * A directory contact *and* a separate account for one address. Repointing the
 * membership breaks every activity event naming the person it holds; attaching
 * the account collides on `team_memberships_team_email_unique`. The refusal
 * belongs on the members screen, in front of the person who can remove the
 * duplicate — not in front of the invitee, who can do nothing about it.
 */
it('refuses to invite an address that is both a contact and an account', function (): void {
    $role = Role::query()->whereNull('team_id')->where('key', SystemRole::TeamMember->value)->sole();

    // Somebody who signs in — in another team, which is the realistic case.
    Person::factory()->create(['email' => 'claire@example.test']);

    $this->post('/people', [
        'first_name' => 'Claire',
        'email' => 'claire@example.test',
        'status' => 'lead',
    ])->assertSessionHasNoErrors();

    $this->post('/settings/members/invitations', [
        'email' => 'claire@example.test',
        'role_id' => $role->getKey(),
    ])->assertSessionHasErrors('email');

    // Nothing sent, so nobody is holding a link that can never work.
    expect(TeamInvitation::query()->count())->toBe(0);
    Mail::assertNotSent(TeamInvitationMail::class);
});

/**
 * One outstanding invitation per address per team.
 *
 * `team_invitations_pending_unique` has existed since Slice 1 and nothing
 * validated against it, so the second invitation to a still-pending address
 * was a 500 — reachable by *"she says she never got it, send it again"* and by
 * a double-click on Send. The same defect round 1 called blocking as B2, one
 * table over.
 */
it('refuses a second invitation while one is still outstanding', function (): void {
    $role = Role::query()->whereNull('team_id')->where('key', SystemRole::TeamMember->value)->sole();

    $this->post('/settings/members/invitations', [
        'email' => 'heather@example.test',
        'role_id' => $role->getKey(),
    ])->assertSessionHasNoErrors();

    // Capitals included, because the index is over `lower(email)` and the
    // rule has to ask the question the index answers.
    $this->post('/settings/members/invitations', [
        'email' => 'Heather@Example.TEST',
        'role_id' => $role->getKey(),
    ])->assertSessionHasErrors('email');

    expect(TeamInvitation::query()->count())->toBe(1);
});

it('lets a revoked invitation’s address be invited again', function (): void {
    // The index is partial — live rows only — so revoking frees the address.
    // That is what makes "revoke it first" a remedy rather than advice.
    $role = Role::query()->whereNull('team_id')->where('key', SystemRole::TeamMember->value)->sole();

    $this->post('/settings/members/invitations', [
        'email' => 'heather@example.test',
        'role_id' => $role->getKey(),
    ])->assertSessionHasNoErrors();

    $invitation = TeamInvitation::query()->sole();

    $this->delete("/settings/members/invitations/{$invitation->getKey()}")
        ->assertSessionHasNoErrors();

    $this->post('/settings/members/invitations', [
        'email' => 'heather@example.test',
        'role_id' => $role->getKey(),
    ])->assertSessionHasNoErrors();

    expect(TeamInvitation::query()->whereNull('revoked_at')->count())->toBe(1);
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
