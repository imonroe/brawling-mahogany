<?php

declare(strict_types=1);

namespace App\Http\Requests\Deals;

use App\Models\Workflow;
use App\Support\Workflow\AdvanceWorkflow;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Marking a stage not applicable (PRD §4.4 F4.12 · S16 · issue #70).
 *
 * `skipStage` and not `advance` or `override`: IA §7 keeps the three verbs
 * apart because they carry different permissions and different audit
 * meanings, and `stage.skip` has been in the catalogue since Slice 1 waiting
 * for this. An assistant who advances stages all day must not thereby be able
 * to decide the appraisal was not part of this sale.
 *
 * The stage itself is a route parameter, resolved through `{workflow}` by
 * scoped binding, so there is no id in the body to hold to anything.
 */
class SkipStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workflow = $this->route('workflow');

        return $workflow instanceof Workflow
            && ($this->user()?->can('skipStage', $workflow) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * The floor comes from `AdvanceWorkflow::MINIMUM_REASON_LENGTH`,
             * the same constant the override uses, so the sentence a person is
             * shown and the check that actually holds cannot drift apart.
             */
            'reason' => ['required', 'string', 'min:'.AdvanceWorkflow::MINIMUM_REASON_LENGTH, 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why this stage does not apply to this deal — '
                .'"cash purchase, no financing contingency" is the kind of thing that reads well later.',
            'reason.min' => 'A few more words, please. A blank reason six weeks from now '
                .'is indistinguishable from somebody clicking past a stage they did not want to do.',
        ];
    }

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }
}
