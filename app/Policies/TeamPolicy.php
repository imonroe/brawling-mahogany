<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Person;
use App\Models\Team;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Support\Permissions;

class TeamPolicy
{
    use ChecksTeamPermissions;

    public function view(Person $person, Team $team): bool
    {
        return $this->isCurrent($team) && $this->membership($person) !== null;
    }

    public function update(Person $person, Team $team): bool
    {
        return $this->isCurrent($team) && $this->allows($person, Permissions::MANAGE_SETTINGS);
    }

    public function manageMembers(Person $person, Team $team): bool
    {
        return $this->isCurrent($team) && $this->allows($person, Permissions::MANAGE_TEAM_MEMBERS);
    }

    public function export(Person $person, Team $team): bool
    {
        return $this->isCurrent($team) && $this->allows($person, Permissions::EXPORT_TEAM_DATA);
    }

    public function viewAudit(Person $person, Team $team): bool
    {
        return $this->isCurrent($team) && $this->allows($person, Permissions::VIEW_AUDIT_LOG);
    }

    /**
     * A team other than the resolved one is not this policy's business, and
     * saying so is not the same as saying no: the super admin console reaches
     * other teams through its own audited path (ADR 0002), never through here.
     */
    private function isCurrent(Team $team): bool
    {
        return $this->currentTeam()?->getKey() === $team->getKey();
    }
}
