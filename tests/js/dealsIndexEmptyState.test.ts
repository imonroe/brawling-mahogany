/**
 * S13's empty state says which kind of empty it is (#78).
 *
 * The default segment is `open`, so a team whose deals are all closed lands on
 * an empty list — and the first version told them "No deals yet. Create your
 * first deal.", which is what a screen says to somebody with none. The `all`
 * count is on the same page and knows better.
 *
 * Mounted rather than asserted on the server, because the whole decision lives
 * in a computed. The feature test written for this first round asserted
 * `deals.data` was empty and the `all` count was 1 — both of which were
 * already true *before* the fix, since `segmentCounts()` never changed. It
 * could not fail.
 */
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

const routerGet = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: () => () => null }),
    router: { get: routerGet, on: vi.fn() },
    Link: defineComponent({
        props: { href: { type: String, default: '' } },
        setup:
            (props, { slots }) =>
            () =>
                h('a', { href: props.href }, slots.default?.()),
    }),
    usePage: () => ({ props: { auth: { permissions: ['deals.view'] } } }),
}));

const Index = (await import('@/pages/Deals/Index.vue')).default;

type Row = {
    id: string;
    name: string;
    url: string;
    client: string | null;
    stage: string | null;
    state: string;
    dealTypeName: string | null;
    nextDate: string | null;
};

function page(
    counts: { open: number; all: number },
    data: Row[] = [],
    overrides: Record<string, unknown> = {},
) {
    return mount(Index, {
        props: {
            segment: 'open',
            segmentCounts: [
                { value: 'open', label: 'Open', count: counts.open },
                { value: 'all', label: 'All', count: counts.all },
            ],
            search: '',
            dealType: 'all',
            dealTypeOptions: [],
            sort: '',
            direction: 'asc',
            deals: {
                data,
                total: data.length,
                per_page: 25,
                current_page: 1,
                last_page: 1,
                prev_page_url: null,
                next_page_url: null,
            },
            ...overrides,
        },
    });
}

/**
 * The Clear filters button, or a failure saying it was not there.
 *
 * It renders only inside `<template #empty>`, under `v-if` on both an empty
 * page and `isFiltered` — so a fixture with a row has no button, and
 * `find(…)?.trigger('click')` on it is a no-op that any negative assertion
 * survives. That is exactly how the narrowing test below passed while
 * pressing nothing.
 */
function pressClear(wrapper: ReturnType<typeof page>) {
    const button = wrapper
        .findAll('button')
        .find((b) => b.text() === 'Clear filters');

    expect(button, 'No Clear filters button rendered to press.').toBeDefined();

    return button!.trigger('click');
}

beforeEach(() => {
    routerGet.mockClear();
});

describe('Deals index empty state', () => {
    it('offers to create the first deal when the team really has none', () => {
        // IA §10's exact sentence for this case.
        expect(page({ open: 0, all: 0 }).text()).toContain('No deals yet');
    });

    it('does not claim a team with closed deals has none', () => {
        const text = page({ open: 0, all: 8 }).text();

        expect(text).not.toContain('No deals yet');
        expect(text).toContain('No deals match those filters');
    });

    it('shows no empty state at all when there are rows', () => {
        const text = page({ open: 1, all: 1 }, [
            {
                id: 'd1',
                name: '14 Elm St',
                url: '/deals/d1',
                client: null,
                stage: null,
                state: 'active',
                dealTypeName: null,
                nextDate: null,
            },
        ]).text();

        expect(text).not.toContain('No deals yet');
        expect(text).not.toContain('No deals match those filters');
    });
});

/**
 * The three fixes round 2 made to this page, each of which the whole suite was
 * green without.
 *
 * They are here rather than in a feature test because every one of them is a
 * decision the page makes before the server is asked — which control fires,
 * with what, and whether it fires at all.
 */
