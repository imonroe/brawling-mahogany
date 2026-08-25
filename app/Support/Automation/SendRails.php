<?php

declare(strict_types=1);

namespace App\Support\Automation;

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

        if ($live->sendsAreDisabled()) {
            return SendDecision::halt(
                $live->sends_disabled_reason ?? 'Sending is switched off for this team.',
            );
        }

        // A message already accepted by a provider is never sent again,
        // whatever its state says. See `ActionInstance::reachedTheProvider()`.
        if ($instance->reachedTheProvider()) {
            return SendDecision::refuse('This message has already been accepted by the provider.');
        }

        if ($instance->state !== AutomationState::Pending) {
            return SendDecision::refuse(
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
