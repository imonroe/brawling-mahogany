<?php

declare(strict_types=1);

namespace App\Support\Workflow;

use App\Models\Workflow;
use RuntimeException;

/**
 * Advance was called on a workflow with no active stage.
 *
 * A programming error rather than a refusal, which is why it throws where an
 * unmet gate returns a result. A blocked advance is a fact about the world; a
 * workflow with nothing to advance means a screen offered a button it should
 * not have, and swallowing it would hide the bug behind a shrug.
 *
 * The message carries ids and the state, never the deal name — a deal name is
 * a client's street address (IA §10), and PRD §9 keeps those out of logs.
 */
final class NothingToAdvance extends RuntimeException
{
    public static function for(Workflow $workflow): self
    {
        return new self(
            "Workflow [{$workflow->getKey()}] cannot be advanced "
            ."(state: {$workflow->state->value}).",
        );
    }
}
