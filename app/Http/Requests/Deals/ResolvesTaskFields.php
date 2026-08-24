<?php

declare(strict_types=1);

namespace App\Http\Requests\Deals;

use App\Models\Deal;
use App\Models\Stage;
use App\Models\Workflow;
use App\Queries\TaskAssignees;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * The fields S27 writes, shared by the two requests that write them.
 *
 * Add and Edit take the same form and must not validate it differently: a
 * stage that may not be chosen when a task is created must not become
 * choosable by editing the task afterwards. One copy is the only way to say
 * that once.
 */
trait ResolvesTaskFields
{
    /**
     * @return array<string, mixed>
     */
    protected function taskRules(Deal $deal): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],

            /*
             * A stage on **this deal**, named explicitly rather than left to
             * `exists`.
             *
             * `stages` carries `team_id`, so the global scope already refuses
             * another team's — but every deal in one team shares that scope,
             * and a stage id from the deal next door would otherwise attach a
             * task to a checklist on a transaction it has nothing to do with.
             * The same argument `routes/web.php` makes for `scopeBindings()`:
             * the tenancy layers answer "whose team", and only this answers
             * "whose deal".
             */
            'stage_id' => ['nullable', 'string', Rule::in($this->stageIdsFor($deal))],

            /*
             * `people` has no `team_id` (ADR 0002), so this is the only thing
             * standing between the column and another team's person id. See
             * `App\Queries\TaskAssignees`.
             */
            'assignee_id' => ['nullable', 'string', Rule::in(app(TaskAssignees::class)->personIds())],

            /*
             * Any date, including one in the past. A team catching up on a
             * deal that started before they had the software types real dates,
             * and refusing them would mean refusing the truth to keep a
             * validator tidy — the row simply renders Overdue, which is what
             * it is.
             */
            'due_date' => ['nullable', 'date'],

            'is_required' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return list<string>
     */
    private function stageIdsFor(Deal $deal): array
    {
        return array_values($deal->workflows()
            ->with('stages:id,workflow_id')
            ->get()
            ->flatMap(fn (Workflow $workflow): Collection => $workflow->stages->map(
                fn (Stage $stage): string => (string) $stage->getKey(),
            ))
            ->all());
    }

    /**
     * The stage this request names, resolved through the deal.
     *
     * Null is a real answer — PRD §6.4 makes `stage_id` nullable so an ad-hoc
     * job, or one extraction proposes in Slice 5, can sit on the deal outside
     * any stage. The rules above are what guarantee that a non-null one
     * belongs here.
     */
    public function resolveStage(Deal $deal): ?Stage
    {
        $id = $this->validated('stage_id');

        if (! is_string($id) || $id === '') {
            return null;
        }

        return Stage::query()
            ->whereKey($id)
            ->whereIn('workflow_id', $deal->workflows()->pluck('id'))
            ->first();
    }
}
