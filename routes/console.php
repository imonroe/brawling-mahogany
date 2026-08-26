<?php

declare(strict_types=1);

use App\Console\Commands\DispatchDueAutomations;
use App\Console\Commands\PurgeSoftDeletedRecords;
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
