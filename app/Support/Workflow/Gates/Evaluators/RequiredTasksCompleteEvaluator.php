<?php

declare(strict_types=1);

namespace App\Support\Workflow\Gates\Evaluators;

use App\Models\Gate;
use App\Support\Workflow\Gates\GateEvaluator;
use App\Support\Workflow\Gates\GateVerdict;

/**
 * Every task on this stage flagged required is done (issue #67, #71).
 *
 * The count is in the sentence deliberately. "Required tasks are not complete"
 * sends somebody to the task list to work out which; "3 of 5 required tasks
 * are still open" tells them what they are walking into before they click.
 */
final class RequiredTasksCompleteEvaluator implements GateEvaluator
{
    public static function type(): string
    {
        return 'required_tasks_complete';
    }

    public function evaluate(Gate $gate): GateVerdict
    {
        $stage = $gate->stage;

        $required = $stage->tasks()->required()->get();

        if ($required->isEmpty()) {
            // Not a failure. A stage with no required tasks has satisfied the
            // condition trivially, and refusing here would make the gate
            // impossible to clear rather than already clear.
            return GateVerdict::met('No tasks on this stage are required.');
        }

        $open = $required->filter(fn ($task): bool => ! $task->isComplete());

        if ($open->isEmpty()) {
            return GateVerdict::met("All {$required->count()} required tasks are done.");
        }

        return GateVerdict::unmet(
            $open->count().' of '.$required->count().' required tasks are still open.',
            ['type' => 'tasks', 'stage' => $stage->getKey()],
        );
    }
}
