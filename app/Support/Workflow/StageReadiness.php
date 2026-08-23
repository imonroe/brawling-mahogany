<?php

declare(strict_types=1);

namespace App\Support\Workflow;

use App\Models\Gate;
use App\Support\Workflow\Gates\GateVerdict;

/**
 * Why a stage can or cannot advance, answered without advancing it (#75).
 *
 * The read-only twin of what `AdvanceResult` reports after the fact. The two
 * deliberately share a vocabulary — `blocking` and `advisories`, split by
 * `Gate::blocksAdvance()` — because a screen that says one thing before the
 * button and another after it is worse than a screen that says nothing.
 *
 * **Not a second mutation path.** This holds gates and verdicts and writes
 * nothing; `DescribeBlockers` builds it from evaluators that are themselves
 * pure. `AdvanceWorkflow` keeps its monopoly on writing workflow state.
 */
final readonly class StageReadiness
{
    /**
     * @param  array<string, array{gate: Gate, verdict: GateVerdict}>  $blocking
     * @param  array<string, array{gate: Gate, verdict: GateVerdict}>  $advisories
     */
    public function __construct(
        public array $blocking,
        public array $advisories,
    ) {}

    /**
     * Whether an advance attempted right now would get past the gates.
     *
     * Not a promise, and the difference matters. Between this render and the
     * click a colleague can tick a gate or reopen a required task;
     * `AdvanceWorkflow` evaluates again inside its transaction and is the only
     * thing entitled to an opinion that sticks. This decides whether to offer
     * the button, never whether pressing it will succeed.
     */
    public function canAdvance(): bool
    {
        return $this->blocking === [];
    }

    /**
     * Everything unmet, blocking first, in a shape Inertia can carry.
     *
     * Blocking first because issue #75's standard is that a reader learns what
     * is stopping the deal without scrolling, and an advisory above a blocker
     * is the reader scrolling.
     *
     * `linkTarget` is passed through untouched: PRD §5.4 requires that *"each
     * unmet gate links directly to the thing that clears it"*, and the
     * evaluator is the only thing that knows what that is.
     *
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return [
            ...$this->describe($this->blocking, true),
            ...$this->describe($this->advisories, false),
        ];
    }

    /**
     * @param  array<string, array{gate: Gate, verdict: GateVerdict}>  $entries
     * @return list<array<string, mixed>>
     */
    private function describe(array $entries, bool $isBlocking): array
    {
        return array_values(array_map(
            fn (array $entry): array => [
                'id' => $entry['gate']->getKey(),
                'label' => $entry['gate']->label,
                'gateType' => $entry['gate']->gate_type,
                'isBlocking' => $isBlocking,
                // IA §8: overridden is not a kind of met, and the badge has to
                // say which. `Gate::state()` is the only thing that decides.
                'gateState' => $entry['gate']->state()->value,
                ...$entry['verdict']->toArray(),
            ],
            $entries,
        ));
    }
}
