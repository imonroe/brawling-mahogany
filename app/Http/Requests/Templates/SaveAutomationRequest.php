<?php

declare(strict_types=1);

namespace App\Http\Requests\Templates;

use App\Enums\AutomationActionType;
use App\Enums\AutomationTrigger;
use App\Models\GateTemplate;
use App\Models\MessageTemplate;
use App\Models\StageTemplate;
use App\Models\WorkflowTemplate;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * S44's form, and the reason the Screen Inventory calls it hard.
 *
 * > *"Trigger, action, recipient rule, all interdependent."* … Build it as a
 * > progressive form that narrows, **not four independent dropdowns that can
 * > be combined into nonsense.**
 *
 * The editor narrows; this is what makes *"invalid combinations are impossible
 * to save"* true rather than merely likely. Every rule below is a combination
 * the four dropdowns could otherwise produce:
 *
 *  - A **template on an action that sends nothing.** "Create a task" storing a
 *    pointer to an email nobody ever reads.
 *  - A **template on the wrong channel.** An email action pointed at a push
 *    template — an HTML body on a lock screen. Refused here *and* on the model
 *    (`ActionDefinition::booted()`), because #92's instantiation is a second
 *    caller this request never sees.
 *  - A **template belonging to another team**, or an archived one.
 *  - A **gate the stage does not have.** `gate_cleared` naming a requirement
 *    from a different stage template is an automation that never fires, and
 *    nothing anywhere would say so.
 *  - **Two humans in the loop.** F5.4's manual prompt and F5.7's approval are
 *    the same moment from two ends, so `executionMode` is one choice with
 *    three values rather than two booleans with four states. The database
 *    carries the same invariant as a CHECK constraint.
 */
class SaveAutomationRequest extends FormRequest
{
    public function authorize(): bool
    {
        /*
         * Against the **workflow template**, not the stage.
         *
         * The rule `routes/web.php` states for every nested template route: a
         * guard on the parent with a door beside it is not a guard, and a
         * pack's template must be uneditable all the way down.
         */
        $template = $this->route('template');

        return $template instanceof WorkflowTemplate
            && ($this->user()?->can('update', $template) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $action = $this->chosenAction();

        return [
            'trigger' => ['required', Rule::in(array_keys(AutomationTrigger::selectableOptions()))],
            'action_type' => ['required', Rule::in(array_keys(AutomationActionType::selectableOptions()))],

            'message_template_id' => $action?->needsMessageTemplate() === true
                ? ['required', 'string', $this->usableTemplate($action)]
                : ['prohibited'],

            'executionMode' => ['required', Rule::in($this->modesFor($action))],

            'config' => ['nullable', 'array'],
            'config.gateTemplateId' => [
                Rule::requiredIf(fn (): bool => $this->chosenTrigger()?->needsGate() === true),
                Rule::excludeIf(fn (): bool => $this->chosenTrigger()?->needsGate() !== true),
                'string',
                $this->gateOnThisStage(),
            ],
            'config.taskTitle' => [
                Rule::requiredIf(fn (): bool => $action === AutomationActionType::CreateTask),
                Rule::excludeIf(fn (): bool => $action !== AutomationActionType::CreateTask),
                'string',
                'max:200',
            ],
            'config.taskDueOffsetDays' => ['nullable', 'integer', 'between:-365,365'],
            'config.instruction' => [
                Rule::requiredIf(fn (): bool => $action === AutomationActionType::ManualPrompt),
                Rule::excludeIf(fn (): bool => $action !== AutomationActionType::ManualPrompt),
                'string',
                'max:500',
            ],

            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message_template_id.prohibited' => 'This kind of automation does not send a message, so it has no template.',
            'config.gateTemplateId.required' => 'Choose which requirement clearing should start this.',
            'config.taskTitle.required' => 'Give the task a title.',
            'config.instruction.required' => 'Say what somebody should do.',
        ];
    }

    /**
     * The three-way choice, narrowed to what this action can actually offer.
     *
     * *Approval* only means something for an action that **sends** — F5.7 is
     * about releasing a queued message, and there is no meaningful sense in
     * which a created task is approved. *Manual* is the only mode a manual
     * prompt has, by definition.
     *
     * @return list<string>
     */
    private function modesFor(?AutomationActionType $action): array
    {
        if ($action?->isManual() === true) {
            return ['manual'];
        }

        return $action?->needsMessageTemplate() === true
            ? ['automatic', 'approval', 'manual']
            : ['automatic', 'manual'];
    }

    /**
     * A live template of this team, on the channel this action sends.
     *
     * Written out rather than `Rule::exists`, because three separate things
     * have to hold and `exists` can only say the row is there. The global
     * scope answers the team half; the other two are asked here so the message
     * names which one failed.
     */
    private function usableTemplate(AutomationActionType $action): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($action): void {
            if (! is_string($value)) {
                return;
            }

            $template = MessageTemplate::query()->whereKey($value)->first();

            if (! $template instanceof MessageTemplate) {
                $fail('That template does not exist.');

                return;
            }

            if ($template->isArchived()) {
                $fail('That template is archived. Restore it, or choose another.');

                return;
            }

            if ($template->channel !== $action->channel()) {
                $fail(sprintf(
                    'That is a %s template, and this automation sends %s.',
                    $template->channel->label(),
                    $action->channel()?->label() ?? 'nothing',
                ));
            }
        };
    }

    /**
     * The requirement has to be on **this** stage template.
     *
     * A `gate_cleared` automation naming a gate from another stage is an
     * automation that can never fire — and, worse, from another team's
     * template it would be a cross-tenant pointer that no foreign key refuses,
     * because `gate_templates` carries no `team_id` of its own.
     */
    private function gateOnThisStage(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $stage = $this->route('stageTemplate');

            if (! $stage instanceof StageTemplate || ! is_string($value)) {
                return;
            }

            $exists = GateTemplate::query()
                ->whereKey($value)
                ->where('stage_template_id', $stage->getKey())
                ->exists();

            if (! $exists) {
                $fail('That requirement is not on this stage.');
            }
        };
    }

    private function chosenAction(): ?AutomationActionType
    {
        $value = $this->input('action_type');

        return is_string($value) ? AutomationActionType::tryFrom($value) : null;
    }

    private function chosenTrigger(): ?AutomationTrigger
    {
        $value = $this->input('trigger');

        return is_string($value) ? AutomationTrigger::tryFrom($value) : null;
    }
}
