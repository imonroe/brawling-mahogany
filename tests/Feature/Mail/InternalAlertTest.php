<?php

declare(strict_types=1);

use App\Enums\AutomationActionType;
use App\Enums\AutomationState;
use App\Mail\AutomatedMessageMail;
use App\Mail\InternalAlertMail;
use App\Models\ActionInstance;
use App\Models\Deal;
use App\Support\Automation\AlertOnFailures;
use App\Support\Automation\ExecuteAction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * S91 — the internal alert (issue #97 · F5.8).
 *
 * The failure it exists for is not *"a message failed"* — S47 and the deal's
 * timeline both record that already, and ADR 0003 leans on them. It is *"the
 * team believed the client had been told, for a fortnight."* Both existing
 * records are pull; this is the push.
 *
 * ## Round 1's finding, and why these tests are shaped as they are
 *
 * The first version raised the alert from `ExecuteAction::fail()`, and every
 * test drove the same refusal path — so the suite was green while the alert
 * never fired for a **transport exception**, which is caught in `send()` and
 * re-thrown without ever reaching `fail()`. That is the outage the whole
 * feature is written about. The alert reads `state` now, so the cases below
 * deliberately arrive at `failed` by three different routes.
 */
beforeEach(function (): void {
    Mail::fake();

    [$this->team, $this->owner] = $this->teamWithOwner();
    $this->actingAsPerson($this->owner, $this->team);

    $this->team->forceFill([
        'sandbox_mode' => false,
        'approval_required_until' => now()->subDay(),
    ])->save();

    $this->deal = Deal::factory()->create(['team_id' => $this->team->getKey()]);
});

function failingMessage(array $payload = []): ActionInstance
{
    return ActionInstance::factory()->create([
        'team_id' => test()->team->getKey(),
        'deal_id' => test()->deal->getKey(),
        'payload' => [
            ...ActionInstance::factory()->definition()['payload'],
            'recipients' => [],
            ...$payload,
        ],
    ]);
}

function carryOutFailing(ActionInstance $instance): ActionInstance
{
    app(ExecuteAction::class)->handle($instance, test()->team);

    return $instance->fresh();
}

/**
 * Sweep, a second after whatever just failed.
 *
 * The second is the point, not a workaround. The sweep reports a half-open
 * window ending at the start of the **current** second, so a failure in that
 * second belongs to the next window rather than being split across this one —
 * which is what stops a burst losing the rows that land between a sweep's
 * `SELECT` and its watermark write. In production five minutes separate the
 * two; in a test the whole scenario happens inside one second unless it is
 * moved, and a helper that swept without moving would be testing a moment the
 * scheduler never occupies.
 */
function sweepAlerts(): bool
{
    test()->travel(1)->second();

    return app(AlertOnFailures::class)->sweep(test()->team);
}

it('tells the team when an automated message has failed', function (): void {
    carryOutFailing(failingMessage());

    expect(sweepAlerts())->toBeTrue();

    Mail::assertSent(InternalAlertMail::class, function (InternalAlertMail $mail): bool {
        return $mail->hasTo($this->owner->email)
            && $mail->headline === 'An automated message needs looking at'
            && str_contains($mail->detail, 'resolved to nobody')
            && str_contains($mail->actionUrl, '/messages/');
    });

    Mail::assertNotSent(AutomatedMessageMail::class);
});

it('sees a row that failed by a route no hook ever ran on', function (): void {
    /*
     * Round 1's blocker, stated as the property that fixes it. The alert no
     * longer listens for a failure; it reads `state`. A row that arrived at
     * `failed` without anything calling `fail()` — a transport exception, a
     * branch a later slice adds, a hand-corrected row — is still a row this
     * finds.
     */
    ActionInstance::factory()->failed()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);

    expect(sweepAlerts())->toBeTrue();

    Mail::assertSent(InternalAlertMail::class);
});

