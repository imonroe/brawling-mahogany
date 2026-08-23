<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\Person;
use App\Models\Role;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamMembership;
use App\Support\Tenancy\TeamContext;

/**
 * S81–S85 — the super admin console (PRD §4.1 F1.5, §3.6 · IA §5.5).
 */
beforeEach(function (): void {
    $this->admin = $this->enrollTwoFactor(Person::factory()->superAdministrator()->create());
});

it('404s every admin route for anybody else', function (): void {
    [$team, $member] = $this->teamWithMember();

    $this->actingAsPerson($member, $team);

    // Issue #52: a 404, not a 403 — a 403 confirms the namespace exists,
    // which is the one thing the response must not say.
    foreach (['/admin', '/admin/teams', '/admin/health', '/admin/audit'] as $path) {
        $this->get($path)->assertNotFound();
    }
});

it('opens the console for a super administrator', function (): void {
    $this->actingAs($this->admin);

    $this->get('/admin')->assertOk()->assertInertia(fn ($page) => $page->component('Admin/Dashboard'));
    $this->get('/admin/teams')->assertOk();
    $this->get('/admin/health')->assertOk();
    $this->get('/admin/audit')->assertOk();
});

it('provisions a team and invites its owner in one step', function (): void {
    // PRD §5.1 step 1, and where Slice 1's exit criterion starts.
    $this->actingAs($this->admin);

    $this->post('/admin/teams', [
        'name' => 'Bosart Group',
        'timezone' => 'America/Denver',
        'owner_email' => 'emily@example.test',
    ])->assertRedirect();

    $team = Team::query()->where('name', 'Bosart Group')->sole();

    expect($team->slug)->toBe('bosart-group');

    $invitation = TeamInvitation::withoutTeamScope()->where('team_id', $team->getKey())->sole();

    expect($invitation->email)->toBe('emily@example.test')
        ->and($invitation->role->key)->toBe('team_owner');
});

it('audits a cross-tenant read, not only a write', function (): void {
    // PRD §9 wants the trail to prove access was *appropriate*, which means
    // recording the access.
    [$team] = $this->teamWithMember();

    $this->actingAs($this->admin);

    $this->get("/admin/teams/{$team->getKey()}")->assertOk();

    $entry = AuditEntry::query()->where('action', 'admin.team_viewed')->sole();

    expect($entry->team_id)->toBe($team->getKey())
        ->and($entry->actor_person_id)->toBe($this->admin->getKey());
});

it('suspends and restores a team, audibly', function (): void {
    [$team, $member] = $this->teamWithMember();

    $this->actingAs($this->admin);

    $this->post("/admin/teams/{$team->getKey()}/suspend")->assertRedirect();

    expect($team->fresh()->isSuspended())->toBeTrue()
        ->and(AuditEntry::query()->where('action', 'admin.team_suspended')->exists())->toBeTrue();

    // A suspended team is not reachable by its own members.
    $this->actingAs($member);
    $this->get('/dashboard')->assertRedirect(route('teams.none'));

    $this->actingAs($this->admin);
    $this->post("/admin/teams/{$team->getKey()}/restore")->assertRedirect();

    expect($team->fresh()->isSuspended())->toBeFalse();
});

it('refuses to impersonate without a reason', function (): void {
    [$team, $member] = $this->teamWithMember();

    $this->actingAs($this->admin);

    $this->post("/admin/teams/{$team->getKey()}/impersonate", [
        'person_id' => $member->getKey(),
        'minutes' => 30,
    ])->assertSessionHasErrors('reason');

    // A single word is not a reason either.
    $this->post("/admin/teams/{$team->getKey()}/impersonate", [
        'person_id' => $member->getKey(),
        'reason' => 'support',
        'minutes' => 30,
    ])->assertSessionHasErrors('reason');
});

