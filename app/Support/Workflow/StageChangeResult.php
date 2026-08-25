<?php

declare(strict_types=1);

namespace App\Support\Workflow;

use App\Models\Stage;

/**
 * What a skip or a reopen produced (PRD §4.4 F4.12 · issue #70).
 *
 * One shape for both, unlike `AdvanceResult` and `OverrideResult`, because
 * both answer the same two questions: which stage changed, and which stage the
 * workflow is on now. A skip of the current stage moves the pointer forward; a
 * reopen moves it back; a skip of a future stage moves it nowhere. `current`
 * is what the screen re-renders around in all three cases.
 *
 * Refusal is an ordinary outcome here for the same reason it is on the other
 * two: "somebody advanced past that stage while your modal was open" is a
 * sentence, not a stack trace.
 */
final readonly class StageChangeResult
{
    private function __construct(
        public bool $changed,
        public ?Stage $stage = null,
        public ?Stage $current = null,
        public bool $workflowCompleted = false,
        public ?string $refusal = null,
    ) {}

    public static function applied(
        Stage $stage,
        ?Stage $current,
        bool $workflowCompleted = false,
    ): self {
        return new self(
            changed: true,
            stage: $stage,
            current: $current,
            workflowCompleted: $workflowCompleted,
        );
    }

    public static function refused(string $explanation): self
    {
        return new self(changed: false, refusal: $explanation);
    }
}
