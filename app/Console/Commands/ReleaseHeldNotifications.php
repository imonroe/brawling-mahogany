<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Notification;
use App\Support\Notifications\DeliverNotification;
use Illuminate\Console\Command;

/**
 * What quiet hours held, once the window opens (F12.4 · issue #101).
 *
 * ## Why *"delayed, not dropped"* needs a sweep at all
 *
 * The alternative is a delayed job, and it is worse in a way that matters
 * here: a queue that loses its backlog — a Redis restart, a flush, a worker
 * fleet replaced during a deploy — loses every held notification silently,
 * and the person they were for never learns anything was owed. The row is the
 * record, `deliver_after` is on it, and a sweep over the table cannot lose
 * what a queue forgot. Same argument `AlertOnFailures` makes about keeping its
 * watermark on `teams` rather than in the cache.
 *
 * ## Unscoped, like the sweeps beside it
 *
 * It runs for every team, so it cannot run inside one. Each notification's own
 * `team_id` is carried into the job, which re-establishes the tenant before
 * anything is read — the same shape `AlertOnAutomationFailures` uses.
 */
class ReleaseHeldNotifications extends Command
{
    protected $signature = 'notifications:release-held {--limit=500}';

    protected $description = 'Queue notifications that quiet hours held, now that their window has closed';

    public function handle(DeliverNotification $delivery): int
    {
        $limit = max(1, (int) $this->option('limit'));

        /*
         * Oldest first and bounded. The bound is a **throughput** limit rather
         * than a correctness one: what is not taken this minute is taken the
         * next, because nothing here moves the row until it is dispatched. A
         * cap that silently dropped the tail would be the `LIMIT` failure
         * `AlertOnFailures` records; this one just runs again.
         */
        $held = Notification::withoutTeamScope()
            ->due()
            ->whereNotNull('deliver_after')
            ->orderBy('deliver_after')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($held as $notification) {
            $delivery->dispatch($notification);
        }

        $this->components->info($held->count() === 1
            ? 'Released 1 held notification.'
            : "Released {$held->count()} held notifications.");

        return self::SUCCESS;
    }
}
