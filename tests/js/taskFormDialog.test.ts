/**
 * S27, as assertions (#71).
 *
 * The list around it is covered by `dealTasks.test.ts`, which stubs this
 * component out — so without this file the modal that writes every task had no
 * test at all. Review on #71 said so.
 *
 * Three of its decisions are the kind that break quietly:
 *
 * - **It is one form for two verbs**, and reopening it on a second task must
 *   not show the first one's values — `is_required` most of all, because a
 *   stale one decides whether a stage can advance.
 * - **A departed assignee stays selectable.** The server's list is live
 *   colleagues only; a select holding a value with no matching option renders
 *   blank, which reads as Unassigned and is the opposite of the truth.
 * - **Add posts, Edit patches**, to different URLs.
 */
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { TaskFormValues } from '@/components/app/TaskFormDialog.vue';

const post = vi.fn();
const patch = vi.fn();

/**
 * A stand-in for Inertia's `useForm`: a plain reactive bag with the two verbs.
 *
 * Mocked rather than driven for real, because what this file is about is which
 * values land in the bag and which endpoint it is sent to — not Inertia's
 * serialisation, which is Inertia's test to write.
 */
const form: Record<string, unknown> = {};

vi.mock('@inertiajs/vue3', () => ({
    useForm: (initial: Record<string, unknown>) => {
        Object.assign(form, initial, {
            post,
            patch,
            processing: false,
            errors: {},
            clearErrors: () => {},
            reset: () => {},
        });

        return form;
    },
}));

const TaskFormDialog = (await import('@/components/app/TaskFormDialog.vue'))
    .default;

function task(overrides: Partial<TaskFormValues> = {}): TaskFormValues {
    return {
        id: 'task-1',
        title: 'Order the sign',
        description: 'Rider too.',
        stageId: 'stage-1',
        assigneeId: 'person-1',
        assigneeName: 'Heather Nguyen',
        dueDate: '2026-09-01T00:00:00+00:00',
        isRequired: true,
        ...overrides,
    };
}

function dialog(props: Partial<Record<string, unknown>> = {}) {
    return mount(TaskFormDialog, {
        props: {
            open: true,
            dealId: 'deal-1',
            task: null,
            assignees: [{ id: 'person-1', name: 'Heather Nguyen' }],
            stageOptions: [
                {
                    workflowId: 'workflow-1',
                    workflowName: 'Listing to Close',
                    stages: [{ id: 'stage-1', name: 'Listing Preparation' }],
                },
            ],
            ...props,
        },
        global: {
            // Radix's dialog teleports and needs a DOM root; the form itself is
            // what this file asserts on, so the chrome is stubbed to a wrapper
            // that renders its slot in place.
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

describe('TaskFormDialog', () => {
    beforeEach(() => {
        post.mockClear();
        patch.mockClear();

        for (const key of Object.keys(form)) {
            delete form[key];
        }
    });

    it('fills itself from the task it was opened on', () => {
        dialog({ task: task() });

        expect(form.title).toBe('Order the sign');
        expect(form.is_required).toBe(true);
        expect(form.assignee_id).toBe('person-1');
        expect(form.stage_id).toBe('stage-1');
        // The day half of an ISO 8601 value, sliced rather than reformatted:
        // the column is a `date`, so the first ten characters *are* the value,
        // and putting it through a formatter would put it through a timezone.
        expect(form.due_date).toBe('2026-09-01');
    });

    it('does not carry one task’s values onto the next', async () => {
        const wrapper = dialog({ task: task() });

        await wrapper.setProps({
            task: task({
                id: 'task-2',
                title: 'Book the photographer',
                isRequired: false,
                assigneeId: null,
                assigneeName: null,
                dueDate: null,
            }),
        });

        expect(form.title).toBe('Book the photographer');
        // The one that matters: a stale `true` here decides whether a stage
        // can advance.
        expect(form.is_required).toBe(false);
        expect(form.assignee_id).toBe('');
        expect(form.due_date).toBe('');
    });

    it('preselects the stage the Add button sat in', () => {
        dialog({ task: null, defaultStageId: 'stage-1' });

        expect(form.stage_id).toBe('stage-1');
        expect(form.title).toBe('');
    });

    it('keeps a departed assignee selectable, and says they have gone', () => {
        /*
         * The server's list is live colleagues; this task names somebody who
         * has since been revoked. A select with no option for the value it
         * holds renders blank — which reads as Unassigned, and would silently
         * reassign the work on the next save.
         */
        const wrapper = dialog({
            task: task({
                assigneeId: 'person-gone',
                assigneeName: 'Gone Away',
            }),
        });

        const options = wrapper.findAll('#task_assignee option');

        expect(options.map((option) => option.attributes('value'))).toContain(
            'person-gone',
        );
        expect(wrapper.find('#task_assignee').text()).toContain(
            'no longer on the team',
        );
    });

    it('posts a new task and patches an existing one', async () => {
        const adding = dialog({ task: null });

        await adding.find('form').trigger('submit');

        expect(post).toHaveBeenCalledWith(
            '/deals/deal-1/tasks',
            expect.anything(),
        );
        expect(patch).not.toHaveBeenCalled();

        const editing = dialog({ task: task({ id: 'task-9' }) });

        await editing.find('form').trigger('submit');

        expect(patch).toHaveBeenCalledWith(
            '/deals/deal-1/tasks/task-9',
            expect.anything(),
        );
    });

    it('offers Delete only when the page says this reader may', async () => {
        /*
         * The row hides Delete below `sm` to buy the title back its horizontal
         * budget, so this is where the capability lives on a phone — and it
         * has to ask the same question the row does. `TaskPolicy::delete()`
         * refuses an override's follow-up to somebody without
         * `workflow.override`; a control that appears here and not there is
         * the shape of an eventual 403.
         */
        const allowed = dialog({
            task: task({ id: 'task-4' }),
            canDelete: true,
        });

        expect(allowed.text()).toContain('Delete task');

        await allowed
            .findAll('[data-slot="app-button"]')
            .find((button) => button.text() === 'Delete task')!
            .trigger('click');

        expect(allowed.emitted('delete')).toEqual([['task-4']]);

        expect(dialog({ task: task(), canDelete: false }).text()).not.toContain(
            'Delete task',
        );

        // Nothing to delete yet when the dialog is adding.
        expect(dialog({ task: null, canDelete: true }).text()).not.toContain(
            'Delete task',
        );
    });

    it('says what a required task costs, without overstating it', () => {
        /*
         * The copy used to say getting past a required task "takes an
         * override, which is written to the audit log" — and unticking the box
         * is the other way past, which made the sentence false. Both routes
         * are on the record now, and the copy says which is which.
         */
        const text = dialog({ task: task() }).text();

        expect(text).toContain('recorded on the deal’s activity');
        // Both routes past the gate, because both are recorded — the copy said
        // only one of them for a round, and review found the other twice.
        expect(text).toContain('moving the task to another stage');
        expect(text).toContain('override');
    });
});
