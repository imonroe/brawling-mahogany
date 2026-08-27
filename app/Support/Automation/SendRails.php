<?php

declare(strict_types=1);

namespace App\Support\Automation;

use App\Enums\AutomationActionType;
use App\Enums\AutomationState;
use App\Enums\SuppressionReason;
use App\Models\ActionInstance;
use App\Models\SuppressedAddress;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Messages\ResolveRecipients;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * F5.9's three rails, at the last possible moment before the provider call
 * (PRD §4.5 · issue #96).
 *
 * PRD §4.5 calls this and F5.7 **launch blockers, not enhancements**, and
 * issue #96 says exactly where they have to live:
 *
 * > At the send path, in the queue worker, not in the UI. Every one of them
 * > must hold when a message is sent by a scheduled job at 3am with no human
 * > present.
 *
 * So this is asked by `ExecuteAction` immediately before the mailer, and by
 * nothing else. A rail checked at dispatch is a rail a message queued five
 * minutes before somebody pulled the cord sails straight past — which is the
 * whole point of the kill switch: *"when a team calls to say stop, something
 * is wrong, the answer must be one toggle, and it must catch everything
 * already queued."*
 *
 * ## The order is deliberate
 *
 * The kill switch first, because it is the one somebody is on the phone about.
 * Then the message's own soundness — a broken message is refused whatever the
 * limits say, and counting it against a ceiling would be counting a send that
 * was never going to happen. Then the ceiling. Then sandbox, which rewrites
 * rather than refuses and so must be last: a redirected message still counts
 * against the limit, because a loop that sends four hundred messages to the
 * team owner is still a loop.
 */
final class SendRails
{
    public function __construct(private readonly ResolveRecipients $recipients) {}

    public function decide(ActionInstance $instance, Team $team): SendDecision
    {
        /*
         * **Is this row still ours to act on at all** — before anything else,
         * including the kill switch.
         *
         * Round 4's finding, and the ordering is the whole of it. Every rail
         * below can *write* to the row: a halt stamps `error`. So asking the
         * kill switch first meant a worker arriving late at a **cancelled**
         * message overwrote the reason a person typed with *"sending is
         * switched off for this team"* — round 1's finding #2 arriving through
         * the one branch left above the state gate, on the screen whose job is
         * to answer *"why did the client never hear about this"* — and a
         * duplicate delivery for a **sent** message wrote a rail error onto a
         * delivered one.
         *
         * Putting ownership first costs the switch nothing: a row that is
         * `pending` and unclaimed still reaches it, and that is every row the
         * switch is for.
         *
         * A row carrying a `message_key` has been handed to a transport and is
         * never handed to one again. **Stand down, always** — three rounds of
         * review went into that word. `pending` plus a key does not mean the
         * worker died; it means *some* worker claimed it and has not written
         * its outcome, and the commonest reading is a sibling inside
         * `Mail::send` at this instant. Two workers on one row is what a queue
         * does after a visibility timeout, which is what the claim exists for.
         *
         * There is no signal here that separates the two. `updated_at` looked
         * like one and is not: the second delivery happens *because* the claim
         * aged past the queue's visibility timeout, so the crashed worker and
         * the live sibling present the same age at the only moment anything
         * asks. A threshold below it calls live sends failures; a threshold
         * above it is never evaluated, because the stand-down completes the
         * job and there is no third delivery.
         *
         * So the outcome of a claim nobody came back for is decided **away
         * from the claim**, by `automations:reap-unconfirmed` hours later,
         * where no worker is standing — and in the meantime the row is
         * *visible* on S47 rather than narrated, because a read cannot
         * contradict anybody.
         */
        if ($instance->reachedTheProvider()) {
            return SendDecision::standDown('This message has already been handed to a transport.');
        }

        /*
         * And a row in any other state belongs to whoever put it there.
         *
         * The case that matters is `cancelled`: `ApproveMessage::cancel()`
         * deliberately allows stopping a `pending` instance, and a `pending`
         * instance is one that has **already been dispatched**. So "queued →
         * somebody presses Stop → the worker arrives" is the ordinary
         * sequence, not a race.
         */
        if ($instance->state !== AutomationState::Pending) {
            return SendDecision::standDown(
                'This message is '.$instance->state->label().' and is not waiting to be sent.',
            );
        }

        /*
         * Rail 2 — the hard switch, first.
         *
         * Read from the row rather than from a cached team, because the point
         * of this rail is that it takes effect *now*: a worker holding a Team
         * model from thirty seconds ago would keep sending after the toggle.
         */
        $live = Team::query()->find($team->getKey());

        if (! $live instanceof Team) {
            return SendDecision::refuse('The team this message belongs to no longer exists.');
        }

        /*
         * And the row has to belong to the team whose rails are being asked.
         *
         * Hardening rather than a live hole — `RunAutomation` re-establishes
         * the team and finds the row inside that scope, so the pairing is
         * consistent today. It is here because the consequence of a future
         * caller getting it wrong is specific and silent: team A's sandbox
         * setting redirecting team B's client message to team A's owner, and
         * team A's ceiling pausing team B's sends.
         */
        if ($instance->team_id !== $live->getKey()) {
            /*
             * **Stand down, not refuse**, and round 2 was right about why: a
             * refusal sends `ExecuteAction` into `fail()`, which writes state
             * onto the row — so the guard against touching another tenant's
             * message would have answered by touching another tenant's
             * message, under this team's established scope. The row is
             * emphatically not this worker's; nothing about it is ours to
             * record.
             */
            return SendDecision::standDown('This message does not belong to the team being asked about.');
        }

        if ($live->sendsAreDisabled()) {
            return SendDecision::halt(
                $live->sends_disabled_reason ?? 'Sending is switched off for this team.',
            );
        }

        /*
         * The message's own soundness. #93: *"a missing merge field blocks
         * approval"* — and blocks a send, because an automation that fires
         * without a human never reached the approval queue at all.
         */
        $rendered = $instance->rendered();

        if (! $rendered->isComplete()) {
            return SendDecision::refuse(
                'This message still has merge fields that could not be filled in: '
                .implode(', ', [...$rendered->malformed, ...$rendered->unknown, ...$rendered->unresolved]).'.',
            );
        }

        $recipients = $instance->recipients();

        if ($recipients === []) {
            /*
             * PRD §1.1's second question is *"has the client been told?"* and
             * silence is the answer this product must never give. A send that
             * resolves to nobody is a failure with a reason, not a success
             * with an empty list.
             */
            return SendDecision::refuse('This message resolved to nobody on this deal.');
        }

        /*
         * **Rail 0b — the suppression list** (#95 · F5.8).
         *
         * Above the ceiling and the sandbox, because a suppressed address is
         * not a message this team may send later when the window rolls: it is
         * a message that must not go, ever, and counting it against a limit
         * or redirecting it to the team owner would both be wrong.
         *
         * Below the message's own soundness, because a broken template is
         * worth telling somebody about whichever address it was pointed at.
         *
         * Partial suppression **drops the address and sends the rest**. A deal
         * with two sellers, one of whose mailbox has died, must still reach
         * the other — refusing the whole message would let one dead address
         * silence a client who is perfectly reachable, which is the failure
         * this product cares about most.
         */
        $sendable = $this->withoutSuppressed($recipients);

        if ($sendable === []) {
            return SendDecision::refuse($this->suppressionRefusal($recipients));
        }

        $recipients = $sendable;

        // Rail 1 — the ceiling.
        $ceiling = $this->ceilingReached($live);

        if ($ceiling !== null) {
            return SendDecision::halt($ceiling);
        }

        // Rail 3 — sandbox, last, and a rewrite rather than a refusal.
        if ($live->sandbox_mode) {
            $owner = $this->teamOwnerAddress($live);

            if ($owner === null) {
                return SendDecision::halt(
                    'Sandbox mode is on and this team has no owner with an email address to redirect to.',
                );
            }

            return SendDecision::send([$owner], redirected: true);
        }

        return SendDecision::send($recipients);
    }

    /**
     * The recipients this product is still allowed to write to.
     *
     * One query for the whole list rather than one per address — this runs
     * inside a queue worker on every send, and the table it reads is the one
     * shared by every team on the platform.
     *
     * @param  list<array{name: string, email: string, membershipId: string|null}>  $recipients
     * @return list<array{name: string, email: string, membershipId: string|null}>
     */
    private function withoutSuppressed(array $recipients): array
    {
        $suppressed = SuppressedAddress::among(array_column($recipients, 'email'));

        if ($suppressed === []) {
            return $recipients;
        }

        return array_values(array_filter(
            $recipients,
            static fn (array $recipient): bool => ! array_key_exists(
                SuppressedAddress::normalise($recipient['email']),
                $suppressed,
            ),
        ));
    }

    /**
     * Why nothing went out, in words about the address.
     *
     * Never *"another team's client reported you"*, and never the row. The
     * suppression list is account-wide (`SuppressedAddress` argues why), and
     * two teams sharing a client must not learn about each other's
     * correspondence from a refusal message. What a team is entitled to know
     * is that **this** address is not reachable and what to do about it, which
     * is what `SuppressionReason::explanation()` says.
     *
     * @param  list<array{name: string, email: string, membershipId: string|null}>  $recipients
     */
    private function suppressionRefusal(array $recipients): string
    {
        $suppressed = SuppressedAddress::among(array_column($recipients, 'email'));

        /*
         * The most serious reason among them leads. On a message to one
         * person that is simply their reason; on a message to two it is the
         * one that needs acting on first, and the count below says there was
         * more than one.
         */
        $reason = collect($suppressed)
            ->sortByDesc(fn (SuppressionReason $reason): bool => $reason->threatensTheAccount())
            ->first();

        $explanation = $reason instanceof SuppressionReason
            ? ' '.$reason->explanation()
            : '';

        return count($suppressed) > 1
            ? 'None of the addresses on this message can be written to any more.'.$explanation
            : 'This message was not sent because the address it was going to can no longer be written to.'.$explanation;
    }

    /**
     * F5.9: *"Exceeding the limit halts sending and alerts — it does not
     * silently drop."*
     *
     * Counted off `action_instances` rather than a cache counter, because the
     * table is the record: a counter that resets when Redis restarts is a
     * ceiling that lifts itself during exactly the kind of incident it exists
     * for. Both windows are rolling rather than calendar — an hourly limit
     * that resets on the hour lets a loop send two hours' worth in two
     * minutes across the boundary.
     */
    private function ceilingReached(Team $team): ?string
    {
        /*
         * Scoped, like everything else. This runs inside the job's own
         * `withinTeam()`, so the global scope already constrains it to the
         * team being asked about — reaching for `withoutTeamScope()` here
         * would have been reading tenant data unscoped for no reason, which
         * is what `UnscopedQueryConventionTest` says out loud.
         */
        $sentSince = fn (Carbon $since): int => ActionInstance::query()
            ->where('state', AutomationState::Sent)
            /*
             * **Emails only.** F5.9's ceiling exists so *"a bug that loops an
             * automation"* cannot reach clients four hundred times, and a
             * created task reaches nobody — it is a row on a screen the team
             * already has open.
             *
             * Counting every `sent` row meant three `create_task` automations
             * across a busy morning silently paused the team's actual client
             * email, which is the ceiling causing the failure it exists to
             * prevent. A manual prompt is marked `sent` too, and is a person
             * saying they did something.
             */
            ->where('action_type', AutomationActionType::SendEmail)
            ->where('executed_at', '>=', $since)
            ->count();

        $hourly = $sentSince(Carbon::now()->subHour());

        if ($hourly >= $team->hourly_send_limit) {
            $this->alert($team, 'hourly', $hourly, $team->hourly_send_limit);

            return 'This team has reached its limit of messages for the hour. Sending is paused, not cancelled.';
        }

        $daily = $sentSince(Carbon::now()->subDay());

        if ($daily >= $team->daily_send_limit) {
            $this->alert($team, 'daily', $daily, $team->daily_send_limit);

            return 'This team has reached its limit of messages for the day. Sending is paused, not cancelled.';
        }

        return null;
    }

    /**
     * Nothing here is interpolated, and nothing here is a person.
     *
     * PRD §9: no PII in logs, ever. A team id and two numbers are what an
     * operator needs to know a ceiling was hit; who it would have gone to is
     * exactly what must not be written down.
     */
    private function alert(Team $team, string $window, int $sent, int $limit): void
    {
        Log::warning('Team reached its outbound message ceiling.', [
            'team_id' => $team->getKey(),
            'window' => $window,
            'sent' => $sent,
            'limit' => $limit,
        ]);
    }

    /**
     * @return array{name: string, email: string, membershipId: string|null}|null
     */
    private function teamOwnerAddress(Team $team): ?array
    {
        $owner = $this->recipients->teamOwners($team)
            ->first(fn (TeamMembership $membership): bool => ($membership->email ?? '') !== '');

        return $owner instanceof TeamMembership
            ? [
                'name' => $owner->fullName(),
                'email' => (string) $owner->email,
                'membershipId' => (string) $owner->getKey(),
            ]
            : null;
    }
}
