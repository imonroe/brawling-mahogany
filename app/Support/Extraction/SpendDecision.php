<?php

declare(strict_types=1);

namespace App\Support\Extraction;

/**
 * Whether this extraction may spend, and what to say if not.
 *
 * #113: *"Hitting the cap stops extraction and tells the user plainly — it does
 * not silently degrade."* The refusal therefore carries the sentence a person
 * reads, and the sentence differs by which ceiling was hit.
 *
 * ## Neither sentence names a control the reader has
 *
 * The team-cap message read *"An owner can raise it in Settings"* for a round,
 * and review was right that it was a promise with nothing behind it:
 * `teams.extraction_monthly_cap_micros` had a reader and no writer anywhere in
 * the application. The fix is not to add the screen. `SpendLedger` calls the
 * team cap *"a commercial limit"* and the migration describes the column as
 * what an **operator** sets *"for the one team that needs stopping now"* — a
 * commercial limit the customer can raise for themselves is not a limit, and a
 * ceiling somebody sets on a team that is spending too fast is not one that
 * team should be able to lift.
 *
 * So it goes the way `mail:suppression` goes, and for the same reason stated
 * there: *"every team is affected — it is deliberately not something a team can
 * do to itself."* `extraction:cap` is the writer, it is audited, and both
 * sentences here now name only what the reader can actually do — wait for the
 * month, or ask the person who runs the installation.
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
                .'It resets at the start of next month, or whoever runs this installation can raise it.',
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
            'Extraction is paused across this installation while a spending limit is reviewed. '
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
