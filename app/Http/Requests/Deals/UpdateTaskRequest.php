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

        return $this->taskRules($deal);
    }

    /**
     * The columns the service fills, which are not all the fields validated.
     *
     * `stage_id` is deliberately absent: it is applied by association, from a
     * `Stage` the request resolved through the deal.
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        return [
            'title' => $this->validated('title'),
            'description' => $this->validated('description'),
            'assignee_id' => $this->validated('assignee_id'),
            'due_date' => $this->validated('due_date'),
            'is_required' => (bool) $this->boolean('is_required'),
        ];
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
