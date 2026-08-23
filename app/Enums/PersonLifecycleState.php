<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/**
 * IA §8 person lifecycle.
 *
 * This lives on the team membership, not the person: the same human can be a
 * past client of one team and a lead for another (PRD §6.2).
 */
enum PersonLifecycleState: string implements HasLabel
{
    use ProvidesOptions;

    case Lead = 'lead';
    case Active = 'active';
    case PastClient = 'past_client';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Lead => 'Lead',
            self::Active => 'Client',
            self::PastClient => 'Past Client',
            self::Archived => 'Archived',
        };
    }
}
