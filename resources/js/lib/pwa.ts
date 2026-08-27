/**
 * What the page has to tell the service worker (#102, #103).
 *
 * Both of these are here rather than in a component because they are
 * properties of the **session**, not of any screen — and because a persistent
 * layout would run a component's `onMounted` once and never again, which is
 * the wrong lifetime for both.
 */

import { OFFLINE_CACHE } from '@/lib/pwaCache';

/**
 * Who the offline cache was written for, as far as this browser knows.
 *
 * `localStorage`, because it has to outlive the session it describes: the
 * whole point is noticing that the person or team has *changed* since the
 * cache was filled, and a value that dies with the session cannot say that.
 */
const IDENTITY_KEY = 'goldieflow.offline.identity';

/**
 * Laravel's CSRF token, from the cookie it sets rather than from a meta tag.
 *
 * This application renders no `csrf-token` meta — Inertia's client reads the
 * `XSRF-TOKEN` cookie and sends it back as `X-XSRF-TOKEN`, and the value is
 * URL-encoded in the cookie. Adding a meta tag for one `fetch` would be a
 * second source for the same secret, and the two would drift the first time
 * somebody rotated how it is issued.
 */
function xsrfToken(): string | null {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match?.[1] ? decodeURIComponent(match[1]) : null;
}

/**
 * Empty the offline cache, from the page.
 *
 * `caches` is available in a window as well as in a worker, so this deletes
 * the store directly rather than asking the worker to. Round 2 of review made
 * the case: a `postMessage` is fire-and-forget, so the previous version
 * recorded the new identity whether or not anything received the request —
 * and on a first load, before a worker has claimed the page, there is nobody
 * to receive it at all. A cache the page believes it cleared and did not is
 * the same defect as never clearing it, minus any chance of noticing.
 */
async function emptyOfflineCache(): Promise<boolean> {
    try {
        // `true` when there was one to delete, `false` when there was not —
        // both are success. Only a throw means it did not happen.
        await caches.delete(OFFLINE_CACHE);

        return true;
    } catch {
        return false;
    }
}

/**
 * Throw away offline copies belonging to somebody else.
 *
 * ## Why this is checked on every load rather than done on sign-out
 *
 * Round 1 of review found the cache was never cleared at all: sign out on a
 * shared iPad, go offline, open `/work`, and the previous account's rendered
 * work queue came back — keyed by URL, with no session in the path to refuse
 * it. The same mechanism served one team's queue under another, because
 * `/work` is a single URL for every team a person is in.
 *
 * Clearing on the sign-out click would close the tidy case and miss the ones
 * that matter: a session that expired, a sign-out from another device, a tab
 * closed mid-flow, a browser that never ran the handler. Comparing identity is
 * a **statement about what is in the cache** rather than a hook on one of the
 * ways it can go stale, so it holds however the transition happened.
 *
 * ## And it runs on every Inertia navigation, not on `load`
 *
 * Round 2 of review found the first version wired to
 * `window.addEventListener('load')`, which closed none of the cases it was
 * written for. This is a single-page app: **signing out, signing in and
 * switching team are all Inertia visits, and none of them fires a document
 * `load`.** So on a shared iPad, A could sign out and B sign in without one —
 * `localStorage` still held A's identity, nothing was ever cleared, and B
 * offline got A's `/work` back.
 *
 * `router.on('navigate')` is the event that actually happens, and the pattern
 * `useAdvanceDialog` already uses for the same reason.
 *
 * The identity is a person and a team, because both change what `/work` and
 * `/dashboard` render.
 */
export async function reconcileOfflineCache(
    personId: string | null,
    teamId: string | null,
): Promise<void> {
    const identity = `${personId ?? 'none'}:${teamId ?? 'none'}`;

    let previous: string | null = null;

    try {
        previous = window.localStorage.getItem(IDENTITY_KEY);
    } catch {
        /*
         * Private mode, or storage switched off. Fall through to clearing:
         * with no way to know whose copies these are, the safe answer is that
         * they are not this person's.
         */
        previous = null;
    }

    if (previous === identity) {
        return;
    }

    /*
     * **Record the new identity only once the cache is actually gone.** If
     * the delete failed, a stale stored identity means the next navigation
     * tries again — which costs a refetch, where the other way round costs
     * somebody else their work queue.
     */
    if (!(await emptyOfflineCache())) {
        return;
    }

    try {
        window.localStorage.setItem(IDENTITY_KEY, identity);
    } catch {
        // Then it is compared against null next time and cleared again, which
        // costs a refetch and never serves the wrong person's page.
    }
}

/**
 * Hand the server the push subscription this browser already holds.
 *
 * ## The claim this replaces
 *
 * `PushSubscriptionRegistry::store()` said *"the page re-subscribes on every
 * load"*, and round 1 of review checked: nothing did. The only POST was a
 * click on S55. Combined with the sign-out hook — which deletes **every**
 * subscription a person has, deliberately, so a handed-back phone stops
 * buzzing — push switched itself off permanently for every device the first
 * time anybody signed out, and the only way back was for each person to visit
 * Settings on each device and press the button again. Nobody would have
 * connected the two.
 *
 * So the claim is made true rather than deleted. The browser keeps its
 * subscription across a sign-out (it belongs to the browser, not the
 * session), and this hands it back on the next load. The endpoint is the
 * identity and `store()` upserts on it, so re-posting an unchanged
 * subscription is a no-op row-wise.
 *
 * **No permission prompt can happen here.** This only ever reads an existing
 * subscription; `subscribe()` — the call that prompts, and whose refusal is
 * permanent — stays behind the button on S55 where somebody has been told
 * what it is for.
 */
export async function reRegisterPush(): Promise<void> {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        return;
    }

    // Granted only. `default` means nobody has been asked, and asking is not
    // this function's business.
    if (Notification.permission !== 'granted') {
        return;
    }

    const registration = await navigator.serviceWorker.getRegistration();
    const subscription = await registration?.pushManager.getSubscription();

    const token = xsrfToken();

    if (!subscription || token === null) {
        return;
    }

    const keys = subscription.toJSON().keys;

    if (!keys?.p256dh || !keys?.auth) {
        return;
    }

    /*
     * `fetch`, not Inertia. This is bookkeeping that must not touch the page:
     * an Inertia visit would swap props, and a redirect back would be a
     * navigation nobody asked for on every load.
     */
    await fetch('/settings/notifications/push', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': token,
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: JSON.stringify({
            endpoint: subscription.endpoint,
            public_key: keys.p256dh,
            auth_token: keys.auth,
        }),
    }).catch(() => {
        /*
         * Offline, or the environment has no VAPID keys and answered 503.
         * Neither is worth interrupting anybody for: the notification is in
         * the panel regardless, and the next load tries again.
         */
    });
}
