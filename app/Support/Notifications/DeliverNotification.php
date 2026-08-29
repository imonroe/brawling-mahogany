<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Jobs\DeliverNotification as DeliverNotificationJob;
use App\Models\Notification;

/**
 * The one thing that puts a notification on a queue (issue #101).
 *
 * A seam rather than a call: {@see Notify} would otherwise reach for a job
 * class directly, and every test of the fan-out would then be a test of the
 * queue as well. It is also where the *"after the transaction, never inside
 * it"* rule has somewhere to live if a caller ever needs to hold dispatch
 * back — `AdvanceWorkflow::dispatchRaised()` is the shape.
 */
class DeliverNotification
{
    public function dispatch(Notification $notification): void
    {
        $job = (new DeliverNotificationJob((string) $notification->getKey()))
            ->forTeam($notification->team_id);

        dispatch($job)
            /*
             * **After the caller's transaction commits**, and settled here
             * rather than asked of every caller.
             *
             * `AdvanceWorkflow::dispatchRaised()` makes the same guarantee by
             * having exactly one dispatch site outside its transaction, which
             * works because it has one caller. Notifications are raised from a
             * model hook, a workflow advance, a console sweep and a queue
             * worker, and *"remember to dispatch outside the transaction"* is
             * a rule the fourth caller is written without — `CLAUDE.md`
             * records that shape twice already.
             *
             * With no transaction open this is a plain dispatch, so the
             * console and the worker pay nothing for it.
             */
            ->afterCommit();
    }
}
