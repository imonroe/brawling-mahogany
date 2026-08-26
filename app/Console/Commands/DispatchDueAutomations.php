<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AutomationActionType;
use App\Enums\AutomationState;
use App\Jobs\RunAutomation;
use App\Models\ActionInstance;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Messages\ResolveRecipients;
use App\Support\Tenancy\TeamContext;
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
     * Whether sandbox mode has somebody to redirect to.
     *
     * Asked the way `SendRails::teamOwnerAddress()` asks it, through the same
     * resolver, so the sweep and the worker cannot disagree about which teams
     * are stuck. One query per sandboxed team with work waiting — and the set
     * is already bounded by the work, so on a platform with nothing queued
     * this is never reached.
     */
    private function hasAnOwnerToRedirectTo(Team $team): bool
    {
        /*
         * **Inside that team's context**, and this is the trap it fell into
         * first. `TeamMembership` is team-scoped, and this command runs with
         * no tenant at all — so asking outside a context returns nothing for
         * every team, which reads as *"no owner to redirect to"* and would
         * have held every sandboxed team on the platform forever. In a test,
         * where a context happens to be resolved, it would have quietly
         * answered about the wrong team instead.
         *
         * `ExecuteAction` reaches the same resolver from inside `withinTeam()`
         * for the same reason. The sweep is unscoped about *which rows exist*
         * and scoped about everything it then asks.
         */
        return $this->teams->runFor($team, fn (): bool => app(ResolveRecipients::class)
            ->teamOwners($team)
            ->contains(fn (TeamMembership $membership): bool => ($membership->email ?? '') !== ''));
    }

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
     * **Asked only about teams with something waiting.** The first version
     * read every team on the platform every sixty seconds, whether or not
     * anything was due, and built a `whereNotIn` list that only ever grew —
     * every team that has *ever* left the kill switch on, forever, against
     * Postgres's 65,535-parameter ceiling. The set that matters is bounded by
     * the work, not by the tenancy, so the work names it.
     *
     * @param  list<string>  $teams  the teams with work waiting, and only those
     * @return list<string>
     */
    private function teamsWhoseEmailIsHeld(array $teams): array
    {
        if ($teams === []) {
            return [];
        }

        /*
         * `toBase()` rather than hydrating: these rows are two counts and a
         * key, not `ActionInstance`s, and an Eloquent model carrying
         * aggregate columns is a model with properties nothing declares.
         */
        $sent = ActionInstance::withoutTeamScope()
            ->whereIn('team_id', $teams)
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

        foreach (Team::query()->whereKey($teams)->get(['id', 'sends_disabled_at', 'sandbox_mode', 'hourly_send_limit', 'daily_send_limit']) as $team) {
            $id = (string) $team->getKey();

            if ($team->sendsAreDisabled()) {
                $held[] = $id;

                continue;
            }

            /*
             * The **third** halting rail, and the one that stayed behind
             * through two rounds of fixing the other two. `SendRails` halts
             * when sandbox mode is on and no owner has an email address to
             * redirect to — and `sandbox_mode` defaults **on** for a new team,
             * which is exactly the population whose owner membership is most
             * likely to have no `email` yet. Those rows were re-dispatched
             * every sixty seconds forever, each one a team lookup, two counts,
             * and a `save()` writing the same sentence back.
             */
            if ($team->sandbox_mode && ! $this->hasAnOwnerToRedirectTo($team)) {
                $held[] = $id;

                continue;
            }

            /*
             * A default rather than a `continue`, and the difference is a real
             * one: `SendRails::ceilingReached()` has no early exit, so it asks
             * `0 >= $limit` and halts a team whose limit is zero. Skipping the
             * comparison here made the sweep and the rail disagree in exactly
             * the configuration where the ceiling is most aggressive — swept
             * every minute, halted every minute, with `attempts` no longer
             * recording it.
             */
            $seen = $counts[$id] ?? ['hourly' => 0, 'daily' => 0];

            if ($seen['hourly'] >= $team->hourly_send_limit || $seen['daily'] >= $team->daily_send_limit) {
                $held[] = $id;
            }
        }

        return $held;
    }

    public function __construct(private readonly TeamContext $teams)
    {
        parent::__construct();
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
        /*
         * Which teams have anything waiting at all. One `distinct`, and it is
         * what bounds everything below: with nothing due this is empty and the
         * sweep asks the rails nothing.
         */
        $waiting = array_values(array_unique(
            ActionInstance::withoutTeamScope()
                ->due()
                ->where('created_at', '<=', now()->subMinute())
                ->distinct()
                ->pluck('team_id')
                ->map(fn (mixed $id): string => (string) $id)
                ->all(),
        ));

        $held = $this->teamsWhoseEmailIsHeld($waiting);

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
