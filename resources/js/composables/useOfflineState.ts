/**
 * S56 — what the app knows about the network, and how old its data is (#102).
 *
 * ## Two questions, not one
 *
 * *"Are we online?"* and *"how old is what you are reading?"* are separate,
 * and a banner that conflates them is the banner people learn to ignore.
 * Somebody online is reading fresh data and needs no banner at all; somebody
 * offline with a cached queue needs to know **when** it is from; somebody
 * offline with nothing cached needs to be told that plainly rather than shown
 * an empty list that looks like a finished day's work.
 *
 * ## `navigator.onLine` is necessary and not sufficient
 *
 * It reports whether the machine has *a* network connection, not whether this
 * server is reachable — a captive portal at an open house is online by that
 * measure. So the flag is a hint, and the honest signal is the service
 * worker having had to fall back to its cache, which is what `cachedAt`
 * being fresher than the page is really saying.
 */
import { onMounted, onUnmounted, readonly, ref } from 'vue';

/** When the service worker last stored a copy of an offline-readable page. */
const cachedAt = ref<Date | null>(null);

const online = ref(true);

/**
 * Ask the worker how old its cache is.
 *
 * A `MessageChannel` rather than a broadcast, so the answer comes back to the
 * caller instead of to every tab — and so a page with no worker (a browser
 * that refused the registration, a fresh clone with nothing built) simply
 * never resolves anything rather than throwing.
 */
async function readCacheStatus(): Promise<void> {
    const worker = navigator.serviceWorker?.controller;

    if (!worker) {
        return;
    }

    const answer = await new Promise<{ fetchedAt: number | null }>(
        (resolve) => {
            const channel = new MessageChannel();

            // A worker that never answers must not leave the caller hanging: the
            // banner is drawn either way, and "we do not know" is `null`.
            const timeout = setTimeout(
                () => resolve({ fetchedAt: null }),
                1000,
            );

            channel.port1.onmessage = (event: MessageEvent) => {
                clearTimeout(timeout);
                resolve(event.data);
            };

            worker.postMessage({ type: 'CACHE_STATUS' }, [channel.port2]);
        },
    );

    cachedAt.value =
        answer.fetchedAt === null ? null : new Date(answer.fetchedAt);
}

export function useOfflineState() {
    function goOnline(): void {
        online.value = true;
    }

    function goOffline(): void {
        online.value = false;
        void readCacheStatus();
    }

    onMounted(() => {
        online.value = navigator.onLine;

        window.addEventListener('online', goOnline);
        window.addEventListener('offline', goOffline);

        if (!online.value) {
            void readCacheStatus();
        }
    });

    onUnmounted(() => {
        window.removeEventListener('online', goOnline);
        window.removeEventListener('offline', goOffline);
    });

    return {
        online: readonly(online),
        cachedAt: readonly(cachedAt),
        readCacheStatus,
    };
}
