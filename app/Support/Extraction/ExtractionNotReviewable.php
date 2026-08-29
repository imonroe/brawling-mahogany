<?php

declare(strict_types=1);

namespace App\Support\Extraction;

use RuntimeException;

/**
 * A proposal cannot be accepted as it stands.
 *
 * Two cases, both of which a person can meet by doing something ordinary, so
 * both carry a sentence written for them rather than for a log.
 */
final class ExtractionNotReviewable extends RuntimeException
{
    private function __construct(public readonly string $reasonCode, string $message)
    {
        parent::__construct($message);
    }

    public static function alreadyReviewed(): self
    {
        return new self(
            'already_reviewed',
            'Somebody has already decided this one. Reload the page to see what it says now.',
        );
    }

    /**
     * Confirm was pressed on something that is not a date.
     *
     * The model returns what it read, and what it read is sometimes *"ten days
     * after closing"* or a misprint. S66 shows that verbatim on purpose, so a
     * person can see it — and this is what happens if they accept it anyway.
     *
     * The refused value is **not** in the message. It came out of somebody's
     * contract, and this message reaches a session flash and possibly a log.
     */
    public static function notADate(string $value): self
    {
        return new self(
            'not_a_date',
            'That is not a date this app can put on the calendar. '
                .'Edit it to a day — the format is year, month, day — or discard it.',
        );
    }
}
