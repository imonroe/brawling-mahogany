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
 * Sweep, from far enough away that the failures are settled.
 *
 * The distance is the point, not a workaround. The sweep's window ends
 * `VISIBILITY_LAG_SECONDS` **behind** it, because a row stamped in PHP on
 * another host and committed a moment later is below any boundary taken at the
 * instant of the sweep — so a boundary at `now()` walks over rows that were
 * never visible to it. In production five minutes separate a failure from the
 * sweep that reports it; in a test the whole scenario happens inside one second
 * unless it is moved, and a helper that swept without moving would be testing a
 * moment the scheduler never occupies.
 */
function settleAndSweep(): bool
{
    test()->travel(AlertOnFailures::VISIBILITY_LAG_SECONDS + 1)->seconds();

    return app(AlertOnFailures::class)->sweep(test()->team);
}

it('tells the team when an automated message has failed', function (): void {
    carryOutFailing(failingMessage());

    expect(settleAndSweep())->toBeTrue();

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

    expect(settleAndSweep())->toBeTrue();

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

    settleAndSweep();

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

    settleAndSweep();

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

    settleAndSweep();

    Mail::assertSent(InternalAlertMail::class, fn (InternalAlertMail $mail): bool => $mail->headline === 'An automation needs looking at');

    expect(App\Models\ActivityEvent::query()
        ->where('deal_id', $this->deal->getKey())
        ->where('summary', 'like', 'An automation did not run%')
        ->exists())->toBeTrue();
});

it('says nothing at all when nothing has failed', function (): void {
    expect(settleAndSweep())->toBeFalse();

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

    expect(settleAndSweep())->toBeTrue()
        ->and(settleAndSweep())->toBeFalse();

    Mail::assertSent(InternalAlertMail::class, 1);
});

it('tells them about the next one', function (): void {
    carryOutFailing(failingMessage());
    settleAndSweep();

    $this->travel(2)->minutes();

    carryOutFailing(failingMessage());

    expect(settleAndSweep())->toBeTrue();

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

    expect(settleAndSweep())->toBeFalse();

    Mail::assertNotSent(InternalAlertMail::class);
});

it('never alerts one team about another team’s failure', function (): void {
    [$other, $otherOwner] = $this->teamWithOwner();

    carryOutFailing(failingMessage());

    settleAndSweep();

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

    expect(settleAndSweep())->toBeFalse();

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

    expect(settleAndSweep())->toBeFalse();

    App\Models\TeamMembership::query()
        ->where('team_id', $this->team->getKey())
        ->update(['email' => 'owner@example.test']);

    expect(settleAndSweep())->toBeTrue();
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

    // Far enough past the failures for them to be settled — see `settleAndSweep()`.
    $this->travel(AlertOnFailures::VISIBILITY_LAG_SECONDS + 1)->seconds();

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

    settleAndSweep();

    Mail::assertSent(InternalAlertMail::class, 1);

    // A sibling in the same second the sweep just ran in.
    carryOutFailing(failingMessage());

    expect(app(AlertOnFailures::class)->sweep($this->team))->toBeFalse();

    $this->travel(AlertOnFailures::VISIBILITY_LAG_SECONDS + 1)->seconds();

    expect(app(AlertOnFailures::class)->sweep($this->team))->toBeTrue();

    Mail::assertSent(InternalAlertMail::class, 2);
});

it('reports every failure in the window and then nothing more', function (): void {
    /*
     * The count and the mark have to describe the same set. An earlier version
     * read a page of 500 rows and counted those while the mark moved to the
     * end of the window, so a burst over that size reported 500 and silenced
     * the rest — the same silence the window fixes, arriving through a `LIMIT`.
     * The count is an aggregate now, so the number below is deliberately small:
     * what is being asserted is that the two agree, not that 500 is enough.
     */
    $deal = $this->deal;

    ActionInstance::factory()->count(30)->failed()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $deal->getKey(),
    ]);

    settleAndSweep();

    Mail::assertSent(InternalAlertMail::class, fn (InternalAlertMail $mail): bool => str_starts_with($mail->headline, '30 '));

    // And the whole window is behind the mark, so a second sweep says nothing.
    $this->travel(AlertOnFailures::VISIBILITY_LAG_SECONDS + 1)->seconds();

    expect(app(AlertOnFailures::class)->sweep($this->team))->toBeFalse();
});

it('agrees with itself about the number of others', function (): void {
    ActionInstance::factory()->count(2)->failed()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);

    settleAndSweep();

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

    settleAndSweep();

    Illuminate\Support\Facades\Cache::flush();

    $this->travel(AlertOnFailures::VISIBILITY_LAG_SECONDS + 1)->seconds();

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

    expect(settleAndSweep())->toBeFalse();

    $this->travel(8)->days();

    App\Models\TeamMembership::query()
        ->where('team_id', $this->team->getKey())
        ->update(['email' => 'owner@example.test']);

    expect(app(AlertOnFailures::class)->sweep($this->team))->toBeTrue();
});

