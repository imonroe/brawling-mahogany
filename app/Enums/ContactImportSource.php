<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/** Where an imported contact list came from (PRD §4.2 F2.8). */
enum ContactImportSource: string implements HasLabel
{
    use ProvidesOptions;

    case Csv = 'csv';
    case VCard = 'vcard';
    case GoogleContacts = 'google_contacts';

    public function label(): string
    {
        return match ($this) {
            self::Csv => 'CSV',
            self::VCard => 'vCard',
            self::GoogleContacts => 'Google Contacts',
        };
    }
}
