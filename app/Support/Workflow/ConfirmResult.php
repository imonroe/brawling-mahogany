<?php

declare(strict_types=1);

namespace App\Support\Workflow;

use App\Models\Gate;

/**
 * What an attempt to confirm — or unconfirm — a manual gate produced (#71's
 * shape, one gate type over).
 *
 * The same three-way shape as `AdvanceResult` and `OverrideResult`, and for
 * the same reason: "somebody already confirmed that" is an ordinary outcome
 * the modal has to render as a sentence, not a stack trace.
 *
 * **Confirming never advances.** Clearing one of three blocking gates must
 * not move the deal past the other two, which is exactly the argument
 * `override()` records. Advance is a second, deliberate press that
 * re-evaluates every gate under its own lock.
 */
final readonly class ConfirmResult
{
    private function __construct(
        public bool $changed,
        public ?Gate $gate = null,
        public ?string $refusal = null,
    ) {}

    public static function confirmed(Gate $gate): self
    {
        return new self(changed: true, gate: $gate);
    }

    public static function refused(string $explanation): self
    {
        return new self(changed: false, refusal: $explanation);
    }
}
