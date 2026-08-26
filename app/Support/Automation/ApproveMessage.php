<?php

declare(strict_types=1);

namespace App\Support\Automation;

use App\Enums\AutomationActionType;
use App\Enums\AutomationState;
use App\Jobs\RunAutomation;
use App\Models\ActionInstance;
use App\Models\Deal;
use App\Models\Person;
use App\Support\Activity\RecordActivity;
use App\Support\Audit\AuditLogger;
use App\Support\Messages\MergeFields;
use Illuminate\Support\Facades\DB;

/**
 * A person releases, edits, or stops one queued message (PRD §4.5 F5.7 · S47,
 * S48 · issue #93).
 *
 * PRD §4.5 calls the approval queue a **launch blocker, not an enhancement**,
 * and the reason is one sentence: *"an automation that emails the wrong client
 * the wrong thing damages a real relationship and cannot be recalled."* This is
 * the only door out of `awaiting_approval`, for the same reason
 * `AdvanceWorkflow` is the only door out of a stage state — releasing a message
 * is the flag, an activity entry, an audit entry and a queue dispatch, and a
 * second implementation would remember three of the four.
 *
 * ## An edit here edits the instance, never the template
 *
 * F5.10 pre-fills a message *"ready to review and send"*, and reviewing means
 * being able to change it. What changes is this one instance's `payload`: two
 * instances raised from one template are two messages to two clients, and
 * fixing the sentence about *this* deal's inspection must not rewrite the
 * words every future deal gets. A template edit is S45, deliberately a
 * different screen.
 *
 * ## Editing re-checks the merge fields, and that is not paranoia
 *
 * #93: *"a missing merge field blocks approval."* An approver who types
 * `{{ client_name }` into the body has produced exactly the defect PR #175's
 * review found — braces that survive substitution and reach the client as
 * template internals — and the render that would have caught it happened
 * before they typed. So the edit is re-checked on the way in.
 */
