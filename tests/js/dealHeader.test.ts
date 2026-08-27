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
const permissions = { value: ['workflow.advance', 'deals.manage'] as string[] };

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
    counts: { people: 3, properties: 2, tasks: 5, offers: 0, documents: 0 },
    hasOffers: true,
    advance: { workflowId: 'wf-1', stageId: 'stage-1' },
};

function header(
    overrides: Partial<DealHeaderProps> = {},
    active: string | null = null,
) {
    // What a Team Member holds. Both actions in §8.4's row are permission-
    // gated, so a default of one of them would leave the other untested.
    permissions.value = ['workflow.advance', 'deals.manage'];

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
            'Offers',
            'Documents',
        ]);

        // Built: an anchor. Not built: an inert button, visible so the shape
        // of a deal is honest and disabled so nothing offers a route that
        // 404s.
        expect(tabs[0].element.tagName).toBe('A');
        expect(tabs[0].attributes('href')).toBe('/deals/deal-1');
        // Timeline since S16 (#76), Tasks since S17 (#71).
        expect(tabs[1].element.tagName).toBe('A');
        expect(tabs[1].attributes('href')).toBe('/deals/deal-1/timeline');
        expect(tabs[2].element.tagName).toBe('A');
        expect(tabs[2].attributes('href')).toBe('/deals/deal-1/tasks');
        // Dates is the inert example now.
        expect(tabs[3].element.tagName).toBe('BUTTON');
        expect(tabs[3].attributes('disabled')).toBeDefined();
        expect(tabs[4].attributes('href')).toBe('/deals/deal-1/people');
        // Offers since S22 (#73).
        expect(tabs[6].element.tagName).toBe('A');
        expect(tabs[6].attributes('href')).toBe('/deals/deal-1/offers');
    });

    it('hides Offers only when the deal type has none and none were recorded', () => {
        /*
         * IA §5.2: *"hidden when empty and the deal type has no offers."*
         * **Two** conditions, and dropping either one is the mistake worth a
         * test — a rental placement must not grow an empty Offers tab, and a
         * deal that somehow holds one must not hide the tab that shows it.
         */
        const labels = (overrides: Partial<DealHeaderProps>) =>
            header(overrides)
                .findAll('[data-slot="tab"]')
                .map((tab) => tab.text().replace(/\d+$/, ''));

        expect(
            labels({ hasOffers: false, counts: { ...deal.counts, offers: 0 } }),
        ).not.toContain('Offers');

        // Recorded on a deal type that does not expect them: still shown,
        // because hiding it would hide the offers themselves.
        expect(
            labels({ hasOffers: false, counts: { ...deal.counts, offers: 2 } }),
        ).toContain('Offers');
    });

    it('shows a count only on the tabs that are lists of something', () => {
        const tabs = header().findAll('[data-slot="tab"]');

        expect(tabs[0].text()).toBe('Overview');
        expect(tabs[4].text()).toBe('People3');
        expect(tabs[5].text()).toBe('Properties2');
        /*
         * Tasks counts what is still **open**, not what the deal holds — a
         * seeded pack puts eighty tasks on a deal, and `80` on a finished
         * checklist says the opposite of what happened. The server decides
         * that; what this pins is that the tab renders a count at all.
         */
        expect(tabs[2].text()).toBe('Tasks5');
        // A tab whose slice has not landed has no count to show either — a
        // zero there would read as a fact about this deal.
        expect(tabs[3].text()).toBe('Dates');
        // Built and still countless: Timeline is not a list of anything, so it
        // stays bare now that it is linked rather than gaining a number.
        expect(tabs[1].text()).toBe('Timeline');
    });

    it('marks the active tab and no other', () => {
        const tabs = header({}, 'people').findAll('[data-slot="tab"]');

        expect(tabs[4].attributes('aria-current')).toBe('page');
        expect(tabs[0].attributes('aria-current')).toBeUndefined();
    });

    it('offers Add task, which lands on the tab that owns the form', () => {
        /*
         * Design System §8.4 puts `Add Task` in the chrome every deal tab
         * wears, and recorded it as *"not built"* while tasks were S17. It is
         * a link rather than a dialog opened in place: the form needs this
         * deal's stages and this team's assignees, which is a payload the
         * other seven tabs have no use for.
         */
        const add = header()
            .findAll('a')
            .find((link) => link.text() === 'Add task');

        expect(add?.attributes('href')).toBe('/deals/deal-1/tasks?new');
    });

    it('hides Add task from somebody who may not change a deal', () => {
        permissions.value = ['deals.view'];

        const wrapper = mount(DealHeader, { props: { deal, active: null } });

        // §7.3: hidden, never disabled — for the same reason Advance is.
        expect(wrapper.text()).not.toContain('Add task');
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

        // By name rather than by position: `Add task` is a button too, and it
        // sits first in §8.4's action row.
        const advance = wrapper
            .findAll('[data-slot="app-button"]')
            .find((button) => button.text() === 'Advance stage');

        await advance!.trigger('click');

        expect(wrapper.emitted('advance')).toEqual([['wf-1', 'stage-1']]);
    });

    it('drops the location pair when there is no subject property', () => {
        expect(header({ location: null }).text()).not.toContain('Indianapolis');
    });
});
