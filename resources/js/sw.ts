/// <reference lib="webworker" />

/**
 * The service worker (#102 · PRD §4.12 F12.1, and #103's push half).
 *
 * ## What is precached, and the much longer list of what is not
 *
 * `self.__WB_MANIFEST` is the hashed bundle — JS, CSS, fonts — and nothing
 * else. Everything else this application serves is an authenticated,
 * per-request, per-team response, and precaching one would mean writing one
 * team's data into a cache keyed by URL alone.
 *
 * That is also why there is no precached "app shell" in the usual sense. The
 * shell is a Blade response carrying the signed-in person's name, their team,
 * and their unread count; there is no version of it that is true before
 * somebody has signed in, so the offline story is *the last page you actually
 * looked at*, not a synthetic frame.
 *
 * ## Read-only offline, and honest about it
 *
 * Issue #102: *"Read-only offline. A stale banner that says when the data is
 * from."* Two routes are cached for offline reading — `/work` and
 * `/dashboard`, which is F12.1's *"work queue and today's deals"* — under
 * `NetworkFirst`: the network wins whenever it answers, so nobody reads
 * yesterday's queue while online.
 *
 * **Nothing is queued for replay.** `POST`, `PATCH` and `DELETE` are passed
 * straight to the network and allowed to fail, because in this product a
 * replayed mutation is not a neutral act: completing a task clears a gate,
 * and clearing a gate can send a client an email that cannot be recalled
 * (`CLAUDE.md`, on automation being the highest-blast-radius feature). A
 * queue that fired those hours later, from a phone in a pocket, is worse than
 * a refusal — and #102's own standard is that *"action queued" must not lie*.
 * The refusal is drawn by `OfflineNotice.vue`.
 *
 * ## Cached responses carry the time they were fetched
 *
 * S56 has to say *when* the data is from, and a `Response` in the Cache API
 * does not remember when it was put there. So a `sw-fetched-at` header is
 * stamped on the way in and read by the page through `CACHE_STATUS`. Without
 * it the banner could only say "this might be old", which is the kind of
 * warning people learn to dismiss.
 */
import { cleanupOutdatedCaches, precacheAndRoute } from 'workbox-precaching';
import { OFFLINE_CACHE } from './lib/pwaCache';

declare const self: ServiceWorkerGlobalScope;

/** The header stamped onto a cached response, in ms since the epoch. */
const FETCHED_AT = 'sw-fetched-at';

/**
 * What may be read offline. F12.1 names two things and this is them.
 *
 * Exact paths rather than a prefix, deliberately: a prefix match would
 * quietly start caching `/work/something-else` the day somebody adds it, and
 * a route nobody decided to cache is a route nobody decided was safe to show
 * stale.
 */
const OFFLINE_READABLE = ['/dashboard', '/work'];

precacheAndRoute(self.__WB_MANIFEST);
cleanupOutdatedCaches();

self.addEventListener('install', () => {
    /*
     * Take over immediately rather than waiting for every tab to close. The
     * worker is versioned with the bundle it was built beside, so an old tab
     * holding the previous worker is an old tab talking to a new server.
     */
    void self.skipWaiting();
});

self.addEventListener('activate', (event: ExtendableEvent) => {
    event.waitUntil(self.clients.claim());
});

function isOfflineReadable(url: URL): boolean {
    return (
        url.origin === self.location.origin &&
        OFFLINE_READABLE.includes(url.pathname)
    );
}

/**
 * Stamp the fetch time onto a response before it is cached.
 *
 * A new `Response` rather than a mutated one, because `response.headers` is
 * immutable for a response that came off the network.
 */
async function stamped(response: Response): Promise<Response> {
    const headers = new Headers(response.headers);
    headers.set(FETCHED_AT, String(Date.now()));

    return new Response(await response.clone().blob(), {
        status: response.status,
        statusText: response.statusText,
        headers,
    });
}

/**
 * Network first, falling back to whatever we last saw.
 *
 * Only ever `GET`, and only ever the two paths above — see the module
 * docblock on why a mutation is never replayed.
 */
async function networkFirst(request: Request): Promise<Response> {
    const cache = await caches.open(OFFLINE_CACHE);

    try {
        const response = await fetch(request);

        /*
         * Only a 200. A redirect to the sign-in page is a perfectly valid
         * response and precisely the one thing that must never be cached as
         * somebody's work queue — it would be served back offline forever,
         * and it is what an expired session looks like.
         */
        if (response.ok && response.status === 200) {
            await cache.put(request, await stamped(response.clone()));
        }

        return response;
    } catch (failure) {
        const cached = await cache.match(request);

        if (cached) {
            return cached;
        }

        throw failure;
    }
}

