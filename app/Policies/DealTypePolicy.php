<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DealType;
use App\Models\Person;
use App\Policies\Concerns\ChecksTeamPermissions;
use App\Support\Permissions;

/**
 * Deal types (S76 · issue #58).
 *
 * A lookup with no `team_id` scope, so `belongsToCurrentTeam()` cannot do the
 * work here — the checks are written out. A **system** type belongs to nobody
 * and is editable by nobody: hiding "Rental Placement" for every team on the
 * platform because one team stopped doing rentals is not what that team asked
 * for.
 */
class DealTypePolicy
{
    use ChecksTeamPermissions;

    public function viewAny(Person $person): bool
    {
        return $this->allows($person, Permissions::MANAGE_SETTINGS);
    }

    public function view(Person $person, DealType $dealType): bool
    {
        return $this->visibleHere($dealType)
            && $this->allows($person, Permissions::MANAGE_SETTINGS);
    }

    public function create(Person $person): bool
    {
        return $this->allows($person, Permissions::MANAGE_SETTINGS);
    }

    public function update(Person $person, DealType $dealType): bool
    {
        return ! $dealType->isSystem()
            && $this->visibleHere($dealType)
            && $this->allows($person, Permissions::MANAGE_SETTINGS);
    }

    /**
     * Archive, never delete — a type live deals point at cannot be removed
     * without orphaning them (S76's in-use warning).
     */
    public function archive(Person $person, DealType $dealType): bool
    {
        return $dealType->isArchivable() && $this->update($person, $dealType);
    }

    /** Ours, or the shared kind. Never another team's. */
    private function visibleHere(DealType $dealType): bool
    {
        return $dealType->isSystem()
            || $dealType->team_id === $this->currentTeam()?->getKey();
    }
}