it('reports a failure stamped by a clock that was running behind', function (): void {
    /*
     * Round 3's blocker. `executed_at` is stamped in PHP by whichever process
     * wrote the row — a queue worker, on a host that is not the scheduler's —
     * and it becomes visible at COMMIT rather than at assignment. So a row can
     * carry a timestamp *below* a boundary that a `count()` taken at that
     * boundary could not see, and a mark set to the sweep's own instant walks
     * over it forever.
     *
     * `onOneServer` does not help: it pins the scheduler, not the writers.
     * Neither does a clock running backwards — a worker one second slow is
     * enough.
     *
     * The boundary sits `VISIBILITY_LAG_SECONDS` behind the sweep for exactly
     * this row.
     */
    carryOutFailing(failingMessage());

    expect(settleAndSweep())->toBeTrue();

    Mail::assertSent(InternalAlertMail::class, 1);

    /*
     * A second failure stamped **before the instant that sweep ran at** — what
     * a worker on a slow clock writes, and what a late commit makes visible.
     *
     * Anchored to the sweep's own instant and not to the boundary it stored,
     * which is the difference between a test that reproduces this and one that
     * moves with the fix: with the lag the boundary is a minute behind the
     * sweep, so this row is comfortably ahead of the mark. Without it the
     * boundary *is* the sweep's instant, and this row is already below the
     * mark and can never be reported.
     */
    $sweptAt = now();

    ActionInstance::factory()->failed()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'executed_at' => $sweptAt->copy()->subSeconds(30),
    ]);

    expect(settleAndSweep())->toBeTrue();

    Mail::assertSent(InternalAlertMail::class, 2);
});

it('says nothing about a failed row that never recorded when it failed', function (): void {
    /*
     * The window keys on `executed_at` and deliberately **not** on
     * `COALESCE(executed_at, updated_at)`. The coalesce was a round-2 answer to
     * a narrower claim and bought the wrong thing: `updated_at` moves on any
     * save, so a row with no `executed_at` is dragged in front of the mark
     * every time anything touches it — an email about the same row every five
     * minutes, against a promise `automation.md` makes in the words *never
     * twice about the same failure*.
     *
     * The hole the coalesce covered is not reachable: `ExecuteAction::fail()`
     * is the only writer of `failed` and sets both columns in one statement.
     * For a row made by hand, silence is the better of the two wrong answers.
     */
    ActionInstance::factory()->failed()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
        'executed_at' => null,
    ]);

    expect(settleAndSweep())->toBeFalse();

    Mail::assertNotSent(InternalAlertMail::class);

    // And it does not start being reported when somebody touches the row.
    ActionInstance::query()->update(['error' => 'Somebody annotated this row.']);

    expect(settleAndSweep())->toBeFalse();

    Mail::assertNotSent(InternalAlertMail::class);
});

it('anchors the cold-start floor on the first sweep, before there is anything to say', function (): void {
    /*
     * Round 4's blocker, and it is round 2's — a floor relative to `now()`
     * slides forward with every sweep — arriving in the branch **every healthy
     * team takes on every sweep**. The no-audience branch had the anchor; this
     * one did not, so a team that had never had a failure kept re-deriving the
     * floor and lost anything older than it.
     */
    expect(settleAndSweep())->toBeFalse();

    expect($this->team->fresh()->automation_alerted_through)->not->toBeNull();
});

it('tells a team about a failure the sweep did not run in time to see', function (): void {
    /*
     * The loss the anchor prevents, end to end. Nothing is wrong on day zero;
     * a message dies an hour later; the sweep does not run for three days — a
     * deploy that drops the cron entry, a container down over a weekend, or
     * `withoutOverlapping()`'s own 1440-minute mutex, which is exactly the
     * cold-start floor and therefore has no margin at all.
     *
     * With the floor sliding, that failure is silently and permanently
     * invisible.
     */
    expect(settleAndSweep())->toBeFalse();

    $this->travel(1)->hours();

    ActionInstance::factory()->failed()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);

    $this->travel(3)->days();

    expect(app(AlertOnFailures::class)->sweep($this->team))->toBeTrue();

    Mail::assertSent(InternalAlertMail::class, 1);
});

it('keeps the cold-start floor further back than the boundary is lagged', function (): void {
    /*
     * The two constants have to stay in this order. If the floor were ever set
     * below the lag, `$since` would exceed `$through` on a fresh team and the
     * sweep would return early on every run — reporting nothing, forever, with
     * no error anywhere. They are the kind of pair somebody tunes during an
     * incident, so the relationship is asserted rather than left in a comment.
     */
    expect(AlertOnFailures::COLD_START_HOURS * 3600)
        ->toBeGreaterThan(AlertOnFailures::VISIBILITY_LAG_SECONDS);
});

