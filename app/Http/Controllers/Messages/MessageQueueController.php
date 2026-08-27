<?php

declare(strict_types=1);

namespace App\Http\Controllers\Messages;

use App\Enums\AutomationState;
use App\Enums\DeliveryStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messages\ApproveMessageRequest;
use App\Http\Requests\Messages\CancelMessageRequest;
use App\Models\ActionInstance;
use App\Models\MessageDelivery;
use App\Support\Automation\ApproveMessage;
use App\Support\Mail\MilestoneAnnouncement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * S47, S48 and S49 — the approval queue, the preview, and one message's
 * record (PRD §4.5 F5.7, F5.8 · issue #93).
 *
 * PRD §4.5 calls this a **launch blocker, not an enhancement**. It is the
 * screen that stands between an automation and a client's inbox, and issue #93
 * names the two things it must never become: a list somebody clears without
 * reading, and a list that hides the reason a message is being held.
 *
 * ## No bulk approve, and that is the feature
 *
 * #93 is explicit — *"bulk approve teaches people to approve without
 * reading"*. Every release is one message, opened, with its words on screen.
 * The queue is deliberately a little tedious, in the way a checklist is.
 *
 * ## The failed list is on the same screen, not a separate one
 *
 * A message that did not go out is the same question as a message that has not
 * gone out yet — *"has the client been told?"*, PRD §1.1's second question —
 * and putting the failures somewhere else is how a team goes a fortnight
 * without noticing their SES credentials expired.
 */
class MessageQueueController extends Controller
{
    /**
     * How many recent sends and failures the queue carries alongside the
     * pending ones.
     *
     * A window rather than everything: this screen answers *"what needs me"*,
     * and a full history of every message the team ever sent belongs on the
     * deal it was about. S49 is the way into one of those.
     */
    private const RECENT = 25;

    /**
     * The ceiling on the review queue itself.
     *
     * Generous rather than absent: each row ships a rendered payload, and a
     * team inside F5.7's first-month window has *every* outbound message here.
     * Oldest first, so what is cut is the newest — the opposite of what a
     * failure list may cut.
     */
    private const WAITING = 200;

    /**
     * And on the failures, which are counted rather than sliced.
     *
     * Their own query, not a filter over `$recent`. Deriving them from the
     * 25 most-recent rows meant a team that sent 25 messages after a failure
     * lost the failure off the screen entirely — while `automation.md`
     * promises *"it is the thing you most need to notice"*. A list that
     * silently drops the row it exists for is worse than no list.
     */
    private const FAILURES = 50;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ActionInstance::class);

        $person = $request->user();

        $waiting = ActionInstance::query()
            ->awaitingApproval()
            ->limit(self::WAITING)
            /*
             * The deal and the template, eagerly, and each has a cell that
             * reads it — the rule `CLAUDE.md` records after S13 shipped an
             * eager-load nothing rendered and a 500 with it. The deal's name
             * is the row's heading; the template's name is what the row is
             * *of*, and the payload's copy of it is only a fallback for a
             * template since deleted.
             */
            ->with(['deal', 'messageTemplate'])
            ->oldest()
            /*
             * A tiebreaker on every one of these lists, because the columns
             * they sort by are `timestamp(0)` and a busy second puts several
             * rows in it. Without one Postgres returns heap order, which is
             * stable only until something churns the pages — so the queue
             * reorders under a reader between two refreshes over identical
             * data, and a fixture that creates its rows in one second orders
             * differently depending on what ran before it. That last part is
             * how it was found: an intermittent `MessageQueueBudgetTest`.
             */
            ->oldest('id')
            ->get();

        /*
         * The failures, on their own terms.
         *
         * `Queue.vue` used to filter these out of `$recent` client-side, which
         * made "did this go out?" a question about how busy the team had been
         * since. Oldest first, because the one that has been red longest is
         * the one nobody has dealt with.
         */
        $failing = ActionInstance::query()
            ->where('state', AutomationState::Failed)
            ->with(['deal', 'messageTemplate'])
            ->oldest('executed_at')
            ->oldest('id')
            ->limit(self::FAILURES)
            ->get();

        /*
         * Held: `pending`, carrying the reason a rail gave for not sending it.
         *
         * These were on no list at all, which was survivable only while every
         * `pending` row was re-dispatched every minute. Now that a message
         * behind the kill switch or a ceiling is **deliberately** not swept,
         * it is idle — and the only place it surfaced was a single integer on
         * `/settings/sending` framed as *what the switch would catch*. A team
         * over its daily ceiling had N client messages that no screen named,
         * indefinitely, while `automation.md` pointed them here for *"what
         * your automations are about to send"*.
         *
         * The error is what makes a row belong here rather than in the
         * ordinary scheduled queue: an untouched `pending` instance is simply
         * on its way.
         */
        $held = ActionInstance::query()
            ->where('state', AutomationState::Pending)
            ->where(fn (Builder $query): Builder => $query
                ->whereNotNull('error')
                /*
                 * **And a claim nobody came back from.** A row carrying a
                 * `message_key` and still `pending` was handed to a transport
                 * by a worker that never wrote its outcome. Nothing will pick
                 * it up — `scopeDue()` excludes it — and it was on no list at
                 * all until it appeared here, which is round 2's blocker in
                 * its final form: not a wrong sentence, an absent one.
                 *
                 * Listing it is a **read**, which is why this is the right
                 * place for it. A worker asked at the moment of the second
                 * delivery cannot tell a crashed sibling from a live one; a
                 * screen does not have to, because it is not writing
                 * anything. `automations:reap-unconfirmed` records the
                 * outcome hours later, and until then the row says what is
                 * actually known, which is that nobody knows.
                 */
                ->orWhereNotNull('message_key'))
            ->with(['deal', 'messageTemplate'])
            ->oldest()
            ->oldest('id')
            ->limit(self::FAILURES)
            ->get();

        $recent = ActionInstance::query()
            ->whereIn('state', [
                AutomationState::Sent->value,
                AutomationState::Failed->value,
                AutomationState::Cancelled->value,
            ])
            ->with(['deal', 'messageTemplate'])
            /*
             * By when the row was last touched, not by `executed_at`: a
             * cancelled message never executed and carries a null, and
             * Postgres sorts nulls first in a descending order — so ordering
             * by the execution time would pin every stopped message to the top
             * of a list whose whole job is *what happened most recently*.
             */
            ->latest('updated_at')
            ->latest('id')
            ->limit(self::RECENT)
            ->get();

        return Inertia::render('Messages/Queue', [
            'waiting' => $waiting->map(self::row(...))->values()->all(),
            'failing' => $failing->map(self::row(...))->values()->all(),
            'held' => $held->map(self::row(...))->values()->all(),
            'recent' => $recent->map(self::row(...))->values()->all(),
            /*
             * The true totals, so a truncated list says so rather than
             * reading as the whole picture. A queue that silently shows 200 of
             * 340 is a queue somebody believes they have cleared.
             */
            'totals' => [
                'waiting' => ActionInstance::query()->awaitingApproval()->count(),
                'failing' => ActionInstance::query()->where('state', AutomationState::Failed)->count(),
                'held' => ActionInstance::query()
                    ->where('state', AutomationState::Pending)
                    ->where(fn (Builder $query): Builder => $query
                        ->whereNotNull('error')
                        ->orWhereNotNull('message_key'))
                    ->count(),
            ],
            'can' => [
                'approve' => $person?->can('approveAny', ActionInstance::class) ?? false,
            ],
        ]);
    }

    /**
     * S49 — one message, and everything that happened to it.
     *
     * Its own screen rather than a wider row on the queue, because the
     * question it answers is asked long after the queue has moved on: *"what
     * exactly did the client get told about the inspection, and when?"*
     */
    public function show(Request $request, ActionInstance $message): Response
    {
        $this->authorize('view', $message);

        $message->load(['deal', 'stage', 'messageTemplate', 'approver']);

        $person = $request->user();

        return Inertia::render('Messages/Show', [
            'message' => [
                ...self::row($message),
                'rendered' => $message->rendered()->toArray(),
                'stageName' => $message->stage?->name,
                'approvedAt' => $message->approved_at?->toIso8601String(),
                'attempts' => $message->attempts,
            ],
            /*
             * S49's other half (#95): what the provider said afterwards.
             *
             * Kept apart from `message` rather than folded into it, because
             * they answer different questions and a screen that merged them
             * would have to pick one word for both. `message.state` is *did
             * this product manage to send it*; a delivery is *did it arrive*,
             * per recipient — and a message whose `state` is `sent` can have
             * bounced off every address it was written to.
             *
             * Empty for every message raised before this shipped, and for
             * every `create_task`. The screen renders nothing rather than an
             * empty table, because *"no delivery information"* over a task
             * automation would be answering a question nobody asked.
             */
            'deliveries' => self::deliveries($message),
            /*
             * Carried through so S49 does not contradict the screen that sent
             * the reader here. The queue's Held section says *"open it and
             * decide"*; without this the detail page badged the row
             * **Scheduled**, rendered no reason, and offered no controls — the
             * three things least true of a message already handed to a
             * transport.
             */
            'can' => [
                'approve' => $person?->can('approve', $message) ?? false,
                'cancel' => $person?->can('cancel', $message) ?? false,
            ],
        ]);
    }

    public function approve(
        ApproveMessageRequest $request,
        ActionInstance $message,
        ApproveMessage $approvals,
    ): RedirectResponse {
        $person = $request->person();

        $result = $approvals->approve($message, $person, $request->edits());

        if (! $result->applied) {
            return back()->with('error', $result->refusal);
        }

        /*
         * Dispatched **after** the service's transaction has committed, which
         * is why it is a second call rather than the tail of `approve()`. A
         * job dispatched inside a transaction can be picked up by a worker
         * before the commit lands — and this particular job sends a client an
         * email.
         */
        if ($result->instance instanceof ActionInstance) {
            $approvals->dispatch($result->instance);
        }

        return back()->with('success', 'The message is on its way.');
    }

    public function cancel(
        CancelMessageRequest $request,
        ActionInstance $message,
        ApproveMessage $approvals,
    ): RedirectResponse {
        $result = $approvals->cancel($message, $request->person(), $request->reason());

        return $result->applied
            ? back()->with('success', 'The message was stopped and will not go out.')
            : back()->with('error', $result->refusal);
    }

    /**
     * What became of each copy of this message (#95 · F5.8).
     *
     * @return list<array<string, mixed>>
     */
    private static function deliveries(ActionInstance $message): array
    {
        return array_values($message->deliveries()
            /*
             * Eager-loaded because the row below reads it. Bounded by the
             * recipient count and therefore small, but a `with()` whose cell
             * is named is the rule this project keeps — and the alternative is
             * a query per addressee on a screen somebody opens when a message
             * has gone wrong.
             */
            ->with('membership')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(static fn (MessageDelivery $delivery): array => [
                'id' => $delivery->getKey(),
                /*
                 * The code, and **not** a label beside it. `lib/states.ts` is
                 * the one place a state gets a word (governance rule 7), and
                 * shipping the enum's label alongside would be a second
                 * spelling of the same thing that could drift — the badge
                 * would show one and a heading the other.
                 */
                'status' => $delivery->status->value,
                'isFailure' => $delivery->status->isFailure(),
                /*
                 * The address, on this screen and nowhere else. It is a
                 * client's email and this is one deal, opened by somebody with
                 * permission on it — which is not true of the alert email, and
                 * is why that one says *"a message could not be delivered"*
                 * with no address in it (PRD §9).
                 */
                'email' => $delivery->recipient_email,
                'name' => $delivery->membership?->fullName(),
                'deliveredAt' => $delivery->delivered_at?->toIso8601String(),
                'openedAt' => $delivery->opened_at?->toIso8601String(),
                'bouncedAt' => $delivery->bounced_at?->toIso8601String(),
                'complainedAt' => $delivery->complained_at?->toIso8601String(),
                /*
                 * The plain-language sentence, which is the one #95 asks for,
                 * and the provider's own words separately for whoever is
                 * actually debugging deliverability. Never the other way
                 * round: `smtp; 550 5.1.1` as the headline is the thing the
                 * issue names as the failure.
                 */
                'explanation' => self::explain($delivery),
                'detail' => $delivery->detail,
                /*
                 * Whether F5.9's sandbox sent this to the team owner instead
                 * of the person it was addressed to. On the row rather than
                 * inferred: without it the screen renders the owner's name
                 * beside a message addressed to a client and explains nothing.
                 */
                'redirected' => $delivery->redirected,
            ])
            ->all());
    }

    /**
     * What happened, in words, and what to do about it.
     *
     * Composed here rather than stored, because it is presentation: the row
     * holds the facts (a status, a timestamp, the provider's diagnostic) and
     * a stored sentence would be a copy of this method that could not be
     * corrected for messages already sent.
     */
    private static function explain(MessageDelivery $delivery): string
    {
        return match ($delivery->status) {
            DeliveryStatus::Bounced => 'Their mail server refused this message. If it keeps happening, the address is probably wrong — check it with them and correct it on the deal.',
            DeliveryStatus::Complained => 'The person this was sent to marked it as spam. Nothing further will be sent to that address, and that is deliberate: continuing to write to somebody who has reported you puts every other message your team sends at risk.',
            DeliveryStatus::Delivered => 'Their mail server accepted this message. That is as far as anything can be known — whether they read it is not something email reports.',
            DeliveryStatus::Opened => 'This message was opened. Opens are measured with a tracking image that many mail apps block, so a message with no open here may still have been read.',
            /*
             * `sent` says the least and must not be dressed up. It covers two
             * genuinely different situations — a provider that has not
             * reported yet, and a send whose id never came back so nothing
             * ever will — and inventing a reassuring sentence over the second
             * is the overclaim this product keeps refusing to make.
             */
            DeliveryStatus::Sent => $delivery->provider_message_id === null
                ? 'This was handed over for sending. No delivery confirmation will arrive for it — the send was accepted without an identifier to track it by.'
                : 'This was handed over for sending. Nothing has come back about it yet.',
            /*
             * Never handed over at all. The reason is read from the row rather
             * than from the address's current state: this says why *this* send
             * was withheld at the moment it was withheld, and a suppression
             * lifted afterwards does not make the message retrospectively
             * sent.
             */
            DeliveryStatus::Suppressed => 'This was not sent. '
                .($delivery->withheld_reason?->explanation()
                    ?? 'That address can no longer be written to.'),
        };
    }

    /**
     * One row, built once for three screens.
     *
     * The same argument `DealHeader` makes: a queue row and a detail page that
     * each built their own would disagree about which template a message came
     * from within a month, and the one they would disagree about is a message
     * whose template has since been deleted.
     *
     * @return array<string, mixed>
     */
    private static function row(ActionInstance $message): array
    {
        $payload = $message->payload ?? [];
        $rendered = $message->rendered();

        return [
            'id' => $message->getKey(),
            'state' => $message->state->value,
            'stateLabel' => $message->state->label(),
            'actionType' => $message->action_type->value,
            'actionLabel' => $message->action_type->label(),
            'trigger' => $message->trigger->value,
            'triggerLabel' => $message->trigger->label(),
            'dealId' => $message->deal_id,
            'dealName' => $message->deal?->displayName(),
            /*
             * The template's own name where it still exists, and the copy
             * taken at raise time where it does not. A message whose template
             * was deleted is exactly the message somebody is trying to
             * understand on S49, and *"(no template)"* tells them nothing.
             */
            'templateName' => $message->messageTemplate->name
                ?? (is_string($payload['templateName'] ?? null) ? $payload['templateName'] : null),
            'subject' => $rendered->subject,
            'recipients' => $message->recipients(),
            'isComplete' => $rendered->isComplete(),
            'problems' => [
                'malformed' => $rendered->malformed,
                'unknown' => $rendered->unknown,
                'unresolved' => $rendered->unresolved,
            ],
            'error' => $message->error,
            /*
             * Whether this row is *held by a rail* or *unconfirmed*, which the
             * Held list has to tell apart: one goes out on its own when the
             * reason clears and the other never will. Both look like `pending`
             * from outside.
             */
            'isUnconfirmed' => $message->state === AutomationState::Pending
                && $message->reachedTheProvider(),
            /*
             * S87's announcement, because F5.7's whole promise is that what an
             * approver reads is what the client gets — and `MilestoneAnnouncement`
             * rests its own argument on *"what an approver reads on S48 **is
             * the payload**"*. That was true of the words and false of the
             * frame around them: the headline, the address and the listing
             * button reached a client having been seen by nobody.
             *
             * Read from the payload, never re-resolved, which is the rule the
             * mailable follows — a preview that resolved it live would show an
             * approver one address and send another.
             */
            'milestone' => MilestoneAnnouncement::fromPayload($payload['milestone'] ?? null)
                ?->withoutLinkAlreadyIn($rendered)
                ?->toArray(),
            'raisedAt' => $message->created_at?->toIso8601String(),
            'executedAt' => $message->executed_at?->toIso8601String(),
        ];
    }
}
