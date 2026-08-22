<?php

declare(strict_types=1);

use App\Enums\PersonLifecycleState;
use App\Models\ActivityEvent;
use App\Models\Person;
use App\Models\TeamMembership;
use App\Support\Tenancy\TeamContext;

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

    $person = Person::query()->where('email', 'claire@example.test')->sole();

    // The person has no credentials: PRD F2.1 makes that the common case, and
    // a directory entry is not an account.
    expect($person->hasCredentials())->toBeFalse();

    $membership = TeamMembership::query()->where('person_id', $person->getKey())->sole();

    expect($membership->status)->toBe(PersonLifecycleState::Lead)
        ->and($membership->notes)->toBe('Met at the open house.');
});

it('keeps team notes off the shared person record', function (): void {
    // PRD §6.2: "Team-private notes live here, not on the person." The point
    // of the whole membership table.
    $this->post('/people', [
        'first_name' => 'Sam',
        'email' => 'sam@example.test',
        'status' => PersonLifecycleState::Active->value,
        'notes' => 'Slow to return calls.',
    ]);

    $person = Person::query()->where('email', 'sam@example.test')->sole();

    expect($person->getAttributes())->not->toHaveKey('notes');
});

it('attaches a second team to one shared person rather than duplicating them', function (): void {
    // Issue #18's decision, exercised: the stager who works for two teams.
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

    expect(Person::query()->where('email', 'sam@example.test')->count())->toBe(1)
        ->and(TeamMembership::withoutTeamScope()
            ->whereHas('person', fn ($query) => $query->where('email', 'sam@example.test'))
            ->count())->toBe(2);

    // And neither team can read the other's note about them.
    $mine = TeamMembership::query()->whereHas('person', fn ($query) => $query->where('email', 'sam@example.test'))->sole();

    expect($mine->notes)->toBe('Team B’s opinion.');
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
            'person_id' => Person::factory()->contactOnly()->create(['email' => 'theirs@example.test'])->getKey(),
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

it('refuses a person without the permission', function (): void {
    // Deny by default (PRD §9). A Contact holds nothing at all.
    $contact = Person::factory()->create();

    app(TeamContext::class)->runFor($this->team, function () use ($contact): void {
        $membership = TeamMembership::query()->create([
            'team_id' => $this->team->getKey(),
            'person_id' => $contact->getKey(),
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
        'person_id' => Person::factory()->contactOnly()->create([
            'first_name' => $firstName,
            'email' => $email ?? Str::lower($firstName).'-'.Str::random(6).'@example.test',
            'phone' => $phone,
        ])->getKey(),
        'status' => $status,
        'is_vendor' => $vendor,
    ]));
}
