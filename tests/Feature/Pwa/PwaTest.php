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
