<?php

declare(strict_types=1);

use App\Models\Person;
use App\Models\PushSubscription;
use App\Support\Push\PushSubscriptionRegistry;

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

it('answers the background re-post with no body to follow', function (): void {
    /*
     * `resources/js/lib/pwa.ts` re-posts the browser's subscription on every
     * navigation with a plain `fetch`, and **`fetch` follows a 302** — so a
     * bare `back()` meant every navigation quietly fetched and discarded a
     * whole rendered page.
     *
     * ## The headers are the client's, not `postJson()`'s
     *
     * Round 3 of review found the first version of this test using
     * `postJson()`, whose helper injects `Accept: application/json` — so it
     * passed against a branch keyed on `wantsJson()` that **never fired for
     * the real caller**, which sets no `Accept` at all and gets the browser's
     * a catch-all. A test that does not send what the caller sends proves nothing
     * about the caller.
     *
     * So these are spelled out. `X-Requested-With` is what makes
     * `expectsJson()` true for an XHR that accepts anything; the explicit
     * `Accept` is what `lib/pwa.ts` now states rather than leaving to a
     * default.
     */
    $this->json('POST', '/settings/notifications/push', subscriptionPayload(), [
        'X-Requested-With' => 'XMLHttpRequest',
        'Accept' => 'application/json',
    ])->assertNoContent();

    expect(PushSubscription::query()->count())->toBe(1);
});

it('answers a background re-post that sets no Accept at all', function (): void {
    /*
     * The case round 2's `wantsJson()` branch fell through: a `fetch` with no
     * `Accept` gets the browser's catch-all, and `wantsJson()` asks whether
     * the *first* acceptable type is JSON.
     *
     * `lib/pwa.ts` now states `Accept: application/json` explicitly, so this
     * is belt and braces — but it is the assertion that says the server does
     * not *depend* on the client remembering to, which is the property that
     * made the original defect invisible.
     */
    $this->call(
        'POST',
        '/settings/notifications/push',
        [],
        [],
        [],
        [
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'HTTP_ACCEPT' => '*'.'/'.'*',
            'CONTENT_TYPE' => 'application/json',
        ],
        (string) json_encode(subscriptionPayload()),
    )->assertNoContent();

    expect(PushSubscription::query()->count())->toBe(1);
});

it('still redirects the button on S55, which has a list to re-render', function (): void {
    /*
     * The other half of that branch, and the one a too-eager discriminator
     * breaks: Inertia sends `Accept: text/html, …`, so `expectsJson()` is
     * false and `router.post` gets the redirect that re-renders the device
     * list. Without this test, "return 204 for everything" passes.
     */
    $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Requested-With' => 'XMLHttpRequest',
        'Accept' => 'text/html, application/xhtml+xml',
    ])->post('/settings/notifications/push', subscriptionPayload())
        ->assertRedirect();
});

it('records nothing while an operator is impersonating somebody', function (): void {
    /*
     * `store()` keys on the endpoint and moves the row to whoever is signed
     * in, which is right for a shared device. During an S84 support session
     * that is the **customer** — so a platform operator's own browser would
     * be reassigned to them, and from then on the operator's lock screen
     * would show that team's work, including the property streets
     * `PushPayload` deliberately allows. It would detach the customer's real
     * phone too, because the endpoint is unique.
     *
     * Before this slice only S55's button reached here. The per-navigation
     * re-post makes it automatic, which is what turns an odd edge into
     * something that happens on the first page an operator opens.
     */
    $operator = Person::factory()->create(['is_super_admin' => true]);

    /*
     * Signed in as the member — an impersonated session *is* the customer's,
     * which is the whole point — with the marker `Impersonation::isActive()`
     * actually reads. Built through the helper's own key rather than a
     * hand-made array, so a change to that shape breaks this test rather
     * than silently making it assert nothing.
     */
    $this->withSession(['impersonation' => [
        'admin_person_id' => $operator->getKey(),
        'person_id' => $this->member->getKey(),
        'person_name' => 'Emily',
        'team_id' => $this->team->getKey(),
        'team_name' => $this->team->name,
        'reason' => 'Support call',
        'expires_at' => now()->addMinutes(30)->toIso8601String(),
    ]])->json('POST', '/settings/notifications/push', subscriptionPayload(), [
        'X-Requested-With' => 'XMLHttpRequest',
        'Accept' => 'application/json',
    ])->assertNoContent();

    expect(PushSubscription::query()->count())->toBe(0);
});

