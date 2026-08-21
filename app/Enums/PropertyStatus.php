<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/**
 * PRD §6.3 property status — market status only.
 *
 * PRD §7.11 corrects the rough data model here: "Undergoing improvements" and
 * "Staged" are workflow positions, not market status, and belong to a stage
 * rather than to the property.
 */
enum PropertyStatus: string implements HasLabel
{
    use ProvidesOptions;

    case PreListing = 'pre_listing';
    case ForSale = 'for_sale';
    case UnderContract = 'under_contract';
    case Sold = 'sold';
    case OffMarket = 'off_market';
    case Rented = 'rented';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::PreListing => 'Pre-listing',
            self::ForSale => 'For Sale',
            self::UnderContract => 'Under Contract',
            self::Sold => 'Sold',
            self::OffMarket => 'Off Market',
            self::Rented => 'Rented',
            self::Other => 'Other',
        };
    }
}
