<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/** S79's four states: request, preparing, ready, expired. */
enum DataExportState: string implements HasLabel
{
    use ProvidesOptions;

    case Pending = 'pending';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Expired = 'expired';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Preparing => 'Preparing',
            self::Ready => 'Ready',
            self::Expired => 'Expired',
            self::Failed => 'Failed',
        };
    }
}
