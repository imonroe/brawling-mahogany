<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Team;
use App\Support\Automation\AlertOnFailures;
use App\Support\Tenancy\TeamContext;
use Illuminate\Console\Command;

/**
 * S91 — the push half of a failure two screens already hold (#97 · F5.8).
 *
 * ## Why a sweep and not a hook on the failure
 *
 * Both reasons are review findings, and both are worth keeping.
 *
 * The first version raised the alert from `ExecuteAction::fail()` and **never
 * fired for the outage it was written about**: a transport exception is caught
 * in `send()`, recorded inline and re-thrown, so it never reaches `fail()` at
 * all. A thing wired to one implementation of a failure is wired to none of
 * it. A sweep reads `state`, which is `failed` however the row got there and
 * whatever branch a later slice adds.
 *
 * The second: fired on the failure, the alert went out **first**, when the
 * backlog was one. Forty messages died and one email said so about one of
 * them. A few minutes later the number is simply available.
 *
 * ## Unscoped, like the two sweeps beside it
 *
 * It runs for every team, so it cannot run inside one team's context. Each
 * team is handled inside `runFor()`, so the reads the alert makes — the
 * failures, the people who can approve messages — are scoped to the tenant
 * they are about.
 */
class AlertOnAutomationFailures extends Command
{
    protected $signature = 'automations:alert-on-failures';

    protected $description = 'Email each team about automations that have failed since they were last told';

    public function handle(AlertOnFailures $alerts, TeamContext $teams): int
    {
        $told = 0;

        /*
         * Every team, including ones with nothing wrong: the alert's own
         * high-water mark decides whether there is anything to say, and asking
         * per team is one indexed count. Filtering here would mean building
         * the same question twice and keeping the two in step.
         *
         * A soft-deleted team is excluded — `records:purge` will take its rows
         * within thirty days, and emailing about a deleted team's automations
         * is telling somebody about a thing they have already shut down.
         */
        Team::query()
            ->orderBy('id')
            ->eachById(function (Team $team) use ($alerts, $teams, &$told): void {
                if ($teams->runFor($team, fn (): bool => $alerts->sweep($team))) {
                    $told++;
                }
            });

        $this->info("Alerted {$told} team(s).");

        return self::SUCCESS;
    }
}
