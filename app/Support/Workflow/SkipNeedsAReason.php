<?php

declare(strict_types=1);

namespace App\Support\Workflow;

use RuntimeException;

/**
 * A skip arrived without a reason worth writing down (F4.12 · issue #70).
 *
 * Thrown rather than refused, for the reason `OverrideNeedsAReason` is: the
 * requirement is not that the product *asks*, it is that skipping without a
 * reason is impossible at the service layer as well as in the form.
 *
 * IA §7 calls the Override/Skip distinction legally material, and the reason
 * is what carries it. **"Cash purchase, no financing contingency"** is a
 * record of why a stage did not apply to this deal; an empty column six weeks
 * later is indistinguishable from somebody clicking past a stage they did not
 * want to do. PRD §12.2 measures overrides precisely so that process failures
 * can be told apart from deals that genuinely differ — which only works if
 * the skips are documented too.
 *
 * The message never carries the reason. A typed reason is a sentence about
 * somebody's transaction.
 */
final class SkipNeedsAReason extends RuntimeException
{
    public static function atLeast(int $characters): self
    {
        return new self(
            "Skipping a stage needs a typed reason of at least {$characters} characters (PRD F4.12).",
        );
    }
}
