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
     * @param  array<string, array{gate: Gate, verdict: GateVerdict}>  $met  the
     *                                                                       ones nothing is waiting on, carried for S23 and dropped by
     *                                                                       `toArray()` — see `checklist()`
     */
    public function __construct(
        public array $blocking,
        public array $advisories,
        public array $met,
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
            ...$this->describe($this->blocking),
            ...$this->describe($this->advisories),
        ];
    }

    /**
     * Every gate on the stage, including the ones nothing is waiting on (S23).
     *
     * The difference from `toArray()` is the audience, not the data. F3.7 asks
     * the overview *what blocks advance*, so `toArray()` drops the met ones —
     * a hub screen listing satisfied conditions is a hub screen burying the
     * two that matter. S23 is the opposite question: Design System §7.4's
     * requirements pane is a **checklist** with its own count (*"Requirements
     * to advance · 2 of 3 met"*), and a checklist that hides the ticked rows
     * cannot be counted by the person reading it.
     *
     * Blocking first, then advisories, then met — the order somebody should
     * read them in, which is the same argument `toArray()` makes for its two.
     *
     * @return list<array<string, mixed>>
     */
    public function checklist(): array
    {
        return [
            ...$this->describe($this->blocking),
            ...$this->describe($this->advisories),
            ...$this->describe($this->met),
        ];
    }

    /**
     * How many of each kind, for §7.4's pane heading.
     *
     * `cleared` is met **plus overridden**, because that is the question the
     * heading asks — how many are no longer in the way. IA §8 keeps the two
     * apart everywhere it matters (the badge, the timeline, the audit entry);
     * a count of things standing in the way is the one place they are the
     * same, and an overridden gate that still read as outstanding would make
     * the count disagree with the button beside it.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $overridden = count(array_filter(
            $this->advisories,
            fn (array $entry): bool => $entry['gate']->overridden,
        ));

        return [
            'total' => count($this->blocking) + count($this->advisories) + count($this->met),
            'blocking' => count($this->blocking),
            'advisory' => count($this->advisories) - $overridden,
            'overridden' => $overridden,
            'cleared' => count($this->met) + $overridden,
        ];
    }

    /**
     * One bucket, in a shape Inertia can carry.
     *
     * `isBlocking` is the gate's own column; `blocksAdvance` is whether it
     * stands in the way **right now**. They differ on exactly one kind of row,
     * and it is the kind this issue creates: an overridden blocking gate sorts
     * into `advisories`, because `Gate::blocksAdvance()` is `is_blocking && !
     * overridden`. Before #77 nothing could write `overridden`, so the two
     * questions had never once disagreed and one field answered both — and
     * reporting such a gate as `isBlocking: false` would have had S15 draw it
     * as an **Advisory**, which is exactly what IA §8 says an override is not.
     *
     * @param  array<string, array{gate: Gate, verdict: GateVerdict}>  $entries
     * @return list<array<string, mixed>>
     */
    private function describe(array $entries): array
    {
        return array_values(array_map(
            fn (array $entry): array => [
                'id' => $entry['gate']->getKey(),
                'label' => $entry['gate']->label,
                'gateType' => $entry['gate']->gate_type,
                'isBlocking' => $entry['gate']->is_blocking,
                'blocksAdvance' => $entry['gate']->blocksAdvance() && ! $entry['verdict']->met,
                // IA §8: overridden is not a kind of met, and the badge has to
                // say which. `Gate::state()` is the only thing that decides.
                'gateState' => $entry['gate']->state()->value,
                ...$entry['verdict']->toArray(),
            ],
            $entries,
        ));
    }
}
