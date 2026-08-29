/**
 * S66 and S67, as assertions (PRD §4.10 F10.2–F10.4 · Design System §7.4,
 * §9.5 · issues #116, #117).
 *
 * Screen Inventory carries a danger callout over S66:
 *
 * > A missed inspection deadline has legal consequences, and this screen is
 * > the only thing standing between a model's output and a live contingency
 * > calendar. It must make an unreviewed date impossible to accept by
 * > accident, show its source page so a human can actually check it, and
 * > never default to "confirm all."
 *
 * Every promise in that sentence is machine-checkable, and none of them was
 * until this file. What is asserted here is the **substance**, not the
 * wording: that no control confirms every date at once, that the header's
 * count only ever counts what a person has actually been through, that a
 * high confidence still costs a press, that confidence is not drawn as a
 * status, that every field carries its page and its quote, and that a
 * conflict states its consequence rather than merely flagging a difference.
 * The copy should be free to improve; the promises should not.
 *
 * ## Mounted, never re-implemented
 *
 * The real page and the real card, not a copy of their arithmetic. CLAUDE.md
 * records `calendarNavigation.test.ts` as the counter-example — a guard that
 * held its own copy of the component's maths and stayed green with the fix
 * deleted — so nothing below computes a label it then compares against the
 * component's.
 *
 * ## The positive control
 *
 * The last test in the first block asserts this suite can *see* the failure
 * it is written about: a page with nothing reviewed must produce **no**
 * count in its primary action. Without it, a selector that stopped matching
 * would make "there is no confirm-all" pass over a screen that had one.
 */
import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';

const post = vi.fn();
const destroy = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: () => () => null }),
    router: { post, delete: destroy, get: vi.fn(), on: vi.fn() },
    Link: defineComponent({
        props: { href: { type: String, default: '' } },
        setup:
            (props, { slots }) =>
            () =>
                h('a', { href: props.href }, slots.default?.()),
    }),
    useForm: (initial: Record<string, unknown>) => {
        const form: Record<string, unknown> = reactive({
            ...initial,
            errors: {},
            processing: false,
            clearErrors: () => {
                form.errors = {};
            },
            post: (url: string) => post(url, { ...form }),
        });

        return form;
    },
    usePage: () => ({ props: {} }),
}));

const Extraction = (await import('@/pages/Deals/Extraction.vue')).default;
const ExtractionReviewCard = (
    await import('@/components/app/ExtractionReviewCard.vue')
).default;
const ConfidenceMark = (await import('@/components/app/ConfidenceMark.vue'))
    .default;

type Field = InstanceType<typeof ExtractionReviewCard>['$props']['field'];

function field(overrides: Partial<Field> = {}): Field {
    return {
        id: 'ef-1',
        fieldType: 'key_date',
        label: 'Inspection objection',
        proposedValue: '2026-09-12',
        value: '2026-09-12',
        confidence: 0.72,
        sourcePage: 4,
        sourceSnippet:
            'Buyer shall have until September 12, 2026 to object in writing.',
        reviewState: 'pending',
        reviewedByName: null,
        reviewedAt: null,
        isCritical: true,
        derivation: null,
        detail: null,
        severity: null,
        conflict: null,
        cascade: [],
        createdRecordUrl: null,
        ...overrides,
    };
}

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
        documents: 1,
        dates: 2,
    },
    hasOffers: true,
    advance: null,
};

function page(overrides: Record<string, unknown> = {}): VueWrapper {
    return mount(Extraction, {
        props: {
            dealHeader,
            dealUrl: '/deals/deal-1',
            extraction: {
                id: 'ex-1',
                kind: 'contract',
                kindLabel: 'Contract dates',
                state: 'complete',
                documentName: 'Executed contract.pdf',
                documentUrl: '/deals/deal-1/documents/doc-1/download',
                provenance: {
                    provider: 'Anthropic',
                    model: 'claude-opus',
                    modelVersion: '4.5',
                    promptVersion: 'v3',
                    cost: '$0.08',
                    latencyMs: 4180,
                },
                error: null,
                omittedCount: null,
            },
            fields: [field(), field({ id: 'ef-2', label: 'Closing' })],
            progress: { reviewed: 0, total: 2 },
            canConfirm: true,
            ...overrides,
        },
    });
}

