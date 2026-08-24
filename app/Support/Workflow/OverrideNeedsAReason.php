<?php

declare(strict_types=1);

namespace App\Support\Workflow;

use RuntimeException;

/**
 * An override arrived without a reason worth writing down (F4.9 · issue #69).
 *
 * Thrown rather than refused, because #69's definition of done is not that the
 * product asks for a reason — it is that overriding without one is
 * *impossible*, "in the UI and at the service layer". A refusal is a decision
 * the service made and could later make differently; a throw is the shape of
 * the thing.
 *
 * The floor is a length rather than merely non-empty, and
 * `AdvanceWorkflow::MINIMUM_REASON_LENGTH` is the single place it is written
 * down so the form and the service cannot disagree. PRD §12.2 measures the
 * share of advances that used an override and calls a high rate a sign that
 * *"the gates are wrong, not the users"* — a column full of `"x"` answers that
 * question with noise, which is the same as not asking it.
 *
 * The message never carries the reason itself. A typed reason is a sentence
 * about somebody's transaction.
 */
final class OverrideNeedsAReason extends RuntimeException
{
    public static function atLeast(int $characters): self
    {
        return new self(
            "An override needs a typed reason of at least {$characters} characters (PRD F4.9).",
        );
    }
}
