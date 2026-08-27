<?php

declare(strict_types=1);

/**
 * The PWA shell (#102 · PRD §4.12 F12.1, §8.1).
 *
 * Most of this feature is a service worker, which no PHP test can execute.
 * What a test *can* hold is the part that decides whether the worker ever
 * controls anything — and that part is a route, deliberately, so that it can
 * be held here rather than trusted to a deployment.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
});

it('serves the worker from the root, which is the whole point', function (): void {
    /*
     * **A worker controls only URLs at or below its own path.** Vite writes
     * its output to `public/build`, so a worker registered from
     * `/build/sw.js` would install cleanly, report itself healthy, and
     * intercept no navigation anybody makes. The failure mode is silence,
     * which is the worst kind for a feature whose whole job is to work when
     * the network does not.
     */
    if (! is_file(public_path('build/sw.js'))) {
        $this->markTestSkipped('The front end has not been built in this environment.');
    }

    $response = $this->get('/sw.js')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/javascript; charset=utf-8')
        ->assertHeader('Service-Worker-Allowed', '/');

    /*
     * A stale worker is the one bug here a person cannot clear by reloading:
     * the browser keeps using the cached script to decide what to serve,
     * including which script to serve.
     *
     * The **directives**, not the header verbatim — Symfony reorders them
     * alphabetically and adds `private` of its own accord, so asserting the
     * exact string would be asserting the framework's serialisation rather
     * than our intent, and would break on a framework upgrade that changed
     * neither.
     */
    $cacheControl = $response->headers->get('Cache-Control');

    expect($cacheControl)->toContain('no-store')
        ->and($cacheControl)->toContain('no-cache');
});

it('answers 404 rather than 500 when the front end has not been built', function (): void {
    /*
     * A fresh clone, or a CI job that only touches PHP. A browser handles a
     * 404 gracefully — registration fails, the page works, nothing is
     * offline — where a 500 would put an exception in the log on every page
     * load for a condition that is not an error.
     */
    $path = public_path('build/sw.js');
    $existing = is_file($path) ? file_get_contents($path) : null;

    if ($existing !== null) {
        unlink($path);
    }

    try {
        $this->get('/sw.js')->assertNotFound();
    } finally {
        if ($existing !== null) {
            file_put_contents($path, $existing);
        }
    }
});

it('does not need a session to fetch the worker', function (): void {
    /*
     * The browser re-fetches this on its own schedule to check for updates. A
     * session that expired between two of those checks would otherwise get
     * the sign-in page's HTML served back as JavaScript. It carries no data —
     * the same bytes for everybody, signed in or not.
     */
    if (! is_file(public_path('build/sw.js'))) {
        $this->markTestSkipped('The front end has not been built in this environment.');
    }

    // No `actingAs`.
    $this->get('/sw.js')->assertOk();
});

it('precaches URLs that resolve to files that exist', function (): void {
    /*
     * Round 1 of review's first blocking finding, and the reason it was worth
     * a machine: **serving the worker from `/sw.js` changed what its relative
     * URLs mean.**
     *
     * Workbox resolves a precache URL against the *worker's own* location, so
     * `assets/app-abc.js` from `/sw.js` becomes `/assets/app-abc.js` — while
     * the file is at `/build/assets/app-abc.js`. All 140 entries 404, workbox
     * throws `bad-precaching-response` inside `install`, and the worker never
     * activates: no offline reads, no `push`, no `notificationclick`. Both
     * issues in this slice dead in production, with every other test green.
     *
     * The scope fix and the precache base are two halves of one decision, and
     * nothing else in the suite connects them. This reads the **built**
     * worker, because the bug lives in the build output rather than in any
     * source file anybody would think to look at.
     */
    $worker = public_path('build/sw.js');

    if (! is_file($worker)) {
        $this->markTestSkipped('The front end has not been built in this environment.');
    }

    $source = (string) file_get_contents($worker);

    expect(preg_match_all('/"url":"([^"]+)"/', $source, $matches))
        ->toBeGreaterThan(0, 'The built worker precaches nothing at all.');

    foreach ($matches[1] as $url) {
        /*
         * Resolved the way the browser will resolve it: against `/sw.js`.
         * An entry that is already absolute is unaffected, which is the point
         * of doing it this way rather than asserting a prefix — the assertion
         * is *"this resolves to a file we serve"*, not *"this starts with
         * /build"*.
         */
        $path = parse_url((string) $url, PHP_URL_PATH) ?? $url;
        $resolved = str_starts_with((string) $path, '/') ? $path : '/'.$path;

        expect(is_file(public_path(ltrim((string) $resolved, '/'))))
            ->toBeTrue("The worker precaches {$url}, which resolves to {$resolved} and is not there.");
    }
});

