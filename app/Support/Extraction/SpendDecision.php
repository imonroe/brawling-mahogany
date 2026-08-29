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
    /**
     * @param  int  $spentMicros  what the ceiling that decided has had spent against it
     * @param  int  $capMicros  that ceiling
     * @param  int  $teamSpentMicros  what **this team** has spent, whichever ceiling decided
     * @param  int  $teamCapMicros  this team's own ceiling, likewise
     */
    private function __construct(
        public bool $allowed,
        public ?string $reasonCode,
        public ?string $message,
        public int $spentMicros,
        public int $capMicros,
        public bool $shouldWarn,
        public int $teamSpentMicros,
        public int $teamCapMicros,
        public bool $teamShouldWarn,
    ) {}

    /**
     * Allowed, which is only ever decided by the team's own ceiling — so the
     * two pairs are the same pair here.
     */
    public static function allowed(int $spentMicros, int $capMicros, bool $shouldWarn): self
    {
        return new self(
            true, null, null,
            $spentMicros, $capMicros, $shouldWarn,
            $spentMicros, $capMicros, $shouldWarn,
        );
    }

    /** Likewise: the ceiling that decided *is* the team's. */
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
            $spentMicros,
            $capMicros,
            /*
             * A team at or over its own ceiling is past the warning threshold
             * by definition, and a screen drawing its bar from this should say
             * so rather than drawing it in the untroubled colour. `shouldWarn`
             * above stays false because it is about *whether to warn instead of
             * stopping*, and this one has stopped.
             */
            true,
        );
    }

    /**
     * The one case where the two pairs differ, and the reason both exist.
     *
     * The refusal is about the **platform**, so `spentMicros`/`capMicros` are
     * the installation's — that is what the sentence and the logs are about.
     * A screen showing *this team's* spend must not draw those: every team's
     * Extract dialog rendered the cross-tenant total as its own for a round
     * (ADR 0002, *"the count is scoped even when the row is not"*), which is
     * why the team's own pair travels alongside rather than being fetched
     * again by whoever needs it.
     */
    public static function platformCapReached(
        int $spentMicros,
        int $capMicros,
        int $teamSpentMicros,
        int $teamCapMicros,
        bool $teamShouldWarn,
    ): self {
        return new self(
            false,
            'platform_spend_cap_reached',
            'Extraction is paused across this installation while a spending limit is reviewed. '
                .'Nothing has been lost — try again once it has been raised.',
            $spentMicros,
            $capMicros,
            false,
            $teamSpentMicros,
            $teamCapMicros,
            $teamShouldWarn,
        );
    }

    /**
     * How much of a ceiling a spend has used, in whole per cent.
     *
     * **The one statement of this rule.** It was three by round 4:
     * `percentUsed()` below, `ExtractionHistory::spend()`'s own `match`, and an
     * inline copy in `DealDocumentController::extractProps()` — which used
     * `round()` where this uses `floor()`, so the two screens could disagree
     * by a point about the same team on the same day. CLAUDE.md already
     * records that exact shape from this slice: *"a badge that counts a
     * different set from the list beneath it comes from a second place stating
     * the rule."*
     *
     * `null` means *there is no ceiling*, which is a different answer from a
     * number and has to stay tellable apart by a screen deciding whether to
     * draw a bar at all. Zero is a ceiling of zero and is therefore fully
     * spent, whatever was spent against it.
     *
     * Deliberately **not clamped**. A run can finish over the line, and
     * `ExtractDocumentDialog`'s own comment calls that *"a real state"* — so
     * the figure is the truth and clamping is the bar's business, which is
     * where both screens already do it.
     */
    public static function percentOf(int $spentMicros, int $capMicros): ?int
    {
        if ($capMicros < 0) {
            return null;
        }

        if ($capMicros === 0) {
            return 100;
        }

        return (int) floor($spentMicros / $capMicros * 100);
    }

    /**
     * The same question about whichever ceiling did the deciding.
     *
     * Kept as an instance method because `SpendLedgerTest` asks it of a
     * decision and that is the natural reading — but it is one line over
     * `percentOf()` now rather than a second copy of the rule.
     *
     * The `int` return is preserved: a decision always names a ceiling, and a
     * negative one reads as nought per cent of nothing rather than as *no
     * ceiling*, which is the distinction only a screen needs.
     */
    public function percentUsed(): int
    {
        return (int) min(100, self::percentOf($this->spentMicros, $this->capMicros) ?? 0);
    }
}
