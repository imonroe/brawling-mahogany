<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\RunsForTeam;
use App\Models\ActionInstance;
use App\Support\Automation\ExecuteAction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One action instance, carried out off the request (issue #92).
 *
 * Thin on purpose: it re-establishes the team, finds the row, and hands it to
 * {@see ExecuteAction}. Everything that could go wrong for a client lives in
 * the service, where `SingleMutationPathTest` can see it and where the
 * scheduler's caller reaches the identical code.
 *
 * ## Why an id and not the model
 *
 * `SerializesModels` would restore the row as it stood at dispatch, and the
 * gap between dispatch and execution is exactly where a person cancels the
 * deal, disables sending, or approves the message. The row is re-read here so
 * the rails see what is true now — which is the whole reason issue #96 puts
 * them in the worker.
 *
 * ## Unique, and the window matters more than the flag
 *
 * `ShouldBeUnique` stops the obvious double-dispatch, and it is **not** the
 * idempotency guarantee — a lock that expires, a queue driver without one, or
 * two dispatches either side of the window all get past it. The guarantee is
 * `action_instances.message_key`, claimed with a conditional UPDATE inside
 * `ExecuteAction`. This is the cheap first door, not the lock.
 */
class RunAutomation implements ShouldBeUnique, ShouldQueue
{
    use Queueable, RunsForTeam;

    /**
     * Four attempts and then the row's own record of the failure stands.
     *
     * A transport that threw may already have delivered, so a retry only ever
     * finds `message_key` set and stops. What the retries are actually for is
     * the transport that was briefly unreachable — the case where nothing left
     * the building at all.
     */
    public int $tries = 4;

    public function __construct(public readonly string $instanceId) {}

    public function uniqueId(): string
    {
        return $this->instanceId;
    }

    public function handle(ExecuteAction $actions): void
    {
        $this->withinTeam(function ($team) use ($actions): void {
            $instance = ActionInstance::query()->find($this->instanceId);

            if (! $instance instanceof ActionInstance) {
                // Purged, or cancelled and swept. Not an error: the record of
                // what happened is the absence of the row.
                return;
            }

            $actions->handle($instance, $team);
        });
    }
}
