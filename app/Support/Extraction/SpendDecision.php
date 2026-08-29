<?php

declare(strict_types=1);

namespace App\Support\Extraction;

/**
 * Whether this extraction may spend, and what to say if not.
 *
 * #113: *"Hitting the cap stops extraction and tells the user plainly — it does
 * not silently degrade."* The refusal therefore carries the sentence a person
 * reads, and the sentence differs by which ceiling was hit — a team cap is
 * something their own administrator can raise, and a platform cap is not.
 * Telling somebody to "contact your administrator" about a limit their
 * administrator cannot move is worse than telling them nothing.
 */
final readonly class SpendDecision
{
    private function __construct(
        public bool $allowed,
        public ?string $reasonCode,
        public ?string $message,
        public int $spentMicros,
        public int $capMicros,
        public bool $shouldWarn,
    ) {}

    public static function allowed(int $spentMicros, int $capMicros, bool $shouldWarn): self
    {
        return new self(true, null, null, $spentMicros, $capMicros, $shouldWarn);
    }

    public static function teamCapReached(int $spentMicros, int $capMicros): self
    {
        return new self(
            false,
            'team_spend_cap_reached',
            'This team has reached its monthly limit for reading documents. '
                .'An owner can raise it in Settings, or it resets at the start of next month.',
            $spentMicros,
            $capMicros,
            false,
        );
    }

    public static function platformCapReached(int $spentMicros, int $capMicros): self
    {
        return new self(
            false,
            'platform_spend_cap_reached',
            'Reading documents is paused across this installation while a spending limit is reviewed. '
                .'Nothing has been lost — try again once it has been raised.',
            $spentMicros,
            $capMicros,
            false,
        );
    }

    public function percentUsed(): int
    {
        /*
         * A cap of zero is fully spent whatever was spent against it, and a
         * **negative** cap is the absence of a ceiling — which is nought per
         * cent of nothing rather than a bar that is full. `SpendLedger::decide()`
         * makes the same distinction; a screen reading 100% over "no limit"
         * would be the two halves disagreeing again.
         */
        if ($this->capMicros < 0) {
            return 0;
        }

        if ($this->capMicros === 0) {
            return 100;
        }

        return (int) min(100, floor($this->spentMicros / $this->capMicros * 100));
    }
}
