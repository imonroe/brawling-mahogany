<?php

declare(strict_types=1);

namespace App\Http\Requests\Deals;

use App\Models\Deal;
use App\Models\Workflow;
use App\Support\Tenancy\TeamContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Attach a workflow to a live deal (S28 · PRD F4.7 · issue #74).
 */
class AttachWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        $deal = $this->route('deal');

        return $deal instanceof Deal
            && ($this->user()?->can('create', [Workflow::class, $deal]) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * Scoped to what this team may see — its own templates and the
             * system ones from installed packs. `InstantiateWorkflow` refuses
             * a foreign template itself (#66), which is what holds for every
             * other caller; this turns the same refusal into a named 422
             * instead of an exception.
             *
             * `is_active`, because an inactive template is one a team has
             * taken out of circulation, and attaching from it would be S76's
             * archived-deal-type problem one layer over.
             */
            'workflow_template_id' => [
                'required', 'string',
                Rule::exists('workflow_templates', 'id')->where(
                    fn ($query) => $query
                        ->where('is_active', true)
                        ->whereNull('deleted_at')
                        ->where(fn ($inner) => $inner
                            ->whereNull('team_id')
                            ->orWhere('team_id', app(TeamContext::class)->requireId(Deal::class))),
                ),
            ],

            /*
             * When the process starts, which is not always today: the *Under
             * Contract* workflow is attached the day an offer is accepted and
             * its dates run from then. Optional, and `InstantiateWorkflow`
             * defaults to now.
             */
            'starting_on' => ['nullable', 'date'],
        ];
    }
}