it('records a transport exception as failed, where the sweep can see it', function (): void {
    /*
     * And the route that made round 1's hook useless. An expired SES
     * credential is a `TransportException` out of `Mail::send`, caught in
     * `send()` and re-thrown — it never touches `fail()`, so a hook there
     * fired for every failure except the one the feature was named after.
     */
    Mail::shouldReceive('to->send')->andThrow(new RuntimeException('535 Authentication Credentials Invalid'));

    $instance = ActionInstance::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);

    try {
        app(ExecuteAction::class)->handle($instance, $this->team);
    } catch (Throwable) {
        // Re-thrown on purpose, so the queue's own retry sees it.
    }

    expect($instance->fresh()->state)->toBe(AutomationState::Failed)
        ->and($instance->fresh()->error)->toContain('mail transport rejected');
});

it('writes a timeline entry for a transport failure, which used to be the one failure with none', function (): void {
    Mail::shouldReceive('to->send')->andThrow(new RuntimeException('535 Authentication Credentials Invalid'));

    $instance = ActionInstance::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);

    try {
        app(ExecuteAction::class)->handle($instance, $this->team);
    } catch (Throwable) {
    }

    expect(App\Models\ActivityEvent::query()
        ->where('deal_id', $this->deal->getKey())
        ->where('event_type', 'message.failed')
        ->exists())->toBeTrue();
});

it('counts the burst, because it looks after the burst rather than during it', function (): void {
    /*
     * The other half of round 1's finding. Fired from `fail()`, the alert went
     * out on the **first** failure — the moment the backlog is at its smallest
     * — so forty dead messages produced one email reporting one. The number
     * did not exist at the only instant anything was running.
     */
    foreach (range(1, 12) as $ignored) {
        carryOutFailing(failingMessage());
    }

    sweepAlerts();

    Mail::assertSent(InternalAlertMail::class, 1);

    Mail::assertSent(InternalAlertMail::class, function (InternalAlertMail $mail): bool {
        return $mail->headline === '12 automated messages need looking at'
            && str_contains($mail->detail, '11 others also need looking at.')
            && str_ends_with($mail->actionUrl, '/messages');
    });
});

it('does not say "did not go out" over the top of a message that may have arrived', function (): void {
    /*
     * `ExecuteAction::fail()` already carries a comment about this exact
     * self-contradiction on the deal's timeline: *"did not go out"* in front
     * of *"may have reached the recipient"*. Round 1 found the alert
     * reintroducing it in an inbox, one file over. The headline asserts
     * nothing about delivery now, and the detail quotes the row's own careful
     * wording.
     */
    $instance = ActionInstance::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'message_key' => (string) Illuminate\Support\Str::ulid(),
    ]);

    app(ExecuteAction::class)->reapUnconfirmed($instance);

    sweepAlerts();

    Mail::assertSent(InternalAlertMail::class, function (InternalAlertMail $mail): bool {
        return ! str_contains($mail->headline, 'did not go out')
            && str_contains($mail->detail, 'may have reached the recipient');
    });
});

it('does not call a failed task an automated message', function (): void {
    /*
     * Round 1: a `create_task` with no title emailed the team *"an automated
     * message did not go out … the transport said"*. No message was involved
     * and no transport said anything.
     */
    $instance = ActionInstance::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'action_type' => AutomationActionType::CreateTask,
        'config' => [],
    ]);

    carryOutFailing($instance);

    sweepAlerts();

    Mail::assertSent(InternalAlertMail::class, fn (InternalAlertMail $mail): bool => $mail->headline === 'An automation needs looking at');

    expect(App\Models\ActivityEvent::query()
        ->where('deal_id', $this->deal->getKey())
        ->where('summary', 'like', 'An automation did not run%')
        ->exists())->toBeTrue();
});

it('says nothing at all when nothing has failed', function (): void {
    expect(sweepAlerts())->toBeFalse();

    Mail::assertNotSent(InternalAlertMail::class);
});

