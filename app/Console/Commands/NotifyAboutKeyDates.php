<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\NotificationType;
use App\Models\Deal;
use App\Models\KeyDate;
use App\Models\Notification;
use App\Models\Person;
use App\Models\Team;
use App\Support\Formatting\Format;
use App\Support\Notifications\NotificationAudience;
use App\Support\Notifications\Notify;
use App\Support\Permissions;
use App\Support\Tenancy\TeamContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Deadline reminders ahead of a key date (PRD §4.8 F8.4 · S88 · issue #109).
 *
 * ## What this inherits rather than rebuilds
 *
 * `NotifyAboutDeadlines` already does F8.4's shape for **tasks**, and #109's
 * own notes say the delivery machinery does not need rebuilding: the hourly
 * sweep handled in each team's 8am, the once-only guard that lives in the
 * table rather than in a queue, and the channels, preferences and quiet hours
 * that come free through `Notify`. This is the same shape with key dates as
 * the source, and three things that are genuinely new.
 *
 * ## 1. Offsets are per date, and a critical one is louder
 *
 * The task sweep has a fixed one-day horizon. A key date carries its own list
 * — `KeyDate::reminderDays()` — defaulting to a week out and the day before,
 * and to four steps including the day itself when the date is `is_critical`.
 * PRD §12.3 is why: *"a missed inspection deadline is a legal problem."*
 *
 * ## 2. One email a day, not four
 *
 * #109: *"several deadlines on one day should produce **one** email, not four.
 * That is the difference between a useful reminder and one that gets
 * filtered."* So the sweep folds **before** it writes: everything a person is
 * owed today becomes one notification carrying one line per date. Folding
 * afterwards would be `NotificationFeed`'s mistake one table along — the row
 * count and the line count disagreeing — and folding at delivery time would
 * mean three rows in the panel for one email.
 *
 * The exception is a **critical date today**, which is its own type and its
 * own notification because it bypasses quiet hours (see `NotificationType`).
 * Folding it into the ordinary digest would either wake somebody for a date a
 * week away or hold the one that is today until morning.
 *
 * ## 3. A moved date reschedules itself, because nothing was scheduled
 *
 * There is no reminder table and no delayed job. The sweep asks, every
 * morning, *"what is owed today"* — so moving a date on Tuesday changes
 * Wednesday's answer with nothing to cancel, and the once-only guard is keyed
 * on **the date being reminded about** as well as the date itself. Move a
 * deadline and the reminder for the new day is a reminder that has not been
 * sent; move it back and yesterday's is still remembered.
 *
 * Closing or cancelling a deal cancels them the same way: the sweep reads
 * `Deal::open()`, so a closed deal is simply not asked about.
 */
class NotifyAboutKeyDates extends Command
{
    protected $signature = 'notifications:key-dates';

    protected $description = 'Tell the team about deadlines coming up, in their team’s morning';

    /** Local hour, 24h. The same one the task sweep uses, and for the same reason. */
    public const HOUR = 8;

    public function handle(TeamContext $teams, Notify $notify, NotificationAudience $audience): int
    {
        $told = 0;

        foreach (Team::query()->cursor() as $team) {
            $localHour = (int) Carbon::now()->setTimezone($team->timezone)->format('G');

            if ($localHour !== self::HOUR) {
                continue;
            }

            $told += (int) $teams->runFor(
                $team,
                fn (): int => $this->sweep($team, $notify, $audience),
            );
        }

        $this->components->info($told === 1
            ? 'Sent 1 deadline reminder.'
            : "Sent {$told} deadline reminders.");

        return self::SUCCESS;
    }

    private function sweep(Team $team, Notify $notify, NotificationAudience $audience): int
    {
        $today = CarbonImmutable::now($team->timezone)->startOfDay();

        /*
         * The furthest either schedule reaches, read off the constants rather
         * than repeated as a literal — so a product that later offers a longer
         * critical schedule needs no change here.
         *
         * `max()` over the whole list rather than its first element: the
         * constants happen to be sorted descending today, and a horizon that
         * silently depended on that ordering is a reminder that stops firing
         * the day somebody appends to the list instead of prepending.
         */
        $horizon = $today->addDays(max(
            max(KeyDate::CRITICAL_REMINDERS),
            max(KeyDate::DEFAULT_REMINDERS),
        ));

        $dates = KeyDate::query()
            /*
             * An extracted date nobody has agreed to is not a deadline (#116),
             * so nobody is reminded about one. Reminding would be the machine's
             * reading of a contract arriving in somebody's inbox as a fact.
             */
            ->confirmed()
            ->whereBetween('date', [$today->toDateString(), $horizon->toDateString()])
            // `KeyDate::scopeOnOpenDeals()`, which S59 and the calendar now
            // read too. This restated it, and they had no rule at all.
            ->onOpenDeals()
            ->with('deal')
            ->orderBy('date')
            ->get();

        /** @var array<string, list<KeyDate>> $due */
        $due = ['ordinary' => [], 'criticalToday' => []];

        foreach ($dates as $date) {
            $daysOut = Format::calendarDaysBetween($today, $date->date);

            if (! in_array($daysOut, $date->reminderDays(), true)) {
                continue;
            }

            /*
             * A critical date **today** is the one type that bypasses quiet
             * hours, so it cannot ride in the digest — folding it in would
             * either wake somebody for a date a week out or hold the one that
             * is today until morning.
             */
            $bucket = $daysOut === 0 && $date->is_critical ? 'criticalToday' : 'ordinary';

            $due[$bucket][] = $date;
        }

        if ($due['ordinary'] === [] && $due['criticalToday'] === []) {
            return 0;
        }

        /*
         * Who hears. A key date has no assignee — it is a fact about the deal,
         * not a piece of work owed — so the audience is the people who could
         * act on it, which `NotificationAudience` already answers for the
         * three team-facing types #101 shipped.
         */
        $people = $audience->holding($team, Permissions::VIEW_DEALS);

        $told = 0;

        foreach ($people as $person) {
            $told += $this->tellOrdinary($person, $team, $due['ordinary'], $today, $notify);

            foreach ($due['criticalToday'] as $date) {
                $told += $this->tellCritical($person, $team, $date, $notify);
            }
        }

        return $told;
    }

    /**
     * One digest per person, however many dates are in it (#109's aggregation).
     *
     * @param  list<KeyDate>  $dates
     */
    private function tellOrdinary(
        Person $person,
        Team $team,
        array $dates,
        CarbonImmutable $today,
        Notify $notify,
    ): int {
        $unsent = array_values(array_filter(
            $dates,
            fn (KeyDate $date): bool => ! $this->alreadyTold($person, $date, NotificationType::DeadlineApproaching),
        ));

        if ($unsent === []) {
            return 0;
        }

        $lines = array_map(
            fn (KeyDate $date): string => $this->summarise($date),
            $unsent,
        );

        $written = $notify->send(
            type: NotificationType::DeadlineApproaching,
            people: [$person],
            team: $team,
            summary: count($unsent) === 1
                ? $unsent[0]->name.' is '.Format::relativeDate($unsent[0]->date, $today)
                : Format::count(count($unsent), 'deadline').' coming up',
            /*
             * A digest spans deals, so it belongs to none of them. Attaching it
             * to the first would make the panel line link somewhere arbitrary
             * — and `Notification::url()` falls back to the notifications
             * screen, which is where a list of several belongs.
             */
            deal: count($unsent) === 1 ? $unsent[0]->deal : null,
            data: [
                'announced' => array_map(self::announcement(...), $unsent),
                /*
                 * S88's *"several dates"* state. Composed here rather than in
                 * the mailable because this is where the dates are, and
                 * `InternalAlertMail` is the one internal frame — a second
                 * mailable would be the *"second front door"* #97 records.
                 */
                'lines' => $lines,
                'emphasis' => $this->anyCritical($unsent),
            ],
        );

        return count($written);
    }

    private function tellCritical(Person $person, Team $team, KeyDate $date, Notify $notify): int
    {
        if ($this->alreadyTold($person, $date, NotificationType::CriticalDateToday)) {
            return 0;
        }

        return count($notify->send(
            type: NotificationType::CriticalDateToday,
            people: [$person],
            team: $team,
            summary: $date->name.' is today',
            deal: $date->deal,
            data: [
                'announced' => [self::announcement($date)],
                'lines' => [$this->summarise($date)],
                'emphasis' => true,
            ],
        ));
    }

    /**
     * Has this person already been told about this date, **on this day**?
     *
     * Keyed on the date's *current* value as well as its id, which is what
     * makes *"moving a date reschedules its reminders"* true with nothing
     * scheduled: a deadline moved from the 15th to the 22nd has never been
     * reminded about *for the 22nd*, so tomorrow's sweep tells somebody — and
     * moved back, the 15th is still remembered and does not repeat.
     *
     * The row is the record, so a queue flush or a missed day cannot make it
     * repeat. Same argument as `NotifyAboutDeadlines`, one column wider.
     *
     * ## One key per date, not two arrays that get compared independently
     *
     * A digest carries several dates, and the first version of this stored
     * their ids and their days as **parallel arrays**. Two `whereJsonContains`
     * over those match *"this id is somewhere in the row"* AND *"this day is
     * somewhere in the row"* — which is true of a row where the id and the day
     * belong to **different dates**.
     *
     * That is not theoretical, and it fails in the direction the feature
     * exists to prevent. One digest names *Inspection* on the 8th and
     * *Appraisal* on the 2nd. *Appraisal* then slips to the 8th. It has never
     * been announced for the 8th, but its id is in the row and so is that day,
     * so the sweep decides it has and says nothing — the moved deadline, which
     * is the one case the docblock above promises to catch.
     *
     * `id@day` in one array makes the pair the thing matched.
     */
    private function alreadyTold(Person $person, KeyDate $date, NotificationType $type): bool
    {
        return Notification::query()
            ->where('person_id', $person->getKey())
            ->where('type', $type->value)
            ->whereJsonContains('data->announced', self::announcement($date))
            ->exists();
    }

    /**
     * What a row records about one date: which date, and for which day.
     *
     * A ULID contains no `@`, and a `Y-m-d` contains no `@`, so the pair
     * round-trips unambiguously and Postgres can match it with one
     * containment check.
     */
    private static function announcement(KeyDate $date): string
    {
        return $date->getKey().'@'.$date->date->toDateString();
    }

    /**
     * One line of the digest: the date, when it is, and whose deal it is on.
     *
     * Deliberately **not** named `line()`. `Illuminate\Console\Command` has a
     * public `line()` for writing to the console, and a private override of it
     * is a fatal error at class-load — which PHPStan found before any test
     * could, because the class never loads far enough to run one.
     */
    private function summarise(KeyDate $date): string
    {
        $deal = $date->deal instanceof Deal ? ' · '.$date->deal->displayName() : '';

        return $date->name.' — '.Format::date($date->date).$deal;
    }

    /**
     * @param  list<KeyDate>  $dates
     */
    private function anyCritical(array $dates): bool
    {
        foreach ($dates as $date) {
            if ($date->is_critical) {
                return true;
            }
        }

        return false;
    }
}
