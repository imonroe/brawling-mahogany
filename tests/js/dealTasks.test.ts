/**
 * S17, as assertions (#71).
 *
 * Four of this screen's decisions live in computeds rather than on the server,
 * and each is the kind a later change breaks quietly:
 *
 * - **Open is the default view**, and a group with nothing open leaves the
 *   page rather than sitting there as a header over nothing.
 * - **The meta line** carries §7.3's completion attribution, and before it the
 *   two facts a reader needs first: whether the task is required, and whether
 *   anybody owns it.
 * - **Ticking posts to the completion sub-resource**, and unticking deletes
 *   it — never a PATCH on the task, which is what an edit is.
 * - **`deals.manage` is what makes any of it writable.** PRD §4.2 F2.2's Read
 *   Only role reads the checklist and cannot tick it.
 *
 * Mounted rather than asserted on the server for the reason
 * `dealsIndexEmptyState.test.ts` gives: a feature test can only see what the
 * payload holds, and every one of these is a decision taken after it arrives.
 */
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import type { DealHeaderProps } from '@/components/app/DealHeader.vue';

const routerPost = vi.fn();
const routerDelete = vi.fn();
const permissions = { value: ['deals.view', 'deals.manage'] as string[] };

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: () => () => null }),
    router: { post: routerPost, delete: routerDelete, on: vi.fn() },
    Link: defineComponent({
        props: { href: { type: String, default: '' } },
        setup:
            (props, { slots }) =>
            () =>
                h('a', { href: props.href }, slots.default?.()),
    }),
    usePage: () => ({ props: { auth: { permissions: permissions.value } } }),
    useForm: () => ({}),
}));

const Tasks = (await import('@/pages/Deals/Tasks.vue')).default;

const dealHeader: DealHeaderProps = {
    id: 'deal-1',
    name: '123 Main St',
    state: 'active',
    dealTypeName: 'Listing',
    sideLabel: 'Sell',
    clientName: 'Emily Bosart',
    location: null,
    counts: { people: 1, properties: 1, tasks: 2 },
    advance: null,
};

type TaskRow = {
    id: string;
    title: string;
    description: string | null;
    stageId: string | null;
    state: string;
    isRequired: boolean;
    dueDate: string | null;
    completedAt: string | null;
    completedByName: string | null;
    assigneeId: string | null;
    assigneeName: string | null;
    source: string;
    sourceLabel: string;
};

function task(overrides: Partial<TaskRow> = {}): TaskRow {
    return {
        id: 'task-1',
        title: 'Order the sign',
        description: null,
        stageId: 'stage-1',
        state: 'open',
        isRequired: false,
        dueDate: null,
        completedAt: null,
        completedByName: null,
        assigneeId: 'person-1',
        assigneeName: 'Heather Nguyen',
        source: 'template',
        sourceLabel: 'From the workflow',
        ...overrides,
    };
}

function group(tasks: TaskRow[], overrides: Record<string, unknown> = {}) {
    return {
        key: 'stage-1',
        stageId: 'stage-1',
        stageName: 'Listing Preparation',
        workflowName: 'Listing to Close',
        isCurrent: true,
        tasks,
        ...overrides,
    };
}

function screen(groups: ReturnType<typeof group>[], counts = {}) {
    const all = groups.flatMap((one) => one.tasks);

    return mount(Tasks, {
        props: {
            dealHeader,
            dealUrl: '/deals/deal-1',
            groups,
            counts: {
                open: all.filter((one) => one.state !== 'completed').length,
                completed: all.filter((one) => one.state === 'completed')
                    .length,
                all: all.length,
                overdue: all.filter((one) => one.state === 'overdue').length,
                unassigned: all.filter(
                    (one) => one.state !== 'completed' && !one.assigneeId,
                ).length,
                ...counts,
            },
            assignees: [{ id: 'person-1', name: 'Heather Nguyen' }],
            stageOptions: [
                {
                    workflowName: 'Listing to Close',
                    stages: [{ id: 'stage-1', name: 'Listing Preparation' }],
                },
            ],
        },
        global: {
            // The form is S27's own component and has its own reasons to
            // exist; this file is about the list around it.
            stubs: { TaskFormDialog: true },
        },
    });
}

/**
 * Switch the local filter, the way a reader does.
 *
 * The default is **Open**, so anything completed is off the page until this is
 * called — which is the behaviour two of the tests below are standing on and
 * the reason they cannot just mount and assert.
 */
async function show(
    wrapper: ReturnType<typeof screen>,
    label: string,
): Promise<void> {
    const tab = wrapper
        .findAll('[role="tab"]')
        .find((one) => one.text().startsWith(label));

    await tab!.trigger('click');
}