self.addEventListener('fetch', (event: FetchEvent) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (!isOfflineReadable(url)) {
        return;
    }

    event.respondWith(networkFirst(request));
});

/**
 * How old is what we have, if anything?
 *
 * Asked by the page rather than pushed, because the answer is only wanted
 * when a banner is about to be drawn. `null` means *"nothing cached"*, which
 * S56 renders differently from *"cached, and this old"* — the difference
 * between "you are offline and there is nothing here" and "you are offline,
 * this is from 08:12".
 */
self.addEventListener('message', (event: ExtendableMessageEvent) => {
    if (event.data?.type !== 'CACHE_STATUS') {
        return;
    }

    const port = event.ports[0];

    if (!port) {
        return;
    }

    /*
     * **About the page being read, not about the cache.**
     *
     * The first version answered with the newest stamp across *every* entry,
     * and the page never said which URL it was on — so on any screen that is
     * not cached at all (a deal, a person, settings) the banner claimed a
     * save time belonging to a different page, and the honest *"this page was
     * not saved for offline use"* branch became unreachable the moment
     * anything was in the cache. Round 5 of review found it. #102's standard
     * is that S56 must not lie, and a save time for a page that was never
     * saved is the plainest kind.
     */
    const url = typeof event.data?.url === 'string' ? event.data.url : null;

    void (async () => {
        if (url === null) {
            port.postMessage({ fetchedAt: null });

            return;
        }

        const cache = await caches.open(OFFLINE_CACHE);

        /*
         * `ignoreVary`, because the question is *"is there a copy of this
         * page"* rather than *"is there one matching this exact request"*.
         * The entries are stored against Inertia's `Vary: X-Inertia`, so a
         * plain `match()` from the page's own context misses the one that
         * would actually be served.
         */
        const response = await cache.match(url, { ignoreVary: true });
        const stamp = Number(response?.headers.get(FETCHED_AT) ?? NaN);

        port.postMessage({ fetchedAt: Number.isFinite(stamp) ? stamp : null });
    })();
});

/**
 * A push arriving (#103).
 *
 * ## The payload carries no PII, and this is the reason it can be read at all
 *
 * PRD §9. A push notification body sits on a third-party push service and on
 * a lock screen somebody else may be looking at. `App\Support\Push\SendPush`
 * decides what goes in; this only draws it. *"123 Main St has an overdue
 * task"* is fine; a client's name, phone number or figure is not.
 *
 * ## A push that cannot be parsed is still shown
 *
 * The browser will display its own generic notification if a `push` handler
 * finishes without calling `showNotification()`, and on some platforms it
 * punishes a worker that does that repeatedly by dropping the subscription.
 * So a malformed payload gets the product's name and nothing else rather than
 * being swallowed.
 */
self.addEventListener('push', (event: PushEvent) => {
    let payload: { title?: string; body?: string; url?: string; tag?: string } =
        {};

    try {
        payload = event.data?.json() ?? {};
    } catch {
        payload = {};
    }

    event.waitUntil(
        self.registration.showNotification(payload.title ?? 'Goldieflow', {
            body: payload.body,
            icon: '/icons/icon-192.png',
            badge: '/icons/icon-192.png',
            /*
             * Collapses repeats of the same subject rather than stacking six
             * lock-screen entries for one deal — the push half of the panel's
             * own grouping rule.
             */
            tag: payload.tag,
            data: { url: payload.url ?? '/notifications' },
        }),
    );
});

/**
 * Focus a tab that is already open rather than launching a second one.
 *
 * Somebody tapping a notification while the app is open in a tab expects that
 * tab, and an installed PWA that opens a duplicate window on every tap is the
 * behaviour people uninstall it for.
 */
self.addEventListener('notificationclick', (event: NotificationEvent) => {
    event.notification.close();

    const target =
        (event.notification.data?.url as string | undefined) ??
        '/notifications';

    event.waitUntil(
        (async () => {
            const clients = await self.clients.matchAll({
                type: 'window',
                includeUncontrolled: true,
            });

            for (const client of clients) {
                if (new URL(client.url).origin === self.location.origin) {
                    await client.focus();

                    return client.navigate(target).then(() => undefined);
                }
            }

            await self.clients.openWindow(target);
        })(),
    );
});
