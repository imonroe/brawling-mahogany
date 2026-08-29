/**
 * S68, as assertions (PRD §4.10 F10.4, §12.3 · issue #118).
 *
 * #118 does not describe a table, it describes three readers:
 *
 * 1. **Audit** — *who confirmed this date, from which page, on what date?*
 * 2. **Cost** — *what is this team costing, and are we under $2 per deal?*
 * 3. **Quality** — *what is the model getting wrong, and has a version change
 *    made it worse?*
 *
 * So what is asserted here is that each question is **answerable from the
 * rendered page**, that F10.4's *"what the human changed"* shows both values
 * rather than a flag, and — the one that matters most — that the screen never
 * fabricates a figure it cannot measure.
 *
 * ## The number that must not appear
 *
 * PRD §12.3's second target is *"critical dates missed — zero tolerance"*, and
 * this application can never measure it: a date the model failed to report
 * leaves no row, so every count the live data could produce is `0` whether the
 * model is perfect or read one page of twelve. A tile reading **0 missed**
 * would be believed. The tests below assert the row exists (so the target is
 * not quietly dropped), carries **no figure**, and names the regression harness
 * that does measure it.
 *
 * ## Mounted, never re-implemented
 *
 * The real page, with the real state table and the real formatters. CLAUDE.md
 * records `calendarNavigation.test.ts` as the counter-example — a guard holding
 * its own copy of the component's arithmetic, green with the fix deleted — so
 * nothing here computes a string it then compares against the component's.
 *
 * ## The positive controls
 *
 * Three of the assertions below are about something being **absent**, and an
 * absence passes for a selector that has stopped matching, a fixture that
 * renders nothing, and a component that failed to mount. Each has a control
 * asserting the same selector *does* fire on a fixture where the thing is
 * present.
 */
import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: () => () => null }),
    Link: defineComponent({
        props: { href: { type: String, default: '' } },
        setup:
            (props, { slots }) =>
            () =>
                h('a', { href: props.href }, slots.default?.()),
    }),
    usePage: () => ({ props: {} }),
}));

const Extractions = (await import('@/pages/Settings/Extractions.vue')).default;

type Props = InstanceType<typeof Extractions>['$props'];

function scorecard(overrides: Record<string, unknown> = {}) {
    return {
        confirmedWithoutEdit: {
            percent: 91.7,
            confirmed: 11,
            edited: 1,
            rejected: 0,
            reviewed: 12,
            pending: 3,
            targetPercent: 85,
            meetsTarget: true,
        },
        costPerDeal: {
            deals: 4,
            average: '$1.40',
            total: '$5.60',
            overTarget: 0,
            target: '$2.00',
            meetsTarget: true,
        },
        /*
         * The shape the server actually ships: a target and where it is
         * measured, and deliberately no count. A fixture that invented one
         * would make the tests below pass over a screen that reported it.
         */
        criticalDates: {
            target: 'Zero',
            measuredHere: false,
            command: 'php artisan extraction:score',
        },
        ...overrides,
    };
}

function props(overrides: Partial<Props> = {}): Props {
    return {
        scorecard: scorecard(),
        spend: {
            monthToDate: '$5.60',
            cap: '$50.00',
            percent: 11,
            warnAtPercent: 80,
            resetsAt: '2026-09-01T00:00:00+00:00',
        },
        versions: [
            {
                key: 'claude-sonnet-5|2026-05|contract-v3',
                model: 'claude-sonnet-5',
                modelVersion: '2026-05',
                promptVersion: 'contract-v3',
                attempts: 3,
                cost: '$3.20',
                reviewed: 8,
                confirmedWithoutEdit: 100,
                lastUsedAt: '2026-08-27T15:00:00+00:00',
            },
            {
                key: 'claude-sonnet-5|2026-05|contract-v2',
                model: 'claude-sonnet-5',
                modelVersion: '2026-05',
                promptVersion: 'contract-v2',
                attempts: 2,
                cost: '$2.40',
                reviewed: 4,
                confirmedWithoutEdit: 75,
                lastUsedAt: '2026-08-01T15:00:00+00:00',
            },
        ],
        edits: [
            {
                id: 'ef-1',
                label: 'Inspection objection',
                fieldTypeLabel: 'Date',
                reviewState: 'edited',
                proposedValue: '2026-09-12',
                finalValue: '2026-09-14',
                confidence: 0.62,
                sourcePage: 4,
                reviewedByName: 'Heather Nguyen',
                reviewedAt: '2026-08-26T17:30:00+00:00',
                dealName: '4820 Rosslyn Avenue',
                documentName: 'Purchase agreement.pdf',
                promptVersion: 'contract-v3',
                model: 'claude-sonnet-5',
                url: '/deals/deal-1/extractions/ex-1',
            },
        ],
        editsTotal: 1,
        attempts: [
            {
                id: 'ex-1',
                state: 'complete',
                kindLabel: 'Contract',
                dealName: '4820 Rosslyn Avenue',
                documentName: 'Purchase agreement.pdf',
                model: 'claude-sonnet-5',
                modelVersion: '2026-05',
                promptVersion: 'contract-v3',
                cost: '$1.10',
                requestedByName: 'Heather Nguyen',
                createdAt: '2026-08-26T17:00:00+00:00',
                proposals: 11,
                pending: 0,
                edited: 1,
                error: null,
                url: '/deals/deal-1/extractions/ex-1',
            },
        ],
        attemptsTotal: 1,
        ...overrides,
    } as Props;
}

