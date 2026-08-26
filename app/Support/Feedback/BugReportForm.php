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

        if (! (bool) config('services.bug_report.enabled')) {
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

        return ['url' => $url];
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