it('ships the icons the manifest names', function (): void {
    /*
     * A manifest naming an icon that is not there is an installable app with
     * a blank tile on somebody's home screen — and S54 spends real copy
     * asking them to put it there.
     *
     * `maskable` is a separate file rather than the same one relabelled: a
     * launcher crops a maskable icon to its own shape and only the inner 80%
     * survives, so declaring an `any`-shaped icon maskable gets its edges
     * shaved off.
     */
    foreach ([
        'icons/icon-192.png' => [192, 192],
        'icons/icon-512.png' => [512, 512],
        'icons/icon-maskable-512.png' => [512, 512],
        'apple-touch-icon.png' => [180, 180],
    ] as $path => [$width, $height]) {
        $file = public_path($path);

        expect(is_file($file))->toBeTrue("Missing {$path}");

        $size = getimagesize($file);

        expect($size[0])->toBe($width, "{$path} is the wrong width")
            ->and($size[1])->toBe($height, "{$path} is the wrong height");
    }
});

it('serves a manifest naming icons that exist', function (): void {
    /*
     * Served rather than built, because the plugin's copy entered the
     * worker's precache list as a relative URL that resolved to a path
     * nothing served — and unlike the asset entries, no build hook could
     * reach it. See `WebManifestController`.
     *
     * No session: a browser fetches this to decide whether the site is
     * installable, sometimes before anybody has signed in.
     */
    $response = $this->get('/manifest.webmanifest')->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('application/manifest+json');

    $manifest = $response->json();

    expect($manifest['name'])->toBe(config('app.product_name'))
        ->and($manifest['start_url'])->toBe('/dashboard')
        ->and($manifest['display'])->toBe('standalone');

    // A manifest naming an icon that is not there is an installable app with
    // a blank tile on somebody's home screen.
    foreach ($manifest['icons'] as $icon) {
        expect(is_file(public_path(ltrim((string) $icon['src'], '/'))))
            ->toBeTrue("The manifest names {$icon['src']}, which is not there.");
    }

    /*
     * One `maskable`, and it must be **its own file** rather than one of the
     * `any` icons relabelled — a launcher crops a maskable icon to its own
     * shape and only the inner 80% survives, so the same picture declared
     * both ways gets its edges shaved off.
     *
     * Compared against the `any` sources specifically. An earlier version
     * compared against *every* source, which of course includes the maskable
     * one — an assertion that could only ever have been false, and passed
     * anyway because a `->not->toContain()` chained after `->and()` was not
     * doing what it read as. Written out as two named lists instead, because
     * the fluent form is what hid it.
     */
    $sources = [];

    foreach ($manifest['icons'] as $icon) {
        $sources[$icon['purpose']][] = $icon['src'];
    }

    expect($sources['maskable'] ?? [])->toHaveCount(1);

    expect(in_array($sources['maskable'][0], $sources['any'] ?? [], true))
        ->toBeFalse('The maskable icon is an `any` icon relabelled.');
});

it('points the document at the manifest and a theme colour', function (): void {
    $this->actingAsPerson($this->member, $this->team);

    $response = $this->get('/dashboard')->assertOk();

    expect($response->getContent())
        ->toContain('rel="manifest"')
        // iOS ignores the manifest's `display` and reads its own tags. It is
        // the platform that makes S54 mandatory rather than optional, so the
        // tags it actually honours are the ones worth asserting.
        ->toContain('apple-mobile-web-app-capable')
        ->toContain('name="theme-color"');
});