function render(overrides: Partial<Props> = {}): VueWrapper {
    return mount(Extractions, { props: props(overrides) });
}

/** The target row whose label starts with the given words. */
function targetRow(wrapper: VueWrapper, label: string) {
    const row = wrapper
        .findAll('[data-slot="target-row"]')
        .find((candidate) => candidate.text().includes(label));

    if (!row) {
        throw new Error(`No target row for "${label}".`);
    }

    return row;
}

describe('the three questions S68 exists to answer', () => {
    it('answers the audit question: who read what, for which deal, and when', () => {
        const wrapper = render();
        const attempt = wrapper.get('[data-slot="attempt-row"]');

        expect(attempt.text()).toContain('4820 Rosslyn Avenue');
        expect(attempt.text()).toContain('Purchase agreement.pdf');
        expect(attempt.text()).toContain('Heather Nguyen');
        expect(attempt.text()).toContain('Aug 26');

        // The full per-deal trail is S66's; every row has to reach it.
        expect(attempt.get('a').attributes('href')).toBe(
            '/deals/deal-1/extractions/ex-1',
        );
    });

    it('answers the cost question: the month, the ceiling, and the per-deal target', () => {
        const wrapper = render();

        expect(wrapper.get('[data-slot="month-spend"]').text()).toBe('$5.60');
        expect(wrapper.text()).toContain('of $50.00');
        expect(wrapper.text()).toContain('11%');

        const row = targetRow(wrapper, 'Cost per deal');

        expect(row.text()).toContain('$1.40');
        expect(row.text()).toContain('Under $2.00');
        // The distribution, not only the mean: an average hides the one deal
        // that is actually being asked about.
        expect(row.text()).toContain('none over target');
    });

    it('answers the quality question, with the denominator beside the rate', () => {
        const wrapper = render();
        const row = targetRow(wrapper, 'Dates confirmed without edit');

        expect(row.text()).toContain('91.7%');
        expect(row.text()).toContain('Above 85%');
        // A rate with no denominator is a rumour: 100% of three proposals
        // reads exactly like 100% of three hundred.
        expect(row.text()).toContain('12 reviewed date proposals');
    });

    it('makes a version change visible as a change, not a value in a list', () => {
        const wrapper = render();
        const rows = wrapper.findAll('[data-slot="version-row"]');

        expect(rows).toHaveLength(2);
        expect(rows[0].text()).toContain('contract-v3');
        expect(rows[0].get('[data-slot="version-rate"]').text()).toContain(
            '100%',
        );
        expect(rows[1].text()).toContain('contract-v2');
        expect(rows[1].get('[data-slot="version-rate"]').text()).toContain(
            '75%',
        );
    });
});

