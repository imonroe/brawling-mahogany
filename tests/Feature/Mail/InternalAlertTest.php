<?php

declare(strict_types=1);

use App\Mail\AutomatedMessageMail;
use App\Mail\InternalAlertMail;
use App\Models\ActionInstance;
use App\Models\Deal;
use App\Support\Automation\AlertOnFailure;
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

function failingMessage(array $attributes = []): ActionInstance
{
    return ActionInstance::factory()->create([
        'team_id' => test()->team->getKey(),
        'deal_id' => test()->deal->getKey(),
        'payload' => [
            ...ActionInstance::factory()->definition()['payload'],
            'recipients' => [],
            ...$attributes,
        ],
    ]);
}

function carryOutFailing(ActionInstance $instance): ActionInstance
{
    app(ExecuteAction::class)->handle($instance, test()->team);

    return $instance->fresh();
}

it('tells the team when a client email does not go out', function (): void {
    carryOutFailing(failingMessage());

    Mail::assertSent(InternalAlertMail::class, function (InternalAlertMail $mail): bool {
        return $mail->hasTo($this->owner->email)
            && str_contains($mail->detail, 'resolved to nobody')
            && str_contains($mail->actionUrl, '/messages/');
    });

    Mail::assertNotSent(AutomatedMessageMail::class);
});

it('sends one alert an hour however many messages fail', function (): void {
    /*
     * An expired credential takes out a morning's queue, and an alert per
     * failure is forty emails about one problem. The first failure claims the
     * hour; the rest are silent.
     */
    foreach (range(1, 4) as $ignored) {
        carryOutFailing(failingMessage());
    }

    Mail::assertSent(InternalAlertMail::class, 1);
});

it('counts the whole backlog rather than the one that happened to be first', function (): void {
    ActionInstance::factory()->count(3)->failed()->create([
        'team_id' => $this->team->getKey(),
        'deal_id' => $this->deal->getKey(),
    ]);

    carryOutFailing(failingMessage());

    Mail::assertSent(InternalAlertMail::class, function (InternalAlertMail $mail): bool {
        // The three seeded, plus the one that just failed.
        return $mail->footnote === '4 messages are waiting for someone on your message queue.';
    });
});

it('says nothing about a backlog when there is only the one', function (): void {
    carryOutFailing(failingMessage());

    Mail::assertSent(InternalAlertMail::class, fn (InternalAlertMail $mail): bool => $mail->footnote === null);
});

it('never alerts one team about another team’s failure', function (): void {
    [$other, $otherOwner] = $this->teamWithOwner();

    carryOutFailing(failingMessage());

    Mail::assertSent(InternalAlertMail::class, function (InternalAlertMail $mail) use ($otherOwner): bool {
        return ! $mail->hasTo($otherOwner->email);
    });
});

it('keeps its own throttle per team', function (): void {
    /*
     * A shared key would mean one team's outage silences every other team's
     * alerts for an hour, which is the failure this whole class exists to
     * prevent, arriving through the cache.
     */
    expect(app(AlertOnFailure::class))->not->toBeNull();

    carryOutFailing(failingMessage());
    Mail::assertSent(InternalAlertMail::class, 1);

    [$other, $otherOwner] = $this->teamWithOwner();

    $this->actingAsPerson($otherOwner, $other);
    $other->forceFill(['sandbox_mode' => false, 'approval_required_until' => now()->subDay()])->save();

    $otherDeal = Deal::factory()->create(['team_id' => $other->getKey()]);
    $instance = ActionInstance::factory()->create([
        'team_id' => $other->getKey(),
        'deal_id' => $otherDeal->getKey(),
        'payload' => [
            ...ActionInstance::factory()->definition()['payload'],
            'recipients' => [],
        ],
    ]);

    app(ExecuteAction::class)->handle($instance, $other);

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

    Mail::assertNotSent(InternalAlertMail::class);
});

it('does not let a broken transport turn one lost message into a retry loop', function (): void {
    /*
     * The commonest reason a message failed is that the transport is broken,
     * which is the commonest reason this alert will throw. A throw would fail
     * the worker's job, which retries, which re-enters `ExecuteAction` on a
     * row that is already `failed`.
     */
    Log::spy();

    Mail::shouldReceive('to->send')->andThrow(new RuntimeException('Connection refused'));

    $instance = carryOutFailing(failingMessage());

    expect($instance->state->value)->toBe('failed');

    Log::shouldHaveReceived('warning')->once();
});
