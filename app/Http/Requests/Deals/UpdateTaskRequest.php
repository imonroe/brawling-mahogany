<?php

declare(strict_types=1);

namespace App\Http\Requests\Deals;

use App\Models\Deal;
use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;

/**
 * S27, editing (PRD §4.4 F4.10 · issue #71).
 *
 * Completing a task is **not** here: it has its own route and its own method
 * on `DealTasks`, because it is a different act with a different consequence —
 * it writes an activity event, and it can clear the gate that is holding a
 * stage. A boolean inside an edit would make "I fixed a typo in the title" and
 * "the work is done" the same request.
 */
class UpdateTaskRequest extends FormRequest
{
    use ResolvesTaskFields;

    public function authorize(): bool
    {
        $task = $this->route('task');

        return $task instanceof Task
            && ($this->user()?->can('update', $task) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Deal $deal */
        $deal = $this->route('deal');
        $task = $this->route('task');

        /*
         * The incumbent assignee stays valid, however their membership has
         * changed. See `ResolvesTaskFields::taskRules()` — a task assigned to
         * a revoked colleague was otherwise uneditable.
         */
        return $this->taskRules(
            $deal,
            $task instanceof Task ? $task->assignee_id : null,
        );
    }

    /**
     * The columns the service fills — **by presence, not by value**.
     *
     * `stage_id` is deliberately absent: it is applied by association, from a
     * `Stage` the request resolved through the deal.
     *
     * The presence rule is the fix for a real defect rather than a nicety.
     * The first version built this array unconditionally, so an edit that did
     * not mention `is_required` — a rename from a future screen, a partial
     * PATCH from Slice 5 — read the absent checkbox as **false** and quietly
     * cleared the flag. That is not a lost field: `is_required` is what a
     * `required_tasks_complete` gate counts, so renaming a task would have
     * unblocked the stage it was holding, with nothing on any screen to say
     * so. `description`, `assignee_id` and `due_date` had the same shape with
     * a smaller blast radius.
     *
     * S27's own form always sends all five, so nothing about this modal
     * changes. What changes is what happens when something else posts here.
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        $changes = [];

        foreach (['title', 'description', 'assignee_id', 'due_date'] as $field) {
            if ($this->has($field)) {
                $changes[$field] = $this->validated($field);
            }
        }

        /*
         * The checkbox, and the reason presence is a safe test for it here.
         *
         * An unticked checkbox in an HTML form sends nothing at all, which
         * would make absence ambiguous — unticked, or never asked? Inertia's
         * `useForm` posts every declared field as JSON, so S27's unticked box
         * arrives as `is_required: false` rather than as a hole. Presence
         * therefore means *this sender had the field*, which is exactly the
         * question. A future form built the plain-HTML way would need to say
         * so; nothing in this codebase is built that way.
         */
        if ($this->has('is_required')) {
            $changes['is_required'] = $this->boolean('is_required');
        }

        return $changes;
    }

    /**
     * Whether the stage was part of what this edit said.
     *
     * An edit that never mentions `stage_id` leaves the task where it is.
     * Without this, a partial update from anywhere but S27's own form — a
     * later screen, or Slice 5 — would silently move a template task off the
     * stage its workflow put it on, which is the checklist it belongs to.
     */
    public function movesStage(): bool
    {
        return $this->has('stage_id');
    }
}
