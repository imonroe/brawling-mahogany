<?php

declare(strict_types=1);

namespace App\Support\Workflow;

use App\Models\Gate;
use App\Models\Stage;
use App\Support\Workflow\Gates\GateVerdict;

/**
 * What an attempt to advance produced (issue #68).
 *
 * A result object rather than an exception on refusal, because a refused
 * advance is an **ordinary outcome** rather than an error. Most of the time a
 * gate is unmet because the survey has not come back, which is a fact about
 * the world, not a bug. S23 renders this directly.
 *
 * A refusal carries **every** unmet blocking gate, never the first. Issue #68
 * is explicit, and the reason is the user's afternoon: told about one gate,
 * somebody clears it, clicks again, and is told about the next. Three round
 * trips to learn what one screen could have said.
 */
final readonly class AdvanceResult
{
    /**
     * @param  array<string, GateVerdict>  $blockedBy  gate id => why
     * @param  array<string, GateVerdict>  $advisories  gate id => why, shown but not enforced
     */
    private function __construct(
        public bool $advanced,
        public ?Stage $completedStage = null,
        public ?Stage $activatedStage = null,
        public bool $workflowCompleted = false,
        public ?string $milestoneAnnouncement = null,
        public array $blockedBy = [],
        public array $advisories = [],
        public ?string $refusal = null,
    ) {}

    /**
     * @param  array<string, GateVerdict>  $advisories
     */
    public static function advanced(
        Stage $completedStage,
        ?Stage $activatedStage,
        bool $workflowCompleted,
        ?string $milestoneAnnouncement,
        array $advisories = [],
    ): self {
        return new self(
            advanced: true,
            completedStage: $completedStage,
            activatedStage: $activatedStage,
            workflowCompleted: $workflowCompleted,
            milestoneAnnouncement: $milestoneAnnouncement,
            advisories: $advisories,
        );
    }

    /**
     * @param  array<string, GateVerdict>  $blockedBy
     * @param  array<string, GateVerdict>  $advisories
     */
    public static function blocked(Stage $stage, array $blockedBy, array $advisories = []): self
    {
        return new self(
            advanced: false,
            completedStage: null,
            activatedStage: $stage,
            blockedBy: $blockedBy,
            advisories: $advisories,
        );
    }

    /**
     * The workflow itself will not move, whatever its gates say.
     *
     * A hold, a cancellation, or a workflow that never started. This is a
     * refusal rather than an exception for the same reason an unmet gate is:
     * S23 has to render it, and *“this workflow is on hold”* is a sentence a
     * person reads, not a stack trace. Throwing would have made the same
     * screen a 500 for a state somebody deliberately put the deal into.
     *
     * No stage is completed and none is marked blocked — the stage is not the
     * problem, and marking it would leave a blocked badge behind after the
     * hold is lifted.
     */
    public static function refused(string $explanation): self
    {
        return new self(advanced: false, refusal: $explanation);
    }

    /**
     * The advance did not happen, for either reason.
     *
     * `wasRefused()` is what tells the two apart, and a screen almost always
     * wants to: *“this workflow is on hold”* names something somebody did on
     * purpose and needs a different affordance from *“the survey has not come
     * back”*, which names something to go and chase.
     */
    public function wasBlocked(): bool
    {
        return ! $this->advanced;
    }

    /** The workflow itself would not move — a hold, a cancellation, a race. */
    public function wasRefused(): bool
    {
        return $this->refusal !== null;
    }

    /**
     * The sentences to show, in the order a person should read them.
     *
     * **Each one names its gate.** An evaluator writes a sentence about a
     * *kind* of gate, not about the particular one: `ActionCompletedEvaluator`
     * takes no configuration at all, so every instance of it produces a
     * byte-identical string, and two document gates collide whenever they ask
     * for the same category. A stage held by three of them refuses with the
     * same sentence three times.
     *
     * A caller that shows these as a list can either repeat itself or
     * deduplicate, and both are wrong: the repetition reads as a bug, and the
     * deduplication silently reports one blocker where there are three. The
     * label is what tells them apart, so it goes in the sentence rather than
     * being left for a screen to add — the deal overview lists every gate
     * beside its label, but the People and Properties tabs advance from the
     * same endpoint and render nothing but this text.
     *
     * @return list<string>
     */
    public function reasons(): array
    {
        if ($this->refusal !== null) {
            return [$this->refusal];
        }

        $labels = $this->gateLabels();

        // `array_keys` already produces a list, so `array_map` over it does too.
        return array_map(
            fn (string $gateId): string => array_key_exists($gateId, $labels)
                ? $labels[$gateId].': '.$this->blockedBy[$gateId]->explanation
                : $this->blockedBy[$gateId]->explanation,
            array_keys($this->blockedBy),
        );
    }

    /**
     * Gate id => label, read off the stage the result already carries.
     *
     * No query: `AdvanceWorkflow` evaluated these gates from the stage's own
     * loaded relation a moment ago, so the rows are in memory. The fallback in
     * `reasons()` is for a result assembled some other way — a sentence
     * without its label is worse than one with, and neither is worth a
     * lazy-load on a refusal path.
     *
     * @return array<string, string>
     */
    private function gateLabels(): array
    {
        $stage = $this->activatedStage;

        if (! $stage instanceof Stage || ! $stage->relationLoaded('gates')) {
            return [];
        }

        $labels = [];

        foreach ($stage->gates as $gate) {
            $labels[(string) $gate->getKey()] = $gate->label;
        }

        return $labels;
    }

    /**
     * Whether this gate is among the ones that refused.
     *
     * Used by the advance modal to mark the row rather than re-deriving it.
     */
    public function wasBlockedBy(Gate $gate): bool
    {
        return array_key_exists($gate->getKey(), $this->blockedBy);
    }
}
