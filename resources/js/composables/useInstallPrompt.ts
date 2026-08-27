/**
 * S54 — the install prompt, and the platform that makes it mandatory (#102).
 *
 * Screen Inventory §J:
 *
 * > **iOS makes S54 mandatory rather than optional.** Web push on iOS only
 * > works once the PWA has been added to the home screen, so the install
 * > prompt is a prerequisite for notifications rather than a nicety.
 *
 * ## Four states, and only one of them is a button
 *
 * Android fires `beforeinstallprompt`, which hands over a prompt the page can
 * trigger later — one tap, no instructions. **iOS fires nothing and has no
 * API at all**, so the only thing that works there is telling somebody which
 * buttons to press, which is why S54 needs real copy rather than a control.
 *
 * The other two are refusals to ask again: already installed, and dismissed.
 *
 * ## Detecting "already installed" without an API for it
 *
 * There is none, so this reads the two side effects: `display-mode:
 * standalone` (the manifest's `display`, honoured by Chrome and by iOS 16.4+)
 * and `navigator.standalone` (iOS's own, older, non-standard flag). Either is
 * conclusive; neither is available to a browser tab, which is the point.
 */
import { computed, onMounted, onUnmounted, ref } from 'vue';

/**
 * The event Chrome hands over, which is not in `lib.dom`.
 *
 * `prompt()` may be called once, and only from a user gesture — the browser
 * revokes the event otherwise, which is why this is stored rather than acted
 * on when it arrives.
 */
type InstallEvent = Event & {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
};

/**
 * That somebody said no.
 *
 * `localStorage`, deliberately, rather than a column: it is a property of
 * *this browser on this device*, not of the person. Somebody who declined on
 * their laptop should still be asked on the phone, which is the only place an
 * installed PWA means anything.
 */
const DISMISSED_KEY = 'goldieflow.install-prompt.dismissed';

const deferred = ref<InstallEvent | null>(null);
const dismissed = ref(false);
const installed = ref(false);

function isStandalone(): boolean {
    return (
        window.matchMedia?.('(display-mode: standalone)').matches === true ||
        // iOS's own flag, which predates the standard one and is still what
        // an older iPhone reports.
        (window.navigator as Navigator & { standalone?: boolean })
            .standalone === true
    );
}

/**
 * iOS, including an iPad pretending to be a Mac.
 *
 * iPadOS 13+ reports a desktop Safari user agent, so the platform test needs
 * the touch check as well — without it, an iPad user is shown nothing at all,
 * and an iPad is exactly the device somebody works an open house from.
 */
function isIos(): boolean {
    const ua = window.navigator.userAgent;

    return (
        /iPad|iPhone|iPod/.test(ua) ||
        (ua.includes('Macintosh') && navigator.maxTouchPoints > 1)
    );
}

function readDismissed(): boolean {
    try {
        return window.localStorage.getItem(DISMISSED_KEY) === '1';
    } catch {
        // Private mode, or storage switched off. Being asked again is a much
        // smaller harm than a thrown exception on every page load.
        return false;
    }
}

export function useInstallPrompt() {
    function capture(event: Event): void {
        /*
         * Chrome shows its own mini-infobar unless the event is cancelled,
         * and that bar appears wherever Chrome likes — over the deal header,
         * typically. Cancelling here is what buys the right to ask in our own
         * words at our own moment.
         */
        event.preventDefault();
        deferred.value = event as InstallEvent;
    }

    function markInstalled(): void {
        installed.value = true;
        deferred.value = null;
    }

    onMounted(() => {
        installed.value = isStandalone();
        dismissed.value = readDismissed();

        window.addEventListener('beforeinstallprompt', capture);
        window.addEventListener('appinstalled', markInstalled);
    });

    onUnmounted(() => {
        window.removeEventListener('beforeinstallprompt', capture);
        window.removeEventListener('appinstalled', markInstalled);
    });

    async function install(): Promise<void> {
        const event = deferred.value;

        if (!event) {
            return;
        }

        await event.prompt();

        const choice = await event.userChoice;

        /*
         * The event is spent either way — Chrome will not hand the same one
         * over twice — so it is cleared regardless of the answer. A decline
         * here is *not* stored as a dismissal: they answered the browser's
         * dialog, not ours, and Chrome will offer the event again on a later
         * visit if it still thinks the site qualifies.
         */
        deferred.value = null;

        if (choice.outcome === 'accepted') {
            installed.value = true;
        }
    }

    function dismiss(): void {
        dismissed.value = true;

        try {
            window.localStorage.setItem(DISMISSED_KEY, '1');
        } catch {
            // Nothing to do. They will be asked again next time, which is the
            // failure this feature can afford.
        }
    }

    return {
        /** Android and desktop Chrome: a real button. */
        canPrompt: computed(() => deferred.value !== null),
        /** iOS: instructions, because there is no API to offer. */
        needsInstructions: computed(() => isIos() && !installed.value),
        installed,
        dismissed,
        install,
        dismiss,
    };
}
