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
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
    /** How long the high-water mark is remembered. Long, because forgetting it costs an extra email. */
    public const WATERMARK_DAYS = 30;

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

        /** @var list<ActionInstance> $failures */
        $failures = ActionInstance::query()
            ->where('team_id', $team->getKey())
            ->where('state', AutomationState::Failed->value)
            ->where('executed_at', '>', $since)
            ->with('deal')
            ->orderByDesc('executed_at')
            ->limit(500)
            ->get()
            ->all();

        if ($failures === []) {
            return false;
        }

        $audience = $this->audience($team);

        if ($audience === []) {
            /*
             * The watermark is **not** moved. A team with nobody to tell today
             * may have somebody tomorrow, and a mark advanced now would mean
             * they were never told about this morning.
             */
            return false;
        }

        $newest = $failures[0];
        $carriedEmail = $this->anyEmail($failures);

        Mail::to($audience)->send(new InternalAlertMail(
            team: $team,
            headline: $this->headline(count($failures), $carriedEmail),
            detail: $this->detail($newest, count($failures)),
            /*
             * One failure gets a link to itself; several get the queue, since
             * picking one of twelve to open is a choice nobody can make from
             * an inbox.
             */
            actionUrl: count($failures) === 1
                ? route('messages.show', ['message' => $newest->getKey()])
                : route('messages.index'),
            actionLabel: count($failures) === 1 ? 'See what happened' : 'Open the message queue',
        ));

        $this->remember($team, $newest->executed_at);

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
     */
    private function watermark(Team $team): CarbonInterface
    {
        $stored = Cache::get($this->key($team));

        return is_string($stored)
            ? Carbon::parse($stored)
            : Carbon::now()->subHours(self::COLD_START_HOURS);
    }

    private function remember(Team $team, ?CarbonInterface $at): void
    {
        Cache::put(
            $this->key($team),
            ($at ?? Carbon::now())->toIso8601String(),
            Carbon::now()->addDays(self::WATERMARK_DAYS),
        );
    }

    private function key(Team $team): string
    {
        return 'automation-alert-watermark:'.$team->getKey();
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
            $sentence .= ' '.($count - 1).' other'.($count === 2 ? '' : 's').' also need looking at.';
        }

        return $sentence;
    }

    /**
     * @param  list<ActionInstance>  $failures
     */
    private function anyEmail(array $failures): bool
    {
        foreach ($failures as $failure) {
            if ($failure->action_type === AutomationActionType::SendEmail) {
                return true;
            }
        }

        return false;
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
