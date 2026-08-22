<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\Person;

/**
 * The first platform administrator (issue #52 · PRD §5.1).
 *
 * The bootstrap gap this closes is easy to state: `/admin` provisions a team
 * and invites its owner, and `is_super_admin` is set nowhere in the UI — so a
 * fresh install had a console nobody could open and a registered account that
 * could only be told to wait for an invitation nobody could send.
 */
it('promotes an existing account', function (): void {
    $person = Person::factory()->create(['email' => 'operator@example.test']);

    expect($person->is_super_admin)->toBeFalse();

    $this->artisan('platform:promote', ['email' => 'operator@example.test'])
        ->assertSuccessful();

    expect($person->fresh()->is_super_admin)->toBeTrue();
});

it('matches the address whatever its capitals', function (): void {
    // `people.email` is stored folded and the unique index is over
    // `lower(email)`, so the lookup has to ask the question the index answers.
    $person = Person::factory()->create(['email' => 'operator@example.test']);

    $this->artisan('platform:promote', ['email' => 'Operator@Example.TEST'])
        ->assertSuccessful();

    expect($person->fresh()->is_super_admin)->toBeTrue();
});

it('refuses an address that is not an account', function (): void {
    $this->artisan('platform:promote', ['email' => 'nobody@example.test'])
        ->assertFailed();
});

/**
 * The confusing case on a fresh install, and worth its own test.
 *
 * Since #140 a contact in a team's directory holds a credential-less `people`
 * row with a **null** `email` — the address a team has for them is on the
 * membership. So typing a client's address here finds nothing, and the command
 * says why rather than reporting a bare "not found".
 */
it('does not promote a contact who has no login', function (): void {
    [$team, $member] = $this->teamWithMember();

    $this->actingAsPerson($member, $team);

    $this->post('/people', [
        'first_name' => 'Claire',
        'email' => 'claire@example.test',
        'status' => 'lead',
    ])->assertRedirect();

    $this->artisan('platform:promote', ['email' => 'claire@example.test'])
        ->assertFailed();

    expect(Person::query()->where('is_super_admin', true)->count())->toBe(0);
});

it('says nothing changed when they already hold it', function (): void {
    $person = Person::factory()->create(['email' => 'operator@example.test']);
    $person->forceFill(['is_super_admin' => true])->save();

    $this->artisan('platform:promote', ['email' => 'operator@example.test'])
        ->assertSuccessful();

    expect(AuditEntry::query()->where('action', 'platform.administrator_granted')->count())
        ->toBe(0, 'A no-op should not write an audit entry.');
});

/**
 * PRD §9: permission changes are audited, and this is the largest one the
 * product has. A row edited by hand in psql leaves nothing behind; this does.
 */
it('writes an audit entry with no team and no actor', function (): void {
    $person = Person::factory()->create(['email' => 'operator@example.test']);

    $this->artisan('platform:promote', ['email' => 'operator@example.test'])->assertSuccessful();

    $entry = AuditEntry::query()->where('action', 'platform.administrator_granted')->sole();

    expect($entry->auditable_id)->toBe($person->getKey())
        // The privilege spans every team, so no single team owns the entry.
        ->and($entry->team_id)->toBeNull()
        // An operator with a shell is not somebody the application knows, and
        // inventing an actor would be worse than a null.
        ->and($entry->actor_person_id)->toBeNull()
        ->and($entry->after['is_super_admin'])->toBeTrue();
});

it('takes the privilege away again', function (): void {
    $person = Person::factory()->create(['email' => 'operator@example.test']);
    $person->forceFill(['is_super_admin' => true])->save();

    // Somebody else still holds it, so there is no last-administrator prompt.
    Person::factory()->create()->forceFill(['is_super_admin' => true])->save();

    $this->artisan('platform:promote', ['email' => 'operator@example.test', '--demote' => true])
        ->assertSuccessful();

    expect($person->fresh()->is_super_admin)->toBeFalse()
        ->and(AuditEntry::query()->where('action', 'platform.administrator_revoked')->exists())
        ->toBeTrue();
});

it('does not let --force answer the last-administrator question', function (): void {
    // `--force` is `ConfirmableTrait`'s production gate, and every operator
    // types it. Letting it double as the answer here means the one prompt
    // worth reading is the one nobody is ever asked.
    $person = Person::factory()->create(['email' => 'operator@example.test']);
    $person->forceFill(['is_super_admin' => true])->save();

    $this->artisan('platform:promote', [
        'email' => 'operator@example.test',
        '--demote' => true,
        '--force' => true,
    ])
        ->expectsConfirmation('Demote them anyway?', 'no')
        ->assertFailed();

    expect($person->fresh()->is_super_admin)->toBeTrue();

    // Its own flag says it out loud, and is the only thing that skips it.
    $this->artisan('platform:promote', [
        'email' => 'operator@example.test',
        '--demote' => true,
        '--demote-last' => true,
    ])->assertSuccessful();

    expect($person->fresh()->is_super_admin)->toBeFalse();
});

it('warns before demoting the last administrator, and can be told to anyway', function (): void {
    $person = Person::factory()->create(['email' => 'operator@example.test']);
    $person->forceFill(['is_super_admin' => true])->save();

    // Declining leaves them in place.
    $this->artisan('platform:promote', ['email' => 'operator@example.test', '--demote' => true])
        ->expectsConfirmation('Demote them anyway?', 'no')
        ->assertFailed();

    expect($person->fresh()->is_super_admin)->toBeTrue();

    // A warning rather than a refusal, because this one is recoverable: the
    // same command promotes somebody back.
    $this->artisan('platform:promote', ['email' => 'operator@example.test', '--demote' => true])
        ->expectsConfirmation('Demote them anyway?', 'yes')
        ->assertSuccessful();

    expect($person->fresh()->is_super_admin)->toBeFalse();
});

it('grants no team access at all', function (): void {
    // A platform administrator runs above the tenant boundary (ADR 0002) and
    // holds no membership anywhere. Impersonation is how they see a customer's
    // data, and it is logged with a reason every time.
    $person = Person::factory()->create(['email' => 'operator@example.test']);

    $this->artisan('platform:promote', ['email' => 'operator@example.test'])->assertSuccessful();

    // `activeTeams()` asks across teams by design, so it needs no context.
    expect($person->fresh()->activeTeams())->toHaveCount(0)
        ->and(App\Models\TeamMembership::withoutTeamScope()
            ->where('person_id', $person->getKey())
            ->count())->toBe(0);
});

/**
 * The dead end this closes at the other end.
 *
 * Somebody registers on a fresh install, lands on the "no team" screen, and is
 * told to ask for an invitation nobody can send. The screen says which command
 * — but only while it is true.
 */
it('tells a first-run account how to get an administrator', function (): void {
    $person = Person::factory()->create();

    $this->actingAs($person)
        ->get('/no-team')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('platformHasNoAdministrator', true));
});

it('says nothing about the console once somebody administers the platform', function (): void {
    // A revoked member on a running install should be told to ask their team,
    // not handed operator instructions.
    Person::factory()->create()->forceFill(['is_super_admin' => true])->save();

    $person = Person::factory()->create();

    $this->actingAs($person)
        ->get('/no-team')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('platformHasNoAdministrator', false));
});
