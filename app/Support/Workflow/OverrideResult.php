<?php

declare(strict_types=1);

namespace App\Support\Workflow;

use App\Models\Gate;
use App\Models\Task;

/**
 * What an attempt to override a gate produced (PRD §4.4 F4.9 · issue #69).
 *
 * The same shape as `AdvanceResult`, and for the same reason: a refused
 * override is an ordinary outcome that S24 has to render as a sentence. "That
 * gate is met now" is something a colleague did while the modal was open, not
 * a bug worth a stack trace.
 *
 * **An override never advances anything.** It clears one gate and stops. The
 * person then presses Advance, which re-evaluates every gate under a lock —
 * which is what makes overriding one of three blockers do the right thing
 * rather than moving a deal past the other two. PRD §5.5 reads as one motion
 * because the modal reopens onto the refreshed checklist, not because the
 * service does two things.
 *
 * `followUp` is not decoration. F4.9's fourth artefact is a task, and #69 is
 * blunt about why it is the one that matters: *"an override defers an
 * obligation; it does not delete one."* Returning it means the confirmation
 * can name the task it just created rather than promising one.
 */
final readonly class OverrideResult
{
    private function __construct(
        public bool $overridden,
        public ?Gate $gate = null,
        public ?Task $followUp = null,
        public ?string $refusal = null,
    ) {}

    public static function overridden(Gate $gate, Task $followUp): self
    {
        return new self(overridden: true, gate: $gate, followUp: $followUp);
    }

    /**
     * Nothing was written, and here is the sentence saying why.
     *
     * Distinct from the exceptions this service throws, which are the cases a
     * screen could not have produced: a gate belonging to another workflow, or
     * an empty reason. Those mean somebody posted something no screen
     * rendered. These mean the world moved.
     */
    public static function refused(string $explanation): self
    {
        return new self(overridden: false, refusal: $explanation);
    }
}
