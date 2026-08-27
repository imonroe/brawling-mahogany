<?php

declare(strict_types=1);

namespace App\Support\Delivery;

use App\Enums\DeliveryStatus;
use App\Models\Deal;
use App\Models\MessageDelivery;
use App\Models\Team;
use App\Support\Activity\RecordActivity;
use App\Support\Tenancy\TeamContext;
use Illuminate\Support\Collection;

/**
 * Applying what SES said to the rows it is about (#95 · PRD §4.5 F5.8).
 *
 * ## The one lookup in the product that starts without a tenant
 *
 * A webhook arrives from Amazon carrying a message id and nothing else — no
 * session, no team, no way to know whose message it is until the row is found.
 * So the **find** is unscoped and the **write** is not: the rows are located
 * by `provider_message_id`, then everything that follows runs inside
 * `TeamContext::runFor()` for the team that owns them.
 *
 * That split is the whole of why this is a sanctioned use of the escape hatch
 * (`UnscopedQueryConventionTest`, kind 2 — *a context with no tenant*). The
 * unscoped query reads one indexed column and returns rows already keyed to
 * one team; nothing downstream of it is unscoped, so a timeline entry cannot
 * land on the wrong deal and a suppression cannot be attributed to the wrong
 * team.
 *
 * ## Idempotent by construction, not by a ledger
 *
 * SNS delivers at least once and in no particular order. Nothing here counts,
 * appends or remembers a notification id:
 *
 *  - {@see MessageDelivery::advanceTo()} ranks the statuses, so a replayed
 *    Delivery cannot walk a Bounce backwards and a duplicate writes nothing.
 *  - {@see Suppression::record()} is a unique index, so the second bounce for
 *    an address is a no-op that returns `false`.
 *  - The timeline entry is written only when one of the two above actually
 *    changed something, so three copies of a bounce produce one entry.
 *
 * A ledger of seen ids would need its own retention, its own purge, and its
 * own answer to *"what if we lost it"*. Making the writes idempotent needs
 * none of that.
 */
final class ApplyDeliveryEvent
{
    public function __construct(
        private readonly TeamContext $teams,
        private readonly Suppression $suppression,
        private readonly RecordActivity $activity,
    ) {}

    /**
     * @return int how many delivery rows this event actually changed
     */
    public function apply(DeliveryEvent $event): int
    {
        /*
         * Unscoped, and only this. See the class docblock: the webhook has no
         * tenant to run under until these rows say which one it is.
         */
        $deliveries = MessageDelivery::withoutTeamScope()
            ->where('provider_message_id', $event->providerMessageId)
            ->get();

        if ($deliveries->isEmpty()) {
            return 0;
        }

        $addresses = $this->addressesIn($event);

        $changed = 0;

        foreach ($deliveries->groupBy('team_id') as $teamId => $rows) {
            /*
             * **`withTrashed()`**, and round 1 of review is why. `Team` soft-
             * deletes, so a team inside its 30-day purge window came back null
             * and the whole notification was dropped: no suppression, no
             * timeline, a 200 back to Amazon. The address then stayed writable
             * for every other team on the platform, which is the one thing
             * this table exists to prevent — a tenant's lifecycle deciding a
             * fact that is not the tenant's.
             *
             * Writing to a soft-deleted team's own rows is harmless: they go
             * with it when `records:purge` finishes the job.
             */
            $team = Team::withTrashed()->find($teamId);

            if (! $team instanceof Team) {
                continue;
            }

            $changed += (int) $this->teams->runFor(
                $team,
                fn (): int => $this->applyWithin($event, $rows, $addresses, $team),
            );
        }

        return $changed;
    }