final class ApproveMessage
{
    public function __construct(
        private readonly RecordActivity $activity,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Release it. Editing first is optional; approving without reading is not
     * something this can prevent, and #93 says so.
     *
     * @param  array<string, mixed>  $edits  subject / bodyHtml / bodyText, any subset
     */
    public function approve(ActionInstance $instance, Person $actor, array $edits = []): ApprovalResult
    {
        return DB::transaction(function () use ($instance, $actor, $edits): ApprovalResult {
            $instance = ActionInstance::query()
                ->whereKey($instance->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($instance->state !== AutomationState::AwaitingApproval) {
                /*
                 * Two people opening S47 at once is the ordinary case, not the
                 * race: the queue is a shared list and both see the same top
                 * item. The second one is told rather than sending again.
                 */
                return ApprovalResult::refused(
                    'This message is '.$instance->state->label().' and is no longer waiting for review.',
                );
            }

            if ($edits !== []) {
                $refusal = $this->applyEdits($instance, $edits);

                if ($refusal !== null) {
                    return ApprovalResult::refused($refusal);
                }
            }

            /*
             * The same completeness check the rails make at the send path, made
             * again here — and this one is not redundant. #93 puts it in as
             * many words: *"a missing merge field blocks approval."* An
             * approver told at the moment of approving can fix the sentence;
             * one whose message is refused in a worker an hour later finds out
             * from a red row on a screen they were not looking at.
             */
            $rendered = $instance->rendered();

            if ($instance->action_type->needsMessageTemplate() && ! $rendered->isComplete()) {
                return ApprovalResult::refused(
                    'This message still has merge fields that could not be filled in: '
                    .implode(', ', [...$rendered->malformed, ...$rendered->unknown, ...$rendered->unresolved])
                    .'. Edit the message to fix them, or cancel it.',
                );
            }

            /*
             * A manual prompt is **done**, not queued.
             *
             * F5.4: an action *"presented to a human rather than fired"*, and
             * *"recorded identically once done"*. Approving one means the
             * person did the thing, so there is nothing for a worker to carry
             * out — dispatching a job for it would only produce a failure
             * saying a queue cannot do somebody's job for them.
             */
            $manual = $instance->action_type === AutomationActionType::ManualPrompt;

            $instance->forceFill([
                'state' => $manual ? AutomationState::Sent->value : AutomationState::Pending->value,
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
                'executed_at' => $manual ? now() : null,
                'error' => null,
            ])->save();

            $deal = $instance->deal;

            if ($deal instanceof Deal) {
                $this->activity->record(
                    subject: $deal,
                    eventType: $manual ? 'message.action_done' : 'message.approved',
                    summary: $manual
                        ? 'Marked the automation’s manual step done'
                        : 'Approved an automated message to go out',
                    actor: $actor,
                );
            }

            /*
             * Audited as well as timelined, and this is one of the entries
             * PRD §9 asks for by name: a person took responsibility for
             * something reaching a client, and that has to survive the
             * activity feed's retention.
             */
            $this->audit->record(
                action: 'message.approved',
                auditable: $instance,
                teamId: $instance->team_id,
                actorPersonId: $actor->getKey(),
                after: [
                    'action_type' => $instance->action_type->value,
                    'edited' => $edits !== [],
                ],
            );

            return ApprovalResult::approved($instance);
        });
    }

    /**
     * Stop it. IA §7's **Cancel**, which is not Delete: the row stays.
     *
     * S49 has to be able to answer *"why did the client never hear about
     * this"* months later, and a deleted row answers nothing. F5.8's stop
     * control is this, one message at a time.
     */
    public function cancel(ActionInstance $instance, Person $actor, ?string $reason = null): ApprovalResult
    {
        return DB::transaction(function () use ($instance, $actor, $reason): ApprovalResult {
            $instance = ActionInstance::query()
                ->whereKey($instance->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Cancellable only while nothing has gone. A `pending` instance is
             * included deliberately — it is scheduled, not sent — but one
             * carrying a `message_key` has been handed to a transport, and
             * marking that cancelled would be the record saying a client was
             * not told something they were told.
             */
            if ($instance->reachedTheProvider()) {
                /*
                 * *"Already gone out"* was a flat assertion, and it is the one
                 * thing nobody knows about this row — the whole reason the
                 * outcome is settled by a sweep hours later rather than here.
                 * A person reading it on S49 was told the opposite of what the
                 * screen beside it said.
                 */
                return ApprovalResult::refused(
                    'This message has already been handed to the mail service, so it cannot be stopped. '
                    .'Whether it arrived is not yet known.',
                );
            }

            if (! in_array($instance->state, [
                AutomationState::AwaitingApproval,
                AutomationState::Pending,
            ], true)) {
                return ApprovalResult::refused(
                    'This message is '.$instance->state->label().' and cannot be stopped.',
                );
            }

            $instance->forceFill([
                'state' => AutomationState::Cancelled->value,
                'error' => $reason !== null && trim($reason) !== ''
                    ? trim($reason)
                    : 'Somebody on the team stopped this before it went out.',
            ])->save();

            $deal = $instance->deal;

            if ($deal instanceof Deal) {
                $this->activity->record(
                    subject: $deal,
                    eventType: 'message.cancelled',
                    summary: 'Stopped an automated message before it went out',
                    actor: $actor,
                );
            }

            $this->audit->record(
                action: 'message.cancelled',
                auditable: $instance,
                teamId: $instance->team_id,
                actorPersonId: $actor->getKey(),
                reason: $reason,
            );

            return ApprovalResult::cancelled($instance);
        });
    }

    /**
     * Queue what approving released, **after** the transaction commits.
     *
     * A separate call rather than the tail of `approve()`, and the same seam
     * `AdvanceWorkflow` keeps for the same reason: a job dispatched inside a
     * transaction is a job a worker may pick up before the commit lands, or
     * after a rollback that never happened as far as the queue is concerned.
     */
    public function dispatch(ActionInstance $instance): void
    {
        if ($instance->state === AutomationState::Pending && ! $instance->reachedTheProvider()) {
            dispatch((new RunAutomation($instance->getKey()))->forTeam($instance->team_id));
        }
    }

    /**
     * Whether this message has a subject line at all.
     *
     * Read off the payload as it stood before the edit, which is the only
     * thing that knows: a `null` subject on an email somebody just cleared and
     * a `null` subject on a push template that never had one are the same
     * value meaning opposite things.
     *
     * @param  array<string, mixed>  $payload
     */
    private function carriesASubject(array $payload): bool
    {
        return is_string($payload['subject'] ?? null) && trim($payload['subject']) !== '';
    }

    /**
     * Fold an approver's changes into the instance's own payload.
     *
     * @param  array<string, mixed>  $edits
     * @return string|null a refusal, or null when the edit is sound
     */
    private function applyEdits(ActionInstance $instance, array $edits): ?string
    {
        $payload = $instance->payload ?? [];

        foreach (['subject', 'bodyHtml', 'bodyText'] as $field) {
            if (! array_key_exists($field, $edits)) {
                continue;
            }

            $value = $edits[$field];

            if ($value !== null && ! is_string($value)) {
                return 'That is not something this message can carry.';
            }

            /*
             * An emptied **subject** is refused, and the shape it arrives in is
             * not the obvious one. Laravel's `TrimStrings` and
             * `ConvertEmptyStringsToNull` mean an approver who clears S48's
             * field posts `null`, not `''` — and `AutomatedMessageMail` falls
             * back to the **team's name** for a null subject, so clearing the
             * field would quietly send a client an email subjected *"Vanterpool
             * Realty"*. A surprise, from the one screen that exists so the
             * words get read before they go.
             *
             * Which is why the test is *"does this message carry a subject at
             * all"* rather than *"is the value null"*: the fallback is right
             * for a channel that never had one and wrong for one somebody
             * emptied, and only the payload can tell those apart.
             *
             * The bodies are not held to this: a push message legitimately has
             * no HTML body, and `body_text` is already `required` on the
             * template it came from.
             */
            if ($field === 'subject' && trim((string) $value) === '' && $this->carriesASubject($payload)) {
                return 'A message needs a subject line. Type one, or cancel this message.';
            }

            /*
             * Checked **after** the edit, on the words that would actually be
             * sent. `MergeFields::strayBraceRuns()` is the check PR #175's
             * review found missing — `{{ client_name }` with a brace dropped
             * saves clean, renders verbatim, and reaches the client as the
             * template's own internals. An approver typing into a textarea can
             * produce that as easily as a template author can.
             */
            $stray = MergeFields::strayBraceRuns(
                is_string($value) ? $value : null,
                markup: $field === 'bodyHtml',
            );

            if ($stray !== []) {
                return 'A merge field in the message is missing a brace: “'
                    .implode('” and “', $stray).'”. Fix it before approving.';
            }

            /*
             * And **no merge fields at all** in an edited field.
             *
             * This is the part that is easy to get wrong, and the first
             * version of this method did: it filtered the carried-over lists
             * and never noticed a token somebody had just typed. The payload
             * is text that has *already been substituted* — the render
             * happened at raise time, so F5.10 could pre-fill it — which means
             * a `{{ closing_date }}` typed here has nothing left to replace it
             * and reaches the client exactly as written. Whether the token is
             * one this product knows is beside the point: registered or not,
             * it goes out as braces.
             *
             * Refused rather than substituted, because substituting would make
             * an approver's edit behave differently from the template it came
             * from — the values would be fixed at approval time rather than at
             * raise time, on one message out of two raised from the same
             * words. The approver is looking at the deal; they can type the
             * date.
             */
            $tokens = MergeFields::extract(is_string($value) ? $value : null);

            if ($tokens !== []) {
                return 'The words in this message are already filled in, so “{{ '
                    .implode(' }}” and “{{ ', $tokens)
                    .' }}” would go to the client exactly as written. Type the value instead.';
            }

            $payload[$field] = $value;
        }

        /*
         * And the lists are narrowed to what the edited words still contain.
         *
         * They describe the payload, and the payload has just changed: an
         * approver who rewrote the sentence containing `{{ mls_link }}` has
         * removed the reason the message was incomplete, and a stale
         * `unresolved` would go on blocking an approval that is now sound.
         *
         * A *filter* rather than a fresh render, and only because of the rule
         * directly above: no edited field may contain a token at all, so the
         * only tokens that can survive are ones in a field nobody touched —
         * a subject left alone while the body was fixed, which must go on
         * blocking. Nothing new can appear here, which is what the refusal
         * above is for.
         */
        $body = implode("\n", array_filter([
            is_string($payload['subject'] ?? null) ? $payload['subject'] : null,
            is_string($payload['bodyText'] ?? null) ? $payload['bodyText'] : null,
            is_string($payload['bodyHtml'] ?? null) ? $payload['bodyHtml'] : null,
        ], is_string(...)));

        $remaining = MergeFields::extract($body);

        foreach (['unresolved', 'unknown'] as $list) {
            $payload[$list] = array_values(array_filter(
                is_array($payload[$list] ?? null) ? $payload[$list] : [],
                fn (mixed $token): bool => is_string($token) && in_array($token, $remaining, true),
            ));
        }

        /*
         * `malformed` **is** recomputed, over all three fields.
         *
         * It used to be assigned `[]` with a comment claiming it was
         * recomputed, which was the comment describing the intention and the
         * code doing the opposite: `strayBraceRuns()` ran only against the
         * value being edited, so an approver who fixed the body released a
         * subject still carrying `{{ client_name }` — PR #175's finding
         * arriving through the approval path instead of the template editor,
         * and past the `isComplete()` check the rails depend on.
         *
         * A property of the text is only a property of the text if you read
         * the text.
         */
        $payload['malformed'] = array_values(array_unique([
            ...MergeFields::strayBraceRuns(
                is_string($payload['subject'] ?? null) ? $payload['subject'] : null,
            ),
            // Markup, so `<style>` and `<script>` blocks come out before the
            // braces are counted — the same relaxation `RenderMessage` makes,
            // and for the same reason: nested CSS rules close with `}}`.
            ...MergeFields::strayBraceRuns(
                is_string($payload['bodyHtml'] ?? null) ? $payload['bodyHtml'] : null,
                markup: true,
            ),
            ...MergeFields::strayBraceRuns(
                is_string($payload['bodyText'] ?? null) ? $payload['bodyText'] : null,
            ),
        ]));

        $instance->forceFill(['payload' => $payload]);

        return null;
    }
}
