<?php

declare(strict_types=1);

namespace App\Support\Automation;

/**
 * What the rails decided about one message (PRD §4.5 F5.9 · issue #96).
 *
 * Three outcomes, and the middle one is the one a boolean would lose.
 *
 *  - **Send**, to these addresses. Which may not be the addresses the message
 *    was raised for: sandbox mode rewrites them, and that is a *permitted*
 *    send to a *different* recipient rather than a refusal.
 *  - **Halt** — the team's kill switch, or the ceiling. F5.9 is explicit that
 *    exceeding the limit *"halts sending and alerts — it does not silently
 *    drop"*, so a halted instance stays `pending` and is picked up again.
 *  - **Refuse** — something about this message means it must never go: an
 *    unresolved merge field, no recipients, a message already accepted by the
 *    provider.
 *
 * The difference between halting and refusing is whether trying again later
 * could work. A boolean answer would make a rate limit look like a broken
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
}
