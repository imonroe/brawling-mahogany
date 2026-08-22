<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Deal;
use App\Models\Person;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Support\Permissions;

/**
 * Deals (PRD §4.3 · issue #59).
 *
 * Viewing and changing are separate permissions on purpose: PRD §4.2 F2.2's
 * Read Only role exists for a bookkeeper or a broker who needs to see the
 * pipeline and must not be able to move it.
 */
class DealPolicy
{
    use ChecksTeamPermissions;

    public function viewAny(Person $person): bool
    {
        return $this->allows($person, Permissions::VIEW_DEALS);
    }

    public function view(Person $person, Deal $deal): bool
    {
        return $this->belongsToCurrentTeam($deal)
            && $this->allows($person, Permissions::VIEW_DEALS);
    }

    public function create(Person $person): bool
    {
        return $this->allows($person, Permissions::MANAGE_DEALS);
    }

    public function update(Person $person, Deal $deal): bool
    {
        return $this->belongsToCurrentTeam($deal)
            && $this->allows($person, Permissions::MANAGE_DEALS);
    }

    public function delete(Person $person, Deal $deal): bool
    {
        return $this->update($person, $deal);
    }
}
