<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pwa;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * The web app manifest (#102).
 *
 * ## Why this is a route and not a build artefact
 *
 * `vite-plugin-pwa` will generate one, and it did until round 1 of review.
 * The problem is where it lands: Vite builds into `public/build`, so the
 * manifest is written there and the plugin adds `manifest.webmanifest` — a
 * **relative** URL — to the worker's precache list. Workbox resolves that
 * against the worker's own location, which is `/sw.js`, so the entry points
 * at `/manifest.webmanifest`, which was not a thing that existed. One 404
 * inside `install` is enough: workbox throws `bad-precaching-response` and
 * the worker never activates.
 *
 * The assets were fixable with a `manifestTransforms` hook. That entry was
 * not: the plugin appends it to `additionalManifestEntries`, which workbox
 * concatenates *after* every transform has run, so nothing in the build
 * configuration can reach it.
 *
 * Serving it makes the question go away rather than working around it, and
 * a manifest is the one PWA file that gains nothing from a bundler — no
 * imports, no hashing, no minification worth having. It also buys two
 * things the build could not:
 *
 *  - **The product name follows configuration.** `config('app.product_name')`
 *    is what a person reads (`docs/Environment and secrets.md`), and a
 *    build-time `env()` would bake one environment's name into an image
 *    deployed to another.
 *  - **The icon URLs are absolute and ours**, so the manifest cannot drift
 *    from the files `PwaTest` asserts exist.
 */
class WebManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $name = (string) config('app.product_name');

        return response()->json([
            'name' => $name,
            'short_name' => $name,
            'description' => 'The workflow, tasks and client updates for a real estate transaction.',
            /*
             * The dashboard rather than `/`, which redirects for a signed-in
             * person and lands on marketing for everybody else. An installed
             * app opening on a redirect is a blank flash every launch.
             */
            'start_url' => '/dashboard',
            'scope' => '/',
            'display' => 'standalone',
            // Design System §2.4's `--primary`, as the sRGB a manifest can
            // hold: a manifest is outside the token layer for the same reason
            // a PNG is.
            'theme_color' => '#1A588F',
            'background_color' => '#FFFFFF',
            'icons' => [
                ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                /*
                 * A separate picture, not the same one relabelled. A launcher
                 * crops a `maskable` icon to its own shape and only the inner
                 * 80% survives, so declaring an `any`-shaped icon maskable
                 * gets its edges shaved. `scripts/generate-pwa-icons.php`
                 * draws both.
                 */
                ['src' => '/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, [
            'Content-Type' => 'application/manifest+json',
        ]);
    }
}
