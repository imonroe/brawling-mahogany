<?php

declare(strict_types=1);

use App\Support\Feedback\BugReportForm;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia;

/**
 * The top bar's Report a bug button (issue #176).
 *
 * What is pinned here is the button's *absence*, four ways, because that is
 * the half nothing on screen advertises. The URL reaching a signed-in person
 * is one case; the other four are configurations in which it must not.
 */
beforeEach(function (): void {
    // The class latches its warning per process, and a process runs the whole
    // suite. Without this the second misconfigured case logs nothing.
    BugReportForm::forgetWarning();

    config()->set('services.bug_report.enabled', true);
    config()->set('services.bug_report.url', 'https://n8n.example.test/form/bugs');

    [$this->team, $this->member] = $this->teamWithMember();
});

it('hands the form URL to a signed-in person', function (): void {
    $this->actingAsPerson($this->member, $this->team);

    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('bugReport.url', 'https://n8n.example.test/form/bugs'));
});

it('tells a guest nothing, so the sign-in page never carries the URL', function (): void {
    /*
     * Not a permission — anybody may report a bug. The button lives in the
     * shell's top bar, which a stranger never sees, so handing a stranger the
     * URL would put it in the HTML of the one page the internet can reach.
     */
    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('bugReport', null));
});

it('draws no button when the flag is off, however somebody spelled off', function (mixed $off): void {
    /*
     * The two keys exist separately so that switching the button off during an
     * n8n outage does not mean losing the address — which makes this the one
     * setting somebody changes in a hurry, under pressure, from a phone.
     *
     * `env()` converts only `true`, `false`, `null` and `empty`; every other
     * string arrives as a string, and `(bool) 'off'` is true. So a plain cast
     * failed open on three of the likeliest spellings of the word.
     */
    config()->set('services.bug_report.enabled', $off);

    $this->actingAsPerson($this->member, $this->team);

    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('bugReport', null));
})->with([
    'false' => [false],
    'the string off' => ['off'],
    'the string no' => ['no'],
    'the string disabled' => ['disabled'],
    'zero' => ['0'],
    'unset' => [null],
]);

it('refuses a form served by this application itself', function (): void {
    /*
     * The frame is sandboxed `allow-scripts allow-same-origin`, which a hosted
     * form needs and which is not a sandbox at all when the framed document is
     * same-origin: it reaches `window.parent` and reads the session. A
     * self-host that proxies n8n under the app's own domain is an ordinary
     * layout, and n8n is a third-party application with its own attack
     * surface.
     */
    config()->set('app.url', 'https://goldieflow.example.test');
    config()->set('services.bug_report.url', 'https://goldieflow.example.test/n8n/form/bugs');

    $this->actingAsPerson($this->member, $this->team);

    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('bugReport', null));
});

it('does not confuse a different host that merely looks similar', function (): void {
    // The check is the host and nothing else — a neighbouring subdomain is
    // somebody else's origin and the sandbox holds there.
    config()->set('app.url', 'https://goldieflow.example.test');
    config()->set('services.bug_report.url', 'https://n8n.goldieflow.example.test/form/bugs');

    $this->actingAsPerson($this->member, $this->team);

    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('bugReport.url', 'https://n8n.goldieflow.example.test/form/bugs'));
});

it('says once, and only once, that the flag is on with no address behind it', function (): void {
    Log::spy();

    config()->set('services.bug_report.url', null);

    $this->actingAsPerson($this->member, $this->team);

    /*
     * Two requests, because one cannot tell the two failures apart: a latch
     * that never fires and a latch that fires on every request both produce
     * exactly one warning across a single request. The first assertion is that
     * silence is wrong — an operator who set the flag and forgot the address
     * has no button and no explanation. The second is that the fix for that is
     * not a line per request for as long as the mistake stands.
     */
    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('bugReport', null));

    $this->get('/dashboard')->assertOk();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'bug report button')
            && str_contains((string) $context['reason'], 'BUG_REPORT_URL is empty'));
});

it('refuses a URL that is not http or https', function (string $url): void {
    /*
     * An `iframe src` is not inert, and #61's lesson applies unchanged: a
     * `javascript:` URL is script execution in the reader's session the moment
     * something renders it. It takes a typo in the environment to get there,
     * and a typo that becomes stored XSS is still worth refusing.
     */
    config()->set('services.bug_report.url', $url);

    $this->actingAsPerson($this->member, $this->team);

    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('bugReport', null));
})->with([
    'javascript' => ['javascript:alert(1)'],
    'data' => ['data:text/html,<script>alert(1)</script>'],
    'no host' => ['https:///form'],
    'relative' => ['/form/bugs'],
]);

it('stores the URL it judged, whitespace and all', function (): void {
    // `SafeUrl::permits()` trims before it looks, so the value handed to the
    // front end has to be the trimmed one or the guard and the `src` disagree.
    config()->set('services.bug_report.url', "  https://n8n.example.test/form/bugs \n");

    $this->actingAsPerson($this->member, $this->team);

    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('bugReport.url', 'https://n8n.example.test/form/bugs'));
});