/** Every control on the screen, as the words a distracted person would read. */
function controlLabels(wrapper: VueWrapper): string[] {
    return labelsWithin(wrapper.element as HTMLElement);
}

/**
 * The controls in §9.5's review header, which is where the scoped primary
 * action lives.
 *
 * Scoped rather than swept off the whole page, and the reason is the defect
 * this helper was written after: a card's own source link reads *"Page 4"*,
 * so *"no control names a number"* was false on a screen with nothing
 * reviewed for a reason that has nothing to do with the promise. A guard that
 * cannot say which control it means is a guard whose next failure is
 * answered by widening it.
 */
function headerLabels(wrapper: VueWrapper): string[] {
    const header = wrapper.find('header');

    expect(header.exists()).toBe(true);

    return labelsWithin(header.element as HTMLElement);
}

function labelsWithin(root: HTMLElement): string[] {
    return [...root.querySelectorAll('button, a, input[type="submit"]')].map(
        (control) => (control.textContent ?? '').replace(/\s+/g, ' ').trim(),
    );
}

beforeEach(() => {
    post.mockClear();
    destroy.mockClear();
});

describe('S66 — there is no confirm-all, and the count is scoped', () => {
    it('offers no control that confirms every date at once', () => {
        const wrapper = page({
            fields: [
                field(),
                field({ id: 'ef-2', label: 'Closing' }),
                field({ id: 'ef-3', label: 'Financing' }),
            ],
            progress: { reviewed: 0, total: 3 },
        });

        for (const label of controlLabels(wrapper)) {
            expect(label).not.toMatch(/confirm all|accept all|approve all/i);
            expect(label).not.toMatch(/all (dates|fields|proposals)/i);
        }

        // One Confirm per card and not one more: a bulk press would be an
        // extra control over the same list.
        const confirms = controlLabels(wrapper).filter(
            (label) => label === 'Confirm',
        );

        expect(confirms).toHaveLength(3);
    });

    it('offers no select-all, and no tick at all, on the contract kind', () => {
        const wrapper = page({
            fields: [field(), field({ id: 'ef-2', label: 'Closing' })],
        });

        // §7.4: "There is no confirm-all, and no select-all." A date renders
        // no checkbox, so there is nothing for a select-all to select.
        expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(0);
    });

    it('names a count in the primary action that only counts reviewed items', () => {
        const wrapper = page({ progress: { reviewed: 3, total: 11 } });

        const scoped = headerLabels(wrapper).filter((label) =>
            /\b3\b/.test(label),
        );

        expect(scoped).toHaveLength(1);
        // The count is what has been reviewed, never the size of the list.
        expect(scoped[0]).not.toMatch(/\b11\b/);
    });

    it('produces no count at all when nothing has been reviewed', () => {
        /*
         * The positive control. Every assertion above is of the form "the
         * screen does not say X", which a broken selector satisfies for free.
         * This one requires the suite to be able to tell the two fixtures
         * apart: with nothing reviewed there is no number anywhere on a
         * control, so the count above was really read off the screen.
         */
        const wrapper = page({ progress: { reviewed: 0, total: 11 } });

        for (const label of headerLabels(wrapper)) {
            expect(label).not.toMatch(/\d/);
        }
    });
});

