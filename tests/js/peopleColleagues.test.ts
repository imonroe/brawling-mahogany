/**
 * A colleague is not a client (#162).
 *
 * The bug as reported: invite an assistant, and the People directory draws
 * them with a green **Client** badge, while the edit form offers to make them
 * a Lead or a Past Client. It was the badge: `PersonLifecycleState::Active`'s
 * label is literally *Client*, and every row rendered the lifecycle
 * unconditionally.
 *
 * The first fix drew the roles instead **when the person carried access**, and
 * review found that it had only moved the bug: revoke the access and the row
 * fell through to the same unchosen `active`. So `status` is nullable now, and
 * these tests are mostly about what a screen does with a null.
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
/** What the signed-in person can do, per test. */
let permissions: string[] = ['people.view', 'people.manage'];
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
    usePage: () => ({ props: { auth: { permissions } } }),
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
const Show = (await import('@/pages/People/Show.vue')).default;
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
        isColleague: false,
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

function record(membership: PersonDetail) {
    return mount(Show, {
        props: {
            membership,
            activity: [],
            deals: [],
            lifecycleStates: {
                lead: 'Lead',
                active: 'Client',
                past_client: 'Past Client',
                archived: 'Archived',
            },
        },
        global: { stubs: { PersonFormDialog: true, LogContactDialog: true } },
    });
}

