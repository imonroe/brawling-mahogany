<?php

declare(strict_types=1);

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
