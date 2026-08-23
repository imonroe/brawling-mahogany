<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/**
 * IA §8 gate state.
 *
 * Overridden is not a kind of met. It means the gate should have been met and
 * was not, and somebody proceeded anyway with a reason — which is why it is
 * audited and why it is a different word from Skip (IA §7).
 */
enum GateState: string implements HasLabel
{
    use ProvidesOptions;

    case Met = 'met';
    case Unmet = 'unmet';
    case Overridden = 'overridden';

    public function label(): string
    {
        return match ($this) {
            self::Met => 'Met',
            self::Unmet => 'Not Met',
            self::Overridden => 'Overridden',
        };
    }
}