describe('a colleague in the People directory', () => {
    beforeEach(() => {
        permissions = ['people.view', 'people.manage'];
        patch.mockClear();

        for (const key of Object.keys(form)) {
            delete form[key];
        }
    });

    it('wears their role, not a Client badge', () => {
        const wrapper = directory([
            person({
                status: null,
                carriesAccess: true,
                isColleague: true,
                roles: ['Team Member'],
            }),
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

    it('says a revoked colleague is revoked, rather than still on the team', () => {
        /*
         * Found by review on #162: `carriesAccess()` says nothing about
         * revocation on purpose, so somebody whose access had ended was badged
         * **Team Member** with nothing saying otherwise. They keep their roles
         * until somebody tidies up, so the roles alone read as though they
         * still work here.
         */
        const badges = directory([
            person({
                status: null,
                carriesAccess: true,
                isColleague: false,
                isRevoked: true,
                roles: ['Team Member'],
            }),
        ])
            .findAll('[data-slot="status-badge"]')
            .map((badge) => badge.text());

        expect(badges).toContain('Team Member');
        expect(badges).toContain('Revoked');
    });

    it('calls a revoked colleague nothing at all until the team says', () => {
        /*
         * The bug the *first* fix introduced, and the reason `status` is
         * nullable rather than the screen deducing whether it applies.
         *
         * Round 1 sent a revoked colleague down the `v-else` to the lifecycle
         * badge, and the value a colleague's membership held was `active` —
         * label **Client**, tone success. Somebody who had never been a client
         * of the team was drawn in green as one, which is #162 verbatim on a
         * different row. Round 1's own test missed it by seeding `past_client`,
         * the one value nothing in the product writes onto a colleague.
         */
        const badges = directory([
            person({
                status: null,
                carriesAccess: true,
                isColleague: false,
                isRevoked: true,
                roles: ['Team Member'],
            }),
        ])
            .findAll('[data-slot="status-badge"]')
            .map((badge) => badge.text());

        expect(badges).not.toContain('Client');
        expect(badges).not.toContain('Lead');
        expect(badges).not.toContain('Past Client');
        expect(badges).not.toContain('Archived');
    });

    it('draws the lifecycle a team records for a former colleague', () => {
        // The other half: once somebody says what they are now, the row says
        // it — beside the roles, not instead of them.
        const badges = directory([
            person({
                status: 'past_client',
                carriesAccess: true,
                isColleague: false,
                isRevoked: true,
                roles: ['Team Member'],
            }),
        ])
            .findAll('[data-slot="status-badge"]')
            .map((badge) => badge.text());

        expect(badges).toContain('Past Client');
        expect(badges).toContain('Team Member');
        expect(badges).toContain('Revoked');
    });

    it('badges each role separately, as the members screen does', () => {
        /*
         * Three screens describe a colleague, and review on #162 caught this
         * one inventing a fourth vocabulary: one `info` pill with the roles
         * joined by a middot, where `/settings/members` and the console draw
         * one neutral badge per role. `lead` is also `info` (§8), so on the
         * All tab a Lead and a colleague were the same blue.
         */
        const badges = directory([
            person({
                status: null,
                carriesAccess: true,
                isColleague: true,
                roles: ['Team Owner', 'Waives Gates'],
            }),
        ])
            .findAll('[data-slot="status-badge"]')
            .map((badge) => badge.text());

        expect(badges).toContain('Team Owner');
        expect(badges).toContain('Waives Gates');
        expect(badges).not.toContain('Team Owner · Waives Gates');
    });

    it('is not offered a lifecycle in the edit form', () => {
        const colleague = dialog(
            detail({
                status: null,
                carriesAccess: true,
                isColleague: true,
                roles: ['Team Member'],
            }),
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

    it('offers a former colleague the lifecycle, and a way to leave it unset', () => {
        /*
         * Revocation ends being a colleague, so the team may record what this
         * person is to them now — and must not be made to. Somebody editing a
         * phone number should not be forced to classify anybody, which is why
         * the rule is `nullable` here and `required` for a contact, and why
         * this select carries a fifth option the contact's does not.
         */
        const former = dialog(
            detail({
                status: null,
                carriesAccess: true,
                isColleague: false,
                isRevoked: true,
                roles: ['Team Member'],
            }),
        );

        expect(former.find('#status').exists()).toBe(true);
        expect(former.findAll('#status option')).toHaveLength(5);
        expect(former.text()).toContain('Not on the lifecycle');
    });

    it('offers to revoke a colleague, not to delete them', () => {
        /*
         * Found by review on #162. `destroy()` never deletes a membership that
         * carries access — PRD F1.3 keeps historical attribution, and since
         * #140 the name on everything that person authored lives on this row —
         * so it revokes instead. The button said **Remove from team** and its
         * confirm promised that "your notes about them are deleted", which is
         * true of neither branch.
         */
        permissions = ['people.view', 'people.manage', 'team.members.manage'];

        const colleague = record(
            detail({
                status: null,
                carriesAccess: true,
                isColleague: true,
                roles: ['Team Member'],
            }),
        );

        expect(colleague.text()).toContain('Revoke access');
        expect(colleague.text()).not.toContain('Remove from team');

        const contact = record(detail());

        expect(contact.text()).toContain('Remove from team');
    });

    it('offers no control at all to somebody who cannot carry it out', () => {
        /*
         * The other half of the same finding: revoking is
         * `team.members.manage`, which the person who tidies the directory
         * does not hold — so the button was a 403 with a confirm in front of
         * it. A contact is still theirs to remove.
         */
        const colleague = record(
            detail({
                status: null,
                carriesAccess: true,
                isColleague: true,
                roles: ['Team Member'],
            }),
        );

        expect(colleague.text()).not.toContain('Revoke access');
        expect(colleague.text()).not.toContain('Remove from team');
        expect(record(detail()).text()).toContain('Remove from team');
    });

    it('offers nothing to revoke once access has ended', () => {
        permissions = ['people.view', 'people.manage', 'team.members.manage'];

        const former = record(
            detail({
                status: null,
                carriesAccess: true,
                isColleague: false,
                isRevoked: true,
                roles: ['Team Member'],
                revokedAt: '2026-08-01T00:00:00+00:00',
            }),
        );

        expect(former.text()).not.toContain('Revoke access');
        expect(former.text()).not.toContain('Remove from team');
    });

    it('does not send a lifecycle for a colleague, and does for a contact', async () => {
        /*
         * The server refuses `status` outright on a membership carrying access
         * (`PersonRules`), so sending it would fail the whole edit. Hidden in
         * the UI *and* dropped from the payload — one without the other is
         * either a 422 nobody expects or a rule only the client enforces.
         */
        const colleague = dialog(
            detail({
                status: null,
                carriesAccess: true,
                isColleague: true,
                roles: ['Team Member'],
            }),
        );

        await colleague.find('form').trigger('submit');

        expect(form.__transformed).not.toHaveProperty('status');

        const contact = dialog(detail());

        await contact.find('form').trigger('submit');

        expect(form.__transformed).toHaveProperty('status');
    });
});
