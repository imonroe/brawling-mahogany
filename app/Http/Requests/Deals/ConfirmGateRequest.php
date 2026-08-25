<?php

declare(strict_types=1);

namespace App\Http\Requests\Deals;

use App\Models\Gate;
use App\Models\Workflow;
use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Ticking a manual gate (PRD §4.4 F4.8 · S23).
 *
 * `advance`, not `override`. IA §7 keeps the verbs apart because they carry
 * different permissions, and this is the one that is ordinary: an assistant
 * who advances stages all day is exactly the person who confirms the survey
 * came back. Requiring `workflow.override` for it would have been the
 * original hole worn as a permission.
 */
class ConfirmGateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->workflow() instanceof Workflow
            && ($this->user()?->can('advance', $this->workflow()) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * Held to the gates on *this* workflow, the same way
             * `OverrideGateRequest` is and for the same reason: the global
             * scope answers "whose team", the policy answers "may this
             * person", and only the relationship answers "whose workflow".
             * `AdvanceWorkflow::confirm()` asks again — a rule that lives only
             * in the request is a rule the next caller is written without.
             */
            'gate_id' => ['required', 'string', $this->onThisWorkflow()],
        ];
    }

    public function gate(): Gate
    {
        /** @var Gate */
        return Gate::query()->findOrFail($this->validated('gate_id'));
    }

    public function workflow(): ?Workflow
    {
        $workflow = $this->route('workflow');

        return $workflow instanceof Workflow ? $workflow : null;
    }

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