it('gives its own newest-failure pick a tiebreaker too', function (): void {
    /*
     * `ApprovalQueueTest` asserts the same rule over the three sorts
     * `/messages` issues, and round 5 found that this fourth one — the sweep's
     * own *which failure do I name* — was outside its reach, while the reply
     * claiming otherwise was not. A guard that covers three of four is a guard
     * for the fourth's next editor to walk past.
     *
     * `executed_at` is `timestamp(0)` and a burst puts many rows in one second,
     * so without a tiebreaker the failure the alert names changes between two
     * runs over identical data.
     */
    $sorts = [];

    Illuminate\Support\Facades\DB::listen(function ($query) use (&$sorts): void {
        if (str_contains($query->sql, 'from "action_instances"') && str_contains($query->sql, 'order by')) {
            $sorts[] = mb_substr($query->sql, (int) mb_strpos($query->sql, 'order by'));
        }
    });

    carryOutFailing(failingMessage());

    settleAndSweep();

    expect($sorts)->not->toBeEmpty();

    foreach ($sorts as $sort) {
        expect($sort)->toContain('"id"');
    }
});

it('tells the team about a bounce, which is not a failure of the send', function (): void {
    /*
     * S91's second state (#95 · F5.8: *"bounces suppress and alert"*).
     *
     * The row this reports is `sent` and correctly so — the message was
     * written and handed over, and the mailbox rejected it afterwards. So it
     * is invisible to a sweep reading `action_instances.state`, which is the
     * whole reason `message_deliveries` gets its own half of the window rather
     * than a flag on the instance.
     */
    $instance = ActionInstance::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);

    $delivery = App\Models\MessageDelivery::factory()->create([
        'team_id' => $this->team->getKey(),
        'action_instance_id' => $instance->getKey(),
        'recipient_email' => 'dana@example.test',
    ]);

    $delivery->advanceTo(App\Enums\DeliveryStatus::Bounced, now(), 'smtp; 550 5.1.1 user unknown');

    expect(settleAndSweep())->toBeTrue();

    Mail::assertSent(InternalAlertMail::class, function (InternalAlertMail $mail) use ($instance): bool {
        return $mail->headline === 'An automated message needs looking at'
            && str_contains($mail->detail, 'could not be delivered')
            // Plain language, never the protocol — #95 names `SMTP 550` as the
            // thing an agent must not be handed.
            && ! str_contains($mail->detail, '550')
            /*
             * And **no address**. An internal alert is forwarded, quoted and
             * left sitting in inboxes; a client's email address belongs on
             * S49, behind the link, in front of somebody looking at one deal
             * (PRD §9).
             */
            && ! str_contains($mail->detail, 'dana@example.test')
            && str_contains($mail->actionUrl, '/messages/'.$instance->getKey());
    });
});

it('counts a bounce and a failure in one alert rather than two', function (): void {
    /*
     * One mark, one email. A team that gets one alert about their credentials
     * and a second about a bounce two minutes later is a team that starts
     * filtering both — which is the *"an alert people filter is an alert that
     * does not work when it matters"* argument, arriving through the number of
     * emails instead of their frequency.
     */
    carryOutFailing(failingMessage());

    $instance = ActionInstance::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);

    App\Models\MessageDelivery::factory()
        ->create([
            'team_id' => $this->team->getKey(),
            'action_instance_id' => $instance->getKey(),
        ])
        ->advanceTo(App\Enums\DeliveryStatus::Complained, now());

    expect(settleAndSweep())->toBeTrue();

    Mail::assertSent(InternalAlertMail::class, function (InternalAlertMail $mail): bool {
        return $mail->headline === '2 automated messages need looking at'
            // Several, so the link is the queue: picking one of two to open is
            // not a choice anybody can make from an inbox.
            && str_ends_with($mail->actionUrl, '/messages');
    });

    Mail::assertSentCount(1);
});

it('does not report the same bounce twice', function (): void {
    /*
     * The watermark covers both halves of the window, and this is the half a
     * second table makes easy to forget. `noticed_at` is written once, on the
     * transition into a failure, so a replayed SNS notification cannot drag
     * the row back in front of the mark — the `COALESCE(executed_at,
     * updated_at)` finding, one table over.
     */
    $instance = ActionInstance::factory()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);

    $delivery = App\Models\MessageDelivery::factory()->create([
        'team_id' => $this->team->getKey(),
        'action_instance_id' => $instance->getKey(),
    ]);

    $delivery->advanceTo(App\Enums\DeliveryStatus::Bounced, now());

    expect(settleAndSweep())->toBeTrue();

    // The provider sends it again, as SNS does.
    $delivery->fresh()->advanceTo(App\Enums\DeliveryStatus::Bounced, now());

    expect(settleAndSweep())->toBeFalse();

    Mail::assertSentCount(1);
});
