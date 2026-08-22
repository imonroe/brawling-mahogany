<?php

declare(strict_types=1);

namespace App\Support\Import;

/**
 * A row that could not be imported (Screen Inventory S33, partial failure).
 *
 * *"Row 340 is malformed — import the other 339 and report row 340
 * specifically."*
 *
 * The row number and a reason, and **never the value**. PRD §9 puts no PII in
 * logs, and a failure report that quotes the offending cell is a file full of
 * half-parsed client details sitting in a JSONB column and, later, in Sentry.
 */
final readonly class ImportFailure
{
    public function __construct(
        public int $row,
        public string $reason,
    ) {}

    /**
     * @return array{row: int, reason: string}
     */
    public function toArray(): array
    {
        return ['row' => $this->row, 'reason' => $this->reason];
    }
}
