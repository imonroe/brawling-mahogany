<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AutomationActionType;
use App\Enums\AutomationState;
use App\Jobs\RunAutomation;
use App\Models\ActionInstance;
use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

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

    /**
     * Teams whose outbound email is currently held by a rail.
     *
     * The kill switch and both ceilings, asked the way `SendRails` asks them
     * so the sweep and the worker cannot disagree about who is held. Counted
     * per team in one grouped query rather than per row: the sweep runs every
     * minute and the alternative is two counts per queued message.
     *
     * A team that is merely *near* its ceiling is not held — the worker will
     * send what fits and halt the rest, which is the behaviour F5.9 describes.
     *
     * @return list<string>
     */
    private function teamsWhoseEmailIsHeld(): array
    {
        /*
         * `toBase()` rather than hydrating: these rows are two counts and a
         * key, not `ActionInstance`s, and an Eloquent model carrying
         * aggregate columns is a model with properties nothing declares.
         */
        $sent = ActionInstance::withoutTeamScope()
            ->where('state', AutomationState::Sent)
            ->where('action_type', AutomationActionType::SendEmail)
            ->where('executed_at', '>=', now()->subDay())
            ->groupBy('team_id')
            ->toBase()
            ->selectRaw('team_id')
            ->selectRaw('count(*) filter (where executed_at >= ?) as hourly', [now()->subHour()])
            ->selectRaw('count(*) as daily')
            ->get();

        /** @var array<string, array{hourly: int, daily: int}> $counts */
        $counts = [];

        foreach ($sent as $row) {
            $counts[(string) $row->team_id] = [
                'hourly' => (int) $row->hourly,
                'daily' => (int) $row->daily,
            ];
        }

        $held = [];

        foreach (Team::query()->get(['id', 'sends_disabled_at', 'hourly_send_limit', 'daily_send_limit']) as $team) {
            $id = (string) $team->getKey();

            if ($team->sendsAreDisabled()) {
                $held[] = $id;

                continue;
            }

            $seen = $counts[$id] ?? null;

            if ($seen === null) {
                continue;
            }

            if ($seen['hourly'] >= $team->hourly_send_limit || $seen['daily'] >= $team->daily_send_limit) {
                $held[] = $id;
            }
        }

        return $held;
    }

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $dispatched = 0;

        /*
         * Teams whose **emails** cannot go out right now are not asked again
         * every minute.
         *
         * Not a weakening of F5.9 — `SendRails` still decides in the worker,
         * which is what issue #96 requires for anything already in flight.
         * This is about the sweep: a team holding 500 queued messages behind a
         * rail was generating 720,000 no-op jobs a day, each doing a team
         * lookup and two counts, for as long as the rail held. A rail that
         * holds is not a reason to go on knocking on it.
         *
         * **Both rails that halt, not just the switch.** The first version
         * skipped `sends_disabled_at` alone and left the ceiling case
         * untouched — a team that hits its daily limit was still swept every
         * minute for the rest of the day, and removing the `attempts`
         * increment removed the only column that recorded it. Half a fix,
         * and the half that fires more often.
         *
         * **And only the action type the rails are about.** `ExecuteAction`
         * routes `create_task` straight past `SendRails`, so a stranded task
         * for a halted team must still be swept — it reaches nobody outside
         * the team. This is the narrowing the ceiling already carries, applied
         * to the sweep that stands in front of it.
         */
        $held = $this->teamsWhoseEmailIsHeld();

        ActionInstance::withoutTeamScope()
            ->when($held !== [], fn (Builder $query): Builder => $query->where(
                fn (Builder $inner): Builder => $inner
                    ->whereNotIn('team_id', $held)
                    ->orWhere('action_type', '!=', AutomationActionType::SendEmail->value),
            ))
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
            /*
             * Ordered by id rather than by `created_at`, because `eachById`
             * pages on the id and strips only id orderings — leaving both
             * would give an id-keyed cursor walking a `created_at` ordering,
             * which skips rows once the limit exceeds one page. The key is a
             * ULID, so id order **is** creation order to the millisecond.
             */
            ->orderBy('id')
            ->limit($limit)
            /*
             * `eachById` rather than `each`, and the difference only shows on
             * a `sync` queue — which is local development and some CI shapes.
             * `each()` pages by **offset** over a result set the dispatched
             * job removes rows from, so once the first page's sends complete
             * inline, the second page's offset skips exactly as many rows as
             * were just handled and those instances are silently never
             * dispatched. Paging by id cannot skip.
             */
            ->eachById(function (ActionInstance $instance) use (&$dispatched): void {
                dispatch((new RunAutomation($instance->getKey()))->forTeam($instance->team_id));
                $dispatched++;
            });

        $this->info("Queued {$dispatched} automation instance(s).");

        return self::SUCCESS;
    }
}
