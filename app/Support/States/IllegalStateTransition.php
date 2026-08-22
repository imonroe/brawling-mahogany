<?php

declare(strict_types=1);

namespace App\Support\States;

use RuntimeException;

/**
 * A record was asked to move to a state it could not reach from where it is.
 *
 * Thrown rather than ignored, and thrown rather than corrected. A deal that
 * silently stays `active` after something asked it to become `nurture` is a
 * deal whose screen and database disagree, and the disagreement surfaces weeks
 * later as "why is this still in my pipeline".
 *
 * The message names the model and both states and nothing else. A deal's name
 * is a client's street address (IA §10), which PRD §9 keeps out of logs — and
 * an exception message is a log entry by the time anybody reads it.
 */
final class IllegalStateTransition extends RuntimeException
{
    public static function between(string $model, string $from, string $to): self
    {
        return new self("[{$model}] cannot move from [{$from}] to [{$to}].");
    }
}