it('does not add a row every time the same browser re-registers', function (): void {
    /*
     * `resources/js/lib/pwa.ts` re-posts whatever subscription the browser
     * holds on every load — which is both how a rotated endpoint is noticed
     * and how push survives the sign-out hook that deletes every row. A blind
     * insert would give somebody one row per visit and one push per row.
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

it('refuses a plaintext endpoint', function (): void {
    /*
     * The server POSTs to whatever is stored here, so admitting `http` would
     * let somebody register an internal plaintext address and have the
     * application make requests to it — blind SSRF, where the status still
     * leaks through which subscriptions survive. Every real push service is
     * TLS, so this costs nothing.
     */
    $this->post('/settings/notifications/push', subscriptionPayload([
        'endpoint' => 'http://169.254.169.254/latest/meta-data/',
    ]))->assertSessionHasErrors('endpoint');

    expect(PushSubscription::query()->count())->toBe(0);
});

it('keeps a person’s device list bounded', function (): void {
    /*
     * Every row is an endpoint the server POSTs to on every notification, so
     * an unbounded list is both a growing send cost and what would make the
     * scheme restriction above worth little.
     *
     * **Evicts rather than refuses**: turning away the newest device would
     * mean somebody's current phone silently never buzzes while one they sold
     * holds its place. The oldest `last_seen_at` goes.
     */
    $oldest = PushSubscription::factory()->create([
        'person_id' => $this->member->getKey(),
        'last_seen_at' => now()->subYear(),
    ]);

    PushSubscription::factory()
        ->count(PushSubscriptionRegistry::MAX_DEVICES - 1)
        ->create(['person_id' => $this->member->getKey()]);

    $this->post('/settings/notifications/push', subscriptionPayload())
        ->assertRedirect();

    expect(PushSubscription::query()->count())->toBe(PushSubscriptionRegistry::MAX_DEVICES)
        ->and(PushSubscription::query()->find($oldest->getKey()))->toBeNull();
});

it('does not wipe a customer’s devices when an operator signs out of a support session', function (): void {
    /*
     * The sign-out hook deletes every device the person signing out holds —
     * deliberately, so a handed-back phone stops buzzing. During an S84
     * support session that person is the **customer**, so an operator ending
     * the session by signing out would destroy customer state with no way to
     * restore it except each of their people re-enabling push on each phone.
     */
    $operator = Person::factory()->create(['is_super_admin' => true]);

    PushSubscription::factory()->count(2)->create(['person_id' => $this->member->getKey()]);

    $this->withSession(['impersonation' => [
        'admin_person_id' => $operator->getKey(),
        'person_id' => $this->member->getKey(),
        'person_name' => 'Emily',
        'team_id' => $this->team->getKey(),
        'team_name' => $this->team->name,
        'reason' => 'Support call',
        'expires_at' => now()->addMinutes(30)->toIso8601String(),
    ]])->post('/logout')->assertRedirect();

    expect(PushSubscription::query()->count())->toBe(2);
});

it('evicts a device nothing has ever reached before one that works', function (): void {
    /*
     * Postgres puts NULLs **first** on a `DESC` sort, so the plain
     * `orderByDesc('last_seen_at')` this started as ranked a subscription
     * nothing had ever reached as the newest and kept it — evicting a
     * working device instead. Round 5 of review caught the docblock claiming
     * the opposite of what the query did.
     */
    $neverReached = PushSubscription::factory()->create([
        'person_id' => $this->member->getKey(),
        'last_seen_at' => null,
    ]);

    $working = PushSubscription::factory()->create([
        'person_id' => $this->member->getKey(),
        'last_seen_at' => now()->subYear(),
    ]);

    PushSubscription::factory()
        ->count(PushSubscriptionRegistry::MAX_DEVICES - 2)
        ->create(['person_id' => $this->member->getKey()]);

    $this->post('/settings/notifications/push', subscriptionPayload())
        ->assertRedirect();

    expect(PushSubscription::query()->find($neverReached->getKey()))->toBeNull()
        ->and(PushSubscription::query()->find($working->getKey()))->not->toBeNull();
});