describe('S66 — a high confidence is still only an opinion', () => {
    it('renders a confirm control for a near-certain date, and confirms nothing on its own', () => {
        const wrapper = mount(ExtractionReviewCard, {
            props: { field: field({ confidence: 0.99 }) },
        });

        const confirm = wrapper
            .findAll('button')
            .find((button) => button.text() === 'Confirm');

        expect(confirm).toBeDefined();
        // Mounting is not reviewing: F10.2's "nothing enters the contingency
        // calendar unconfirmed" has to survive a page that merely rendered.
        expect(wrapper.emitted('confirm')).toBeUndefined();

        confirm?.trigger('click');

        expect(wrapper.emitted('confirm')).toEqual([['2026-09-12']]);
    });

    it('draws confidence as a mark and never as a status badge', () => {
        const wrapper = mount(ConfidenceMark, { props: { confidence: 0.99 } });

        // §2.5: "an icon plus text, no pill". A StatusBadge stamps its own
        // data-slot, so borrowing one would show up here.
        expect(wrapper.attributes('data-slot')).toBe('confidence-mark');
        expect(wrapper.find('[data-slot="status-badge"]').exists()).toBe(false);
        expect(wrapper.html()).not.toMatch(/rounded-full/);
        expect(wrapper.html()).not.toMatch(/\bbg-state-/);
        // The name a screen reader reads says which vocabulary this is.
        expect(wrapper.text()).toMatch(/confidence/i);
    });

    it('keeps the two vocabularies apart on one card', () => {
        const wrapper = mount(ExtractionReviewCard, {
            props: {
                field: field({
                    confidence: 0.4,
                    reviewState: 'confirmed',
                    reviewedByName: 'Heather Vance',
                    reviewedAt: '2026-08-28T15:04:00Z',
                }),
            },
        });

        const badges = wrapper.findAll('[data-slot="status-badge"]');
        const marks = wrapper.findAll('[data-slot="confidence-mark"]');

        expect(marks).toHaveLength(1);
        expect(badges.length).toBeGreaterThan(0);

        for (const badge of badges) {
            expect(badge.text()).not.toMatch(/confidence/i);
        }
    });
});

describe('S66 — the source is on the screen beside the value', () => {
    it('gives every field its page and the words it was copied from', () => {
        const rows = [
            field(),
            field({
                id: 'ef-2',
                label: 'Closing',
                sourcePage: 9,
                sourceSnippet: 'Closing shall occur on or before October 3.',
            }),
        ];

        const wrapper = page({ fields: rows });

        const cards = wrapper.findAll('[data-slot="extraction-review-card"]');

        expect(cards).toHaveLength(rows.length);

        cards.forEach((card, index) => {
            expect(card.text()).toContain(`Page ${rows[index].sourcePage}`);
            expect(card.text()).toContain(rows[index].sourceSnippet);
        });
    });

    it('says so loudly when the extraction recorded no page', () => {
        // Rule 2 is that the link is mandatory. When the server has nothing to
        // link to, the reviewer becomes the fallback and has to know they are.
        const wrapper = mount(ExtractionReviewCard, {
            props: { field: field({ sourcePage: null }) },
        });

        expect(wrapper.text()).toMatch(/no source page/i);
        expect(wrapper.html()).toContain('text-state-danger');
    });

    it('shows the selected field’s passage in the source pane', async () => {
        const wrapper = page({
            fields: [
                field(),
                field({
                    id: 'ef-2',
                    label: 'Closing',
                    sourcePage: 9,
                    sourceSnippet:
                        'Closing shall occur on or before October 3.',
                }),
            ],
        });

        const pane = () => wrapper.find('[data-slot="source-page"]');

        expect(pane().text()).toContain('Page 4');

        await wrapper
            .findAll('[data-slot="extraction-review-card"]')[1]
            .trigger('click');

        expect(pane().text()).toContain('Page 9');
        expect(pane().text()).toContain('Closing shall occur');
    });
});

