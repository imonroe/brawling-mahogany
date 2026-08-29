<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Enums\NotificationChannel;
use App\Logging\Redactor;
use App\Mail\InternalAlertMail;
use App\Models\Notification;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Push\SendPush;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The outbound half of a notification (issue #101).
 *
 * The row already exists and the panel already shows it; this is only the
 * email and, from #103, the push. Which is why almost nothing here is fatal:
 * a person who cannot be emailed has still been told.
 *
 * ## `InternalAlertMail`, not a second mailable
 *
 * S91's alert already renders exactly this shape — a headline, a sentence, and
 * one link — in the team's own frame. Adding a `NotificationMail` beside it
 * would be `CLAUDE.md`'s S87 finding: *"the second front door is the one you
 * cut for a layout."* Two mailables would drift, and `EmailIndependence` would
 * have a second entry to justify for the same fact.
 */
class SendNotification
{
    public function __construct(private readonly SendPush $push) {}

    /**
     * Deliver what this notification owes, once.
     *
     * ## Claimed before anything is sent
     *
     * `delivered_at` is set with a conditional UPDATE **before** the mailer is
     * called, and a worker that loses the claim stands down silently. Same
     * shape and same reason as `action_instances.message_key`: a queue runs a
     * job twice, and the second run must find the door shut rather than send a
     * second email.
     *
     * The cost is the mirror image of the automation's: a send that throws
     * after the claim is not retried, so somebody misses an *email about* work
     * rather than a client missing a message. That trade points the opposite
     * way here, and deliberately — the row is still in their panel.
     */
    public function deliver(string $notificationId): void
    {
        $notification = Notification::query()->find($notificationId);

        if (! $notification instanceof Notification) {
            // Purged, or the person's account went. The absence is the record.
            return;
        }

        if ($notification->outboundChannels() === []) {
            return;
        }

        $claimed = Notification::query()
            ->whereKey($notification->getKey())
            ->whereNull('delivered_at')
            ->update(['delivered_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

        if ($claimed === 0) {
            return;
        }

        foreach ($notification->outboundChannels() as $channel) {
            match ($channel) {
                NotificationChannel::Email => $this->email($notification),
                /*
                 * #103. Named rather than left to a catch-all, so adding the
                 * sender was adding a method here instead of discovering that
                 * a channel somebody could already choose reached nobody —
                 * which is what this arm returning `null` meant for one slice.
                 */
                NotificationChannel::Push => $this->push->send($notification),
                NotificationChannel::InApp => null,
            };
        }
    }

    private function email(Notification $notification): void
    {
        $team = $notification->team;

        if (! $team instanceof Team) {
            return;
        }

        $membership = TeamMembership::query()
            ->where('team_id', $team->getKey())
            ->where('person_id', $notification->person_id)
            ->active()
            ->first();

        $address = $membership?->email;

        if (! is_string($address) || $address === '') {
            /*
             * Somebody with no address recorded, or whose membership has been
             * revoked since the row was written. Not an error and not worth a
             * log line with a person in it: the notification is in their panel
             * if they can still reach it, and gone with the membership if they
             * cannot.
             */
            return;
        }

        try {
            Mail::to([new Address($address, $membership->fullName())])->send(new InternalAlertMail(
                team: $team,
                headline: $notification->summary,
                detail: $notification->type->description(),
                actionUrl: url($notification->url() ?? '/notifications'),
                actionLabel: $notification->deal_id === null ? 'Open the app' : 'Open the deal',
                /*
                 * S88's *"several dates"* state (#109). Composed by whoever
                 * raised the notification, because that is where the facts
                 * are — this class knows nothing about key dates and should
                 * not have to. Empty for every other type, so the list simply
                 * does not render.
                 */
                lines: $this->lines($notification),
                emphasis: (bool) ($notification->data['emphasis'] ?? false),
            ));
        } catch (Throwable $failure) {
            /*
             * The class and nothing else. PRD §9: no PII in logs, and a mail
             * transport routinely quotes the address it refused. Swallowed
             * rather than re-thrown, unlike `ExecuteAction`: this is an email
             * about work rather than to a client, the panel already holds the
             * record, and a throw here would retry the whole delivery and try
             * the other channels a second time.
             */
            Log::warning('notification.email_failed', Redactor::context([
                'exception' => $failure::class,
                'type_code' => $notification->type->value,
            ]));
        }
    }

    /**
     * Supporting lines a notification carried, as strings and only as strings.
     *
     * Read defensively rather than trusted: `data` is a JSONB bag, and a row
     * written by a later version of the product — or restored from a backup —
     * may hold a shape this build does not expect. Rendering whatever is there
     * would put an array into a Blade `{{ }}` and take the email down.
     *
     * @return list<string>
     */
    private function lines(Notification $notification): array
    {
        $lines = [];

        foreach ((array) ($notification->data['lines'] ?? []) as $line) {
            if (is_string($line) && trim($line) !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
