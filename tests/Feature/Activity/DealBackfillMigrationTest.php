<?php

declare(strict_types=1);

use App\Enums\ActivitySource;
use App\Models\Deal;
use App\Support\Activity\RecordActivity;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\DB;

/**
 * The `deal_id` backfill (issue #81).
 *
 * `RecordActivity` derives `deal_id` from a deal subject for every row it
 * writes from now on, and the migration applies the same derivation backwards
 * — otherwise the column is only correct for events written after the deploy.
 * `dev` has had deals since #59, so without the backfill S15's activity card
 * and S12's deal filter open on a history that begins the day the column did.
 *
 * The migration's `up()` cannot be re-run against a migrated database (the
 * column is already there), so the backfill is invoked directly. That is the
 * point of it being its own method: the SQL under test is the SQL that ships,
 * not a copy of it written into the test.
 */
function runDealBackfill(): void
{
    $migration = require database_path('migrations/2026_08_23_160000_add_deal_to_activity_events.php');

    (new ReflectionMethod($migration, 'backfillDealSubjects'))->invoke($migration);
}

beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);
});

it('gives a pre-existing deal-subject event its deal back', function (): void {
    $deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);

    $event = app(RecordActivity::class)->record(
        subject: $deal,
        eventType: 'deal.created',
        summary: 'Emily created the deal.',
        source: ActivitySource::Manual,
    );

    // The state the table was in before the column existed.
    DB::table('activity_events')->where('id', $event->getKey())->update(['deal_id' => null]);

    runDealBackfill();

    expect(DB::table('activity_events')->where('id', $event->getKey())->value('deal_id'))
        ->toBe($deal->getKey());
});

/**
 * The negative half, and the one that makes the positive half mean something.
 *
 * A backfill that set `deal_id = subject_id` unconditionally would pass the
 * test above and put a person's id in a foreign key column.
 */
it('leaves an event whose subject is not a deal alone', function (): void {
    $event = app(RecordActivity::class)->record(
        subject: $this->member,
        eventType: 'person.updated',
        summary: 'Emily updated the record.',
        source: ActivitySource::Manual,
    );

    runDealBackfill();

    expect(DB::table('activity_events')->where('id', $event->getKey())->value('deal_id'))->toBeNull();
});

/**
 * S26's shape survives: a person subject with a deal as context.
 *
 * PRD F2.5 logs a contact against a *person* and optionally a deal, so
 * `subject_id` is the person's and `deal_id` is already right. It is the join
 * that protects this, not the `deal_id is null` guard — a bare
 * `set deal_id = subject_id` would put a person's id in the column and the
 * composite foreign key would abort the migration.
 */
it('does not touch a row whose subject is a person', function (): void {
    $deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);

    $event = app(RecordActivity::class)->record(
        subject: $this->member,
        eventType: 'contact.logged',
        summary: 'Emily called the client.',
        source: ActivitySource::Manual,
        deal: $deal,
    );

    runDealBackfill();

    expect(DB::table('activity_events')->where('id', $event->getKey())->value('deal_id'))
        ->toBe($deal->getKey());
});

/**
 * The join is on the team as well as the id.
 *
 * The composite foreign key would reject a cross-team pair anyway — but it
 * would reject it by aborting the migration halfway through a deploy. An
 * anomalous row is left alone instead.
 */
it('will not attach an event to another team\'s deal', function (): void {
    [$otherTeam] = $this->teamWithMember();

    $theirDeal = app(TeamContext::class)->runFor(
        $otherTeam,
        fn (): Deal => Deal::factory()->create(['team_id' => $otherTeam->getKey()]),
    );

    $mine = Deal::factory()->create(['team_id' => $this->team->getKey()]);

    $event = app(RecordActivity::class)->record(
        subject: $mine,
        eventType: 'deal.created',
        summary: 'Emily created the deal.',
        source: ActivitySource::Manual,
    );

    // A row that points across the tenant boundary — the shape the guard is for.
    DB::table('activity_events')->where('id', $event->getKey())->update([
        'deal_id' => null,
        'subject_id' => $theirDeal->getKey(),
    ]);

    runDealBackfill();

    expect(DB::table('activity_events')->where('id', $event->getKey())->value('deal_id'))->toBeNull();
});

/**
 * The `deal_id is null` guard, and the only case that needs it.
 *
 * A caller may file an event under one deal while its subject is another —
 * `RecordActivity` takes both, and the explicit `$deal` deliberately wins over
 * the derivation. Backfilling without the guard would overwrite that choice
 * with the subject, which is the one thing a backfill must never do: it exists
 * to fill in what nobody said, not to correct what somebody did.
 */
it('does not overwrite a deal somebody chose explicitly', function (): void {
    $subject = Deal::factory()->create(['team_id' => $this->team->getKey()]);
    $filedUnder = Deal::factory()->create(['team_id' => $this->team->getKey()]);

    $event = app(RecordActivity::class)->record(
        subject: $subject,
        eventType: 'deal.linked',
        summary: 'Emily linked the two deals.',
        source: ActivitySource::Manual,
        deal: $filedUnder,
    );

    expect(DB::table('activity_events')->where('id', $event->getKey())->value('deal_id'))
        ->toBe($filedUnder->getKey());

    runDealBackfill();

    expect(DB::table('activity_events')->where('id', $event->getKey())->value('deal_id'))
        ->toBe($filedUnder->getKey());
});
