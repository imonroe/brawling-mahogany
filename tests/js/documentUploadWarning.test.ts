/**
 * S51 and S53 as assertions (#98, #99).
 *
 * Screen Inventory carries a danger note over these two rows:
 *
 * > The warning on S51 is a compliance control described in PRD section 10,
 * > and S53 is the visible half of the scan in PRD section 8.4. **Neither can
 * > be quietly softened later for being annoying.**
 *
 * Round 1 of adversarial review pointed out that nothing held them to it — the
 * warning and the refusal dialog had no test at all, so "quietly softened" was
 * exactly what could happen. Softening them now fails here.
 *
 * The assertions are about **substance, not wording**: that all five refused
 * categories are named, that the warning is a distinct region rather than a
 * line of description, and that a refusal says where the document belongs.
 * Copy should be free to improve; the promise should not.
 */
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

const post = vi.fn();
const form: Record<string, unknown> = reactive({});

vi.mock('@inertiajs/vue3', () => ({
    Head: { render: () => null },
    router: { delete: vi.fn(), patch: vi.fn(), get: vi.fn() },
    useForm: (initial: Record<string, unknown>) => {
        Object.assign(form, initial, {
            post,
            processing: false,
            errors: {},
            reset: () => Object.assign(form, initial),
        });

        return form;
    },
}));

const Documents = (await import('@/pages/Deals/Documents.vue')).default;

const dealHeader = {
    id: 'deal-1',
    name: '4820 Rosslyn Avenue',
    state: 'active',
    dealTypeName: 'Listing',
    sideLabel: 'Sell',
    clientName: 'Emily Bosart',
    location: { city: 'Indianapolis', state: 'IN' },
    counts: {
        people: 1,
        properties: 1,
        tasks: 0,
        offers: 0,
        documents: 0,
        dates: 0,
    },
    hasOffers: true,
    advance: null,
};

function render(overrides: Record<string, unknown> = {}) {
    return mount(Documents, {
        props: {
            dealHeader,
            dealUrl: '/deals/deal-1',
            documents: [],
            categories: { other: 'Other' },
            visibilities: {
                internal: 'Internal',
                client_visible: 'Client-visible',
            },
            maxBytes: 15 * 1024 * 1024,
            refusal: null,
            extract: {
                available: true,
                unavailableReason: null,
                spend: {
                    used: '$4.80',
                    cap: '$50.00',
                    percent: 10,
                    warn: false,
                    resetsAt: '2026-09-01T00:00:00+00:00',
                },
            },
            can: { upload: true, extract: true },
            ...overrides,
        },
        global: {
            /*
             * The shadcn dialog teleports to `body`, so an unstubbed mount
             * renders the warning somewhere `wrapper.text()` cannot see and
             * every assertion here passes by looking at nothing. Same stubs
             * `addNoteDialog.test.ts` uses, for the same reason.
             */
            stubs: {
                Dialog: { template: '<div><slot /></div>' },
                DialogContent: { template: '<div><slot /></div>' },
                DialogTitle: { template: '<h2><slot /></h2>' },
                DialogDescription: { template: '<p><slot /></p>' },
                AppSelect: { template: '<select />' },
                AppInput: { template: '<input />' },
                UploadZone: { template: '<div />' },
            },
        },
    });
}

describe('S51 — the upload warning', () => {
    beforeEach(() => {
        post.mockClear();
    });

    it('names every category that will be refused', async () => {
        /*
         * Every one, by name. "Sensitive documents" would be softer and would
         * fail the only thing that matters here: the failure mode is somebody
         * believing their file is the exception, and a category they can match
         * against their own file is what prevents that.
         *
         * Four since #209 removed the executed contract. What remains is
         * every case of `RestrictedDocumentCategory`, and the panel has to
         * name all of them — a category the scanner refuses and the warning
         * does not mention is somebody finding out after the upload.
         */
        const wrapper = render();

        await wrapper.find('button').trigger('click');

        const text = wrapper.text().toLowerCase();

        for (const category of [
            'earnest money',
            'lending packet',
            'bank statement',
            'government id',
        ]) {
            expect(text, category).toContain(category);
        }
    });

    it('says plainly not to upload them, rather than only that they are checked', () => {
        // A description of the check is reassurance. An instruction is a
        // control, and PRD §10 asks for the second.
        const wrapper = render();

        expect(wrapper.text().toLowerCase()).toContain('do not upload');
    });
});

