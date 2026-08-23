import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import type { DealHeaderProps } from '@/components/app/DealHeader.vue';

/**
 * Design System §8.4, as assertions (#75).
 *
 * The header is shared by all eight deal tabs, and three of its rules are the
 * kind that get quietly broken by the next tab that lands: which tabs exist,
 * which of them are inert because their slice has not shipped, and when the
 * single primary **Advance Stage** button is allowed to appear.
 *
 * Inertia is mocked rather than installed. `usePage` is what
 * `usePermissions()` reads, and `Link` is what `Tab` renders when it has an
 * `href`; neither needs a real router to answer what this file asks.
 */
const permissions = { value: ['workflow.advance'] as string[] };

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { auth: { permissions: permissions.value } } }),
    Link: defineComponent({
        props: { href: { type: String, default: '' } },
        setup:
            (props, { slots }) =>
            () =>
                h('a', { href: props.href }, slots.default?.()),
    }),
}));

const DealHeader = (await import('@/components/app/DealHeader.vue')).default;

const deal: DealHeaderProps = {
    id: 'deal-1',
    name: '123 Main St',
    state: 'active',
    dealTypeName: 'Listing',
    sideLabel: 'Sell',
    clientName: 'Emily Bosart',
    location: { city: 'Indianapolis', state: 'IN' },
    counts: { people: 3, properties: 2 },
    advance: { workflowId: 'wf-1', stageId: 'stage-1' },
};

function header(
    overrides: Partial<DealHeaderProps> = {},
    active: string | null = null,
) {
    permissions.value = ['workflow.advance'];

    return mount(DealHeader, {
        props: { deal: { ...deal, ...overrides }, active },
    });
}

describe('DealHeader', () => {
    it('carries the deal name, its state badge and the meta row', () => {
        const wrapper = header();

        expect(wrapper.find('[data-slot="deal-name"]').text()).toBe(
            '123 Main St',
        );
        expect(wrapper.find('[data-slot="status-badge"]').text()).toBe(
            'Active',
        );
        // City and state, never the street again and never the postcode —
        // the deal is already named after the street (IA §10).
        expect(wrapper.text()).toContain('Indianapolis, IN');
        expect(wrapper.text()).toContain('Emily Bosart');
        expect(wrapper.text()).toContain('Listing');
    });

    it('renders IA §5.2’s tabs in order, and links only the ones that exist', () => {
        const tabs = header().findAll('[data-slot="tab"]');

        expect(tabs.map((tab) => tab.text().replace(/\d+$/, ''))).toEqual([
            'Overview',
            'Timeline',
            'Tasks',
            'Dates',
            'People',
            'Properties',
            'Documents',
        ]);

        // Built: an anchor. Not built: an inert button, visible so the shape
        // of a deal is honest and disabled so nothing offers a route that
        // 404s.
        expect(tabs[0].element.tagName).toBe('A');
        expect(tabs[0].attributes('href')).toBe('/deals/deal-1');
        expect(tabs[1].element.tagName).toBe('BUTTON');
        expect(tabs[1].attributes('disabled')).toBeDefined();
        expect(tabs[4].attributes('href')).toBe('/deals/deal-1/people');
    });

    it('shows a count only on the tabs that are lists of something', () => {
        const tabs = header().findAll('[data-slot="tab"]');

        expect(tabs[0].text()).toBe('Overview');
        expect(tabs[4].text()).toBe('People3');
        expect(tabs[5].text()).toBe('Properties2');
        // A tab whose slice has not landed has no count to show either — a
        // zero there would read as a fact about this deal.
        expect(tabs[2].text()).toBe('Tasks');
    });

    it('marks the active tab and no other', () => {
        const tabs = header({}, 'people').findAll('[data-slot="tab"]');

        expect(tabs[4].attributes('aria-current')).toBe('page');
        expect(tabs[0].attributes('aria-current')).toBeUndefined();
    });

    it('offers Advance only when the server named one workflow to advance', () => {
        expect(header().text()).toContain('Advance stage');

        // Two workflows running, or none: the server sends null and the header
        // has no primary action rather than one that silently picks.
        expect(header({ advance: null }).text()).not.toContain('Advance stage');
    });

    it('hides Advance from somebody who may not advance', () => {
        permissions.value = ['deals.view'];

        const wrapper = mount(DealHeader, { props: { deal, active: null } });

        // §7.3: hidden, never disabled. A disabled control still advertises a
        // capability and still invites a support question.
        expect(wrapper.text()).not.toContain('Advance stage');
    });

    it('emits the workflow and the stage the reader was looking at', async () => {
        const wrapper = header();

        await wrapper.find('[data-slot="app-button"]').trigger('click');

        expect(wrapper.emitted('advance')).toEqual([['wf-1', 'stage-1']]);
    });

    it('drops the location pair when there is no subject property', () => {
        expect(header({ location: null }).text()).not.toContain('Indianapolis');
    });
});