it('does not tell the same team about the same failures twice', function (): void {
    /*
     * A high-water mark rather than a throttle, and the difference is what
     * stops the nag: a throttle re-alerts about the same standing backlog
     * every time it expires, forever, until somebody clears rows nobody may
     * ever clear.
     */
    carryOutFailing(failingMessage());

    expect(sweepAlerts())->toBeTrue()
        ->and(sweepAlerts())->toBeFalse();

    Mail::assertSent(InternalAlertMail::class, 1);
});

it('tells them about the next one', function (): void {
    carryOutFailing(failingMessage());
    sweepAlerts();

    $this->travel(2)->minutes();

    carryOutFailing(failingMessage());

    expect(sweepAlerts())->toBeTrue();

    Mail::assertSent(InternalAlertMail::class, 2);
});

it('does not alert on a halt, which is a message that has not been lost', function (): void {
    /*
     * F5.9's kill switch keeps a message `pending`: nothing is gone, the sweep
     * carries it when the switch lifts, and both surfaces for it say so in the
     * present tense. Emailing about a toggle somebody pulled thirty seconds
     * ago is how an alert becomes noise.
     */
    $this->team->forceFill([
        'sends_disabled_at' => now(),
        'sends_disabled_reason' => 'The team called and asked us to stop.',
    ])->save();

    carryOutFailing(failingMessage(['recipients' => [['name' => 'Dana', 'email' => 'dana@example.test']]]));

    expect(sweepAlerts())->toBeFalse();

    Mail::assertNotSent(InternalAlertMail::class);
});

it('never alerts one team about another team’s failure', function (): void {
    [$other, $otherOwner] = $this->teamWithOwner();

    carryOutFailing(failingMessage());

    sweepAlerts();

    Mail::assertSent(InternalAlertMail::class, fn (InternalAlertMail $mail): bool => ! $mail->hasTo($otherOwner->email));

    expect(app(AlertOnFailures::class)->sweep($other))->toBeFalse();
});

it('does not let a broken transport take the sweep down with it', function (): void {
    /*
     * The commonest reason an automation failed is that the transport is
     * broken, which is the commonest reason this send will throw. A sweep that
     * died on the first broken team would never reach the second — and this
     * one runs for every team on the platform.
     */
    Log::spy();

    carryOutFailing(failingMessage());

    Mail::shouldReceive('to->send')->andThrow(new RuntimeException('Connection refused'));

    expect(sweepAlerts())->toBeFalse();

    Log::shouldHaveReceived('warning')->once();
});

it('does not move the watermark past failures nobody could be told about', function (): void {
    /*
     * A team with nobody holding `message.approve` and no owner with an
     * address has no audience today and may have one tomorrow. A mark advanced
     * now would mean they were never told about this morning.
     */
    carryOutFailing(failingMessage());

    App\Models\TeamMembership::query()
        ->where('team_id', $this->team->getKey())
        ->update(['email' => null]);

    expect(sweepAlerts())->toBeFalse();

    App\Models\TeamMembership::query()
        ->where('team_id', $this->team->getKey())
        ->update(['email' => 'owner@example.test']);

    expect(sweepAlerts())->toBeTrue();
});

it('sweeps every team from the scheduled command, without a resolved tenant', function (): void {
    /*
     * The command runs for the whole platform, so it cannot run inside one
     * team's context — and both of the alert's reads are team-scoped, which
     * throws with no tenant resolved (ADR 0002, deliberately). Each team is
     * handled inside `runFor()`. Asserted from **no** context at all, which is
     * what a scheduler has.
     */
    carryOutFailing(failingMessage());

    [$other] = $this->teamWithOwner();

    app(App\Support\Tenancy\TeamContext::class)->runFor($other, function () use ($other): void {
        $deal = Deal::factory()->create(['team_id' => $other->getKey()]);

        ActionInstance::factory()->failed()->create([
            'team_id' => $other->getKey(),
            'deal_id' => $deal->getKey(),
        ]);
    });

    // What a scheduler has: no resolved tenant at all.
    app(App\Support\Tenancy\TeamContext::class)->set(null);

    // Out of the second the failures landed in — see `sweepAlerts()`.
    $this->travel(1)->second();

    $this->artisan('automations:alert-on-failures')
        ->expectsOutputToContain('Alerted 2 team(s).')
        ->assertSuccessful();

    Mail::assertSent(InternalAlertMail::class, 2);
});

