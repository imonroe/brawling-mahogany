/**
 * S13's filters do not drop each other during a round trip (#78).
 *
 * `props` are not "the current filters" — they are the filters of the last
 * response that **landed**, and they stay stale for the whole in-flight round
 * trip. Every control on this screen passes only what it changes and inherits
 * the rest, so for the width of that window the inheritance read the wrong
 * thing and silently undid whatever the reader had just done.
 *
 * These are the sequences, all of them a second control used before the first
 * one's response arrives. They are mounted rather than driven through the
 * server because the whole mechanism is client-side: what matters is the query
 * object handed to `router.get`, which is the last honest place to look before
 * it becomes a URL.
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

function page(overrides: Record<string, unknown> = {}) {
    return mount(Index, {
        props: {
            segment: 'open',
            segmentCounts: [
                { value: 'open', label: 'Open', count: 4 },
                { value: 'all', label: 'All', count: 9 },
            ],
            search: '',
            dealType: 'all',
            dealTypeOptions: [
                { value: 'dt-1', label: 'Sale' },
                { value: 'dt-2', label: 'Purchase' },
            ],
            sort: '',
            direction: 'asc',
            deals: {
                data: [
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
                ],
                total: 1,
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

/** The segmented control's buttons: [Open, All]. */
function segments(wrapper: ReturnType<typeof page>) {
    return wrapper.findAll('[role="tablist"] button');
}

/** The header cell of the first sortable column ("Deal"). */
function sortHeader(wrapper: ReturnType<typeof page>) {
    const button = wrapper.findAll('thead button')[0];

    expect(button, 'No sortable column header rendered.').toBeDefined();

    return button!;
}

/** The query object of the nth `router.get`, with a helpful failure message. */
function queryOf(index: number): Record<string, unknown> {
    expect(
        routerGet.mock.calls.length,
        `Expected at least ${index + 1} visits, got ${routerGet.mock.calls.length}.`,
    ).toBeGreaterThan(index);

    return routerGet.mock.calls[index]![1] as Record<string, unknown>;
}

beforeEach(() => {
    routerGet.mockClear();
});

afterEach(() => {
    vi.useRealTimers();
});

