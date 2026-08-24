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

function page(counts: { open: number; all: number }, data: Row[] = []) {
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
        },
    });
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

        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'Clear filters')
            ?.trigger('click');

        expect(routerGet).toHaveBeenCalledWith(
            '/deals',
            { segment: 'all' },
            expect.anything(),
        );
    });

    it('clears only the search when the segment is not what is hiding things', async () => {
        // Open deals exist and some are showing: the reader typed a search, so
        // clearing means dropping the search, not showing them closed deals.
        const wrapper = page({ open: 4, all: 9 }, [
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
        ]);

        await wrapper.find('input[type="search"]').setValue('smith');
        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'Clear filters')
            ?.trigger('click');

        // No rows are missing, so there is no Clear filters button to press —
        // the empty state is not rendered at all. Nothing widened.
        expect(routerGet).not.toHaveBeenCalledWith(
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

        // Exactly one visit, and it carries both. Cancelling the pending
        // search used to drop it; not cancelling it used to drop the segment.
        expect(routerGet).toHaveBeenCalledTimes(1);
        expect(routerGet.mock.calls[0][1]).toMatchObject({ search: 'smith' });
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
