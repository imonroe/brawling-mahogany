/**
 * S55 — the permission flow (#103 · PRD §4.12 F12.2).
 *
 * ## The pre-prompt is not optional
 *
 * Issue #103:
 *
 * > A browser permission prompt fired without explanation is a permission
 * > **permanently denied**, and there is no second chance. Ask in the app,
 * > explain what will be sent, and only then trigger the browser prompt.
 *
 * That is a hard constraint rather than a nicety: `Notification.requestPermission()`
 * can be answered "no" exactly once, and after that the browser refuses to ask
 * again — there is no API to re-prompt, and the only route back is a settings
 * panel most people cannot find. So `subscribe()` is never called on page
 * load, only from a click on a control that has already said what it is for.
 *
 * ## "Blocked at OS level" is the state people forget
 *
 * Also #103's words. Somebody can say **yes** in the browser and **no** in
 * iOS Settings, and then nothing arrives with every layer reporting success:
 * `Notification.permission` is `granted`, the subscription is valid, the push
 * service accepts the message. It is undetectable from here directly — so it
 * is inferred: permission granted, a subscription that registers fine, and
 * still nothing. The screen names the possibility rather than pretending
 * everything is working.
 */
import { computed, ref } from 'vue';

export type PushPermission = 'unsupported' | 'default' | 'granted' | 'denied';

/**
 * The VAPID public key, base64url as the server holds it, as the `Uint8Array`
 * `pushManager.subscribe()` demands.
 *
 * Hand-decoded because `atob` does not know base64**url**: the two characters
 * that differ (`-_` for `+/`) are exactly the ones a P-256 point is likely to
 * contain, so a key that decodes fine nine times in ten and throws on the
 * tenth is the failure this avoids.
 */
function decodeKey(base64Url: string): Uint8Array<ArrayBuffer> {
    const padded = base64Url.padEnd(
        base64Url.length + ((4 - (base64Url.length % 4)) % 4),
        '=',
    );

    const binary = atob(padded.replace(/-/g, '+').replace(/_/g, '/'));

    /*
     * Built over an explicit `ArrayBuffer` rather than with `Uint8Array.from`,
     * which infers `ArrayBufferLike` — and `BufferSource` will not accept
     * that, because it could in principle be a `SharedArrayBuffer`.
     */
    const bytes = new Uint8Array(new ArrayBuffer(binary.length));

    for (let index = 0; index < binary.length; index += 1) {
        bytes[index] = binary.charCodeAt(index);
    }

    return bytes;
}

/** Base64url, for handing the browser's own keys back to the server. */
function encodeKey(buffer: ArrayBuffer | null): string {
    if (!buffer) {
        return '';
    }

    let binary = '';

    for (const byte of new Uint8Array(buffer)) {
        binary += String.fromCharCode(byte);
    }

    return btoa(binary)
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '');
}

export function usePushSubscription(publicKey: string | null) {
    const permission = ref<PushPermission>('unsupported');
    const busy = ref(false);

    /** The endpoint this browser currently holds, hashed to match the server's. */
    const fingerprint = ref<string | null>(null);

    function supported(): boolean {
        return (
            'serviceWorker' in navigator &&
            'PushManager' in window &&
            'Notification' in window
        );
    }

    async function fingerprintOf(endpoint: string): Promise<string> {
        const digest = await crypto.subtle.digest(
            'SHA-256',
            new TextEncoder().encode(endpoint),
        );

        return Array.from(new Uint8Array(digest))
            .map((byte) => byte.toString(16).padStart(2, '0'))
            .join('');
    }

    /**
     * What this browser currently thinks, without asking it anything.
     *
     * Safe to call on load: reading `Notification.permission` does not prompt,
     * and neither does `getSubscription()`.
     */
    async function refresh(): Promise<void> {
        if (!supported()) {
            permission.value = 'unsupported';

            return;
        }

        permission.value = Notification.permission as PushPermission;

        const registration = await navigator.serviceWorker.getRegistration();
        const existing = await registration?.pushManager.getSubscription();

        fingerprint.value = existing
            ? await fingerprintOf(existing.endpoint)
            : null;
    }

    /**
     * Ask the browser, then register what it hands back.
     *
     * **Only ever from a click.** See the module docblock: a prompt somebody
     * did not ask for is a permission lost for good.
     */
    async function subscribe(): Promise<{
        ok: boolean;
        subscription?: Record<string, string>;
    }> {
        if (!supported() || !publicKey) {
            return { ok: false };
        }

        busy.value = true;

        try {
            permission.value =
                (await Notification.requestPermission()) as PushPermission;

            if (permission.value !== 'granted') {
                return { ok: false };
            }

            const registration = await navigator.serviceWorker.ready;

            const subscription = await registration.pushManager.subscribe({
                /*
                 * Required, and required to be `true`, by every browser that
                 * implements this: a subscription that could push silently is
                 * a tracking vector, so the spec makes the promise explicit.
                 */
                userVisibleOnly: true,
                applicationServerKey: decodeKey(publicKey),
            });

            fingerprint.value = await fingerprintOf(subscription.endpoint);

            return {
                ok: true,
                subscription: {
                    endpoint: subscription.endpoint,
                    public_key: encodeKey(subscription.getKey('p256dh')),
                    auth_token: encodeKey(subscription.getKey('auth')),
                },
            };
        } catch {
            /*
             * A rejected subscribe, a worker that never became ready, a key
             * the browser would not accept. None of it is actionable by the
             * person reading the screen beyond "it did not work", and the
             * panel still has every notification either way.
             */
            return { ok: false };
        } finally {
            busy.value = false;
        }
    }

    /** Tell the browser to forget it, so the server's row is not orphaned. */
    async function unsubscribe(): Promise<string | null> {
        const registration = await navigator.serviceWorker.getRegistration();
        const existing = await registration?.pushManager.getSubscription();

        if (!existing) {
            return null;
        }

        const endpoint = existing.endpoint;

        await existing.unsubscribe();
        fingerprint.value = null;

        return endpoint;
    }

    return {
        permission,
        busy,
        fingerprint,
        supported,
        refresh,
        subscribe,
        unsubscribe,
        /**
         * Granted here, and still nothing arriving. See the module docblock:
         * this is the only way "blocked at OS level" is visible at all.
         */
        mayBeBlockedBySystem: computed(
            () => permission.value === 'granted' && fingerprint.value === null,
        ),
    };
}
