<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Deal;
use App\Models\Event;
use App\Models\KeyDate;
use App\Models\Team;
use App\Support\Calendar\Recurrence;
use App\Support\Tenancy\TeamContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * One window of the calendar, with both kinds of thing in it (S57 · #105).
 *
 * Screen Inventory names the reason S57 is the hard one: *"events and
 * deadlines are different things sharing a grid."* They are two tables, two
 * shapes and two meanings, and the screen has to draw them together without
 * letting a reader confuse them.
 *
 * ## Why the merge happens here rather than in the component
 *
 * Because *which square a thing falls in* is a timezone question, and PRD §9
 * gives it one answer: stored UTC, displayed in the team's zone. An event is
 * an instant and a key date is a day, so the two are converted differently —
 * an event's square comes from `setTimezone()`, and a deadline's square is the
 * day already written on it, which has no zone to be read in. A component that
 * did this for itself would get the second case wrong in the obvious way, and
 * a deadline rendering a day early for every team west of UTC is precisely the
 * defect `Task::state()` records twice over.
 *
 * ## Two shapes, one list, and the kind is explicit
 *
 * Every item carries `kind`, and the screen styles from that rather than from
 * the presence of a time. Deriving it — *"if it has no end time it must be a
 * deadline"* — would be right until an all-day event existed, which is the
 * next row somebody adds.
 */
final class CalendarBoard
{
    public function __construct(private readonly TeamContext $teams) {}

    /**
     * Everything between two instants, ordered as a day reads.
     *
     * The window is inclusive at both ends and given in the team's zone by the
     * caller, because a *month* is a month on somebody's wall and not a span
     * of 30 × 86,400 seconds.
     *
     * @param  Deal|null  $deal  narrows to one deal — the deal tab and the
     *                           per-deal `.ics` feed (#108) ask the same
     *                           question of a smaller set
     * @return list<array<string, mixed>>
     */
    public function between(CarbonInterface $from, CarbonInterface $to, ?Deal $deal = null): array
    {
        $timezone = $this->timezone();

        $items = [
            ...$this->events($from, $to, $timezone, $deal),
            ...$this->deadlines($from, $to, $deal),
        ];

        /*
         * Sorted on the day first and the start second, with all-day rows
         * ahead of timed ones on the same day.
         *
         * A deadline has no time, and sorting a null to the end would put
         * *"inspection objection expires today"* underneath a 4pm showing.
         * Design System §7.4's argument about a rail applies to a day cell:
         * the thing with legal consequences is read first.
         */
        usort($items, static function (array $a, array $b): int {
            return [$a['day'], $a['sortsAfterAllDay'], $a['sortKey']]
                <=> [$b['day'], $b['sortsAfterAllDay'], $b['sortKey']];
        });

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function events(CarbonInterface $from, CarbonInterface $to, string $timezone, ?Deal $deal): array
    {
        $events = Event::query()
            ->touching($from, $to)
            ->when($deal instanceof Deal, fn ($query) => $query->where('deal_id', $deal?->getKey()))
            ->with(['deal', 'property'])
            ->get();

        $rows = [];

        foreach ($events as $event) {
            foreach ($this->startsWithin($event, $from, $to, $timezone) as $start) {
                $rows[] = $this->eventRow($event, $start, $timezone);
            }
        }

        return $rows;
    }

    /**
     * Every start this event has inside the window — one, or one per repeat.
     *
     * A one-off is loaded because it overlaps the window, so its own start may
     * be *before* it: a three-day conference beginning last Friday belongs on
     * this week's grid. Clamping it to the window would move the event; it is
     * returned at its real start and the grid places it by day.
     *
     * @return list<CarbonImmutable>
     */
    private function startsWithin(
        Event $event,
        CarbonInterface $from,
        CarbonInterface $to,
        string $timezone,
    ): array {
        $rule = $event->repeats();

        if (! $rule instanceof Recurrence) {
            return [$event->startsIn($timezone)];
        }

        return $rule->occurrencesBetween($event->startsIn($timezone), $from, $to);
    }

    /**
     * @return array<string, mixed>
     */
    private function eventRow(Event $event, CarbonImmutable $start, string $timezone): array
    {
        $length = $event->startsIn($timezone)->diffInSeconds($event->endsIn($timezone));

        $end = $start->addSeconds((int) $length);

        $deal = $event->deal;

        return [
            /*
             * A repeating event's occurrences share the row's id, so the key a
             * list renders by carries the day too — two `v-for` children with
             * one key is a rendering bug that looks like a missing event.
             */
            'key' => $event->getKey().':'.$start->toDateString(),
            'id' => $event->getKey(),
            'kind' => 'event',
            'type' => $event->type->value,
            'typeLabel' => $event->type->label(),
            'title' => $event->title,
            'day' => $start->toDateString(),
            'startsAt' => $start->toIso8601String(),
            'endsAt' => $end->toIso8601String(),
            'isAllDay' => $event->is_all_day,
            'location' => $event->location,
            'isRepeat' => $event->repeats() instanceof Recurrence,
            /*
             * S58's *"Repeats every 2 weeks until Sep 30"*, composed by the
             * rule itself rather than by a screen. `Recurrence::sentence()`
             * shipped with a careful docblock and no caller, which is the same
             * dead-end `teams.logo_path` is recorded for — and `isRepeat`
             * beside it was a prop nothing rendered, so a repeating occurrence
             * looked exactly like a one-off on the grid.
             */
            'repeatSentence' => $event->repeats()?->sentence(),
            'deal' => $deal instanceof Deal
                ? ['label' => $deal->displayName(), 'url' => route('deals.show', $deal)]
                : null,
            /*
             * Alongside the label, not inside it. S57 draws the label — a
             * colleague reading their own team's screen — and the `.ics` feed
             * must not, because `Deal::displayName()` falls back to a client's
             * surname and that document is stored by Google. `IcsDocument`
             * needs the id to compose a safe suffix of its own.
             */
            'dealId' => $deal?->getKey(),
            'sortsAfterAllDay' => $event->is_all_day ? 0 : 1,
            'sortKey' => $event->is_all_day ? '' : $start->format('H:i:s'),
        ];
    }

    /**
     * The deadlines in the window (#106).
     *
     * ## An unconfirmed extracted date is not on the calendar
     *
     * #107: *"it must not be counted as a deadline until confirmed."* The
     * calendar is the surface where counting it would do the most damage — a
     * date the machine proposed, rendered next to real ones, is a date
     * somebody plans around.
     *
     * @return list<array<string, mixed>>
     */
    private function deadlines(CarbonInterface $from, CarbonInterface $to, ?Deal $deal): array
    {
        $dates = KeyDate::query()
            ->confirmed()
            /*
             * A cross-deal read filters to running deals; a single deal's own
             * view does not. S18 and a per-deal feed are somebody looking at
             * *that* deal on purpose, and a closed deal's dates are the record
             * of what happened — the month grid and the whole-team feed are
             * asking what is coming, which a closed deal has none of.
             */
            ->when(! $deal instanceof Deal, fn ($query) => $query->onOpenDeals())
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when($deal instanceof Deal, fn ($query) => $query->where('deal_id', $deal?->getKey()))
            ->with('deal')
            ->get();

        $rows = [];

        foreach ($dates as $date) {
            $dealOf = $date->deal;

            $rows[] = [
                'key' => 'key_date:'.$date->getKey(),
                'id' => $date->getKey(),
                'kind' => 'deadline',
                'title' => $date->name,
                'day' => $date->date->toDateString(),
                'isCritical' => $date->is_critical,
                'isDerived' => $date->follows(),
                'deal' => $dealOf instanceof Deal
                    ? ['label' => $dealOf->displayName(), 'url' => route('deals.dates.index', $dealOf)]
                    : null,
                // See the note on the event row above.
                'dealId' => $dealOf?->getKey(),
                /*
                 * Ahead of every timed event on the same day, and ahead of
                 * all-day events too. A deadline is the thing on that square
                 * with consequences.
                 */
                'sortsAfterAllDay' => -1,
                'sortKey' => '',
            ];
        }

        return $rows;
    }

    public function timezone(): string
    {
        $team = $this->teams->get();

        return $team instanceof Team ? $team->timezone : (string) config('app.timezone');
    }
}