it('impersonates with a reason, a banner, and two audit entries', function (): void {
    [$team, $member] = $this->teamWithMember();

    $this->actingAs($this->admin);

    $reason = 'Emily reported that the people index is empty for her team.';

    $this->post("/admin/teams/{$team->getKey()}/impersonate", [
        'person_id' => $member->getKey(),
        'reason' => $reason,
        'minutes' => 30,
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($member);

    $start = AuditEntry::query()->where('action', 'impersonation.started')->sole();

    expect($start->reason)->toBe($reason)
        ->and($start->actor_person_id)->toBe($this->admin->getKey())
        ->and($start->team_id)->toBe($team->getKey());

    // The shell renders the banner whenever this prop is present.
    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('auth.impersonating.teamName', $team->name)
            ->where('auth.impersonating.reason', $reason));

    // The console is closed for the duration: an impersonating admin holds
    // the impersonated person's permissions, never their own.
    $this->get('/admin')->assertNotFound();

    $this->delete('/impersonation')->assertRedirect(route('admin.dashboard'));

    $this->assertAuthenticatedAs($this->admin);

    expect(AuditEntry::query()->where('action', 'impersonation.ended')->exists())->toBeTrue();
});

it('accepts the duration a browser actually posts', function (): void {
    // A form sends strings. `integer` validates the value, it does not cast
    // it, and `Impersonation::start()` takes an int — so a test posting a
    // real int passes while the actual screen 500s.
    [$team, $member] = $this->teamWithMember();

    $this->actingAs($this->admin);

    $this->post("/admin/teams/{$team->getKey()}/impersonate", [
        'person_id' => $member->getKey(),
        'reason' => 'Heather reported the people index is empty for her.',
        'minutes' => '30',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($member);
});

it('ends a support session when its clock runs out', function (): void {
    [$team, $member] = $this->teamWithMember();

    $this->freezeAt('2026-08-21 09:00:00');

    $this->actingAs($this->admin);

    $this->post("/admin/teams/{$team->getKey()}/impersonate", [
        'person_id' => $member->getKey(),
        'reason' => 'Reproducing the empty people index Emily reported.',
        'minutes' => 15,
    ]);

    $this->assertAuthenticatedAs($member);

    $this->freezeAt('2026-08-21 09:16:00');

    $this->get('/dashboard');

    // Reverted without anybody clicking anything, and the audit trail says
    // which of the two ways it ended.
    $this->assertAuthenticatedAs($this->admin);

    expect(AuditEntry::query()->where('action', 'impersonation.expired')->exists())->toBeTrue();
});

/**
 * Somebody the team knows, holding a client-surface role and a real password.
 *
 * Slice 4 gives every client a reason to have one (#110), so this is the
 * membership the console must not offer: a password is not access, and
 * `activeTeams()` correctly says they can act in nothing.
 */
function statusViewerWithAPassword(Team $team): Person
{
    $person = Person::factory()->create(['password' => 'password']);

    app(TeamContext::class)->runFor($team, function () use ($team, $person): void {
        $membership = TeamMembership::query()->create([
            'team_id' => $team->getKey(),
            'person_id' => $person->getKey(),
            'first_name' => 'Client',
            'last_name' => 'Withapassword',
            'status' => App\Enums\PersonLifecycleState::Active,
            'joined_at' => now(),
        ]);

        $membership->roles()->attach(
            Role::query()->whereNull('team_id')
                ->where('key', App\Enums\SystemRole::StatusViewer->value)
                ->sole()->getKey(),
        );
    });

    return $person;
}

it('keeps somebody who holds no team access out of the impersonation picker', function (): void {
    /*
     * The password check alone let this through. It is a separate test from
     * the one below because the two rules have to be isolated: a probe with no
     * roles at all is excluded by *both*, so a single fixture cannot tell you
     * which one is doing the work — which is how deleting either check left the
     * suite green.
     */
    [$team] = $this->teamWithMember();

    $client = statusViewerWithAPassword($team);

    $this->actingAs($this->admin);

    $this->get("/admin/teams/{$team->getKey()}/impersonate")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where(
            'people',
            fn ($people) => collect($people)->doesntContain('personId', $client->getKey())
                // The control: somebody *is* offered, so this is not passing
                // on an empty list.
                && collect($people)->isNotEmpty(),
        ));
});

it('refuses to start a session for somebody who holds no team access', function (): void {
    /*
     * The picker is a convenience; this is the check. Narrowing only the list
     * left the door behind it open — a POST naming this person started an
     * audited session and landed the operator on `/no-team`.
     */
    [$team] = $this->teamWithMember();

    $client = statusViewerWithAPassword($team);

    $this->actingAs($this->admin);

    $this->post("/admin/teams/{$team->getKey()}/impersonate", [
        'person_id' => $client->getKey(),
        'reason' => 'Investigating a support ticket about their status page.',
        'minutes' => 15,
    ])->assertNotFound();

    expect(AuditEntry::query()->where('action', 'impersonation.started')->count())->toBe(0);
});

it('refuses to impersonate somebody who cannot sign in', function (): void {
    /*
     * A **Team Member** without a password, so `carriesAccess()` says yes and
     * only the password check can exclude them.
     *
     * The earlier fixture was a contact with no roles at all, which both rules
     * refuse — so deleting the password check left this test green while it
     * went on claiming to hold it. A test that two rules can satisfy isolates
     * neither.
     */
    [$team] = $this->teamWithMember();

    $contact = Person::factory()->contactOnly()->create();

    app(TeamContext::class)->runFor($team, function () use ($team, $contact): void {
        $membership = TeamMembership::factory()->create([
            'team_id' => $team->getKey(),
            'person_id' => $contact->getKey(),
        ]);

        $membership->roles()->attach(
            Role::query()->whereNull('team_id')
                ->where('key', App\Enums\SystemRole::TeamMember->value)
                ->sole()->getKey(),
        );
    });

    $this->actingAs($this->admin);

    $this->get("/admin/teams/{$team->getKey()}/impersonate")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where(
            'people',
            fn ($people) => collect($people)->doesntContain('personId', $contact->getKey()),
        ));
});
