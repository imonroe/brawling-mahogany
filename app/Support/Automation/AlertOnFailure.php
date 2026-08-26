<?php

declare(strict_types=1);

namespace App\Support\Automation;

use App\Enums\AutomationState;
use App\Mail\InternalAlertMail;
use App\Models\ActionInstance;
use App\Models\Deal;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Messages\ResolveRecipients;
use App\Support\Permissions;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * S91 — telling a team that a client email did not go out (#97 · F5.8).
 *
 * A failed message already writes itself onto the deal's timeline and onto
 * S47 in red, which is ADR 0003's second door and is why this product's
 * automation *"cannot go quiet"*. But both are pull: they are true the moment
 * somebody looks, and nobody looks at a queue that is usually empty. PRD
 * §1.1's question is *"has the client been told?"* — and the failure mode this
 * closes is a team believing the answer is yes for a fortnight.
 *
 * ## One alert an hour, per team, counting everything
 *
 * The obvious implementation sends one email per failure, and the first time
 * an expired SES credential takes out a morning's queue it sends forty. So the
 * first failure in an hour claims a slot and sends; the rest are silent — and
 * the one that sends **counts the team's whole backlog**, so a burst reports
 * itself as a burst rather than as one message that happened to be first.
 *
 * `Cache::add()` rather than a has-then-put, because two workers failing two
 * messages in the same instant is the ordinary case here, not a race worth
 * ignoring: `add()` is the atomic one, and exactly one of them wins.
 *
 * ## What is deliberately **not** alerted
 *
 * A **halt** — F5.9's kill switch, the hourly ceiling, sandbox with no owner —
 * does not come through here, and the omission is a decision rather than an
 * oversight. A halted message is still `pending`: nothing has been lost, the
 * sweep will carry it when the condition clears, and both surfaces that exist
 * for it say so in the present tense (S47 lists it as held; `/settings/sending`
 * says how many the switch is holding right now). Emailing about a switch
 * somebody pulled thirty seconds ago is how an alert becomes noise, and an
 * alert people filter is an alert that does not work when it matters.
 *
 * S91's other two states are not ours yet: a **bounce** is #95, and an
 * **extraction failure** is Slice 5. Both raise the same alert through the
 * same throttle when they land — which is why this takes a headline and a
 * detail rather than an `ActionInstance`.
 */
final class AlertOnFailure
{
    /** One alert per team per hour, whatever else fails inside it. */
    public const THROTTLE_SECONDS = 3600;

    /** Enough people to be sure somebody sees it, few enough not to be a broadcast. */
    private const MAX_RECIPIENTS = 5;

    public function __construct(private readonly ResolveRecipients $recipients) {}

    /**
     * A message could not be sent. Tell somebody, at most once an hour.
     */
    public function messageFailed(ActionInstance $instance, Team $team, string $reason): void
    {
        if (! $this->claimTheHour($team)) {
            return;
        }

        $outstanding = ActionInstance::query()
            ->where('team_id', $team->getKey())
            ->where('state', AutomationState::Failed->value)
            ->count();

        $deal = $instance->deal;
        $dealName = $deal instanceof Deal ? $deal->displayName() : null;

        $this->deliver($team, new InternalAlertMail(
            team: $team,
            headline: 'An automated message did not go out',
            detail: $dealName === null
                ? 'A message could not be sent. The transport said: '.$reason
                : 'A message on '.$dealName.' could not be sent. The transport said: '.$reason,
            actionUrl: route('messages.show', ['message' => $instance->getKey()]),
            actionLabel: 'See what happened',
            /*
             * The count is of everything currently failed, not of what this
             * hour produced — a person opening the queue wants to know how
             * much is waiting, and an hour's worth is an arbitrary window they
             * did not choose. One is not worth a sentence.
             */
            footnote: $outstanding > 1
                ? $outstanding.' messages are waiting for someone on your message queue.'
                : null,
        ));
    }

    /**
     * Whether this failure is the one that gets to speak.
     *
     * True at most once per team per hour. The key is not scoped by what
     * failed: forty different failures from one expired credential are one
     * problem, and an alert per cause would be forty emails again with extra
     * steps.
     */
    private function claimTheHour(Team $team): bool
    {
        return Cache::add('automation-alert:'.$team->getKey(), true, self::THROTTLE_SECONDS);
    }

    /**
     * @return list<Address>
     */
    private function audience(Team $team): array
    {
        $memberships = TeamMembership::query()
            ->where('team_id', $team->getKey())
            ->active()
            ->with('roles.permissions')
            ->get()
            ->filter(static fn (TeamMembership $membership): bool => $membership->hasPermission(Permissions::APPROVE_MESSAGE));

        /*
         * Owners when nobody holds the permission. A team that has composed
         * its roles so that only one person approves messages, and that person
         * has left, would otherwise get no alert at all — which is the silence
         * this class exists to prevent, arriving through the recipient list
         * instead of through the send.
         */
        if ($memberships->isEmpty()) {
            $memberships = $this->recipients->teamOwners($team);
        }

        $addresses = [];

        foreach ($memberships as $membership) {
            $email = $membership->email;

            if (! is_string($email) || $email === '') {
                continue;
            }

            $addresses[] = new Address($email, $membership->fullName());

            if (count($addresses) >= self::MAX_RECIPIENTS) {
                break;
            }
        }

        return $addresses;
    }

    /**
     * Send it, and never let it break the thing that raised it.
     *
     * The commonest reason a message failed is that the transport is broken,
     * which is also the commonest reason this send will throw. A throw here
     * would fail the worker's job, which retries, which re-enters
     * `ExecuteAction` on a row that is already `failed` — so the alert would
     * turn one lost message into a retry loop. The instance's state and its
     * timeline entry are already written by the time this runs; nothing after
     * this point is allowed to undo them.
     */
    private function deliver(Team $team, InternalAlertMail $mail): void
    {
        $audience = $this->audience($team);

        if ($audience === []) {
            return;
        }

        try {
            Mail::to($audience)->send($mail);
        } catch (Throwable $failure) {
            /*
             * The class and nothing else. PRD §9: no PII in logs, and an
             * exception message from a mail transport routinely carries the
             * recipient address that was refused.
             */
            Log::warning('An internal alert could not be sent.', [
                'team_id' => $team->getKey(),
                'exception' => $failure::class,
            ]);
        }
    }
}