describe('what the human changed', () => {
    it('shows the proposal and the final value side by side', () => {
        const wrapper = render();
        const change = wrapper.get('[data-slot="change"]');

        expect(change.get('[data-slot="proposed-value"]').text()).toBe(
            '2026-09-12',
        );
        expect(change.get('[data-slot="final-value"]').text()).toBe(
            '2026-09-14',
        );
    });

    it('carries who changed it, when, and which page it came from', () => {
        const row = render().get('[data-slot="edit-row"]');

        expect(row.text()).toContain('Heather Nguyen');
        expect(row.text()).toContain('page 4');
        expect(row.text()).toContain('contract-v3');
    });

    it('draws the review state from the row rather than a literal', () => {
        // `edited` is `info` in lib/states.ts, and the label is the table's.
        expect(render().get('[data-slot="edit-row"]').text()).toContain(
            'Edited',
        );
    });

    it('says what it is not showing when the list is capped', () => {
        const wrapper = render({ editsTotal: 240 });

        expect(wrapper.get('[data-slot="edits-cap"]').text()).toContain(
            'most recent of 240 edits',
        );
    });

    it('says what belongs here when nobody has edited anything', () => {
        const wrapper = render({ edits: [], editsTotal: 0 });

        expect(wrapper.find('[data-slot="edit-row"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Nobody has edited a proposal yet');
    });
});

describe('critical dates missed is never fabricated', () => {
    it('keeps the target on the screen', () => {
        const row = targetRow(render(), 'Critical dates missed');

        expect(row.text()).toContain('Target: Zero');
    });

    it('reports no figure at all, rather than a zero it cannot justify', () => {
        const row = targetRow(render(), 'Critical dates missed');

        expect(row.find('[data-slot="no-figure"]').exists()).toBe(true);
        /*
         * The specific failure this file was written about. A `0` here is the
         * kind of number somebody builds a wrong assumption on, and it would
         * be `0` for a perfect model and for a useless one alike.
         */
        expect(row.text()).not.toMatch(/\b0\b/);
        expect(row.text()).toContain('Not measured here');
    });

    it('sends the question somewhere it can actually be answered', () => {
        const row = targetRow(render(), 'Critical dates missed');

        expect(row.text()).toContain('php artisan extraction:score');
        expect(row.text()).toContain('a missed date leaves no record');
    });
});

describe('a cost is never rendered as a bare zero', () => {
    it('shows nothing where a row was never priced', () => {
        const wrapper = render({
            attempts: [
                {
                    ...props().attempts[0],
                    // `blocked` by the monthly cap: no provider was called, so
                    // there is no cost — which is a different claim from free.
                    state: 'blocked',
                    cost: null,
                    proposals: 0,
                },
            ],
        });

        const attempt = wrapper.get('[data-slot="attempt-row"]');

        expect(attempt.text()).not.toContain('$');
        expect(attempt.text()).toContain('Stopped');
    });

    it('shows nothing for a version whose runs were never priced', () => {
        const wrapper = render({
            versions: [{ ...props().versions[0], cost: null }],
        });

        expect(wrapper.get('[data-slot="version-row"]').text()).not.toContain(
            '$',
        );
    });

    it('says so rather than showing 0% when nothing has been reviewed', () => {
        const wrapper = render({
            scorecard: scorecard({
                confirmedWithoutEdit: {
                    percent: null,
                    confirmed: 0,
                    edited: 0,
                    rejected: 0,
                    reviewed: 0,
                    pending: 0,
                    targetPercent: 85,
                    meetsTarget: null,
                },
            }),
        } as Partial<Props>);

        const row = targetRow(wrapper, 'Dates confirmed without edit');

        expect(row.text()).not.toContain('0%');
        expect(row.text()).toContain(
            'No date proposals have been reviewed yet',
        );
    });
});

describe('the spend ceiling', () => {
    it('says the month is UTC, because every other date on the screen is not', () => {
        expect(render().text()).toContain('counted in UTC');
    });

    it('draws no bar and claims no ceiling when the team has none', () => {
        const wrapper = render({
            spend: {
                monthToDate: '$5.60',
                cap: null,
                percent: null,
                warnAtPercent: 80,
                resetsAt: '2026-09-01T00:00:00+00:00',
            },
        });

        expect(wrapper.find('[role="img"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('No monthly ceiling is set');
    });
});

/*
 * Each control asserts the *same selector* fires on a fixture where the thing
 * being looked for is present. Without them, a renamed `data-slot`, an empty
 * fixture, or a component that failed to mount would make every absence above
 * pass over a screen doing exactly the wrong thing.
 */
describe('the positive controls', () => {
    it('renders a figure for the two targets that have one', () => {
        const wrapper = render();

        expect(
            targetRow(wrapper, 'Dates confirmed without edit')
                .find('[data-slot="no-figure"]')
                .exists(),
        ).toBe(false);
        expect(
            targetRow(wrapper, 'Cost per deal')
                .find('[data-slot="no-figure"]')
                .exists(),
        ).toBe(false);
    });

    it('renders a cost where the row was priced', () => {
        expect(render().get('[data-slot="attempt-row"]').text()).toContain(
            '$1.10',
        );
        expect(render().get('[data-slot="version-row"]').text()).toContain(
            '$3.20',
        );
    });

    it('would see a fabricated figure in the critical-dates row', () => {
        /*
         * The row's absent value is hard-coded in the component, which is the
         * point — so the control proves the *reading* rather than the fixture:
         * the same query over a row that does carry a figure finds one. If
         * `[data-slot="no-figure"]` ever stopped matching, this is the
         * assertion that would still be true and the one above would not.
         */
        const row = targetRow(render(), 'Cost per deal');

        expect(row.text()).toMatch(/\$\d/);
    });

    it('mounts something with rows in it at all', () => {
        const wrapper = render();

        expect(wrapper.findAll('[data-slot="target-row"]')).toHaveLength(3);
        expect(wrapper.findAll('[data-slot="attempt-row"]')).toHaveLength(1);
        expect(wrapper.findAll('[data-slot="edit-row"]')).toHaveLength(1);
    });
});
