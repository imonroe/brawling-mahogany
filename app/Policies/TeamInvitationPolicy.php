<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Person;
use App\Models\TeamInvitation;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Support\Permissions;

class TeamInvitationPolicy
{
    use ChecksTeamPermissions;

    public function viewAny(Person $person): bool
    {
        return $this->allows($person, Permissions::MANAGE_TEAM_MEMBERS);
    }

    public function create(Person $person): bool
    {
        return $this->allows($person, Permissions::MANAGE_TEAM_MEMBERS);
    }

    public function delete(Person $person, TeamInvitation $invitation): bool
    {
        return $this->belongsToCurrentTeam($invitation)
            && $this->allows($person, Permissions::MANAGE_TEAM_MEMBERS);
    }
}