it('reports the failures that land while it is sweeping, one window later', function (): void {
    /*
     * Round 2's blocker, and it is invisible to a frozen clock. `executed_at`
     * is `timestamp(0)`, so a burst puts many rows in one second — and a mark
     * set to a reported row's own timestamp, read back with a strict `>`,
     * silences every sibling that landed in that same second **after** the
     * sweep's `SELECT`. Permanently, because the mark had already moved past
     * them.
     *
     * The window ends at the start of the current second, so a row failing in
     * it is the next sweep's rather than nobody's.
     */
    carryOutFailing(failingMessage());

    sweepAlerts();

    Mail::assertSent(InternalAlertMail::class, 1);

    // A sibling in the same second the sweep just ran in.
    carryOutFailing(failingMessage());

    expect(app(AlertOnFailures::class)->sweep($this->team))->toBeFalse();

    $this->travel(1)->second();

    expect(app(AlertOnFailures::class)->sweep($this->team))->toBeTrue();

    Mail::assertSent(InternalAlertMail::class, 2);
});

it('counts a burst larger than any page it could have loaded', function (): void {
    /*
     * The first version read up to 500 rows and counted those, so a burst over
     * that size reported 500 and then moved the mark past the rest — the same
     * silence the window fixes, arriving through a `LIMIT`. The count is an
     * aggregate now, and the number below is deliberately small: what matters
     * is that nothing between the count and the mark can disagree.
     */
    $deal = $this->deal;

    ActionInstance::factory()->count(30)->failed()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $deal->getKey(),
    ]);

    sweepAlerts();

    Mail::assertSent(InternalAlertMail::class, fn (InternalAlertMail $mail): bool => str_starts_with($mail->headline, '30 '));

    // And the whole window is behind the mark, so a second sweep says nothing.
    $this->travel(1)->second();

    expect(app(AlertOnFailures::class)->sweep($this->team))->toBeFalse();
});

it('agrees with itself about the number of others', function (): void {
    ActionInstance::factory()->count(2)->failed()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);

    sweepAlerts();

    Mail::assertSent(InternalAlertMail::class, fn (InternalAlertMail $mail): bool => str_contains($mail->detail, '1 other also needs looking at.'));
});

it('remembers what it has said across a cache flush', function (): void {
    /*
     * The mark is a column, not a cache key. `resources/help/automation.md`
     * promises a team *"never twice about the same failure"*, and Redis is
     * evictable and empty after a restart — a promise that survives a flush
     * has to live where the rows live.
     */
    carryOutFailing(failingMessage());

    sweepAlerts();

    Illuminate\Support\Facades\Cache::flush();

    $this->travel(1)->second();

    expect(app(AlertOnFailures::class)->sweep($this->team))->toBeFalse();

    Mail::assertSent(InternalAlertMail::class, 1);
});

it('still tells a team about a backlog nobody could be told about last week', function (): void {
    /*
     * The empty-audience branch does not move the mark, and that promise is
     * only worth making because the mark is a column: held in a cache, an
     * unwritten mark fell back to the 24-hour cold-start floor, so a backlog
     * older than that was silenced by the very branch that claimed to be
     * preserving it.
     */
    carryOutFailing(failingMessage());

    App\Models\TeamMembership::query()
        ->where('team_id', $this->team->getKey())
        ->update(['email' => null]);

    expect(sweepAlerts())->toBeFalse();

    $this->travel(8)->days();

    App\Models\TeamMembership::query()
        ->where('team_id', $this->team->getKey())
        ->update(['email' => 'owner@example.test']);

    expect(app(AlertOnFailures::class)->sweep($this->team))->toBeTrue();
});
