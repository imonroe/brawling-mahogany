<?php

declare(strict_types=1);

namespace App\Support\Automation;

use App\Enums\AutomationActionType;
use App\Enums\AutomationState;
use App\Mail\InternalAlertMail;
use App\Models\ActionInstance;
use App\Models\Deal;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Messages\ResolveRecipients;
use App\Support\Permissions;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * S91 — telling a team that automations are failing (#97 · F5.8).
 *
 * A failed message already writes itself onto the deal's timeline and onto S47
 * in red, which is ADR 0003's second door and is why this product's automation
 * *"cannot go quiet"*. But both are **pull**: true the moment somebody looks,
 * and nobody looks at a queue that is usually empty. PRD §1.1's question is
 * *"has the client been told?"* — and the failure this closes is a team
 * believing the answer is yes for a fortnight.
 *
 * ## It observes the rows, and does not listen for the failure
 *
 * The first version of this hung off `ExecuteAction::fail()`, and review found
 * that it never fired for the one outage it was written about. A transport
 * exception does not go through `fail()` — it is caught in `send()`, recorded
 * inline and re-thrown — so *"an expired SES credential takes out a morning's
 * queue"*, the scenario in this class's own docblock, raised nothing at all.
 *
 * That is `CLAUDE.md`'s `gate_cleared` finding one feature over: **a thing
 * wired to one implementation of a failure is wired to none of it.** So this
 * asks the table instead. A row is `failed` however it got there — a rail's
 * refusal, a transport throw, the reaper's verdict, a branch written next year
 * — and a sweep that reads state cannot be bypassed by a new code path,
 * because there is no path to bypass.
 *
 * ## Which also makes the count true
 *
 * Fired from `fail()`, the alert went out on the **first** failure of a burst,
 * which is the moment the backlog is at its smallest: forty failures produced
 * one email reporting one. The information did not exist at the only instant
 * anything was running — the same shape as the unconfirmed-send finding, where
 * the discriminator was unavailable because it was caused by the thing being
 * measured. A sweep a few minutes later can simply count.
 *
 * ## Half-open windows, because a second holds more than one failure
 *
 * `action_instances.executed_at` is `timestamp(0)`. The first version of this
 * sweep stored the newest reported row's timestamp and asked for strictly
 * greater next time, which loses **every sibling that landed in the same
 * second after the `SELECT` ran** — permanently, because the mark had already
 * moved past them. A burst with a sweep landing in the middle of it reported
 * half and silenced half, and the failure is invisible to a frozen clock,
 * which is what every test in the suite uses.
 *
 * So the sweep chooses its own boundary — the start of the current second —
 * and reports `[mark, boundary)`. Every instant then belongs to exactly one
 * window: nothing is counted twice and nothing falls between two. A failure in
 * the current second is simply the next window's, one sweep later.
 *
 * The one thing this does not survive is a row **backdated** below the mark,
 * which needs a clock moving backwards between two runs. The schedule is
 * `onOneServer`, and nothing in the product writes `executed_at` from anywhere
 * but `now()`.
 *
 * ## What it does not alert about
 *
 * A **halt** — F5.9's kill switch, the hourly ceiling, sandbox with no owner.
 * A halted message is still `pending`: nothing is lost, the sweep carries it
 * when the condition clears, and both surfaces for it say so in the present
 * tense (S47 lists it as held; `/settings/sending` says how many the switch is
 * holding right now). Emailing about a switch somebody pulled thirty seconds
 * ago is how an alert becomes noise, and an alert people filter is an alert
 * that does not work when it matters.
 *
 * S91's other two states are not ours yet: a **bounce** is #95 and an
 * **extraction failure** is Slice 5. Both become rows this sweep can read.
 */
final class AlertOnFailures
{
    /**
     * How far back a team with no watermark is allowed to look.
     *
     * A cache flush, or the first run after this shipped, would otherwise
     * count every failure the team has ever had and email somebody about a
     * month of history they have already dealt with.
     */
    public const COLD_START_HOURS = 24;

    /** Enough people to be sure somebody sees it, few enough not to be a broadcast. */
    private const MAX_RECIPIENTS = 5;

    public function __construct(private readonly ResolveRecipients $recipients) {}

    /**
     * Tell this team about anything that has failed since they were last told.
     *
     * Returns whether an alert went out, so the command can say how many teams
     * it spoke to. Never throws: the commonest reason an automation failed is
     * the transport, which is the commonest reason this send will fail too,
     * and a sweep that dies on the first broken team never reaches the second.
     */
    public function sweep(Team $team): bool
    {
        try {
            return $this->alert($team);
        } catch (Throwable $failure) {
            /*
             * The class and nothing else. PRD §9: no PII in logs, and an
             * exception from a mail transport routinely carries the recipient
             * address that was refused.
             */
            Log::warning('An internal alert could not be sent.', [
                'team_id' => $team->getKey(),
                'exception' => $failure::class,
            ]);

            return false;
        }
    }

    private function alert(Team $team): bool
    {
        $since = $this->watermark($team);

        /*
         * The sweep's own boundary, exclusive. Anything failing in the current
         * second belongs to the next window rather than being split across
         * this one — see the note on the class.
         */
        $through = Carbon::now()->startOfSecond();

        if ($through <= $since) {
            // Two sweeps inside one second. Nothing can have been missed.
            return false;
        }

        $window = fn (): Builder => ActionInstance::query()
            ->where('team_id', $team->getKey())
            ->where('state', AutomationState::Failed->value)
            /*
             * `COALESCE`, so the claim this class makes — *a row is failed
             * however it got there* — is true of the column it actually keys
             * on. Every writer of `failed` in the product sets `executed_at`
             * in the same statement, and a row corrected by hand may not.
             * `updated_at` is never null.
             */
            ->whereRaw('COALESCE(executed_at, updated_at) >= ?', [$since])
            ->whereRaw('COALESCE(executed_at, updated_at) < ?', [$through]);

        /*
         * Three aggregates rather than a page of rows. The first version
         * loaded up to 500 and counted those, so a burst over that size
         * reported 500 and moved the mark past the rest — the same silence the
         * window fixes, arriving through a `LIMIT` instead.
         */
        $count = $window()->count();

        if ($count === 0) {
            $this->remember($team, $through);

            return false;
        }

        $audience = $this->audience($team);

        if ($audience === []) {
            /*
             * Not advanced — **anchored**. A team with nobody to tell today
             * may have somebody tomorrow, and a mark advanced now would mean
             * they were never told about this morning.
             *
             * Writing `$since` back rather than leaving the column null is the
             * half that is easy to miss, and review caught the first version
             * without it: a mark that has never been written falls back to the
             * cold-start floor, which is relative to `now()` and therefore
             * **slides forward** with every sweep. The backlog was silenced
             * the moment it aged past a day — by the very branch whose comment
             * promised to be preserving it. Anchoring stops the floor moving.
             */
            $this->remember($team, $since);

            return false;
        }

        $newest = $window()
            ->with('deal')
            ->orderByRaw('COALESCE(executed_at, updated_at) DESC')
            ->first();

        if (! $newest instanceof ActionInstance) {
            // Unreachable behind the count above, and cheap insurance against
            // a row purged between the two queries.
            $this->remember($team, $through);

            return false;
        }

        $carriedEmail = $window()
            ->where('action_type', AutomationActionType::SendEmail->value)
            ->exists();

        Mail::to($audience)->send(new InternalAlertMail(
            team: $team,
            headline: $this->headline($count, $carriedEmail),
            detail: $this->detail($newest, $count),
            /*
             * One failure gets a link to itself; several get the queue, since
             * picking one of twelve to open is a choice nobody can make from
             * an inbox.
             */
            actionUrl: $count === 1
                ? route('messages.show', ['message' => $newest->getKey()])
                : route('messages.index'),
            actionLabel: $count === 1 ? 'See what happened' : 'Open the message queue',
        ));

        $this->remember($team, $through);

        return true;
    }

    /**
     * Where this team was last told up to.
     *
     * A high-water mark rather than a throttle, and the difference is what
     * stops the nag: a throttle re-alerts about the same standing backlog
     * every time it expires, forever, until somebody clears rows nobody may
     * ever clear. This only ever speaks about failures nobody has been told
     * about.
     *
     * On the **team row**, not in the cache. A cache is evictable and empty
     * after a restart, and *"never twice about the same failure"* is a promise
     * `resources/help/automation.md` makes to a team in those words — it has to
     * survive a flush. The migration argues the rest.
     */
    private function watermark(Team $team): CarbonInterface
    {
        $stored = $team->automation_alerted_through;

        return $stored instanceof CarbonInterface
            ? $stored
            /*
             * Never swept. A floor of the last day rather than all of history,
             * so shipping this does not email somebody about a month of
             * failures they have already worked through — and it applies
             * exactly once per team, because the column is written from then
             * on whether or not there was anything to say.
             */
            : Carbon::now()->subHours(self::COLD_START_HOURS);
    }

    private function remember(Team $team, CarbonInterface $through): void
    {
        $team->forceFill(['automation_alerted_through' => $through])->save();
    }

    /**
     * Neutral about delivery, on purpose.
     *
     * *"Did not go out"* is the natural sentence and it is false for one of
     * the rows that reaches here: the reaper records a message that **was**
     * handed to a transport and never confirmed, whose own careful wording is
     * *"it may have reached the recipient"*. A headline asserting the opposite
     * over the top of it is the self-contradiction `ExecuteAction::fail()`
     * already carries a comment about, arriving in an inbox instead of on a
     * timeline.
     */
    private function headline(int $count, bool $carriedEmail): string
    {
        $noun = $carriedEmail
            ? ($count === 1 ? 'automated message' : 'automated messages')
            : ($count === 1 ? 'automation' : 'automations');

        return $count === 1
            ? 'An '.$noun.' needs looking at'
            : $count.' '.$noun.' need looking at';
    }

    /**
     * The most recent failure, in the words that row already carries.
     *
     * Screen Inventory S91 asks for *"plain, specific"*, and each of those
     * `error` strings was written for its own case — a rail's refusal, a
     * transport's rejection, the reaper's deliberate ambiguity. Quoting the
     * row beats composing a sentence about it.
     */
    private function detail(ActionInstance $newest, int $count): string
    {
        $deal = $newest->deal;
        $reason = trim((string) $newest->error);

        $sentence = $deal instanceof Deal
            ? 'On '.$deal->displayName().': '.($reason !== '' ? $reason : 'no reason was recorded.')
            : ($reason !== '' ? $reason : 'A failure was recorded with no reason.');

        if ($count > 1) {
            /*
             * The verb agrees with the number, which the first version did not:
             * at two failures it read *"1 other also need looking at."*
             */
            $others = $count - 1;

            $sentence .= $others === 1
                ? ' 1 other also needs looking at.'
                : ' '.$others.' others also need looking at.';
        }

        return $sentence;
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
}
