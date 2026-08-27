<?php

declare(strict_types=1);

namespace App\Support\Automation;

use App\Enums\AutomationActionType;
use App\Enums\AutomationState;
use App\Enums\TaskSource;
use App\Mail\AutomatedMessageMail;
use App\Models\ActionInstance;
use App\Models\Deal;
use App\Models\Task;
use App\Models\Team;
use App\Support\Activity\RecordActivity;
use App\Support\Audit\AuditLogger;
use App\Support\Delivery\RecordDeliveries;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * The only thing that carries out an action instance (PRD §4.5 · issue #92).
 *
 * The same argument `AdvanceWorkflow` makes for workflow state, applied to the
 * one table in this product that can reach a client: a second caller that sent
 * the mail and forgot the rails, or forgot to write the state, or forgot the
 * activity entry, would look like it worked. `SingleMutationPathTest` guards
 * `action_instances.state` for exactly that reason.
 *
 * ## The rails are asked here, and not one line earlier
 *
 * Issue #96 puts them *"at the send path, in the queue worker"* and says why:
 * a rail checked at dispatch is a rail a message queued five minutes before
 * somebody pulled the cord sails straight past. So {@see SendRails::decide()}
 * is the statement immediately before the mailer, every time, with nothing
 * between them that could take a different branch.
 *
 * ## The order of the two writes at the moment of sending
 *
 * `message_key` is generated and **saved before** `Mail::send()` is called,
 * and the state moves to `sent` after it returns. That ordering is the whole
 * idempotency guarantee, and the crash window it leaves is deliberately the
 * safe one: a worker killed between the two leaves a `pending` row carrying a
 * key, which every path stands down on and `automations:reap-unconfirmed`
 * settles from a distance. The other ordering leaves a row that looks
 * unsent and is not, and sends a client the same message twice.
 */
final class ExecuteAction
{
    public function __construct(
        private readonly SendRails $rails,
        private readonly RecordActivity $activity,
        private readonly AuditLogger $audit,
        private readonly RecordDeliveries $deliveries,
    ) {}

    /**
     * Carry out one instance, or record why it did not happen.
     *
     * Never throws for an ordinary refusal. A message with an unfilled merge
     * field is a fact about the template, not an exception — and a job that
     * threw would be retried by the queue until the attempts ran out, writing
     * the same failure into the log five times. What does throw is the
     * transport falling over, because that genuinely is worth retrying.
     */
    public function handle(ActionInstance $instance, Team $team): void
    {
        /*
         * **Is this row still ours to act on** — for every action type, not
         * just the one that goes through the rails.
         *
         * The ownership gate lives in `SendRails::decide()`, and `handle()`
         * only consults it for `send_email`. So `create_task` had no state
         * check at all: a task automation somebody **stopped** was carried out
         * anyway, the row moved `cancelled → sent`, the reason a person typed
         * was destroyed, and because `RaiseAutomations::alreadyRaised()`
         * counts everything except `cancelled`, a stage that was skipped and
         * later reopened was silently never owed that task again. Round 1's
         * finding #2, one enum case over.
         *
         * And it made a duplicate delivery create the task **twice**. The
         * `message_key` claim is what stops that for a send; this branch has
         * no other guarantee, and `RunAutomation`'s own docblock says
         * `ShouldBeUnique` is not one.
         *
         * Nothing is written here, for the reason the rails stand down rather
         * than refusing: whoever put the row in that state owns what happens
         * next, and a second worker narrating over the top is how a stopped
         * message ends up on a timeline as a transport error.
         */
        if ($instance->state !== AutomationState::Pending) {
            return;
        }

        match ($instance->action_type) {
            AutomationActionType::SendEmail => $this->send($instance, $team),
            AutomationActionType::CreateTask => $this->createTask($instance),
            /*
             * F5.4's manual prompt is done by a person and recorded by
             * {@see ApproveMessage}, so it never reaches a worker. Named
             * rather than left to the catch-all, because the catch-all's
             * sentence is about a build that cannot do something and this one
             * is about a queue that should not.
             */
            AutomationActionType::ManualPrompt => $this->fail(
                $instance,
                'A manual action is marked done by a person, not carried out by the queue.',
            ),
            default => $this->fail(
                $instance,
                'This build cannot carry out a '.$instance->action_type->label().'.',
            ),
        };
    }

    /**
     * F5.9's rails, then the transport, in that order and no other.
     */
    private function send(ActionInstance $instance, Team $team): void
    {
        $decision = $this->rails->decide($instance, $team);

        if (! $decision->allowed) {
            /*
             * Three ways not to send, and the row records which.
             *
             * **Stand down** writes nothing at all: the row is no longer this
             * worker's, and a second worker narrating its own failure over
             * somebody's Stop is how a cancelled message ends up on a deal's
             * timeline reading as a transport error. Same treatment as losing
             * the `message_key` claim below.
             */
            if ($decision->ownedByAnother) {
                return;
            }

            /*
             * **Halt** keeps it. F5.9: exceeding a limit *"halts sending and
             * alerts — it does not silently drop"*, so the instance stays
             * `pending` with the reason on it and the sweep picks it up once
             * the window rolls.
             *
             * `attempts` is deliberately **not** incremented. It counts tries
             * at the transport, and a halt never reached one — a team that
             * pulls the kill switch and leaves it on for three weeks would
             * otherwise overflow the column, which is `smallint`, and turn a
             * paused queue into a throwing one.
             */
            if ($decision->retryable) {
                $instance->forceFill(['error' => $decision->reason])->save();

                return;
            }

            /*
             * **Refuse** is final and is about this message: an unfillable
             * merge field, no recipients, an action this build cannot carry
             * out. Those are worth a person's attention, so they land on the
             * deal.
             */
            $this->fail($instance, (string) $decision->reason);

            return;
        }

        /*
         * The key first, and saved, before anything reaches a transport.
         *
         * Claimed with a conditional UPDATE rather than a read-then-write:
         * two workers holding the same row is exactly what a queue does after
         * a visibility timeout, and both would find `message_key` null in the
         * model they deserialised. `WHERE message_key IS NULL` makes the
         * database decide which of them owns the send, in one statement, with
         * no window between the check and the write.
         */
        $key = (string) Str::ulid();

        $claimed = ActionInstance::query()
            ->whereKey($instance->getKey())
            ->whereNull('message_key')
            ->update(['message_key' => $key, 'updated_at' => now()]);

        if ($claimed === 0) {
            // Somebody else got there first. Nothing to do and nothing to say:
            // the worker that claimed it owns the outcome.
            return;
        }

        $instance->forceFill(['message_key' => $key]);

        $rendered = $instance->rendered();

        try {
            $pending = Mail::to($this->addresses($decision->recipients));

            $sent = $pending->send(new AutomatedMessageMail(
                instance: $instance,
                rendered: $rendered,
                team: $team,
                redirected: $decision->redirected,
            ));
        } catch (Throwable $exception) {
            /*
             * Recorded and re-thrown, so the queue's own retry sees it — and
             * the retry finds `message_key` set and refuses, which is correct.
             * A transport that threw may still have delivered, and this
             * product's rule about a message it cannot take back is that it
             * would rather tell somebody it is unsure than send it twice.
             *
             * **Through `fail()`, not written inline.** This branch used to
             * set the same four columns itself, which left the commonest
             * outage in the product — an expired credential — as the one
             * failure with no entry on the deal's timeline. `fail()` writes
             * exactly these columns and the entry, so the difference was only
             * ever what got forgotten.
             *
             * The reason names the class rather than the message. A transport
             * exception routinely quotes the recipient address back — PRD §9:
             * no PII in logs, ever, and `action_instances.error` is read on
             * S49 by anybody who can open the deal.
             */
            $this->fail(
                $instance,
                'The mail transport rejected this message ('.$exception::class.').',
            );

            throw $exception;
        }

        /*
         * What the provider called it, which is the only thing a bounce
         * notification will name (#95).
         *
         * Read from the transport's answer rather than generated, and null
         * where the transport did not give one — the array transport used in
         * tests, a local Mailpit, an SMTP server that answered without an id.
         * A null here is honest: nothing will ever come back about this
         * message, and `message_deliveries` records it as `sent` forever,
         * which is exactly what is known.
         *
         * Never confused with `message_key`. That one is **ours**, written
         * before the mailer was called, and it is what stops a second send;
         * this one is theirs, arrives afterwards, and is only a join key.
         * `action_instances`' migration argues the distinction at length.
         */
        $providerMessageId = $this->providerMessageId($sent);

        $instance->forceFill([
            'state' => AutomationState::Sent->value,
            'executed_at' => now(),
            'attempts' => $instance->attempts + 1,
            'error' => null,
            'provider_message_id' => $providerMessageId,
        ])->save();

        /*
         * One row per address, after the instance is saved rather than before.
         * A delivery row pointing at an instance still marked `pending` is a
         * row S49 would render under the wrong heading if anything read it in
         * between.
         */
        $this->deliveries->forSend($instance, $decision, $providerMessageId);

        $this->recordSent($instance, $decision);
    }

    /**
     * A claim nobody came back for, decided far from the claim (#92).
     *
     * `automations:reap-unconfirmed` calls this and nothing else does. The
     * distinction it can make and a worker cannot is **distance**: a worker
     * asked at the moment of the second delivery cannot tell a crashed sibling
     * from a live one, because the staleness it would measure is the very
     * thing that caused the delivery. Hours later there is no live sibling to
     * contradict — a send that has not written its outcome by then is not
     * going to.
     *
     * It records `failed`, and the sentence is deliberately not a claim in
     * either direction: nobody knows whether the message arrived, and a person
     * deciding whether to resend needs to be told exactly that rather than
     * reassured.
     *
     * Lives here rather than in the command because this file is the only
     * writer of `action_instances.state` on the send path, which is what
     * `SingleMutationPathTest` holds. A command that wrote the column itself
     * would be the second implementation that remembers the state and forgets
     * the timeline entry.
     */
    public function reapUnconfirmed(ActionInstance $instance): void
    {
        if ($instance->state !== AutomationState::Pending || ! $instance->reachedTheProvider()) {
            return;
        }

        $reason = 'This message was handed to a transport and never confirmed. '
            .'It may have reached the recipient — check before sending it again.';

        $this->fail($instance, $reason, summary: 'An automated message was sent and never confirmed: '.$reason);

        /*
         * **And the audit log, which an ordinary refusal does not get.**
         *
         * A refused message is a fact about a template: the merge field was
         * unfillable, the rule resolved to nobody. The deal's timeline is the
         * right home for that and its 30-day retention is fine.
         *
         * This one is *"a message may have reached a client and nobody knows"*,
         * which is exactly the question somebody asks months later and exactly
         * what the append-only record is for.
         */
        $this->audit->record(
            action: 'message.unconfirmed',
            auditable: $instance,
            teamId: $instance->team_id,
            after: [
                'message_key' => $instance->message_key,
                'recipient_count' => count($instance->recipients()),
                'claimed_at' => $instance->updated_at?->toIso8601String(),
            ],
        );
    }

    /**
     * F5.3's *create a task*, which reaches nobody outside the team.
     *
     * No rails: they are about messages leaving the building, and a task is
     * a row on a screen the team already has open. What it does share with a
     * send is `TaskSource` — #69's argument, one enum over: *"a manual task
     * and a task the machine proposed must never render alike."*
     */
    private function createTask(ActionInstance $instance): void
    {
        $title = ($instance->config ?? [])['taskTitle'] ?? null;

        if (! is_string($title) || trim($title) === '') {
            $this->fail($instance, 'This automation has no task title to create a task from.');

            return;
        }

        $deal = $instance->deal;

        if (! $deal instanceof Deal) {
            $this->fail($instance, 'The deal this automation belongs to no longer exists.');

            return;
        }

        $task = new Task;

        $task->forceFill([
            'team_id' => $instance->team_id,
            'deal_id' => $deal->getKey(),
            'stage_id' => $instance->stage_id,
            'title' => trim($title),
            /*
             * **Never `is_required`**, and this is #69's finding rather than a
             * default carried over. `required_tasks_complete` counts the
             * required tasks on one stage, so a required task raised by a
             * `stage_start` automation on that same stage is a gate the team
             * did not write blocking a stage they just entered. A team that
             * wants the task to block adds a tasks gate deliberately.
             */
            'is_required' => false,
            'source' => TaskSource::Automation->value,
            'sort_order' => 0,
        ])->save();

        $instance->forceFill([
            'state' => AutomationState::Sent->value,
            'executed_at' => now(),
            'attempts' => $instance->attempts + 1,
            'error' => null,
        ])->save();

        $this->activity->record(
            subject: $deal,
            eventType: 'task.added',
            summary: "An automation added the task “{$task->title}”",
        );
    }

    /**
     * Done, said so on the deal, and written into the append-only record.
     *
     * PRD §1.1's second question is *"has the client been told?"*, and the
     * timeline is where a team answers it. The audit entry is the separate
     * record with the separate reader: a message that reached a client is a
     * fact somebody may need to prove months later, and the activity feed has
     * a 30-day retention that the audit log does not.
     */
    private function recordSent(ActionInstance $instance, SendDecision $decision): void
    {
        $deal = $instance->deal;

        $payload = $instance->payload ?? [];

        $subject = $instance->rendered()->subject
            ?? (is_string($payload['templateName'] ?? null) ? $payload['templateName'] : 'a message');

        /*
         * **Who was actually written to, and who was not.**
         *
         * Round 1 of review's first blocker: this sentence was composed from
         * the *intended* list, so a message whose suppressed recipient had
         * been dropped read *"Emailed Dana Okafor, Sam Reilly"* over a send
         * Dana never received. `names()`'s own docblock argues for the
         * intended list — and it is right about the case it was written for
         * (sandbox), and wrong about this one. The two are different
         * questions: sandbox changes **where** a message went, suppression
         * changes **whether** it went at all.
         */
        /*
         * **Diffed on the address, never on the name**, which is round 2 of
         * review and round 1's blocker arriving a third time.
         *
         * `array_diff` removes every element equal to a withheld value, so
         * diffing on names collapsed two recipients who share a display name
         * and differ only by address — Sr. and Jr. on one deal, which is not
         * exotic in residential real estate. Measured: a message that reached
         * `john.jr@example.test` produced *"Emailed nobody"* on the deal's
         * activity feed. The audit was right and the sentence a person reads
         * was wrong, which is worse than silence.
         *
         * The address is the only field that is actually unique here — the
         * same reason `message_deliveries` keys its history on it.
         */
        $withheldEmails = array_map(
            static fn (string $email): string => mb_strtolower($email),
            array_column($decision->withheld, 'email'),
        );

        $reached = $this->joinNames(array_values(array_map(
            static fn (array $recipient): string => $recipient['name'],
            array_filter(
                $instance->recipients(),
                static fn (array $recipient): bool => ! in_array(
                    mb_strtolower($recipient['email']),
                    $withheldEmails,
                    true,
                ),
            ),
        )));

        /*
         * And the withheld half names the **address** beside the person, for
         * the same collision: *"John Smith was not written to"* names somebody
         * who was, when two of them share a name. It is also the thing to go
         * and correct, so it is the more useful sentence either way. The
         * activity feed is a team-scoped deal screen, not a log — S49 shows
         * the same address one click away.
         */
        /*
         * Two tenses, because sandbox is a rehearsal. *"Was not written to"*
         * is false of a redirected send — nobody was — and *"would have been
         * skipped"* is the sentence a team came to sandbox for. Round 3 of
         * review measured the redirected summary naming a dead address among
         * the people a live send would have reached.
         */
        $missed = $decision->withheld === []
            ? ''
            : ' '.implode(' ', array_map(
                static fn (array $recipient): string => $recipient['name']
                    .' ('.$recipient['email'].') '
                    .($decision->redirected
                        ? 'would have been skipped — that address can no longer be reached.'
                        : 'was not written to — that address can no longer be reached.'),
                $decision->withheld,
            ));

        if ($deal instanceof Deal) {
            $this->activity->record(
                subject: $deal,
                eventType: $decision->redirected ? 'message.redirected' : 'message.sent',
                /*
                 * The redirected branch ends on a **full stop** before
                 * `$missed` is appended. Round 2 measured *"rather than Sam
                 * Reilly Dana Okafor was not written to"* reading as one
                 * four-word name, because the branch ended on a name and the
                 * clause was appended with only a space. The `message.sent`
                 * branch was fine by luck — it ends on `: “{subject}”`.
                 */
                summary: $decision->redirected
                    ? "Sandbox: “{$subject}” went to the team rather than {$reached}.".$missed
                    : "Emailed {$reached}: “{$subject}”".$missed,
            );
        }

        $this->audit->record(
            action: 'message.sent',
            auditable: $instance,
            teamId: $instance->team_id,
            actorPersonId: $instance->approved_by,
            after: [
                /*
                 * A count and a key, and **no addresses**.
                 *
                 * The temptation is to write down who it went to, since that
                 * is what an audit is for. `AuditRedactor` exists to make that
                 * impossible on purpose — PRD §9, no PII in logs, ever — and
                 * a key spelled to slip past it would be defeating the
                 * mechanism rather than using it. The addresses are on the
                 * instance this entry is `auditable` against, under the access
                 * control a rendered client message deserves; the entry says
                 * which row and when, which is what makes them findable.
                 */
                'recipient_count' => count($decision->recipients),
                /*
                 * Counted separately, because it is the number an auditor
                 * would ask about: a send recorded as reaching one person out
                 * of two is only honest if the other one is somewhere.
                 */
                'withheld_count' => count($decision->withheld),
                'redirected' => $decision->redirected,
                'message_key' => $instance->message_key,
            ],
        );
    }

    /**
     * Names for a sentence a person reads.
     *
     * The list handed in is the **intended** one minus anything withheld —
     * never `$decision->recipients`, which under sandbox is the team owner,
     * and *"Emailed Ian Monroe"* about a message meant for the seller is the
     * sentence that makes somebody think the client was told.
     *
     * @param  list<string>  $names
     */
    private function joinNames(array $names): string
    {
        return $names === [] ? 'nobody' : implode(', ', $names);
    }

    /**
     * The id the provider assigned, if it gave one.
     *
     * `SentMessage::getMessageId()` is the transport's answer, which for SES
     * over SMTP is the id in its `250 Ok` line — the same id SNS will name in
     * a bounce. Symfony falls back to the `Message-ID` header when a transport
     * offers nothing, which is what the array transport does, so a test cannot
     * tell the two apart and this class does not try to: either way it is what
     * the send is known by.
     */
    private function providerMessageId(?SentMessage $sent): ?string
    {
        $id = $sent?->getMessageId();

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @param  list<array{name: string, email: string, membershipId: string|null}>  $recipients
     * @return list<Address>
     */
    private function addresses(array $recipients): array
    {
        return array_map(
            static fn (array $recipient): Address => new Address($recipient['email'], $recipient['name']),
            $recipients,
        );
    }

    /**
     * Not sent, and it never will be — with a sentence somebody can act on.
     *
     * S49 renders `error` verbatim, so it is written for a person rather than
     * for a log: *"this message resolved to nobody on this deal"* names
     * something to go and fix, and a stack trace names nothing.
     */
    private function fail(ActionInstance $instance, string $reason, ?string $summary = null): void
    {
        $instance->forceFill([
            'state' => AutomationState::Failed->value,
            'executed_at' => now(),
            'attempts' => $instance->attempts + 1,
            'error' => $reason,
        ])->save();

        $deal = $instance->deal;

        if ($deal instanceof Deal) {
            /*
             * On the deal's timeline rather than in a log nobody opens. A
             * silent failure is the specific thing this product must not do:
             * PRD §1.1's question is *"has the client been told?"* and the
             * worst answer is one nobody knows to give.
             */
            $this->activity->record(
                subject: $deal,
                eventType: 'message.failed',
                /*
                 * The caller may supply its own sentence, and one caller has
                 * to: *"did not go out"* in front of *"may have reached the
                 * recipient"* is a line that contradicts itself, on the deal
                 * timeline, about the one operation this product cannot take
                 * back. The careful wording `reapUnconfirmed()` chose was
                 * doing no work while this prefix stood in front of it.
                 */
                summary: $summary ?? $this->failureSentence($instance, $reason),
            );
        }
    }

    /**
     * What the timeline says happened, in words true of *this* action type.
     *
     * *"An automated message did not go out"* was the sentence for every
     * branch, including the four that never involved a message: a `create_task`
     * with no title, a manual prompt that reached a worker, an action type this
     * build cannot carry out. A team read that a client had not been emailed
     * when nothing had ever been going to email them.
     */
    private function failureSentence(ActionInstance $instance, string $reason): string
    {
        return $instance->action_type === AutomationActionType::SendEmail
            ? 'An automated message did not go out: '.$reason
            : 'An automation did not run: '.$reason;
    }
}
