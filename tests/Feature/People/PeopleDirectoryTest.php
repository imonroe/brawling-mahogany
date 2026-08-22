<?php

declare(strict_types=1);

use App\Enums\PersonLifecycleState;
use App\Models\ActivityEvent;
use App\Models\Person;
use App\Models\TeamMembership;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\DB;

/**
 * S30, S31, S32 — the people directory (PRD §4.2 F2.1, F2.4, F2.5, F2.6).
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();

    $this->actingAsPerson($this->member, $this->team);
});

it('creates a person and their membership together', function (): void {
    $this->post('/people', [
        'first_name' => 'Claire',
        'last_name' => 'Nakamura',
        'email' => 'claire@example.test',
        'phone' => '+1 303 555 0100',
        'status' => PersonLifecycleState::Lead->value,
        'notes' => 'Met at the open house.',
    ])->assertRedirect();

    $membership = TeamMembership::query()->where('email', 'claire@example.test')->sole();

    // The person has no credentials: PRD F2.1 makes that the common case, and
    // a directory entry is not an account (#140).
    expect($membership->person->hasCredentials())->toBeFalse()
        ->and($membership->person->email)->toBeNull()
        ->and($membership->fullName())->toBe('Claire Nakamura')
        ->and($membership->phone)->toBe('+1 303 555 0100')
        ->and($membership->status)->toBe(PersonLifecycleState::Lead)
        ->and($membership->notes)->toBe('Met at the open house.');
});

it('keeps everything a team knows off the login record', function (): void {
    /*
     * PRD §6.2 said *"team-private notes live here, not on the person"*, and
     * #140 finished the thought: the name, the address, and the number are as
     * private as the notes. `people` is the login and holds none of it.
     */
    $this->post('/people', [
        'first_name' => 'Sam',
        'email' => 'sam@example.test',
        'status' => PersonLifecycleState::Active->value,
        'notes' => 'Slow to return calls.',
    ]);

    $membership = TeamMembership::query()->where('email', 'sam@example.test')->sole();
    $attributes = $membership->person->getAttributes();

    foreach (['notes', 'first_name', 'last_name', 'phone'] as $field) {
        expect($attributes)->not->toHaveKey($field, "`people` still carries {$field}.");
    }

    expect($attributes['email'])->toBeNull();
});

it('gives each team its own record of the same human', function (): void {
    /*
     * Issue #18 decided this the other way and #140 revised it. The stager who
     * works for two teams is two directory entries, because every field a team
     * can see is the team's own — so a shared row would carry nothing worth
     * sharing and would still let one team read the other's.
     */
    $this->post('/people', [
        'first_name' => 'Sam',
        'last_name' => 'Ferreira',
        'email' => 'sam@example.test',
        'status' => PersonLifecycleState::Active->value,
        'notes' => 'Team A’s opinion.',
    ]);

    [$otherTeam, $otherMember] = $this->teamWithMember();

    $this->actingAsPerson($otherMember, $otherTeam);

    $this->post('/people', [
        'first_name' => 'Sam',
        'last_name' => 'Ferreira',
        'email' => 'sam@example.test',
        'status' => PersonLifecycleState::Lead->value,
        'notes' => 'Team B’s opinion.',
    ]);

    // One row visible here, two across the platform, and two `people` rows —
    // a credential-less contact is not an account, so nothing is shared.
    expect(TeamMembership::query()->where('email', 'sam@example.test')->count())->toBe(1)
        ->and(TeamMembership::withoutTeamScope()->where('email', 'sam@example.test')->count())->toBe(2)
        ->and(TeamMembership::withoutTeamScope()->where('email', 'sam@example.test')
            ->pluck('person_id')->unique())->toHaveCount(2);

    // And neither team can read the other's note about them.
    $mine = TeamMembership::query()->where('email', 'sam@example.test')->sole();

    expect($mine->notes)->toBe('Team B’s opinion.');
});

