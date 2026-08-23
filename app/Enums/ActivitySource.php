<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/**
 * Where a timeline entry came from (PRD §6.2 `activity_events.source`).
 *
 * `manual` is the contact log (PRD F2.5): a human typing what happened.
 * Everything else is the product narrating itself.
 */
enum ActivitySource: string implements HasLabel
{
    use ProvidesOptions;

    case Manual = 'manual';
    case System = 'system';
    case Automation = 'automation';
    case Import = 'import';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::System => 'System',
            self::Automation => 'Automation',
            self::Import => 'Import',
        };
    }
}
