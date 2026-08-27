<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/**
 * What became of one message to one recipient (PRD §4.5 F5.8 · issue #95).
 *
 * ## Why this is not {@see AutomationState}
 *
 * `action_instances.state` answers *"did this product manage to hand the
 * message over"*, and it is one value for the whole instance. This answers
 * *"did it arrive"*, and it is one value **per recipient** — a message to a
 * seller and their attorney can be delivered to one and hard-bounce off the
 * other, and PRD §1.1's second question (*"has the client been told?"*) is
 * asked about a person, not about a row.
 *
 * F5.8's list is the five below. They are ordered by how far the message got,
 * because that is the only ordering the screen needs and it makes
 * {@see self::isFinal()} a comparison rather than a list to keep in step.
 */
enum DeliveryStatus: string implements HasLabel
{
    use ProvidesOptions;

    /** Handed to the provider, which accepted it. Nothing has come back yet. */
    case Sent = 'sent';

    /** The provider says it reached the receiving server. */
    case Delivered = 'delivered';

    /** It opened. Best-effort, and the screen says so — see {@see self::isEvidence()}. */
    case Opened = 'opened';

    /** It came back. A hard bounce suppresses the address; a soft one does not. */
    case Bounced = 'bounced';

    /** Somebody pressed "this is spam". Worse than a bounce, for the reason below. */
    case Complained = 'complained';

    /**
     * Never handed over at all: the address is on the suppression list.
     *
     * A row rather than an absence, and round 1 of review is why. Dropping a
     * suppressed address and sending to the rest is the right behaviour — one
     * dead mailbox must not silence a reachable client — but the first version
     * recorded the drop **nowhere**: the timeline said *"Emailed Dana Okafor,
     * Sam Reilly"*, the audit counted one recipient, and S49 listed Sam beside
     * a "Goes to" naming both. PRD §1.1's second question is *"has the client
     * been told?"*, and the answer for Dana was silence — the one answer the
     * rest of the send path refuses to give.
     */
    case Suppressed = 'suppressed';

    public function label(): string
    {
        return match ($this) {
            self::Sent => 'Sent',
            self::Delivered => 'Delivered',
            self::Opened => 'Opened',
            self::Bounced => 'Bounced',
            self::Complained => 'Marked as spam',
            self::Suppressed => 'Not sent',
        };
    }

    /**
     * How far along this is, so a later notification cannot walk one back.
     *
     * SNS delivers at least once and **in no particular order**: a Delivery
     * notification can arrive after an Open, and a duplicate of either can
     * arrive an hour later. Ranking makes the write a max() rather than a
     * last-writer-wins, which is what makes replay harmless without a table
     * of notification ids to remember.
     *
     * Bounce and complaint sit above the happy path deliberately. A message
     * can be delivered to the server and then bounce off the mailbox, and the
     * bounce is the fact somebody needs; nothing may overwrite it.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Sent => 0,
            self::Delivered => 1,
            self::Opened => 2,
            self::Bounced => 3,
            self::Complained => 4,
            /*
             * Top, and unreachable from below: a suppressed row was never
             * handed to a provider, so no notification can name it and nothing
             * may move it. It is written at that rank and stays there.
             */
            self::Suppressed => 5,
        };
    }

    /**
     * Whether this outcome means the message did not land.
     *
     * Drives the screen's tone and the alert, and is a question about the
     * *recipient* rather than about the send: `action_instances.state` is
     * `sent` for every row here, including the ones that bounced. That gap is
     * the whole reason this table exists.
     */
    public function isFailure(): bool
    {
        return $this === self::Bounced
            || $this === self::Complained
            || $this === self::Suppressed;
    }

    /**
     * Every status that {@see self::isFailure()} answers true for.
     *
     * Derived rather than listed a second time in a query scope. Round 1 of
     * review found the enum and `MessageDelivery::scopeFailed()` already one
     * case apart from each other the moment a third failure existed, which is
     * how a delivery stops being counted by the sweep that exists to count it.
     *
     * @return list<string>
     */
    public static function failureValues(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->isFailure()),
        ));
    }

    /**
     * Whether this is evidence of a person having seen the message.
     *
     * Only `opened`, and even that is weak: an open is measured with a tracking
     * pixel, which a great many clients block. PRD §12.2 measures **delivered**
     * above 98% and does not measure opens, which is the right way round — a
     * missing open says nothing at all, so nothing in this product may treat
     * one as a client not having been told.
     */
    public function isEvidence(): bool
    {
        return $this === self::Opened;
    }
}
