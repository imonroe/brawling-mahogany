<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\RunsForTeam;
use App\Support\Notifications\SendNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The email and the push, off the request (issue #101).
 *
 * Thin, like `RunAutomation` beside it and for the same reasons: an id rather
 * than the model, so the row is re-read and a notification marked read — or a
 * person removed from the team — between dispatch and execution is seen as it
 * is now; and everything that could reach somebody lives in the service where
 * one test can find it.
 *
 * **Not `ShouldBeUnique`.** The guarantee is `notifications.delivered_at`,
 * claimed with a conditional UPDATE in {@see SendNotification}, and a unique
 * lock beside it would look like the guarantee while being a lock that
 * expires. Same argument `RunAutomation` makes about `message_key`.
 */
class DeliverNotification implements ShouldQueue
{
    use Queueable, RunsForTeam;

    /**
     * Twice, and then the row stands as undelivered.
     *
     * Fewer than the automation's four: nothing here is client-facing, the
     * in-app record already exists whatever happens, and a notification email
     * that arrives on the third retry twenty minutes later is a notification
     * about work that has moved on.
     */
    public int $tries = 2;

    public function __construct(public readonly string $notificationId) {}

    public function handle(SendNotification $sender): void
    {
        $this->withinTeam(fn () => $sender->deliver($this->notificationId));
    }
}
