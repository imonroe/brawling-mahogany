<?php

declare(strict_types=1);

namespace App\Http\Controllers\Messages;

use App\Enums\AutomationState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messages\ApproveMessageRequest;
use App\Http\Requests\Messages\CancelMessageRequest;
use App\Models\ActionInstance;
use App\Support\Automation\ApproveMessage;
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

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ActionInstance::class);

        $person = $request->user();

        $waiting = ActionInstance::query()
            ->awaitingApproval()
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
            'recent' => $recent->map(self::row(...))->values()->all(),
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
            'raisedAt' => $message->created_at?->toIso8601String(),
            'executedAt' => $message->executed_at?->toIso8601String(),
        ];
    }
}
