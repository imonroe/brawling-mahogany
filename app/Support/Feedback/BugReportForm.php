<?php

declare(strict_types=1);

namespace App\Support\Feedback;

use App\Models\Person;
use App\Support\Links\SafeUrl;
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
 * A rejected URL hides the button rather than raising, because a bug-report
 * form is not worth a white screen. It is logged once per process instead, so
 * the failure is visible to whoever configured it without one line per request.
 *
 * ## And not on our own host
 *
 * The frame is sandboxed `allow-scripts allow-same-origin`, which a form needs
 * to keep its own storage and its own cookies — and which is **not a sandbox at
 * all** when the framed document is served from the application's own origin:
 * it reaches `window.parent` and reads the session. A self-host that proxies
 * n8n under the app's own domain is an ordinary layout rather than a
 * contrivance, and n8n is a third-party application with its own attack
 * surface, so this turns a compromised form into account takeover.
 *
 * `SafeUrl` answers *"is this a URL"*, not *"whose"*, so the host check lives
 * here. Enforced rather than asserted: the sandbox's own docblock claimed *"it
 * is not our origin"* on nothing but the operator's good sense.
 */
final class BugReportForm
{
    /** Whether this process has already said the configuration is unusable. */
    private static bool $warned = false;

    /**
     * What the shell needs, or null when there is no button to draw.
     *
     * @return array{url: string}|null
     */
    public static function propsFor(?Person $person): ?array
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

        if (self::isOwnHost($url)) {
            self::warnOnce('BUG_REPORT_URL is on this application’s own host, which defeats the frame’s sandbox.');

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
     * flags this way.
     */
    private static function switchedOn(): bool
    {
        return filter_var(
            config('services.bug_report.enabled'),
            FILTER_VALIDATE_BOOL,
        );
    }

    /** Whether this URL is served by the application framing it. */
    private static function isOwnHost(string $url): bool
    {
        $formHost = parse_url($url, PHP_URL_HOST);
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($formHost) || ! is_string($appHost) || $appHost === '') {
            return false;
        }

        return mb_strtolower($formHost) === mb_strtolower($appHost);
    }

    /** Reset the once-per-process latch. For tests, which run many configurations. */
    public static function forgetWarning(): void
    {
        self::$warned = false;
    }

    private static function warnOnce(string $reason): void
    {
        if (self::$warned) {
            return;
        }

        self::$warned = true;

        // Context rather than interpolation (docs/Testing.md), and no PII:
        // this is deployment configuration, not anything a person typed.
        Log::warning('The bug report button is switched on but has no usable form URL.', [
            'reason' => $reason,
        ]);
    }
}
