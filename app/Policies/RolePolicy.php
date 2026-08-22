<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Person;
use App\Models\Role;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Support\Permissions;

/**
 * Roles are the one shared table a team can also write to (PRD F2.3).
 *
 * `Role` carries no global scope — the five system roles have no team — so
 * this policy does the work the scope would otherwise do: a team may read the
 * system roles and its own, and may only ever edit its own.
 */
class RolePolicy
{
    use ChecksTeamPermissions;

    public function viewAny(Person $person): bool
    {
        return $this->allows($person, Permissions::MANAGE_TEAM_MEMBERS);
    }

    public function view(Person $person, Role $role): bool
    {
        return $this->isVisible($role) && $this->allows($person, Permissions::MANAGE_TEAM_MEMBERS);
    }

    public function create(Person $person): bool
    {
        return $this->allows($person, Permissions::MANAGE_ROLES);
    }

    public function update(Person $person, Role $role): bool
    {
        // A system role is the product's, not the customer's. F2.3 is how a
        // team differs: compose a new one, never edit a shipped one.
        return ! $role->is_system
            && $role->team_id === $this->currentTeam()?->getKey()
            && $this->allows($person, Permissions::MANAGE_ROLES);
    }

    public function delete(Person $person, Role $role): bool
    {
        return $this->update($person, $role);
    }

    private function isVisible(Role $role): bool
    {
        return $role->team_id === null || $role->team_id === $this->currentTeam()?->getKey();
    }
}
