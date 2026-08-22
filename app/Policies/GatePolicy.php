<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Gate;
use App\Models\Person;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Support\Permissions;

class GatePolicy
{
    use ChecksTeamPermissions;

    public function view(Person $person, Gate $gate): bool
    {
        return $this->belongsToCurrentTeam($gate)
            && $this->allows($person, Permissions::VIEW_DEALS);
    }

    /** Ticking a manual gate is ordinary deal work. */
    public function update(Person $person, Gate $gate): bool
    {
        return $this->belongsToCurrentTeam($gate)
            && $this->allows($person, Permissions::MANAGE_DEALS);
    }

    /** Proceeding without it is not (S24, #69). */
    public function override(Person $person, Gate $gate): bool
    {
        return $this->belongsToCurrentTeam($gate)
            && $this->allows($person, Permissions::OVERRIDE_GATE);
    }
}
