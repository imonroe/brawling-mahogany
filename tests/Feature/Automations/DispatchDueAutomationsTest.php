<?php

declare(strict_types=1);

use App\Enums\AutomationState;
use App\Jobs\RunAutomation;
use App\Models\ActionInstance;
use App\Models\Deal;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Facades\Queue;

/**
 * The sweep that makes a queued message eventually happen (issue #92).
 *
 * Two jobs: a **scheduled** instance nobody dispatched at raise time, and a
 * **stranded** one left by a web process that died between committing an
 * advance and dispatching its job. Without the second, the message simply
 * never goes and nothing anywhere says so.
 *
 * It runs every minute, across every team, which is why what it *skips*
 * matters as much as what it picks up.
 */
beforeEach(function (): void {
    Queue::fake();

    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);

    $this->team->forceFill([
        'sandbox_mode' => false,
        'approval_required_until' => now()->subDay(),
    ])->save();

    $this->deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);
});

function stranded(array $attributes = []): ActionInstance
{
    $instance = ActionInstance::factory()->create([
        'team_id' => test()->team->getKey(),
        'deal_id' => test()->deal->getKey(),
        ...$attributes,
    ]);

    // Older than the sweep's one-minute floor, which exists so it cannot race
    // the dispatch the raising request is about to make.
    $instance->forceFill(['created_at' => now()->subHour()])->save();

    return $instance;
}

it('picks up a message nothing is coming for', function (): void {
    stranded();

    $this->artisan('automations:dispatch-due')->assertSuccessful();

    Queue::assertPushed(RunAutomation::class, 1);
});

it('leaves a scheduled message until it is due', function (): void {
    stranded(['scheduled_for' => now()->addDay()]);

    $this->artisan('automations:dispatch-due');

    Queue::assertNothingPushed();
});

it('never picks up a message already handed to a transport', function (): void {
    // `pending` carrying a `message_key` is the crash window. Handing it over
    // again is the one thing this sweep must never do.
    stranded(['message_key' => 'already-claimed']);

    $this->artisan('automations:dispatch-due');

    Queue::assertNothingPushed();
});

it('stops knocking on a team whose sending is switched off', function (): void {
    /*
     * Not a weakening of F5.9 — `SendRails` still decides in the worker for
     * anything in flight. This is about the sweep: a team holding 500 queued
     * messages behind a rail was generating 720,000 no-op jobs a day, each
     * doing a team lookup and two counts, for as long as the rail held.
     */
    stranded();

    $this->team->forceFill(['sends_disabled_at' => now()])->save();

    $this->artisan('automations:dispatch-due');

    Queue::assertNothingPushed();
});

it('stops knocking on a team that has hit its ceiling', function (): void {
    /*
     * The half the first version of this fix missed. `SendRails` halts for
     * three reasons and only one of them is the kill switch — a team that hits
     * its **daily** ceiling was still swept every minute for the rest of the
     * day, through the other rail, and removing the `attempts` increment
     * removed the only column that recorded it.
     */
    $this->team->forceFill(['daily_send_limit' => 2])->save();

    ActionInstance::factory()->count(2)->sent()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);

    stranded();

    $this->artisan('automations:dispatch-due');

    Queue::assertNothingPushed();
});

it('still sweeps a stranded task for a team whose sending is off', function (): void {
    /*
     * The narrowing the ceiling already carried, applied to the sweep standing
     * in front of it: `ExecuteAction` routes `create_task` straight past
     * `SendRails`, so a task reaches nobody outside the team and a switch
     * about outbound mail has no business holding it. The first version
     * excluded every action type for a halted team.
     */
    stranded(ActionInstance::factory()->creatingATask()->raw([
        'deal_id' => $this->deal->getKey(),
    ]));

    $this->team->forceFill(['sends_disabled_at' => now()])->save();

    $this->artisan('automations:dispatch-due');

    Queue::assertPushed(RunAutomation::class, 1);
});

it('does not sweep another team’s messages into this one', function (): void {
    /*
     * The sweep is the one place in the product that reads `action_instances`
     * unscoped, because it runs for every team at once. What makes that safe
     * is that it reads nothing but the id and the team, and the job
     * re-establishes that team before touching anything.
     */
    [$otherTeam] = $this->teamWithMember();

    app(TeamContext::class)->runFor($otherTeam, function () use ($otherTeam): void {
        $instance = ActionInstance::factory()->create([
            'team_id' => $otherTeam->getKey(),
            'deal_id' => Deal::factory()->create(['team_id' => $otherTeam->getKey()])->getKey(),
        ]);

        $instance->forceFill(['created_at' => now()->subHour()])->save();
    });

    stranded();

    $this->artisan('automations:dispatch-due');

    Queue::assertPushed(
        RunAutomation::class,
        fn (RunAutomation $job): bool => $job->teamId === $this->team->getKey(),
    );

    Queue::assertPushed(RunAutomation::class, 2);
});

it('leaves a cancelled message alone', function (): void {
    stranded(['state' => AutomationState::Cancelled]);

    $this->artisan('automations:dispatch-due');

    Queue::assertNothingPushed();
});
