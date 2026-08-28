<?php

declare(strict_types=1);

namespace App\Support\Extraction;

use App\Models\Extraction;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * What extraction has cost, and whether it may cost any more (#113 · §14.3).
 *
 * PRD §14.3, which is the whole reason this exists on day one rather than as a
 * later optimisation:
 *
 * > **Extraction cost grows with deal volume rather than team count, so a heavy
 * > user could be unprofitable at a flat price. Track cost per deal from day
 * > one of slice 5 and cap it.**
 *
 * ## Spend is summed, not counted
 *
 * There is no running-total column. A counter is a second copy of a fact the
 * `extractions` rows already carry, and a second copy is a thing that can
 * disagree — a failed transaction, a purged row, a hand-corrected cost, and the
 * counter is wrong forever with nothing to reconcile it against. The sum is one
 * indexed aggregate over one month of one team's rows, which at the volumes PRD
 * §9 budgets for is cheaper than the risk.
 *
 * ## The month is UTC, and that is a real decision
 *
 * Every other date question in this product is asked in the *team's* timezone,
 * and CLAUDE.md is emphatic about it. This one is not, for two reasons. A
 * platform-wide ceiling cannot roll over at thirty different instants and still
 * be a ceiling. And the two numbers appear side by side on the admin health
 * screen (#54), where a team month and a platform month covering different days
 * would make the smaller number occasionally exceed the larger one.
 *
 * The cost of that choice is stated rather than hidden: a team in UTC-7 sees
 * its budget reset at 5pm on the last day of the month. That is visible on the
 * screen, where the reset instant is shown rather than implied.
 */
final class SpendLedger
{
    /**
     * May this team start another extraction?
     *
     * Called twice, deliberately. Once by `StartExtraction`, so somebody
     * pressing the button on S65 is told immediately rather than queueing work
     * that will not run — and once by `PerformExtraction`, immediately before
     * the provider call, because a queue can hold a job for long enough that
     * twenty siblings spend the remaining budget in between. The first is
     * courtesy; the second is the control.
     */
    public function decide(Team $team): SpendDecision
    {
        $platformSpent = $this->platformSpentThisMonth();
        $platformCap = (int) config('extraction.caps.platform_monthly_micros');

        if ($platformCap > 0 && $platformSpent >= $platformCap) {
            return SpendDecision::platformCapReached($platformSpent, $platformCap);
        }

        $teamSpent = $this->teamSpentThisMonth($team);
        $teamCap = $this->capFor($team);

        if ($teamCap > 0 && $teamSpent >= $teamCap) {
            return SpendDecision::teamCapReached($teamSpent, $teamCap);
        }

        return SpendDecision::allowed($teamSpent, $teamCap, $this->shouldWarn($teamSpent, $teamCap));
    }

    /**
     * The team's ceiling: its own if it has one, otherwise the configured one.
     *
     * Null means *"the default, whatever it currently is"* rather than *"the
     * default as it stood when this team was created"*. The distinction matters
     * the first time somebody raises the platform default and expects every
     * team to get the new number.
     */
    public function capFor(Team $team): int
    {
        $own = $team->extraction_monthly_cap_micros;

        if ($own !== null) {
            return (int) $own;
        }

        return (int) config('extraction.caps.team_monthly_micros');
    }

    public function teamSpentThisMonth(Team $team, ?CarbonInterface $at = null): int
    {
        return $this->sum(
            Extraction::query()->where('team_id', $team->getKey()),
            $at,
        );
    }

    /**
     * Across every team, so `withoutTeamScope()` is the right tool here.
     *
     * This is `UnscopedQueryConventionTest`'s second sanctioned category — *"a
     * context with no tenant"*. A platform ceiling is a fact about the
     * installation's bill, not about anybody's data, and asking it inside one
     * team's scope would answer a different question entirely.
     */
    public function platformSpentThisMonth(?CarbonInterface $at = null): int
    {
        return $this->sum(Extraction::withoutTeamScope(), $at);
    }

    /**
     * When the current month's budget resets, so a screen can say so.
     */
    public function resetsAt(?CarbonInterface $at = null): CarbonImmutable
    {
        return $this->monthStart($at)->addMonth();
    }

    private function shouldWarn(int $spent, int $cap): bool
    {
        if ($cap <= 0) {
            return false;
        }

        $threshold = (int) config('extraction.caps.warn_at_percent', 80);

        return $spent * 100 >= $cap * $threshold;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Extraction>  $query
     */
    private function sum($query, ?CarbonInterface $at = null): int
    {
        $start = $this->monthStart($at);

        /*
         * Keyed on `created_at`, not `completed_at`.
         *
         * A row created on the 31st and completed on the 1st spent last
         * month's budget from the queue's point of view, and it is the *start*
         * that the cap check gated. Keying on completion would let a burst
         * queued under the wire land on the wrong side of the ceiling — and,
         * worse, a row still `processing` at the boundary would count against
         * neither month.
         */
        return (int) $query
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $start->addMonth())
            ->sum('cost_micros');
    }

    private function monthStart(?CarbonInterface $at = null): CarbonImmutable
    {
        return CarbonImmutable::instance($at ?? CarbonImmutable::now())
            ->setTimezone('UTC')
            ->startOfMonth();
    }
}
