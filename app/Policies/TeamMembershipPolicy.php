<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Person;
use App\Models\TeamMembership;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Support\Permissions;

/**
 * A person, as this team knows them (PRD §4.2 · Screen Inventory S30–S32).
 *
 * The membership is the policy's subject rather than the `Person`, because the
 * membership is the team-scoped half. What Team A may do with a shared person
 * record is decided entirely by the membership Team A holds.
 */
class TeamMembershipPolicy
{
    use ChecksTeamPermissions;

    public function viewAny(Person $person): bool
    {
        return $this->allows($person, Permissions::VIEW_PEOPLE);
    }

    public function view(Person $person, TeamMembership $membership): bool
    {
        return $this->belongsToCurrentTeam($membership)
            && $this->allows($person, Permissions::VIEW_PEOPLE);
    }

    public function create(Person $person): bool
    {
        return $this->allows($person, Permissions::MANAGE_PEOPLE);
    }

    public function update(Person $person, TeamMembership $membership): bool
    {
        return $this->belongsToCurrentTeam($membership)
            && $this->allows($person, Permissions::MANAGE_PEOPLE);
    }

    public function delete(Person $person, TeamMembership $membership): bool
    {
        return $this->update($person, $membership);
    }

    /**
     * Managing *access* is a different thing from managing a contact, and a
     * different permission. Heather adds clients all day; she does not change
     * who can sign in.
     */
    public function manageAccess(Person $person, TeamMembership $membership): bool
    {
        return $this->belongsToCurrentTeam($membership)
            && $this->allows($person, Permissions::MANAGE_TEAM_MEMBERS);
    }

    public function import(Person $person): bool
    {
        return $this->allows($person, Permissions::IMPORT_PEOPLE);
    }
}
