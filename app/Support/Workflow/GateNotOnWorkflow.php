<?php

declare(strict_types=1);

namespace App\Support\Workflow;

use App\Models\Gate;
use App\Models\Workflow;
use RuntimeException;

/**
 * An override named a gate that is not on the workflow it was aimed at.
 *
 * A programming error rather than a refusal, the way `NothingToAdvance` is. No
 * screen can produce it: S23 lists the gates on the stage it is showing and
 * posts one of those ids back. A different id means a request nobody rendered,
 * and answering it with a polite sentence would be answering it.
 *
 * The tenancy layers are not what is being enforced here — the global scope
 * has already refused another team's gate. This is the second question, the
 * one only the nesting can answer: *whose workflow*. Two deals in the same
 * team is the ordinary case, and neither `team_id` nor a policy separates
 * them.
 *
 * The message carries ids, never labels: a gate's label is a team's process
 * written down, and PRD §9 keeps those out of logs.
 */
final class GateNotOnWorkflow extends RuntimeException
{
    public static function for(Gate $gate, Workflow $workflow): self
    {
        return new self(
            "Gate [{$gate->getKey()}] is not on any stage of workflow [{$workflow->getKey()}].",
        );
    }
}