describe('S66 — a conflict states its consequence', () => {
    it('says how many deadlines confirming would shift, not merely that there is a difference', () => {
        const wrapper = mount(ExtractionReviewCard, {
            props: {
                field: field({
                    conflict: {
                        name: 'Inspection objection',
                        currentDate: '2026-09-08',
                        movesCount: 4,
                        detaches: false,
                        anchorName: null,
                    },
                    cascade: [
                        {
                            id: 'kd-9',
                            name: 'Resolution deadline',
                            isCritical: true,
                            from: '2026-09-15',
                            to: '2026-09-19',
                            days: 4,
                        },
                    ],
                }),
            },
        });

        const text = wrapper.text();

        expect(text).toMatch(/shifts 4 derived deadlines/);
        // And the difference alone is not enough: the card carries the moved
        // rows too, so the number is checkable rather than merely asserted.
        expect(text).toContain('Resolution deadline');
        expect(wrapper.html()).toContain('border-state-warning');
    });

    it('does not claim a shift when nothing follows the date', () => {
        const wrapper = mount(ExtractionReviewCard, {
            props: {
                field: field({
                    conflict: {
                        name: 'Closing',
                        currentDate: '2026-10-03',
                        movesCount: 0,
                        detaches: false,
                        anchorName: null,
                    },
                }),
            },
        });

        expect(wrapper.text()).not.toMatch(/shifts/);
        expect(wrapper.text()).toMatch(/nothing else is counted from it/i);
    });

    it('states the other direction too — that confirming stops a derived date following its anchor', () => {
        /*
         * Round 2 of adversarial review found the strip stating one
         * consequence and not the other. `movesCount` is what this date drags
         * with it; a **derived** date confirmed against a typed day stops
         * following its anchor for good (`SaveKeyDate::edit()` clears
         * `is_derived` and stamps `detached_at`), and the strip said nothing.
         *
         * #106's whole argument for storing a derived day rather than
         * computing it is that a move becomes a write somebody can be shown
         * before it happens. A cascade shown without the detachment is half of
         * that promise.
         */
        const wrapper = mount(ExtractionReviewCard, {
            props: {
                field: field({
                    conflict: {
                        name: 'Inspection objection',
                        currentDate: '2026-09-08',
                        movesCount: 2,
                        detaches: true,
                        anchorName: 'Mutual acceptance',
                    },
                }),
            },
        });

        const text = wrapper.text();

        expect(text).toMatch(/shifts 2 derived deadlines/);
        expect(text).toContain('Mutual acceptance');
        expect(text).toMatch(/stops that/i);
    });

    it('says nothing about an anchor when the date has none', () => {
        /*
         * The control. A card that appended the detachment sentence
         * unconditionally would pass the case above and lie on every ordinary
         * conflict — which is most of them, since a date a contract states is
         * typed rather than derived.
         */
        const wrapper = mount(ExtractionReviewCard, {
            props: {
                field: field({
                    conflict: {
                        name: 'Closing',
                        currentDate: '2026-10-03',
                        movesCount: 1,
                        detaches: false,
                        anchorName: null,
                    },
                }),
            },
        });

        expect(wrapper.text()).not.toMatch(/stops that/i);
    });
});

describe('S67 — the inspection kind may accept in bulk, over ticks only', () => {
    function inspection(overrides: Record<string, unknown> = {}) {
        return page({
            extraction: {
                id: 'ex-2',
                kind: 'inspection',
                kindLabel: 'Inspection findings',
                state: 'complete',
                documentName: 'Inspection report.pdf',
                documentUrl: '/deals/deal-1/documents/doc-2/download',
                provenance: {
                    provider: 'Anthropic',
                    model: 'claude-opus',
                    modelVersion: '4.5',
                    promptVersion: 'v3',
                    cost: '$0.11',
                    latencyMs: 5120,
                },
                error: null,
                omittedCount: 2,
            },
            fields: [
                field({
                    id: 'ef-a',
                    fieldType: 'task',
                    label: 'Replace the GFCI outlet',
                    proposedValue: 'Replace the GFCI outlet',
                    value: 'Replace the GFCI outlet',
                    severity: 'safety',
                    isCritical: false,
                }),
                field({
                    id: 'ef-b',
                    fieldType: 'task',
                    label: 'Re-caulk the tub surround',
                    proposedValue: 'Re-caulk the tub surround',
                    value: 'Re-caulk the tub surround',
                    severity: 'minor',
                    isCritical: false,
                }),
            ],
            progress: { reviewed: 0, total: 2 },
            ...overrides,
        });
    }

    it('will not accept anything until something is ticked', async () => {
        const wrapper = inspection();

        const primary = wrapper
            .findAll('button')
            .find((button) => /accept/i.test(button.text()));

        expect(primary?.text()).toBe('Accept ticked findings');
        expect(primary?.attributes('disabled')).toBeDefined();

        await primary?.trigger('click');

        expect(post).not.toHaveBeenCalled();
    });

    it('says how many are ticked, and posts only those', async () => {
        const wrapper = inspection();

        await wrapper.findAll('input[type="checkbox"]')[0].setValue(true);

        const primary = wrapper
            .findAll('button')
            .find((button) => /accept/i.test(button.text()));

        expect(primary?.text()).toBe('Accept 1 ticked finding');

        await primary?.trigger('click');

        expect(post).toHaveBeenCalledWith(
            '/deals/deal-1/extractions/ex-2/fields',
            { ids: ['ef-a'] },
            expect.anything(),
        );
    });

    it('says what the model left out rather than implying the list is the report', () => {
        expect(inspection().find('[data-slot="review-guard"]').text()).toMatch(
            /left 2 findings out/i,
        );
    });
});

