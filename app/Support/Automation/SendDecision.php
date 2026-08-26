<?php

declare(strict_types=1);

namespace App\Support\Automation;

/**
 * What the rails decided about one message (PRD §4.5 F5.9 · issue #96).
 *
 * Four outcomes, and two of them are ones a boolean would lose.
 *
 *  - **Send**, to these addresses. Which may not be the addresses the message
 *    was raised for: sandbox mode rewrites them, and that is a *permitted*
 *    send to a *different* recipient rather than a refusal.
 *  - **Halt** — the team's kill switch, or the ceiling. F5.9 is explicit that
 *    exceeding the limit *"halts sending and alerts — it does not silently
 *    drop"*, so a halted instance stays `pending` and is picked up again.
 *  - **Refuse** — something about **this message** means it must never go: an
 *    unresolved merge field, no recipients, an action this build cannot carry
 *    out. The row is marked failed and the deal's timeline says so, because a
 *    message that should have gone and did not is exactly what PRD §1.1's
 *    *"has the client been told?"* is asking about.
 *  - **Stand down** — the row is no longer this worker's to send. Somebody
 *    stopped it, somebody else already sent it, or it is back in the approval
 *    queue. Nothing is written at all.
 *
 * The difference between halting and refusing is whether trying again later
 * could work. The difference between refusing and standing down is **whose
 * problem it is**, and collapsing those two is the defect round 1 found: a job
 * still in flight when somebody pressed Stop arrived, read *"this is
 * cancelled"*, and marked the row `failed` — destroying the reason a person
 * typed, writing *"An automated message did not go out: This message is
 * Cancelled"* onto the deal, and, worst of the three, flipping a `cancelled`
 * row to `failed` where `RaiseAutomations::alreadyRaised()` counts it. A
 * skipped stage that was later reopened then silently never re-raised its
 * message. A boolean answer would make a rate limit look like a broken
 * message, and a broken message look like something worth retrying forever.
 */
final readonly class SendDecision
{
    /**
     * @param  list<array{name: string, email: string}>  $recipients
     */
    private function __construct(
        public bool $allowed,
        public array $recipients = [],
        public ?string $reason = null,
        public bool $retryable = false,
        public bool $redirected = false,
        public bool $ownedByAnother = false,
    ) {}

    /**
     * @param  list<array{name: string, email: string}>  $recipients
     */
    public static function send(array $recipients, bool $redirected = false): self
    {
        return new self(true, $recipients, redirected: $redirected);
    }

    /** Try again later: the wall is the team's, not the message's. */
    public static function halt(string $reason): self
    {
        return new self(false, reason: $reason, retryable: true);
    }

    /** Never: this message cannot become sendable by waiting. */
    public static function refuse(string $reason): self
    {
        return new self(false, reason: $reason, retryable: false);
    }

    /**
     * Not ours: somebody else owns this row's outcome now.
     *
     * The caller writes **nothing** — not the state, not the error, not a
     * timeline entry. The same treatment `ExecuteAction` already gives a
     * worker that loses the `message_key` claim, and for the same reason:
     * whoever got there first owns what happens next, and a second worker
     * narrating its own failure over the top is how a stopped message ends up
     * on a deal's timeline reading as a mail-transport error.
     */
    public static function standDown(string $reason): self
    {
        return new self(false, reason: $reason, ownedByAnother: true);
    }
}
