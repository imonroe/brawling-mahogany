<?php

declare(strict_types=1);

use App\Console\Commands\AlertOnAutomationFailures;
use App\Console\Commands\DispatchDueAutomations;
use App\Console\Commands\NotifyAboutDeadlines;
use App\Console\Commands\NotifyAboutKeyDates;
use App\Console\Commands\PurgeSoftDeletedRecords;
use App\Console\Commands\ReapUnconfirmedSends;
use App\Console\Commands\ReleaseHeldNotifications;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * PRD §9: soft delete with a 30-day recovery window, then a hard delete.
 *
 * Nightly and off-peak. A purge that has not run for a week is a retention
 * obligation quietly unmet, so `withoutOverlapping` keeps a slow run from
 * stacking rather than from happening.
 */
Schedule::command(PurgeSoftDeletedRecords::class)
    ->dailyAt('03:15')
    ->withoutOverlapping()
    ->onOneServer();

/*
 * The automation sweep (#92).
 *
 * Every minute, because the two things it catches are both about latency a
 * client would notice: a scheduled message that is now due, and one stranded
 * by a web process that died between committing the advance and dispatching
 * the job. `withoutOverlapping` because a slow run must not stack, and
 * `onOneServer` because two schedulers picking up the same instance would
 * queue it twice — survivable (the claim on `message_key` makes only one of
 * them send) and pointless.
 */
Schedule::command(DispatchDueAutomations::class)
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

/*
 * The outcome of a send nobody came back from (#92).
 *
 * Hourly rather than every minute, and that cadence is the point: this
 * command's whole job is to decide at a distance from the claim, where no live
 * worker can be contradicted. Running it often would put it back inside the
 * window it exists to stay out of.
 */
Schedule::command(ReapUnconfirmedSends::class)
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

/*
 * S91's internal alert (#97).
 *
 * Every five minutes, and the cadence carries the whole design. It is not
 * hooked to a failure, because a thing wired to one implementation of a
 * failure is wired to none of it — a transport exception never reaches
 * `ExecuteAction::fail()`, which is where the first version listened, and that
 * is the outage the alert exists for. It reads `state` instead, which is
 * `failed` however the row got there.
 *
 * Five minutes rather than one, because the second thing the delay buys is a
 * true number: fired on the failure itself, the alert went out when the
 * backlog was one, so forty dead messages produced one email reporting one.
 * Five minutes is late enough to have counted the burst and early enough that
 * somebody is still at their desk.
 */
Schedule::command(AlertOnAutomationFailures::class)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Deadline reminders (#101).
 *
 * **Hourly**, and the command decides which teams are in scope — each is
 * handled only in the hour that is 8am locally, because a reminder is a thing
 * somebody reads with their coffee and PRD §9's display-in-the-team's-timezone
 * applies to a decision as much as to a rendering. A daily schedule would have
 * to pick one zone and be wrong for every other team on the platform.
 */
Schedule::command(NotifyAboutDeadlines::class)
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Deadline reminders ahead of a **key date** (#109 · F8.4).
 *
 * Hourly and team-local for the same reason its sibling above is, and a
 * separate command rather than a second loop inside it: the two sweep
 * different tables, aggregate differently (one notification per task, one
 * *digest* per person), and fail independently. A long title in one team's
 * tasks must not stop the deadline that has legal consequences from being
 * announced.
 */
Schedule::command(NotifyAboutKeyDates::class)
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

/*
 * What quiet hours held (#101).
 *
 * Every five minutes, which is the resolution F12.4 actually needs: the rule
 * is *"nobody wants a 6am push"*, and a person whose window ends at seven does
 * not care whether their email arrives at 07:00 or 07:04. Every minute would
 * be a sweep of a table that is empty for most of the day.
 *
 * A sweep rather than a delayed job, because a queue that loses its backlog
 * loses every held notification silently and the person they were for never
 * learns anything was owed. `notifications.deliver_after` is the record; the
 * queue is only how it travels.
 */
Schedule::command(ReleaseHeldNotifications::class)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();
