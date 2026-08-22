<?php

declare(strict_types=1);

use App\Models\ActivityEvent;
use App\Models\ContactImport;
use App\Models\DataExport;
use App\Models\Person;
use App\Models\Role;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamMembership;
use App\Support\Tenancy\MissingTeamContextException;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\URL;

/**
 * The release blocker (PRD §8.2, §9 · issue #42 · `CLAUDE.md`).
 *
 * > A test suite whose entire job is asserting cross-tenant access returns
 * > 403 or 404. **A gap here is a release blocker.**
 *
 * The route table is enumerated rather than listed, so a route added in
 * Slice 4 is covered the day it lands rather than the day somebody remembers.
 * `ModelTenancyConventionTest` is the other half: it holds the *models*.
 */

/**
 * Two teams, each with a member who can sign in.
 *
 * @return array{a: array{team: Team, person: Person}, b: array{team: Team, person: Person}}
 */
function twoTeams(): array
{
    /** @var Tests\TestCase $test */
    $test = test();

    [$teamA, $memberA] = $test->teamWithMember();
    [$teamB, $memberB] = $test->teamWithMember();

    return [
        'a' => ['team' => $teamA, 'person' => $memberA],
        'b' => ['team' => $teamB, 'person' => $memberB],
    ];
}

it('hides another team’s people from the index', function (): void {
    ['a' => $a, 'b' => $b] = twoTeams();

    $theirs = app(TeamContext::class)->runFor($b['team'], fn (): TeamMembership => TeamMembership::factory()
        ->for($b['team'])
        ->for(Person::factory()->contactOnly()->create(['first_name' => 'Confidential']), 'person')
        ->create());

    $this->actingAsPerson($a['person'], $a['team']);

    $this->get('/people')
        ->assertOk()
        ->assertDontSee('Confidential')
        ->assertDontSee($theirs->getKey());
});

it('refuses a direct route to another team’s record', function (): void {
    ['a' => $a, 'b' => $b] = twoTeams();

    $theirs = app(TeamContext::class)->runFor($b['team'], fn (): TeamMembership => TeamMembership::factory()
        ->for($b['team'])
        ->for(Person::factory()->contactOnly()->create(), 'person')
        ->create());

    $this->actingAsPerson($a['person'], $a['team']);

    // 404 rather than 403 (ADR 0002, layer 3): a 403 confirms the record
    // exists, and that is itself a disclosure.
    $this->get("/people/{$theirs->getKey()}")->assertNotFound();
    $this->patch("/people/{$theirs->getKey()}", [
        'first_name' => 'Renamed',
        'status' => 'active',
    ])->assertNotFound();
    $this->delete("/people/{$theirs->getKey()}")->assertNotFound();
});

it('refuses a nested route under another team’s record', function (): void {
    ['a' => $a, 'b' => $b] = twoTeams();

    $theirs = app(TeamContext::class)->runFor($b['team'], fn (): TeamMembership => TeamMembership::factory()
        ->for($b['team'])
        ->for(Person::factory()->contactOnly()->create(), 'person')
        ->create());

    $this->actingAsPerson($a['person'], $a['team']);

    $this->post("/people/{$theirs->getKey()}/contact-log", [
        'contact_type' => 'phone_call',
        'note' => 'Should never be written.',
    ])->assertNotFound();

    expect(ActivityEvent::withoutTeamScope()->where('team_id', $b['team']->getKey())->count())->toBe(0);
});

it('fails validation rather than writing when a form names a foreign id', function (): void {
    ['a' => $a, 'b' => $b] = twoTeams();

    // A role belonging to the *other* team, offered to this team's invite form.
    $foreignRole = Role::query()->create([
        'team_id' => $b['team']->getKey(),
        'key' => 'their_custom_role',
        'name' => 'Their Custom Role',
    ]);

    $owner = Person::query()->where('id', TeamMembership::withoutTeamScope()
        ->where('team_id', $a['team']->getKey())
        ->whereHas('roles', fn ($query) => $query->where('roles.key', 'team_owner'))
        ->value('person_id'))->sole();

    $this->enrollTwoFactor($owner);
    $this->actingAsPerson($owner, $a['team']);

    $this->post('/settings/members/invitations', [
        'email' => 'someone@example.test',
        'role_id' => $foreignRole->getKey(),
    ])->assertSessionHasErrors('role_id');

    expect(TeamInvitation::withoutTeamScope()->count())->toBe(0);
});

it('refuses a signed download belonging to another team', function (): void {
    ['a' => $a, 'b' => $b] = twoTeams();

    $theirExport = app(TeamContext::class)->runFor($b['team'], fn (): DataExport => DataExport::factory()
        ->for($b['team'])
        ->create(['state' => App\Enums\DataExportState::Ready, 'disk_path' => 'exports/x.json', 'expires_at' => now()->addDay()]));

    $this->actingAsPerson($a['person'], $a['team']);

    // Signed correctly and still refused: the signature proves the link was
    // not tampered with, never that the holder is entitled to the record.
    $url = URL::temporarySignedRoute('export.download', now()->addHour(), ['export' => $theirExport->getKey()]);

    $this->get($url)->assertNotFound();
});

