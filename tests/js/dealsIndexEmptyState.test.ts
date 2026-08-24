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
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: () => () => null }),
    router: { get: vi.fn(), on: vi.fn() },
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
