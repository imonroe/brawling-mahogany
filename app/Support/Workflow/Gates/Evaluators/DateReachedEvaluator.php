<?php

declare(strict_types=1);

namespace App\Support\Workflow\Gates\Evaluators;

use App\Models\Deal;
use App\Models\Gate;
use App\Models\KeyDate;
use App\Models\Team;
use App\Support\Formatting\Format;
use App\Support\Tenancy\TeamContext;
use App\Support\Workflow\Gates\GateEvaluator;
use App\Support\Workflow\Gates\GateVerdict;
use Carbon\CarbonImmutable;

/**
 * A named key date has passed (PRD §4.4 F4.8, §4.8 F8.2 · issue #109).
 *
 * Built against the interface in #67 and returning an explanatory unmet until
 * `key_dates` existed; this is that wiring, and it is the second of the two
 * evaluators `CLAUDE.md` still owed a *"is this path actually reachable"*
 * check. It is reachable now in both directions: S43 can configure the date
 * this gate names, and S18 is where somebody moves it.
 *
 * ## Named, not pointed at
 *
 * A gate lives on a **template**, and a template has never met the deal it
 * will run on — the same constraint F5.3's key-date trigger works under. So
 * the configuration carries the *name* a team uses for the date, matched
 * case- and whitespace-insensitively against that deal's own dates, because
 * the two sides were typed by people months apart.
 *
 * ## Evaluated in the team's calendar, and *today counts*
 *
 * PRD §9's display-in-the-team's-timezone reaching a decision rather than a
 * rendering — the rule `Task::state()` arrived at over two rounds of review.
 * A date is reached on the day it lands: an inspection objection deadline of
 * the 15th has been reached on the 15th, and a gate that waited until the 16th
 * would hold a stage for a day nobody agreed to. Comparing against a UTC
 * instant would move that boundary by hours in one direction or the other, so
 * the comparison is day against day, in the team's zone.
 *
 * ## A date nobody has confirmed cannot clear it
 *
 * #107: an extracted, unconfirmed date *"must not be counted as a deadline"*.
 * Letting a machine's reading of a contract advance a workflow is the exact
 * shape PRD §4.10 forbids — extraction never writes into a live record without
 * human confirmation, and clearing a gate is writing into the record by
 * another door.
 */
final class DateReachedEvaluator implements GateEvaluator
{
    public function __construct(private readonly TeamContext $teams) {}

    public static function type(): string
    {
        return 'date_reached';
    }

    public static function label(): string
    {
        return 'Date reached';
    }

    public function evaluate(Gate $gate): GateVerdict
    {
        $name = trim((string) ($gate->configuration()['keyDateName']
            ?? $gate->configuration()['key_date']
            ?? ''));

        if ($name === '') {
            /*
             * Unmet, the direction every unconfigured evaluator takes: a gate
             * nobody can read is a gate that must not wave an advance through.
             */
            return GateVerdict::unmet(
                'This requirement does not say which date it is waiting for. '
                .'Edit the workflow template to name one.',
            );
        }

        $deal = $gate->stage?->workflow?->deal;

        if (! $deal instanceof Deal) {
            return GateVerdict::unmet('This requirement is waiting on a deal that is no longer here.');
        }

        $keyDate = $this->find($deal, $name);

        if (! $keyDate instanceof KeyDate) {
            /*
             * Says so rather than sitting unmet forever. An advance blocked by
             * a requirement nobody can clear is worse than one blocked by a
             * requirement somebody can, because the second has a next action
             * and the first looks like a bug in the product — and the next
             * action here is real: add the date on this deal's Dates &
             * Deadlines, which is where the link goes.
             */
            return GateVerdict::unmet(
                'This deal has no date called “'.$name.'” yet. Add it on Dates & Deadlines.',
                $this->linkTo($name),
            );
        }

        $today = $this->today();

        if ($keyDate->date->toDateString() <= $today) {
            return GateVerdict::met($keyDate->name.' was '.Format::date($keyDate->date).'.');
        }

        return GateVerdict::unmet(
            $keyDate->name.' is '.Format::date($keyDate->date).', which has not arrived yet.',
            /*
             * PRD §5.4: *"each unmet gate links directly to the thing that
             * clears it."* What clears this one is time — but what somebody
             * does about it is look at the date, and move it if the contract
             * moved. So the link is S18, with the date named.
             */
            $this->linkTo($keyDate->name),
        );
    }

    /**
     * The deal's date of that name, folded the way F5.3's trigger folds it.
     *
     * `mb_strtolower` rather than `strtolower`, since a team is free to name a
     * date in any language they work in.
     *
     * An unconfirmed extracted date is not a candidate — see the class
     * docblock — so a deal holding only a *suggested* closing reads as having
     * no closing date at all, which is the honest answer.
     */
    private function find(Deal $deal, string $name): ?KeyDate
    {
        $wanted = mb_strtolower($name);

        return KeyDate::query()
            ->confirmed()
            ->where('deal_id', $deal->getKey())
            ->get()
            ->first(fn (KeyDate $date): bool => mb_strtolower(trim($date->name)) === $wanted);
    }

    /**
     * @return array<string, mixed>
     */
    private function linkTo(string $name): array
    {
        /*
         * The type and the name only. `gates.ts` resolves this shape to the
         * deal's dates tab and builds the URL from the `dealUrl` it already
         * has — a payload carrying a URL nobody reads is a contract nobody is
         * keeping, which is the finding `DocumentPresentEvaluator` records.
         */
        return ['type' => 'key_date', 'name' => $name];
    }

    private function today(): string
    {
        $team = $this->teams->get();

        $timeZone = $team instanceof Team ? $team->timezone : config('app.timezone');

        return CarbonImmutable::now(is_string($timeZone) ? $timeZone : 'UTC')->toDateString();
    }
}