describe('the guard alert', () => {
    it('says nothing is on the deal yet, above the list rather than under it', () => {
        const wrapper = page();
        const html = wrapper.html();
        const guard = wrapper.find('[data-slot="review-guard"]');
        const firstCard = wrapper.find('[data-slot="extraction-review-card"]');

        expect(guard.exists()).toBe(true);
        expect(guard.text()).toMatch(
            /nothing on this page is on the deal yet/i,
        );
        expect(html.indexOf('data-slot="review-guard"')).toBeLessThan(
            html.indexOf('data-slot="extraction-review-card"'),
        );
        expect(firstCard.exists()).toBe(true);
    });

    it('carries F10.4’s provenance in the header', () => {
        // Model, prompt version and cost are a requirement, not a footer.
        const text = page().text();

        expect(text).toContain('claude-opus');
        expect(text).toContain('prompt v3');
        expect(text).toContain('$0.08');
    });
});

describe('the per-field writes', () => {
    it('confirms one field at a time, at the field’s own route', async () => {
        const wrapper = page();

        await wrapper
            .findAll('[data-slot="extraction-review-card"]')[0]
            .findAll('button')
            .find((button) => button.text() === 'Confirm')
            ?.trigger('click');

        expect(post).toHaveBeenCalledTimes(1);
        expect(post).toHaveBeenCalledWith(
            '/deals/deal-1/extractions/ex-1/fields/ef-1',
            { value: '2026-09-12' },
            expect.anything(),
        );
    });

    it('hides the whole action band when the reader cannot confirm', () => {
        const wrapper = page({ canConfirm: false });

        for (const label of controlLabels(wrapper)) {
            expect(label).not.toMatch(/^(confirm|reject|edit)$/i);
        }
    });
});

/**
 * S65 — the disclosure, which is a compliance control and not a nicety.
 *
 * PRD §4.10's danger note (*"F10.5 narrows the exposure. It does not
 * eliminate it"*) and §14.3 (*"do not let marketing copy claim more than
 * section 8.4 actually delivers"*) are the two sentences this dialog has to
 * survive. So this block asserts both directions: that the mechanism is named
 * plainly, **and** that the reassuring words nobody is entitled to use are
 * absent. A test for only the first would pass over "sent securely and
 * anonymously to our AI partner", which is the failure that matters.
 */
describe('S65 — what leaves the account, said plainly', () => {
    async function dialog(overrides: Record<string, unknown> = {}) {
        const ExtractDocumentDialog = (
            await import('@/components/app/ExtractDocumentDialog.vue')
        ).default;

        return mount(ExtractDocumentDialog, {
            props: {
                open: true,
                documentId: 'doc-1',
                documentName: 'Executed contract.pdf',
                dealUrl: '/deals/deal-1',
                available: true,
                unavailableReason: null,
                spend: {
                    used: '$4.80',
                    cap: '$25.00',
                    percent: 19,
                    warn: false,
                    resetsAt: 'Sep 1',
                },
                ...overrides,
            },
            global: {
                // The shadcn dialog teleports to `body`; unstubbed, every
                // assertion below would pass by looking at nothing.
                stubs: {
                    Dialog: { template: '<div><slot /></div>' },
                    DialogContent: { template: '<div><slot /></div>' },
                    DialogHeader: { template: '<div><slot /></div>' },
                    DialogFooter: { template: '<div><slot /></div>' },
                    DialogTitle: { template: '<h2><slot /></h2>' },
                    DialogDescription: { template: '<p><slot /></p>' },
                },
            },
        });
    }

    it('names the mechanism rather than describing a safeguard', async () => {
        const text = (await dialog()).text().toLowerCase();

        expect(text).toContain('leave your account');
        expect(text).toContain('masked');
        expect(text).toContain('outside model');
    });

    it('does not claim more than F10.5 delivers', async () => {
        const text = (await dialog()).text().toLowerCase();

        // The exposure is narrowed, not removed, and the copy has to say the
        // second thing rather than the first.
        expect(text).toMatch(/does not remove it|not remove/);

        for (const overclaim of [
            'anonymis',
            'anonymiz',
            'never leaves',
            'completely secure',
            'fully redacted',
        ]) {
            expect(text, overclaim).not.toContain(overclaim);
        }
    });

    it('carries the spend position as a state rather than a footnote', async () => {
        const near = await dialog({
            spend: {
                used: '$23.10',
                cap: '$25.00',
                percent: 92,
                warn: true,
                resetsAt: 'Sep 1',
            },
        });

        expect(near.find('[data-slot="extraction-spend"]').text()).toContain(
            '$23.10 of $25.00',
        );
        expect(near.text().toLowerCase()).toContain('close to the cap');
    });

    it('says why, rather than only disabling, when extraction is unavailable', async () => {
        const wrapper = await dialog({
            available: false,
            unavailableReason:
                'No extraction provider is configured for this environment.',
        });

        expect(
            wrapper.find('[data-slot="extraction-unavailable"]').text(),
        ).toBe('No extraction provider is configured for this environment.');

        const start = wrapper
            .findAll('button')
            .find((button) => button.text() === 'Extract');

        expect(start?.attributes('disabled')).toBeDefined();
    });

    it('starts nothing until the button is pressed, and posts the chosen kind', async () => {
        const wrapper = await dialog();

        expect(post).not.toHaveBeenCalled();

        await wrapper.findAll('input[type="radio"]')[1].setValue();
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Extract')
            ?.trigger('click');

        expect(post).toHaveBeenCalledWith(
            '/deals/deal-1/extractions',
            expect.objectContaining({
                documentId: 'doc-1',
                kind: 'inspection',
            }),
        );
    });
});