describe('Deals index filter races', () => {
    it('carries the segment into a sort pressed before the response lands', async () => {
        const wrapper = page();

        // Click "All". The visit goes out; the server has not answered, so
        // `props.segment` is still `open`.
        await segments(wrapper)[1]!.trigger('click');
        await sortHeader(wrapper).trigger('click');

        expect(queryOf(0)).toMatchObject({ segment: 'all' });

        /*
         * The sort inherited `props.segment` before `asked` existed, so it
         * went out as `{sort, direction}` with no segment — silently returning
         * the reader to open deals one press after they left them.
         */
        expect(queryOf(1)).toMatchObject({
            segment: 'all',
            sort: 'primary',
            direction: 'asc',
        });
    });

    it('carries the segment into a deal type chosen before the response lands', async () => {
        const wrapper = page();

        await segments(wrapper)[1]!.trigger('click');
        await wrapper.find('select').setValue('dt-1');

        expect(queryOf(1)).toMatchObject({
            segment: 'all',
            dealType: 'dt-1',
        });
    });

    it('carries a deal type into a sort pressed before the response lands', async () => {
        // The other order, so neither control is the one that happens to work.
        const wrapper = page();

        await wrapper.find('select').setValue('dt-2');
        await sortHeader(wrapper).trigger('click');

        expect(queryOf(1)).toMatchObject({
            dealType: 'dt-2',
            sort: 'primary',
        });
    });

    it('toggles direction on a second press inside the same round trip', async () => {
        const wrapper = page();

        await sortHeader(wrapper).trigger('click');
        await sortHeader(wrapper).trigger('click');

        expect(queryOf(0)).toMatchObject({
            sort: 'primary',
            direction: 'asc',
        });

        /*
         * `sortBy()` read `props.sort` — still the *previous* column — so the
         * second press looked like a first press on a new column and restarted
         * at ascending. The arrow refused to flip until the reader waited.
         */
        expect(queryOf(1)).toMatchObject({
            sort: 'primary',
            direction: 'desc',
        });
    });

    it('does not lose a keystroke typed while the previous search is in flight', async () => {
        vi.useFakeTimers();

        const wrapper = page();
        const box = wrapper.find('input[type="search"]');

        await box.setValue('a');
        vi.advanceTimersByTime(300);

        expect(queryOf(0)).toMatchObject({ search: 'a' });

        // Typed during the round trip, then the answer for `a` arrives.
        await box.setValue('ab');
        await wrapper.setProps({ search: 'a' });

        /*
         * The echo used to overwrite the box back to `a` and cancel `ab`'s
         * timer on the way, so the character was lost and no request was ever
         * made for it — a search box silently undoing a keystroke.
         */
        expect((box.element as HTMLInputElement).value).toBe('ab');

        vi.advanceTimersByTime(300);

        expect(queryOf(1)).toMatchObject({ search: 'ab' });
    });

    it('still echoes the server into the box when nothing is pending', async () => {
        // The other side of that guard. A back button or a hand-edited URL
        // arrives as a prop change with no local edit outstanding, and the box
        // has to follow it or it lies about what is being filtered.
        const wrapper = page();

        await wrapper.setProps({ search: 'from the url' });

        expect(
            (wrapper.find('input[type="search"]').element as HTMLInputElement)
                .value,
        ).toBe('from the url');
    });

    it('does not let an older response revive a filter a later visit dropped', async () => {
        vi.useFakeTimers();

        const wrapper = page();
        const box = wrapper.find('input[type="search"]');

        // A search goes out, then a segment click supersedes it.
        await box.setValue('smith');
        vi.advanceTimersByTime(300);
        await segments(wrapper)[1]!.trigger('click');

        /*
         * The search's own response lands now — an answer to the *first*
         * request while the second is still outstanding. Dropping `asked` on
         * any arrival would discard the segment click's record here, so the
         * next control would inherit `open` again and reintroduce the bug one
         * visit later. `asked` is dropped only when the props agree with it.
         */
        await wrapper.setProps({ search: 'smith' });
        await sortHeader(wrapper).trigger('click');

        expect(queryOf(2)).toMatchObject({
            segment: 'all',
            search: 'smith',
            sort: 'primary',
        });
    });

    it('does not let a control pressed after Clear filters put the filters back', async () => {
        /*
         * `clearFilters()` is the second thing on this page that navigates,
         * and it does not go through `visit()` — it deliberately sends a bare
         * query rather than inheriting anything. So it has to record what it
         * asked for itself, and a rule written into one of two callers is the
         * defect this screen keeps producing.
         *
         * Without that record, a sort pressed before the clear's response
         * landed inherited the *pre-clear* props and put the deal type
         * straight back — the button undoing itself by way of the next thing
         * the reader touched.
         */
        const wrapper = page({
            segmentCounts: [
                { value: 'open', label: 'Open', count: 0 },
                { value: 'all', label: 'All', count: 8 },
            ],
            dealType: 'dt-1',
            deals: {
                data: [],
                total: 0,
                per_page: 25,
                current_page: 1,
                last_page: 1,
                prev_page_url: null,
                next_page_url: null,
            },
        });

        const clear = wrapper
            .findAll('button')
            .find((b) => b.text() === 'Clear filters');

        expect(
            clear,
            'No Clear filters button rendered to press.',
        ).toBeDefined();
        await clear!.trigger('click');

        expect(queryOf(0).dealType).toBeUndefined();

        await sortHeader(wrapper).trigger('click');

        expect(queryOf(1)).toMatchObject({ sort: 'primary' });
        expect(queryOf(1).dealType).toBeUndefined();
    });

    it('lets the props take over again once the server has caught up', async () => {
        const wrapper = page();

        await segments(wrapper)[1]!.trigger('click');

        // The server answers exactly what was asked, so `asked` is spent.
        await wrapper.setProps({ segment: 'all' });

        // A back button now moves the props with no visit of ours behind it.
        await wrapper.setProps({ segment: 'open', dealType: 'dt-1' });
        await sortHeader(wrapper).trigger('click');

        const query = queryOf(1);

        expect(query.dealType).toBe('dt-1');

        // `segment: 'open'` is the default and drops out of the query string.
        expect(query.segment).toBeUndefined();
    });
});
