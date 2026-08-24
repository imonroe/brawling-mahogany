/**
 * §8.8's sortable column headers, which shipped invisible (#78).
 *
 * `dealRow.ts` has marked Deal and Next date `sortable` since #33 and nothing
 * rendered the chevron, so S13 implemented it — and the first implementation
 * decided sortability from `useAttrs().onSort`, which is always absent once
 * `defineEmits` declares the event. Every header fell back to plain text, the
 * feature was invisible, and the whole suite stayed green: the backend sort
 * tests hit the URL directly and never rendered a header.
 *
 * That is the argument for mounting this one rather than reading the source.
 * The defect was not in the query or in the markup a page writes — it was in
 * what the component *does* with the props it is given.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { dealRowColumns } from '@/components/app/dealRow';
import Table from '@/components/app/Table.vue';

const columns = dealRowColumns({});

function headers(
    sortable: boolean,
    sort: string | null = null,
    direction: 'asc' | 'desc' = 'asc',
) {
    return mount(Table, {
        props: { columns, sortable, sort, direction },
        attrs: { onSort: vi.fn() },
    });
}

describe('Table sorting', () => {
    it('offers a button on the sortable columns when the screen handles sorting', () => {
        const buttons = headers(true).findAll('thead button');

        // `dealRow.ts` marks exactly two: the deal and the next date.
        expect(buttons).toHaveLength(2);
        expect(buttons.map((button) => button.text())).toEqual([
            'Deal',
            'Next date',
        ]);
    });

    it('renders plain headers on a screen that does not sort', () => {
        // A header that is a button where nothing listens is an affordance
        // leading nowhere — the dashboard renders this same row read-only.
        expect(headers(false).findAll('thead button')).toHaveLength(0);
    });

    it('emits the column key when a header is pressed', async () => {
        const wrapper = headers(true);

        await wrapper.findAll('thead button')[0].trigger('click');

        expect(wrapper.emitted('sort')).toEqual([['primary']]);
    });

    it('points the chevron up for ascending, the way every table does', () => {
        const ascending = headers(true, 'primary', 'asc');
        const descending = headers(true, 'primary', 'desc');

        // The component name is the only thing distinguishing them once
        // rendered, and it is what a reader reads as direction.
        expect(ascending.findAll('thead button svg')[0].html()).not.toBe(
            descending.findAll('thead button svg')[0].html(),
        );
    });

    it('tells a screen reader which column is sorted and which way', () => {
        const sorted = headers(true, 'primary', 'desc');
        const cells = sorted.findAll('thead th');

        const primary =
            cells[columns.findIndex((column) => column.key === 'primary')];
        const date =
            cells[columns.findIndex((column) => column.key === 'date')];
        const state =
            cells[columns.findIndex((column) => column.key === 'state')];

        expect(primary.attributes('aria-sort')).toBe('descending');
        // Sortable but not the one sorted.
        expect(date.attributes('aria-sort')).toBe('none');
        // Not sortable at all, so it makes no claim either way.
        expect(state.attributes('aria-sort')).toBeUndefined();
    });
});
