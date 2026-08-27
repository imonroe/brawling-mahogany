<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Logging\Redactor;
use App\Support\Delivery\ApplyDeliveryEvent;
use App\Support\Delivery\DeliveryEvent;
use App\Support\Delivery\SnsMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Bounce and complaint notifications from SES, over SNS (#95 · PRD §4.5 F5.8).
 *
 * ## Not a controller like the others
 *
 * No session, no team, no CSRF token, no authenticated person — it is
 * registered outside the `web` group for exactly that reason. Everything that
 * would normally establish who is asking is absent, so **the signature is the
 * authentication** and there is nothing else. `SnsMessage` holds that argument;
 * what this class adds is the topic check and the certificate fetch.
 *
 * ## Why almost everything returns 200
 *
 * SNS retries a non-2xx response, with backoff, for up to some days — and
 * **disables the subscription** if it keeps failing. So a 500 on a payload
 * this build does not model would first flood the endpoint and then silently
 * turn off bounce handling altogether, which is the failure this feature
 * exists to prevent, reached by defending against it clumsily.
 *
 * The rule is therefore: **a message we refuse gets 403; a message we accept
 * and cannot use gets 200.** Anything Amazon genuinely sent is acknowledged,
 * whether or not it named a message this product has ever heard of — a bounce
 * for a message sent before this table existed is not an error, it is history.
 */
class SesNotificationController extends Controller
{
    /**
     * How long a signing certificate is worth keeping.
     *
     * Amazon rotates them rarely and a bounce storm can be thousands of
     * notifications in a minute; fetching the same certificate for each one
     * would turn a deliverability incident into an outbound-request incident.
     * An hour is short enough that a rotation heals on its own.
     */
    private const CERTIFICATE_TTL = 3600;

    public function __invoke(Request $request, ApplyDeliveryEvent $events): Response
    {
        /*
         * The body is parsed by hand rather than through `$request->json()`,
         * because SNS posts it as `text/plain` — genuinely, and it has done
         * for years. A handler reading the JSON body through the framework's
         * content-type sniffing gets an empty array from every real
         * notification and an empty 200 back, which looks exactly like
         * working.
         */
        $envelope = json_decode($request->getContent(), true);

        $message = is_array($envelope) ? SnsMessage::tryFrom($envelope) : null;

        if ($message === null) {
            return $this->refuse('unrecognised_envelope');
        }

        if (! $this->fromOurTopic($message)) {
            return $this->refuse('wrong_topic');
        }

        if (! $message->isAuthentic($this->certificate(...))) {
            // `SnsMessage` has already logged which of the checks failed.
            return response('', 403);
        }

        if ($message->isSubscriptionConfirmation()) {
            $this->confirm($message);

            return response('', 200);
        }

        if (! $message->isNotification()) {
            // An `UnsubscribeConfirmation`: genuine, signed, and nothing to do.
            return response('', 200);
        }

        $decoded = $message->decoded();

        $event = is_array($decoded) ? DeliveryEvent::tryFrom($decoded) : null;

        if ($event === null) {
            /*
             * Signed by our topic and not something this build models — a
             * `Click`, a `Send`, a `Rendering Failure`. Acknowledged, because
             * see above, and not logged at warning: a line per delivery
             * notification on a busy account is a log nobody reads.
             */
            return response('', 200);
        }

        $events->apply($event);

        return response('', 200);
    }

    /**
     * Is this from the topic we configured, rather than merely from Amazon?
     *
     * **An unset ARN refuses everything.** The other way round — treating an
     * unset ARN as "accept any topic" — is the shape of default that gets
     * shipped: it works in staging, nobody notices it is unset in production,
     * and the one check standing between a stranger's SNS topic and this
     * product's account-wide suppression list is off.
     */
    private function fromOurTopic(SnsMessage $message): bool
    {
        $expected = config('services.ses.topic_arn');

        if (! is_string($expected) || $expected === '') {
            return false;
        }

        return hash_equals($expected, (string) $message->topicArn());
    }

    /**
     * Complete the subscription handshake.
     *
     * The URL is Amazon's own and has already been through the signature
     * check, so following it is following something we have verified — but it
     * is still a URL out of a request body, and this product's rule (`SafeUrl`,
     * `BUG_REPORT_URL`) is that such a thing is checked at the point of use
     * rather than trusted because of where it came from.
     */
    private function confirm(SnsMessage $message): void
    {
        $url = $message->subscribeUrl();

        if ($url === null || ! $this->isAmazonUrl($url)) {
            Log::warning('delivery.webhook_refused', Redactor::context([
                'reason_code' => 'subscribe_url',
            ]));

            return;
        }

        $response = Http::timeout(10)->get($url);

        Log::info('delivery.subscription_confirmed', Redactor::context([
            'status_code' => $response->status(),
        ]));
    }

    /**
     * Amazon's signing certificate, fetched once an hour.
     *
     * The URL reaching here has already been matched against
     * `sns.<region>.amazonaws.com` by `SnsMessage`, which is what makes
     * fetching it safe; it is checked again on the way in because a helper
     * that is only safe when called correctly is a helper somebody will call
     * incorrectly.
     */
    private function certificate(string $url): ?string
    {
        if (! $this->isAmazonUrl($url)) {
            return null;
        }

        return Cache::remember(
            'sns:certificate:'.hash('sha256', $url),
            self::CERTIFICATE_TTL,
            function () use ($url): ?string {
                $response = Http::timeout(10)->get($url);

                return $response->successful() ? $response->body() : null;
            },
        );
    }

    private function isAmazonUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https') {
            return false;
        }

        $host = $parts['host'] ?? null;

        return is_string($host)
            && preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?$/D', mb_strtolower($host)) === 1;
    }

    private function refuse(string $code): Response
    {
        Log::warning('delivery.webhook_refused', Redactor::context(['reason_code' => $code]));

        return response('', 403);
    }
}
