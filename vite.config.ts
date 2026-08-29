import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig, loadEnv } from 'vite';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');

    return {
        server: {
            // 0.0.0.0 so the dev server is reachable from outside its container;
            // `origin` so the asset URLs it writes into public/hot point at the
            // host, which is where the browser actually is.
            host: '0.0.0.0',
            port: 5173,
            origin: 'http://localhost:5173',
            // laravel-vite-plugin derives its default `cors.origin` from
            // APP_URL, but only when `server.origin` above is unset — since
            // we set it, we must allow the app's own origin explicitly, or
            // the browser (serving the page from APP_URL) gets blocked
            // loading assets from the Vite origin.
            cors: {
                origin: [env.APP_URL, 'http://localhost:5173'].filter(Boolean),
            },
            hmr: { host: 'localhost' },
        },
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.ts'],
                refresh: true,
            }),
            inertia(),
            tailwindcss(),
            vue({
                template: {
                    transformAssetUrls: {
                        base: null,
                        includeAbsolute: false,
                    },
                },
            }),
            wayfinder({
                formVariants: true,
            }),

            /*
             * The PWA (#102 · PRD §8.1). `injectManifest`, not `generateSW`,
             * because #103 needs its own `push` and `notificationclick`
             * listeners in the same worker — a generated one has nowhere to
             * put them, and a second worker is not a thing a page can have.
             *
             * The output lands in `public/build` with everything else, and is
             * served to the browser from `/sw.js` by a Laravel route. A worker
             * may only control URLs below its own path, so one registered from
             * `/build/sw.js` would control `/build/*` and nothing a person
             * ever visits. See `ServiceWorkerController` for why that is a
             * route rather than a `Service-Worker-Allowed` header.
             */
            VitePWA({
                strategies: 'injectManifest',
                srcDir: 'resources/js',
                filename: 'sw.ts',
                // Laravel serves the HTML, so there is no index.html for the
                // plugin to inject a registration into; `app.ts` registers.
                injectRegister: null,
                /*
                 * **No manifest from the plugin.** `WebManifestController`
                 * serves it, because the plugin's copy lands in `public/build`
                 * and is appended to the precache list as a relative URL —
                 * `additionalManifestEntries`, which workbox concatenates
                 * after every transform, so nothing here can correct it. One
                 * 404 inside `install` stops the worker activating at all.
                 */
                manifest: false,
                injectManifest: {
                    // The hashed bundle, and only that. Everything else this
                    // app serves is an authenticated, per-request response —
                    // see `resources/js/sw.ts` on why none of it is precached.
                    globPatterns: ['assets/**/*.{js,css,woff2}'],
                    /*
                     * A **classic** worker, not an ES module, which is the
                     * plugin's default here.
                     *
                     * A module worker has to be registered with
                     * `{ type: 'module' }`, and a browser that does not
                     * support that rejects the registration outright — so the
                     * PWA would install on some phones and silently not on
                     * others. Given this whole slice exists because web push
                     * on iOS requires an installed PWA (#102, #103), a
                     * registration that depends on a comparatively recent
                     * browser capability is the wrong trade for the one
                     * capability the worker actually needs, which is being
                     * there.
                     */
                    rollupFormat: 'iife',
                    /*
                     * **And every precache URL needs the same prefix**, for
                     * the reason `buildBase` exists: workbox resolves a
                     * relative entry against the worker's own location, which
                     * is `/sw.js`, so `assets/app-abc.js` becomes
                     * `/assets/app-abc.js` and 404s.
                     *
                     * `manifestTransforms` rather than the more obvious
                     * `modifyURLPrefix`, and the difference is not cosmetic:
                     * `modifyURLPrefix` is applied to the **globbed** entries
                     * only, and the plugin appends the web manifest to
                     * `additionalManifestEntries` afterwards — so the assets
                     * were fixed and `manifest.webmanifest` was left bare,
                     * one 404 instead of 140. `manifestTransforms` runs over
                     * the finished list, which is the only place that sees
                     * both.
                     *
                     * Idempotent by design: an entry that is already absolute
                     * is left alone, so this cannot double-prefix if the
                     * plugin's own handling changes.
                     *
                     * `PwaTest`'s *"precaches URLs that resolve to files that
                     * exist"* is what found the manifest case, by reading the
                     * **built** worker — this bug lives in build output rather
                     * than in any source file anybody would think to check.
                     */
                    manifestTransforms: [
                        async (entries) => ({
                            manifest: entries.map((entry) => ({
                                ...entry,
                                url: entry.url.startsWith('/')
                                    ? entry.url
                                    : `/build/${entry.url}`,
                            })),
                            warnings: [],
                        }),
                    ],
                },
                devOptions: {
                    // A stale worker is the single most confusing thing to
                    // debug in a dev loop, so it is off by default and turned
                    // on deliberately.
                    enabled: false,
                },
            }),
        ],
    };
});
