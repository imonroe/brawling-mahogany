<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AutomationState;
use App\Models\ActionInstance;
use App\Support\Automation\ExecuteAction;
use App\Support\Tenancy\TeamContext;
use Illuminate\Console\Command;

/**
 * The outcome of a send nobody came back from (issue #92).
 *
 * ## Why this is a scheduled sweep and not a branch in the worker
 *
 * `ExecuteAction` claims `message_key` before calling the mailer, which is
 * what stops a client being emailed twice. The window that ordering accepts is
 * a row left `pending` **carrying a key**: the worker was killed between the
 * transport call and the state write — an OOM, a `queue:restart`, a container
 * eviction.
 *
 * Three rounds of review went into trying to decide that row's fate inside the
 * next worker, and it cannot be done. `pending` plus a key does not mean the
 * worker died; it means *some* worker claimed it and has not written its
 * outcome, and the commonest reading is a sibling inside `Mail::send` right
 * now. The obvious discriminator — how old the claim is — is unavailable,
 * because the second delivery happens *precisely because* the claim aged past
 * the queue's visibility timeout. Both shapes are the same age at the only
 * moment a worker looks. A threshold below it narrates failures about live
 * sends; one above it is never reached, because standing down completes the
 * job and there is no third delivery.
 *
 * Distance is the discriminator. Hours after the claim there is no live
 * sibling to contradict: a send that has not written its outcome by then is
 * not going to.
 *
 * ## What it does not do
 *
 * It does not resend. Nobody knows whether the message arrived, and the whole
 * reason `message_key` exists is that guessing wrong in that direction emails
 * a client twice. It records the outcome so a **person** can decide, on S49,
 * with the ambiguity stated rather than hidden.
 *
 * ## Unscoped, like the sweep beside it
 *
 * It runs for every team, so it cannot run inside one team's context. Each row
 * is handled inside `runFor()` on its own team, so everything the executor
 * writes — the state, the timeline entry — lands under the right tenant.
 */
class ReapUnconfirmedSends extends Command
{
    protected $signature = 'automations:reap-unconfirmed {--hours=6} {--limit=500}';

    protected $description = 'Record the outcome of sends that were handed to a transport and never confirmed';

    public function handle(ExecuteAction $actions, TeamContext $teams): int
    {
        /*
         * Six hours by default, and generous on purpose. The cost of waiting
         * is a row sitting on S47's Held list saying it is unconfirmed, which
         * is true. The cost of being hasty is telling a team a message failed
         * while it is being delivered — the failure mode this command exists
         * *because of*, so it must not reintroduce it at a shorter interval.
         */
        $olderThan = now()->subHours(max(1, (int) $this->option('hours')));

        $reaped = 0;

        ActionInstance::withoutTeamScope()
            ->where('state', AutomationState::Pending)
            ->whereNotNull('message_key')
            ->where('updated_at', '<=', $olderThan)
            /*
             * A row whose team is gone is excluded by the query rather than
             * skipped in the loop. Skipping left it matching on every
             * subsequent run — harmless below the limit, and at 500 such rows
             * at the head of the id order it would consume the whole page
             * every hour and live rows behind them would never be reached.
             * `records:purge` clears them within thirty days; until then they
             * are simply not this command's work.
             */
            ->whereHas('team')
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->eachById(function (ActionInstance $instance) use ($actions, $teams, &$reaped): void {
                $team = $instance->team;

                if ($team === null) {
                    // Unreachable behind `whereHas('team')` above, and cheap
                    // insurance against a team deleted between the page read
                    // and this call.
                    return;
                }

                $teams->runFor($team, fn () => $actions->reapUnconfirmed($instance));

                $reaped++;
            });

        $this->info("Recorded {$reaped} unconfirmed send(s).");

        return self::SUCCESS;
    }
}
