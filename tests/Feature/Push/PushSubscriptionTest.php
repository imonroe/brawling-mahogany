<?php

declare(strict_types=1);

use App\Models\Person;
use App\Models\PushSubscription;

/**
 * S55 — registering and forgetting a device (#103).
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);

    $keys = Minishlink\WebPush\VAPID::createVapidKeys();

    config()->set('push.vapid.subject', 'mailto:ops@example.test');
    config()->set('push.vapid.public_key', $keys['publicKey']);
    config()->set('push.vapid.private_key', $keys['privateKey']);
});

function subscriptionPayload(array $overrides = []): array
{
    return array_merge([
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.Str::random(120),
        'public_key' => Str::random(87),
        'auth_token' => Str::random(22),
    ], $overrides);
}

it('registers a device', function (): void {
    $this->post('/settings/notifications/push', subscriptionPayload())
        ->assertRedirect();

    $subscription = PushSubscription::query()->sole();

    expect($subscription->person_id)->toBe($this->member->getKey())
        // Seen now by definition: the browser just spoke to us. Without this
        // a new subscription is a candidate for the stale sweep on day one.
        ->and($subscription->last_seen_at)->not->toBeNull()
        // Recorded from the request rather than trusted from the body.
        ->and($subscription->user_agent)->not->toBe('');
});

it('does not add a row every time the same browser re-registers', function (): void {
    /*
     * The page re-subscribes on every load that finds permission already
     * granted — that is how it notices a subscription the browser rotated. A
     * blind insert would give somebody one row per visit and one push per row.
     */
    $payload = subscriptionPayload();

    $this->post('/settings/notifications/push', $payload)->assertRedirect();
    $this->post('/settings/notifications/push', $payload)->assertRedirect();

    expect(PushSubscription::query()->count())->toBe(1);
});

it('moves a device to whoever is signed in on it now', function (): void {
    /*
     * An endpoint belongs to a **browser profile**. If it turns up under a
     * different person then a different account signed in on that device, and
     * the row has to move rather than duplicate — otherwise one person's
     * notifications go on reaching a browser now signed in as somebody else.
     */
    $payload = subscriptionPayload();

    $this->post('/settings/notifications/push', $payload)->assertRedirect();

    [$otherTeam, $other] = $this->teamWithMember();
    $this->actingAsPerson($other, $otherTeam);

    $this->post('/settings/notifications/push', $payload)->assertRedirect();

    $subscription = PushSubscription::query()->sole();

    expect($subscription->person_id)->toBe($other->getKey());
});

it('refuses an endpoint that is not a URL', function (): void {
    /*
     * A row `SendPush` would hand to an HTTP client on every notification,
     * forever, for a device that does not exist.
     */
    $this->post('/settings/notifications/push', subscriptionPayload(['endpoint' => 'not-a-url']))
        ->assertSessionHasErrors('endpoint');

    expect(PushSubscription::query()->count())->toBe(0);
});

it('refuses to register when the environment cannot push', function (): void {
    config()->set('push.vapid.private_key', null);

    $this->post('/settings/notifications/push', subscriptionPayload())
        ->assertStatus(503);

    expect(PushSubscription::query()->count())->toBe(0);
});

it('forgets one device without touching the others', function (): void {
    $mine = PushSubscription::factory()->create(['person_id' => $this->member->getKey()]);
    $other = PushSubscription::factory()->create(['person_id' => $this->member->getKey()]);

    $this->delete('/settings/notifications/push', ['endpoint' => $mine->endpoint])
        ->assertRedirect();

    expect(PushSubscription::query()->find($mine->getKey()))->toBeNull()
        ->and(PushSubscription::query()->find($other->getKey()))->not->toBeNull();
});

it('forgets every device when no endpoint is named', function (): void {
    PushSubscription::factory()->count(3)->create(['person_id' => $this->member->getKey()]);

    $this->delete('/settings/notifications/push')->assertRedirect();

    expect(PushSubscription::query()->count())->toBe(0);
});

it('will not let somebody forget another person’s device', function (): void {
    $stranger = Person::factory()->create();

    $theirs = PushSubscription::factory()->create(['person_id' => $stranger->getKey()]);

    $this->delete('/settings/notifications/push', ['endpoint' => $theirs->endpoint])
        ->assertSessionHasErrors('endpoint');

    expect(PushSubscription::query()->find($theirs->getKey()))->not->toBeNull();
});

it('forgets every device when somebody signs out', function (): void {
    /*
     * Not only this browser's, and that is the point. A subscription survives
     * a sign-out on its own — it belongs to the browser, not the session — so
     * without this a phone somebody handed back or sold goes on showing *"a
     * task was assigned to you"* on its lock screen indefinitely, with no
     * session anywhere to notice.
     */
    PushSubscription::factory()->count(2)->create(['person_id' => $this->member->getKey()]);

    $this->post('/logout')->assertRedirect();

    expect(PushSubscription::query()->count())->toBe(0);
});
