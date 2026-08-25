<?php

declare(strict_types=1);

namespace App\Support\Workflow;

use App\Models\Stage;
use App\Models\Workflow;
use RuntimeException;

/**
 * A skip or a reopen named a stage that is not on the workflow it was aimed at.
 *
 * `GateNotOnWorkflow`'s sibling, and the same reasoning: a programming error
 * rather than a refusal, because no screen can produce it. S16 draws the
 * stages of the workflow it is showing and posts one of those ids back.
 *
 * The tenancy layers are not what is being enforced here — the global scope
 * has already refused another team's stage. This is the second question, the
 * one only the nesting can answer: *whose workflow*. One deal runs several
 * workflows at once (F4.7), so two stages in the same team, on the same deal,
 * with no `team_id` and no policy between them is the ordinary case.
 *
 * The message carries ids, never names: a stage name is a team's process
 * written down, and PRD §9 keeps those out of logs.
 */
final class StageNotOnWorkflow extends RuntimeException
{
    public static function for(Stage $stage, Workflow $workflow): self
    {
        return new self(
            "Stage {$stage->getKey()} does not belong to workflow {$workflow->getKey()}.",
        );
    }
}
