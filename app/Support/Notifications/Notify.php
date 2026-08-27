<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\Deal;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\Person;
use App\Models\Team;
use Carbon\CarbonInterface;

/**
 * The only writer of `notifications` (issue #101 · F12.4).
 *
 * ## One place, and issue #101 says why
 *
 * *"The channel fan-out belongs in one place, not scattered across the
 * features that raise notifications."* Five features raise these — a task
 * assignment, a gate clearing, an override, a failing automation, an
 * approaching deadline — and if each decided its own channels then a
 * preference somebody set on S78 would be honoured by four of them and
 * forgotten by the fifth, which is the shape `CLAUDE.md` records about
 * `gate_cleared` being *"wired to one implementation of a thing and therefore
 * to none of it"*.
 *
 * So a caller says **what happened and who should know**, and nothing else.
 * Whether that reaches somebody as a row, an email or a push is decided here,
 * once, from their preferences.
 *
 * ## The row is written now; the outbound channels may wait
 *
 * F12.4's quiet hours are *"nobody wants a 6am push"* — a rule about being
 * woken. A row appearing in a panel wakes nobody, so it is written
 * immediately, and `deliver_after` holds the email and the push until the
 * window opens. That is what makes *"delayed, not dropped"* true without a
 * second table, and it means somebody opening the app at 7am has already been
 * told rather than waiting on a sweep.
 *
 * ## Nobody is told about their own action
 *
 * The person who assigned the task, cleared the gate or performed the override
 * is filtered out. A notification telling somebody what they just did is the
 * fastest way to teach them the panel is noise, and the panel is the one
 * surface F12.4 relies on being read.
 */
final class Notify
{
    public function __construct(private readonly DeliverNotification $delivery) {}

    /**
     * @param  iterable<int, mixed>  $people
     * @param  array<string, mixed>  $data
     * @return list<Notification>
     */
    public function send(
        NotificationType $type,
        iterable $people,
        Team $team,
        string $summary,
        ?Deal $deal = null,
        array $data = [],
        ?Person $actor = null,
        ?CarbonInterface $at = null,
    ): array {
        $preferences = $this->preferencesFor($team);

        $written = [];

        foreach ($people as $person) {
            if (! $person instanceof Person) {
                continue;
            }

            // Never about your own action. See the class docblock.
            if ($actor instanceof Person && $person->is($actor)) {
                continue;
            }

            $preference = $preferences[$person->getKey()] ?? null;

            $channels = $preference instanceof NotificationPreference
                ? $preference->channelsFor($type)
                : [NotificationChannel::InApp, ...array_filter(
                    $type->defaultChannels(),
                    static fn (NotificationChannel $channel): bool => $channel->isOptional(),
                )];

            /*
             * Deliverable ones only. `push` is a stored preference before #103
             * exists — S78 refuses to offer it, but a row written by hand or
             * by a later migration should not make the worker try — and this
             * is the layer that knows what the build can actually do.
             */
            $channels = array_values(array_filter(
                $channels,
                static fn (NotificationChannel $channel): bool => $channel->availableFrom() === null,
            ));

            $holdUntil = $preference?->holdUntil($type, $team->timezone, $at);

            $notification = new Notification;

            $notification->forceFill([
                'team_id' => $team->getKey(),
                'person_id' => $person->getKey(),
                'type' => $type->value,
                'deal_id' => $deal?->getKey(),
                'summary' => $summary,
                'data' => $data,
                'channels' => array_map(
                    static fn (NotificationChannel $channel): string => $channel->value,
                    $channels,
                ),
                'deliver_after' => $holdUntil,
                /*
                 * Nothing owed is delivered the moment it is written, rather
                 * than left for a sweep to notice and mark. A row that is only
                 * ever a row has no outbound state to be in.
                 */
                'delivered_at' => $this->owesAnything($channels) ? null : ($at ?? now()),
            ])->save();

            $written[] = $notification;
        }

        /*
         * Dispatched after every row is written, and never inside a caller's
         * transaction — `AdvanceWorkflow::dispatchRaised()` is the boundary
         * that already exists for exactly this, and the finding behind it is
         * the same: a job picked up before the commit lands finds nothing.
         */
        foreach ($written as $notification) {
            if ($notification->deliver_after === null && $notification->delivered_at === null) {
                $this->delivery->dispatch($notification);
            }
        }

        return $written;
    }

    /**
     * Every preference in this team, in one query.
     *
     * A workflow instantiation assigns a dozen tasks at once, which is the
     * burst #101 names when it asks for grouping — and asking the same table
     * once per recipient inside it is the shape the budget tests refuse.
     *
     * @return array<string, NotificationPreference>
     */
    private function preferencesFor(Team $team): array
    {
        return NotificationPreference::query()
            ->where('team_id', $team->getKey())
            ->get()
            ->keyBy('person_id')
            ->all();
    }

    /**
     * @param  list<NotificationChannel>  $channels
     */
    private function owesAnything(array $channels): bool
    {
        foreach ($channels as $channel) {
            if ($channel->reachesOut()) {
                return true;
            }
        }

        return false;
    }
}
