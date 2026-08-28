<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/**
 * What confirming a proposal will create — PRD §6.2, §4.10 F10.1 and F10.3.
 *
 * Not a taxonomy of contract clauses. This column answers one question:
 * *"if a human accepts this row, what appears in the deal?"* A key date
 * becomes a `key_dates` row, a task becomes a `tasks` row, and a provision
 * becomes a note on the timeline. The proposal's own name — "Inspection
 * objection", "Radon test" — lives in `extracted_fields.label`.
 *
 * Keeping the two apart is what lets `ConfirmExtractedField` be a `match` over
 * five words rather than a lookup table that grows with Colorado's contract
 * form.
 */
enum ExtractedFieldType: string implements HasLabel
{
    use ProvidesOptions;

    case KeyDate = 'key_date';
    case Provision = 'provision';
    case Task = 'task';

    public function label(): string
    {
        return match ($this) {
            self::KeyDate => 'Date',
            self::Provision => 'Provision',
            self::Task => 'Task',
        };
    }
}
