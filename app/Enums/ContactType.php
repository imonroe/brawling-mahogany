<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/** PRD §6.3 contact type — how a logged interaction happened. */
enum ContactType: string implements HasLabel
{
    use ProvidesOptions;

    case PhoneCall = 'phone_call';
    case Email = 'email';
    case Text = 'text';
    case Meeting = 'meeting';
    case Showing = 'showing';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::PhoneCall => 'Phone call',
            self::Email => 'Email',
            self::Text => 'Text',
            self::Meeting => 'Meeting',
            self::Showing => 'Showing',
            self::Other => 'Other',
        };
    }
}
