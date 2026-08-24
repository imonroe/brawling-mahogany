<?php

declare(strict_types=1);

namespace App\Http\Requests\Deals;

use App\Models\Gate;
use App\Models\Workflow;
use App\Support\Workflow\AdvanceWorkflow;
use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Overriding a gate (PRD §4.4 F4.9 · S24 · issue #69).
 *
 * `override` and not `advance`: IA §7 and `WorkflowPolicy` keep the three
 * verbs apart because they carry different permissions, and #69 is explicit
 * that this one is *"gated on a distinct permission (`workflow.override`), not
 * on being a Team Owner"*. An assistant advances stages all day and must not
 * thereby be able to decide the survey was not needed.
 */
class OverrideGateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->workflow() instanceof Workflow
            && ($this->user()?->can('override', $this->workflow()) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * Held to the gates on *this* workflow, not merely to a gate that
             * exists.
             *
             * The global scope answers "whose team" and the policy answers
             * "may this person override" — and two deals in the same team pass
             * both while belonging to different afternoons. Only the
             * relationship answers "whose workflow".
             *
             * `AdvanceWorkflow::override()` throws `GateNotOnWorkflow` on the
             * same question, and the duplication is deliberate: a rule that
             * lives only in the request is a rule the next caller — a queue
             * job, a native client (F12.5) — is written without. This one
             * exists so a forged id is a readable 422 rather than a 500.
             */
            'gate_id' => ['required', 'string', $this->onThisWorkflow()],
            /*
             * The typed reason, which is the entire point of the feature.
             *
             * The floor comes from `AdvanceWorkflow::MINIMUM_REASON_LENGTH`,
             * so the sentence a person is shown and the check that actually
             * holds cannot drift apart.
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
            'reason.required' => 'An override is permanent and needs a reason. '
                .'Say what you know that the gate does not.',
            'reason.min' => 'A few more words, please — this is what somebody reads in six weeks’ time.',
        ];
    }

    public function gate(): Gate
    {
        /** @var Gate */
        return Gate::query()->findOrFail($this->validated('gate_id'));
    }

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }

    public function workflow(): ?Workflow
    {
        $workflow = $this->route('workflow');

        return $workflow instanceof Workflow ? $workflow : null;
    }

    /**
     * Every query here is an ordinary scoped one, so the tenancy layers hold
     * as they do everywhere else — this asks the extra question they cannot.
     */
    private function onThisWorkflow(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $workflow = $this->workflow();

            $found = $workflow instanceof Workflow
                && is_string($value)
                && Gate::query()
                    ->whereKey($value)
                    ->whereHas(
                        'stage',
                        fn (Builder $query): Builder => $query->where('workflow_id', $workflow->getKey()),
                    )
                    ->exists();

            if (! $found) {
                $fail('That requirement is not on this workflow. Reload the deal and try again.');
            }
        };
    }
}
