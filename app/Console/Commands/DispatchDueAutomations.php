<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RunAutomation;
use App\Models\ActionInstance;
use Illuminate\Console\Command;

/**
 * The sweep that makes a queued message eventually happen (issue #92).
 *
 * Two jobs, and the second is the one worth having:
 *
 *  1. A **scheduled** instance — `scheduled_for` in the future — has nobody
 *     dispatching it at raise time. #106's date-based triggers are what fill
 *     that column; today nothing does, and the sweep is already here so that
 *     landing them is a trigger and not a trigger plus a delivery mechanism.
 *  2. A **stranded** one. An instance is raised inside a transaction and
 *     dispatched after it commits, which is the correct order and leaves a
 *     window: a web process killed between the commit and the dispatch leaves
 *     a `pending` row nothing is coming for. Without this, the message simply
 *     never goes, and PRD §1.1's *"has the client been told?"* gets the worst
 *     possible answer — silence, with no failure anywhere to find.
 *
 * ## Unscoped, deliberately, and it is the one place that is right
 *
 * The sweep runs for every team, so it cannot run inside one team's context.
 * It reads `team_id` off each row and hands it to the job, which re-establishes
 * the scope before touching anything — the same shape `PurgeSoftDeletedRecords`
 * uses, and the reason `RunsForTeam` takes an explicit id rather than
 * inferring one.
 *
 * ## It dispatches; it never sends
 *
 * Nothing here decides whether a message may go out. `SendRails` does, in the
 * worker, immediately before the transport — issue #96 requires exactly that,
 * because a rail checked anywhere earlier is a rail that a message queued five
 * minutes before somebody pulled the cord sails straight past.
 */
class DispatchDueAutomations extends Command
{
    protected $signature = 'automations:dispatch-due {--limit=500}';

    protected $description = 'Queue automation instances that are due and have nothing coming for them';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $dispatched = 0;

        ActionInstance::withoutTeamScope()
            ->due()
            /*
             * A moment old, so this cannot race the dispatch that is about to
             * happen. An instance raised half a second ago is being handed to
             * the queue by the request that raised it; picking it up here as
             * well would double-dispatch it — survivable, because the
             * conditional claim on `message_key` makes only one of them send,
             * but a second job per message for no reason.
             */
            ->where('created_at', '<=', now()->subMinute())
            ->orderBy('created_at')
            ->limit($limit)
            ->each(function (ActionInstance $instance) use (&$dispatched): void {
                dispatch((new RunAutomation($instance->getKey()))->forTeam($instance->team_id));
                $dispatched++;
            });

        $this->info("Queued {$dispatched} automation instance(s).");

        return self::SUCCESS;
    }
}
