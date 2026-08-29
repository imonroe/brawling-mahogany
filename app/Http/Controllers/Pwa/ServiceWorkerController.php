<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pwa;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The service worker, served from the root (#102).
 *
 * ## Why this is a route and not a file the web server hands over
 *
 * **A worker may only control URLs at or below its own path.** Vite writes its
 * output into `public/build`, so the worker it emits is at `/build/sw.js` —
 * which can control `/build/*` and nothing anybody ever visits. The worker
 * would install, report itself healthy, and intercept no navigation at all:
 * the failure mode is silence, which is the worst kind for a feature whose
 * whole job is to work when the network does not.
 *
 * There are three ways out. Copying the file into `public/` after every build
 * means a build artefact in version control and a copy step to forget.
 * Serving `/build/sw.js` with a `Service-Worker-Allowed: /` header widens the
 * scope, but the header has to come from whatever is serving static files —
 * Caddy here, something else on a developer's machine — so the rule lives
 * outside the application and breaks silently when the two disagree.
 *
 * A route is the third, and it is the only one this repository can hold with
 * a test: the URL *is* `/sw.js`, so the scope is `/` with no header needed,
 * and `PwaTest` asserts the whole thing rather than trusting a deployment.
 *
 * ## A 404 rather than an error page when it has not been built
 *
 * `npm run build` has not necessarily run — a fresh clone, a CI job that only
 * touches PHP. A 404 is what a browser handles gracefully: registration
 * fails, the page works, nothing is offline. A 500 would put an exception in
 * the log on every page load for a condition that is not an error.
 */
class ServiceWorkerController extends Controller
{
    /**
     * Where Vite writes it. Not configurable, because the manifest link in
     * `app.blade.php` and `vite.config.ts` would then have three places to
     * disagree.
     */
    private const BUILT_PATH = 'build/sw.js';

    public function __invoke(): Response
    {
        $path = public_path(self::BUILT_PATH);

        abort_unless(is_file($path), HttpResponse::HTTP_NOT_FOUND);

        return response(
            (string) file_get_contents($path),
            HttpResponse::HTTP_OK,
            [
                'Content-Type' => 'text/javascript; charset=utf-8',
                /*
                 * **Never cached.** A stale worker is the one bug in this
                 * feature that a person cannot clear by reloading — the
                 * browser keeps using the cached script to decide what to
                 * serve, including which script to serve. Browsers cap worker
                 * script caching at 24 hours on their own; saying so
                 * explicitly means a proxy in between does not get to decide.
                 */
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                /*
                 * Belt and braces on the scope. The URL already grants `/`,
                 * so this changes nothing today — but it is what keeps the
                 * grant if somebody later moves this behind a prefix, and it
                 * costs one header.
                 */
                'Service-Worker-Allowed' => '/',
            ],
        );
    }
}
