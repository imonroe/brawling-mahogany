<?php

declare(strict_types=1);

namespace App\Support\Feedback;

use App\Models\Person;
use App\Support\Links\SafeUrl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The n8n form behind the top bar's **Report a bug** button (issue #176).
 *
 * The first people to use this product are not developers and have no GitHub
 * accounts, so the path from *"this is broken"* to a filed issue has to be a
 * button in the chrome rather than a URL somebody remembers. The form itself
 * belongs to n8n, which turns a submission into an issue on this repository;
 * this application only frames it and never sees what is typed in.
 *
 * ## Two keys, and the second one is not redundant
 *
 * `BUG_REPORT_URL` is where the form lives and `BUG_REPORT_ENABLED` is whether
 * the button appears. Keeping them separate is what lets an operator turn the
 * button off — during an n8n outage, or on a deployment that should not invite
 * reports — without deleting the URL and having to find it again. A single key
 * would make "off" and "unconfigured" the same state.
 *
 * ## Signed in only
 *
 * The prop is null for a guest. That is not a permission — anybody may report
 * a bug — it is that the button lives in `AppLayout`'s top bar, which only a
 * signed-in person ever sees. Deciding it here rather than in the shell means
 * the sign-in page never carries the URL in its page props for a stranger to
 * read out of the HTML.
 *
 * ## A URL that cannot be framed is treated as unset
 *
 * The value is held to `SafeUrl`'s http/https allowlist, for the reason #61
 * records: a `javascript:` URL is script execution in the reader's session the
 * moment something renders it, and an `iframe src` is exactly that. Somebody
 * has to have written it into the environment for that to happen, which makes
 * it a typo rather than an attack — but a typo that turns into stored XSS is
 * still worth one `in_array`.
 *
 * ## And not on an origin of ours
 *
 * The frame is sandboxed `allow-scripts allow-same-origin`, which a form needs
 * to keep its own storage and its own cookies — and which is **not a sandbox at
 * all** when the framed document is served from an origin the application also
 * answers on: it reaches `window.parent` and reads the session. A self-host
 * that proxies n8n under the app's own domain is an ordinary layout rather than
 * a contrivance, and n8n is a third-party application with its own attack
 * surface, so this would turn a compromised form into account takeover.
 *
 * `SafeUrl` answers *"is this a URL"*, not *"whose"*, so the check lives here.
 * Enforced rather than asserted: the sandbox's own docblock claimed *"it is not
 * our origin"* on nothing but the operator's good sense.
 *
 * **Two hosts, because `APP_URL` is the value most likely to be stale.**
 * `.env.example` ships `http://localhost:8000`, Laravel only uses it for
 * console URL generation, and so an install serving `app.example.com` with that
 * left in place is wrong in the one way that is completely silent. Checking the
 * host actually serving the request as well means the guard does not depend on
 * the operator having got right the adjacent thing they most often get wrong.
 *
 * **Host and port, not host alone.** `app.test:8000` and `app.test:5678` are
 * different origins, so the sandbox holds between them — and a developer
 * running n8n on its own port beside this app is the ordinary local setup.
 * Comparing hosts alone refused that one and logged a security reason that did
 * not apply to it.
 *
 * **But a URL with no port stands for both defaults.** `http://app.test` is
 * port 80 and `https://app.test` is 443, so deriving one port from the scheme
 * let `BUG_REPORT_URL=http://app.test/n8n` past a guard protecting
 * `https://app.test` — and `Deployment.md` §3 turns HSTS on, so the browser
 * would upgrade that frame to https and land it same-origin after all. An
 * origin written without a port therefore contributes **both** candidates; an
 * explicitly written port is matched exactly, which is what keeps
 * `localhost:5678` allowed beside `localhost:8000`.
 *
 * ## A rejected URL is hidden rather than raised, and said once an hour
 *
 * A bug-report form is not worth a white screen, so a bad value hides the
 * button. That has to be audible or an operator gets no button and no reason —
 * and it has to be audible *once*, because this runs on every authenticated
 * request and a misconfiguration that stands for a day would otherwise be
 * thousands of identical lines.
 *
 * A static latch is what that looked like at first and it does not work:
 * FrankenPHP runs here in classic mode (`Dockerfile`: `frankenphp run` with no
 * worker directive), so user-land statics are torn down at every request
 * boundary exactly as they are under php-fpm. The latch held only inside the
 * Pest process, which runs the whole suite in one execution — so the guard
 * appeared to work in the one place it was never needed and nowhere it was.
 * The cache outlives a request, which is the property actually required.
 *
 * **Keyed by the reason**, because there are three of them and one key would
 * mean fixing the empty URL, mistyping the next one, and then being told for
 * the rest of the hour about a problem that no longer exists.
 */
final class BugReportForm
{
    /** How long one complaint about the configuration silences the rest. */
    private const WARNING_TTL_MINUTES = 60;

