<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/**
 * Where an import has got to (Screen Inventory S33).
 *
 * `awaiting_review` is the state that makes the screen honest: the file is
 * parsed and the duplicates are identified, but **nothing has been written**.
 * S33's requirement is that somebody sees what will merge and what will be
 * created, and can change it, before any of it happens.
 */
enum ContactImportState: string implements HasLabel
{
    use ProvidesOptions;

    case Pending = 'pending';
    case Parsing = 'parsing';
    case AwaitingReview = 'awaiting_review';
    case Importing = 'importing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Parsing => 'Parsing',
            self::AwaitingReview => 'Needs Review',
            self::Importing => 'Importing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }
}
