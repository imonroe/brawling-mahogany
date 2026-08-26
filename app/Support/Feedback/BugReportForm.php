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
 * not apply to it. The scheme is deliberately *not* compared: `http` and
 * `https` on one host and port cannot both be somebody else.
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
 */
final class BugReportForm
{
    /** How long one complaint about the configuration silences the rest. */
    private const WARNING_TTL_MINUTES = 60;

    /**
     * Where the cooldown lives.
     *
     * Public because a test has to name it: whether the latch survives a
     * request is the whole of this mechanism, and it is not observable from
     * outside within one PHP execution — a static would suppress the second
     * warning in a test process exactly as this does, and would not suppress
     * it in production, which is the case that matters.
     */
    public const WARNING_KEY = 'feedback:bug-report:warned';

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
        $form = self::hostAndPort($url);

        if ($form === null) {
            return false;
        }

        $ours = array_filter([
            self::hostAndPort((string) config('app.url')),
            self::hostAndPort((string) $servingOrigin),
        ]);

        return in_array($form, $ours, true);
    }

    /**
     * `host:port`, with the scheme's default port filled in.
     *
     * Written out rather than compared as strings, because `https://app.test`
     * and `https://app.test:443` are the same origin and do not look alike.
     */
    private static function hostAndPort(string $url): ?string
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? null;

        if (! is_string($host) || $host === '') {
            return null;
        }

        $port = $parts['port']
            ?? (mb_strtolower((string) ($parts['scheme'] ?? '')) === 'https' ? 443 : 80);

        return mb_strtolower($host).':'.$port;
    }

    /**
     * Say it, and then be quiet about it for an hour.
     *
     * Deliberately **not** a `Cache::lock`: the point is a cooldown that
     * expires on its own, not mutual exclusion. `add` is atomic on every store
     * this application runs on, so two workers arriving together still produce
     * one line.
     */
    private static function warnOnce(string $reason): void
    {
        if (! Cache::add(self::WARNING_KEY, true, now()->addMinutes(self::WARNING_TTL_MINUTES))) {
            return;
        }

        // Context rather than interpolation (docs/Testing.md), and no PII:
        // this is deployment configuration, not anything a person typed.
        Log::warning('The bug report button is switched on but has no usable form URL.', [
            'reason' => $reason,
        ]);
    }
}
