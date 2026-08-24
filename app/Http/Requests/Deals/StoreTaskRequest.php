<?php

declare(strict_types=1);

namespace App\Http\Requests\Deals;

use App\Models\Deal;
use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;

/**
 * S27, adding (PRD §4.4 F4.10 · issue #71).
 */
class StoreTaskRequest extends FormRequest
{
    use ResolvesTaskFields;

    public function authorize(): bool
    {
        $deal = $this->route('deal');

        return $deal instanceof Deal
            && ($this->user()?->can('create', [Task::class, $deal]) ?? false);
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
     * What the service writes, without the two keys it resolves itself.
     *
     * `stage_id` is handed over as a `Stage` rather than an id, so nothing
     * downstream has to re-ask whether it belongs to this deal.
     *
     * **Not `attributes()`.** `FormRequest::attributes()` is Laravel's own
     * hook for overriding the names the validator puts in its messages, so a
     * method of that name returning a payload feeds this array to the message
     * formatter and silently stops validating what it was supposed to.
     *
     * @return array<string, mixed>
     */
    public function taskAttributes(): array
    {
        return [
            'title' => $this->validated('title'),
            'description' => $this->validated('description'),
            'assignee_id' => $this->validated('assignee_id'),
            'due_date' => $this->validated('due_date'),
            // Absent means false. A checkbox nobody ticked sends nothing at
            // all, and `is_required` decides whether a stage can advance —
            // the safe reading of silence is the one that blocks nothing.
            'is_required' => (bool) $this->boolean('is_required'),
        ];
    }
}