it('adds a person who has a phone number and no email', function (): void {
    // PRD F2.1: most people in this product never log in, and plenty of them
    // have no address either. The column came over from Laravel's `users`
    // table, where everybody signs in, and was still NOT NULL.
    $this->post('/people', [
        'first_name' => 'Sam',
        'last_name' => 'Ferreira',
        'phone' => '+1 303 555 0100',
        'status' => PersonLifecycleState::Active->value,
        'is_vendor' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $membership = TeamMembership::query()->where('phone', '+1 303 555 0100')->sole();
    $person = $membership->person;

    expect($person->email)->toBeNull();
});

it('lets several people have no email at once', function (): void {
    // The unique index has to treat "no address" as no constraint at all.
    foreach (['Sam', 'Lee', 'Claire'] as $name) {
        $this->post('/people', [
            'first_name' => $name,
            'status' => PersonLifecycleState::Lead->value,
        ])->assertSessionHasNoErrors();
    }

    expect(TeamMembership::query()->whereNull('email')->count())->toBe(3);
});

/**
 * One address is one person in this team, however it was typed.
 *
 * This test asserted `assertSessionHasNoErrors()` on both posts for a review
 * round, and passed — because the second post was a **500**, and a 500 carries
 * no session validation errors either. `->sole()` then passed for the same
 * reason: only one row had been written. Two assertions, both green, both
 * measuring a server error. Worth recording, because the shape recurs: a
 * negative assertion about errors is satisfied by a crash.
 *
 * The Postgres DETAIL line from that 500 also carried the address into the
 * log, which is what `Redactor::throwable()` now closes.
 */
it('treats one address as one human whatever its capitals', function (): void {
    $this->post('/people', [
        'first_name' => 'Claire',
        'email' => 'Claire@Example.TEST',
        'status' => PersonLifecycleState::Active->value,
    ])->assertSessionHasNoErrors();

    // Refused in validation, which is a sentence on the form rather than a
    // stack trace. S32's "warning and an offer to open the existing record"
    // is `/people/lookup`, which warns before the submit; this is what stops
    // the submit that follows it from being a 500.
    $this->post('/people', [
        'first_name' => 'Claire',
        'email' => 'claire@example.test',
        'status' => PersonLifecycleState::Lead->value,
    ])->assertSessionHasErrors('email');

    // Asked for by address rather than by "the first person with one" — the
    // team already has an owner and a member with addresses of their own, and
    // which row comes back first is up to the ULIDs.
    $claire = TeamMembership::query()->whereRaw('lower(email) = ?', ['claire@example.test'])->sole();

    // Stored folded, so the index and every lookup agree.
    expect($claire->email)->toBe('claire@example.test');
});

it('lets another team hold the same address', function (): void {
    $this->post('/people', [
        'first_name' => 'Claire',
        'email' => 'claire@example.test',
        'status' => PersonLifecycleState::Active->value,
    ])->assertSessionHasNoErrors();

    /*
     * The unique rule asks about *this* team and nothing else, which is the
     * point of the #140 move. A rule that asked globally would tell one team
     * that another team already knows somebody — the exact disclosure the move
     * was made to close, reintroduced by the validation that protects its
     * index.
     */
    [$other, $member] = $this->teamWithMember();
    $this->actingAsPerson($member, $other);

    $this->post('/people', [
        'first_name' => 'Claire',
        'email' => 'claire@example.test',
        'status' => PersonLifecycleState::Lead->value,
    ])->assertSessionHasNoErrors();

    expect(TeamMembership::withoutTeamScope()
        ->whereRaw('lower(email) = ?', ['claire@example.test'])
        ->count())->toBe(2);
});

it('lets somebody keep their own address when their record is edited', function (): void {
    $this->post('/people', [
        'first_name' => 'Claire',
        'email' => 'claire@example.test',
        'status' => PersonLifecycleState::Active->value,
    ])->assertSessionHasNoErrors();

    $claire = TeamMembership::query()->whereRaw('lower(email) = ?', ['claire@example.test'])->sole();

    // The unique rule has to ignore the row it is validating, or nobody could
    // ever change their own surname.
    $this->patch("/people/{$claire->getKey()}", [
        'first_name' => 'Claire',
        'last_name' => 'Nakamura',
        'email' => 'claire@example.test',
        'status' => PersonLifecycleState::Active->value,
    ])->assertSessionHasNoErrors();

    expect($claire->fresh()->last_name)->toBe('Nakamura');
});

it('keeps a vendor’s cost in integer cents end to end', function (): void {
    // ADR 0001: money is integer cents, never a float. The screen types
    // dollars and converts once at the boundary; everything below that —
    // the request, the column, and the prop the detail page reads — is cents,
    // and a stray conversion anywhere turns a $1,200 stager into a $12 one.
    $this->post('/people', [
        'first_name' => 'Sam',
        'email' => 'sam@example.test',
        'status' => PersonLifecycleState::Active->value,
        'is_vendor' => true,
        'vendor_typical_cost' => 120_000,
        'vendor_rating' => 4,
    ])->assertRedirect();

    $membership = TeamMembership::query()->where('email', 'sam@example.test')->sole();

    expect($membership->vendor_typical_cost)->toBe(120_000);

    $this->get("/people/{$membership->getKey()}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('membership.vendor.typicalCost', 120_000));
});

it('refuses a vendor rating outside the scale, at the database too', function (): void {
    $this->post('/people', [
        'first_name' => 'Sam',
        'status' => PersonLifecycleState::Active->value,
        'is_vendor' => true,
        'vendor_rating' => 9,
    ])->assertSessionHasErrors('vendor_rating');

    // And the column refuses it even when nothing validated (a seeder, a
    // console command, a future import).
    $membership = TeamMembership::factory()->create([
        'team_id' => $this->team->getKey(),
        'person_id' => Person::factory()->contactOnly()->create()->getKey(),
    ]);

    expect(fn () => DB::transaction(
        fn () => $membership->forceFill(['vendor_rating' => 9])->save(),
    ))->toThrow(Illuminate\Database\QueryException::class);
});

it('warns about a duplicate address rather than refusing it', function (): void {
    // S32: "duplicate email produces a warning and an offer to open the
    // existing record, not a hard failure."
    $this->post('/people', [
        'first_name' => 'Claire',
        'email' => 'claire@example.test',
        'status' => PersonLifecycleState::Lead->value,
    ]);

    $this->getJson('/people/lookup?email=claire@example.test')
        ->assertOk()
        ->assertJsonPath('duplicate.name', 'Claire');

    $this->getJson('/people/lookup?email=nobody@example.test')
        ->assertOk()
        ->assertJsonPath('duplicate', null);
});

it('does not report a duplicate that belongs to another team', function (): void {
    // Reporting a match from the shared `people` table would confirm that some
    // other team knows that address.
    [$otherTeam] = $this->teamWithMember();

    app(TeamContext::class)->runFor($otherTeam, function () use ($otherTeam): void {
        TeamMembership::query()->create([
            'team_id' => $otherTeam->getKey(),
            'person_id' => Person::factory()->contactOnly()->create()->getKey(),
            'first_name' => 'Theirs',
            'email' => 'theirs@example.test',
            'status' => PersonLifecycleState::Active,
        ]);
    });

    $this->actingAsPerson($this->member, $this->team);

    $this->getJson('/people/lookup?email=theirs@example.test')
        ->assertOk()
        ->assertJsonPath('duplicate', null);
});

it('segments the directory rather than splitting it into four screens', function (): void {
    $lead = membershipFor($this->team, 'Lee', PersonLifecycleState::Lead);
    $client = membershipFor($this->team, 'Claire', PersonLifecycleState::Active);
    $vendor = membershipFor($this->team, 'Sam', PersonLifecycleState::PastClient, vendor: true);

    $this->get('/people?segment=leads')->assertOk()->assertSee('Lee')->assertDontSee('Claire');
    $this->get('/people?segment=clients')->assertOk()->assertSee('Claire')->assertDontSee('Lee');
    $this->get('/people?segment=vendors')->assertOk()->assertSee('Sam')->assertDontSee('Lee');

    // A person can be a past client *and* a vendor at once — IA §13.3's
    // question, settled as a flag rather than a lifecycle value.
    $this->get('/people?segment=clients')->assertOk()->assertSee('Sam');

    unset($lead, $client, $vendor);
});

it('finds somebody by name, address, or number', function (): void {
    membershipFor($this->team, 'Claire', PersonLifecycleState::Active, email: 'claire@example.test', phone: '3035550100');
    membershipFor($this->team, 'Lee', PersonLifecycleState::Lead);

    $this->get('/people?search=claire')->assertOk()->assertSee('Claire')->assertDontSee('Lee');
    $this->get('/people?search=CLAIRE@EXAMPLE')->assertOk()->assertSee('Claire')->assertDontSee('Lee');
    $this->get('/people?search=5550100')->assertOk()->assertSee('Claire')->assertDontSee('Lee');
});

it('records a lifecycle change on the timeline', function (): void {
    $membership = membershipFor($this->team, 'Lee', PersonLifecycleState::Lead);

    $this->patch("/people/{$membership->getKey()}", [
        'first_name' => 'Lee',
        'status' => PersonLifecycleState::Active->value,
    ])->assertRedirect();

    // PRD §7.3 makes Past Client a first-class state that Slice 6 reads, so
    // the transitions are worth a timeline entry rather than a silent column
    // change.
    $event = ActivityEvent::query()->where('event_type', 'person.status_changed')->sole();

    expect($event->payload['from'])->toBe('lead')
        ->and($event->payload['to'])->toBe('active')
        ->and($event->is_client_visible)->toBeFalse();
});

it('logs contact against a person', function (): void {
    $membership = membershipFor($this->team, 'Claire', PersonLifecycleState::Active);

    $this->post("/people/{$membership->getKey()}/contact-log", [
        'contact_type' => 'phone_call',
        'note' => 'Walked through the listing timeline.',
    ])->assertRedirect();

    $event = ActivityEvent::query()->where('event_type', 'contact.logged')->sole();

    expect($event->summary)->toBe('Phone call')
        ->and($event->source)->toBe('manual')
        ->and($event->actor_person_id)->toBe($this->member->getKey())
        // IA §9: a logged call is internal. Nothing reaches a client unless
        // somebody deliberately says so.
        ->and($event->is_client_visible)->toBeFalse();
});

it('refuses a contact type the vocabulary does not have', function (): void {
    $membership = membershipFor($this->team, 'Claire', PersonLifecycleState::Active);

    $this->post("/people/{$membership->getKey()}/contact-log", [
        'contact_type' => 'carrier_pigeon',
    ])->assertSessionHasErrors('contact_type');
});

it('keeps the shared person when a team removes them', function (): void {
    $membership = membershipFor($this->team, 'Claire', PersonLifecycleState::Active);
    $personId = $membership->person_id;

    $this->delete("/people/{$membership->getKey()}")->assertRedirect('/people');

    // Soft-deleted, which is PRD §9's 30-day recovery window. The shared
    // person row is untouched: another team may still know them.
    expect(TeamMembership::query()->find($membership->getKey()))->toBeNull()
        ->and(Person::query()->find($personId))->not->toBeNull();
});

/**
 * The directory and the members screen reach the same row, and only one of
 * them was asking the last-owner rule.
 *
 * A Team Member holds `people.manage` and not `team.members.manage`, so the
 * *lower*-privileged role had a route to do what the members screen refused
 * them: delete the last Team Owner's membership, leaving a team nobody could
 * administer and no route in `/admin` to repair it.
 */
it('refuses to remove somebody’s access from the directory screen', function (): void {
    $ownerMembership = ownerMembershipOf($this->team);

    // Refused on the members screen, for want of `team.members.manage`…
    $this->delete("/settings/members/{$ownerMembership->getKey()}")->assertForbidden();

    // …and refused here too, for the same want.
    $this->delete("/people/{$ownerMembership->getKey()}")->assertForbidden();

    expect(TeamMembership::withoutTeamScope()->find($ownerMembership->getKey()))->not->toBeNull()
        ->and($ownerMembership->person->fresh()->activeTeams()->count())->toBe(1);
});

it('revokes rather than deletes when the person holds access', function (): void {
    [$team, $owner] = $this->teamWithOwner();
    $this->enrollTwoFactor($owner);

    $second = Person::factory()->create();

    app(TeamContext::class)->runFor($team, function () use ($team, $second): void {
        $membership = TeamMembership::query()->create([
            'team_id' => $team->getKey(),
            'person_id' => $second->getKey(),
            'first_name' => 'Second',
            'status' => PersonLifecycleState::Active,
        ]);

        $membership->roles()->attach(
            App\Models\Role::query()->whereNull('team_id')->where('key', 'team_member')->sole()->getKey(),
        );
    });

    $this->actingAsPerson($owner, $team);

    $membership = $second->membershipIn($team);

    $this->delete("/people/{$membership->getKey()}")->assertRedirect('/people');

    // Revoked, not deleted: PRD F1.3 keeps their name on everything they did.
    $membership = TeamMembership::withoutTeamScope()->find($membership->getKey());

    expect($membership)->not->toBeNull()
        ->and($membership->revoked_at)->not->toBeNull()
        ->and(App\Models\AuditEntry::query()->where('action', 'membership.revoked')->exists())->toBeTrue();
});

it('refuses to remove the last owner even with the access permission', function (): void {
    [$team, $owner] = $this->teamWithOwner();
    $this->enrollTwoFactor($owner);

    $this->actingAsPerson($owner, $team);

    $membership = $owner->membershipIn($team);

    $this->delete("/people/{$membership->getKey()}")
        ->assertSessionHasErrors('membership');

    expect(TeamMembership::withoutTeamScope()->find($membership->getKey())->revoked_at)->toBeNull();
});

it('writes an audit entry when an ordinary contact is removed', function (): void {
    $membership = membershipFor($this->team, 'Claire', PersonLifecycleState::Active);

    $this->delete("/people/{$membership->getKey()}")->assertRedirect('/people');

    expect(App\Models\AuditEntry::query()
        ->where('action', 'membership.removed')
        ->where('auditable_id', $membership->getKey())
        ->exists())->toBeTrue();
});

it('does not offer a deleted account as a duplicate', function (): void {
    $membership = membershipFor($this->team, 'Claire', PersonLifecycleState::Active, email: 'claire@example.test');

    $this->getJson('/people/lookup?email=claire@example.test')
        ->assertOk()
        ->assertJsonPath('duplicate.id', $membership->getKey());

    $membership->person->delete();

    $this->getJson('/people/lookup?email=claire@example.test')
        ->assertOk()
        ->assertJsonPath('duplicate', null);
});

it('refuses a person without the permission', function (): void {
    // Deny by default (PRD §9). A Contact holds nothing at all.
    $contact = Person::factory()->create();

    app(TeamContext::class)->runFor($this->team, function () use ($contact): void {
        $membership = TeamMembership::query()->create([
            'team_id' => $this->team->getKey(),
            'person_id' => $contact->getKey(),
            'first_name' => 'Casey',
            'status' => PersonLifecycleState::Active,
        ]);

        $membership->roles()->attach(
            App\Models\Role::query()->whereNull('team_id')->where('key', 'contact')->sole()->getKey(),
        );
    });

    $this->actingAsPerson($contact, $this->team);

    $this->get('/people')->assertForbidden();
    $this->post('/people', ['first_name' => 'Nope', 'status' => 'lead'])->assertForbidden();
});

/** A person this team knows, created directly so a test can set up volume. */
function membershipFor(
    App\Models\Team $team,
    string $firstName,
    PersonLifecycleState $status,
    bool $vendor = false,
    ?string $email = null,
    ?string $phone = null,
): TeamMembership {
    return app(TeamContext::class)->runFor($team, fn (): TeamMembership => TeamMembership::query()->create([
        'team_id' => $team->getKey(),
        // A directory entry is a membership plus a credential-less `people`
        // row that exists only so activity has somebody to point at (#140).
        'person_id' => Person::factory()->contactOnly()->create()->getKey(),
        'first_name' => $firstName,
        'email' => $email ?? Str::lower($firstName).'-'.Str::random(6).'@example.test',
        'phone' => $phone,
        'status' => $status,
        'is_vendor' => $vendor,
    ]));
}

/** The Team Owner's membership, found without a team scope in the way. */
function ownerMembershipOf(App\Models\Team $team): TeamMembership
{
    return TeamMembership::withoutTeamScope()
        ->where('team_id', $team->getKey())
        ->with('roles', 'person')
        ->get()
        ->first(fn (TeamMembership $membership): bool => $membership->hasRole('team_owner'));
}
