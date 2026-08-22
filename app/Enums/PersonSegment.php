<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/**
 * The People index is one segmented screen, not four (issue #47, IA §5.2).
 *
 * The segment is a query parameter — `/people?segment=vendors` — which is how
 * the vendor directory (S34, Slice 2) works too rather than being a second
 * screen that drifts.
 *
 * Note what the segments are *not*: they are not a single status column. A
 * person can be a past client and a vendor at once (IA §13.3), which is why
 * Vendor is a flag and these are four questions rather than four values.
 */
enum PersonSegment: string implements HasLabel
{
    use ProvidesOptions;

    case All = 'all';
    case Clients = 'clients';
    case Vendors = 'vendors';
    case Team = 'team';
    case Leads = 'leads';

    public function label(): string
    {
        return match ($this) {
            self::All => 'All',
            self::Clients => 'Clients',
            self::Vendors => 'Vendors',
            self::Team => 'Team',
            self::Leads => 'Leads',
        };
    }

    /**
     * The empty state's copy (IA §10: say what goes here, then the action).
     */
    public function emptyMessage(): string
    {
        return match ($this) {
            self::All => 'No people yet. Add someone, or import your contacts.',
            self::Clients => 'No clients yet. A lead becomes a client when you mark them one.',
            self::Vendors => 'No vendors yet. Mark somebody a vendor to keep their rates and specialties here.',
            self::Team => 'Nobody else has access yet. Invite a teammate from Settings.',
            self::Leads => 'No leads yet. Add someone, or import your contacts.',
        };
    }
}
