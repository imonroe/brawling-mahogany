/**
 * S18's reminder schedule, parsed rather than asserted in prose (#107, #109).
 *
 * The field is a comma-separated list of days before the date, and the two
 * states that matter are the ones a text field is worst at: **empty**, which
 * has to mean *"no reminders"*, and **default**, which has to stay default
 * rather than being frozen onto the row by somebody who opened the dialog and
 * pressed Save.
 *
 * Both were wrong. `Number('')` is `0`, not `NaN`, so `''.split(',')` is
 * `['']` is `[0]` — clearing the field to turn reminders off *added* a
 * same-day reminder, and a trailing comma mid-typing added one silently. This
 * is here rather than in a feature test because neither is visible from the
 * server: the request arrives well-formed and wrong.
 */
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import KeyDateFormDialog from '@/components/app/KeyDateFormDialog.vue';
import type { KeyDateRow } from '@/components/app/KeyDateFormDialog.vue';

const submitted = vi.fn();

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');

    const make = (fields: Record<string, unknown>) => {
        const form: Record<string, unknown> = reactive({
            ...fields,
            errors: {},
            processing: false,
            reset: () => Object.assign(form, fields),
            clearErrors: () => {
                form.errors = {};
            },
            post: (url: string) => submitted(url, { ...form }),
            patch: (url: string) => submitted(url, { ...form }),
        });

        return form;
    };

    return {
        useForm: make,
        usePage: () => ({ props: {} }),
        router: { post: vi.fn(), patch: vi.fn() },
    };
});

function flush(): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, 0));
}

/** The row shape S18 sends, with only the fields a test varies spelled out. */
function row(overrides: Partial<KeyDateRow>): KeyDateRow {
    return {
        id: 'KD-1',
        name: 'Financing',
        date: '2026-09-30',
        isCritical: false,
        notes: null,
        isDerived: false,
        wasDetached: false,
        anchor: null,
        offsetDays: null,
        offsetBasis: null,
        derivation: null,
        source: 'manual',
        isPending: false,
        reminderDays: [7, 1],
        remindersAreSet: false,
        isPastDue: false,
        ...overrides,
    };
}

/**
 * What the dialog would send, read off the submit spy.
 *
 * Asserted here rather than by reaching into `wrapper.vm.form`, because what
 * matters is the request: a getter that looked right over a form that sent
 * something else would pass either way.
 */
async function submit(): Promise<Record<string, unknown>> {
    const form = document.querySelector('form');

    if (form === null) {
        throw new Error('The dialog has no form.');
    }

    form.dispatchEvent(new Event('submit', { cancelable: true }));

    await flush();

    return submitted.mock.calls.at(-1)?.[1] as Record<string, unknown>;
}

async function openDialog(keyDate: KeyDateRow | null = null) {
    const wrapper = mount(KeyDateFormDialog, {
        props: {
            open: false,
            dealId: 'DEAL-1',
            keyDate,
            anchorOptions: [],
            offsetBases: {
                calendar: 'Calendar days',
                business: 'Business days',
            },
        },
        attachTo: document.body,
    });

    await wrapper.setProps({ open: true });
    await flush();

    return wrapper;
}

/*
 * Queried off `document`, not off the wrapper: Reka portals the dialog's
 * content into `document.body`, so `wrapper.find()` looks in the wrong tree
 * and hands back a wrapper whose `setValue` throws.
 */
function reminderField(): HTMLInputElement {
    const field = document.querySelector<HTMLInputElement>(
        '#key_date_reminders',
    );

    if (field === null) {
        throw new Error('The reminder field is not on the dialog.');
    }

    return field;
}

async function type(value: string): Promise<void> {
    const field = reminderField();

    field.value = value;
    field.dispatchEvent(new Event('input'));

    await flush();
}

describe('the reminder schedule field', () => {
    beforeEach(() => {
        submitted.mockClear();
        document.body.innerHTML = '';
    });

    it('shows the default for a new date without storing it', async () => {
        await openDialog();

        expect(reminderField().value).toBe('7, 1');

        // Untouched, so nothing is sent and the row keeps following the rule —
        // including when it is later marked critical.
        expect((await submit()).reminderOffsets).toBeNull();
    });

    it('turns reminders off when the field is cleared', async () => {
        await openDialog();

        await type('');

        expect((await submit()).reminderOffsets).toEqual([]);
    });

    it('ignores a trailing comma rather than adding a same-day reminder', async () => {
        // Every keystroke of "14, 7, 1" passes through this state.
        await openDialog();

        await type('14, 7,');

        expect((await submit()).reminderOffsets).toEqual([14, 7]);
    });

    it('reads a stored schedule back as stored, and a default as default', async () => {
        await openDialog(
            row({ reminderDays: [14, 7, 1], remindersAreSet: true }),
        );

        expect((await submit()).reminderOffsets).toEqual([14, 7, 1]);

        document.body.innerHTML = '';

        /*
         * `reminderDays` is what the row *uses*, defaults resolved, so it
         * cannot say whether anything is stored. Reading only that would
         * freeze today's default onto every row somebody opened and saved —
         * and the date would stop following the rule the moment it was marked
         * critical.
         */
        await openDialog(
            row({ id: 'KD-2', reminderDays: [7, 1], remindersAreSet: false }),
        );

        expect((await submit()).reminderOffsets).toBeNull();
    });
});
