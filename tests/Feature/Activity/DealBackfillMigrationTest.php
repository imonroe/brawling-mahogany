<?php

declare(strict_types=1);

use App\Enums\ActivitySource;
use App\Models\Deal;
use App\Models\Workflow;
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
 * Two ways in, because they hold different things.
 *
 * `runWholeMigration()` takes the column away and puts it back — `down()` then
 * `up()` — which is the only way to test that `up()` *calls* the backfill at
 * all. Reflection alone holds the SQL and not the wiring: dropping the call
 * from `up()` left the whole suite green.
 *
 * `runDealBackfill()` reaches the methods directly, for the cases that need a
 * starting state `down()` would destroy — a row that already names a deal
 * cannot survive a column drop.
 */
function backfillMigration(): object
{
    return require database_path('migrations/2026_08_23_160000_add_deal_to_activity_events.php');
}

/** The real thing: drop the column, re-add it, and let `up()` do the rest. */
function runWholeMigration(): void
{
    $migration = backfillMigration();

    $migration->down();
    $migration->up();
}

/** Just the backfill statements, for a starting state a column drop would lose. */
function runDealBackfill(): void
{
    $migration = backfillMigration();

    foreach (['backfillDealSubjects', 'backfillWorkflowSubjects'] as $method) {
        (new ReflectionMethod($migration, $method))->invoke($migration);
    }
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

    // `down()` drops the column, which is exactly the state the table was in
    // before this migration — so `up()` has the job it will really have.
    runWholeMigration();

    expect(DB::table('activity_events')->where('id', $event->getKey())->value('deal_id'))
        ->toBe($deal->getKey());
});

/**
 * And a workflow-subject event, which is the half that is easy to forget.
 *
 * `stage.advanced` is subjected to the workflow, one hop from the deal. S15's
 * activity card reads by `deal_id`, and an advance is the single entry a
 * person opening that screen most wants to see — so a null here is an advance
 * missing from its own deal. The retention purge reads the same column to
 * decide what a purged deal takes with it, so a null is also an orphan.
 */
it('gives a pre-existing workflow-subject event its deal back', function (): void {
    $deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);

    $workflow = Workflow::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $deal->getKey(),
    ]);

    $event = app(RecordActivity::class)->record(
        subject: $workflow,
        eventType: 'stage.advanced',
        summary: 'Emily advanced the stage.',
        source: ActivitySource::Manual,
        deal: $deal,
    );

    runWholeMigration();

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
 * And the same guard on the workflow statement, which inherited the SQL.
 *
 * The two statements are the same shape and the second one arrived without
 * this test — which is how the first half of this migration got written and
 * the second half got written differently. An `activity_events` row pointing
 * at another team's workflow would take that workflow's `deal_id`, and the
 * composite key would then abort the migration on an FK violation partway
 * through a deploy.
 */
it('will not attach an event to a workflow in another team', function (): void {
    [$otherTeam] = $this->teamWithMember();

    $theirWorkflow = app(TeamContext::class)->runFor($otherTeam, function () use ($otherTeam): Workflow {
        $theirDeal = Deal::factory()->create(['team_id' => $otherTeam->getKey()]);

        return Workflow::factory()->create([
            'team_id' => $otherTeam->getKey(),
            'deal_id' => $theirDeal->getKey(),
        ]);
    });

    $deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);

    $workflow = Workflow::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $deal->getKey(),
    ]);

    $event = app(RecordActivity::class)->record(
        subject: $workflow,
        eventType: 'stage.advanced',
        summary: 'Emily advanced the stage.',
        source: ActivitySource::Manual,
        deal: $deal,
    );

    // A row that points across the tenant boundary — the shape the guard is for.
    DB::table('activity_events')->where('id', $event->getKey())->update([
        'deal_id' => null,
        'subject_id' => $theirWorkflow->getKey(),
    ]);

    runDealBackfill();

    expect(DB::table('activity_events')->where('id', $event->getKey())->value('deal_id'))->toBeNull();
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
