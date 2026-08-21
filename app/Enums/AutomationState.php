<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/**
 * IA §8 automation and message state.
 *
 * `awaiting_approval` is the safety rail from PRD §4.5: an email to the wrong
 * client cannot be recalled, so a queued message waits for a human.
 */
enum AutomationState: string implements HasLabel
{
    use ProvidesOptions;

    case Pending = 'pending';
    case AwaitingApproval = 'awaiting_approval';
    case Sent = 'sent';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Scheduled',
            self::AwaitingApproval => 'Needs Review',
            self::Sent => 'Sent',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }
}
