<?php

declare(strict_types=1);

namespace App\Support\Workflow\Gates;

/**
 * What an evaluator returns — never a boolean (issue #67).
 *
 * A boolean cannot build S23. The Screen Inventory requires the advance modal
 * to *"explain refusal clearly enough to act on"*, and PRD §5.4 goes further:
 * *"each unmet gate links directly to the thing that clears it."* A screen
 * that receives `false` can say "blocked" and nothing else, so the person
 * reading it goes hunting.
 *
 * So a verdict carries three things: whether it is met, a sentence a human
 * wrote, and where to go. The sentence is not assembled from fragments — every
 * evaluator writes its own, because "3 of 5 required tasks are done" and "no
 * inspection report is attached" are not the same sentence with different
 * nouns.
 */
final readonly class GateVerdict
{
    /**
     * @param  array<string, mixed>  $linkTarget
     */
    private function __construct(
        public bool $met,
        public string $explanation,
        public array $linkTarget = [],
    ) {}

    /**
     * @param  array<string, mixed>  $linkTarget
     */
    public static function met(string $explanation, array $linkTarget = []): self
    {
        return new self(true, $explanation, $linkTarget);
    }

    /**
     * @param  array<string, mixed>  $linkTarget
     */
    public static function unmet(string $explanation, array $linkTarget = []): self
    {
        return new self(false, $explanation, $linkTarget);
    }

    /**
     * A gate whose data does not exist in this slice yet.
     *
     * Three of the seven types depend on documents (Slice 3), action instances
     * (Slice 3), or key dates (Slice 4). Issue #67 is precise about how they
     * behave in the meantime: *"the three deferred evaluators return an
     * explanatory unmet, never a silent false, and each names the issue that
     * will wire it."*
     *
     * Unmet rather than met, because a gate that has not been built cannot
     * have been satisfied — and because the safe direction on a gate is
     * always closed. The named issue turns a mystery into a link.
     */
    public static function notYetWired(string $what, string $issue): self
    {
        return new self(
            false,
            "{$what} This gate is not wired up yet ({$issue}), so it cannot clear on its own — "
            .'override it with a reason if the deal needs to move.',
            ['type' => 'awaiting_slice', 'issue' => $issue],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'met' => $this->met,
            'explanation' => $this->explanation,
            'linkTarget' => $this->linkTarget,
        ];
    }
}