    /**
     * @param  Collection<int, MessageDelivery>  $rows
     * @param  array<string, string|null>|null  $addresses
     */
    private function applyWithin(
        DeliveryEvent $event,
        Collection $rows,
        ?array $addresses,
        Team $team,
    ): int {
        $changed = 0;

        foreach ($rows as $delivery) {
            $key = mb_strtolower($delivery->recipient_email);

            /*
             * A null `$addresses` means the event named nobody — which a
             * well-formed SES payload does not do, and a truncated one might.
             * Applying it to every copy of the message would turn one
             * unparseable notification into a bounce against three clients, so
             * an event with no recipients is applied to none of them.
             */
            if ($addresses === null || ! array_key_exists($key, $addresses)) {
                continue;
            }

            $advanced = $delivery->advanceTo($event->status, $event->at, $addresses[$key]);

            /*
             * **Suppression is not gated on the row moving**, which round 1 of
             * review measured as a lost hard bounce: a `Transient` bounce puts
             * the row at `bounced`, and the `Permanent` bounce that follows
             * for the same recipient does not advance it — so under the first
             * version the address was never suppressed, `apply()` returned 0,
             * and the webhook answered 200.
             *
             * The two questions are different. *"Has this row changed?"* is
             * about one message; *"is this mailbox dead?"* is about an address
             * every team on the platform writes to. `Suppression::record()`
             * is idempotent in its own right — a unique index — so asking it
             * every time costs a lookup and closes the gap.
             */
            $suppressed = $event->suppresses !== null
                && $this->suppression->record(
                    email: $delivery->recipient_email,
                    reason: $event->suppresses,
                    detail: $addresses[$key],
                    discoveredByTeamId: $team->getKey(),
                    /*
                     * The provider's own clock, which is what decides whether
                     * this bounce can undo an operator's lift. A replayed
                     * notification carries the timestamp of the original
                     * event, so it cannot reverse a decision taken after it.
                     */
                    occurredAt: $event->at,
                );

            if (! $advanced && ! $suppressed) {
                // A replay: nothing about the message or the address is new.
                continue;
            }

            $changed++;

            $this->announce($delivery, $event, $suppressed);
        }

        return $changed;
    }

    /**
     * The team's own record that a message did not land.
     *
     * ADR 0003's second door, and the reason it is a **timeline** entry rather
     * than only an email: an alert can be missed, filtered, or sent to somebody
     * who has left, and the question *"has the client been told?"* is asked on
     * the deal months later. A bounce that exists only in an alert is a bounce
     * nobody can look up.
     *
     * Only failures. A delivery confirmation on every automated message would
     * bury a deal's timeline under the mechanics of its own correspondence,
     * and PRD §12.2 measures delivery off `message_deliveries` rather than off
     * the timeline.
     */
    private function announce(MessageDelivery $delivery, DeliveryEvent $event, bool $suppressed): void
    {
        if (! $event->status->isFailure()) {
            return;
        }

        $instance = $delivery->actionInstance;
        $deal = $instance->deal;

        if (! $deal instanceof Deal) {
            return;
        }

        $what = $event->status === DeliveryStatus::Complained
            ? 'was marked as spam by the person it was sent to'
            : 'could not be delivered';

        $this->activity->record(
            /*
             * **The deal, not the instance**, and `ActivityFeedIsolationTest`
             * is what says so: `ActivityFeed::subjectPermissions()` is an
             * allowlist, and a subject type with no rule in it is hidden from
             * everybody. Subjecting the `ActionInstance` wrote an entry no
             * screen in the product could render — a bounce recorded and
             * invisible, which is worse than not recording it, because the
             * timeline is the door ADR 0003 says has to exist.
             *
             * Every other automation entry subjects the deal for the same
             * reason. The message is named by `payload`, which is where a
             * pointer belongs.
             */
            subject: $deal,
            eventType: 'message.'.$event->status->value,
            summary: 'A message '.$what.'.'
                .($suppressed ? ' Nothing further will be sent to that address.' : ''),
            payload: ['message_id' => $instance->getKey()],
        );
    }

    /**
     * The event's recipients, lower-cased, mapped to what the provider said.
     *
     * Null rather than an empty array when the event named nobody, because the
     * two mean opposite things to the caller: *"applies to nobody"* and
     * *"applies to everybody"* are one typo apart, and the safe reading of an
     * unparseable notification is the first.
     *
     * @return array<string, string|null>|null
     */
    private function addressesIn(DeliveryEvent $event): ?array
    {
        if ($event->recipients === []) {
            return null;
        }

        $addresses = [];

        foreach ($event->recipients as $recipient) {
            $addresses[mb_strtolower(trim($recipient['email']))] = $recipient['diagnostic'];
        }

        return $addresses;
    }
}
