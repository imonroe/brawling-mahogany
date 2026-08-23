<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/** PRD §6.3 property type. */
enum PropertyType: string implements HasLabel
{
    use ProvidesOptions;

    case SingleFamily = 'single_family';
    case MultiFamily = 'multi_family';
    case Condo = 'condo';
    case Townhouse = 'townhouse';
    case Apartment = 'apartment';
    case Land = 'land';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::SingleFamily => 'Single Family',
            self::MultiFamily => 'Multi Family',
            self::Condo => 'Condo',
            self::Townhouse => 'Townhouse',
            self::Apartment => 'Apartment',
            self::Land => 'Land',
            self::Other => 'Other',
        };
    }
}