describe('S66 — a decided field offers only what the server supports', () => {
    /*
     * Round 2 of adversarial review found this card promising a person
     * something the endpoints refuse.
     *
     * A reviewed field carried an **Undo** that re-opened the action band
     * locally and a note reading *"This is already on the deal. Confirm again
     * to change it, or Reject to take it off."* Both presses met
     * `ConfirmExtractedField`'s `alreadyReviewed()` refusal, surfaced as a
     * validation error on a field the person had not edited — and the second
     * half was false a second time, because rejecting a confirmed field flips
     * a review state and does not take the date off the deal.
     *
     * The rule these cases hold is not the copy. It is that this card offers
     * **no write** over a row that has left `pending`, and that it still says
     * where the thing it produced actually lives.
     */
    function decided(overrides: Partial<Field> = {}) {
        return mount(ExtractionReviewCard, {
            props: {
                field: field({
                    reviewState: 'confirmed',
                    reviewedByName: 'Heather Vance',
                    reviewedAt: '2026-08-28T15:04:00Z',
                    createdRecordUrl: '/deals/deal-1/dates#kd-1',
                    ...overrides,
                }),
                canConfirm: true,
            },
        });
    }

    it('offers no button that would be refused', () => {
        const labels = decided()
            .findAll('button')
            .map((button) => button.text().trim());

        expect(labels).not.toContain('Confirm');
        expect(labels).not.toContain('Reject');
        expect(labels).not.toContain('Edit');
        expect(labels).not.toContain('Undo');
        expect(labels).not.toContain('Reopen');
    });

    it('offers them all while the field is still pending', () => {
        /*
         * The positive control, and the case that makes the one above mean
         * something: a card that had stopped rendering its action band at all
         * would pass the first test over a screen nobody could review on.
         */
        const labels = mount(ExtractionReviewCard, {
            props: { field: field(), canConfirm: true },
        })
            .findAll('button')
            .map((button) => button.text().trim());

        expect(labels).toContain('Confirm');
        expect(labels).toContain('Reject');
        expect(labels).toContain('Edit');
    });

    it('sends a person to the record instead, which is where changing it works', () => {
        const wrapper = decided();

        const link = wrapper.find('a[href="/deals/deal-1/dates#kd-1"]');

        expect(link.exists()).toBe(true);

        /*
         * And nothing on the card claims a second decision is available. This
         * is asserted on the substance rather than on the sentence: any text
         * telling somebody to confirm or reject *again* is the defect coming
         * back in different words.
         */
        expect(wrapper.text()).not.toMatch(/confirm again|take it off/i);
    });
});
