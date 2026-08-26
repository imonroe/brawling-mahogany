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
use Illuminate\Mail\Mailables\Address;
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
 * key, which every path refuses. The other ordering leaves a row that looks
 * unsent and is not, and sends a client the same message twice.
 */
final class ExecuteAction
{
    public function __construct(
        private readonly SendRails $rails,
        private readonly RecordActivity $activity,
        private readonly AuditLogger $audit,
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
             * Halt and refuse are different outcomes and the row records the
             * difference. F5.9: exceeding a limit *"halts sending and alerts —
             * it does not silently drop"*, so a halted instance stays
             * `pending` with the reason on it and the scheduler picks it up
             * again once the window rolls. A refusal is final.
             */
            if ($decision->retryable) {
                $instance->forceFill([
                    'attempts' => $instance->attempts + 1,
                    'error' => $decision->reason,
                ])->save();

                return;
            }

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

            $pending->send(new AutomatedMessageMail(
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
             */
            $instance->forceFill([
                'state' => AutomationState::Failed->value,
                'attempts' => $instance->attempts + 1,
                'executed_at' => now(),
                /*
                 * The class rather than the message. A transport exception
                 * routinely quotes the recipient address back — PRD §9: no PII
                 * in logs, ever, and `action_instances.error` is read on S49
                 * by anybody who can open the deal.
                 */
                'error' => 'The mail transport rejected this message ('.$exception::class.').',
            ])->save();

            throw $exception;
        }

        $instance->forceFill([
            'state' => AutomationState::Sent->value,
            'executed_at' => now(),
            'attempts' => $instance->attempts + 1,
            'error' => null,
        ])->save();

        $this->recordSent($instance, $decision);
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

        if ($deal instanceof Deal) {
            $this->activity->record(
                subject: $deal,
                eventType: $decision->redirected ? 'message.redirected' : 'message.sent',
                summary: $decision->redirected
                    ? "Sandbox: “{$subject}” went to the team rather than ".$this->names($instance)
                    : "Emailed {$this->names($instance)}: “{$subject}”",
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
                'redirected' => $decision->redirected,
                'message_key' => $instance->message_key,
            ],
        );
    }

    /**
     * The instance's own intended recipients, for a sentence a person reads.
     *
     * Never `$decision->recipients` — under sandbox those are the team owner,
     * and *"Emailed Ian Monroe"* about a message meant for the seller is the
     * sentence that makes somebody think the client was told.
     */
    private function names(ActionInstance $instance): string
    {
        $names = array_column($instance->recipients(), 'name');

        return $names === [] ? 'nobody' : implode(', ', $names);
    }

    /**
     * @param  list<array{name: string, email: string}>  $recipients
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
    private function fail(ActionInstance $instance, string $reason): void
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
                summary: 'An automated message did not go out: '.$reason,
            );
        }
    }
}
