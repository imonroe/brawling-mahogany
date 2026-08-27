/**
 * S56 says *when* the data is from, and only when there is data (#102).
 *
 * Round 5 of review found the banner claiming a save time on pages that had
 * never been saved, and printing it with no day. #102's standard for this
 * screen is that it must not lie, and both were lies of exactly the kind it
 * names.
 */
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';

/** What the worker was asked, so a test can assert the question. */
let asked: Record<string, unknown> | null = null;

/** What the worker answers, per test. */
let fetchedAt: number | null = null;

const postMessage = vi.fn(
    (message: Record<string, unknown>, ports: MessagePort[]) => {
        asked = message;

        /*
         * **Post back down the port**, rather than invoking `onmessage` on
         * it. The composable listens on `port1` and transfers `port2`, so
         * `ports[0]` is the *far* end — calling its `onmessage` runs nothing,
         * which is how the first version of this file had two tests passing
         * with no reply ever delivered. jsdom implements `MessageChannel`
         * properly, so posting is both simpler and real.
         */
        ports[0].postMessage({ fetchedAt });
    },
);

vi.stubGlobal('navigator', {
    onLine: false,
    serviceWorker: { controller: { postMessage } },
});

const OfflineNotice = (await import('@/components/app/OfflineNotice.vue'))
    .default;

beforeEach(() => {
    postMessage.mockClear();
    asked = null;
    fetchedAt = null;
    window.history.replaceState({}, '', '/deals/01ABC');
});

async function banner() {
    const wrapper = mount(OfflineNotice, {
        global: { stubs: { Transition: false } },
    });

    /*
     * The composable answers over a `MessageChannel` and awaits it, so the
     * ref lands a microtask later than the mount. Flushed explicitly rather
     * than by stacking `nextTick()`s, which is guesswork that passes until it
     * does not.
     */
    await new Promise((resolve) => setTimeout(resolve, 0));
    await nextTick();

    return wrapper;
}

describe('what the banner claims', () => {
    it('asks the worker about the page being read', async () => {
        /*
         * The whole finding. Without a URL the worker could only answer with
         * the newest stamp anywhere in the cache — a save time belonging to a
         * different page.
         */
        await banner();

        expect(asked).toMatchObject({ type: 'CACHE_STATUS' });
        expect(String((asked as { url: string }).url)).toContain(
            '/deals/01ABC',
        );
    });

    it('says nothing was saved when this page was not', async () => {
        /*
         * Only `/dashboard` and `/work` are ever cached, so this is the
         * ordinary case on every other screen — and it was unreachable once
         * anything at all was in the cache.
         */
        fetchedAt = null;

        const wrapper = await banner();

        expect(wrapper.text()).toContain('not saved for offline use');
        expect(wrapper.text()).not.toContain('This is what was saved');
    });

    it('names the day, not only the time', async () => {
        /*
         * `formatTime` alone renders a three-day-old copy as "saved at
         * 3:09am", which reads as this morning — the exact "today or Tuesday"
         * distinction the banner exists to draw.
         */
        const threeDaysAgo = new Date();
        threeDaysAgo.setDate(threeDaysAgo.getDate() - 3);
        fetchedAt = threeDaysAgo.getTime();

        const wrapper = await banner();
        const text = wrapper.text();

        expect(text).toContain('This is what was saved');
        /*
         * A weekday and a date, then the time — "saved Mon, Aug 24 at
         * 8:15am". The assertion that matters is the negative one below: a
         * bare `saved at …` is the reading that makes a three-day-old copy
         * look like this morning.
         */
        expect(text).toMatch(
            /saved [A-Z][a-z]{2}, [A-Z][a-z]{2} \d{1,2} at \d/,
        );
        expect(text).not.toMatch(/saved at \d/);
    });

    it('always promises that nothing will be saved while offline', async () => {
        /*
         * The promise the code actually keeps: nothing is queued for replay,
         * because a task completion replayed later can clear a gate and send
         * a client an email that cannot be recalled.
         */
        const wrapper = await banner();

        expect(wrapper.text()).toContain('nothing will be saved');
    });
});
