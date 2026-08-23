/**
 * The two things about `Deals/Create.vue` that a feature test cannot see.
 *
 * Both were shipped and both were found by review rather than by the suite,
 * because the server half of each was correct — the wizard's endpoints did
 * exactly what they should. What was wrong was which of them the screen
 * called, and with what.
 */
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Create from '@/pages/Deals/Create.vue';

const patch = vi.fn();
const post = vi.fn();

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Head: defineComponent({
            setup:
                (_, { slots }) =>
                () =>
                    h('div', slots.default?.()),
        }),
        Link: defineComponent({
            props: { href: { type: String, default: '' } },
            setup:
                (props, { slots }) =>
                () =>
                    h('a', { href: props.href }, slots.default?.()),
        }),
        router: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
        /*
         * A stand-in that keeps `useForm`'s shape: fields are readable and
         * writable on the object itself, and `patch`/`post` are spies whose
         * calls carry the field values *at the moment they were called*.
         * Snapshotting is the point — the bug was a field being stale by the
         * time the request went out.
         */
        useForm: (fields: Record<string, unknown>) => {
            const form: Record<string, unknown> = {
                ...fields,
                errors: {},
                processing: false,
                reset: () => Object.assign(form, fields),
                patch: (url: string, options?: unknown) =>
                    patch(url, { ...form }, options),
                post: (url: string, options?: unknown) =>
                    post(url, { ...form }, options),
            };

            return form;
        },
    };
});

type Step = 'type' | 'client' | 'property' | 'template';

function draft(overrides: Record<string, unknown> = {}) {
    return {
        step: 'template' as Step,
        dealTypeId: 'TYPE-1',
        name: null,
        membershipId: null,
        participantRole: null,
        propertyId: null,
        workflowTemplateId: null,
        resumed: true,
        ...overrides,
    };
}

function build(props: Record<string, unknown> = {}) {
    return mount(Create, {
        props: {
            draft: draft(),
            steps: [
                { value: 'type', label: 'Type', position: 1 },
                { value: 'client', label: 'Client', position: 2 },
                { value: 'property', label: 'Property', position: 3 },
                { value: 'template', label: 'Process', position: 4 },
            ],
            dealTypes: [{ id: 'TYPE-1', name: 'Sale', sideLabel: 'Sell' }],
            impliedRole: { value: 'seller', label: 'Seller' },
            participantRoles: { seller: 'Seller', other: 'Other' },
            propertyTypes: { single_family: 'Single family' },
            propertyStatuses: { pre_listing: 'Pre-listing' },
            templates: [],
            chosen: { membership: null, property: null },
            ...props,
        },
        global: { stubs: { teleport: true } },
    });
}

describe('the create-deal wizard', () => {
    beforeEach(() => {
        patch.mockClear();
        post.mockClear();
    });

    it('offers the skip on step four even when no template is on offer', () => {
        /*
         * `CreateDealFromDraft` refuses a template deactivated mid-draft with
         * "choose a workflow again, or skip it" — and deactivating the only
         * template on offer empties `templates`, which used to take the skip
         * button away with it. The advice was then impossible to follow and
         * the only exit was discarding four steps of work.
         */
        const labels = build({ templates: [] })
            .findAll('button')
            .map((button) => button.text());

        expect(labels.join(' | ')).toContain('attach one later');
    });

    it('sends the draft’s membership when the role changes, not a stale mirror', async () => {
        /*
         * `stepTwo.team_membership_id` is a local mirror written only by the
         * initializer and by picking from the directory — creating a client
         * inline posts to a different endpoint and never touches it, and
         * `useForm` state is not re-derived from props on a visit. So the
         * PATCH went out empty after an inline create, and carried the
         * *previous* membership after pick-then-inline-create, silently
         * reverting the client.
         */
        const wrapper = build({
            draft: draft({
                step: 'client' as Step,
                membershipId: 'PICKED-FIRST',
            }),
            impliedRole: null,
            chosen: {
                membership: { id: 'PICKED-FIRST', name: 'Ada', email: null },
                property: null,
            },
        });

        /*
         * What an inline create does: the POST lands, the server records a
         * *different* membership, and `back()` re-renders with fresh props —
         * while `useForm`'s copy of the old id stays exactly where it was.
         *
         * Asserting without this step is asserting nothing: the mirror is
         * initialised from this very prop, so before the update the two agree
         * and any implementation passes.
         */
        await wrapper.setProps({
            draft: draft({
                step: 'client' as Step,
                membershipId: 'FROM-SERVER',
            }),
            chosen: {
                membership: { id: 'FROM-SERVER', name: 'Dana', email: null },
                property: null,
            },
        });

        const select = wrapper.find('#participant_role');

        expect(select.exists()).toBe(true);

        await select.setValue('other');

        expect(patch).toHaveBeenCalled();

        const [url, payload] = patch.mock.calls.at(-1) ?? [];

        expect(url).toBe('/deals/create');
        expect((payload as Record<string, unknown>).team_membership_id).toBe(
            'FROM-SERVER',
        );
        expect((payload as Record<string, unknown>).participant_role).toBe(
            'other',
        );
    });
});
