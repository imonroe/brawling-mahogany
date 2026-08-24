/**
 * A colleague is not a client (#162).
 *
 * The bug as reported: invite an assistant, and the People directory draws
 * them with a green **Client** badge, while the edit form offers to make them
 * a Lead or a Past Client. The segment was never wrong — `notCarryingAccess()`
 * kept them out of Clients — it was the badge, because
 * `PersonLifecycleState::Active`'s label is literally *Client* and every row
 * rendered it unconditionally.
 *
 * Mounted rather than asserted on the server: the payload half is pinned by
 * `PeopleDirectoryTest`, and what these two screens do with `carriesAccess` is
 * a decision taken after it arrives.
 */
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import type { PersonDetail, PersonRow } from '@/types';

const patch = vi.fn();
const form: Record<string, unknown> = {};

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: () => () => null }),
    router: { get: vi.fn(), delete: vi.fn(), on: vi.fn() },
    Link: defineComponent({
        props: { href: { type: String, default: '' } },
        setup:
            (props, { slots }) =>
            () =>
                h('a', { href: props.href }, slots.default?.()),
    }),
    usePage: () => ({
        props: { auth: { permissions: ['people.view', 'people.manage'] } },
    }),
    useForm: (initial: Record<string, unknown>) => {
        Object.assign(form, initial, {
            patch,
            post: vi.fn(),
            transform: (fn: (data: Record<string, unknown>) => unknown) => {
                form.__transformed = fn({ ...initial });

                return form;
            },
            processing: false,
            errors: {},
            reset: () => {},
            clearErrors: () => {},
        });

        return form;
    },
}));

const Index = (await import('@/pages/People/Index.vue')).default;
const PersonFormDialog = (await import('@/components/app/PersonFormDialog.vue'))
    .default;

function person(overrides: Partial<PersonRow> = {}): PersonRow {
    return {
        id: 'membership-1',
        firstName: 'Demo',
        lastName: 'Assistant',
        email: 'assistant@example.test',
        phone: null,
        status: 'active',
        carriesAccess: false,
        roles: [],
        isVendor: false,
        hasLogin: false,
        isRevoked: false,
        ...overrides,
    };
}

function directory(rows: PersonRow[]) {
    return mount(Index, {
        props: {
            segment: 'all',
            segmentCounts: [
                { value: 'all', label: 'All', count: rows.length },
                { value: 'team', label: 'Team', count: 1 },
            ],
            emptyMessage: 'Nobody here yet.',
            search: '',
            people: {
                data: rows,
                current_page: 1,
                last_page: 1,
                per_page: 25,
                total: rows.length,
                prev_page_url: null,
                next_page_url: null,
            },
            lifecycleStates: {
                lead: 'Lead',
                active: 'Client',
                past_client: 'Past Client',
                archived: 'Archived',
            },
        },
        global: { stubs: { PersonFormDialog: true } },
    });
}

function detail(overrides: Partial<PersonDetail> = {}): PersonDetail {
    return {
        ...person(),
        notes: null,
        vendor: {
            specialties: [],
            typicalCost: null,
            serviceArea: null,
            rating: null,
            notes: null,
        },
        joinedAt: null,
        revokedAt: null,
        ...overrides,
    };
}

function dialog(membership: PersonDetail | null) {
    return mount(PersonFormDialog, {
        props: {
            open: true,
            lifecycleStates: {
                lead: 'Lead',
                active: 'Client',
                past_client: 'Past Client',
                archived: 'Archived',
            },
            membership,
        },
        global: {
            stubs: {
                Dialog: { template: '<div><slot /></div>' },
                DialogContent: { template: '<div><slot /></div>' },
                DialogHeader: { template: '<div><slot /></div>' },
                DialogTitle: { template: '<h2><slot /></h2>' },
                DialogDescription: { template: '<p><slot /></p>' },
                DialogFooter: { template: '<div><slot /></div>' },
            },
        },
    });
}

describe('a colleague in the People directory', () => {
    beforeEach(() => {
        patch.mockClear();

        for (const key of Object.keys(form)) {
            delete form[key];
        }
    });

    it('wears their role, not a Client badge', () => {
        const wrapper = directory([
            person({ carriesAccess: true, roles: ['Team Member'] }),
        ]);

        const badges = wrapper
            .findAll('[data-slot="status-badge"]')
            .map((badge) => badge.text());

        expect(badges).toContain('Team Member');
        expect(badges).not.toContain('Client');
    });

    it('leaves a real client wearing Client', () => {
        // The control. Without it this passes by drawing no lifecycle at all.
        const badges = directory([person({ status: 'active' })])
            .findAll('[data-slot="status-badge"]')
            .map((badge) => badge.text());

        expect(badges).toContain('Client');
    });

    it('says Team when the roles list is empty but access is real', () => {
        // A composed role with no name should still not read as a client.
        const badges = directory([person({ carriesAccess: true, roles: [] })])
            .findAll('[data-slot="status-badge"]')
            .map((badge) => badge.text());

        expect(badges).toContain('Team');
        expect(badges).not.toContain('Client');
    });

    it('is not offered a lifecycle in the edit form', () => {
        const colleague = dialog(
            detail({ carriesAccess: true, roles: ['Team Member'] }),
        );

        expect(colleague.find('#status').exists()).toBe(false);
        expect(colleague.text()).toContain('Settings → Members');
        // The four options that made no sense for an assistant.
        expect(colleague.text()).not.toContain('Past Client');
    });

    it('still offers one to a contact', () => {
        const contact = dialog(detail());

        expect(contact.find('#status').exists()).toBe(true);
        expect(contact.findAll('#status option')).toHaveLength(4);
    });

    it('does not send a lifecycle for a colleague, and does for a contact', async () => {
        /*
         * The server refuses `status` outright on a membership carrying access
         * (`PersonRules`), so sending it would fail the whole edit. Hidden in
         * the UI *and* dropped from the payload — one without the other is
         * either a 422 nobody expects or a rule only the client enforces.
         */
        const colleague = dialog(
            detail({ carriesAccess: true, roles: ['Team Member'] }),
        );

        await colleague.find('form').trigger('submit');

        expect(form.__transformed).not.toHaveProperty('status');

        const contact = dialog(detail());

        await contact.find('form').trigger('submit');

        expect(form.__transformed).toHaveProperty('status');
    });
});
