<?php

declare(strict_types=1);

namespace App\Http\Requests\Deals;

use App\Models\Workflow;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Advancing a workflow (F4.8 · issue #75).
 *
 * `advance` and not `update`: IA §7 and `WorkflowPolicy` keep the three verbs
 * apart because they have different permissions. An assistant advances stages
 * all day and must not thereby be able to override a gate.
 */
class AdvanceWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workflow = $this->route('workflow');

        return $workflow instanceof Workflow
            && ($this->user()?->can('advance', $workflow) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * The stage the screen believed was current.
             *
             * Optional, because a caller that genuinely means "advance
             * whatever is current" is legitimate — but every screen sends it,
             * and `AdvanceWorkflow` refuses in its own words when it no longer
             * matches.
             *
             * Deliberately **not** validated against this workflow's stages: a
             * stale id is the whole point, and an `exists` rule would turn a
             * considered refusal — *"somebody else advanced this while you
             * were looking at it"* — into a 422 with a worse message.
             */
            'expected_stage_id' => ['nullable', 'string'],
        ];
    }

    public function expectedStageId(): ?string
    {
        $id = $this->validated('expected_stage_id');

        return is_string($id) && $id !== '' ? $id : null;
    }
}
