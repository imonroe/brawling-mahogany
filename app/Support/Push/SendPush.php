<?php

declare(strict_types=1);

namespace App\Support\Push;

use App\Logging\Redactor;
use App\Models\Notification;
use App\Models\PushSubscription;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;
use Throwable;

/**
 * The push half of a notification (#103 · PRD §4.12 F12.2).
 *
 * ## Configured or silent, never half-configured
 *
 * An environment with no VAPID keys does not offer push at all: S55 hides the
 * control, `NotificationChannel::Push` is filtered out by `Notify`, and this
 * returns without doing anything. Push is one channel of several and the
 * panel is the record (ADR 0003), so *not offering* it is a complete answer
 * where *offering it and failing* is a promise broken silently.
 *
 * ## Endpoints die, and the push service is the only one who knows
 *
 * A subscription is not revoked, it simply stops existing — the browser is
 * uninstalled, the profile wiped, the user clears site data. The push service
 * answers **404** or **410** for those, and issue #103 is explicit that they
 * are removed rather than retried forever. Anything else (a 5xx, a timeout, a
 * 429) is the *service* having a bad moment and says nothing about the
 * subscription, so those are left alone.
 *
 * Getting that backwards in either direction is expensive: delete on a 500
 * and a push outage quietly unsubscribes the whole customer base; keep on a
 * 410 and every send from now on wastes a request on an address that will
 * never answer again.
 *
 * ## Nothing here is fatal
 *
 * The row is already in the panel before this runs, so a failure costs a
 * convenience rather than a record. It is logged with a reason **code** and no
 * address, and swallowed — the same trade `SendNotification::email()` makes,
 * one channel over, and for the same reason: a throw would retry the whole
 * delivery and send the email a second time.
 */
class SendPush
{
    /**
     * @param  ClientInterface|null  $http  the PSR-18 client to push through.
     *                                      Null means "build the configured
     *                                      one", which is every caller in the
     *                                      application; tests pass a mock,
     *                                      because the 404-and-410-delete rule
     *                                      is the single most consequential
     *                                      branch in this class and asserting
     *                                      it needs a push service that can be
     *                                      told what to say.
     */
    public function __construct(
        private readonly PushSubscriptionRegistry $subscriptions,
        private readonly ?ClientInterface $http = null,
    ) {}

    /**
     * Is push offerable in this environment at all?
     *
     * Asked by S55 before it draws a control and by `Notify` before it writes
     * `push` into a notification's channels, so the two cannot disagree about
     * whether a switch does anything.
     */
    public static function configured(): bool
    {
        foreach (['subject', 'public_key', 'private_key'] as $key) {
            $value = config('push.vapid.'.$key);

            if (! is_string($value) || $value === '') {
                return false;
            }
        }

        return true;
    }

