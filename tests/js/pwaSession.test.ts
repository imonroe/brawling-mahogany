/**
 * What the page tells the service worker about the session (#102, #103).
 *
 * Both behaviours here were **absent** in round 1 of this PR's review, and
 * both are invisible from PHP: the cache lives in the browser and the
 * re-registration is a `fetch` nothing on the server initiates.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';

const cachesDelete = vi.fn();
const fetchMock = vi.fn();

let subscription: unknown = null;
let permission = 'granted';

vi.stubGlobal('navigator', {
    serviceWorker: {
        getRegistration: async () => ({
            pushManager: { getSubscription: async () => subscription },
        }),
    },
});

/*
 * The page empties the cache itself. It used to ask the worker by
 * `postMessage`, which is fire-and-forget — and on a first load, before a
 * worker has claimed the page, there is nobody to receive it. `caches` is
 * available in a window too, so there is no reason to go through anybody.
 */
vi.stubGlobal('caches', { delete: cachesDelete });

vi.stubGlobal('PushManager', class {});
vi.stubGlobal('Notification', {
    get permission() {
        return permission;
    },
});
vi.stubGlobal('fetch', fetchMock);

const { reconcileOfflineCache, reRegisterPush } = await import('@/lib/pwa');

beforeEach(() => {
    cachesDelete.mockReset().mockResolvedValue(true);
    fetchMock.mockReset().mockResolvedValue({ ok: true });
    window.localStorage.clear();
    document.cookie = 'XSRF-TOKEN=tok%3Den';
    subscription = null;
    permission = 'granted';
});

describe('the offline cache and who it belongs to', () => {
    it('clears it the first time it sees this browser', async () => {
        // Nothing stored yet, so nothing is known about whose copies these
        // are — and "unknown" has to mean "not theirs".
        await reconcileOfflineCache('person-1', 'team-1');

        expect(cachesDelete).toHaveBeenCalledWith('goldieflow-offline-v1');
    });

    it('leaves it alone when the same person is still in the same team', async () => {
        await reconcileOfflineCache('person-1', 'team-1');
        cachesDelete.mockReset().mockResolvedValue(true);

        await reconcileOfflineCache('person-1', 'team-1');

        expect(cachesDelete).not.toHaveBeenCalled();
    });

    it('clears it when somebody else signs in on this device', async () => {
        /*
         * The shared-iPad case, and the one that made this blocking: cached
         * pages are keyed by URL alone, so without this the previous
         * account's `/work` was served back offline with no session in the
         * path to refuse it.
         */
        await reconcileOfflineCache('person-1', 'team-1');
        cachesDelete.mockReset().mockResolvedValue(true);

        await reconcileOfflineCache('person-2', 'team-1');

        expect(cachesDelete).toHaveBeenCalledWith('goldieflow-offline-v1');
    });

    it('clears it when the same person switches team', async () => {
        // `/work` is one URL for every team somebody is in, so the team is
        // half the identity even though the person has not changed.
        await reconcileOfflineCache('person-1', 'team-1');
        cachesDelete.mockReset().mockResolvedValue(true);

        await reconcileOfflineCache('person-1', 'team-2');

        expect(cachesDelete).toHaveBeenCalledWith('goldieflow-offline-v1');
    });

    it('clears it on sign-out, when there is no identity at all', async () => {
        await reconcileOfflineCache('person-1', 'team-1');
        cachesDelete.mockReset().mockResolvedValue(true);

        await reconcileOfflineCache(null, null);

        expect(cachesDelete).toHaveBeenCalledWith('goldieflow-offline-v1');
    });
});

describe('handing the push subscription back', () => {
    it('re-posts the subscription this browser already holds', async () => {
        /*
         * The sign-out hook deletes every subscription a person has, so
         * without this push turned itself off permanently for every device
         * the first time anybody signed out — and the only way back was
         * visiting Settings on each device and pressing the button again.
         */
        subscription = {
            endpoint: 'https://fcm.googleapis.com/fcm/send/abc',
            toJSON: () => ({ keys: { p256dh: 'pub', auth: 'aut' } }),
        };

        await reRegisterPush();

        expect(fetchMock).toHaveBeenCalledTimes(1);

        const [url, options] = fetchMock.mock.calls[0];

        expect(url).toBe('/settings/notifications/push');
        expect(JSON.parse(options.body)).toEqual({
            endpoint: 'https://fcm.googleapis.com/fcm/send/abc',
            public_key: 'pub',
            auth_token: 'aut',
        });
        // Decoded from the cookie, which is where this app's token lives —
        // there is no csrf-token meta tag to read.
        expect(options.headers['X-XSRF-TOKEN']).toBe('tok=en');
    });

    it('does nothing when the browser holds no subscription', async () => {
        subscription = null;

        await reRegisterPush();

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('never touches a browser that has not granted permission', async () => {
        /*
         * The rule this protects is #103's: a prompt fired without
         * explanation is a permission **permanently** denied. This function
         * only ever reads, but stopping at `default` keeps that true even if
         * somebody later reaches for `subscribe()` in here.
         */
        permission = 'default';
        subscription = {
            endpoint: 'https://example.test/x',
            toJSON: () => ({ keys: { p256dh: 'pub', auth: 'aut' } }),
        };

        await reRegisterPush();

        expect(fetchMock).not.toHaveBeenCalled();
    });
});

describe('when the cache cannot be emptied', () => {
    it('does not record the new identity, so the next navigation retries', async () => {
        /*
         * The other way round costs somebody else their work queue: an
         * identity recorded against a cache that was never cleared is a page
         * this browser will go on serving to the wrong person, and nothing
         * will ever look again.
         */
        cachesDelete.mockRejectedValue(new Error('no'));

        await reconcileOfflineCache('person-1', 'team-1');

        cachesDelete.mockReset().mockResolvedValue(true);

        await reconcileOfflineCache('person-1', 'team-1');

        expect(cachesDelete).toHaveBeenCalledWith('goldieflow-offline-v1');
    });
});
