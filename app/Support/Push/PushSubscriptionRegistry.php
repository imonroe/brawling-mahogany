<?php

declare(strict_types=1);

namespace App\Support\Push;

use App\Models\Person;
use App\Models\PushSubscription;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `push_subscriptions` (#103).
 *
 * One writer for the reason `Notify` is one writer of `notifications`: five
 * places have a reason to remove a subscription — the browser unsubscribing,
 * a push service answering 410, somebody signing out, a membership being
 * revoked, a device going quiet for six months — and a rule enforced at five
 * call sites is a rule the sixth is written without.
 */
class PushSubscriptionRegistry
{
    /**
     * Record a browser's subscription, or refresh the one it already had.
     *
     * ## Keyed on the endpoint, which is the device's identity
     *
     * A browser hands back the *same* endpoint when it re-subscribes, and
     * `resources/js/lib/pwa.ts` re-posts whatever subscription the browser
     * holds on every load. Inserting blindly would give somebody one row per
     * visit and one push per row.
     *
     * That re-post exists because of this method's own sign-out counterpart:
     * `forgetFor()` runs on every sign-out, so without something handing the
     * subscription back, push switched itself off permanently for every
     * device the first time anybody signed out. Round 1 of review found the
     * claim in this paragraph asserted and unimplemented — the page did not
     * re-post anything, and the only POST in the codebase was a click on
     * S55.
     *
     * `updateOrCreate` on `endpoint` rather than on `(person_id, endpoint)`:
     * an endpoint belongs to a browser profile, so if it turns up under a
     * different person then a *different account signed in on this device*
     * and the row must move to them — not be duplicated, which would push one
     * person's notifications to a browser now signed in as somebody else.
     */
    public function store(
        Person $person,
        string $endpoint,
        string $publicKey,
        string $authToken,
        ?string $userAgent,
    ): PushSubscription {
        /*
         * Looked up and constructed by hand rather than with `firstOrNew`,
         * which mass-assigns its attributes — and every model in this product
         * carries an empty `#[Fillable]` so that `forceFill` is the only way
         * in. The convention caught this immediately, which is the point of
         * it.
         */
        $subscription = PushSubscription::query()->where('endpoint', $endpoint)->first()
            ?? new PushSubscription;

        $subscription->forceFill([
            'person_id' => $person->getKey(),
            'endpoint' => $endpoint,
            'public_key' => $publicKey,
            'auth_token' => $authToken,
            /*
             * Truncated rather than refused. A user-agent string is
             * unbounded, it is only ever used to say "iPhone" on S55, and a
             * 500 on a long one would refuse a working subscription over a
             * label.
             */
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
            /*
             * Seen now, by definition: the browser just spoke to us. Without
             * this a brand-new subscription starts with a null `last_seen_at`
             * and is a candidate for the stale sweep from its first day.
             */
            'last_seen_at' => now(),
        ])->save();

        return $subscription;
    }

    /**
     * The endpoint is gone. Not soft-deleted — see the migration.
     */
    public function forget(string $endpoint): void
    {
        PushSubscription::query()->where('endpoint', $endpoint)->delete();
    }

    /** Every device this person has registered. */
    public function forgetFor(Person $person): void
    {
        PushSubscription::query()->where('person_id', $person->getKey())->delete();
    }

    /**
     * A successful push proves the endpoint is alive.
     *
     * A bare `UPDATE` rather than a model save: this runs once per device per
     * push, and hydrating a model to stamp one column is the kind of thing
     * that shows up in a queue worker's profile long before anybody suspects
     * it. Nothing observes this column but the stale sweep.
     */
    public function markSeen(string $endpoint): void
    {
        DB::table('push_subscriptions')
            ->where('endpoint', $endpoint)
            ->update(['last_seen_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Devices nothing has reached for a long time.
     *
     * The push services are not obliged to tell us a phone has been wiped and
     * several never do, so this is the only thing that ever clears those.
     * Deliberately generous (`PushSubscription::STALE_AFTER_DAYS`): somebody
     * who has had a quiet six months has not stopped existing, and silently
     * unsubscribing them presents as *"push just stopped working"* with
     * nothing to point at.
     *
     * @return int how many were removed
     */
    public function pruneStale(?CarbonInterface $before = null): int
    {
        $cutoff = $before ?? now()->subDays(PushSubscription::STALE_AFTER_DAYS);

        return PushSubscription::query()
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '<', $cutoff)
            ->delete();
    }
}
