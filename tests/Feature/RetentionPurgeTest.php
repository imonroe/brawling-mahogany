<?php

declare(strict_types=1);

use App\Actions\Teams\ScheduleTeamPurge;
use App\Enums\DataExportState;
use App\Models\ActivityEvent;
use App\Models\AuditEntry;
use App\Models\DataExport;
use App\Models\Deal;
use App\Models\DealType;
use App\Models\Person;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\Workflow;
use App\Support\Activity\RecordActivity;
use App\Support\Branding\TeamLogo;
use App\Support\Tenancy\TeamContext;
use Illuminate\Http\UploadedFile;
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

it('deletes a purged team’s logo bytes, not just the column pointing at them', function (): void {
    /*
     * `records:purge` finds a row by its `deleted_at`, and a logo has no row —
     * it is a column on `teams` and a file on the documents disk. Both go, and
     * the coverage is here rather than incidental because it depends on
     * `TeamLogo::DISK` being `DocumentStorage::DISK` rather than a string that
     * happens to match it today.
     */
    Storage::fake(TeamLogo::DISK);

    [$team, $owner] = $this->teamWithOwner();

    $this->enrollTwoFactor($owner);
    $this->actingAsPerson($owner, $team);

    $this->post('/settings/team/logo', ['logo' => UploadedFile::fake()->image('mark.png', 400, 120)]);

    $path = (string) $team->fresh()->logo_path;

    Storage::disk(TeamLogo::DISK)->assertExists($path);

    $this->freezeAt('2026-08-01 09:00:00');

    app(ScheduleTeamPurge::class)->schedule($team);

    $this->freezeAt('2026-09-15 09:00:00');

    $this->artisan('records:purge')->assertSuccessful();

    Storage::disk(TeamLogo::DISK)->assertMissing($path);
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

/**
 * `people` is the table the discovery mechanism could never find.
 *
 * `purgeableTables()` looks for `BelongsToTeam`, and `Person` deliberately
 * does not carry it — that is the shared-record decision (#18). So the one
 * table holding password hashes and two-factor secrets sat outside the 30-day
 * window entirely, and §9's *"then hard delete"* stopped at the soft delete.
 */
it('hard-deletes an account once its window closes', function (): void {
    $this->freezeAt('2026-01-01 03:00:00');

    $person = Person::factory()->create(['email' => 'gone@example.test']);
    $person->delete();

    $this->freezeAt('2026-02-05 03:00:00');

    $this->artisan('records:purge')->assertSuccessful();

    expect(Person::query()->withTrashed()->find($person->getKey()))->toBeNull();
});

it('leaves a deleted account alone inside its window', function (): void {
    $this->freezeAt('2026-01-01 03:00:00');

    $person = Person::factory()->create();
    $person->delete();

    $this->freezeAt('2026-01-20 03:00:00');

    $this->artisan('records:purge')->assertSuccessful();

    expect(Person::query()->withTrashed()->find($person->getKey()))->not->toBeNull();
});

it('leaves a live account alone however long it has been there', function (): void {
    $this->freezeAt('2020-01-01 03:00:00');

    $person = Person::factory()->create();

    $this->freezeAt('2026-08-01 03:00:00');

    $this->artisan('records:purge')->assertSuccessful();

    expect(Person::query()->find($person->getKey()))->not->toBeNull();
});

/**
 * What the schema decided, asserted rather than assumed.
 *
 * A purge is not a revocation, and the two obligations pull opposite ways:
 * F1.3 keeps a revoked person's name on everything they did, and §9 removes a
 * deleted person once the window closes. The foreign keys already choose —
 * attribution columns null, memberships and credentials cascade — and this
 * pins the choice so a later migration cannot quietly reverse it.
 */
it('leaves the team’s record of what happened, with the human removed from it', function (): void {
    [$team, $owner] = $this->teamWithOwner();
    $this->withTeam($team);

    $this->freezeAt('2026-01-01 03:00:00');

    $person = Person::factory()->create();

    $membership = TeamMembership::factory()->create([
        'team_id' => $team->getKey(),
        'person_id' => $person->getKey(),
    ]);

    $event = ActivityEvent::factory()->create([
        'team_id' => $team->getKey(),
        'actor_person_id' => $person->getKey(),
    ]);

    $person->delete();

    $this->freezeAt('2026-02-05 03:00:00');

    $this->artisan('records:purge')->assertSuccessful();

    // The event survives; the name on it does not.
    $event->refresh();
    expect($event->actor_person_id)->toBeNull();

    // The membership was nothing without the person.
    expect(TeamMembership::withoutTeamScope()->withTrashed()->find($membership->getKey()))->toBeNull();

    // And the purge itself is on the record.
    expect(AuditEntry::query()->where('action', 'person.purged')->where('auditable_id', $person->getKey())->exists())
        ->toBeTrue();

    unset($owner);
});

/**
 * Files go before rows, because a purged row cannot tell you where its file was.
 *
 * Round 2 fixed this shape for an expired export. It was still reachable
 * through a purged one: `purgeRowsFor()` hard-deleted the row whose window had
 * closed, and the sweep that ran afterwards had nothing left to find — a copy
 * of the team's whole client list still in object storage with nothing
 * anywhere pointing at it.
 */
it('takes an export’s file with it when the row is purged', function (): void {
    [$team, $owner] = $this->teamWithOwner();
    $this->withTeam($team);

    $this->freezeAt('2026-01-01 03:00:00');

    $path = 'exports/'.$team->getKey().'/archive.zip';
    Storage::put($path, 'the whole client list');

    $export = DataExport::factory()->create([
        'team_id' => $team->getKey(),
        'state' => DataExportState::Ready,
        'disk_path' => $path,
        // Not expired: the row is on its way out for a different reason.
        'expires_at' => now()->addDays(2),
    ]);

    DB::table('data_exports')->where('id', $export->getKey())->update(['deleted_at' => now()]);

    $this->freezeAt('2026-02-05 03:00:00');

    $this->artisan('records:purge')->assertSuccessful();

    expect(DB::table('data_exports')->where('id', $export->getKey())->exists())->toBeFalse()
        ->and(Storage::exists($path))->toBeFalse();

    unset($owner);
});

it('sweeps an abandoned import’s upload once the window closes', function (): void {
    [$team, $owner] = $this->teamWithOwner();
    $this->withTeam($team);

    $this->freezeAt('2026-01-01 03:00:00');

    $path = 'imports/'.$team->getKey().'/contacts.csv';
    Storage::put($path, "first_name,email\nClaire,claire@example.test\n");

    // Reviewed and then never finished — the state the file outlives.
    $import = App\Models\ContactImport::factory()->create([
        'team_id' => $team->getKey(),
        'state' => App\Enums\ContactImportState::AwaitingReview,
        'disk_path' => $path,
        'preview' => [['row' => 1, 'first_name' => 'Claire', 'email' => 'claire@example.test']],
    ]);

    $this->freezeAt('2026-02-05 03:00:00');

    $this->artisan('records:purge')->assertSuccessful();

    $import->refresh();

    expect(Storage::exists($path))->toBeFalse()
        ->and($import->disk_path)->toBeNull()
        ->and($import->preview)->toBeNull();

    unset($owner);
});

it('does not take a person’s contact log with a purged deal', function (): void {
    /*
     * `activity_events.deal_id` is a `teamScopedForeign`, so it cascades — and
     * that is right for an event *about* the deal. It is wrong for a contact
     * logged against a **person**, where F2.5 makes the deal context rather
     * than ownership: the client is still in the directory and the call still
     * happened, but thirty days after an unrelated deal was purged their
     * history silently lost entries.
     */
    [$team, $member] = $this->teamWithMember();

    $this->actingAsPerson($member, $team);

    [$contactLog, $dealsOwn, $workflowsOwn] = app(TeamContext::class)->runFor($team, function () use ($team, $member): array {
        $type = DealType::factory()->create(['team_id' => $team->getKey()]);
        $deal = Deal::factory()->create([
            'team_id' => $team->getKey(),
            'deal_type_id' => $type->getKey(),
        ]);

        $membership = TeamMembership::query()->where('person_id', $member->getKey())->sole();

        // Subject is the person; the deal is context.
        $contactLog = app(RecordActivity::class)->record(
            subject: $membership,
            eventType: 'contact.logged',
            summary: 'Called about the inspection.',
            deal: $deal,
        );

        // Subject is the deal; this is the deal's own record of itself.
        $dealsOwn = app(RecordActivity::class)->record(
            subject: $deal,
            eventType: 'deal.created',
            summary: 'Created the deal.',
        );

        /*
         * Subject is a **workflow** on the deal. The first version of the
         * detach kept everything whose subject was not the deal itself, so
         * this survived pointing at a `workflows` row that no longer existed —
         * an orphan `ActivityFeed::subject()` has no branch for. Worse, the
         * feed hides deal-context rows from a viewer without `deals.view` with
         * `whereNull('deal_id')`, so nulling the column moved it *into* their
         * feed.
         */
        $workflowsOwn = app(RecordActivity::class)->record(
            subject: Workflow::factory()->create([
                'team_id' => $team->getKey(),
                'deal_id' => $deal->getKey(),
            ]),
            eventType: 'stage.advanced',
            summary: 'Advanced to Under Contract.',
            deal: $deal,
        );

        $deal->delete();

        return [$contactLog, $dealsOwn, $workflowsOwn];
    });

    $this->travel(31)->days();

    $this->artisan('records:purge')->assertSuccessful();

    // The contact survives, with the deal reference dropped rather than the row.
    $survivor = ActivityEvent::withoutTeamScope()->find($contactLog->getKey());

    expect($survivor)->not->toBeNull()
        ->and($survivor->deal_id)->toBeNull()
        // The control: the deal's own event does go, so this is not passing on
        // a purge that deleted nothing.
        ->and(ActivityEvent::withoutTeamScope()->find($dealsOwn->getKey()))->toBeNull()
        // And so does the workflow's. Only a subject the team still holds — a
        // person — keeps its row, because only there is the deal context
        // rather than ownership.
        ->and(ActivityEvent::withoutTeamScope()->find($workflowsOwn->getKey()))->toBeNull();
});

it('purges a read notification once its window closes, and keeps an unread one', function (): void {
    /*
     * The third staging sweep (#101). Nothing ever soft-deletes a
     * notification, so `purgeRowsFor()` — which finds rows by `deleted_at` —
     * would never reach this table, and it sits under `ShellCounts`' unread
     * count on every request in the product.
     *
     * **Read ones only**: an unread notification is still doing its job
     * however old it is, and deleting one would answer *"has anybody been
     * told?"* by quietly making it no.
     */
    [$team, $member] = $this->teamWithMember();

    [$read, $unread, $recent] = app(TeamContext::class)->runFor($team, function () use ($team, $member): array {
        $read = App\Models\Notification::factory()->create([
            'team_id' => $team->getKey(),
            'person_id' => $member->getKey(),
            'read_at' => now(),
        ]);

        $unread = App\Models\Notification::factory()->create([
            'team_id' => $team->getKey(),
            'person_id' => $member->getKey(),
            'read_at' => null,
        ]);

        return [$read, $unread, null];
    });

    $this->travel(31)->days();

    $this->artisan('records:purge')->assertSuccessful();

    expect(App\Models\Notification::withoutTeamScope()->find($read->getKey()))->toBeNull()
        ->and(App\Models\Notification::withoutTeamScope()->find($unread->getKey()))->not->toBeNull()
        ->and($recent)->toBeNull();
});

it('bounds every notification delete by the team it is purging', function (): void {
    /*
     * **The statement, not the outcome**, and that is the point.
     *
     * `Builder::forceDelete()` is `return $this->query->delete()` — the base
     * builder — so it never applies `TeamScope`, and round 2 of review caught
     * this sweep shipping that way. The rows that end up gone are the same
     * set either way, because the caller loops every team, so no assertion
     * about survivors can tell the two apart. What differs is whether
     * `records:purge` is a destructive write bounded by its own SQL or by the
     * shape of its caller.
     *
     * `UnscopedQueryConventionTest` cannot see this: it reads for
     * `withoutTeamScope` and `withoutGlobalScope`, and `forceDelete()` spells
     * neither.
     */
    [$teamA, $memberA] = $this->teamWithMember();
    [$teamB, $memberB] = $this->teamWithMember();

    foreach ([[$teamA, $memberA], [$teamB, $memberB]] as [$team, $member]) {
        app(TeamContext::class)->runFor($team, function () use ($team, $member): void {
            App\Models\Notification::factory()->create([
                'team_id' => $team->getKey(),
                'person_id' => $member->getKey(),
                'read_at' => now(),
            ]);
        });
    }

    $this->travel(31)->days();

    DB::enableQueryLog();

    $this->artisan('records:purge')->assertSuccessful();

    /*
     * **On `read_at`, not on the table name**, and round 3 of review is why.
     * `purgeableTables()` discovers `notifications` too, so `purgeRowsFor()`
     * issues its own `delete from "notifications" … "deleted_at" …` per team
     * whether or not a row matches — measured at four statements with no
     * notifications in the database at all. Matching the table alone meant the
     * control below stayed green with this sweep deleted entirely.
     */
    $deletes = array_values(array_filter(
        DB::getQueryLog(),
        static fn (array $entry): bool => str_contains($entry['query'], 'delete from "notifications"')
            && str_contains($entry['query'], '"read_at"'),
    ));

    DB::disableQueryLog();

    // The control: if this sweep stopped running, the loop below would assert
    // nothing and the test would pass against a deleted method.
    expect($deletes)->not->toBeEmpty();

    foreach ($deletes as $entry) {
        expect($entry['query'])->toContain('"team_id"');
    }
});