describe('Deals/Tasks', () => {
    beforeEach(() => {
        permissions.value = ['deals.view', 'deals.manage'];
        routerPost.mockClear();
        routerDelete.mockClear();
    });

    it('leads with what is open, and drops a group with nothing left in it', () => {
        const wrapper = screen([
            group([task({ id: 'a', title: 'Order the sign' })]),
            group(
                [
                    task({
                        id: 'b',
                        title: 'Book the photographer',
                        state: 'completed',
                    }),
                ],
                { key: 'stage-2', stageId: 'stage-2', stageName: 'Marketing' },
            ),
        ]);

        expect(wrapper.text()).toContain('Order the sign');
        // A stage whose work is finished leaves the page rather than sitting
        // there as a header over nothing.
        expect(wrapper.text()).not.toContain('Book the photographer');
        expect(wrapper.text()).not.toContain('Marketing');
    });

    it('shows the finished work when asked for it', async () => {
        const wrapper = screen([
            group([
                task({ id: 'a', title: 'Order the sign' }),
                task({
                    id: 'b',
                    title: 'Book the photographer',
                    state: 'completed',
                }),
            ]),
        ]);

        await show(wrapper, 'Completed');

        expect(wrapper.text()).toContain('Book the photographer');
        expect(wrapper.text()).not.toContain('Order the sign');
    });

    it('says which kind of empty it is', () => {
        // Nothing at all: usually a deal with no workflow attached, and the
        // fix is a different screen.
        expect(screen([]).text()).toContain('No tasks on this deal yet');

        // Everything done: the reader's own checklist, finished. Telling them
        // there are no tasks would be wrong, and the count on the page knows
        // better.
        const finished = screen([group([task({ state: 'completed' })])]);

        expect(finished.text()).toContain('Nothing open on this deal');
    });

    it('carries §7.3’s meta line: required, unowned, then who finished it', async () => {
        const wrapper = screen([
            group([
                task({
                    id: 'a',
                    title: 'Sign the listing agreement',
                    isRequired: true,
                    assigneeId: null,
                    assigneeName: null,
                }),
            ]),
        ]);

        // #71: unassigned is a visible state, not a silent default.
        expect(wrapper.text()).toContain('Required · Unassigned');

        const done = screen([
            group([
                task({
                    id: 'b',
                    state: 'completed',
                    isRequired: true,
                    completedByName: 'Heather Nguyen',
                }),
            ]),
        ]);

        await show(done, 'Completed');

        expect(done.text()).toContain('Required · Completed by Heather Nguyen');
    });

    it('names the machine when the machine put a task there', () => {
        /*
         * PRD §4.10: nothing a model proposes may read as something a person
         * typed. `manual` and `template` are the ordinary cases and say
         * nothing — labelling the ordinary case is noise on every row.
         */
        const override = screen([
            group([
                task({
                    source: 'override',
                    sourceLabel: 'From an override',
                }),
            ]),
        ]);

        expect(override.text()).toContain('From an override');
        expect(screen([group([task()])]).text()).not.toContain(
            'From the workflow',
        );
    });

    it('completes through the completion sub-resource, and reopens by deleting it', async () => {
        const wrapper = screen([group([task({ id: 'task-9' })])]);

        await wrapper.find('input[type="checkbox"]').setValue(true);

        expect(routerPost).toHaveBeenCalledWith(
            '/deals/deal-1/tasks/task-9/completion',
            {},
            { preserveScroll: true },
        );

        const done = screen([
            group([task({ id: 'task-9', state: 'completed' })]),
        ]);

        await show(done, 'Completed');

        await done.find('input[type="checkbox"]').setValue(false);

        // Never a PATCH on the task: that is what an edit is, and completing
        // is a different act with a different consequence.
        expect(routerDelete).toHaveBeenCalledWith(
            '/deals/deal-1/tasks/task-9/completion',
            { preserveScroll: true },
        );
    });

    it('lets a read-only viewer read the checklist and change nothing', () => {
        permissions.value = ['deals.view'];

        const wrapper = screen([group([task()])]);

        expect(wrapper.text()).toContain('Order the sign');

        // The checkbox stays visible — its *state* is the information — and is
        // disabled. Everything that writes is hidden, per §7.3.
        expect(
            wrapper.find('input[type="checkbox"]').attributes('disabled'),
        ).toBeDefined();
        expect(wrapper.text()).not.toContain('Add task');
        expect(wrapper.find('[aria-label="Edit task"]').exists()).toBe(false);
        expect(wrapper.find('[aria-label="Delete task"]').exists()).toBe(false);
    });

    it('shows the two counts that need chasing, and only when there are any', () => {
        const wrapper = screen([
            group([
                task({ id: 'a', state: 'overdue', dueDate: '2026-08-01' }),
                task({ id: 'b', assigneeId: null, assigneeName: null }),
            ]),
        ]);

        expect(wrapper.text()).toContain('1 overdue');
        expect(wrapper.text()).toContain('1 unassigned');

        const clean = screen([group([task()])]);

        expect(clean.text()).not.toContain('overdue');
        expect(clean.text()).not.toContain('unassigned');
    });
});