    /**
     * Where the cooldown lives.
     *
     * Private, because the difference between this and a static *is* observable
     * from outside after all: travel past the TTL and the cache forgets while a
     * static would not. The first version of this test asserted the key
     * directly on the argument that nothing else could tell them apart, which
     * was wrong — and a white-box assertion bought with a wrong argument is one
     * that stops being checked the day the storage changes.
     */
    private const WARNING_KEY = 'feedback:bug-report:warned';

    /**
     * What the shell needs, or null when there is no button to draw.
     *
     * `$servingOrigin` is the scheme and host the current request arrived on —
     * `Request::getSchemeAndHttpHost()`. Optional so that a caller outside HTTP
     * can still ask; `config('app.url')` is checked either way.
     *
     * @return array{url: string}|null
     */
    public static function propsFor(?Person $person, ?string $servingOrigin = null): ?array
    {
        if (! $person instanceof Person) {
            return null;
        }

        if (! self::switchedOn()) {
            return null;
        }

        $url = SafeUrl::normalise(config('services.bug_report.url'));

        if ($url === '') {
            self::warnOnce('BUG_REPORT_URL is empty.');

            return null;
        }

        if (! SafeUrl::permits($url)) {
            self::warnOnce('BUG_REPORT_URL is not an http or https address.');

            return null;
        }

        if (self::isOwnOrigin($url, $servingOrigin)) {
            self::warnOnce('BUG_REPORT_URL is on a host and port this application answers on, which defeats the frame’s sandbox.');

            return null;
        }

        return ['url' => $url];
    }

    /**
     * Whether the button is switched on.
     *
     * `filter_var` rather than a cast, because `env()` converts only the four
     * spellings it knows — `true`, `false`, `null`, `empty` — and leaves every
     * other string alone. So `BUG_REPORT_ENABLED=off`, `=no` and `=disabled`
     * all cast to **true**: a documented kill switch that fails open on three
     * of the likeliest ways somebody would try to pull it, in a hurry, during
     * the outage it exists for. `InstallFeaturesCommand` already reads its
     * flags this way. Anything unrecognised is off, which is the safe end.
     */
    private static function switchedOn(): bool
    {
        return filter_var(
            config('services.bug_report.enabled'),
            FILTER_VALIDATE_BOOL,
        );
    }

    /** Whether this URL is served by an origin the application answers on. */
    private static function isOwnOrigin(string $url, ?string $servingOrigin): bool
    {
        $form = self::originsOf($url);

        if ($form === []) {
            return false;
        }

        $ours = [
            ...self::originsOf((string) config('app.url')),
            ...self::originsOf((string) $servingOrigin),
        ];

        return array_intersect($form, $ours) !== [];
    }

    /**
     * The `host:port` origins a URL could resolve to.
     *
     * One when the port is written out, two when it is not — see the docblock:
     * a scheme-derived port is a guess, and guessing `80` for
     * `http://app.test` is what let it past a guard protecting the same host
     * on `443`.
     *
     * @return list<string>
     */
    private static function originsOf(string $url): array
    {
        $parts = parse_url($url);

        // `parse_url` answers false on a malformed URL rather than an array.
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;

        if (! is_string($host) || $host === '') {
            return [];
        }

        $host = mb_strtolower($host);
        $port = is_array($parts) ? ($parts['port'] ?? null) : null;

        return $port === null
            ? [$host.':80', $host.':443']
            : [$host.':'.$port];
    }

    /**
     * Say it, and then be quiet about *that* for an hour.
     *
     * Deliberately **not** a `Cache::lock`: the point is a cooldown that
     * expires on its own, not mutual exclusion. `add` is atomic on redis, which
     * is what every deployed environment runs, so two workers arriving together
     * still produce one line. (The `array` store the test suite pins is
     * single-process and the question does not arise.)
     *
     * `rescue` because this is a log line: a cache store that cannot be reached
     * must not turn a bug-report misconfiguration into a 500 on every
     * authenticated page. Not hypothetical — sessions can be on the database
     * while the cache is on redis, and then an authenticated request outlives a
     * redis outage that this would otherwise kill. Saying nothing is the right
     * failure for a mechanism whose whole job is to say something once.
     */
    private static function warnOnce(string $reason): void
    {
        $key = self::WARNING_KEY.':'.md5($reason);

        $first = rescue(
            fn (): bool => Cache::add($key, true, now()->addMinutes(self::WARNING_TTL_MINUTES)),
            false,
            report: false,
        );

        if (! $first) {
            return;
        }

        // Context rather than interpolation (docs/Testing.md), and no PII:
        // this is deployment configuration, not anything a person typed.
        Log::warning('The bug report button is switched on but has no usable form URL.', [
            'reason' => $reason,
        ]);
    }
}
