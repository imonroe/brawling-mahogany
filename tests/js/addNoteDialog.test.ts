/**
 * F4.11's toggle, and the one property that is the whole feature (#72).
 *
 * *"Internal by default is the whole feature. The default must never be
 * 'visible', and the toggle must never be sticky across notes — an agent who
 * made one note client-visible last Tuesday must not silently publish the next
 * one."*
 *
 * The server half is pinned in `DealNotesTest`: an absent checkbox stays
 * internal. This is the half it cannot see — whether the *form* carries a
 * decision from one note to the next, including across a cancel.
 */
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, reactive } from 'vue';

const post = vi.fn();
/*
 * Reactive, because the dialog's preview is driven by the form rather than by
 * a prop — a plain object would let every assertion about the *value* pass
 * while the one about what renders quietly tested nothing.
 */
const form: Record<string, unknown> = reactive({});

vi.mock('@inertiajs/vue3', () => ({
    useForm: (initial: Record<string, unknown>) => {
        Object.assign(form, initial, {
            post,
            processing: false,
            errors: {},
            reset: () => Object.assign(form, initial),
            clearErrors: () => {},
        });

        return form;
    },
}));

const AddNoteDialog = (await import('@/components/app/AddNoteDialog.vue'))
    .default;

let wrapper: ReturnType<typeof mount> | null = null;

function dialog(open = true) {
    wrapper = mount(AddNoteDialog, {
        props: { open, dealUrl: '/deals/deal-1' },
        global: {
            stubs: {
                Dialog: { template: '<div><slot /></div>' },
                DialogContent: { template: '<div><slot /></div>' },
                DialogTitle: { template: '<h2><slot /></h2>' },
                DialogDescription: { template: '<p><slot /></p>' },
                Checkbox: defineComponent({
                    props: { modelValue: { type: Boolean, default: false } },
                    emits: ['update:modelValue'],
                    template: '<input type="checkbox" />',
                }),
            },
        },
    });

    return wrapper;
}

describe('the note dialog', () => {
    beforeEach(() => {
        post.mockClear();
    });

    /*
     * Unmounted between cases, and the form emptied only after that.
     * A live component watching keys that have just been deleted throws from
     * its own render — an unhandled rejection that leaves every assertion
     * passing and the run reporting errors nobody has to act on.
     */
    afterEach(() => {
        wrapper?.unmount();
        wrapper = null;

        for (const key of Object.keys(form)) {
            delete form[key];
        }
    });

    it('starts internal', () => {
        dialog();

        expect(form.is_client_visible).toBe(false);
    });

    it('forgets a published note when the dialog reopens', async () => {
        const wrapper = dialog();

        form.is_client_visible = true;
        form.body = 'Published on purpose, this once.';

        // Closed and opened again, which is the next note.
        await wrapper.setProps({ open: false });
        await wrapper.setProps({ open: true });

        expect(form.is_client_visible).toBe(false);
        expect(form.body).toBe('');
    });

    it('forgets it after a cancel, too', async () => {
        /*
         * The other route to the same failure: resetting on submit would leave
         * the toggle set for somebody who ticked it, thought better of it, and
         * closed the dialog.
         */
        const wrapper = dialog();

        form.is_client_visible = true;

        await wrapper.setProps({ open: false });
        await wrapper.setProps({ open: true });

        expect(form.is_client_visible).toBe(false);
    });

    it('shows the client nothing until the note is meant for them', async () => {
        const wrapper = dialog();

        expect(wrapper.find('[data-slot="client-preview"]').exists()).toBe(
            false,
        );

        form.is_client_visible = true;
        form.body = 'Your inspection is booked for Thursday morning.';
        await wrapper.vm.$nextTick();

        const preview = wrapper.find('[data-slot="client-preview"]');

        expect(preview.exists()).toBe(true);
        // Rendered in the client's own reading size (§9.6), not the app's.
        expect(preview.html()).toContain('client-surface');
        expect(preview.text()).toContain(
            'Your inspection is booked for Thursday morning.',
        );
    });
});