    public function send(Notification $notification): void
    {
        if (! self::configured()) {
            return;
        }

        $subscriptions = PushSubscription::query()
            ->where('person_id', $notification->person_id)
            ->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $payload = json_encode(PushPayload::for($notification), JSON_THROW_ON_ERROR);

        $timeout = max(1, (int) config('push.timeout', 10));

        try {
            $push = new WebPush(
                ['VAPID' => [
                    'subject' => (string) config('push.vapid.subject'),
                    'publicKey' => (string) config('push.vapid.public_key'),
                    'privateKey' => (string) config('push.vapid.private_key'),
                ]],
                [],
                $this->http ?? $this->client($timeout),
                null,
                null,
                null,
                /*
                 * **A logger, and it is load-bearing.**
                 *
                 * `Utils::checkRequirement()` advises installing GMP or BCMath
                 * for speed, and with no logger it does so through
                 * `trigger_error(E_USER_NOTICE)`. Laravel's error handler
                 * escalates notices to `ErrorException`, so on any environment
                 * without those extensions the **constructor threw** — and the
                 * `catch` below dutifully logged `push.misconfigured` and
                 * returned, silently disabling push on an installation that
                 * was configured perfectly well. Every test in
                 * `SendPushTest` failed identically, which is what made it
                 * obvious rather than intermittent.
                 *
                 * Handing over a PSR-3 logger takes the `logNotice` branch
                 * instead, so the advice lands in the log where advice
                 * belongs. The image installs bcmath (see the Dockerfile), so
                 * this is about environments that do not — a developer's
                 * laptop, a CI runner — behaving like the one that does.
                 */
                Log::driver(),
            );
        } catch (Throwable $failure) {
            /*
             * Malformed keys, or a subject the library refuses. A
             * configuration problem rather than a delivery one, so it is worth
             * a log line — and worth exactly one, rather than one per
             * subscription.
             */
            Log::warning('push.misconfigured', Redactor::context([
                'exception' => $failure::class,
            ]));

            return;
        }

        foreach ($subscriptions as $subscription) {
            $push->queueNotification($this->toSubscription($subscription), $payload);
        }

        /*
         * `flush()` rather than `sendOneNotification()` per row: the library
         * pipelines them over one connection, which matters for the person
         * with a phone, a tablet and a desktop — three sequential round trips
         * to three different push services inside a queue worker is most of
         * the job's time.
         */
        foreach ($push->flush() as $report) {
            $this->record($report->getEndpoint(), $report->isSuccess(), $report->getResponse()?->getStatusCode());
        }
    }

    /**
     * **An HTTP client we configured, not one discovered for us.**
     *
     * v11 dropped the library's own `$timeout` parameter in favour of PSR-18
     * discovery, and `php-http/discovery` will happily hand back a client with
     * **no timeout at all** — so one push service having a bad afternoon would
     * hold a queue worker open indefinitely, for a message whose record is
     * already in the panel. `connect_timeout` as well as `timeout`, because a
     * host that never completes a handshake is the exact case a response
     * timeout does not cover.
     */
    private function client(int $timeout): ClientInterface
    {
        return new Client([
            'timeout' => $timeout,
            'connect_timeout' => $timeout,
            /*
             * The status is the whole signal here — 404 and 410 mean delete,
             * everything else means leave alone — so a 4xx has to arrive as a
             * response rather than as a thrown exception.
             */
            'http_errors' => false,
        ]);
    }

    private function toSubscription(PushSubscription $subscription): Subscription
    {
        return Subscription::create([
            'endpoint' => $subscription->endpoint,
            'publicKey' => $subscription->public_key,
            'authToken' => $subscription->auth_token,
            // The only content encoding any current browser accepts, and the
            // one RFC 8291 specifies. Named rather than defaulted so a library
            // upgrade that changes its default cannot change what we send.
            'contentEncoding' => 'aes128gcm',
        ]);
    }

    /**
     * What the push service said about one endpoint.
     */
    private function record(string $endpoint, bool $delivered, ?int $status): void
    {
        if ($delivered) {
            /*
             * `last_seen_at` is what the stale sweep reads. Touched on
             * success only: a subscription that has failed for six months is
             * exactly what that sweep is for, and updating this on a failure
             * would keep it alive forever.
             */
            $this->subscriptions->markSeen($endpoint);

            return;
        }

        /*
         * **Gone means gone; everything else means try again.** 404 and 410
         * are the two the Push API specifies for an endpoint that no longer
         * exists. A 5xx, a timeout (no status at all) or a 429 is the service
         * having a bad moment, and deleting on those would unsubscribe the
         * customer base during an outage.
         */
        if ($status === 404 || $status === 410) {
            $this->subscriptions->forget($endpoint);

            return;
        }

        Log::info('push.delivery_failed', Redactor::context([
            /*
             * The status, and nothing that identifies the device. An endpoint
             * is a credential — it is the whole authorisation to push to
             * somebody's phone — so it never reaches a log line, which is
             * also why `Redactor` is not being relied on to catch it.
             */
            'status_code' => $status,
        ]));
    }
}
