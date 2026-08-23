/**
 * S26's two-click target, measured rather than asserted in prose.
 *
 * Screen Inventory marks S26 a **two-click target** and PRD F12.3 says why:
 * Heather logs a call from a car between showings. A feature test can see what
 * the endpoint writes; only this can see how many interactions it took to send
 * it — which is the requirement.
 *
 * The dialog is mounted in the shape the person record and the deal use, with
 * the person already known, because that is the case the two clicks are
 * budgeted for. The shell's entry point has to ask who first, and does so
 * *before* those two rather than between them.
 */
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import LogContactDialog from '@/components/app/LogContactDialog.vue';

const post = vi.fn();

const CONTACT_TYPES = {
    phone_call: 'Phone call',
    email: 'Email',
    text: 'Text',
    meeting: 'Meeting',
    showing: 'Showing',
    other: 'Other',
};

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');

    return {
        usePage: () => ({
            props: { lookups: { contactTypes: CONTACT_TYPES } },
        }),
        /*
         * A stand-in that keeps `useForm`'s shape: fields readable and
         * writable on the object, and `post` a spy whose calls carry the field
         * values *at the moment it was called*. Snapshotting is the point —
         * what is being measured is what the second click sends.
         */
        useForm: (fields: Record<string, unknown>) => {
            const form: Record<string, unknown> = reactive({
                ...fields,
                errors: {},
                processing: false,
                reset: () => Object.assign(form, fields),
                clearErrors: () => {
                    form.errors = {};
                },
                post: (url: string, options?: unknown) =>
                    post(url, { ...form }, options),
            });

            return form;
        },
    };
});

/**
 * Reka mounts the dialog's content through a portal and a presence wrapper, so
 * it lands in `document.body` a macrotask later rather than on the next tick.
 * Everything here waits on that rather than on `$nextTick`.
 */
function flush(): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, 0));
}

/*
 * The modal opens with `open: false` and is switched on, because that is what
 * every entry point does — and the watcher that resets the form only runs on
 * the transition. Mounting straight into `open: true` would skip it and test a
 * state the product never reaches.
 */
async function openDialog(props: Record<string, unknown> = {}) {
    const wrapper = mount(LogContactDialog, {
        props: {
            open: false,
            membership: { id: 'MEMBERSHIP-1', name: 'Claire Nakamura' },
            deals: [],
            ...props,
        },
        attachTo: document.body,
    });

    await wrapper.setProps({ open: true });
    await flush();

    return wrapper;
}

function typeTile(label: string): HTMLButtonElement {
    const tile = [...document.querySelectorAll('button')].find(
        (button) => button.textContent?.trim() === label,
    );

    if (!tile) {
        throw new Error(`No “${label}” tile in the dialog.`);
    }

    return tile as HTMLButtonElement;
}

function logItButton(): HTMLButtonElement {
    const button = [...document.querySelectorAll('button')].find(
        (candidate) => candidate.textContent?.trim() === 'Log it',
    );

    if (!button) {
        throw new Error('No “Log it” button in the dialog.');
    }

    return button as HTMLButtonElement;
}

describe('log contact', () => {
    beforeEach(() => {
        post.mockClear();
    });

    it('saves an entry in two clicks when the person is known', async () => {
        const wrapper = await openDialog();

        // Click one.
        typeTile('Phone call').click();
        await flush();

        // Click two.
        logItButton().click();
        await flush();

        expect(post).toHaveBeenCalledTimes(1);

        const [url, payload] = post.mock.calls[0];

        expect(url).toBe('/people/MEMBERSHIP-1/contact-log');
        expect(payload.contact_type).toBe('phone_call');
        // Nothing else was needed, which is the whole requirement: a note or a
        // time made mandatory here is a third and fourth click.
        expect(payload.note).toBe('');
        expect(payload.occurred_at).toBe('');
        expect(payload.deal_id).toBeNull();

        wrapper.unmount();
    });

    it('refuses to send before a type is picked', async () => {
        const wrapper = await openDialog();

        expect(logItButton().disabled).toBe(true);

        logItButton().click();
        await flush();

        expect(post).not.toHaveBeenCalled();

        typeTile('Showing').click();
        await flush();

        // Enabled by the type alone — never waiting on anything optional.
        expect(logItButton().disabled).toBe(false);

        wrapper.unmount();
    });

    it('offers a type tile for every contact type the server sent', async () => {
        const wrapper = await openDialog();

        for (const label of Object.values(CONTACT_TYPES)) {
            expect(typeTile(label)).toBeTruthy();
        }

        wrapper.unmount();
    });

    it('sends the deal a caller preselected', async () => {
        const wrapper = await openDialog({
            deals: [{ id: 'DEAL-1', name: '14 Elm St' }],
            dealId: 'DEAL-1',
        });

        typeTile('Meeting').click();
        await flush();

        logItButton().click();
        await flush();

        // Opened from a deal, the attachment costs no click at all.
        expect(post.mock.calls[0][1].deal_id).toBe('DEAL-1');

        wrapper.unmount();
    });

    it('asks who first when nothing preselected a person', async () => {
        const wrapper = await openDialog({ membership: null });

        // The shell's entry point. Still refused until somebody is chosen, so
        // the two clicks that follow cannot land on nobody.
        typeTile('Text').click();
        await flush();

        expect(logItButton().disabled).toBe(true);

        logItButton().click();
        await flush();

        expect(post).not.toHaveBeenCalled();

        wrapper.unmount();
    });

    it('does not carry the last entry into the next one', async () => {
        const wrapper = await openDialog();

        typeTile('Email').click();
        await flush();

        await wrapper.setProps({ open: false });
        await flush();
        await wrapper.setProps({ open: true });
        await flush();

        // Reopened, and the button is refusing again — so the type went with
        // the last entry rather than sitting there ready to be logged twice.
        expect(logItButton().disabled).toBe(true);

        wrapper.unmount();
    });
});
