<?php

declare(strict_types=1);

namespace App\Support\Automation;

use App\Enums\AutomationActionType;
use App\Enums\AutomationState;
use App\Models\ActionInstance;
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
         * A message already handed to a transport is never handed to one
         * again, whatever its state says. See
         * `ActionInstance::reachedTheProvider()`.
         *
         * **Two row shapes satisfy this, and they need opposite answers.**
         * Round 2 found that collapsing them turned a noisy failure into a
         * silent one, which is the worse of the two.
         *
         * A row that is already terminal — `sent`, `failed`, `cancelled` —
         * belongs to whoever put it there. This branch is reached by the
         * queue's own retry after a transport threw (`ExecuteAction` records
         * the failure and rethrows), so refusing would write *"an automated
         * message did not go out: this message has already been handed to a
         * transport"* onto the deal three more times, about a message that may
         * well have been delivered. Stand down.
         *
         * A row that is still **`pending`** and carrying a key is owned by
         * **nobody**: the worker claimed the key and never came back. That is
         * the crash window the claim ordering deliberately leaves — a worker
         * OOM, a `queue:restart` mid-send, a container eviction — and standing
         * down left it `pending` forever, excluded from `scopeDue()`, on no
         * list on S47, with no timeline entry and no audit entry. Reachable
         * only by already knowing its id. That is PRD §1.1's worst answer to
         * *"has the client been told?"*: silence, with no failure anywhere to
         * find, on the one operation this product cannot take back.
         *
         * So it is recorded — and the sentence says what is actually true,
         * which is that nobody knows whether it arrived.
         */
        if ($instance->reachedTheProvider()) {
            return $instance->state === AutomationState::Pending
                ? SendDecision::refuse(
                    'This message was handed to a transport and never confirmed. '
                    .'It may have reached the recipient — check before sending it again.',
                )
                : SendDecision::standDown('This message has already been handed to a transport.');
        }

        /*
         * And a row in any other state belongs to whoever put it there.
         *
         * The case that matters is `cancelled`: `ApproveMessage::cancel()`
         * deliberately allows stopping a `pending` instance, and a `pending`
         * instance is one that has **already been dispatched**. So "queued →
         * somebody presses Stop → the worker arrives" is the ordinary
         * sequence, not a race — and a refusal here overwrote the reason they
         * typed, contradicted itself on the timeline, and flipped the row from
         * `cancelled` to `failed`, where `RaiseAutomations::alreadyRaised()`
         * counts it. A skipped stage that was later reopened then silently
         * never re-raised its message, which is the contract this whole
         * feature is built around.
         */
        if ($instance->state !== AutomationState::Pending) {
            return SendDecision::standDown(
                'This message is '.$instance->state->label().' and is not waiting to be sent.',
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
     * @return array{name: string, email: string}|null
     */
    private function teamOwnerAddress(Team $team): ?array
    {
        $owner = $this->recipients->teamOwners($team)
            ->first(fn (TeamMembership $membership): bool => ($membership->email ?? '') !== '');

        return $owner instanceof TeamMembership
            ? ['name' => $owner->fullName(), 'email' => (string) $owner->email]
            : null;
    }
}