describe('Deals index filtering', () => {
    afterEach(() => {
        vi.useRealTimers();
    });

    it('clears to every deal, which is what the sentence beside it promises', async () => {
        // A closed-only team: `/deals` with no query string *is* `segment=open`,
        // so clearing to it reloaded the identical empty screen.
        const wrapper = page({ open: 0, all: 8 });

        await pressClear(wrapper);

        expect(routerGet).toHaveBeenCalledWith(
            '/deals',
            { segment: 'all' },
            expect.anything(),
        );
    });

    it('clears only the search when the segment is not what is hiding things', async () => {
        /*
         * Four open deals, and a search matching none of them. The reader
         * wants their search dropped — not five closed deals they never asked
         * to see. So this clears to `/deals` bare, which *is* `segment=open`.
         *
         * The fixture is empty **and** searched, which is the only shape that
         * reaches the narrowing at all: the button lives inside the empty
         * state, so a version of this test that supplied a row rendered no
         * button, pressed nothing, and passed on a negative assertion that
         * was true by construction. `search` goes in as a prop because that
         * is what the branch reads — the server's resolved value, not the box.
         */
        const wrapper = page({ open: 4, all: 9 }, [], { search: 'smith' });

        await pressClear(wrapper);

        // Positive, not negative: the press happened and carried nothing.
        expect(routerGet).toHaveBeenCalledWith('/deals', {}, expect.anything());
    });

    it('clears only the deal type when that is what is hiding things', async () => {
        // The other half of the same branch, and the one a `&&` slipped to a
        // `||` would take. Same team, same segment, filtered by type instead.
        const wrapper = page({ open: 4, all: 9 }, [], { dealType: 'dt-1' });

        await pressClear(wrapper);

        expect(routerGet).toHaveBeenCalledWith('/deals', {}, expect.anything());
    });

    it('cancels a pending search rather than letting it undo the clear', async () => {
        vi.useFakeTimers();

        // Typed but not yet fired: the debounce is 250ms and 100 have passed.
        const wrapper = page({ open: 0, all: 8 });

        await wrapper.find('input[type="search"]').setValue('smith');
        vi.advanceTimersByTime(100);

        await pressClear(wrapper);
        vi.advanceTimersByTime(500);

        // One visit, and it is the clear. `visit()` cancels the debounce and
        // says why; this is the second thing on the page that builds a query
        // string, and a rule written into one of two callers is the defect
        // this screen keeps producing. Without the cancel, the pending search
        // fired 150ms later and put `smith` straight back.
        expect(routerGet).toHaveBeenCalledTimes(1);
        expect(routerGet).toHaveBeenCalledWith(
            '/deals',
            { segment: 'all' },
            expect.anything(),
        );
    });

    it('clears to nothing at all when the team really has no deals', async () => {
        const wrapper = page({ open: 0, all: 0 });

        // No filtered branch here, so no Clear filters button — the action is
        // the create one. Nothing to assert but that we did not offer it.
        expect(
            wrapper.findAll('button').some((b) => b.text() === 'Clear filters'),
        ).toBe(false);
    });

    it('carries a typed search through a filter pressed before the debounce fires', async () => {
        vi.useFakeTimers();

        const wrapper = page({ open: 2, all: 2 });

        await wrapper.find('input[type="search"]').setValue('smith');

        // The reader clicks a segment before the 250ms is up.
        vi.advanceTimersByTime(100);
        await wrapper.findAll('[role="tablist"] button')[1]?.trigger('click');

        vi.advanceTimersByTime(500);

        // Exactly one visit, and it carries **both**. Cancelling the pending
        // search used to drop it; not cancelling it used to drop the segment.
        // Asserting only the search would pass on a visit that lost the click
        // that triggered it, which is half the bug.
        expect(routerGet).toHaveBeenCalledTimes(1);
        expect(routerGet.mock.calls[0][1]).toMatchObject({
            search: 'smith',
            segment: 'all',
        });
    });

    it('cancels a search the reader emptied by hand before pressing Clear', async () => {
        vi.useFakeTimers();

        const wrapper = page({ open: 0, all: 8 });
        const box = wrapper.find('input[type="search"]');

        // Typed, then deleted back to empty. That deletion is a real keystroke
        // and arms the debounce like any other.
        await box.setValue('smith');
        await box.setValue('');
        vi.advanceTimersByTime(100);

        await pressClear(wrapper);
        vi.advanceTimersByTime(500);

        /*
         * This is the path that holds `clearFilters()`' own `clearTimeout`.
         *
         * The sibling test above reaches the clear with text still in the box,
         * so `setSearch('')` changes the ref, the watcher runs, and the
         * watcher's own first line clears the timer — the line in
         * `clearFilters()` is doing nothing there, and deleting it left all
         * eleven of these green. Here the ref is **already** `''`, so
         * `setSearch()` is a no-op by design, no watcher fires, and the timer
         * the reader armed survives unless `clearFilters()` cancels it itself.
         * Without that line: two visits, the second to bare `/deals`, which
         * *is* `segment=open` — the clear undoing itself again.
         */
        expect(routerGet).toHaveBeenCalledTimes(1);
        expect(routerGet).toHaveBeenCalledWith(
            '/deals',
            { segment: 'all' },
            expect.anything(),
        );
    });

    it('does not swallow the keystroke after the server echoes the search back', async () => {
        vi.useFakeTimers();

        const wrapper = page({ open: 2, all: 2 });

        await wrapper.find('input[type="search"]').setValue('smith');
        vi.advanceTimersByTime(300);

        expect(routerGet).toHaveBeenCalledTimes(1);

        /*
         * The server answers, resolving the search to the same string the box
         * already holds. `setSearch()` has to notice: arming the flag for an
         * assignment that changes nothing fires no watcher to disarm it, so
         * the flag stays up and eats the reader's **next** keystroke instead
         * — a search box that goes dead one round trip in.
         */
        await wrapper.setProps({ search: 'smith' });

        await wrapper.find('input[type="search"]').setValue('smithy');
        vi.advanceTimersByTime(300);

        expect(routerGet).toHaveBeenCalledTimes(2);
        expect(routerGet.mock.calls[1][1]).toMatchObject({ search: 'smithy' });
    });

    it('does not navigate after the page has gone', async () => {
        vi.useFakeTimers();

        const wrapper = page({ open: 2, all: 2 });

        // Awaited, so the watcher has actually scheduled the timer. Without
        // this the test passes on a page that never had one pending.
        await wrapper.find('input[type="search"]').setValue('smith');
        wrapper.unmount();

        vi.advanceTimersByTime(500);

        // Typing and clicking straight through to a deal used to yank the
        // reader back to /deals a quarter of a second later.
        expect(routerGet).not.toHaveBeenCalled();
    });
});
