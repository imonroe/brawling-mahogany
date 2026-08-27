<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/**
 * PRD §6.3 participant role — somebody's part in one deal.
 *
 * This is not an access role. PRD §7.2: Client, Buyer, Seller, and Service
 * Provider describe a relationship to a transaction, not permission to use
 * the software.
 */
enum ParticipantRole: string implements HasLabel
{
    use ProvidesOptions;

    case Seller = 'seller';
    case Buyer = 'buyer';
    case CoAgent = 'co_agent';
    case OpposingAgent = 'opposing_agent';
    case Lender = 'lender';
    case TitleEscrow = 'title_escrow';
    case Inspector = 'inspector';
    case Appraiser = 'appraiser';
    case Stager = 'stager';
    case Photographer = 'photographer';
    case Contractor = 'contractor';
    case Attorney = 'attorney';
    case Other = 'other';

    /**
     * Whether this participant is on **the team's** side of the deal (#111).
     *
     * Only `co_agent`. Every other role is the client, the other side, or a
     * vendor — and the distinction matters on exactly one surface: the client
     * status page names the person to ring, and naming the *opposing* agent
     * there would be the worst possible answer to *"who do I call?"*.
     *
     * Deliberately not a list of *"internal-ish"* roles. A lender and a title
     * company are the team's contacts and are not the team.
     */
    public function isTeamSide(): bool
    {
        return $this === self::CoAgent;
    }

    public function label(): string
    {
        return match ($this) {
            self::Seller => 'Seller',
            self::Buyer => 'Buyer',
            self::CoAgent => 'Co-Agent',
            self::OpposingAgent => 'Opposing Agent',
            self::Lender => 'Lender',
            self::TitleEscrow => 'Title/Escrow',
            self::Inspector => 'Inspector',
            self::Appraiser => 'Appraiser',
            self::Stager => 'Stager',
            self::Photographer => 'Photographer',
            self::Contractor => 'Contractor',
            self::Attorney => 'Attorney',
            self::Other => 'Other',
        };
    }
}
