<?php

declare(strict_types=1);

use App\Models\Notification;
use App\Models\PushSubscription;
use App\Models\TeamMembership;
use App\Support\Push\PushSubscriptionRegistry;
use App\Support\Push\SendPush;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

/**
 * What a push service says, and what we do about it (#103 · F12.2).
 *
 * The 404-and-410 rule is the most consequential branch in `SendPush` and it
 * is wrong in both directions: delete on a 500 and a push outage silently
 * unsubscribes the customer base; keep on a 410 and every send from now on
 * spends a request on an address that will never answer again.
 *
 * That is why the HTTP client is injectable — a push service that can be told
 * what to say is the only way to assert either half.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);

    /*
     * A real key pair, so the library's own VAPID signing runs rather than
     * being stubbed — a pair it would refuse is a configuration failure this
     * test should see rather than skip past.
     *
     * Set key by key. An earlier version composed the array with `+`, which
     * keeps the **left** operand's entries, so the deliberately-null
     * `publicKey` survived and `SendPush::configured()` was false: `send()`
     * returned immediately, every case took the same branch, and the three
     * "keeps the subscription" cases passed without a push being attempted at
     * all. Tests that cannot fail, produced by a fixture.
     */
    $keys = Minishlink\WebPush\VAPID::createVapidKeys();

    config()->set('push.vapid.subject', 'mailto:ops@example.test');
    config()->set('push.vapid.public_key', $keys['publicKey']);
    config()->set('push.vapid.private_key', $keys['privateKey']);

    expect(SendPush::configured())->toBeTrue();

    $this->notification = Notification::factory()->create([
        'team_id' => $this->team->getKey(),
        'person_id' => $this->member->getKey(),
    ]);
});

/** A push service that answers with whatever this test says. */
function pushSaying(int ...$statuses): SendPush
{
    $handler = new MockHandler(array_map(
        static fn (int $status): Response => new Response($status),
        $statuses,
    ));

    return new SendPush(
        app(PushSubscriptionRegistry::class),
        /*
         * `http_errors => false`, exactly as `SendPush::client()` configures
         * the real one. Without it Guzzle throws on a 4xx, the library catches
         * it, and the report arrives with **no response at all** — so every
         * case in this file would take the "service having a bad day" branch
         * and the 404/410 rule would never be exercised. A test client that
         * does not match the production one tests a different code path.
         */
        new Client([
            'handler' => HandlerStack::create($handler),
            'http_errors' => false,
        ]),
    );
}

it('forgets a subscription the push service says is gone', function (int $status): void {
    $subscription = PushSubscription::factory()->create([
        'person_id' => $this->member->getKey(),
    ]);

    pushSaying($status)->send($this->notification);

    expect(PushSubscription::query()->find($subscription->getKey()))->toBeNull();
})->with([
    '404, no such endpoint' => [404],
    '410, gone for good' => [410],
]);

it('keeps a subscription when the push service is merely having a bad day', function (int $status): void {
    /*
     * The other half, and the expensive one to get wrong: deleting on these
     * would unsubscribe every device on the platform during an outage, and
     * nobody would find out until they noticed they had stopped being
     * notified.
     */
    $subscription = PushSubscription::factory()->create([
        'person_id' => $this->member->getKey(),
    ]);

    pushSaying($status)->send($this->notification);

    expect(PushSubscription::query()->find($subscription->getKey()))->not->toBeNull();
})->with([
    'rate limited' => [429],
    'service error' => [500],
    'gateway' => [502],
]);

it('marks a device seen when a push actually lands', function (): void {
    $subscription = PushSubscription::factory()->create([
        'person_id' => $this->member->getKey(),
        'last_seen_at' => now()->subMonth(),
    ]);

    pushSaying(201)->send($this->notification);

    expect($subscription->fresh()->last_seen_at->isToday())->toBeTrue();
});

it('does not mark a device seen when the push failed', function (): void {
    /*
     * `last_seen_at` is what the stale sweep reads, so touching it on a
     * failure would keep a dead device alive forever — the one thing that
     * sweep exists to prevent.
     */
    $subscription = PushSubscription::factory()->create([
        'person_id' => $this->member->getKey(),
        'last_seen_at' => now()->subMonth(),
    ]);

    pushSaying(500)->send($this->notification);

    expect($subscription->fresh()->last_seen_at->isToday())->toBeFalse();
});

it('does nothing at all when the environment has no keys', function (): void {
    config()->set('push.vapid.private_key', null);

    PushSubscription::factory()->create(['person_id' => $this->member->getKey()]);

    // No mock responses queued: if this tried to send, the handler would throw.
    $push = new SendPush(app(PushSubscriptionRegistry::class), new Client([
        'handler' => HandlerStack::create(new MockHandler([])),
    ]));

    $push->send($this->notification);

    expect(SendPush::configured())->toBeFalse()
        ->and(PushSubscription::query()->count())->toBe(1);
});

it('sweeps a device nothing has reached in half a year', function (): void {
    $stale = PushSubscription::factory()->stale()->create([
        'person_id' => $this->member->getKey(),
    ]);

    $live = PushSubscription::factory()->create([
        'person_id' => $this->member->getKey(),
    ]);

    expect(app(PushSubscriptionRegistry::class)->pruneStale())->toBe(1)
        ->and(PushSubscription::query()->find($stale->getKey()))->toBeNull()
        ->and(PushSubscription::query()->find($live->getKey()))->not->toBeNull();
});

it('does not push to somebody who has left the team', function (): void {
    /*
     * Round 4 of review's blocking finding, and it needs no race to reach.
     *
     * `NotifyAboutDeadlines::sweep()` raises `deadline_approaching` straight
     * off `tasks.assignee_id` with **no membership filter**, so a task still
     * assigned to somebody who has left produces a notification for them
     * every day it stays open. Their panel hides it
     * (`Notification::scopeForPerson()`), the email refuses it
     * (`SendNotification::email()`'s `active()`) — and until this gate,
     * push sent it: a customer's property street onto the lock screen of a
     * phone the team can no longer see.
     *
     * Until this slice the push arm was `=> null`, so this PR is what turned
     * a hidden row into an outbound message.
     */
    PushSubscription::factory()->create(['person_id' => $this->member->getKey()]);

    TeamMembership::query()
        ->where('team_id', $this->team->getKey())
        ->where('person_id', $this->member->getKey())
        ->update(['revoked_at' => now()]);

    // No mock responses queued: if this tried to send, the handler throws.
    $push = new SendPush(app(PushSubscriptionRegistry::class), new Client([
        'handler' => HandlerStack::create(new MockHandler([])),
    ]));

    $push->send($this->notification);

    // The device is untouched — this is a send predicate, not a purge.
    expect(PushSubscription::query()->count())->toBe(1);
});

it('still pushes to somebody who is in the team', function (): void {
    /*
     * The control. Without it, "never push to anybody" passes the test above
     * — which is the failure mode a guard like this invites.
     */
    $subscription = PushSubscription::factory()->create([
        'person_id' => $this->member->getKey(),
        'last_seen_at' => now()->subMonth(),
    ]);

    pushSaying(201)->send($this->notification);

    expect($subscription->fresh()->last_seen_at->isToday())->toBeTrue();
});