describe('S53 — the refusal', () => {
    it('tells somebody where the document does belong', () => {
        /*
         * #99: *"that is the part that makes this acceptable rather than
         * infuriating."* A refusal that only refuses teaches people to work
         * around it.
         */
        const wrapper = render({
            refusal: {
                category: 'Bank statement',
                reason: 'This looks like a bank statement.',
                alternative:
                    'Keep it wherever your transaction paperwork lives.',
            },
        });

        const text = wrapper.text();

        expect(text).toContain('bank statement');
        expect(text).toContain('What to do instead');
        expect(text).toContain('Keep it wherever your transaction paperwork');
    });

    it('stays out of the way when there is nothing to refuse', () => {
        expect(render().text()).not.toContain('What to do instead');
    });
});

describe('S65 — the row’s own extraction control (#115)', () => {
    /*
     * Round 2 of adversarial review found that a `failed` or `blocked` attempt
     * took the Extract control away **permanently**: the branch rendering the
     * stopped badge sat ahead of the branch rendering the button in one
     * `v-if`/`v-else-if` chain, so the button could never be reached again for
     * that document. The only route back was uploading the same file a second
     * time.
     *
     * `StartExtraction` refuses a second attempt only while a sibling is
     * `queued` or `processing`, so the server was willing the whole time and
     * nothing could ask it. That gap is what these cases hold.
     */
    function documentWith(
        extraction: Record<string, unknown> | null,
    ): Record<string, unknown> {
        return {
            id: 'doc-1',
            name: 'Contract to Buy and Sell.pdf',
            caption: null,
            category: 'contract',
            categoryLabel: 'Contract',
            visibility: 'internal',
            sizeBytes: 240_000,
            uploadedAt: '2026-08-20T12:00:00+00:00',
            uploadedBy: 'Emily Bosart',
            scanState: 'clean',
            url: '/deals/deal-1/documents/doc-1',
            extraction,
        };
    }

    const stopped = {
        id: 'ex-1',
        state: 'failed',
        kind: 'contract',
        url: '/deals/deal-1/extractions/ex-1',
        pending: 0,
    };

    it('offers a way back after an attempt that ended badly', () => {
        const page = render({ documents: [documentWith(stopped)] });

        const buttons = page
            .findAll('button')
            .map((button) => button.text().trim());

        expect(buttons).toContain('Try again');

        /*
         * And the badge stays. The pair is the point: on its own the button
         * hides why the last attempt stopped, and on its own the badge is a
         * dead end.
         */
        expect(page.text()).toContain('Failed');
    });

    it('offers the same way back when the spend ceiling was what stopped it', () => {
        /*
         * `blocked` is a refusal rather than a fault, and it is the state most
         * likely to be retried — an owner raises the cap, or the month turns
         * over. Asserted separately because the first case would pass with a
         * predicate that named only `failed`.
         */
        const page = render({
            documents: [documentWith({ ...stopped, state: 'blocked' })],
        });

        expect(
            page.findAll('button').map((button) => button.text().trim()),
        ).toContain('Try again');
        expect(page.text()).toContain('Stopped');
    });

    it('does not offer one while an attempt is still running', () => {
        /*
         * The negative half, and it is the one that makes the two above mean
         * something: a component that rendered `Try again` unconditionally
         * would pass both. `StartExtraction` refuses a second attempt here, so
         * a button would be a press that can only produce an error.
         */
        const page = render({
            documents: [documentWith({ ...stopped, state: 'processing' })],
        });

        const labels = page
            .findAll('button')
            .map((button) => button.text().trim());

        expect(labels).not.toContain('Try again');
        expect(labels).not.toContain('Extract');
        expect(page.text()).toContain('Extracting');
    });

    it('does not offer one to somebody who may not extract', () => {
        const page = render({
            documents: [documentWith(stopped)],
            can: { upload: true, extract: false },
        });

        expect(
            page.findAll('button').map((button) => button.text().trim()),
        ).not.toContain('Try again');
    });
});
