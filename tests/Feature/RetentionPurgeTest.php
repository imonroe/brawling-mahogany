<?php

declare(strict_types=1);

use App\Actions\Teams\ScheduleTeamPurge;
use App\Enums\DataExportState;
use App\Models\ActivityEvent;
use App\Models\AuditEntry;
use App\Models\DataExport;
use App\Models\Person;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Soft-delete retention and the 30-day purge (PRD §9 Deletion · issue #57).
 *
 * *"Soft delete with a 30-day recovery window, then hard delete. Team deletion
 * purges within 30 days."*
 */
it('leaves a soft-deleted record alone inside the window', function (): void {
    [$team, $member] = $this->teamWithMember();

    $this->withTeam($team);

    $this->freezeAt('2026-08-01 03:00:00');

    $membership = TeamMembership::factory()->create([
        'team_id' => $team->getKey(),
        'person_id' => Person::factory()->contactOnly()->create()->getKey(),
    ]);

    $membership->delete();

    $this->freezeAt('2026-08-30 03:00:00');

    $this->artisan('records:purge')->assertSuccessful();

    expect(TeamMembership::withoutTeamScope()->withTrashed()->find($membership->getKey()))->not->toBeNull();

    unset($member);
});

it('hard-deletes it once the window closes', function (): void {
    [$team] = $this->teamWithMember();

    $this->withTeam($team);

    $this->freezeAt('2026-08-01 03:00:00');

    $membership = TeamMembership::factory()->create([
        'team_id' => $team->getKey(),
        'person_id' => Person::factory()->contactOnly()->create()->getKey(),
    ]);

    $membership->delete();

    $this->freezeAt('2026-09-01 03:00:00');

    $this->artisan('records:purge')->assertSuccessful();

    expect(TeamMembership::withoutTeamScope()->withTrashed()->find($membership->getKey()))->toBeNull();
});

it('leaves a live record alone however long it has been there', function (): void {
    [$team] = $this->teamWithMember();

    $this->withTeam($team);

    $this->freezeAt('2020-01-01 03:00:00');

    $membership = TeamMembership::factory()->create([
        'team_id' => $team->getKey(),
        'person_id' => Person::factory()->contactOnly()->create()->getKey(),
    ]);

    $this->freezeAt('2026-09-01 03:00:00');

    $this->artisan('records:purge')->assertSuccessful();

    expect(TeamMembership::withoutTeamScope()->find($membership->getKey()))->not->toBeNull();
});

it('schedules a team purge rather than performing one, and can be called off', function (): void {
    [$team] = $this->teamWithMember();

    $this->freezeAt('2026-08-01 09:00:00');

    app(ScheduleTeamPurge::class)->schedule($team);

    $team->refresh();

    // A customer who leaves on Friday and changes their mind on Monday should
    // still have their deals.
    expect($team->purge_after?->toDateString())->toBe('2026-08-31')
        ->and($team->isSuspended())->toBeTrue();

    $this->freezeAt('2026-08-15 09:00:00');

    $this->artisan('records:purge')->assertSuccessful();

    expect(Team::query()->find($team->getKey()))->not->toBeNull();

    app(ScheduleTeamPurge::class)->cancel($team);

    $this->freezeAt('2026-09-15 09:00:00');

    $this->artisan('records:purge')->assertSuccessful();

    expect(Team::query()->find($team->getKey()))->not->toBeNull();
});

it('leaves no rows behind when the window closes on a team', function (): void {
    [$team, $member] = $this->teamWithMember();

    $this->withTeam($team);

    TeamMembership::factory()->create([
        'team_id' => $team->getKey(),
        'person_id' => Person::factory()->contactOnly()->create()->getKey(),
    ]);

    ActivityEvent::factory()->create(['team_id' => $team->getKey()]);

    $this->freezeAt('2026-08-01 09:00:00');

    app(ScheduleTeamPurge::class)->schedule($team);

    $this->freezeAt('2026-09-15 09:00:00');

    $this->artisan('records:purge')->assertSuccessful();

    expect(Team::query()->withTrashed()->find($team->getKey()))->toBeNull()
        ->and(DB::table('team_memberships')->where('team_id', $team->getKey())->count())->toBe(0)
        ->and(DB::table('activity_events')->where('team_id', $team->getKey())->count())->toBe(0);

    // The shared person survives: another team may still know them, and a
    // human is not a tenant's property.
    expect(Person::query()->find($member->getKey()))->not->toBeNull();
});

it('deletes an expired export’s file, not just its row', function (): void {
    // The archive is a copy of the team's whole record set. Marking the row
    // expired while leaving the file is PRD §9's deletion policy honoured on
    // paper only.
    [$team, $owner] = $this->teamWithOwner();

    $this->enrollTwoFactor($owner);
    $this->actingAsPerson($owner, $team);

    $this->post('/settings/export');

    $export = DataExport::query()->sole();
    $path = (string) $export->disk_path;

    expect(Storage::exists($path))->toBeTrue();

    $export->forceFill(['expires_at' => now()->subDay()])->save();

    $this->artisan('records:purge')->assertSuccessful();

    expect(Storage::exists($path))->toBeFalse()
        ->and($export->fresh()->disk_path)->toBeNull()
        ->and($export->fresh()->state)->toBe(DataExportState::Expired);
});

it('leaves a live export alone', function (): void {
    [$team, $owner] = $this->teamWithOwner();

    $this->enrollTwoFactor($owner);
    $this->actingAsPerson($owner, $team);

    $this->post('/settings/export');

    $path = (string) DataExport::query()->sole()->disk_path;

    $this->artisan('records:purge')->assertSuccessful();

    expect(Storage::exists($path))->toBeTrue();
});

it('leaves no files behind when a team is purged', function (): void {
    // Issue #57: "A purged team leaves no rows and no files." Object storage
    // does not cascade, so the archives outlived everything that named them.
    [$team, $owner] = $this->teamWithOwner();

    $this->enrollTwoFactor($owner);
    $this->actingAsPerson($owner, $team);

    $this->post('/settings/export');

    $path = (string) DataExport::query()->sole()->disk_path;

    expect(Storage::exists($path))->toBeTrue();

    $this->freezeAt('2026-08-01 09:00:00');

    app(ScheduleTeamPurge::class)->schedule($team);

    $this->freezeAt('2026-09-15 09:00:00');

    $this->artisan('records:purge')->assertSuccessful();

    expect(Storage::exists($path))->toBeFalse()
        ->and(Team::query()->withTrashed()->find($team->getKey()))->toBeNull();
});

it('keeps the audit trail of the purge, which is the proof it happened', function (): void {
    [$team] = $this->teamWithMember();

    $this->freezeAt('2026-08-01 09:00:00');

    app(ScheduleTeamPurge::class)->schedule($team);

    $this->freezeAt('2026-09-15 09:00:00');

    $this->artisan('records:purge')->assertSuccessful();

    // `audit_log` carries no foreign key to `teams` for exactly this reason.
    $entry = AuditEntry::query()->where('action', 'team.purged')->sole();

    expect($entry->team_id)->toBe($team->getKey());
});

it('runs with no ambient team of its own', function (): void {
    // ADR 0002: "Scheduled commands iterate teams explicitly. There is no
    // ambient team." The command must work from a cold container.
    [$teamA] = $this->teamWithMember();
    [$teamB] = $this->teamWithMember();

    app(TeamContext::class)->set(null);

    $this->artisan('records:purge')->assertSuccessful();

    expect(app(TeamContext::class)->get())->toBeNull();

    unset($teamA, $teamB);
});