it('keeps a job dispatched in one team out of another', function (): void {
    ['a' => $a, 'b' => $b] = twoTeams();

    $import = app(TeamContext::class)->runFor($a['team'], fn (): ContactImport => ContactImport::factory()
        ->for($a['team'])
        ->create());

    // The job re-enters its own team, so a query inside it sees team A only.
    app(TeamContext::class)->runFor($b['team'], function () use ($import, $a, $b): void {
        $seen = null;

        (new class($import->getKey(), $seen) implements Illuminate\Contracts\Queue\ShouldQueue
        {
            use App\Jobs\Concerns\RunsForTeam;

            public function __construct(public string $importId, public mixed &$seen) {}

            public function handle(): void
            {
                $this->seen = $this->withinTeam(fn (Team $team): string => $team->getKey());
            }
        })->forTeam($a['team'])->handle();

        expect($seen)->toBe($a['team']->getKey())
            ->and($seen)->not->toBe($b['team']->getKey());
    });
});

it('throws rather than returning everybody when no team is resolved', function (): void {
    ['a' => $a] = twoTeams();

    app(TeamContext::class)->set(null);

    // ADR 0002: "a silent empty list looks like 'no deals yet' to the person
    // reading it and like a working feature to the developer who wrote it."
    expect(fn () => TeamMembership::query()->get())
        ->toThrow(MissingTeamContextException::class);
});

it('never lets mass assignment choose a tenant', function (): void {
    ['a' => $a, 'b' => $b] = twoTeams();

    app(TeamContext::class)->set($a['team']);

    // `team_id` is absent from every model's fillable list on purpose, so a
    // request body carrying one is discarded rather than honoured. The row
    // lands in the resolved team, which is the fail-closed outcome.
    $event = ActivityEvent::query()->create([
        'team_id' => $b['team']->getKey(),
        'subject_type' => (new Person)->getMorphClass(),
        'subject_id' => $a['person']->getKey(),
        'event_type' => 'test',
        'source' => 'system',
        'occurred_at' => now(),
        'summary' => 'Lands in the resolved team.',
    ]);

    expect($event->team_id)->toBe($a['team']->getKey());
});

it('refuses to write a row into a team other than the resolved one', function (): void {
    ['a' => $a, 'b' => $b] = twoTeams();

    app(TeamContext::class)->set($a['team']);

    // The vector mass assignment cannot reach: an explicit attribute set, as
    // a job or a console command would write it.
    $event = new ActivityEvent;
    $event->forceFill([
        'team_id' => $b['team']->getKey(),
        'subject_type' => (new Person)->getMorphClass(),
        'subject_id' => $a['person']->getKey(),
        'event_type' => 'test',
        'source' => 'system',
        'occurred_at' => now(),
        'summary' => 'Should not be written.',
    ]);

    expect(fn () => $event->save())
        ->toThrow(App\Support\Tenancy\CrossTenantException::class);

    expect(ActivityEvent::withoutTeamScope()->where('team_id', $b['team']->getKey())->count())->toBe(0);
});

it('refuses to move an existing row into another team', function (): void {
    ['a' => $a, 'b' => $b] = twoTeams();

    app(TeamContext::class)->set($a['team']);

    $event = ActivityEvent::query()->create([
        'subject_type' => (new Person)->getMorphClass(),
        'subject_id' => $a['person']->getKey(),
        'event_type' => 'test',
        'source' => 'system',
        'occurred_at' => now(),
        'summary' => 'Belongs to team A.',
    ]);

    $event->forceFill(['team_id' => $b['team']->getKey()]);

    expect(fn () => $event->save())
        ->toThrow(App\Support\Tenancy\CrossTenantException::class);
});

it('cannot reach the other team’s data after switching', function (): void {
    ['a' => $a, 'b' => $b] = twoTeams();

    // One person, two memberships — the case PRD §7.4 says breaks a naive
    // model. Switching must change every subsequent query.
    app(TeamContext::class)->runFor($b['team'], function () use ($a, $b): void {
        $membership = TeamMembership::query()->create([
            'team_id' => $b['team']->getKey(),
            'person_id' => $a['person']->getKey(),
            'status' => App\Enums\PersonLifecycleState::Active,
            'joined_at' => now(),
        ]);

        $membership->roles()->attach(
            Role::query()->whereNull('team_id')->where('key', 'team_member')->sole()->getKey(),
        );

        TeamMembership::query()->create([
            'team_id' => $b['team']->getKey(),
            'person_id' => Person::factory()->contactOnly()->create(['first_name' => 'OnlyInB'])->getKey(),
            'status' => App\Enums\PersonLifecycleState::Active,
            'joined_at' => now(),
        ]);
    });

    app(TeamContext::class)->runFor($a['team'], function () use ($a): void {
        TeamMembership::query()->create([
            'team_id' => $a['team']->getKey(),
            'person_id' => Person::factory()->contactOnly()->create(['first_name' => 'OnlyInA'])->getKey(),
            'status' => App\Enums\PersonLifecycleState::Active,
            'joined_at' => now(),
        ]);
    });

    $this->actingAsPerson($a['person'], $a['team']);

    $this->get('/people')->assertOk()->assertSee('OnlyInA')->assertDontSee('OnlyInB');

    $this->put('/teams/current', ['team' => $b['team']->getKey()])->assertRedirect('/dashboard');

    $this->get('/people')->assertOk()->assertSee('OnlyInB')->assertDontSee('OnlyInA');
});

it('refuses to switch into a team the person is not a member of', function (): void {
    ['a' => $a, 'b' => $b] = twoTeams();

    $this->actingAsPerson($a['person'], $a['team']);

    $this->put('/teams/current', ['team' => $b['team']->getKey()])->assertNotFound();
});
