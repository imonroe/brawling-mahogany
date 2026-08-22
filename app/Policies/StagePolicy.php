<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Person;
use App\Models\Stage;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Support\Permissions;

class StagePolicy
{
    use ChecksTeamPermissions;

    public function viewAny(Person $person): bool
    {
        return $this->allows($person, Permissions::VIEW_DEALS);
    }

    public function view(Person $person, Stage $stage): bool
    {
        return $this->belongsToCurrentTeam($stage)
            && $this->allows($person, Permissions::VIEW_DEALS);
    }

    public function update(Person $person, Stage $stage): bool
    {
        return $this->belongsToCurrentTeam($stage)
            && $this->allows($person, Permissions::MANAGE_DEALS);
    }

    /**
     * Skip is its own permission and its own word (IA §7).
     *
     * A skipped stage never happened; an overridden gate should have been met
     * and was not. Conflating them in a label or a permission loses the only
     * distinction anybody cares about six weeks later.
     */
    public function skip(Person $person, Stage $stage): bool
    {
        return $this->belongsToCurrentTeam($stage)
            && $this->allows($person, Permissions::SKIP_STAGE);
    }
}
